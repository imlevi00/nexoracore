<?php
// ===============================================
// user/notebooks/api/delete_topic.php
// ===============================================
require_once '../../../config/config.php';
require_once '../../../config/security.php';

SessionManager::requireAuth('user');
header('Content-Type: application/json');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$topicId = (int)($input['topic_id'] ?? 0);
$csrf_token = $input['csrf_token'] ?? '';

if (!Security::validateCSRFToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if (!$topicId) {
    echo json_encode(['success' => false, 'message' => 'Topic ID is required']);
    exit;
}

try {
    $conn->begin_transaction();
    
    // تاقیکردنی دەسەڵات
    $stmt = $conn->prepare("SELECT * FROM notebook_topics WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $topicId, $userId);
    $stmt->execute();
    $topic = $stmt->get_result()->fetch_assoc();
    
    if (!$topic) {
        echo json_encode(['success' => false, 'message' => 'Topic not found']);
        exit;
    }
    
    // سڕینەوە بەهۆی CASCADE
    $stmt = $conn->prepare("DELETE FROM notebook_topics WHERE id = ?");
    $stmt->bind_param('i', $topicId);
    $stmt->execute();
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Topic deleted successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
