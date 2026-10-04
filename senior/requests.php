<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../sms/sms_gateway.php';
$user = require_role(['Senior Citizen']);
$s = db()->prepare('SELECT senior_id FROM senior_citizens WHERE user_id=?'); $s->execute([$user['user_id']]); $seniorId = (int)$s->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type=trim($_POST['request_type'] ?? '');
    $description=trim($_POST['description'] ?? '');
    if (!in_array($type,['Medical Assistance','Medicine Support','Food Assistance','Financial Assistance','Home Visit','Senior Benefits Inquiry'],true) || $description==='') {
        flash('danger','Select an assistance type and describe what you need.'); redirect('/senior/requests.php');
    }
    db()->beginTransaction();
    try {
    $stmt = db()->prepare('INSERT INTO assistance_requests (senior_id, request_type, description) VALUES (?, ?, ?)');
    $stmt->execute([$seniorId, trim($_POST['request_type']), trim($_POST['description'])]);
    $requestId = (int)db()->lastInsertId();
    application_event((int)$user['user_id'],$requestId,'Submitted','Assistance request submitted.',(int)$user['user_id']);
    notify_user((int)$user['user_id'], 'Assistance Request Submitted', 'Request #' . $requestId . ' for ' . trim($_POST['request_type']) . ' has been submitted and is Pending review.',null,$requestId);
    notify_barangay_staff('New Assistance Application', $user['full_name'] . ' submitted request #' . $requestId . ' for ' . trim($_POST['request_type']) . '.', null, $requestId);
    audit_log((int)$user['user_id'], 'Submitted assistance request', 'assistance_requests', $requestId);
    db()->commit();
    } catch(Throwable $error) {
        if(db()->inTransaction()) db()->rollBack();
        flash('danger','Your request could not be saved. Please try again.'); redirect('/senior/requests.php');
    }
    send_sms((int)$user['user_id'], $user['phone_number'], 'Your assistance request has been submitted.');

    flash('success','Request submitted.'); redirect('/senior/requests.php');
}
$rows = db()->prepare('SELECT * FROM assistance_requests WHERE senior_id=? ORDER BY created_at DESC'); $rows->execute([$seniorId]);
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">My Assistance Requests</h1><div class="panel mb-3"><p class="text-muted">Tell us the support you need. Barangay staff will review your request and contact you with the next steps.</p><form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><div class="col-md-3"><select class="form-select" name="request_type"><option>Medical Assistance</option><option>Medicine Support</option><option>Food Assistance</option><option>Financial Assistance</option><option>Home Visit</option><option>Senior Benefits Inquiry</option></select></div><div class="col-md-7"><input class="form-control" name="description" placeholder="Describe the support you need" required></div><div class="col-md-2"><button class="btn btn-primary w-100">Submit Request</button></div></form></div><div class="panel table-responsive"><table class="table"><thead><tr><th>Type</th><th>Description</th><th>Status</th><th>Schedule</th><th>Remarks</th></tr></thead><?php foreach($rows as $r): ?><tr><td><?= e($r['request_type']) ?><div><a href="<?= BASE_URL ?>/senior/application.php?request_id=<?= (int)$r['request_id'] ?>">Track application</a></div></td><td><?= e($r['description']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['schedule_date']) ?></td><td><?= e($r['remarks']) ?></td></tr><?php endforeach; ?></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
