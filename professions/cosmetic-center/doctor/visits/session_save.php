<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__, 2) . '/includes/cosmetic_case_scope.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('professions/cosmetic-center/doctor/visits/index.php'));
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('نادروستی ئامنیەتی', 'danger');
    redirect(url('professions/cosmetic-center/doctor/visits/index.php'));
}

$session = cosmeticDoctorSession();
$userId = (int)$session['user_id'];
$doctorAccountId = (int)$session['doctor_account_id'];
$creator = cosmetic_case_creator_from_doctor_session($session);
$creatorRole = $creator['role'];
$creatorAccountId = $creator['account_id'];
$role = 'doctor';

$caseId = (int)($_POST['case_id'] ?? 0);
$sessionDate = trim((string)($_POST['session_date'] ?? ''));
$notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);
$priceRaw = str_replace(',', '.', trim((string)($_POST['price'] ?? '')));
$discountRaw = str_replace(',', '.', trim((string)($_POST['discount'] ?? '')));

if ($caseId <= 0 || $sessionDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
    setMessage('داتای نادروست', 'danger');
    redirect(url('professions/cosmetic-center/doctor/visits/index.php'));
}

if (!is_numeric($priceRaw) || !is_numeric($discountRaw)) {
    setMessage('نرخ و داشکاندن دەبێت ژمارە بن', 'danger');
    redirect(url('professions/cosmetic-center/doctor/visits/case.php?id=' . $caseId));
}

$price = round((float)$priceRaw, 2);
$discount = round((float)$discountRaw, 2);

$cst = $conn_kasher_platform->prepare(
    'SELECT id, sessions_planned FROM cosmetic_client_cases WHERE id = ? AND user_id = ? AND created_by_role = ? AND created_by_account_id = ? LIMIT 1'
);
$cst->bind_param('iisi', $caseId, $userId, $creatorRole, $creatorAccountId);
$cst->execute();
$crow = $cst->get_result()->fetch_assoc();
$cst->close();
if (!$crow) {
    setMessage('کەیس نەدۆزرایەوە', 'danger');
    redirect(url('professions/cosmetic-center/doctor/visits/index.php'));
}

$planned = (int)$crow['sessions_planned'];

$mx = $conn_kasher_platform->prepare('SELECT COALESCE(MAX(session_number), 0) AS m FROM cosmetic_client_sessions WHERE case_id = ?');
$mx->bind_param('i', $caseId);
$mx->execute();
$mrow = $mx->get_result()->fetch_assoc();
$mx->close();
$next = (int)($mrow['m'] ?? 0) + 1;

if ($next > $planned) {
    setMessage('ناتوانرێت جەلسەی زیاتر لە پلان تۆمار بکرێت', 'danger');
    redirect(url('professions/cosmetic-center/doctor/visits/case.php?id=' . $caseId));
}

$ins = $conn_kasher_platform->prepare('
    INSERT INTO cosmetic_client_sessions (case_id, session_number, session_date, notes, created_by_role, created_by_account_id, price, discount, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
');
$ins->bind_param('iisssidd', $caseId, $next, $sessionDate, $notes, $role, $doctorAccountId, $price, $discount);
if ($ins->execute()) {
    setMessage('جەلسە تۆمارکرا', 'success');
}
$ins->close();
redirect(url('professions/cosmetic-center/doctor/visits/case.php?id=' . $caseId));
