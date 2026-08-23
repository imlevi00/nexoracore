<?php
/**
 * دانانی ڕێژەی ئاڵوگۆڕکردنی دراوە (دۆلار → دینار) بۆ POS — ajax/set_exchange_rate.php
 *
 * نرخەکە لە خشتەی currency_exchange_rates تۆمار دەکرێت بەپێی user_id، کە بۆ
 * کارمەندەکانیش (sub_user) هەمان ID ی ئەکاونتی سەرەکییە. بۆیە ئەم نرخە تەنها
 * کاریگەری لەسەر ئەم ئەکاونتە و کارمەندەکانی دەبێت — نەک لەسەر تەواوی سیستەم
 * (خشتەی dollar_prices کە بەکارهێنەر هەرگیز لێرەوە دەستکاری ناکات).
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id']; // بۆ sub_user دا ئەمە ID ی ئەکاونتی سەرەکییە

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

/**
 * ناردنی وەڵامی JSON
 */
function respondJson($success, $data = [], $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'timestamp' => date('c'),
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(false, [], 'شێوازی داواکاری نادروستە', 405);
}

// خوێندنەوەی داتا (JSON یان form-encoded)
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// تاقیکردنەوەی CSRF
if (empty($input['csrf_token']) || !Security::validateCSRFToken($input['csrf_token'])) {
    respondJson(false, [], 'CSRF token نادروستە', 403);
}

// تاقیکردنەوەی نرخ
$rawRate = $input['exchange_rate'] ?? null;
if ($rawRate === null || $rawRate === '' || !is_numeric($rawRate)) {
    respondJson(false, [], 'تکایە نرخێکی دروست بنووسە', 400);
}

$rate = (float)$rawRate;

// مەودای پەسەندکراو — هاوتا لەگەڵ پشکنینی pos_bootstrap.php (> 100 و < 10000)
if ($rate <= 100 || $rate >= 10000) {
    respondJson(false, [], 'نرخی دۆلار دەبێت لە نێوان ١٠٠ و ١٠٠٠٠ دیناردا بێت', 422);
}

SessionManager::releaseSessionLockForParallelReads();

// تۆمارکردنی نرخی تەواو بۆ ئەم ئەکاونتە (زیادکراوی دەستی خۆکارانە حساب دەکرێت)
if (setExchangeRate($userId, 'USD', 'IQD', $rate)) {
    if (function_exists('writeLog')) {
        $email = $currentUser['email'] ?? ('user#' . $userId);
        writeLog("POS exchange rate set by {$email}: 1 USD = {$rate} IQD");
    }
    respondJson(true, [
        'exchange_rate' => $rate,
    ], 'نرخی دۆلار نوێکرایەوە');
}

respondJson(false, [], 'هەڵەیەک ڕوویدا لە پاشەکەوتکردنی نرخ', 500);
