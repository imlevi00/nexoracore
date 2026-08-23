<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('professions/medical-center/secretary/patients/index.php'));
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە', 'danger');
    redirect(url('professions/medical-center/secretary/patients/index.php'));
}

$session = medicalSecretarySession();
$userId = (int)$session['user_id'];
$secretaryId = (int)$session['secretary_id'];
$patientId = (int)($_POST['patient_id'] ?? 0);

if ($patientId <= 0) {
    setMessage('ناسنامەی نەخۆش نادروستە', 'danger');
    redirect(url('professions/medical-center/secretary/patients/index.php'));
}

$stmt = $conn_kasher_platform->prepare("
    DELETE FROM medical_center_patients
    WHERE id = ? AND user_id = ? AND created_by_secretary_id = ?
");
if ($stmt) {
    $stmt->bind_param('iii', $patientId, $userId, $secretaryId);
    $stmt->execute();
    $deletedRows = $stmt->affected_rows;
    $stmt->close();
    if ($deletedRows > 0) {
        setMessage('نەخۆش بە سەرکەوتوویی سڕایەوە', 'success');
    } else {
        setMessage('نەخۆش نەدۆزرایەوە یان مافی سڕینەوەت نییە', 'warning');
    }
    redirect(url('professions/medical-center/secretary/patients/index.php'));
}

setMessage('هەڵەیەک ڕوویدا لە سڕینەوە', 'danger');
redirect(url('professions/medical-center/secretary/patients/index.php'));
