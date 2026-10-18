<p align="center">
  <img src="modules/addons/telegram_notifications/logo.png" alt="Telegram Notifications for WHMCS" width="120">
</p>

<h1 align="center">Telegram Notifications for WHMCS</h1>

<p align="center">
  Secure, real-time Telegram alerts for sales, support, security and provisioning events.<br>
  Built for <strong>WHMCS 9</strong> and <strong>PHP 8.2+</strong>.
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-2.1.0-blue">
  <img alt="WHMCS" src="https://img.shields.io/badge/WHMCS-8.x%20%7C%209.x-1f6feb">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4">
  <img alt="License" src="https://img.shields.io/badge/license-MIT-green">
</p>

---

## Features

Every notification can be turned on or off separately. All of them are off after installation.

### Sales & billing

- 🆕 **New client:** name, email and a direct admin link.
- 🛒 **New order:** order number, client, item count, total and payment method.
- 💵 **Invoice paid:** invoice ID, client and total.
- ↩️ **Refund:** refunded invoice and client.
- ❌ **Cancellation request:** service, cancellation type and reason.

### Support

- 🎫 **Ticket opened:** works for registered clients and guests.
- 💬 **Ticket reply:** subject, with an optional preview of the reply.

### Security

- 🔐 **Admin login:** admin username, IP, browser and time. Logins from your trusted IPs or CIDR ranges are skipped.
- 🚨 **Failed admin logins:** a summary after each cron run, grouped by IP with the usernames that were tried.
- 🛡️ **Account changes:** client email, user login email and user password changes, with who made the change. Passwords are never sent.
- ⚠️ **Fraud orders:** orders flagged by your fraud module.
- 📢 **Separate security chat:** security alerts can go to their own Telegram chat.

### Operations

- 🧩 **Provisioning failures:** service creation, suspension, unsuspension, termination or package change failed, with the module's error message.
- 🌐 **Domain failures:** registration, renewal or transfer failed, with the registrar's error message.

### Built in

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

