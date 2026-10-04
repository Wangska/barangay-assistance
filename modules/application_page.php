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
            } elseif (!$isSenior && $action==='add_requirement') {
                $label=trim($_POST['label'] ?? '');
                if ($label==='' || strlen($label)>150) throw new RuntimeException('Enter a requirement name up to 150 characters.');
                $pdo->prepare('INSERT INTO application_requirements (user_id,label,remarks) VALUES (?,?,?)')->execute([$userId,$label,$remarks]);
                application_event($userId,null,'Requirement requested',$label.': '.$remarks,(int)$viewer['user_id']);
                notify_user($userId,'Registration Requirements','Please submit: '.$label.'. '.$remarks,$userId);
            } elseif (!$isSenior && $action==='review_requirement') {
                $status=$_POST['requirement_status'] ?? '';
                if (!in_array($status,['Accepted','Needs replacement'],true) || ($status==='Needs replacement' && $remarks==='')) throw new RuntimeException('Select a status and explain why a replacement is needed.');
                $q=$pdo->prepare('SELECT * FROM application_requirements WHERE requirement_id=? AND user_id=? FOR UPDATE');
                $q->execute([(int)($_POST['requirement_id'] ?? 0),$userId]); $requirement=$q->fetch();
                if (!$requirement || !$requirement['file_path']) throw new RuntimeException('No submitted document to review.');
                $pdo->prepare('UPDATE application_requirements SET status=?,remarks=?,reviewed_by=? WHERE requirement_id=?')->execute([$status,$remarks,$viewer['user_id'],$requirement['requirement_id']]);
                application_event($userId,null,'Under Review',$requirement['label'].': '.$status.'. '.$remarks,(int)$viewer['user_id']);
                notify_user($userId,'Registration Requirements',$requirement['label'].': '.$status.'. '.$remarks,$userId);
            } elseif (!$isSenior && $action==='start_review') {
                if ($currentStatus!=='Pending') throw new RuntimeException('Only pending registrations can enter review.');
                application_event($userId,null,'Under Review',$remarks ?: 'Your registration is being reviewed.',(int)$viewer['user_id']);
                notify_user($userId,'Registration Update','Your registration is under review. '.$remarks,$userId);
            } elseif ($viewer['role_name']==='Administrator' && in_array($action,['approve','reject'],true)) {
                if ($currentStatus!=='Pending') throw new RuntimeException('This registration is no longer pending.');
                if ($action==='approve') {
                    $q=$pdo->prepare("SELECT COUNT(*) FROM application_requirements WHERE user_id=? AND status<>'Accepted'"); $q->execute([$userId]);
                    if ((int)$q->fetchColumn()>0) throw new RuntimeException('Accept all required documents before approving.');
                    if (duplicate_seniors($userId) && (empty($_POST['duplicate_checked']) || $remarks==='')) throw new RuntimeException('Review possible duplicates and record why this is a separate person before approving.');
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
    require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/sidebar.php';
?>
<h1 class="page-title" data-en="Application tracking" data-ceb="Pagsubay sa aplikasyon">Application tracking</h1>
<div class="panel mb-3">
<h2 class="h5"><?= e($record['full_name']) ?> — <?= $requestId ? 'Request #'.$requestId : 'Registration #'.$userId ?></h2>
<p>Current status: <strong><?= e($record['status']) ?></strong></p>
<?php if ($requestId): ?><p><?= e($record['request_type']) ?>: <?= e($record['description']) ?></p><?php endif; ?>
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
<?php foreach($events as $event): ?><li><strong><?= e($event['status']) ?></strong><div><?= e($event['remarks']) ?></div><time><?= e(date('M j, Y g:i A',strtotime($event['created_at']))) ?></time></li><?php endforeach; ?>
</ol>
<p class="small text-muted">Older applications show their recorded submission date; review steps are recorded from this feature onward.</p>
</div>
<?php if (!$requestId): ?>
<div class="panel mb-3"><h2 class="h5" data-en="Requirements checklist" data-ceb="Lista sa mga kinahanglan">Requirements checklist</h2>
<?php foreach($requirements as $requirement): ?>
<section class="border-bottom py-3">
<h3 class="h6"><?= e($requirement['label']) ?> — <strong><?= e($requirement['status']) ?></strong></h3>
<p><?= e($requirement['remarks']) ?></p>
<?php if ($requirement['file_path']): ?><a class="btn btn-outline-primary mb-2" href="<?= BASE_URL ?>/api/requirement_file.php?id=<?= (int)$requirement['requirement_id'] ?>">View submitted document</a><?php endif; ?>
<?php if ($isSenior && $requirement['status']!=='Accepted'): ?>
<form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="requirement_id" value="<?= (int)$requirement['requirement_id'] ?>">
<label>Document (JPG, PNG, PDF; maximum 2 MB)<input class="form-control" type="file" name="document" accept=".jpg,.jpeg,.png,.pdf" required></label>
<button class="btn btn-primary" name="action" value="upload" data-en="Submit document" data-ceb="Isumite ang dokumento">Submit document</button>
</form>
<?php elseif (!$isSenior && $requirement['file_path']): ?>
<form method="post" class="row g-2">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="requirement_id" value="<?= (int)$requirement['requirement_id'] ?>">
<label class="col-md-3">Document status<select class="form-select" name="requirement_status"><option>Accepted</option><option>Needs replacement</option></select></label>
<label class="col-md-6">Review remarks<input class="form-control" name="remarks" value="<?= e($requirement['remarks']) ?>"></label>
<div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary" name="action" value="review_requirement">Save review</button></div>
</form>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php if (!$isSenior): ?>
<form method="post" class="row g-2 mt-2">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label class="col-md-4">Additional requirement<input class="form-control" name="label" maxlength="150" required></label>
<label class="col-md-5">Instructions<input class="form-control" name="remarks"></label>
<div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary" name="action" value="add_requirement">Request document</button></div>
</form>
<?php endif; ?>
</div>
<?php if (!$isSenior && $record['status']==='Pending'): ?>
<div class="panel"><form method="post">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label class="form-label w-100">Review / decision remarks<textarea class="form-control" name="remarks"></textarea></label>
<?php if (!empty($duplicates)): ?><label class="d-block mb-3"><input type="checkbox" name="duplicate_checked" value="1"> I verified the matching records and confirmed this is a different person.</label><?php endif; ?>
<button class="btn btn-outline-primary" name="action" value="start_review">Start review</button>
<?php if ($viewer['role_name']==='Administrator'): ?><button class="btn btn-success" name="action" value="approve">Approve registration</button> <button class="btn btn-outline-danger" name="action" value="reject">Reject with reason</button><?php endif; ?>
</form></div>
<?php endif; ?>
<?php else:
$d=db()->prepare('SELECT d.*,u.full_name FROM assistance_distributions d JOIN users u ON u.user_id=d.released_by WHERE request_id=?');$d->execute([$requestId]);$release=$d->fetch();
if ($release): ?>
<div class="panel"><h2 class="h5">Assistance received</h2><p><?= e($release['item']) ?> — <?= e($release['quantity']) ?> <?= e($release['unit']) ?></p><p>Amount: <?= $release['amount']===null?'Not applicable':'PHP '.e($release['amount']) ?></p><p>Released <?= e($release['released_at']) ?> by <?= e($release['full_name']) ?>. Received by <?= e($release['received_by']) ?>.</p><p><?= e($release['remarks']) ?></p></div>
<?php endif; endif;
require __DIR__.'/../includes/footer.php';
}