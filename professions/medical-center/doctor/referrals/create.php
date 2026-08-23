<?php
/**
 * Create a doctor-to-doctor referral (POST only).
 *
 * The referring doctor picks a target doctor (any other doctor in the tenant) and
 * optionally a note, from the "Send To → Referral" control on the patient History
 * page. On success the patient appears in the target doctor's Referrals list.
 *
 * The referring doctor must be allowed to work on the patient (own it, or already
 * have it referred to them) — this also lets a specialist re-refer onward.
 */
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/referral_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];

$patientHistoryUrl = static function (int $patientId): string {
    return url('professions/medical-center/doctor/patients/history.php?patient_id=' . $patientId);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('Security validation failed. Please try again.', 'danger');
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}

$patientId = (int)($_POST['patient_id'] ?? 0);
$toDoctorId = (int)($_POST['to_doctor_id'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));
if (mb_strlen($note) > 1000) {
    $note = mb_substr($note, 0, 1000);
}

// The current doctor must be able to work on this patient (owned or referred to).
$patient = medicalDoctorFetchAccessiblePatient($conn_kasher_platform, $userId, $doctorId, $patientId);
if ($patient === null) {
    setMessage('Selected patient is invalid or not accessible.', 'danger');
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}

$backUrl = $patientHistoryUrl($patientId);

if ($toDoctorId <= 0 || $toDoctorId === $doctorId) {
    setMessage('Please choose a different doctor to refer the patient to.', 'danger');
    redirect($backUrl);
}

// Target doctor must be a real doctor in this tenant.
$docStmt = $conn_kasher_platform->prepare("
    SELECT id, name FROM doctors WHERE id = ? AND user_id = ? LIMIT 1
");
if (!$docStmt) {
    setMessage('Something went wrong while validating the doctor.', 'danger');
    redirect($backUrl);
}
$docStmt->bind_param('ii', $toDoctorId, $userId);
$docStmt->execute();
$targetDoctor = $docStmt->get_result()->fetch_assoc() ?: null;
$docStmt->close();
if ($targetDoctor === null) {
    setMessage('Selected doctor is invalid.', 'danger');
    redirect($backUrl);
}

// Avoid piling up duplicate pending referrals for the same patient/target pair.
$dupStmt = $conn_kasher_platform->prepare("
    SELECT id FROM medical_referrals
    WHERE user_id = ? AND patient_id = ? AND to_doctor_id = ? AND status = 'pending'
    LIMIT 1
");
$dupStmt->bind_param('iii', $userId, $patientId, $toDoctorId);
$dupStmt->execute();
$existing = $dupStmt->get_result()->fetch_assoc() ?: null;
$dupStmt->close();

$targetName = (string)$targetDoctor['name'];
$noteValue = $note !== '' ? $note : null;

if ($existing !== null) {
    // Refresh the note/timestamp on the still-open referral instead of duplicating.
    $updStmt = $conn_kasher_platform->prepare("
        UPDATE medical_referrals
        SET note = ?, from_doctor_id = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $existingId = (int)$existing['id'];
    $updStmt->bind_param('siii', $noteValue, $doctorId, $existingId, $userId);
    $updStmt->execute();
    $updStmt->close();
    setMessage("This patient is already referred to {$targetName}. Details updated.", 'info');
    redirect($backUrl);
}

$insStmt = $conn_kasher_platform->prepare("
    INSERT INTO medical_referrals
    (user_id, patient_id, from_doctor_id, to_doctor_id, note, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
");
if (!$insStmt) {
    setMessage('Something went wrong while creating the referral.', 'danger');
    redirect($backUrl);
}
$insStmt->bind_param('iiiis', $userId, $patientId, $doctorId, $toDoctorId, $noteValue);
if ($insStmt->execute()) {
    $insStmt->close();
    setMessage("Patient referred to {$targetName}.", 'success');
} else {
    $insStmt->close();
    setMessage('Something went wrong while creating the referral.', 'danger');
}
redirect($backUrl);
