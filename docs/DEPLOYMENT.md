# Ahost / cPanel deployment

This guide prepares deployment; no production action was performed during development. Live configuration must be completed privately on your host. Do not paste passwords or bot tokens into chat, support tickets, source control or URLs.

## 1. Hosting prerequisites

In Ahost's hosting panel, select PHP **8.3 or newer** for the bot domain/subdomain and its cron command. Enable `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `gd`, `zip`, and `iconv`. Allow at least 256 MB PHP memory (512 MB recommended for large exports) and approximately 120 seconds maximum cron runtime. A MySQL 8+ or MariaDB 10.6+ database with InnoDB and utf8mb4 is required; development was tested with MariaDB 11.4.

The host must allow outbound verified HTTPS to `api.telegram.org`, private writable storage, HTTPS inbound webhooks on the domain and one-minute cron. The application makes no inbound database port public. Confirm cron frequency, PHP CLI path, maximum runtime, filesystem quota and outbound HTTPS with Ahost if your plan restricts them. Set PHP `display_errors=Off`, `log_errors=On`, and put PHP logs outside the public root. TLS certificate verification must stay enabled.

## 2. Build or use the deployment ZIP

For an existing installation, first read [the immediate-reply update instructions](FAST-REPLIES.md). The original preserved artifact requires that update; a newly rebuilt full archive from current source includes the fix.

The generated artifact is `dist/pivot-deployment.zip` with a sibling SHA-256 checksum. It contains application code, SQL, configuration example, instructions, and locked Composer dependencies including Unicode fonts. It contains no local configuration, credentials, test database, user files or Git metadata.

