<?php
/**
 * Mark an incoming referral as completed (POST only).
 *
 * Only the target doctor of a pending referral may complete it. Completing it
 * removes the patient from that doctor's Referrals list; the clinical records the
 * doctor created for the patient remain untouched.
 */
require_once dirname(__DIR__) . '/includes/auth_guard.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];

$referralsUrl = url('professions/medical-center/doctor/referrals/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($referralsUrl);
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('Security validation failed. Please try again.', 'danger');
    redirect($referralsUrl);
}

$referralId = (int)($_POST['referral_id'] ?? 0);
if ($referralId <= 0) {
    setMessage('Invalid referral.', 'danger');
    redirect($referralsUrl);
}

$stmt = $conn_kasher_platform->prepare("
    UPDATE medical_referrals
    SET status = 'completed', completed_at = NOW(), updated_at = NOW()
    WHERE id = ? AND user_id = ? AND to_doctor_id = ? AND status = 'pending'
");
if ($stmt) {
    $stmt->bind_param('iii', $referralId, $userId, $doctorId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected > 0) {
        setMessage('Referral marked as completed.', 'success');
    } else {
        setMessage('Referral could not be updated.', 'warning');
    }
}
redirect($referralsUrl);
