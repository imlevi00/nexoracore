<?php
/**
 * API بۆ سڕینەوەی وەسڵی کڕدراو - user/api/delete_receipt.php
 * سڕینەوە بە گەڕاندنەوەی تەواوی کاریگەرییەکان (قەرز، کۆگا، نرخ، یەکە، بەسەرچوون)
 */

require_once '../../includes/functions.php';
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/product_change_logger.php';
require_once '../telegram/telegram_helper.php';

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
$receiptId = (int)($input['receipt_id'] ?? 0);

if (!$receiptId) {
    echo json_encode(['success' => false, 'message' => 'Receipt ID is required']);
    exit;
}

try {
    $conn->begin_transaction();
    
    // دڵنیابوون لە دەسەڵات
    $stmt = $conn->prepare("SELECT * FROM purchase_receipts WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $receiptId, $userId);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();
    
    if (!$receipt) {
        echo json_encode(['success' => false, 'message' => 'Receipt not found or access denied']);
        exit;
    }

    $oldStrategy = (int)($receipt['inventory_price_strategy'] ?? 1);

    // وەرگرتنی کاڵاکانی وەسڵەکە (نرخ و snapshot بۆ گەڕاندنەوە — کۆگا لە loopدا لە product_units دەخوێنرێتەوە)
    $companyName = '';
    if (!empty($receipt['company_id']) && (int)$receipt['company_id'] > 0) {
        $companyStmt = $conn->prepare("SELECT name FROM companies WHERE id = ? AND user_id = ? LIMIT 1");
        $companyId = (int)$receipt['company_id'];
        $companyStmt->bind_param('ii', $companyId, $userId);
        $companyStmt->execute();
        $companyRow = $companyStmt->get_result()->fetch_assoc();
        $companyStmt->close();
        $companyName = $companyRow['name'] ?? '';
    }

    $stmt = $conn->prepare("
        SELECT pri.id AS pri_id,
               pri.product_id, pri.quantity, pri.unit_id,
               pri.buy_price, pri.sell_price, pri.wholesale_price, pri.special_price,
               pri.expiry_date,
               pri.revert_buy_price, pri.revert_sell_price, pri.revert_wholesale_price, pri.revert_special_price,
               p.name AS product_name
        FROM purchase_receipt_items pri
        LEFT JOIN products p ON pri.product_id = p.id AND p.user_id = ?
        WHERE pri.purchase_receipt_id = ? AND pri.product_id > 0
    ");
    $stmt->bind_param('ii', $userId, $receiptId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $affectedProductIds = [];
    foreach ($items as $logItem) {
        $pid = (int)($logItem['product_id'] ?? 0);
        if ($pid > 0) {
            $affectedProductIds[$pid] = $pid;
        }
    }
    $beforeSnapshots = [];
    foreach ($affectedProductIds as $pid) {
        $beforeSnapshots[$pid] = getProductSnapshotForLogs($conn, $userId, $pid);
    }
    
    // **1. گەڕاندنەوەی قەرزی کۆمپانیا ئەگەر payment_type = debt بووە**
    if ($receipt['payment_type'] === 'debt' && $receipt['company_id'] > 0) {
        // کەمکردنەوەی قەرز لە کۆمپانیا
        $revertDebtStmt = $conn->prepare("
            UPDATE companies 
            SET debt_amount = GREATEST(0, debt_amount - ?), updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $oldFinalAmount = (float)$receipt['final_amount'];
        $oldCompanyId = (int)$receipt['company_id'];
        $revertDebtStmt->bind_param("dii", $oldFinalAmount, $oldCompanyId, $userId);
        if (!$revertDebtStmt->execute()) {
            throw new Exception('هەڵە لە گەڕاندنەوەی قەرزی کۆمپانیا');
        }
        
        // سڕینەوەی تۆمارەکانی قەرزی کۆن لە company_debts
        $deleteOldDebtsStmt = $conn->prepare("
            DELETE FROM company_debts 
            WHERE user_id = ? AND company_id = ? AND type = 'debt' 
            AND description LIKE ?
        ");
        $debtDescription = "%#" . ($receipt['receipt_number'] ?: $receiptId) . "%";
        $deleteOldDebtsStmt->bind_param("iis", $userId, $oldCompanyId, $debtDescription);
        $deleteOldDebtsStmt->execute();
    }
    
    // **2. گەڕاندنەوەی کۆگا و نرخەکان (تەنها لە product_units)**
    require_once __DIR__ . '/../../includes/purchase_receipt_revert_inventory.php';
    foreach ($items as $oldItem) {
        revertPurchaseReceiptLineInventory($conn, $userId, $oldItem, $oldStrategy);
    }
    
    // سڕینەوەی کاڵاکانی وەسڵەکە
    $stmt = $conn->prepare("DELETE FROM purchase_receipt_items WHERE purchase_receipt_id = ?");
    $stmt->bind_param('i', $receiptId);
    $stmt->execute();
    
    // سڕینەوەی وێنەی وەسڵەکە لە DigitalOcean Spaces
    if (!empty($receipt['receipt_image'])) {
        spaces_delete_object_from_public_url($receipt['receipt_image']);
    }
    
    // سڕینەوەی وەسڵەکە
    $stmt = $conn->prepare("DELETE FROM purchase_receipts WHERE id = ?");
    $stmt->bind_param('i', $receiptId);
    $stmt->execute();
    
    $conn->commit();
    foreach ($affectedProductIds as $pid) {
        $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $pid);
        logProductChangeEvent(
            'purchase_receipt.delete',
            'purchase_receipt',
            $receiptId,
            $beforeSnapshots[$pid] ?? null,
            $afterSnapshot,
            [
                'user_id' => $userId,
                'current_user' => $currentUser,
                'product_id' => $pid,
                'source_module' => 'user/api/delete_receipt.php',
                'source_reference' => (string)$receiptId
            ]
        );
    }

    try {
        $deleteMessage = TelegramHelper::buildPurchaseReceiptDeletedMessage(
            $receipt,
            $items,
            $companyName,
            $currentUser['email'] ?? '',
            $currentUser['business_name'] ?? ''
        );
        TelegramHelper::notifyUser($userId, 'purchase_receipt_delete', $deleteMessage);
    } catch (Exception $telegramException) {
        error_log('Telegram purchase receipt delete notification failed: ' . $telegramException->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'وەسڵەکە بە سەرکەوتوویی سڕایەوە'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>