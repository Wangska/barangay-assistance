<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../sms/sms_gateway.php';
$user = require_role(['Senior Citizen']);
$s = db()->prepare('SELECT senior_id FROM senior_citizens WHERE user_id=?'); $s->execute([$user['user_id']]); $seniorId = (int)$s->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type=trim($_POST['request_type'] ?? '');
    $description=trim($_POST['description'] ?? '');
    $allowedTypes=['Medical Assistance','Medicine Support','Food Assistance','Financial Assistance'];
    $documentRequired=in_array($type,['Medical Assistance','Medicine Support','Food Assistance','Financial Assistance'],true);
    if (!in_array($type,$allowedTypes,true) || $description==='') {
        flash('danger','Select an assistance type and describe what you need.'); redirect('/senior/requests.php');
    }
    $documentPath=null;
    try {
        $documentPath=upload_request_document($_FILES['document'] ?? []);
    } catch (RuntimeException $error) {
        flash('danger',$error->getMessage()); redirect('/senior/requests.php');
    }
    if ($documentRequired && $documentPath===null) {
        flash('danger','Upload a supporting document for this assistance. Use a JPG, PNG, or PDF no larger than 2 MB.'); redirect('/senior/requests.php');
    }
    db()->beginTransaction();
    try {
    $stmt = db()->prepare('INSERT INTO assistance_requests (senior_id, request_type, description, supporting_document) VALUES (?, ?, ?, ?)');
    $stmt->execute([$seniorId, $type, $description, $documentPath]);
    $requestId = (int)db()->lastInsertId();
    application_event((int)$user['user_id'],$requestId,'Submitted','Assistance request submitted.',(int)$user['user_id']);
    notify_user((int)$user['user_id'], 'Assistance Request Submitted', 'Request #' . $requestId . ' for ' . trim($_POST['request_type']) . ' has been submitted and is Pending review.',null,$requestId);
    notify_barangay_staff('New Assistance Application', $user['full_name'] . ' submitted request #' . $requestId . ' for ' . trim($_POST['request_type']) . '.', null, $requestId);
    audit_log((int)$user['user_id'], 'Submitted assistance request', 'assistance_requests', $requestId);
    db()->commit();
    } catch(Throwable $error) {
        if(db()->inTransaction()) db()->rollBack();
        delete_request_document($documentPath);
        flash('danger','Your request could not be saved. Please try again.'); redirect('/senior/requests.php');
    }
    send_sms((int)$user['user_id'], $user['phone_number'], 'Your assistance request has been submitted.');

    flash('success','Request submitted.'); redirect('/senior/requests.php');
}
$rows = db()->prepare('SELECT * FROM assistance_requests WHERE senior_id=? ORDER BY created_at DESC'); $rows->execute([$seniorId]); $rows = $rows->fetchAll();
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="page-title mb-0">My Assistance Requests</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#request-modal">Submit a Request</button>
</div>
<div class="panel table-responsive">
    <table class="table align-middle">
        <thead><tr><th>Type</th><th>Description</th><th>Status</th><th>Schedule</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">No requests yet. Use Submit a Request to ask for assistance.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['request_type']) ?><div><a href="<?= BASE_URL ?>/senior/application.php?request_id=<?= (int) $r['request_id'] ?>">Track application</a></div></td>
                <td><?= e($r['description']) ?><?php if (!empty($r['supporting_document'])): ?><div><a href="<?= BASE_URL ?>/api/request_document.php?request_id=<?= (int) $r['request_id'] ?>">View supporting document</a></div><?php endif; ?></td>
                <td><?= e($r['status']) ?></td>
                <td><?= e($r['schedule_date']) ?></td>
                <td><?= e($r['remarks']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="modal fade" id="request-modal" tabindex="-1" aria-labelledby="request-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="post" enctype="multipart/form-data" class="modal-content" id="assistance-request-form">
            <div class="modal-header">
                <h2 class="modal-title h5" id="request-modal-title">Submit a Request</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Tell us the support you need. Barangay staff will review your request and contact you with the next steps.</p>
                <div class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="col-md-4"><label class="form-label" for="request-type">Assistance type</label><select class="form-select" id="request-type" name="request_type"><option>Medical Assistance</option><option>Medicine Support</option><option>Food Assistance</option><option>Financial Assistance</option></select></div>
                    <div class="col-md-8"><label class="form-label" for="request-description">Description</label><input class="form-control" id="request-description" name="description" placeholder="Describe the support you need" required></div>
                    <div class="col-12"><label class="form-label" for="request-document">Supporting document</label><input class="form-control" id="request-document" type="file" name="document" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><div class="form-text">JPG, PNG, or PDF, up to 2 MB. Required for medical, medicine, food, and financial assistance.</div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>
<script>
(() => {
    const type = document.getElementById('request-type');
    const file = document.getElementById('request-document');
    const requiredTypes = ['Medical Assistance', 'Medicine Support', 'Food Assistance', 'Financial Assistance'];
    const sync = () => { file.required = requiredTypes.includes(type.value); };
    type.addEventListener('change', sync);
    sync();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
