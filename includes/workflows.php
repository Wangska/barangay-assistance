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
    $map=['Dashboard'=>'Pangunang panid','Application'=>'Aplikasyon','Profile'=>'Personal nga impormasyon','Notifications'=>'Mga pahibalo','Requests'=>'Mga hangyo','Released Assistance'=>'Gipagawas nga tabang','My Assistance Requests'=>'Akong mga hangyo sa tabang','Back to my requests'=>'Balik sa akong mga hangyo','Back to dashboard'=>'Balik sa dashboard','Announcements'=>'Mga anunsyo','Events'=>'Mga kalihokan','Logout'=>'Paggawas'];
    return '<span data-en="'.e($text).'" data-ceb="'.e($map[$text] ?? $text).'">'.e($text).'</span>';
}

function request_status_label(string $status): string
{
    return $status === 'Completed' ? 'Released' : $status;
}

function format_release_date(string $value): string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    if (date('H:i:s', $timestamp) === '00:00:00') {
        return date('M j, Y', $timestamp);
    }
    return date('M j, Y g:i A', $timestamp);
}

function assistance_priority(array $request): bool
{
    return ($request['pension_status'] ?? '') === 'Without Pension'
        || in_array($request['financial_status'] ?? '', ['Low Income', 'Indigent'], true);
}

function setting_value(string $key, string $default = ''): string
{
    $query = db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $query->execute([$key]);
    $value = $query->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function death_assistance_amount(): string
{
    $value = trim(setting_value('death_assistance_amount', '2500'));
    return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) === 1 ? $value : '2500';
}

function death_assistance_limit_message(): string
{
    return 'Death assistance can be requested only once. You already have a death assistance request.';
}

function death_assistance_already_requested(int $seniorId): bool
{
    $query = db()->prepare("SELECT 1 FROM assistance_requests WHERE senior_id=? AND request_type='Death Assistance' AND status<>'Rejected' LIMIT 1");
    $query->execute([$seniorId]);
    return $query->fetchColumn() !== false;
}

function claim_id_reminder_needed(string $status, ?array $release = null): bool
{
    if (in_array($status, ['Approved', 'Scheduled'], true)) {
        return true;
    }
    if ($status !== 'Completed') {
        return false;
    }
    $releasedAt = strtotime((string) ($release['released_at'] ?? ''));
    return $releasedAt !== false && $releasedAt >= time();
}

function release_assistance(int $requestId, array $data, int $actorId): void
{
    $quantity=(string)($data['quantity'] ?? '');
    $amount=trim((string)($data['amount'] ?? ''));
    $rawDate=(string)($data['released_at'] ?? '');
    $date=DateTime::createFromFormat('!Y-m-d', $rawDate) ?: DateTime::createFromFormat('!Y-m-d\TH:i', $rawDate);
    $dateMatches=$date && ($date->format('Y-m-d')===$rawDate || $date->format('Y-m-d\TH:i')===$rawDate);
    $item=trim((string)($data['item'] ?? ''));
    $unit=trim((string)($data['unit'] ?? ''));
    $receiver=trim((string)($data['received_by'] ?? ''));
    $releaseDay=$date ? DateTime::createFromFormat('!Y-m-d', $date->format('Y-m-d')) : null;
    if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/',$quantity) || (float)$quantity<=0
        || !preg_match('/^\d{1,10}(\.\d{1,2})?$/',$amount)
        || !$dateMatches || $releaseDay < new DateTime('today')
        || $item==='' || strlen($item)>180 || $unit==='' || strlen($unit)>40 || $receiver==='' || strlen($receiver)>150) {
        throw new RuntimeException('Enter the amount, item, quantity, unit, recipient, and a release date and time of today or later.');
    }
    $pdo=db(); $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT ar.status,s.user_id FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id WHERE request_id=? FOR UPDATE');
        $q->execute([$requestId]); $r=$q->fetch();
        if (!$r || !in_array($r['status'],['Approved','Scheduled'],true)) throw new RuntimeException('Only approved or scheduled requests can be released.');
        $pdo->prepare('INSERT INTO assistance_distributions (request_id,item,quantity,unit,amount,released_at,released_by,received_by,remarks) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$requestId,$item,$quantity,$unit,$amount===''?null:$amount,$date->format('Y-m-d H:i:s'),$actorId,$receiver,trim($data['remarks'] ?? '')]);
        $pdo->prepare("UPDATE assistance_requests SET status='Completed',processed_by=? WHERE request_id=?")->execute([$actorId,$requestId]);
        $releasedWhen=str_contains($rawDate, 'T') ? $date->format('M j, Y g:i A') : $date->format('M j, Y');
        application_event((int)$r['user_id'],$requestId,'Completed','Released on '.$releasedWhen.': '.$item.' ('.$quantity.' '.$unit.'). Amount: PHP '.number_format((float)$amount,2).'. Received by '.$receiver.'.',$actorId);
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

function request_file_label(array $request, string $file = 'supporting'): string
{
    if ($file === 'identity') {
        return 'View ' . strtolower((string) ($request['identity_label'] ?: 'identification'));
    }
    return match ($request['request_type'] ?? '') {
        'Death Assistance' => 'View death certificate',
        'Medical/Financial Assistance' => 'View supporting document / medical prescription',
        default => 'View supporting document',
    };
}

function requirement_preview_button(int $requirementId, string $storedPath, string $label = 'View submitted document'): void
{
    $extension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
    $kind = in_array($extension, ['jpg', 'jpeg', 'png'], true) ? 'image' : 'pdf';
    $url = BASE_URL . '/api/requirement_file.php?id=' . $requirementId;
    printf(
        '<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#document-preview-modal" data-document-url="%s" data-document-kind="%s">%s</button>',
        e($url),
        e($kind),
        e($label)
    );
}

function document_preview_button(int $requestId, string $storedPath, string $label, string $file = 'supporting', string $class = 'request-inline-link'): void
{
    $extension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
    $kind = in_array($extension, ['jpg', 'jpeg', 'png'], true) ? 'image' : 'pdf';
    $url = BASE_URL . '/api/request_document.php?request_id=' . $requestId . ($file === 'identity' ? '&file=identity' : '');
    printf(
        '<button type="button" class="%s" data-bs-toggle="modal" data-bs-target="#document-preview-modal" data-document-url="%s" data-document-kind="%s">%s</button>',
        e($class),
        e($url),
        e($kind),
        e($label)
    );
}

function document_preview_modal(): void
{
    static $shown = false;
    if ($shown) {
        return;
    }
    $shown = true;
    ?>
<div class="modal fade" id="document-preview-modal" tabindex="-1" aria-labelledby="document-preview-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="document-preview-title">Supporting document</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="document-preview-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
(() => {
    const modal = document.getElementById('document-preview-modal');
    const title = document.getElementById('document-preview-title');
    const body = document.getElementById('document-preview-body');
    if (!modal || !title || !body) return;
    modal.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        if (!button) return;
        const url = button.dataset.documentUrl || '';
        const caption = button.textContent.trim();
        title.textContent = caption;
        body.replaceChildren();
        if (button.dataset.documentKind === 'image') {
            const image = document.createElement('img');
            image.className = 'document-preview';
            image.alt = caption;
            image.src = url;
            body.append(image);
            return;
        }
        const frame = document.createElement('iframe');
        frame.className = 'document-preview-frame';
        frame.title = caption;
        frame.src = url + '#toolbar=0&navpanes=0';
        body.append(frame);
    });
    modal.addEventListener('hidden.bs.modal', () => {
        body.replaceChildren();
        title.textContent = 'Supporting document';
    });
})();
</script>
    <?php
}
