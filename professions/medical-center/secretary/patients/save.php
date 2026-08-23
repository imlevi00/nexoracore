<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/secretary_service.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
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
$doctorId = (int)($_POST['doctor_id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$mobile = trim((string)($_POST['mobile'] ?? ''));
$age = (int)($_POST['age'] ?? -1);
$ageMonthsInput = (string)($_POST['age_months'] ?? '');
$ageMonths = medicalCenterNormalizePatientAgeMonths($ageMonthsInput);
$gender = medicalCenterNormalizePatientGender((string)($_POST['gender'] ?? ''));
$profession = medicalCenterNormalizePatientProfession((string)($_POST['profession'] ?? ''));
$bloodType = medicalCenterNormalizePatientBloodType((string)($_POST['blood_type'] ?? ''));
$address = medicalCenterNormalizePatientAddress((string)($_POST['address'] ?? ''));

// --- Appointment (date + time slot) ---------------------------------------
// Date defaults to today; time is the important part. The end time is derived
// from the chosen slot length so the schedule stays consistent and predictable.
$appointmentDate = medicalCenterNormalizeAppointmentDate((string)($_POST['appointment_date'] ?? ''));
$appointmentTime = medicalCenterNormalizeAppointmentTime((string)($_POST['appointment_time'] ?? ''));
$appointmentDuration = medicalCenterNormalizeAppointmentDuration((string)($_POST['appointment_duration'] ?? ''));
$appointmentEndTime = null;

if ($appointmentTime !== null) {
    // A time without a date is meaningless — assume today so it never gets lost.
    if ($appointmentDate === null) {
        $appointmentDate = date('Y-m-d');
    }
    $appointmentEndTime = medicalCenterComputeAppointmentEndTime($appointmentTime, $appointmentDuration);
} else {
    // No start time → no appointment; do not keep a dangling date.
    $appointmentDate = null;
}

$redirectOnError = $patientId > 0
    ? url('professions/medical-center/secretary/patients/index.php?edit=' . $patientId)
    : url('professions/medical-center/secretary/patients/index.php');

if ($name === '' || $mobile === '' || $age < 0 || $age > 130 || $gender === null) {
    setMessage('تکایە زانیاریی دروست بنووسە', 'danger');
    redirect($redirectOnError);
}

if (trim($ageMonthsInput) !== '' && $ageMonths === null) {
    setMessage('ژمارەی مانگ دەبێت لە نێوان 0 تا 11 بێت', 'danger');
    redirect($redirectOnError);
}

if (!preg_match('/^[0-9+\-\s()]{7,30}$/', $mobile)) {
    setMessage('ژمارەی مۆبایل نادروستە', 'danger');
    redirect($redirectOnError);
}

if ($doctorId <= 0 || !isDoctorAssignedToSecretary($conn_kasher_platform, $userId, $secretaryId, $doctorId)) {
    setMessage('دکتۆری هەڵبژێردراو نادروستە یان مۆڵەتت نییە', 'danger');
    redirect($redirectOnError);
}

if ($patientId > 0) {
    $stmt = $conn_kasher_platform->prepare("
        UPDATE medical_center_patients
        SET doctor_id = ?, name = ?, mobile = ?, age = ?, age_months = ?, gender = ?,
            profession = ?, blood_type = ?, address = ?,
            appointment_date = ?, appointment_time = ?, appointment_end_time = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ? AND created_by_secretary_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param(
            'issiisssssssiii',
            $doctorId, $name, $mobile, $age, $ageMonths, $gender,
            $profession, $bloodType, $address,
            $appointmentDate, $appointmentTime, $appointmentEndTime,
            $patientId, $userId, $secretaryId
        );
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($ok && $affected >= 0) {
            setMessage('زانیاری نەخۆش نوێکرایەوە', 'success');
            redirect(url('professions/medical-center/secretary/patients/index.php'));
        }
    }
    setMessage('هەڵەیەک ڕوویدا لە نوێکردنەوە', 'danger');
    redirect(url('professions/medical-center/secretary/patients/index.php'));
}

$stmt = $conn_kasher_platform->prepare("
    INSERT INTO medical_center_patients
    (user_id, doctor_id, created_by_secretary_id, name, mobile, age, age_months, gender,
     profession, blood_type, address,
     appointment_date, appointment_time, appointment_end_time, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");
if ($stmt) {
    $stmt->bind_param(
        'iiissiisssssss',
        $userId, $doctorId, $secretaryId, $name, $mobile, $age, $ageMonths, $gender,
        $profession, $bloodType, $address,
        $appointmentDate, $appointmentTime, $appointmentEndTime
    );
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        setMessage('نەخۆش بە سەرکەوتوویی زیادکرا', 'success');
        $redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
        if ($redirectTo === 'dashboard') {
            redirect(url('professions/medical-center/secretary/dashboard/index.php'));
        }
        redirect(url('professions/medical-center/secretary/patients/index.php'));
    }
}

setMessage('هەڵەیەک ڕوویدا لە زیادکردن', 'danger');
redirect(url('professions/medical-center/secretary/patients/index.php'));
