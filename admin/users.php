<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role(['Administrator']);

$redirectUsers = static function (string $type, string $message): void {
    flash($type, $message);
    redirect('/admin/users.php');
};

$accountInput = static function (bool $creating): array {
    $roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = $_POST['status'] ?? '';
    $role = false;
    if ($roleId !== false) {
        $roleQuery = db()->prepare('SELECT role_name FROM roles WHERE role_id = ?');
        $roleQuery->execute([$roleId]);
        $role = $roleQuery->fetch();
    }
    $password = (string) ($_POST['password'] ?? '');
    $valid = $role
        && $role['role_name'] !== 'Senior Citizen'
        && in_array($status, ['Active', 'Pending', 'Rejected'], true)
        && trim($_POST['full_name'] ?? '') !== ''
        && filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL)
        && valid_philippine_mobile(trim($_POST['phone_number'] ?? ''))
        && (!$creating || strlen($password) >= 8)
        && ($password === '' || strlen($password) >= 8);
    return [$valid, $roleId, $status, trim($_POST['full_name'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['phone_number'] ?? ''), $password];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (isset($_POST['create'])) {
            [$valid, $roleId, $status, $fullName, $email, $phone, $password] = $accountInput(true);
            if (!$valid) {
                $redirectUsers('danger', 'Enter valid account details. Senior citizens must use the registration form so their profile can be reviewed.');
            }
            db()->prepare('INSERT INTO users (role_id, full_name, email, phone_number, password_hash, status) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$roleId, $fullName, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $status]);
            audit_log((int) $user['user_id'], 'Created user', 'users', (int) db()->lastInsertId());
            $redirectUsers('success', 'User added.');
        }
        $targetUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($targetUserId === false) {
            $redirectUsers('danger', 'Choose a valid user.');
        }
        $existing = db()->prepare('SELECT u.user_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?');
        $existing->execute([$targetUserId]);
        $existing = $existing->fetch();
        if (!$existing) {
            $redirectUsers('danger', 'That user could not be found.');
        }
        if (isset($_POST['delete'])) {
            if ($targetUserId === (int) $user['user_id']) {
                $redirectUsers('danger', 'You cannot remove your own account.');
            }
            db()->prepare("UPDATE users SET status = 'Inactive' WHERE user_id = ?")->execute([$targetUserId]);
            audit_log((int) $user['user_id'], 'Soft deleted user', 'users', $targetUserId);
            $redirectUsers('success', 'User removed. Their records are still kept.');
        }
        if (isset($_POST['update'])) {
            $isSelf = $targetUserId === (int) $user['user_id'];
            $isSenior = $existing['role_name'] === 'Senior Citizen';
            [$valid, $roleId, $status, $fullName, $email, $phone, $password] = $accountInput(false);
            if ($isSelf || $isSenior) {
                $valid = trim($_POST['full_name'] ?? '') !== ''
                    && filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL)
                    && valid_philippine_mobile(trim($_POST['phone_number'] ?? ''))
                    && ($password === '' || strlen($password) >= 8);
                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone_number'] ?? '');
            }
            if (!$valid) {
                $redirectUsers('danger', 'Enter a name, email, and valid Philippine mobile number.');
            }
            if ($isSelf || $isSenior) {
                db()->prepare('UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE user_id = ?')
                    ->execute([$fullName, $email, $phone, $targetUserId]);
            } else {
                db()->prepare('UPDATE users SET role_id = ?, full_name = ?, email = ?, phone_number = ?, status = ? WHERE user_id = ?')
                    ->execute([$roleId, $fullName, $email, $phone, $status, $targetUserId]);
            }
            if ($password !== '') {
                db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $targetUserId]);
            }
            audit_log((int) $user['user_id'], 'Updated user', 'users', $targetUserId);
            $redirectUsers('success', 'User updated.');
        }
    } catch (PDOException $error) {
        $redirectUsers('danger', $error->getCode() === '23000' ? 'That email is already used by another account.' : 'The user could not be saved.');
    }
}

