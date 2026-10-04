<?php
require_once __DIR__.'/../includes/functions.php';
function render_reports_page(array $roles): void {
    $user=require_role($roles);
    $month=(string)($_GET['month'] ?? date('Y-m'));
    $date=DateTimeImmutable::createFromFormat('!Y-m',$month);
    if(!$date || $date->format('Y-m')!==$month) { http_response_code(422);exit('Select a valid month.'); }
    $start=$date->format('Y-m-d');$end=$date->modify('+1 month')->format('Y-m-d');
    $type=$_GET['type'] ?? 'requests';
    if(!in_array($type,['requests','registrations','distributions'],true))$type='requests';
    $headings=[
        'requests'=>['Senior','Assistance type','Current status','Remarks','Submitted'],
        'registrations'=>['Senior','Current status','Submitted'],
        'distributions'=>['Request','Senior','Item','Quantity','Unit','Cash amount (PHP)','Released','Released by','Received by']
    ];
    $sql=match($type) {
        'registrations'=>'SELECT u.full_name,u.status,u.created_at FROM users u JOIN senior_citizens s ON s.user_id=u.user_id WHERE u.created_at>=? AND u.created_at<? ORDER BY u.created_at',
        'distributions'=>'SELECT d.request_id,u.full_name,d.item,d.quantity,d.unit,d.amount,d.released_at,staff.full_name,d.received_by FROM assistance_distributions d JOIN assistance_requests ar ON ar.request_id=d.request_id JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id JOIN users staff ON staff.user_id=d.released_by WHERE d.released_at>=? AND d.released_at<? ORDER BY d.released_at',
        default=>'SELECT u.full_name,ar.request_type,ar.status,ar.remarks,ar.created_at FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id WHERE ar.created_at>=? AND ar.created_at<? ORDER BY ar.created_at'
    };
    $q=db()->prepare($sql);$q->execute([$start,$end]);$rows=$q->fetchAll(PDO::FETCH_NUM);
    if (($_GET['export'] ?? '')==='csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$type.'-'.$month.'.csv"');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$headings[$type]);
        foreach($rows as $row) fputcsv($out,array_map('report_csv_cell',$row));
        fclose($out);
        audit_log((int)$user['user_id'],'Exported '.$type.' report','reports');
        return;
    }
    $counts=[];
    foreach([
        'Registrations submitted'=>'SELECT COUNT(*) FROM users u JOIN senior_citizens s ON s.user_id=u.user_id WHERE u.created_at>=? AND u.created_at<?',
        'Requests still pending review'=>"SELECT COUNT(*) FROM assistance_requests WHERE created_at>=? AND created_at<? AND status IN ('Pending','Under Review')",
        'Assistance releases'=>'SELECT COUNT(*) FROM assistance_distributions WHERE released_at>=? AND released_at<?',
        'Unique beneficiaries'=>'SELECT COUNT(DISTINCT ar.senior_id) FROM assistance_distributions d JOIN assistance_requests ar ON ar.request_id=d.request_id WHERE d.released_at>=? AND d.released_at<?',
        'Cash released (PHP)'=>'SELECT COALESCE(SUM(amount),0) FROM assistance_distributions WHERE released_at>=? AND released_at<?'
    ] as $label=>$countSql) { $q=db()->prepare($countSql);$q->execute([$start,$end]);$counts[$label]=$q->fetchColumn(); }
    require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/sidebar.php';
?>
<h1 class="page-title">Monthly Service Report — <?= e($month) ?></h1>
<form method="get" class="no-print d-flex flex-wrap align-items-end gap-2 mb-3">
<label>Month<input class="form-control" type="month" name="month" value="<?= e($month) ?>" required></label>
<label>Report<select class="form-select" name="type"><?php foreach(array_keys($headings) as $option): ?><option value="<?= $option ?>" <?= $type===$option?'selected':'' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select></label>
<button class="btn btn-primary">Apply</button><button class="btn btn-outline-primary" name="export" value="csv">Download CSV</button><button type="button" class="btn btn-outline-primary" onclick="window.print()">Print / Save PDF</button>
</form>
<div class="row g-2 mb-3"><?php foreach($counts as $label=>$count): ?><div class="col"><div class="metric-card"><div class="label"><?= e($label) ?></div><div class="value"><?= e((string)$count) ?></div></div></div><?php endforeach; ?></div>
<p class="small text-muted">Dates: <?= e($start) ?> through <?= e($date->modify('last day of this month')->format('Y-m-d')) ?>. Statuses are current; pending totals count requests submitted this month. Beneficiaries and releases use actual release dates. Cash totals exclude non-cash assistance.</p>
<div class="panel table-responsive"><h2 class="h5"><?= ucfirst($type) ?></h2><table class="table"><thead><tr><?php foreach($headings[$type] as $heading): ?><th><?= e($heading) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="<?= count($headings[$type]) ?>">No records for this month.</td></tr><?php endif; ?>
<?php foreach($rows as $row): ?><tr><?php foreach($row as $cell): ?><td><?= e($cell===null?'—':(string)$cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__.'/../includes/footer.php'; }