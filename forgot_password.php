<?php
require_once __DIR__ . '/includes/password_reset.php';
header('Cache-Control: no-store');
$error = '';
$message = '';
$step = isset($_SESSION['reset_email']) ? 'verify' : 'request';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'start_over') {
            unset($_SESSION['reset_email']);
            $step = 'request';
        } elseif ($action === 'request' || $action === 'resend') {
            $email = $action === 'resend' ? ($_SESSION['reset_email'] ?? '') : trim((string)($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
                $error = 'Please enter a valid registered email address.';
            } elseif (!reset_mail_ready()) {
                $error = 'Email delivery is not available yet. Please contact the Barangay Office for help.';
            } elseif (time() - (int)($_SESSION['reset_last_request'] ?? 0) < 60) {
                $error = 'Please wait one minute before requesting another code.';
            } else {
                $_SESSION['reset_last_request'] = time();
                request_reset_code(db(), $email);
                $_SESSION['reset_email'] = $email;
                $step = 'verify';
                $message = 'If this email belongs to an eligible senior account, a code has been requested. Check your inbox and spam folder. Codes expire after 10 minutes.';
            }
        } elseif ($action === 'reset' && isset($_SESSION['reset_email'])) {
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 8 || strlen($password) > 72) {
                $error = 'Use a password between 8 and 72 bytes long.';
            } elseif ($password !== ($_POST['password_confirmation'] ?? '')) {
                $error = 'The passwords do not match.';
            } elseif (complete_password_reset(db(), $_SESSION['reset_email'], trim((string)($_POST['code'] ?? '')), $password)) {
                clear_failed_logins($_SESSION['reset_email']);
                unset($_SESSION['reset_email']);
                session_regenerate_id(true);
                $step = 'done';
                $message = 'Your password has been changed. You can now log in with your new password.';
            } else {
                $error = 'The code is incorrect, expired, or has reached its attempt limit. Request a new code and try again.';
            }
        }
    } catch (Throwable $e) {
        error_log('Password reset service failed: ' . get_class($e));
        $error = 'Password reset is temporarily unavailable. Please try again later or contact the Barangay Office.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password - SeniorCare</title>
<link rel="stylesheet" href="assets/css/forgot.css">
<style>
.container{padding:24px 12px}.card{width:100%;max-width:480px;padding:32px}
label{display:block;text-align:left;margin:18px 0 8px;font-size:18px}
.input-box input{padding:12px;font-size:18px}.error{font-size:17px;line-height:1.5}
.notice{font-size:17px}.secondary{background:#365a7c;margin-top:12px}
input:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #154ac0;outline-offset:3px}
</style>
</head>
<body><main class="container"><div class="card">
<h2><?= $step === 'done' ? 'Password Changed' : 'Reset Password' ?></h2>
<?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="notice" role="status"><?= e($message) ?></div><?php endif; ?>
<?php if ($step === 'request'): ?>
<p class="text">Step 1 of 2: Enter the email address registered to your senior account. We will email you a verification code.</p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action" value="request">
<label for="email">Registered email address</label>
<div class="input-box"><input id="email" type="email" name="email" autocomplete="email" maxlength="150" value="<?= e((string)($_POST['email'] ?? '')) ?>" required></div>
<button type="submit">Send Verification Code</button>
</form>
<?php elseif ($step === 'verify'): ?>
<p class="text">Step 2 of 2: Enter the code sent to <?= e($_SESSION['reset_email']) ?> and choose your new password.</p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action" value="reset">
<label for="code">6-digit verification code</label>
<div class="input-box"><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
<label for="password">New password (at least 8 characters)</label>
<div class="input-box"><input id="password" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
<label for="confirmation">Confirm new password</label>
<div class="input-box"><input id="confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" maxlength="72" required></div>
<button type="submit">Verify Code and Reset Password</button>
</form>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<button class="secondary" name="action" value="resend">Resend Code</button>
<button class="secondary" name="action" value="start_over">Use a Different Email</button>
</form>
<p class="notice">Please wait at least one minute between requests. You can request up to 5 codes per hour. Only the latest code works. If you cannot access your email, contact the Barangay Office.</p>
<?php endif; ?>
<a href="index.php#signin" class="back">Back to Login</a>
</div></main></body></html>
