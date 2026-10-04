<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['Administrator']);
header('Content-Type: application/json');
echo json_encode(counts_for_dashboard(), JSON_THROW_ON_ERROR);
