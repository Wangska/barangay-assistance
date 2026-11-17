<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/senior_id.php';

function render_senior_id_page(array $roles): void
{
    $viewer = require_role($roles);
    $isSenior = $viewer['role_name'] === 'Senior Citizen';
    if ($isSenior) {
        $owner = db()->prepare('SELECT senior_id FROM senior_citizens WHERE user_id = ?');
        $owner->execute([(int) $viewer['user_id']]);
        $seniorId = (int) $owner->fetchColumn();
    } else {
        $seniorId = (int) filter_input(INPUT_GET, 'senior_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }
    $record = $seniorId > 0 ? senior_id_record($seniorId) : null;
    $prefix = $isSenior ? '/senior' : ($viewer['role_name'] === 'Administrator' ? '/admin' : '/staff');
    $backHref = BASE_URL . ($isSenior ? '/senior/dashboard.php' : $prefix . '/seniors.php');
    require __DIR__ . '/../includes/header.php';
    require __DIR__ . '/../includes/sidebar.php';
    echo '<link rel="stylesheet" href="' . e(BASE_URL) . '/assets/css/senior-id.css?v=20261117a">';
    if (!$record || trim((string) $record['id_control_number']) === '' || $record['status'] !== 'Active') {
        echo '<div class="panel"><h1 class="h4">Assistance card</h1><p class="mb-3">This card is issued when the senior registration is approved.</p><a class="btn btn-outline-primary" href="' . e($backHref) . '">Back</a></div>';
        require __DIR__ . '/../includes/footer.php';
        return;
    }

    $name = trim($record['first_name'] . ' ' . trim((string) $record['middle_name']) . ' ' . $record['last_name']);
    $address = trim(implode(', ', array_filter([
        trim((string) $record['address']),
        trim((string) $record['barangay']) !== '' ? 'Barangay ' . trim((string) $record['barangay']) : '',
        trim((string) $record['city_municipality']),
        trim((string) $record['province']),
    ])));
    $photo = profile_photo_storage_path($record['profile_photo'] ?? null);
    $control = (string) $record['id_control_number'];
    ?>
    <div class="id-page">
        <div class="id-toolbar">
            <div>
                <h1 class="page-title mb-1">Assistance card</h1>
                <p class="text-muted mb-0"><?= e($name) ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?= e($backHref) ?>">Back</a>
                <button type="button" class="btn btn-primary" onclick="window.print()">Print card</button>
            </div>
        </div>
        <div class="id-sheet">
            <div class="id-slot">
            <article class="assist-card" aria-label="Front of the assistance card">
                <header class="assist-card-head">
                    <img src="<?= e(BASE_URL) ?>/assets/images/seniorlogo.png" alt="Senior Assist">
                    <div>
                        <p class="assist-brand">Senior Assist</p>
                        <p>Barangay Tungkil</p>
                        <p>Assistance card</p>
                    </div>
                    <p class="assist-control">No. <strong><?= e($control) ?></strong></p>
                </header>
                <div class="assist-card-body">
                    <?php if ($photo): ?>
                        <img class="assist-photo" src="<?= e(BASE_URL) ?>/api/senior_photo.php?senior_id=<?= (int) $record['senior_id'] ?>" alt="Photo of <?= e($name) ?>">
                    <?php else: ?>
                        <div class="assist-photo assist-photo-empty">Photo</div>
                    <?php endif; ?>
                    <div class="assist-details">
                        <p><span>Name</span><b><?= e($name) ?></b></p>
                        <p class="assist-address"><span>Address</span><b><?= e($address) ?></b></p>
                        <div class="assist-meta">
                            <p><b><?= e(senior_id_card_date($record['birthdate'])) ?> / <?= (int) senior_id_age((string) $record['birthdate']) ?></b><span>Birth date / age</span></p>
                            <p><b><?= e(senior_id_sex((string) $record['gender'])) ?></b><span>Sex</span></p>
                            <p><b><?= e(senior_id_card_date($record['id_issued_at'])) ?></b><span>Date issued</span></p>
                        </div>
                    </div>
                </div>
                <p class="assist-note">System record for Barangay Tungkil. This does not replace the OSCA senior citizen ID.</p>
            </article>
            </div>
            <div class="id-slot">
            <article class="assist-card" aria-label="Back of the assistance card">
                <header class="assist-card-head">
                    <img src="<?= e(BASE_URL) ?>/assets/images/seniorlogo.png" alt="Senior Assist">
                    <div>
                        <p class="assist-brand">Senior Assist</p>
                        <p>Barangay Tungkil</p>
                        <p>Emergency contact</p>
                    </div>
                    <p class="assist-control">No. <strong><?= e($control) ?></strong></p>
                </header>
                <div class="assist-emergency">
                    <span>In case of emergency</span>
                    <strong><?= e(trim((string) $record['emergency_contact_name']) !== '' ? $record['emergency_contact_name'] : 'Not recorded') ?></strong>
                    <b><?= e(trim((string) $record['emergency_contact_phone']) !== '' ? $record['emergency_contact_phone'] : 'No phone recorded') ?></b>
                </div>
                <p class="assist-use">This card identifies the senior in the Barangay Tungkil assistance system when they request help or claim assistance.</p>
            </article>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
}
