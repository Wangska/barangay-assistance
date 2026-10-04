<?php
if (PHP_SAPI !== 'cli') exit;
putenv('BASE_URL=/');
$port=random_int(25000,29000);
$root=dirname(__DIR__);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root],[0=>['pipe','r'],1=>['file',__DIR__.'/registration-server.log','a'],2=>['file',__DIR__.'/registration-server.log','a']],$pipes,$root);
if (!is_resource($server)) throw new RuntimeException('Test server failed');
fclose($pipes[0]);
register_shutdown_function(function() use ($server) { proc_terminate($server); proc_close($server); });
usleep(500000);
$base='http://127.0.0.1:'.$port;
$context=stream_context_create(['http'=>['follow_location'=>0,'ignore_errors'=>true,'timeout'=>10]]);
$body=file_get_contents($base.'/register.php',false,$context);
if (!in_array('Location: /register_verify.php',$http_response_header,true)) throw new RuntimeException('Registration gate did not redirect');
echo "PASS Registration HTTP route requires verification\n";
$body=file_get_contents($base.'/register_verify.php',false,$context);
if (!str_contains($body,'Send Verification Code')) throw new RuntimeException('OTP page missing');
echo "PASS Email verification page renders\n";
$context=stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>'action=verify&code=123456&csrf_token=bad','follow_location'=>0,'ignore_errors'=>true,'timeout'=>10]]);
file_get_contents($base.'/register_verify.php',false,$context);
if (!str_contains($http_response_header[0],'419')) throw new RuntimeException('CSRF bypass');
echo "PASS HTTP verification rejects invalid CSRF\n";

