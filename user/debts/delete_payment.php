<?php
/**
 * سڕینەوەی پارەدانی قەرز - user/debts/delete_payment.php
 * بەکارهاتوو لە "مێژووی پارەدانەکان" history.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/customer_change_logger.php';
require_once '../telegram/telegram_helper.php';

SessionManager::requireAuth('user');

header('Content-Type: application/json; charset=utf-8');

// تەنها POST قبوڵکراوە
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'تەنها داواکردنی POST قبوڵ کراوە'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'] ?? 0;

$paymentId = (int)($_POST['payment_id'] ?? 0);

if ($paymentId <= 0 || $userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'زانیاری نادروستە، دەتوانی دووبارە هەوڵ بدەیت'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn->begin_transaction();

    // وەرگرتنی زانیاری پارەدان و قەرز و وەسڵ
    $stmt = $conn->prepare("
        SELECT 
            dp.id as payment_id,
            dp.debt_id,
            dp.payment_amount,
            dp.payment_date,
            d.user_id,
            d.customer_id,
            d.total_debt,
            d.paid_amount,
            d.remaining_amount,
            d.status as debt_status,
            dr.id as receipt_id,
            dr.user_id as receipt_user_id
        FROM debt_payments dp
        JOIN debts d ON dp.debt_id = d.id
        LEFT JOIN debt_receipts dr ON dr.debt_payment_id = dp.id
        WHERE dp.id = ? AND d.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $paymentId, $userId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payment) {
        $conn->rollback();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'پارەدان نەدۆزرایەوە یان مافی دەستکاری نییە'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $debtId = (int)$payment['debt_id'];
    $customerId = (int)($payment['customer_id'] ?? 0);
    $paymentAmount = (float)$payment['payment_amount'];
    $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
    $beforeDebtSnapshot = getDebtSnapshotForCustomerLogs($conn, $userId, $debtId);

    // گەڕاندنەوەی بڕەکان لە قەرز
    $oldPaidAmount = (float)$payment['paid_amount'];
    $oldTotalDebt = (float)$payment['total_debt'];

    $newPaidAmount = max(0, $oldPaidAmount - $paymentAmount);
    $newRemainingAmount = $oldTotalDebt - $newPaidAmount;
    if ($newRemainingAmount < 0) {
        $newRemainingAmount = 0;
    }

    // دۆخی نوێی قەرز
    $oldStatus = $payment['debt_status'];
    if ($newRemainingAmount <= 0.000001) {
        $newStatus = 'completed';
    } else {
        // ئەگەر پێشتر completed بوو و ئێستا باقی هەیە، بکە active
        // بۆ دۆخەکانی defaulted / active، هەمان status بهێڵە هێندەیەتی
        if ($oldStatus === 'completed') {
            $newStatus = 'active';
        } else {
            $newStatus = $oldStatus;
        }
    }

    $updateDebt = $conn->prepare("
        UPDATE debts
        SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $updateDebt->bind_param(
        'ddsii',
        $newPaidAmount,
        $newRemainingAmount,
        $newStatus,
        $debtId,
        $userId
    );

    if (!$updateDebt->execute()) {
        throw new Exception('هەڵە لە نوێکردنەوەی قەرز: ' . $updateDebt->error);
    }

    $updateDebt->close();

    // نوێکردنەوەی گشتی قەرزی کڕیار
    if ($customerId > 0) {
        $updateCustomerDebt = $conn->prepare("
            UPDATE customers
            SET total_debt = (
                SELECT COALESCE(SUM(remaining_amount), 0)
                FROM debts
                WHERE customer_id = ? AND status = 'active'
            )
            WHERE id = ?
        ");
        $updateCustomerDebt->bind_param('ii', $customerId, $customerId);

        if (!$updateCustomerDebt->execute()) {
            throw new Exception('هەڵە لە نوێکردنەوەی قەرزی کڕیار: ' . $updateCustomerDebt->error);
        }

        $updateCustomerDebt->close();
    }

    // سڕینەوەی وەسڵە هاوپاڵەکان
    $deleteReceipt = $conn->prepare("
        DELETE FROM debt_receipts
        WHERE debt_payment_id = ? AND user_id = ?
    ");
    $deleteReceipt->bind_param('ii', $paymentId, $userId);
    if (!$deleteReceipt->execute()) {
        throw new Exception('هەڵە لە سڕینەوەی وەسڵ: ' . $deleteReceipt->error);
    }
    $deleteReceipt->close();

    // سڕینەوەی تۆماری پارەدان
    $deletePayment = $conn->prepare("
        DELETE FROM debt_payments
        WHERE id = ?
    ");
    $deletePayment->bind_param('i', $paymentId);
    if (!$deletePayment->execute()) {
        throw new Exception('هەڵە لە سڕینەوەی پارەدان: ' . $deletePayment->error);
    }
    $deletePayment->close();

    $conn->commit();

    writeLog("Debt payment deleted: Payment ID $paymentId, Debt ID $debtId, Amount $paymentAmount by user: {$currentUser['email']}");

    $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
    $afterDebtSnapshot = getDebtSnapshotForCustomerLogs($conn, $userId, $debtId);
    logCustomerChangeEvent(
        'customer_debt.payment_delete',
        'debt_payment',
        $paymentId,
        [
            'customer_snapshot' => $beforeCustomerSnapshot,
            'debt' => $beforeDebtSnapshot,
            'payment' => $payment
        ],
        [
            'customer_snapshot' => $afterCustomerSnapshot,
            'debt' => $afterDebtSnapshot,
            'payment' => null
        ],
        [
            'user_id' => $userId,
            'current_user' => $currentUser,
            'customer_id' => $customerId,
            'debt_id' => $debtId,
            'source_module' => 'user/debts/delete_payment.php',
            'source_reference' => (string)$paymentId
        ]
    );

    try {
        $deleteMessage = TelegramHelper::buildDebtPaymentDeletedMessage(
            $payment,
            $beforeCustomerSnapshot,
            $currentUser['email'] ?? '',
            $currentUser['business_name'] ?? ''
        );
        TelegramHelper::notifyUser($userId, 'debt_payment_delete', $deleteMessage);
    } catch (Exception $telegramException) {
        error_log('Telegram debt payment delete notification failed: ' . $telegramException->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'پارەدانەکە بە سەرکەوتوویی سڕایەوە و قەرز نوێکرایەوە'
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);

    writeLog('Delete debt payment failed: ' . $e->getMessage(), 'ERROR');

    echo json_encode([
        'success' => false,
        'message' => 'هەڵەیەک ڕوویدا لە سڕینەوەی پارەدان، تکایە دووبارە هەوڵ بدە',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

