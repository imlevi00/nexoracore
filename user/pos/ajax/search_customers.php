<?php
/**
 * Customer Search API for POS System
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate'); // ڕێگریکردن لە کاشکردن
header('Pragma: no-cache');
header('Expires: 0');

// تاقیکردنی داخڵبوون
if (!isUser()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

SessionManager::releaseSessionLockForParallelReads();

// وەرگرتنی گەڕان
$searchTerm = cleanInput($_GET['q'] ?? '');
$customerId = (int)($_GET['id'] ?? 0);

// ئەگەر customer_id دابەزرابێت، کڕیارەکە بگەڕێنەوە
if ($customerId > 0) {
    $stmt = $conn->prepare("
        SELECT c.id, c.name, c.phone, c.address, c.total_debt,
               COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_iqd,
               COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_usd
        FROM customers c
        LEFT JOIN debts d ON c.id = d.customer_id AND d.status = 'active'
        LEFT JOIN sales s ON d.sale_id = s.id
        WHERE c.id = ? AND c.user_id = ? AND c.status = 'active'
        GROUP BY c.id
    ");
    
    $stmt->bind_param("ii", $customerId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['customers' => [[
            'id' => $row['id'],
            'name' => $row['name'],
            'phone' => $row['phone'] ?: '',
            'address' => $row['address'] ?: '',
            'total_debt' => (float)$row['total_debt'],
            'current_debt_iqd' => (float)$row['current_debt_iqd'],
            'current_debt_usd' => (float)$row['current_debt_usd']
        ]]]);
    } else {
        echo json_encode(['customers' => []]);
    }
    $stmt->close();
    $conn->close();
    exit;
}

if (empty($searchTerm)) {
    echo json_encode(['customers' => []]);
    exit;
}

try {
    // گەڕان لە کڕیاراندا
    $searchPattern = "%$searchTerm%";
    $stmt = $conn->prepare("
        SELECT c.id, c.name, c.phone, c.address, c.total_debt,
               COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_iqd,
               COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_usd
        FROM customers c
        LEFT JOIN debts d ON c.id = d.customer_id AND d.status = 'active'
        LEFT JOIN sales s ON d.sale_id = s.id
        WHERE c.user_id = ? AND c.status = 'active' 
        AND (c.name LIKE ? OR c.phone LIKE ?)
        GROUP BY c.id
        ORDER BY c.name ASC
        LIMIT 10
    ");
    
    $stmt->bind_param("iss", $userId, $searchPattern, $searchPattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $customers[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'phone' => $row['phone'] ?: '',
            'address' => $row['address'] ?: '',
            'total_debt' => (float)$row['total_debt'],
            'current_debt_iqd' => (float)$row['current_debt_iqd'],
            'current_debt_usd' => (float)$row['current_debt_usd']
        ];
    }
    
    echo json_encode(['customers' => $customers]);
    
} catch (Exception $e) {
    error_log("Customer search error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

$stmt->close();
$conn->close();
?>
