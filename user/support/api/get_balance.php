<?php
/**
 * API: وەرگرتنی بڕی پاڵپشتی بەکارهێنەر
 * user/support/api/get_balance.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

header('Content-Type: application/json; charset=utf-8');

try {
    global $conn;
    
    $currentUser = getCurrentUser();
    $userId = $currentUser['id'];
    
    // وەرگرتنی بڕی پاڵپشتی ئێستا
    $stmt = $conn->prepare("
        SELECT 
            id,
            business_name,
            email,
            phone,
            support_balance,
            created_at
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        throw new Exception('بەکارهێنەر نەدۆزرایەوە');
    }
    
    // وەرگرتنی کۆی گشتی زیادکراو
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_added,
            COUNT(*) as total_transactions
        FROM support_balance_history 
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $addedStats = $result->fetch_assoc();
    
    // وەرگرتنی کۆی گشتی بەکارهاتوو
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_used,
            COUNT(*) as total_usages
        FROM support_balance_usage 
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $usedStats = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'user' => [
                'id' => $user['id'],
                'business_name' => $user['business_name'],
                'email' => $user['email'],
                'phone' => $user['phone']
            ],
            'balance' => [
                'current' => number_format($user['support_balance'], 3),
                'current_raw' => floatval($user['support_balance']),
                'total_added' => number_format($addedStats['total_added'], 3),
                'total_added_raw' => floatval($addedStats['total_added']),
                'total_used' => number_format($usedStats['total_used'], 3),
                'total_used_raw' => floatval($usedStats['total_used']),
                'total_transactions' => intval($addedStats['total_transactions']),
                'total_usages' => intval($usedStats['total_usages'])
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

