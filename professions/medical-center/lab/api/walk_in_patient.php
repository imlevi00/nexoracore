<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_api_helpers.php';
require_once dirname(__DIR__) . '/includes/lab_order_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    labJsonRespond(false, [], 'پەیوەندی داتابەیس بەردەست نییە', 500);
}
/** @var mysqli $conn_kasher_platform */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    labJsonRespond(false, [], 'ڕێگەی داواکاری نادروست', 405);
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    labJsonRespond(false, [], 'نادروستی ئامنیەتی', 403);
}

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];

$action = (string)($_POST['action'] ?? '');
if ($action !== 'lookup_by_mobile') {
    labJsonRespond(false, [], 'کردار نادروست', 400);
}

$mobile = trim((string)($_POST['mobile'] ?? ''));
if ($mobile === '' || !preg_match('/^[0-9+\-\s()]{7,30}$/', $mobile)) {
    labJsonRespond(false, [], 'ژمارەی مۆبایل نادروستە');
}

$patient = labFindWalkInPatientByMobile($conn_kasher_platform, $userId, $labId, $mobile);
if ($patient === null) {
    labJsonRespond(true, ['found' => false], 'نەخۆش نەدۆزرایەوە');
}

labJsonRespond(true, [
    'found' => true,
    'patient' => [
        'id' => (int)$patient['id'],
        'name' => (string)$patient['name'],
        'mobile' => (string)$patient['mobile'],
        'age' => (int)$patient['age'],
        'age_months' => $patient['age_months'] !== null ? (int)$patient['age_months'] : null,
        'gender' => $patient['gender'],
    ],
], 'نەخۆش دۆزرایەوە');
