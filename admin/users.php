<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role(['Administrator']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($_POST['create'])) {
        $roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = $_POST['status'] ?? '';
        $role = false;
        if ($roleId !== false) {
            $roleQuery = db()->prepare('SELECT role_name FROM roles WHERE role_id = ?');
            $roleQuery->execute([$roleId]);
            $role = $roleQuery->fetch();
        }
        if (!$role || $role['role_name'] === 'Senior Citizen' || !in_array($status, ['Active', 'Pending', 'Inactive'], true) || trim($_POST['full_name'] ?? '') === '' || !filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) || !valid_philippine_mobile(trim($_POST['phone_number'] ?? '')) || strlen($_POST['password'] ?? '') < 8) {
            flash('danger', 'Enter valid account details. Senior citizens must use the registration form so their profile can be reviewed.');
            redirect('/admin/users.php');
        }
        $stmt = db()->prepare('INSERT INTO users (role_id, full_name, email, phone_number, password_hash, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$roleId, trim($_POST['full_name']), trim($_POST['email']), trim($_POST['phone_number']), password_hash($_POST['password'], PASSWORD_DEFAULT), $status]);
        audit_log((int)$user['user_id'], 'Created user', 'users', (int)db()->lastInsertId());
    } elseif (isset($_POST['status'], $_POST['user_id'])) {
        $targetUserId = filter_var($_POST['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($targetUserId === false || $targetUserId === (int) $user['user_id'] || !in_array($_POST['status'], ['Active', 'Pending', 'Inactive', 'Rejected'], true)) {
            flash('danger', 'Invalid user status update. You cannot change your own account status here.');
            redirect('/admin/users.php');
        }
        $stmt = db()->prepare('UPDATE users SET status = ? WHERE user_id = ?');
        $stmt->execute([$_POST['status'], $targetUserId]);
        audit_log((int)$user['user_id'], 'Updated user status', 'users', (int)$targetUserId);
    }
    flash('success', 'User record saved.');
    redirect('/admin/users.php');
}
$roles = db()->query('SELECT * FROM roles')->fetchAll();
$rows = db()->query('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id ORDER BY u.created_at DESC')->fetchAll();
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">Manage Users</h1>
<div class="panel mb-3"><form method="post" class="row g-2 align-items-end"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="create" value="1"><div class="col-md-2"><label class="form-label">Role</label><select class="form-select" name="role_id"><?php foreach($roles as $r): ?><option value="<?= $r['role_id'] ?>"><?= e($r['role_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Name</label><input class="form-control" name="full_name" required></div><div class="col-md-2"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div><div class="col-md-2"><label class="form-label">Phone</label><input class="form-control" name="phone_number" required></div><div class="col-md-2"><label class="form-label">Password</label><input class="form-control" type="password" name="password" minlength="8" required></div><div class="col-md-1"><label class="form-label">Status</label><select class="form-select" name="status"><option>Active</option><option>Pending</option><option>Inactive</option></select></div><div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div></form></div>
<div class="panel table-responsive"><table class="table align-middle"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Phone</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e($r['full_name']) ?></td><td><?= e($r['email']) ?></td><td><?= e($r['role_name']) ?></td><td><?= e($r['phone_number']) ?></td><td><span class="badge text-bg-secondary"><?= e($r['status']) ?></span></td><td><form method="post" class="d-flex gap-1"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="user_id" value="<?= $r['user_id'] ?>"><select name="status" class="form-select form-select-sm"><option<?= $r['status'] === 'Active' ? ' selected' : '' ?>>Active</option><option<?= $r['status'] === 'Pending' ? ' selected' : '' ?>>Pending</option><option<?= $r['status'] === 'Inactive' ? ' selected' : '' ?>>Inactive</option><option<?= $r['status'] === 'Rejected' ? ' selected' : '' ?>>Rejected</option></select><button class="btn btn-sm btn-outline-primary">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
