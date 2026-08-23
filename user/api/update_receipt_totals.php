<?php
/**
 * API بۆ نوێکردنەوەی کۆی گشتی وەسڵی کڕدراو - user/api/update_receipt_totals.php
 */

require_once '../../includes/functions.php';
require_once '../../config/config.php';
require_once '../../config/security.php';

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
    // دڵنیابوون لە دەسەڵات
    $stmt = $conn->prepare("SELECT id FROM purchase_receipts WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $receiptId, $userId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Receipt not found or access denied']);
        exit;
    }
    
    $conn->begin_transaction();
    
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
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Totals updated successfully',
        'data' => [
            'subtotal' => $subtotal,
            'discount' => $discountAmount,
            'additional_charges' => $additionalCharges,
            'final_amount' => $finalAmount
        ]
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