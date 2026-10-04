<?php

$GLOBALS['APP_SHELL_OPEN'] = true;

$u = current_user();

$role = $u['role_name'] ?? '';
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

$menus = [

    'Administrator' => [

        ['Dashboard', '/admin/dashboard.php', 'bi-speedometer2'],
        ['Users', '/admin/users.php', 'bi-people'],
        ['Registrations', '/admin/registrations.php', 'bi-person-check'],
        ['Seniors', '/admin/seniors.php', 'bi-person-hearts'],
        ['Requests', '/admin/requests.php', 'bi-clipboard2-pulse'],
        ['Announcements', '/admin/announcements.php', 'bi-broadcast'],
        ['Events', '/admin/events.php', 'bi-calendar-event'],
        ['Reports', '/admin/reports.php', 'bi-file-earmark-bar-graph'],
        ['SMS Logs', '/admin/sms_logs.php', 'bi-chat-dots'],
        ['Audit Logs', '/admin/audit_logs.php', 'bi-shield-check'],
        ['Settings', '/admin/settings.php', 'bi-gear'],
        ['Backup', '/admin/backup.php', 'bi-database-down']

    ],

    'Barangay Staff' => [

        ['Dashboard', '/staff/dashboard.php', 'bi-speedometer2'],
        ['Notifications', '/staff/notifications.php', 'bi-bell'],
        ['Registrations', '/staff/registrations.php', 'bi-person-check'],
        ['Seniors', '/staff/seniors.php', 'bi-person-hearts'],
        ['Requests', '/staff/requests.php', 'bi-clipboard2-pulse'],
        ['Announcements', '/staff/announcements.php', 'bi-broadcast'],
        ['Events', '/staff/events.php', 'bi-calendar-event'],
        ['Send SMS', '/staff/sms.php', 'bi-chat-dots'],
        ['Reports', '/staff/reports.php', 'bi-printer']

    ],

    'Senior Citizen' => [

        ['Dashboard', '/senior/dashboard.php', 'bi-speedometer2'],
        ['Application', '/senior/application.php', 'bi-list-check'],
        ['Profile', '/senior/profile.php', 'bi-person'],
        ['Notifications', '/senior/notifications.php', 'bi-bell'],
        ['Requests', '/senior/requests.php', 'bi-clipboard-plus'],
        ['Announcements', '/senior/announcements.php', 'bi-broadcast'],
        ['Events', '/senior/events.php', 'bi-calendar-event']

    ],


];

?>

