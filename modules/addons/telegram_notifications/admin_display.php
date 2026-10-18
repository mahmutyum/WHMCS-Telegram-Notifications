<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// Render the module log viewer
if (!function_exists('telegram_notifications_admin_display')) {
    function telegram_notifications_admin_display() {
        // Fetch the latest Telegram API responses from the module log
        $logs = Capsule::table('tblmodulelog')
            ->where('module', 'telegram_notifications')
            ->where('action', 'send_telegram_message')
            ->orderBy('id', 'desc')
            ->limit(100) // Only the latest 100 entries
            ->get();

        echo '<p class="text-muted">Entries are recorded only while Module Logging is enabled (Configuration &gt; System Logs &gt; Module Log).</p>';
        echo '<table class="table table-bordered">';
        echo '<thead><tr><th>ID</th><th>Date</th><th>Status</th><th>Response Text</th><th>Link</th></tr></thead>';
        echo '<tbody>';

        if ($logs->isEmpty()) {
            echo '<tr><td colspan="5">No logs found for this module.</td></tr>';
        } else {
            foreach ($logs as $log) {
                $response = json_decode((string) $log->response, true);
                $ok = is_array($response) && !empty($response['ok']);
                $result = $ok && is_array($response['result'] ?? null) ? $response['result'] : [];

                $text = $ok
                    ? ($result['text'] ?? '')
                    : (is_array($response) ? ($response['description'] ?? '') : (string) $log->response);

                // Find the "View" link entity and only allow http(s) URLs
                $link = '';
                foreach ($result['entities'] ?? [] as $entity) {
                    if (($entity['type'] ?? '') === 'text_link' && preg_match('#^https?://#i', $entity['url'] ?? '')) {
                        $link = $entity['url'];
                        break;
                    }
                }

                echo '<tr>';
                echo '<td>' . (int) $log->id . '</td>';
                echo '<td>' . htmlspecialchars((string) $log->date, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . ($ok ? '<span class="label label-success">Sent</span>' : '<span class="label label-danger">Failed</span>') . '</td>';
                echo '<td>' . nl2br(htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')) . '</td>';
                echo '<td>' . ($link !== '' ? '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">View</a>' : '') . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';
    }
}

// Render the log viewer when the addon page is opened
telegram_notifications_admin_display();
