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

function appointment_slot_hours(): array
{
    $options = [];
    for ($hour = 8; $hour <= 17; $hour++) {
        $value = sprintf('%02d:00', $hour);
        $options[$value] = date('g:i A', strtotime($value));
    }
    return $options;
}

function appointment_slots_per_hour(): int
{
    $value = trim(setting_value('appointment_slots_per_hour', '5'));
    $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
    return $value === (string) $count ? $count : 5;
}

function easter_sunday(int $year): DateTimeImmutable
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

function proclaimed_regular_holidays(): array
{
    return [
        '2026-03-20' => "Eid'l Fitr",
        '2026-05-27' => "Eid'l Adha",
    ];
}

function regular_holidays(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }
    $easter = easter_sunday($year);
    $heroes = new DateTimeImmutable(sprintf('%04d-08-31', $year));
    while ((int) $heroes->format('N') !== 1) {
        $heroes = $heroes->modify('-1 day');
    }
    $holidays = [
        sprintf('%04d-01-01', $year) => "New Year's Day",
        $easter->modify('-3 days')->format('Y-m-d') => 'Maundy Thursday',
        $easter->modify('-2 days')->format('Y-m-d') => 'Good Friday',
        sprintf('%04d-04-09', $year) => 'Araw ng Kagitingan',
        sprintf('%04d-05-01', $year) => 'Labor Day',
        sprintf('%04d-06-12', $year) => 'Independence Day',
        $heroes->format('Y-m-d') => 'National Heroes Day',
        sprintf('%04d-11-30', $year) => 'Bonifacio Day',
        sprintf('%04d-12-25', $year) => 'Christmas Day',
        sprintf('%04d-12-30', $year) => 'Rizal Day',
    ];
    foreach (proclaimed_regular_holidays() as $date => $name) {
        if (str_starts_with($date, sprintf('%04d-', $year))) {
            $holidays[$date] = $name;
        }
    }
    ksort($holidays);
    $cache[$year] = $holidays;
    return $holidays;
}

function regular_holiday(string $day): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $day) {
        return null;
    }
    return regular_holidays((int) $date->format('Y'))[$day] ?? null;
}

function regular_holiday_map(int $fromYear, int $toYear): array
{
    $map = [];
    for ($year = $fromYear; $year <= $toYear; $year++) {
        foreach (regular_holidays($year) as $date => $name) {
            $map[$date] = $name;
        }
    }
    return $map;
}

function appointment_is_weekday(string $day): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    return $date instanceof DateTimeImmutable
        && $date->format('Y-m-d') === $day
        && (int) $date->format('N') <= 5;
}

function appointment_day_is_open(string $day): bool
{
    return appointment_is_weekday($day) && regular_holiday($day) === null;
}

function appointment_next_weekday(string $day): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day) ?: new DateTimeImmutable('today');
    while (!appointment_day_is_open($date->format('Y-m-d'))) {
        $date = $date->modify('+1 day');
    }
    return $date->format('Y-m-d');
}

function appointment_slot_is_bookable(string $day, string $hour): bool
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day . ' ' . $hour);
    return $start instanceof DateTimeImmutable
        && $start->format('Y-m-d H:i') === $day . ' ' . $hour
        && appointment_day_is_open($day)
        && $start >= new DateTimeImmutable('now');
}

