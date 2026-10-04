<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role(['Barangay Staff']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'read_all') {
        $stmt = db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0');
        $stmt->execute([$user['user_id']]);
    } elseif (($_POST['action'] ?? '') === 'read') {
        $stmt = db()->prepare('UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_id=?');
        $stmt->execute([(int)($_POST['notification_id'] ?? 0), $user['user_id']]);
    }
    redirect('/staff/notifications.php');
}
$filter=$_GET['filter'] ?? 'all';
if (!in_array($filter,['all','unread','read'],true)) $filter='all';
$readFilter=$filter==='all' ? '' : ' AND is_read='.($filter==='read'?'1':'0');
$page = max(1, (int)($_GET['page'] ?? 1));
$count = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? $readFilter");
$count->execute([$user['user_id']]);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / 20));
$page = min($page, $pages);
$offset = ($page - 1) * 20;
$stmt = db()->prepare("SELECT n.*, u.status AS registration_status, ar.status AS assistance_status FROM notifications n LEFT JOIN users u ON u.user_id=n.application_user_id LEFT JOIN assistance_requests ar ON ar.request_id=n.assistance_request_id WHERE n.user_id=? $readFilter ORDER BY n.created_at DESC, n.notification_id DESC LIMIT 20 OFFSET $offset");
$stmt->execute([$user['user_id']]);
$notifications = $stmt->fetchAll();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="page-title mb-0">My Notifications</h1>
    <?php if ($unreadCount > 0): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <button class="btn btn-primary" name="action" value="read_all">Mark all as read</button>
    </form>
    <?php endif; ?>
</div>
<p class="text-muted">New senior registrations and assistance applications appear here. Refresh to check for new updates.</p>
<form method="get" class="d-flex gap-2 mb-3">
<label>Show<select class="form-select" name="filter">
<?php foreach(['all'=>'All notifications','unread'=>'Unread','read'=>'Read'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filter===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
</select></label><button class="btn btn-primary">Filter</button>
</form>
<div data-notification-update class="alert alert-info" hidden>New notifications are available. <a href="<?= BASE_URL ?>/staff/notifications.php">Refresh notifications</a></div>
<?php if (!$notifications): ?>
<div class="panel"><i class="bi bi-bell" aria-hidden="true"></i> No notifications yet. New senior registrations and assistance applications will appear here.</div>
<?php endif; ?>
<?php foreach ($notifications as $notification): ?>
<article class="panel mb-3 <?= !$notification['is_read'] ? 'border border-primary' : '' ?>">
    <div class="d-flex flex-wrap justify-content-between gap-2">
        <h2 class="h5"><?= e($notification['title']) ?>
            <?php if (!$notification['is_read']): ?><span class="badge text-bg-primary">Unread</span><?php endif; ?>
        </h2>
        <?php require __DIR__ . '/../includes/notification_dates.php'; ?>
    </div>
        <?php
        $applicationStatus = $notification['registration_status'] ?? $notification['assistance_status'] ?? null;
        $statusColor = match ($applicationStatus) {
            'Active', 'Approved', 'Completed' => 'success',
            'Pending' => 'warning',
            'Rejected' => 'danger',
            'Scheduled' => 'info',
            default => 'secondary',
        };
        $message = $notification['message'];
        if ($notification['title'] === 'New Senior Registration') {
            $message = preg_replace('/ Pending administrator approval\.$/', '', $message);
        } elseif ($notification['title'] === 'New Assistance Application') {
            $message = preg_replace('/ Pending review\.$/', '', $message);
        }
    ?>
    <p style="white-space: pre-wrap; overflow-wrap: anywhere"><?= e($message) ?></p>
    <?php if (!empty($notification['application_user_id']) || !empty($notification['assistance_request_id'])): ?>
    <p>Current application status: <span class="badge text-bg-<?= $statusColor ?>"><?= e($applicationStatus ?? 'Unavailable') ?></span></p>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
        <?php $destination=notification_destination($notification,$user['role_name']); if ($destination): ?>
        <a class="btn btn-primary" href="<?= BASE_URL.e($destination) ?>">View details</a>
        <?php endif; ?>
        <?php if (str_contains($notification['title'], 'Registration')): ?>
        <a class="btn btn-outline-primary" href="<?= BASE_URL ?>/staff/registrations.php">View registrations</a>
        <?php elseif (str_contains($notification['title'], 'Application')): ?>
        <a class="btn btn-outline-primary" href="<?= BASE_URL ?>/staff/requests.php">View assistance requests</a>
        <?php endif; ?>
        <?php if (!$notification['is_read']): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>">
            <button class="btn btn-outline-secondary" name="action" value="read">Mark as read</button>
        </form>
        <?php endif; ?>
    </div>
</article>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
<nav aria-label="Notification pages" class="d-flex align-items-center gap-3">
    <?php if ($page > 1): ?><a class="btn btn-outline-primary" href="?filter=<?= e($filter) ?>&amp;page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-outline-primary" href="?filter=<?= e($filter) ?>&amp;page=<?= $page + 1 ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>