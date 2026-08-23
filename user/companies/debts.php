<?php
/**
 * بەشی قەرزی کۆمپانیاکان - user/companies/debts.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
SessionManager::requireAuth('user');
requireCompaniesModuleAccess();

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$companyId = (int)($_GET['company_id'] ?? 0);
$errors = [];
$bulkErrors = [];
$bulkFormData = [
    'total_amount' => '',
    'date' => date('Y-m-d'),
    'description' => '',
    'receipt_ids' => [],
    'currency' => 'IQD',
];

// وەرگرتنی زانیاری کۆمپانیا
$companyStmt = $conn->prepare("SELECT * FROM companies WHERE id = ? AND user_id = ?");
$companyStmt->bind_param("ii", $companyId, $userId);
$companyStmt->execute();
$company = $companyStmt->get_result()->fetch_assoc();

if (!$company) {
    setMessage('کۆمپانیا نەدۆزرایەوە', 'error');
    redirect(url('user/companies/index.php'));
}

// پرۆسێسی پارەدانەوەی بە کۆمەڵە بۆ وەسڵەکان (FIFO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bulk_payment'])) {
    $bulkFormData['total_amount'] = trim((string)($_POST['total_amount'] ?? ''));
    $bulkFormData['date'] = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $bulkFormData['description'] = trim((string)($_POST['description'] ?? ''));
    $bulkFormData['receipt_ids'] = isset($_POST['receipt_ids']) && is_array($_POST['receipt_ids']) ? $_POST['receipt_ids'] : [];
    $bulkFormData['currency'] = (($_POST['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';

    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $bulkErrors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $selectedReceiptIds = array_values(array_unique(array_map('intval', $bulkFormData['receipt_ids'])));
        $selectedReceiptIds = array_values(array_filter($selectedReceiptIds, static function ($id) {
            return $id > 0;
        }));

        $totalAmount = (float)$bulkFormData['total_amount'];
        $paymentDate = $bulkFormData['date'];
        $description = cleanInput($bulkFormData['description']);
        $bulkCurrency = $bulkFormData['currency'];
        $bulkDebtColumn = $bulkCurrency === 'USD' ? 'debt_amount_usd' : 'debt_amount';

        if (empty($selectedReceiptIds)) {
            $bulkErrors[] = 'تکایە لانیکەم یەک وەسڵ هەڵبژێرە';
        }
        if ($totalAmount <= 0) {
            $bulkErrors[] = 'بڕی گشتی دەبێت گەورەتر بێت لە سفر';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) || strtotime($paymentDate) === false) {
            $bulkErrors[] = 'بەروارەکە نادروستە';
        }

        if (empty($bulkErrors)) {
            $installmentsFifoQuery = "SELECT
                    pr.id,
                    pr.receipt_number,
                    pr.receipt_date,
                    pr.created_at,
                    pr.final_amount,
                    COALESCE(SUM(
                        CASE WHEN cd.type = 'payment' THEN cd.amount ELSE 0 END
                    ), 0) AS paid_amount
                FROM purchase_receipts pr
                LEFT JOIN company_debts cd
                    ON cd.purchase_receipt_id = pr.id
                    AND cd.user_id = pr.user_id
                    AND cd.company_id = pr.company_id
                WHERE pr.company_id = ?
                  AND pr.user_id = ?
                  AND pr.payment_type = 'debt'
                  AND pr.status = 'active'
                  AND pr.currency = ?
                GROUP BY pr.id, pr.receipt_number, pr.receipt_date, pr.created_at, pr.final_amount
                ORDER BY pr.receipt_date ASC, pr.created_at ASC, pr.id ASC";

            $installmentsFifoStmt = $conn->prepare($installmentsFifoQuery);
            $installmentsFifoStmt->bind_param("iis", $companyId, $userId, $bulkCurrency);
            $installmentsFifoStmt->execute();
            $fifoRows = $installmentsFifoStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $selectedMap = array_fill_keys($selectedReceiptIds, true);
            $selectedInstallments = [];
            $selectedRemainingTotal = 0.0;

            foreach ($fifoRows as $row) {
                $receiptId = (int)($row['id'] ?? 0);
                if (!isset($selectedMap[$receiptId])) {
                    continue;
                }

                $finalAmount = (float)($row['final_amount'] ?? 0);
                $paidAmount = (float)($row['paid_amount'] ?? 0);
                $remainingAmount = max(0, $finalAmount - $paidAmount);
                if ($remainingAmount <= 0) {
                    continue;
                }

                $row['remaining_amount'] = $remainingAmount;
                $selectedInstallments[] = $row;
                $selectedRemainingTotal += $remainingAmount;
            }

            if (empty($selectedInstallments)) {
                $bulkErrors[] = 'هیچ وەسڵێکی مانەوەدار لە هەڵبژاردنەکەتدا نییە';
            } elseif ($totalAmount > $selectedRemainingTotal) {
                $bulkErrors[] = 'بڕی پارەدانەوە نابێت گەورەتر بێت لە کۆی مانەوەی وەسڵە هەڵبژێردراوەکان';
            } else {
                $conn->begin_transaction();

                try {
                    $insertPaymentStmt = $conn->prepare("
                        INSERT INTO company_debts (user_id, company_id, purchase_receipt_id, amount, currency, description, type, date)
                        VALUES (?, ?, ?, ?, ?, ?, 'payment', ?)
                    ");
                    $completeReceiptStmt = $conn->prepare("
                        UPDATE purchase_receipts
                        SET status = 'completed', updated_at = NOW()
                        WHERE id = ? AND user_id = ? AND company_id = ? AND status = 'active'
                    ");
                    $updateCompanyDebtStmt = $conn->prepare("
                        UPDATE companies
                        SET {$bulkDebtColumn} = GREATEST(0, {$bulkDebtColumn} - ?), updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");

                    $paymentPool = $totalAmount;
                    $totalAllocated = 0.0;
                    $affectedReceipts = 0;

                    foreach ($selectedInstallments as $inst) {
                        if ($paymentPool <= 0) {
                            break;
                        }

                        $receiptId = (int)$inst['id'];
                        $remainingAmount = (float)$inst['remaining_amount'];
                        $allocated = min($remainingAmount, $paymentPool);
                        if ($allocated <= 0) {
                            continue;
                        }

                        $receiptLabel = !empty($inst['receipt_number']) ? $inst['receipt_number'] : $receiptId;
                        $paymentDescription = $description !== ''
                            ? $description
                            : "پارەدانەوەی بە کۆمەڵە بۆ وەسڵ #{$receiptLabel}";

                        $insertPaymentStmt->bind_param("iiidsss", $userId, $companyId, $receiptId, $allocated, $bulkCurrency, $paymentDescription, $paymentDate);
                        if (!$insertPaymentStmt->execute()) {
                            throw new Exception('هەڵە لە تۆمارکردنی پارەدانەوە');
                        }

                        if ($allocated >= $remainingAmount) {
                            $completeReceiptStmt->bind_param("iii", $receiptId, $userId, $companyId);
                            if (!$completeReceiptStmt->execute()) {
                                throw new Exception('هەڵە لە نوێکردنەوەی دۆخی وەسڵ');
                            }
                        }

                        $paymentPool -= $allocated;
                        $totalAllocated += $allocated;
                        $affectedReceipts++;
                    }

                    if ($totalAllocated <= 0) {
                        throw new Exception('هیچ بڕێک دابەش نەکرا');
                    }

                    $updateCompanyDebtStmt->bind_param("dii", $totalAllocated, $companyId, $userId);
                    if (!$updateCompanyDebtStmt->execute()) {
                        throw new Exception('هەڵە لە نوێکردنەوەی قەرزی کۆمپانیا');
                    }

                    $conn->commit();
                    setMessage(
                        "پارەدانەوەی بە کۆمەڵە بەسەرکەوتوویی تۆمار کرا ("
                        . formatCurrencyAmount($totalAllocated, $bulkCurrency)
                        . " بۆ {$affectedReceipts} وەسڵ)",
                        'success'
                    );
                    redirect(url('user/companies/debts.php?company_id=' . $companyId . '&tab=receipt'));
                } catch (Exception $e) {
                    $conn->rollback();
                    $bulkErrors[] = 'هەڵە لە تۆمارکردن: ' . $e->getMessage();
                }
            }
        }
    }
}

// پرۆسێسی زیادکردنی قەرز/دانەوەی نوێ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $description = cleanInput($_POST['description'] ?? '');
        $type = $_POST['type'] ?? 'debt';
        $date = $_POST['date'] ?? date('Y-m-d');
        $currency = (($_POST['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
        $debtColumn = $currency === 'USD' ? 'debt_amount_usd' : 'debt_amount';

        // پشتڕاستکردنەوە
        if ($amount <= 0) {
            $errors[] = 'بڕەکە دەبێت گەورەتر لە سفر بێت';
        }

        if (empty($description)) {
            $errors[] = 'وردەکاری پێویستە';
        }

        if (!in_array($type, ['debt', 'payment'])) {
            $errors[] = 'جۆری مامەڵە نادروستە';
        }

        if (empty($errors)) {
            $conn->begin_transaction();

            try {
                // زیادکردنی مامەڵە (لەگەڵ دراو)
                $stmt = $conn->prepare("INSERT INTO company_debts (user_id, company_id, amount, currency, description, type, date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iidssss", $userId, $companyId, $amount, $currency, $description, $type, $date);

                if ($stmt->execute()) {
                    // نوێکردنەوەی بڕی قەرزی کۆمپانیا (ئەتۆمی) — بۆ باڵانسی هەمان دراو
                    if ($type === 'debt') {
                        $updateStmt = $conn->prepare("UPDATE companies SET {$debtColumn} = {$debtColumn} + ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                        $updateStmt->bind_param("dii", $amount, $companyId, $userId);
                    } else {
                        $updateStmt = $conn->prepare("UPDATE companies SET {$debtColumn} = GREATEST(0, {$debtColumn} - ?), updated_at = NOW() WHERE id = ? AND user_id = ?");
                        $updateStmt->bind_param("dii", $amount, $companyId, $userId);
                    }
                    $updateStmt->execute();
                    
                    $conn->commit();
                    setMessage($type === 'debt' ? 'قەرز زیاد کرا' : 'پارە وەرگیرا', 'success');
                    redirect(url('user/companies/debts.php?company_id=' . $companyId));
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

// وەرگرتنی مێژووی مامەڵەکان
$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$offset = ($page - 1) * $perPage;

$transactionsQuery = "SELECT * FROM company_debts 
                      WHERE company_id = ? AND user_id = ? 
                      ORDER BY date DESC, created_at DESC 
                      LIMIT ? OFFSET ?";

$transStmt = $conn->prepare($transactionsQuery);
$transStmt->bind_param("iiii", $companyId, $userId, $perPage, $offset);
$transStmt->execute();
$transactions = $transStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ژمارەی گشتی مامەڵەکان
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM company_debts WHERE company_id = ? AND user_id = ?");
$countStmt->bind_param("ii", $companyId, $userId);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];

// ئامارەکان
$statsQuery = "SELECT 
    SUM(CASE WHEN type = 'debt' THEN amount ELSE 0 END) as total_debt,
    SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END) as total_payments,
    COUNT(CASE WHEN type = 'debt' THEN 1 END) as debt_transactions,
    COUNT(CASE WHEN type = 'payment' THEN 1 END) as payment_transactions,
    MIN(date) as first_transaction,
    MAX(date) as last_transaction
    FROM company_debts 
    WHERE company_id = ? AND user_id = ?";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->bind_param("ii", $companyId, $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// دابەشکردنی مامەڵەکان بۆ بەبێ وەسڵ و پەیوەست بە وەسڵ
// تۆماری قەرزی وەسڵ کە پێشتر بە purchase_receipt_idی بەتاڵ تۆمار بووە لەگەڵ وەسڵەکە دووبارە نیشان ندرێت
$isPurchaseReceiptDebtMirror = static function (array $t): bool {
    if (($t['type'] ?? '') !== 'debt') {
        return false;
    }
    $d = (string)($t['description'] ?? '');
    return strncmp($d, 'وەسڵی کڕین #', strlen('وەسڵی کڕین #')) === 0;
};

$transactionsWithoutReceipt = array_filter($transactions, function ($t) use ($isPurchaseReceiptDebtMirror) {
    if (!empty($t['purchase_receipt_id'])) {
        return false;
    }
    return !$isPurchaseReceiptDebtMirror($t);
});

$transactionsWithReceipt = array_filter($transactions, function ($t) use ($isPurchaseReceiptDebtMirror) {
    return !empty($t['purchase_receipt_id']) || $isPurchaseReceiptDebtMirror($t);
});

// پشتگیری دوو دراو: هەموو کۆکراوە پارەییەکان بە شێوەی array[دراو] هەڵدەگیرێن
$debtCurrencies = ['IQD', 'USD'];
$zeroByCurrency = ['IQD' => 0.0, 'USD' => 0.0];

$totalAllDebt = $zeroByCurrency;
$totalAllPayments = $zeroByCurrency;
$totalAllRemaining = $zeroByCurrency;

// ئامارە تایبەتەکانی مامەڵەکان (بێ وەسڵ) — بەپێی دراو
$transactionsStatsQuery = "SELECT cd.currency,
    SUM(CASE WHEN cd.type = 'debt' THEN cd.amount ELSE 0 END) as total_debt,
    SUM(CASE WHEN cd.type = 'payment' THEN cd.amount ELSE 0 END) as total_payments
    FROM company_debts cd
    WHERE cd.company_id = ? AND cd.user_id = ? AND cd.purchase_receipt_id IS NULL
    AND NOT (
        cd.type = 'debt'
        AND cd.description LIKE 'وەسڵی کڕین #%'
        AND EXISTS (
            SELECT 1 FROM purchase_receipts pr
            WHERE pr.company_id = cd.company_id
              AND pr.user_id = cd.user_id
              AND pr.payment_type = 'debt'
              AND pr.status = 'active'
              AND (
                  cd.description = CONCAT('وەسڵی کڕین #', pr.id)
                  OR (pr.receipt_number IS NOT NULL AND TRIM(pr.receipt_number) <> ''
                      AND cd.description = CONCAT('وەسڵی کڕین #', pr.receipt_number))
              )
        )
    )
    GROUP BY cd.currency";

$transactionsStatsStmt = $conn->prepare($transactionsStatsQuery);
$transactionsStatsStmt->bind_param("ii", $companyId, $userId);
$transactionsStatsStmt->execute();
$transactionsStatsRows = $transactionsStatsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$transactionDebtTotal = $zeroByCurrency;
$transactionPaymentTotal = $zeroByCurrency;
$transactionRemaining = $zeroByCurrency;
foreach ($transactionsStatsRows as $row) {
    $cur = (($row['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
    $transactionDebtTotal[$cur] = (float)($row['total_debt'] ?? 0);
    $transactionPaymentTotal[$cur] = (float)($row['total_payments'] ?? 0);
}
foreach ($debtCurrencies as $cur) {
    $transactionRemaining[$cur] = max(0, $transactionDebtTotal[$cur] - $transactionPaymentTotal[$cur]);
}

$totalPages = ceil($totalRecords / $perPage);
$csrf_token = Security::generateCSRFToken();

// وەرگرتنی وەسڵە قەرزانەکان (installment loans) لەگەڵ پارەدانەوە و مانەوە
$installmentsQuery = "SELECT
                          pr.*,
                          (SELECT COUNT(*) FROM purchase_receipt_items WHERE purchase_receipt_id = pr.id) as items_count,
                          COALESCE((
                              SELECT SUM(cd.amount)
                              FROM company_debts cd
                              WHERE cd.company_id = pr.company_id
                                AND cd.user_id = pr.user_id
                                AND cd.type = 'payment'
                                AND cd.purchase_receipt_id = pr.id
                          ), 0) AS paid_amount,
                          (pr.final_amount - COALESCE((
                              SELECT SUM(cd2.amount)
                              FROM company_debts cd2
                              WHERE cd2.company_id = pr.company_id
                                AND cd2.user_id = pr.user_id
                                AND cd2.type = 'payment'
                                AND cd2.purchase_receipt_id = pr.id
                          ), 0)) AS remaining_amount
                      FROM purchase_receipts pr
                      WHERE pr.company_id = ? AND pr.user_id = ? AND pr.payment_type = 'debt' AND pr.status = 'active'
                      ORDER BY pr.receipt_date ASC, pr.created_at ASC";
$installmentsStmt = $conn->prepare($installmentsQuery);
$installmentsStmt->bind_param("ii", $companyId, $userId);
$installmentsStmt->execute();
$installments = $installmentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// کۆی وەسڵە قەرزانەکان — بەپێی دراو
$installmentsDebtTotal = $zeroByCurrency;
$installmentsPaidTotal = $zeroByCurrency;
$installmentsRemainingTotal = $zeroByCurrency;
foreach ($installments as $inst) {
    $cur = (($inst['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
    $finalAmount = isset($inst['final_amount']) ? (float)$inst['final_amount'] : 0;
    $paidAmount = isset($inst['paid_amount']) ? (float)$inst['paid_amount'] : 0;
    $remaining = isset($inst['remaining_amount']) ? (float)$inst['remaining_amount'] : $finalAmount;
    if ($remaining < 0) {
        $remaining = 0;
    }
    $installmentsDebtTotal[$cur] += $finalAmount;
    $installmentsPaidTotal[$cur] += $paidAmount;
    $installmentsRemainingTotal[$cur] += $remaining;
}

// کۆکردنەوەی قەرزی مامەڵەکان + وەسڵەکان بۆ هەموو کۆی گشتیەکان — بەپێی دراو
$combinedTotalDebt = $zeroByCurrency;
$combinedTotalPayments = $zeroByCurrency;
$combinedRemaining = $zeroByCurrency;
foreach ($debtCurrencies as $cur) {
    $combinedTotalDebt[$cur] = $transactionDebtTotal[$cur] + $installmentsDebtTotal[$cur];
    $combinedTotalPayments[$cur] = $transactionPaymentTotal[$cur] + $installmentsPaidTotal[$cur];
    $combinedRemaining[$cur] = max(0, $transactionRemaining[$cur] + $installmentsRemainingTotal[$cur]);
}

// نوێکردنەوەی کۆی گشتی قەرز/پارەدانەوە/ماوە بە بنەمای هەردوو جۆر قەرز
$totalAllDebt = $combinedTotalDebt;
$totalAllPayments = $combinedTotalPayments;
$totalAllRemaining = $combinedRemaining;

// نوێکردنەوەی زانیاری کۆمپانیا
$companyStmt->execute();
$company = $companyStmt->get_result()->fetch_assoc();

// قەرزی ئێستا = ماوەی قەرزی کۆمپانیا لە هەموو جۆر قەرز (مامەڵە + وەسڵ)
$totalCurrentDebt = $combinedRemaining;

// ئایا هیچ چالاکیەکی دۆلاری هەیە؟ (بۆ نیشاندانی باڵانسی دۆلار تەنها کاتێک پێویست بێت)
$debtHasUsd = ($combinedTotalDebt['USD'] > 0)
    || ($combinedTotalPayments['USD'] > 0)
    || ((float)($company['debt_amount_usd'] ?? 0) > 0);

// یاریدەدەری نیشاندانی بڕ بە هەردوو دراو (دینار هەمیشە، دۆلار کاتێک هەبێت)
$renderDualAmount = static function (array $amounts) use ($debtHasUsd) {
    $out = '<span class="dual-iqd">' . htmlspecialchars(formatCurrencyAmount($amounts['IQD'] ?? 0, 'IQD')) . '</span>';
    if ($debtHasUsd) {
        $out .= ' <span class="text-muted mx-1">/</span> <span class="dual-usd">' . htmlspecialchars(formatCurrencyAmount($amounts['USD'] ?? 0, 'USD')) . '</span>';
    }
    return $out;
};
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>قەرزەکانی <?php echo htmlspecialchars($company['name']); ?> - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    
    <style>
        .page-company-debts {
            --debts-bg: #eef1f6;
            --debts-surface: #ffffff;
            --debts-border: #e1e6ee;
            --debts-text: #12151c;
            --debts-muted: #5f6675;
            --debts-accent: #1d63d8;
            --debts-accent-soft: #e8f0ff;
            --debts-debt: #b4232c;
            --debts-debt-soft: #fdecef;
            --debts-pay: #0f6b4c;
            --debts-pay-soft: #e4f5ed;
            --debts-remain: #3d4451;
            --debts-remain-soft: #eceff5;
            --debts-radius: 14px;
            --debts-shadow: 0 1px 2px rgba(18, 24, 33, 0.05), 0 6px 18px rgba(18, 24, 33, 0.06);
            background-color: var(--debts-bg);
            min-height: 100vh;
            color: var(--debts-text);
        }
        .page-company-debts .debts-shell {
            max-width: 1200px;
            margin-inline: auto;
        }
        .page-company-debts .debts-surface {
            background: var(--debts-surface);
            border: 1px solid var(--debts-border);
            border-radius: var(--debts-radius);
            box-shadow: var(--debts-shadow);
        }
        .page-company-debts .debts-hero {
            padding: 1.35rem 1.5rem;
        }
        .page-company-debts .debts-hero .debts-hero-title {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .page-company-debts .debts-hero-kpi {
            text-align: end;
            padding: 0.65rem 1rem;
            border-radius: 12px;
            background: var(--debts-remain-soft);
            border: 1px solid var(--debts-border);
            min-width: 160px;
        }
        .page-company-debts .debts-hero-kpi .debts-kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--debts-debt);
            line-height: 1.2;
        }
        .page-company-debts .debts-hero-kpi .debts-kpi-label {
            font-size: 0.8rem;
            color: var(--debts-muted);
        }
        .page-company-debts .debts-status {
            font-weight: 600;
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
            border-radius: 999px;
        }
        .page-company-debts .debts-toolbar h1 {
            font-size: 1.15rem;
            font-weight: 700;
            margin: 0;
        }
        .page-company-debts .debts-toolbar .text-muted {
            color: var(--debts-muted) !important;
            font-size: 0.875rem;
        }
        .page-company-debts .debts-btn-primary {
            background: var(--debts-accent);
            border: none;
            font-weight: 600;
            padding: 0.55rem 1.1rem;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(29, 99, 216, 0.25);
        }
        .page-company-debts .debts-btn-primary:hover {
            background: #1857c4;
        }
        .page-company-debts .debts-stat {
            border-radius: var(--debts-radius);
            border: 1px solid var(--debts-border);
            padding: 1rem 1.15rem;
            height: 100%;
            background: var(--debts-surface);
            box-shadow: var(--debts-shadow);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .page-company-debts .debts-stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(18, 24, 33, 0.08);
        }
        .page-company-debts .debts-stat .debts-stat-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--debts-muted);
            margin-bottom: 0.35rem;
        }
        .page-company-debts .debts-stat .debts-stat-value {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .page-company-debts .debts-stat .debts-stat-meta {
            font-size: 0.78rem;
            color: var(--debts-muted);
            margin-top: 0.5rem;
        }
        .page-company-debts .debts-stat--debt {
            border-top: 3px solid var(--debts-debt);
            background: linear-gradient(180deg, #fff 0%, var(--debts-debt-soft) 100%);
        }
        .page-company-debts .debts-stat--debt .debts-stat-value {
            color: var(--debts-debt);
        }
        .page-company-debts .debts-stat--pay {
            border-top: 3px solid var(--debts-pay);
            background: linear-gradient(180deg, #fff 0%, var(--debts-pay-soft) 100%);
        }
        .page-company-debts .debts-stat--pay .debts-stat-value {
            color: var(--debts-pay);
        }
        .page-company-debts .debts-stat--remain {
            border-top: 3px solid var(--debts-remain);
            background: linear-gradient(180deg, #fff 0%, var(--debts-remain-soft) 100%);
        }
        .page-company-debts .debts-stat--remain .debts-stat-value {
            color: var(--debts-remain);
        }
        .page-company-debts .debts-tabs {
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .page-company-debts .debts-tabs .nav-link {
            border-radius: 10px;
            font-weight: 600;
            color: var(--debts-muted);
            padding: 0.5rem 1rem;
            border: 1px solid transparent;
        }
        .page-company-debts .debts-tabs .nav-link:hover {
            color: var(--debts-text);
            background: rgba(0, 0, 0, 0.04);
        }
        .page-company-debts .debts-tabs .nav-link.active {
            color: var(--debts-accent);
            background: var(--debts-accent-soft);
            border-color: rgba(29, 99, 216, 0.2);
        }
        .page-company-debts .debts-panel-card {
            border: 1px solid var(--debts-border);
            border-radius: var(--debts-radius);
            overflow: hidden;
            box-shadow: var(--debts-shadow);
            background: var(--debts-surface);
        }
        .page-company-debts .debts-panel-card .card-header {
            background: #f8f9fc;
            border-bottom: 1px solid var(--debts-border);
            padding: 0.9rem 1.15rem;
        }
        .page-company-debts .debts-panel-card .card-title {
            font-size: 1rem;
            font-weight: 700;
        }
        .page-company-debts .debts-mini-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.65rem;
            font-size: 0.8rem;
        }
        .page-company-debts .debts-mini-metric {
            padding: 0.5rem 0.65rem;
            border-radius: 10px;
            background: #fff;
            border: 1px solid var(--debts-border);
        }
        .page-company-debts .debts-mini-metric strong {
            display: block;
            font-size: 0.95rem;
            margin-top: 0.15rem;
        }
        .page-company-debts .debts-mini-metric--debt strong {
            color: var(--debts-debt);
        }
        .page-company-debts .debts-mini-metric--pay strong {
            color: var(--debts-pay);
        }
        .page-company-debts .debts-mini-metric--rem strong {
            color: var(--debts-remain);
        }
        .page-company-debts .debts-table-wrap {
            border-top: 1px solid var(--debts-border);
        }
        .page-company-debts .debts-table thead th {
            font-size: 0.78rem;
            text-transform: none;
            font-weight: 700;
            color: var(--debts-muted);
            border-bottom: 1px solid var(--debts-border);
            background: #f8f9fc !important;
        }
        .page-company-debts .debts-table tbody td {
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .page-company-debts .debts-table tfoot th {
            background: #f3f5f9;
            font-size: 0.85rem;
        }
        .page-company-debts .debts-icon-btn {
            border-radius: 8px;
            padding: 0.28rem 0.45rem;
        }
        .page-company-debts .transaction-item {
            border: 0;
            border-inline-start: 4px solid var(--debts-border);
            transition: background 0.15s ease;
        }
        .page-company-debts .transaction-item.debt {
            border-inline-start-color: var(--debts-debt);
        }
        .page-company-debts .transaction-item.payment {
            border-inline-start-color: var(--debts-pay);
        }
        .page-company-debts .transaction-item:hover {
            background-color: #f8f9fc !important;
        }
        .page-company-debts .debts-empty {
            padding: 2rem 1rem;
            color: var(--debts-muted);
        }
        .page-company-debts .debts-empty i {
            font-size: 2.25rem;
            opacity: 0.45;
        }
        .page-company-debts .debts-pagination .page-link {
            border-radius: 8px;
            margin: 0 0.15rem;
            border: 1px solid var(--debts-border);
            color: var(--debts-text);
        }
        .page-company-debts .debts-pagination .page-item.active .page-link {
            background: var(--debts-accent);
            border-color: var(--debts-accent);
        }
        .page-company-debts .debts-footnote {
            font-size: 0.75rem;
            color: var(--debts-muted);
        }
        .page-company-debts .new-transaction-modal .modal-content {
            border-radius: var(--debts-radius);
            border: 1px solid var(--debts-border);
            box-shadow: 0 12px 40px rgba(18, 24, 33, 0.15);
        }
        .page-company-debts .new-transaction-modal .modal-header {
            border-bottom: 1px solid var(--debts-border);
            background: #f8f9fc;
        }
        .page-company-debts .new-transaction-modal .modal-title {
            font-weight: 700;
        }
        @media (max-width: 767.98px) {
            .page-company-debts .debts-hero .d-flex {
                flex-direction: column;
                align-items: stretch !important;
                gap: 1rem;
            }
            .page-company-debts .debts-hero-kpi {
                text-align: center;
            }
        }
    </style>
</head>
<body class="companies-module-page companies-debts-page page-company-debts">

        <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 debts-shell hub-page-content">
        <div class="row mb-4">
            <div class="col-12">
                <div class="debts-surface debts-hero">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-building text-secondary"></i>
                                <span class="debts-hero-title"><?php echo htmlspecialchars($company['name']); ?></span>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <?php if (!empty($company['phone'])): ?>
                                    <span class="text-muted small"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($company['phone']); ?></span>
                                <?php endif; ?>
                                <span class="debts-status <?php echo $company['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?>">
                                    <?php echo $company['status'] === 'active' ? 'چالاک' : 'ناچالاک'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="debts-hero-kpi">
                            <div class="debts-kpi-value"><?php echo $renderDualAmount($totalCurrentDebt); ?></div>
                            <div class="debts-kpi-label">قەرزی ئێستا (کۆی ماوە)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row align-items-start g-3 mb-2 debts-toolbar">
            <div class="col-12">
                <h1>کۆی گشتی قەرزی کۆمپانیا</h1>
                <p class="text-muted mb-0">ئاماری گشتی، وەسڵە قەرزانەکان و مامەڵەی دەستی لە تابەکاندا ڕێکخراون. مامەڵەی نوێ لە تابی «مامەڵەی دەستی»دا دەستەبەرە.</p>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-4 col-md-6">
                <div class="debts-stat debts-stat--debt">
                    <div class="debts-stat-label"><i class="bi bi-arrow-up-circle me-1"></i>کۆی گشتی قەرز</div>
                    <div class="debts-stat-value"><?php echo $renderDualAmount($totalAllDebt); ?></div>
                    <div class="debts-stat-meta"><?php echo (int)($stats['debt_transactions'] ?? 0); ?> مامەڵەی قەرز</div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="debts-stat debts-stat--pay">
                    <div class="debts-stat-label"><i class="bi bi-arrow-down-circle me-1"></i>کۆی گشتی پارەدانەوە</div>
                    <div class="debts-stat-value"><?php echo $renderDualAmount($totalAllPayments); ?></div>
                    <div class="debts-stat-meta"><?php echo (int)($stats['payment_transactions'] ?? 0); ?> مامەڵەی دانەوە</div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="debts-stat debts-stat--remain">
                    <div class="debts-stat-label"><i class="bi bi-wallet2 me-1"></i>ماوەی قەرزی کۆمپانیا</div>
                    <div class="debts-stat-value"><?php echo $renderDualAmount($totalAllRemaining); ?></div>
                    <div class="debts-stat-meta">
                        یەکەم مامەڵە: <?php echo $stats['first_transaction'] ? date('Y/m/d', strtotime($stats['first_transaction'])) : 'هیچ'; ?>
                        <span class="mx-1">·</span>
                        دوایین: <?php echo $stats['last_transaction'] ? date('Y/m/d', strtotime($stats['last_transaction'])) : 'هیچ'; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php
            $requestedTab = $_GET['tab'] ?? 'receipt';
            $debtsManualTabActive = $requestedTab === 'manual';
            if (!empty($errors)) {
                $debtsManualTabActive = true;
            }
            if (!empty($bulkErrors)) {
                $debtsManualTabActive = false;
            }
        ?>

        <ul class="nav nav-pills debts-tabs mb-3" id="debtsMainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $debtsManualTabActive ? '' : 'active'; ?>"
                        id="debts-tab-receipt"
                        data-bs-toggle="pill"
                        data-bs-target="#debts-pane-receipt"
                        type="button"
                        role="tab"
                        aria-controls="debts-pane-receipt"
                        aria-selected="<?php echo $debtsManualTabActive ? 'false' : 'true'; ?>">
                    <i class="bi bi-receipt me-1"></i>وەسڵەکان
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $debtsManualTabActive ? 'active' : ''; ?>"
                        id="debts-tab-manual"
                        data-bs-toggle="pill"
                        data-bs-target="#debts-pane-manual"
                        type="button"
                        role="tab"
                        aria-controls="debts-pane-manual"
                        aria-selected="<?php echo $debtsManualTabActive ? 'true' : 'false'; ?>">
                    <i class="bi bi-pencil-square me-1"></i>مامەڵەی دەستی
                </button>
            </li>
        </ul>

        <div class="tab-content mb-4" id="debtsMainTabsContent">
            <div class="tab-pane fade <?php echo $debtsManualTabActive ? '' : 'show active'; ?>" id="debts-pane-receipt" role="tabpanel" aria-labelledby="debts-tab-receipt" tabindex="0">
                <?php if (!empty($installments)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card debts-panel-card border-0 mb-4">
                                <div class="card-header flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="rounded-circle bg-warning bg-opacity-10 text-warning d-inline-flex p-2"><i class="bi bi-receipt-cutoff"></i></span>
                                        <h5 class="card-title mb-0">وەسڵە قەرزانەکان</h5>
                                    </div>
                                    <div class="debts-mini-metrics flex-grow-1">
                                        <div class="debts-mini-metric">
                                            ژمارەی وەسڵ
                                            <strong><?php echo count($installments); ?></strong>
                                        </div>
                                        <div class="debts-mini-metric debts-mini-metric--debt">
                                            قەرزی وەسڵەکان
                                            <strong><?php echo $renderDualAmount($installmentsDebtTotal); ?></strong>
                                        </div>
                                        <div class="debts-mini-metric debts-mini-metric--pay">
                                            پارەدراو
                                            <strong><?php echo $renderDualAmount($installmentsPaidTotal); ?></strong>
                                        </div>
                                        <div class="debts-mini-metric debts-mini-metric--rem">
                                            ماوە
                                            <strong><?php echo $renderDualAmount($installmentsRemainingTotal); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body p-0 debts-table-wrap">
                                    <form method="POST" class="p-3 border-bottom bg-light-subtle">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <?php if (!empty($bulkErrors)): ?>
                                            <div class="alert alert-danger border-0 shadow-sm mb-3">
                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                <strong>هەڵەکانی پارەدانەوەی بە کۆمەڵە:</strong>
                                                <ul class="mb-0 mt-2 pe-3">
                                                    <?php foreach ($bulkErrors as $bulkError): ?>
                                                        <li><?php echo htmlspecialchars($bulkError); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <div class="row g-2 align-items-end">
                                            <div class="col-lg-3">
                                                <label for="bulk_currency" class="form-label fw-semibold mb-1">دراو</label>
                                                <select id="bulk_currency" class="form-select" name="currency">
                                                    <option value="IQD" <?php echo $bulkFormData['currency'] === 'USD' ? '' : 'selected'; ?>>دینار (IQD)</option>
                                                    <option value="USD" <?php echo $bulkFormData['currency'] === 'USD' ? 'selected' : ''; ?>>دۆلار (USD)</option>
                                                </select>
                                            </div>
                                            <div class="col-lg-3">
                                                <label for="bulk_total_amount" class="form-label fw-semibold mb-1">بڕی گشتی پارەدانەوە</label>
                                                <input type="number"
                                                       id="bulk_total_amount"
                                                       class="form-control"
                                                       name="total_amount"
                                                       min="0.01"
                                                       step="0.01"
                                                       value="<?php echo htmlspecialchars((string)$bulkFormData['total_amount']); ?>"
                                                       required>
                                            </div>
                                            <div class="col-lg-3">
                                                <label for="bulk_payment_date" class="form-label fw-semibold mb-1">بەروار</label>
                                                <input type="date"
                                                       id="bulk_payment_date"
                                                       class="form-control"
                                                       name="date"
                                                       value="<?php echo htmlspecialchars((string)$bulkFormData['date']); ?>"
                                                       required>
                                            </div>
                                            <div class="col-lg-3">
                                                <label for="bulk_description" class="form-label fw-semibold mb-1">تێبینی <span class="text-muted small">(ئیجباری نییە)</span></label>
                                                <input type="text"
                                                       id="bulk_description"
                                                       class="form-control"
                                                       name="description"
                                                       maxlength="255"
                                                       value="<?php echo htmlspecialchars((string)$bulkFormData['description']); ?>"
                                                       placeholder="بۆ نموونە: پارەدانەوەی هەفتانە">
                                            </div>
                                            <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2 pt-1">
                                                <small class="text-muted">
                                                    کۆی مانەوەی وەسڵە هەڵبژێردراوەکان (بۆ دراوی هەڵبژێردراو):
                                                    <strong id="bulkSelectedRemaining">0</strong> <span id="bulkSelectedCurrencyLabel">دینار</span>
                                                </small>
                                                <button type="submit" name="add_bulk_payment" class="btn btn-success px-4">
                                                    <i class="bi bi-cash-stack me-1"></i>پارەدانەوەی بە کۆمەڵە (FIFO)
                                                </button>
                                            </div>
                                        </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0 debts-table">
                                            <thead>
                                                <tr>
                                                    <th>
                                                        <input type="checkbox" class="form-check-input" id="bulkSelectAllReceipts" title="هەمووی هەڵبژێرە">
                                                    </th>
                                                    <th>بەروار</th>
                                                    <th>ژمارەی وەسڵ</th>
                                                    <th>کاڵاکان</th>
                                                    <th>کۆی گشتی</th>
                                                    <th>مانەوە</th>
                                                    <th>دۆخ</th>
                                                    <th>کردەوەکان</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($installments as $installment): ?>
                                                    <?php
                                                        $installmentRemaining = max(0, (float)($installment['remaining_amount'] ?? 0));
                                                        $installmentCurrency = (($installment['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
                                                    ?>
                                                    <tr data-currency="<?php echo $installmentCurrency; ?>">
                                                        <td>
                                                            <?php if ($installmentRemaining > 0): ?>
                                                                <input type="checkbox"
                                                                       class="form-check-input bulk-receipt-checkbox"
                                                                       name="receipt_ids[]"
                                                                       value="<?php echo (int)$installment['id']; ?>"
                                                                       data-remaining="<?php echo $installmentRemaining; ?>"
                                                                       data-currency="<?php echo $installmentCurrency; ?>"
                                                                       <?php echo in_array((string)$installment['id'], $bulkFormData['receipt_ids'], true) ? 'checked' : ''; ?>>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo date('Y-m-d', strtotime($installment['receipt_date'])); ?></div>
                                                            <small class="text-muted"><?php echo date('H:i', strtotime($installment['created_at'])); ?></small>
                                                        </td>
                                                        <td>
                                                            <?php if ($installment['receipt_number']): ?>
                                                                <span class="badge text-bg-secondary"><?php echo htmlspecialchars($installment['receipt_number']); ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted">#<?php echo $installment['id']; ?></span>
                                                            <?php endif; ?>
                                                            <span class="badge <?php echo $installmentCurrency === 'USD' ? 'text-bg-success' : 'text-bg-info'; ?> ms-1"><?php echo $installmentCurrency === 'USD' ? 'دۆلار' : 'دینار'; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge text-bg-light border"><?php echo (int)$installment['items_count']; ?> کاڵا</span>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold text-danger"><?php echo htmlspecialchars(formatCurrencyAmount($installment['final_amount'], $installmentCurrency)); ?></div>
                                                            <small class="text-muted d-block">کۆی وەسڵ</small>
                                                            <small class="text-success">پارەدراو: <?php echo htmlspecialchars(formatCurrencyAmount($installment['paid_amount'], $installmentCurrency)); ?></small>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold <?php echo $installmentRemaining > 0 ? 'text-danger' : 'text-success'; ?>">
                                                                <?php echo htmlspecialchars(formatCurrencyAmount($installmentRemaining, $installmentCurrency)); ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge text-bg-<?php
                                                                echo $installment['status'] == 'active' ? 'primary' :
                                                                    ($installment['status'] == 'completed' ? 'success' : 'danger');
                                                            ?>">
                                                                <?php
                                                                    echo $installment['status'] == 'active' ? 'چالاک' :
                                                                        ($installment['status'] == 'completed' ? 'تەواو' : 'هەڵوەشێندراوە');
                                                                ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <a href="<?php echo url("user/purchases/view.php?id={$installment['id']}"); ?>"
                                                                   class="btn btn-sm btn-outline-primary debts-icon-btn" title="بینین">
                                                                    <i class="bi bi-eye"></i>
                                                                </a>
                                                                <a href="<?php echo url("user/purchases/edit.php?id={$installment['id']}"); ?>"
                                                                   class="btn btn-sm btn-outline-secondary debts-icon-btn" title="دەستکاری">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="<?php echo url("user/purchases/pay_installment.php?id={$installment['id']}"); ?>"
                                                                   class="btn btn-sm btn-outline-success debts-icon-btn" title="پارەدانەوە">
                                                                    <i class="bi bi-cash-coin"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <th colspan="4" class="text-end">کۆی گشتی ماوە:</th>
                                                    <th class="text-danger"><?php echo $renderDualAmount($installmentsRemainingTotal); ?></th>
                                                    <th colspan="3"></th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card debts-panel-card border-0">
                            <div class="card-header">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle bg-secondary bg-opacity-10 text-secondary d-inline-flex p-2"><i class="bi bi-clock-history"></i></span>
                                    <h5 class="card-title mb-0">مێژووی پارەدانەوەی قەرزی وەسڵەکان</h5>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($transactionsWithReceipt)): ?>
                                    <div class="debts-empty text-center border-top">
                                        <i class="bi bi-inbox d-block mb-2"></i>
                                        <p class="mb-0 fw-semibold">هیچ پارەدانەوەی پەیوەست بە وەسڵ نییە</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($transactionsWithReceipt as $transaction): ?>
                                            <div class="list-group-item transaction-item <?php echo $transaction['type']; ?>">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                            <i class="bi bi-<?php echo $transaction['type'] === 'debt' ? 'arrow-up-circle text-danger' : 'arrow-down-circle text-success'; ?>"></i>
                                                            <h6 class="mb-0"><?php echo $transaction['type'] === 'debt' ? 'قەرز (وەسڵ)' : 'دانەوە (وەسڵ)'; ?></h6>
                                                            <span class="badge <?php echo $transaction['type'] === 'debt' ? 'text-bg-danger' : 'text-bg-success'; ?>">
                                                                <?php echo htmlspecialchars(formatCurrencyAmount($transaction['amount'], $transaction['currency'] ?? 'IQD')); ?>
                                                            </span>
                                                        </div>
                                                        <p class="mb-0 text-muted small text-break"><?php echo htmlspecialchars($transaction['description']); ?></p>
                                                    </div>
                                                    <div class="text-end d-flex align-items-center gap-2 flex-shrink-0">
                                                        <div>
                                                            <small class="text-muted d-block">
                                                                <i class="bi bi-calendar3"></i>
                                                                <?php echo date('Y/m/d', strtotime($transaction['date'])); ?>
                                                            </small>
                                                            <small class="text-muted d-block">
                                                                <i class="bi bi-clock"></i>
                                                                <?php echo date('H:i', strtotime($transaction['created_at'])); ?>
                                                            </small>
                                                        </div>
                                                        <button type="button"
                                                                class="btn btn-sm btn-outline-danger debts-icon-btn"
                                                                onclick="deleteTransaction(<?php echo $transaction['id']; ?>, '<?php echo $transaction['type'] === 'debt' ? 'قەرز (وەسڵ)' : 'دانەوە (وەسڵ)'; ?>', <?php echo $transaction['amount']; ?>, '<?php echo $transaction['currency'] ?? 'IQD'; ?>')"
                                                                title="سڕینەوە">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $debtsManualTabActive ? 'show active' : ''; ?>" id="debts-pane-manual" role="tabpanel" aria-labelledby="debts-tab-manual" tabindex="0">
                <div class="row">
                    <div class="col-12">
                        <div class="card debts-panel-card border-0">
                            <div class="card-header flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex p-2"><i class="bi bi-arrow-left-right"></i></span>
                                    <h5 class="card-title mb-0">قەرزی مامەڵەکان</h5>
                                </div>
                                <div class="debts-mini-metrics flex-grow-1">
                                    <div class="debts-mini-metric debts-mini-metric--debt">
                                        کۆی قەرز
                                        <strong><?php echo $renderDualAmount($transactionDebtTotal); ?></strong>
                                    </div>
                                    <div class="debts-mini-metric debts-mini-metric--pay">
                                        کۆی پارەدانەوە
                                        <strong><?php echo $renderDualAmount($transactionPaymentTotal); ?></strong>
                                    </div>
                                    <div class="debts-mini-metric debts-mini-metric--rem">
                                        ماوە
                                        <strong><?php echo $renderDualAmount($transactionRemaining); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">
                                    ئەم بەشە تەنیا ئەو قەرز و پارەدانەوانە نیشان دەدات کە پەیوەست بە وەسڵی کڕین نیین (مامەڵەکان بە شێوەی دەستی زیادکراون).
                                </p>
                                <?php if (empty($transactionsWithoutReceipt)): ?>
                                    <div class="debts-empty text-center rounded-3 border border-dashed">
                                        <i class="bi bi-inbox d-block mb-2"></i>
                                        <p class="mb-0 fw-semibold">هیچ قەرزی مامەڵەی دەستی تۆمار نەکراوە</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush rounded-3 border">
                                        <?php foreach ($transactionsWithoutReceipt as $transaction): ?>
                                            <div class="list-group-item transaction-item <?php echo $transaction['type']; ?>">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                            <i class="bi bi-<?php echo $transaction['type'] === 'debt' ? 'arrow-up-circle text-danger' : 'arrow-down-circle text-success'; ?>"></i>
                                                            <h6 class="mb-0"><?php echo $transaction['type'] === 'debt' ? 'قەرز' : 'دانەوە'; ?></h6>
                                                            <span class="badge <?php echo $transaction['type'] === 'debt' ? 'text-bg-danger' : 'text-bg-success'; ?>">
                                                                <?php echo htmlspecialchars(formatCurrencyAmount($transaction['amount'], $transaction['currency'] ?? 'IQD')); ?>
                                                            </span>
                                                        </div>
                                                        <p class="mb-0 text-muted small text-break"><?php echo htmlspecialchars($transaction['description']); ?></p>
                                                    </div>
                                                    <div class="text-end d-flex align-items-center gap-2 flex-shrink-0">
                                                        <div>
                                                            <small class="text-muted d-block">
                                                                <i class="bi bi-calendar3"></i>
                                                                <?php echo date('Y/m/d', strtotime($transaction['date'])); ?>
                                                            </small>
                                                            <small class="text-muted d-block">
                                                                <i class="bi bi-clock"></i>
                                                                <?php echo date('H:i', strtotime($transaction['created_at'])); ?>
                                                            </small>
                                                        </div>
                                                        <button type="button"
                                                                class="btn btn-sm btn-outline-danger debts-icon-btn"
                                                                onclick="deleteTransaction(<?php echo $transaction['id']; ?>, '<?php echo $transaction['type'] === 'debt' ? 'قەرز' : 'دانەوە'; ?>', <?php echo $transaction['amount']; ?>, '<?php echo $transaction['currency'] ?? 'IQD'; ?>')"
                                                                title="سڕینەوە">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-center pt-3 pb-2 mt-1">
                    <button type="button"
                            class="btn btn-primary debts-btn-primary d-inline-flex align-items-center gap-2 px-4"
                            data-bs-toggle="modal"
                            data-bs-target="#newTransactionModal">
                        <i class="bi bi-plus-lg"></i>
                        <span>مامەڵەی نوێ</span>
                    </button>
                </div>
            </div>
        </div>

        <p class="debts-footnote text-center mb-3">زانیاریەکان لە ماوەی ٣٠ چرکەدا خۆکارانە نوێ دەبنەوە.</p>

        <?php if ($totalPages > 1): ?>
            <div class="row mt-2">
                <div class="col-12">
                    <nav aria-label="پەیجەکان">
                        <ul class="pagination justify-content-center debts-pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?company_id=<?php echo $companyId; ?>&page=<?php echo $page - 1; ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?company_id=<?php echo $companyId; ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?company_id=<?php echo $companyId; ?>&page=<?php echo $page + 1; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- New Transaction Modal -->
    <div class="modal fade new-transaction-modal" id="newTransactionModal" tabindex="-1" aria-labelledby="newTransactionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="newTransactionModalLabel">
                        <span class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex p-2"><i class="bi bi-plus-lg"></i></span>
                        <span>مامەڵەی نوێ</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger border-0 shadow-sm">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong>هەڵەکان:</strong>
                            <ul class="mb-0 mt-2 pe-3">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">جۆری مامەڵە</label>
                            <select class="form-select" name="type" required>
                                <option value="debt">قەرز (زیادکردن)</option>
                                <option value="payment">دانەوەی پارە</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">دراو</label>
                            <select class="form-select" name="currency" id="manualCurrency" required>
                                <option value="IQD">دینار (IQD)</option>
                                <option value="USD">دۆلار (USD)</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">بڕ <span class="text-muted small" id="manualAmountCurrencyLabel">(دینار)</span></label>
                            <input type="number" class="form-control" name="amount"
                                   min="0.01" step="0.01" required>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">بەروار</label>
                            <input type="date" class="form-control" name="date"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">وردەکاری</label>
                            <textarea class="form-control" name="description" rows="3"
                                      placeholder="وردەکاری مامەڵەکە..." required></textarea>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-1">
                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                            <button type="submit" name="add_transaction" class="btn btn-primary debts-btn-primary px-4">
                                <i class="bi bi-check2-circle me-1"></i>زیادکردن
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!empty($errors)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modalEl = document.getElementById('newTransactionModal');
            if (modalEl && window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
    </script>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const currencyLabel = (cur) => (cur === 'USD' ? 'دۆلار' : 'دینار');

        document.addEventListener('DOMContentLoaded', function () {
            const checkboxes = Array.from(document.querySelectorAll('.bulk-receipt-checkbox'));
            const selectAll = document.getElementById('bulkSelectAllReceipts');
            const selectedRemainingEl = document.getElementById('bulkSelectedRemaining');
            const selectedCurrencyLabelEl = document.getElementById('bulkSelectedCurrencyLabel');
            const totalAmountInput = document.getElementById('bulk_total_amount');
            const bulkCurrencySelect = document.getElementById('bulk_currency');

            const formatNumber = (value) => new Intl.NumberFormat().format(Math.max(0, value));
            const activeCurrency = () => (bulkCurrencySelect ? bulkCurrencySelect.value : 'IQD');

            // تەنها ئەو وەسڵانە کە هەمان دراوی هەڵبژێردراویان هەیە بەردەستن؛ ئەوانی تر ناچالاک دەکرێن
            const applyCurrencyFilter = () => {
                const cur = activeCurrency();
                checkboxes.forEach((checkbox) => {
                    const match = (checkbox.dataset.currency || 'IQD') === cur;
                    checkbox.disabled = !match;
                    if (!match) {
                        checkbox.checked = false;
                    }
                    const row = checkbox.closest('tr');
                    if (row) {
                        row.style.opacity = match ? '' : '0.4';
                    }
                });
                if (selectedCurrencyLabelEl) {
                    selectedCurrencyLabelEl.textContent = currencyLabel(cur);
                }
            };

            const updateBulkSummary = () => {
                const cur = activeCurrency();
                const selectedTotal = checkboxes.reduce((sum, checkbox) => {
                    if (!checkbox.checked || (checkbox.dataset.currency || 'IQD') !== cur) {
                        return sum;
                    }
                    const remaining = parseFloat(checkbox.dataset.remaining || '0');
                    return sum + (isNaN(remaining) ? 0 : remaining);
                }, 0);

                if (selectedRemainingEl) {
                    selectedRemainingEl.textContent = formatNumber(selectedTotal);
                }

                if (totalAmountInput) {
                    totalAmountInput.max = selectedTotal > 0 ? String(selectedTotal) : '';
                }
            };

            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    if (selectAll) {
                        const eligible = checkboxes.filter((item) => !item.disabled);
                        selectAll.checked = eligible.length > 0 && eligible.every((item) => item.checked);
                        selectAll.indeterminate = !selectAll.checked && eligible.some((item) => item.checked);
                    }
                    updateBulkSummary();
                });
            });

            if (selectAll) {
                selectAll.addEventListener('change', () => {
                    checkboxes.forEach((checkbox) => {
                        if (!checkbox.disabled) {
                            checkbox.checked = selectAll.checked;
                        }
                    });
                    selectAll.indeterminate = false;
                    updateBulkSummary();
                });
            }

            if (bulkCurrencySelect) {
                bulkCurrencySelect.addEventListener('change', () => {
                    if (selectAll) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    }
                    applyCurrencyFilter();
                    updateBulkSummary();
                });
            }

            applyCurrencyFilter();
            updateBulkSummary();

            // نوێکردنەوەی نیشانەی دراو لە فۆرمی مامەڵەی دەستی
            const manualCurrency = document.getElementById('manualCurrency');
            const manualAmountCurrencyLabel = document.getElementById('manualAmountCurrencyLabel');
            if (manualCurrency && manualAmountCurrencyLabel) {
                manualCurrency.addEventListener('change', () => {
                    manualAmountCurrencyLabel.textContent = '(' + currencyLabel(manualCurrency.value) + ')';
                });
            }
        });

        function deleteTransaction(transactionId, type, amount, currency) {
            const curLabel = currencyLabel(currency || 'IQD');
            Swal.fire({
                title: 'دڵنیای لە سڕینەوە؟',
                html: `
                    <div class="text-end" dir="rtl">
                        <p><strong>جۆری مامەڵە:</strong> ${type}</p>
                        <p><strong>بڕ:</strong> ${new Intl.NumberFormat().format(amount)} ${curLabel}</p>
                        <p class="text-danger mt-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            ئەم مامەڵەیە دەسڕێتەوە و قەرزی کۆمپانیا دەگەڕێتەوە بۆ دۆخی پێشوو
                        </p>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'بەڵێ، بیسڕەوە',
                cancelButtonText: 'پاشگەزبوونەوە',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // نیشاندانی لۆدینگ
                    Swal.fire({
                        title: 'تکایە چاوەڕێ بکە...',
                        text: 'مامەڵەکە دەسڕێتەوە',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // ناردنی داواکاری سڕینەوە
                    const formData = new FormData();
                    formData.append('transaction_id', transactionId);

                    fetch('<?php echo url('user/companies/delete_transaction.php'); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'سەرکەوتوو بوو!',
                                text: data.message,
                                confirmButtonText: 'باشە'
                            }).then(() => {
                                // نوێکردنەوەی لاپەڕە
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'هەڵە!',
                                text: data.message,
                                confirmButtonText: 'باشە'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'هەڵە!',
                            text: 'هەڵەیەک ڕوویدا لە پەیوەندی کردن',
                            confirmButtonText: 'باشە'
                        });
                        console.error('Error:', error);
                    });
                }
            });
        }

        // Auto refresh every 30 seconds for real-time updates
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>