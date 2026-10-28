# Demo Day information message

The `ℹ️ Demo Day haqida` button and `/about` now send the owner's supplied Grand Demo Day season 4 announcement verbatim, with its paragraph breaks, emojis and bold phrases. Telegram HTML formatting is applied only to this static announcement. Other bot replies retain their existing plain-text behavior. The registration keyboard remains available below the announcement.

The announcement is fixed copy in `Bot::ABOUT_HTML`; editing event fields on the website does not rewrite this announcement. Registration deadlines and other event settings continue to govern their existing workflows.

## Install on Ahost

This combined update includes the simpler Telegram admin menu. It accepts either the original Telegram admin release (`cc326f1`) or the simpler menu release (`9bda5d8`), so the simpler-menu ZIP does not need to be installed separately. It preserves admin links, configuration, applications and uploads.

1. Upload `pivot-about-update.zip` into `public_html/pivot` and extract it there.
2. Run in cPanel Terminal:

   ```sh
   /usr/local/bin/php /home/atlonuz/public_html/pivot/pivot-about-update/install.php /home/atlonuz/public_html/pivot
   ```

3. Expected output: `About message update installed successfully.` If cron is busy, retry shortly. The installer verifies file hashes, refuses unexpected local edits, and makes private backups.
4. Press `ℹ️ Demo Day haqida` in the private bot chat. Check the announcement and bold text. Send `/admin` to open the simpler admin menu.
5. After verification, remove only the update ZIP and extracted installer folder. Keep installed application files and the private backup.

No database import, secret changes, admin relinking or webhook registration is required. Do not rerun older source installers after this update; their source checks will reject the newer build.
