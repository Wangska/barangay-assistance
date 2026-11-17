<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/claim_queue.php';
require_once __DIR__ . '/../includes/simple_pdf.php';

function officer_queue_counts(): array
{
    $counts = [
        'Release' => ['claimed' => 0, 'unclaimed' => 0],
        'Appointment' => ['claimed' => 0, 'unclaimed' => 0],
    ];
    foreach (db()->query("SELECT queue_type, SUM(claimed_at IS NOT NULL) AS claimed, SUM(claimed_at IS NULL) AS unclaimed FROM claim_queues GROUP BY queue_type") as $row) {
        $type = (string) $row['queue_type'];
        if (!isset($counts[$type])) {
            continue;
        }
        $counts[$type] = ['claimed' => (int) $row['claimed'], 'unclaimed' => (int) $row['unclaimed']];
    }
    return $counts;
}

function officer_ring_style(int $done, int $waiting, string $doneColor, string $waitingColor): string
{
    $total = $done + $waiting;
    if ($total === 0) {
        return '#e7eeec';
    }
    $end = round($done / $total * 100, 2);
    return 'conic-gradient(' . $doneColor . ' 0% ' . $end . '%, ' . $waitingColor . ' ' . $end . '% 100%)';
}

function officer_open_queues(string $type): array
{
    $query = db()->prepare("SELECT q.queue_number, q.queue_date, q.queue_type, u.full_name, ar.request_type, ar.description, ar.schedule_date, ar.schedule_end
        FROM claim_queues q
        JOIN assistance_requests ar ON ar.request_id = q.request_id
        JOIN senior_citizens s ON s.senior_id = ar.senior_id
        JOIN users u ON u.user_id = s.user_id
        WHERE q.queue_type = ? AND q.claimed_at IS NULL
        ORDER BY q.queue_date, q.queue_number
        LIMIT 4");
    $query->execute([$type]);
    return $query->fetchAll();
}

function officer_report_period(): array
{
    $period = ($_GET['period'] ?? 'day') === 'month' ? 'month' : 'day';
    if ($period === 'month') {
        $month = (string) ($_GET['month'] ?? date('Y-m'));
        $date = DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m') !== $month) {
            $date = new DateTimeImmutable('first day of this month');
            $month = $date->format('Y-m');
        }
        return [$period, $month, $date->format('Y-m-d'), $date->modify('+1 month')->format('Y-m-d'), $date->format('F Y')];
    }
    $day = (string) ($_GET['day'] ?? date('Y-m-d'));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $day) {
        $date = new DateTimeImmutable('today');
        $day = $date->format('Y-m-d');
    }
    return [$period, $day, $day, $date->modify('+1 day')->format('Y-m-d'), $date->format('F j, Y')];
}

