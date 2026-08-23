<?php
/**
 * سڕینەوەی مامەڵەی قەرزی کۆمپانیا - user/companies/delete_transaction.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if (!hasCompaniesModuleAccess()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نییە']);
    exit;
}

// تاقیکردنی POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'تەنها POST قبوڵکراوە']);
    exit;
}

$transactionId = (int)($_POST['transaction_id'] ?? 0);

if ($transactionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ژمارەی مامەڵە نادروستە']);
    exit;
}

// وەرگرتنی زانیاری مامەڵەکە
$transactionQuery = "
    SELECT cd.*, c.debt_amount as current_debt
    FROM company_debts cd
    JOIN companies c ON cd.company_id = c.id
    WHERE cd.id = ? AND cd.user_id = ? AND c.user_id = ?
";
$transactionStmt = $conn->prepare($transactionQuery);
$transactionStmt->bind_param("iii", $transactionId, $userId, $userId);
$transactionStmt->execute();
$transaction = $transactionStmt->get_result()->fetch_assoc();

if (!$transaction) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'مامەڵەکە نەدۆزرایەوە']);
    exit;
}

// دڵنیابوون کە بەکارهێنەر مافی سڕینەوەی هەیە
if ($transaction['user_id'] != $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'تۆ مافی سڕینەوەی ئەم مامەڵەیەت نییە']);
    exit;
}

$conn->begin_transaction();

try {
    $companyId = $transaction['company_id'];
    $amount = $transaction['amount'];
    $type = $transaction['type'];
    // دراوی مامەڵەکە دیاری دەکات کە کام باڵانس نوێ دەکرێتەوە (دینار یان دۆلار)
    $debtColumn = (($transaction['currency'] ?? 'IQD') === 'USD') ? 'debt_amount_usd' : 'debt_amount';

    // نوێکردنەوەی بڕی قەرزی کۆمپانیا (ئەتۆمی)
    // ئەگەر مامەڵەکە قەرز بوو، کاتێک دەیسڕینەوە قەرز کەم دەکەینەوە؛ ئەگەر دانەوە بوو، قەرز زیاد دەکەینەوە
    if ($type === 'debt') {
        $updateCompany = $conn->prepare("
            UPDATE companies
            SET {$debtColumn} = GREATEST(0, {$debtColumn} - ?), updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $updateCompany->bind_param("dii", $amount, $companyId, $userId);
    } else {
        $updateCompany = $conn->prepare("
            UPDATE companies
            SET {$debtColumn} = {$debtColumn} + ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $updateCompany->bind_param("dii", $amount, $companyId, $userId);
    }
    
    if (!$updateCompany->execute()) {
        throw new Exception('هەڵە لە نوێکردنەوەی قەرزی کۆمپانیا: ' . $updateCompany->error);
    }
    
    // سڕینەوەی مامەڵەکە
    $deleteTransaction = $conn->prepare("
        DELETE FROM company_debts 
        WHERE id = ? AND user_id = ?
    ");
    $deleteTransaction->bind_param("ii", $transactionId, $userId);
    
    if (!$deleteTransaction->execute()) {
        throw new Exception('هەڵە لە سڕینەوەی مامەڵە: ' . $deleteTransaction->error);
    }
    
    $conn->commit();
    
    writeLog("Company debt transaction deleted: Transaction ID $transactionId, Amount: $amount, Type: $type, Company ID: $companyId by user: {$currentUser['email']}");
    
    echo json_encode([
        'success' => true, 
        'message' => 'مامەڵەکە بە سەرکەوتووی سڕایەوە'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'هەڵە لە سڕینەوەی مامەڵە: ' . $e->getMessage()
    ]);
}

