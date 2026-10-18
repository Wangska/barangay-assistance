<?php
require_once __DIR__ . '/../includes/functions.php';
$user = require_role(['Administrator', 'Barangay Staff']);
$profilePage = $user['role_name'] === 'Barangay Staff' ? '/staff/profile.php' : '/admin/profile.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fullName = trim($_POST['full_name'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    if ($fullName === '' || !valid_philippine_mobile($phoneNumber)) {
        flash('danger', 'Enter your name and a valid Philippine mobile number.');
        redirect($profilePage);
    }
    try {
        $photo = upload_profile_photo($_FILES['profile_photo'] ?? []);
    } catch (RuntimeException $error) {
        flash('danger', $error->getMessage());
        redirect($profilePage);
    }
    db()->prepare('UPDATE users SET full_name=?, phone_number=? WHERE user_id=?')->execute([$fullName, $phoneNumber, $user['user_id']]);
    if ($photo !== null) {
        delete_profile_photo($user['profile_photo'] ?? null);
        db()->prepare('UPDATE users SET profile_photo=? WHERE user_id=?')->execute([$photo, $user['user_id']]);
    }
    audit_log((int) $user['user_id'], 'Updated own profile', 'users', (int) $user['user_id']);
    flash('success', 'Profile updated.');
    redirect($profilePage);
}

$stmt = db()->prepare('SELECT full_name, email, phone_number, profile_photo FROM users WHERE user_id=?');
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
                <span><?= e($user['role_name']) ?></span>
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
            <input class="form-control" id="role" value="<?= e($user['role_name']) ?>" readonly>
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
