<?php
// ===============================================
// user/notebooks/api/delete_entry.php
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
$entryId = (int)($input['entry_id'] ?? 0);
$csrf_token = $input['csrf_token'] ?? '';

if (!Security::validateCSRFToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if (!$entryId) {
    echo json_encode(['success' => false, 'message' => 'Entry ID is required']);
    exit;
}

try {
    // تاقیکردنی دەسەڵات
    $stmt = $conn->prepare("SELECT * FROM notebook_entries WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $entryId, $userId);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    
    if (!$entry) {
        echo json_encode(['success' => false, 'message' => 'Entry not found']);
        exit;
    }
    
    // سڕینەوە
    $stmt = $conn->prepare("DELETE FROM notebook_entries WHERE id = ?");
    $stmt->bind_param('i', $entryId);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Entry deleted successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