$roles = db()->query("SELECT * FROM roles WHERE role_name <> 'Senior Citizen' ORDER BY role_name")->fetchAll();
$rows = db()->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.status <> 'Inactive' ORDER BY u.created_at DESC")->fetchAll();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="page-title mb-0">Manage Users</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#add-user">Add user</button>
</div>
<div class="panel table-responsive">
    <table class="table align-middle">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Phone</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['full_name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><?= e($r['role_name']) ?></td>
                <td><?= e($r['phone_number']) ?></td>
                <td><span class="badge text-bg-secondary"><?= e($r['status']) ?></span></td>
                <td>
                    <div class="d-flex flex-wrap gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#view-user" data-user-view="<?= e($r['full_name']) ?>" data-user-email="<?= e($r['email']) ?>" data-user-role="<?= e($r['role_name']) ?>" data-user-phone="<?= e($r['phone_number']) ?>" data-user-status="<?= e($r['status']) ?>" data-user-created="<?= e(date('M j, Y', strtotime($r['created_at']))) ?>">View</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit-user" data-user-id="<?= (int) $r['user_id'] ?>" data-user-name="<?= e($r['full_name']) ?>" data-user-email="<?= e($r['email']) ?>" data-user-phone="<?= e($r['phone_number']) ?>" data-user-role="<?= (int) $r['role_id'] ?>" data-user-role-name="<?= e($r['role_name']) ?>" data-user-status="<?= e($r['status']) ?>" data-user-self="<?= $r['user_id'] === $user['user_id'] ? '1' : '0' ?>">Edit</button>
                        <?php if ((int) $r['user_id'] !== (int) $user['user_id']): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-user" data-user-id="<?= (int) $r['user_id'] ?>" data-user-name="<?= e($r['full_name']) ?>">Delete</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="modal fade" id="add-user" tabindex="-1" aria-labelledby="add-user-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="add-user-title">Add user</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="create" value="1">
                <div class="col-md-6"><label class="form-label" for="add-role">Role</label><select class="form-select" id="add-role" name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= (int) $role['role_id'] ?>"><?= e($role['role_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label" for="add-status">Status</label><select class="form-select" id="add-status" name="status"><option>Active</option><option>Pending</option><option>Rejected</option></select></div>
                <div class="col-12"><label class="form-label" for="add-name">Name</label><input class="form-control" id="add-name" name="full_name" required></div>
                <div class="col-md-6"><label class="form-label" for="add-email">Email</label><input class="form-control" id="add-email" type="email" name="email" required></div>
                <div class="col-md-6"><label class="form-label" for="add-phone">Phone</label><input class="form-control" id="add-phone" name="phone_number" required></div>
                <div class="col-12"><label class="form-label" for="add-password">Password</label><input class="form-control" id="add-password" type="password" name="password" minlength="8" required></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add user</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="view-user" tabindex="-1" aria-labelledby="view-user-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="view-user-title">User details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <dl class="row mb-0">
                    <dt class="col-4">Name</dt><dd class="col-8" id="view-name"></dd>
                    <dt class="col-4">Email</dt><dd class="col-8" id="view-email"></dd>
                    <dt class="col-4">Role</dt><dd class="col-8" id="view-role"></dd>
                    <dt class="col-4">Phone</dt><dd class="col-8" id="view-phone"></dd>
                    <dt class="col-4">Status</dt><dd class="col-8" id="view-status"></dd>
                    <dt class="col-4">Joined</dt><dd class="col-8" id="view-created"></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="edit-user" tabindex="-1" aria-labelledby="edit-user-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content" id="edit-user-form">
            <div class="modal-header"><h2 class="modal-title h5" id="edit-user-title">Edit user</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="update" value="1">
                <input type="hidden" name="user_id" id="edit-id">
                <div class="col-md-6" id="edit-role-wrap"><label class="form-label" for="edit-role">Role</label><select class="form-select" id="edit-role" name="role_id"><?php foreach ($roles as $role): ?><option value="<?= (int) $role['role_id'] ?>"><?= e($role['role_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6" id="edit-status-wrap"><label class="form-label" for="edit-status">Status</label><select class="form-select" id="edit-status" name="status"><option>Active</option><option>Pending</option><option>Rejected</option></select></div>
                <div class="col-12"><label class="form-label" for="edit-name">Name</label><input class="form-control" id="edit-name" name="full_name" required></div>
                <div class="col-md-6"><label class="form-label" for="edit-email">Email</label><input class="form-control" id="edit-email" type="email" name="email" required></div>
                <div class="col-md-6"><label class="form-label" for="edit-phone">Phone</label><input class="form-control" id="edit-phone" name="phone_number" required></div>
                <div class="col-12"><label class="form-label" for="edit-password">New password</label><input class="form-control" id="edit-password" type="password" name="password" minlength="8" placeholder="Leave blank to keep the current password"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="delete-user" tabindex="-1" aria-labelledby="delete-user-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="delete-user-title">Remove user</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="delete" value="1">
                <input type="hidden" name="user_id" id="delete-id">
                <p class="mb-0">Remove <strong id="delete-name"></strong> from the list? Their records stay in the system.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Remove</button></div>
        </form>
    </div>
</div>
<script>
document.getElementById('view-user').addEventListener('show.bs.modal', (event) => {
    const button = event.relatedTarget;
    document.getElementById('view-name').textContent = button.dataset.userView;
    document.getElementById('view-email').textContent = button.dataset.userEmail;
    document.getElementById('view-role').textContent = button.dataset.userRole;
    document.getElementById('view-phone').textContent = button.dataset.userPhone;
    document.getElementById('view-status').textContent = button.dataset.userStatus;
    document.getElementById('view-created').textContent = button.dataset.userCreated;
});
document.getElementById('edit-user').addEventListener('show.bs.modal', (event) => {
    const button = event.relatedTarget;
    const senior = button.dataset.userRoleName === 'Senior Citizen';
    const self = button.dataset.userSelf === '1';
    document.getElementById('edit-id').value = button.dataset.userId;
    document.getElementById('edit-name').value = button.dataset.userName;
    document.getElementById('edit-email').value = button.dataset.userEmail;
    document.getElementById('edit-phone').value = button.dataset.userPhone;
    document.getElementById('edit-password').value = '';
    document.getElementById('edit-role-wrap').hidden = senior || self;
    document.getElementById('edit-status-wrap').hidden = senior || self;
    if (!senior && !self) {
        document.getElementById('edit-role').value = button.dataset.userRole;
        document.getElementById('edit-status').value = button.dataset.userStatus;
    }
});
document.getElementById('delete-user').addEventListener('show.bs.modal', (event) => {
    const button = event.relatedTarget;
    document.getElementById('delete-id').value = button.dataset.userId;
    document.getElementById('delete-name').textContent = button.dataset.userName;
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
