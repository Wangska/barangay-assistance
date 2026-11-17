<?php
require_once __DIR__ . '/includes/functions.php';
header('Cache-Control: no-store');
$embed = (string) ($_POST['registration_embed'] ?? $_GET['embed'] ?? '') === '1';
$registrationReturn = $embed ? '/register.php?embed=1' : '/register.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $_SESSION['registration_old'] = array_filter(
        array_diff_key($_POST, array_flip(['password', 'password_confirmation', 'csrf_token'])),
        'is_string'
    );

    $required = [
        'first_name', 'last_name', 'address',
        'phone_number', 'email', 'password', 'birthdate', 'place_of_birth', 'gender',
        'civil_status', 'emergency_contact_name', 'emergency_contact_phone', 'terms_accepted',
        'occupation', 'living_type', 'beneficiary_name', 'beneficiary_address', 'emergency_contact_address',
    ];

    foreach ($required as $field) {
        if (trim((string) ($_POST[$field] ?? '')) === '') {
            flash('danger', 'Please complete all required registration fields.');
            redirect($registrationReturn);
        }
    }

    $birthdate = DateTime::createFromFormat('!Y-m-d', $_POST['birthdate']);
    if (!$birthdate || $birthdate->format('Y-m-d') !== $_POST['birthdate'] || (clone $birthdate)->modify('+60 years') > new DateTime('today')) {
        flash('danger', 'Registration is available to people who are 60 years old or above.');
        redirect($registrationReturn);
    }

    if (strlen($_POST['password']) < 8 || $_POST['password'] !== ($_POST['password_confirmation'] ?? '')) {
        flash('danger', 'Passwords must match and contain at least 8 characters.');
        redirect($registrationReturn);
    }

    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phoneNumber = trim($_POST['phone_number']);
    $emergencyPhone = trim($_POST['emergency_contact_phone']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !valid_philippine_mobile($phoneNumber) || !valid_philippine_mobile($emergencyPhone)) {
        flash('danger', 'Enter a valid email address and Philippine mobile numbers (09XXXXXXXXX or +639XXXXXXXXX).');
        redirect($registrationReturn);
    }

    if (!in_array($_POST['is_pensioner'] ?? '', ['Yes', 'No'], true)) {
        flash('danger', 'Answer whether you are receiving financial support.');
        redirect($registrationReturn);
    }
    $dependents = trim((string) ($_POST['dependent_children'] ?? ''));
    if (!preg_match('/^\d{1,2}$/', $dependents) || (int) $dependents > 30) {
        flash('danger', 'Enter the number of dependent children below 18 (0 to 30).');
        redirect($registrationReturn);
    }
    if ($_POST['is_pensioner'] === 'Yes' && trim((string) ($_POST['pension_source'] ?? '')) === '') {
        flash('danger', 'Enter the name of the pension you receive.');
        redirect($registrationReturn);
    }
    foreach ([
        'occupation' => 120, 'pension_source' => 180, 'living_type' => 80, 'beneficiary_name' => 150,
        'beneficiary_address' => 255, 'emergency_contact_address' => 255,
        'facebook_account' => 150,
    ] as $field => $limit) {
        if (strlen(trim((string) ($_POST[$field] ?? ''))) > $limit) {
            flash('danger', 'One of the registration answers is too long. Please shorten it and try again.');
            redirect($registrationReturn);
        }
    }

    $fullName = implode(' ', array_filter([
        trim($_POST['first_name']), trim($_POST['middle_name'] ?? ''), trim($_POST['last_name']),
    ]));

    $role = db()->prepare("SELECT role_id FROM roles WHERE role_name = 'Senior Citizen'");
    $role->execute();
    $roleId = (int) $role->fetchColumn();
    $pdo = db();
    $idPath = null;
    $photoPath = null;
    try {
        $idPath = upload_valid_id($_FILES['valid_id'] ?? []);
        if ($idPath === null) {
            throw new RuntimeException('Please select a valid identification file.');
        }
        $photoPath = upload_profile_photo($_FILES['photo'] ?? []);
        if ($photoPath === null) {
            throw new RuntimeException('Please select a 1x1 photo. Use a JPG, PNG, or WebP no larger than 2 MB.');
        }
    } catch (RuntimeException $e) {
        $uploadedId = $idPath !== null ? valid_id_storage_path($idPath) : null;
        if ($uploadedId !== null) {
            unlink($uploadedId);
        }
        delete_profile_photo($photoPath);
        flash('danger', $e->getMessage() . ' Your other details have been kept; please re-enter your password and select your files again.');
        redirect($registrationReturn);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO users (role_id, full_name, email, phone_number, password_hash, status, profile_photo) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$roleId, $fullName, $email, $phoneNumber, password_hash($_POST['password'], PASSWORD_DEFAULT), 'Pending', $photoPath]);
        $userId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('
            INSERT INTO senior_citizens (
                user_id, first_name, middle_name, last_name, birthdate, gender, region, province,
                city_municipality, barangay, address, sitio, place_of_birth, civil_status, blood_type, religion,
                education, telephone, facebook_account, gsis_no, sss_no, tin_no, philhealth_no, employment_status, classification,
                application_status, membership_remarks, nationality, occupation, monthly_income, is_pensioner, pension_source,
                lgu_assistance, lgu_assistance_type, other_financial_support, other_support_details,
                dependent_children, living_type, beneficiary_name, beneficiary_address, monthly_pension,
                emergency_contact_name, emergency_contact_phone, emergency_contact_address, emergency_relationship,
                valid_id_path, medical_notes, terms_accepted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $userId, trim($_POST['first_name']), trim($_POST['middle_name'] ?? ''), trim($_POST['last_name']),
            $birthdate->format('Y-m-d'), $_POST['gender'], 'Region VII (Central Visayas)', 'Cebu',
            'Minglanilla', 'Tungkil', trim($_POST['address']), '', trim($_POST['place_of_birth']),
            $_POST['civil_status'], '', '', '', '', trim((string) ($_POST['facebook_account'] ?? '')),
            '', '', '',
            '', '', '',
            'New applicant', null, null, trim($_POST['occupation']), null,
            $_POST['is_pensioner'], trim((string) ($_POST['pension_source'] ?? '')),
            null, '',
            null, '',
            (int) $dependents, trim($_POST['living_type']), trim($_POST['beneficiary_name']), trim($_POST['beneficiary_address']),
            null,
            trim($_POST['emergency_contact_name']), $emergencyPhone, trim($_POST['emergency_contact_address']), '',
            $idPath,
            '',
        ]);

        $pdo->prepare("INSERT INTO application_requirements (user_id,label,status,file_path) VALUES (?,'Valid identification',?,?)")->execute([$userId,$idPath?'Submitted':'Missing',$idPath]);
        application_event($userId,null,'Submitted','Registration submitted.',$userId);
        notify_barangay_staff('New Senior Registration', $fullName . ' submitted a senior citizen registration (Account #' . $userId . ').', $userId);
        $pdo->commit();
        unset($_SESSION['registration_old']);

        if ($embed) {
            echo '<!doctype html><meta charset="utf-8"><script>window.parent.postMessage({type:"registration-success"}, window.location.origin);</script>';
            exit;
        }
        redirect('/index.php?registered=1');
    } catch (Throwable $e) {
        error_log('Senior registration failed: ' . $e->getMessage());
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $uploadedPath = valid_id_storage_path($idPath);
        if ($uploadedPath !== null) {
            unlink($uploadedPath);
        }
        delete_profile_photo($photoPath);
        $message = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062
            ? 'This email address is already registered. Sign in or use a different email address.'
            : 'Registration could not be submitted. Please try again.';
        flash('danger', $message . ' Your details have been kept; please re-enter your password and select your ID again.');
        redirect($registrationReturn);
    }
}

