<?php
require_once __DIR__.'/../includes/functions.php';
$user=require_login();
$id=(int)($_GET['id'] ?? 0);
$q=db()->prepare('SELECT * FROM application_requirements WHERE requirement_id=?');
$q->execute([$id]); $r=$q->fetch();
if (!$r || (!in_array($user['role_name'],['Administrator','Barangay Staff'],true) && (int)$r['user_id']!==(int)$user['user_id'])) { http_response_code(404); exit('Document not found.'); }
$path=valid_id_storage_path((string)$r['file_path']);
if (!$path) { http_response_code(404); exit('Document not available.'); }
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
audit_log((int)$user['user_id'],'Viewed requirement document','application_requirements',$id);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="requirement-'.$id.'.'.$extension.'"');
readfile($path);