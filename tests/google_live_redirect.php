<?php
if (PHP_SAPI !== 'cli') exit;
$base='http://localhost:8080/barangay-assistance-system';
$ch=curl_init();
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_URL=>$base.'/register_verify.php']);
$html=curl_exec($ch);
if (!is_string($html) || !preg_match('/name="csrf_token" value="([^"]+)"/',$html,$m)) throw new RuntimeException('Could not read registration form');
$location='';
curl_setopt_array($ch,[CURLOPT_URL=>$base.'/register_google.php',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['csrf_token'=>html_entity_decode($m[1])]),CURLOPT_HEADERFUNCTION=>function($c,$line) use (&$location) {if (stripos($line,'Location:')===0) $location=trim(substr($line,9));return strlen($line);}]);
curl_exec($ch);
$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
curl_close($ch);
$url=parse_url($location);
parse_str($url['query'] ?? '',$params);
if ($status!==302 || ($url['host'] ?? '')!=='accounts.google.com' || ($params['redirect_uri'] ?? '')!==$base.'/register_google.php' || empty($params['state']) || empty($params['code_challenge'])) throw new RuntimeException('Google redirect check failed');
echo "PASS Live registration button redirects to Google with correct callback, state and PKCE\n";
