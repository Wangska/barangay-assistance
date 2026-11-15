<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$user = require_role(['Administrator', 'Barangay Staff', 'Senior Citizen']);
$seniorId = filter_input(INPUT_GET, 'senior_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$query = db()->prepare('SELECT u.user_id, u.profile_photo FROM senior_citizens s JOIN users u ON u.user_id = s.user_id WHERE s.senior_id = ?');
$query->execute([$seniorId ?: 0]);
$record = $query->fetch();
$allowed = $record && ($user['role_name'] !== 'Senior Citizen' || (int) $record['user_id'] === (int) $user['user_id']);
$path = $allowed ? profile_photo_storage_path($record['profile_photo'] ?? null) : null;
if ($path === null) {
    http_response_code(404);
    exit('Profile photo not found.');
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="senior-photo.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
