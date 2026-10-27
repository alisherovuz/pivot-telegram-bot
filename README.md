# Pivot Uzbekistan · Telegram applications & review workspace

PHP 8.3+ and MySQL/MariaDB application for Ahost shared hosting. Telegram sends HTTPS webhooks; each request persists and processes its conversation immediately. A bounded cron task recovers retries and delivers administrative messages. No permanent application worker is required.

## Existing Ahost installation

Install the [immediate-reply update](docs/FAST-REPLIES.md) to remove the original once-per-minute conversational delay. The original `dist/pivot-deployment.zip` is preserved unchanged; existing and new installations using that archive need this small update.

## Start here

- [Ahost/cPanel installation, BotFather, webhook and cron guide](docs/DEPLOYMENT.md)
- [Development and cloud environment setup](docs/DEVELOPMENT.md)
- [Architecture, security, roles and statistics definitions](docs/ARCHITECTURE.md)
- [Test evidence and live deployment checklist](docs/TESTING.md)
- Import [database/schema.sql](database/schema.sql) into an empty database using phpMyAdmin.
- Copy [config/example.php](config/example.php) to private `config/local.php` and enter credentials on the host.
- Build the uploadable ZIP with `php bin/package.php` after `composer install --no-dev --prefer-dist --optimize-autoloader`. The ZIP includes vendor dependencies; the shared host needs no Composer.

## Delivered workflow

Uzbek registration with mandatory `@pivotuzbekistan` membership checks, own-contact verification, document/photo attachments (20 MB maximum), persistent drafts, back/pause/resume, references and duplicate-submission protection. Membership is checked before registration and again before accepting an attachment. The bot must be a channel administrator.

A responsive admin console provides owner/reviewer login, password management, search and filters, status and reviewer assignment, internal notes, individual ratings, audit history, original attachment downloads, editable message previews and explicit sends, queue delivery tracking and retries. Owner settings control registration, deadlines, holidays, support contact and notification templates. Three-business-day review deadlines use Asia/Tashkent; overdue reminders appear on the dashboard after cron runs.

Real XLSX and PDF exports use PhpSpreadsheet and Dompdf with Unicode fonts. Application exports follow application filters; statistics exports follow date filters and include definitions. Attachments remain separately downloadable and are never automatically converted or executed.

## Event context

Grand Demo Day is organized by Startup Garage and Yandex Uzbekistan on **November 19, 2026, 14:00–18:00, Asia/Tashkent**, at **“Yandex Uzbekistan ofisi, Toshkent”**. Submission does not mean acceptance into the final. The official application link points to `@SGdemodaybot`; this Pivot application maintains independent records with no synchronization.

The supplied event research references are [pivot.uz](https://pivot.uz/), [Pivot channel](https://t.me/s/pivotuzbekistan), [Yango event](https://yango.com/career/events/grand-demo-day-uz), and [Luma listing](https://luma.com/9jepiv4d?locale=uz). The venue intentionally uses the general wording because the listing's address and map disagree. The default registration deadline is a configurable implementation choice (November 18, 23:59:59), not a verified official cutoff.

Development and tests use fake Telegram transport. Do not use production tokens in local tests or run live webhook registration without deployment authorization. Never commit real credentials, applicant data or attachments.
