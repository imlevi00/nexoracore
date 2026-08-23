<?php
/**
 * Save web order completion settings - user/website/ajax/save_order_completion_settings.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../../config/config.php';
require_once '../../../config/security.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];

if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دەسەڵات نەماوە'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'ڕێگەیەکی نادروست'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'نادروستی ئامنیەتی'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerRequired = isset($_POST['order_complete_customer_required']) && (int)$_POST['order_complete_customer_required'] === 1 ? 1 : 0;
$defaultPaymentCredit = isset($_POST['order_complete_default_payment_credit']) && (int)$_POST['order_complete_default_payment_credit'] === 1 ? 1 : 0;

try {
    $checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'order_complete_customer_required'");
    if ($checkColumnStmt && $checkColumnStmt->num_rows === 0) {
        $conn->query("ALTER TABLE website_settings ADD COLUMN order_complete_customer_required TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=کڕیار مەرجە، 0=ئارەزوومەندانە'");
    }
    if ($checkColumnStmt) {
        $checkColumnStmt->close();
    }

    $checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'order_complete_default_payment_credit'");
    if ($checkColumnStmt && $checkColumnStmt->num_rows === 0) {
        $conn->query("ALTER TABLE website_settings ADD COLUMN order_complete_default_payment_credit TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=بنەڕەت قەرز، 0=بنەڕەت نەخت'");
    }
    if ($checkColumnStmt) {
        $checkColumnStmt->close();
    }

    $updateStmt = $conn->prepare("
        UPDATE website_settings
        SET order_complete_customer_required = ?,
            order_complete_default_payment_credit = ?
        WHERE user_id = ?
    ");
    $updateStmt->bind_param('iii', $customerRequired, $defaultPaymentCredit, $userId);

    if (!$updateStmt->execute()) {
        throw new Exception('نەتوانرا ڕێکخستنەکان پاشەکەوت بکرێن');
    }

    if ($updateStmt->affected_rows === 0) {
        $verifyStmt = $conn->prepare("SELECT id FROM website_settings WHERE user_id = ? LIMIT 1");
        $verifyStmt->bind_param('i', $userId);
        $verifyStmt->execute();
        $exists = $verifyStmt->get_result()->num_rows > 0;
        $verifyStmt->close();

        if (!$exists) {
            throw new Exception('ڕێکخستنەکانی فرۆشگا نەدۆزرانەوە');
        }
    }

    $updateStmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'ڕێکخستنەکان پاشەکەوت کران',
        'settings' => [
            'customerRequired' => (bool)$customerRequired,
            'defaultPaymentCredit' => (bool)$defaultPaymentCredit,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
