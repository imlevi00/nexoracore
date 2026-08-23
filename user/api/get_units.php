<?php
/**
 * API endpoint for getting units list
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../config/database.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is authenticated
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // product_count: چەند کاڵا ئەم یەکەیەیان هەیە (بۆ ناچالاککردنی سڕینەوە لە UI)
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.symbol, u.is_default,
            (SELECT COUNT(*) FROM product_units pu WHERE pu.unit_id = u.id) AS product_count
        FROM units u
        WHERE u.user_id = ? AND u.is_active = 1 
        ORDER BY u.is_default DESC, u.name
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $units = [];
    while ($row = $result->fetch_assoc()) {
        $units[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'units' => $units
    ]);
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()]);
}
?>

