<?php
// Legacy registration verification links now open the registration form directly.
require_once __DIR__ . '/includes/functions.php';
unset($_SESSION['registration_verified'], $_SESSION['registration_otp'], $_SESSION['google_registration']);
redirect('/register.php');
