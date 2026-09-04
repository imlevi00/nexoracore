<?php
/**
 * سڕینەوەی خەرجی - user/expenses/delete.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/wallet_service.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expenses.delete', [
    'route' => '/user/expenses/delete.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

if (function_exists('ensureExpensesSchemaTables')) {
    ensureExpensesSchemaTables($conn);
}

$expense_id = intval($_GET['id'] ?? 0);

if ($expense_id <= 0) {
    redirect(url('user/expenses/index.php?error=' . urlencode('IDی خەرجی نادروستە!')));
}

// تاقیکردنی بوونی خەرجی و سەرپەرشتی
$check_expense = $conn->prepare("
    SELECT id, expense_name, payment_method, wallet_id, amount, currency
    FROM expenses
    WHERE id = ? AND user_id = ?
");
$check_expense->bind_param("ii", $expense_id, $userId);
$check_expense->execute();
$expense_result = $check_expense->get_result();

if ($expense_result->num_rows === 0) {
    redirect(url('user/expenses/index.php?error=' . urlencode('خەرجی نەدۆزرایەوە!')));
}

$expense = $expense_result->fetch_assoc();
$check_expense->close();

$conn->begin_transaction();

try {
    if ($expense['payment_method'] === 'cash') {
        $walletId = (int)($expense['wallet_id'] ?? 0);
        $amount = (float)$expense['amount'];
        $currency = strtoupper($expense['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';

        if (!walletReverseExpenseCash($conn, (int)$userId, (int)$expense_id, $walletId, $amount, $currency, (int)$userId)) {
            throw new Exception('نەتوانرا جوڵەی قاسەی خەرجی گەڕێندرێتەوە');
        }
    }

    // سڕینەوەی پارەدانەوەکان و قەرزەکانی پەیوەندیدار
    $del_pay_stmt = $conn->prepare("DELETE p FROM expense_credit_payments p INNER JOIN expense_credits c ON p.expense_credit_id = c.id WHERE c.expense_id = ? AND c.user_id = ?");
    if ($del_pay_stmt) {
        $del_pay_stmt->bind_param("ii", $expense_id, $userId);
        $del_pay_stmt->execute();
        $del_pay_stmt->close();
    }

    $del_cred_stmt = $conn->prepare("DELETE FROM expense_credits WHERE expense_id = ? AND user_id = ?");
    if ($del_cred_stmt) {
        $del_cred_stmt->bind_param("ii", $expense_id, $userId);
        $del_cred_stmt->execute();
        $del_cred_stmt->close();
    }

    $delete_expense = $conn->prepare("DELETE FROM expenses WHERE id = ? AND user_id = ?");
    $delete_expense->bind_param("ii", $expense_id, $userId);

    if (!$delete_expense->execute()) {
        throw new Exception('هەڵەیەک ڕوویدا لە سڕینەوەی خەرجی!');
    }
    $delete_expense->close();

    $conn->commit();
    redirect(url('user/expenses/index.php?success=' . urlencode('خەرجی "' . $expense['expense_name'] . '" بە سەرکەوتووی سڕایەوە!')));
} catch (Exception $e) {
    $conn->rollback();
    redirect(url('user/expenses/index.php?error=' . urlencode($e->getMessage())));
}
?>
