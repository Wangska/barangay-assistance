<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['Administrator']);
$rows = db()->query('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id ORDER BY a.created_at DESC LIMIT 300')->fetchAll();
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">Audit Logs</h1><div class="panel table-responsive"><table class="table"><thead><tr><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Date</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e($r['full_name'] ?? 'System') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['entity']) ?> #<?= e((string)$r['entity_id']) ?></td><td><?= e($r['ip_address']) ?></td><td><?= e($r['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
