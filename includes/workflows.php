<?php
declare(strict_types=1);

function application_event(int $userId, ?int $requestId, string $status, string $remarks, ?int $actorId): void
{
    db()->prepare('INSERT INTO application_events (user_id,request_id,status,remarks,actor_id) VALUES (?,?,?,?,?)')->execute([$userId,$requestId,$status,$remarks,$actorId]);
}

/**
 * Uses fixed internal SQL aliases: s/u for the applicant, other/other_user
 * for the possible match. Older installations only have users.full_name.
 */
function senior_duplicate_condition(): string
{
    $columns=db()->query('SHOW COLUMNS FROM senior_citizens')->fetchAll(PDO::FETCH_COLUMN);
    $fullName="TRIM(u.full_name)<>'' AND LOWER(TRIM(u.full_name))=LOWER(TRIM(other_user.full_name))";
    if (!in_array('first_name',$columns,true) || !in_array('last_name',$columns,true)) {
        return 'other.birthdate=s.birthdate AND ('.$fullName.')';
    }
    $hasNames="COALESCE(TRIM(s.first_name),'')<>'' AND COALESCE(TRIM(s.last_name),'')<>''
        AND COALESCE(TRIM(other.first_name),'')<>'' AND COALESCE(TRIM(other.last_name),'')<>''";
    return "other.birthdate=s.birthdate AND (
        (($hasNames) AND LOWER(TRIM(other.first_name))=LOWER(TRIM(s.first_name))
         AND LOWER(TRIM(other.last_name))=LOWER(TRIM(s.last_name)))
        OR (NOT ($hasNames) AND ($fullName))
    )";
}

function duplicate_seniors(int $userId): array
{
    $condition=senior_duplicate_condition();
    $q = db()->prepare("SELECT other.user_id,other_user.full_name,other_user.status,other.birthdate
        FROM senior_citizens s JOIN users u ON u.user_id=s.user_id
        JOIN senior_citizens other ON other.user_id<>s.user_id
        JOIN users other_user ON other_user.user_id=other.user_id
        WHERE s.user_id=? AND ($condition)");
    $q->execute([$userId]);
    return $q->fetchAll();
}
function application_record(int $userId, ?int $requestId = null): array
{
    if ($requestId !== null) {
        $q=db()->prepare('SELECT ar.*,u.full_name,u.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id JOIN users u ON u.user_id=s.user_id WHERE ar.request_id=? AND u.user_id=?');
        $q->execute([$requestId,$userId]);
    } else {
        $q=db()->prepare('SELECT u.user_id,u.full_name,u.status,u.created_at,s.senior_id FROM users u JOIN senior_citizens s ON s.user_id=u.user_id WHERE u.user_id=?');
        $q->execute([$userId]);
    }
    $record=$q->fetch();
    if (!$record) { http_response_code(404); exit('Application not found.'); }
    return $record;
}

function notification_destination(array $n, string $role): ?string
{
    $prefix=$role==='Senior Citizen' ? '/senior' : ($role==='Administrator' ? '/admin' : '/staff');
    if (!empty($n['assistance_request_id'])) return $role==='Senior Citizen' ? '/senior/requests.php' : $prefix.'/application.php?request_id='.(int)$n['assistance_request_id'];
    if (!empty($n['application_user_id'])) return $role==='Senior Citizen' ? '/senior/dashboard.php' : $prefix.'/application.php?user_id='.(int)$n['application_user_id'];
    if (!empty($n['announcement_id'])) return $prefix.'/announcements.php#announcement-'.(int)$n['announcement_id'];
    if (str_contains($n['title'],'Announcement')) return $prefix.'/announcements.php';
    return null;
}

function translated_label(string $text): string
{
    $map=['Dashboard'=>'Pangunang panid','Application'=>'Aplikasyon','Profile'=>'Personal nga impormasyon','Notifications'=>'Mga pahibalo','Requests'=>'Mga hangyo','My Assistance Requests'=>'Akong mga hangyo sa tabang','Back to my requests'=>'Balik sa akong mga hangyo','Back to dashboard'=>'Balik sa dashboard','Announcements'=>'Mga anunsyo','Events'=>'Mga kalihokan','Logout'=>'Paggawas'];
    return '<span data-en="'.e($text).'" data-ceb="'.e($map[$text] ?? $text).'">'.e($text).'</span>';
}

function release_assistance(int $requestId, array $data, int $actorId): void
{
    $quantity=(string)($data['quantity'] ?? '');
    $amount=trim((string)($data['amount'] ?? ''));
    $date=DateTime::createFromFormat('!Y-m-d\TH:i', (string)($data['released_at'] ?? ''));
    $item=trim((string)($data['item'] ?? ''));
    $unit=trim((string)($data['unit'] ?? ''));
    $receiver=trim((string)($data['received_by'] ?? ''));
    if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/',$quantity) || (float)$quantity<=0
        || ($amount!=='' && !preg_match('/^\d{1,10}(\.\d{1,2})?$/',$amount))
        || !$date || $date->format('Y-m-d\TH:i')!==($data['released_at'] ?? '') || $date>new DateTime()
        || $item==='' || strlen($item)>180 || $unit==='' || strlen($unit)>40 || $receiver==='' || strlen($receiver)>150) {
        throw new RuntimeException('Enter a valid item, quantity, unit, recipient, and release date (not in the future).');
    }
    $pdo=db(); $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT ar.status,s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE request_id=? FOR UPDATE');
        $q->execute([$requestId]); $r=$q->fetch();
        if (!$r || !in_array($r['status'],['Approved','Scheduled'],true)) throw new RuntimeException('Only approved or scheduled requests can be released.');
        $pdo->prepare('INSERT INTO assistance_distributions (request_id,item,quantity,unit,amount,released_at,released_by,received_by,remarks) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$requestId,$item,$quantity,$unit,$amount===''?null:$amount,$date->format('Y-m-d H:i:s'),$actorId,$receiver,trim($data['remarks'] ?? '')]);
        $pdo->prepare("UPDATE assistance_requests SET status='Completed',processed_by=? WHERE request_id=?")->execute([$actorId,$requestId]);
        application_event((int)$r['user_id'],$requestId,'Completed','Released: '.$item.' ('.$quantity.' '.$unit.'). Received by '.$receiver.'.',$actorId);
        notify_user((int)$r['user_id'],'Assistance Released','Your assistance for request #'.$requestId.' has been released.',null,$requestId);
        audit_log($actorId,'Released assistance','assistance_requests',$requestId);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function report_csv_cell(mixed $value): string
{
    $value=(string)$value;
    return preg_match('/^\s*[=+@-]/u',$value) || preg_match('/^[\t\r\n]/',$value) ? "'".$value : $value;
}
