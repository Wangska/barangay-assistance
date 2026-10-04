<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['Administrator']);
$rows = db()->query('SELECT s.*, u.full_name FROM sms_logs s LEFT JOIN users u ON u.user_id=s.user_id ORDER BY s.sent_at DESC LIMIT 300')->fetchAll();
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">SMS Logs</h1><div class="panel table-responsive"><table class="table"><thead><tr><th>User</th><th>Phone</th><th>Message</th><th>Status</th><th>Sent At</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e($r['full_name'] ?? 'Manual') ?></td><td><?= e($r['phone_number']) ?></td><td><?= e($r['message']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['sent_at']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
