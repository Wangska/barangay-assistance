<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=86400');

$region = $_GET['region'] ?? '';
$province = $_GET['province'] ?? '';
$city = $_GET['city'] ?? '';
if (!is_string($region) || !is_string($province) || !is_string($city) || $region === '' || $province === '' || $city === '') {
    echo '[]';
    exit;
}

$root = dirname(__DIR__);
$locations = json_decode((string) file_get_contents($root . '/assets/data/philippines-locations.json'), true);
$cities = is_array($locations) ? ($locations[$region][$province] ?? null) : null;
if (!is_array($cities) || !in_array($city, $cities, true)) {
    echo '[]';
    exit;
}

$barangays = json_decode((string) file_get_contents($root . '/assets/data/philippines-barangays.json'), true);
$list = is_array($barangays) ? ($barangays[$region][$province][$city] ?? []) : [];
if (!is_array($list)) {
    $list = [];
}

echo json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
