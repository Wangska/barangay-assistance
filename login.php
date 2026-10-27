<?php
require_once __DIR__ . '/includes/functions.php';

$embed = (($_GET['embed'] ?? '') === '1');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {

        $error = "Please fill in all fields.";

    } elseif (login_is_rate_limited($email)) {

        $error = "Too many failed attempts. Please wait 15 minutes and try again.";

    } else {

        $stmt = db()->prepare("
            SELECT u.*, r.role_name
            FROM users u
            JOIN roles r ON r.role_id = u.role_id
            WHERE u.email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (
            $user &&
            password_verify($password, $user['password_hash']) &&
            ($user['status'] === 'Active' || ($user['role_name'] === 'Senior Citizen' && in_array($user['status'], ['Pending','Rejected'], true)))
        ) {

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['user_id'];
            clear_failed_logins($email);

            audit_log(
                (int) $user['user_id'],
                'Logged in',
                'users',
                (int) $user['user_id']
            );

            $destination = $user['status'] === 'Active' ? '/index.php' : '/senior/dashboard.php';
            if ($embed) {
                $destinationUrl = json_encode(BASE_URL . $destination, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo '<!doctype html><meta charset="utf-8"><script>window.top.location.href = ' . $destinationUrl . ';</script>';
                exit;
            }
            redirect($destination);

        } else {

            record_failed_login($email);
            $error = "Invalid email or password.";

        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
    >
    <link
        rel="stylesheet"
        href="assets/css/login.css?v=20261027j"
    >

</head>

<body class="<?= $embed ? 'login-embed' : '' ?>">

    <div class="background">

        <div class="shape1"></div>

        <div class="shape2"></div>

        <div class="login-photo">
            <img src="assets/images/image.png" alt="Senior citizens using the barangay service">
        </div>

        <div class="logo">
            <img class="logo-mark" src="assets/images/seniorlogo.png" alt="">
            <span>Senior Assist<small>Barangay Tungkil</small></span>
        </div>

        <div class="login-card">

                <img class="card-logo" src="assets/images/seniorlogo.png" alt="Senior Assist">
                <p class="welcome-back">Welcome back</p>

            <?php if ($error): ?>

                <div class="error">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>

            <form method="POST" action="<?= $embed ? 'login.php?embed=1' : 'login.php' ?>">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= csrf_token() ?>"
                >

                <label>Email</label>

                <input
                    type="email"
                    name="email"
                    placeholder="Your email"
                    required
                >

                <label>Password</label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Password"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        onclick="togglePassword()"
                        aria-label="Show password"
                        aria-pressed="false"
                    >
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>

                </div>

                <div class="options">

                    <label>

                        <input type="checkbox">

                        Remember me

                    </label>

                    <a href="forgot_password.php"<?= $embed ? ' target="_top"' : '' ?>>
                        Forgot Password?
                    </a>

                </div>

                <button type="submit">
                    LOGIN
                </button>

                <div class="bottom-links">

                    <p>
                        Don't have an account?

                        <a href="register.php"<?= $embed ? ' id="create-account"' : '' ?>>
                            Create Account
                        </a>
                    </p>

                <?php if (!$embed): ?>
                <div class="back-link">
                    <a href="index.php">Back to home</a>
                </div>
                <?php endif; ?>

                </div>

            </form>

        </div>

    </div>

    <script>

        function togglePassword() {
            const password = document.getElementById("password");
            const button = document.querySelector(".toggle-password");
            const icon = button.querySelector("i");
            const visible = password.type === "password";
            password.type = visible ? "text" : "password";
            icon.className = visible ? "bi bi-eye-slash" : "bi bi-eye";
            button.setAttribute("aria-label", visible ? "Hide password" : "Show password");
            button.setAttribute("aria-pressed", visible ? "true" : "false");
        }

        const createAccount = document.getElementById('create-account');
        if (createAccount) {
            createAccount.addEventListener('click', (event) => {
                event.preventDefault();
                window.parent.postMessage({ type: 'open-registration' }, window.location.origin);
            });
        }

    </script>

</body>

</html>
