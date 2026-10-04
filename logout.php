<?php
require_once __DIR__ . '/includes/functions.php';
if (!empty($_SESSION['user_id'])) {
    audit_log((int) $_SESSION['user_id'], 'Logged out', 'users', (int) $_SESSION['user_id']);
}
session_destroy();
redirect('/index.php');
