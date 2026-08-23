<?php
/**
 * وەرگرتنی لیستی چاتەکان
 * user/chatbot/api/get_sessions.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';

header('Content-Type: application/json');

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

try {
    $stmt = $conn->prepare("
        SELECT 
            id, 
            section, 
            title,
            DATE_FORMAT(created_at, '%Y/%m/%d %H:%i') as created_at,
            DATE_FORMAT(updated_at, '%Y/%m/%d %H:%i') as updated_at
        FROM chat_sessions 
        WHERE user_id = ? AND is_active = 1
        ORDER BY updated_at DESC
        LIMIT 50
    ");
    
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $sessions = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'sessions' => $sessions
    ]);
    
} catch (Exception $e) {
    error_log("Get sessions error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()
    ]);
}
