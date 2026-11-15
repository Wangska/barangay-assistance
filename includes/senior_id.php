<?php
declare(strict_types=1);

function issue_senior_digital_id(PDO $pdo, int $userId): void
{
    $query = $pdo->prepare('SELECT senior_id, id_control_number FROM senior_citizens WHERE user_id = ? FOR UPDATE');
    $query->execute([$userId]);
    $senior = $query->fetch();
    if (!$senior) {
        throw new RuntimeException('This account has no senior record.');
    }
    if (trim((string) $senior['id_control_number']) !== '') {
        return;
    }
    $pdo->prepare('UPDATE senior_citizens SET id_control_number = ?, id_issued_at = CURDATE() WHERE senior_id = ?')
        ->execute([sprintf('%06d', (int) $senior['senior_id']), (int) $senior['senior_id']]);
}

function senior_id_record(int $seniorId): ?array
{
    $query = db()->prepare('SELECT s.*, u.full_name, u.status, u.profile_photo FROM senior_citizens s JOIN users u ON u.user_id = s.user_id WHERE s.senior_id = ?');
    $query->execute([$seniorId]);
    $record = $query->fetch();
    return $record ?: null;
}

function senior_id_age(string $birthdate): int
{
    $birth = DateTimeImmutable::createFromFormat('Y-m-d', $birthdate);
    if ($birth === false) {
        return 0;
    }
    return $birth->diff(new DateTimeImmutable('today'))->y;
}

function senior_id_sex(string $gender): string
{
    return match (strtolower(trim($gender))) {
        'male', 'm' => 'M',
        'female', 'f' => 'F',
        default => strtoupper(substr(trim($gender), 0, 1)),
    };
}

function senior_id_card_date(?string $value): string
{
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp === false ? '' : date('m-d-y', $timestamp);
}
