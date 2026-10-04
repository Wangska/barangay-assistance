<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../sms/sms_gateway.php';
$user = require_role(['Barangay Staff']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if (!valid_philippine_mobile($phoneNumber) || $message === '' || strlen($message) > 480) {
        flash('danger', 'Enter a valid Philippine mobile number and a message of up to 480 characters.');
        redirect('/staff/sms.php');
    }
    $result = send_sms_with_status(null, $phoneNumber, $message);
    audit_log((int)$user['user_id'], 'Sent manual SMS', 'sms_logs');
    flash($result['ok'] ? 'success' : 'warning', $result['ok'] ? 'SMS sent.' : 'SMS could not be sent. Check the gateway configuration and SMS logs.'); redirect('/staff/sms.php');
}
require_once __DIR__ . '/../includes/header.php'; require_once __DIR__ . '/../includes/sidebar.php';
?>
<h1 class="page-title">Send SMS</h1><div class="panel" style="max-width:680px"><form method="post" class="vstack gap-3"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><label class="form-label">Phone Number<input class="form-control" name="phone_number" required></label><label class="form-label">Message<textarea class="form-control" name="message" rows="4" required></textarea></label><button class="btn btn-primary">Send SMS</button></form></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
