<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

// Credentials live in the protected project storage directory and are never returned to the browser.
function app_mail_config(): array
{
    $path = getenv('APP_MAIL_CONFIG') ?: UPLOAD_DIR . '/mail.php';
    $config = is_file($path) ? require $path : [];
    return is_array($config) ? $config : [];
}

function app_mail_ready(): bool
{
    $config = app_mail_config();
    if ($config) {
        return !empty($config['enabled']) && extension_loaded('curl')
            && filter_var($config['from'] ?? '', FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/^[a-zA-Z0-9.-]+$/D', $config['host'] ?? '') === 1
            && in_array($config['encryption'] ?? '', ['tls', 'ssl'], true)
            && (int)($config['port'] ?? 0) > 0 && (int)$config['port'] <= 65535
            && !empty($config['username']) && !empty($config['password']);
    }
    return getenv('PASSWORD_RESET_MAIL_ENABLED') === '1'
        && filter_var(getenv('PASSWORD_RESET_FROM'), FILTER_VALIDATE_EMAIL) !== false;
}

function app_mail_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build plain-text and branded HTML bodies for Senior Assist emails. */
function app_mail_compose(string $heading, array $paragraphs, ?string $highlight = null, string $highlightLabel = ''): array
{
    $textLines = [$heading, ''];
    foreach ($paragraphs as $paragraph) {
        $textLines[] = $paragraph;
        $textLines[] = '';
    }
    if ($highlight !== null && $highlight !== '') {
        if ($highlightLabel !== '') {
            $textLines[] = $highlightLabel . ': ' . $highlight;
        } else {
            $textLines[] = $highlight;
        }
        $textLines[] = '';
    }
    $textLines[] = 'Senior Assist · Barangay Tungkil';
    $text = rtrim(implode("\n", $textLines)) . "\n";

    $blocks = '';
    foreach ($paragraphs as $paragraph) {
        $blocks .= '<p style="margin:0 0 16px;color:#45606f;font-size:16px;line-height:1.65;">'
            . nl2br(app_mail_escape($paragraph)) . '</p>';
    }
    if ($highlight !== null && $highlight !== '') {
        $labelHtml = $highlightLabel !== ''
            ? '<p style="margin:0 0 10px;color:#107a7a;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">'
                . app_mail_escape($highlightLabel) . '</p>'
            : '';
        $blocks .= '<div style="margin:8px 0 20px;padding:18px 16px;text-align:center;background:#eef8f4;border:1px solid #d5e9e0;border-radius:14px;">'
            . $labelHtml
            . '<p style="margin:0;color:#0d3b55;font-size:28px;font-weight:700;letter-spacing:.18em;">'
            . app_mail_escape($highlight) . '</p></div>';
    }

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . app_mail_escape($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f8f7;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f8f7;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #dce9e5;border-radius:18px;overflow:hidden;">'
        . '<tr><td style="padding:18px 24px;background:#0d3b55;color:#ffffff;">'
        . '<table role="presentation" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="vertical-align:middle;padding-right:14px;">'
        . '<img src="cid:senior-assist-logo" width="48" height="48" alt="Senior Assist" style="display:block;width:48px;height:48px;border:0;border-radius:12px;">'
        . '</td>'
        . '<td style="vertical-align:middle;">'
        . '<p style="margin:0;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#a9e2cd;">Barangay Tungkil</p>'
        . '<p style="margin:6px 0 0;font-size:22px;font-weight:700;color:#ffffff;">Senior Assist</p>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 24px 8px;">'
        . '<h1 style="margin:0 0 18px;color:#0d3b55;font-size:22px;line-height:1.3;">' . app_mail_escape($heading) . '</h1>'
        . $blocks
        . '</td></tr>'
        . '<tr><td style="padding:8px 24px 24px;">'
        . '<p style="margin:0;padding-top:16px;border-top:1px solid #e4eeea;color:#78928c;font-size:13px;line-height:1.5;">'
        . 'Senior Assist · Barangay Tungkil<br>Serving seniors with dignity and care.</p>'
        . '</td></tr></table></td></tr></table></body></html>';

    return ['text' => $text, 'html' => $html];
}

/** Small PNG for email headers so Gmail can show the logo without a public URL. */
function app_mail_logo_png(): ?string
{
    $dir = dirname(__DIR__) . '/assets/images';
    foreach (['seniorlogo-email.png', 'seniorlogo.png'] as $file) {
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        $bytes = file_get_contents($path);
        if ($bytes !== false && $bytes !== '') {
            return $bytes;
        }
    }
    return null;
}

function app_send_mail(string $email, string $subject, string $body, ?string $htmlBody = null): bool
{
    if (!app_mail_ready() || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $email . $subject)) {
        return false;
    }
    $config = app_mail_config();
    $html = $htmlBody;
    if ($html === null) {
        $parts = preg_split("/\n\s*\n/", trim($body)) ?: [trim($body)];
        $composed = app_mail_compose($subject, array_values(array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== '')));
        $body = $composed['text'];
        $html = $composed['html'];
    }
    if (!$config) {
        return mail($email, $subject, $body,
            ['From' => getenv('PASSWORD_RESET_FROM'), 'Content-Type' => 'text/plain; charset=UTF-8']);
    }
    $stream = tmpfile();
    if ($stream === false) return false;
    $curl = null;
    try {
        $altBoundary = 'sciams_alt_' . bin2hex(random_bytes(8));
        $relBoundary = 'sciams_rel_' . bin2hex(random_bytes(8));
        $logo = app_mail_logo_png();
        $alternative = '--' . $altBoundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n")
            . '--' . $altBoundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $altBoundary . "--\r\n";
        if ($logo !== null) {
            $content = '--' . $relBoundary . "\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $altBoundary . "\"\r\n\r\n"
                . $alternative
                . '--' . $relBoundary . "\r\n"
                . "Content-Type: image/png; name=\"seniorlogo.png\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-ID: <senior-assist-logo>\r\n"
                . "Content-Disposition: inline; filename=\"seniorlogo.png\"\r\n\r\n"
                . chunk_split(base64_encode($logo), 76, "\r\n")
                . '--' . $relBoundary . "--\r\n";
            $contentType = 'multipart/related; type="multipart/alternative"; boundary="' . $relBoundary . '"';
        } else {
            $content = $alternative;
            $contentType = 'multipart/alternative; boundary="' . $altBoundary . '"';
        }
        $message = 'Date: ' . date(DATE_RFC2822) . "\r\n"
            . 'From: Senior Assist <' . $config['from'] . ">\r\n"
            . 'To: <' . $email . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . 'Content-Type: ' . $contentType . "\r\n\r\n"
            . $content;
        fwrite($stream, $message);
        rewind($stream);
        $scheme = $config['encryption'] === 'ssl' ? 'smtps' : 'smtp';
        $curl = curl_init($scheme . '://' . $config['host'] . ':' . (int)$config['port']);
        curl_setopt_array($curl, [
            CURLOPT_USERNAME => $config['username'],
            CURLOPT_PASSWORD => $config['password'],
            CURLOPT_MAIL_FROM => '<' . $config['from'] . '>',
            CURLOPT_MAIL_RCPT => ['<' . $email . '>'],
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => strlen($message),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $sent = curl_exec($curl) !== false;
        if (!$sent) error_log('SMTP delivery failed (transport error ' . curl_errno($curl) . ').');
        return $sent;
    } finally {
        if ($curl !== null) curl_close($curl);
        fclose($stream);
    }
}

function app_send_composed_mail(string $email, string $subject, string $heading, array $paragraphs, ?string $highlight = null, string $highlightLabel = ''): bool
{
    $composed = app_mail_compose($heading, $paragraphs, $highlight, $highlightLabel);
    return app_send_mail($email, $subject, $composed['text'], $composed['html']);
}

function registration_mail_name(string $name): string
{
    $clean = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    return $clean !== '' ? $clean : 'Senior';
}

function send_registration_under_review_mail(string $email, string $name): bool
{
    $who = registration_mail_name($name);
    return app_send_composed_mail(
        $email,
        'Senior Assist registration received',
        'Your registration is under review',
        [
            "Hello {$who},",
            'Thank you for registering with Senior Assist for Barangay Tungkil.',
            'Your account is currently under review by the barangay. You can sign in to check your status while we verify your details and valid ID.',
            'You will receive another email when your registration is approved or if we need more information.',
        ]
    );
}

function send_registration_approved_mail(string $email, string $name): bool
{
    $who = registration_mail_name($name);
    return app_send_composed_mail(
        $email,
        'Welcome to Senior Assist — Barangay Tungkil',
        'Welcome to Senior Assist',
        [
            "Hello {$who},",
            'Your senior citizen registration for Barangay Tungkil has been approved.',
            'You may now sign in to request assistance, book appointments, read announcements, and view your assistance card.',
            'If you did not create this account, please contact the barangay office.',
        ]
    );
}

function send_registration_rejected_mail(string $email, string $name, string $reason): bool
{
    $who = registration_mail_name($name);
    $reasonText = trim(preg_replace("/[\r\n]+/", ' ', $reason) ?? '');
    if ($reasonText === '') {
        $reasonText = 'Please contact the barangay office for more information.';
    }
    return app_send_composed_mail(
        $email,
        'Senior Assist registration update',
        'Registration was not approved',
        [
            "Hello {$who},",
            'Your senior citizen registration was not approved.',
            'Reason: ' . $reasonText,
            'You can sign in to review the decision and submit updated documents if needed.',
        ]
    );
}
