<?php
require_once __DIR__.'/../includes/functions.php';
function render_registration_list(array $roles): void {
    $user=require_role($roles);
    $prefix=$user['role_name']==='Administrator'?'/admin':'/staff';
    $status=$_GET['status'] ?? 'Pending';
    if (!in_array($status,['All','Pending','Active','Rejected','Inactive'],true)) $status='Pending';
    $duplicateCondition=senior_duplicate_condition();
    $q=db()->prepare("SELECT u.user_id,u.full_name,u.status,u.created_at,s.birthdate,
      EXISTS(SELECT 1 FROM senior_citizens other JOIN users other_user ON other_user.user_id=other.user_id WHERE other.user_id<>s.user_id AND ($duplicateCondition)) AS possible_duplicate
      FROM users u JOIN senior_citizens s ON s.user_id=u.user_id WHERE (?='All' OR u.status=?) ORDER BY u.created_at DESC");
    $q->execute([$status,$status]);
    require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/sidebar.php';
?>
<h1 class="page-title">Senior Registrations</h1>
<form method="get" class="d-flex gap-2 mb-3"><label>Status<select class="form-select" name="status"><?php foreach(['All','Pending','Active','Rejected','Inactive'] as $option): ?><option <?= $option===$status?'selected':'' ?>><?= $option ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Filter</button></form>
<div class="panel table-responsive"><table class="table"><thead><tr><th>Senior</th><th>Birthdate</th><th>Submitted</th><th>Status</th><th>Review</th></tr></thead><tbody>
<?php foreach($q as $r): ?><tr><td><?= e($r['full_name']) ?><?php if($r['possible_duplicate']): ?><div class="badge text-bg-warning">Possible duplicate</div><?php endif; ?></td><td><?= e($r['birthdate']) ?></td><td><?= e($r['created_at']) ?></td><td><?= e($r['status']) ?></td><td><a class="btn btn-outline-primary" href="<?= BASE_URL.$prefix ?>/application.php?user_id=<?= (int)$r['user_id'] ?>">Review application</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__.'/../includes/footer.php'; }