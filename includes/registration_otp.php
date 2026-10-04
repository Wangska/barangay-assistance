<?php
declare(strict_types=1);
require_once __DIR__.'/password_reset.php';
function registration_email(string $email): string { return strtolower(trim($email)); }
function registration_email_verified(string $email): bool {
    $v = $_SESSION['registration_verified'] ?? [];
    return isset($v['email'],$v['expires_at']) && $v['email'] === registration_email($email) && $v['expires_at'] > time();
}
function send_registration_code(string $email,string $code): bool {
    return app_send_mail($email,'SeniorCare registration verification code',
        "Your registration code is: $code\n\nThis code expires in 10 minutes. Do not share it.\nIf you did not request an account, ignore this email.");

}
function request_registration_code(PDO $pdo,string $email,string $browser,?callable $deliver=null): string {
    $email=registration_email($email);
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>150) return 'invalid';
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO registration_email_codes(email) VALUES (?)')->execute([$email]);
        $s=$pdo->prepare('SELECT * FROM registration_email_codes WHERE email=? FOR UPDATE');
        $s->execute([$email]); $r=$s->fetch(); $now=time();
        if ($now-(int)$r['sent_at']<60 || ($now-(int)$r['window_started_at']<3600 && (int)$r['send_count']>=5)) {
            $pdo->commit(); return 'limited';
        }
        $s=$pdo->prepare('SELECT user_id FROM users WHERE email=?'); $s->execute([$email]); $exists=(bool)$s->fetchColumn();
        $same=$now-(int)$r['window_started_at']<3600;
        $code=(string)random_int(100000,999999);
        $pdo->prepare('UPDATE registration_email_codes SET code_hash=?,browser_hash=?,expires_at=?,attempts=0,sent_at=?,window_started_at=?,send_count=? WHERE email=?')
            ->execute([password_hash($code,PASSWORD_DEFAULT),hash('sha256',$browser),$exists ? 0 : $now+600,$now,$same ? $r['window_started_at'] : $now,$same ? (int)$r['send_count']+1 : 1,$email]);
        $sent=true;
        if (!$exists) {
            try { $sent=($deliver ?? 'send_registration_code')($email,$code); } catch (Throwable $e) { $sent=false; }
        }
        if (!$sent) {
            $pdo->prepare('UPDATE registration_email_codes SET expires_at=0 WHERE email=?')->execute([$email]);
            error_log('Registration verification email delivery failed.');
        }
        $pdo->commit(); return $sent ? 'sent' : 'failed';
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function verify_registration_code(PDO $pdo,string $email,string $browser,string $code): bool {
    $email=registration_email($email); $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM registration_email_codes WHERE email=? FOR UPDATE'); $s->execute([$email]); $r=$s->fetch();
        if (!$r || (int)$r['expires_at']<=time() || (int)$r['attempts']>=5 || !hash_equals($r['browser_hash'],hash('sha256',$browser))) {
            $pdo->commit(); return false;
        }
        if (!preg_match('/^[0-9]{6}$/D',$code) || !password_verify($code,$r['code_hash'])) {
            $pdo->prepare('UPDATE registration_email_codes SET attempts=attempts+1 WHERE email=?')->execute([$email]);
            $pdo->commit(); return false;
        }
        $pdo->prepare("UPDATE registration_email_codes SET expires_at=0,code_hash='' WHERE email=?")->execute([$email]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
