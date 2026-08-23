<?php
/**
 * API endpoint for deleting units
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF token validation
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

try {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    
    if (empty($unitId)) {
        echo json_encode(['success' => false, 'message' => 'یەکە دیارینەکراوە']);
        exit;
    }
    
    // Check if unit exists and belongs to this user
    $stmt = $conn->prepare("SELECT id, name, is_default FROM units WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $unitId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'یەکەکە نەدۆزرایەوە']);
        exit;
    }
    
    $unit = $result->fetch_assoc();
    $stmt->close();
    
    // Check if unit is default
    if ($unit['is_default'] == 1) {
        echo json_encode(['success' => false, 'message' => 'ناتوانیت یەکەی بنەڕەتی بسڕیتەوە']);
        exit;
    }
    
    // Check if unit is used in any product
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_units WHERE unit_id = ?");
    $stmt->bind_param("i", $unitId);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    
    if ($count > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "ناتوانیت ئەم یەکەیە بسڕیتەوە، چونکە لە $count کاڵادا بەکارهاتووە"
        ]);
        exit;
    }
    
    // Soft delete: set is_active to 0
    $stmt = $conn->prepare("
        UPDATE units 
        SET is_active = 0, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $unitId, $userId);
    
    if ($stmt->execute()) {
        // Log activity
        writeLog("Unit deleted: {$unit['name']} (ID: $unitId) by user: {$currentUser['email']}");
        
        echo json_encode([
            'success' => true, 
            'message' => 'یەکە بە سەرکەوتوویی سڕایەوە'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا لە سڕینەوەی یەکە']);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()]);
}
?>

