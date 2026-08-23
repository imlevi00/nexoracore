<?php
/**
 * API endpoint for adding new units
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
    $name = cleanInput($_POST['name'] ?? '');
    $symbol = cleanInput($_POST['symbol'] ?? '');
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'ناوی یەکە پێویستە']);
        exit;
    }
    
    // Check if unit name already exists for this user
    $stmt = $conn->prepare("SELECT id FROM units WHERE name = ? AND user_id = ?");
    $stmt->bind_param("si", $name, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'ئەم یەکەیە پێشتر هەیە']);
        exit;
    }
    $stmt->close();
    
    // Insert new unit
    $stmt = $conn->prepare("
        INSERT INTO units (user_id, name, name_en, symbol, is_default, is_active) 
        VALUES (?, ?, ?, ?, 0, 1)
    ");
    
    $name_en = $name; // For now, use the same name in English
    $stmt->bind_param("isss", $userId, $name, $name_en, $symbol);
    
    if ($stmt->execute()) {
        $unitId = $conn->insert_id;
        
        // Log activity
        writeLog("New unit added: $name (ID: $unitId) by user: {$currentUser['email']}");
        
        echo json_encode([
            'success' => true, 
            'message' => 'یەکەی نوێ بە سەرکەوتوویی زیادکرا',
            'unit' => [
                'id' => $unitId,
                'name' => $name,
                'symbol' => $symbol,
                'is_default' => 0
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا لە زیادکردنی یەکە']);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()]);
}
?>
