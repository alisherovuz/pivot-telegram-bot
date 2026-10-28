# Bot users: audience and registration progress

This update exposes the existing `users` records to authorized admins. A user is recorded when the bot processes a private message or callback, before channel-subscription checks or application submission. People who only open the Telegram chat without interacting are not visible to the bot. These are unique Telegram IDs, not visits or verified unique people. Admins who interact with the bot are included.

Existing records are available immediately; no new database tables or schema migration are required. Registration progress is joined from current drafts/applications without requiring a completed application.

## Telegram

Keep the simple home menu: **/admin → Statistics → Bot users**.

- Statistics now shows total recorded users, new users today and users without a submitted application alongside application statistics.
- Bot users shows total users, new today, users who interacted within the last seven days, not started, unfinished (including paused), and submitted.
- Browse four users per page. Filter All, No application submitted, Not started, Unfinished, or Submitted.
- A user card shows Telegram ID, latest recorded username if available, name/phone provided during registration, first/last recorded interaction, draft step or submitted reference, and the last successful channel check. The channel check is historical, not evidence of current subscription. First/last interaction does not mean Telegram online status.
- Submitted users have a link to their application. Back remembers the user-list filter and page separately from application-list navigation.
- Download list offers Excel and PDF exports of the selected group, including users who never submitted.

Names and phone numbers are shown only when provided during registration; this update does not claim access to private Telegram profile data. An absent username or field is shown as unavailable/not provided.

## Website

The sidebar now includes **Bot users**. The page has the same audience summary, plus search by Telegram ID, username or provided name; registration-stage filters; and first-interaction date filters. Search/list exports use those filters. Summary cards always show all recorded users and are labeled separately from matching rows.

First/last dates, today and the seven-day window use **Asia/Tashkent**. New today uses first interaction on today's calendar date; seven-day activity uses the latest recorded interaction within the preceding seven days. No application submitted includes both users who never started and unfinished/paused drafts. Submitted users are counted once, not once per message.

Existing owner/reviewer website authorization applies. Telegram access continues to use the existing linked IDs, with checks on every request and again before queued user reports are sent. No admin access is added or removed by this update. Spreadsheet cells use explicit text to preserve IDs/phones and prevent formula execution; website/PDF fields are escaped.

Exports are limited to 10,000 users per Excel file and 300 per PDF. Narrow filters on the website for larger datasets. Telegram document requests share the existing ten-second spacing and delivery retries. Temporary exports are private and deleted after each attempt.

## Ahost installation

Requires the installed About-message release `963939d`. The announcement, simpler menus and existing admin links are preserved.

1. Upload `pivot-users-update.zip` into `public_html/pivot`, then extract it there.
2. Run in cPanel Terminal:

   ```sh
   /usr/local/bin/php /home/atlonuz/public_html/pivot/pivot-users-update/install.php /home/atlonuz/public_html/pivot
   ```

3. Expected: `Bot users update installed successfully.` Source hashes are checked and changed files backed up privately. If cron is busy, retry shortly. Unexpected local edits are rejected.
4. In Telegram open `/admin → Statistics → Bot users`. Verify a known user who has not submitted. Open Bot users on the website and check a filtered Excel/PDF download.
5. After verification, remove only the uploaded ZIP and extracted `pivot-users-update/` installer folder. Keep installed application files and private backups.

Do not re-import the database, change secrets, relink admins or re-register the webhook.

## Development verification

`tests/bot-users.php` uses the isolated `pivot_test` database and fake Telegram transport. It checks counts, disjoint progress categories, date/search filters, pagination, unavailable fields, paused drafts, exports, ID/phone string preservation, formula handling, admin-only access and revoked queued delivery. HTTP tests cover authentication, escaped stored usernames, filtering and user reports. Browser checks include the new page on desktop/mobile. Run `tests/run.php` first to recreate only the test fixtures. Live Ahost/Telegram delivery still requires verification after installation.
