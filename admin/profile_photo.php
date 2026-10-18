<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$user = require_role(['Administrator', 'Barangay Staff', 'Senior Citizen']);
$path = profile_photo_storage_path($user['profile_photo'] ?? null);
if ($path === null) {
    http_response_code(404);
    exit('Profile photo not found.');
}

$extension = pathinfo($path, PATHINFO_EXTENSION);
$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="profile-photo.' . $extension . '"');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
