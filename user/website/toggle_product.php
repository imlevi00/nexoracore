<?php
/**
 * API بۆ گۆڕینی بینینی کاڵا - user/website/toggle_product.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// تاقیکردنی ئەوەی یوزەر سەرەکیە (نەک لاوەکی)
if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دەسەڵات نەماوە']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'ڕێگەیەکی نادروست']);
    exit;
}

// CSRF token validation
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'نادروستی ئامنیەتی']);
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);
$isVisible = isset($_POST['is_visible']) ? 1 : 0;

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'کاڵای هەڵبژاردوو نادروستە']);
    exit;
}

try {
    // چیککردنی ئەوەی کاڵا بە یوزەرەوە دەگەڕێت
    $checkStmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $productId, $userId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        throw new Exception('کاڵا نەدۆزرایەوە');
    }
    $checkStmt->close();
    
    // نوێکردنەوە یان زیادکردنی visibility
    $upsertStmt = $conn->prepare("INSERT INTO website_product_visibility (user_id, product_id, is_visible) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_visible = ?");
    $upsertStmt->bind_param("iiii", $userId, $productId, $isVisible, $isVisible);
    
    if ($upsertStmt->execute()) {
        $message = $isVisible ? 'کاڵا نیشاندرا لە وێب سایت' : 'کاڵا شاردرایەوە لە وێب سایت';
        writeLog("Product visibility toggled by user {$currentUser['email']}: Product ID {$productId}, Visible: {$isVisible}");
        echo json_encode(['success' => true, 'message' => $message, 'is_visible' => $isVisible]);
    } else {
        throw new Exception('هەڵەیەک ڕوویدا لە نوێکردنەوەی دۆخی کاڵا');
    }
    $upsertStmt->close();
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
