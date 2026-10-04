<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/registration_otp.php';
$name='registration_test_'.bin2hex(random_bytes(5));
$pdo=new PDO('mysql:host='.DB_HOST.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function check($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
try {
    $pdo->exec("CREATE DATABASE $name"); $pdo->exec("USE $name");
    $pdo->exec('CREATE TABLE users(user_id INT PRIMARY KEY,email VARCHAR(150)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO users VALUES(1,'existing@example.invalid')");
    $pdo->exec(file_get_contents(__DIR__.'/../database/registration_otp_migration.sql'));
    $codes=[]; $deliver=function($email,$code) use (&$codes) { $codes[]=$code; return true; };
    $email='senior@example.invalid'; $browser='test-browser';
    check(request_registration_code($pdo,'invalid',$browser,$deliver)==='invalid','Invalid email rejected');
    request_registration_code($pdo,'existing@example.invalid',$browser,$deliver);
    check(count($codes)===0,'Existing account receives no registration code');
    check(request_registration_code($pdo,$email,$browser,$deliver)==='sent','New email gets code');
    $code=end($codes);
    $s=$pdo->prepare('SELECT code_hash FROM registration_email_codes WHERE email=?'); $s->execute([$email]);
    check(password_verify($code,$s->fetchColumn()),'Only code hash stored');
    check(request_registration_code($pdo,$email,'other-browser',$deliver)==='limited','Cooldown applies across browsers');
    check(!verify_registration_code($pdo,$email,'other-browser',$code),'Code bound to requesting browser');
    check(!verify_registration_code($pdo,'different@example.invalid',$browser,$code),'Code bound to email');
    check(!verify_registration_code($pdo,$email,$browser,'000000'),'Wrong code rejected');
    check(verify_registration_code($pdo,$email,$browser,$code),'Correct code accepted');
    check(!verify_registration_code($pdo,$email,$browser,$code),'Used code rejected');
    $pdo->exec('UPDATE registration_email_codes SET sent_at=0');
    request_registration_code($pdo,$email,$browser,$deliver); $second=end($codes);
    check(!verify_registration_code($pdo,$email,$browser,$code),'Resend replaces old code');
    $pdo->exec('UPDATE registration_email_codes SET expires_at=0');
    check(!verify_registration_code($pdo,$email,$browser,$second),'Expired code rejected');
    $pdo->exec('UPDATE registration_email_codes SET sent_at=0');
    request_registration_code($pdo,$email,$browser,$deliver); $code=end($codes);
    for($i=0;$i<5;$i++) verify_registration_code($pdo,$email,$browser,'000000');
    check(!verify_registration_code($pdo,$email,$browser,$code),'Five wrong attempts lock code');
    $pdo->exec('UPDATE registration_email_codes SET sent_at=0,send_count=5');
    check(request_registration_code($pdo,$email,$browser,$deliver)==='limited','Hourly limit enforced');
    $pdo->exec('UPDATE registration_email_codes SET window_started_at=0,sent_at=0');
    $failedCode='';
    check(request_registration_code($pdo,$email,$browser,function($e,$c) use (&$failedCode) {$failedCode=$c; return false;})==='failed','Delivery failure reported');
    check(!verify_registration_code($pdo,$email,$browser,$failedCode),'Failed delivery code unusable');
    check(!registration_email_verified($email),'Unverified session denied');
    $_SESSION['registration_verified']=['email'=>$email,'expires_at'=>time()+3600];
    check(registration_email_verified(strtoupper($email)),'Verified email accepted after normalization');
    check(!registration_email_verified('tampered@example.invalid'),'Changed email denied');
    $_SESSION['registration_verified']['expires_at']=time()-1;
    check(!registration_email_verified($email),'Expired registration permission denied');
} finally { $pdo->exec("DROP DATABASE $name"); }
