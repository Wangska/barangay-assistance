<?php
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services — Senior Assist</title>
    <link rel="stylesheet" href="assets/css/landing.css?v=20261027l">
</head>
<body>
<header>
    <a class="logo" href="index.php"><img class="logo-mark" src="assets/images/seniorlogo.png" alt=""><span>Senior Assist <small>Barangay Tungkil</small></span></a>
    <nav aria-label="Main navigation">
        <a href="index.php#home">Home</a>
        <a href="index.php#about">About</a>
        <a href="index.php#services">Services</a>
        <a href="index.php#signin" class="login-btn" data-open-signin>Sign in <span>&rarr;</span></a>
    </nav>
</header>
<main class="section service-page">
    <p class="eyebrow"><span></span> Barangay services</p>
    <h2>What a senior can request <span>and follow.</span></h2>
    <p class="about-copy">Each service below is something a registered senior can do after signing in. Barangay staff review the request and post the next update on the account.</p>

    <section class="service-block" id="medical">
        <h2>Medical assistance</h2>
        <p>Use this when you need help with medicine, a checkup, or other health support from the barangay. You describe what you need from home instead of making a trip only to ask.</p>
        <ul>
            <li><b>&check;</b> Choose Medical Assistance or Medicine Support when you submit a request.</li>
            <li><b>&check;</b> Write what you need so staff know how to review it.</li>
            <li><b>&check;</b> Follow the status, and see the schedule if a visit or release is set.</li>
        </ul>
        <div class="service-actions"><a class="btn-primary" href="index.php#register" data-open-registration>Register as a senior <span>&rarr;</span></a><a class="btn-secondary" href="index.php#services">Back to services</a></div>
    </section>

    <section class="service-block" id="financial">
        <h2>Financial assistance</h2>
        <p>Use this to ask about barangay aid you may qualify for. The request tells staff what kind of support you are seeking so they can review it and reply with the next step.</p>
        <ul>
            <li><b>&check;</b> Choose Financial Assistance and describe your situation.</li>
            <li><b>&check;</b> Staff review the request before any aid is scheduled or released.</li>
            <li><b>&check;</b> Check your account for the status and any remarks.</li>
        </ul>
        <div class="service-actions"><a class="btn-primary" href="index.php#register" data-open-registration>Register as a senior <span>&rarr;</span></a><a class="btn-secondary" href="index.php#services">Back to services</a></div>
    </section>

    <section class="service-block" id="updates">
        <h2>Updates and activities</h2>
        <p>Announcements and community activities are posted by the barangay. After you sign in, you can read them without waiting for a paper notice or a trip to the hall.</p>
        <ul>
            <li><b>&check;</b> Open Announcements for health missions, programs, and other notices.</li>
            <li><b>&check;</b> Open Events for the date and location of a community activity.</li>
            <li><b>&check;</b> Important notices can also appear in your account notifications.</li>
        </ul>
        <div class="service-actions"><a class="btn-primary" href="index.php#signin" data-open-signin>Sign in to view updates <span>&rarr;</span></a><a class="btn-secondary" href="index.php#services">Back to services</a></div>
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
    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin) return;
        if (event.data?.type === 'open-registration') openRegistration();
        if (event.data?.type === 'registration-success') openRegistered();
    });
})();
</script>
</body>
</html>