1. Download `telegram_notifications-v2.1.0.zip` from the [latest release](https://github.com/mahmutyum/WHMCS-Telegram-Notifications/releases/latest), or clone this repository.
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
| **Chat ID** | The target chat, group or channel ID (for example `-1001234567890`). See [Finding your Chat ID](#finding-your-chat-id). |
| **Security Chat ID** | Optional. Admin logins, failed logins, account changes and fraud alerts go here. If empty, the **Chat ID** is used. |
| **Notify on …** | Turn each event type on or off. |
| **Include Reply Content** | Off by default. Adds the first 500 characters of client ticket replies. |
| **Trusted Admin IPs** | Admin logins from these IPs or CIDR ranges are not reported, for example `203.0.113.10, 198.51.100.0/24`. IPv4 and IPv6 are supported. Malformed entries are ignored. |

### Notification reference

| Setting | WHMCS hook | Goes to |
| --- | --- | --- |
| Notify on New Client | `ClientAdd` | Chat ID |
| Notify on New Order | `AfterShoppingCartCheckout` | Chat ID |
| Notify on Invoice Paid | `InvoicePaid` | Chat ID |
| Notify on Refund | `InvoiceRefunded` | Chat ID |
| Notify on Cancellation Request | `CancellationRequest` | Chat ID |
| Notify on Ticket Open | `TicketOpen` | Chat ID |
| Notify on Ticket Reply | `TicketUserReply` | Chat ID |
| Notify on Admin Login | `AdminLogin` | Security Chat ID |
| Notify on Failed Admin Logins | `AfterCronJob` + Activity Log | Security Chat ID |
| Notify on Account Changes | `ClientEdit`, `UserEdit`, `UserChangePassword` | Security Chat ID |
| Notify on Fraud Orders | `AfterFraudCheck` | Security Chat ID |
| Notify on Provisioning Failures | `AfterModule{Create,Suspend,Unsuspend,Terminate,ChangePackage}Failed` | Chat ID |
| Notify on Domain Failures | `AfterRegistrar{Registration,Renewal,Transfer}Failed`, `DomainTransferFailed` | Chat ID |

### Notes on security alerts

- **Failed admin logins:** WHMCS has no hook for failed admin logins. The addon reads new failed-login entries from the Activity Log after each cron run, so alerts arrive with your cron interval (usually every 5 minutes). The first cron run after installation only sets a starting point, so older attempts are never reported.
- **IP addresses behind a proxy or CDN:** if WHMCS is behind Cloudflare or a load balancer, add the proxy ranges under **System Settings → General Settings → Security → Trusted Proxies**. Otherwise WHMCS sees the proxy's IP instead of the visitor's, and your trusted IP list won't match.

To see delivery results under **Addons → Telegram Notifications**, enable **Configuration → System Logs → Module Log**.

### Finding your Chat ID

**Private chat (your own account)**

1. Open [@userinfobot](https://t.me/userinfobot) or [@getidsbot](https://t.me/getidsbot) in Telegram and send `/start`.
2. The bot replies with your ID, for example `Id: 123456789`.
3. Open **your own bot** and send `/start` once. Bots cannot message users who haven't started a chat with them.

**Group or channel (recommended for teams)**

1. Add your bot to the group. For a channel, add the bot as an **administrator**.
2. Post any message in the group or channel, for example `test`.
3. Open this URL in your browser, replacing `<TOKEN>` with your bot token:
   ```
   https://api.telegram.org/bot<TOKEN>/getUpdates
   ```
4. Find `"chat":{"id":-1001234567890, ...}` in the response. For channels it is under `channel_post`. That number is your Chat ID.

**Tips**

- Group and channel IDs are **negative** and usually start with `-100`. Include the minus sign.
- If `getUpdates` returns an empty list (`[]`), post a new message and refresh the page.
- If the bot doesn't see group messages, either disable privacy mode with `/setprivacy` in [@BotFather](https://t.me/BotFather) or make the bot a group admin.
- If a webhook is set on the bot, `getUpdates` won't return anything. Remove the webhook with `https://api.telegram.org/bot<TOKEN>/deleteWebhook`, or use the private-chat method.
- Keep your token secret. Clear the URL from your browser history afterwards.

After saving the Chat ID, create a test client or ticket to confirm that notifications arrive.

### Ignoring automated tickets

Tickets whose subject starts with `Cron` or contains `root@` or `csf` are skipped. To change this, edit `$ignorePatterns` in `telegram_notifications.php`.

## Security notes

- Notifications contain client names, email addresses and admin links. Send them only to a **private** chat or channel limited to your staff.
- Keep **Include Reply Content** off unless you need it. Ticket replies often contain credentials.
- If your bot token leaks, revoke it in @BotFather (`/revoke`) and update the setting.
- Use a separate **Security Chat ID** so login and fraud alerts don't get lost among sales notifications.

## Upgrading from 2.0.x

1. Replace the files in `modules/addons/telegram_notifications/`.
2. Go to **Addon Modules → Configure**, turn on the new notifications you want and save.

## Upgrading from 1.x

1. Replace the files in `modules/addons/telegram_notifications/` with the new version. Your settings are kept.
2. Go to **Addon Modules → Configure** and save once to register the new **Include Reply Content** option.
3. Reply content is now **off by default**. Turn it on if you want the old behavior.

Read [CHANGELOG.md](CHANGELOG.md) for the full list of changes and the reason for each.

## Türkçe Özet

WHMCS 9 ve PHP 8.2+ için güncellenmiş, güvenliği güçlendirilmiş Telegram bildirim modülü. Gönderebildiği bildirimler:

- **Satış:** yeni müşteri, yeni sipariş, ödenen fatura, iade, iptal talebi.
- **Destek:** yeni ticket, ticket yanıtı.
- **Güvenlik:** admin girişi (güvenilir IP listesi hariç), başarısız admin girişi özeti, e-posta ve şifre değişiklikleri, fraud siparişler.
- **Operasyon:** başarısız hizmet kurulumu, askıya alma, silme işlemleri; başarısız domain kaydı, yenileme ve transferleri.

Güvenlik bildirimleri isterseniz ayrı bir **Security Chat ID**'ye gönderilir. Tüm bildirimler kurulumdan sonra kapalıdır, ayarlardan tek tek açılır.

**Kurulum:** `modules` klasörünü WHMCS ana dizinine yükleyin. Ardından **Addon Modules** ekranından modülü etkinleştirip Bot Token ve Chat ID bilgilerini girin.

**Chat ID nasıl bulunur?**

- **Kendi hesabınız için:** [@userinfobot](https://t.me/userinfobot) botuna `/start` yazın. Size gönderdiği numara Chat ID'niz. Ayrıca kendi botunuza bir kez `/start` yazmanız gerekir.
- **Grup veya kanal için:**
  1. Botu gruba ekleyin. Kanal kullanıyorsanız botu kanalda yönetici yapın.
  2. Grupta ya da kanalda bir mesaj yazın.
  3. Tarayıcıda `https://api.telegram.org/bot<TOKEN>/getUpdates` adresini açın.
  4. Yanıttaki `"chat":{"id": ...}` değeri Chat ID'nizdir. Bu değer genelde `-100` ile başlar; eksi işaretini de yazın.
- **`getUpdates` boş dönerse:** Yeni bir mesaj yazıp sayfayı yenileyin. Yine boşsa BotFather'da `/setprivacy` ayarını **Disable** yapın.

Sürüm 2.0.0'daki güvenlik değişiklikleri:

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
