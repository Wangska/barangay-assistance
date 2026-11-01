<?php
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../sms/sms_gateway.php';
function render_requests_page(array $roles): void {
    $user=require_role($roles);
    $isAdmin=$user['role_name']==='Administrator';
    $prefix=$isAdmin?'/admin':'/staff';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        verify_csrf();
        try {
            $requestId=(int)($_POST['request_id'] ?? 0);
            if (($_POST['action'] ?? '')==='release') {
                if (!$isAdmin) throw new RuntimeException('Only an administrator can enter the amount and release assistance.');
                release_assistance($requestId,$_POST,(int)$user['user_id']);
            } else {
                if ($isAdmin) throw new RuntimeException('Staff approve or reject the request. Enter the amount and release date when it is approved.');
                $status=$_POST['status'] ?? '';
                $remarks=trim($_POST['remarks'] ?? '');
                if (!in_array($status,['Approved','Rejected'],true)) throw new RuntimeException('Approve the request, or reject it with a reason.');
                if ($status==='Rejected' && $remarks==='') throw new RuntimeException('Explain the reason for rejection.');
                $pdo=db();$pdo->beginTransaction();
                try {
                    $q=$pdo->prepare('SELECT ar.*,s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE request_id=? FOR UPDATE');
                    $q->execute([$requestId]);$r=$q->fetch();
                    if (!$r || in_array($r['status'],['Approved','Scheduled','Completed'],true)) throw new RuntimeException('This request is already with the administrator.');
                    if ($status!==$r['status'] || $remarks!==(string)$r['remarks']) {
                        $pdo->prepare('UPDATE assistance_requests SET status=?,remarks=?,processed_by=? WHERE request_id=?')->execute([$status,$remarks,$user['user_id'],$requestId]);
                        application_event((int)$r['user_id'],$requestId,$status,$remarks,(int)$user['user_id']);
                        notify_user((int)$r['user_id'],'Assistance Request Update','Request #'.$requestId.' is now '.$status.'. '.$remarks,null,$requestId);
                        audit_log((int)$user['user_id'],'Updated assistance request','assistance_requests',$requestId);
                    }
                    $pdo->commit();
                } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
                if ($status!==$r['status']) {
                    $phone=$pdo->prepare('SELECT phone_number FROM users WHERE user_id=?');$phone->execute([$r['user_id']]);
                    send_sms((int)$r['user_id'],(string)$phone->fetchColumn(),'Your assistance request #'.$requestId.' is now '.$status.'.');
                }
            }
            flash('success','Request updated.');
        } catch(Throwable $e) { flash('danger',$e instanceof PDOException?'Unable to save. Check whether this assistance has already been released.':$e->getMessage()); }
        redirect($prefix.'/requests.php');
    }
    $rows=db()->query('SELECT ar.*,u.full_name FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id ORDER BY ar.created_at DESC')->fetchAll();
    $deathAmount=death_assistance_amount();
    require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/sidebar.php';
