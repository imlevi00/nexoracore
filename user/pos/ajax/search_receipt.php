<?php
/**
 * گەڕانی وەسڵ - ajax/search_receipt.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';

// تاقیکردنی دەسەڵات
SessionManager::requireAuth('user');


// تاقیکردنی داواکاری
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !Security::validateCSRFToken($input['csrf_token'] ?? '')) {
    sendJsonResponse(false, null, 'ناردنی ناپێویست', 403);
}

$receipt_number = trim($input['receipt_number'] ?? '');
if (empty($receipt_number)) {
    sendJsonResponse(false, null, 'ژمارەی وەسڵ پێویستە');
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

try {
    // گەڕانی وەسڵ
    $stmt = $conn->prepare("
        SELECT s.*, 
               c.name as customer_name_updated,
               c.phone as customer_phone_updated,
               DATE_FORMAT(s.sale_date, '%Y/%m/%d %H:%i:%s') as formatted_date,
               DATE_FORMAT(s.sale_date, '%d/%m/%Y') as short_date,
               DATE_FORMAT(s.sale_date, '%H:%i') as time
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.user_id = ? AND s.invoice_number = ?
        LIMIT 1
    ");

    $stmt->bind_param('is', $userId, $receipt_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $receipt = $result->fetch_assoc();

    if (!$receipt) {
        sendJsonResponse(false, null, 'وەسڵ نەدۆزرایەوە');
    }
 
    // وەرگرتنی ئایتەمەکانی وەسڵ
    $itemsStmt = $conn->prepare("
        SELECT si.*, 
               p.name as product_name_updated,
               p.barcode,
               CASE 
                   WHEN si.unit_id IS NOT NULL AND si.unit_id != 0 THEN
                       COALESCE(pu.buy_price, pu_fallback.buy_price, 0)
                   ELSE COALESCE(pu_primary.buy_price, pu_fallback.buy_price, 0)
               END as current_buy_price,
               COALESCE(pu_primary.stock_quantity, pu_fallback.stock_quantity, 0) as current_stock
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        LEFT JOIN product_units pu ON (si.product_id = pu.product_id AND si.unit_id = pu.unit_id)
        LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
        LEFT JOIN product_units pu_fallback ON pu_fallback.id = (
            SELECT pu2.id
            FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        WHERE si.sale_id = ?
        ORDER BY si.id
    ");
    
    $itemsStmt->bind_param('i', $receipt['id']);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $items = $itemsResult->fetch_all(MYSQLI_ASSOC);

    // ئامادەکردنی داتای وەسڵ
    $receiptData = [
        'id' => (int)$receipt['id'],
        'invoice_number' => $receipt['invoice_number'],
        'customer_id' => $receipt['customer_id'] ? (int)$receipt['customer_id'] : null,
        'customer_name' => $receipt['customer_name'] ?? $receipt['customer_name_updated'] ?? '',
        'customer_phone' => $receipt['customer_phone_updated'] ?? '',
        'total_amount' => (float)$receipt['total_amount'],
        'discount' => (float)$receipt['discount'],
        'final_amount' => (float)$receipt['final_amount'],
        'payment_method' => $receipt['payment_method'],
        'payment_status' => $receipt['payment_status'],
        'paid_amount' => (float)$receipt['paid_amount'],
        'remaining_amount' => (float)$receipt['remaining_amount'],
        'sale_date' => $receipt['sale_date'],
        'formatted_date' => $receipt['formatted_date'],
        'short_date' => $receipt['short_date'],
        'time' => $receipt['time'],
        'items' => []
    ];

    // ئامادەکردنی ئایتەمەکان
    foreach ($items as $item) {
        $itemData = [
            'id' => (int)$item['id'],
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => (int)$item['quantity'],
            'unit_price' => (float)$item['unit_price'],
            'total_price' => (float)$item['total_price'],
            'price_type' => $item['price_type'] ?? 'retail',
            'barcode' => $item['barcode'] ?? '',
            'current_stock' => $item['current_stock'] ?? 0,
            'is_external' => $item['product_id'] == 0
        ];

        // حیسابکردنی قازانج (ئەگەر زانیاری کڕین هەبوو)
        if (isset($item['current_buy_price']) && $item['current_buy_price'] > 0) {
            $itemData['buy_price'] = (float)$item['current_buy_price'];
            $itemData['profit_per_unit'] = $itemData['unit_price'] - $itemData['buy_price'];
            $itemData['total_profit'] = $itemData['profit_per_unit'] * $itemData['quantity'];
        }

        $receiptData['items'][] = $itemData;
    }

    // حیسابکردنی قازانجی گشتی
    $total_profit = 0;
    foreach ($receiptData['items'] as $item) {
        if (isset($item['total_profit'])) {
            $total_profit += $item['total_profit'];
        }
    }
    $receiptData['total_profit'] = $total_profit;

    // وەرگرتنی زانیاری قەرز (ئەگەر هەبوو)
    if (in_array($receipt['payment_method'], ['debt', 'installment'])) {
        $debtStmt = $conn->prepare("
            SELECT d.*, 
                   COUNT(dp.id) as payment_count,
                   COALESCE(SUM(dp.payment_amount), 0) as total_paid_debt
            FROM debts d
            LEFT JOIN debt_payments dp ON d.id = dp.debt_id
            WHERE d.sale_id = ?
            GROUP BY d.id
        ");
        
        $debtStmt->bind_param('i', $receipt['id']);
        $debtStmt->execute();
        $debtResult = $debtStmt->get_result();
        $debt = $debtResult->fetch_assoc();

        if ($debt) {
            $receiptData['debt_info'] = [
                'debt_id' => (int)$debt['id'],
                'total_debt' => (float)$debt['total_debt'],
                'paid_amount' => (float)$debt['paid_amount'],
                'remaining_amount' => (float)$debt['remaining_amount'],
                'debt_type' => $debt['debt_type'],
                'status' => $debt['status'],
                'payment_count' => (int)$debt['payment_count'],
                'installment_months' => $debt['installment_months'] ? (int)$debt['installment_months'] : null,
                'monthly_amount' => $debt['monthly_amount'] ? (float)$debt['monthly_amount'] : null,
                'next_payment_date' => $debt['next_payment_date'] ?? null
            ];
        }
    }

    // لۆگکردنی گەڕان
    logActivity($userId, 'receipt_search', "گەڕان بۆ وەسڵ: $receipt_number");

    sendJsonResponse(true, [
        'receipt' => $receiptData
    ], 'وەسڵ بە سەرکەوتووی دۆزرایەوە');

} catch (Exception $e) {
    error_log("Search receipt error: " . $e->getMessage());
    sendJsonResponse(false, null, 'هەڵەیەک ڕوویدا لە گەڕانی وەسڵ', 500);
}

/**
 * لۆگکردنی چالاکی
 */
function logActivity($userId, $action, $description) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->bind_param('issss', $userId, $action, $description, $ipAddress, $userAgent);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * ناردنی وەڵامی JSON
 */
function sendJsonResponse($success, $data = null, $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    
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