<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/google_registration.php';
function check($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$p=['state'=>'expected','verifier'=>'secret','expires_at'=>time()+600];
check(google_registration_state_valid($p,'expected'),'Matching state accepted');
check(!google_registration_state_valid($p,'wrong'),'Wrong state rejected');
check(!google_registration_state_valid([],''),'Missing state rejected');
$p['expires_at']=time()-1;
check(!google_registration_state_valid($p,'expected'),'Expired state rejected');
check(google_registration_email(['sub'=>'123','email'=>'Senior@example.com','email_verified'=>true])==='senior@example.com','Verified Google email normalized');
foreach ([
    ['sub'=>'123','email'=>'senior@example.com','email_verified'=>false],
    ['sub'=>'123','email'=>'senior@example.com','email_verified'=>'true'],
    ['email'=>'senior@example.com','email_verified'=>true],
    ['sub'=>'123','email'=>'invalid','email_verified'=>true],
] as $profile) {
    $rejected=false;
    try { google_registration_email($profile); } catch (RuntimeException $e) { $rejected=true; }
    check($rejected,'Invalid identity rejected');
}
putenv('GOOGLE_CLIENT_ID='); putenv('GOOGLE_CLIENT_SECRET=');
check(!google_registration_ready(),'Missing configuration fails closed');
putenv('GOOGLE_CLIENT_ID=test');putenv('GOOGLE_CLIENT_SECRET=test');putenv('GOOGLE_REDIRECT_URI=http://example.com/register_google.php');
check(!google_registration_ready(),'Non-local insecure callback rejected');
echo "Google registration checks complete.\n";
