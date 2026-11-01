<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../sms/sms_gateway.php';
function render_application_page(array $roles): void
{
    $viewer=require_role($roles);
    $isSenior=$viewer['role_name']==='Senior Citizen';
    $prefix=$isSenior?'/senior':($viewer['role_name']==='Administrator'?'/admin':'/staff');
    $requestId=filter_var($_GET['request_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null;
    $userId=$isSenior?(int)$viewer['user_id']:(int)($_GET['user_id'] ?? 0);
    if ($requestId && !$isSenior) {
        $q=db()->prepare('SELECT s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE ar.request_id=?');
        $q->execute([$requestId]); $userId=(int)$q->fetchColumn();
    }
    $record=application_record($userId,$requestId);
    $url=$prefix.'/application.php?'.($requestId?'request_id='.$requestId:'user_id='.$userId);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        verify_csrf();
        $pdo=db(); $pdo->beginTransaction();
        try {
            $lock=$pdo->prepare('SELECT status FROM users WHERE user_id=? FOR UPDATE'); $lock->execute([$userId]); $currentStatus=$lock->fetchColumn();
            $action=$_POST['action'] ?? '';
            $remarks=trim($_POST['remarks'] ?? '');
            if ($requestId) throw new RuntimeException('Use the request management page to update this request.');
            if ($isSenior && $action==='upload') {
                $q=$pdo->prepare('SELECT * FROM application_requirements WHERE requirement_id=? AND user_id=? FOR UPDATE');
                $q->execute([(int)($_POST['requirement_id'] ?? 0),$userId]); $requirement=$q->fetch();
                if (!$requirement || $requirement['status']==='Accepted') throw new RuntimeException('This requirement is not available for replacement.');
                $path=upload_valid_id($_FILES['document'] ?? []);
                if (!$path) throw new RuntimeException('Choose a JPG, PNG, or PDF document (up to 2 MB).');
                $pdo->prepare("UPDATE application_requirements SET file_path=?,status='Submitted',reviewed_by=NULL WHERE requirement_id=?")->execute([$path,$requirement['requirement_id']]);
                if ($requirement['label']==='Valid identification') $pdo->prepare('UPDATE senior_citizens SET valid_id_path=? WHERE user_id=?')->execute([$path,$userId]);
                if ($currentStatus==='Rejected') $pdo->prepare("UPDATE users SET status='Pending' WHERE user_id=?")->execute([$userId]);
                application_event($userId,null,'Document submitted',$requirement['label'].' submitted for review.',(int)$viewer['user_id']);
                notify_barangay_staff('New Senior Registration',$record['full_name'].' submitted a requirement for review.',$userId);
            } elseif (!$isSenior && in_array($action,['approve','reject'],true)) {
                if (!in_array($currentStatus,['Pending','Under Review'],true)) throw new RuntimeException('This registration is no longer waiting for review.');
                if ($action==='approve') {
                    $q=$pdo->prepare("SELECT COUNT(*) FROM application_requirements WHERE user_id=? AND file_path IS NOT NULL AND file_path<>''"); $q->execute([$userId]);
                    if ((int)$q->fetchColumn()<1) throw new RuntimeException('The senior must submit an identification document before approval.');
                    if (duplicate_seniors($userId) && (empty($_POST['duplicate_checked']) || $remarks==='')) throw new RuntimeException('Review possible duplicates and record why this is a separate person before approving.');
                    $pdo->prepare("UPDATE application_requirements SET status='Accepted', reviewed_by=? WHERE user_id=? AND file_path IS NOT NULL AND file_path<>''")->execute([(int)$viewer['user_id'],$userId]);
                } elseif ($remarks==='') throw new RuntimeException('Explain the reason for rejection.');
                $status=$action==='approve'?'Active':'Rejected';
                $pdo->prepare('UPDATE users SET status=? WHERE user_id=?')->execute([$status,$userId]);
                application_event($userId,null,$action==='approve'?'Approved':'Rejected',$remarks ?: 'Registration approved.',(int)$viewer['user_id']);
                notify_user($userId,'Registration Update','Your registration is now '.$status.'. '.$remarks,$userId);
                notify_barangay_staff('Senior Registration Update',$record['full_name'].' registration is now '.$status.'.',$userId);
            } else throw new RuntimeException('This action is not allowed.');
            audit_log((int)$viewer['user_id'],'Application: '.$action,'users',$userId);
            $pdo->commit();
            if (in_array($action,['approve','reject'],true)) {
                $phone=$pdo->prepare('SELECT phone_number FROM users WHERE user_id=?');$phone->execute([$userId]);
                $sms=send_sms_with_status($userId,(string)$phone->fetchColumn(),'Your senior registration is now '.$status.'.');
                flash($sms['ok']?'success':'warning',$sms['ok']?'Application updated and SMS sent.':'Application updated. The SMS could not be sent; the account notification is available.');
            } else flash('success','Application updated.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('danger',$e instanceof PDOException ? 'Could not save this update. The requirement may already exist.' : $e->getMessage());
        }
        redirect($url);
    }
    $events=db()->prepare('SELECT * FROM application_events WHERE user_id=? AND request_id <=> ? ORDER BY event_id');
    $events->execute([$userId,$requestId]);
    $requirements=db()->prepare('SELECT * FROM application_requirements WHERE user_id=? ORDER BY requirement_id');
    $requirements->execute([$userId]);
    $profile = null;
    if (!$requestId) {
        $profileQuery = db()->prepare('SELECT s.*, u.email, u.phone_number FROM senior_citizens s JOIN users u ON u.user_id = s.user_id WHERE s.user_id=?');
        $profileQuery->execute([$userId]);
        $profile = $profileQuery->fetch() ?: null;
    }
    $release = null;
    if ($requestId) {
        $distribution = db()->prepare('SELECT d.*, u.full_name FROM assistance_distributions d JOIN users u ON u.user_id=d.released_by WHERE d.request_id=?');
        $distribution->execute([$requestId]);
        $release = $distribution->fetch() ?: null;
    }
    require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/sidebar.php';
    $seniorBackHref = BASE_URL . ($requestId ? '/senior/requests.php' : '/senior/dashboard.php');
    $seniorBackLabel = $requestId ? 'Back to my requests' : 'Back to dashboard';
?>
<?php if ($isSenior): ?>
<a class="btn btn-outline-primary mb-3 d-inline-flex align-items-center gap-2" href="<?= e($seniorBackHref) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?= translated_label($seniorBackLabel) ?></a>
<?php endif; ?>
<h1 class="page-title" data-en="Application tracking" data-ceb="Pagsubay sa aplikasyon">Application tracking</h1>
<div class="panel mb-3">
<h2 class="h5"><?= e($record['full_name']) ?> — <?= $requestId ? 'Request #'.$requestId : 'Registration #'.$userId ?></h2>
<p>Current status: <strong><?= e($requestId ? request_status_label((string) $record['status']) : $record['status']) ?></strong></p>
<?php if ($requestId && claim_id_reminder_needed((string) $record['status'], $release)): ?>
<p class="alert alert-info" role="note" data-en="Note: Please bring a Senior Citizen ID or any valid ID to claim the assistance." data-ceb="Timan-i: Palihog pagdala og Senior Citizen ID o bisan unsang balidong ID aron makuha ang tabang.">Note: Please bring a Senior Citizen ID or any valid ID to claim the assistance.</p>
<?php endif; ?>
<?php if ($requestId): ?><p><?= e($record['request_type']) ?><?= ($record['request_type'] ?? '')==='Death Assistance' ? '' : ': '.e($record['description']) ?></p><p class="d-flex flex-wrap gap-2"><?php if (!empty($record['supporting_document'])): document_preview_button((int) $requestId, (string) $record['supporting_document'], request_file_label($record), 'supporting', 'btn btn-outline-primary'); endif; ?><?php if (!empty($record['identity_document'])): document_preview_button((int) $requestId, (string) $record['identity_document'], request_file_label($record, 'identity'), 'identity', 'btn btn-outline-primary'); endif; ?></p><?php endif; ?>
</div>
<?php if (!$requestId && !$isSenior):
$duplicates=duplicate_seniors($userId);
if ($duplicates): ?>
<div class="alert alert-warning"><strong>Possible duplicate registrations</strong><p>Matching name and birthdate. Older records are compared using the stored full name. Verify identity before approval; matching names alone do not prove a duplicate.</p>
<?php foreach($duplicates as $duplicate): ?><div><a href="?user_id=<?= (int)$duplicate['user_id'] ?>">#<?= (int)$duplicate['user_id'] ?> <?= e($duplicate['full_name']) ?></a> — <?= e($duplicate['birthdate']) ?> · <?= e($duplicate['status']) ?></div><?php endforeach; ?>
</div>
<?php endif; endif; ?>
<div class="panel mb-3">
<h2 class="h5" data-en="Application timeline" data-ceb="Kasaysayan sa aplikasyon">Application timeline</h2>
<ol class="timeline">
<?php foreach($events as $event): ?><li><strong><?= e($requestId ? request_status_label((string) $event['status']) : $event['status']) ?></strong><div><?= e($event['remarks']) ?></div><time><?= e(date('M j, Y g:i A',strtotime($event['created_at']))) ?></time></li><?php endforeach; ?>
</ol>
<p class="small text-muted">Older applications show their recorded submission date; review steps are recorded from this feature onward.</p>
</div>
<?php if (!$requestId && $isSenior): ?>
<div class="panel mb-3"><h2 class="h5" data-en="Requirements checklist" data-ceb="Lista sa mga kinahanglan">Requirements checklist</h2>
<?php foreach($requirements as $requirement): ?>
<section class="border-bottom py-3">
<h3 class="h6"><?= e($requirement['label']) ?> — <strong><?= e($requirement['status']) ?></strong></h3>
<p><?= e($requirement['remarks']) ?></p>
<?php if ($requirement['file_path']): ?><div class="mb-2"><?php requirement_preview_button((int) $requirement['requirement_id'], (string) $requirement['file_path']); ?></div><?php endif; ?>
<?php if ($requirement['status']!=='Accepted'): ?>
<form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="requirement_id" value="<?= (int)$requirement['requirement_id'] ?>">
<label>Document (JPG, PNG, PDF; maximum 2 MB)<input class="form-control" type="file" name="document" accept=".jpg,.jpeg,.png,.pdf" required></label>
<button class="btn btn-primary" name="action" value="upload" data-en="Submit document" data-ceb="Isumite ang dokumento">Submit document</button>
</form>
<?php endif; ?>
</section>
<?php endforeach; ?>
</div>
<?php elseif (!$requestId): ?>
<?php
    $blank = static function (?string $value): string {
        $value = trim((string) $value);
        return $value === '' ? '—' : $value;
    };
    $purok = trim((string) ($profile['address'] ?? ''));
    $sitio = trim((string) ($profile['sitio'] ?? ''));
    if ($sitio !== '') {
        $purok = trim($purok . ', ' . $sitio, ', ');
    }
    $detailRows = [
        ['Full name', $record['full_name'] ?? ''],
        ['Date of birth', !empty($profile['birthdate']) ? date('M j, Y', strtotime((string) $profile['birthdate'])) : ''],
        ['Place of birth', $profile['place_of_birth'] ?? ''],
        ['Sex', $profile['gender'] ?? ''],
        ['Civil status', $profile['civil_status'] ?? ''],
        ['Purok / Sitio', $purok],
        ['Barangay', $profile['barangay'] ?? ''],
        ['Municipality', $profile['city_municipality'] ?? ''],
        ['Province', $profile['province'] ?? ''],
        ['Contact number', $profile['phone_number'] ?? ''],
        ['Email', $profile['email'] ?? ''],
        ['Beneficiary', $profile['beneficiary_name'] ?? ''],
        ['Beneficiary address', $profile['beneficiary_address'] ?? ''],
        ['Emergency contact', $profile['emergency_contact_name'] ?? ''],
        ['Emergency number', $profile['emergency_contact_phone'] ?? ''],
    ];
?>
<div class="panel mb-3">
<h2 class="h5">Registration details</h2>
<dl class="row mb-0">
<?php foreach ($detailRows as [$label, $value]): ?>
<dt class="col-sm-4 col-lg-3"><?= e($label) ?></dt><dd class="col-sm-8 col-lg-9"><?= e($blank($value)) ?></dd>
<?php endforeach; ?>
</dl>
</div>
<div class="panel mb-3">
<h2 class="h5">Submitted documents</h2>
<p class="text-muted">Review the identification, then approve or reject this registration.</p>
<div class="d-flex flex-wrap gap-2">
<?php $hasDocument = false; foreach ($requirements as $requirement): if (!$requirement['file_path']) continue; $hasDocument = true; ?>
<?php requirement_preview_button((int) $requirement['requirement_id'], (string) $requirement['file_path'], 'View ' . $requirement['label']); ?>
<?php endforeach; if (!$hasDocument): ?><p class="text-muted mb-0">No identification has been submitted yet.</p><?php endif; ?>
</div>
</div>
<?php if (in_array($record['status'], ['Pending', 'Under Review'], true)): ?>
<div class="panel"><form method="post" id="registration-decision">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action" id="registration-action" value="">
<label class="form-label w-100" for="registration-remarks">Remarks<textarea class="form-control" id="registration-remarks" name="remarks" placeholder="Required when rejecting"></textarea></label>
<?php if (!empty($duplicates)): ?><label class="d-block mb-3"><input type="checkbox" name="duplicate_checked" value="1"> I verified the matching records and confirmed this is a different person.</label><?php endif; ?>
<div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-success" id="registration-approve">Approve registration</button><button type="button" class="btn btn-outline-danger" id="registration-reject">Reject with reason</button></div>
</form></div>
<div class="modal fade" id="approve-registration" tabindex="-1" aria-labelledby="approve-registration-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="approve-registration-title">Approve registration</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="mb-0">Approve <strong><?= e($record['full_name']) ?></strong>? This account will become active.</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-success" id="approve-registration-confirm">Approve registration</button></div>
        </div>
    </div>
</div>
<div class="modal fade" id="reject-registration" tabindex="-1" aria-labelledby="reject-registration-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="reject-registration-title">Reject registration</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="mb-0">Are you sure you want to reject <strong><?= e($record['full_name']) ?></strong>?</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="reject-registration-confirm">Reject registration</button></div>
        </div>
    </div>
</div>
<script>
(() => {
    const form = document.getElementById('registration-decision');
    const action = document.getElementById('registration-action');
    const remarks = document.getElementById('registration-remarks');
    const submitDecision = (decision) => {
        action.value = decision;
        form.requestSubmit();
    };
    document.getElementById('registration-approve').addEventListener('click', () => {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('approve-registration')).show();
    });
    document.getElementById('registration-reject').addEventListener('click', () => {
        if (remarks.value.trim() === '') {
            remarks.setCustomValidity('Explain the reason for rejection.');
            remarks.reportValidity();
            return;
        }
        remarks.setCustomValidity('');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('reject-registration')).show();
    });
    remarks.addEventListener('input', () => remarks.setCustomValidity(''));
    document.getElementById('approve-registration-confirm').addEventListener('click', () => submitDecision('approve'));
    document.getElementById('reject-registration-confirm').addEventListener('click', () => submitDecision('reject'));
})();
</script>
<?php endif; ?>
<?php elseif ($release): ?>
<div class="panel"><h2 class="h5">Assistance received</h2><p><?= e($release['item']) ?> — <?= e($release['quantity']) ?> <?= e($release['unit']) ?></p><p>Amount: <?= $release['amount']===null?'Not applicable':'PHP '.e($release['amount']) ?></p><p>Released <?= e(format_release_date((string) $release['released_at'])) ?> by <?= e($release['full_name']) ?>. Received by <?= e($release['received_by']) ?>.</p><p><?= e($release['remarks']) ?></p></div>
<?php endif;
document_preview_modal();
require __DIR__.'/../includes/footer.php';
}