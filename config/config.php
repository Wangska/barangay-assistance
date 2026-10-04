<?php
declare(strict_types=1);

define('APP_NAME', 'Senior Citizen Assistance System');
define('BASE_URL', rtrim((string) (getenv('BASE_URL') ?: '/barangay-assistance-system'), '/'));
/*
 * Project storage is protected by its Apache .htaccess. Override this with
 * APP_STORAGE_DIR on a deployed server and give the web-server user write access.
 */
define('UPLOAD_DIR', (string) (getenv('APP_STORAGE_DIR') ?: dirname(__DIR__) . '/barangay-assistance-storage'));
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);

define('DB_HOST', (string) (getenv('DB_HOST') ?: '127.0.0.1'));
define('DB_NAME', (string) (getenv('DB_NAME') ?: 'barangay_assistance_system'));
define('DB_USER', (string) (getenv('DB_USER') ?: 'root'));
define('DB_PASS', (string) (getenv('DB_PASS') ?: ''));

$textbeePath = getenv('TEXTBEE_CONFIG') ?: UPLOAD_DIR . '/textbee.php';
$textbee = is_file($textbeePath) ? require $textbeePath : [];
$textbee = is_array($textbee) ? $textbee : [];
$textbeeEnabled = ($textbee['enabled'] ?? false) === true;
$envApiKey = getenv('TEXTBEE_API_KEY');
$envDeviceId = getenv('TEXTBEE_DEVICE_ID');
define('TEXTBEE_API_KEY', is_string($envApiKey) && $envApiKey !== '' ? $envApiKey : ($textbeeEnabled ? (string) ($textbee['api_key'] ?? '') : ''));
define('TEXTBEE_DEVICE_ID', is_string($envDeviceId) && $envDeviceId !== '' ? $envDeviceId : ($textbeeEnabled ? (string) ($textbee['device_id'] ?? '') : ''));
define('TEXTBEE_API_URL', 'https://api.textbee.dev/api/v1/gateway/send-sms');

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $embeddedForm = (($_GET['embed'] ?? '') === '1')
        && (str_ends_with($requestPath, '/register.php') || str_ends_with($requestPath, '/login.php'));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: ' . ($embeddedForm ? 'SAMEORIGIN' : 'DENY'));
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
