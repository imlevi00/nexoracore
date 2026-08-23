<?php
require_once dirname(__DIR__, 4) . '/config/config.php';
require_once dirname(__DIR__, 4) . '/config/database.php';
require_once dirname(__DIR__, 4) . '/config/security.php';
require_once dirname(__DIR__, 4) . '/config/kasher_platform/database.php';

if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
    $db = new Database();
    $GLOBALS['conn'] = $db->connect();
}
$conn = $GLOBALS['conn'];

if (!($conn_kasher_platform instanceof mysqli)) {
    setMessage('داتابەیسی kasher_platform بەردەست نییە', 'danger');
    redirect(url('user/auth/login.php'));
}

const MEDICAL_DOCTOR_SESSION_KEY = 'medical_doctor_auth';

function medicalDoctorSession(): ?array
{
    $session = $_SESSION[MEDICAL_DOCTOR_SESSION_KEY] ?? null;
    return is_array($session) ? $session : null;
}

function isMedicalDoctorLoggedIn(): bool
{
    return medicalDoctorSession() !== null;
}

function requireMedicalDoctorAuth(): void
{
    if (!isMedicalDoctorLoggedIn()) {
        redirect(url('professions/medical-center/doctor/auth/login.php'));
    }
}

function medicalDoctorLogout(): void
{
    unset($_SESSION[MEDICAL_DOCTOR_SESSION_KEY]);
    session_regenerate_id(true);
}

function setMedicalDoctorSession(array $data): void
{
    $_SESSION[MEDICAL_DOCTOR_SESSION_KEY] = [
        'doctor_id' => (int)$data['doctor_id'],
        'user_id' => (int)$data['user_id'],
        'name' => (string)$data['name'],
        'email' => (string)$data['email'],
        'mobile' => (string)$data['mobile'],
        'logged_at' => date('Y-m-d H:i:s')
    ];
    session_regenerate_id(true);
}
