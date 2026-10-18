# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [2.1.0] - 2026-10-18

Adds security, sales and operations notifications. All new notifications are **off by default**, so upgrading changes nothing until you enable them.

### Added: security notifications

| Notification | Source | Why |
| --- | --- | --- |
| **Admin login** with username, IP, browser and time | `AdminLogin` hook | A stolen admin account is the biggest risk for a WHMCS install. An instant alert lets you react within minutes. |
| **Trusted Admin IPs** (IPv4/IPv6, CIDR) | New setting | Logins from your office or VPN would otherwise flood the chat, and real alerts would get ignored. |
| **Failed admin login summary**, grouped by IP with the usernames tried | `AfterCronJob` + Activity Log | Shows brute-force attempts early. WHMCS has no failed-login hook, so new Activity Log entries are read after each cron run. A checkpoint is stored in `tblconfiguration`, so each attempt is reported once and history is never replayed. |
| **Client email changed**, **user login email changed**, **user password changed**, with who made the change (admin or client area, with IP) | `ClientEdit`, `UserEdit`, `UserChangePassword` | Changing the email is usually the first step of an account takeover. Passwords are never included. |
| **Order flagged as fraud** | `AfterFraudCheck` (only when `isfraud` is true) | `FraudOrder` only runs when an admin marks an order manually. `AfterFraudCheck` catches what the fraud module detects. |
| **Security Chat ID** | New setting | Sends security alerts to a separate chat, so they don't get lost among sales notifications. |

### Added: sales and billing notifications

| Notification | Source |
| --- | --- |
| **New order:** order number, client, item count, total in the client's currency, payment method | `AfterShoppingCartCheckout` |
| **Invoice refunded** | `InvoiceRefunded` |
| **Cancellation request:** service, type and reason | `CancellationRequest` |

### Added: operations notifications

| Notification | Source | Why |
| --- | --- | --- |
| **Provisioning failure:** create, suspend, unsuspend, terminate or package change, with the module error | `AfterModule*Failed` hooks | These fail silently and are usually noticed only when a client complains. |
| **Domain failure:** registration, renewal or transfer, with the registrar error | `AfterRegistrar*Failed`, `DomainTransferFailed` | A failed renewal can let a client's domain expire. |

### Changed

- Messages are built by one shared formatter (`telegram_notifications_format`) that escapes every value, so new notification types can't skip escaping by mistake.
- Free-text fields from clients and modules (cancellation reasons, error messages) are capped at 300 characters.
- Amounts in new notifications are shown in the client's currency.
- Module constants use guarded `define()`, so including the module file twice no longer raises "constant already defined" warnings.

### Security

- Malformed CIDR prefixes in **Trusted Admin IPs** (for example `1.2.3.4/abc`) and `/0` ranges are ignored. Otherwise a typo could have trusted every IP address and silenced all admin login alerts.

### Testing

- Message building, HTML escaping, truncation, ticket filters and IPv4/IPv6 CIDR matching were tested on PHP 8.2 and 8.3, with all error reporting on.
- The hooks need a live WHMCS install and have not been tested end-to-end. Please report any issue on GitHub.

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
