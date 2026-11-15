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
    echo '<link rel="stylesheet" href="' . e(BASE_URL) . '/assets/css/senior-id.css?v=20261115k">';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Great+Vibes&family=Libre+Baskerville:wght@400;700&display=swap">';
    if (!$record || trim((string) $record['id_control_number']) === '' || $record['status'] !== 'Active') {
        echo '<div class="panel"><h1 class="h4">Digital senior ID</h1><p class="mb-3">This ID is issued when the senior registration is approved.</p><a class="btn btn-outline-primary" href="' . e($backHref) . '">Back</a></div>';
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
    $oscaHead = trim(setting_value('osca_head_name'));
    $captain = trim(setting_value('punong_barangay_name'));
    $benefits = [
        'Free medical and dental, diagnostic, and laboratory services in government facilities.',
        'Twenty percent (20%) discount and exemption from VAT on medicine, prescribed vitamins and minerals, and medical supplies.',
        'Discount on professional fees of the attending physician in private hospitals and medical facilities.',
        'Discount on medical and dental services and laboratory fees in private hospitals.',
        'Discount on domestic air fare, sea fare, and public land transportation fare.',
        'Discount on hotels, restaurants, and recreational centers.',
        'Discount on admission fees of theaters, cinema houses, and concert halls.',
        'Discount on funeral and burial services.',
        'Five percent (5%) discount on prime commodities and basic necessities.',
        'Five percent (5%) discount on electricity and water within the prescribed monthly use.',
        'An express lane in private, banking, commercial, and government establishments.',
        'Abuse of these privileges is punishable by law.',
    ];
    ?>
    <div class="id-page">
        <div class="id-toolbar">
            <div>
                <h1 class="page-title mb-1">Digital senior ID</h1>
                <p class="text-muted mb-0"><?= e($name) ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?= e($backHref) ?>">Back</a>
                <button type="button" class="btn btn-primary" onclick="window.print()">Print ID</button>
            </div>
        </div>
        <div class="id-sheet">
            <div class="id-slot">
            <article class="osca-card osca-front" aria-label="Front of the senior ID">
                <div class="osca-top">
                    <p class="osca-control">Control No. <strong><?= e($record['id_control_number']) ?></strong></p>
                    <div class="osca-seal">
                        <img src="<?= e(BASE_URL) ?>/assets/images/cebu-city-seal.png?v=20261115c" alt="Official Seal of the City of Cebu">
                    </div>
                    <div class="osca-heading">
                        <p>Republic of the Philippines</p>
                        <p class="osca-office">Office of the Senior Citizens Affairs</p>
                        <p class="osca-place">Minglanilla, Cebu</p>
                    </div>
                    <div class="osca-photo-col">
                        <?php if ($photo): ?>
                            <img class="osca-photo" src="<?= e(BASE_URL) ?>/api/senior_photo.php?senior_id=<?= (int) $record['senior_id'] ?>" alt="Photo of <?= e($name) ?>">
                        <?php else: ?>
                            <div class="osca-photo-empty">Photo</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="osca-fields">
                    <div class="osca-line"><span>NAME:</span><b><?= e($name) ?></b></div>
                    <div class="osca-line osca-address"><span>ADDRESS:</span><b><?= e($address) ?></b></div>
                </div>
                <div class="osca-meta">
                    <div><b><?= e(senior_id_card_date($record['birthdate'])) ?>/<?= (int) senior_id_age((string) $record['birthdate']) ?></b><span>Date of Birth/Age</span></div>
                    <div><b><?= e(senior_id_sex((string) $record['gender'])) ?></b><span>Sex</span></div>
                    <div><b><?= e(senior_id_card_date($record['id_issued_at'])) ?></b><span>Date Issued</span></div>
                </div>
                <p class="osca-legal">THIS CARD IS NON-TRANSFERABLE AND<br>VALID ANYWHERE IN THE COUNTRY</p>
                <div class="osca-signature">
                    <div class="osca-sign-line" aria-label="Sign here"></div>
                    <p class="osca-printed"><?= e($name) ?></p>
                    <p class="osca-caption">Printed Name and Signature/Thumbmark</p>
                </div>
            </article>
            </div>
            <div class="id-slot">
            <article class="osca-card osca-back" aria-label="Back of the senior ID">
                <div class="osca-emergency">
                    <span>IN CASE OF EMERGENCY</span>
                    <strong><?= e(trim((string) $record['emergency_contact_name']) !== '' ? $record['emergency_contact_name'] : 'Not recorded') ?></strong>
                    <span>PLEASE CONTACT:</span>
                    <b><?= e(trim((string) $record['emergency_contact_phone']) !== '' ? $record['emergency_contact_phone'] : '') ?></b>
                </div>
                <h2>Benefits and Privileges under Republic Act No. 9994</h2>
                <ul class="osca-benefits">
                    <?php foreach ($benefits as $benefit): ?><li><?= e($benefit) ?></li><?php endforeach; ?>
                </ul>
                <div class="osca-signs">
                    <div><span><?= e($oscaHead) ?></span><small>OSCA HEAD</small></div>
                    <div><span><?= e($captain) ?></span><small>PUNONG BARANGAY</small></div>
                </div>
            </article>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
}
