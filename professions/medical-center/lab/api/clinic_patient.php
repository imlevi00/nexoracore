<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_api_helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
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
$userId = (int)$session['user_id'];

$action = (string)($_POST['action'] ?? '');
if ($action !== 'search') {
    labJsonRespond(false, [], 'کردار نادروست', 400);
}

$q = trim((string)($_POST['q'] ?? ''));
$patients = labSearchClinicPatients($conn_kasher_platform, $userId, $q, 20);

$results = [];
foreach ($patients as $patient) {
    $results[] = [
        'id' => (int)$patient['id'],
        'name' => (string)$patient['name'],
        'mobile' => (string)$patient['mobile'],
        'age_label' => medicalCenterPatientAgeLabel(
            (int)$patient['age'],
            isset($patient['age_months']) ? (int)$patient['age_months'] : null,
            'ku'
        ),
        'gender_label' => medicalCenterPatientGenderLabel($patient['gender'] ?? null, 'ku'),
        'doctor_name' => trim((string)($patient['doctor_name'] ?? '')),
    ];
}

labJsonRespond(true, ['patients' => $results], '');
