<?php
// Run with: C:\xamppa\php\php.exe tests/capstone_smoke.php
// Uses an isolated random database and a loopback-only PHP server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$name='capstone_test_'.bin2hex(random_bytes(5));
$storage=$root.'/outputs/'.$name;
mkdir($storage,0775,true);
$adminPdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'127.0.0.1').';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$adminPdo->exec("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
putenv('DB_NAME='.$name);putenv('BASE_URL=/');putenv('APP_STORAGE_DIR='.$storage);putenv('TEXTBEE_API_KEY=');putenv('TEXTBEE_DEVICE_ID=');putenv('TEXTBEE_CONFIG=');
require $root.'/includes/functions.php';
$schema=file_get_contents($root.'/database/database.sql');
$schema=preg_replace('/^CREATE DATABASE[^;]+;\s*USE[^;]+;/','',$schema);
db()->exec($schema);
$server=null;$passed=0;$password='Test-only-'.bin2hex(random_bytes(6));
function check(bool $condition,string $message): void {
    global $passed;
    if(!$condition) throw new RuntimeException($message);
    $passed++;echo "PASS $message\n";
}
function account(string $name,int $role,string $status='Active'): int {
    global $password;
    db()->prepare('INSERT INTO users (role_id,full_name,email,phone_number,password_hash,status) VALUES (?,?,?,?,?,?)')->execute([$role,$name,strtolower(str_replace(' ','',$name)).'@example.invalid','09171234567',password_hash($password,PASSWORD_DEFAULT),$status]);
    $id=(int)db()->lastInsertId();
    if($role===3) {
        db()->prepare("INSERT INTO senior_citizens (user_id,first_name,last_name,birthdate,gender,region,province,city_municipality,address,place_of_birth,civil_status,blood_type,religion,education,employment_status,classification) VALUES (?,?,'Test','1950-01-01','Female','Region','Province','City','Address','City','Single','O+','Religion','College','Retired','Pensioner')")->execute([$id,$name]);
        db()->prepare("INSERT INTO application_requirements (user_id,label) VALUES (?,'Valid identification')")->execute([$id]);
        application_event($id,null,'Submitted','Test registration submitted.',$id);
    }
    return $id;
}
function http(array &$session,string $path,?array $data=null,?array $upload=null): array {
    global $port;
    $headers=['Cookie: '.($session['cookie']??'')];$content='';
    if($data!==null) {
        if($upload) {
            $boundary='Boundary'.bin2hex(random_bytes(8));
            $headers[]='Content-Type: multipart/form-data; boundary='.$boundary;
            foreach($data as $key=>$value)$content.="--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
            $content.="--$boundary\r\nContent-Disposition: form-data; name=\"".$upload['field']."\"; filename=\"id.png\"\r\nContent-Type: image/png\r\n\r\n".$upload['bytes']."\r\n--$boundary--\r\n";
        } else { $headers[]='Content-Type: application/x-www-form-urlencoded';$content=http_build_query($data); }
    }
    $context=stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$content,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]);
    $body=file_get_contents("http://127.0.0.1:$port".$path,false,$context);
    $responseHeaders=$http_response_header;
    foreach($responseHeaders as $header)if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m))$session['cookie']=$m[1];
    preg_match('/\s(\d{3})\s/',$responseHeaders[0],$m);$status=(int)$m[1];
    if($status>=500)throw new RuntimeException("HTTP $status $path: ".strip_tags((string)$body));
    if(preg_match('/name="csrf_token"\s+value="([^"]+)"/',(string)$body,$m))$session['csrf']=$m[1];
    return [$status,(string)$body,$responseHeaders];
}
function login_test(string $name): array {
    global $password;
    $session=[];http($session,'/login.php');
    $r=http($session,'/login.php',['csrf_token'=>$session['csrf'],'email'=>strtolower(str_replace(' ','',$name)).'@example.invalid','password'=>$password]);
    check($r[0]===302,$name.' login');
    return $session;
}
function post_action(array &$session,string $path,array $data,?array $upload=null): array {
    return http($session,$path,['csrf_token'=>$session['csrf']]+$data,$upload);
}
try {
    $adminId=account('Admin',1);$staffId=account('Staff',2);
    $pendingId=account('Applicant',3,'Pending');$otherId=account('Other',3);
    $port=random_int(18000,24000);
    $log=$storage.'/server.log';
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
    if(!is_resource($server))throw new RuntimeException('Could not start test server.');
    fclose($pipes[0]);usleep(500000);
    $admin=login_test('Admin');$staff=login_test('Staff');$senior=login_test('Applicant');$other=login_test('Other');
    $r=http($senior,'/senior/application.php');check($r[0]===200 && str_contains($r[1],'Requirements checklist'),'Pending applicant can track requirements');
    check(http($senior,'/senior/requests.php')[0]===302,'Pending applicant cannot submit assistance');
    check(http($staff,'/admin/application.php?user_id='.$pendingId)[0]===403,'Staff cannot use administrator approval route');
    check(http($senior,'/staff/registrations.php')[0]===302,'Pending senior cannot browse registrations');
    $path='/admin/application.php?user_id='.$pendingId;
    http($admin,$path);
    post_action($admin,$path,['action'=>'approve']);
    check(db()->query("SELECT status FROM users WHERE user_id=$pendingId")->fetchColumn()==='Pending','Missing requirements block approval');
    post_action($staff,'/staff/application.php?user_id='.$pendingId,['action'=>'approve']);
    check(db()->query("SELECT status FROM users WHERE user_id=$pendingId")->fetchColumn()==='Pending','Staff approval POST is denied');
    $req=(int)db()->query("SELECT requirement_id FROM application_requirements WHERE user_id=$pendingId")->fetchColumn();
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGZkAAAAASUVORK5CYII=');
    post_action($senior,'/senior/application.php',['action'=>'upload','requirement_id'=>$req],['field'=>'document','bytes'=>$png]);
    check(db()->query("SELECT status FROM application_requirements WHERE requirement_id=$req")->fetchColumn()==='Submitted','Document upload is recorded');
    check(http($other,'/api/requirement_file.php?id='.$req)[0]===404,'Document ownership is enforced');
    check(http($staff,'/api/requirement_file.php?id='.$req)[0]===200,'Staff can access submitted requirements');
    post_action($admin,$path,['action'=>'review_requirement','requirement_id'=>$req,'requirement_status'=>'Needs replacement','remarks'=>'Use a clearer image']);
    check(db()->query("SELECT status FROM application_requirements WHERE requirement_id=$req")->fetchColumn()==='Needs replacement','Reviewer can request replacement');
    post_action($senior,'/senior/application.php',['action'=>'upload','requirement_id'=>$req],['field'=>'document','bytes'=>$png]);
    post_action($admin,$path,['action'=>'review_requirement','requirement_id'=>$req,'requirement_status'=>'Accepted','remarks'=>'Verified']);
    db()->prepare("UPDATE senior_citizens SET first_name='Applicant' WHERE user_id=?")->execute([$otherId]);
    check(count(duplicate_seniors($pendingId))===1,'Duplicate names and birthdates are flagged');
    post_action($admin,$path,['action'=>'approve','remarks'=>'Approved']);
    check(db()->query("SELECT status FROM users WHERE user_id=$pendingId")->fetchColumn()==='Pending','Unreviewed duplicate blocks approval');
    post_action($admin,$path,['action'=>'approve','remarks'=>'Verified distinct identity','duplicate_checked'=>'1']);
    check(db()->query("SELECT status FROM users WHERE user_id=$pendingId")->fetchColumn()==='Active','Approval succeeds after checklist and duplicate review');
    check(str_contains(http($senior,'/senior/application.php')[1],'Approved'),'Senior timeline includes approval');
    http($senior,'/senior/requests.php');
    post_action($senior,'/senior/requests.php',['request_type'=>'Food Assistance','description'=>'Food support']);
    $requestId=(int)db()->query('SELECT MAX(request_id) FROM assistance_requests')->fetchColumn();
    check($requestId>0,'Senior assistance submission works');
    check(http($other,'/senior/application.php?request_id='.$requestId)[0]===404,'Other senior cannot read request timeline');
    http($staff,'/staff/requests.php');
    post_action($staff,'/staff/requests.php',['request_id'=>$requestId,'status'=>'Under Review','remarks'=>'Checking eligibility']);
    post_action($staff,'/staff/requests.php',['request_id'=>$requestId,'status'=>'Approved','remarks'=>'Eligible']);
    check(db()->query("SELECT status FROM assistance_requests WHERE request_id=$requestId")->fetchColumn()==='Approved','Request review and approval work');
    $release=['action'=>'release','request_id'=>$requestId,'item'=>'Rice','quantity'=>'2','unit'=>'bags','amount'=>'100','received_by'=>'Applicant','released_at'=>date('Y-m-d\TH:i'),'remarks'=>'Received'];
    post_action($staff,'/staff/requests.php',array_replace($release,['quantity'=>'-1']));
    check((int)db()->query('SELECT COUNT(*) FROM assistance_distributions')->fetchColumn()===0,'Invalid release is rejected');
    post_action($staff,'/staff/requests.php',$release);
    check(db()->query("SELECT status FROM assistance_requests WHERE request_id=$requestId")->fetchColumn()==='Completed','Release completes request');
    post_action($staff,'/staff/requests.php',$release);
    check((int)db()->query('SELECT COUNT(*) FROM assistance_distributions')->fetchColumn()===1,'Duplicate release is prevented');
    check(str_contains(http($senior,'/senior/application.php?request_id='.$requestId)[1],'Rice'),'Senior sees assistance received');
    foreach(['requests','registrations','distributions'] as $type) {
        $r=http($staff,'/staff/reports.php?type='.$type);
        check($r[0]===200 && str_contains($r[1],'Monthly Service Report'),'Monthly '.$type.' report renders');
    }
    check(http($staff,'/staff/reports.php?month=invalid')[0]===422,'Invalid report month rejected');
    $r=http($staff,'/staff/reports.php?type=distributions&export=csv');
    check(str_contains($r[1],'Rice') && !str_contains($r[1],'<html'),'CSV export contains release data');
    check(report_csv_cell('=1+1')==="'=1+1",'CSV formula injection escaped');
    foreach(['all','unread','read'] as $filter)check(http($staff,'/staff/notifications.php?filter='.$filter)[0]===200,'Staff notification '.$filter.' filter');
    $r=http($staff,'/api/notification_count.php');check(isset(json_decode($r[1],true)['unread']),'Unread-count API returns data');
    check(str_contains(http($staff,'/staff/notifications.php')[1],'/staff/application.php?'),'Notifications link to exact application');
    $note=(int)db()->query("SELECT notification_id FROM notifications WHERE user_id=$staffId LIMIT 1")->fetchColumn();
    post_action($other,'/api/mark_notification_read.php',['notification_id'=>$note]);
    check((int)db()->query("SELECT is_read FROM notifications WHERE notification_id=$note")->fetchColumn()===0,'Cannot mark another account notification read');
    check(http($staff,'/api/mark_notification_read.php',['notification_id'=>$note,'csrf_token'=>'wrong'])[0]===419,'CSRF protects notification mutation');
    $signup=[];
    http($signup,'/register.php');
    $form=['first_name'=>'New','last_name'=>'Senior','region'=>'Region IV-A (CALABARZON)','province'=>'Cavite','city_municipality'=>'City of Bacoor','address'=>'Test address','phone_number'=>'09171234567','email'=>'newsenior@example.invalid','password'=>$password,'password_confirmation'=>$password,'birthdate'=>'1950-01-01','place_of_birth'=>'City','gender'=>'Female','civil_status'=>'Single','blood_type'=>'O+','religion'=>'Religion','education'=>'College','employment_status'=>'Retired','classification'=>'Pensioner','emergency_contact_name'=>'Contact','emergency_contact_phone'=>'09171234568','terms_accepted'=>'1'];
    post_action($signup,'/register.php',$form,['field'=>'valid_id','bytes'=>str_repeat('x', 2 * 1024 * 1024 + 1)]);
    $rejected=http($signup,'/register.php');
    check(str_contains($rejected[1], 'Your ID file is too large'), 'Oversized registration ID shows actionable error');
    check(str_contains($rejected[1], 'value="newsenior@example.invalid"') && str_contains($rejected[1], '>Test address</textarea>') && str_contains($rejected[1], ' selected>Female'), 'Registration retains text and selected fields after upload failure');
    check(!str_contains($rejected[1], $password), 'Registration never repopulates passwords');
    check((int)db()->query("SELECT COUNT(*) FROM users WHERE email='newsenior@example.invalid'")->fetchColumn()===0, 'Rejected upload creates no account');
    post_action($signup,'/register.php',$form,['field'=>'valid_id','bytes'=>'not an image']);
    check(str_contains(http($signup,'/register.php')[1], 'Only JPG, PNG, and PDF'), 'Invalid ID content shows file type error');
    post_action($signup,'/register.php',$form);
    check(str_contains(http($signup,'/register.php')[1], 'Please select a valid identification file'), 'Registration requires an ID on the server');
    post_action($signup,'/register.php',$form,['field'=>'valid_id','bytes'=>$png]);
    $newId=(int)db()->query("SELECT user_id FROM users WHERE email='newsenior@example.invalid'")->fetchColumn();
    check($newId>0,'Public registration creates pending applicant');
    check((int)db()->query("SELECT COUNT(*) FROM application_events WHERE user_id=$newId")->fetchColumn()===1,'Registration creates initial timeline event');
    check((int)db()->query("SELECT COUNT(*) FROM application_requirements WHERE user_id=$newId AND status='Submitted'")->fetchColumn()===1,'Registration creates submitted ID checklist');
    $newSession=login_test('NewSenior');
    http($admin,'/admin/application.php?user_id='.$newId);
    post_action($admin,'/admin/application.php?user_id='.$newId,['action'=>'reject','remarks'=>'Please replace the document']);
    http($newSession,'/senior/application.php');
    $newRequirement=(int)db()->query("SELECT requirement_id FROM application_requirements WHERE user_id=$newId")->fetchColumn();
    post_action($newSession,'/senior/application.php',['action'=>'upload','requirement_id'=>$newRequirement],['field'=>'document','bytes'=>$png]);
    check(db()->query("SELECT status FROM users WHERE user_id=$newId")->fetchColumn()==='Pending','Rejected applicant can resubmit documents for review');
    http($staff,'/staff/announcements.php');
    post_action($staff,'/staff/announcements.php',['title'=>'Monthly meeting','body'=>'Visit the barangay hall.','audience'=>'Senior Citizen']);
    post_action($staff,'/staff/announcements.php',['title'=>'Monthly meeting','body'=>'Visit the barangay hall.','audience'=>'Senior Citizen']);
    check((int)db()->query("SELECT COUNT(*) FROM notifications WHERE user_id=$pendingId AND announcement_id IS NOT NULL")->fetchColumn()===2,'Distinct announcements with identical text each notify');
    sync_senior_announcement_notifications($pendingId);
    check((int)db()->query("SELECT COUNT(*) FROM notifications WHERE user_id=$pendingId AND announcement_id IS NOT NULL")->fetchColumn()===2,'Announcement sync prevents duplicates');
    $announcement=db()->query("SELECT * FROM notifications WHERE user_id=$pendingId AND announcement_id IS NOT NULL LIMIT 1")->fetch();
    check(str_contains((string)notification_destination($announcement,'Senior Citizen'),'#announcement-'),'Announcement notification links to exact post');
    foreach (['admin'=>&$admin,'staff'=>&$staff] as $role=>&$session) {
        check(http($session,'/'.$role.'/registrations.php?status=All')[0]===200,$role.' registration list supports expanded schema');
    }
    unset($session);
    if (getenv('CAPSTONE_PREVIEW_HOLD')!=='1') {
        db()->exec('ALTER TABLE senior_citizens DROP COLUMN first_name, DROP COLUMN last_name');
        db()->prepare('UPDATE users SET full_name=? WHERE user_id=?')->execute(['Applicant',$otherId]);
        check(count(duplicate_seniors($pendingId))===1,'Legacy duplicate detection uses full name and birthdate');
        foreach (['admin'=>&$admin,'staff'=>&$staff] as $role=>&$session) {
            $legacy=http($session,'/'.$role.'/registrations.php?status=All');
            check($legacy[0]===200 && str_contains($legacy[1],'Possible duplicate'),$role.' registration list supports legacy schema');
        }
        unset($session);
    }
    if (getenv('CAPSTONE_PREVIEW_HOLD')==='1') {
        file_put_contents($root.'/outputs/capstone_preview.json',json_encode(['url'=>'http://127.0.0.1:'.$port,'email'=>'applicant@example.invalid','password'=>$password]));
        echo "PREVIEW READY\n";flush();
        $deadline=time()+600;
        while(time()<$deadline && !is_file($root.'/outputs/capstone_preview_done'))sleep(1);
        @unlink($root.'/outputs/capstone_preview.json');@unlink($root.'/outputs/capstone_preview_done');
    }
    echo "SUCCESS: $passed checks; all test data will be removed.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(preg_match('/^capstone_test_[a-f0-9]{10}$/',$name))$adminPdo->exec("DROP DATABASE $name");
    foreach(glob($storage.'/ids/*')?:[] as $file)unlink($file);
    if(is_dir($storage.'/ids'))rmdir($storage.'/ids');
    foreach(glob($storage.'/*')?:[] as $file)if(is_file($file))unlink($file);
    rmdir($storage);
}
