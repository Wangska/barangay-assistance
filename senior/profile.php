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
    $photo = null;
    try {
        $photo = upload_profile_photo($_FILES['profile_photo'] ?? []);
        $idPath = upload_valid_id($_FILES['valid_id'] ?? []);
    } catch (RuntimeException $error) {
        if ($photo !== null) {
            delete_profile_photo($photo);
        }
        flash('danger', $error->getMessage());
        redirect('/senior/profile.php');
    }
    db()->prepare('UPDATE users SET full_name=?, phone_number=? WHERE user_id=?')->execute([$fullName, $phoneNumber, $user['user_id']]);
    if ($photo !== null) {
        delete_profile_photo($user['profile_photo'] ?? null);
        db()->prepare('UPDATE users SET profile_photo=? WHERE user_id=?')->execute([$photo, $user['user_id']]);
    }
    if ($idPath) {
        db()->prepare("INSERT INTO application_requirements (user_id,label,status,file_path) VALUES (?,'Valid identification','Submitted',?) ON DUPLICATE KEY UPDATE status='Submitted',file_path=VALUES(file_path),reviewed_by=NULL")->execute([$user['user_id'], $idPath]);
        application_event((int) $user['user_id'], null, 'Document submitted', 'Valid identification replaced for review.', (int) $user['user_id']);
        notify_barangay_staff('New Senior Registration', $user['full_name'] . ' submitted a replacement ID.', (int) $user['user_id']);
        db()->prepare('UPDATE senior_citizens SET address=?, emergency_contact_name=?, emergency_contact_phone=?, medical_notes=?, valid_id_path=? WHERE user_id=?')->execute([$address, trim($_POST['emergency_contact_name'] ?? ''), $emergencyPhone, trim($_POST['medical_notes'] ?? ''), $idPath, $user['user_id']]);
    } else {
        db()->prepare('UPDATE senior_citizens SET address=?, emergency_contact_name=?, emergency_contact_phone=?, medical_notes=? WHERE user_id=?')->execute([$address, trim($_POST['emergency_contact_name'] ?? ''), $emergencyPhone, trim($_POST['medical_notes'] ?? ''), $user['user_id']]);
    }
    audit_log((int) $user['user_id'], 'Updated own profile', 'users', (int) $user['user_id']);
    flash('success', 'Profile updated.');
    redirect('/senior/profile.php');
}

$stmt = db()->prepare('SELECT s.*, u.full_name, u.email, u.phone_number, u.profile_photo FROM senior_citizens s JOIN users u ON u.user_id=s.user_id WHERE s.user_id=?');
$stmt->execute([$user['user_id']]);
$profile = $stmt->fetch();
$photoPath = profile_photo_storage_path($profile['profile_photo'] ?? null);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">My Profile</h1>
<div class="panel" style="max-width:720px">
    <form method="post" enctype="multipart/form-data" class="row g-3" id="profile-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="col-12 profile-head">
            <div class="profile-photo-wrap">
                <div class="profile-photo" id="photo-preview">
                    <?php if ($photoPath !== null): ?>
                        <img class="profile-current" src="<?= BASE_URL ?>/admin/profile_photo.php?v=<?= (int) filemtime($photoPath) ?>" alt="">
                    <?php else: ?>
                        <i class="bi bi-person-fill" id="photo-placeholder"></i>
                    <?php endif; ?>
                </div>
                <button type="button" class="profile-camera" id="choose-photo" aria-label="Upload profile photo"><i class="bi bi-camera-fill" aria-hidden="true"></i></button>
            </div>
            <div class="profile-identity">
                <strong><?= e($profile['full_name']) ?></strong>
                <span>Senior Citizen</span>
            </div>
        </div>
        <input class="visually-hidden" id="profile_photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
        <div class="col-md-6">
            <label class="form-label" for="full_name">Full name</label>
            <input class="form-control" id="full_name" name="full_name" value="<?= e($profile['full_name']) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="phone_number">Phone</label>
            <input class="form-control" id="phone_number" name="phone_number" value="<?= e($profile['phone_number']) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" id="email" value="<?= e($profile['email']) ?>" readonly>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="role">Role</label>
            <input class="form-control" id="role" value="Senior Citizen" readonly>
        </div>
        <div class="col-12">
            <label class="form-label" for="address">Address</label>
            <textarea class="form-control" id="address" name="address" required><?= e($profile['address']) ?></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="emergency_contact_name">Emergency contact</label>
            <input class="form-control" id="emergency_contact_name" name="emergency_contact_name" value="<?= e($profile['emergency_contact_name']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="emergency_contact_phone">Emergency phone</label>
            <input class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" value="<?= e($profile['emergency_contact_phone']) ?>">
        </div>
        <div class="col-12">
            <label class="form-label" for="valid_id">Valid ID</label>
            <input class="form-control" id="valid_id" type="file" name="valid_id" accept=".jpg,.jpeg,.png,.pdf">
        </div>
        <div class="col-12">
            <label class="form-label" for="medical_notes">Medical notes</label>
            <textarea class="form-control" id="medical_notes" name="medical_notes"><?= e($profile['medical_notes']) ?></textarea>
        </div>
        <div class="col-12">
            <button class="btn btn-primary">Save profile</button>
        </div>
    </form>
</div>
<div class="modal fade" id="photo-modal" tabindex="-1" aria-labelledby="photo-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content photo-modal">
            <div class="modal-body">
                <div class="photo-modal-title">
                    <h2 id="photo-modal-title">Position your photo</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <p class="crop-help">Drag to move · use the slider to zoom · then save the circular crop.</p>
                <div class="crop-frame" id="photo-stage">
                    <img id="crop-image" alt="" draggable="false">
                    <div class="crop-shade"></div>
                </div>
                <label class="photo-zoom" for="crop-zoom">Zoom
                    <input id="crop-zoom" type="range" min="1" max="3" step="0.01" value="1">
                </label>
                <div class="photo-modal-actions">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="save-crop">Save photo</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/profile-photo.js?v=20261018a"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
