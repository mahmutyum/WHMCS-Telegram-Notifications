<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

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
