<?php
/**
 * Re-queue a returning patient.
 *
 * When a patient who has visited before comes back, the secretary re-sends the
 * SAME patient record to a doctor instead of creating a fresh one. Because every
 * prescription and lab order is linked to this patient row's id, reusing the row
 * keeps the whole prior history attached, so the doctor immediately sees what the
 * patient was treated for before (the doctor's create screens render the visit
 * history keyed on patient_id + doctor_id).
 */
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/secretary_service.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$dashboardUrl = url('professions/medical-center/secretary/dashboard/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($dashboardUrl);
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە', 'danger');
    redirect($dashboardUrl);
}

$session = medicalSecretarySession();
$userId = (int)$session['user_id'];
$secretaryId = (int)$session['secretary_id'];

$patientId = (int)($_POST['patient_id'] ?? 0);
$doctorId = (int)($_POST['doctor_id'] ?? 0);

if ($patientId <= 0) {
    setMessage('داواکاری نادروستە', 'danger');
    redirect($dashboardUrl);
}

if ($doctorId <= 0 || !isDoctorAssignedToSecretary($conn_kasher_platform, $userId, $secretaryId, $doctorId)) {
    setMessage('دکتۆری هەڵبژێردراو نادروستە یان مۆڵەتت نییە', 'danger');
    redirect($dashboardUrl);
}

// A re-send starts a fresh visit, so it can carry a fresh appointment slot.
$appointmentDate = medicalCenterNormalizeAppointmentDate((string)($_POST['appointment_date'] ?? ''));
$appointmentTime = medicalCenterNormalizeAppointmentTime((string)($_POST['appointment_time'] ?? ''));
$appointmentDuration = medicalCenterNormalizeAppointmentDuration((string)($_POST['appointment_duration'] ?? ''));
$appointmentEndTime = null;
if ($appointmentTime !== null) {
    if ($appointmentDate === null) {
        $appointmentDate = date('Y-m-d');
    }
    $appointmentEndTime = medicalCenterComputeAppointmentEndTime($appointmentTime, $appointmentDuration);
} else {
    $appointmentDate = null;
}

// The patient must belong to this secretary (same guard the rest of the portal uses).
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
if ($currentStatus === 'waiting' || $currentStatus === 'with_doctor') {
    setMessage('ئەم نەخۆشە ئێستا لە ڕیزی چاوەڕوانیدایە یان لای دکتۆرە', 'warning');
    redirect($dashboardUrl);
}

// Re-queue the SAME patient row so all previous history stays linked to it.
$updateStmt = $conn_kasher_platform->prepare("
    UPDATE medical_center_patients
    SET doctor_id = ?, visit_status = 'waiting', visit_status_updated_at = NOW(),
        appointment_date = ?, appointment_time = ?, appointment_end_time = ?, updated_at = NOW()
    WHERE id = ? AND user_id = ? AND created_by_secretary_id = ? AND visit_status = ?
");
if (!$updateStmt) {
    setMessage('هەڵەیەک ڕوویدا', 'danger');
    redirect($dashboardUrl);
}
$updateStmt->bind_param(
    'isssiiis',
    $doctorId, $appointmentDate, $appointmentTime, $appointmentEndTime,
    $patientId, $userId, $secretaryId, $currentStatus
);
$ok = $updateStmt->execute();
$affected = $updateStmt->affected_rows;
$updateStmt->close();

if ($ok && $affected > 0) {
    setMessage('نەخۆشی پێشوو گەڕایەوە بۆ ڕیزی چاوەڕوانی لەگەڵ مێژووی پێشووی', 'success');
    redirect($dashboardUrl);
}

setMessage('گەڕاندنەوەی نەخۆش سەرکەوتوو نەبوو', 'danger');
redirect($dashboardUrl);
