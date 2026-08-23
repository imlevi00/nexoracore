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
$creator = cosmetic_case_creator_from_doctor_session($session);
$recordId = (int)($_POST['record_id'] ?? 0);

if ($recordId <= 0) {
    setMessage('ناسنامە نادروستە', 'danger');
    redirect(url('professions/cosmetic-center/doctor/visits/index.php'));
}

$stmt = $conn_kasher_platform->prepare(
    'DELETE FROM cosmetic_client_cases WHERE id = ? AND user_id = ? AND created_by_role = ? AND created_by_account_id = ?'
);
$creatorRole = $creator['role'];
$creatorAccountId = $creator['account_id'];
$stmt->bind_param('iisi', $recordId, $userId, $creatorRole, $creatorAccountId);
if ($stmt->execute()) {
    setMessage('سڕایەوە', 'success');
}
$stmt->close();
redirect(url('professions/cosmetic-center/doctor/visits/index.php'));
