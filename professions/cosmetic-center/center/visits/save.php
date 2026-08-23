<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__, 2) . '/includes/cosmetic_case_scope.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('professions/cosmetic-center/center/visits/index.php'));
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('نادروستی ئامنیەتی', 'danger');
    redirect(url('professions/cosmetic-center/center/visits/index.php'));
}

$session = cosmeticCenterSession();
$userId = (int)$session['user_id'];
$centerAccountId = (int)$session['center_account_id'];
$creator = cosmetic_case_creator_from_center_session($session);
$creatorRole = $creator['role'];
$creatorAccountId = $creator['account_id'];
$role = 'center';

$recordId = (int)($_POST['record_id'] ?? 0);
$clientName = trim((string)($_POST['client_name'] ?? ''));
$mobile = trim((string)($_POST['mobile'] ?? ''));
$age = (int)($_POST['age'] ?? -1);
$sessionsPlanned = (int)($_POST['sessions_planned'] ?? 0);
$workType = trim((string)($_POST['work_type'] ?? ''));
$priceRaw = str_replace(',', '.', trim((string)($_POST['price'] ?? '')));
$discountRaw = str_replace(',', '.', trim((string)($_POST['discount'] ?? '')));
$firstSessionDate = trim((string)($_POST['first_session_date'] ?? ''));

if ($clientName === '' || $workType === '' || $mobile === '' || $age < 0 || $age > 130 || $sessionsPlanned < 1) {
    setMessage('تکایە هەموو خانە پێویستەکان بەدرستی پڕبکەرەوە', 'danger');
    $redir = $recordId > 0 ? url('professions/cosmetic-center/center/visits/case.php?id=' . $recordId) : url('professions/cosmetic-center/center/visits/new.php');
    redirect($redir);
}

if ($recordId <= 0 && (!is_numeric($priceRaw) || !is_numeric($discountRaw))) {
    setMessage('نرخ و داشکاندنی جەلسەی یەکەم دەبێت ژمارە بن', 'danger');
    redirect(url('professions/cosmetic-center/center/visits/new.php'));
}

$price = round((float)$priceRaw, 2);
$discount = round((float)$discountRaw, 2);

if (!preg_match('/^[0-9+\-\s()]{7,30}$/', $mobile)) {
    setMessage('ژمارەی مۆبایل نادروستە', 'danger');
    redirect(url('professions/cosmetic-center/center/visits/index.php'));
}

if ($recordId > 0) {
    $mxStmt = $conn_kasher_platform->prepare('SELECT COALESCE(MAX(session_number), 0) AS m FROM cosmetic_client_sessions WHERE case_id = ?');
    $maxS = 0;
    if ($mxStmt) {
        $mxStmt->bind_param('i', $recordId);
        $mxStmt->execute();
        $mxRow = $mxStmt->get_result()->fetch_assoc();
        $mxStmt->close();
        $maxS = (int)($mxRow['m'] ?? 0);
    }
    if ($sessionsPlanned < $maxS) {
        setMessage('پلانی جەلسە نابێت کەمتر بێت لە ژمارەی جەلسەی تۆمارکراو (' . $maxS . ')', 'danger');
        redirect(url('professions/cosmetic-center/center/visits/case.php?id=' . $recordId));
    }

    $stmt = $conn_kasher_platform->prepare('
        UPDATE cosmetic_client_cases
        SET client_name = ?, mobile = ?, age = ?, sessions_planned = ?, work_type = ?,
            updated_by_role = ?, updated_by_account_id = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ? AND created_by_role = ? AND created_by_account_id = ?
    ');
    if ($stmt) {
        $stmt->bind_param(
            'ssiissiiisi',
            $clientName,
            $mobile,
            $age,
            $sessionsPlanned,
            $workType,
            $role,
            $centerAccountId,
            $recordId,
            $userId,
            $creatorRole,
            $creatorAccountId
        );
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            setMessage('تۆمار نوێکرایەوە', 'success');
            redirect(url('professions/cosmetic-center/center/visits/case.php?id=' . $recordId));
        }
    }
    setMessage('نوێکردنەوە سەرکەوتوو نەبوو', 'danger');
    redirect(url('professions/cosmetic-center/center/visits/case.php?id=' . $recordId));
}

if ($firstSessionDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstSessionDate)) {
    setMessage('بەرواری جەلسەی یەکەم نادروستە', 'danger');
    redirect(url('professions/cosmetic-center/center/visits/new.php'));
}

$casePrice = 0.0;
$caseDiscount = 0.0;

$conn_kasher_platform->begin_transaction();
try {
    $stmt = $conn_kasher_platform->prepare('
        INSERT INTO cosmetic_client_cases
        (user_id, client_name, age, sessions_planned, work_type, price, discount, mobile,
         created_by_role, created_by_account_id, updated_by_role, updated_by_account_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException('prepare failed');
    }
    $stmt->bind_param(
        'isiissddsisi',
        $userId,
        $clientName,
        $age,
        $sessionsPlanned,
        $workType,
        $casePrice,
        $caseDiscount,
        $mobile,
        $role,
        $centerAccountId,
        $role,
        $centerAccountId
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('insert case failed');
    }
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    $s1 = $conn_kasher_platform->prepare('
        INSERT INTO cosmetic_client_sessions
        (case_id, session_number, session_date, notes, created_by_role, created_by_account_id, price, discount, created_at)
        VALUES (?, 1, ?, NULL, ?, ?, ?, ?, NOW())
    ');
    if (!$s1) {
        throw new RuntimeException('prepare session failed');
    }
    $s1->bind_param('issidd', $newId, $firstSessionDate, $role, $centerAccountId, $price, $discount);
    if (!$s1->execute()) {
        $s1->close();
        throw new RuntimeException('insert session failed');
    }
    $s1->close();

    $conn_kasher_platform->commit();
    setMessage('تۆمار پاشەکەوتکرا', 'success');
    redirect(url('professions/cosmetic-center/center/visits/case.php?id=' . $newId));
} catch (Throwable $e) {
    $conn_kasher_platform->rollback();
    setMessage('پاشەکەوتکردن سەرکەوتوو نەبوو', 'danger');
    redirect(url('professions/cosmetic-center/center/visits/new.php'));
}
