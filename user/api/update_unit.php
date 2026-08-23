<?php
/**
 * API endpoint for updating units
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
    $name = cleanInput($_POST['name'] ?? '');
    $symbol = cleanInput($_POST['symbol'] ?? '');
    
    if (empty($unitId)) {
        echo json_encode(['success' => false, 'message' => 'یەکە دیارینەکراوە']);
        exit;
    }
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'ناوی یەکە پێویستە']);
        exit;
    }
    
    // Check if unit exists and belongs to this user
    $stmt = $conn->prepare("SELECT id, is_default FROM units WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $unitId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'یەکەکە نەدۆزرایەوە']);
        exit;
    }
    
    $unit = $result->fetch_assoc();
    $stmt->close();
    
    // Check if new name already exists (excluding current unit)
    $stmt = $conn->prepare("SELECT id FROM units WHERE name = ? AND user_id = ? AND id != ?");
    $stmt->bind_param("sii", $name, $userId, $unitId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'یەکەیەک بەم ناوە پێشتر هەیە']);
        exit;
    }
    $stmt->close();
    
    // Update unit
    $stmt = $conn->prepare("
        UPDATE units 
        SET name = ?, name_en = ?, symbol = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    
    $name_en = $name; // For now, use the same name in English
    $stmt->bind_param("sssii", $name, $name_en, $symbol, $unitId, $userId);
    
    if ($stmt->execute()) {
        // Log activity
        writeLog("Unit updated: $name (ID: $unitId) by user: {$currentUser['email']}");
        
        echo json_encode([
            'success' => true, 
            'message' => 'یەکە بە سەرکەوتوویی نوێکرایەوە',
            'unit' => [
                'id' => $unitId,
                'name' => $name,
                'symbol' => $symbol,
                'is_default' => $unit['is_default']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا لە نوێکردنەوەی یەکە']);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()]);
}
?>

