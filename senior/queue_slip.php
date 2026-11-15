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
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="page-title mb-0">Queue <?= e($queueLabel) ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/senior/requests.php">Back</a>
        <button type="button" class="btn btn-primary" id="download-queue-png">Download PNG</button>
    </div>
</div>
<article class="panel" id="queue-slip" style="max-width: 28rem;">
    <div class="queue-slip-head">
        <img class="queue-slip-seal" src="<?= e(BASE_URL) ?>/assets/images/cebu-city-seal.png?v=20261115c" alt="Official Seal of the City of Cebu">
        <p class="text-muted queue-slip-place">Barangay Tungkil</p>
        <img class="queue-slip-seal" src="<?= e(BASE_URL) ?>/assets/images/minglanilla-seal.png?v=20261115q" alt="Official Seal of Minglanilla, Cebu">
    </div>
    <h2 class="h4 mb-1 text-center"><?= e($queueKind) ?></h2>
    <p class="display-4 fw-bold mb-2 text-center" id="queue-number"><?= e($queueLabel) ?></p>
    <p class="mb-1"><?= e($queueWhen) ?></p>
    <p class="mb-1"><?= e($queue['full_name']) ?></p>
    <p class="mb-1"><?= e($queue['request_type']) ?></p>
    <p class="mb-0">ID control no. <?= e((string) $queue['id_control_number']) ?></p>
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
    const loadSeal = (src) => new Promise((resolve) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => resolve(null);
        image.src = src;
    });
    Promise.all([
        loadSeal(<?= json_encode(BASE_URL . '/assets/images/cebu-city-seal.png') ?>),
        loadSeal(<?= json_encode(BASE_URL . '/assets/images/minglanilla-seal.png') ?>)
    ]).then(([cebu, minglanilla]) => {
        const canvas = document.createElement('canvas');
        canvas.width = 900;
        canvas.height = 640;
        const pen = canvas.getContext('2d');
        pen.fillStyle = '#fffdf8';
        pen.fillRect(0, 0, canvas.width, canvas.height);
        pen.strokeStyle = '#b89a62';
        pen.lineWidth = 8;
        pen.strokeRect(24, 24, canvas.width - 48, canvas.height - 48);
        const sealSize = 128;
        if (cebu) pen.drawImage(cebu, 52, 52, sealSize, sealSize);
        if (minglanilla) pen.drawImage(minglanilla, canvas.width - 52 - sealSize, 52, sealSize, sealSize);
        pen.fillStyle = '#1d2430';
        pen.textAlign = 'center';
        pen.font = '28px Georgia, serif';
        pen.fillText('Barangay Tungkil', 450, 124);
        pen.font = '32px Georgia, serif';
        pen.fillText(slip.kind, 450, 250);
        pen.font = 'bold 120px Georgia, serif';
        pen.fillText(slip.number, 450, 390);
        pen.font = '28px Georgia, serif';
        pen.fillText(slip.when, 450, 455);
        pen.fillText(slip.name, 450, 500);
        pen.font = '24px Georgia, serif';
        pen.fillText(slip.request, 450, 545);
        pen.fillText('ID control no. ' + slip.control, 450, 585);
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = 'queue-' + slip.number + '.png';
        link.click();
    });
});
</script>
<?php require __DIR__ . '/../includes/footer.php';