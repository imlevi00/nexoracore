<?php
/**
 * بەشی قەرزی پارەی کڕیاران - user/customers/money_debts.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/customer_change_logger.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$errors = [];
$success = '';

// پرۆسەکردنی پارەدان
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    // پارەدانی قەرزی پارە
    if ($action === 'pay_money_debt') {
        $transactionId = (int)($_POST['transaction_id'] ?? 0);
        $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
        $paymentMethod = cleanInput($_POST['payment_method'] ?? 'cash');
        $notes = cleanInput($_POST['notes'] ?? '');
        
        if ($paymentAmount <= 0) {
            $errors[] = 'بڕی پارە دەبێت زیاتر لە سفر بێت';
        } else {
            // وەرگرتنی زانیاری مامەڵە
            $transactionStmt = $conn->prepare("
                SELECT cmd.*, c.name as customer_name, c.phone as customer_phone
                FROM customer_money_debts cmd
                LEFT JOIN customers c ON cmd.customer_id = c.id
                WHERE cmd.id = ? AND cmd.user_id = ? AND cmd.type = 'debt'
            ");
            $transactionStmt->bind_param("ii", $transactionId, $userId);
            $transactionStmt->execute();
            $transaction = $transactionStmt->get_result()->fetch_assoc();
            $transactionStmt->close();
            
            if (!$transaction) {
                $errors[] = 'مامەڵەکە نەدۆزرایەوە';
            } else {
                // حیسابکردنی قەرزی ماوە
                $totalDebt = (float)$transaction['amount'];
                $totalReceived = 0;
                
                // وەرگرتنی کۆی پارەدانی پێشوو
                $receivedStmt = $conn->prepare("
                    SELECT COALESCE(SUM(amount), 0) as total_received
                    FROM customer_money_debts
                    WHERE customer_id = ? AND user_id = ? AND type = 'payment' AND currency = ?
                ");
                $receivedStmt->bind_param("iis", $transaction['customer_id'], $userId, $transaction['currency']);
                $receivedStmt->execute();
                $receivedResult = $receivedStmt->get_result()->fetch_assoc();
                $totalReceived = (float)($receivedResult['total_received'] ?? 0);
                $receivedStmt->close();
                
                $remainingDebt = $totalDebt - $totalReceived;
                
                if ($paymentAmount > $remainingDebt) {
                    $errors[] = 'بڕی پارە زیاترە لە قەرزی ماوە (' . formatCurrencyAmount($remainingDebt, $transaction['currency']) . ')';
                } else {
                    $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)$transaction['customer_id']);
                    $conn->begin_transaction();
                    
                    try {
                        // زیادکردنی پارەدان
                        $description = $notes ?: 'پارەدان بۆ قەرزی پارە';
                        $paymentDate = date('Y-m-d');
                        $insertPayment = $conn->prepare("
                            INSERT INTO customer_money_debts (user_id, customer_id, amount, currency, description, type, date) 
                            VALUES (?, ?, ?, ?, ?, 'payment', ?)
                        ");
                        $insertPayment->bind_param("iidsss", 
                            $userId, 
                            $transaction['customer_id'], 
                            $paymentAmount, 
                            $transaction['currency'], 
                            $description,
                            $paymentDate
                        );
                        $insertPayment->execute();
                        
                        // نوێکردنەوەی گشتی قەرزی کڕیار
                        $updateCustomerDebt = $conn->prepare("
                            UPDATE customers 
                            SET total_debt = (
                                SELECT COALESCE(SUM(
                                    CASE 
                                        WHEN cmd.currency = 'IQD' THEN cmd.amount
                                        ELSE 0
                                    END
                                ), 0)
                                FROM customer_money_debts cmd
                                WHERE cmd.customer_id = customers.id 
                                AND cmd.user_id = ?
                                AND cmd.type = 'debt'
                            ) + (
                                SELECT COALESCE(SUM(remaining_amount), 0)
                                FROM debts d
                                WHERE d.customer_id = customers.id
                                AND d.user_id = ?
                                AND d.status = 'active'
                                AND EXISTS (
                                    SELECT 1 FROM sales s 
                                    WHERE s.id = d.sale_id 
                                    AND s.currency = 'IQD'
                                )
                            )
                            WHERE id = ?
                        ");
                        $updateCustomerDebt->bind_param("iii", $userId, $userId, $transaction['customer_id']);
                        $updateCustomerDebt->execute();
                        
                        $conn->commit();
                        
                        $success = 'پارە بە سەرکەوتووی وەرگیرا';
                        writeLog("Money debt payment: $paymentAmount {$transaction['currency']} for transaction ID: $transactionId by user: {$currentUser['email']}");
                        
                        // ناردنی نامە بۆ واتس ئەپپ
                        if (!empty($transaction['customer_phone'])) {
                            $newRemaining = $remainingDebt - $paymentAmount;
                            $_SESSION['whatsapp_payment_data'] = [
                                'customer_name' => $transaction['customer_name'],
                                'customer_phone' => $transaction['customer_phone'],
                                'payment_amount' => $paymentAmount,
                                'remaining_amount' => $newRemaining,
                                'receipt_number' => 'MD-' . date('YmdHis') . '-' . $transactionId,
                                'business_name' => $currentUser['business_name'],
                                'currency' => $transaction['currency']
                            ];
                        }

                        $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)$transaction['customer_id']);
                        logCustomerChangeEvent(
                            'customer_debt.payment',
                            'money_debt_payment',
                            $transactionId,
                            [
                                'customer_snapshot' => $beforeCustomerSnapshot,
                                'money_debt_transaction' => $transaction,
                                'remaining_before' => $remainingDebt,
                                'payment_amount' => $paymentAmount
                            ],
                            [
                                'customer_snapshot' => $afterCustomerSnapshot,
                                'remaining_after' => $newRemaining
                            ],
                            [
                                'user_id' => $userId,
                                'current_user' => $currentUser,
                                'customer_id' => (int)$transaction['customer_id'],
                                'currency' => (string)$transaction['currency'],
                                'source_module' => 'user/customers/money_debts.php',
                                'source_reference' => (string)$transactionId
                            ]
                        );
                        
                    } catch (Exception $e) {
                        $conn->rollback();
                        $errors[] = 'هەڵە لە پرۆسەی پارەدان: ' . $e->getMessage();
                    }
                }
            }
        }
    }
    
    // سڕینەوەی مامەڵەی قەرز/پارەدان
    if ($action === 'delete_money_debt') {
        $transactionId = (int)($_POST['transaction_id'] ?? 0);
        if ($transactionId <= 0) {
            $errors[] = 'مامەڵەکە نەدۆزرایەوە';
        } else {
            $chkStmt = $conn->prepare("SELECT id, customer_id, type, amount, currency, description, date FROM customer_money_debts WHERE id = ? AND user_id = ?");
            $chkStmt->bind_param("ii", $transactionId, $userId);
            $chkStmt->execute();
            $row = $chkStmt->get_result()->fetch_assoc();
            $chkStmt->close();
            if (!$row) {
                $errors[] = 'مامەڵەکە نەدۆزرایەوە یان مۆڵەتت نییە';
            } else {
                $customerId = (int)$row['customer_id'];
                $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                $conn->begin_transaction();
                try {
                    $delStmt = $conn->prepare("DELETE FROM customer_money_debts WHERE id = ? AND user_id = ?");
                    $delStmt->bind_param("ii", $transactionId, $userId);
                    $delStmt->execute();
                    $delStmt->close();
                    // نوێکردنەوەی گشتی قەرزی کڕیار
                    $updateCustomerDebt = $conn->prepare("
                        UPDATE customers 
                        SET total_debt = (
                            SELECT COALESCE(SUM(
                                CASE 
                                    WHEN cmd.currency = 'IQD' THEN cmd.amount
                                    ELSE 0
                                END
                            ), 0)
                            FROM customer_money_debts cmd
                            WHERE cmd.customer_id = customers.id 
                            AND cmd.user_id = ?
                            AND cmd.type = 'debt'
                        ) + (
                            SELECT COALESCE(SUM(remaining_amount), 0)
                            FROM debts d
                            WHERE d.customer_id = customers.id
                            AND d.user_id = ?
                            AND d.status = 'active'
                            AND EXISTS (
                                SELECT 1 FROM sales s 
                                WHERE s.id = d.sale_id 
                                AND s.currency = 'IQD'
                            )
                        )
                        WHERE id = ?
                    ");
                    $updateCustomerDebt->bind_param("iii", $userId, $userId, $customerId);
                    $updateCustomerDebt->execute();
                    $updateCustomerDebt->close();
                    $conn->commit();
                    $success = 'مامەڵەکە بە سەرکەوتووی سڕایەوە';
                    writeLog("Money debt transaction deleted: ID $transactionId, customer_id $customerId by user: {$currentUser['email']}");
                    $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                    logCustomerChangeEvent(
                        'customer_debt.transaction_delete',
                        'money_debt_transaction',
                        $transactionId,
                        [
                            'customer_snapshot' => $beforeCustomerSnapshot,
                            'transaction' => $row
                        ],
                        [
                            'customer_snapshot' => $afterCustomerSnapshot,
                            'transaction' => null
                        ],
                        [
                            'user_id' => $userId,
                            'current_user' => $currentUser,
                            'customer_id' => $customerId,
                            'currency' => (string)($row['currency'] ?? 'IQD'),
                            'source_module' => 'user/customers/money_debts.php',
                            'source_reference' => (string)$transactionId
                        ]
                    );
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = 'هەڵە لە سڕینەوە: ' . $e->getMessage();
                }
            }
        }
    }
}

// پرۆسێسی زیادکردنی قەرز/پارەدان
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = $_POST['currency'] ?? 'IQD';
        $description = cleanInput($_POST['description'] ?? '');
        $type = $_POST['type'] ?? 'debt';
        $date = $_POST['date'] ?? date('Y-m-d');
        
        // پشتڕاستکردنەوە
        if ($customerId <= 0) {
            $errors[] = 'کڕیار هەڵبژێرە';
        }
        
        if ($amount <= 0) {
            $errors[] = 'بڕەکە دەبێت گەورەتر لە سفر بێت';
        }
        
        if (!in_array($currency, ['IQD', 'USD'])) {
            $errors[] = 'جۆری پارە نادروستە';
        }
        
        if (empty($description)) {
            $errors[] = 'وردەکاری پێویستە';
        }
        
        if (!in_array($type, ['debt', 'payment'])) {
            $errors[] = 'جۆری مامەڵە نادروستە';
        }
        
        if (empty($errors)) {
            // وەرگرتنی زانیاری کڕیار
            $customerStmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND user_id = ?");
            $customerStmt->bind_param("ii", $customerId, $userId);
            $customerStmt->execute();
            $customer = $customerStmt->get_result()->fetch_assoc();
            
            if (!$customer) {
                $errors[] = 'کڕیار نەدۆزرایەوە';
            } else {
                $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                $conn->begin_transaction();
                
                try {
                    // زیادکردنی مامەڵە
                    $stmt = $conn->prepare("INSERT INTO customer_money_debts (user_id, customer_id, amount, currency, description, type, date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iidssss", $userId, $customerId, $amount, $currency, $description, $type, $date);
                    
                    if ($stmt->execute()) {
                        $newTransactionId = (int)$conn->insert_id;
                        // حیسابکردنی کۆی قەرزی کڕیار بەپێی جۆری پارە
                        $updateCustomerDebt = $conn->prepare("
                            UPDATE customers 
                            SET total_debt = (
                                SELECT COALESCE(SUM(
                                    CASE 
                                        WHEN cmd.currency = 'IQD' THEN cmd.amount
                                        ELSE 0
                                    END
                                ), 0)
                                FROM customer_money_debts cmd
                                WHERE cmd.customer_id = customers.id 
                                AND cmd.user_id = ?
                                AND cmd.type = 'debt'
                            ) + (
                                SELECT COALESCE(SUM(remaining_amount), 0)
                                FROM debts d
                                WHERE d.customer_id = customers.id
                                AND d.user_id = ?
                                AND d.status = 'active'
                                AND EXISTS (
                                    SELECT 1 FROM sales s 
                                    WHERE s.id = d.sale_id 
                                    AND s.currency = 'IQD'
                                )
                            )
                            WHERE id = ?
                        ");
                        $updateCustomerDebt->bind_param("iii", $userId, $userId, $customerId);
                        $updateCustomerDebt->execute();
                        
                        $conn->commit();
                        $success = $type === 'debt' ? 'قەرز زیاد کرا' : 'پارە وەرگیرا';
                        writeLog("Customer money debt transaction: $type - $amount $currency for customer ID: $customerId by user: {$currentUser['email']}");
                        $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                        $eventType = $type === 'debt' ? 'customer_debt.increase' : 'customer_debt.payment';
                        logCustomerChangeEvent(
                            $eventType,
                            'money_debt_transaction',
                            $newTransactionId,
                            [
                                'customer_snapshot' => $beforeCustomerSnapshot,
                                'transaction' => null
                            ],
                            [
                                'customer_snapshot' => $afterCustomerSnapshot,
                                'transaction' => [
                                    'id' => $newTransactionId,
                                    'type' => $type,
                                    'amount' => $amount,
                                    'currency' => $currency,
                                    'description' => $description,
                                    'date' => $date
                                ]
                            ],
                            [
                                'user_id' => $userId,
                                'current_user' => $currentUser,
                                'customer_id' => $customerId,
                                'currency' => $currency,
                                'source_module' => 'user/customers/money_debts.php',
                                'source_reference' => (string)$newTransactionId
                            ]
                        );
                        
                        // پاککردنەوەی POST data
                        $_POST = [];
                    } else {
                        throw new Exception('هەڵە لە تۆمارکردن');
                    }
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = 'هەڵە لە تۆمارکردن: ' . $e->getMessage();
                }
            }
        }
    }
}

// فلتەرەکان
$customerId = (int)($_GET['customer_id'] ?? 0);
$currencyFilter = $_GET['currency'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// دروستکردنی کلۆزی WHERE
$whereConditions = ["cmd.user_id = ?"];
$params = [$userId];
$types = 'i';

if ($customerId > 0) {
    $whereConditions[] = "cmd.customer_id = ?";
    $params[] = $customerId;
    $types .= 'i';
}

if ($currencyFilter !== 'all') {
    $whereConditions[] = "cmd.currency = ?";
    $params[] = $currencyFilter;
    $types .= 's';
}

if ($typeFilter !== 'all') {
    $whereConditions[] = "cmd.type = ?";
    $params[] = $typeFilter;
    $types .= 's';
} else {
    // بە شێوەیەکی بنەڕەتی مامەڵەکانی جۆری "پارەدان" نیشان نادرێت
    $whereConditions[] = "cmd.type != 'payment'";
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// ژماردنی گشتی مامەڵەکان
$countQuery = "SELECT COUNT(*) as total FROM customer_money_debts cmd $whereClause";
$countStmt = $conn->prepare($countQuery);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);

// وەرگرتنی مامەڵەکان
// بۆ هەر وەسڵێک بڕی تایبەت بەخۆی حیساب دەکرێت (بەپێی FIFO)
$query = "
    SELECT cmd.*, 
           c.name as customer_name,
           c.phone as customer_phone,
           DATE_FORMAT(cmd.date, '%Y/%m/%d') as formatted_date,
           DATE_FORMAT(cmd.created_at, '%Y/%m/%d %H:%i') as formatted_created,
           -- بۆ وەسڵەکان: بڕی ئەو وەسڵە تایبەت
           CASE 
               WHEN cmd.type = 'debt' THEN cmd.amount
               ELSE 0
           END as total_debt,
           -- پارەی وەرگیراو بۆ ئەم وەسڵە تایبەتە (بەپێی FIFO - کۆنیترین قەرزەکان یەکەم دەدرێن)
           CASE 
               WHEN cmd.type = 'debt' THEN
                   LEAST(
                       cmd.amount,
                       GREATEST(0,
                           COALESCE((
                               SELECT SUM(amount)
                               FROM customer_money_debts all_payments
                               WHERE all_payments.customer_id = cmd.customer_id
                               AND all_payments.user_id = cmd.user_id
                               AND all_payments.type = 'payment'
                               AND all_payments.currency = cmd.currency
                           ), 0) - COALESCE((
                               SELECT SUM(amount)
                               FROM customer_money_debts prev_debts
                               WHERE prev_debts.customer_id = cmd.customer_id
                               AND prev_debts.user_id = cmd.user_id
                               AND prev_debts.type = 'debt'
                               AND prev_debts.currency = cmd.currency
                               AND (
                                   prev_debts.date < cmd.date 
                                   OR (prev_debts.date = cmd.date AND prev_debts.created_at < cmd.created_at)
                               )
                           ), 0)
                       )
                   )
               ELSE 0
           END as total_received,
           -- قەرزی ماوە بۆ ئەم وەسڵە تایبەتە
           CASE 
               WHEN cmd.type = 'debt' THEN
                   cmd.amount - LEAST(
                       cmd.amount,
                       GREATEST(0,
                           COALESCE((
                               SELECT SUM(amount)
                               FROM customer_money_debts all_payments
                               WHERE all_payments.customer_id = cmd.customer_id
                               AND all_payments.user_id = cmd.user_id
                               AND all_payments.type = 'payment'
                               AND all_payments.currency = cmd.currency
                           ), 0) - COALESCE((
                               SELECT SUM(amount)
                               FROM customer_money_debts prev_debts
                               WHERE prev_debts.customer_id = cmd.customer_id
                               AND prev_debts.user_id = cmd.user_id
                               AND prev_debts.type = 'debt'
                               AND prev_debts.currency = cmd.currency
                               AND (
                                   prev_debts.date < cmd.date 
                                   OR (prev_debts.date = cmd.date AND prev_debts.created_at < cmd.created_at)
                               )
                           ), 0)
                       )
                   )
               ELSE 0
           END as remaining_debt
    FROM customer_money_debts cmd
    LEFT JOIN customers c ON cmd.customer_id = c.id
    $whereClause
    ORDER BY cmd.date DESC, cmd.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ئامارەکان
$statsQuery = "
    SELECT 
        SUM(CASE WHEN type = 'debt' AND currency = 'IQD' THEN amount ELSE 0 END) as total_debt_iqd,
        SUM(CASE WHEN type = 'debt' AND currency = 'USD' THEN amount ELSE 0 END) as total_debt_usd,
        SUM(CASE WHEN type = 'payment' AND currency = 'IQD' THEN amount ELSE 0 END) as total_payments_iqd,
        SUM(CASE WHEN type = 'payment' AND currency = 'USD' THEN amount ELSE 0 END) as total_payments_usd,
        COUNT(CASE WHEN type = 'debt' THEN 1 END) as debt_transactions,
        COUNT(CASE WHEN type = 'payment' THEN 1 END) as payment_transactions,
        COUNT(*) as total_transactions
    FROM customer_money_debts cmd
    WHERE cmd.user_id = ?
";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->bind_param("i", $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// حیسابکردنی قەرزی ماوە
$remainingIQD = ($stats['total_debt_iqd'] ?? 0) - ($stats['total_payments_iqd'] ?? 0);
$remainingUSD = ($stats['total_debt_usd'] ?? 0) - ($stats['total_payments_usd'] ?? 0);

// وەرگرتنی کڕیاران بۆ فلتەر و فۆرم
$customersQuery = "SELECT id, name, phone FROM customers WHERE user_id = ? AND status = 'active' ORDER BY name";
$customersStmt = $conn->prepare($customersQuery);
$customersStmt->bind_param("i", $userId);
$customersStmt->execute();
$customers = $customersStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی لیستی پارەدانەوەکان بۆ وەسڵ
$paymentDetails = [];
$paymentCustomer = null;
$paymentCurrency = null;

if (isset($_GET['view_payments']) && $customerId > 0 && isset($_GET['currency']) && $_GET['currency'] !== 'all') {
    $paymentCurrency = $_GET['currency'];

    $paymentsStmt = $conn->prepare("
        SELECT cmd.*, 
               c.name AS customer_name,
               c.phone AS customer_phone,
               DATE_FORMAT(cmd.date, '%Y/%m/%d') AS formatted_date,
               DATE_FORMAT(cmd.created_at, '%Y/%m/%d %H:%i') AS formatted_created
        FROM customer_money_debts cmd
        LEFT JOIN customers c ON cmd.customer_id = c.id
        WHERE cmd.user_id = ? 
          AND cmd.customer_id = ? 
          AND cmd.type = 'payment' 
          AND cmd.currency = ?
        ORDER BY cmd.date DESC, cmd.created_at DESC
    ");
    $paymentsStmt->bind_param("iis", $userId, $customerId, $paymentCurrency);
    $paymentsStmt->execute();
    $paymentDetails = $paymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $paymentsStmt->close();

    if (!empty($paymentDetails)) {
        $paymentCustomer = [
            'name' => $paymentDetails[0]['customer_name'] ?? '',
            'phone' => $paymentDetails[0]['customer_phone'] ?? ''
        ];
    } else {
        // وەرگرتنی ناوی کڕیار کاتێک پارەدانەوە نییە
        $pcStmt = $conn->prepare("SELECT name, phone FROM customers WHERE id = ? AND user_id = ?");
        $pcStmt->bind_param("ii", $customerId, $userId);
        $pcStmt->execute();
        $paymentCustomer = $pcStmt->get_result()->fetch_assoc() ?: null;
        $pcStmt->close();
    }
}

$csrf_token = Security::generateCSRFToken();
$pageTitle = 'قەرزی پارەی کڕیاران';
$bodyClass = 'customers-module-page customers-debts-page customers-page';
$additionalCSS = ['customers/customers-pages.css', 'customers/customers-dark.css', 'customers/customers-responsive.css'];
include '../../includes/header.php';
?>

<style>
    html[data-bs-theme='dark'] .theme-footer {
        background: #111827 !important;
        border-color: #374151 !important;
    }

    html[data-bs-theme='dark'] .theme-footer .text-muted {
        color: #9ca3af !important;
    }

    html[data-bs-theme='dark'] .text-gray-800,
    html[data-bs-theme='dark'] .font-weight-bold,
    html[data-bs-theme='dark'] .text-xs {
        color: #e5e7eb !important;
    }

    html[data-bs-theme='dark'] .text-gray-300 {
        color: #6b7280 !important;
    }

    html[data-bs-theme='dark'] .table-light,
    html[data-bs-theme='dark'] .table-light th {
        background-color: #1f2937 !important;
        color: #d1d5db !important;
        border-color: #374151 !important;
    }

    html[data-bs-theme='dark'] .card,
    html[data-bs-theme='dark'] .modal-content,
    html[data-bs-theme='dark'] .dropdown-menu {
        background-color: #111827 !important;
        border-color: #374151 !important;
        color: #e5e7eb !important;
    }

    #transactionFormContainer {
        display: none;
        background: var(--surface-1, #ffffff);
    }

    html[data-bs-theme='dark'] #transactionFormContainer {
        background: #111827;
    }

    .customer-filter-card {
        position: relative;
        z-index: 30;
        overflow: visible;
    }

    .customer-filter-card .card-body {
        overflow: visible;
    }

    .customer-combobox {
        position: relative;
    }

    .customer-combobox .customer-search-input {
        border-radius: 0.6rem;
        padding-right: 2.25rem;
    }

    .customer-combobox .customer-search-icon {
        position: absolute;
        top: 12px;
        right: 12px;
        color: var(--bs-secondary-color);
        pointer-events: none;
        z-index: 2;
    }

    .customer-combobox .customer-dropdown {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        z-index: 1080;
        border: 1px solid var(--bs-border-color);
        border-radius: 0.75rem;
        background: var(--bs-body-bg);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
        max-height: 280px;
        overflow-y: auto;
        display: none;
    }

    .customer-combobox .customer-dropdown.show {
        display: block;
    }

    .customer-combobox .customer-option {
        cursor: pointer;
        padding: 0.6rem 0.8rem;
        border-bottom: 1px solid var(--bs-border-color-translucent);
    }

    .customer-combobox .customer-option:last-child {
        border-bottom: 0;
    }

    .customer-combobox .customer-option.active,
    .customer-combobox .customer-option:hover {
        background: var(--bs-primary-bg-subtle);
    }

    .customer-combobox .customer-option .name {
        font-weight: 600;
        display: block;
    }

    .customer-combobox .customer-option .meta {
        font-size: 0.83rem;
        color: var(--bs-secondary-color);
    }
</style>

<div class="container-fluid customers-page-content cu-wrap">
    <header class="cu-hero">
        <div>
            <div class="cu-kicker"><i class="bi bi-wallet2"></i> قەرزی پارە</div>
            <h1><i class="bi bi-cash-stack"></i> قەرزی پارەی کڕیاران</h1>
            <p class="cu-hero-sub">بەڕێوەبردنی قەرز و پارەدانی کڕیاران بە دینار و دۆلار</p>
        </div>
        <div class="cu-actions">
            <a href="<?php echo url('user/customers/index.php'); ?>" class="cu-btn cu-btn-ghost">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
        </div>
    </header>

    <!-- Alerts -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['view_payments']) && $customerId > 0 && $paymentCurrency): ?>
        <div class="card mb-4 border-left-primary shadow">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">
                    وەسڵی پارەدانەوەکان
                    <?php if ($paymentCustomer): ?>
                        <span class="badge bg-primary ms-2">
                            <?php echo htmlspecialchars($paymentCustomer['name'] ?? ''); ?>
                            (<?php echo htmlspecialchars($paymentCurrency); ?>)
                        </span>
                    <?php endif; ?>
                </h6>
                <a href="<?php echo url('user/customers/money_debts.php'); ?>" class="btn btn-sm btn-outline-secondary">
                    گەرانەوە بۆ هەموو مامەڵەکان
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($paymentDetails)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-card-list display-4 text-muted"></i>
                        <h5 class="text-muted mt-3">هیچ پارەدانەوەیەک نەدۆزرایەوە بۆ ئەم کڕیارە</h5>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0 customers-debts-table">
                            <thead class="table-light">
                                <tr>
                                    <th>بەروار</th>
                                    <th>بڕ</th>
                                    <th>وردەکاری</th>
                                    <th>تۆمارکراو لە</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paymentDetails as $payment): ?>
                                    <tr>
                                        <td><?php echo $payment['formatted_date']; ?></td>
                                        <td class="text-success fw-bold">
                                            <?php echo formatCurrencyAmount($payment['amount'], $payment['currency']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment['description']); ?></td>
                                        <td><?php echo $payment['formatted_created']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="cu-stats cu-stats-4">
        <div class="cu-stat" style="--stat-accent:#4f46e5">
            <div class="cu-stat-icon"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="cu-stat-label">کۆی قەرز (دینار)</div>
                <div class="cu-stat-value"><?php echo formatCurrencyAmount($stats['total_debt_iqd'] ?? 0, 'IQD'); ?></div>
                <div class="cu-stat-meta"><?php echo $stats['debt_transactions'] ?? 0; ?> مامەڵە</div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#10b981">
            <div class="cu-stat-icon"><i class="bi bi-currency-dollar"></i></div>
            <div>
                <div class="cu-stat-label">کۆی قەرز (دۆلار)</div>
                <div class="cu-stat-value"><?php echo formatCurrencyAmount($stats['total_debt_usd'] ?? 0, 'USD'); ?></div>
                <div class="cu-stat-meta"><?php echo $stats['debt_transactions'] ?? 0; ?> مامەڵە</div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#0ea5e9">
            <div class="cu-stat-icon"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="cu-stat-label">کۆی پارەدان</div>
                <div class="cu-stat-value">
                    <?php 
                    $paidIQD = $stats['total_payments_iqd'] ?? 0;
                    $paidUSD = $stats['total_payments_usd'] ?? 0;
                    if ($paidUSD > 0 && $paidIQD > 0) {
                        echo formatCurrencyAmount($paidIQD, 'IQD') . ' + ' . formatCurrencyAmount($paidUSD, 'USD');
                    } elseif ($paidUSD > 0) {
                        echo formatCurrencyAmount($paidUSD, 'USD');
                    } else {
                        echo formatCurrencyAmount($paidIQD, 'IQD');
                    }
                    ?>
                </div>
                <div class="cu-stat-meta"><?php echo $stats['payment_transactions'] ?? 0; ?> مامەڵە</div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#f59e0b">
            <div class="cu-stat-icon"><i class="bi bi-clock-history"></i></div>
            <div>
                <div class="cu-stat-label">قەرزی ماوە</div>
                <div class="cu-stat-value">
                    <?php 
                    if ($remainingUSD > 0 && $remainingIQD > 0) {
                        echo formatCurrencyAmount($remainingIQD, 'IQD') . ' + ' . formatCurrencyAmount($remainingUSD, 'USD');
                    } elseif ($remainingUSD > 0) {
                        echo formatCurrencyAmount($remainingUSD, 'USD');
                    } else {
                        echo formatCurrencyAmount($remainingIQD, 'IQD');
                    }
                    ?>
                </div>
                <div class="cu-stat-meta"><?php echo $stats['total_transactions'] ?? 0; ?> مامەڵە</div>
            </div>
        </div>
    </div>

    <div class="cu-panel mb-4 customer-filter-card">
        <div class="cu-panel-body">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">کڕیار:</label>
                    <div class="customer-combobox">
                        <i class="bi bi-search customer-search-icon"></i>
                        <input type="text"
                               id="customerSearchInput"
                               class="form-control customer-search-input"
                               placeholder="بە ناو یان تەلەفۆن گەڕان بکە..."
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                        <div id="customerDropdown" class="customer-dropdown" role="listbox" aria-label="لیستی کڕیاران"></div>
                    </div>
                    <select name="customer_id" id="customerSelect" class="form-select d-none">
                        <option value="0">هەموو کڕیاران</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars((string)($customer['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-phone="<?php echo htmlspecialchars((string)($customer['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $customerId == $customer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?>
                                <?php if (!empty($customer['phone'])): ?>
                                    - <?php echo htmlspecialchars($customer['phone']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">جۆری پارە:</label>
                    <select name="currency" class="form-select">
                        <option value="all" <?php echo $currencyFilter === 'all' ? 'selected' : ''; ?>>هەموو</option>
                        <option value="IQD" <?php echo $currencyFilter === 'IQD' ? 'selected' : ''; ?>>دینار</option>
                        <option value="USD" <?php echo $currencyFilter === 'USD' ? 'selected' : ''; ?>>دۆلار</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">جۆری مامەڵە:</label>
                    <select name="type" class="form-select">
                        <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>هەموو</option>
                        <option value="debt" <?php echo $typeFilter === 'debt' ? 'selected' : ''; ?>>قەرز</option>
                        <option value="payment" <?php echo $typeFilter === 'payment' ? 'selected' : ''; ?>>پارەدان</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> گەڕان
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i> پاککردنەوە
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Transaction Form -->
    <div class="card shadow mb-4 border-0" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; overflow: hidden;">
        <div class="card-body p-0">
            <button type="button" class="btn btn-lg w-100 text-white p-4" 
                    onclick="toggleTransactionForm()" 
                    style="background: transparent; border: none; text-align: right;"
                    id="addDebtBtn">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-white bg-opacity-20 rounded-circle p-3" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-plus-circle-fill" style="font-size: 1.8rem;"></i>
                        </div>
                        <div class="text-start">
                            <h5 class="mb-1 fw-bold">زیادکردنی قەرزی پارە</h5>
                            <p class="mb-0 opacity-75" style="font-size: 0.9rem;">کلیک بکە بۆ زیادکردنی قەرزی نوێ</p>
                        </div>
                    </div>
                    <i class="bi bi-chevron-down" id="formToggleIcon" style="font-size: 1.5rem; transition: transform 0.3s;"></i>
                </div>
            </button>
        </div>
        <div class="card-body" id="transactionFormContainer">
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="add_transaction" value="1">
                
                <div class="col-md-2">
                    <label class="form-label">جۆری مامەڵە</label>
                    <input type="hidden" name="type" value="debt">
                    <input type="text" class="form-control" value="قەرز (زیادکردن)" disabled>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">کڕیار</label>
                    <select class="form-select" name="customer_id" required>
                        <option value="">هەڵبژاردن...</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>">
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">جۆری پارە</label>
                    <select class="form-select" name="currency" required>
                        <option value="IQD">دینار</option>
                        <option value="USD">دۆلار</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">بڕ</label>
                    <input type="number" class="form-control" name="amount" 
                           min="0.001" step="0.001" required>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">بەروار</label>
                    <input type="date" class="form-control" name="date" 
                           value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-circle"></i> زیادکردن
                    </button>
                </div>
                
                <div class="col-12">
                    <label class="form-label">وردەکاری</label>
                    <textarea class="form-control" name="description" rows="2" 
                              placeholder="وردەکاری مامەڵەکە..." required></textarea>
                </div>
            </form>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                لیستی مامەڵەکان
                <span class="badge bg-primary ms-2"><?php echo $totalRecords; ?></span>
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($transactions)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h5 class="text-muted mt-3">هیچ مامەڵەیەک نەدۆزرایەوە</h5>
                    <p class="text-muted">لەگەڵ فلتەرەکانی هەڵبژاردوو هیچ مامەڵەیەک نەدۆزرایەوە</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>کڕیار</th>
                                <th>تەلەفۆن</th>
                                <th>جۆری مامەڵە</th>
                                <th>جۆری پارە</th>
                                <th>کۆی قەرز</th>
                                <th>پارە وەرگیراو</th>
                                <th>قەرزی ماوە</th>
                                <th>بەروار</th>
                                <th>کردارەکان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($transaction['customer_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($transaction['customer_phone'] ?: 'بێ تەلەفۆن'); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $transaction['type'] === 'debt' ? 'danger' : 'success'; ?>">
                                            <?php echo $transaction['type'] === 'debt' ? 'قەرز' : 'پارەدان'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $transaction['currency'] === 'USD' ? 'info' : 'secondary'; ?>">
                                            <?php echo $transaction['currency']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-primary">
                                            <?php echo formatCurrencyAmount($transaction['total_debt'], $transaction['currency']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-success">
                                            <?php echo formatCurrencyAmount($transaction['total_received'], $transaction['currency']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-danger">
                                            <?php echo formatCurrencyAmount($transaction['remaining_debt'], $transaction['currency']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $transaction['formatted_date']; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($transaction['type'] === 'debt' && $transaction['remaining_debt'] > 0): ?>
                                                <button class="btn btn-sm btn-success" 
                                                        onclick="showPaymentModal(<?php echo $transaction['id']; ?>, '<?php echo htmlspecialchars($transaction['customer_name']); ?>', <?php echo $transaction['remaining_debt']; ?>, '<?php echo $transaction['currency']; ?>')">
                                                    <i class="bi bi-credit-card"></i> پارەدان
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($transaction['type'] === 'debt'): ?>
                                                <a href="<?php echo url('user/customers/money_debts.php?customer_id=' . $transaction['customer_id'] . '&currency=' . $transaction['currency'] . '&view_payments=1'); ?>"
                                                   class="btn btn-sm btn-outline-primary" title="وەسڵ">
                                                    <i class="bi bi-card-list"></i> وەسڵ
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('دڵنیایت دەتەوێت ئەم مامەڵەیە بسڕیتەوە؟');">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                                <input type="hidden" name="action" value="delete_money_debt">
                                                <input type="hidden" name="transaction_id" value="<?php echo (int)$transaction['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="سڕینەوە">
                                                    <i class="bi bi-trash"></i> سڕینەوە
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        پێشوو
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        داهاتوو
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">پارەدانی قەرزی پارە</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="paymentForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="pay_money_debt">
                    <input type="hidden" name="transaction_id" id="paymentTransactionId">
                    
                    <div class="mb-3">
                        <label class="form-label">کڕیار:</label>
                        <input type="text" class="form-control" id="paymentCustomerName" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">قەرزی ماوە:</label>
                        <input type="text" class="form-control" id="paymentRemainingAmount" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">بڕی پارە <span class="text-danger">*</span></label>
                        <input type="number" name="payment_amount" class="form-control" 
                               step="0.01" min="0.01" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">شێوەی پارەدان:</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">نەقد</option>
                            <option value="bank_transfer">گواستنەوەی بانکی</option>
                            <option value="check">چیک</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تێبینی:</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                    <button type="submit" class="btn btn-success">پارەدان</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- WhatsApp Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-whatsapp"></i> ناردن بۆ واتس ئەپپ
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                </div>
                <h4 class="mb-3">پارەدانەوە سەرکەوتوو بوو!</h4>
                
                <div class="alert alert-info text-start">
                    <div class="mb-2">
                        <strong>کڕیار:</strong> <span id="whatsappCustomerName"></span>
                    </div>
                    <div class="mb-2">
                        <strong>پارەی داوتەوە:</strong> <span id="whatsappPaymentAmount" class="text-success"></span>
                    </div>
                    <div>
                        <strong>قەرزی ماوە:</strong> <span id="whatsappRemainingAmount" class="text-danger"></span>
                    </div>
                </div>
                
                <p class="text-muted mb-4">
                    دەتەوێت نامەی پارەدانەوە بنێریت بۆ کڕیار لە ڕێگەی واتس ئەپپەوە؟
                </p>
                
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success btn-lg" id="whatsappSendBtn">
                        <i class="bi bi-whatsapp"></i> ناردن بۆ واتس ئەپپ
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> نەخێر، سوپاس
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// تاقیکردنەوەی هەبوونی زانیاری واتس ئەپپ و نیشاندانی مۆدال
<?php if (isset($_SESSION['whatsapp_payment_data'])): ?>
    const whatsappData = <?php echo json_encode($_SESSION['whatsapp_payment_data']); ?>;
    <?php unset($_SESSION['whatsapp_payment_data']); ?>
    
    // نیشاندانی مۆدالی واتس ئەپپ دوای باری پەڕە
    document.addEventListener('DOMContentLoaded', function() {
        showWhatsAppModal(whatsappData);
    });
<?php endif; ?>

// فەنکشنی کردنەوە/داخستنی فۆرمی زیادکردنی قەرز
function toggleTransactionForm() {
    const formContainer = document.getElementById('transactionFormContainer');
    const toggleIcon = document.getElementById('formToggleIcon');
    
    if (formContainer.style.display === 'none') {
        formContainer.style.display = 'block';
        toggleIcon.style.transform = 'rotate(180deg)';
        // هەڕەمەکی بۆ سەر فۆرم
        setTimeout(() => {
            formContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 100);
    } else {
        formContainer.style.display = 'none';
        toggleIcon.style.transform = 'rotate(0deg)';
    }
}

// نیشاندانی فۆرم ئەگەر هەڵە هەبێت (بۆ نیشاندانی فۆرم دوای تاقیکردنەوە)
<?php if (!empty($errors) && isset($_POST['add_transaction'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    toggleTransactionForm();
});
<?php endif; ?>

function showPaymentModal(transactionId, customerName, remainingAmount, currency) {
    document.getElementById('paymentTransactionId').value = transactionId;
    document.getElementById('paymentCustomerName').value = customerName;
    
    // شێوەکردنی بڕ بەپێی دراو
    const currencySymbol = (currency === 'USD') ? '$' : ' دینار';
    const decimals = (currency === 'USD') ? 2 : 0;
    const formattedAmount = parseFloat(remainingAmount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    document.getElementById('paymentRemainingAmount').value = formattedAmount + currencySymbol;
    
    document.querySelector('input[name="payment_amount"]').max = remainingAmount;
    document.querySelector('input[name="payment_amount"]').value = '';
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

function showWhatsAppModal(data) {
    const modal = new bootstrap.Modal(document.getElementById('whatsappModal'));
    
    // وەرگرتنی دراو
    const currency = data.currency || 'IQD';
    const currencySymbol = (currency === 'USD') ? '$' : ' دینار';
    const decimals = (currency === 'USD') ? 2 : 0;
    
    // پڕکردنەوەی زانیاری
    document.getElementById('whatsappCustomerName').textContent = data.customer_name;
    
    const paymentFormatted = parseFloat(data.payment_amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    document.getElementById('whatsappPaymentAmount').textContent = paymentFormatted + currencySymbol;
    
    const remainingFormatted = parseFloat(data.remaining_amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    document.getElementById('whatsappRemainingAmount').textContent = remainingFormatted + currencySymbol;
    
    // هەڵگرتنی زانیاری بۆ ناردن
    document.getElementById('whatsappSendBtn').onclick = function() {
        sendPaymentToWhatsApp(data);
    };
    
    modal.show();
}

function sendPaymentToWhatsApp(data) {
    // وەرگرتنی دراو
    const currency = data.currency || 'IQD';
    const currencySymbol = (currency === 'USD') ? '$' : ' دینار';
    const decimals = (currency === 'USD') ? 2 : 0;
    
    // شێوەکردنی بڕەکان
    const paymentFormatted = parseFloat(data.payment_amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    const remainingFormatted = parseFloat(data.remaining_amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    
    // ئامادەکردنی نامە
    let message = `*وەسڵی پارەدانەوە*\n\n`;
    message += `*${data.business_name}*\n`;
    message += `━━━━━━━━━━━━━━━━━━━━\n\n`;
    message += `ژمارەی وەسڵ: ${data.receipt_number}\n`;
    message += `بەروار: ${new Date().toLocaleDateString('ku-IQ')}\n`;
    message += `کات: ${new Date().toLocaleTimeString('ku-IQ', {hour: '2-digit', minute: '2-digit'})}\n`;
    message += `کڕیار: ${data.customer_name}\n\n`;
    message += `━━━━━━━━━━━━━━━━━━━━\n`;
    message += `💰 *پارەی داوتەوە: ${paymentFormatted}${currencySymbol}*\n`;
    message += `📊 *قەرزی ماوە: ${remainingFormatted}${currencySymbol}*\n`;
    message += `━━━━━━━━━━━━━━━━━━━━\n\n`;
    message += `سوپاس بۆ پارەدانەوەکەت\n`;
    message += `سیستەمی NexoraCore\nNexoraCore.com`;
    
    // پاککردنەوەی ژمارە تەلەفۆن
    let phoneNumber = data.customer_phone.replace(/\D/g, '');
    
    // ئەگەر ژمارەکە بە 0 دەست پێ دەکات، بیگۆڕە بۆ کۆدی وڵات
    if (phoneNumber.startsWith('0')) {
        phoneNumber = '964' + phoneNumber.substring(1);
    } else if (!phoneNumber.startsWith('964')) {
        phoneNumber = '964' + phoneNumber;
    }
    
    // دروستکردنی لینکی واتس ئەپپ
    const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
    
    // کردنەوەی واتس ئەپپ
    window.open(whatsappUrl, '_blank');
    
    // داخستنی مۆدال
    bootstrap.Modal.getInstance(document.getElementById('whatsappModal')).hide();
}

document.addEventListener('DOMContentLoaded', function() {
    const customerSearchInput = document.getElementById('customerSearchInput');
    const customerDropdown = document.getElementById('customerDropdown');
    const customerSelect = document.getElementById('customerSelect');

    if (!customerSearchInput || !customerDropdown || !customerSelect) {
        return;
    }

    const allCustomerOption = customerSelect.querySelector('option[value="0"]');
    const customerOptions = Array.from(customerSelect.options)
        .filter(option => option.value !== '0')
        .map(option => ({
            id: option.value,
            name: option.dataset.name || '',
            phone: option.dataset.phone || '',
            label: option.textContent.trim()
        }));

    let activeIndex = -1;
    let visibleCustomers = [...customerOptions];
    let currentQuery = '';

    function normalize(text) {
        return (text || '').toLowerCase().trim();
    }

    function escapeHtml(value) {
        return (value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderDropdown() {
        const noResultHtml = '<div class="customer-option"><span class="meta">هیچ کڕیارێک نەدۆزرایەوە</span></div>';
        const allOptionHtml = `
            <div class="customer-option ${customerSelect.value === '0' ? 'active' : ''}" data-id="0" role="option">
                <span class="name">${escapeHtml(allCustomerOption ? allCustomerOption.textContent.trim() : 'هەموو کڕیاران')}</span>
                <span class="meta">بۆ نیشاندانی هەموو ئەنجامەکان</span>
            </div>
        `;

        const customersHtml = visibleCustomers.map((customer, index) => `
            <div class="customer-option ${index === activeIndex ? 'active' : ''}" data-id="${customer.id}" role="option">
                <span class="name">${escapeHtml(customer.name || 'بێ ناو')}</span>
                <span class="meta">${escapeHtml(customer.phone || '')}</span>
            </div>
        `).join('');

        const shouldShowAllOption = currentQuery === '';
        customerDropdown.innerHTML = (shouldShowAllOption ? allOptionHtml : '') + (customersHtml || noResultHtml);
    }

    function openDropdown() {
        customerDropdown.classList.add('show');
        renderDropdown();
    }

    function closeDropdown() {
        customerDropdown.classList.remove('show');
        activeIndex = -1;
    }

    function setSelectedCustomer(customerId, fromUserInput = false) {
        customerSelect.value = customerId;
        if (customerId === '0') {
            customerSearchInput.value = fromUserInput ? '' : (allCustomerOption ? allCustomerOption.textContent.trim() : '');
            return;
        }

        const selected = customerOptions.find(customer => customer.id === customerId);
        if (selected) {
            customerSearchInput.value = selected.name + (selected.phone ? ` - ${selected.phone}` : '');
        }
    }

    function filterCustomers() {
        const query = normalize(customerSearchInput.value);
        currentQuery = query;
        visibleCustomers = customerOptions.filter(customer =>
            normalize(customer.name).includes(query) || normalize(customer.phone).includes(query)
        );
        activeIndex = visibleCustomers.length ? 0 : -1;
        openDropdown();
    }

    customerSearchInput.addEventListener('focus', function() {
        currentQuery = normalize(customerSearchInput.value);
        visibleCustomers = [...customerOptions];
        activeIndex = -1;
        openDropdown();
    });

    customerSearchInput.addEventListener('input', function() {
        const query = normalize(customerSearchInput.value);
        currentQuery = query;
        if (query === '') {
            setSelectedCustomer('0', true);
            visibleCustomers = [...customerOptions];
            activeIndex = -1;
            openDropdown();
            return;
        }
        filterCustomers();
    });

    customerSearchInput.addEventListener('keydown', function(event) {
        if (!customerDropdown.classList.contains('show')) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (visibleCustomers.length > 0) {
                activeIndex = (activeIndex + 1) % visibleCustomers.length;
                renderDropdown();
            }
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (visibleCustomers.length > 0) {
                activeIndex = activeIndex <= 0 ? visibleCustomers.length - 1 : activeIndex - 1;
                renderDropdown();
            }
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (activeIndex >= 0 && visibleCustomers[activeIndex]) {
                setSelectedCustomer(visibleCustomers[activeIndex].id);
            }
            closeDropdown();
        } else if (event.key === 'Escape') {
            closeDropdown();
        }
    });

    customerDropdown.addEventListener('mousedown', function(event) {
        const optionElement = event.target.closest('.customer-option');
        if (!optionElement) return;

        const optionId = optionElement.dataset.id ?? '0';
        setSelectedCustomer(optionId, optionId === '0');
        closeDropdown();
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.customer-combobox')) {
            closeDropdown();
        }
    });

    if (customerSelect.value && customerSelect.value !== '0') {
        setSelectedCustomer(customerSelect.value);
    } else {
        customerSearchInput.value = '';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>