$old = $_SESSION['registration_old'] ?? [];
unset($_SESSION['registration_old']);
$extraBodyClass = $embed ? 'registration-embed' : '';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/register.css?v=20261117b">

<main class="auth-wrap py-5">
    <section class="auth-card shadow">
        <div class="registration-heading text-center mb-4">
            <span class="registration-kicker">Barangay Senior Citizen Services</span>
            <h1 class="h3 mt-2">Senior Citizen Registration Form</h1>
            <p class="text-muted mb-0">Create your account to request assistance and stay connected with barangay services.</p>
        </div>

        <div class="registration-guide"><strong>Before you begin</strong><p>For residents aged 60 and above. Prepare a 1x1 photo, a valid ID, and your emergency contact details.</p><span>Fields marked <b aria-hidden="true">*</b> are required.</span></div>
        <form method="post" action="<?= $embed ? 'register.php?embed=1' : 'register.php' ?>" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <?php if ($embed): ?><input type="hidden" name="registration_embed" value="1"><?php endif; ?>

            <fieldset class="registration-section col-12"><legend class="form-section-title"><span class="section-number" aria-hidden="true">1</span>Personal Information</legend><div class="row g-3">
            <div class="col-md-4"><label class="form-label required" for="last_name">Last Name</label><input class="form-control" id="last_name" name="last_name" required value="<?= e($old['last_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required" for="first_name">First Name</label><input class="form-control" id="first_name" name="first_name" required value="<?= e($old['first_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="middle_name">Middle Name</label><input class="form-control" id="middle_name" name="middle_name" value="<?= e($old['middle_name'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label required" for="birthdate">Date of Birth</label><input class="form-control" type="date" id="birthdate" name="birthdate" max="<?= date('Y-m-d') ?>" value="<?= e($old['birthdate'] ?? '') ?>" required></div>
            <div class="col-md-3"><label class="form-label required" for="place_of_birth">Place of Birth</label><input class="form-control" id="place_of_birth" name="place_of_birth" required value="<?= e($old['place_of_birth'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label required" for="gender">Sex</label><select class="form-select" id="gender" name="gender" required><option value="">Choose option</option><option<?= ($old['gender'] ?? '') === 'Female' ? ' selected' : '' ?>>Female</option><option<?= ($old['gender'] ?? '') === 'Male' ? ' selected' : '' ?>>Male</option></select></div>
            <div class="col-md-3"><label class="form-label required" for="civil_status">Civil Status</label><select class="form-select" id="civil_status" name="civil_status" required><option value="">Choose option</option><option<?= ($old['civil_status'] ?? '') === 'Single' ? ' selected' : '' ?>>Single</option><option<?= ($old['civil_status'] ?? '') === 'Married' ? ' selected' : '' ?>>Married</option><option<?= ($old['civil_status'] ?? '') === 'Widowed' ? ' selected' : '' ?>>Widowed</option><option<?= ($old['civil_status'] ?? '') === 'Separated' ? ' selected' : '' ?>>Separated</option><option<?= ($old['civil_status'] ?? '') === 'Divorced' ? ' selected' : '' ?>>Divorced</option></select></div>

            </div></fieldset><fieldset class="registration-section col-12"><legend class="form-section-title"><span class="section-number" aria-hidden="true">2</span>Address and Contact</legend><div class="row g-3">
            <div class="col-12"><label class="form-label required" for="address">Purok / Sitio</label><input class="form-control" id="address" name="address" placeholder="Purok and sitio" required value="<?= e($old['address'] ?? '') ?>"><div class="form-text">Barangay Tungkil, Minglanilla, Cebu.</div></div>
            <div class="col-md-6"><label class="form-label required" for="phone_number">Contact Number</label><input class="form-control" type="tel" id="phone_number" name="phone_number" placeholder="e.g., 09171234567" required value="<?= e($old['phone_number'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label" for="facebook_account">FB Account <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" id="facebook_account" name="facebook_account" value="<?= e($old['facebook_account'] ?? '') ?>"></div>

            </div></fieldset><fieldset class="registration-section col-12"><legend class="form-section-title"><span class="section-number" aria-hidden="true">3</span>Membership Details</legend><div class="row g-3">
            <div class="col-md-6"><label class="form-label required" for="occupation">Profession / Skills</label><input class="form-control" id="occupation" name="occupation" required value="<?= e($old['occupation'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label required" for="dependent_children">No. of dependent children below 18</label><input class="form-control" type="number" min="0" max="30" step="1" id="dependent_children" name="dependent_children" required value="<?= e($old['dependent_children'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label required" for="living_type">Type of Living</label><input class="form-control" id="living_type" name="living_type" placeholder="Owned, rented, or living with family" required value="<?= e($old['living_type'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label required" for="beneficiary_name">Name of Beneficiary</label><input class="form-control" id="beneficiary_name" name="beneficiary_name" required value="<?= e($old['beneficiary_name'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label required" for="beneficiary_address">Beneficiary Address</label><input class="form-control" id="beneficiary_address" name="beneficiary_address" required value="<?= e($old['beneficiary_address'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required" for="is_pensioner">Receiving financial support</label><select class="form-select" id="is_pensioner" name="is_pensioner" required><option value="">Choose option</option><option<?= ($old['is_pensioner'] ?? '') === 'Yes' ? ' selected' : '' ?>>Yes</option><option<?= ($old['is_pensioner'] ?? '') === 'No' ? ' selected' : '' ?>>No</option></select></div>
            <div class="col-md-8"><label class="form-label" for="pension_source">Name of pension received</label><input class="form-control" id="pension_source" name="pension_source" placeholder="Required if you answered Yes" value="<?= e($old['pension_source'] ?? '') ?>"></div>

            </div></fieldset><fieldset class="registration-section col-12"><legend class="form-section-title"><span class="section-number" aria-hidden="true">4</span>In Case of Emergency</legend><div class="row g-3">
            <div class="col-md-4"><label class="form-label required" for="emergency_contact_name">Name</label><input class="form-control" id="emergency_contact_name" name="emergency_contact_name" required value="<?= e($old['emergency_contact_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required" for="emergency_contact_address">Address</label><input class="form-control" id="emergency_contact_address" name="emergency_contact_address" required value="<?= e($old['emergency_contact_address'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required" for="emergency_contact_phone">Contact Number</label><input class="form-control" type="tel" id="emergency_contact_phone" name="emergency_contact_phone" required value="<?= e($old['emergency_contact_phone'] ?? '') ?>"></div>

            </div></fieldset><fieldset class="registration-section col-12"><legend class="form-section-title"><span class="section-number" aria-hidden="true">5</span>Account</legend><div class="row g-3">
            <div class="col-md-6"><label class="form-label required" for="photo">1x1 Photo</label><input class="form-control" type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required><div class="form-text">JPG, PNG, or WebP; maximum file size is 2 MB.</div></div>
            <div class="col-md-6"><label class="form-label required" for="valid_id">Valid Identification</label><input class="form-control" type="file" id="valid_id" name="valid_id" accept=".jpg,.jpeg,.png,.pdf" required><div class="form-text">JPG, PNG, or PDF only; maximum file size is 2 MB.</div></div>
            <div class="col-12"><label class="form-label required" for="email">Email Address</label><input class="form-control" type="email" id="email" name="email" required value="<?= e($old['email'] ?? '') ?>"><div class="form-text">This email is the account you will use to sign in.</div></div>
            <div class="col-md-6">
                <label class="form-label required" for="password">Password</label>
                <div class="password-field">
                    <input class="form-control" type="password" id="password" name="password" autocomplete="new-password" aria-describedby="password-help" minlength="8" required>
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
                <p class="form-text mb-0" id="password-help">Use at least 8 characters.</p>
            </div>
            <div class="col-md-6">
                <label class="form-label required" for="password_confirmation">Confirm Password</label>
                <div class="password-field">
                    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="8" required>
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="password_confirmation" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
            </div>
            </div></fieldset>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" value="1" id="terms" name="terms_accepted" <?= ($old['terms_accepted'] ?? '') === '1' ? 'checked' : '' ?> required><label class="form-check-label" for="terms">I certify that the information I provided is true and agree to the processing of my details for senior citizen assistance services.</label></div></div>

            <div class="col-12 d-flex flex-column flex-sm-row gap-2 mt-2"><button type="submit" class="btn btn-main flex-fill">Submit Registration</button><?php if (!$embed): ?><a href="login.php" class="btn btn-main-outline flex-fill">Back to Login</a><?php endif; ?></div>
            <?php if (!$embed): ?><div class="col-12 text-center"><a href="index.php">Back to home</a></div><?php endif; ?>
        </form>
    </section>
</main>

<script>
const registrationId = document.querySelector('[name="valid_id"]');
registrationId.addEventListener('change', () => {
    const file = registrationId.files[0];
    registrationId.setCustomValidity(file && file.size > <?= MAX_UPLOAD_BYTES ?>
        ? 'Your ID file is too large. Choose a JPG, PNG, or PDF no larger than 2 MB.' : '');
    registrationId.reportValidity();
});
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        button.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


