# Architecture and operational behavior

## Persistence and jobs

`public/webhook.php` validates Telegram's secret header and body size, then inserts an update under a unique update ID. A 200 response means durable ingestion; database errors return 503 for Telegram retry. Unsupported/group/channel updates are acknowledged and ignored. The app only handles private user conversations.

`bin/cron.php` uses a database advisory lock so overlapping invocations do not overlap work. It processes updates in update-ID order within a transaction, locks the user's row, writes draft/application state and outgoing jobs, and marks the update processed in the same commit. A failed download rolls back processing so Telegram's persisted update can be retried. A unique application user ID permits one application per user for this event, even if the sender retries with a new update ID. The schema is intentionally one-event; running a later event requires a new database or a separate future migration.

Failed processing retries after 60 seconds, at most eight times. Earlier retrying or failed updates block later updates for that user; owners can retry them after fixing the cause in Delivery queue. A cron pass examines up to 100 pending updates. Monitor failures promptly because a large backlog of failed rows can delay later users.

Cron stops starting new update work after 20 seconds and new delivery work after 40 seconds, with at most 30 outbound operations. The current network request may extend the run (up to 20 seconds for an API request, 30 for download). Membership plus download may add another API request; allow approximately 120 seconds at the host. The advisory lock prevents overlapping runs. Standard one-minute cron has up to one-minute conversational latency; ask Ahost about shorter cron frequency if needed. Do not run an endless loop on shared hosting.

Outgoing jobs have unique dedupe keys. Transient failures and rate limits retry with exponential backoff, respecting Telegram `retry_after`, for five attempts; 400/401/403/404 are terminal. An owner can explicitly retry failed deliveries. An interrupted `sending` job recovers after five minutes. Telegram has no idempotency key for `sendMessage`: a network timeout after Telegram accepted a message can produce a duplicate delivery. Application persistence remains idempotent. Callback acknowledgments are queued and may expire before cron; their failure does not invalidate the application or membership check.

The success message is enqueued only after application insertion in the same transaction. It contains the application reference. Delivery failure is visible in the queue; it does not undo an accepted application. `/status` retrieves the persistent record.

## Drafts and files

Back retains entered fields and clears a pending attachment; cancel pauses rather than deletes the draft. Applying again resumes it. Failed membership verification keeps fields and a pending file ID, so the subscription button can complete submission after a successful fresh check. API outages never bypass membership gating. A draft can continue collecting fields after registration closes, but acceptance and new starts are blocked until registration reopens. A persisted submission is never silently overwritten.

Documents of all formats and the largest Telegram photo variant are accepted. Telegram's normal Bot API download limit is 20 MB; both reported size and downloaded byte count are checked. Downloads are streamed over verified HTTPS into random `.bin` files outside the public root, with restrictive file permissions. Uploaded names never become filesystem paths. Photos reflect Telegram's photo version; sending an image as a document preserves its original bytes.

Files are served only after an active admin session, with `application/octet-stream`, `Content-Disposition: attachment`, `nosniff`, and an audited download event. The app does not parse or execute uploads. If a process is killed after file creation but before commit, an orphan `.bin` can remain; periodically compare storage keys with the database before deleting confirmed orphans. Back up the database and private storage together. The operator chooses retention policy and performs approved deletions; the application does not silently expire applicant records.

## Roles and security

Reviewers can view all applications/attachments, filter/export/statistics, assign reviewers, update statuses, write internal notes, rate applications and preview/queue applicant notifications. Owners have those capabilities plus event settings, team account creation/reset/disable, and failed job retries. Every role check is enforced on the server. Owner-only controls are also hidden from reviewers.

Passwords use `password_hash` / `password_verify`; new passwords require 12–72 bytes. Authentication rotates session IDs, uses strict cookie-only sessions with HttpOnly/SameSite=Strict and HTTPS Secure in production, expires after 30 minutes idle or eight hours absolute, and validates the database account and session version on every request. Password changes, owner resets and account disabling invalidate other sessions. Login attempts are limited to ten per IP in 15 minutes. Only `REMOTE_ADDR` is trusted; configure the web server correctly if behind a reverse proxy. Database login-attempt rows can be periodically deleted after their window expires.

All mutating admin actions require CSRF tokens. SQL values use native prepared statements. User-controlled HTML is escaped; notifications are plain Telegram text without parse mode. CSP disallows scripts, framing and remote assets. PDF remote fetching, embedded PHP and JavaScript are disabled. XLSX cells are explicit text, protecting phone formatting and preventing formula injection. Queries and exports are authenticated. Internal notes are not sent in applicant notifications.

Status edits never send automatically. A reviewer edits the rendered Uzbek template, previews exact text and recipient, then explicitly confirms within 15 minutes. The confirmed preview nonce is consumed and deduplicated in the queue. Internal review state and applicant communication are separate, audited actions.

## Dates and statistics

All application/database session dates use Asia/Tashkent (UTC+05:00). Review deadline = three business days after submission, keeping the local submission time, skipping Saturdays, Sundays and configured holidays. Holidays must be explicit YYYY-MM-DD dates. Changes affect future submissions; existing deadlines remain as originally promised. Pending/overdue means status `new` or `under review`; shortlist/accepted/rejected are outside the pending review queue. Cron creates one internal reminder per overdue application; the dashboard shows only still-overdue pending applications. These reminders do not send Telegram messages.

- Total users is always all-time; new users and daily user trends use first-seen date within the inclusive date range.
- Membership count is users in that first-seen cohort who have a recorded successful check. It is historical evidence, never a claim that they remain subscribed. The latest check and latest successful check timestamps are retained.
- Started/completed/unfinished and completion rate use drafts started in the selected range, with their current state. Completion can occur after the range. Cancelled drafts remain unfinished.
- The funnel tracks the furthest step reached per draft. Unfinished/drop-off is attributed to that furthest step, including paused drafts; back navigation does not erase prior reach.
- Submission trends, review statuses, pending and overdue counts use application submission date and current review state.
- Notification counts use job creation date and current state, grouped by kind (`bot` versus `review`). `sent` is a successful Telegram API response, not proof the applicant read the message. `failed` exhausted retries or had a terminal error; pending and sending are separate.
- Date range end is inclusive through the local day's end. Empty cohorts have a 0% completion rate.

Application list exports honor search, status, reviewer, overdue and submission-date filters. PDF reports cap at 300 applications and XLSX at 10,000 to protect shared-host memory; narrow filters to export larger datasets in batches. Individual summaries include applicant/contact/file metadata, status, assignment, aggregate rating and dates; notes and full audit history remain in the console. Statistics exports are separate and only use date filters. There is no automatic conversion of arbitrary uploaded documents to PDF.
