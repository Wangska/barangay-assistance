<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['Administrator']);
$input = static fn(string $key): string => is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
$search = substr($input('q'), 0, 180);
$entity = substr($input('entity'), 0, 80);
$from = $input('from');
$to = $input('to');
$errors = [];
foreach (['from', 'to'] as $key) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $$key);
    if ($$key !== '' && (!$date || $date->format('Y-m-d') !== $$key)) {
        $errors[] = 'Enter a valid ' . ($key === 'from' ? 'start' : 'end') . ' date.';
        $$key = '';
    }
}
if ($from !== '' && $to !== '' && $from > $to) $errors[] = 'The start date must be on or before the end date.';
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR a.action LIKE ? OR a.ip_address LIKE ? OR CAST(a.entity_id AS CHAR) LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
if ($entity !== '') { $where[] = 'a.entity = ?'; $params[] = $entity; }
if ($from !== '') { $where[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'a.created_at < ?'; $params[] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'); }
if ($errors) $where[] = '1 = 0';
$filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$source = ' FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id';
$count = db()->prepare('SELECT COUNT(*)' . $source . $filter);
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / 25));
$page = min($pages, max(1, (int) $input('page')));
$offset = ($page - 1) * 25;
$query = db()->prepare('SELECT a.*, u.full_name' . $source . $filter . ' ORDER BY a.created_at DESC, a.audit_id DESC LIMIT 25 OFFSET ' . $offset);
$query->execute($params);
$rows = $query->fetchAll();
$entities = db()->query('SELECT DISTINCT entity FROM audit_logs ORDER BY entity')->fetchAll(PDO::FETCH_COLUMN);
$pageUrl = static fn(int $number): string => '?' . http_build_query(['q' => $search, 'entity' => $entity, 'from' => $from, 'to' => $to, 'page' => $number]);
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/audit-logs.css?v=20261117f">
<div class="audit-heading"><div><p class="audit-eyebrow">Administration / Activity history</p><h1 class="page-title mb-2">Audit Logs</h1><p class="text-muted mb-0">Review recorded account activity and changes across the system.</p></div><span class="audit-count"><?= number_format($total) ?> matching records</span></div>
<form method="get" class="panel audit-filters" aria-label="Filter audit logs">
    <div><label class="form-label" for="audit-search">Search activity</label><input class="form-control" id="audit-search" name="q" maxlength="180" value="<?= e($search) ?>" placeholder="Name, action, IP, or record number"></div>
    <div><label class="form-label" for="audit-entity">Record type</label><select class="form-select" id="audit-entity" name="entity"><option value="">All record types</option><?php foreach ($entities as $option): ?><option value="<?= e($option) ?>"<?= $entity === $option ? ' selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $option))) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="audit-from">From</label><input class="form-control" type="date" id="audit-from" name="from" value="<?= e($from) ?>"></div>
    <div><label class="form-label" for="audit-to">To</label><input class="form-control" type="date" id="audit-to" name="to" value="<?= e($to) ?>"></div>
    <div class="audit-filter-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="audit_logs.php">Reset</a></div>
    <?php foreach ($errors as $error): ?><p class="text-danger mb-0 audit-filter-error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
</form>
<section class="panel audit-results" aria-label="Audit records">
    <div class="audit-results-heading"><h2 class="h5 mb-0">Activity history</h2><span class="text-muted">Newest first</span></div>
    <div class="table-responsive"><table class="table audit-table"><caption class="visually-hidden">Recorded activity matching the selected filters.</caption><thead><tr><th scope="col">User</th><th scope="col">Activity</th><th scope="col">Record</th><th scope="col">Date &amp; time</th><th scope="col">Details</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr>
        <td><strong><?= e($row['full_name'] ?? 'System / deleted user') ?></strong><small><?= $row['user_id'] !== null ? 'User #' . (int) $row['user_id'] : 'No linked account' ?></small></td>
        <td class="audit-action"><?= e($row['action']) ?></td>
        <td><span class="audit-entity"><?= e(ucwords(str_replace('_', ' ', $row['entity']))) ?></span><?php if ($row['entity_id'] !== null): ?><small>Record #<?= (int) $row['entity_id'] ?></small><?php endif; ?></td>
        <td class="audit-date"><?= e(date('M j, Y', strtotime($row['created_at']))) ?><small><?= e(date('g:i:s A', strtotime($row['created_at']))) ?></small></td>
        <td><details class="audit-details"><summary>View details</summary><dl><dt>Log number</dt><dd>#<?= (int) $row['audit_id'] ?></dd><dt>IP address</dt><dd><?= e($row['ip_address'] ?: 'Not recorded') ?></dd><dt>Browser / device</dt><dd><?= e($row['user_agent'] ?: 'Not recorded') ?></dd></dl></details></td>
    </tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5"><div class="audit-empty"><i class="bi bi-search" aria-hidden="true"></i><h3 class="h5">No activity found</h3><p class="text-muted mb-0"><?= $errors ? 'Correct the date filters above to see results.' : 'Try another search or reset the filters. Recorded activity will appear here.' ?></p></div></td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="audit-pagination" aria-label="Audit log pages"><span>Showing <?= $total ? number_format($offset + 1) : '0' ?>&ndash;<?= number_format(min($offset + 25, $total)) ?> of <?= number_format($total) ?></span><div><?php if ($page > 1): ?><a class="btn btn-outline-primary btn-sm" href="<?= e($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a class="btn btn-outline-primary btn-sm" href="<?= e($pageUrl($page + 1)) ?>">Next</a><?php endif; ?></div></nav>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
