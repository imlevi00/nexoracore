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

const COSMETIC_DOCTOR_SESSION_KEY = 'cosmetic_doctor_auth';

function cosmeticDoctorSession(): ?array
{
    $session = $_SESSION[COSMETIC_DOCTOR_SESSION_KEY] ?? null;
    return is_array($session) ? $session : null;
}

function isCosmeticDoctorLoggedIn(): bool
{
    return cosmeticDoctorSession() !== null;
}

function requireCosmeticDoctorAuth(): void
{
    if (!isCosmeticDoctorLoggedIn()) {
        redirect(url('professions/cosmetic-center/doctor/auth/login.php'));
    }
}

function cosmeticDoctorLogout(): void
{
    unset($_SESSION[COSMETIC_DOCTOR_SESSION_KEY]);
    session_regenerate_id(true);
}

function setCosmeticDoctorSession(array $data): void
{
    $_SESSION[COSMETIC_DOCTOR_SESSION_KEY] = [
        'doctor_account_id' => (int)$data['doctor_account_id'],
        'user_id' => (int)$data['user_id'],
        'name' => (string)$data['name'],
        'email' => (string)$data['email'],
        'mobile' => (string)$data['mobile'],
        'logged_at' => date('Y-m-d H:i:s'),
    ];
    session_regenerate_id(true);
}
