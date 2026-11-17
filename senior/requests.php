<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/claim_queue.php';
require_once __DIR__ . '/../sms/sms_gateway.php';
$user = require_role(['Senior Citizen']);
$s = db()->prepare('SELECT s.*, u.full_name FROM senior_citizens s JOIN users u ON u.user_id=s.user_id WHERE s.user_id=?');
$s->execute([$user['user_id']]);
$profile = $s->fetch();
$seniorId = (int) ($profile['senior_id'] ?? 0);
$savedPension = (string) ($profile['is_pensioner'] ?? '');
$savedSource = strtoupper((string) ($profile['pension_source'] ?? ''));
$defaultPensionStatus = $savedPension === 'Yes' ? 'With Pension' : ($savedPension === 'No' ? 'Without Pension' : '');
$defaultProvider = '';
if ($defaultPensionStatus === 'With Pension') {
    $defaultProvider = str_contains($savedSource, 'GSIS') ? 'GSIS' : (str_contains($savedSource, 'SSS') ? 'SSS' : 'Other');
} elseif ($defaultPensionStatus === 'Without Pension') {
    $defaultProvider = 'None';
}
$defaultPensionAmount = ($profile['monthly_pension'] ?? '') !== '' && $profile['monthly_pension'] !== null
    ? number_format((float) $profile['monthly_pension'], 2, '.', '') : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type=trim($_POST['request_type'] ?? '');
    $description=trim($_POST['description'] ?? '');
    $pensionStatus=trim($_POST['pension_status'] ?? '');
    $pensionProvider=trim($_POST['pension_provider'] ?? '');
    $pensionAmount=trim($_POST['monthly_pension_amount'] ?? '');
    $financialStatus=trim($_POST['financial_status'] ?? '');
    $allowedTypes=['Medical/Financial Assistance','Death Assistance','Special / Indigent Assistance','Other Request'];
    if (!in_array($type,$allowedTypes,true)) {
        flash('danger','Choose a request to continue.'); redirect('/senior/requests.php');
    }
    if ($type==='Death Assistance' && death_assistance_already_requested($seniorId)) {
        flash('danger', death_assistance_limit_message()); redirect('/senior/requests.php');
    }
    $documentPath=null;
    $scheduleStart=null;
    $scheduleEnd=null;
    $identityPath=null;
    $identityLabel=null;
    $seniorIdPath=null;
    $otherIdPath=null;
    try {
        if ($type==='Death Assistance') {
            $documentPath=upload_request_document($_FILES['death_certificate'] ?? []);
            $seniorIdPath=upload_request_document($_FILES['senior_id'] ?? []);
            $otherIdPath=upload_request_document($_FILES['other_id'] ?? []);
            if ($documentPath===null) {
                throw new RuntimeException('Upload the death certificate. Use a JPG, PNG, or PDF no larger than 2 MB.');
            }
            if ($seniorIdPath!==null) {
                $identityPath=$seniorIdPath;
                $identityLabel='Senior ID';
                delete_request_document($otherIdPath);
            } elseif ($otherIdPath!==null) {
                $identityPath=$otherIdPath;
                $identityLabel='Other ID';
            } else {
                throw new RuntimeException('Upload a Senior ID, or any valid ID if a Senior ID is not available.');
            }
            $description='Death assistance request.';
            $pensionStatus='';
            $pensionProvider='';
            $pensionAmount='';
            $financialStatus='';
        } elseif ($type==='Other Request') {
            if ($description==='' || strlen($description)>2000) {
                throw new RuntimeException('Explain the other request.');
            }
            [$scheduleStart, $scheduleEnd] = appointment_slot_values((string) ($_POST['appointment_date'] ?? ''), (string) ($_POST['appointment_hour'] ?? ''));
            $documentPath=upload_request_document($_FILES['document'] ?? []);
            $pensionStatus='';
            $pensionProvider='';
            $pensionAmount='';
            $financialStatus='';
        } else {
            $validMoney=$pensionAmount==='' || preg_match('/^\d{1,10}(\.\d{1,2})?$/',$pensionAmount)===1;
            $pensionMatches=($pensionStatus==='Without Pension' && $pensionProvider==='None')
                || ($pensionStatus==='With Pension' && in_array($pensionProvider,['GSIS','SSS','Other'],true));
            if (!in_array($financialStatus,['Financially Stable','Low Income','Indigent'],true)) {
                throw new RuntimeException('Choose a financial status.');
            }
            if (!$pensionMatches) {
                throw new RuntimeException('Choose With Pension and GSIS, SSS, or Other, or choose Without Pension and None.');
            }
            if (!$validMoney) {
                throw new RuntimeException('Enter the monthly pension as a number, or leave it blank.');
            }
            if ($description==='' || strlen($description)>2000) {
                throw new RuntimeException('Explain the reason for the request.');
            }
            $documentPath=upload_request_document($_FILES['document'] ?? []);
            if ($documentPath===null) {
                $documentName=$type==='Medical/Financial Assistance' ? 'supporting document or medical prescription' : 'supporting document';
                throw new RuntimeException('Upload a '.$documentName.'. Use a JPG, PNG, or PDF no larger than 2 MB.');
            }
        }
    } catch (RuntimeException $error) {
        delete_request_document($documentPath);
        delete_request_document($identityPath);
        delete_request_document($seniorIdPath);
        delete_request_document($otherIdPath);
        flash('danger',$error->getMessage()); redirect('/senior/requests.php');
    }
    $slotLock = null;
    if ($type==='Other Request') {
        $slotLock = 'appt-' . str_replace(['-', ':', ' '], '', (string) $scheduleStart);
        $locked = db()->query('SELECT GET_LOCK(' . db()->quote($slotLock) . ', 5)')->fetchColumn();
        if ((string) $locked !== '1') {
            delete_request_document($documentPath);
            flash('danger', 'That hour is being booked. Try again.');
            redirect('/senior/requests.php');
        }
    }
    try {
    db()->beginTransaction();
    try {
    if ($type==='Other Request' && appointment_slot_taken(db(), (string) $scheduleStart, (string) $scheduleEnd, 0) >= appointment_slots_per_hour()) {
        throw new RuntimeException('That hour is full. Choose another open hour.');
    }
    if ($type==='Death Assistance') {
        $lock = db()->prepare('SELECT senior_id FROM senior_citizens WHERE senior_id=? FOR UPDATE');
        $lock->execute([$seniorId]);
        if (death_assistance_already_requested($seniorId)) {
            throw new RuntimeException(death_assistance_limit_message());
        }
    }
    $stmt = db()->prepare('INSERT INTO assistance_requests (senior_id, request_type, description, supporting_document, identity_document, identity_label, pension_status, pension_provider, monthly_pension_amount, financial_status, schedule_date, schedule_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$seniorId, $type, $description, $documentPath, $identityPath, $identityLabel, $pensionStatus!==''?$pensionStatus:null, $pensionProvider!==''?$pensionProvider:null, $pensionAmount===''?null:$pensionAmount, $financialStatus!==''?$financialStatus:null, $scheduleStart, $scheduleEnd]);
    $requestId = (int)db()->lastInsertId();
    $appointmentWhen = $type==='Other Request' ? appointment_window_label((string) $scheduleStart, (string) $scheduleEnd) : '';
    $submittedNote = $type==='Other Request' ? 'Other request submitted for ' . $appointmentWhen . '.' : 'Assistance request submitted.';
    application_event((int)$user['user_id'],$requestId,'Submitted',$submittedNote,(int)$user['user_id']);
    notify_user((int)$user['user_id'], $type==='Other Request' ? 'Other Request Submitted' : 'Assistance Request Submitted', 'Request #' . $requestId . ' for ' . trim($_POST['request_type']) . ' has been submitted and is Pending review.' . ($appointmentWhen !== '' ? ' Appointment: ' . $appointmentWhen . '.' : ''),null,$requestId);
    notify_barangay_staff('New Assistance Application', $user['full_name'] . ' submitted request #' . $requestId . ' for ' . trim($_POST['request_type']) . '.', null, $requestId);
    audit_log((int)$user['user_id'], 'Submitted assistance request', 'assistance_requests', $requestId);
    db()->commit();
    } catch(Throwable $error) {
        if(db()->inTransaction()) db()->rollBack();
        delete_request_document($documentPath);
        delete_request_document($identityPath);
        $message = $error instanceof RuntimeException && ($error->getMessage()===death_assistance_limit_message() || str_contains($error->getMessage(), 'hour'))
            ? $error->getMessage()
            : 'Your request could not be saved. Please try again.';
        flash('danger',$message); redirect('/senior/requests.php');
    }
    } finally {
        if ($slotLock !== null) {
            db()->query('SELECT RELEASE_LOCK(' . db()->quote($slotLock) . ')');
        }
    }
    send_sms((int)$user['user_id'], $user['phone_number'], 'Your assistance request has been submitted.');

    flash('success','Request submitted.'); redirect('/senior/requests.php');
}
$rows = db()->prepare('SELECT * FROM assistance_requests WHERE senior_id=? ORDER BY created_at DESC'); $rows->execute([$seniorId]); $rows = $rows->fetchAll();
$deathAlreadyRequested = death_assistance_already_requested($seniorId);
$eventsByRequest = [];
$releasesByRequest = [];
$queuesByRequest = [];
if ($rows) {
    $requestIds = array_map(static fn (array $row): int => (int) $row['request_id'], $rows);
    $marks = implode(',', array_fill(0, count($requestIds), '?'));
    $eventQuery = db()->prepare("SELECT * FROM application_events WHERE request_id IN ($marks) ORDER BY event_id");
    $eventQuery->execute($requestIds);
    foreach ($eventQuery as $event) {
        $eventsByRequest[(int) $event['request_id']][] = $event;
    }
    $releaseQuery = db()->prepare("SELECT d.*, u.full_name AS released_by_name FROM assistance_distributions d JOIN users u ON u.user_id = d.released_by WHERE d.request_id IN ($marks)");
    $releaseQuery->execute($requestIds);
    $queueQuery = db()->prepare("SELECT * FROM claim_queues WHERE request_id IN ($marks) ORDER BY queue_date, queue_number");
    $queueQuery->execute($requestIds);
    foreach ($queueQuery as $queue) {
        $queuesByRequest[(int) $queue['request_id']][] = $queue;
    }
    foreach ($releaseQuery as $release) {
        $releasesByRequest[(int) $release['request_id']] = $release;
    }
}
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="page-title mb-0">My Assistance Requests</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#request-modal">Submit a Request</button>
</div>
<div class="panel table-responsive">
    <table class="table align-middle">
        <thead><tr><th>Type</th><th>Reason</th><th>Status</th><th>Requested</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">No requests yet. Use Submit a Request to ask for assistance.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <div class="text-nowrap"><?= e($r['request_type']) ?></div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#track-modal" data-track-id="<?= (int) $r['request_id'] ?>">Track application</button>
                    <?php foreach ($queuesByRequest[(int) $r['request_id']] ?? [] as $queue):
                        $queueLabel = claim_queue_label($queue);
                        $queueKind = $queue['queue_type'] === 'Appointment' ? 'Appointment queue' : 'Release queue';
                        $queueWhen = date('F j, Y', strtotime((string) $queue['queue_date']));
                        if ($queue['queue_type'] === 'Appointment') {
                            $visitWindow = appointment_window_label($r['schedule_date'] ?? null, $r['schedule_end'] ?? null, false);
                        } else {
                            $visitWindow = release_claim_hours($r['schedule_date'] ?? null, $r['schedule_end'] ?? null, (string) $queue['queue_date']);
                        }
                        if ($visitWindow !== '') {
                            $queueWhen .= ', ' . $visitWindow;
                        }
                    ?>
                        <button type="button" class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#queue-modal" data-queue-kind="<?= e($queueKind) ?>" data-queue-number="<?= e($queueLabel) ?>" data-queue-when="<?= e($queueWhen) ?>" data-queue-name="<?= e($profile['full_name'] ?? $user['full_name']) ?>" data-queue-request="<?= e($r['request_type']) ?>" data-queue-control="<?= e((string) ($profile['id_control_number'] ?? '')) ?>" aria-label="Queue <?= e($queueLabel) ?>"><?= e($queueLabel) ?></button>
                    <?php endforeach; ?>
                    </div>
                </td>
                <td>
                    <div><?= e($r['request_type']==='Death Assistance' ? 'Death certificate and identification' : $r['description']) ?></div>
                    <?php
