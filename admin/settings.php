<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role(['Administrator']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $allowedKeys = db()->query('SELECT setting_key FROM settings')->fetchAll(PDO::FETCH_COLUMN);
    $postedSettings = $_POST['settings'] ?? [];
    if (!is_array($postedSettings)) {
        flash('danger', 'Invalid settings submission.');
        redirect('/admin/settings.php');
    }
    foreach ($postedSettings as $key => $value) {
        if (!in_array($key, $allowedKeys, true) || !is_scalar($value)) {
            continue;
        }
        db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, trim($value)]);
    }
    audit_log((int)$user['user_id'], 'Updated system settings', 'settings');
    flash('success','Settings saved.'); redirect('/admin/settings.php');
}
$rows = db()->query('SELECT * FROM settings ORDER BY setting_key')->fetchAll();
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">System Settings</h1><div class="panel"><form method="post" class="vstack gap-3"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><?php foreach($rows as $r): ?><label class="form-label"><?= e($r['setting_key']) ?><input class="form-control" name="settings[<?= e($r['setting_key']) ?>]" value="<?= e($r['setting_value']) ?>"></label><?php endforeach; ?><button class="btn btn-primary align-self-start">Save Settings</button></form></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
