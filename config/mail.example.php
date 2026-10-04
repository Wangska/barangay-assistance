<?php
// Private Gmail settings: do not commit this file; keep it in the protected storage directory.
// Enable Google 2-Step Verification, then create an App Password:
// https://myaccount.google.com/apppasswords
return [
    'enabled' => false, // Change to true after filling in the three fields below.
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls',
    'from' => '',     // Your full Gmail address.
    'username' => '', // The same Gmail address.
    'password' => '', // Google App Password (16 characters, without spaces).
];