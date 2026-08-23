<?php
/**
 * دەرچوونی بەکارهێنەر - user/auth/logout.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

// وەرگرتنی زانیاری بەکارهێنەر بۆ logging
$currentUser = getCurrentUser();

// Log بۆ logout
if ($currentUser) {
    writeLog("User logout: {$currentUser['email']} from IP: " . $_SERVER['REMOTE_ADDR']);
}

// سڕینەوەی Remember Me token
if (isset($_COOKIE['remember_me'])) {
    $cookieParts = explode(':', $_COOKIE['remember_me'], 2);
    if (count($cookieParts) === 2) {
        $selector = $cookieParts[0];
        Security::deleteRememberToken($selector);
    }
    // سڕینەوەی cookie
    setcookie('remember_me', '', time() - 3600, '/', '', true, true);
}

// سڕینەوەی هەموو token ەکانی ئەم ناسنامەیە لە database (main + legacy sub + sub)
if ($currentUser && isset($currentUser['id'])) {
    $mainId = (int) $currentUser['id'];
    Security::deleteRememberToken(null, $mainId, 'user');
    if (!empty($currentUser['sub_user_id'])) {
        $sid = (int) $currentUser['sub_user_id'];
        Security::deleteRememberToken(null, $sid, 'sub');
        Security::deleteRememberToken(null, $sid, 'user'); // legacy: sub id stored as user_type=user
    }
}

// دەرچوون
SessionManager::logout('user');

// نیشاندانی پەیام
setMessage('بە سەرکەوتوویی دەرچوویت', 'success');

// redirect بکە بۆ login page
redirect(url('user/auth/login.php'));
?>