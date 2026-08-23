<?php
/**
 * API endpoint to get list of active customers
 * user/website/ajax/get_customers.php
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

try {
    // Get active customers
    $stmt = $conn->prepare("
        SELECT id, name, phone, address 
        FROM customers 
        WHERE user_id = ? AND status = 'active' 
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $userId);
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
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'customers' => $customers
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'هەڵە لە وەرگرتنی کڕیارەکان: ' . $e->getMessage()
    ]);
}
