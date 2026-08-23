<?php
require_once dirname(__DIR__, 4) . '/config/config.php';
require_once dirname(__DIR__, 4) . '/config/security.php';
require_once dirname(__DIR__, 4) . '/config/kasher_platform/database.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    setMessage('داتابەیسی kasher_platform بەردەست نییە', 'danger');
    redirect(url('user/auth/login.php'));
}

const MEDICAL_SECRETARY_SESSION_KEY = 'medical_secretary_auth';

function medicalSecretarySession(): ?array
{
    $session = $_SESSION[MEDICAL_SECRETARY_SESSION_KEY] ?? null;
    return is_array($session) ? $session : null;
}

function isMedicalSecretaryLoggedIn(): bool
{
    return medicalSecretarySession() !== null;
}

function requireMedicalSecretaryAuth(): void
{
    if (!isMedicalSecretaryLoggedIn()) {
        redirect(url('professions/medical-center/secretary/auth/login.php'));
    }
}

function medicalSecretaryLogout(): void
{
    unset($_SESSION[MEDICAL_SECRETARY_SESSION_KEY]);
    session_regenerate_id(true);
}

function setMedicalSecretarySession(array $data): void
{
    $_SESSION[MEDICAL_SECRETARY_SESSION_KEY] = [
        'secretary_id' => (int)$data['secretary_id'],
        'user_id' => (int)$data['user_id'],
        'doctor_id' => (int)$data['doctor_id'],
        'name' => (string)$data['name'],
        'email' => (string)$data['email'],
        'logged_at' => date('Y-m-d H:i:s')
    ];
    session_regenerate_id(true);
}
