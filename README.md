# Senior Citizen Assistance System for Barangay Services

A native PHP 8 and MySQL application for managing senior citizen registrations, assistance requests, announcements, events, SMS notifications, and barangay staff workflows.

See the [development Gantt chart](docs/GANTT_CHART.md) for the proposed 12-week implementation schedule.

## Features

- Role-based access for Administrators, Barangay Staff, and Senior Citizens
- Online senior registration with administrator approval and secure ID review
- OSCA-inspired senior profiles, assistance requests, schedules, notices, reports, SMS logs, and audit logs
- Server-side authorization, CSRF protection, prepared statements, password hashing, and upload MIME checks

## Requirements

- PHP 8.1 or later with `pdo_mysql`, `curl`, and `fileinfo` enabled
- MySQL 5.7+ or a compatible MariaDB release
- Apache/XAMPP with `mod_headers` enabled and `.htaccess` overrides allowed for the legacy `uploads` directory
- A writable private storage directory for uploaded IDs

## Installation

1. Copy the project to your web server, for example `C:\xampp\htdocs\barangay-assistance-system`.
2. Configure the environment variables described below. Do not use the default XAMPP `root` account in production.
3. Import `database/database.sql` in phpMyAdmin or MySQL. This schema is non-destructive and does not create sample users.
4. For local demonstrations only, import `database/seed_development.sql`. It creates the sample accounts listed below.
5. Ensure PHP/Apache can create and write to `APP_STORAGE_DIR`. The default is a `barangay-assistance-storage` directory outside `htdocs`.
6. Visit `http://localhost/barangay-assistance-system/`.

### Existing installations

Back up the database first. Then run `database/osca_registration_migration.sql` once. It is repeatable and adds the expanded registration fields and indexes without removing records.

To permanently remove legacy Resident accounts and their retired concerns workflow, back up first and run `database/remove_resident_users.sql`. This cleanup is irreversible.

Existing ID files in the legacy `uploads/ids` directory are now blocked from direct web access. They are not automatically moved because that should be done under the barangay's data-handling procedure. New uploads use private storage and can only be downloaded by an administrator through the secure review link.

## Configuration

Set these as Apache/PHP environment variables or in your deployment environment:

| Variable | Purpose |
| --- | --- |
| `BASE_URL` | Application path, such as `/barangay-assistance-system` |
| `APP_STORAGE_DIR` | Private absolute directory for uploaded IDs, outside the document root |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Least-privilege MySQL account settings |
| `TEXTBEE_API_KEY` | TextBee API key, sent as the `x-api-key` header |
| `TEXTBEE_DEVICE_ID` | Device ID of the Android phone that sends the SMS |

The local defaults in `config/config.php` are only for a fresh XAMPP development environment. Do not commit real passwords or TextBee credentials. For this XAMPP setup, copy `config/textbee.example.php` to `barangay-assistance-storage/textbee.php`, set `enabled` to `true`, and fill in the API key and device ID. That storage file is excluded from Git.

## SMS gateway contract

When TextBee is enabled, the application POSTs to `https://api.textbee.dev/api/v1/gateway/send-sms` with the `x-api-key` header and this JSON body:

```json
{
  "recipients": ["+639170000000"],
  "message": "Message text",
  "deviceId": "your-device-id"
}
```

Numbers stored as `09XXXXXXXXX` are converted to `+639XXXXXXXXX` before sending. Any 2xx response is logged as **Sent**; other outcomes are logged as failed. The system updates the request or registration even when SMS delivery fails, and tells the administrator that the notification was not sent. The Android phone running TextBee must stay on, with a load and the Send SMS permission granted.

## Development sample accounts

Import `database/seed_development.sql` only in a local demonstration database. All its accounts use the password `password`:

- Administrator: `admin@barangay.test`
- Barangay Staff: `staff@barangay.test`
- Senior Citizen: `senior@barangay.test`

Never deploy these credentials or this seed file to a real barangay system.

## Security and operations

- Uploaded IDs are stored outside the public web root and reviewed through authenticated administrator access.
- The legacy `uploads` directory blocks direct requests as an additional safeguard.
- The database contains sensitive personal and health information. Restrict database access, take encrypted backups, apply a documented retention policy, and collect only data required for the service.
- Use HTTPS in production, a non-root database account, strong unique administrator passwords, and a real SMS credential secret.
- The backup page provides a command reference; schedule and test actual database backups and restores separately.

## Verification

Run PHP syntax checks from the project directory:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Before release, also test registration approval, valid-ID review, role boundaries, request updates, SMS failure handling, and database restoration.

## Capstone workflow additions

See [Capstone features and demonstration guide](docs/capstone-features.md) for workflows, the role matrix, migration order, verification commands, and evaluation/backup demonstration steps.

Run C:\xamppa\php\php.exe tests/capstone_smoke.php for isolated integration verification.
