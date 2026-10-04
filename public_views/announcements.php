<?php
require_once __DIR__ . '/../includes/functions.php';
function render_public_announcements(array $roles, string $where): void {
    require_role($roles);
    $rows = db()->query("SELECT * FROM announcements WHERE {$where} ORDER BY created_at DESC")->fetchAll();
    require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
    echo '<h1 class="page-title">Announcements</h1><div class="row g-3">';
    foreach($rows as $r) echo '<div class="col-md-6"><div class="panel h-100" id="announcement-'.(int)$r['announcement_id'].'"><h2 class="h5">'.e($r['title']).'</h2><p>'.e($r['body']).'</p><span class="badge text-bg-primary">'.e($r['audience']).'</span></div></div>';
    echo '</div>';
    require_once __DIR__ . '/../includes/footer.php';
}
