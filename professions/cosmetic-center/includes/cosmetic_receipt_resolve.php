<?php
/**
 * داتای وەسڵ: کەیس، جەلسە، و براندینگی ئەکاونتی چوونەژوورەوە.
 */
declare(strict_types=1);

/**
 * بارکردنی ڕیزی دەستکاریکەر بۆ وەسڵ/پشتڕاستکردنەوە.
 *
 * @return array{role: string, account_id: int}|null
 */
function cosmetic_current_branding_context_center(): ?array
{
    if (!function_exists('isCosmeticCenterLoggedIn') || !isCosmeticCenterLoggedIn()) {
        return null;
    }
    $s = cosmeticCenterSession();
    return [
        'role' => 'center',
        'account_id' => (int)($s['center_account_id'] ?? 0),
    ];
}

/**
 * @return array{role: string, account_id: int}|null
 */
function cosmetic_current_branding_context_doctor(): ?array
{
    if (!function_exists('isCosmeticDoctorLoggedIn') || !isCosmeticDoctorLoggedIn()) {
        return null;
    }
    $s = cosmeticDoctorSession();
    return [
        'role' => 'doctor',
        'account_id' => (int)($s['doctor_account_id'] ?? 0),
    ];
}

/**
 * بارکردنی ناو و سەرپەڕە و لۆگۆی وەسڵ بەپێی جۆری ئەکاونت.
 *
 * @return array{name: string, receipt_header: string, receipt_logo_url: string}|null
 */
function cosmetic_fetch_branding_row(mysqli $db, int $userId, string $role, int $accountId): ?array
{
    $role = $role === 'doctor' ? 'doctor' : 'center';
    if ($accountId <= 0 || $userId <= 0) {
        return null;
    }
    if ($role === 'center') {
        $st = $db->prepare(
            'SELECT id, name, receipt_header, receipt_logo_url FROM cosmetic_center_accounts WHERE id = ? AND user_id = ? LIMIT 1'
        );
        if (!$st) {
            return null;
        }
        $st->bind_param('ii', $accountId, $userId);
    } else {
        $st = $db->prepare(
            'SELECT id, name, receipt_header, receipt_logo_url FROM cosmetic_doctor_accounts WHERE id = ? AND user_id = ? LIMIT 1'
        );
        if (!$st) {
            return null;
        }
        $st->bind_param('ii', $accountId, $userId);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }
    return [
        'name' => (string)($row['name'] ?? ''),
        'receipt_header' => (string)($row['receipt_header'] ?? ''),
        'receipt_logo_url' => (string)($row['receipt_logo_url'] ?? ''),
    ];
}

/**
 * @return array{case: array<string, mixed>, session: array<string, mixed>}|null
 */
function cosmetic_fetch_case_and_session(
    mysqli $db,
    int $tenantUserId,
    int $caseId,
    ?int $sessionId,
    ?string $creatorRole = null,
    ?int $creatorAccountId = null
): ?array {
    $scopeCreator = $creatorRole !== null && $creatorRole !== '' && $creatorAccountId !== null && $creatorAccountId > 0;
    $roleNorm = $scopeCreator && $creatorRole === 'doctor' ? 'doctor' : 'center';

    $sql = 'SELECT id, user_id, client_name, age, sessions_planned, work_type, mobile, created_at
         FROM cosmetic_client_cases
         WHERE id = ? AND user_id = ?';
    if ($scopeCreator) {
        $sql .= ' AND created_by_role = ? AND created_by_account_id = ?';
    }
    $sql .= ' LIMIT 1';

    $st = $db->prepare($sql);
    if (!$st) {
        return null;
    }
    if ($scopeCreator) {
        $st->bind_param('iisi', $caseId, $tenantUserId, $roleNorm, $creatorAccountId);
    } else {
        $st->bind_param('ii', $caseId, $tenantUserId);
    }
    $st->execute();
    $case = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$case) {
        return null;
    }

    if ($sessionId !== null && $sessionId > 0) {
        $ss = $db->prepare(
            'SELECT id, case_id, session_number, session_date, notes, price, discount, created_at
             FROM cosmetic_client_sessions
             WHERE id = ? AND case_id = ?
             LIMIT 1'
        );
        if (!$ss) {
            return null;
        }
        $ss->bind_param('ii', $sessionId, $caseId);
        $ss->execute();
        $session = $ss->get_result()->fetch_assoc();
        $ss->close();
        if (!$session) {
            return null;
        }
    } else {
        $ss = $db->prepare(
            'SELECT id, case_id, session_number, session_date, notes, price, discount, created_at
             FROM cosmetic_client_sessions
             WHERE case_id = ?
             ORDER BY session_number DESC, id DESC
             LIMIT 1'
        );
        if (!$ss) {
            return null;
        }
        $ss->bind_param('i', $caseId);
        $ss->execute();
        $session = $ss->get_result()->fetch_assoc();
        $ss->close();
        if (!$session) {
            return null;
        }
    }

    return ['case' => $case, 'session' => $session];
}
