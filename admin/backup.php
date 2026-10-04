<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role(['Administrator']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    audit_log((int)$user['user_id'], 'Downloaded database backup instructions', 'database');
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="backup-command.txt"');
    echo "Run this from XAMPP shell:\n";
    echo "mysqldump -u " . DB_USER . " -p " . DB_NAME . " > barangay_assistance_backup.sql\n";
    exit;
}
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">Backup Database</h1><div class="panel"><p>Download a safe backup command for the configured database. Execute it in the XAMPP shell on the server where MySQL is running.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><button class="btn btn-primary"><i class="bi bi-database-down"></i> Download Backup Command</button></form></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
