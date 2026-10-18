<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// Include the main module file
if (!function_exists('telegram_notify')) {
    require_once __DIR__ . '/telegram_notifications.php';
}

// Function to resolve the display name of a ticket author (registered client or guest)
if (!function_exists('telegram_notifications_ticket_author')) {
    function telegram_notifications_ticket_author($userid, $ticketid) {
        if (!empty($userid)) {
            $clientDetails = localAPI('GetClientsDetails', ['clientid' => (int) $userid]);
            return $clientDetails['fullname'] ?? '';
        }

        // If the ticket belongs to a guest, use the contact name from the ticket
        $ticketDetails = localAPI('GetTicket', ['ticketid' => (int) $ticketid]);
        return !empty($ticketDetails['name']) ? $ticketDetails['name'] . ' 🥷' : 'Guest';
    }
}

// Hook to send a notification when a new client is added
add_hook('ClientAdd', 1, function($vars) {
    try {
        $clientDetails = [
            'id' => $vars['client_id'] ?? $vars['userid'] ?? '',
            'name' => trim(($vars['firstname'] ?? '') . ' ' . ($vars['lastname'] ?? '')),
            'email' => $vars['email'] ?? '',
        ];
        telegram_notify('ClientAdd', $clientDetails);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (ClientAdd): ' . $e->getMessage());
    }
});

// Hook to send a notification when an invoice is paid
add_hook('InvoicePaid', 1, function($vars) {
    try {
        $invoiceData = localAPI('GetInvoice', ['invoiceid' => (int) $vars['invoiceid']]);
        $clientDetails = localAPI('GetClientsDetails', ['clientid' => (int) ($invoiceData['userid'] ?? 0)]);
        $invoiceDetails = [
            'id' => $vars['invoiceid'],
            'userid' => $invoiceData['userid'] ?? '',
            'name' => $clientDetails['fullname'] ?? '',
            'total' => $invoiceData['total'] ?? '',
        ];
        telegram_notify('InvoicePaid', $invoiceDetails);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (InvoicePaid): ' . $e->getMessage());
    }
});

// Hook to send a notification when a new ticket is opened
add_hook('TicketOpen', 1, function($vars) {
    try {
        $ticketDetails = [
            'id' => $vars['ticketid'],
            'userid' => $vars['userid'] ?? 0,
            'name' => telegram_notifications_ticket_author($vars['userid'] ?? 0, $vars['ticketid']),
            'subject' => $vars['subject'] ?? '',
        ];
        telegram_notify('TicketOpen', $ticketDetails);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (TicketOpen): ' . $e->getMessage());
    }
});

// Hook to send a notification when a user replies to a ticket
add_hook('TicketUserReply', 1, function($vars) {
    try {
        $replyDetails = [
            'ticketid' => $vars['ticketid'],
            'userid' => $vars['userid'] ?? 0,
            'name' => telegram_notifications_ticket_author($vars['userid'] ?? 0, $vars['ticketid']),
            'subject' => $vars['subject'] ?? '',
            'message' => $vars['message'] ?? '',
        ];
        telegram_notify('TicketUserReply', $replyDetails);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (TicketUserReply): ' . $e->getMessage());
    }
});