function appointment_slot_usage(PDO $pdo, string $day, int $excludeRequestId = 0): array
{
    $counts = array_fill_keys(array_keys(appointment_slot_hours()), 0);
    $query = $pdo->prepare("SELECT DATE_FORMAT(schedule_date, '%H:%i') AS slot_hour, COUNT(*) AS taken
        FROM assistance_requests
        WHERE request_type = 'Other Request'
          AND status IN ('Pending', 'Scheduled', 'Completed')
          AND schedule_date >= ? AND schedule_date < DATE_ADD(?, INTERVAL 1 DAY)
          AND request_id <> ?
        GROUP BY slot_hour");
    $query->execute([$day . ' 00:00:00', $day, $excludeRequestId]);
    foreach ($query as $row) {
        $hour = (string) $row['slot_hour'];
        if (isset($counts[$hour])) {
            $counts[$hour] = (int) $row['taken'];
        }
    }
    return $counts;
}

function appointment_slot_choices(string $day, int $excludeRequestId = 0): array
{
    $limit = appointment_slots_per_hour();
    $usage = appointment_slot_usage(db(), $day, $excludeRequestId);
    $holiday = regular_holiday($day);
    $weekend = !appointment_is_weekday($day);
    $choices = [];
    foreach (appointment_slot_hours() as $value => $label) {
        $left = max(0, $limit - ($usage[$value] ?? 0));
        $bookable = appointment_slot_is_bookable($day, $value);
        $choices[] = [
            'value' => $value,
            'label' => $label,
            'left' => $left,
            'open' => $bookable && $left > 0,
            'state' => $holiday !== null ? 'holiday' : ($weekend ? 'weekend' : (!$bookable ? 'closed' : ($left > 0 ? 'open' : 'full'))),
        ];
    }
    return $choices;
}

function appointment_slot_values(string $rawDate, string $rawHour): array
{
    $hours = appointment_slot_hours();
    $day = DateTime::createFromFormat('!Y-m-d', $rawDate);
    $holiday = regular_holiday($rawDate);
    if ($holiday !== null) {
        throw new RuntimeException($holiday . ' is a regular holiday. The office is closed. Choose another Monday to Friday.');
    }
    if (!$day || $day->format('Y-m-d') !== $rawDate || !isset($hours[$rawHour]) || !appointment_slot_is_bookable($rawDate, $rawHour)) {
        throw new RuntimeException('Choose a Monday to Friday appointment, today or later, and an open hour from 8:00 AM to 5:00 PM. Saturday, Sunday, and regular holidays are closed.');
    }
    $start = $rawDate . ' ' . $rawHour . ':00';
    $end = (new DateTimeImmutable($start))->modify('+1 hour')->format('Y-m-d H:i:s');
    return [$start, $end, $rawDate];
}

function appointment_slot_fields(int $requestId, ?string $scheduleDate): void
{
    $day = $scheduleDate ? date('Y-m-d', strtotime($scheduleDate)) : date('Y-m-d');
    if ($day < date('Y-m-d') || !appointment_day_is_open($day)) {
        $day = appointment_next_weekday(date('Y-m-d'));
    }
    $savedHour = $scheduleDate && date('Y-m-d', strtotime($scheduleDate)) === $day ? date('H:i', strtotime($scheduleDate)) : '';
    $choices = appointment_slot_choices($day, $requestId);
    $selected = '';
    foreach ($choices as $choice) {
        if ($choice['value'] === $savedHour && $choice['open']) {
            $selected = $choice['value'];
            break;
        }
    }
    if ($selected === '') {
        foreach ($choices as $choice) {
            if ($choice['open']) {
                $selected = $choice['value'];
                break;
            }
        }
    }
    ?>
    <label class="col-12 col-md-4">Appointment date<input class="form-control appointment-day" type="text" name="appointment_date" min="<?= date('Y-m-d') ?>" required value="<?= e($day) ?>" data-request-id="<?= $requestId ?>" inputmode="none" autocomplete="off" readonly></label>
    <label class="col-12 col-md-4">Hour<select class="form-select appointment-hour" name="appointment_hour" required><?php if ($selected === ''): ?><option value="" selected disabled>No open hour</option><?php endif; ?><?php foreach ($choices as $choice): ?><option value="<?= e($choice['value']) ?>" <?= $choice['value'] === $selected ? 'selected' : '' ?> <?= $choice['open'] ? '' : 'disabled' ?>><?= e($choice['label'] . ' (' . ($choice['state'] === 'open' ? $choice['left'] . ' left' : $choice['state']) . ')') ?></option><?php endforeach; ?></select></label>
    <p class="col-12 small text-muted mb-0" data-en="Dates in red are Sundays and regular holidays. The office is closed, so those days cannot be chosen." data-ceb="Ang pula nga petsa kay Dominggo ug regular holiday. Sirado ang opisina, busa dili kini mapili.">Dates in red are Sundays and regular holidays. The office is closed, so those days cannot be chosen.</p>
    <?php
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

function set_other_appointment(int $requestId, string $rawDate, string $rawHour, int $staffId): string
{
    [$start, $end, $rawDate] = appointment_slot_values($rawDate, $rawHour);
    $pdo = db();
    $lockName = 'appt-' . str_replace(['-', ':', ' '], '', $start);
    $locked = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 5)')->fetchColumn();
    if ((string) $locked !== '1') {
        throw new RuntimeException('That hour is being booked. Try again.');
    }
    try {
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
        if (appointment_slot_taken($pdo, $start, $end, $requestId) >= appointment_slots_per_hour()) {
            throw new RuntimeException('That hour is full. Choose another open hour.');
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
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
    }
}

function appointment_slot_taken(PDO $pdo, string $start, string $end, int $excludeRequestId = 0): int
{
    $taken = $pdo->prepare("SELECT COUNT(*) FROM assistance_requests WHERE request_type = 'Other Request' AND status IN ('Pending', 'Scheduled', 'Completed') AND schedule_date >= ? AND schedule_date < ? AND request_id <> ?");
    $taken->execute([$start, $end, $excludeRequestId]);
    return (int) $taken->fetchColumn();
}

function confirm_other_appointment(int $requestId, int $staffId): string
{
    $pdo = db();
    $pdo->beginTransaction();
    $lockName = null;
    try {
        $query = $pdo->prepare('SELECT ar.status, ar.request_type, ar.schedule_date, ar.schedule_end, s.user_id, u.full_name
            FROM assistance_requests ar
            JOIN senior_citizens s ON s.senior_id = ar.senior_id
            JOIN users u ON u.user_id = s.user_id
            WHERE ar.request_id = ? FOR UPDATE');
        $query->execute([$requestId]);
        $request = $query->fetch();
        if (!$request || $request['request_type'] !== 'Other Request' || !in_array($request['status'], ['Pending', 'Under Review'], true)) {
            throw new RuntimeException('Approve the other request while it is still pending.');
        }
        $start = trim((string) ($request['schedule_date'] ?? ''));
        $end = trim((string) ($request['schedule_end'] ?? ''));
        $day = $start !== '' ? date('Y-m-d', strtotime($start)) : '';
        $hour = $start !== '' ? date('H:i', strtotime($start)) : '';
        $expectedEnd = $start !== '' ? (new DateTimeImmutable($start))->modify('+1 hour')->format('Y-m-d H:i:s') : '';
        $holiday = $day !== '' ? regular_holiday($day) : null;
        if ($holiday !== null) {
            throw new RuntimeException($holiday . ' is a regular holiday. The office is closed. Decline it so the senior can choose another date.');
        }
        if ($start === '' || $end !== $expectedEnd || !appointment_slot_is_bookable($day, $hour)) {
            throw new RuntimeException('This appointment hour has passed or was not chosen. Decline it so the senior can choose another date and hour.');
        }
        $lockName = 'appt-' . str_replace(['-', ':', ' '], '', $start);
        $locked = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 5)')->fetchColumn();
        if ((string) $locked !== '1') {
            throw new RuntimeException('That hour is being booked. Try again.');
        }
        if (appointment_slot_taken($pdo, $start, $expectedEnd, $requestId) >= appointment_slots_per_hour()) {
            throw new RuntimeException('That hour is full. Decline it so the senior can choose another hour.');
        }
        $pdo->prepare("UPDATE assistance_requests SET status='Scheduled', processed_by=? WHERE request_id=?")->execute([$staffId, $requestId]);
        $queue = claim_queue_for_request($pdo, $requestId, 'Appointment', $day);
        $label = claim_queue_label($queue);
        $when = appointment_window_label($start, $expectedEnd);
        application_event((int) $request['user_id'], $requestId, 'Scheduled', 'Appointment confirmed for ' . $when . '. Appointment queue number ' . $label . '.', $staffId);
        notify_user((int) $request['user_id'], 'Appointment confirmed', 'Your other request is confirmed for ' . $when . '. Appointment queue number ' . $label . '.', null, $requestId);
        audit_log($staffId, 'Confirmed Other Request appointment ' . $label, 'assistance_requests', $requestId);
        $pdo->commit();
        return 'Appointment ' . $label . ' confirmed for ' . $request['full_name'] . ' on ' . $when . '.';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        if ($lockName !== null) {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
        }
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
