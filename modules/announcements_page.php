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
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="page-title mb-0">Senior Announcements</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#announcement-modal">New announcement</button>
    </div>
    <div class="panel table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Title</th><th>Audience</th><th>Emergency</th><th>By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['title']) ?></strong><div><?= e($r['body']) ?></div></td>
                    <td><?= e($r['audience']) ?></td>
                    <td><?= $r['is_emergency'] ? 'Yes' : 'No' ?></td>
                    <td><?= e($r['full_name']) ?></td>
                    <td><?= e($r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="modal fade" id="announcement-modal" tabindex="-1" aria-labelledby="announcement-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content">
                <div class="modal-header"><h2 class="modal-title h5" id="announcement-modal-title">New announcement</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body row g-3">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="col-12"><label class="form-label" for="announcement-title">Title</label><input class="form-control" id="announcement-title" name="title" required></div>
                    <div class="col-12"><label class="form-label" for="announcement-body">Details</label><textarea class="form-control" id="announcement-body" name="body" rows="5" required></textarea></div>
                    <div class="col-md-6"><label class="form-label" for="announcement-audience">Audience</label><select class="form-select" id="announcement-audience" name="audience"><option>All</option><option>Senior Citizen</option></select></div>
                    <div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_emergency" id="announcement-emergency"><label class="form-check-label" for="announcement-emergency">Emergency SMS</label></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Post</button></div>
            </form>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
}
