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
        'civil_status', 'blood_type', 'religion', 'education', 'employment_status',
        'classification', 'emergency_contact_name', 'emergency_contact_phone', 'terms_accepted',
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

    $fullName = implode(' ', array_filter([
        trim($_POST['first_name']), trim($_POST['middle_name'] ?? ''), trim($_POST['last_name']),
    ]));

    $role = db()->prepare("SELECT role_id FROM roles WHERE role_name = 'Senior Citizen'");
    $role->execute();
    $roleId = (int) $role->fetchColumn();
    $pdo = db();
    try {
        $idPath = upload_valid_id($_FILES['valid_id'] ?? []);
        if ($idPath === null) {
            throw new RuntimeException('Please select a valid identification file.');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage() . ' Your other details have been kept; please re-enter your password and select your ID again.');
        redirect($registrationReturn);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO users (role_id, full_name, email, phone_number, password_hash, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$roleId, $fullName, $email, $phoneNumber, password_hash($_POST['password'], PASSWORD_DEFAULT), 'Pending']);
        $userId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('
            INSERT INTO senior_citizens (
                user_id, first_name, middle_name, last_name, birthdate, gender, region, province,
                city_municipality, barangay, address, place_of_birth, civil_status, blood_type, religion,
                education, telephone, gsis_no, sss_no, tin_no, philhealth_no, employment_status, classification,
                monthly_pension, emergency_contact_name, emergency_contact_phone, valid_id_path,
                medical_notes, terms_accepted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $userId, trim($_POST['first_name']), trim($_POST['middle_name'] ?? ''), trim($_POST['last_name']),
            $birthdate->format('Y-m-d'), $_POST['gender'], 'Region VII (Central Visayas)', 'Cebu',
            'Minglanilla', 'Tungkil', trim($_POST['address']), trim($_POST['place_of_birth']),
            $_POST['civil_status'], $_POST['blood_type'], trim($_POST['religion']), $_POST['education'], trim($_POST['telephone'] ?? ''),
            '', '', '',
            '', $_POST['employment_status'], $_POST['classification'],
            ($_POST['monthly_pension'] ?? '') !== '' ? (float) $_POST['monthly_pension'] : null,
            trim($_POST['emergency_contact_name']), $emergencyPhone, $idPath,
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
<link rel="stylesheet" href="assets/css/register.css?v=20261027a">

<main class="auth-wrap py-5">
    <section class="auth-card p-4 p-md-5 shadow">
        <div class="registration-heading text-center mb-4">
            <span class="registration-kicker">Barangay Senior Citizen Services</span>
            <h1 class="h3 mt-2">Senior Citizen Registration Form</h1>
            <p class="text-muted mb-0">Please provide complete and accurate information for your senior citizen profile.</p>
        </div>

        <form method="post" action="<?= $embed ? 'register.php?embed=1' : 'register.php' ?>" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <?php if ($embed): ?><input type="hidden" name="registration_embed" value="1"><?php endif; ?>

            <div class="col-12"><h2 class="form-section-title">Address</h2></div>
            <div class="col-12"><label class="form-label required" for="address">Purok Address</label><textarea class="form-control" id="address" name="address" rows="2" placeholder="Purok and house number" required><?= e($old['address'] ?? '') ?></textarea><div class="form-text">This registration is for residents of Barangay Tungkil, Minglanilla, Cebu.</div></div>

            <div class="col-12"><h2 class="form-section-title">Personal Information</h2></div>
            <div class="col-md-4"><label class="form-label required">First Name</label><input class="form-control" name="first_name" required value="<?= e($old['first_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Middle Name</label><input class="form-control" name="middle_name" value="<?= e($old['middle_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required">Last Name</label><input class="form-control" name="last_name" required value="<?= e($old['last_name'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label required">Birthdate</label><input class="form-control" type="date" name="birthdate" max="<?= date('Y-m-d') ?>" value="<?= e($old['birthdate'] ?? '') ?>" required></div>
            <div class="col-md-6"><label class="form-label required">Place of Birth</label><input class="form-control" name="place_of_birth" required value="<?= e($old['place_of_birth'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required">Sex</label><select class="form-select" name="gender" required><option value="">Choose option</option><option<?= ($old['gender'] ?? '') === 'Female' ? ' selected' : '' ?>>Female</option><option<?= ($old['gender'] ?? '') === 'Male' ? ' selected' : '' ?>>Male</option></select></div>
            <div class="col-md-4"><label class="form-label required">Civil Status</label><select class="form-select" name="civil_status" required><option value="">Choose option</option><option<?= ($old['civil_status'] ?? '') === 'Single' ? ' selected' : '' ?>>Single</option><option<?= ($old['civil_status'] ?? '') === 'Married' ? ' selected' : '' ?>>Married</option><option<?= ($old['civil_status'] ?? '') === 'Widowed' ? ' selected' : '' ?>>Widowed</option><option<?= ($old['civil_status'] ?? '') === 'Separated' ? ' selected' : '' ?>>Separated</option><option<?= ($old['civil_status'] ?? '') === 'Divorced' ? ' selected' : '' ?>>Divorced</option></select></div>
            <div class="col-md-4"><label class="form-label required">Blood Type</label><select class="form-select" name="blood_type" required><option value="">Choose option</option><option<?= ($old['blood_type'] ?? '') === 'O+' ? ' selected' : '' ?>>O+</option><option<?= ($old['blood_type'] ?? '') === 'O-' ? ' selected' : '' ?>>O-</option><option<?= ($old['blood_type'] ?? '') === 'A+' ? ' selected' : '' ?>>A+</option><option<?= ($old['blood_type'] ?? '') === 'A-' ? ' selected' : '' ?>>A-</option><option<?= ($old['blood_type'] ?? '') === 'B+' ? ' selected' : '' ?>>B+</option><option<?= ($old['blood_type'] ?? '') === 'B-' ? ' selected' : '' ?>>B-</option><option<?= ($old['blood_type'] ?? '') === 'AB+' ? ' selected' : '' ?>>AB+</option><option<?= ($old['blood_type'] ?? '') === 'AB-' ? ' selected' : '' ?>>AB-</option></select></div>
            <div class="col-md-6"><label class="form-label required">Religion</label><input class="form-control" name="religion" required value="<?= e($old['religion'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label required">Highest Educational Attainment</label><select class="form-select" name="education" required><option value="">Choose option</option><option<?= ($old['education'] ?? '') === 'Elementary' ? ' selected' : '' ?>>Elementary</option><option<?= ($old['education'] ?? '') === 'High School' ? ' selected' : '' ?>>High School</option><option<?= ($old['education'] ?? '') === 'Vocational' ? ' selected' : '' ?>>Vocational</option><option<?= ($old['education'] ?? '') === 'College' ? ' selected' : '' ?>>College</option><option<?= ($old['education'] ?? '') === 'Master\'s Degree' ? ' selected' : '' ?>>Master's Degree</option><option<?= ($old['education'] ?? '') === 'Doctoral' ? ' selected' : '' ?>>Doctoral</option></select></div>

            <div class="col-12"><h2 class="form-section-title">Contact and Benefits Information</h2></div>
            <div class="col-md-4"><label class="form-label">Telephone</label><input class="form-control" name="telephone" placeholder="Optional" value="<?= e($old['telephone'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required">Mobile Number</label><input class="form-control" type="tel" name="phone_number" placeholder="e.g., 09171234567" required value="<?= e($old['phone_number'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required">Email Address</label><input class="form-control" type="email" name="email" required value="<?= e($old['email'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label required">Employment Status</label><select class="form-select" name="employment_status" required><option value="">Choose option</option><option<?= ($old['employment_status'] ?? '') === 'Employed' ? ' selected' : '' ?>>Employed</option><option<?= ($old['employment_status'] ?? '') === 'Self-Employed' ? ' selected' : '' ?>>Self-Employed</option><option<?= ($old['employment_status'] ?? '') === 'Unemployed' ? ' selected' : '' ?>>Unemployed</option><option<?= ($old['employment_status'] ?? '') === 'Retired' ? ' selected' : '' ?>>Retired</option></select></div>
            <div class="col-md-4"><label class="form-label required">Classification</label><select class="form-select" name="classification" required><option value="">Choose option</option><option<?= ($old['classification'] ?? '') === 'Indigent' ? ' selected' : '' ?>>Indigent</option><option<?= ($old['classification'] ?? '') === 'Pensioner' ? ' selected' : '' ?>>Pensioner</option><option<?= ($old['classification'] ?? '') === 'Supported' ? ' selected' : '' ?>>Supported</option></select></div>
            <div class="col-md-4"><label class="form-label">Monthly Pension</label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control" type="number" min="0" step="0.01" name="monthly_pension" placeholder="0.00" value="<?= e($old['monthly_pension'] ?? '') ?>"></div></div>

            <div class="col-12"><h2 class="form-section-title">Emergency and Account Details</h2></div>
            <div class="col-md-6"><label class="form-label required">Emergency Contact Person</label><input class="form-control" name="emergency_contact_name" required value="<?= e($old['emergency_contact_name'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label required">Emergency Contact Number</label><input class="form-control" type="tel" name="emergency_contact_phone" required value="<?= e($old['emergency_contact_phone'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label required">Valid Identification</label><input class="form-control" type="file" name="valid_id" accept=".jpg,.jpeg,.png,.pdf" required><div class="form-text">JPG, PNG, or PDF only; maximum file size is 2 MB.</div></div>
            <div class="col-md-3">
                <label class="form-label required" for="password">Password</label>
                <div class="password-field">
                    <input class="form-control" type="password" id="password" name="password" minlength="8" required>
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label required" for="password_confirmation">Confirm Password</label>
                <div class="password-field">
                    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="8" required>
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="password_confirmation" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
            </div>
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


