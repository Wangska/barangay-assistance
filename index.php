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
    <title>Senior Assist — Barangay Tungkil</title>
    <link rel="stylesheet" href="assets/css/landing.css?v=20261027l">
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
            height: 100%;
            min-height: 0;
            border: 0;
        }
        dialog#signin-dialog:modal {
            width: min(1080px, calc(100vw - 32px));
            height: min(700px, calc(100vh - 32px));
            max-height: calc(100vh - 32px);
        }
        dialog#signin-dialog:modal iframe {
            height: 100%;
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
    <a class="logo" href="#home"><img class="logo-mark" src="assets/images/seniorlogo.png" alt=""><span>Senior Assist <small>Barangay Tungkil</small></span></a>
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
        <div>
            <p class="about-copy">Senior Assist makes it easier for older persons and barangay staff to coordinate assistance, health and welfare updates, requests, and scheduled services without unnecessary trips or paperwork.</p>
            <p class="about-note">A senior registers once with personal and barangay details. After the account is active, they sign in to send a request, follow its status, and read announcements from home.</p>
            <ul class="about-points">
                <li><b>&check;</b> Request medical, financial, or home-visit help from home</li>
                <li><b>&check;</b> Follow each request and its upcoming schedule</li>
                <li><b>&check;</b> Read barangay announcements and community activities</li>
            </ul>
        </div>
        <ol class="about-steps">
            <li><strong>Register</strong><p>Create a senior account with your name, contact details, address, and a valid ID for review.</p></li>
            <li><strong>Request help</strong><p>Sign in and describe the medical, financial, or home-visit support you need.</p></li>
            <li><strong>Stay updated</strong><p>See the request status, any schedule, and barangay announcements in the same account.</p></li>
        </ol>
    </section>

    <section id="services" class="section services-section">
        <div class="services-heading"><div><p class="eyebrow"><span></span> What we can help with</p><h2>Care, support, and <span>connection.</span></h2></div><a href="login.php" data-open-signin>Member sign in <span>&rarr;</span></a></div>
        <div class="cards">
            <article class="card"><span class="service-icon medical">+</span><h3>Medical Assistance</h3><p>Submit medicine, checkup, and health support requests from home.</p><a href="services.php#medical">Learn more <span>&rarr;</span></a></article>
            <article class="card"><span class="service-icon finance">PHP</span><h3>Financial Assistance</h3><p>Request guidance and support for eligible barangay aid programs.</p><a href="services.php#financial">Learn more <span>&rarr;</span></a></article>
            <article class="card"><span class="service-icon news">*</span><h3>Updates &amp; Activities</h3><p>Keep up with senior programs, announcements, and community events.</p><a href="services.php#updates">Learn more <span>&rarr;</span></a></article>
        </div>
    </section>
</main>

<footer>
    <span>&copy; <?= date('Y') ?> Senior Assist · Barangay Tungkil</span>
    <span>Serving seniors with dignity and care.</span>
</footer>
<dialog id="registration-dialog" class="registration-dialog" aria-label="Senior citizen registration">
    <button type="button" class="registration-close" data-close-dialog aria-label="Close registration">&times;</button>
    <iframe title="Senior citizen registration form"></iframe>
</dialog>
<dialog id="signin-dialog" class="registration-dialog" aria-label="Sign in">
    <button type="button" class="registration-close" data-close-dialog aria-label="Close sign in">&times;</button>
    <iframe title="Sign in form"></iframe>
</dialog>
<dialog id="registered-dialog" class="registered-dialog" aria-labelledby="registered-title">
    <button type="button" class="registration-close" data-close-registered aria-label="Close">&times;</button>
    <img src="assets/images/seniorlogo.png" alt="">
    <h2 id="registered-title">Successfully registered</h2>
    <p>Your senior account was submitted for barangay review. You can close this window and sign in from the home page when you want to check its status.</p>
    <button type="button" class="registered-done" data-close-registered>Done</button>
</dialog>
<script>
(() => {
    const bindDialog = (dialog, src, openSelector) => {
        const frame = dialog.querySelector('iframe');
        const open = () => {
            document.querySelectorAll('dialog[open]').forEach((other) => {
                if (other !== dialog) other.close();
            });
            const current = frame.getAttribute('src');
            if (!current || current === 'about:blank') frame.src = src;
            dialog.showModal();
        };
        document.querySelectorAll(openSelector).forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                open();
            });
        });
        dialog.querySelector('[data-close-dialog]')?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            const bounds = dialog.getBoundingClientRect();
            const inside = event.clientX >= bounds.left && event.clientX <= bounds.right && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
            if (!inside) dialog.close();
        });
        return open;
    };
    const registrationDialog = document.getElementById('registration-dialog');
    const registeredDialog = document.getElementById('registered-dialog');
    const openRegistration = bindDialog(registrationDialog, 'register.php?embed=1', '[data-open-registration]');
    const openSignin = bindDialog(document.getElementById('signin-dialog'), 'login.php?embed=1', '[data-open-signin]');
    let registeredTimer;
    const openRegistered = () => {
        if (registrationDialog.open) registrationDialog.close();
        registrationDialog.querySelector('iframe').src = 'about:blank';
        registeredDialog.showModal();
        clearTimeout(registeredTimer);
        registeredTimer = setTimeout(() => {
            if (registeredDialog.open) registeredDialog.close();
        }, 4000);
    };
    registeredDialog.querySelectorAll('[data-close-registered]').forEach((button) => {
        button.addEventListener('click', () => registeredDialog.close());
    });
    registeredDialog.addEventListener('click', (event) => {
        const bounds = registeredDialog.getBoundingClientRect();
        const inside = event.clientX >= bounds.left && event.clientX <= bounds.right && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        if (!inside) registeredDialog.close();
    });
    if (location.hash === '#signin') openSignin();
    if (location.hash === '#register') openRegistration();
    if (new URLSearchParams(location.search).get('registered') === '1') openRegistered();
    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin) return;
        if (event.data?.type === 'open-registration') openRegistration();
        if (event.data?.type === 'registration-success') openRegistered();
    });
})();
</script>
</body>
</html>
