<?php
// ===============================================
// user/notebooks/api/update_field_order.php
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
$fieldIds = $input['field_ids'] ?? [];
$csrf_token = $input['csrf_token'] ?? '';

if (!Security::validateCSRFToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if (!$topicId || empty($fieldIds)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

try {
    $conn->begin_transaction();
    
    foreach ($fieldIds as $order => $fieldId) {
        $stmt = $conn->prepare("
            UPDATE notebook_fields 
            SET field_order = ? 
            WHERE id = ? AND user_id = ? AND topic_id = ?
        ");
        $stmt->bind_param('iiii', $order, $fieldId, $userId, $topicId);
        $stmt->execute();
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Order updated successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>