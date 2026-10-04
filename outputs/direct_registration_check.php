<?php
if (PHP_SAPI !== 'cli') exit;
$base='http://localhost:8080/barangay-assistance-system';
foreach (['register.php','register_verify.php','register_google.php'] as $page) {
    $ch=curl_init($base.'/'.$page);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10]);
    $body=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $redirect=curl_getinfo($ch,CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if ($page==='register.php') {
        if ($status!==200 || !str_contains($body,'Senior Citizen Registration Form') || str_contains($body,'Continue with Google') || !preg_match('/type="email" name="email" required/',$body)) throw new RuntimeException('Direct registration failed');
    } elseif ($status!==302 || $redirect!==$base.'/register.php') throw new RuntimeException('Legacy redirect failed');
    echo 'PASS '.$page.PHP_EOL;
}
