<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('professions/medical-center/secretary/dashboard/index.php'));
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە', 'danger');
    redirect(url('professions/medical-center/secretary/dashboard/index.php'));
}

$session = medicalSecretarySession();
$userId = (int)$session['user_id'];
$secretaryId = (int)$session['secretary_id'];
$patientId = (int)($_POST['patient_id'] ?? 0);
$newStatus = trim((string)($_POST['status'] ?? ''));

$dashboardUrl = url('professions/medical-center/secretary/dashboard/index.php');

if ($patientId <= 0 || $newStatus !== 'with_doctor') {
    setMessage('داواکاری نادروستە', 'danger');
    redirect($dashboardUrl);
}

$stmt = $conn_kasher_platform->prepare("
    SELECT visit_status
    FROM medical_center_patients
    WHERE id = ? AND user_id = ? AND created_by_secretary_id = ?
    LIMIT 1
");
if (!$stmt) {
    setMessage('هەڵەیەک ڕوویدا', 'danger');
    redirect($dashboardUrl);
}

$stmt->bind_param('iii', $patientId, $userId, $secretaryId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    setMessage('نەخۆش نەدۆزرایەوە', 'danger');
    redirect($dashboardUrl);
}

$currentStatus = (string)$row['visit_status'];
if (!medicalCenterIsValidVisitStatusTransition($currentStatus, $newStatus, 'secretary')) {
    setMessage('گۆڕینی دۆخ مۆڵەت نییە', 'danger');
    redirect($dashboardUrl);
}

$updateStmt = $conn_kasher_platform->prepare("
    UPDATE medical_center_patients
    SET visit_status = ?, visit_status_updated_at = NOW(), updated_at = NOW()
    WHERE id = ? AND user_id = ? AND created_by_secretary_id = ? AND visit_status = ?
");
if (!$updateStmt) {
    setMessage('هەڵەیەک ڕوویدا', 'danger');
    redirect($dashboardUrl);
}

$updateStmt->bind_param('siiis', $newStatus, $patientId, $userId, $secretaryId, $currentStatus);
$ok = $updateStmt->execute();
$affected = $updateStmt->affected_rows;
$updateStmt->close();

if ($ok && $affected > 0) {
    setMessage('نەخۆش نێردرا بۆ لای دکتۆر', 'success');
    redirect($dashboardUrl);
}

setMessage('گۆڕینی دۆخ سەرکەوتوو نەبوو', 'danger');
redirect($dashboardUrl);
