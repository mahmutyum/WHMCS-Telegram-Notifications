<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// Constants are guarded because WHMCS may include this file more than once
defined('TELEGRAM_NOTIFICATIONS_MODULE') || define('TELEGRAM_NOTIFICATIONS_MODULE', 'telegram_notifications');
defined('TELEGRAM_NOTIFICATIONS_MESSAGE_PREVIEW_LENGTH') || define('TELEGRAM_NOTIFICATIONS_MESSAGE_PREVIEW_LENGTH', 500);
defined('TELEGRAM_NOTIFICATIONS_TEXT_LIMIT') || define('TELEGRAM_NOTIFICATIONS_TEXT_LIMIT', 300);

// Notification types that are routed to the security chat when one is configured
defined('TELEGRAM_NOTIFICATIONS_SECURITY_TYPES') || define('TELEGRAM_NOTIFICATIONS_SECURITY_TYPES', ['AdminLogin', 'AdminLoginFailed', 'AccountSecurityChange', 'FraudDetected']);

// Function to read all module settings in a single query
if (!function_exists('telegram_notifications_settings')) {
    function telegram_notifications_settings() {
        static $settings = null;

        if ($settings === null) {
            $settings = Capsule::table('tbladdonmodules')
                ->where('module', TELEGRAM_NOTIFICATIONS_MODULE)
                ->pluck('value', 'setting')
                ->toArray();
        }

        return $settings;
    }
}

// Function to check whether a notification type is enabled
if (!function_exists('telegram_notifications_enabled')) {
    function telegram_notifications_enabled($type) {
        return !empty(telegram_notifications_settings()[$type]);
    }
}

// Function to send a message to the Telegram bot
if (!function_exists('telegram_notify')) {
    function telegram_notify($type, $data) {
        try {
            $settings = telegram_notifications_settings();

            $botToken = trim((string) ($settings['bot_token'] ?? ''));
            $chatID = trim((string) ($settings['chat_id'] ?? ''));

            // Security events go to a dedicated chat when one is configured
            $securityChatID = trim((string) ($settings['security_chat_id'] ?? ''));
            if ($securityChatID !== '' && in_array($type, TELEGRAM_NOTIFICATIONS_SECURITY_TYPES, true)) {
                $chatID = $securityChatID;
            }

            // Check if the module is configured and the notification type is enabled
            if ($botToken === '' || $chatID === '' || empty($settings[$type])) {
                return;
            }

            $message = telegram_notifications_build_message($type, $data, $settings);

            // An empty message means the notification was filtered out
            if ($message === null || $message === '') {
                return;
            }

            telegram_notifications_send($botToken, $chatID, $message);
        } catch (\Throwable $e) {
            // Never let a notification failure break checkout, payments or ticket submission
            logActivity('Telegram Notifications: ' . $e->getMessage());
        }
    }
}

