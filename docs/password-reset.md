# Senior password reset email setup

The flow is: registered email -> six-digit email code -> code and new password -> login.

## Gmail setup on this XAMPP installation

Private settings are in `C:/xamppa/htdocs/barangay-assistance-system/barangay-assistance-storage/mail.php`, in the protected project storage directory. Fill in `from` and `username` with your full Gmail address, set `password` to your Google App Password without spaces, and set `enabled` to `true`. Do not use your normal Google password or share the App Password in chat. No Apache restart is required for this file.

Enable Google 2-Step Verification and create an App Password at https://myaccount.google.com/apppasswords. See https://support.google.com/accounts/answer/185833 for account requirements. Some managed or protected accounts do not offer App Passwords.

SMTP uses smtp.gmail.com, port 587, STARTTLS and certificate verification. PHP cURL with SMTP support is required (included in this XAMPP installation). A successful SMTP submission means Google accepted the message; check the recipient inbox and spam folder to confirm delivery.

For other installations, copy `config/mail.example.php` to `UPLOAD_DIR/mail.php`, or point `APP_MAIL_CONFIG` to a private file outside the document root. Keep credentials out of source control. A disabled private file disables delivery. With no private file, the legacy PHP mail relay remains supported using `PASSWORD_RESET_MAIL_ENABLED=1` and `PASSWORD_RESET_FROM`.

## Database and verification

Existing installations need `database/password_reset_migration.sql`; new databases include the table already. Run `C:/xamppa/php/php.exe tests/password_reset_test.php` and `C:/xamppa/php/php.exe tests/registration_otp_test.php`. These use isolated databases and simulated delivery, and never send real emails.

Only eligible Senior Citizen accounts can reset. Codes expire after 10 minutes, allow five attempts, and work once. Resends replace the previous code; a 60-second cooldown and five sends per account per hour apply. Codes are hashed and never displayed or logged. Failed delivery invalidates the code, preserves throttling, and shows an error instead of continuing as if email was sent.