<?php

// ===============================================
// user/notebooks/api/delete_field.php
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
$fieldId = (int)($input['field_id'] ?? 0);
$csrf_token = $input['csrf_token'] ?? '';

if (!Security::validateCSRFToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if (!$fieldId) {
    echo json_encode(['success' => false, 'message' => 'Field ID is required']);
    exit;
}

try {
    // تاقیکردنی دەسەڵات
    $stmt = $conn->prepare("SELECT * FROM notebook_fields WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $fieldId, $userId);
    $stmt->execute();
    $field = $stmt->get_result()->fetch_assoc();
    
    if (!$field) {
        echo json_encode(['success' => false, 'message' => 'Field not found']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM notebook_fields WHERE id = ?");
    $stmt->bind_param('i', $fieldId);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Field deleted successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
