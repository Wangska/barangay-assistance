<?php
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/claim_queue.php';
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
                release_assistance($requestId,$_POST,(int)$user['user_id']);
            } else {
                if ($isAdmin) throw new RuntimeException('Staff approve or decline the request.');
                $status=$_POST['status'] ?? '';
                if ($status==='Approved') {
                    $kind = db()->prepare('SELECT request_type FROM assistance_requests WHERE request_id=?');
                    $kind->execute([$requestId]);
                    if (is_other_request((string) $kind->fetchColumn())) {
                        $confirmed = confirm_other_appointment($requestId, (int) $user['user_id']);
                        $phone = db()->prepare('SELECT u.user_id, u.phone_number FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id WHERE ar.request_id=?');
                        $phone->execute([$requestId]);
                        $senior = $phone->fetch();
                        if ($senior) {
                            send_sms((int) $senior['user_id'], (string) $senior['phone_number'], $confirmed);
                        }
                        flash('success', $confirmed);
                        redirect($prefix.'/requests.php');
                    }
                }
                $remarks=trim($_POST['remarks'] ?? '');
                if (!in_array($status,['Approved','Rejected'],true)) throw new RuntimeException('Approve the request, or decline it with a reason.');
                if ($status==='Rejected' && $remarks==='') throw new RuntimeException('Explain the reason for the decline.');
                $pdo=db();$pdo->beginTransaction();
                try {
                    $q=$pdo->prepare('SELECT ar.*,s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE request_id=? FOR UPDATE');
                    $q->execute([$requestId]);$r=$q->fetch();
                    if (!$r) throw new RuntimeException('This request could not be found.');
                    if ($r['status']==='Rejected') throw new RuntimeException('This request is already declined.');
                    if (in_array($r['status'],['Approved','Scheduled','Completed'],true)) throw new RuntimeException('This request is already with the administrator.');
                    if ($status!==$r['status'] || $remarks!==(string)$r['remarks']) {
                        $pdo->prepare('UPDATE assistance_requests SET status=?,remarks=?,processed_by=? WHERE request_id=?')->execute([$status,$remarks,$user['user_id'],$requestId]);
                        application_event((int)$r['user_id'],$requestId,$status,$remarks,(int)$user['user_id']);
                        $notice = $status==='Rejected'
                            ? (is_other_request((string)$r['request_type'])
                                ? 'Your appointment request #'.$requestId.' was declined. Reason: '.$remarks.'. You can submit another appointment.'
                                : 'Request #'.$requestId.' was declined. Reason: '.$remarks)
                            : 'Request #'.$requestId.' is now '.request_status_label($status).'. '.$remarks;
                        notify_user((int)$r['user_id'], $status==='Rejected' ? 'Request declined' : 'Assistance Request Update', $notice, null, $requestId);
                        audit_log((int)$user['user_id'],'Updated assistance request','assistance_requests',$requestId);
                    }
                    $pdo->commit();
                } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
                if ($status!==$r['status']) {
                    $phone=$pdo->prepare('SELECT phone_number FROM users WHERE user_id=?');$phone->execute([$r['user_id']]);
                    $sms = $status==='Rejected'
                        ? (is_other_request((string)$r['request_type'])
                            ? 'Your appointment request #'.$requestId.' was declined. Reason: '.$remarks.'. You can submit another appointment.'
                            : 'Request #'.$requestId.' was declined. Reason: '.$remarks)
                        : 'Your assistance request #'.$requestId.' is now '.request_status_label($status).'.';
                    send_sms((int)$r['user_id'],(string)$phone->fetchColumn(),$sms);
                }
            }
            flash('success','Request updated.');
        } catch(Throwable $e) { flash('danger',$e instanceof PDOException?'Unable to save. Check whether this assistance has already been released.':$e->getMessage()); }
        redirect($prefix.'/requests.php');
    }
    $rows=db()->query('SELECT ar.*,u.full_name,s.id_control_number FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id ORDER BY ar.created_at DESC')->fetchAll();
    $queuesByRequest=[];
    foreach (db()->query('SELECT * FROM claim_queues ORDER BY queue_date, queue_number') as $queue) {
        $queuesByRequest[(int) $queue['request_id']][] = $queue;
    }
    $deathAmount=death_assistance_amount();
    require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/sidebar.php';
