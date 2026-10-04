<?php
require_once __DIR__ . '/../includes/functions.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$user = require_login();
verify_csrf();
$stmt = db()->prepare('UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_id=?');
$stmt->execute([$_POST['notification_id'] ?? 0, $user['user_id']]);
header('Content-Type: application/json');
echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
