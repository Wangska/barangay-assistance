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
        if (isset($_POST['deceased_toggle'])) {
            $seniorQuery = db()->prepare('SELECT senior_id, is_deceased FROM senior_citizens WHERE user_id = ?');
            $seniorQuery->execute([$targetUserId]);
            $senior = $seniorQuery->fetch();
            if ($existing['role_name'] !== 'Senior Citizen' || !$senior || !death_assistance_already_requested((int) $senior['senior_id'])) {
                $redirectUsers('danger', 'Only a senior with a death assistance request can be marked deceased.');
            }
            $markDeceased = ($_POST['deceased'] ?? '0') === '1';
            if ($markDeceased) {
                db()->prepare('UPDATE senior_citizens SET is_deceased = 1, deceased_at = NOW() WHERE senior_id = ?')->execute([(int) $senior['senior_id']]);
                db()->prepare("UPDATE users SET status = 'Inactive' WHERE user_id = ?")->execute([$targetUserId]);
                audit_log((int) $user['user_id'], 'Marked senior deceased', 'users', $targetUserId);
                $redirectUsers('success', 'Account marked deceased and set to inactive.');
            }
            db()->prepare('UPDATE senior_citizens SET is_deceased = 0, deceased_at = NULL WHERE senior_id = ?')->execute([(int) $senior['senior_id']]);
            db()->prepare("UPDATE users SET status = 'Active' WHERE user_id = ? AND status = 'Inactive'")->execute([$targetUserId]);
            audit_log((int) $user['user_id'], 'Restored deceased senior account', 'users', $targetUserId);
            $redirectUsers('success', 'Account restored to active.');
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
$deathAccounts = db()->query("
    SELECT u.user_id, u.full_name, u.email, u.phone_number, u.status, s.is_deceased, s.deceased_at,
           ar.status AS death_status, ar.created_at AS death_requested_at
    FROM senior_citizens s
    JOIN users u ON u.user_id = s.user_id
    JOIN assistance_requests ar ON ar.request_id = (
        SELECT MAX(request_id) FROM assistance_requests
        WHERE senior_id = s.senior_id AND request_type = 'Death Assistance' AND status <> 'Rejected'
    )
    ORDER BY s.is_deceased DESC, ar.created_at DESC
")->fetchAll();
$deathUserIds = [];
foreach ($deathAccounts as $account) {
    $deathUserIds[(int) $account['user_id']] = $account;
}
$deceasedToggle = static function (array $account, string $suffix) {
    $checked = (int) ($account['is_deceased'] ?? 0) === 1;
    $inputId = 'deceased-' . $suffix . '-' . (int) $account['user_id'];
    ?>
    <form method="post" class="deceased-toggle mb-0">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="deceased_toggle" value="1">
        <input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">
        <input type="hidden" name="deceased" value="0">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="<?= e($inputId) ?>" name="deceased" value="1" data-deceased-toggle data-user-name="<?= e($account['full_name']) ?>" <?= $checked ? 'checked' : '' ?>>
            <label class="form-check-label" for="<?= e($inputId) ?>">Deceased</label>
        </div>
    </form>
    <?php
};
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
                        <?php if (isset($deathUserIds[(int) $r['user_id']])) { $deceasedToggle($deathUserIds[(int) $r['user_id']], 'users'); } ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 mt-4">
    <h2 class="h4 mb-0">Deceased seniors</h2>
</div>
<p class="text-muted">A senior is listed here after filing a death assistance request. Turn on Deceased to set that account to inactive.</p>
<div class="panel table-responsive">
    <table class="table align-middle">
        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Death assistance</th><th>Requested</th><th>Account</th><th>Deceased</th></tr></thead>
        <tbody>
        <?php if (!$deathAccounts): ?>
            <tr><td colspan="7" class="text-muted">No senior has filed a death assistance request.</td></tr>
        <?php endif; ?>
        <?php foreach ($deathAccounts as $account): ?>
            <tr>
                <td><?= e($account['full_name']) ?></td>
                <td><?= e($account['email']) ?></td>
                <td><?= e($account['phone_number']) ?></td>
                <td><?= e(request_status_label((string) $account['death_status'])) ?></td>
                <td><?= e(date('M j, Y g:i A', strtotime($account['death_requested_at']))) ?></td>
                <td><span class="badge text-bg-secondary"><?= (int) $account['is_deceased'] === 1 ? 'Inactive' : e($account['status']) ?></span></td>
                <td><?php $deceasedToggle($account, 'list'); ?></td>
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

<div class="modal fade" id="deceased-user" tabindex="-1" aria-labelledby="deceased-user-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="deceased-user-title">Mark as deceased</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="mb-0" id="deceased-modal-text"></p></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="deceased-confirm">Mark deceased</button></div>
        </div>
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
(() => {
    const modalElement = document.getElementById('deceased-user');
    const title = document.getElementById('deceased-user-title');
    const text = document.getElementById('deceased-modal-text');
    const confirmButton = document.getElementById('deceased-confirm');
    let pendingToggle = null;
    let confirmed = false;
    document.querySelectorAll('[data-deceased-toggle]').forEach((input) => {
        input.addEventListener('change', () => {
            pendingToggle = input;
            confirmed = false;
            const marking = input.checked;
            const name = document.createElement('strong');
            name.textContent = input.dataset.userName;
            title.textContent = marking ? 'Mark as deceased' : 'Restore account';
            text.replaceChildren();
            text.append(marking ? 'Mark ' : 'Restore ', name, marking
                ? ' as deceased and set the account to inactive? They will no longer be able to sign in.'
                : ' and set the account back to active?');
            confirmButton.textContent = marking ? 'Mark deceased' : 'Restore account';
            confirmButton.className = marking ? 'btn btn-danger' : 'btn btn-primary';
            bootstrap.Modal.getOrCreateInstance(modalElement).show();
        });
    });
    confirmButton.addEventListener('click', () => {
        if (!pendingToggle) return;
        confirmed = true;
        pendingToggle.form.requestSubmit();
    });
    modalElement.addEventListener('hidden.bs.modal', () => {
        if (!confirmed && pendingToggle) pendingToggle.checked = !pendingToggle.checked;
        pendingToggle = null;
        confirmed = false;
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
