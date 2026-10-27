<?php
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../sms/sms_gateway.php';
function render_requests_page(array $roles): void {
    $user=require_role($roles);
    $prefix=$user['role_name']==='Administrator'?'/admin':'/staff';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        verify_csrf();
        try {
            $requestId=(int)($_POST['request_id'] ?? 0);
            if (($_POST['action'] ?? '')==='release') {
                release_assistance($requestId,$_POST,(int)$user['user_id']);
            } else {
                $status=$_POST['status'] ?? '';
                $schedule=trim($_POST['schedule_date'] ?? '');
                $remarks=trim($_POST['remarks'] ?? '');
                if (!in_array($status,['Pending','Under Review','Approved','Rejected','Scheduled'],true)) throw new RuntimeException('Select a valid status. Use Record release to complete a request.');
                if ($schedule!=='') {
                    $parsed=DateTime::createFromFormat('!Y-m-d\TH:i',$schedule);
                    if (!$parsed || $parsed->format('Y-m-d\TH:i')!==$schedule) throw new RuntimeException('Enter a valid schedule date.');
                    $schedule=$parsed->format('Y-m-d H:i:s');
                }
                if ($status==='Scheduled' && $schedule==='') throw new RuntimeException('A scheduled request needs a date and time.');
                if ($status==='Rejected' && $remarks==='') throw new RuntimeException('Explain the reason for rejection.');
                $pdo=db();$pdo->beginTransaction();
                try {
                    $q=$pdo->prepare('SELECT ar.*,s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE request_id=? FOR UPDATE');
                    $q->execute([$requestId]);$r=$q->fetch();
                    if (!$r || $r['status']==='Completed') throw new RuntimeException('This request is unavailable for editing.');
                    if ($status!==$r['status'] || $schedule!==(string)$r['schedule_date'] || $remarks!==(string)$r['remarks']) {
                        $pdo->prepare('UPDATE assistance_requests SET status=?,schedule_date=?,remarks=?,processed_by=? WHERE request_id=?')->execute([$status,$schedule?:null,$remarks,$user['user_id'],$requestId]);
                        $details=$remarks.($schedule!==''?"\nSchedule: ".$schedule:"\nNo schedule assigned.");
                        application_event((int)$r['user_id'],$requestId,$status,$details,(int)$user['user_id']);
                        notify_user((int)$r['user_id'],'Assistance Request Update','Request #'.$requestId.' is now '.$status.'. '.$details,null,$requestId);
                        audit_log((int)$user['user_id'],'Updated assistance request','assistance_requests',$requestId);
                    }
                    $pdo->commit();
                } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
                if ($status!==$r['status'] && in_array($status,['Approved','Rejected','Scheduled'],true)) {
                    $phone=$pdo->prepare('SELECT phone_number FROM users WHERE user_id=?');$phone->execute([$r['user_id']]);
                    send_sms((int)$r['user_id'],(string)$phone->fetchColumn(),'Your assistance request #'.$requestId.' is now '.$status.'.');
                }
            }
            flash('success','Request updated.');
        } catch(Throwable $e) { flash('danger',$e instanceof PDOException?'Unable to save. Check whether this assistance has already been released.':$e->getMessage()); }
        redirect($prefix.'/requests.php');
    }
    $rows=db()->query('SELECT ar.*,u.full_name FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id ORDER BY ar.created_at DESC')->fetchAll();
    require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/sidebar.php';
?>
<h1 class="page-title">Assistance Requests</h1>
<?php if(!$rows): ?><div class="panel">No assistance requests yet.</div><?php endif; ?>
<?php foreach($rows as $r): ?>
<article class="panel mb-3" id="request-<?= (int)$r['request_id'] ?>">
<h2 class="h5">#<?= (int)$r['request_id'] ?> · <?= e($r['full_name']) ?> · <?= e($r['request_type']) ?></h2>
<p><?= e($r['description']) ?></p>
<?php if (!empty($r['supporting_document'])): ?><p><a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/api/request_document.php?request_id=<?= (int)$r['request_id'] ?>">View supporting document</a></p><?php else: ?><p class="text-muted">No supporting document</p><?php endif; ?>
<p><strong><?= e($r['status']) ?></strong> · <a href="<?= BASE_URL.$prefix ?>/application.php?request_id=<?= (int)$r['request_id'] ?>">Timeline and release details</a></p>
<?php if($r['status']!=='Completed'): ?>
<form method="post" class="row g-2 mb-3">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
<label class="col-md-3">Status<select class="form-select" name="status"><?php foreach(['Pending','Under Review','Approved','Rejected','Scheduled'] as $status): ?><option <?= $r['status']===$status?'selected':'' ?>><?= $status ?></option><?php endforeach; ?></select></label>
<label class="col-md-3">Schedule<input class="form-control" type="datetime-local" name="schedule_date" value="<?= $r['schedule_date']?e(date('Y-m-d\TH:i',strtotime($r['schedule_date']))):'' ?>"></label>
<label class="col-md-4">Remarks<input class="form-control" name="remarks" value="<?= e($r['remarks']) ?>"></label>
<div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary">Update</button></div>
</form>
<?php endif; ?>
<?php if(in_array($r['status'],['Approved','Scheduled'],true)): ?>
<details><summary>Record assistance release</summary>
<form method="post" class="row g-2 mt-2">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
<label class="col-md-4">Item / assistance provided<input class="form-control" name="item" maxlength="180" required></label>
<label class="col-md-2">Quantity<input class="form-control" type="number" name="quantity" step="0.01" min="0.01" max="9999999999.99" required></label>
<label class="col-md-2">Unit<input class="form-control" name="unit" placeholder="packs, tablets, grant" maxlength="40" required></label>
<label class="col-md-4">Cash amount (PHP, optional)<input class="form-control" type="number" name="amount" step="0.01" min="0" max="9999999999.99"></label>
<label class="col-md-4">Release date / time<input class="form-control" name="released_at" type="datetime-local" max="<?= date('Y-m-d\TH:i') ?>" required></label>
<label class="col-md-4">Received by<input class="form-control" name="received_by" maxlength="150" value="<?= e($r['full_name']) ?>" required></label>
<label class="col-md-4">Release remarks<input class="form-control" name="remarks"></label>
<div><button class="btn btn-success" name="action" value="release">Record release and complete request</button></div>
</form></details>
<?php endif; ?>
</article>
<?php endforeach; require __DIR__.'/../includes/footer.php'; }