To rebuild on a development machine with PHP 8.3 and Composer:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/package.php
(cd dist && sha256sum -c pivot-deployment.zip.sha256)
```

In the managed cloud environment use `./dev/run composer install --no-dev --prefer-source --optimize-autoloader` and `./dev/run php bin/package.php`. The source installation uses the same lockfile revisions; this environment permits GitHub Git downloads but its initial policy blocked the GitHub API archive hostname. The deployment host needs no Composer, Docker, Node or Python.

## 3. Upload with private/public separation

Use cPanel File Manager to upload/extract the ZIP into your **home directory outside `public_html`**, producing e.g. `/home/CPANELUSER/pivot/`.

Preferred layout: create a dedicated bot subdomain whose **document root is `/home/CPANELUSER/pivot/public`**. Only `index.php`, `webhook.php`, `paths.php`, `.htaccess` and `assets/` are public. The top-level `app/`, `vendor/`, `config/`, `storage/`, `database/`, `bin/` and `docs/` must never be the document root. The top-level `.htaccess` denies accidental direct access; `public/.htaccess` grants the intended public directory and disables indexing. Enable hidden-file display in File Manager to copy `.htaccess` files.

If your plan only allows `public_html`, keep `/home/CPANELUSER/pivot` private, copy **only the contents of `pivot/public/`** into the chosen public directory, and edit that public directory's `paths.php` to:

```php
<?php
return '/home/CPANELUSER/pivot';
```

Do not copy the other project folders into `public_html`. No rewrite rules are needed; admin pages use query-string routes. Enable a valid HTTPS certificate (cPanel SSL/TLS or AutoSSL), and force HTTPS at the domain/web-server level. Do not register the webhook until HTTPS is working.

## 4. Create and import the database

1. Use cPanel MySQL Database Wizard to create a database and a dedicated database user, with a strong password.
2. Grant that user privileges on this database only. The prefix cPanel adds to both names belongs in the configuration.
3. In phpMyAdmin, select the **empty database** and import `database/schema.sql` using UTF-8. The import creates all tables and default settings. It has no `CREATE DATABASE` or database-name assumption.
4. Verify tables such as `applications`, `users`, `updates`, `notifications` and `admins` exist, with InnoDB and utf8mb4.
5. This file is the initial schema, not a repeatable migration. Do not import it over an existing installation. Back up existing data before any future migration. Runtime needs SELECT/INSERT/UPDATE/DELETE and advisory-lock capability; revoke schema-changing privileges after import if your host allows it.

## 5. Configure privately

Copy `config/example.php` to `/home/CPANELUSER/pivot/config/local.php`. Set the actual database DSN/user/password, bot token, webhook secret, HTTPS base URL and private storage directory. Keep `channel` as `@pivotuzbekistan`, `session_secure` as `true` and `telegram_mode` as `live` for production. The URL must match the public folder if the app is served under a subpath.

Generate a webhook secret locally or on the host with:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Enter it privately in `config/local.php`; it is not the bot token. Use file permissions 600 for configuration, 700 for private storage when the PHP process runs as your account, and the minimum compatible permissions if Ahost uses a different PHP user. Never use 777. Ensure `storage/private` and `storage/cache` are writable by PHP; other application code should not be web-writable.

If terminal access exists, run:

```sh
cd /home/CPANELUSER/pivot
/path/to/php83 bin/check.php /home/CPANELUSER/pivot/public
```

For the `public_html` layout, pass that actual public path instead. The preflight checks extensions, DB access, storage and production settings without calling Telegram. Never enable fake mode on the real bot.

## 6. Bootstrap the owner account

With cPanel Terminal/SSH:

```sh
cd /home/CPANELUSER/pivot
/path/to/php83 bin/create-admin.php owner@example.uz owner
```

Type a unique password at the hidden prompt (12–72 bytes). Do not put it on the command line. Then sign in at the HTTPS admin URL. Owners can create reviewers and additional owners in **Team access**, reset passwords and disable accounts. A disabled account loses existing sessions on its next request. Reviewers can change their own passwords under **Your account**.

If the host has no terminal, run the bundled helper on a trusted local PHP machine:

```sh
php bin/password-hash.php
```

It prints a password hash, not the password. In phpMyAdmin's Insert form for `admins`, enter the owner's email, the generated hash, `role=owner`, `active=1`, `session_version=1`; leave auto-increment `id` empty. Keep the hash private too. Do not place an admin-creation web script in the public directory. A host without terminal can still use cPanel cron for the application, but verify the PHP CLI path with Ahost.

## 7. BotFather and channel administrator

1. In official `@BotFather`, use `/newbot` (or select the existing authorized Pivot bot) and set its name/username. Store its token privately in the configuration.
2. Set a description explaining Pivot Demo Day application collection and that submission is not final acceptance. Set commands with `/setcommands`:
   ```text
   start - Botni boshlash
   status - Arizam holati
   about - Demo Day haqida
   back - Oldingi bosqich
   cancel - Arizani to‘xtatish
   ```
3. Add this bot as an **administrator of `@pivotuzbekistan`**. Telegram only guarantees `getChatMember` checks for other users when the bot is a channel administrator. Use the minimum channel rights Telegram permits; posting rights are not needed for membership checks.
4. The bot receives own-contact messages in a private conversation via the Telegram contact-sharing button. Users must open/start the bot before it can message them.
5. This system does not integrate with `@SGdemodaybot`. Do not describe records as synchronized.

## 8. Register and verify the HTTPS webhook

Only after the domain, configuration, database and private storage work, run:

```sh
cd /home/CPANELUSER/pivot
/path/to/php83 bin/webhook.php register
/path/to/php83 bin/webhook.php info
```

Registration sets the secret header, accepts `message` and `callback_query`, limits connections and preserves Telegram's pending updates. It is an intentional live Telegram operation and was not run during development. The token is read privately rather than placed in a curl URL or shell history.

Without SSH, temporarily create a **one-time cPanel cron** for the registration command, write output to a private file, confirm success, then remove that cron immediately. Use the same approach for `info`. Do not make a public registration endpoint. Requests to `webhook.php` without the secret should return 403; invalid JSON with the correct secret returns 400. A 503 means persistence/configuration failed and Telegram should retry. Check private error logs and `info` without sharing secrets.

## 9. Install the short cron job

In cPanel Cron Jobs, schedule once every minute (or a permitted shorter interval). Use the actual PHP 8.3+ CLI path shown by the host:

```cron
* * * * * /path/to/php83 /home/CPANELUSER/pivot/bin/cron.php >> /home/CPANELUSER/pivot/storage/cron.log 2>&1
```

Common cPanel/CloudLinux paths include `/usr/local/bin/ea-php83` or `/opt/alt/php83/usr/bin/php`; **verify your Ahost account's path**, do not assume either. The CLI must have the same extensions/configuration as web PHP. The command handles deferred work, administrative notifications and reminders; normal bot replies run immediately through the webhook. The command has no user-supplied token argument and is not an HTTP endpoint. Rotate the private log to prevent quota exhaustion.

Cron persists internal overdue reminders and attempts message deliveries. Check the console's Delivery queue, webhook processing errors and cron log. Deferred retries can arrive on a later cron tick; ordinary bot replies do not wait for cron. Processing failures retry up to eight times; delivery failures retry up to five times, with rate-limit delay. Fix configuration or permissions before using owner retry controls. An API timeout can duplicate a notification, but cannot create a duplicate application.

## 10. Configure the event and go-live checks

In **Event settings**, review registration status, the chosen cutoff, support contact and holidays. Defaults are Grand Demo Day, November 19, 2026, 14:00–18:00 Asia/Tashkent, “Yandex Uzbekistan ofisi, Toshkent”. Organizers are Startup Garage and Yandex Uzbekistan. The default deadline of November 18 at 23:59:59 is an implementation default, not a verified official cutoff.

Before opening registration, perform the live checklist in [TESTING.md](TESTING.md) using authorized test accounts. In particular validate channel administrator permissions, real contact sharing, file downloads, HTTPS/session cookies, cron timing, Unicode PDF rendering on your host and delivery queue behavior. Enable real notifications only when authorized.

Back up database, config and private attachments securely, with a restore test. Restrict admin accounts to the review team. Keep dependencies updated on a development machine, rerun tests and rebuild the ZIP; never run unreviewed updates on the shared host. Replace code and vendor atomically while preserving private config and data. Disable cron briefly during a code replacement, then restore it and inspect pending jobs. For a later event, provision a separate database unless a future multi-event migration is implemented.