// Function to escape user supplied values for Telegram HTML parse mode
if (!function_exists('telegram_notifications_escape')) {
    function telegram_notifications_escape($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Function to shorten long free text such as reasons and error messages
if (!function_exists('telegram_notifications_truncate')) {
    function telegram_notifications_truncate($value, $length = TELEGRAM_NOTIFICATIONS_TEXT_LIMIT) {
        $value = trim((string) $value);
        if (mb_strlen($value, 'UTF-8') > $length) {
            $value = mb_substr($value, 0, $length, 'UTF-8') . '…';
        }

        return $value;
    }
}

// Function to build an admin area URL respecting a custom admin folder
if (!function_exists('telegram_notifications_admin_url')) {
    function telegram_notifications_admin_url($path) {
        $baseURL = rtrim((string) \App::getSystemURL(), '/');
        $adminFolder = \App::get_admin_folder_name() ?: 'admin';

        return "{$baseURL}/{$adminFolder}/{$path}";
    }
}

// Function to render a message from a title, label => value pairs and an optional admin link
if (!function_exists('telegram_notifications_format')) {
    function telegram_notifications_format($title, array $fields, $url = null, $linkText = 'View') {
        $lines = ["<b>{$title}</b>"];

        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = telegram_notifications_escape($label) . ': ' . telegram_notifications_escape($value);
        }

        if ($url !== null) {
            $lines[] = '<a href="' . telegram_notifications_escape(telegram_notifications_admin_url($url)) . '">' . telegram_notifications_escape($linkText) . '</a>';
        }

        return implode("\n", $lines);
    }
}

// Function to resolve a client's display name
if (!function_exists('telegram_notifications_client_name')) {
    function telegram_notifications_client_name($clientId) {
        if (empty($clientId)) {
            return '';
        }

        $client = Capsule::table('tblclients')
            ->where('id', (int) $clientId)
            ->first(['firstname', 'lastname', 'companyname']);

        if (!$client) {
            return '';
        }

        $name = trim($client->firstname . ' ' . $client->lastname);
        return $client->companyname !== '' ? "{$name} ({$client->companyname})" : $name;
    }
}

// Function to format an amount in the client's currency
if (!function_exists('telegram_notifications_money')) {
    function telegram_notifications_money($amount, $clientId = 0) {
        try {
            if (function_exists('formatCurrency') && function_exists('getCurrency')) {
                $currency = getCurrency((int) $clientId);
                return (string) formatCurrency($amount, $currency['id'] ?? false);
            }
        } catch (\Throwable $e) {
            // Fall back to the raw amount below
        }

        return (string) $amount;
    }
}

// Function to get the visitor IP, honoring the WHMCS trusted proxy configuration
if (!function_exists('telegram_notifications_remote_ip')) {
    function telegram_notifications_remote_ip() {
        $requestClass = '\WHMCS\Utility\Environment\CurrentRequest';
        if (class_exists($requestClass) && method_exists($requestClass, 'getIP')) {
            return (string) $requestClass::getIP();
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}

// Function to describe who is performing the current request
if (!function_exists('telegram_notifications_actor')) {
    function telegram_notifications_actor() {
        $ip = telegram_notifications_remote_ip();

        if (!empty($_SESSION['adminid'])) {
            $username = Capsule::table('tbladmins')->where('id', (int) $_SESSION['adminid'])->value('username');
            return 'Admin ' . ($username ?: '#' . (int) $_SESSION['adminid']) . ($ip !== '' ? " ({$ip})" : '');
        }

        return 'Client area' . ($ip !== '' ? " ({$ip})" : '');
    }
}

// Function to check an IP against a list of addresses and CIDR ranges (IPv4 and IPv6)
if (!function_exists('telegram_notifications_ip_in_list')) {
    function telegram_notifications_ip_in_list($ip, $list) {
        $ipBinary = @inet_pton((string) $ip);
        if ($ipBinary === false) {
            return false;
        }

        foreach (preg_split('/[\s,]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            [$subnet, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $subnetBinary = @inet_pton($subnet);

            if ($subnetBinary === false || strlen($subnetBinary) !== strlen($ipBinary)) {
                continue;
            }

            // Reject malformed prefixes so a typo can never turn into /0 (trust everything)
            $maxBits = strlen($ipBinary) * 8;
            if ($bits !== null && !ctype_digit($bits)) {
                continue;
            }
            $bits = $bits === null ? $maxBits : (int) $bits;
            if ($bits < 1 || $bits > $maxBits) {
                continue;
            }

            // Compare whole bytes first, then the remaining bits
            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;
            if (substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
                continue;
            }
            if ($remainder === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainder)) & 0xFF;
            if ((ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}

// Function to build the message based on the notification type
if (!function_exists('telegram_notifications_build_message')) {
    function telegram_notifications_build_message($type, $data, $settings = []) {
        // Define a list of patterns or keywords to ignore
        $ignorePatterns = [
            '/^Cron\b/i', // Ignore subjects starting with "Cron"
            '/root@/',    // Ignore emails from root@
            '/csf/',      // Ignore subjects containing "csf"
            // Add more patterns as needed
        ];

        // Check if the subject matches any of the ignore patterns
        if ($type === 'TicketOpen' || $type === 'TicketUserReply') {
            $subject = (string) ($data['subject'] ?? '');
            foreach ($ignorePatterns as $pattern) {
                if (preg_match($pattern, $subject)) {
                    // If a match is found, skip the notification
                    return null;
                }
            }
        }

        switch ($type) {
            case 'ClientAdd':
                return telegram_notifications_format('🆕 New Client Added', [
                    'ID' => $data['id'],
                    'Name' => $data['name'],
                    'Email' => $data['email'],
                ], 'clientssummary.php?userid=' . (int) $data['id'], 'View Client');
            case 'InvoicePaid':
                return telegram_notifications_format('💵 Invoice Paid', [
                    'Invoice ID' => $data['id'],
                    'User ID' => $data['userid'],
                    'User Name' => $data['name'],
                    'Total' => $data['total'],
                ], 'invoices.php?action=edit&id=' . (int) $data['id'], 'View Invoice');
            case 'TicketOpen':
                return telegram_notifications_format('🎫 New Ticket Opened', [
                    'Ticket ID' => $data['id'],
                    'User ID' => $data['userid'],
                    'User Name' => $data['name'],
                    'Subject' => $data['subject'],
                ], 'supporttickets.php?action=view&id=' . (int) $data['id'], 'View Ticket');
            case 'TicketUserReply':
                $fields = [
                    'Ticket ID' => $data['ticketid'],
                    'User ID' => $data['userid'],
                    'User Name' => $data['name'],
                    'Subject' => $data['subject'],
                ];

                // Ticket contents may hold credentials, so they are only sent when explicitly enabled
                if (!empty($settings['include_message'])) {
                    $fields['Message'] = telegram_notifications_truncate($data['message'] ?? '', TELEGRAM_NOTIFICATIONS_MESSAGE_PREVIEW_LENGTH);
                }

                return telegram_notifications_format('💬 New Ticket Reply', $fields, 'supporttickets.php?action=view&id=' . (int) $data['ticketid'], 'View Ticket');
            case 'AdminLogin':
                return telegram_notifications_format('🔐 Admin Login', [
                    'Admin' => $data['username'],
                    'IP' => $data['ip'],
                    'Browser' => $data['useragent'],
                    'Time' => $data['time'],
                ], 'systemadminlog.php', 'View Admin Log');
            case 'AdminLoginFailed':
                return telegram_notifications_format('🚨 Failed Admin Login Attempts', [
                    'Attempts' => $data['attempts'],
                    'Period' => $data['period'],
                    'Details' => $data['details'],
                ], 'systemactivitylog.php', 'View Activity Log');
            case 'AccountSecurityChange':
                return telegram_notifications_format('🛡️ ' . telegram_notifications_escape($data['change']), [
                    'Account' => $data['account'],
                    'Old' => $data['old'] ?? '',
                    'New' => $data['new'] ?? '',
                    'Changed By' => $data['actor'],
                ], $data['url'] ?? null, 'View Account');
            case 'FraudDetected':
                return telegram_notifications_format('⚠️ Order Flagged as Fraud', [
                    'Order ID' => $data['orderid'],
                    'Client' => $data['name'],
                    'Email' => $data['email'],
                    'Amount' => $data['amount'],
                ], 'orders.php?action=view&id=' . (int) $data['orderid'], 'View Order');
            case 'NewOrder':
                return telegram_notifications_format('🛒 New Order', [
                    'Order ID' => $data['orderid'],
                    'Order Number' => $data['ordernumber'],
                    'Client' => $data['name'],
                    'Items' => $data['items'],
                    'Total' => $data['total'],
                    'Payment Method' => $data['paymentmethod'],
                ], 'orders.php?action=view&id=' . (int) $data['orderid'], 'View Order');
            case 'InvoiceRefunded':
                return telegram_notifications_format('↩️ Invoice Refunded', [
                    'Invoice ID' => $data['id'],
                    'Client' => $data['name'],
                    'Invoice Total' => $data['total'],
                ], 'invoices.php?action=edit&id=' . (int) $data['id'], 'View Invoice');
            case 'CancellationRequest':
                return telegram_notifications_format('❌ Cancellation Request', [
                    'Service ID' => $data['serviceid'],
                    'Client' => $data['name'],
                    'Service' => $data['service'],
                    'Type' => $data['type'],
                    'Reason' => telegram_notifications_truncate($data['reason']),
                ], 'cancelrequests.php', 'View Requests');
            case 'ModuleFailure':
                return telegram_notifications_format('🧩 Service ' . telegram_notifications_escape($data['action']) . ' Failed', [
                    'Service ID' => $data['serviceid'],
                    'Client' => $data['name'],
                    'Domain' => $data['domain'],
                    'Module' => $data['module'],
                    'Error' => telegram_notifications_truncate($data['error']),
                ], 'clientsservices.php?userid=' . (int) $data['userid'] . '&id=' . (int) $data['serviceid'], 'View Service');
            case 'DomainFailure':
                return telegram_notifications_format('🌐 Domain ' . telegram_notifications_escape($data['action']) . ' Failed', [
                    'Domain ID' => $data['domainid'],
                    'Domain' => $data['domain'],
                    'Registrar' => $data['registrar'],
                    'Error' => telegram_notifications_truncate($data['error']),
                ], 'clientsdomains.php?id=' . (int) $data['domainid'], 'View Domain');
            default:
                return null;
        }
    }
}

// Function to send a message via the Telegram API
if (!function_exists('telegram_notifications_send')) {
    function telegram_notifications_send($botToken, $chatID, $message) {
        $url = 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/sendMessage';
        $data = [
            'chat_id' => $chatID,
            'text' => $message,
            'parse_mode' => 'HTML',
            // Prevent Telegram from fetching (and exposing) the admin area URLs
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ];

        // Use cURL to send the POST request
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $response = 'cURL error: ' . curl_error($ch);
        }

        // Log the response for the admin log viewer, masking the bot token
        logModuleCall(TELEGRAM_NOTIFICATIONS_MODULE, 'send_telegram_message', $data, $response, null, [$botToken]);
    }
}

// Configuration function for the module
if (!function_exists('telegram_notifications_config')) {
    function telegram_notifications_config() {
        $configarray = [
            "name" => "Telegram Notifications",
            "description" => "Sends real-time WHMCS event notifications to a Telegram chat or channel.",
            "version" => "2.1.0",
            "author" => "Mahmut YUM",
            "fields" => [
                "bot_token" => [
                    "FriendlyName" => "Bot Token",
                    "Type" => "text",
                    "Size" => "50",
                    "Description" => "Enter your Telegram Bot Token here.",
                    "Default" => "",
                ],
                "chat_id" => [
                    "FriendlyName" => "Chat ID",
                    "Type" => "text",
                    "Size" => "50",
                    "Description" => "Enter your Telegram Chat ID here.",
                    "Default" => "",
                ],
                "security_chat_id" => [
                    "FriendlyName" => "Security Chat ID",
                    "Type" => "text",
                    "Size" => "50",
                    "Description" => "Optional. Admin logins, failed logins, account changes and fraud alerts are sent here. Leave empty to use the Chat ID above.",
                    "Default" => "",
                ],
                "ClientAdd" => [
                    "FriendlyName" => "Notify on New Client",
                    "Type" => "yesno",
                    "Description" => "Send notification when a new client is added.",
                ],
                "InvoicePaid" => [
                    "FriendlyName" => "Notify on Invoice Paid",
                    "Type" => "yesno",
                    "Description" => "Send notification when an invoice is paid.",
                ],
                "TicketOpen" => [
                    "FriendlyName" => "Notify on Ticket Open",
                    "Type" => "yesno",
                    "Description" => "Send notification when a new ticket is opened.",
                ],
                "TicketUserReply" => [
                    "FriendlyName" => "Notify on Ticket Reply",
                    "Type" => "yesno",
                    "Description" => "Send notification when a user replies to a ticket.",
                ],
                "include_message" => [
                    "FriendlyName" => "Include Reply Content",
                    "Type" => "yesno",
                    "Description" => "Include the first " . TELEGRAM_NOTIFICATIONS_MESSAGE_PREVIEW_LENGTH . " characters of ticket replies. Replies may contain passwords or other sensitive data.",
                ],
                "AdminLogin" => [
                    "FriendlyName" => "Notify on Admin Login",
                    "Type" => "yesno",
                    "Description" => "Send notification when an administrator logs in.",
                ],
                "trusted_ips" => [
                    "FriendlyName" => "Trusted Admin IPs",
                    "Type" => "textarea",
                    "Rows" => "3",
                    "Description" => "Admin logins from these IPs or CIDR ranges are not reported. One per line or comma separated, e.g. 203.0.113.10, 198.51.100.0/24",
                ],
                "AdminLoginFailed" => [
                    "FriendlyName" => "Notify on Failed Admin Logins",
                    "Type" => "yesno",
                    "Description" => "Send a summary of failed admin login attempts after each cron run.",
                ],
                "AccountSecurityChange" => [
                    "FriendlyName" => "Notify on Account Changes",
                    "Type" => "yesno",
                    "Description" => "Send notification when a client or user email address or password changes.",
                ],
                "FraudDetected" => [
                    "FriendlyName" => "Notify on Fraud Orders",
                    "Type" => "yesno",
                    "Description" => "Send notification when the fraud check flags an order.",
                ],
                "NewOrder" => [
                    "FriendlyName" => "Notify on New Order",
                    "Type" => "yesno",
                    "Description" => "Send notification when a new order is placed.",
                ],
                "InvoiceRefunded" => [
                    "FriendlyName" => "Notify on Refund",
                    "Type" => "yesno",
                    "Description" => "Send notification when an invoice is refunded.",
                ],
                "CancellationRequest" => [
                    "FriendlyName" => "Notify on Cancellation Request",
                    "Type" => "yesno",
                    "Description" => "Send notification when a client requests a cancellation.",
                ],
                "ModuleFailure" => [
                    "FriendlyName" => "Notify on Provisioning Failures",
                    "Type" => "yesno",
                    "Description" => "Send notification when creating, suspending, unsuspending, terminating or upgrading a service fails.",
                ],
                "DomainFailure" => [
                    "FriendlyName" => "Notify on Domain Failures",
                    "Type" => "yesno",
                    "Description" => "Send notification when a domain registration, renewal or transfer fails.",
                ],
            ],
        ];

        return $configarray;
    }
}

// Activate function
if (!function_exists('telegram_notifications_activate')) {
    function telegram_notifications_activate() {
        // Any activation process can be handled here
        return [
            'status' => 'success',
            'description' => 'Telegram Notifications module activated successfully.',
        ];
    }
}

// Deactivate function
if (!function_exists('telegram_notifications_deactivate')) {
    function telegram_notifications_deactivate() {
        // Any deactivation process can be handled here
        return [
            'status' => 'success',
            'description' => 'Telegram Notifications module deactivated successfully.',
        ];
    }
}

// Upgrade function (optional)
if (!function_exists('telegram_notifications_upgrade')) {
    function telegram_notifications_upgrade($vars) {
        // Handle upgrades between versions
    }
}

if (!function_exists('telegram_notifications_output')) {
    function telegram_notifications_output($vars) {
        include __DIR__ . '/admin_display.php';
    }
}
