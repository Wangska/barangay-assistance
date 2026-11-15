<?php
declare(strict_types=1);

function claim_queue_label(array $queue): string
{
    return sprintf('%03d', (int) $queue['queue_number']);
}

function claim_queue_code(string $type, string $date, int $number): string
{
    $prefix = $type === 'Appointment' ? 'A' : 'R';
    return $prefix . '-' . str_replace('-', '', $date) . '-' . sprintf('%03d', $number);
}

function claim_queue_for_request(PDO $pdo, int $requestId, string $type, string $date): array
{
    if (!in_array($type, ['Release', 'Appointment'], true) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new RuntimeException('The queue number could not be issued.');
    }
    $existing = $pdo->prepare('SELECT * FROM claim_queues WHERE request_id=? AND queue_type=? ORDER BY queue_id DESC LIMIT 1 FOR UPDATE');
    $existing->execute([$requestId, $type]);
    $row = $existing->fetch();
    if ($row) {
        if (!$row['claimed_at'] && substr((string) $row['queue_date'], 0, 10) !== $date) {
            $code = claim_queue_code($type, $date, (int) $row['queue_number']);
            $pdo->prepare('UPDATE claim_queues SET queue_date=?, queue_code=? WHERE queue_id=?')
                ->execute([$date, $code, (int) $row['queue_id']]);
            $row['queue_date'] = $date;
            $row['queue_code'] = $code;
        }
        return $row;
    }
    return issue_claim_queue($pdo, $requestId, $type, $date);
}

function issue_claim_queue(PDO $pdo, int $requestId, string $type, string $date): array
{
    if (!in_array($type, ['Release', 'Appointment'], true) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new RuntimeException('The queue number could not be issued.');
    }
    $existing = $pdo->prepare('SELECT * FROM claim_queues WHERE request_id=? AND queue_type=? AND queue_date=? FOR UPDATE');
    $existing->execute([$requestId, $type, $date]);
    $row = $existing->fetch();
    if ($row) {
        return $row;
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $max = $pdo->prepare('SELECT COALESCE(MAX(queue_number), 0) FROM claim_queues WHERE queue_type=?');
        $max->execute([$type]);
        $number = (int) $max->fetchColumn() + 1;
        $code = claim_queue_code($type, $date, $number);
        try {
            $pdo->prepare('INSERT INTO claim_queues (request_id, queue_type, queue_date, queue_number, queue_code) VALUES (?,?,?,?,?)')
                ->execute([$requestId, $type, $date, $number, $code]);
            $created = $pdo->prepare('SELECT * FROM claim_queues WHERE queue_id=?');
            $created->execute([(int) $pdo->lastInsertId()]);
            $row = $created->fetch();
            if ($row) {
                return $row;
            }
        } catch (PDOException $error) {
            if ($attempt === 2 || !str_contains($error->getMessage(), 'Duplicate')) {
                throw $error;
            }
        }
    }
    throw new RuntimeException('The queue number could not be issued.');
}

function appointment_hour_options(): array
{
    $options = [];
    for ($hour = 8; $hour <= 17; $hour++) {
        $value = sprintf('%02d:00', $hour);
        $options[$value] = date('g:i A', strtotime($value));
    }
    return $options;
}

function appointment_window_label(?string $start, ?string $end, bool $includeDate = true): string
{
    if ($start === null || trim($start) === '') {
        return '';
    }
    $from = date('g:i A', strtotime($start));
    $times = $end !== null && trim($end) !== '' ? $from . ' to ' . date('g:i A', strtotime($end)) : $from;
    return $includeDate ? date('M j, Y', strtotime($start)) . ', ' . $times : $times;
}

