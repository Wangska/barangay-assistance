<?php
require_once __DIR__ . '/../includes/functions.php';
$query = $_SERVER['QUERY_STRING'] ?? '';
redirect('/officer/assistance.php' . ($query !== '' ? '?' . $query : ''));
