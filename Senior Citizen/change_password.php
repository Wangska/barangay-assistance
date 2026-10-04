<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!password_verify($_POST['current_password'] ?? '', $user['password_hash'])) {
        flash('danger', 'Current password is incorrect.');
    } elseif (strlen($_POST['new_password'] ?? '') < 8) {
        flash('danger', 'New password must be at least 8 characters.');
    } else {
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
        $stmt->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $user['user_id']]);
        audit_log((int) $user['user_id'], 'Changed password', 'users', (int) $user['user_id']);
        flash('success', 'Password changed.');
    }
}
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<h1 class="page-title">Change Password</h1>
<div class="panel" style="max-width:560px"><form method="post" class="vstack gap-3"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input class="form-control" type="password" name="current_password" placeholder="Current password" required><input class="form-control" type="password" name="new_password" placeholder="New password" required><button class="btn btn-primary">Save Password</button></form></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
