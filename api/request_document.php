<?php
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($requestId === false || $requestId === null) {
    http_response_code(404);
    exit('Document not found.');
}

$file = (string) ($_GET['file'] ?? 'supporting');
if (!in_array($file, ['supporting', 'identity'], true)) {
    http_response_code(404);
    exit('Document not found.');
}
$stmt = db()->prepare('SELECT ar.supporting_document, ar.identity_document, ar.identity_label, s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id = ar.senior_id WHERE ar.request_id = ?');
$stmt->execute([$requestId]);
$record = $stmt->fetch();
$allowed = $record && (in_array($user['role_name'], ['Administrator', 'Barangay Staff'], true) || (int) $record['user_id'] === (int) $user['user_id']);
$stored = $allowed ? ($file === 'identity' ? ($record['identity_document'] ?? null) : ($record['supporting_document'] ?? null)) : null;
$path = request_document_storage_path($stored);
if ($path === null) {
    http_response_code(404);
    exit('Document not found.');
}

$extension = pathinfo($path, PATHINFO_EXTENSION);
$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
audit_log((int) $user['user_id'], 'Viewed assistance supporting document', 'assistance_requests', $requestId);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
$downloadName = $file === 'identity' ? 'identification' : 'assistance-request';
header('Content-Disposition: inline; filename="' . $downloadName . '-' . $requestId . '.' . $extension . '"');
readfile($path);
