# Telegram admin panel

The current source uses the [simpler menu](SIMPLE-ADMIN.md). If you installed the original Telegram admin ZIP, apply that small follow-up update for fewer visible buttons and navigation in one message.

Send `/admin` in a private chat with the bot. The panel is denied by default. A numeric Telegram ID must first be linked to an active website admin using the hosting terminal. No bot token or password is entered in Telegram. The bot checks sender/chat identity, account activation and roles on every action; queued admin messages/documents also recheck the link before delivery.

## Update an existing Ahost installation

Requires the immediate-reply update (`c2dd9c4`) already installed. Keep the current webhook and cron configuration. Do not import the schema again.

1. Upload `pivot-telegram-admin.zip` into `/home/atlonuz/public_html/pivot` and extract it there. This creates `pivot-telegram-admin/`.
2. In cPanel Terminal run:

   ```sh
   /usr/local/bin/php /home/atlonuz/public_html/pivot/pivot-telegram-admin/install.php /home/atlonuz/public_html/pivot
   ```

   The installer verifies source hashes, refuses unexpected edits, acquires the cron lock, backs up changed source in private `storage/upgrades`, and enables the new bot route last. It preserves configuration, database records and uploads. If cron is busy, retry shortly. If it rejects local changes, preserve your existing files and obtain a reviewed patch.
3. In your private bot chat send `/whoami`. Use the numeric ID returned by **this bot**.
4. Link that ID to your existing website admin email in cPanel Terminal. Replace both placeholders:

   ```sh
   /usr/local/bin/php /home/atlonuz/public_html/pivot/bin/telegram-admin.php link YOUR_TELEGRAM_ID YOUR_ADMIN_EMAIL
   ```

5. Send `/admin` in the same private Telegram chat. Other users cannot access the panel by guessing this command.
6. After verification, remove only the uploaded update ZIP and the extracted `pivot-telegram-admin/` installer folder. Keep `app/AdminBot.php`, `bin/telegram-admin.php`, the other installed files, and the private backup.

Fresh source installations already contain the code and need only steps 3–5. Links use reserved keys in the existing `settings` table; no schema migration or dependency update is required.

## Controls

- Applications: four per page in the current source, filters for all five statuses, applicant/contact details and original filenames.
- Original file: sends the actual private attachment as a Telegram document, with its original filename. Files are not published through a public URL.
- Application PDF, filtered application Excel/PDF, and statistics Excel/PDF: files are generated and uploaded privately. Temporary export files have owner-only permissions and are deleted after each attempt, including failures.
- Review status: preview, then confirm. Preserves assignment and writes to the same database/audit trail as the website. It does not implicitly notify the applicant.
- Applicant message: previews the configured notification template using the current application status. Confirming queues exactly that text for the existing cron to deliver, normally within one minute. Confirmation expires after five minutes, is bound to the initiating admin chat, is single-use, and is invalidated by cancellation or a new preview. A status change after preview requires a fresh preview.
- Statistics: all-time counts and status breakdown. The exported statistics contain the existing cohort definitions.
- Owners: registration on/off, with confirmation. The configured deadline still applies even when the registration switch is open. Reviewers cannot toggle it.
- Detailed delivery counts and retry controls are available on the website.

The website remains available for search/date filters, notes, ratings, reviewer assignment, editing event settings/templates, managing accounts and detailed queue troubleshooting. The bot panel covers reviewing applications and getting documents without leaving Telegram.

Exports retain the website limits of 300 applications per PDF and 10,000 per Excel file; narrow the status filter or use the website date filters for larger datasets. Wait ten seconds between document requests. Generating/uploading large files may take longer than ordinary chat replies. Telegram uploads are capped at 50 MB; applicant originals are already limited to 20 MB. A terminal delivery failure produces a private message pointing to the website download/queue. Transient failures use the existing retry queue.

## Revoke access

Disable the website admin account under Team access, or unlink just the Telegram identity:

```sh
php bin/telegram-admin.php revoke TELEGRAM_ID
```

Revocation prevents new access and queued admin deliveries. It cannot recall documents already downloaded. Keep linked Telegram accounts private and secured. Website role changes apply to the bot too.

## Validation

Development uses only synthetic applications and fake Telegram transport. Run the base fixtures first, then the admin suite:

```sh
./dev/run php tests/run.php
./dev/run php tests/telegram-admin.php
```

After installing on Ahost, verify `/admin` for the linked account and a denied account, download one original and each report type, cancel a status preview, confirm a status change, and check that the website reflects the same result. Applicant notifications should only be tested with an authorized test applicant and explicit confirmation. No live Telegram messages are sent by development tests or the installer.
