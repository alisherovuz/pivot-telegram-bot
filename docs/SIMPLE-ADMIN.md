# Simpler Telegram admin menu

This update follows the Telegram admin release `cc326f1`. It preserves linked admins, applications, uploaded documents, configuration, webhook registration and cron. No database migration or account relinking is needed.

## What changes

- Home: Applications, Statistics, and Settings (owners only). Reviewers see the first two choices.
- Application list: four applicants per page. Status choices are under Filter; Excel/PDF formats are under Download list.
- Application card: Project file, Review application, More options, and Back. More options contains the individual PDF and applicant message preview.
- Review: choose a new status, then confirm or cancel. The current status is omitted from the choices. Saving refreshes the application card with a brief success message.
- Statistics: four headline metrics, with complete Excel/PDF reports behind Download report.
- Settings: registration controls and deadline information, away from the everyday review screen.
- Button navigation edits the tapped menu instead of posting a new menu each time. `/admin` still opens a fresh home menu. Files are delivered as separate documents.
- Back remembers each admin's last list filter/page independently, even when several Telegram IDs share a website owner account.
- Deleted/uneditable menus fall back to a fresh message. Repeated unchanged edits count as success; rate limits use the existing retry queue.

Existing permission checks, private-chat restrictions, confirmation binding, disabled-account checks and document handling remain in force. Changing a status still does not send an applicant message automatically. Original older menu buttons continue to work; use `/admin` for the new layout.

## Ahost installation

1. Upload `pivot-simple-admin.zip` into `public_html/pivot` and extract it there. A `pivot-simple-admin/` folder will appear.
2. Run in cPanel Terminal:

   ```sh
   /usr/local/bin/php /home/atlonuz/public_html/pivot/pivot-simple-admin/install.php /home/atlonuz/public_html/pivot
   ```

   Expected: `Simple admin update installed successfully.` The updater verifies the four changed source files and takes private backups before installation. It refuses unexpected local modifications. If cron is busy, retry shortly.
3. Send `/admin` privately to the bot. Open Applications, select an applicant, and use Back. The menu should change in place. Check a file download and a cancelled review preview.
4. After checking, remove only the uploaded ZIP and extracted `pivot-simple-admin/` installer folder. Keep installed application files and private backups.

## Development checks

The tests use fake Telegram transport and synthetic data only. Run `tests/run.php` to reset isolated fixtures before the other database suites. `tests/telegram-simple.php` checks the smaller menus, editing instead of extra messages, independent navigation context, confirmations, duplicate updates, fallback, rate limits and revoked access. `tests/telegram-transport.php` intercepts cURL inside the PHP transport namespace; it makes no network requests and checks unchanged edits, uneditable-message errors, retry delays and multipart uploads.

Live Telegram rendering and hosting behavior require checking after installation.
