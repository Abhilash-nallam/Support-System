# BitsResources Telegram Support — PHP + InfinityFree

PHP webhook backend + InfinityFree MySQL. No Node.js and no Supabase.

## Important host limitation
Telegram calls `webhook.php` inbound, but PHP must call `api.telegram.org` outbound to send messages and register the webhook. Some free hosts restrict outbound HTTPS. This package uses PHP cURL; if cURL cannot reach Telegram, the account cannot run the whole bot directly and needs a Cloudflare Worker relay or a host allowing Telegram API access. Verify this before relying on the bot.

## Setup
1. Create a bot with @BotFather. Copy its token. Get your numeric user ID from @userinfobot. Press Start on your new bot as admin.
2. In InfinityFree control panel, create a MySQL database. Use the exact host/name/user/password shown there.
3. Import `schema.sql` in phpMyAdmin.
4. Rename `config.example.php` to `config.php` and fill in token, username, admin ID, webhook secret (long random string), and MySQL credentials. Never commit `config.php`.
5. Upload `config.php`, `db.php`, `telegram.php`, `webhook.php`, `health.php`, and `set-webhook.php` to the same folder on your HTTPS site. Upload the widget snippet wherever you keep frontend assets.
6. Edit `set-webhook.php`: change `$setup_key` to another long random secret.
7. Visit `https://YOURDOMAIN/support/set-webhook.php?key=YOUR_SETUP_KEY` (adjust path). Confirm Telegram returns `ok:true`. Delete `set-webhook.php` immediately after successful setup.
8. Visit `/health.php`; it should return `ok:true`.
9. Replace `YOUR_SUPPORT_BOT` in `public/bitsresources-telegram-widget.html` and paste that snippet before `</body>` in BitsResources shared footer.

## Commands
Admin:
- `/tickets` — list up to 20 open tickets
- `/reply BR-YYYYMMDD-ABC123 Your reply here` — reply to user
- `/close BR-YYYYMMDD-ABC123` — close ticket

User:
- `/start`, `/help`
- `/status BR-YYYYMMDD-ABC123` — check own ticket

Users must press Start before the bot can message them. This version handles text messages only. Each normal user text message creates a separate ticket.

## Security
Keep `config.php` private. Never expose bot token or database password. Use HTTPS and a long webhook secret. The webhook checks Telegram's `X-Telegram-Bot-Api-Secret-Token` header. Delete `set-webhook.php` after setup. If credentials leak, rotate them immediately.
