<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$user = require_role(['Administrator']);
$seniorId = filter_input(INPUT_GET, 'senior_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($seniorId === false || $seniorId === null) {
    http_response_code(404);
    exit('Record not found.');
}

$stmt = db()->prepare('SELECT valid_id_path FROM senior_citizens WHERE senior_id = ?');
$stmt->execute([$seniorId]);
$storedPath = (string) $stmt->fetchColumn();
$path = valid_id_storage_path($storedPath);
if ($path === null) {
    http_response_code(404);
    exit('The secure ID file is not available.');
}

$extension = pathinfo($path, PATHINFO_EXTENSION);
$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
audit_log((int) $user['user_id'], 'Viewed secure valid ID', 'senior_citizens', $seniorId);
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="senior-valid-id-' . $seniorId . '.' . $extension . '"');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
