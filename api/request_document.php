<?php
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($requestId === false || $requestId === null) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = db()->prepare('SELECT ar.supporting_document, s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id = ar.senior_id WHERE ar.request_id = ?');
$stmt->execute([$requestId]);
$record = $stmt->fetch();
$allowed = $record && (in_array($user['role_name'], ['Administrator', 'Barangay Staff'], true) || (int) $record['user_id'] === (int) $user['user_id']);
$path = $allowed ? request_document_storage_path($record['supporting_document'] ?? null) : null;
if ($path === null) {
    http_response_code(404);
    exit('Document not found.');
}

$extension = pathinfo($path, PATHINFO_EXTENSION);
$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
audit_log((int) $user['user_id'], 'Viewed assistance supporting document', 'assistance_requests', $requestId);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="assistance-request-' . $requestId . '.' . $extension . '"');
readfile($path);
