<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/claim_queue.php';

require_role(['Barangay Staff', 'Senior Citizen']);

$day = (string) ($_GET['date'] ?? '');
$requestId = (int) ($_GET['request_id'] ?? 0);
$parsed = DateTime::createFromFormat('!Y-m-d', $day);
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (!$parsed || $parsed->format('Y-m-d') !== $day || $day < date('Y-m-d')) {
    http_response_code(422);
    echo json_encode(['hours' => []]);
    exit;
}

echo json_encode([
    'hours' => appointment_slot_choices($day, max(0, $requestId)),
    'holiday' => regular_holiday($day),
]);
