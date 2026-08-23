<?php
/**
 * بەشی قەرزەکانی کڕیاران - user/customers/credit_sales.php
 * Credit Sales Management Page
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/customer_change_logger.php';
require_once __DIR__ . '/../../includes/debt_payment_breakdown.php';
require_once '../../includes/sale_return_service.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$errors = [];
$success = '';

// پرۆسەکردنی پارەدان
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::validateCSRFToken($_POST['csrf_token'])) {
    $action = $_POST['action'] ?? '';
    
    // پارەدانی کۆمەڵە
    if ($action === 'pay_multiple_debts') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $totalPayment = (float)($_POST['total_payment'] ?? 0);
        $paymentMethod = 'cash';
        $notes = cleanInput($_POST['notes'] ?? '');
        $paymentDateInput = trim((string)($_POST['payment_datetime'] ?? ''));
        $paymentDate = date('Y-m-d H:i:s');
        $selectedDebtsInput = $_POST['selected_debts'] ?? [];
        $selectedDebts = [];
        if ($paymentDateInput !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $paymentDateInput)) {
                $errors[] = 'کات و بەروار دروست نییە';
            } else {
                $normalizedDate = str_replace('T', ' ', $paymentDateInput);
                if (strlen($normalizedDate) === 16) {
                    $normalizedDate .= ':00';
                }
                $paymentDate = $normalizedDate;
            }
        }

        if (is_array($selectedDebtsInput)) {
            $selectedDebts = array_values(array_filter(array_unique(array_map('intval', $selectedDebtsInput)), function ($id) {
                return $id > 0;
            }));
        }
        
        if ($totalPayment <= 0) {
            $errors[] = 'بڕی پارە دەبێت زیاتر لە سفر بێت';
        } elseif (empty($selectedDebts)) {
            $errors[] = 'تکایە لانیکەم یەک وەسڵ هەڵبژێرە';
        } else {
            // وەرگرتنی وەسڵە هەڵبژێردراوەکان
            $debtIds = array_map('intval', $selectedDebts);
            $placeholders = str_repeat('?,', count($debtIds) - 1) . '?';
            
            $debtsQuery = "SELECT d.*, c.name as customer_name, c.phone as customer_phone,
                          COALESCE(s.currency, 'IQD') as currency
                          FROM debts d
                          LEFT JOIN customers c ON d.customer_id = c.id
                          LEFT JOIN sales s ON d.sale_id = s.id
                          WHERE d.id IN ($placeholders) 
                          AND d.user_id = ? 
                          AND d.customer_id = ?
                          AND d.status = 'active'
                          ORDER BY d.created_at ASC";
            
            $debtsStmt = $conn->prepare($debtsQuery);
            $bindParams = array_merge($debtIds, [$userId, $customerId]);
            $types = str_repeat('i', count($debtIds)) . 'ii';
            $debtsStmt->bind_param($types, ...$bindParams);
            $debtsStmt->execute();
            $selectedDebtsData = $debtsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $debtsStmt->close();
            
            if (empty($selectedDebtsData)) {
                $errors[] = 'هیچ وەسڵێکی دروست نەدۆزرایەوە';
            } elseif (count($selectedDebtsData) !== count($debtIds)) {
                $errors[] = 'هەندێک وەسڵ گونجاو نین یان پێشتر پارەیان دراوە؛ تکایە لیستەکە نوێ بکەرەوە';
            } else {
                $currencySet = [];
                foreach ($selectedDebtsData as $debt) {
                    $normalizedCurrency = strtoupper(trim((string)($debt['currency'] ?? 'IQD')));
                    $currencySet[$normalizedCurrency === 'USD' ? 'USD' : 'IQD'] = true;
                }
                $selectedCurrencies = array_keys($currencySet);
                if (count($selectedCurrencies) !== 1) {
                    $errors[] = 'پارەدانی کۆمەڵە تەنها بۆ یەک دراوە (IQD یان USD) ڕێگەپێدراوە';
                }
            }

            if (empty($errors)) {
                $paymentCurrency = $selectedCurrencies[0];
                $amountPrecision = $paymentCurrency === 'USD' ? 2 : 0;
                $epsilon = $paymentCurrency === 'USD' ? 0.00001 : 0.5;
                $normalizeAmount = function ($amount) use ($amountPrecision) {
                    return round((float)$amount, $amountPrecision);
                };
                $totalPayment = $normalizeAmount($totalPayment);

                // حیسابکردنی کۆی قەرزی ماوە
                $totalRemaining = 0.0;
                foreach ($selectedDebtsData as $debt) {
                    $totalRemaining += $normalizeAmount($debt['remaining_amount'] ?? 0);
                }
                $totalRemaining = $normalizeAmount($totalRemaining);
                
                if ($totalPayment > ($totalRemaining + $epsilon)) {
                    $errors[] = 'بڕی پارە زیاترە لە کۆی قەرزی ماوە (' . formatMoney($totalRemaining, $paymentCurrency) . ')';
                } else {
                    $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                    $conn->begin_transaction();
                    
                    try {
                        $remainingPayment = $totalPayment;
                        $paidDebts = [];
                        
                        // دابەشکردنی پارە بەسەر وەسڵەکاندا
                        foreach ($selectedDebtsData as $debt) {
                            if ($remainingPayment <= 0) break;
                            
                            $debtRemaining = $normalizeAmount($debt['remaining_amount'] ?? 0);
                            $paymentForThisDebt = $normalizeAmount(min($remainingPayment, $debtRemaining));
                            if ($paymentForThisDebt <= $epsilon) {
                                continue;
                            }
                            
                            // زیادکردنی پارەدان
                            $insertPayment = $conn->prepare("
                                INSERT INTO debt_payments (debt_id, payment_amount, notes, payment_date) 
                                VALUES (?, ?, ?, ?)
                            ");
                            $paymentNotes = $notes . ' (پارەدانی کۆمەڵە)';
                            $insertPayment->bind_param("idss", $debt['id'], $paymentForThisDebt, $paymentNotes, $paymentDate);
                            $insertPayment->execute();
                            $paymentId = $conn->insert_id;
                            
                            // نوێکردنەوەی قەرز
                            $newPaidAmount = $normalizeAmount(($debt['paid_amount'] ?? 0) + $paymentForThisDebt);
                            $newRemainingAmount = $normalizeAmount(($debt['total_debt'] ?? 0) - $newPaidAmount);
                            if ($newRemainingAmount <= $epsilon) {
                                $newRemainingAmount = 0.0;
                            }
                            $newStatus = $newRemainingAmount <= 0 ? 'completed' : 'active';
                            
                            $updateDebt = $conn->prepare("
                                UPDATE debts 
                                SET paid_amount = ?, remaining_amount = ?, status = ?
                                WHERE id = ?
                            ");
                            $updateDebt->bind_param("ddsi", $newPaidAmount, $newRemainingAmount, $newStatus, $debt['id']);
                            $updateDebt->execute();
                            
                            $paidDebts[] = [
                                'debt_id' => $debt['id'],
                                'payment_id' => $paymentId,
                                'amount' => $paymentForThisDebt
                            ];
                            
                            $remainingPayment = $normalizeAmount($remainingPayment - $paymentForThisDebt);
                        }

                        if (empty($paidDebts)) {
                            throw new Exception('No debts were paid in bulk payment');
                        }
                        
                        // نوێکردنەوەی گشتی قەرزی کڕیار
                        $updateCustomerDebt = $conn->prepare("
                            UPDATE customers 
                            SET total_debt = (
                                SELECT COALESCE(SUM(remaining_amount), 0) 
                                FROM debts 
                                WHERE customer_id = ? AND status = 'active'
                            )
                            WHERE id = ?
                        ");
                        $updateCustomerDebt->bind_param("ii", $customerId, $customerId);
                        $updateCustomerDebt->execute();
                        
                        // دروستکردنی وەسڵی کۆمەڵە
                        // بەکارهێنانی یەکەم payment ID بۆ وەسڵی کۆمەڵە
                        $firstPaymentId = !empty($paidDebts) ? $paidDebts[0]['payment_id'] : null;
                        $receiptNumber = 'RC-MULTI-' . date('YmdHis') . '-' . random_int(100, 999);
                        $insertReceipt = $conn->prepare("
                            INSERT INTO debt_receipts 
                            (user_id, receipt_number, customer_id, debt_payment_id, payment_amount, payment_method, notes)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $receiptNotes = $notes . ' (کۆمەڵە: ' . count($paidDebts) . ' وەسڵ - ' . $paymentCurrency . ')';
                        $insertReceipt->bind_param("isiidss", 
                            $userId, $receiptNumber, $customerId, 
                            $firstPaymentId, $totalPayment, $paymentMethod, $receiptNotes
                        );
                        $insertReceipt->execute();
                        $receiptId = $conn->insert_id;
                        
                        $conn->commit();
                        
                        $success = 'پارە بە سەرکەوتووی وەرگیرا بۆ ' . count($paidDebts) . ' وەسڵ و وەسڵی کۆمەڵە دروستکرا';
                        writeLog("Multiple debt payment: $totalPayment for customer ID: $customerId (" . count($paidDebts) . " debts) by user: {$currentUser['email']}");
                        
                        $_SESSION['new_receipt_id'] = $receiptId;
                        
                        // حیسابکردنی کۆی قەرزی ماوە دوای پارەدان
                        $totalRemainingAfterPayment = 0.0;
                        foreach ($selectedDebtsData as $d) {
                            $paidForThisDebt = 0.0;
                            foreach ($paidDebts as $pd) {
                                if ($pd['debt_id'] == $d['id']) {
                                    $paidForThisDebt = $normalizeAmount($pd['amount'] ?? 0);
                                    break;
                                }
                            }
                            $debtRemainingAfter = $normalizeAmount(($d['remaining_amount'] ?? 0) - $paidForThisDebt);
                            if ($debtRemainingAfter <= $epsilon) {
                                $debtRemainingAfter = 0.0;
                            }
                            $totalRemainingAfterPayment += $debtRemainingAfter;
                        }
                        $totalRemainingAfterPayment = $normalizeAmount($totalRemainingAfterPayment);
                        
                        // ناردنی نامە بۆ واتس ئەپپ
                        if (!empty($selectedDebtsData[0]['customer_phone'])) {
                            // کۆی قەرزی ماوەی وەسڵەکانی پێشووتر بەجیا (دینار و دۆلار)
                            $paidDebtIds = array_column($selectedDebtsData, 'id');
                            $placeholders = implode(',', array_fill(0, count($paidDebtIds), '?'));
                            $otherStmt = $conn->prepare("
                                SELECT
                                    COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as other_iqd,
                                    COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as other_usd
                                FROM debts d
                                LEFT JOIN sales s ON d.sale_id = s.id
                                WHERE d.customer_id = ? AND d.status = 'active' AND d.id NOT IN ($placeholders)
                            ");
                            $bindParams = array_merge([$customerId], $paidDebtIds);
                            $types = str_repeat('i', count($bindParams));
                            $otherStmt->bind_param($types, ...$bindParams);
                            $otherStmt->execute();
                            $otherRow = $otherStmt->get_result()->fetch_assoc();
                            $otherRemainingIqd = (float)($otherRow['other_iqd'] ?? 0);
                            $otherRemainingUsd = (float)($otherRow['other_usd'] ?? 0);

                            $_SESSION['whatsapp_payment_data'] = [
                                'customer_name' => $selectedDebtsData[0]['customer_name'],
                                'customer_phone' => $selectedDebtsData[0]['customer_phone'],
                                'payment_amount' => $totalPayment,
                                'remaining_amount' => $totalRemainingAfterPayment,
                                'other_installments_remaining_iqd' => $otherRemainingIqd,
                                'other_installments_remaining_usd' => $otherRemainingUsd,
                                'receipt_number' => $receiptNumber,
                                'receipt_id' => $receiptId,
                                'business_name' => $currentUser['business_name'],
                                'currency' => $paymentCurrency,
                                'is_multiple' => true,
                                'debts_count' => count($paidDebts)
                            ];
                        }

                        $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                        $selectedDebtStatesAfter = [];
                        foreach ($selectedDebtsData as $selectedDebtRow) {
                            $selectedDebtId = (int)($selectedDebtRow['id'] ?? 0);
                            if ($selectedDebtId > 0) {
                                $selectedDebtStatesAfter[] = getDebtSnapshotForCustomerLogs($conn, $userId, $selectedDebtId);
                            }
                        }
                        logCustomerChangeEvent(
                            'customer_debt.payment',
                            'bulk_debt_payment',
                            $customerId,
                            [
                                'customer_snapshot' => $beforeCustomerSnapshot,
                                'selected_debts' => $selectedDebtsData,
                                'total_payment' => $totalPayment,
                                'currency' => $paymentCurrency
                            ],
                            [
                                'customer_snapshot' => $afterCustomerSnapshot,
                                'selected_debts' => $selectedDebtStatesAfter,
                                'paid_debts' => $paidDebts,
                                'remaining_selected_total' => $totalRemainingAfterPayment,
                                'receipt_id' => $receiptId
                            ],
                            [
                                'user_id' => $userId,
                                'current_user' => $currentUser,
                                'customer_id' => $customerId,
                                'currency' => $paymentCurrency,
                                'source_module' => 'user/customers/credit_sales.php',
                                'source_reference' => (string)$receiptId
                            ]
                        );
                        
                    } catch (Exception $e) {
                        $conn->rollback();
                        writeLog("Bulk debt payment failed for customer ID: $customerId by user: {$currentUser['email']} - " . $e->getMessage());
                        $errors[] = 'هەڵەیەک لە پرۆسەی پارەدانی کۆمەڵە ڕوویدا. تکایە دووبارە هەوڵبدەوە';
                    }
                }
            }
        }
    }
    // پارەدانی تاک
    elseif ($action === 'pay_debt') {
        $debtId = (int)($_POST['debt_id'] ?? 0);
        $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
        $paymentMethod = 'cash';
        $notes = cleanInput($_POST['notes'] ?? '');
        $paymentDateInput = trim((string)($_POST['payment_datetime'] ?? ''));
        $paymentDate = date('Y-m-d H:i:s');

        if ($paymentDateInput !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $paymentDateInput)) {
                $errors[] = 'کات و بەروار دروست نییە';
            } else {
                $normalizedDate = str_replace('T', ' ', $paymentDateInput);
                if (strlen($normalizedDate) === 16) {
                    $normalizedDate .= ':00';
                }
                $paymentDate = $normalizedDate;
            }
        }
        
        if ($paymentAmount <= 0) {
            $errors[] = 'بڕی پارە دەبێت زیاتر لە سفر بێت';
        }
        
        if (empty($errors)) {
            // وەرگرتنی زانیاری قەرز
            $debtStmt = $conn->prepare("
                SELECT d.*, c.name as customer_name, c.phone as customer_phone,
                COALESCE(s.currency, 'IQD') as currency
                FROM debts d
                LEFT JOIN customers c ON d.customer_id = c.id
                LEFT JOIN sales s ON d.sale_id = s.id
                WHERE d.id = ? AND d.user_id = ? AND d.status = 'active'
            ");
            $debtStmt->bind_param("ii", $debtId, $userId);
            $debtStmt->execute();
            $debt = $debtStmt->get_result()->fetch_assoc();
            $debtStmt->close();
            
            if (!$debt) {
                $errors[] = 'قەرزەکە نەدۆزرایەوە';
            } elseif ($paymentAmount > $debt['remaining_amount']) {
                $errors[] = 'بڕی پارە زیاترە لە قەرزی ماوە';
            } else {
                $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)($debt['customer_id'] ?? 0));
                $beforeDebtState = $debt;
                $conn->begin_transaction();
                
                try {
                    // زیادکردنی پارەدان
                    $insertPayment = $conn->prepare("
                        INSERT INTO debt_payments (debt_id, payment_amount, notes, payment_date) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $insertPayment->bind_param("idss", $debtId, $paymentAmount, $notes, $paymentDate);
                    $insertPayment->execute();
                    $paymentId = $conn->insert_id;
                    
                    // نوێکردنەوەی قەرز
                    $newPaidAmount = $debt['paid_amount'] + $paymentAmount;
                    $newRemainingAmount = $debt['total_debt'] - $newPaidAmount;
                    $newStatus = $newRemainingAmount <= 0 ? 'completed' : 'active';
                    
                    $updateDebt = $conn->prepare("
                        UPDATE debts 
                        SET paid_amount = ?, remaining_amount = ?, status = ?
                        WHERE id = ?
                    ");
                    $updateDebt->bind_param("ddsi", $newPaidAmount, $newRemainingAmount, $newStatus, $debtId);
                    $updateDebt->execute();
                    
                    // نوێکردنەوەی گشتی قەرزی کڕیار
                    if ($debt['customer_id']) {
                        $updateCustomerDebt = $conn->prepare("
                            UPDATE customers 
                            SET total_debt = (
                                SELECT COALESCE(SUM(remaining_amount), 0) 
                                FROM debts 
                                WHERE customer_id = ? AND status = 'active'
                            )
                            WHERE id = ?
                        ");
                        $updateCustomerDebt->bind_param("ii", $debt['customer_id'], $debt['customer_id']);
                        $updateCustomerDebt->execute();
                    }
                    
                    // دروستکردنی وەسڵ
                    $receiptNumber = 'RC-' . date('YmdHis') . '-' . $paymentId;
                    $insertReceipt = $conn->prepare("
                        INSERT INTO debt_receipts 
                        (user_id, receipt_number, customer_id, debt_payment_id, payment_amount, payment_method, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insertReceipt->bind_param("isiidss", 
                        $userId, $receiptNumber, $debt['customer_id'], 
                        $paymentId, $paymentAmount, $paymentMethod, $notes
                    );
                    $insertReceipt->execute();
                    $receiptId = $conn->insert_id;
                    
                    $conn->commit();
                    
                    $success = 'پارە بە سەرکەوتووی وەرگیرا و وەسڵ دروستکرا';
                    writeLog("Debt payment: $paymentAmount for debt ID: $debtId by user: {$currentUser['email']}");
                    
                    // ڕێنمایی بۆ وەسڵ
                    $_SESSION['new_receipt_id'] = $receiptId;
                    
                    // ناردنی نامە بۆ واتس ئەپپ
                    if (!empty($debt['customer_phone'])) {
                        // کۆی قەرزی ماوەی وەسڵەکانی پێشووتر بەجیا (دینار و دۆلار)
                        $otherStmt = $conn->prepare("
                            SELECT
                                COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as other_iqd,
                                COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as other_usd
                            FROM debts d
                            LEFT JOIN sales s ON d.sale_id = s.id
                            WHERE d.customer_id = ? AND d.status = 'active' AND d.id != ?
                        ");
                        $otherStmt->bind_param("ii", $debt['customer_id'], $debtId);
                        $otherStmt->execute();
                        $otherRow = $otherStmt->get_result()->fetch_assoc();
                        $otherRemainingIqd = (float)($otherRow['other_iqd'] ?? 0);
                        $otherRemainingUsd = (float)($otherRow['other_usd'] ?? 0);

                        $_SESSION['whatsapp_payment_data'] = [
                            'customer_name' => $debt['customer_name'],
                            'customer_phone' => $debt['customer_phone'],
                            'payment_amount' => $paymentAmount,
                            'remaining_amount' => $newRemainingAmount,
                            'other_installments_remaining_iqd' => $otherRemainingIqd,
                            'other_installments_remaining_usd' => $otherRemainingUsd,
                            'receipt_number' => $receiptNumber,
                            'receipt_id' => $receiptId,
                            'business_name' => $currentUser['business_name'],
                            'currency' => $debt['currency'] ?? 'IQD'
                        ];
                    }

                    $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)($debt['customer_id'] ?? 0));
                    $afterDebtState = getDebtSnapshotForCustomerLogs($conn, $userId, $debtId);
                    logCustomerChangeEvent(
                        'customer_debt.payment',
                        'debt_payment',
                        $debtId,
                        [
                            'customer_snapshot' => $beforeCustomerSnapshot,
                            'debt' => $beforeDebtState,
                            'payment_amount' => $paymentAmount
                        ],
                        [
                            'customer_snapshot' => $afterCustomerSnapshot,
                            'debt' => $afterDebtState,
                            'receipt_id' => $receiptId
                        ],
                        [
                            'user_id' => $userId,
                            'current_user' => $currentUser,
                            'customer_id' => (int)($debt['customer_id'] ?? 0),
                            'debt_id' => $debtId,
                            'currency' => (string)($debt['currency'] ?? 'IQD'),
                            'source_module' => 'user/customers/credit_sales.php',
                            'source_reference' => (string)$receiptId
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

// فلتەرەکان
$customerId = (int)($_GET['customer_id'] ?? 0);
$status = $_GET['status'] ?? 'all';
$search = cleanInput($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 100;
$offset = ($page - 1) * $limit;

// دروستکردنی کلۆزی WHERE
$whereConditions = ["d.user_id = ?"];
$params = [$userId];
$types = 'i';

// نەنیشاندنی قەرزەکانی کڕیارانی سڕدراوە
$whereConditions[] = "d.customer_id IS NOT NULL";

if ($customerId > 0) {
    $whereConditions[] = "d.customer_id = ?";
    $params[] = $customerId;
    $types .= 'i';
}

if ($status !== 'all') {
    $whereConditions[] = "d.status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($search)) {
    $whereConditions[] = "(d.customer_name LIKE ? OR d.customer_phone LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm]);
    $types .= 'ss';
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// ژماردنی گشتی قەرزەکان
$countQuery = "SELECT COUNT(*) as total FROM debts d $whereClause";
$countStmt = $conn->prepare($countQuery);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalDebts = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalDebts / $limit);

$filterParams = $params;
$filterTypes = $types;
$paymentBreakdown = fetchDebtPaymentBreakdownTotals($conn, $userId, $whereClause, $filterParams, $filterTypes);

// وەرگرتنی قەرزەکان
$query = "
    SELECT d.*, 
           c.name as customer_name_updated,
           c.phone as customer_phone_updated,
           COALESCE(s.currency, 'IQD') as currency,
           DATE_FORMAT(d.created_at, '%Y/%m/%d') as formatted_date,
           DATE_FORMAT(d.next_payment_date, '%Y/%m/%d') as formatted_next_payment
    FROM debts d
    LEFT JOIN customers c ON d.customer_id = c.id
    LEFT JOIN sales s ON d.sale_id = s.id
    $whereClause
    ORDER BY d.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$debts = enrichDebtRowsWithPaymentBreakdown($conn, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));

// ئامارەکان
$statsQuery = "
    SELECT 
        COUNT(*) as total_debts,
        COUNT(CASE WHEN d.status = 'active' THEN 1 END) as active_debts,
        COUNT(CASE WHEN d.status = 'completed' THEN 1 END) as completed_debts,
        COUNT(CASE WHEN d.status = 'defaulted' THEN 1 END) as defaulted_debts,
        COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as total_remaining_iqd,
        COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as total_remaining_usd,
        COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN d.total_debt ELSE 0 END), 0) as grand_total_iqd,
        COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.total_debt ELSE 0 END), 0) as grand_total_usd
    FROM debts d
    LEFT JOIN sales s ON d.sale_id = s.id
    WHERE d.user_id = ?
";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->bind_param("i", $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$stats['total_received_iqd'] = $paymentBreakdown['received_iqd'];
$stats['total_received_usd'] = $paymentBreakdown['received_usd'];
$stats['total_return_iqd'] = $paymentBreakdown['return_iqd'];
$stats['total_return_usd'] = $paymentBreakdown['return_usd'];

// وەرگرتنی کڕیاران بۆ فلتەر
$customersStmt = $conn->prepare("
    SELECT
        c.id,
        c.name,
        c.phone,
        COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) AS total_debt_iqd,
        COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) AS total_debt_usd
    FROM customers c
    LEFT JOIN debts d ON d.customer_id = c.id AND d.user_id = c.user_id
    LEFT JOIN sales s ON d.sale_id = s.id
    WHERE c.user_id = ? AND c.status = 'active'
    GROUP BY c.id, c.name, c.phone
    ORDER BY name ASC
");
$customersStmt->bind_param("i", $userId);
$customersStmt->execute();
$customers = $customersStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'قەرزەکانی کڕیاران';
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

    html[data-bs-theme='dark'] .card.bg-light {
        background-color: #1f2937 !important;
        border-color: #374151 !important;
    }

    html[data-bs-theme='dark'] .card,
    html[data-bs-theme='dark'] .modal-content,
    html[data-bs-theme='dark'] .dropdown-menu {
        background-color: #111827 !important;
        border-color: #374151 !important;
        color: #e5e7eb !important;
    }

    /* Reduce spacing between checkbox and label in print modal */
    #printA4Modal .form-check {
        display: flex !important;
        align-items: center !important;
        gap: 0.5em !important; /* Small gap between checkbox and label */
        padding-right: 0 !important; /* Remove any default padding that might push it */
    }
    #printA4Modal .form-check-input {
        margin-right: 0 !important;
        margin-left: 0 !important;
        margin-top: 0 !important; /* Remove any default vertical margin */
        flex-shrink: 0; /* Prevent checkbox from shrinking */
    }
    #printA4Modal .form-check-label {
        padding-right: 0 !important;
        margin-right: 0 !important;
    }

    .customer-combobox {
        position: relative;
    }

    .customer-filter-card {
        position: relative;
        z-index: 30;
        overflow: visible;
    }

    .customer-filter-card .card-body {
        overflow: visible;
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
            <div class="cu-kicker"><i class="bi bi-credit-card-2-back"></i> قەرز</div>
            <h1><i class="bi bi-receipt-cutoff"></i> قەرزەکانی کڕیاران</h1>
            <p class="cu-hero-sub">بەڕێوەبردنی قەرزەکانی کڕیاران، پارەدانەوە و گەڕاوە</p>
        </div>
        <div class="cu-actions">
            <a href="<?php echo url('user/website/orders.php'); ?>" class="cu-btn cu-btn-ghost">
                <i class="bi bi-bag"></i> داواکارییەکان
            </a>
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

    <div class="cu-stats cu-stats-5">
        <div class="cu-stat" style="--stat-accent:#4f46e5">
            <div class="cu-stat-icon"><i class="bi bi-credit-card-2-back"></i></div>
            <div>
                <div class="cu-stat-label">کۆی قەرزەکان</div>
                <div class="cu-stat-value"><?php echo number_format($stats['total_debts']); ?></div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#10b981">
            <div class="cu-stat-icon"><i class="bi bi-clock-history"></i></div>
            <div>
                <div class="cu-stat-label">قەرزە چالاکەکان</div>
                <div class="cu-stat-value"><?php echo number_format($stats['active_debts']); ?></div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#0ea5e9">
            <div class="cu-stat-icon"><i class="bi bi-currency-dollar"></i></div>
            <div>
                <div class="cu-stat-label">کۆی قەرزی ماوە</div>
                <div class="cu-stat-value">
                    <?php 
                    $remainingIQD = $stats['total_remaining_iqd'] ?? 0;
                    $remainingUSD = $stats['total_remaining_usd'] ?? 0;
                    if ($remainingUSD > 0 && $remainingIQD > 0) {
                        echo formatMoney($remainingIQD, 'IQD') . ' + ' . formatMoney($remainingUSD, 'USD');
                    } elseif ($remainingUSD > 0) {
                        echo formatMoney($remainingUSD, 'USD');
                    } else {
                        echo formatMoney($remainingIQD, 'IQD');
                    }
                    ?>
                </div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#f59e0b">
            <div class="cu-stat-icon"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="cu-stat-label">کۆی پارە وەرگیراو</div>
                <div class="cu-stat-value">
                    <?php 
                    $paidIQD = $stats['total_received_iqd'] ?? 0;
                    $paidUSD = $stats['total_received_usd'] ?? 0;
                    if ($paidUSD > 0 && $paidIQD > 0) {
                        echo formatMoney($paidIQD, 'IQD') . ' + ' . formatMoney($paidUSD, 'USD');
                    } elseif ($paidUSD > 0) {
                        echo formatMoney($paidUSD, 'USD');
                    } else {
                        echo formatMoney($paidIQD, 'IQD');
                    }
                    ?>
                </div>
            </div>
        </div>
        <div class="cu-stat" style="--stat-accent:#ef4444">
            <div class="cu-stat-icon"><i class="bi bi-arrow-return-left"></i></div>
            <div>
                <div class="cu-stat-label">کۆی پارەی گەڕاوە</div>
                <div class="cu-stat-value">
                    <?php
                    $returnIQD = $stats['total_return_iqd'] ?? 0;
                    $returnUSD = $stats['total_return_usd'] ?? 0;
                    if ($returnUSD > 0 && $returnIQD > 0) {
                        echo formatMoney($returnIQD, 'IQD') . ' + ' . formatMoney($returnUSD, 'USD');
                    } elseif ($returnUSD > 0) {
                        echo formatMoney($returnUSD, 'USD');
                    } else {
                        echo formatMoney($returnIQD, 'IQD');
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="cu-panel mb-4 customer-filter-card">
        <div class="cu-panel-body">
            <form method="GET" class="row g-3">
                <div class="col-md-7">
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
                            <?php
                                $customerDebtIqd = (float)($customer['total_debt_iqd'] ?? 0);
                                $customerDebtUsd = (float)($customer['total_debt_usd'] ?? 0);
                                $debtParts = [];
                                if ($customerDebtIqd > 0) {
                                    $debtParts[] = number_format($customerDebtIqd, 0) . ' دینار';
                                }
                                if ($customerDebtUsd > 0) {
                                    $debtParts[] = number_format($customerDebtUsd, 2) . ' $';
                                }
                            ?>
                            <option value="<?php echo $customer['id']; ?>"
                                    data-name="<?php echo htmlspecialchars((string)($customer['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-phone="<?php echo htmlspecialchars((string)($customer['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-debt-iqd="<?php echo htmlspecialchars((string)$customerDebtIqd, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-debt-usd="<?php echo htmlspecialchars((string)$customerDebtUsd, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $customerId == $customer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?>
                                <?php if (!empty($customer['phone'])): ?>
                                    - <?php echo htmlspecialchars($customer['phone']); ?>
                                <?php endif; ?>
                                <?php if (!empty($debtParts)): ?>
                                    (قەرز: <?php echo htmlspecialchars(implode(' + ', $debtParts), ENT_QUOTES, 'UTF-8'); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">حاڵەت:</label>
                    <select name="status" class="form-select">
                        <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>هەموو</option>
                        <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>چالاک</option>
                        <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>تەواو</option>
                        <option value="defaulted" <?php echo $status == 'defaulted' ? 'selected' : ''; ?>>تاخیر</option>
                    </select>
                </div>
                <div class="col-md-3">
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

    <!-- Debts Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                لیستی قەرزەکان
                <span class="badge bg-primary ms-2"><?php echo $totalDebts; ?></span>
            </h6>
            <?php if ($customerId > 0): 
                // وەرگرتنی زانیاری کڕیار
                $customerStmt = $conn->prepare("SELECT name FROM customers WHERE id = ? AND user_id = ?");
                $customerStmt->bind_param("ii", $customerId, $userId);
                $customerStmt->execute();
                $customerData = $customerStmt->get_result()->fetch_assoc();
                $customerStmt->close();
                
                if ($customerData):
            ?>
                <button class="btn btn-info btn-sm" 
                        onclick="showMultiplePaymentModal(<?php echo $customerId; ?>, '<?php echo htmlspecialchars($customerData['name']); ?>')">
                    <i class="bi bi-credit-card-2-front"></i> پارەدانی کۆمەڵە بۆ <?php echo htmlspecialchars($customerData['name']); ?>
                </button>
            <?php endif; endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($debts)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h5 class="text-muted mt-3">هیچ قەرزێک نەدۆزرایەوە</h5>
                    <p class="text-muted">لەگەڵ فلتەرەکانی هەڵبژاردوو هیچ قەرزێک نەدۆزرایەوە</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover customers-debts-table">
                        <thead class="table-light">
                            <tr>
                                <th>کڕیار</th>
                                <th>تەلەفۆن</th>
                                <th>بڕی قەرز</th>
                                <th>پارە وەرگیراو</th>
                                <th>پارەی گەڕاوە</th>
                                <th>قەرزی ماوە</th>
                                <th>جۆری قەرز</th>
                                <th>بەرواری دروستکردن</th>
                                <th>حاڵەت</th>
                                <th>کردارەکان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($debts as $debt): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($debt['customer_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($debt['customer_phone'] ?: 'بێ تەلەفۆن'); ?>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-primary">
                                            <?php echo formatMoney($debt['total_debt'], $debt['currency'] ?? 'IQD'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-success">
                                            <?php echo formatMoney($debt['received_payments'] ?? 0, $debt['currency'] ?? 'IQD'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-danger">
                                            <?php echo formatMoney($debt['return_payments'] ?? 0, $debt['currency'] ?? 'IQD'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-danger">
                                            <?php echo formatMoney($debt['remaining_amount'], $debt['currency'] ?? 'IQD'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $debt['debt_type'] == 'installment' ? 'info' : 'warning'; ?>">
                                            <?php echo $debt['debt_type'] == 'installment' ? 'قیست' : 'قەرز'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $debt['formatted_date']; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $debt['status'] == 'active' ? 'warning' : 
                                                ($debt['status'] == 'completed' ? 'success' : 'danger'); 
                                        ?>">
                                            <?php 
                                            echo $debt['status'] == 'active' ? 'چالاک' : 
                                                ($debt['status'] == 'completed' ? 'تەواو' : 'تاخیر'); 
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($debt['status'] == 'active' && $debt['remaining_amount'] > 0): ?>
                                                <button class="btn btn-sm btn-success" 
                                                        onclick="showPaymentModal(<?php echo $debt['id']; ?>, '<?php echo htmlspecialchars($debt['customer_name']); ?>', <?php echo $debt['remaining_amount']; ?>, '<?php echo $debt['currency'] ?? 'IQD'; ?>')">
                                                    <i class="bi bi-credit-card"></i> پارەدان
                                                </button>
                                            <?php endif; ?>
                                            <a href="<?php echo url('user/pos/receipt.php?id=' . $debt['sale_id']); ?>" 
                                               class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-receipt"></i> وەسڵ
                                            </a>
                                            <?php if (!empty($debt['sale_id'])): ?>
                                            <a href="<?php echo url('user/pos/sales.php?edit_sale_id=' . $debt['sale_id']); ?>"
                                               class="btn btn-sm btn-outline-warning"
                                               title="دەستکاریکردنی وەسڵ">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-info" 
                                                    title="پرێنتی A4" 
                                                    onclick="showPrintA4Modal(<?php echo $debt['sale_id']; ?>)">
                                                <i class="bi bi-file-earmark-text"></i>
                                            </button>
                                            <?php endif; ?>
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

<!-- Multiple Payment Modal -->
<div class="modal fade" id="multiplePaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-credit-card-2-front"></i> پارەدانی کۆمەڵە</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="multiplePaymentForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="pay_multiple_debts">
                    <input type="hidden" name="customer_id" id="multiplePaymentCustomerId">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>کڕیار:</strong> <span id="multiplePaymentCustomerName"></span>
                    </div>
                    <div id="multiplePaymentValidationMessage" class="alert alert-warning d-none py-2"></div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">هەڵبژاردنی وەسڵەکان:</label>
                        <div id="debtsListContainer" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                            <div class="text-center py-3">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">چاوەڕوان بە...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title">کۆی قەرزی هەڵبژێردراو:</h6>
                                    <h4 class="text-danger mb-0" id="selectedTotalDebt">0</h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title">ژمارەی وەسڵ:</h6>
                                    <h4 class="text-primary mb-0" id="selectedDebtsCount">0</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">بڕی پارە <span class="text-danger">*</span></label>
                        <input type="number" name="total_payment" id="totalPaymentAmount" class="form-control form-control-lg" 
                               step="0.01" min="0.01" required>
                        <small class="text-muted">دەتوانیت بڕێکی کەمتر لە کۆی قەرز بنووسیت، پارەکە بەپێی ڕیزبەندی بەسەر وەسڵەکاندا دابەش دەکرێت (هەموو وەسڵەکان دەبێت هەمان دراو بن)</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">بەروار و کاتی پارەدان <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="payment_datetime" id="multiplePaymentDateTime"
                               class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">شێوەی پارەدان:</label>
                        <input type="hidden" name="payment_method" value="cash">
                        <input type="text" class="form-control" value="نەقد" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">تێبینی:</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> داخستن
                    </button>
                    <button type="submit" class="btn btn-info" id="submitMultiplePayment" disabled>
                        <i class="bi bi-check-circle"></i> پارەدان
                    </button>
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
                    دەتەوێت نامەی پارەدانەوە بنێریت بۆ کڕیار لە ڕێگەی واتس ئەپپ یان تیلیگرامەوە؟
                </p>
                
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-primary btn-lg" id="printReceiptBtn">
                        <i class="bi bi-printer"></i> چاپکردنی وەسڵ
                    </button>
                    <button type="button" class="btn btn-success btn-lg" id="whatsappSendBtn">
                        <i class="bi bi-whatsapp"></i> ناردن بۆ واتس ئەپپ
                    </button>
                    <button type="button" class="btn btn-primary btn-lg" id="telegramSendBtn">
                        <i class="bi bi-telegram"></i> ناردن بۆ تیلیگرام
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> نەخێر، سوپاس
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">پارەدانی قەرز</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="paymentForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="pay_debt">
                    <input type="hidden" name="debt_id" id="paymentDebtId">
                    
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
                        <label class="form-label">بەروار و کاتی پارەدان <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="payment_datetime" id="paymentDateTime"
                               class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">شێوەی پارەدان:</label>
                        <input type="hidden" name="payment_method" value="cash">
                        <input type="text" class="form-control" value="نەقد" readonly>
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

<script>
const debtReceiptPrintBaseUrl = <?php echo json_encode(url('user/receipts/print.php')); ?>;

// چاپکردنی خۆکاری وەسڵ دوای پارەدانەوە
<?php if (isset($_SESSION['new_receipt_id'])): ?>
    const newReceiptId = <?php echo (int)$_SESSION['new_receipt_id']; ?>;
    <?php unset($_SESSION['new_receipt_id']); ?>
    document.addEventListener('DOMContentLoaded', function () {
        window.open(debtReceiptPrintBaseUrl + '?id=' + newReceiptId + '&print=1&from=credit_sales', '_blank');
    });
<?php endif; ?>

// تاقیکردنەوەی هەبوونی زانیاری واتس ئەپپ و نیشاندانی مۆدال
<?php if (isset($_SESSION['whatsapp_payment_data'])): ?>
    const whatsappData = <?php echo json_encode($_SESSION['whatsapp_payment_data']); ?>;
    <?php unset($_SESSION['whatsapp_payment_data']); ?>
    
    // نیشاندانی مۆدالی واتس ئەپپ دوای باری پەڕە
    document.addEventListener('DOMContentLoaded', function() {
        showWhatsAppModal(whatsappData);
    });
<?php endif; ?>

function showPaymentModal(debtId, customerName, remainingAmount, currency) {
    document.getElementById('paymentDebtId').value = debtId;
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
    document.getElementById('paymentDateTime').value = getCurrentDateTimeLocal();
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

function getCurrentDateTimeLocal() {
    const now = new Date();
    const offset = now.getTimezoneOffset();
    const localDate = new Date(now.getTime() - (offset * 60000));
    return localDate.toISOString().slice(0, 16);
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
    document.getElementById('telegramSendBtn').onclick = function() {
        sendPaymentToTelegram(data);
    };
    document.getElementById('printReceiptBtn').onclick = function() {
        openDebtPaymentReceipt(data);
    };
    
    modal.show();
}

function openDebtPaymentReceipt(data) {
    if (!data || !data.receipt_id) {
        return;
    }
    window.open(
        debtReceiptPrintBaseUrl + '?id=' + encodeURIComponent(data.receipt_id) + '&print=1&from=credit_sales',
        '_blank'
    );
}

function buildPaymentMessage(data, platform) {
    const isTelegram = (platform === 'telegram');
    const b = isTelegram ? '**' : '*';  // تیلیگرام ** بۆڵد، واتسئەپ *
    const currency = data.currency || 'IQD';
    const currencySymbol = (currency === 'USD') ? '$' : ' دینار';
    const decimals = (currency === 'USD') ? 2 : 0;
    const paymentFormatted = parseFloat(data.payment_amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    const remainingFormatted = parseFloat(data.remaining_amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    const otherIqd = parseFloat(data.other_installments_remaining_iqd ?? 0);
    const otherUsd = parseFloat(data.other_installments_remaining_usd ?? 0);
    const otherIqdFormatted = otherIqd.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    const otherUsdFormatted = otherUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const otherLines = [];
    if (otherIqd > 0) otherLines.push(`${b}قەرزی دینار:${b} ${otherIqdFormatted} دینار`);
    if (otherUsd > 0) otherLines.push(`${b}قەرزی دۆلار:${b} ${otherUsdFormatted} $`);
    const otherText = otherLines.length ? otherLines.join('\n') : '—';
    let message = `${b}وەسڵی پارەدانەوە${b}\n\n`;
    message += `${b}${data.business_name}${b}\n`;
    message += `━━━━━━━━━━━━━━━━━━━━\n\n`;
    message += `ژمارەی وەسڵ: ${data.receipt_number}\n`;
    message += `بەروار: ${new Date().toLocaleDateString('ku-IQ')}\n`;
    message += `کات: ${new Date().toLocaleTimeString('ku-IQ', {hour: '2-digit', minute: '2-digit'})}\n`;
    message += `کڕیار: ${data.customer_name}\n\n`;
    if (data.is_multiple) {
        message += `پارەدانەوە بۆ ${data.debts_count} وەسڵ\n\n`;
    }
    message += `━━━━━━━━━━━━━━━━━━━━\n`;
    message += `💰 ${b}پارەی داوتەوە: ${paymentFormatted}${currencySymbol}${b}\n`;
    message += `📊 ${b}قەرزی ماوەی ئەم وەسڵە: ${remainingFormatted}${currencySymbol}${b}\n`;
    message += `کۆی قەرزی ماوەی وەسڵەکانی پێشووتر:\n${otherText}\n`;
    message += `━━━━━━━━━━━━━━━━━━━━\n\n`;
    message += `سوپاس بۆ پارەدانەوەکەت\n`;
    message += `سیستەمی NexoraCore\nNexoraCore.com`;
    return message;
}

function sendPaymentToWhatsApp(data) {
    const message = buildPaymentMessage(data);
    let phoneNumber = data.customer_phone.replace(/\D/g, '');
    if (phoneNumber.startsWith('0')) {
        phoneNumber = '964' + phoneNumber.substring(1);
    } else if (!phoneNumber.startsWith('964')) {
        phoneNumber = '964' + phoneNumber;
    }
    const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
    window.open(whatsappUrl, '_blank');
    bootstrap.Modal.getInstance(document.getElementById('whatsappModal')).hide();
}

function sendPaymentToTelegram(data) {
    const message = buildPaymentMessage(data, 'telegram');
    let phoneNumber = data.customer_phone.replace(/\D/g, '');
    if (phoneNumber.startsWith('0')) {
        phoneNumber = '964' + phoneNumber.substring(1);
    } else if (!phoneNumber.startsWith('964')) {
        phoneNumber = '964' + phoneNumber;
    }
    const telegramUrl = `https://t.me/+${phoneNumber}?text=${encodeURIComponent(message)}`;
    window.open(telegramUrl, '_blank');
    bootstrap.Modal.getInstance(document.getElementById('whatsappModal')).hide();
}

function showMultiplePaymentModal(customerId, customerName) {
    document.getElementById('multiplePaymentCustomerId').value = customerId;
    document.getElementById('multiplePaymentCustomerName').textContent = customerName;
    
    // ڕیسێت کردنی فۆرم
    const form = document.getElementById('multiplePaymentForm');
    if (form) {
        form.reset();
    }
    const paymentDateInput = document.getElementById('multiplePaymentDateTime');
    if (paymentDateInput) {
        paymentDateInput.value = getCurrentDateTimeLocal();
    }
    document.getElementById('totalPaymentAmount').value = '';
    document.getElementById('totalPaymentAmount').min = '0.01';
    document.getElementById('totalPaymentAmount').step = '0.01';
    document.getElementById('selectedTotalDebt').textContent = '0';
    document.getElementById('selectedDebtsCount').textContent = '0';
    const submitBtn = document.getElementById('submitMultiplePayment');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> پارەدان';
    setMultiplePaymentValidationMessage('');
    
    // وەرگرتنی لیستی قەرزەکان بەبێ کاشکردن
    const url = 'get_customer_debts.php?customer_id=' + customerId + '&_t=' + new Date().getTime();
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayDebts(data.debts);
            } else {
                document.getElementById('debtsListContainer').innerHTML = 
                    '<div class="alert alert-warning">هیچ قەرزێکی چالاک نەدۆزرایەوە</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('debtsListContainer').innerHTML = 
                '<div class="alert alert-danger">هەڵە لە وەرگرتنی زانیاری</div>';
        });
    
    new bootstrap.Modal(document.getElementById('multiplePaymentModal')).show();
}

function displayDebts(debts) {
    const container = document.getElementById('debtsListContainer');
    
    if (debts.length === 0) {
        container.innerHTML = '<div class="alert alert-warning">هیچ قەرزێکی چالاک نەدۆزرایەوە</div>';
        return;
    }
    
    let html = '<div class="form-check mb-2">';
    html += '<input class="form-check-input" type="checkbox" id="selectAllDebts" onchange="toggleAllDebts(this)">';
    html += '<label class="form-check-label fw-bold" for="selectAllDebts">هەڵبژاردنی هەموو</label>';
    html += '</div><hr>';
    
    debts.forEach(debt => {
        const currency = normalizeDebtCurrency(debt.currency);
        html += `
            <div class="form-check mb-3 p-3 border rounded debt-item" 
                 data-remaining="${debt.remaining_amount}" 
                 data-currency="${currency}">
                <input class="form-check-input debt-checkbox" type="checkbox" 
                       name="selected_debts[]" value="${debt.id}" 
                       id="debt_${debt.id}" onchange="updateTotals()">
                <label class="form-check-label w-100" for="debt_${debt.id}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong>وەسڵ #${debt.id}</strong>
                            <div class="text-muted small">بەروار: ${debt.formatted_date}</div>
                            <div class="text-muted small">جۆر: ${debt.debt_type == 'installment' ? 'قیست' : 'قەرز'}</div>
                        </div>
                        <div class="text-end">
                            <div class="text-primary">کۆ: ${formatDebtAmount(debt.total_debt, currency)}</div>
                            <div class="text-success">وەرگیراو: ${formatDebtAmount(debt.received_payments ?? 0, currency)}</div>
                            <div class="text-danger">گەڕاوە: ${formatDebtAmount(debt.return_payments ?? 0, currency)}</div>
                            <div class="text-danger fw-bold">ماوە: ${formatDebtAmount(debt.remaining_amount, currency)}</div>
                        </div>
                    </div>
                </label>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function formatDebtAmount(amount, currency) {
    const normalizedCurrency = normalizeDebtCurrency(currency);
    const currencySymbol = (normalizedCurrency === 'USD') ? '$' : ' دینار';
    const decimals = (normalizedCurrency === 'USD') ? 2 : 0;
    return parseFloat(amount).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    }) + currencySymbol;
}

function normalizeDebtCurrency(currency) {
    return String(currency || 'IQD').toUpperCase() === 'USD' ? 'USD' : 'IQD';
}

function setMultiplePaymentValidationMessage(message, type = 'warning') {
    const validationBox = document.getElementById('multiplePaymentValidationMessage');
    if (!validationBox) {
        return;
    }

    validationBox.classList.remove('d-none', 'alert-warning', 'alert-danger', 'alert-info');
    if (!message) {
        validationBox.classList.add('d-none', 'alert-warning');
        validationBox.textContent = '';
        return;
    }

    validationBox.classList.add(type === 'danger' ? 'alert-danger' : (type === 'info' ? 'alert-info' : 'alert-warning'));
    validationBox.textContent = message;
}

function toggleAllDebts(checkbox) {
    const checkboxes = document.querySelectorAll('.debt-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateTotals();
}

function updateTotals() {
    const checkboxes = document.querySelectorAll('.debt-checkbox:checked');
    let total = 0;
    const count = checkboxes.length;
    const selectedCurrencies = new Set();
    
    checkboxes.forEach(cb => {
        const debtItem = cb.closest('.debt-item');
        const remaining = parseFloat(debtItem.dataset.remaining);
        const currency = normalizeDebtCurrency(debtItem.dataset.currency);
        total += remaining;
        selectedCurrencies.add(currency);
    });

    const hasMixedCurrencies = selectedCurrencies.size > 1;
    const selectedCurrency = selectedCurrencies.size === 1 ? [...selectedCurrencies][0] : null;
    const paymentInput = document.getElementById('totalPaymentAmount');
    const submitBtn = document.getElementById('submitMultiplePayment');

    if (hasMixedCurrencies) {
        document.getElementById('selectedTotalDebt').textContent = 'دراوەکان تێکەڵن (IQD/USD)';
        setMultiplePaymentValidationMessage('هەڵبژاردنەکەت تێکەڵە (دینار و دۆلار). تکایە تەنها یەک دراو هەڵبژێرە.', 'danger');
        paymentInput.max = '';
        paymentInput.min = '0.01';
        paymentInput.step = '0.01';
        paymentInput.setCustomValidity('هەڵبژاردنی تێکەڵی دراو ڕێگەپێنەدراوە');
    } else if (count > 0 && selectedCurrency) {
        const currencySymbol = (selectedCurrency === 'USD') ? '$' : ' دینار';
        const decimals = (selectedCurrency === 'USD') ? 2 : 0;
        const formattedTotal = total.toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
        document.getElementById('selectedTotalDebt').textContent = formattedTotal + currencySymbol;
        setMultiplePaymentValidationMessage(`دراوی هەڵبژێردراو: ${selectedCurrency}`, 'info');
        paymentInput.max = decimals === 0 ? Math.round(total).toString() : total.toFixed(2);
        paymentInput.min = decimals === 0 ? '1' : '0.01';
        paymentInput.step = decimals === 0 ? '1' : '0.01';
        paymentInput.setCustomValidity('');
    } else {
        document.getElementById('selectedTotalDebt').textContent = '0';
        setMultiplePaymentValidationMessage('');
        paymentInput.max = '';
        paymentInput.min = '0.01';
        paymentInput.step = '0.01';
        paymentInput.setCustomValidity('');
    }

    document.getElementById('selectedDebtsCount').textContent = count;
    submitBtn.disabled = count === 0 || hasMixedCurrencies;
    
    // نیشاندانی/شاردنەوەی دوگمەی هەڵبژاردنی هەموو
    const selectAllCheckbox = document.getElementById('selectAllDebts');
    if (selectAllCheckbox) {
        const allCheckboxes = document.querySelectorAll('.debt-checkbox');
        selectAllCheckbox.checked = checkboxes.length === allCheckboxes.length && allCheckboxes.length > 0;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const multiplePaymentForm = document.getElementById('multiplePaymentForm');
    const submitBtn = document.getElementById('submitMultiplePayment');
    if (!multiplePaymentForm || !submitBtn) {
        return;
    }

    multiplePaymentForm.addEventListener('submit', function() {
        if (!multiplePaymentForm.checkValidity()) {
            return;
        }
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>چاوەڕوان بە...';
    });
});

// Print A4 Modal Functions
function showPrintA4Modal(saleId) {
    // Reset all checkboxes to checked (default)
    const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = true);
    
    // Store sale ID
    document.getElementById('printA4Modal').setAttribute('data-sale-id', saleId);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('printA4Modal'));
    modal.show();
}

function selectAllFields() {
    const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = true);
}

function deselectAllFields() {
    const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = false);
}

function printA4Receipt() {
    const saleId = document.getElementById('printA4Modal').getAttribute('data-sale-id');
    const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]:checked');
    
    // If no fields selected, show all fields (default behavior)
    let fields = [];
    if (checkboxes.length > 0) {
        checkboxes.forEach(cb => {
            fields.push(cb.value);
        });
    }
    
    // Build URL
    let url = '<?php echo url("user/pos/receipt_a4.php"); ?>?id=' + saleId;
    if (fields.length > 0) {
        url += '&fields=' + fields.join(',');
    }
    
    // Open in new tab
    window.open(url, '_blank');
    
    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('printA4Modal'));
    modal.hide();
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
            debtIqd: parseFloat(option.dataset.debtIqd || '0') || 0,
            debtUsd: parseFloat(option.dataset.debtUsd || '0') || 0,
            label: option.textContent.trim()
        }));

    let activeIndex = -1;
    let visibleCustomers = [...customerOptions];
    let selectedCustomer = customerOptions.find(customer => customer.id === customerSelect.value) || null;
    let currentQuery = '';

    function normalize(text) {
        return (text || '').toLowerCase().trim();
    }

    function getCustomerMeta(customer) {
        const metaParts = [];
        if (customer.phone) metaParts.push(customer.phone);
        const debtParts = [];
        if (customer.debtIqd > 0) debtParts.push(`${customer.debtIqd.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} دینار`);
        if (customer.debtUsd > 0) debtParts.push(`${customer.debtUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} $`);
        if (debtParts.length > 0) metaParts.push(`قەرز: ${debtParts.join(' + ')}`);
        return metaParts.join(' - ');
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
                <span class="meta">${escapeHtml(getCustomerMeta(customer))}</span>
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
            selectedCustomer = null;
            customerSearchInput.value = fromUserInput ? '' : (allCustomerOption ? allCustomerOption.textContent.trim() : '');
            return;
        }

        selectedCustomer = customerOptions.find(customer => customer.id === customerId) || null;
        if (selectedCustomer) {
            customerSearchInput.value = selectedCustomer.name + (selectedCustomer.phone ? ` - ${selectedCustomer.phone}` : '');
        }
    }

    function filterCustomers() {
        const query = normalize(customerSearchInput.value);
        currentQuery = query;
        visibleCustomers = customerOptions.filter(customer => {
            const nameMatch = normalize(customer.name).includes(query);
            const phoneMatch = normalize(customer.phone).includes(query);
            return nameMatch || phoneMatch;
        });
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
        if (!customerDropdown.classList.contains('show')) {
            return;
        }

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
            } else {
                setSelectedCustomer('0', true);
            }
            closeDropdown();
        } else if (event.key === 'Escape') {
            closeDropdown();
        }
    });

    customerDropdown.addEventListener('mousedown', function(event) {
        const optionElement = event.target.closest('.customer-option');
        if (!optionElement) {
            return;
        }

        const optionId = optionElement.dataset.id || '0';
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

<!-- Print A4 Field Selection Modal -->
<div class="modal fade" id="printA4Modal" tabindex="-1" aria-labelledby="printA4ModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printA4ModalLabel">
                    <i class="bi bi-file-earmark-text"></i> هەڵبژاردنی فیلدەکان بۆ چاپی A4
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllFields()">
                        <i class="bi bi-check-all"></i> هەموو هەڵبژێرە
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllFields()">
                        <i class="bi bi-x-square"></i> هیچ هەڵمەبژێرە
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="bi bi-table"></i> فیلدەکانی خشتەی کاڵاکان
                        </h6>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="product_name" id="field_product_name" checked>
                            <label class="form-check-label" for="field_product_name">
                                ناوی کاڵا
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="product_image" id="field_product_image" checked>
                            <label class="form-check-label" for="field_product_image">
                                وێنەی کاڵا
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="barcode" id="field_barcode" checked>
                            <label class="form-check-label" for="field_barcode">
                                بارکۆد
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="quantity" id="field_quantity" checked>
                            <label class="form-check-label" for="field_quantity">
                                بڕ
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="unit" id="field_unit" checked>
                            <label class="form-check-label" for="field_unit">
                                یەکە
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="unit_price" id="field_unit_price" checked>
                            <label class="form-check-label" for="field_unit_price">
                                نرخی یەکە
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="total" id="field_total" checked>
                            <label class="form-check-label" for="field_total">
                                کۆی نرخ
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-success mb-3">
                            <i class="bi bi-info-circle"></i> فیلدەکانی دیکە
                        </h6>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="receipt_number" id="field_receipt_number" checked>
                            <label class="form-check-label" for="field_receipt_number">
                                ژمارەی وەسڵ
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="date" id="field_date" checked>
                            <label class="form-check-label" for="field_date">
                                بەروار
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="time" id="field_time" checked>
                            <label class="form-check-label" for="field_time">
                                کاتژمێر
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="customer_info" id="field_customer_info" checked>
                            <label class="form-check-label" for="field_customer_info">
                                زانیاری کڕیار
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="totals" id="field_totals" checked>
                            <label class="form-check-label" for="field_totals">
                                کۆی گشتی
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="unit_totals_summary" id="field_unit_totals_summary" checked>
                            <label class="form-check-label" for="field_unit_totals_summary">
                                کۆی بڕەکان بەپێی یەکە (لاپەڕە و کۆتایی)
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="payment_method" id="field_payment_method" checked>
                            <label class="form-check-label" for="field_payment_method">
                                شێوازی پارەدان
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="discount" id="field_discount" checked>
                            <label class="form-check-label" for="field_discount">
                                داشکاندن
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="tax" id="field_tax" checked>
                            <label class="form-check-label" for="field_tax">
                                باج
                            </label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" value="customer_note" id="field_customer_note" checked>
                            <label class="form-check-label" for="field_customer_note">
                                تێبینی کڕیار
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> داخستن
                </button>
                <button type="button" class="btn btn-primary" onclick="printA4Receipt()">
                    <i class="bi bi-printer"></i> چاپکردن
                </button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
