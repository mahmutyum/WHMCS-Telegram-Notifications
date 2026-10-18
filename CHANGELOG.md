# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [2.0.0] - 2026-10-18

First release of this maintained fork. The code was audited against WHMCS 9 (PHP 8.2+) and for security and data-leak risks. Every change below lists **what** changed and **why**.

### Security

| Change | Why |
| --- | --- |
| Messages switched from Telegram `Markdown` to `HTML` parse mode, and every client-supplied value (name, email, subject, message) is escaped with `htmlspecialchars`. | Values were inserted into Markdown unescaped. A client could register with a name like `[Pay here](https://evil.example)` and get a clickable phishing link into the staff Telegram chat. Names containing `_` or `*` also broke Markdown parsing, so Telegram returned `400 Bad Request` and the notification was lost without notice. |
| Ticket reply content is **no longer sent by default**. A new **Include Reply Content** setting enables it, and the preview is capped at 500 characters. | Customers often paste passwords, API keys or card details into tickets. Before, the full reply was copied to Telegram (a third-party service) and stored in `tblmodulelog`. |
| Telegram link previews disabled (`link_preview_options.is_disabled`). | To build a preview, Telegram's servers fetch the URL in the message, which exposed your admin area path to an outside crawler. |
| The bot token is masked in module logs (`logModuleCall` replace vars) and URL-encoded in the API URL. | Keeps the bot credentials out of debug logs. |
| Admin log viewer: every output value is escaped, only `http(s)` links are rendered, links use `rel="noopener noreferrer"`. | `id` and `date` were printed raw, and any URL taken from the API response was trusted as a link target. |
| `telegram_notifications.php` now has a `defined("WHMCS")` guard. | Blocks direct web access to the module file. |
| Internal functions renamed with a `telegram_notifications_` prefix (`build_message` → `telegram_notifications_build_message`, `send_telegram_message` → `telegram_notifications_send`). | The old generic names sat behind `function_exists()`. If any other module defined `build_message()`, this addon would silently run that code instead. |
| cURL locked to HTTPS with explicit TLS peer and host verification. | Defense in depth against downgrade or misconfigured PHP defaults. |

### Reliability

| Change | Why |
| --- | --- |
| cURL connect timeout of 5 s and total timeout of 10 s. | Hooks run synchronously. With no timeout, a slow or blocked Telegram API could hang the `InvoicePaid` gateway callback, client registration or ticket submission. |
| All hooks and the sending pipeline are wrapped in `try/catch (\Throwable)`. Failures go to the WHMCS Activity Log. | A notification failure must never break checkout, payments or support. |
| Filtered messages (Cron, `root@`, `csf` subjects) are actually skipped. | The ignore list returned `null`, but the module still sent an empty message, which the Telegram API rejected. |
| Nothing is sent when the Bot Token or Chat ID is empty. | Avoids pointless failing API calls on unconfigured installs. |
| cURL errors are logged, and the log viewer shows a **Sent / Failed** status column with Telegram's error description. | Failed deliveries used to be invisible. |

### WHMCS 9 / PHP 8.2+ compatibility

| Change | Why |
| --- | --- |
| Replaced the legacy `get_query_val()` helper with `WHMCS\Database\Capsule`, loading all settings in a single cached query. | The legacy SQL helpers are deprecated. The old code also ran three queries per notification. |
| `ClientAdd` reads `client_id` and falls back to `userid`. | WHMCS 8+ passes `client_id`. `userid` is kept only for backwards compatibility. |
| Removed `null` arguments to `htmlspecialchars()`, `date()` and `preg_match()`. | These raise deprecation notices on PHP 8.1+. |
| Guest replies resolve the author name from the ticket (shared `telegram_notifications_ticket_author()` helper). Reply notifications now include the subject. | Guest replies had an empty name. Missing `subject` triggered PHP warnings in the ignore filter. |
| Admin links use `App::getSystemURL()` and `App::get_admin_folder_name()`. | `/admin/` was hard-coded, so links broke on installs with a custom admin folder (a common hardening step). A trailing slash in the System URL also produced `//admin`. |
| Removed the deprecated no-op `curl_close()` call. | `curl_close()` has had no effect since PHP 8.0. |

### Packaging & project

- Repository restructured as `modules/addons/telegram_notifications/` so it can be uploaded straight into the WHMCS root.
- A ready-to-install ZIP is attached to each GitHub release.
- New original logo. The previous one combined the WHMCS and Telegram trademarks.
- `whmcs.json`: fixed the support URL (it pointed to an unrelated *Gateway Fees* repository) and updated metadata.
- Leftover Romanian code comments translated to English.
- README rewritten with installation, upgrade, security notes and credits.

### Upgrade notes

- **Behavior change:** ticket reply content is no longer included. Enable **Include Reply Content** if you want the old behavior.
- Messages now use HTML formatting. Custom code that called `build_message()` or `send_telegram_message()` must switch to the new prefixed names.

## [1.2] - 2024 (upstream)

Last release by [Nikba Creative Studio](https://github.com/Nikba-Creative-Studio/WHMCS-Telegram-Notifications), on which this fork is based.
