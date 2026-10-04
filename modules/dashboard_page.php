<?php
require_once __DIR__.'/../includes/functions.php';

function dashboard_status_class(string $status): string {
    return match($status) {
        'Active','Approved','Completed'=>'positive',
        'Pending','Under Review'=>'waiting',
        'Rejected','Inactive'=>'negative',
        default=>'scheduled'
    };
}

function render_role_dashboard(string $role): void {
    $user=require_role([$role]);
    $isSenior=$role==='Senior Citizen';
    $isAdmin=$role==='Administrator';
    $prefix=$isSenior?'/senior':($isAdmin?'/admin':'/staff');
    $where=$isSenior?' AND s.user_id=?':'';
    $args=$isSenior?[$user['user_id']]:[];
    $q=db()->prepare('SELECT ar.status,COUNT(*) AS total FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE 1=1'.$where.' GROUP BY ar.status');
    $q->execute($args);
    $statuses=array_fill_keys(['Pending','Under Review','Approved','Scheduled','Completed','Rejected'],0);
    foreach($q as $row) $statuses[$row['status']]=(int)$row['total'];
    $total=array_sum($statuses);
    $waiting=$statuses['Pending']+$statuses['Under Review'];
    $q=db()->prepare('SELECT ar.*,u.full_name FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id WHERE 1=1'.$where.' ORDER BY ar.created_at DESC,ar.request_id DESC LIMIT 4');
    $q->execute($args);$requests=$q->fetchAll();
    $q=db()->prepare("SELECT ar.*,u.full_name FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id WHERE ar.status='Scheduled' AND ar.schedule_date>=CURDATE()".$where.' ORDER BY ar.schedule_date LIMIT 3');
    $q->execute($args);$schedule=$q->fetchAll();
    $q=db()->prepare('SELECT DATE(ar.created_at) AS day,COUNT(*) AS total FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE ar.created_at>=CURDATE()-INTERVAL 6 DAY'.$where.' GROUP BY DATE(ar.created_at)');
    $q->execute($args);$dayCounts=[];
    foreach($q as $row)$dayCounts[$row['day']]=(int)$row['total'];
    $week=[];for($i=6;$i>=0;$i--){$date=date('Y-m-d',strtotime('-'.$i.' days'));$week[$date]=$dayCounts[$date]??0;}
    $maxDay=max(1,max($week));
    $q=db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY notification_id DESC LIMIT 3');$q->execute([$user['user_id']]);$notes=$q->fetchAll();
    $q=db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');$q->execute([$user['user_id']]);$unread=(int)$q->fetchColumn();
    $announcements=db()->query("SELECT * FROM announcements WHERE audience IN ('All','Senior Citizen') ORDER BY created_at DESC,announcement_id DESC LIMIT 3")->fetchAll();
    $pendingRegistrations=(int)db()->query("SELECT COUNT(*) FROM users u JOIN senior_citizens s ON s.user_id=u.user_id WHERE u.status='Pending'")->fetchColumn();
    $seniorCount=(int)db()->query('SELECT COUNT(*) FROM senior_citizens')->fetchColumn();
    $todayCount=(int)($week[date('Y-m-d')]??0);
    $todaySchedule=0;foreach($schedule as $entry)if(substr($entry['schedule_date'],0,10)===date('Y-m-d'))$todaySchedule++;
    // Count all today's schedules, not only the three shown in the preview.
    if(!$isSenior)$todaySchedule=(int)db()->query("SELECT COUNT(*) FROM assistance_requests WHERE status='Scheduled' AND schedule_date>=CURDATE() AND schedule_date<CURDATE()+INTERVAL 1 DAY")->fetchColumn();
    $activities=$isAdmin?db()->query('SELECT a.action,a.created_at,u.full_name FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id ORDER BY a.audit_id DESC LIMIT 4')->fetchAll():[];
    $firstName=explode(' ',trim($user['full_name']))[0];
    $greeting=(int)date('G')<12?'Good morning':((int)date('G')<18?'Good afternoon':'Good evening');
    $pageStyles=['dashboard.css'];
    require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/sidebar.php';
    $metrics=$isSenior?[
        ['Your requests',$total,'All assistance applications','bi-clipboard-heart','teal',$prefix.'/requests.php'],
        ['In review',$waiting,'Waiting for the next update','bi-hourglass-split','amber',$prefix.'/requests.php'],
        ['Completed',$statuses['Completed'],'Assistance requests completed','bi-check2-circle','green',$prefix.'/requests.php'],
        ['Unread updates',$unread,'Messages for your account','bi-bell','blue',$prefix.'/notifications.php']
    ]:($isAdmin?[
        ['Senior citizens',$seniorCount,'People in your community','bi-people','teal',$prefix.'/seniors.php'],
        ['Registrations',$pendingRegistrations,'Pending account approval','bi-person-check','amber',$prefix.'/registrations.php'],
        ['Requests in review',$waiting,'Pending and under review','bi-clipboard-pulse','blue',$prefix.'/requests.php'],
        ['Completed',$statuses['Completed'],'Assistance requests completed','bi-check2-circle','green',$prefix.'/reports.php']
    ]:[
        ["New requests today",$todayCount,'Submitted since midnight','bi-inbox','teal',$prefix.'/requests.php'],
        ['Awaiting review',$waiting,'Requests needing attention','bi-hourglass-split','amber',$prefix.'/requests.php'],
        ["Scheduled today",$todaySchedule,'Assistance appointments','bi-calendar-check','blue',$prefix.'/requests.php'],
        ['Registrations',$pendingRegistrations,'Applications to review','bi-person-check','green',$prefix.'/registrations.php']
    ]);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard.css">
<div class="dashboard <?= $isSenior?'dashboard-senior':'' ?>">
<header class="dash-heading">
    <div><p class="dash-eyebrow"><?= $isSenior?'YOUR SENIORCARE SPACE':($isAdmin?'COMMUNITY OVERVIEW':'BARANGAY WORKSPACE') ?></p><h1><?= $isSenior?'My dashboard':($isAdmin?'Administrator dashboard':'Staff dashboard') ?></h1></div>
    <span class="dash-date"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= e(date('D, M j, Y')) ?></span>
</header>
<section class="dash-welcome">
    <div class="welcome-copy">
        <span class="welcome-label"><span class="welcome-dot"></span> <?= $isSenior?'Here to help, every step of the way':'SeniorCare · Barangay assistance' ?></span>
        <h2><?= e($greeting) ?>, <?= e($firstName) ?>.</h2>
        <p><?= $isSenior?'Your assistance, updates, and next steps—all in one place.':($isAdmin?'A closer look at your community. Keep services moving and make every application count.':'Make today a little easier for your community. Your review queue and upcoming schedules are ready.') ?></p>
        <div class="welcome-actions">
            <a class="dash-button light" href="<?= BASE_URL.$prefix.($isSenior?'/requests.php':($isAdmin?'/registrations.php':'/requests.php')) ?>"><?= $isSenior?'Request assistance':($isAdmin?'Review registrations':'Open request queue') ?> <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            <a class="welcome-secondary" href="<?= BASE_URL.$prefix.($isSenior?'/application.php':'/reports.php') ?>"><?= $isSenior?'Track my registration':'View reports' ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
    </div>
    <div class="welcome-summary">
        <span class="summary-orbit" aria-hidden="true"></span>
        <div class="summary-symbol"><i class="bi <?= $isSenior?'bi-heart-pulse':($isAdmin?'bi-buildings':'bi-calendar2-heart') ?>" aria-hidden="true"></i></div>
        <span class="summary-number"><?= $isSenior?e($user['status']):number_format($isAdmin?$seniorCount:$todaySchedule) ?></span>
        <span class="summary-caption"><?= $isSenior?'Your account status':($isAdmin?'seniors in your care':'appointments today') ?></span>
    </div>
</section>
<div class="dash-metrics">
<?php foreach($metrics as [$label,$value,$hint,$icon,$color,$href]): ?>
<a class="dash-metric" href="<?= BASE_URL.$href ?>">
    <div class="metric-top"><span class="metric-icon <?= $color ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i></span><i class="bi bi-arrow-up-right metric-arrow" aria-hidden="true"></i></div>
    <strong><?= number_format($value) ?></strong><span class="metric-name"><?= e($label) ?></span><small><?= e($hint) ?></small>
</a>
<?php endforeach; ?>
</div>
<div class="dash-columns">
<div class="dash-primary">
<?php if(!$isSenior): ?>
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">SERVICE ACTIVITY</p><h2>Requests this week</h2></div><div class="chart-total"><?= array_sum($week) ?><small>last 7 days</small></div></div>
<div class="activity-chart" aria-label="Daily request submissions for the last seven days">
<?php foreach($week as $date=>$value): ?>
<div class="chart-column"><span class="bar-number"><?= $value ?></span><div class="bar-track"><div class="chart-bar <?= $date===date('Y-m-d')?'is-today':'' ?>" style="height:<?= $value?max(5,(int)round($value/$maxDay*100)):0 ?>%"></div></div><span><?= e(date('D',strtotime($date))) ?></span><small><?= e(date('M j',strtotime($date))) ?></small></div>
<?php endforeach; ?>
</div>
<p class="chart-caption"><span class="chart-key"></span> Requests submitted · Today's column is highlighted</p>
</section>
<?php endif; ?>
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow"><?= $isSenior?'YOUR ASSISTANCE':'LATEST APPLICATIONS' ?></p><h2><?= $isSenior?'Your recent requests':'Recent requests' ?></h2></div><a class="dash-text-link" href="<?= BASE_URL.$prefix ?>/requests.php">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
<?php if(!$requests): ?><div class="dash-empty"><i class="bi bi-clipboard-heart" aria-hidden="true"></i><h3><?= $isSenior?'Your first step starts here':'No requests yet' ?></h3><p><?= $isSenior?'Need a little support? Submit an assistance request and follow its progress here.':'New assistance applications will appear here as they arrive.' ?></p><a href="<?= BASE_URL.$prefix ?>/requests.php">Open assistance requests →</a></div><?php endif; ?>
<?php foreach($requests as $request): ?>
<a class="dash-request" href="<?= BASE_URL.$prefix ?>/application.php?request_id=<?= (int)$request['request_id'] ?>">
    <span class="request-icon"><i class="bi bi-clipboard2-heart" aria-hidden="true"></i></span>
    <span class="request-copy"><strong><?= e($request['request_type']) ?></strong><small><?= $isSenior?'Request #'.(int)$request['request_id']:e($request['full_name']) ?> · <?= e(date('M j, Y',strtotime($request['created_at']))) ?></small></span>
    <span class="dash-status <?= dashboard_status_class($request['status']) ?>"><?= e($request['status']) ?></span>
    <i class="bi bi-chevron-right request-chevron" aria-hidden="true"></i>
</a>
<?php endforeach; ?>
</section>
<?php if($isSenior || !$isAdmin): ?>
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">FROM YOUR BARANGAY</p><h2>Community announcements</h2></div><a class="dash-text-link" href="<?= BASE_URL.$prefix ?>/announcements.php">View all →</a></div>
<?php if(!$announcements): ?><p class="dash-empty compact">No announcements yet. Check back for community updates.</p><?php endif; ?>
<?php foreach($announcements as $announcement): ?>
<a class="announcement-row" href="<?= BASE_URL.$prefix ?>/announcements.php#announcement-<?= (int)$announcement['announcement_id'] ?>">
<span class="announcement-date"><strong><?= e(date('d',strtotime($announcement['created_at']))) ?></strong><?= e(date('M',strtotime($announcement['created_at']))) ?></span>
<span><strong><?= e($announcement['title']) ?></strong><p><?= e($announcement['body']) ?></p><?php if($announcement['is_emergency']): ?><span class="dash-status negative">Important announcement</span><?php endif; ?></span>
</a>
<?php endforeach; ?>
</section>
<?php else: ?>
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">THE LATEST</p><h2>Recent activity</h2></div><a class="dash-text-link" href="<?= BASE_URL ?>/admin/audit_logs.php">Activity log →</a></div>
<?php if(!$activities): ?><p class="dash-empty compact">Recorded staff and administrator activity will appear here.</p><?php endif; ?>
<div class="dash-timeline"><?php foreach($activities as $activity): ?><div><span class="activity-dot"></span><strong><?= e($activity['action']) ?></strong><p><?= e($activity['full_name'] ?? 'System') ?> <span>· <?= e(date('M j, g:i A',strtotime($activity['created_at']))) ?></span></p></div><?php endforeach; ?></div>
</section>
<?php endif; ?>
</div>
<aside class="dash-secondary">
<?php if(!$isSenior):
$colors=['#e7b14c','#b592cf','#5b9dab','#6d89bd','#258273','#cf7474'];
$stops=[];$cursor=0;$i=0;foreach($statuses as $status=>$count){$next=$cursor+($total?$count/$total*100:0);$stops[]=$colors[$i++].' '.$cursor.'% '.$next.'%';$cursor=$next;}
?>
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">AT A GLANCE</p><h2>Request breakdown</h2></div></div>
<div class="status-ring" style="background:<?= $total?'conic-gradient('.implode(',',$stops).')':'#e7eeec' ?>" role="img" aria-label="<?= $total ?> requests; breakdown listed below"><div><strong><?= $total ?></strong><span>total requests</span></div></div>
<div class="status-legend"><?php $i=0;foreach($statuses as $status=>$count): ?><div><span class="legend-dot" style="background:<?= $colors[$i++] ?>"></span><span><?= e($status) ?></span><strong><?= $count ?></strong></div><?php endforeach; ?></div>
</section>
<?php endif; ?>
<section class="dash-card schedule-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">PLAN AHEAD</p><h2><?= $isSenior?'Your next schedule':'Upcoming schedules' ?></h2></div><i class="bi bi-calendar2-week" aria-hidden="true"></i></div>
<?php if(!$schedule): ?><div class="dash-empty compact"><i class="bi bi-calendar2-check" aria-hidden="true"></i><h3>No upcoming appointments</h3><p><?= $isSenior?'When your assistance is scheduled, the details will appear here.':'Scheduled assistance from today onward will appear here.' ?></p></div><?php endif; ?>
<?php foreach($schedule as $appointment): ?>
<a class="schedule-item" href="<?= BASE_URL.$prefix ?>/application.php?request_id=<?= (int)$appointment['request_id'] ?>"><span class="schedule-time"><?= e(date('M j',strtotime($appointment['schedule_date']))) ?><strong><?= e(date('g:i A',strtotime($appointment['schedule_date']))) ?></strong></span><span><strong><?= e($appointment['request_type']) ?></strong><small><?= $isSenior?'Request #'.(int)$appointment['request_id']:e($appointment['full_name']) ?></small></span></a>
<?php endforeach; ?>
</section>
<?php if($isSenior): ?>
<section class="dash-card">
<div class="dash-card-heading"><div><p class="dash-eyebrow">STAY IN THE LOOP</p><h2>Your updates</h2></div><span class="dash-status scheduled"><?= $unread ?> unread</span></div>
<?php if(!$notes): ?><p class="dash-empty compact">You're all caught up. New account updates will appear here.</p><?php endif; ?>
<?php foreach($notes as $note): ?>
<a class="dash-note" href="<?= BASE_URL.e(notification_destination($note,$role) ?? '/senior/notifications.php') ?>"><span class="note-dot <?= $note['is_read']?'read':'' ?>"></span><span><strong><?= e($note['title']) ?></strong><p><?= e($note['message']) ?></p><?php $notification=$note; require __DIR__.'/../includes/notification_dates.php'; ?></span></a>
<?php endforeach; ?>
<a class="dash-text-link" href="<?= BASE_URL ?>/senior/notifications.php">All notifications →</a>
</section>
<?php else: ?>
<section class="dash-review"><span class="metric-icon amber"><i class="bi bi-person-check" aria-hidden="true"></i></span><h2><?= $pendingRegistrations ?> registration<?= $pendingRegistrations===1?'':'s' ?> waiting</h2><p><?= $isAdmin?'Review the submitted requirements and help seniors get started.':'Check the submitted requirements before administrator approval.' ?></p><a class="dash-button dark" href="<?= BASE_URL.$prefix ?>/registrations.php">Review applications <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></section>
<?php endif; ?>
</aside>
</div>
<footer class="dash-footer"><span><i class="bi bi-heart-pulse" aria-hidden="true"></i> SeniorCare</span><p>Helping your community, one request at a time.</p></footer>
</div>
<?php require __DIR__.'/../includes/footer.php';
}