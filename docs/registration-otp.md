# Senior registration email verification

Registration now begins at register_verify.php. Seniors enter their email, receive a six-digit OTP, verify it, and then complete the existing registration form. No account or uploaded ID is stored before email verification. A verified email is locked to the browser session for one hour and cleared after account creation. Accounts still require the existing Barangay review.

Existing installations must run database/registration_otp_migration.sql. New installations include it in database/database.sql.

Email uses the same private SMTP settings as password reset. See password-reset.md for Gmail and XAMPP setup. Without mail configuration, new registrations cannot proceed and the page explains how to get help.

Codes expire in 10 minutes, allow 5 incorrect attempts, and are single-use. Resends invalidate the old code. Limits of one request per minute and five per hour are stored per email, including across browsers. Codes are hashed and bound to the requesting browser. Existing accounts should use login or password reset.

Run C:/xamppa/php/php.exe tests/registration_otp_test.php for isolated database checks. The tests use an injected mail callback and never send real emails. Real inbox delivery requires testing your configured relay.
