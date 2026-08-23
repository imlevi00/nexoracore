<?php
/**
 * وەرگرتنی ڕێژەی ئاڵوگۆڕکردنی دراوە بۆ POS - ajax/get_exchange_rate.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

SessionManager::releaseSessionLockForParallelReads();

try {
    // وەرگرتنی دوایین offer_price لە dollar_prices (کۆتا کات)
    $result = $conn->query("SELECT offer_price FROM dollar_prices ORDER BY last_updated DESC LIMIT 1");
    
    if ($result && $row = $result->fetch_assoc()) {
        // وەرگرتنی offer_price بە تەواوی (بەبێ دەرهێنانی 4 ژمارە)
        $offerPrice = floatval($row['offer_price']);
        
        // وەرگرتنی زیادکراوەی دەستی بەکارهێنەر لە currency_exchange_rates
        $manualAdjustment = getManualAdjustment($userId);
        
        // حسابکردنی کۆی گشتی: offer_price + manual_adjustment
        // بەکارهێنانی offer_price بە تەواوی بەبێ دەرهێنانی 4 ژمارە
        $exchangeRate = $offerPrice + $manualAdjustment;
    } else {
        // ئەگەر هیچ نرخێک نەدۆزرایەوە، هەوڵ بدە لە currency_exchange_rates وەربگرە
        $exchangeRate = getExchangeRate($userId, 'USD', 'IQD');
        
        // ئەگەر هێشتا نەدۆزرایەوە، بەکارهێنانی default
        if ($exchangeRate === false || $exchangeRate <= 0) {
            $exchangeRate = 1400.0;
        }
    }
    
    // نارەوەی ئەنجام
    sendJsonResponse(true, [
        'exchange_rate' => (float)$exchangeRate,
        'timestamp' => date('Y-m-d H:i:s')
    ], 'ڕێژەی ئاڵوگۆڕکردن بە سەرکەوتووی وەرگیرا');

} catch (Exception $e) {
    error_log("Get exchange rate error: " . $e->getMessage());
    
    // لە کاتی هەڵەدا، بەکارهێنانی default rate
    sendJsonResponse(true, [
        'exchange_rate' => 1400.0,
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => 'هەڵەیەک ڕوویدا، بەکارهێنانی نرخی default'
    ], 'ڕێژەی default بەکارهات');
}

/**
 * ناردنی وەڵامی JSON
 */
function sendJsonResponse($success, $data = null, $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    if ($data !== null) {
        if (is_array($data)) {
            $response = array_merge($response, $data);
        } else {
            $response['data'] = $data;
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
