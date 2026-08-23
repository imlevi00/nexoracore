<?php
/**
 * Image attachment endpoint for a consultation's clinical-note sections.
 *
 * Actions (POST):
 *   - upload : store one image against (patient, section). Resolves the target
 *              prescription — an existing prescription_id this doctor owns, or a
 *              find-or-create draft (mirroring prescription_draft.php) so images
 *              can be attached before the consultation is finalized. Returns the
 *              resolved prescription/draft id so the History form can keep its
 *              hidden draft_id in sync with the auto-save.
 *   - delete : remove one attachment the acting doctor owns.
 *
 * JSON: { success, ... } | { success:false, message }.
 */
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/referral_service.php';
require_once dirname(__DIR__) . '/includes/prescription_attachments.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(['success' => false, 'message' => 'Invalid request method'], 405);
}

$conn_kasher_platform = $GLOBALS['conn_kasher_platform'] ?? null;
if (!($conn_kasher_platform instanceof mysqli)) {
    $respond(['success' => false, 'message' => 'Database connection is unavailable'], 500);
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $respond(['success' => false, 'message' => 'Security validation failed'], 419);
}

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];

$action = (string)($_POST['action'] ?? 'upload');

/* --------------------------------------------------------------------------
 * Delete
 * ------------------------------------------------------------------------ */
if ($action === 'delete') {
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    if ($attachmentId <= 0) {
        $respond(['success' => false, 'message' => 'Missing attachment'], 400);
    }
    // The attachment's prescription must belong to this doctor within this tenant.
    $ownStmt = $conn_kasher_platform->prepare("
        SELECT a.prescription_id
        FROM medical_prescription_attachments a
        INNER JOIN medical_prescriptions mp ON mp.id = a.prescription_id
        WHERE a.id = ? AND mp.user_id = ? AND mp.doctor_id = ?
        LIMIT 1
    ");
    $ownStmt->bind_param('iii', $attachmentId, $userId, $doctorId);
    $ownStmt->execute();
    $ownRow = $ownStmt->get_result()->fetch_assoc();
    $ownStmt->close();
    if (!$ownRow) {
        $respond(['success' => false, 'message' => 'Attachment not found'], 404);
    }
    $prescriptionId = (int)$ownRow['prescription_id'];
    if (!medicalRxAttachmentDelete($conn_kasher_platform, $prescriptionId, $attachmentId)) {
        $respond(['success' => false, 'message' => 'Could not delete the image'], 500);
    }
    $respond(['success' => true, 'attachment_id' => $attachmentId]);
}

/* --------------------------------------------------------------------------
 * Upload
 * ------------------------------------------------------------------------ */
$section = (string)($_POST['section'] ?? '');
if (!medicalRxAttachmentIsValidSection($section)) {
    $respond(['success' => false, 'message' => 'Invalid section'], 400);
}

$patientId = (int)($_POST['patient_id'] ?? 0);
if ($patientId <= 0) {
    $respond(['success' => false, 'message' => 'Missing patient'], 400);
}
if (!medicalDoctorCanAccessPatient($conn_kasher_platform, $userId, $doctorId, $patientId)) {
    $respond(['success' => false, 'message' => 'Invalid patient'], 404);
}

if (!isset($_FILES['image'])) {
    $respond(['success' => false, 'message' => 'No image uploaded'], 400);
}

// Resolve the target prescription. Prefer an explicit prescription_id this
// doctor owns for this patient (any status — draft, pending or completed, so
// images can be added while editing a finalized prescription too). Otherwise
// reuse this patient's open draft, or create one so the image has a home.
$prescriptionId = (int)($_POST['prescription_id'] ?? 0);
$resolvedId = 0;
$isDraft = false;

if ($prescriptionId > 0) {
    $checkStmt = $conn_kasher_platform->prepare("
        SELECT id, status FROM medical_prescriptions
        WHERE id = ? AND user_id = ? AND doctor_id = ? AND patient_id = ?
        LIMIT 1
    ");
    $checkStmt->bind_param('iiii', $prescriptionId, $userId, $doctorId, $patientId);
    $checkStmt->execute();
    $checkRow = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    if ($checkRow) {
        $resolvedId = (int)$checkRow['id'];
        $isDraft = ((string)$checkRow['status'] === 'draft');
    }
}

if ($resolvedId <= 0) {
    // Reuse this patient's existing open draft if there is one.
    $findStmt = $conn_kasher_platform->prepare("
        SELECT id FROM medical_prescriptions
        WHERE user_id = ? AND doctor_id = ? AND patient_id = ? AND status = 'draft'
        ORDER BY id DESC LIMIT 1
    ");
    $findStmt->bind_param('iii', $userId, $doctorId, $patientId);
    $findStmt->execute();
    $findRow = $findStmt->get_result()->fetch_assoc();
    $findStmt->close();
    if ($findRow) {
        $resolvedId = (int)$findRow['id'];
        $isDraft = true;
    }
}

if ($resolvedId <= 0) {
    // No draft yet — create an empty one so this image has a place to live. The
    // History auto-save will fill in its text fields; diagnosis defaults to ''.
    $emptyDiagnosis = '';
    $createStmt = $conn_kasher_platform->prepare("
        INSERT INTO medical_prescriptions
        (user_id, doctor_id, patient_id, history, examination, diagnosis, status, created_at, updated_at)
        VALUES (?, ?, ?, NULL, NULL, ?, 'draft', NOW(), NOW())
    ");
    if (!$createStmt) {
        $respond(['success' => false, 'message' => 'Could not start a consultation'], 500);
    }
    $createStmt->bind_param('iiis', $userId, $doctorId, $patientId, $emptyDiagnosis);
    if (!$createStmt->execute()) {
        $createStmt->close();
        $respond(['success' => false, 'message' => 'Could not start a consultation'], 500);
    }
    $resolvedId = (int)$createStmt->insert_id;
    $createStmt->close();
    $isDraft = true;
}

$result = medicalRxAttachmentStore($conn_kasher_platform, $resolvedId, $section, $_FILES['image']);
if (!$result['success']) {
    $respond(['success' => false, 'message' => $result['error'] ?? 'Upload failed'], 400);
}

$respond([
    'success' => true,
    'attachment' => $result['attachment'],
    'prescription_id' => $resolvedId,
    'draft_id' => $isDraft ? $resolvedId : null,
]);