?>
<h1 class="page-title">Assistance Requests</h1>
<?php if ($isAdmin): ?>
<p class="text-muted">Staff approve or reject each request. Enter the amount and the release date here after it is approved. Death assistance reference amount: PHP <?= e(number_format((float) $deathAmount, 2)) ?>.</p>
<?php else: ?>
<p class="text-muted">Review the request and its attachment, then approve or reject it. The administrator enters the amount and the date the assistance is released.</p>
<?php endif; ?>
<?php if(!$rows): ?><div class="panel">No assistance requests yet.</div><?php endif; ?>
<?php foreach($rows as $r): ?>
<article class="panel mb-3" id="request-<?= (int)$r['request_id'] ?>">
<h2 class="h5">#<?= (int)$r['request_id'] ?> · <?= e($r['full_name']) ?> · <?= e($r['request_type']) ?><?php if (assistance_priority($r)): ?> <span class="badge text-bg-warning">Priority for assessment</span><?php endif; ?></h2>
<p class="mb-1">Requested <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></p>
<?php if (!empty($r['pension_status'])): ?><p class="mb-1">Pension: <?= e($r['pension_status']) ?> · Provider: <?= e($r['pension_provider'] ?: '—') ?><?php if ($r['monthly_pension_amount'] !== null && $r['monthly_pension_amount'] !== ''): ?> · Monthly pension: PHP <?= e(number_format((float) $r['monthly_pension_amount'], 2)) ?><?php endif; ?></p><?php endif; ?>
<?php if (!empty($r['financial_status'])): ?><p class="mb-1">Financial status: <?= e($r['financial_status']) ?></p><?php endif; ?>
<?php if ($r['request_type'] !== 'Death Assistance'): ?><p><?= e($r['description']) ?></p><?php endif; ?>
<p class="d-flex flex-wrap gap-2">
<?php if (!empty($r['supporting_document'])): document_preview_button((int) $r['request_id'], (string) $r['supporting_document'], request_file_label($r), 'supporting', 'btn btn-sm btn-outline-primary'); endif; ?>
<?php if (!empty($r['identity_document'])): document_preview_button((int) $r['request_id'], (string) $r['identity_document'], request_file_label($r, 'identity'), 'identity', 'btn btn-sm btn-outline-primary'); endif; ?>
<?php if (empty($r['supporting_document']) && empty($r['identity_document'])): ?><span class="text-muted">No supporting document</span><?php endif; ?>
</p>
<p><strong><?= e(request_status_label((string) $r['status'])) ?></strong> · <a href="<?= BASE_URL.$prefix ?>/application.php?request_id=<?= (int)$r['request_id'] ?>">Timeline and release details</a></p>
<?php if(!$isAdmin && !in_array($r['status'],['Approved','Scheduled','Completed'],true)): ?>
<form method="post" class="row g-2 mb-3">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
<label class="col-md-4">Decision<select class="form-select" name="status" required><option value="">Choose decision</option><option>Approved</option><option <?= $r['status']==='Rejected'?'selected':'' ?>>Rejected</option></select></label>
<label class="col-md-6">Remarks<input class="form-control" name="remarks" value="<?= e($r['remarks']) ?>" placeholder="Required when rejecting"></label>
<div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary">Save decision</button></div>
</form>
<?php elseif(!$isAdmin && $r['status']!=='Completed'): ?>
<p class="text-muted">This decision is recorded. The administrator enters the amount and release date.</p>
<?php elseif(in_array($r['status'],['Approved','Scheduled'],true)): ?>
<form method="post" class="row g-2 mt-2">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
<label class="col-md-4">Item / assistance provided<input class="form-control" name="item" maxlength="180" required value="<?= $r['request_type']==='Death Assistance' ? 'Death Assistance' : e($r['request_type']) ?>"></label>
<label class="col-md-2">Quantity<input class="form-control" type="number" name="quantity" step="0.01" min="0.01" max="9999999999.99" required value="<?= $r['request_type']==='Death Assistance' ? '1' : '1' ?>"></label>
<label class="col-md-2">Unit<input class="form-control" name="unit" placeholder="packs, tablets, grant" maxlength="40" required value="<?= $r['request_type']==='Death Assistance' ? 'grant' : '' ?>"></label>
<label class="col-md-4">Amount (PHP)<input class="form-control" type="number" name="amount" step="0.01" min="0" max="9999999999.99" required value="<?= $r['request_type']==='Death Assistance' ? e($deathAmount) : '' ?>"></label>
<label class="col-md-4">Release date and time<input class="form-control" name="released_at" type="datetime-local" min="<?= date('Y-m-d\T00:00') ?>" value="<?= date('Y-m-d\TH:i') ?>" required></label>
<label class="col-md-4">Received by<input class="form-control" name="received_by" maxlength="150" value="<?= e($r['full_name']) ?>" required></label>
<label class="col-md-4">Release remarks<input class="form-control" name="remarks"></label>
<div class="col-12"><button class="btn btn-success" name="action" value="release">Record release</button></div>
</form>
<?php elseif($isAdmin && $r['status']!=='Completed'): ?>
<p class="text-muted">Waiting for staff to approve this request before the amount and release date can be recorded.</p>
<?php endif; ?>
</article>
<?php endforeach; document_preview_modal(); require __DIR__.'/../includes/footer.php'; }