<?php if ($role === 'Senior Citizen' && $u['status'] !== 'Active') {
    $menus[$role] = [
        ['Application','/senior/application.php','bi-list-check'],
        ['Notifications','/senior/notifications.php','bi-bell']
    ];
} ?>
<div class="app-shell">
    <button type="button" class="btn btn-primary mobile-navigation-toggle no-print" aria-expanded="false" aria-controls="main-navigation" data-en="Menu" data-ceb="Menu">Menu</button>

    <aside id="main-navigation" class="sidebar d-flex flex-column" aria-label="Main navigation">

        <div class="sidebar-title">
            <span class="sidebar-mark"><i class="bi bi-heart-pulse-fill"></i></span>
            <span>SeniorCare<small>Barangay Services</small></span>
        </div>

        <?php foreach (($menus[$role] ?? []) as [$label, $href, $icon]): ?>

            <a
                href="<?= BASE_URL . $href ?>"
                class="sidebar-link<?= str_ends_with($currentPath, $href) ? ' active' : '' ?>"
            >

                <i class="bi <?= e($icon) ?>"></i>

                <span><?= translated_label($label) ?></span>

            </a>

        <?php endforeach; ?>

        <div class="mt-auto p-3">

            <hr>

            <div class="text-center mb-3">

                <strong>

                    <i class="bi bi-person-circle"></i>

                    <?= e($u['full_name']) ?>

                </strong>

                <br>

                <small>

                    <?= e($role) ?>

                </small>

            </div>

            <button
                type="button"
                class="sidebar-link text-danger"
                data-bs-toggle="modal"
                data-bs-target="#logout-modal"
            >

                <i class="bi bi-box-arrow-right"></i>

                <?= translated_label('Logout') ?>

            </button>

        </div>

    </aside>

    <div class="modal fade" id="logout-modal" tabindex="-1" aria-labelledby="logout-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content logout-modal">
                <div class="modal-body">
                    <h2 id="logout-modal-title"><span data-en="Log out" data-ceb="Paggawas">Log out</span></h2>
                    <p><span data-en="Are you sure you want to logout?" data-ceb="Sigurado ka ba nga gusto ka mogawas?">Are you sure you want to logout?</span></p>
                    <div class="logout-actions">
                        <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal"><span data-en="Cancel" data-ceb="Kanselahon">Cancel</span></button>
                        <a class="btn btn-primary" href="<?= BASE_URL ?>/logout.php"><span data-en="Log out" data-ceb="Paggawas">Log out</span></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <main class="content">
    <?php if ($role==='Senior Citizen'): ?>
    <div class="accessibility-controls no-print d-flex flex-wrap gap-3 align-items-end mb-3" aria-label="Reading preferences">
        <label><span data-en="Interface language" data-ceb="Pinulongan sa interface">Interface language</span>
            <select id="interface-language" class="form-select"><option value="en">English</option><option value="ceb">Cebuano</option></select>
        </label>
        <button type="button" class="btn btn-outline-primary" id="larger-text" aria-pressed="false" data-en="Larger text" data-ceb="Mas dagkong letra">Larger text</button>
    </div>
    <?php endif; ?>
    <?php if (in_array($role, ['Senior Citizen', 'Barangay Staff'], true)):
        $unreadQuery = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
        $unreadQuery->execute([$u['user_id']]);
        $unreadCount = (int)$unreadQuery->fetchColumn();
    ?>
        <?php
        $notificationPage = $role === 'Barangay Staff' ? '/staff/notifications.php' : '/senior/notifications.php';
        $recentNotifications = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY notification_id DESC LIMIT 6');
        $recentNotifications->execute([$u['user_id']]);
        $recentNotifications = $recentNotifications->fetchAll();
        ?>
        <div class="d-flex justify-content-end mb-3">
            <div class="dropdown notification-menu">
                <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                    <i class="bi bi-bell" aria-hidden="true"></i> <?= translated_label('Notifications') ?>
                    <span class="badge text-bg-danger" data-notification-count data-count-url="<?= BASE_URL ?>/api/notification_count.php" aria-live="polite"><?= $unreadCount ?></span><span class="visually-hidden"> unread</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end notification-panel" data-csrf="<?= e(csrf_token()) ?>" data-read-url="<?= BASE_URL ?>/api/mark_notification_read.php">
                    <p class="notification-refresh" data-notification-update hidden><span data-en="New notifications are available." data-ceb="Adunay bag-ong pahibalo.">New notifications are available.</span> <a href="<?= BASE_URL . $notificationPage ?>"><span data-en="Refresh" data-ceb="I-refresh">Refresh</span></a></p>
                    <div class="notification-list">
                        <?php if (!$recentNotifications): ?>
                            <p class="notification-empty"><span data-en="No notifications yet." data-ceb="Wala pay pahibalo.">No notifications yet.</span></p>
                        <?php endif; ?>
                        <?php foreach ($recentNotifications as $notification):
                            $destination = notification_destination($notification, $role) ?? $notificationPage;
                            $preview = preg_replace('/\s+/u', ' ', trim((string) $notification['message'])) ?? '';
                            if (mb_strlen($preview) > 90) {
                                $preview = mb_substr($preview, 0, 87) . '...';
                            }
                            $when = $notification['notified_at'] ?: ($notification['created_at'] ?? '');
                        ?>
                            <a class="notification-item<?= $notification['is_read'] ? '' : ' unread' ?>" href="<?= BASE_URL . e($destination) ?>" data-notification-link data-notification-id="<?= (int) $notification['notification_id'] ?>" data-read="<?= $notification['is_read'] ? '1' : '0' ?>">
                                <strong><?= e($notification['title']) ?></strong>
                                <?php if ($preview !== ''): ?><span><?= e($preview) ?></span><?php endif; ?>
                                <?php if ($when !== ''): ?><time datetime="<?= e(date('c', strtotime($when))) ?>"><?= e(date('M j, Y g:i A', strtotime($when))) ?></time><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <a class="notification-all" href="<?= BASE_URL . $notificationPage ?>"><span data-en="View all notifications" data-ceb="Tan-awa ang tanang pahibalo">View all notifications</span></a>
                </div>
            </div>
        </div>
    <?php endif; ?>
