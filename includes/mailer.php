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

function app_send_mail(string $email, string $subject, string $body): bool
{
    if (!app_mail_ready() || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $email . $subject)) {
        return false;
    }
    $config = app_mail_config();
    if (!$config) {
        return mail($email, $subject, $body,
            ['From' => getenv('PASSWORD_RESET_FROM'), 'Content-Type' => 'text/plain; charset=UTF-8']);
    }
    $stream = tmpfile();
    if ($stream === false) return false;
    $curl = null;
    try {
        $message = 'Date: ' . date(DATE_RFC2822) . "\r\n"
            . 'From: SeniorCare <' . $config['from'] . ">\r\n"
            . 'To: <' . $email . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n");
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