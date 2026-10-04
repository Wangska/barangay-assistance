<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/password_reset.php';
$name='reset_test_'.bin2hex(random_bytes(5));
$pdo=new PDO('mysql:host='.DB_HOST.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function check($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
try {
    $pdo->exec("CREATE DATABASE $name");
    $pdo->exec("USE $name");
    $pdo->exec("CREATE TABLE roles(role_id INT PRIMARY KEY,role_name VARCHAR(50)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE users(user_id INT PRIMARY KEY,role_id INT,email VARCHAR(150),status VARCHAR(20),password_hash VARCHAR(255)) ENGINE=InnoDB");
    $pdo->exec(file_get_contents(__DIR__.'/../database/password_reset_migration.sql'));
    $pdo->exec("INSERT INTO roles VALUES(1,'Senior Citizen'),(2,'Admin')");
    $pdo->exec("INSERT INTO users VALUES(1,1,'senior@example.invalid','Active','old'),(2,2,'admin@example.invalid','Active','old'),(3,1,'inactive@example.invalid','Inactive','old')");
    $codes=[];
    $deliver=function($email,$code) use (&$codes) { $codes[]=$code; return true; };
    foreach(['missing@example.invalid','admin@example.invalid','inactive@example.invalid'] as $email) request_reset_code($pdo,$email,$deliver);
    check(count($codes)===0,'Unknown, admin, and inactive accounts excluded');
    $email='senior@example.invalid';
    request_reset_code($pdo,$email,$deliver);
    $first=$codes[0];
    check(password_verify($first,$pdo->query('SELECT code_hash FROM password_reset_codes')->fetchColumn()),'Code stored as hash');
    request_reset_code($pdo,$email,$deliver);
    check(count($codes)===1,'Resend cooldown enforced');
    check(!complete_password_reset($pdo,$email,'000000','NewPassword123'),'Wrong code rejected');
    check($pdo->query('SELECT password_hash FROM users WHERE user_id=1')->fetchColumn()==='old','Wrong code cannot change password');
    check(!complete_password_reset($pdo,$email,$first,'short'),'Weak password rejected');
    check(complete_password_reset($pdo,$email,$first,'NewPassword123'),'Valid code changes password');
    check(password_verify('NewPassword123',$pdo->query('SELECT password_hash FROM users WHERE user_id=1')->fetchColumn()),'New password securely hashed');
    check(!complete_password_reset($pdo,$email,$first,'AnotherPassword123'),'Code reuse rejected');
    $pdo->exec('UPDATE password_reset_codes SET sent_at=0');
    request_reset_code($pdo,$email,$deliver);
    $second=end($codes);
    check(!complete_password_reset($pdo,$email,$first,'AnotherPassword123'),'Old code invalid after resend');
    $pdo->exec('UPDATE password_reset_codes SET expires_at=0');
    check(!complete_password_reset($pdo,$email,$second,'AnotherPassword123'),'Expired code rejected');
    $pdo->exec('UPDATE password_reset_codes SET sent_at=0');
    request_reset_code($pdo,$email,$deliver);
    $third=end($codes);
    for($i=0;$i<5;$i++) complete_password_reset($pdo,$email,'000000','AnotherPassword123');
    check(!complete_password_reset($pdo,$email,$third,'AnotherPassword123'),'Five failed attempts lock code');
    $pdo->exec('UPDATE password_reset_codes SET sent_at=0,send_count=5');
    $before=count($codes);
    request_reset_code($pdo,$email,$deliver);
    check(count($codes)===$before,'Hourly send limit enforced');
    $pdo->exec('UPDATE password_reset_codes SET window_started_at=0,sent_at=0');
    $failed = false;
    try { request_reset_code($pdo,$email,function() {return false;}); }
    catch (RuntimeException $e) { $failed = true; }
    check($failed,'Delivery failure is reported to the page');
    check((int)$pdo->query('SELECT expires_at FROM password_reset_codes')->fetchColumn()===0,'Delivery failure invalidates code');
    $pdo->exec('UPDATE password_reset_codes SET sent_at=0');
    $failed = false;
    try { request_reset_code($pdo,$email,function() {throw new RuntimeException('Transport unavailable');}); }
    catch (RuntimeException $e) { $failed = true; }
    check($failed,'Transport exception is reported');
    check((int)$pdo->query('SELECT expires_at FROM password_reset_codes')->fetchColumn()===0,'Transport exception invalidates code');
    check((int)$pdo->query('SELECT send_count FROM password_reset_codes')->fetchColumn()===2,'Failed sends preserve throttling');
    check(!$pdo->inTransaction(),'Failed sends close the transaction');
} finally {
    $pdo->exec("DROP DATABASE $name");
}