function appointment_window_fields(?string $scheduleDate, ?string $scheduleEnd): void
{
    $hours = appointment_hour_options();
    $date = $scheduleDate ? date('Y-m-d', strtotime($scheduleDate)) : '';
    $start = $scheduleDate ? date('H:i', strtotime($scheduleDate)) : '08:00';
    $end = $scheduleEnd ? date('H:i', strtotime($scheduleEnd)) : '17:00';
    if (!isset($hours[$start]) || $start === '17:00') {
        $start = '08:00';
    }
    if (!isset($hours[$end]) || $end <= $start) {
        $end = '17:00';
    }
    ?>
    <label class="col-12 col-md-4">Appointment date<input class="form-control" type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" required value="<?= e($date) ?>"></label>
    <label class="col-6 col-md-3">From<select class="form-select appointment-start" name="appointment_start" required><?php foreach ($hours as $value => $label): ?><option value="<?= e($value) ?>" <?= $value === $start ? 'selected' : '' ?> <?= $value === '17:00' ? 'disabled' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="col-6 col-md-3">To<select class="form-select appointment-end" name="appointment_end" required><?php foreach ($hours as $value => $label): ?><option value="<?= e($value) ?>" <?= $value === $end ? 'selected' : '' ?> <?= $value <= $start ? 'disabled' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <?php
}

