<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/claim_queue.php';

function officer_queue_ready(array $row, bool $isAppointment): bool
{
    if ($isAppointment) {
        return !empty($row['queue_id']);
    }
    return !empty($row['queue_id'])
        && $row['released_at'] !== null
        && $row['amount'] !== null
        && $row['amount'] !== '';
}

function officer_queue_rows(string $type, string $day): array
{
    $sql = 'SELECT ar.request_id, ar.request_type, ar.description, ar.schedule_date, ar.schedule_end,
            u.full_name, s.id_control_number,
            q.queue_id, q.queue_number, q.queue_date, q.claimed_at, q.queue_type,
            d.amount, d.released_at
        FROM assistance_requests ar
        JOIN senior_citizens s ON s.senior_id = ar.senior_id
        JOIN users u ON u.user_id = s.user_id
        LEFT JOIN assistance_distributions d ON d.request_id = ar.request_id
        LEFT JOIN claim_queues q ON q.request_id = ar.request_id AND q.queue_type = ?';
    $params = [$type];
    if ($type === 'Appointment') {
        $sql .= " WHERE ar.request_type = 'Other Request' AND ar.status IN ('Scheduled', 'Completed')";
        if ($day !== '') {
            $sql .= ' AND DATE(ar.schedule_date) = ?';
            $params[] = $day;
        }
        $sql .= ' ORDER BY q.claimed_at IS NULL DESC, ar.schedule_date, q.queue_number, ar.request_id';
    } else {
        $sql .= " WHERE ar.request_type <> 'Other Request' AND ar.status IN ('Approved', 'Scheduled', 'Completed')";
        if ($day !== '') {
            $sql .= ' AND DATE(d.released_at) = ?';
            $params[] = $day;
        }
        $sql .= ' ORDER BY q.queue_id IS NULL, q.claimed_at IS NOT NULL, q.queue_number, d.released_at, ar.request_id';
    }
    $list = db()->prepare($sql);
    $list->execute($params);
    return $list->fetchAll();
}

function officer_claim_button(array $row, string $day, string $queueSearch, string $page): void
{
    if ($row['claimed_at']) {
        echo ($page === '/officer/appointments.php' ? 'Done ' : 'Claimed ') . e(date('g:i A', strtotime((string) $row['claimed_at'])));
        return;
    }
    ?>
    <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="claim">
        <input type="hidden" name="queue_id" value="<?= (int) $row['queue_id'] ?>">
        <input type="hidden" name="day" value="<?= e($day) ?>">
        <input type="hidden" name="queue" value="<?= e($queueSearch) ?>">
        <input type="hidden" name="page" value="<?= e($page) ?>">
        <?php $isDone = $page === '/officer/appointments.php'; ?>
        <button type="button" class="btn btn-sm btn-primary text-nowrap" data-claim-confirm data-claim-kind="<?= $isDone ? 'appointment' : 'assistance' ?>" data-claim-label="<?= e(claim_queue_label($row)) ?>" data-claim-name="<?= e($row['full_name']) ?>"><?= $isDone ? 'Mark as done' : 'Mark as claimed' ?></button>
    </form>
    <?php
}