function officer_report_rows(string $type, string $start, string $end): array
{
    $query = db()->prepare("SELECT q.queue_number, q.queue_date, q.claimed_at, q.queue_type,
            u.full_name, s.id_control_number, ar.request_type, ar.description,
            ar.schedule_date, ar.schedule_end, d.amount, d.released_at
        FROM claim_queues q
        JOIN assistance_requests ar ON ar.request_id = q.request_id
        JOIN senior_citizens s ON s.senior_id = ar.senior_id
        JOIN users u ON u.user_id = s.user_id
        LEFT JOIN assistance_distributions d ON d.request_id = ar.request_id
        WHERE q.queue_type = ? AND q.queue_date >= ? AND q.queue_date < ?
        ORDER BY q.claimed_at IS NULL, q.queue_number");
    $query->execute([$type, $start, $end]);
    return $query->fetchAll();
}

function officer_split_claimed(array $rows): array
{
    $claimed = [];
    $unclaimed = [];
    foreach ($rows as $row) {
        if ($row['claimed_at']) {
            $claimed[] = $row;
        } else {
            $unclaimed[] = $row;
        }
    }
    return [$claimed, $unclaimed];
}

function render_officer_home(): void
{
    $officer = require_role(['Barangay Officer']);
    $counts = officer_queue_counts();
    $assistance = $counts['Release'];
    $appointments = $counts['Appointment'];
    $assistanceTotal = $assistance['claimed'] + $assistance['unclaimed'];
    $appointmentTotal = $appointments['claimed'] + $appointments['unclaimed'];
    $openAssistance = officer_open_queues('Release');
    $openAppointments = officer_open_queues('Appointment');
    $firstName = explode(' ', trim($officer['full_name']))[0];
    $greeting = (int) date('G') < 12 ? 'Good morning' : ((int) date('G') < 18 ? 'Good afternoon' : 'Good evening');
    require __DIR__ . '/../includes/header.php';
    require __DIR__ . '/../includes/sidebar.php';
    ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard.css?v=20261115a">
<div class="dashboard">
<header class="dash-heading">
    <div><p class="dash-eyebrow">CLAIM DESK</p><h1>Officer dashboard</h1></div>
    <span class="dash-date"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= e(date('D, M j, Y')) ?></span>
</header>
<section class="dash-welcome">
    <div class="welcome-copy">
        <span class="welcome-label"><span class="welcome-dot"></span> Senior Assist · Barangay Tungkil</span>
        <h2><?= e($greeting) ?>, <?= e($firstName) ?>.</h2>
        <p>See how many assistance claims and appointment visits are finished, and which ones are still waiting.</p>
        <div class="welcome-actions">
            <a class="dash-button light" href="<?= BASE_URL ?>/officer/assistance.php">Assistance <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            <a class="dash-button light" href="<?= BASE_URL ?>/officer/appointments.php">Appointments <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            <a class="welcome-secondary" href="<?= BASE_URL ?>/officer/reports.php">View report <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
    </div>
    <div class="welcome-summary">
        <span class="summary-orbit" aria-hidden="true"></span>
        <div class="summary-symbol"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div>
        <span class="summary-number"><?= number_format($assistance['unclaimed'] + $appointments['unclaimed']) ?></span>
        <span class="summary-caption">still waiting</span>
    </div>
</section>
<div class="dash-metrics">
    <a class="dash-metric" href="<?= BASE_URL ?>/officer/assistance.php"><div class="metric-top"><span class="metric-icon green"><i class="bi bi-check2-circle" aria-hidden="true"></i></span></div><strong><?= number_format($assistance['claimed']) ?></strong><span class="metric-name">Assistance claimed</span><small>Marked as claimed</small></a>
    <a class="dash-metric" href="<?= BASE_URL ?>/officer/assistance.php"><div class="metric-top"><span class="metric-icon amber"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span></div><strong><?= number_format($assistance['unclaimed']) ?></strong><span class="metric-name">Assistance unclaimed</span><small>Queue issued, not yet claimed</small></a>
    <a class="dash-metric" href="<?= BASE_URL ?>/officer/appointments.php"><div class="metric-top"><span class="metric-icon teal"><i class="bi bi-calendar-check" aria-hidden="true"></i></span></div><strong><?= number_format($appointments['claimed']) ?></strong><span class="metric-name">Appointments done</span><small>Visit marked as done</small></a>
    <a class="dash-metric" href="<?= BASE_URL ?>/officer/appointments.php"><div class="metric-top"><span class="metric-icon blue"><i class="bi bi-calendar2-x" aria-hidden="true"></i></span></div><strong><?= number_format($appointments['unclaimed']) ?></strong><span class="metric-name">Appointments not done</span><small>Visit still waiting</small></a>
</div>
<div class="dash-columns">
<div class="dash-primary">
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">ASSISTANCE</p><h2>Claimed and unclaimed</h2></div></div>
<div class="status-ring" style="background:<?= e(officer_ring_style($assistance['claimed'], $assistance['unclaimed'], '#258273', '#e7b14c')) ?>" role="img" aria-label="<?= $assistanceTotal ?> assistance queues; <?= $assistance['claimed'] ?> claimed and <?= $assistance['unclaimed'] ?> unclaimed"><div><strong><?= $assistanceTotal ?></strong><span>assistance</span></div></div>
<div class="status-legend"><div><span class="legend-dot" style="background:#258273"></span><span>Claimed</span><strong><?= $assistance['claimed'] ?></strong></div><div><span class="legend-dot" style="background:#e7b14c"></span><span>Unclaimed</span><strong><?= $assistance['unclaimed'] ?></strong></div></div>
</section>
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">STILL WAITING</p><h2>Unclaimed assistance</h2></div><a class="dash-text-link" href="<?= BASE_URL ?>/officer/assistance.php">Open list <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
<?php if (!$openAssistance): ?><p class="dash-empty compact">Every issued assistance queue has been claimed.</p><?php endif; ?>
<?php foreach ($openAssistance as $row): ?>
<a class="dash-request" href="<?= BASE_URL ?>/officer/assistance.php"><span class="request-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span><span class="request-copy"><strong><?= e(claim_queue_label($row)) ?> · <?= e($row['full_name']) ?></strong><small><?= e($row['request_type']) ?> · <?= e(date('M j, Y', strtotime((string) $row['queue_date']))) ?></small></span><span class="dash-status waiting">Unclaimed</span></a>
<?php endforeach; ?>
</section>
</div>
<aside class="dash-secondary">
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">APPOINTMENTS</p><h2>Done and not done</h2></div></div>
<div class="status-ring" style="background:<?= e(officer_ring_style($appointments['claimed'], $appointments['unclaimed'], '#5b9dab', '#cf7474')) ?>" role="img" aria-label="<?= $appointmentTotal ?> appointment queues; <?= $appointments['claimed'] ?> done and <?= $appointments['unclaimed'] ?> not done"><div><strong><?= $appointmentTotal ?></strong><span>appointments</span></div></div>
<div class="status-legend"><div><span class="legend-dot" style="background:#5b9dab"></span><span>Done</span><strong><?= $appointments['claimed'] ?></strong></div><div><span class="legend-dot" style="background:#cf7474"></span><span>Not done</span><strong><?= $appointments['unclaimed'] ?></strong></div></div>
<p class="chart-caption">These are visits the senior chose and staff confirmed. Saturday, Sunday, and regular holidays stay closed.</p>
</section>
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">STILL WAITING</p><h2>Appointments not done</h2></div><a class="dash-text-link" href="<?= BASE_URL ?>/officer/appointments.php">Open list <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
<?php if (!$openAppointments): ?><p class="dash-empty compact">Every confirmed appointment has been marked done.</p><?php endif; ?>
<?php foreach ($openAppointments as $row): ?>
<a class="dash-request" href="<?= BASE_URL ?>/officer/appointments.php"><span class="request-icon"><i class="bi bi-calendar-check" aria-hidden="true"></i></span><span class="request-copy"><strong><?= e(claim_queue_label($row)) ?> · <?= e($row['full_name']) ?></strong><small><?= e(appointment_window_label($row['schedule_date'] ?? null, $row['schedule_end'] ?? null)) ?></small></span><span class="dash-status waiting">Not done</span></a>
<?php endforeach; ?>
</section>
</aside>
</div>
</div>
    <?php
    require __DIR__ . '/../includes/footer.php';
}

function render_officer_report(): void
{
    require_role(['Barangay Officer']);
    [$period, $value, $start, $end, $periodLabel] = officer_report_period();
    [$assistanceClaimed, $assistanceOpen] = officer_split_claimed(officer_report_rows('Release', $start, $end));
    [$appointmentDone, $appointmentOpen] = officer_split_claimed(officer_report_rows('Appointment', $start, $end));
    $dayValue = $period === 'day' ? $value : date('Y-m-d');
    $monthValue = $period === 'month' ? $value : date('Y-m');
    if (($_GET['export'] ?? '') === 'pdf') {
        officer_report_download($period, $value, $periodLabel, $assistanceClaimed, $assistanceOpen, $appointmentDone, $appointmentOpen);
    }
    require __DIR__ . '/../includes/header.php';
    require __DIR__ . '/../includes/sidebar.php';
    ?>
<h1 class="page-title">Officer report — <?= e($periodLabel) ?></h1>
<p class="text-muted">Claimed and unclaimed assistance, and appointment visits that are done or still waiting. The report updates as soon as you choose a day or a month. You can print it or download the PDF.</p>
<form method="get" class="no-print panel mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label class="form-label" for="report-period">Period</label>
            <select class="form-select" id="report-period" name="period">
                <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Day</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Month</option>
            </select>
        </div>
        <div class="col-12 col-md-3" id="report-day-wrap">
            <label class="form-label" for="report-day">Day</label>
            <input class="form-control" id="report-day" type="date" name="day" value="<?= e($dayValue) ?>">
        </div>
        <div class="col-12 col-md-3" id="report-month-wrap">
            <label class="form-label" for="report-month">Month</label>
            <input class="form-control" id="report-month" type="month" name="month" value="<?= e($monthValue) ?>">
        </div>
        <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" name="export" value="pdf">Download PDF</button>
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">Print</button>
        </div>
    </div>
</form>
<?php officer_report_section('Assistance claimed', $assistanceClaimed, false, true, $period); ?>
<?php officer_report_section('Assistance unclaimed', $assistanceOpen, false, false, $period); ?>
<?php officer_report_section('Appointments done', $appointmentDone, true, true, $period); ?>
<?php officer_report_section('Appointments not done', $appointmentOpen, true, false, $period); ?>
<script>
(() => {
    const period = document.getElementById('report-period');
    const dayWrap = document.getElementById('report-day-wrap');
    const monthWrap = document.getElementById('report-month-wrap');
    const day = document.getElementById('report-day');
    const month = document.getElementById('report-month');
    const sync = () => {
        const monthly = period.value === 'month';
        dayWrap.hidden = monthly;
        monthWrap.hidden = !monthly;
        day.disabled = monthly;
        month.disabled = !monthly;
    };
    const apply = () => {
        sync();
        period.form.requestSubmit();
    };
    period.addEventListener('change', apply);
    day.addEventListener('change', apply);
    month.addEventListener('change', apply);
    sync();
})();
</script>
    <?php
    require __DIR__ . '/../includes/footer.php';
}

function officer_report_headings(bool $appointment, bool $done): array
{
    $headings = ['Queue', 'Senior', 'Control no.', $appointment ? 'Visit' : 'Assistance', $appointment ? 'When' : 'Release date'];
    if (!$appointment) {
        $headings[] = 'Amount';
    }
    $headings[] = $done ? ($appointment ? 'Done at' : 'Claimed at') : 'Status';
    return $headings;
}

function officer_report_cells(array $row, bool $appointment, bool $done): array
{
    if ($appointment) {
        $when = appointment_window_label($row['schedule_date'] ?? null, $row['schedule_end'] ?? null);
    } else {
        $when = $row['released_at'] ? date('M j, Y', strtotime((string) $row['released_at'])) : date('M j, Y', strtotime((string) $row['queue_date']));
        $hours = release_claim_hours($row['schedule_date'] ?? null, $row['schedule_end'] ?? null, $row['released_at'] ?? null);
        if ($hours !== '') {
            $when .= ' · ' . $hours;
        }
    }
    $cells = [
        claim_queue_label($row),
        (string) $row['full_name'],
        (string) $row['id_control_number'],
        $appointment ? (string) $row['description'] : (string) $row['request_type'],
        $when,
    ];
    if (!$appointment) {
        $cells[] = $row['amount'] !== null && $row['amount'] !== '' ? 'PHP ' . number_format((float) $row['amount'], 2) : '—';
    }
    $cells[] = $done && $row['claimed_at']
        ? date('M j, Y g:i A', strtotime((string) $row['claimed_at']))
        : ($appointment ? 'Not done' : 'Unclaimed');
    return $cells;
}

function officer_report_download(string $period, string $value, string $periodLabel, array $assistanceClaimed, array $assistanceOpen, array $appointmentDone, array $appointmentOpen): void
{
    $user = current_user();
    $bytes = officer_report_document($periodLabel, $period, $assistanceClaimed, $assistanceOpen, $appointmentDone, $appointmentOpen);
    $filename = 'officer-report-' . ($period === 'month' ? 'month-' : 'day-') . preg_replace('/[^0-9-]/', '', $value) . '.pdf';
    if ($user) {
        audit_log((int) $user['user_id'], 'Downloaded officer report PDF', 'reports');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $bytes;
    exit;
}

function officer_report_document(string $periodLabel, string $period, array $assistanceClaimed, array $assistanceOpen, array $appointmentDone, array $appointmentOpen): string
{
    $pdf = new SimplePdf();
    $pdf->text(32, 16, 'Barangay Tungkil', true);
    $pdf->advance(20);
    $pdf->text(32, 12, 'Officer report — ' . $periodLabel, true);
    $pdf->advance(16);
    $pdf->text(32, 9, 'Assistance claimed and unclaimed, and appointment visits that are done or still waiting.');
    $pdf->advance(18);
    officer_report_pdf_section($pdf, 'Assistance claimed', $assistanceClaimed, false, true, $period);
    officer_report_pdf_section($pdf, 'Assistance unclaimed', $assistanceOpen, false, false, $period);
    officer_report_pdf_section($pdf, 'Appointments done', $appointmentDone, true, true, $period);
    officer_report_pdf_section($pdf, 'Appointments not done', $appointmentOpen, true, false, $period);
    return $pdf->output();
}

function officer_report_pdf_section(SimplePdf $pdf, string $title, array $rows, bool $appointment, bool $done, string $period): void
{
    $headings = officer_report_headings($appointment, $done);
    $widths = $appointment ? [46, 120, 70, 180, 190, 90] : [46, 110, 68, 140, 130, 78, 92];
    $size = 8.0;
    $line = 11.0;
    $pdf->ensure(36);
    $pdf->text(32, 11, $title . ' (' . count($rows) . ')', true);
    $pdf->advance(16);
    if (!$rows) {
        $pdf->text(32, 9, 'No records for this ' . ($period === 'month' ? 'month' : 'day') . '.');
        $pdf->advance(18);
        return;
    }
    $drawHead = static function () use ($pdf, $headings, $widths, $size, $line): void {
        $x = 32.0;
        foreach ($headings as $index => $heading) {
            $pdf->text($x, $size, $heading, true);
            $x += $widths[$index];
        }
        $pdf->advance($line);
        $pdf->rule(32, 810);
        $pdf->advance(4);
    };
    $drawHead();
    foreach ($rows as $row) {
        $wrapped = [];
        $height = $line;
        foreach (officer_report_cells($row, $appointment, $done) as $index => $cell) {
            $lines = SimplePdf::wrap($cell, $widths[$index] - 6, $size);
            $wrapped[] = $lines;
            $height = max($height, count($lines) * $line);
        }
        if ($pdf->cursor() - $height < 50) {
            $pdf->addPage();
            $pdf->text(32, 11, $title . ' (continued)', true);
            $pdf->advance(16);
            $drawHead();
        }
        $top = $pdf->cursor();
        foreach ($wrapped as $index => $lines) {
            $x = 32.0;
            for ($i = 0; $i < $index; $i++) {
                $x += $widths[$i];
            }
            $lineY = $top;
            foreach ($lines as $lineText) {
                $pdf->textAt($x, $lineY, $size, $lineText);
                $lineY -= $line;
            }
        }
        $pdf->advance($height);
    }
    $pdf->advance(8);
}

function officer_report_section(string $title, array $rows, bool $appointment, bool $done, string $period): void
{
    ?>
<section class="panel mb-3">
    <h2 class="h5"><?= e($title) ?> <span class="text-muted">(<?= count($rows) ?>)</span></h2>
    <?php if (!$rows): ?><p class="mb-0 text-muted">No records for this <?= $period === 'month' ? 'month' : 'day' ?>.</p><?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><?php foreach (officer_report_headings($appointment, $done) as $heading): ?><th><?= e($heading) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach (officer_report_cells($row, $appointment, $done) as $index => $cell): ?>
                        <td<?= $index === 0 ? ' class="fw-bold"' : '' ?>><?= e($cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
    <?php
}
