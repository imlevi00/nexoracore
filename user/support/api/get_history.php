<?php
/**
 * API: وەرگرتنی مێژووی پاڵپشتی بەکارهێنەر
 * user/support/api/get_history.php
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
    
    // وەرگرتنی مێژووی زیادکردنی پاڵپشتی
    $stmt = $conn->prepare("
        SELECT 
            sbh.id,
            sbh.amount,
            sbh.payment_type,
            sbh.notes,
            sbh.created_at,
            a.username as admin_username
        FROM support_balance_history sbh
        LEFT JOIN admins a ON sbh.admin_id = a.id
        WHERE sbh.user_id = ?
        ORDER BY sbh.created_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $additions = [];
    while ($row = $result->fetch_assoc()) {
        $additions[] = $row;
    }
    
    // وەرگرتنی مێژووی بەکارهێنانی پاڵپشتی
    $stmt = $conn->prepare("
        SELECT 
            sbu.id,
            sbu.amount,
            sbu.service_description,
            sbu.notes,
            sbu.created_at,
            a.username as admin_username
        FROM support_balance_usage sbu
        LEFT JOIN admins a ON sbu.admin_id = a.id
        WHERE sbu.user_id = ?
        ORDER BY sbu.created_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $usages = [];
    while ($row = $result->fetch_assoc()) {
        $usages[] = $row;
    }
    
    // فۆرمات کردنی داتاکان
    $formattedAdditions = array_map(function($item) {
        return [
            'id' => $item['id'],
            'amount' => number_format($item['amount'], 3),
            'amount_raw' => floatval($item['amount']),
            'payment_type' => $item['payment_type'],
            'payment_type_label' => getPaymentTypeLabel($item['payment_type']),
            'notes' => $item['notes'],
            'admin_username' => $item['admin_username'] ?? 'سیستەم',
            'created_at' => $item['created_at'],
            'created_at_formatted' => date('Y-m-d H:i', strtotime($item['created_at']))
        ];
    }, $additions);
    
    $formattedUsages = array_map(function($item) {
        return [
            'id' => $item['id'],
            'amount' => number_format($item['amount'], 3),
            'amount_raw' => floatval($item['amount']),
            'service_description' => $item['service_description'],
            'notes' => $item['notes'],
            'admin_username' => $item['admin_username'] ?? 'سیستەم',
            'created_at' => $item['created_at'],
            'created_at_formatted' => date('Y-m-d H:i', strtotime($item['created_at']))
        ];
    }, $usages);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'additions' => $formattedAdditions,
            'usages' => $formattedUsages,
            'total_additions' => count($formattedAdditions),
            'total_usages' => count($formattedUsages)
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * وەرگرتنی ناوی جۆری پارەدان
 */
function getPaymentTypeLabel($type) {
    $labels = [
        'cash' => 'کاش',
        'fastpay' => 'FastPay',
        'fib' => 'FIB',
        'qi_card' => 'Qi Card'
    ];
    return $labels[$type] ?? $type;
}

