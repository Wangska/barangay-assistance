<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../sms/sms_gateway.php';
$user = require_role(['Senior Citizen']);
$s = db()->prepare('SELECT s.*, u.full_name FROM senior_citizens s JOIN users u ON u.user_id=s.user_id WHERE s.user_id=?');
$s->execute([$user['user_id']]);
$profile = $s->fetch();
$seniorId = (int) ($profile['senior_id'] ?? 0);
$savedPension = (string) ($profile['is_pensioner'] ?? '');
$savedSource = strtoupper((string) ($profile['pension_source'] ?? ''));
$defaultPensionStatus = $savedPension === 'Yes' ? 'With Pension' : ($savedPension === 'No' ? 'Without Pension' : '');
$defaultProvider = 'None';
if ($defaultPensionStatus === 'With Pension') {
    $defaultProvider = str_contains($savedSource, 'GSIS') ? 'GSIS' : (str_contains($savedSource, 'SSS') ? 'SSS' : 'Other');
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
    $allowedTypes=['Medical/Financial Assistance','Death Assistance','Special / Indigent Assistance'];
    if (!in_array($type,$allowedTypes,true)) {
        flash('danger','Choose a request to continue.'); redirect('/senior/requests.php');
    }
    if ($type==='Death Assistance' && death_assistance_already_requested($seniorId)) {
        flash('danger', death_assistance_limit_message()); redirect('/senior/requests.php');
    }
    $documentPath=null;
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
        } else {
            $validMoney=$pensionAmount==='' || preg_match('/^\d{1,10}(\.\d{1,2})?$/',$pensionAmount)===1;
            $pensionMatches=($pensionStatus==='Without Pension' && $pensionProvider==='None')
                || ($pensionStatus==='With Pension' && in_array($pensionProvider,['GSIS','SSS','Other'],true));
            if ($description==='' || strlen($description)>2000
                || !in_array($financialStatus,['Financially Stable','Low Income','Indigent'],true)
                || !$pensionMatches || !$validMoney) {
                throw new RuntimeException('Complete the pension details, financial status, and the reason for the request.');
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
    db()->beginTransaction();
    try {
    if ($type==='Death Assistance') {
        $lock = db()->prepare('SELECT senior_id FROM senior_citizens WHERE senior_id=? FOR UPDATE');
        $lock->execute([$seniorId]);
        if (death_assistance_already_requested($seniorId)) {
            throw new RuntimeException(death_assistance_limit_message());
        }
    }
    $stmt = db()->prepare('INSERT INTO assistance_requests (senior_id, request_type, description, supporting_document, identity_document, identity_label, pension_status, pension_provider, monthly_pension_amount, financial_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$seniorId, $type, $description, $documentPath, $identityPath, $identityLabel, $pensionStatus!==''?$pensionStatus:null, $pensionProvider!==''?$pensionProvider:null, $pensionAmount===''?null:$pensionAmount, $financialStatus!==''?$financialStatus:null]);
    $requestId = (int)db()->lastInsertId();
    application_event((int)$user['user_id'],$requestId,'Submitted','Assistance request submitted.',(int)$user['user_id']);
    notify_user((int)$user['user_id'], 'Assistance Request Submitted', 'Request #' . $requestId . ' for ' . trim($_POST['request_type']) . ' has been submitted and is Pending review.',null,$requestId);
    notify_barangay_staff('New Assistance Application', $user['full_name'] . ' submitted request #' . $requestId . ' for ' . trim($_POST['request_type']) . '.', null, $requestId);
    audit_log((int)$user['user_id'], 'Submitted assistance request', 'assistance_requests', $requestId);
    db()->commit();
    } catch(Throwable $error) {
        if(db()->inTransaction()) db()->rollBack();
        delete_request_document($documentPath);
        delete_request_document($identityPath);
        $message = $error instanceof RuntimeException && $error->getMessage()===death_assistance_limit_message()
            ? $error->getMessage()
            : 'Your request could not be saved. Please try again.';
        flash('danger',$message); redirect('/senior/requests.php');
    }
    send_sms((int)$user['user_id'], $user['phone_number'], 'Your assistance request has been submitted.');

    flash('success','Request submitted.'); redirect('/senior/requests.php');
}
$rows = db()->prepare('SELECT * FROM assistance_requests WHERE senior_id=? ORDER BY created_at DESC'); $rows->execute([$seniorId]); $rows = $rows->fetchAll();
$deathAlreadyRequested = death_assistance_already_requested($seniorId);
$eventsByRequest = [];
$releasesByRequest = [];
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
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2 text-nowrap" data-bs-toggle="modal" data-bs-target="#track-modal" data-track-id="<?= (int) $r['request_id'] ?>">Track application</button>
                </td>
                <td>
                    <div><?= e($r['request_type']==='Death Assistance' ? 'Death certificate and identification' : $r['description']) ?></div>
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
    <?php if (claim_id_reminder_needed((string) $r['status'], $release)): ?>
    <p class="alert alert-info" role="note" data-en="Note: Please bring a Senior Citizen ID or any valid ID to claim the assistance." data-ceb="Timan-i: Palihog pagdala og Senior Citizen ID o bisan unsang balidong ID aron makuha ang tabang.">Note: Please bring a Senior Citizen ID or any valid ID to claim the assistance.</p>
    <?php endif; ?>
    <p><?= e($r['request_type']) ?><?= $r['request_type'] === 'Death Assistance' ? '' : ': ' . e($r['description']) ?></p>
    <p>Requested <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></p>
    <?php if (!empty($r['schedule_date'])): ?><p>Schedule: <?= e(date('M j, Y g:i A', strtotime($r['schedule_date']))) ?></p><?php endif; ?>
    <?php if (trim((string) $r['remarks']) !== ''): ?><p>Remarks: <?= e($r['remarks']) ?></p><?php endif; ?>
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
        <p>Released <?= e(format_release_date((string) $release['released_at'])) ?> by <?= e($release['released_by_name']) ?>. Received by <?= e($release['received_by']) ?>.</p>
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
                    <p class="text-muted mb-1">This request is for <?= e($profile['full_name'] ?? $user['full_name']) ?>. Choose the assistance, then complete its form. The request date is saved when you submit it.</p>
                    <button type="button" class="btn btn-outline-primary text-start py-3" data-choose-request="Medical/Financial Assistance"><strong>Medical/Financial Assistance</strong><span class="d-block small">For medicine, a prescription, or financial support.</span></button>
                    <?php if ($deathAlreadyRequested): ?>
                    <button type="button" class="btn btn-outline-secondary text-start py-3" disabled><strong>Death Assistance</strong><span class="d-block small" data-en="<?= e(death_assistance_limit_message()) ?>" data-ceb="Ang tabang sa kamatayon mahimong hangyoon kausa ra. Aduna na kay hangyo niini."><?= e(death_assistance_limit_message()) ?></span></button>
                    <?php else: ?>
                    <button type="button" class="btn btn-outline-primary text-start py-3" data-choose-request="Death Assistance"><strong>Death Assistance</strong><span class="d-block small" data-en="For the family or authorized beneficiary. This can be requested only once. The barangay reference amount is PHP <?= e(number_format((float) death_assistance_amount(), 2)) ?>." data-ceb="Para sa pamilya o gitugutan nga makadawat. Kini mahimong hangyoon kausa ra. Ang kantidad sa barangay kay PHP <?= e(number_format((float) death_assistance_amount(), 2)) ?>.">For the family or authorized beneficiary. This can be requested only once. The barangay reference amount is PHP <?= e(number_format((float) death_assistance_amount(), 2)) ?>.</span></button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary text-start py-3" data-choose-request="Special / Indigent Assistance"><strong>Special / Indigent Assistance</strong><span class="d-block small">For seniors with no regular income, no pension, or limited resources.</span></button>
                    </div>
                </div>
                <div id="request-details" hidden>
                    <div id="request-standard" class="row g-3">
                        <div class="col-12"><p class="text-muted mb-0">A pension, including GSIS or SSS, does not stop this request.</p></div>
                        <div class="col-md-6"><label class="form-label" for="financial-status">Financial status</label><select class="form-select" id="financial-status" name="financial_status"><option value="">Choose option</option><option>Financially Stable</option><option>Low Income</option><option>Indigent</option></select></div>
                        <div class="col-md-6"><label class="form-label" for="pension-status">Pension status</label><select class="form-select" id="pension-status" name="pension_status"><option value="">Choose option</option><option<?= $defaultPensionStatus==='With Pension'?' selected':'' ?>>With Pension</option><option<?= $defaultPensionStatus==='Without Pension'?' selected':'' ?>>Without Pension</option></select></div>
                        <div class="col-md-6"><label class="form-label" for="pension-provider">Pension provider</label><select class="form-select" id="pension-provider" name="pension_provider"><option value="">Choose option</option><?php foreach (['GSIS','SSS','Other','None'] as $provider): ?><option<?= $defaultProvider===$provider?' selected':'' ?>><?= $provider ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label" for="pension-amount">Monthly pension amount</label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control" id="pension-amount" type="number" min="0" step="0.01" name="monthly_pension_amount" placeholder="Optional" value="<?= e($defaultPensionAmount) ?>"></div></div>
                        <div class="col-12"><label class="form-label" for="request-description">Reason for requesting assistance</label><textarea class="form-control" id="request-description" name="description" rows="3" maxlength="2000" placeholder="Explain why this assistance is needed"></textarea></div>
                        <div class="col-12"><label class="form-label" id="request-document-label" for="request-document">Supporting document</label><input class="form-control" id="request-document" type="file" name="document" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><div class="form-text">JPG, PNG, or PDF, up to 2 MB.</div></div>
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
<script>
(() => {
    const modal = document.getElementById('request-modal');
    const choices = document.getElementById('request-choices');
    const details = document.getElementById('request-details');
    const standard = document.getElementById('request-standard');
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
        setPanel(death, false);
    };
    const showForm = (type) => {
        const isDeath = type === 'Death Assistance';
        choices.hidden = true;
        details.hidden = false;
        back.hidden = false;
        submit.hidden = false;
        title.textContent = type;
        typeInput.value = type;
        setPanel(standard, !isDeath);
        setPanel(death, isDeath);
        if (!isDeath) {
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
    };
    document.querySelectorAll('[data-choose-request]').forEach((button) => {
        button.addEventListener('click', () => showForm(button.dataset.chooseRequest));
    });
    back.addEventListener('click', showChoices);
    seniorId.addEventListener('change', syncDeathId);
    modal.addEventListener('hidden.bs.modal', showChoices);
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
</script>
<?php document_preview_modal(); require_once __DIR__ . '/../includes/footer.php'; ?>
