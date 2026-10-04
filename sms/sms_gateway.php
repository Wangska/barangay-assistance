<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

function textbee_recipient(string $phoneNumber): string
{
    $phoneNumber = preg_replace('/\s+/', '', $phoneNumber) ?? '';
    if (preg_match('/^09\d{9}$/', $phoneNumber) === 1) {
        return '+63' . substr($phoneNumber, 1);
    }
    if (preg_match('/^\+639\d{9}$/', $phoneNumber) === 1) {
        return $phoneNumber;
    }
    return '';
}

function send_sms_with_status(?int $userId, string $phoneNumber, string $message): array
{
    $status = 'Failed: SMS gateway is not configured';
    $http = 0;
    $response = '';

    $recipient = textbee_recipient($phoneNumber);
    if (function_exists('curl_init') && TEXTBEE_API_KEY !== '' && TEXTBEE_DEVICE_ID !== '') {
        if ($recipient === '') {
            $status = 'Failed: invalid phone number';
        } else {
            $payload = json_encode([
                'recipients' => [$recipient],
                'message' => $message,
                'deviceId' => TEXTBEE_DEVICE_ID,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $ch = curl_init(TEXTBEE_API_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . TEXTBEE_API_KEY,
                ],
            ]);
            $response = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            $status = ($response !== false && $http >= 200 && $http < 300) ? 'Sent' : ($error !== '' ? 'Failed: ' . $error : 'Failed: HTTP ' . $http);
        }
    }

    $status = mb_substr($status, 0, 140);
    db()->prepare('INSERT INTO sms_logs (user_id, phone_number, message, status, sent_at) VALUES (?, ?, ?, ?, NOW())')->execute([$userId, $phoneNumber, $message, $status]);
    return ['ok' => $status === 'Sent', 'status' => $status, 'http_code' => $http, 'response' => is_string($response) ? $response : ''];
}

function send_sms(?int $userId, string $phoneNumber, string $message): bool
{
    return send_sms_with_status($userId, $phoneNumber, $message)['ok'];
}
