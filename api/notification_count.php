<?php
require_once __DIR__.'/../includes/functions.php';
$user=require_login();
$q=db()->prepare('SELECT COALESCE(SUM(is_read=0),0) AS unread,COALESCE(MAX(notification_id),0) AS latest FROM notifications WHERE user_id=?');
$q->execute([$user['user_id']]);$r=$q->fetch();
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
echo json_encode(['unread'=>(int)$r['unread'],'latest'=>(int)$r['latest']],JSON_THROW_ON_ERROR);