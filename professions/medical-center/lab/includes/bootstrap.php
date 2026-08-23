<?php
require_once dirname(__DIR__, 4) . '/config/config.php';
require_once dirname(__DIR__, 4) . '/config/database.php';
require_once dirname(__DIR__, 4) . '/config/security.php';
require_once dirname(__DIR__, 4) . '/config/kasher_platform/database.php';

if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
    $db = new Database();
    $GLOBALS['conn'] = $db->connect();
}
$conn = $GLOBALS['conn'];

if (!($conn_kasher_platform instanceof mysqli)) {
    setMessage('داتابەیسی kasher_platform بەردەست نییە', 'danger');
    redirect(url('user/auth/login.php'));
}

const MEDICAL_LAB_SESSION_KEY = 'medical_lab_auth';

function medicalLabSession(): ?array
{
    $session = $_SESSION[MEDICAL_LAB_SESSION_KEY] ?? null;
    return is_array($session) ? $session : null;
}

function isMedicalLabLoggedIn(): bool
{
    return medicalLabSession() !== null;
}

function requireMedicalLabAuth(): void
{
    if (!isMedicalLabLoggedIn()) {
        redirect(url('professions/medical-center/lab/auth/login.php'));
    }
    global $conn_kasher_platform;
    if (!($conn_kasher_platform instanceof mysqli)) {
        medicalLabLogout();
        redirect(url('professions/medical-center/lab/auth/login.php'));
    }
    if (!labValidateSession($conn_kasher_platform)) {
        medicalLabLogout();
        setMessage('ئەکاونتەکە چیتر بەردەست نییە. دووبارە بچۆ ژوورەوە', 'warning');
        redirect(url('professions/medical-center/lab/auth/login.php'));
    }
}

/**
 * Verify the lab session still matches a live account in the database.
 */
function labValidateSession(mysqli $conn): bool
{
    $session = medicalLabSession();
    if ($session === null) {
        return false;
    }
    $labId = (int)$session['lab_id'];
    $userId = (int)$session['user_id'];
    $stmt = $conn->prepare("SELECT id FROM medical_center_labs WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $labId, $userId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * Resolve lab login: fetch password-valid matches for identifier, optionally scoped by tenant.
 *
 * @return array{row:?array,error:string,needs_tenant:bool}
 */
function labResolveLogin(
    mysqli $conn,
    string $identifier,
    string $password,
    int $tenantUserId = 0,
    string $businessHint = '',
    ?mysqli $tenantConn = null
): array {
    $stmt = $conn->prepare("
        SELECT id, user_id, name, email, mobile, password_hash
        FROM medical_center_labs
        WHERE email = ? OR mobile = ?
    ");
    if (!$stmt) {
        return ['row' => null, 'error' => 'هەڵەیەک ڕوویدا', 'needs_tenant' => false];
    }
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $valid = [];
    foreach ($candidates as $row) {
        if (password_verify($password, (string)$row['password_hash'])) {
            $valid[] = $row;
        }
    }

    if ($valid === []) {
        return ['row' => null, 'error' => 'ناسێنەر یان پاسۆرد هەڵەیە', 'needs_tenant' => false];
    }

    $filterByUserId = static function (array $rows, int $userId): ?array {
        foreach ($rows as $row) {
            if ((int)$row['user_id'] === $userId) {
                return $row;
            }
        }
        return null;
    };

    if ($tenantUserId > 0) {
        $row = $filterByUserId($valid, $tenantUserId);
        return $row !== null
            ? ['row' => $row, 'error' => '', 'needs_tenant' => false]
            : ['row' => null, 'error' => 'ناوی دامەزراوە یان ناسێنەر هەڵەیە', 'needs_tenant' => false];
    }

    $businessHint = trim($businessHint);
    if ($businessHint !== '' && $tenantConn instanceof mysqli) {
        $hintLower = mb_strtolower($businessHint, 'UTF-8');
        $matched = [];
        foreach ($valid as $row) {
            $uid = (int)$row['user_id'];
            $bizStmt = $tenantConn->prepare("SELECT business_name FROM users WHERE id = ? LIMIT 1");
            if (!$bizStmt) {
                continue;
            }
            $bizStmt->bind_param('i', $uid);
            $bizStmt->execute();
            $bizRow = $bizStmt->get_result()->fetch_assoc();
            $bizStmt->close();
            $bizName = mb_strtolower(trim((string)($bizRow['business_name'] ?? '')), 'UTF-8');
            if ($bizName !== '' && ($bizName === $hintLower || str_contains($bizName, $hintLower) || str_contains($hintLower, $bizName))) {
                $matched[] = $row;
            }
        }
        if (count($matched) === 1) {
            return ['row' => $matched[0], 'error' => '', 'needs_tenant' => false];
        }
        if (count($matched) > 1) {
            return [
                'row' => null,
                'error' => 'چەند ئەکاونتێک بەم ناوە دۆزرانەوە. تکایە بە لینکی پۆرتاڵی تاقیگەکەت بچۆ ژوورەوە',
                'needs_tenant' => true,
            ];
        }
    }

    if (count($valid) === 1) {
        return ['row' => $valid[0], 'error' => '', 'needs_tenant' => false];
    }

    return [
        'row' => null,
        'error' => 'چەند ئەکاونتێک بەم ناسێنەرە دۆزرانەوە. تکایە ناوی دامەزراوە بنووسە یان بە لینکی پۆرتاڵی تاقیگەکەت بچۆ ژوورەوە',
        'needs_tenant' => true,
    ];
}

function medicalLabLogout(): void
{
    unset($_SESSION[MEDICAL_LAB_SESSION_KEY]);
    session_regenerate_id(true);
}

function setMedicalLabSession(array $data): void
{
    $_SESSION[MEDICAL_LAB_SESSION_KEY] = [
        'lab_id' => (int)$data['lab_id'],
        'user_id' => (int)$data['user_id'],
        'name' => (string)$data['name'],
        'email' => (string)$data['email'],
        'mobile' => (string)$data['mobile'],
        'logged_at' => date('Y-m-d H:i:s')
    ];
    session_regenerate_id(true);
}
