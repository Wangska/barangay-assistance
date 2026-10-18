<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }
    }
}

function valid_philippine_mobile(string $phoneNumber): bool
{
    return preg_match('/^(?:0|\\+63)9\\d{9}$/', $phoneNumber) === 1;
}

function login_attempt_key(string $email): string
{
    return hash('sha256', strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function login_is_rate_limited(string $email): bool
{
    $key = login_attempt_key($email);
    $attempt = $_SESSION['login_attempts'][$key] ?? null;
    if (!$attempt || (time() - (int) $attempt['started_at']) >= 900) {
        unset($_SESSION['login_attempts'][$key]);
        return false;
    }

    return (int) $attempt['count'] >= 5;
}

function record_failed_login(string $email): void
{
    $key = login_attempt_key($email);
    $attempt = $_SESSION['login_attempts'][$key] ?? ['count' => 0, 'started_at' => time()];
    if ((time() - (int) $attempt['started_at']) >= 900) {
        $attempt = ['count' => 0, 'started_at' => time()];
    }
    $attempt['count']++;
    $_SESSION['login_attempts'][$key] = $attempt;
}

function clear_failed_logins(string $email): void
{
    unset($_SESSION['login_attempts'][login_attempt_key($email)]);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php');
    }
    if ($user['status'] !== 'Active') {
        $path=basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH));
        if ($user['role_name'] !== 'Senior Citizen' || !in_array($user['status'], ['Pending','Rejected'],true)) {
            http_response_code(403); exit('This account is not active.');
        }
        if (!in_array($path,['application.php','notifications.php','notification_count.php','mark_notification_read.php','requirement_file.php','profile.php','profile_photo.php'],true)) {
            redirect('/senior/application.php');
        }
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role_name'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
    if ($user['role_name'] === 'Senior Citizen') {
        sync_senior_announcement_notifications((int)$user['user_id']);
    }
    return $user;
}

function audit_log(int $userId, string $action, string $entity, ?int $entityId = null): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, entity, entity_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $action,
        $entity,
        $entityId,
        $_SERVER['REMOTE_ADDR'] ?? 'cli',
        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 250),
    ]);
}

function notify_barangay_staff(string $title, string $message, ?int $applicationUserId = null, ?int $assistanceRequestId = null): void
{
    $stmt = db()->prepare("INSERT INTO notifications (user_id, title, message, application_user_id, assistance_request_id)
        SELECT u.user_id, ?, ?, ?, ? FROM users u JOIN roles r ON r.role_id=u.role_id
        WHERE r.role_name='Barangay Staff' AND u.status='Active'");
    $stmt->execute([$title, $message, $applicationUserId, $assistanceRequestId]);
}

function notify_user(int $userId, string $title, string $message, ?int $applicationUserId = null, ?int $requestId = null): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, title, message, application_user_id, assistance_request_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $title, $message, $applicationUserId, $requestId]);
}

/**
 * Import existing announcements while preserving notification read states.
 * Serialize imports per recipient to prevent duplicate alerts on simultaneous visits.
 */
function sync_senior_announcement_notifications(int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $recipient = $pdo->prepare("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND r.role_name='Senior Citizen' FOR UPDATE");
        $recipient->execute([$userId]);
        if ($recipient->fetchColumn() !== false) {
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, announced_at, notified_at, announcement_id)
                SELECT ?, CASE WHEN a.is_emergency=1 THEN 'Emergency Announcement' ELSE 'New Announcement' END,
                       CONCAT(TRIM(a.title), CHAR(10), TRIM(a.body)), a.created_at, CURRENT_TIMESTAMP, a.announcement_id
                FROM announcements a
                WHERE a.audience IN ('All', 'Senior Citizen')
                  AND NOT EXISTS (
                    SELECT 1 FROM notifications n
                    WHERE n.user_id=? AND n.announcement_id=a.announcement_id
                  )
                ORDER BY a.created_at, a.announcement_id
            ");
            $stmt->execute([$userId, $userId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function upload_valid_id(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        || ($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Your ID file is too large. Choose a JPG, PNG, or PDF no larger than 2 MB.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Your ID upload did not finish. Please select the file and try again.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, and PDF files are accepted.');
    }
    if (!is_dir(UPLOAD_DIR . '/ids')) {
        mkdir(UPLOAD_DIR . '/ids', 0775, true);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = UPLOAD_DIR . '/ids/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save uploaded file.');
    }
    return 'ids/' . $name;
}

function upload_profile_photo(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        || ($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('The profile photo is too large. Choose a JPG, PNG, or WebP no larger than 2 MB.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The profile photo upload did not finish. Please select the file and try again.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, and WebP photos are accepted.');
    }
    if (!is_dir(UPLOAD_DIR . '/profiles')) {
        mkdir(UPLOAD_DIR . '/profiles', 0775, true);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = UPLOAD_DIR . '/profiles/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the profile photo.');
    }
    return 'profiles/' . $name;
}

function profile_photo_storage_path(?string $storedPath): ?string
{
    if ($storedPath === null || preg_match('#^profiles/[a-f0-9]{32}\.(?:jpg|png|webp)$#', $storedPath) !== 1) {
        return null;
    }
    $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
    return is_file($path) ? $path : null;
}

function delete_profile_photo(?string $storedPath): void
{
    $path = profile_photo_storage_path($storedPath);
    if ($path !== null) {
        unlink($path);
    }
}

function valid_id_storage_path(string $storedPath): ?string
{
    if (preg_match('#^ids/[a-f0-9]{32}\.(?:jpg|png|pdf)$#', $storedPath) !== 1) {
        return null;
    }

    $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
    return is_file($path) ? $path : null;
}

function counts_for_dashboard(): array
{
    return [
        'users' => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'seniors' => (int) db()->query('SELECT COUNT(*) FROM senior_citizens')->fetchColumn(),
        'pending_requests' => (int) db()->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Pending'")->fetchColumn(),
        'approved_requests' => (int) db()->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Approved'")->fetchColumn(),
        'rejected_requests' => (int) db()->query("SELECT COUNT(*) FROM assistance_requests WHERE status = 'Rejected'")->fetchColumn(),
        'sms_today' => (int) db()->query('SELECT COUNT(*) FROM sms_logs WHERE sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY')->fetchColumn(),
    ];
}

require_once __DIR__ . '/workflows.php';
