# Immediate bot replies for the existing Ahost installation

The original release persisted every webhook and waited for the next minute's cron. That caused the visible delay between registration steps. This update processes each conversation immediately from the webhook and delivers its replies before relying on cron. Network/API latency and large-file downloads still take time; subsecond completion is not guaranteed.

The update changes exactly these runtime files:

- `app/Worker.php` (new)
- `app/Queue.php`
- `bin/cron.php`
- `public/webhook.php`

There are no database migrations, dependency changes, or configuration changes. The original deployment ZIP remains unchanged for reproducibility. Its checksum is recorded in `RELEASE.json`. The small update has its own manifest and SHA-256 checksum.

## Install

1. Download `dist/pivot-fast-replies.zip` from this repository. Upload it into `/home/atlonuz/public_html/pivot` with cPanel File Manager and extract it there. It creates a `pivot-fast-replies` subfolder; it does not overwrite application files during extraction.
2. In cPanel Terminal run:

   ```sh
   /usr/local/bin/php /home/atlonuz/public_html/pivot/pivot-fast-replies/install.php /home/atlonuz/public_html/pivot
   ```

3. Expect `Immediate-reply update installed successfully.` If cron is busy, the installer makes no changes and asks you to retry. If files differ from the known original/update, it refuses to overwrite them. Report the error instead of forcing installation.
4. Keep the existing one-minute cron job. No webhook re-registration, token change, SQL import or settings reset is needed for this update.
5. Send `/status` or `/about` to your own bot. Normal responses should no longer align with the minute tick. Verify a draft resumes without losing fields, and check Delivery queue for failures. A completed application should remain unique.
6. After confirming success, remove only the downloaded update ZIP and extracted `pivot-fast-replies` helper folder. Keep the installed `app/Worker.php` and the other application files. Backups are retained under the configured private `storage/upgrades/` directory.

The CLI-only installer verifies the payload hashes and the target project's identity, takes the original cron lock, makes private backups, stages replacements and installs the webhook last. It never changes credentials, database records or uploaded attachments, and makes no Telegram API requests. Existing webhook requests from the original build can safely continue to persist updates during installation. Temporary and installed source files receive explicit readable permissions; private configuration remains untouched.

The webhook and cron coordinate through per-conversation processing and per-recipient delivery locks. Transient failures remain queued with backoff, and failed earlier updates are not silently bypassed. As before, Telegram does not guarantee exactly-once outgoing messages if its successful response is lost.

## Developer validation

Run `./dev/run php tests/run.php`, then `./dev/run php tests/fast-replies.php`. Start the fake HTTP test server and run `python3 tests/http_checks.py`. Never run the tests against the production database. Build the small archive with `python3 deploy/build-fast-replies.py`; it fixes the original base revision and assigns 0644 to shipped files. Test installation against a disposable extracted original release before publishing an update. The source and update contain no real credentials or applicant records.
