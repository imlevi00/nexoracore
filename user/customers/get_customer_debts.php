<?php
/**
 * API بۆ وەرگرتنی قەرزەکانی کڕیار
 * Get Customer Debts API
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../includes/debt_payment_breakdown.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate'); // ڕێگریکردن لە کاشکردن
header('Pragma: no-cache');
header('Expires: 0');

// تاقیکردنی داخڵبوون
if (!isUser()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$customerId = (int)($_GET['customer_id'] ?? 0);

if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
    exit;
}

try {
    // وەرگرتنی قەرزە چالاکەکانی کڕیار
    $query = "
        SELECT 
            d.id,
            d.total_debt,
            d.paid_amount,
            d.remaining_amount,
            d.debt_type,
            COALESCE(s.currency, 'IQD') as currency,
            DATE_FORMAT(d.created_at, '%Y/%m/%d') as formatted_date,
            DATE_FORMAT(d.next_payment_date, '%Y/%m/%d') as formatted_next_payment
        FROM debts d
        LEFT JOIN sales s ON d.sale_id = s.id
        WHERE d.customer_id = ? 
        AND d.user_id = ? 
        AND d.status = 'active'
        AND d.remaining_amount > 0
        ORDER BY d.created_at ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $customerId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $debts = enrichDebtRowsWithPaymentBreakdown($conn, $result->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'debts' => $debts,
        'count' => count($debts)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching debts: ' . $e->getMessage()
    ]);
}
