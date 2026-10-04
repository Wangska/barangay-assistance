<?php
declare(strict_types=1);
require_once __DIR__.'/registration_otp.php';

// Local credentials live outside the web root; server environment takes precedence.
$googleCredentialsPath = getenv('GOOGLE_CREDENTIALS_FILE') ?: UPLOAD_DIR . '/google-oauth.json';
if (is_readable($googleCredentialsPath)) {
    $googleCredentials = json_decode(file_get_contents($googleCredentialsPath), true);
    $googleWeb = $googleCredentials['web'] ?? [];
    foreach (['GOOGLE_CLIENT_ID'=>'client_id', 'GOOGLE_CLIENT_SECRET'=>'client_secret'] as $setting=>$field) {
        if (!getenv($setting) && is_string($googleWeb[$field] ?? null)) {
            putenv($setting . '=' . $googleWeb[$field]);
        }
    }
    if (!getenv('GOOGLE_REDIRECT_URI') && count($googleWeb['redirect_uris'] ?? []) === 1) {
        putenv('GOOGLE_REDIRECT_URI=' . $googleWeb['redirect_uris'][0]);
    }
    unset($googleCredentials, $googleWeb);
}
unset($googleCredentialsPath);

function google_registration_ready(): bool
{
    $uri = (string)getenv('GOOGLE_REDIRECT_URI');
    $parts = parse_url($uri);
    $secure = ($parts['scheme'] ?? '') === 'https'
        || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost','127.0.0.1'], true));
    return getenv('GOOGLE_CLIENT_ID') && getenv('GOOGLE_CLIENT_SECRET') && $secure && function_exists('curl_init');
}

function google_registration_state_valid(array $pending, string $state): bool
{
    return $state !== '' && isset($pending['state'],$pending['expires_at'],$pending['verifier'])
        && (int)$pending['expires_at'] > time() && hash_equals($pending['state'], $state);
}

function google_registration_email(array $profile): string
{
    if (empty($profile['sub']) || ($profile['email_verified'] ?? false) !== true
        || !is_string($profile['email'] ?? null) || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)
        || strlen($profile['email']) > 150) {
        throw new RuntimeException('Google did not provide a verified email.');
    }
    return registration_email($profile['email']);
}

function google_registration_request(string $url, ?array $fields = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2];
    if ($fields !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    if ($token !== null) {
        if (preg_match('/[\r\n]/', $token)) throw new RuntimeException('Invalid access token.');
        $options[CURLOPT_HTTPHEADER] = ['Authorization: Bearer '.$token];
    }
    curl_setopt_array($ch,$options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) throw new RuntimeException('Google request failed.');
    $data = json_decode($body,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid Google response.');
    return $data;
}

