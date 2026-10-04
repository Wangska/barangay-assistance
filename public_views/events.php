<?php
require_once __DIR__ . '/../includes/functions.php';
function render_public_events(array $roles): void {
    require_role($roles);
    $rows = db()->query('SELECT * FROM events ORDER BY event_date DESC')->fetchAll();
    require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
    echo '<h1 class="page-title">Events</h1><div class="row g-3">';
    foreach($rows as $r) echo '<div class="col-md-6"><div class="panel h-100"><h2 class="h5">'.e($r['title']).'</h2><p>'.e($r['description']).'</p><div class="fw-semibold">'.e($r['event_date']).'</div><div>'.e($r['venue']).'</div></div></div>';
    echo '</div>';
    require_once __DIR__ . '/../includes/footer.php';
}