$listedRelease = $releasesByRequest[(int) $r['request_id']] ?? null;
$claimLine = $listedRelease ? release_claim_hours($r['schedule_date'] ?? null, $r['schedule_end'] ?? null, (string) $listedRelease['released_at']) : '';
?>
<?php if ($r['request_type'] === 'Other Request' && !empty($r['schedule_date'])): ?><div class="small text-muted mt-1">Appointment: <?= e(appointment_window_label((string) $r['schedule_date'], $r['schedule_end'] ?? null)) ?></div><?php if ($r['status'] === 'Rejected'): ?><div class="small text-danger mt-1" data-en="Declined. You can submit another appointment." data-ceb="Gibalibaran. Makasumite ka og laing appointment.">Declined. You can submit another appointment.</div><?php endif; ?><?php elseif ($claimLine !== ''): ?><div class="small text-muted mt-1">Claim: <?= e(appointment_window_label((string) $r['schedule_date'], $r['schedule_end'] ?? null)) ?></div><?php endif; ?>
                    <?php if (!empty($r['supporting_document']) || !empty($r['identity_document'])): ?>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <?php if (!empty($r['supporting_document'])): document_preview_button((int) $r['request_id'], (string) $r['supporting_document'], request_file_label($r), 'supporting', 'btn btn-sm btn-outline-primary text-nowrap'); endif; ?>
                        <?php if (!empty($r['identity_document'])): document_preview_button((int) $r['request_id'], (string) $r['identity_document'], request_file_label($r, 'identity'), 'identity', 'btn btn-sm btn-outline-primary text-nowrap'); endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td><?= e(request_status_label((string) $r['status'])) ?></td>
                <td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                <td><?= e($r['remarks']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php foreach ($rows as $r):
    $requestEvents = $eventsByRequest[(int) $r['request_id']] ?? [];
    $release = $releasesByRequest[(int) $r['request_id']] ?? null;
?>
<template id="track-source-<?= (int) $r['request_id'] ?>">
    <p>Current status: <strong><?= e(request_status_label((string) $r['status'])) ?></strong></p>
    <?php if ($r['request_type'] === 'Other Request' && $r['status'] === 'Rejected'): ?>
    <p class="alert alert-warning" role="note" data-en="This appointment was declined. You can submit another appointment. The reason is below." data-ceb="Gibalibaran kini nga appointment. Makasumite ka og laing appointment. Ang rason anaa sa ubos.">This appointment was declined. You can submit another appointment. The reason is below.</p>
    <?php endif; ?>
    <?php if (claim_id_reminder_needed((string) $r['status'], $release)): ?>
    <p class="alert alert-info" role="note" data-en="Note: Please bring a Senior Citizen ID or any valid ID to claim the assistance." data-ceb="Timan-i: Palihog pagdala og Senior Citizen ID o bisan unsang balidong ID aron makuha ang tabang.">Note: Please bring a Senior Citizen ID or any valid ID to claim the assistance.</p>
    <?php endif; ?>
    <p><?= e($r['request_type']) ?><?= $r['request_type'] === 'Death Assistance' ? '' : ': ' . e($r['description']) ?></p>
    <p>Requested <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></p>
    <?php if (!empty($r['schedule_date'])): ?><p><?= $r['request_type'] === 'Other Request' ? 'Appointment' : (($release && release_claim_hours($r['schedule_date'] ?? null, $r['schedule_end'] ?? null, (string) $release['released_at']) !== '') ? 'Claim' : 'Schedule') ?>: <?= e(appointment_window_label((string) $r['schedule_date'], $r['schedule_end'] ?? null)) ?></p><?php endif; ?>
    <?php foreach ($queuesByRequest[(int) $r['request_id']] ?? [] as $queue): ?>
        <p><?= e($queue['queue_type']) ?> queue <?= e(claim_queue_label($queue)) ?> on <?= e(date('M j, Y', strtotime($queue['queue_date']))) ?><?= $queue['claimed_at'] ? ' · Claimed' : '' ?></p>
    <?php endforeach; ?>
    <?php if (trim((string) $r['remarks']) !== ''): ?><p><?= $r['status'] === 'Rejected' ? 'Reason' : 'Remarks' ?>: <?= e($r['remarks']) ?></p><?php endif; ?>
    <h3 class="h6">Application timeline</h3>
    <ol class="timeline">
        <?php if (!$requestEvents): ?>
            <li><strong>Submitted</strong><div>Assistance request submitted.</div><time><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></time></li>
        <?php endif; ?>
        <?php foreach ($requestEvents as $event): ?>
            <li><strong><?= e(request_status_label((string) $event['status'])) ?></strong><div><?= e($event['remarks']) ?></div><time><?= e(date('M j, Y g:i A', strtotime($event['created_at']))) ?></time></li>
        <?php endforeach; ?>
    </ol>
    <?php if ($release): ?>
        <h3 class="h6">Assistance received</h3>
        <p><?= e($release['item']) ?> — <?= e($release['quantity']) ?> <?= e($release['unit']) ?></p>
        <p>Amount: <?= $release['amount'] === null ? 'Not applicable' : 'PHP ' . e(number_format((float) $release['amount'], 2)) ?></p>
        <p>Released <?= e(format_release_date((string) $release['released_at'])) ?><?php $claimHours = release_claim_hours($r['schedule_date'] ?? null, $r['schedule_end'] ?? null, (string) $release['released_at']); if ($claimHours !== ''): ?>, <?= e($claimHours) ?><?php endif; ?> by <?= e($release['released_by_name']) ?>. Received by <?= e($release['received_by']) ?>.</p>
        <?php if (trim((string) $release['remarks']) !== ''): ?><p><?= e($release['remarks']) ?></p><?php endif; ?>
    <?php endif; ?>
</template>
<?php endforeach; ?>
<div class="modal fade" id="track-modal" tabindex="-1" aria-labelledby="track-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="track-modal-title">Application tracking</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="track-modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="queue-modal" tabindex="-1" aria-labelledby="queue-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="queue-modal-title">Queue</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <article class="panel mb-0" id="queue-slip">
                    <div class="queue-slip-head">
                        <img class="queue-slip-seal" src="<?= e(BASE_URL) ?>/assets/images/cebu-city-seal.png?v=20261115c" alt="Official Seal of the City of Cebu">
                        <p class="text-muted queue-slip-place">Barangay Tungkil</p>
                        <img class="queue-slip-seal" src="<?= e(BASE_URL) ?>/assets/images/minglanilla-seal.png?v=20261115q" alt="Official Seal of Minglanilla, Cebu">
                    </div>
                    <h3 class="h5 mb-1 text-center" id="queue-slip-kind"></h3>
                    <p class="display-4 fw-bold mb-2 text-center" id="queue-slip-number"></p>
                    <p class="mb-1" id="queue-slip-when"></p>
                    <p class="mb-1" id="queue-slip-name"></p>
                    <p class="mb-1" id="queue-slip-request"></p>
                    <p class="mb-0" id="queue-slip-control"></p>
                </article>
            </div>
            <div class="modal-footer flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="download-queue-png">Download PNG</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="request-modal" tabindex="-1" aria-labelledby="request-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="post" enctype="multipart/form-data" class="modal-content" id="assistance-request-form">
            <div class="modal-header">
                <h2 class="modal-title h5" id="request-modal-title">Choose a request</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="request_type" id="request-type" value="">
                <div id="request-choices">
                    <div class="d-grid gap-2">
                    <p class="text-muted mb-1">This request is for <?= e($profile['full_name'] ?? $user['full_name']) ?>. The first three involve money. Other Request is for a visit or another concern. The request date is saved when you submit it.</p>
                    <button type="button" class="btn btn-outline-primary text-start py-3" data-choose-request="Medical/Financial Assistance"><strong>Medical/Financial Assistance</strong><span class="d-block small">For medicine, a prescription, or financial support.</span></button>
                    <?php if ($deathAlreadyRequested): ?>
                    <button type="button" class="btn btn-outline-secondary text-start py-3" disabled><strong>Death Assistance</strong><span class="d-block small" data-en="<?= e(death_assistance_limit_message()) ?>" data-ceb="Ang tabang sa kamatayon mahimong hangyoon kausa ra. Aduna na kay hangyo niini."><?= e(death_assistance_limit_message()) ?></span></button>
                    <?php else: ?>
                    <button type="button" class="btn btn-outline-primary text-start py-3" data-choose-request="Death Assistance"><strong>Death Assistance</strong><span class="d-block small" data-en="For the family or authorized beneficiary. This can be requested only once. The barangay reference amount is PHP <?= e(number_format((float) death_assistance_amount(), 2)) ?>." data-ceb="Para sa pamilya o gitugutan nga makadawat. Kini mahimong hangyoon kausa ra. Ang kantidad sa barangay kay PHP <?= e(number_format((float) death_assistance_amount(), 2)) ?>.">For the family or authorized beneficiary. This can be requested only once. The barangay reference amount is PHP <?= e(number_format((float) death_assistance_amount(), 2)) ?>.</span></button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary text-start py-3" data-choose-request="Special / Indigent Assistance"><strong>Special / Indigent Assistance</strong><span class="d-block small">For seniors with no regular income, no pension, or limited resources.</span></button>
                    <button type="button" class="btn btn-outline-primary text-start py-3" data-choose-request="Other Request"><strong data-en="Other Request" data-ceb="Ubang hangyo">Other Request</strong><span class="d-block small" data-en="For a visit or another concern. You choose a Monday to Friday hour. Saturday, Sunday, and regular holidays are closed. This does not involve money." data-ceb="Para sa pagbisita o laing hangyo. Pilia ang Lunes hangtod Biyernes. Sirado ang Sabado, Dominggo, ug regular holiday. Kini walay kwarta.">For a visit or another concern. You choose a Monday to Friday hour. Saturday, Sunday, and regular holidays are closed. This does not involve money.</span></button>
                    </div>
                </div>
                <div id="request-details" hidden>
                    <div id="request-standard" class="row g-3">
                        <div class="col-12"><p class="text-muted mb-0">A pension, including GSIS or SSS, does not stop this request.</p></div>
                        <div class="col-md-6"><label class="form-label" for="financial-status">Financial status</label><select class="form-select" id="financial-status" name="financial_status"><option value="">Choose option</option><option value="Financially Stable">Financially Stable</option><option value="Low Income">Low Income</option><option value="Indigent">Indigent</option></select></div>
                        <div class="col-md-6"><label class="form-label" for="pension-status">Pension status</label><select class="form-select" id="pension-status" name="pension_status"><option value="">Choose option</option><option value="With Pension"<?= $defaultPensionStatus==='With Pension'?' selected':'' ?>>With Pension</option><option value="Without Pension"<?= $defaultPensionStatus==='Without Pension'?' selected':'' ?>>Without Pension</option></select></div>
                        <div class="col-md-6"><label class="form-label" for="pension-provider">Pension provider</label><select class="form-select" id="pension-provider" name="pension_provider"><option value="">Choose option</option><?php foreach (['GSIS','SSS','Other','None'] as $provider): ?><option value="<?= e($provider) ?>"<?= $defaultProvider===$provider?' selected':'' ?>><?= $provider ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label" for="pension-amount">Monthly pension amount</label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control" id="pension-amount" type="number" min="0" step="0.01" name="monthly_pension_amount" placeholder="Optional" value="<?= e($defaultPensionAmount) ?>"></div></div>
                        <div class="col-12"><label class="form-label" for="request-description">Reason for requesting assistance</label><textarea class="form-control" id="request-description" name="description" rows="3" maxlength="2000" placeholder="Explain why this assistance is needed"></textarea></div>
                        <div class="col-12"><label class="form-label" id="request-document-label" for="request-document">Supporting document</label><input class="form-control" id="request-document" type="file" name="document" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><div class="form-text">JPG, PNG, or PDF, up to 2 MB.</div></div>
                    </div>
                    <div id="request-other" class="row g-3" hidden>
                        <div class="col-12"><p class="text-muted mb-0" data-en="This request does not involve money. Choose a Monday to Friday hour from 8:00 AM to 5:00 PM. Saturday, Sunday, and regular holidays are closed. Each hour has <?= (int) appointment_slots_per_hour() ?> seats. A supporting document is optional." data-ceb="Kini nga hangyo walay kwarta. Pilia ang Lunes hangtod Biyernes, 8:00 AM hangtod 5:00 PM. Sirado ang Sabado, Dominggo, ug regular holiday. Matag oras adunay <?= (int) appointment_slots_per_hour() ?> ka lingkoranan. Ang dokumento nga pamatuod opsyonal.">This request does not involve money. Choose a Monday to Friday hour from 8:00 AM to 5:00 PM. Saturday, Sunday, and regular holidays are closed. Each hour has <?= (int) appointment_slots_per_hour() ?> seats. A supporting document is optional.</p></div>
                        <?php appointment_slot_fields(0, null); ?>
                        <div class="col-12"><label class="form-label" for="other-description">What is this request for?</label><textarea class="form-control" id="other-description" name="description" rows="3" maxlength="2000" placeholder="Explain the visit or concern"></textarea></div>
                        <div class="col-12"><label class="form-label d-flex align-items-baseline gap-2" for="other-document"><span data-en="Supporting document" data-ceb="Dokumento nga pamatuod">Supporting document</span> <span class="text-muted fw-normal" data-en="Optional" data-ceb="Opsyonal">Optional</span></label><input class="form-control" id="other-document" type="file" name="document" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><div class="form-text">JPG, PNG, or PDF, up to 2 MB.</div></div>
                    </div>
                    <div id="request-death" class="row g-3" hidden>
                        <div class="col-12"><p class="text-muted mb-0">Upload the death certificate and a Senior ID. If a Senior ID is not available, upload any valid ID instead.</p></div>
                        <div class="col-12"><label class="form-label" for="death-certificate">Death certificate</label><input class="form-control" id="death-certificate" type="file" name="death_certificate" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><div class="form-text">JPG, PNG, or PDF, up to 2 MB.</div></div>
                        <div class="col-md-6"><label class="form-label" for="senior-id">Senior ID</label><input class="form-control" id="senior-id" type="file" name="senior_id" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><div class="form-text">Use this when the senior ID is available.</div></div>
                        <div class="col-md-6"><label class="form-label" for="other-id">Any ID</label><input class="form-control" id="other-id" type="file" name="other_id" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><div class="form-text">Use this only when a Senior ID is not available.</div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary me-auto" id="request-back" hidden>Back</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="request-submit" hidden>Submit Request</button>
            </div>
        </form>
    </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<style>
.flatpickr-wrapper { display: block; width: 100%; }
.flatpickr-calendar.static { margin-top: .4rem; }
.appointment-date-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%230d7b7b'%3E%3Cpath d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .85rem center;
    background-size: 1.15rem 1.15rem;
    padding-right: 2.6rem;
    cursor: pointer;
}
.flatpickr-day.selected, .flatpickr-day.selected:hover { background: #0d7b7b; border-color: #0d7b7b; color: #fff; }
.flatpickr-day.flatpickr-disabled, .flatpickr-day.flatpickr-disabled:hover { color: #c5d0d4; background: transparent; cursor: not-allowed; }
.flatpickr-weekday:nth-child(7),
.flatpickr-day.is-sunday,
.flatpickr-day.is-holiday,
.flatpickr-day.is-sunday.flatpickr-disabled,
.flatpickr-day.is-holiday.flatpickr-disabled,
.flatpickr-day.is-sunday.flatpickr-disabled:hover,
.flatpickr-day.is-holiday.flatpickr-disabled:hover { color: #d1242f; }
.flatpickr-day.is-sunday, .flatpickr-day.is-holiday { font-weight: 700; }
.flatpickr-day.is-holiday,
.flatpickr-day.is-holiday.flatpickr-disabled,
.flatpickr-day.is-holiday.flatpickr-disabled:hover { background: #fde8ea; }
</style>
<script>
(() => {
    const modal = document.getElementById('request-modal');
    const choices = document.getElementById('request-choices');
    const details = document.getElementById('request-details');
    const standard = document.getElementById('request-standard');
    const other = document.getElementById('request-other');
    const death = document.getElementById('request-death');
    const typeInput = document.getElementById('request-type');
    const title = document.getElementById('request-modal-title');
    const back = document.getElementById('request-back');
    const submit = document.getElementById('request-submit');
    const seniorId = document.getElementById('senior-id');
    const otherId = document.getElementById('other-id');
    const documentLabel = document.getElementById('request-document-label');
    const setDocumentLabel = (english, cebuano) => {
        documentLabel.dataset.en = english;
        documentLabel.dataset.ceb = cebuano;
        documentLabel.textContent = document.documentElement.lang === 'ceb' ? cebuano : english;
    };
    const setPanel = (panel, enabled) => {
        panel.hidden = !enabled;
        panel.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !enabled;
            if (!enabled) field.required = false;
        });
    };
    const syncDeathId = () => {
        if (seniorId.disabled) return;
        otherId.required = seniorId.files.length === 0;
    };
    const showChoices = () => {
        choices.hidden = false;
        details.hidden = true;
        back.hidden = true;
        submit.hidden = true;
        title.textContent = 'Choose a request';
        typeInput.value = '';
        setPanel(standard, false);
        setPanel(other, false);
        setPanel(death, false);
    };
    const showForm = (type) => {
        const isDeath = type === 'Death Assistance';
        const isOther = type === 'Other Request';
        choices.hidden = true;
        details.hidden = false;
        back.hidden = false;
        submit.hidden = false;
        title.textContent = type;
        typeInput.value = type;
        setPanel(standard, !isDeath && !isOther);
        setPanel(other, isOther);
        setPanel(death, isDeath);
        if (isOther) {
            document.getElementById('other-description').required = true;
            other.querySelectorAll('.appointment-day, .appointment-hour').forEach((field) => { field.required = true; });
        } else if (!isDeath) {
            ['financial-status', 'pension-status', 'pension-provider', 'request-description', 'request-document'].forEach((id) => {
                document.getElementById(id).required = true;
            });
            setDocumentLabel(
                type === 'Medical/Financial Assistance' ? 'Supporting Documents/ Medical Prescription' : 'Supporting document',
                type === 'Medical/Financial Assistance' ? 'Mga dokumento nga pamatuod / reseta' : 'Dokumento nga pamatuod'
            );
        } else {
            document.getElementById('death-certificate').required = true;
            syncDeathId();
        }
        if (!isDeath && !isOther) syncPension();
    };
    const pensionStatus = document.getElementById('pension-status');
    const pensionProvider = document.getElementById('pension-provider');
    const syncPension = () => {
        const without = pensionStatus.value === 'Without Pension';
        const withPension = pensionStatus.value === 'With Pension';
        [...pensionProvider.options].forEach((option) => {
            if (option.value === '') return;
            option.disabled = without ? option.value !== 'None' : (withPension && option.value === 'None');
        });
        if (without) pensionProvider.value = 'None';
        else if (withPension && (pensionProvider.value === 'None' || pensionProvider.value === '')) pensionProvider.value = '';
    };
    pensionStatus.addEventListener('change', syncPension);
    document.querySelectorAll('[data-choose-request]').forEach((button) => {
        button.addEventListener('click', () => showForm(button.dataset.chooseRequest));
    });
    back.addEventListener('click', showChoices);
    seniorId.addEventListener('change', syncDeathId);
    modal.addEventListener('hidden.bs.modal', showChoices);
    const regularHolidays = <?= json_encode(regular_holiday_map((int) date('Y') - 1, (int) date('Y') + 3), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const holidayName = (date) => regularHolidays[date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0')] || '';
    document.querySelectorAll('.appointment-day').forEach((day) => {
        const hour = day.form ? day.form.querySelector('.appointment-hour') : null;
        if (!hour) return;
        if (window.flatpickr && !day._flatpickr) {
            flatpickr(day, {
                static: true,
                disableMobile: true,
                allowInput: false,
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'M j, Y',
                altInputClass: 'form-control appointment-date-input',
                minDate: day.getAttribute('min') || 'today',
                disable: [date => date.getDay() === 0 || date.getDay() === 6 || holidayName(date) !== ''],
                locale: { firstDayOfWeek: 1 },
                onDayCreate: (selectedDates, dateStr, instance, dayElem) => {
                    const name = holidayName(dayElem.dateObj);
                    if (dayElem.dateObj.getDay() === 0) {
                        dayElem.classList.add('is-sunday');
                        if (!name) dayElem.title = 'Sunday. The office is closed.';
                    }
                    if (name) {
                        dayElem.classList.add('is-holiday');
                        dayElem.title = name + '. Regular holiday. The office is closed.';
                    }
                }
            });
        }
        day.addEventListener('change', async () => {
            if (!day.value) return;
            const base = document.body.dataset.baseUrl || '';
            const response = await fetch(base + '/api/appointment_slots.php?date=' + encodeURIComponent(day.value) + '&request_id=0', { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            const current = hour.value;
            const slots = Array.isArray(data.hours) ? data.hours : [];
            if (slots.length && slots.every((slot) => slot.state === 'weekend' || slot.state === 'holiday')) {
                hour.replaceChildren();
                const closed = document.createElement('option');
                closed.value = '';
                closed.disabled = true;
                closed.selected = true;
                closed.textContent = slots[0].state === 'holiday'
                    ? (data.holiday ? data.holiday + '. ' : '') + 'Regular holiday. The office is closed.'
                    : 'Saturday and Sunday are closed';
                hour.appendChild(closed);
                return;
            }
            hour.replaceChildren();
            if (!slots.some((slot) => slot.open)) {
                const empty = document.createElement('option');
                empty.value = '';
                empty.disabled = true;
                empty.selected = true;
                empty.textContent = 'No open hour';
                hour.appendChild(empty);
            }
            slots.forEach((slot) => {
                const option = document.createElement('option');
                option.value = slot.value;
                option.textContent = slot.label + ' (' + (slot.open ? slot.left + ' left' : slot.state) + ')';
                option.disabled = !slot.open;
                hour.appendChild(option);
            });
            const keep = [...hour.options].find((option) => option.value === current && !option.disabled);
            const first = [...hour.options].find((option) => option.value && !option.disabled);
            if (keep) hour.value = keep.value;
            else if (first) hour.value = first.value;
        });
    });
    document.getElementById('assistance-request-form').addEventListener('submit', (event) => {
        if (submit.disabled) {
            event.preventDefault();
            return;
        }
        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');
        const waiting = document.documentElement.lang === 'ceb' ? 'Gipadala...' : 'Submitting...';
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm me-2';
        spinner.setAttribute('aria-hidden', 'true');
        submit.replaceChildren(spinner, document.createTextNode(waiting));
        back.disabled = true;
        modal.querySelectorAll('[data-bs-dismiss="modal"]').forEach((button) => { button.disabled = true; });
    });
    showChoices();
})();
(() => {
    const trackModal = document.getElementById('track-modal');
    const trackTitle = document.getElementById('track-modal-title');
    const trackBody = document.getElementById('track-modal-body');
    if (!trackModal || !trackTitle || !trackBody) return;
    trackModal.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        const source = button ? document.getElementById('track-source-' + button.dataset.trackId) : null;
        trackTitle.textContent = document.documentElement.lang === 'ceb' ? 'Pagsubay sa aplikasyon' : 'Application tracking';
        trackBody.replaceChildren();
        if (source) trackBody.append(source.content.cloneNode(true));
        const lang = document.documentElement.lang === 'ceb' ? 'ceb' : 'en';
        trackBody.querySelectorAll('[data-en][data-ceb]').forEach((element) => {
            element.textContent = element.dataset[lang];
        });
    });
    trackModal.addEventListener('hidden.bs.modal', () => trackBody.replaceChildren());
})();
(() => {
    const queueModal = document.getElementById('queue-modal');
    const download = document.getElementById('download-queue-png');
    if (!queueModal || !download) return;
    let slip = null;
    const text = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };
    queueModal.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        if (!button) return;
        slip = {
            kind: button.dataset.queueKind || '',
            number: button.dataset.queueNumber || '',
            when: button.dataset.queueWhen || '',
            name: button.dataset.queueName || '',
            request: button.dataset.queueRequest || '',
            control: button.dataset.queueControl || ''
        };
        document.getElementById('queue-modal-title').textContent = 'Queue ' + slip.number;
        text('queue-slip-kind', slip.kind);
        text('queue-slip-number', slip.number);
        text('queue-slip-when', slip.when);
        text('queue-slip-name', slip.name);
        text('queue-slip-request', slip.request);
        text('queue-slip-control', 'ID control no. ' + slip.control);
    });
    const loadSeal = (src) => new Promise((resolve) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => resolve(null);
        image.src = src;
    });
    download.addEventListener('click', () => {
        if (!slip) return;
        Promise.all([
            loadSeal(<?= json_encode(BASE_URL . '/assets/images/cebu-city-seal.png') ?>),
            loadSeal(<?= json_encode(BASE_URL . '/assets/images/minglanilla-seal.png') ?>)
        ]).then(([cebu, minglanilla]) => {
            const canvas = document.createElement('canvas');
            canvas.width = 900;
            canvas.height = 640;
            const pen = canvas.getContext('2d');
            pen.fillStyle = '#fffdf8';
            pen.fillRect(0, 0, canvas.width, canvas.height);
            pen.strokeStyle = '#b89a62';
            pen.lineWidth = 8;
            pen.strokeRect(24, 24, canvas.width - 48, canvas.height - 48);
            const sealSize = 128;
            if (cebu) pen.drawImage(cebu, 52, 52, sealSize, sealSize);
            if (minglanilla) pen.drawImage(minglanilla, canvas.width - 52 - sealSize, 52, sealSize, sealSize);
            pen.fillStyle = '#1d2430';
            pen.textAlign = 'center';
            pen.font = '28px Georgia, serif';
            pen.fillText('Barangay Tungkil', 450, 124);
            pen.font = '32px Georgia, serif';
            pen.fillText(slip.kind, 450, 250);
            pen.font = 'bold 120px Georgia, serif';
            pen.fillText(slip.number, 450, 390);
            pen.font = '28px Georgia, serif';
            pen.fillText(slip.when, 450, 455);
            pen.fillText(slip.name, 450, 500);
            pen.font = '24px Georgia, serif';
            pen.fillText(slip.request, 450, 545);
            pen.fillText('ID control no. ' + slip.control, 450, 585);
            const link = document.createElement('a');
            link.href = canvas.toDataURL('image/png');
            link.download = 'queue-' + slip.number + '.png';
            link.click();
        });
    });
})();
</script>
<?php document_preview_modal(); require_once __DIR__ . '/../includes/footer.php'; ?>