?>
<h1 class="page-title">Assistance Requests</h1>
<?php if ($isAdmin): ?>
<p class="text-muted">Staff approve or decline each request. The senior chooses the date and hour for an other request. Saturday, Sunday, and regular holidays are closed. You and the staff can enter the amount and release date for assistance. That gives the queue number. Death assistance reference amount: PHP <?= e(number_format((float) $deathAmount, 2)) ?>.</p>
<?php else: ?>
<p class="text-muted">Review the request and its attachment, then approve or decline it. After approval, enter the amount and release date for assistance. The administrator can record that release as well. For an other request, the senior chooses a Monday to Friday hour. Saturday, Sunday, and regular holidays are closed. Approving it confirms that appointment. The officer verifies when the visit is done.</p>
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
<?php foreach ($queuesByRequest[(int) $r['request_id']] ?? [] as $queue): ?>
<p class="mb-1">Queue <?= e(claim_queue_label($queue)) ?> · <?= e($queue['queue_type']) ?> · <?= e(date('M j, Y', strtotime($queue['queue_date']))) ?> · <?= $queue['claimed_at'] ? 'Claimed' : 'Waiting' ?></p>
<?php endforeach; ?>
<?php if (is_other_request((string) $r['request_type']) && !empty($r['schedule_date'])): ?><p class="mb-1">Appointment: <?= e(appointment_window_label((string) $r['schedule_date'], $r['schedule_end'] ?? null)) ?></p><?php endif; ?>
<?php if ($r['status']==='Rejected'): ?>
<?php if (trim((string) $r['remarks']) !== ''): ?><p class="mb-0">Reason: <?= e($r['remarks']) ?></p><?php endif; ?>
<?php elseif(!$isAdmin && !in_array($r['status'],['Approved','Scheduled','Completed'],true)): ?>
<form method="post" class="row g-2 mb-3">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
<label class="col-12 col-md-6">Remarks<input class="form-control" name="remarks" value="<?= e($r['remarks']) ?>" placeholder="Required when declining"></label>
<div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" name="status" value="Approved" data-review="approve" data-review-name="<?= e($r['full_name']) ?>" data-review-type="<?= e($r['request_type']) ?>" data-review-when="<?= e(is_other_request((string) $r['request_type']) && !empty($r['schedule_date']) ? appointment_window_label((string) $r['schedule_date'], $r['schedule_end'] ?? null) : '') ?>">Approve</button><button class="btn btn-outline-danger" name="status" value="Rejected" data-review="decline" data-review-name="<?= e($r['full_name']) ?>" data-review-type="<?= e($r['request_type']) ?>" data-review-when="<?= e(is_other_request((string) $r['request_type']) && !empty($r['schedule_date']) ? appointment_window_label((string) $r['schedule_date'], $r['schedule_end'] ?? null) : '') ?>">Decline</button></div>
</form>
<?php elseif(!is_other_request((string) $r['request_type']) && in_array($r['status'],['Approved','Scheduled'],true)): ?>
<form method="post" class="row g-2 mt-2">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
<label class="col-md-4">Item / assistance provided<input class="form-control" name="item" maxlength="180" required value="<?= $r['request_type']==='Death Assistance' ? 'Death Assistance' : e($r['request_type']) ?>"></label>
<label class="col-md-2">Quantity<input class="form-control" type="number" name="quantity" step="0.01" min="0.01" max="9999999999.99" required value="<?= $r['request_type']==='Death Assistance' ? '1' : '1' ?>"></label>
<label class="col-md-2">Unit<input class="form-control" name="unit" placeholder="packs, tablets, grant" maxlength="40" required value="<?= $r['request_type']==='Death Assistance' ? 'grant' : '' ?>"></label>
<label class="col-md-4">Amount (PHP)<input class="form-control" type="number" name="amount" step="0.01" min="0" max="9999999999.99" required value="<?= $r['request_type']==='Death Assistance' ? e($deathAmount) : '' ?>"></label>
<label class="col-md-4">Release date<input class="form-control" name="released_at" type="date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></label>
<label class="col-6 col-md-4">From<select class="form-select appointment-start" name="claim_start" required><?php foreach (appointment_hour_options() as $value => $hourLabel): ?><option value="<?= e($value) ?>" <?= $value === '08:00' ? 'selected' : '' ?> <?= $value === '17:00' ? 'disabled' : '' ?>><?= e($hourLabel) ?></option><?php endforeach; ?></select></label>
<label class="col-6 col-md-4">To<select class="form-select appointment-end" name="claim_end" required><?php foreach (appointment_hour_options() as $value => $hourLabel): ?><option value="<?= e($value) ?>" <?= $value === '17:00' ? 'selected' : '' ?> <?= $value <= '08:00' ? 'disabled' : '' ?>><?= e($hourLabel) ?></option><?php endforeach; ?></select></label>
<label class="col-md-4">Received by<input class="form-control" name="received_by" maxlength="150" value="<?= e($r['full_name']) ?>" required></label>
<label class="col-md-4">Release remarks<input class="form-control" name="remarks"></label>
<div class="col-12"><button class="btn btn-success" name="action" value="release" data-review="release" data-review-name="<?= e($r['full_name']) ?>">Record release</button></div>
</form>
<p class="text-muted">Choose the hours they can claim, from 8:00 AM to 5:00 PM. The queue number is given when the amount, release date, and claim hours are recorded.</p>
<?php elseif($isAdmin && is_other_request((string) $r['request_type']) && in_array($r['status'],['Approved','Scheduled'],true)): ?>
<p class="text-muted">The senior chose this appointment. Staff approve or decline it. The officer verifies when the visit is done.</p>
<?php elseif($isAdmin && $r['status']!=='Completed'): ?>
<p class="text-muted"><?= is_other_request((string) $r['request_type']) ? 'Waiting for staff to approve or decline this request.' : 'Waiting for staff to approve or decline this request before the amount and release date can be recorded.' ?></p>
<?php endif; ?>
</article>
<?php endforeach; ?>
<script>
document.querySelectorAll('form').forEach(form => {
    const start = form.querySelector('.appointment-start');
    const end = form.querySelector('.appointment-end');
    if (!start || !end) return;
    const limitWindow = () => {
        [...end.options].forEach(option => { option.disabled = option.value <= start.value; });
        if (end.value <= start.value) {
            const later = [...end.options].find(option => option.value > start.value);
            if (later) end.value = later.value;
        }
    };
    start.addEventListener('change', limitWindow);
    limitWindow();
});
document.querySelectorAll('.appointment-day').forEach((day) => {
    const hour = day.form ? day.form.querySelector('.appointment-hour') : null;
    if (!hour) return;
    day.addEventListener('change', async () => {
        if (!day.value) return;
        const base = document.body.dataset.baseUrl || '';
        const response = await fetch(base + '/api/appointment_slots.php?date=' + encodeURIComponent(day.value) + '&request_id=' + encodeURIComponent(day.dataset.requestId || '0'), { headers: { Accept: 'application/json' } });
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
</script>
<div class="modal fade" id="action-confirm" tabindex="-1" aria-labelledby="action-confirm-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="action-confirm-title">Confirm</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="action-confirm-close"></button>
            </div>
            <div class="modal-body"><p class="mb-0" id="action-confirm-text"></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="action-confirm-cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="action-confirm-submit">Confirm</button>
            </div>
        </div>
    </div>
</div>
<script>
(() => {
    const modalEl = document.getElementById('action-confirm');
    const confirmButton = document.getElementById('action-confirm-submit');
    const cancelButton = document.getElementById('action-confirm-cancel');
    const closeButton = document.getElementById('action-confirm-close');
    const title = document.getElementById('action-confirm-title');
    const text = document.getElementById('action-confirm-text');
    if (!modalEl || !confirmButton || !cancelButton || !closeButton || !title || !text) return;
    const modal = () => bootstrap.Modal.getOrCreateInstance(modalEl);
    let pendingForm = null;
    let pendingButton = null;
    let pendingKind = '';
    const money = (value) => {
        const amount = Number(value);
        return Number.isFinite(amount) ? amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : value;
    };
    const claimDate = (value) => {
        const parts = String(value).split('-');
        if (parts.length !== 3) return value;
        const when = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return Number.isNaN(when.getTime()) ? value : when.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };
    const optionLabel = (select) => (select && select.selectedIndex >= 0 ? select.options[select.selectedIndex].textContent : '');
    const resetConfirm = () => {
        confirmButton.disabled = false;
        confirmButton.className = 'btn btn-primary';
        confirmButton.textContent = 'Confirm';
        confirmButton.removeAttribute('aria-busy');
        cancelButton.disabled = false;
        closeButton.disabled = false;
        pendingForm = null;
        pendingButton = null;
        pendingKind = '';
    };
    document.querySelectorAll('[data-review="decline"]').forEach((button) => {
        const remarks = button.form ? button.form.querySelector('[name="remarks"]') : null;
        if (remarks) remarks.addEventListener('input', () => remarks.setCustomValidity(''));
        button.addEventListener('click', (event) => {
            if (!remarks) return;
            remarks.setCustomValidity('');
            if (remarks.value.trim() === '') {
                event.preventDefault();
                remarks.setCustomValidity('Explain the reason for the decline.');
                remarks.reportValidity();
            }
        }, true);
    });
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const submitter = event.submitter;
            if (!submitter || !submitter.dataset.review) return;
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }
            if (form.dataset.confirmed === '1') {
                form.dataset.submitting = '1';
                return;
            }
            const remarks = form.querySelector('[name="remarks"]');
            if (remarks) remarks.setCustomValidity('');
            if (submitter.dataset.review === 'decline' && (!remarks || remarks.value.trim() === '')) {
                event.preventDefault();
                if (remarks) {
                    remarks.setCustomValidity('Explain the reason for the decline.');
                    remarks.reportValidity();
                }
                return;
            }
            event.preventDefault();
            if (modalEl.classList.contains('show')) return;
            pendingForm = form;
            pendingButton = submitter;
            pendingKind = submitter.dataset.review;
            const name = submitter.dataset.reviewName || 'this senior';
            const when = submitter.dataset.reviewWhen || '';
            if (pendingKind === 'approve') {
                title.textContent = 'Approve request';
                confirmButton.className = 'btn btn-primary';
                confirmButton.textContent = 'Approve';
                text.textContent = when !== ''
                    ? 'Approve the other request for ' + name + ' on ' + when + '?'
                    : 'Approve the ' + (submitter.dataset.reviewType || 'assistance') + ' request for ' + name + '?';
            } else if (pendingKind === 'decline') {
                title.textContent = 'Decline request';
                confirmButton.className = 'btn btn-danger';
                confirmButton.textContent = 'Decline';
                const reason = remarks ? remarks.value.trim() : '';
                text.textContent = when !== ''
                    ? 'Decline the appointment for ' + name + ' on ' + when + '? The senior will see this reason: ' + reason
                    : 'Decline the ' + (submitter.dataset.reviewType || 'assistance') + ' request for ' + name + '? The senior will see this reason: ' + reason;
            } else {
                const amount = form.querySelector('[name="amount"]');
                const date = form.querySelector('[name="released_at"]');
                const start = form.querySelector('[name="claim_start"]');
                const end = form.querySelector('[name="claim_end"]');
                title.textContent = 'Record release';
                confirmButton.className = 'btn btn-success';
                confirmButton.textContent = 'Record release';
                text.textContent = 'Record the release of PHP ' + money(amount ? amount.value : '') + ' for ' + name + '. Claim on ' + claimDate(date ? date.value : '') + ', ' + optionLabel(start) + ' to ' + optionLabel(end) + '.';
            }
            modal().show();
        });
    });
    confirmButton.addEventListener('click', () => {
        if (!pendingForm || !pendingButton || confirmButton.disabled) return;
        pendingForm.dataset.confirmed = '1';
        confirmButton.disabled = true;
        cancelButton.disabled = true;
        closeButton.disabled = true;
        confirmButton.setAttribute('aria-busy', 'true');
        confirmButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + (pendingKind === 'approve' ? 'Approving...' : (pendingKind === 'decline' ? 'Declining...' : 'Recording...'));
        pendingForm.requestSubmit(pendingButton);
    });
    modalEl.addEventListener('hide.bs.modal', (event) => {
        if (pendingForm && pendingForm.dataset.confirmed === '1') event.preventDefault();
    });
    modalEl.addEventListener('hidden.bs.modal', resetConfirm);
})();
</script>
<?php document_preview_modal(); require __DIR__.'/../includes/footer.php'; }