// Hook to send a notification when an administrator logs in
add_hook('AdminLogin', 1, function($vars) {
    try {
        if (!telegram_notifications_enabled('AdminLogin')) {
            return;
        }

        // Logins from trusted IPs (office, VPN) are not reported
        $ip = telegram_notifications_remote_ip();
        if (telegram_notifications_ip_in_list($ip, telegram_notifications_settings()['trusted_ips'] ?? '')) {
            return;
        }

        telegram_notify('AdminLogin', [
            'username' => $vars['username'] ?? ('#' . ($vars['adminid'] ?? '')),
            'ip' => $ip,
            'useragent' => telegram_notifications_truncate($_SERVER['HTTP_USER_AGENT'] ?? '', 150),
            'time' => date('Y-m-d H:i:s T'),
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (AdminLogin): ' . $e->getMessage());
    }
});

// Hook to report failed admin login attempts recorded in the Activity Log since the previous cron run
// WHMCS has no hook for failed admin logins, so the Activity Log is used as the source
add_hook('AfterCronJob', 1, function($vars) {
    try {
        $settingKey = 'TelegramNotificationsLastActivityLogId';
        $lastId = (int) \WHMCS\Config\Setting::getValue($settingKey);
        $maxId = (int) Capsule::table('tblactivitylog')->max('id');

        // Always move the checkpoint forward so enabling the option later doesn't report old attempts
        \WHMCS\Config\Setting::setValue($settingKey, $maxId);

        if ($lastId === 0 || $lastId >= $maxId || !telegram_notifications_enabled('AdminLoginFailed')) {
            return;
        }

        $attempts = Capsule::table('tblactivitylog')
            ->where('id', '>', $lastId)
            ->where('id', '<=', $maxId)
            ->where('description', 'like', '%Failed Admin Login%')
            ->orderBy('id')
            ->get(['date', 'description', 'ipaddr']);

        if ($attempts->isEmpty()) {
            return;
        }

        // Group attempts per IP address with the usernames that were tried
        $byIp = [];
        foreach ($attempts as $attempt) {
            $ip = $attempt->ipaddr ?: 'unknown';
            $byIp[$ip]['count'] = ($byIp[$ip]['count'] ?? 0) + 1;
            if (preg_match('/Username:\s*(\S+)/i', (string) $attempt->description, $match)) {
                $byIp[$ip]['usernames'][$match[1]] = true;
            }
        }
        uasort($byIp, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $details = [];
        foreach (array_slice($byIp, 0, 10, true) as $ip => $info) {
            $usernames = array_keys($info['usernames'] ?? []);
            $details[] = "{$ip}: {$info['count']}" . ($usernames ? ' (' . telegram_notifications_truncate(implode(', ', $usernames), 80) . ')' : '');
        }
        if (count($byIp) > 10) {
            $details[] = '+' . (count($byIp) - 10) . ' more IPs';
        }

        telegram_notify('AdminLoginFailed', [
            'attempts' => $attempts->count(),
            'period' => $attempts->first()->date . ' → ' . $attempts->last()->date,
            'details' => "\n" . implode("\n", $details),
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (AdminLoginFailed): ' . $e->getMessage());
    }
});

// Hook to send a notification when a client's email address changes
add_hook('ClientEdit', 1, function($vars) {
    try {
        $oldEmail = (string) ($vars['olddata']['email'] ?? '');
        $newEmail = (string) ($vars['email'] ?? '');
        if ($oldEmail === '' || strcasecmp($oldEmail, $newEmail) === 0) {
            return;
        }

        telegram_notify('AccountSecurityChange', [
            'change' => 'Client Email Changed',
            'account' => 'Client #' . (int) $vars['userid'] . ' ' . telegram_notifications_client_name($vars['userid']),
            'old' => $oldEmail,
            'new' => $newEmail,
            'actor' => telegram_notifications_actor(),
            'url' => 'clientssummary.php?userid=' . (int) $vars['userid'],
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (ClientEdit): ' . $e->getMessage());
    }
});

// Hook to send a notification when a login user's email address changes
add_hook('UserEdit', 1, function($vars) {
    try {
        $oldEmail = (string) ($vars['olddata']['email'] ?? '');
        $newEmail = (string) ($vars['email'] ?? '');
        if ($oldEmail === '' || strcasecmp($oldEmail, $newEmail) === 0) {
            return;
        }

        telegram_notify('AccountSecurityChange', [
            'change' => 'User Login Email Changed',
            'account' => 'User #' . (int) $vars['user_id'] . ' ' . trim(($vars['firstname'] ?? '') . ' ' . ($vars['lastname'] ?? '')),
            'old' => $oldEmail,
            'new' => $newEmail,
            'actor' => telegram_notifications_actor(),
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (UserEdit): ' . $e->getMessage());
    }
});

// Hook to send a notification when a login user's password changes (the password itself is never sent)
add_hook('UserChangePassword', 1, function($vars) {
    try {
        $email = Capsule::table('tblusers')->where('id', (int) $vars['userid'])->value('email');

        telegram_notify('AccountSecurityChange', [
            'change' => 'User Password Changed',
            'account' => 'User #' . (int) $vars['userid'] . ($email ? " ({$email})" : ''),
            'actor' => telegram_notifications_actor(),
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (UserChangePassword): ' . $e->getMessage());
    }
});

// Hook to send a notification when the fraud check flags a new order
add_hook('AfterFraudCheck', 1, function($vars) {
    try {
        if (empty($vars['isfraud'])) {
            return;
        }

        $client = $vars['clientdetails'] ?? [];
        telegram_notify('FraudDetected', [
            'orderid' => $vars['orderid'],
            'name' => trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')),
            'email' => $client['email'] ?? '',
            'amount' => telegram_notifications_money($vars['amount'] ?? 0, $client['userid'] ?? ($client['id'] ?? 0)),
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (AfterFraudCheck): ' . $e->getMessage());
    }
});

// Hook to send a notification when a new order is placed
add_hook('AfterShoppingCartCheckout', 1, function($vars) {
    try {
        $clientId = (int) Capsule::table('tblorders')->where('id', (int) $vars['OrderID'])->value('userid');
        $items = count($vars['ServiceIDs'] ?? []) + count($vars['AddonIDs'] ?? []) + count($vars['DomainIDs'] ?? []) + count($vars['RenewalIDs'] ?? []);

        telegram_notify('NewOrder', [
            'orderid' => $vars['OrderID'],
            'ordernumber' => $vars['OrderNumber'] ?? '',
            'name' => telegram_notifications_client_name($clientId),
            'items' => $items,
            'total' => telegram_notifications_money($vars['TotalDue'] ?? 0, $clientId),
            'paymentmethod' => $vars['PaymentMethod'] ?? '',
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (AfterShoppingCartCheckout): ' . $e->getMessage());
    }
});

// Hook to send a notification when an invoice is refunded
add_hook('InvoiceRefunded', 1, function($vars) {
    try {
        $invoice = Capsule::table('tblinvoices')->where('id', (int) $vars['invoiceid'])->first(['userid', 'total']);

        telegram_notify('InvoiceRefunded', [
            'id' => $vars['invoiceid'],
            'name' => $invoice ? telegram_notifications_client_name($invoice->userid) : '',
            'total' => $invoice ? telegram_notifications_money($invoice->total, $invoice->userid) : '',
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (InvoiceRefunded): ' . $e->getMessage());
    }
});

// Hook to send a notification when a client requests a cancellation
add_hook('CancellationRequest', 1, function($vars) {
    try {
        $service = Capsule::table('tblhosting')
            ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblhosting.id', (int) $vars['relid'])
            ->first(['tblhosting.domain', 'tblproducts.name']);

        telegram_notify('CancellationRequest', [
            'serviceid' => $vars['relid'],
            'name' => telegram_notifications_client_name($vars['userid'] ?? 0),
            'service' => $service ? trim($service->name . ($service->domain ? " - {$service->domain}" : '')) : '',
            'type' => $vars['type'] ?? '',
            'reason' => $vars['reason'] ?? '',
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (CancellationRequest): ' . $e->getMessage());
    }
});

// Hooks to send a notification when a provisioning module action fails
foreach (['Create' => 'Creation', 'Suspend' => 'Suspension', 'Unsuspend' => 'Unsuspension', 'Terminate' => 'Termination', 'ChangePackage' => 'Package Change'] as $moduleAction => $actionLabel) {
    add_hook("AfterModule{$moduleAction}Failed", 1, function($vars) use ($actionLabel) {
        try {
            $params = $vars['params'] ?? [];

            telegram_notify('ModuleFailure', [
                'action' => $actionLabel,
                'serviceid' => $params['serviceid'] ?? '',
                'userid' => $params['userid'] ?? 0,
                'name' => telegram_notifications_client_name($params['userid'] ?? 0),
                'domain' => $params['domain'] ?? '',
                'module' => $params['moduletype'] ?? '',
                'error' => $vars['failureResponseMessage'] ?? '',
            ]);
        } catch (\Throwable $e) {
            logActivity('Telegram Notifications (ModuleFailure): ' . $e->getMessage());
        }
    });
}

// Hooks to send a notification when a registrar command fails
foreach (['Registration', 'Renewal', 'Transfer'] as $registrarAction) {
    add_hook("AfterRegistrar{$registrarAction}Failed", 1, function($vars) use ($registrarAction) {
        try {
            $params = $vars['params'] ?? [];
            $domain = $params['domainname'] ?? trim(($params['sld'] ?? '') . '.' . ($params['tld'] ?? ''), '.');

            telegram_notify('DomainFailure', [
                'action' => $registrarAction,
                'domainid' => $params['domainid'] ?? '',
                'domain' => $domain,
                'registrar' => $params['registrar'] ?? '',
                'error' => $vars['error'] ?? '',
            ]);
        } catch (\Throwable $e) {
            logActivity('Telegram Notifications (DomainFailure): ' . $e->getMessage());
        }
    });
}

// Hook to send a notification when the domain sync cron marks a transfer as failed
add_hook('DomainTransferFailed', 1, function($vars) {
    try {
        telegram_notify('DomainFailure', [
            'action' => 'Transfer',
            'domainid' => $vars['domainId'] ?? '',
            'domain' => $vars['domain'] ?? '',
            'registrar' => $vars['registrar'] ?? '',
            'error' => 'Transfer marked as failed by domain sync',
        ]);
    } catch (\Throwable $e) {
        logActivity('Telegram Notifications (DomainTransferFailed): ' . $e->getMessage());
    }
});
