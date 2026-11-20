<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/claim_queue.php';

$user = require_role(['Senior Citizen']);
$queueId = (int) filter_input(INPUT_GET, 'queue_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$query = db()->prepare('SELECT q.*, ar.request_type, ar.schedule_date, ar.schedule_end, u.full_name, s.id_control_number
    FROM claim_queues q
    JOIN assistance_requests ar ON ar.request_id = q.request_id
    JOIN senior_citizens s ON s.senior_id = ar.senior_id
    JOIN users u ON u.user_id = s.user_id
    WHERE q.queue_id = ? AND s.user_id = ?');
$query->execute([$queueId, (int) $user['user_id']]);
$queue = $query->fetch();
if (!$queue) {
    http_response_code(404);
    exit('Queue number not found.');
}
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
$queueLabel = claim_queue_label($queue);
$queueKind = $queue['queue_type'] === 'Appointment' ? 'Appointment queue' : 'Release queue';
$queueWhen = date('F j, Y', strtotime((string) $queue['queue_date']));
if ($queue['queue_type'] === 'Appointment') {
    $visitWindow = appointment_window_label($queue['schedule_date'] ?? null, $queue['schedule_end'] ?? null, false);
} else {
    $visitWindow = release_claim_hours($queue['schedule_date'] ?? null, $queue['schedule_end'] ?? null, (string) $queue['queue_date']);
}
if ($visitWindow !== '') {
    $queueWhen .= ', ' . $visitWindow;
}
$logoUrl = BASE_URL . '/assets/images/seniorlogo.png?v=20261120q';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="page-title mb-0">Queue <?= e($queueLabel) ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/senior/requests.php">Back</a>
        <button type="button" class="btn btn-primary" id="download-queue-png">Download PNG</button>
    </div>
</div>
<article class="panel queue-slip" id="queue-slip" style="max-width: 24rem;">
    <header class="queue-slip-brand">
        <img class="queue-slip-logo" src="<?= e($logoUrl) ?>" alt="Senior Assist">
        <p class="queue-slip-brand-copy">
            <strong>Senior Assist</strong>
            <span>Barangay Tungkil</span>
        </p>
    </header>
    <div class="queue-slip-body">
        <p class="queue-slip-kind"><?= e($queueKind) ?></p>
        <p class="queue-slip-number" id="queue-number"><?= e($queueLabel) ?></p>
        <dl class="queue-slip-meta">
            <div>
                <dt>Schedule</dt>
                <dd><?= e($queueWhen) ?></dd>
            </div>
            <div>
                <dt>Senior citizen</dt>
                <dd><?= e($queue['full_name']) ?></dd>
            </div>
            <div>
                <dt>Request</dt>
                <dd><?= e($queue['request_type']) ?></dd>
            </div>
            <div>
                <dt>ID control no.</dt>
                <dd><?= e((string) $queue['id_control_number']) ?></dd>
            </div>
        </dl>
        <p class="queue-slip-foot">Present this number at the barangay desk.</p>
    </div>
</article>
<script>
document.getElementById('download-queue-png').addEventListener('click', () => {
    const slip = {
        kind: <?= json_encode($queueKind, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        number: <?= json_encode($queueLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        when: <?= json_encode($queueWhen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        name: <?= json_encode($queue['full_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        request: <?= json_encode($queue['request_type'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        control: <?= json_encode((string) $queue['id_control_number'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    const loadLogo = (src) => new Promise((resolve) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => resolve(null);
        image.src = src;
    });
    const roundRect = (pen, x, y, width, height, radius) => {
        const r = Math.min(radius, width / 2, height / 2);
        pen.beginPath();
        pen.moveTo(x + r, y);
        pen.arcTo(x + width, y, x + width, y + height, r);
        pen.arcTo(x + width, y + height, x, y + height, r);
        pen.arcTo(x, y + height, x, y, r);
        pen.arcTo(x, y, x + width, y, r);
        pen.closePath();
    };
    const wrapText = (pen, text, x, y, maxWidth, lineHeight) => {
        const words = String(text || '').split(/\s+/).filter(Boolean);
        let line = '';
        let cursor = y;
        words.forEach((word, index) => {
            const next = line ? line + ' ' + word : word;
            if (pen.measureText(next).width > maxWidth && line) {
                pen.fillText(line, x, cursor);
                line = word;
                cursor += lineHeight;
            } else {
                line = next;
            }
            if (index === words.length - 1 && line) {
                pen.fillText(line, x, cursor);
                cursor += lineHeight;
            }
        });
        return cursor;
    };
    loadLogo(<?= json_encode(BASE_URL . '/assets/images/seniorlogo.png?v=20261120q') ?>).then((logo) => {
        const canvas = document.createElement('canvas');
        canvas.width = 900;
        canvas.height = 1180;
        const pen = canvas.getContext('2d');
        pen.fillStyle = '#f4f8f7';
        pen.fillRect(0, 0, canvas.width, canvas.height);

        roundRect(pen, 48, 48, canvas.width - 96, canvas.height - 96, 36);
        pen.fillStyle = '#ffffff';
        pen.fill();
        pen.strokeStyle = '#dce7e6';
        pen.lineWidth = 3;
        pen.stroke();

        const header = pen.createLinearGradient(0, 48, 0, 320);
        header.addColorStop(0, '#103c54');
        header.addColorStop(0.65, '#0b4a5f');
        header.addColorStop(1, '#0d7b7b');
        pen.save();
        roundRect(pen, 48, 48, canvas.width - 96, 272, 36);
        pen.clip();
        pen.fillStyle = header;
        pen.fillRect(48, 48, canvas.width - 96, 272);
        pen.restore();

        if (logo) {
            const size = 118;
            const lx = (canvas.width - size) / 2;
            const ly = 84;
            roundRect(pen, lx - 8, ly - 8, size + 16, size + 16, 28);
            pen.fillStyle = 'rgba(255,255,255,0.16)';
            pen.fill();
            pen.drawImage(logo, lx, ly, size, size);
        }

        pen.fillStyle = '#ffffff';
        pen.textAlign = 'center';
        pen.font = '700 42px "DM Sans", Arial, sans-serif';
        pen.fillText('Senior Assist', 450, 250);
        pen.font = '600 22px "DM Sans", Arial, sans-serif';
        pen.fillStyle = 'rgba(255,255,255,0.72)';
        pen.fillText('BARANGAY TUNGKIL', 450, 286);

        pen.fillStyle = '#0d7b7b';
        pen.font = '700 24px "DM Sans", Arial, sans-serif';
        pen.fillText(String(slip.kind || '').toUpperCase(), 450, 372);

        roundRect(pen, 150, 400, 600, 170, 28);
        pen.fillStyle = '#eefaf6';
        pen.fill();
        pen.setLineDash([10, 10]);
        pen.strokeStyle = 'rgba(13,123,123,0.35)';
        pen.lineWidth = 3;
        pen.stroke();
        pen.setLineDash([]);

        pen.fillStyle = '#103c54';
        pen.font = '700 132px "Playfair Display", Georgia, serif';
        pen.fillText(slip.number, 450, 525);

        const rows = [
            ['SCHEDULE', slip.when],
            ['SENIOR CITIZEN', slip.name],
            ['REQUEST', slip.request],
            ['ID CONTROL NO.', slip.control]
        ];
        let top = 620;
        rows.forEach(([label, value]) => {
            roundRect(pen, 120, top, 660, 96, 20);
            pen.fillStyle = '#f7fbfa';
            pen.fill();
            pen.strokeStyle = '#e5efed';
            pen.lineWidth = 2;
            pen.stroke();
            pen.textAlign = 'left';
            pen.fillStyle = '#6a7e88';
            pen.font = '700 18px "DM Sans", Arial, sans-serif';
            pen.fillText(label, 150, top + 34);
            pen.fillStyle = '#103c54';
            pen.font = '600 28px "DM Sans", Arial, sans-serif';
            wrapText(pen, value, 150, top + 68, 600, 30);
            top += 112;
        });

        pen.textAlign = 'center';
        pen.fillStyle = '#6a7e88';
        pen.font = '500 22px "DM Sans", Arial, sans-serif';
        pen.fillText('Present this number at the barangay desk.', 450, 1100);

        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = 'queue-' + slip.number + '.png';
        link.click();
    });
});
</script>
<?php require __DIR__ . '/../includes/footer.php';
