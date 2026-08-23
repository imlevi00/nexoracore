<?php
/**
 * وەرگرتنی پەیامەکانی چاتێک
 * user/chatbot/api/get_messages.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';

header('Content-Type: application/json');

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$sessionId = intval($_GET['session_id'] ?? 0);

if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'Session ID پێویستە']);
    exit;
}

try {
    // تاقیکردنی خاوەندارێتی
    $stmt = $conn->prepare("SELECT section FROM chat_sessions WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    $stmt->bind_param("ii", $sessionId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Session نادروستە']);
        exit;
    }
    
    $session = $result->fetch_assoc();
    
    // وەرگرتنی پەیامەکان
    $stmt = $conn->prepare("
        SELECT 
            role, 
            content,
            DATE_FORMAT(created_at, '%Y/%m/%d %H:%i') as created_at
        FROM chat_messages 
        WHERE session_id = ?
        ORDER BY created_at ASC
    ");
    
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'section' => $session['section'],
        'messages' => $messages
    ]);
    
} catch (Exception $e) {
    error_log("Get messages error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()
    ]);
}
