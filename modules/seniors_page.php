<?php
require_once __DIR__ . '/../includes/functions.php';

function render_seniors_page(array $roles): void
{
    $user = require_role($roles);
    $isAdmin = $user['role_name'] === 'Administrator';
    $rows = db()->query("
        SELECT s.*, u.full_name, u.email, u.phone_number, u.status, u.created_at AS joined_at, req.requirement_id
        FROM senior_citizens s
        JOIN users u ON u.user_id = s.user_id
        LEFT JOIN (
            SELECT user_id, MIN(requirement_id) AS requirement_id
            FROM application_requirements
            WHERE label = 'Valid identification' AND file_path <> ''
            GROUP BY user_id
        ) req ON req.user_id = s.user_id
        WHERE u.status <> 'Inactive'
        ORDER BY u.full_name
    ")->fetchAll();
    $blank = static function (?string $value): string {
        $value = trim((string) $value);
        return $value === '' ? '—' : $value;
    };
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    ?>
    <h1 class="page-title">Senior Citizens</h1>
    <p class="text-muted">Open a senior to see the full record. Name, email, and phone are changed in Users.</p>
    <div class="panel table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Name</th><th>Phone</th><th>Birthdate</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-muted">No senior records yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $idUrl = '';
                if (!empty($r['requirement_id'])) {
                    $idUrl = BASE_URL . '/api/requirement_file.php?id=' . (int) $r['requirement_id'];
                } elseif ($isAdmin && valid_id_storage_path((string) $r['valid_id_path']) !== null) {
                    $idUrl = BASE_URL . '/admin/download_id.php?senior_id=' . (int) $r['senior_id'];
                }
                $government = array_filter([
                    'GSIS' => trim((string) $r['gsis_no']),
                    'SSS' => trim((string) $r['sss_no']),
                    'TIN' => trim((string) $r['tin_no']),
                    'PhilHealth' => trim((string) $r['philhealth_no']),
                ], static fn (string $value): bool => $value !== '');
                $sections = [
                    ['Personal', [
                        ['Full name', $r['full_name']],
                        ['Date of birth', $r['birthdate'] ? date('M j, Y', strtotime($r['birthdate'])) : '—'],
                        ['Place of birth', $blank($r['place_of_birth'])],
                        ['Sex', $blank($r['gender'])],
                        ['Civil status', $blank($r['civil_status'])],
                        ['Profession / skills', $blank($r['occupation'] ?? '')],
                        ['Dependent children below 18', ($r['dependent_children'] ?? '') === '' || $r['dependent_children'] === null ? '—' : (string) $r['dependent_children']],
                        ['Type of living', $blank($r['living_type'] ?? '')],
                        ['Receiving financial support', $blank($r['is_pensioner'] ?? '')],
                        ['Name of pension received', $blank($r['pension_source'] ?? '')],
                        ['Sign-in email', $blank($r['email'])],
                        ['Account status', $blank($r['status'])],
                        ['Joined', $r['joined_at'] ? date('M j, Y', strtotime($r['joined_at'])) : '—'],
                    ]],
                    ['Address and contact', [
                        ['Purok / Sitio', $blank(trim($r['address'] . ((trim((string) ($r['sitio'] ?? '')) !== '') ? ', ' . $r['sitio'] : '')))],
                        ['Barangay', $blank($r['barangay'])],
                        ['Municipality', $blank($r['city_municipality'])],
                        ['Province', $blank($r['province'])],
                        ['Contact number', $blank($r['phone_number'])],
                        ['FB account', $blank($r['facebook_account'] ?? '')],
                    ]],
                    ['Beneficiary', [
                        ['Name', $blank($r['beneficiary_name'] ?? '')],
                        ['Address', $blank($r['beneficiary_address'] ?? '')],
                    ]],
                    ['In case of emergency', [
                        ['Name', $blank($r['emergency_contact_name'])],
                        ['Address', $blank($r['emergency_contact_address'] ?? '')],
                        ['Contact number', $blank($r['emergency_contact_phone'])],
                    ]],
                ];
                if ($government) {
                    $sections[] = ['Government IDs', array_map(static fn (string $label, string $value): array => [$label, $value], array_keys($government), $government)];
                }
                $payload = json_encode([
                    'name' => $r['full_name'],
                    'idUrl' => $idUrl,
                    'sections' => array_map(static fn (array $section): array => ['title' => $section[0], 'rows' => $section[1]], $sections),
                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
                ?>
                <tr>
                    <td><?= e($r['full_name']) ?><div class="small text-muted"><?= e($r['email']) ?></div></td>
                    <td><?= e($r['phone_number']) ?></td>
                    <td><?= e($r['birthdate']) ?></td>
                    <td><span class="badge text-bg-secondary"><?= e($r['status']) ?></span></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#senior-details" data-senior="<?= e($payload) ?>">View</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="modal fade" id="senior-details" tabindex="-1" aria-labelledby="senior-details-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="senior-details-title">Senior details</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="senior-details-body"></div>
                <div class="modal-footer">
                    <a class="btn btn-outline-primary me-auto" id="senior-id-link" hidden>View valid ID</a>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.getElementById('senior-details').addEventListener('show.bs.modal', (event) => {
        const data = JSON.parse(event.relatedTarget.dataset.senior);
        document.getElementById('senior-details-title').textContent = data.name;
        const body = document.getElementById('senior-details-body');
        body.replaceChildren();
        data.sections.forEach((section, index) => {
            const heading = document.createElement('h3');
            heading.className = index === 0 ? 'h6' : 'h6 mt-4';
            heading.textContent = section.title;
            const list = document.createElement('dl');
            list.className = 'row mb-0';
            section.rows.forEach(([label, value]) => {
                const term = document.createElement('dt');
                term.className = 'col-sm-4';
                term.textContent = label;
                const detail = document.createElement('dd');
                detail.className = 'col-sm-8';
                detail.style.whiteSpace = 'pre-wrap';
                detail.textContent = value;
                list.append(term, detail);
            });
            body.append(heading, list);
        });
        const idLink = document.getElementById('senior-id-link');
        idLink.hidden = !data.idUrl;
        if (data.idUrl) idLink.href = data.idUrl;
        else idLink.removeAttribute('href');
    });
    </script>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
}
