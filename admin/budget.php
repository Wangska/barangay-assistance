<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role(['Administrator']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $amount = trim((string) ($_POST['death_assistance_amount'] ?? ''));
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) !== 1) {
            throw new RuntimeException('Enter a valid death assistance amount.');
        }
        db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute(['death_assistance_amount', $amount]);
        audit_log((int) $user['user_id'], 'Updated death assistance amount', 'settings');
        flash('success', 'Death assistance amount saved.');
    } catch (Throwable $error) {
        flash('danger', $error instanceof RuntimeException ? $error->getMessage() : 'The setting could not be saved.');
    }
    redirect('/admin/budget.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
if (strlen($search) > 150) {
    $search = substr($search, 0, 150);
}
if (strlen($type) > 100) {
    $type = '';
}

$recordedTypes = db()->query('SELECT DISTINCT ar.request_type FROM assistance_distributions d JOIN assistance_requests ar ON ar.request_id = d.request_id')->fetchAll(PDO::FETCH_COLUMN);
$types = array_values(array_unique(array_merge(
    ['Death Assistance', 'Medical/Financial Assistance', 'Special / Indigent Assistance'],
    $recordedTypes
)));
sort($types, SORT_NATURAL | SORT_FLAG_CASE);

$sql = 'SELECT d.amount, d.released_at, d.received_by, ar.request_type, u.full_name AS grantee
    FROM assistance_distributions d
    JOIN assistance_requests ar ON ar.request_id = d.request_id
    JOIN senior_citizens s ON s.senior_id = ar.senior_id
    JOIN users u ON u.user_id = s.user_id
    WHERE 1=1';
$params = [];
if ($type !== '') {
    $sql .= ' AND ar.request_type = ?';
    $params[] = $type;
}
if ($search !== '') {
    $sql .= ' AND (u.full_name LIKE ? OR d.received_by LIKE ?)';
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
}
$sql .= ' ORDER BY d.released_at DESC, d.distribution_id DESC';
$releases = db()->prepare($sql);
$releases->execute($params);
$releases = $releases->fetchAll();
$releasedTotal = 0.0;
foreach ($releases as $row) {
    if ($row['amount'] !== null && $row['amount'] !== '') {
        $releasedTotal += (float) $row['amount'];
    }
}
$deathAmount = death_assistance_amount();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">Released Assistance</h1>
<p class="text-muted">These are the seniors whose assistance has been released. The amount is the amount entered when the assistance was released.</p>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="panel h-100"><div class="text-muted">Releases</div><strong><?= count($releases) ?></strong></div></div>
    <div class="col-md-4"><div class="panel h-100"><div class="text-muted">Amount released</div><strong>PHP <?= e(number_format($releasedTotal, 2)) ?></strong></div></div>
</div>
<form method="get" class="panel mb-3">
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label" for="release-type">Assistance</label>
            <select class="form-select" id="release-type" name="type">
                <option value="">All assistance</option>
                <?php foreach ($types as $option): ?>
                    <option value="<?= e($option) ?>"<?= $type === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label" for="release-name">Grantee name</label>
            <input class="form-control" id="release-name" name="q" maxlength="150" value="<?= e($search) ?>" placeholder="Search a senior or recipient">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary">Search</button>
            <?php if ($search !== '' || $type !== ''): ?><a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/admin/budget.php">Clear</a><?php endif; ?>
        </div>
    </div>
</form>
<div class="panel table-responsive mb-3">
    <table class="table align-middle">
        <thead><tr><th>Grantee</th><th>Assistance</th><th>Amount</th><th>Release date</th><th>Received by</th></tr></thead>
        <tbody>
        <?php if (!$releases): ?><tr><td colspan="5" class="text-muted">No released assistance matches this search.</td></tr><?php endif; ?>
        <?php foreach ($releases as $row): ?>
            <tr>
                <td><?= e($row['grantee']) ?></td>
                <td><?= e($row['request_type']) ?></td>
                <td><?= $row['amount'] === null || $row['amount'] === '' ? '—' : 'PHP ' . e(number_format((float) $row['amount'], 2)) ?></td>
                <td><?= e(format_release_date((string) $row['released_at'])) ?></td>
                <td><?= e($row['received_by']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="panel">
    <h2 class="h5">Death assistance amount</h2>
    <p class="text-muted">This amount is filled in when death assistance is released. The administrator can still change it on the release form.</p>
    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="col-md-4"><label class="form-label" for="death-amount">Amount</label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control" id="death-amount" type="number" min="0" step="0.01" name="death_assistance_amount" required value="<?= e($deathAmount) ?>"></div></div>
        <div class="col-12"><button class="btn btn-primary">Save amount</button></div>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
