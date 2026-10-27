<?php
require_once __DIR__ . '/../includes/functions.php';
function render_events_page(array $roles): void
{
    $user = require_role($roles);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $stmt = db()->prepare('INSERT INTO events (title, description, event_date, venue, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([trim($_POST['title']), trim($_POST['description']), $_POST['event_date'], trim($_POST['location'] ?? ''), $user['user_id']]);
        audit_log((int)$user['user_id'], 'Created event', 'events', (int)db()->lastInsertId());
        flash('success', 'Event saved.');
        redirect(str_contains($_SERVER['REQUEST_URI'], '/staff/') ? '/staff/events.php' : '/admin/events.php');
    }
    $rows = db()->query('SELECT e.*, u.full_name FROM events e JOIN users u ON u.user_id = e.created_by ORDER BY e.event_date DESC')->fetchAll();
    require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
    ?>
    <h1 class="page-title">Events</h1>
    <div class="panel mb-3">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="col-md-3"><label class="form-label" for="event-title">Title</label><input class="form-control" id="event-title" name="title" required></div>
            <div class="col-md-3"><label class="form-label" for="event-description">Description</label><input class="form-control" id="event-description" name="description" required></div>
            <div class="col-md-3"><label class="form-label" for="event-location">Location</label><input class="form-control" id="event-location" name="location"></div>
            <div class="col-md-3"><label class="form-label" for="event-date">Date</label><input class="form-control" id="event-date" type="datetime-local" name="event_date" required></div>
            <div class="col-12"><button class="btn btn-primary">Add event</button></div>
        </form>
    </div>
    <div class="panel table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Event</th><th>Date</th><th>Location</th><th>Created By</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['title']) ?></strong><div><?= e($r['description']) ?></div></td>
                    <td><?= e($r['event_date']) ?></td>
                    <td><?= e($r['venue']) ?></td>
                    <td><?= e($r['full_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
}
