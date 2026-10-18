<p align="center">
  <img src="modules/addons/telegram_notifications/logo.png" alt="Telegram Notifications for WHMCS" width="120">
</p>

<h1 align="center">Telegram Notifications for WHMCS</h1>

<p align="center">
  Secure, real-time Telegram alerts for client signups, paid invoices and support tickets.<br>
  Built for <strong>WHMCS 9</strong> and <strong>PHP 8.2+</strong>.
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-2.0.0-blue">
  <img alt="WHMCS" src="https://img.shields.io/badge/WHMCS-8.x%20%7C%209.x-1f6feb">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4">
  <img alt="License" src="https://img.shields.io/badge/license-MIT-green">
</p>

---

## Features

- 🆕 **New client:** name, email and a direct admin link.
- 💵 **Invoice paid:** invoice ID, client and total.
- 🎫 **Ticket opened:** works for registered clients and guests.
- 💬 **Ticket reply:** subject, with an optional preview of the reply.
- 🛡️ **Hardened:** HTML-escaped output, opt-in sensitive content, no link previews, masked bot token in logs.
- ⚡ **Non-blocking:** strict timeouts and error isolation, so a Telegram outage never blocks checkout or payment callbacks.
- 📋 **Log viewer:** delivery status (Sent / Failed) for the last 100 notifications.
- 🔗 **Custom admin folder:** links follow your configured admin path.

## Requirements

| Component | Version |
| --- | --- |
| WHMCS | 8.x or 9.x |
| PHP | 8.1+ (WHMCS 9 requires 8.2+) |
| PHP extensions | `curl`, `mbstring`, `json` |
| Network | Outbound HTTPS to `api.telegram.org` |

## Installation

1. Download `telegram_notifications-v2.0.0.zip` from the [latest release](https://github.com/mahmutyum/WHMCS-Telegram-Notifications/releases/latest), or clone this repository.
2. Upload the `modules` folder to your WHMCS root directory. The files end up in:
   ```
   <whmcs-root>/modules/addons/telegram_notifications/
   ├── admin_display.php
   ├── hooks.php
   ├── logo.png
   ├── telegram_notifications.php
   └── whmcs.json
   ```
3. In the WHMCS admin area, go to **System Settings → Addon Modules**, find **Telegram Notifications** and click **Activate**.
4. Click **Configure**, fill in the settings below and grant access to the admin role groups that should see the log viewer.

## Configuration

| Setting | Description |
| --- | --- |
| **Bot Token** | Create a bot with [@BotFather](https://t.me/BotFather) and paste the token. |
| **Chat ID** | The target chat, group or channel ID (for example `-1001234567890`). Add the bot to the group or channel first. [@get_id_bot](https://t.me/get_id_bot) can tell you the ID. |
| **Notify on …** | Turn each event type on or off. |
| **Include Reply Content** | Off by default. Adds the first 500 characters of client ticket replies. |

To see delivery results under **Addons → Telegram Notifications**, enable **Configuration → System Logs → Module Log**.

### Ignoring automated tickets

Tickets whose subject starts with `Cron` or contains `root@` or `csf` are skipped. To change this, edit `$ignorePatterns` in `telegram_notifications.php`.

## Security notes

- Notifications contain client names, email addresses and admin links. Send them only to a **private** chat or channel limited to your staff.
- Keep **Include Reply Content** off unless you need it. Ticket replies often contain credentials.
- If your bot token leaks, revoke it in @BotFather (`/revoke`) and update the setting.

## Upgrading from 1.x

1. Replace the files in `modules/addons/telegram_notifications/` with the new version. Your settings are kept.
2. Go to **Addon Modules → Configure** and save once to register the new **Include Reply Content** option.
3. Reply content is now **off by default**. Turn it on if you want the old behavior.

Read [CHANGELOG.md](CHANGELOG.md) for the full list of changes and the reason for each.

## Türkçe Özet

WHMCS 9 ve PHP 8.2+ için güncellenmiş, güvenliği güçlendirilmiş Telegram bildirim modülü. Yeni müşteri, ödenen fatura, açılan ticket ve ticket yanıtı için bildirim gönderir.

**Kurulum:** `modules` klasörünü WHMCS ana dizinine yükleyin. Ardından **Addon Modules** ekranından modülü etkinleştirip Bot Token ve Chat ID bilgilerini girin.

Sürüm 2.0.0'daki değişiklikler:

- Mesajlardaki link ve HTML enjeksiyonu kapatıldı.
- Ticket yanıt içeriği artık varsayılan olarak gönderilmiyor.
- Link önizlemesi kapatıldı.
- Telegram'a erişilemediğinde ödeme ve kayıt işlemleri takılmıyor.
- Özel admin dizini destekleniyor.

Ayrıntılar için [CHANGELOG.md](CHANGELOG.md) dosyasına bakın.

## Credits

This project is a maintained and security-hardened fork of [Nikba-Creative-Studio/WHMCS-Telegram-Notifications](https://github.com/Nikba-Creative-Studio/WHMCS-Telegram-Notifications) by [Nikba Creative Studio](https://www.nikba.com/). Thanks to the original authors for the foundation.

Maintained by [Mahmut YUM](https://www.mahmutyum.com.tr).

## License

Released under the [MIT License](LICENSE). The original copyright notices are kept.