function schedule_claim_appointment(int $requestId, string $rawDate, string $rawStart, string $rawEnd, int $actorId): void
{
    $day = DateTime::createFromFormat('!Y-m-d', $rawDate);
    $hours = appointment_hour_options();
    if (!$day || $day->format('Y-m-d') !== $rawDate || $day < new DateTime('today') || !isset($hours[$rawStart], $hours[$rawEnd]) || $rawStart >= $rawEnd) {
        throw new RuntimeException('Choose a date of today or later and a visit window from 8:00 AM to 5:00 PM.');
    }
    $start = $rawDate . ' ' . $rawStart . ':00';
    $end = $rawDate . ' ' . $rawEnd . ':00';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT ar.status, s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE ar.request_id=? FOR UPDATE');
        $query->execute([$requestId]);
        $request = $query->fetch();
        if (!$request || !in_array($request['status'], ['Approved', 'Scheduled'], true)) {
            throw new RuntimeException('Set the appointment after the request is approved.');
        }
        $pdo->prepare("UPDATE assistance_requests SET status='Scheduled', schedule_date=?, schedule_end=?, processed_by=? WHERE request_id=?")
            ->execute([$start, $end, $actorId, $requestId]);
        $existing = $pdo->prepare("SELECT * FROM claim_queues WHERE request_id=? AND queue_type='Appointment' ORDER BY queue_id DESC LIMIT 1 FOR UPDATE");
        $existing->execute([$requestId]);
        $queue = $existing->fetch();
        if ($queue && !$queue['claimed_at'] && substr((string) $queue['queue_date'], 0, 10) !== $rawDate) {
            $code = claim_queue_code('Appointment', $rawDate, (int) $queue['queue_number']);
            $pdo->prepare('UPDATE claim_queues SET queue_date=?, queue_code=? WHERE queue_id=?')
                ->execute([$rawDate, $code, (int) $queue['queue_id']]);
        }
        $when = appointment_window_label($start, $end);
        application_event((int) $request['user_id'], $requestId, 'Scheduled', 'Appointment on ' . $when . '.', $actorId);
        notify_user((int) $request['user_id'], 'Appointment scheduled', 'Your appointment is on ' . $when . '. Your queue number is given after the amount and release date are recorded.', null, $requestId);
        audit_log($actorId, 'Scheduled assistance appointment', 'assistance_requests', $requestId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function appointment_window_values(string $rawDate, string $rawStart, string $rawEnd): array
{
    $hours = appointment_hour_options();
    $day = DateTime::createFromFormat('!Y-m-d', $rawDate);
    if (!$day || $day->format('Y-m-d') !== $rawDate || $day < new DateTime('today') || !isset($hours[$rawStart], $hours[$rawEnd]) || $rawStart >= $rawEnd) {
        throw new RuntimeException('Choose an appointment date of today or later and a timeframe from 8:00 AM to 5:00 PM.');
    }
    return [$rawDate . ' ' . $rawStart . ':00', $rawDate . ' ' . $rawEnd . ':00', $rawDate];
}

function set_other_appointment(int $requestId, string $rawDate, string $rawStart, string $rawEnd, int $staffId): string
{
    [$start, $end, $rawDate] = appointment_window_values($rawDate, $rawStart, $rawEnd);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT ar.status, ar.request_type, s.user_id, u.full_name
            FROM assistance_requests ar
            JOIN senior_citizens s ON s.senior_id = ar.senior_id
            JOIN users u ON u.user_id = s.user_id
            WHERE ar.request_id = ? FOR UPDATE');
        $query->execute([$requestId]);
        $request = $query->fetch();
        if (!$request || $request['request_type'] !== 'Other Request' || !in_array($request['status'], ['Approved', 'Scheduled'], true)) {
            throw new RuntimeException('Set the appointment after the other request is approved.');
        }
        $claimed = $pdo->prepare("SELECT claimed_at FROM claim_queues WHERE request_id=? AND queue_type='Appointment' ORDER BY queue_id DESC LIMIT 1 FOR UPDATE");
        $claimed->execute([$requestId]);
        if ($claimed->fetchColumn()) {
            throw new RuntimeException('This appointment is already done, so the date stays the same.');
        }
        $pdo->prepare("UPDATE assistance_requests SET status='Scheduled', schedule_date=?, schedule_end=?, processed_by=? WHERE request_id=?")
            ->execute([$start, $end, $staffId, $requestId]);
        $queue = claim_queue_for_request($pdo, $requestId, 'Appointment', $rawDate);
        $label = claim_queue_label($queue);
        $when = appointment_window_label($start, $end);
        application_event((int) $request['user_id'], $requestId, 'Scheduled', 'Appointment set for ' . $when . '. Appointment queue number ' . $label . '.', $staffId);
        notify_user((int) $request['user_id'], 'Appointment set', 'Your other request is set for ' . $when . '. Appointment queue number ' . $label . '.', null, $requestId);
        audit_log($staffId, 'Set Other Request appointment ' . $label, 'assistance_requests', $requestId);
        $pdo->commit();
        return 'Appointment ' . $label . ' set for ' . $request['full_name'] . '.';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function claim_queue_search(string $raw): array
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') {
        return [null, null];
    }
    if (preg_match('/^([RA])[-\s]*(\d{1,6})$/', $raw, $match) === 1) {
        return [$match[1] === 'A' ? 'Appointment' : 'Release', (int) $match[2]];
    }
    if (preg_match('/^\d{1,6}$/', $raw) === 1) {
        return [null, (int) $raw];
    }
    throw new RuntimeException('Enter a queue number such as 001.');
}

function mark_queue_claimed(int $queueId, int $officerId, string $expectedType): string
{
    if ($queueId < 1) {
        throw new RuntimeException('Choose a queue from the list.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT q.queue_id, q.request_id, q.queue_type, q.queue_number, q.claimed_at, ar.status, s.user_id, u.full_name
            FROM claim_queues q
            JOIN assistance_requests ar ON ar.request_id = q.request_id
            JOIN senior_citizens s ON s.senior_id = ar.senior_id
            JOIN users u ON u.user_id = s.user_id
            WHERE q.queue_id = ? FOR UPDATE');
        $query->execute([$queueId]);
        $queue = $query->fetch();
        if (!$queue || $queue['queue_type'] !== $expectedType) {
            throw new RuntimeException('That queue number was not found.');
        }
        $label = claim_queue_label($queue);
        $kind = $queue['queue_type'] === 'Appointment' ? 'Appointment' : 'Assistance';
        if ($queue['claimed_at']) {
            throw new RuntimeException($kind . ' ' . $label . ' was already claimed.');
        }
        $pdo->prepare('UPDATE claim_queues SET claimed_at=NOW(), claimed_by=? WHERE queue_id=? AND claimed_at IS NULL')
            ->execute([$officerId, $queueId]);
        if ($queue['queue_type'] === 'Appointment') {
            $pdo->prepare("UPDATE assistance_requests SET status='Completed', processed_by=? WHERE request_id=? AND status='Scheduled'")
                ->execute([$officerId, (int) $queue['request_id']]);
            application_event((int) $queue['user_id'], (int) $queue['request_id'], 'Completed', 'Appointment ' . $label . ' verified as done.', $officerId);
            notify_user((int) $queue['user_id'], 'Appointment done', 'Your appointment ' . $label . ' has been verified as done.', null, (int) $queue['request_id']);
            audit_log($officerId, 'Verified appointment ' . $label, 'claim_queues', $queueId);
            $pdo->commit();
            return $queue['full_name'] . ' is done for appointment ' . $label . '.';
        }
        audit_log($officerId, 'Claimed ' . $kind . ' ' . $label, 'claim_queues', $queueId);
        $pdo->commit();
        return $queue['full_name'] . ' claimed ' . $kind . ' ' . $label . '.';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
