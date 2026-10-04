<?php
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if ($user) {
    $home = [
        'Administrator' => '/admin/dashboard.php',
        'Barangay Staff' => '/staff/dashboard.php',
        'Senior Citizen' => '/senior/dashboard.php',
    ][$user['role_name']] ?? '/login.php';
    redirect($home);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SeniorCare Barangay Services</title>
    <link rel="stylesheet" href="assets/css/landing.css?v=20261004c">
    <style>
        dialog.registration-dialog:modal {
            box-sizing: border-box;
            width: min(1120px, calc(100vw - 32px));
            height: min(920px, calc(100vh - 32px));
            max-width: calc(100vw - 32px);
            max-height: calc(100vh - 32px);
            margin: auto;
            padding: 0;
            border: 0;
        }
        dialog.registration-dialog:modal iframe {
            width: 100%;
            height: calc(100% - 52px);
            min-height: 0;
            border: 0;
        }
        dialog#signin-dialog:modal {
            width: min(1080px, calc(100vw - 32px));
            height: min(700px, calc(100vh - 32px));
            max-height: calc(100vh - 32px);
        }
        @media (max-width: 620px) {
            dialog.registration-dialog:modal,
            dialog#signin-dialog:modal {
                width: 100vw;
                height: 100vh;
                max-width: 100vw;
                max-height: 100vh;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
<header>
    <a class="logo" href="#home"><span class="logo-mark">SC</span><span>SeniorCare <small>Barangay Services</small></span></a>
    <nav aria-label="Main navigation">
        <a href="#home">Home</a>
        <a href="#about">About</a>
        <a href="#services">Services</a>
        <a href="login.php" class="login-btn" data-open-signin>Sign in <span>&rarr;</span></a>
    </nav>
</header>

<main>
    <section class="hero" id="home">
        <div class="hero-text">
            <p class="eyebrow"><span></span> A more caring way to connect</p>
            <h1>Support that feels <span>close to home.</span></h1>
            <p class="hero-copy">A simple, secure space for senior citizens to request assistance, manage their profile, and stay connected to barangay care.</p>
            <div class="buttons">
                <a href="register.php" class="btn-primary" data-open-registration>Register as a Senior <span>&rarr;</span></a>
                <a href="#services" class="btn-secondary">Explore services</a>
            </div>
            <div class="trust-row" aria-label="System benefits">
                <span><b>&check;</b> Simple to use</span>
                <span><b>&check;</b> Secure records</span>
                <span><b>&check;</b> Barangay connected</span>
            </div>
        </div>

        <div class="hero-image">
            <div class="image-orbit orbit-one"></div>
            <div class="image-orbit orbit-two"></div>
            <img src="assets/images/g.jpg" alt="Senior citizen receiving support">
            <div class="floating-card card-assist"><span class="float-icon">&starf;</span><div><strong>Assistance made easy</strong><small>Request support online</small></div></div>
            <div class="floating-card card-update"><span class="pulse-dot"></span><div><strong>Stay updated</strong><small>News and activities</small></div></div>
        </div>
    </section>

    <section id="about" class="section about-section">
        <div class="section-intro">
            <p class="eyebrow"><span></span> Built with care</p>
            <h2>Your barangay support,<br><span>all in one place.</span></h2>
        </div>
        <p class="about-copy">SeniorCare makes it easier for older persons and barangay staff to coordinate assistance, health and welfare updates, requests, and scheduled services without unnecessary trips or paperwork.</p>
    </section>

    <section id="services" class="section services-section">
        <div class="services-heading"><div><p class="eyebrow"><span></span> What we can help with</p><h2>Care, support, and <span>connection.</span></h2></div><a href="login.php" data-open-signin>Member sign in <span>&rarr;</span></a></div>
        <div class="cards">
            <article class="card"><span class="service-icon medical">+</span><p class="service-number">01</p><h3>Medical Assistance</h3><p>Submit medicine, checkup, and health support requests from home.</p><a href="register.php" data-open-registration aria-label="Register for medical assistance">Learn more <span>&rarr;</span></a></article>
            <article class="card"><span class="service-icon finance">PHP</span><p class="service-number">02</p><h3>Financial Assistance</h3><p>Request guidance and support for eligible barangay aid programs.</p><a href="register.php" data-open-registration aria-label="Register for financial assistance">Learn more <span>&rarr;</span></a></article>
            <article class="card"><span class="service-icon visit">H</span><p class="service-number">03</p><h3>Home Visits &amp; Referrals</h3><p>Request a home visit or referral to the right support service.</p><a href="register.php" data-open-registration aria-label="Register for a home visit">Learn more <span>&rarr;</span></a></article>
            <article class="card"><span class="service-icon news">*</span><p class="service-number">04</p><h3>Updates &amp; Activities</h3><p>Keep up with senior programs, announcements, and community events.</p><a href="login.php" data-open-signin aria-label="Sign in to view updates">Learn more <span>&rarr;</span></a></article>
        </div>
    </section>
</main>

<footer>
    <span>&copy; <?= date('Y') ?> SeniorCare Barangay Services</span>
    <span>Serving seniors with dignity and care.</span>
</footer>
<dialog id="registration-dialog" class="registration-dialog" aria-label="Senior citizen registration">
    <div class="registration-dialog-bar">
        <button type="button" class="registration-close" data-close-dialog aria-label="Close registration">&times;</button>
    </div>
    <iframe title="Senior citizen registration form"></iframe>
</dialog>
<dialog id="signin-dialog" class="registration-dialog" aria-label="Sign in">
    <div class="registration-dialog-bar">
        <button type="button" class="registration-close" data-close-dialog aria-label="Close sign in">&times;</button>
    </div>
    <iframe title="Sign in form"></iframe>
</dialog>
<script>
(() => {
    const bindDialog = (dialog, src, openSelector) => {
        const frame = dialog.querySelector('iframe');
        const open = () => {
            document.querySelectorAll('dialog[open]').forEach((other) => {
                if (other !== dialog) other.close();
            });
            if (!frame.getAttribute('src')) frame.src = src;
            dialog.showModal();
        };
        document.querySelectorAll(openSelector).forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                open();
            });
        });
        dialog.querySelector('[data-close-dialog]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            const bounds = dialog.getBoundingClientRect();
            const inside = event.clientX >= bounds.left && event.clientX <= bounds.right && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
            if (!inside) dialog.close();
        });
        return open;
    };
    const openRegistration = bindDialog(document.getElementById('registration-dialog'), 'register.php?embed=1', '[data-open-registration]');
    bindDialog(document.getElementById('signin-dialog'), 'login.php?embed=1', '[data-open-signin]');
    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin || event.data?.type !== 'open-registration') return;
        openRegistration();
    });
})();
</script>
</body>
</html>
