<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../sms/sms_gateway.php';

function render_sms_page(array $roles): void
{
    $user = require_role($roles);
    $returnPath = str_contains($_SERVER['REQUEST_URI'] ?? '', '/staff/') ? '/staff/sms.php' : '/admin/sms.php';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if (!valid_philippine_mobile($phoneNumber) || $message === '' || strlen($message) > 480) {
            flash('danger', 'Enter a valid Philippine mobile number and a message of up to 480 characters.');
            redirect($returnPath);
        }
        $result = send_sms_with_status(null, $phoneNumber, $message);
        audit_log((int) $user['user_id'], 'Sent manual SMS', 'sms_logs');
        flash($result['ok'] ? 'success' : 'warning', $result['ok'] ? 'SMS sent.' : 'SMS could not be sent. Check the gateway configuration and SMS logs.');
        redirect($returnPath);
    }
    $rows = db()->query('SELECT phone_number, message, status, sent_at FROM sms_logs WHERE user_id IS NULL ORDER BY sent_at DESC, sms_id DESC LIMIT 200')->fetchAll();
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/sidebar.php';
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="page-title mb-0">Send SMS</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#sms-modal">Send SMS</button>
    </div>
    <div class="panel table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Phone</th><th>Message</th><th>Status</th><th>Sent</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="4" class="text-muted">No messages sent yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="text-nowrap"><?= e($row['phone_number']) ?></td>
                    <td style="white-space:pre-wrap"><?= e($row['message']) ?></td>
                    <td><?= e($row['status']) ?></td>
                    <td class="text-nowrap"><?= e(date('M j, Y g:i A', strtotime($row['sent_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="modal fade" id="sms-modal" tabindex="-1" aria-labelledby="sms-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="sms-modal-title">Send SMS</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body row g-3">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="col-12"><label class="form-label" for="sms-phone">Phone number</label><input class="form-control" id="sms-phone" name="phone_number" inputmode="numeric" required></div>
                    <div class="col-12"><label class="form-label" for="sms-message">Message</label><textarea class="form-control" id="sms-message" name="message" rows="5" maxlength="480" required></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary">Send SMS</button>
                </div>
            </form>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
}
