<?php
require_once __DIR__ . '/../includes/functions.php';
function render_seniors_page(array $roles): void
{
    $user = require_role($roles);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (isset($_POST['senior_id'])) {
            $stmt = db()->prepare('UPDATE senior_citizens SET address=?, emergency_contact_name=?, emergency_contact_phone=?, medical_notes=? WHERE senior_id=?');
            $stmt->execute([trim($_POST['address']), trim($_POST['emergency_contact_name']), trim($_POST['emergency_contact_phone']), trim($_POST['medical_notes']), $_POST['senior_id']]);
            audit_log((int)$user['user_id'], 'Updated senior profile', 'senior_citizens', (int)$_POST['senior_id']);
            flash('success', 'Senior profile updated.');
        }
        redirect(str_contains($_SERVER['REQUEST_URI'], '/staff/') ? '/staff/seniors.php' : '/admin/seniors.php');
    }
    $rows = db()->query('SELECT s.*, u.full_name, u.email, u.phone_number, u.status FROM senior_citizens s JOIN users u ON u.user_id = s.user_id ORDER BY u.full_name')->fetchAll();
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    echo '<h1 class="page-title">Senior Citizens</h1><div class="panel table-responsive"><table class="table align-middle"><thead><tr><th>Name</th><th>Phone</th><th>Birthdate</th><th>Address</th><th>Medical Notes</th><th>Update</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . e($r['full_name']) . '<div class="small text-muted">' . e($r['email']) . '</div></td><td>' . e($r['phone_number']) . '</td><td>' . e($r['birthdate']) . '</td><td><form method="post" class="vstack gap-1"><input type="hidden" name="csrf_token" value="' . csrf_token() . '"><input type="hidden" name="senior_id" value="' . e((string)$r['senior_id']) . '"><textarea class="form-control form-control-sm" name="address">' . e($r['address']) . '</textarea></td><td><input class="form-control form-control-sm mb-1" name="emergency_contact_name" value="' . e($r['emergency_contact_name']) . '" placeholder="Emergency contact"><input class="form-control form-control-sm mb-1" name="emergency_contact_phone" value="' . e($r['emergency_contact_phone']) . '" placeholder="Emergency phone"><textarea class="form-control form-control-sm" name="medical_notes">' . e($r['medical_notes']) . '</textarea></td><td><button class="btn btn-sm btn-primary">Save</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
    require_once __DIR__ . '/../includes/footer.php';
}
