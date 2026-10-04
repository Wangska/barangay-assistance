<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../sms/sms_gateway.php';
function render_announcements_page(array $roles): void
{
    $user = require_role($roles);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $stmt = db()->prepare('INSERT INTO announcements (title, body, audience, is_emergency, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([trim($_POST['title']), trim($_POST['body']), $_POST['audience'], isset($_POST['is_emergency']) ? 1 : 0, $user['user_id']]);
        $id = (int)db()->lastInsertId();
        if (in_array($_POST['audience'], ['All', 'Senior Citizen'], true)) {
            $recipients = db()->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='Senior Citizen'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($recipients as $recipientId) {
                sync_senior_announcement_notifications((int)$recipientId);
            }
        }
        if (isset($_POST['is_emergency'])) {
            $filter = $_POST['audience'] === 'All' ? '' : ' AND r.role_name = ' . db()->quote($_POST['audience']);
            $targets = db()->query("SELECT u.user_id, u.phone_number FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.status='Active'{$filter}")->fetchAll();
            foreach ($targets as $t) {
                send_sms((int)$t['user_id'], $t['phone_number'], 'Emergency announcement: ' . trim($_POST['title']));
            }
        }
        audit_log((int)$user['user_id'], 'Created announcement', 'announcements', $id);
        flash('success', 'Announcement published.');
        redirect(str_contains($_SERVER['REQUEST_URI'], '/staff/') ? '/staff/announcements.php' : '/admin/announcements.php');
    }
    $rows = db()->query('SELECT a.*, u.full_name FROM announcements a JOIN users u ON u.user_id = a.created_by ORDER BY a.created_at DESC')->fetchAll();
    require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
    echo '<h1 class="page-title">Senior Announcements</h1><div class="panel mb-3"><form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="' . csrf_token() . '"><div class="col-md-3"><input class="form-control" name="title" placeholder="Title" required></div><div class="col-md-4"><input class="form-control" name="body" placeholder="Announcement details" required></div><div class="col-md-2"><select class="form-select" name="audience"><option>All</option><option>Senior Citizen</option></select></div><div class="col-md-2 form-check pt-2"><input class="form-check-input" type="checkbox" name="is_emergency" id="em"><label class="form-check-label" for="em">Emergency SMS</label></div><div class="col-md-1"><button class="btn btn-primary w-100">Post</button></div></form></div><div class="panel table-responsive"><table class="table"><thead><tr><th>Title</th><th>Audience</th><th>Emergency</th><th>By</th><th>Date</th></tr></thead><tbody>';
    foreach ($rows as $r) echo '<tr><td><strong>' . e($r['title']) . '</strong><div>' . e($r['body']) . '</div></td><td>' . e($r['audience']) . '</td><td>' . ($r['is_emergency'] ? 'Yes' : 'No') . '</td><td>' . e($r['full_name']) . '</td><td>' . e($r['created_at']) . '</td></tr>';
    echo '</tbody></table></div>';
    require_once __DIR__ . '/../includes/footer.php';
}
