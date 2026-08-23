<?php
/**
 * API بۆ سڕینەوەی کاڵا لە وەسڵی کڕدراو - api/delete_receipt_item.php
 */

require_once '../includes/functions.php';
require_once '../config/config.php';
require_once '../config/security.php';
require_once '../../includes/product_change_logger.php';

SessionManager::requireAuth('user');

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$itemId = (int)($input['item_id'] ?? 0);

if (!$itemId) {
    echo json_encode(['success' => false, 'message' => 'Item ID is required']);
    exit;
}

try {
    $conn->begin_transaction();
    
    // وەرگرتنی زانیاری کاڵاکە و دڵنیابوون لە دەسەڵات
    $stmt = $conn->prepare("
        SELECT pri.*, pr.user_id, pr.id as receipt_id,
               COALESCE(pr.inventory_price_strategy, 1) AS inventory_price_strategy
        FROM purchase_receipt_items pri
        INNER JOIN purchase_receipts pr ON pri.purchase_receipt_id = pr.id
        WHERE pri.id = ? AND pr.user_id = ?
    ");
    
    $stmt->bind_param('ii', $itemId, $userId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    
    if (!$item) {
        echo json_encode(['success' => false, 'message' => 'Item not found or access denied']);
        exit;
    }
    $productIdForLog = (int)($item['product_id'] ?? 0);
    $beforeSnapshot = $productIdForLog > 0 ? getProductSnapshotForLogs($conn, $userId, $productIdForLog) : null;
    
    // گەڕاندنەوەی کۆگا/نرخ لە product_units (وەک سڕینەوەی وەسڵ)
    require_once __DIR__ . '/../../includes/purchase_receipt_revert_inventory.php';
    $oldStrategy = (int)($item['inventory_price_strategy'] ?? 1);
    revertPurchaseReceiptLineInventory($conn, $userId, $item, $oldStrategy);
    
    // سڕینەوەی کاڵاکە لە وەسڵەکە
    $stmt = $conn->prepare("DELETE FROM purchase_receipt_items WHERE id = ?");
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    
    // نوێکردنەوەی کۆی گشتی وەسڵەکە
    updateReceiptTotals($conn, $item['receipt_id']);
    
    $conn->commit();
    if ($productIdForLog > 0) {
        $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $productIdForLog);
        logProductChangeEvent(
            'purchase_receipt_item.delete',
            'purchase_receipt',
            (int)$item['receipt_id'],
            $beforeSnapshot,
            $afterSnapshot,
            [
                'user_id' => $userId,
                'current_user' => $currentUser,
                'product_id' => $productIdForLog,
                'source_module' => 'user/api/delete_receipt_item.php',
                'source_reference' => (string)$itemId
            ]
        );
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Item deleted successfully'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

function updateReceiptTotals($conn, $receiptId) {
    // حیسابکردنی کۆی بنەڕەتی
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_cost), 0) as subtotal 
        FROM purchase_receipt_items 
        WHERE purchase_receipt_id = ?
    ");
    $stmt->bind_param('i', $receiptId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $subtotal = (float)$result['subtotal'];
    
    // وەرگرتنی داشکاندن و کرێی زیادە
    $stmt = $conn->prepare("
        SELECT discount_amount, additional_charges 
        FROM purchase_receipts 
        WHERE id = ?
    ");
    $stmt->bind_param('i', $receiptId);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();
    
    $discountAmount = (float)($receipt['discount_amount'] ?? 0);
    $additionalCharges = (float)($receipt['additional_charges'] ?? 0);
    
    $finalAmount = $subtotal - $discountAmount + $additionalCharges;
    
    // نوێکردنەوەی وەسڵەکە
    $stmt = $conn->prepare("
        UPDATE purchase_receipts 
        SET total_amount = ?, final_amount = ? 
        WHERE id = ?
    ");
    $stmt->bind_param('ddi', $subtotal, $finalAmount, $receiptId);
    $stmt->execute();
}
?>