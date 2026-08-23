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

const COSMETIC_CENTER_SESSION_KEY = 'cosmetic_center_auth';

function cosmeticCenterSession(): ?array
{
    $session = $_SESSION[COSMETIC_CENTER_SESSION_KEY] ?? null;
    return is_array($session) ? $session : null;
}

function isCosmeticCenterLoggedIn(): bool
{
    return cosmeticCenterSession() !== null;
}

function requireCosmeticCenterAuth(): void
{
    if (!isCosmeticCenterLoggedIn()) {
        redirect(url('professions/cosmetic-center/center/auth/login.php'));
    }
}

function cosmeticCenterLogout(): void
{
    unset($_SESSION[COSMETIC_CENTER_SESSION_KEY]);
    session_regenerate_id(true);
}

function setCosmeticCenterSession(array $data): void
{
    $_SESSION[COSMETIC_CENTER_SESSION_KEY] = [
        'center_account_id' => (int)$data['center_account_id'],
        'user_id' => (int)$data['user_id'],
        'name' => (string)$data['name'],
        'email' => (string)$data['email'],
        'mobile' => (string)$data['mobile'],
        'logged_at' => date('Y-m-d H:i:s'),
    ];
    session_regenerate_id(true);
}
