<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('Security validation failed. Please try again.', 'danger');
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}

$session = medicalDoctorSession();
$userId = (int)$session['user_id'];
$doctorId = (int)$session['doctor_id'];
$patientId = (int)($_POST['patient_id'] ?? 0);
$newStatus = trim((string)($_POST['status'] ?? ''));

$dashboardUrl = url('professions/medical-center/doctor/dashboard/index.php');

if ($patientId <= 0 || $newStatus !== 'completed') {
    setMessage('Invalid request.', 'danger');
    redirect($dashboardUrl);
}

$stmt = $conn_kasher_platform->prepare("
    SELECT visit_status
    FROM medical_center_patients
    WHERE id = ? AND user_id = ? AND doctor_id = ?
    LIMIT 1
");
if (!$stmt) {
    setMessage('Something went wrong.', 'danger');
    redirect($dashboardUrl);
}

$stmt->bind_param('iii', $patientId, $userId, $doctorId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    setMessage('Patient not found.', 'danger');
    redirect($dashboardUrl);
}

$currentStatus = (string)$row['visit_status'];
if (!medicalCenterIsValidVisitStatusTransition($currentStatus, $newStatus, 'doctor')) {
    setMessage('Status change not allowed.', 'danger');
    redirect($dashboardUrl);
}

$updateStmt = $conn_kasher_platform->prepare("
    UPDATE medical_center_patients
    SET visit_status = ?, visit_status_updated_at = NOW(), updated_at = NOW()
    WHERE id = ? AND user_id = ? AND doctor_id = ? AND visit_status = ?
");
if (!$updateStmt) {
    setMessage('Something went wrong.', 'danger');
    redirect($dashboardUrl);
}

$updateStmt->bind_param('siiis', $newStatus, $patientId, $userId, $doctorId, $currentStatus);
$ok = $updateStmt->execute();
$affected = $updateStmt->affected_rows;
$updateStmt->close();

if ($ok && $affected > 0) {
    setMessage('Patient visit marked complete.', 'success');
    redirect($dashboardUrl);
}

setMessage('Status change failed.', 'danger');
redirect($dashboardUrl);
