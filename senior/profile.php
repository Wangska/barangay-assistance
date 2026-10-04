<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role(['Senior Citizen']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fullName = trim($_POST['full_name'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
    if ($fullName === '' || $address === '' || !valid_philippine_mobile($phoneNumber) || ($emergencyPhone !== '' && !valid_philippine_mobile($emergencyPhone))) {
        flash('danger', 'Enter a name, address, and valid Philippine mobile numbers.');
        redirect('/senior/profile.php');
    }
    $idPath = upload_valid_id($_FILES['valid_id'] ?? []);
    db()->prepare('UPDATE users SET full_name=?, phone_number=? WHERE user_id=?')->execute([$fullName, $phoneNumber, $user['user_id']]);
    if ($idPath) {
        db()->prepare("INSERT INTO application_requirements (user_id,label,status,file_path) VALUES (?,'Valid identification','Submitted',?) ON DUPLICATE KEY UPDATE status='Submitted',file_path=VALUES(file_path),reviewed_by=NULL")->execute([$user['user_id'],$idPath]);
        application_event((int)$user['user_id'],null,'Document submitted','Valid identification replaced for review.',(int)$user['user_id']);
        notify_barangay_staff('New Senior Registration',$user['full_name'].' submitted a replacement ID.',(int)$user['user_id']);
        db()->prepare('UPDATE senior_citizens SET address=?, emergency_contact_name=?, emergency_contact_phone=?, medical_notes=?, valid_id_path=? WHERE user_id=?')->execute([$address, trim($_POST['emergency_contact_name'] ?? ''), $emergencyPhone, trim($_POST['medical_notes'] ?? ''), $idPath, $user['user_id']]);
    } else {
        db()->prepare('UPDATE senior_citizens SET address=?, emergency_contact_name=?, emergency_contact_phone=?, medical_notes=? WHERE user_id=?')->execute([$address, trim($_POST['emergency_contact_name'] ?? ''), $emergencyPhone, trim($_POST['medical_notes'] ?? ''), $user['user_id']]);
    }
    flash('success','Profile updated.'); redirect('/senior/profile.php');
}
$stmt = db()->prepare('SELECT s.*, u.full_name, u.phone_number FROM senior_citizens s JOIN users u ON u.user_id=s.user_id WHERE s.user_id=?'); $stmt->execute([$user['user_id']]); $p = $stmt->fetch();
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">Edit Profile</h1><div class="panel"><form method="post" enctype="multipart/form-data" class="row g-3"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><div class="col-md-6"><label class="form-label">Full Name</label><input class="form-control" name="full_name" value="<?= e($p['full_name']) ?>" required></div><div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone_number" value="<?= e($p['phone_number']) ?>" required></div><div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address"><?= e($p['address']) ?></textarea></div><div class="col-md-4"><label class="form-label">Emergency Contact</label><input class="form-control" name="emergency_contact_name" value="<?= e($p['emergency_contact_name']) ?>"></div><div class="col-md-4"><label class="form-label">Emergency Phone</label><input class="form-control" name="emergency_contact_phone" value="<?= e($p['emergency_contact_phone']) ?>"></div><div class="col-md-4"><label class="form-label">Valid ID</label><input class="form-control" type="file" name="valid_id" accept=".jpg,.jpeg,.png,.pdf"></div><div class="col-12"><label class="form-label">Medical Notes</label><textarea class="form-control" name="medical_notes"><?= e($p['medical_notes']) ?></textarea></div><div class="col-12"><button class="btn btn-primary">Save Profile</button></div></form></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
