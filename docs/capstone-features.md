# Capstone features and demonstration guide

## Implemented workflows

1. **Duplicate registration review:** lists flag matching first name, last name, and birthdate. Reviewers can open matching records. Administrators must confirm distinct identity and enter remarks before approving flagged registrations.
2. **Application timelines:** registrations and assistance requests record submission, review, decisions, document events, and release completion. Seniors see only their own records.
3. **Requirements:** each registration starts with valid identification. Staff/admins can request additional documents, accept submissions, or request replacements with a reason. Pending/rejected applicants can sign in to upload replacements and track progress. Replacing a document on a rejected registration returns it to Pending.
4. **Distribution records:** approved/scheduled requests can be released once, recording item, quantity, unit, optional cash amount, release date, releasing staff, recipient, and remarks. Saving a release completes the request atomically. Seniors can view receipt details.
5. **Senior interface:** larger controls, readable text, keyboard focus indicators, mobile menu, persistent larger-text option, and English/Cebuano navigation and key action labels. User-entered content retains its original language.
6. **Monthly reports:** registrations, requests, distributions, unique beneficiaries, and cash totals; CSV download and Print / Save PDF. Statuses are current, not reconstructed month-end snapshots.
7. **Notifications:** All/Unread/Read filters, direct application/announcement links, and unread counts refreshed every 30 seconds while the page is visible. A refresh prompt appears for new notifications; content is not replaced while someone is reading.

## Access matrix

| Action | Administrator | Barangay staff | Active senior | Pending/rejected senior |
|---|---|---|---|---|
| Registration list and duplicate flags | Yes | Yes | No | No |
| Review documents / request replacements | Yes | Yes | Own submissions only | Own submissions only |
| Approve/reject registration | Yes | No | No | No |
| Track registration | Management | Management | Own only | Own only |
| Submit assistance requests | No | No | Yes | No |
| Review / release assistance | Yes | Yes | No | No |
| View assistance history | Management | Management | Own only | Existing own records only |
| Reports / export | Yes | Yes | No | No |
| Notifications | Own account | Own account | Own account | Own account |

Inactive accounts are blocked. Document downloads enforce ownership or reviewer role. Mutating forms check CSRF tokens.

## Database setup

The local project database has already been updated. Do not reimport seed data into it.

For fresh installations, import database/database.sql. For older installations, back up first and apply migrations matching the missing schema:

1. database/osca_registration_migration.sql if the expanded registration fields are missing.
2. database/notification_dates_migration.sql if date columns are missing.
3. database/notification_application_migration.sql if application references are missing.
4. database/capstone_features_migration.sql.
5. database/notification_source_migration.sql if announcement references are missing.

Notification ALTER migrations are one-time migrations. Historical review events are not fabricated: old applications start with their recorded submission event. Old announcement notifications may have unknown delivery times.

Configure APP_STORAGE_DIR to private storage outside the web root with PHP write access. Existing identity documents are reused by the checklist. Never use outputs as production document storage.

## Automated verification

Run from the project root:

    C:\xamppa\php\php.exe tests/capstone_smoke.php

This starts a loopback-only PHP server and creates a randomly named capstone_test_* database. It verifies public registration, limited pending access, role/document ownership boundaries, requirements, duplicate verification, resubmission, timelines, releases, reports, notification filters, and CSRF. Temporary database and uploaded files are removed afterward. Local permission to create/drop that test database is required.

Optional browser verification:

1. Set the environment variable CAPSTONE_PREVIEW_HOLD to 1 and run the PHP test.
2. After PREVIEW READY, run tests/capstone_browser.cjs using Node with Playwright and Microsoft Edge.
3. Create outputs/capstone_preview_done to stop the preview; otherwise it expires after ten minutes.
4. Remove the environment variable afterward. Temporary credentials in outputs/capstone_preview.json are removed when the test exits.

## Defense demonstration

1. Register a senior; show staff notification and Pending status.
2. Sign in as the pending senior and open Application.
3. Request a clearer ID as staff, resubmit as senior, then accept it.
4. Demonstrate duplicate flags and administrator verification.
5. Approve registration; show the senior timeline and staff's current Active status.
6. Submit assistance, review, approve, and record release.
7. Show received-assistance details and monthly distributions report.
8. Demonstrate language labels, larger text, mobile menu, filters, and count updates.
9. Show that another senior cannot access the application/document URL.

## User evaluation worksheet

Ask participating staff/seniors to rate clarity, ease of registration, readability, resubmission, notification usefulness, and overall ease of use from 1–5. Record task completion, time taken, errors, and comments. Report actual participant counts and results; do not invent evaluation responses.

## Backup-and-restore demonstration

The existing Backup page provides a command reference; it does not perform automatic backups. Use a demonstration database and private test documents:

- Export the database and separately copy private document storage.
- Restore to a separate demonstration database and storage path.
- Configure that instance to use the restored database/storage.
- Verify registration counts, request history, release totals, and a document download.
- Never overwrite the working database to demonstrate restoration.