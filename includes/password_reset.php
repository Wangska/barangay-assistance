<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

function reset_mail_ready(): bool
{
    return app_mail_ready();
}

function send_reset_code(string $email, string $code): bool
{
    return app_send_mail($email, 'Senior Assist password verification code',
        "Your password reset code is: $code\n\nThis code expires in 10 minutes. Do not share it with anyone.\nIf you did not request a password reset, ignore this email.");
}
// Lock the account first so requests and redemptions serialize across browsers.
function reset_account(PDO $pdo, string $email): array|false
{
    $stmt = $pdo->prepare("SELECT u.user_id, u.email FROM users u JOIN roles r ON r.role_id=u.role_id
        WHERE u.email=? AND r.role_name='Senior Citizen' AND u.status IN ('Active','Pending','Rejected') FOR UPDATE");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function request_reset_code(PDO $pdo, string $email, ?callable $deliver = null): void
{
    $pdo->beginTransaction();
    try {
        $user = reset_account($pdo, $email);
        if (!$user) {
            $pdo->commit();
            return;
        }
        $stmt = $pdo->prepare('SELECT * FROM password_reset_codes WHERE user_id=?');
        $stmt->execute([$user['user_id']]);
        $old = $stmt->fetch();
        $now = time();
        if ($old && ($now - (int)$old['sent_at'] < 60 ||
            ($now - (int)$old['window_started_at'] < 3600 && (int)$old['send_count'] >= 5))) {
            $pdo->commit();
            return;
        }
        $sameWindow = $old && $now - (int)$old['window_started_at'] < 3600;
        $code = (string)random_int(100000, 999999);
        $pdo->prepare('INSERT INTO password_reset_codes (user_id,code_hash,expires_at,attempts,sent_at,window_started_at,send_count)
            VALUES (?,?,?,0,?,?,?) ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash),expires_at=VALUES(expires_at),
            attempts=0,sent_at=VALUES(sent_at),window_started_at=VALUES(window_started_at),send_count=VALUES(send_count)')
            ->execute([$user['user_id'], password_hash($code, PASSWORD_DEFAULT), $now+600, $now,
                $sameWindow ? $old['window_started_at'] : $now, $sameWindow ? (int)$old['send_count']+1 : 1]);
        try {
            $sent = ($deliver ?? 'send_reset_code')($user['email'], $code);
        } catch (Throwable $deliveryError) {
            $sent = false;
        }
        if (!$sent) {
            // Preserve throttling even when the mail service fails; no usable code remains.
            $pdo->prepare('UPDATE password_reset_codes SET expires_at=0 WHERE user_id=?')->execute([$user['user_id']]);
            error_log('Password reset email delivery failed.');
            $pdo->commit();
            throw new RuntimeException('Email delivery failed.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function complete_password_reset(PDO $pdo, string $email, string $code, string $password): bool
{
    if (strlen($password) < 8 || strlen($password) > 72) return false;
    $pdo->beginTransaction();
    try {
        $user = reset_account($pdo, $email);
        $stmt = $pdo->prepare('SELECT * FROM password_reset_codes WHERE user_id=? FOR UPDATE');
        $stmt->execute([$user ? $user['user_id'] : 0]);
        $reset = $stmt->fetch();
        if (!$user || !$reset || (int)$reset['expires_at'] <= time() || (int)$reset['attempts'] >= 5) {
            $pdo->commit();
            return false;
        }
        if (!preg_match('/^[0-9]{6}$/D', $code) || !password_verify($code, $reset['code_hash'])) {
            $pdo->prepare('UPDATE password_reset_codes SET attempts=attempts+1 WHERE user_id=?')->execute([$user['user_id']]);
            $pdo->commit();
            return false;
        }
        $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
        $pdo->prepare('UPDATE password_reset_codes SET expires_at=0,code_hash=? WHERE user_id=?')
            ->execute(['', $user['user_id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
