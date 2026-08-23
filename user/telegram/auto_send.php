<?php
/**
 * سیستەمی ناردنی ئۆتۆماتیکی لیستی کڕیاران و لیستی کۆمپانیاکان بە تیلیگرام
 * user/telegram/auto_send.php
 *
 * ئەم فایلە دەبێت لە دوای لۆگین بانگهێشت بکرێت
 */

require_once '../../config/config.php';
require_once 'telegram_helper.php';

/**
 * پشکنین و ناردنی ڕاپۆرتی ئۆتۆماتیک
 */
function checkAndSendTelegramReport($userId) {
    global $conn;
    
    // پشکنینی چالاکی سیستەمی تیلیگرام
    if (!TelegramHelper::isEnabled()) {
        return [
            'success' => false,
            'message' => 'سیستەمی تیلیگرام ناچالاکە'
        ];
    }
    
    // وەرگرتنی زانیاری بەکارهێنەر
    $stmt = $conn->prepare("SELECT telegram_id, telegram_last_sent FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$userData) {
        return [
            'success' => false,
            'message' => 'بەکارهێنەر نەدۆزرایەوە'
        ];
    }
    
    // پشکنینی بوونی ئایدی تیلیگرام
    if (empty($userData['telegram_id'])) {
        return [
            'success' => false,
            'message' => 'ئایدی تیلیگرام دیاری نەکراوە'
        ];
    }
    
    // پشکنینی پێویستی بە ناردن (ڕۆژانە یەک جار)
    if (!TelegramHelper::shouldSendToday($userId)) {
        return [
            'success' => false,
            'message' => 'ئەمڕۆ پێشتر نێردراوە'
        ];
    }
    
    // دروستکردنی ڕاپۆرتی لیستی کڕیاران
    $reportData = TelegramHelper::generateCustomersPrintHTML($userId);
    
    if (!$reportData) {
        return [
            'success' => false,
            'message' => 'نەتوانرا ڕاپۆرت دروست بکرێت'
        ];
    }
    
    // ناردنی ڕاپۆرتی لیستی کڕیاران
    $telegram = new TelegramHelper();
    $caption = "📋 لیستی قەرزی ڕۆژانەی کڕیاران\n📅 " . date('Y/m/d - H:i');
    
    $result = $telegram->sendDocument($userData['telegram_id'], $reportData['file_path'], $caption);
    
    // سڕینەوەی فایلی کاتی
    @unlink($reportData['file_path']);
    
    if ($result['success']) {
        // تۆمارکردنی لۆگ و نوێکردنەوەی کاتی دوایین ناردن
        TelegramHelper::logTelegramSend($userId, 'customers_report', $userData['telegram_id'], 'success');
        TelegramHelper::updateLastSent($userId);

        // لیستی کۆمپانیاکان — هەمان ڕۆژانە، دوای لیستی کڕیاران
        $companiesReport = TelegramHelper::generateCompaniesPrintHTML($userId);
        if ($companiesReport) {
            $captionCompanies = "📋 لیستی قەرزی ڕۆژانەی کۆمپانیاکان\n📅 " . date('Y/m/d - H:i');
            $resultCompanies = $telegram->sendDocument($userData['telegram_id'], $companiesReport['file_path'], $captionCompanies);
            @unlink($companiesReport['file_path']);
            if ($resultCompanies['success']) {
                TelegramHelper::logTelegramSend($userId, 'companies_report', $userData['telegram_id'], 'success');
            } else {
                TelegramHelper::logTelegramSend($userId, 'companies_report', $userData['telegram_id'], 'failed', $resultCompanies['error'] ?? 'Unknown error');
            }
        }
        
        return [
            'success' => true,
            'message' => 'ڕاپۆرت بە سەرکەوتوویی نێردرا'
        ];
    } else {
        // تۆمارکردنی هەڵە
        TelegramHelper::logTelegramSend($userId, 'customers_report', $userData['telegram_id'], 'failed', $result['error'] ?? 'Unknown error');
        
        return [
            'success' => false,
            'message' => 'نەتوانرا ڕاپۆرت بنێردرێت',
            'error' => $result['error'] ?? 'Unknown error'
        ];
    }
}

/**
 * ئەگەر ڕاستەوخۆ بانگهێشت بکرێت
 */
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    // تاقیکردنەوەی دەسەڵات
    if (!isset($_SESSION['user'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $userId = $_SESSION['user']['id'];
    $result = checkAndSendTelegramReport($userId);
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>

