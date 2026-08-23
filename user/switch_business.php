<?php
/**
 * گۆڕینی بزنسی چالاک (Multi-Business Switcher)
 * user/switch_business.php
 *
 * تەنها خاوەنی ڕێکخراو (main-user) دەتوانێت بگۆڕێت، تەنها بۆ بزنسێک کە سەر بە
 * هەمان ڕێکخراوە. کارمەند (sub-user) ڕێگەپێنەدراوە.
 */

require_once '../config/config.php';
require_once '../config/security.php';

SessionManager::requireAuth('user');

// تەنها POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('user/dashboard/index.php'));
}

// CSRF
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('نادروستی ئامنیەتی. تکایە دووبارە هەوڵ بدەرەوە.', 'danger');
    redirect(url('user/dashboard/index.php'));
}

$currentUser = getCurrentUser();

// کارمەند ناتوانێت بگۆڕێت
if (($currentUser['user_type'] ?? 'main') === 'sub') {
    setMessage('تەنها خاوەن دەتوانێت بزنس بگۆڕێت', 'danger');
    redirect(url('user/dashboard/index.php'));
}

$ctx = getBusinessSwitchContext();
if ($ctx === null) {
    setMessage('ئەم ئەکاونتە ڕێکخراوی چەندین بزنسی نییە', 'warning');
    redirect(url('user/dashboard/index.php'));
}

$requestedId = (int)($_POST['business_id'] ?? 0);

// پشتڕاستکردنەوە: بزنسەکە دەبێت سەر بە هەمان ڕێکخراو بێت
$target = null;
foreach ($ctx['businesses'] as $biz) {
    if ($biz['id'] === $requestedId) {
        $target = $biz;
        break;
    }
}

if ($target === null) {
    setMessage('بزنسی هەڵبژێردراو نادروستە', 'danger');
    redirect(url('user/dashboard/index.php'));
}

// پشکنینی دۆخ و بەسەرچوون
if (($target['status'] ?? '') !== 'approved') {
    setMessage('ئەم بزنسە چالاک نییە', 'warning');
    redirect(url('user/dashboard/index.php'));
}
if (!empty($target['expiration_date']) && strtotime($target['expiration_date']) < time()) {
    setMessage('ماوەی ئەم بزنسە بەسەرچووە', 'warning');
    redirect(url('user/dashboard/index.php'));
}

// گۆڕینی بزنسی چالاک — لایەری business_context لەسەر داواکاری داهاتوو re-scope دەکات،
// بەڵام ئێستاش دەیخەینە سێشن بۆ یەکسانی.
$_SESSION['active_business_id'] = $requestedId;
$_SESSION['user_data']['id'] = $requestedId;

setMessage('گۆڕدرا بۆ: ' . ($target['business_name'] ?? ''), 'success');
redirect(url('user/dashboard/index.php'));
