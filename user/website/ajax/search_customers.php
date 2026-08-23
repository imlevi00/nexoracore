<?php
/**
 * Customer Search API for Orders
 * user/website/ajax/search_customers.php
 */

header('Content-Type: application/json');

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';

// Check user authentication
if (!isUser()) {
    echo json_encode(['success' => false, 'message' => 'دەبێت داخڵ بیت']);
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی گەڕان
$searchTerm = cleanInput($_GET['q'] ?? '');

if (empty($searchTerm)) {
    echo json_encode(['success' => true, 'customers' => []]);
    exit;
}

try {
    // گەڕان لە کڕیاراندا
    $searchPattern = "%$searchTerm%";
    $stmt = $conn->prepare("
        SELECT id, name, phone, address 
        FROM customers 
        WHERE user_id = ? AND status = 'active' 
        AND (name LIKE ? OR phone LIKE ?)
        ORDER BY name ASC
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
            'phone' => $row['phone'] ?? '',
            'address' => $row['address'] ?? ''
        ];
    }
    
    echo json_encode([
        'success' => true,
        'customers' => $customers
    ]);
    
} catch (Exception $e) {
    error_log("Customer search error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'هەڵە لە گەڕاندا: ' . $e->getMessage()
    ]);
}

$stmt->close();
$conn->close();
?>
