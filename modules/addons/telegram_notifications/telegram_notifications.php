<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

const TELEGRAM_NOTIFICATIONS_MODULE = 'telegram_notifications';
const TELEGRAM_NOTIFICATIONS_MESSAGE_PREVIEW_LENGTH = 500;

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

// Function to send a message to the Telegram bot
if (!function_exists('telegram_notify')) {
    function telegram_notify($type, $data) {
        try {
            $settings = telegram_notifications_settings();

            $botToken = trim((string) ($settings['bot_token'] ?? ''));
            $chatID = trim((string) ($settings['chat_id'] ?? ''));

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

// Function to build an admin area URL respecting a custom admin folder
if (!function_exists('telegram_notifications_admin_url')) {
    function telegram_notifications_admin_url($path) {
        $baseURL = rtrim((string) \App::getSystemURL(), '/');
        $adminFolder = \App::get_admin_folder_name() ?: 'admin';

        return "{$baseURL}/{$adminFolder}/{$path}";
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

        // Every value coming from clients is escaped to prevent HTML/link injection
        $e = array_map('telegram_notifications_escape', $data);

        switch ($type) {
            case 'ClientAdd':
                $clientURL = telegram_notifications_admin_url('clientssummary.php?userid=' . (int) $data['id']);
                return "🆕 <b>New Client Added</b>\nID: {$e['id']}\nName: {$e['name']}\nEmail: {$e['email']}\n<a href=\"" . telegram_notifications_escape($clientURL) . "\">View Client</a>";
            case 'InvoicePaid':
                $invoiceURL = telegram_notifications_admin_url('invoices.php?action=edit&id=' . (int) $data['id']);
                return "💵 <b>Invoice Paid</b>\nInvoice ID: {$e['id']}\nUser ID: {$e['userid']}\nUser Name: {$e['name']}\nTotal: {$e['total']}\n<a href=\"" . telegram_notifications_escape($invoiceURL) . "\">View Invoice</a>";
            case 'TicketOpen':
                $ticketURL = telegram_notifications_admin_url('supporttickets.php?action=view&id=' . (int) $data['id']);
                return "🎫 <b>New Ticket Opened</b>\nTicket ID: {$e['id']}\nUser ID: {$e['userid']}\nUser Name: {$e['name']}\nSubject: {$e['subject']}\n<a href=\"" . telegram_notifications_escape($ticketURL) . "\">View Ticket</a>";
            case 'TicketUserReply':
                $ticketURL = telegram_notifications_admin_url('supporttickets.php?action=view&id=' . (int) $data['ticketid']);
                $message = "💬 <b>New Ticket Reply</b>\nTicket ID: {$e['ticketid']}\nUser ID: {$e['userid']}\nUser Name: {$e['name']}\nSubject: {$e['subject']}";

                // Ticket contents may hold credentials, so they are only sent when explicitly enabled
                if (!empty($settings['include_message'])) {
                    $preview = trim((string) ($data['message'] ?? ''));
                    if (mb_strlen($preview, 'UTF-8') > TELEGRAM_NOTIFICATIONS_MESSAGE_PREVIEW_LENGTH) {
                        $preview = mb_substr($preview, 0, TELEGRAM_NOTIFICATIONS_MESSAGE_PREVIEW_LENGTH, 'UTF-8') . '…';
                    }
                    $message .= "\nMessage: " . telegram_notifications_escape($preview);
                }

                return $message . "\n<a href=\"" . telegram_notifications_escape($ticketURL) . "\">View Ticket</a>";
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
            "version" => "2.0.0",
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
