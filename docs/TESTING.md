# Validation report

Test environment: PHP 8.3.36, MariaDB 11.4, Composer-locked PhpSpreadsheet 5.10.0 and Dompdf 3.1.6. All application tests use a fake Telegram transport and synthetic records. No real Telegram messages were sent, no live webhook was registered and no production deployment was performed.

## Immediate-reply update validation

The update passed 21 additional assertions covering immediate processing/delivery, duplicate webhooks, rapid message ordering, per-user lock contention, independent conversations, concurrent cron/webhook delivery, ordered backoff, failed-update blocking, owner retry recovery, stale delivery recovery and API rate-limit fallback. The original 63 integration and 36 HTTP assertions still pass. An actual HTTP webhook was verified to persist, process and send its reply through fake transport without running cron. The updater was applied to an extracted original release and rerun to verify idempotency; the patched release successfully responded through its original Composer autoloader. Live Ahost response times still require testing after installing the update.

## Telegram admin update validation

The private bot panel passed 64 additional assertions: identity linking and denial, group/mismatched-chat isolation, forged callbacks, pagination, statistics, status preview/confirmation/cancellation, stale status protection, notification preview and single-use send, reviewer/owner boundaries, role changes before confirmation, disabled/revoked access at delivery, original file bytes/names, all five generated report variants, temporary file cleanup, rate limits, retry handling, attachment path validation, and normal applicant registration. Tests use synthetic records and fake Telegram transport; actual Telegram document upload and download still require checking on Ahost.

The 63 base integration assertions, 21 immediate-response/concurrency assertions and 36 HTTP assertions also pass with the new code. PHP syntax checks pass. The small installer was tested against the original deployment ZIP plus the immediate-response patch, including a second idempotent run. The patched release resolved new classes through its original Composer autoloader, and the CLI link/revoke flow succeeded. No database migration or new dependencies are required.

## Simpler Telegram menu validation

The simplified menu passed 28 interaction assertions, covering the smaller home/card menus, editing the tapped message, independent per-admin Back navigation, confirmation/cancellation, duplicate updates, hidden secondary controls, report navigation, owner-only settings, fallback from uneditable messages, rate limits and revoked queued access. Five transport assertions intercept cURL without making network requests: unchanged edits are successful, missing messages still trigger fallback, rate-limit delays survive, and document uploads remain multipart.

The base integration (63), HTTP (36), immediate-reply (21) and Telegram admin regression suites also passed. The latter now reports 58 assertions because the smaller list has fewer buttons to check against Telegram's callback-size limit; permission and document tests remain present. All changed PHP files pass syntax checks. The four-file updater was installed twice on a copy of the deployed admin release to check success and idempotency. Live Telegram rendering must still be checked after Ahost installation.

## Executed checks

- **63 PHP integration assertions passed** against MariaDB: membership gating and recovery, exact Uzbek greeting/success text, own-contact enforcement, draft back/pause/resume, 20 MB rejection, private storage, document/photo acceptance, registration closure, duplicate updates/submissions, transactional failure/retry, business-day/holiday/timezone calculation, cohort/drop-off/overdue statistics, filtering and SQL injection handling, XLSX text cells, PDF/font output, queue deduplication/backoff/permanent errors, login/CSRF/roles, notes/ratings/audit, explicit notification preview/send, session revocation and login rate limiting.
- **36 HTTP assertions passed**: login/dashboard and all admin pages, owner/reviewer restrictions including forged actions, CSRF rejection, unauthenticated downloads/exports, private-path denial, webhook missing secret/malformed body/duplicate acknowledgment/body-size rejection, original attachment bytes, XLSX/PDF/statistics routes, filter validation, stored HTML escaping and logout access revocation.
- Chromium login and **16 desktop/mobile page layout checks passed** at widths 1440 and 390 pixels, without page errors or document-level horizontal overflow. Screenshots were visually inspected; tables use horizontal scrolling on narrow screens.
- PDF text extraction with `pdftotext` preserved **“O‘g‘iloy Ўзбекистон”**, Uzbek punctuation and international phone text. The PDFs embed DejaVu Sans.
- PHP syntax checks passed for application, endpoints, CLI tools and tests. Composer manifest/lock validation and the no-network preflight passed.
- A fake-transport cron pass processed the HTTP-ingested update and queued messages; the next pass processed zero duplicate updates. Overdue reminders persisted.
- Development setup was exercised repeatedly with the same lockfile; the second installation reused dependencies and preserved configuration/database state. Local login readiness was checked with an HTTP request.
- The release ZIP is built from an explicit file allowlist with dependencies. The archive and checksum are checked, including absence of local configuration, user files and Git metadata. Packaged source is separately smoke-tested after extraction.

Commands and fixture isolation are documented in [DEVELOPMENT.md](DEVELOPMENT.md). Machine-readable PHP/HTTP results and generated exports are in ignored `.dev/test-output/`; the release ZIP and checksum are in ignored `dist/`. The PHP suite deliberately recreates only `pivot_test`.

## Unrun or externally blocked checks

- **Composer advisory audit did not run successfully**: the initial managed network policy returned HTTP 403 for `packagist.org/api/security-advisories`. Locked dependency installation and normal integrity/TLS verification succeeded. A passing install is not a vulnerability audit. Allow `packagist.org` in environment settings, then rerun `./dev/run composer audit`. `api.github.com` is also needed if switching from the tested Git-source installation to Composer dist archives.
- No load test or Ahost-specific runtime test has been performed. Export caps and bounded cron runs are designed for shared hosting, but practical capacity depends on plan limits and attachment sizes.
- Current-instance validation and saved environment instructions do not establish that publication or fresh-task restoration succeeded.

## Live deployment checklist — requires authorized test accounts

1. Import SQL through the host's phpMyAdmin and run the preflight with its PHP CLI. Verify utf8mb4, writable private storage, PHP extensions, HTTPS document-root separation and secure session cookies.
2. Create the real owner privately; verify both roles, password changes, account disabling and unauthorized attachment access.
3. Confirm the bot is administrator of `@pivotuzbekistan`. With a nonmember, check the join/check buttons. Join and retry; leave before the attachment step to confirm the fresh gate and retained draft. Also check behavior if channel permissions temporarily fail.
4. Complete the flow using an authorized Telegram user, their own contact, a real document and a photo. Verify actual file bytes, the 20 MB boundary/error guidance, the reference and the exact success wording. Confirm submission is not advertised as final acceptance.
5. Register the HTTPS webhook deliberately, inspect `getWebhookInfo`, confirm host cron cadence and check pending/failed jobs. Test webhook redelivery without duplicating an application. Verify responses arrive within the plan's cron timing.
6. Change status, add a note/rating and assign a reviewer. Confirm no message is sent until preview and explicit send. Check real delivery success and an authorized blocked-bot test for a terminal failure.
7. Configure a holiday, verify a new three-business-day deadline and an overdue reminder. Existing deadlines intentionally remain unchanged.
8. Download originals, export filtered XLSX/PDF and statistics, open them in target office/PDF viewers and check Uzbek characters and phone formatting. Large exports may require narrower filters.
9. Check private logs, back up database plus attachments together and test restoration. Confirm resource usage/cron runtime before opening public registration.

Telegram does not provide exactly-once outbound `sendMessage`: a lost successful response can cause a duplicate retry. The app guarantees unique persisted updates/applications, not exactly-once external delivery. Membership statistics describe recorded checks only. No synchronization with the official `@SGdemodaybot` was implemented or claimed.