function render_officer_queue(string $type): void
{
    $officer = require_role(['Barangay Officer']);
    $isAppointment = $type === 'Appointment';
    $page = $isAppointment ? '/officer/appointments.php' : '/officer/assistance.php';
    $day = (string) ($_GET['day'] ?? $_POST['day'] ?? '');
    if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
        $day = '';
    }
    $queueSearch = trim((string) ($_GET['queue'] ?? $_POST['queue'] ?? ''));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        try {
            if (($_POST['action'] ?? '') === 'claim') {
                $message = mark_queue_claimed((int) ($_POST['queue_id'] ?? 0), (int) $officer['user_id'], $type);
            } else {
                throw new RuntimeException('Choose a request from the list.');
            }
            flash('success', $message);
        } catch (Throwable $error) {
            flash('danger', $error instanceof PDOException ? 'Unable to mark the queue right now.' : $error->getMessage());
        }
        $params = ['day' => $day];
        if ($queueSearch !== '') {
            $params['queue'] = $queueSearch;
        }
        redirect($page . '?' . http_build_query($params));
    }
    $rows = officer_queue_rows($type, $day);
    $allDays = ['day' => ''];
    if ($queueSearch !== '') {
        $allDays['queue'] = $queueSearch;
    }
    require __DIR__ . '/../includes/header.php';
    require __DIR__ . '/../includes/sidebar.php';
    ?>
    <h1 class="page-title"><?= $isAppointment ? 'Appointments' : 'Assistance' ?></h1>
    <p class="text-muted"><?php if ($isAppointment): ?>The senior chooses a Monday to Friday hour. Saturday, Sunday, and regular holidays are closed. Verify the visit here when it is done. Appointment numbers keep counting from 001.<?php else: ?>These assistance requests involve money and do not use an appointment. The queue number appears after the amount and release date are recorded. Assistance numbers keep counting from 001.<?php endif; ?> The list starts with every day, and it narrows as you type a queue number.</p>
    <form method="get" class="panel mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="queue-day">Day</label>
                <input class="form-control" id="queue-day" type="date" name="day" value="<?= e($day) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="queue-number">Queue number</label>
                <input class="form-control" id="queue-number" name="queue" value="<?= e($queueSearch) ?>" inputmode="text" maxlength="8" placeholder="001" autocomplete="off">
            </div>
            <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
                <button class="btn btn-primary">Search</button>
                <a class="btn btn-outline-secondary" href="<?= e(BASE_URL . $page) ?>?<?= e(http_build_query($allDays)) ?>">All days</a>
            </div>
        </div>
    </form>
    <p class="text-muted"><?= $day === '' ? ($isAppointment ? 'Every other request.' : 'Every assistance request.') : e(date('M j, Y', strtotime($day))) . '.' ?></p>
    <?php
    $waiting = [];
    $listed = [];
    foreach ($rows as $row) {
        if ($isAppointment && !officer_queue_ready($row, true)) {
            $waiting[] = $row;
        } else {
            $listed[] = $row;
        }
    }
    ?>
    <?php if (!$rows): ?>
        <div class="panel"><?= $isAppointment ? ($day === '' ? 'No other requests yet.' : 'No other requests are recorded on this day.') : ($day === '' ? 'No seniors are receiving assistance.' : 'No seniors are receiving assistance on this day.') ?></div>
    <?php else: ?>
        <?php foreach ($waiting as $row): ?>
        <article class="panel mb-3">
            <h2 class="h6"><?= e($row['full_name']) ?> · <?= e((string) $row['id_control_number']) ?></h2>
            <p><?= e((string) $row['description']) ?></p>
            <p class="mb-0 text-muted">Waiting for the appointment queue.</p>
        </article>
        <?php endforeach; ?>
        <?php if ($listed): ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Queue</th><th><?= $isAppointment ? 'When' : 'Release date' ?></th><?php if (!$isAppointment): ?><th>Amount</th><?php endif; ?><th>Senior</th><th>Control no.</th><th><?= $isAppointment ? 'Request' : 'Assistance' ?></th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($listed as $row):
                    $ready = officer_queue_ready($row, $isAppointment);
                    $label = $ready ? claim_queue_label($row) : '';
                    ?>
                    <tr data-queue-row data-label="<?= e($label) ?>" data-number="<?= e($label === '' ? '' : (string) (int) $row['queue_number']) ?>">
                        <td class="fw-bold"><?= $ready ? e($label) : '—' ?></td>
                        <td><?php if ($isAppointment): ?><?= !empty($row['schedule_date']) ? e(date('M j, Y', strtotime((string) $row['schedule_date']))) : '—' ?><?php $visitWindow = appointment_window_label($row['schedule_date'] ?? null, $row['schedule_end'] ?? null, false); if ($visitWindow !== ''): ?> · <?= e($visitWindow) ?><?php endif; ?><?php else: ?><?= $row['released_at'] ? e(date('M j, Y', strtotime((string) $row['released_at']))) : '—' ?><?php $claimHours = release_claim_hours($row['schedule_date'] ?? null, $row['schedule_end'] ?? null, $row['released_at'] ?? null); if ($claimHours !== ''): ?> · <?= e($claimHours) ?><?php endif; ?><?php endif; ?></td>
                        <?php if (!$isAppointment): ?><td><?= $row['amount'] !== null && $row['amount'] !== '' ? 'PHP ' . e(number_format((float) $row['amount'], 2)) : '—' ?></td><?php endif; ?>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e((string) $row['id_control_number']) ?></td>
                        <td><?= e($isAppointment ? (string) $row['description'] : $row['request_type']) ?></td>
                        <td><?php if ($ready) { officer_claim_button($row, $day, $queueSearch, $page); } else { echo 'Waiting for release'; } ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted" data-queue-none hidden>No queue number matches.</p>
        <?php endif; ?>
    <?php endif; ?>
    <div class="modal fade" id="claim-confirm" tabindex="-1" aria-labelledby="claim-confirm-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="claim-confirm-title"><?= $isAppointment ? 'Mark as done' : 'Mark as claimed' ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"><p class="mb-0" id="claim-confirm-text"></p></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="claim-confirm-submit"><?= $isAppointment ? 'Mark as done' : 'Mark as claimed' ?></button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (() => {
        const input = document.getElementById('queue-number');
        const day = document.getElementById('queue-day');
        if (!input) return;
            const matches = (label, number, typed) => {
            const query = typed.toUpperCase().replace(/\s+/g, '');
            if (!query) return true;
            if (number === '') return false;
            const letter = query[0] === 'R' || query[0] === 'A' ? query[0] : '';
            if (letter && label[0] !== letter) return false;
            const digits = query.replace(/^[RA]-?/, '').replace(/\D/g, '');
            if (!digits) return true;
            const padded = String(number).padStart(Math.max(3, digits.length), '0');
            if (digits.startsWith('0') || digits.length >= 3) return padded.startsWith(digits);
            return String(number).startsWith(digits);
        };
        const apply = () => {
            document.querySelectorAll('[data-queue-row]').forEach(row => {
                row.hidden = !matches(row.dataset.label || '', row.dataset.number || '', input.value);
            });
            document.querySelectorAll('[data-queue-none]').forEach(note => {
                const table = note.previousElementSibling;
                const rows = table ? table.querySelectorAll('[data-queue-row]') : [];
                const visible = table ? table.querySelectorAll('[data-queue-row]:not([hidden])').length : 0;
                note.hidden = rows.length === 0 || visible > 0;
            });
            document.querySelectorAll('input[name="queue"]').forEach(field => {
                if (field !== input) field.value = input.value;
            });
        };
        input.addEventListener('input', apply);
        if (day && day.form) day.addEventListener('change', () => day.form.requestSubmit());
        apply();
    })();
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
    (() => {
        const modalEl = document.getElementById('claim-confirm');
        const confirmButton = document.getElementById('claim-confirm-submit');
        if (!modalEl || !confirmButton) return;
        const modal = () => bootstrap.Modal.getOrCreateInstance(modalEl);
        const title = document.getElementById('claim-confirm-title');
        const text = document.getElementById('claim-confirm-text');
        let pendingForm = null;
        document.querySelectorAll('[data-claim-confirm]').forEach((button) => {
            button.addEventListener('click', () => {
                pendingForm = button.form;
                const done = button.dataset.claimKind === 'appointment';
                const action = done ? 'done' : 'claimed';
                title.textContent = done ? 'Mark as done' : 'Mark as claimed';
                confirmButton.textContent = title.textContent;
                text.textContent = 'Mark ' + button.dataset.claimKind + ' ' + button.dataset.claimLabel + ' as ' + action + ' for ' + button.dataset.claimName + '?';
                modal().show();
            });
        });
        confirmButton.addEventListener('click', () => {
            if (!pendingForm || confirmButton.disabled) return;
            confirmButton.disabled = true;
            pendingForm.requestSubmit();
        });
        modalEl.addEventListener('hidden.bs.modal', () => {
            pendingForm = null;
            confirmButton.disabled = false;
        });
    })();
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';
}
