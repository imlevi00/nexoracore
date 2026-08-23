<?php
/**
 * کەشف حسابی کڕیار - Account Statement Page
 * Shows credit sales and debt payments for a selected customer
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    header('Location: ' . url('user/auth/login.php'));
    exit;
}

$currentUser = getCurrentUser();
requireCustomersAccountStatementAccess();
$userId = $currentUser['id'];

// وەرگرتنی پارامیتەرەکان
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$paperSize = (isset($_GET['paper']) && strtoupper($_GET['paper']) === 'A5') ? 'A5' : 'A4';
// ڕیزبەندی مامەڵەکان لە خشتەدا: desc = لە سەرەوە تازە → کۆن (پێشگریمان)، asc = کۆن → تازە
$txnSortOrder = (isset($_GET['txn_order']) && $_GET['txn_order'] === 'asc') ? 'asc' : 'desc';

// فلتەری جۆری مامەڵە (چێکبۆکس + hidden=0): ئەگەر کلیل لە GETدا نەبێت = هەموو چالاک
$showCredit = !isset($_GET['show_credit']) || (string)$_GET['show_credit'] === '1';
$showPayment = !isset($_GET['show_payment']) || (string)$_GET['show_payment'] === '1';
$showReturn = !isset($_GET['show_return']) || (string)$_GET['show_return'] === '1';
// نیشاندانی خشتەی کاڵاکان لەژێر وەسڵی قەرز / گەڕاوە (شاشە و چاپ) — پێشگریمان: ناچالاک
$statementShowCreditItemDetails = isset($_GET['show_credit_items']) && (string)$_GET['show_credit_items'] === '1';
$statementShowReturnItemDetails = isset($_GET['show_return_items']) && (string)$_GET['show_return_items'] === '1';

// وەرگرتنی لیستی کڕیاران
$customersQuery = "SELECT id, name, phone, total_debt FROM customers WHERE user_id = ? AND status = 'active' ORDER BY name";
$customersStmt = $conn->prepare($customersQuery);
$customersStmt->bind_param('i', $userId);
$customersStmt->execute();
$customers = $customersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$customersStmt->close();

/** ناسنامەی یەکتا بۆ هاوتاکردنی مامەڵە لەگەڵ قەرزی ڕاکێشراو */
function statementTransactionSortKey(array $t): string {
    $pid = (int)($t['payment_id'] ?? 0);
    if ($pid > 0) {
        return 'pay:' . $pid;
    }
    $did = (int)($t['debt_id'] ?? 0);
    if ($did > 0) {
        return 'debt:' . $did;
    }
    return 'row:' . (int)($t['id'] ?? 0);
}

/** ڕیزکردنی مامەڵەکان بەپێی بەروار و جۆر و ناسنامە */
function statementSortTransactions(array $items, string $dir): array {
    $typeSortOrder = ['credit' => 0, 'return_adjustment' => 1, 'payment' => 2];
    $copy = $items;
    usort($copy, function ($a, $b) use ($typeSortOrder, $dir) {
        $dateA = strtotime($a['transaction_date'] ?? '');
        $dateB = strtotime($b['transaction_date'] ?? '');
        if ($dateA !== $dateB) {
            return $dir === 'desc' ? ($dateB <=> $dateA) : ($dateA <=> $dateB);
        }
        $oa = $typeSortOrder[$a['type'] ?? 'payment'] ?? 9;
        $ob = $typeSortOrder[$b['type'] ?? 'payment'] ?? 9;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        $idA = (int)($a['payment_id'] ?? 0);
        if ($idA === 0) {
            $idA = (int)($a['debt_id'] ?? 0);
        }
        $idB = (int)($b['payment_id'] ?? 0);
        if ($idB === 0) {
            $idB = (int)($b['debt_id'] ?? 0);
        }
        return $dir === 'desc' ? ($idB <=> $idA) : ($idA <=> $idB);
    });
    return array_values($copy);
}

$transactions = [];
$allTransactionsFull = [];
$itemsBySaleId = [];
$itemsByReturnNumber = [];
$customerInfo = null;
$totalCredit = 0;
$totalDebt = 0;
$totalPaid = 0;
$totalCreditIQD = 0;
$totalCreditUSD = 0;
$totalPaidIQD = 0;
$totalPaidUSD = 0;
$totalDebtIQD = 0;
$totalDebtUSD = 0;
if ($customerId > 0) {
    // وەرگرتنی زانیاری کڕیار
    $customerQuery = "SELECT * FROM customers WHERE id = ? AND user_id = ?";
    $customerStmt = $conn->prepare($customerQuery);
    $customerStmt->bind_param('ii', $customerId, $userId);
    $customerStmt->execute();
    $customerInfo = $customerStmt->get_result()->fetch_assoc();
    $customerStmt->close();
    
    if ($customerInfo) {
        // دروستکردنی WHERE clause
        $whereConditions = ["(ccp.customer_id = ? OR d.customer_id = ? OR dp.debt_id IN (SELECT id FROM debts WHERE customer_id = ?))"];
        $params = [$customerId, $customerId, $customerId];
        $paramTypes = 'iii';
        
        if (!empty($dateFrom)) {
            $whereConditions[] = "(DATE(ccp.purchase_date) >= ? OR DATE(d.created_at) >= ? OR DATE(dp.payment_date) >= ?)";
            $params[] = $dateFrom;
            $params[] = $dateFrom;
            $params[] = $dateFrom;
            $paramTypes .= 'sss';
        }
        
        if (!empty($dateTo)) {
            $whereConditions[] = "(DATE(ccp.purchase_date) <= ? OR DATE(d.created_at) <= ? OR DATE(dp.payment_date) <= ?)";
            $params[] = $dateTo;
            $params[] = $dateTo;
            $params[] = $dateTo;
            $paramTypes .= 'sss';
        }
        
        // وەرگرتنی قەرزەکان
        $debtQuery = "
            SELECT 
                'credit' as type,
                d.id,
                d.sale_id,
                d.total_debt as amount,
                d.created_at as transaction_date,
                'قەرز' as description,
                d.id as debt_id,
                NULL as payment_id,
                d.paid_amount,
                d.remaining_amount,
                COALESCE(s.currency, 'IQD') as currency
            FROM debts d
            LEFT JOIN sales s ON d.sale_id = s.id
            WHERE d.customer_id = ? AND d.user_id = ?
        ";
        
        $debtConditions = [];
        $debtParams = [$customerId, $userId];
        $debtTypes = 'ii';
        
        if (!empty($dateFrom)) {
            $debtConditions[] = "DATE(d.created_at) >= ?";
            $debtParams[] = $dateFrom;
            $debtTypes .= 's';
        }
        
        if (!empty($dateTo)) {
            $debtConditions[] = "DATE(d.created_at) <= ?";
            $debtParams[] = $dateTo;
            $debtTypes .= 's';
        }
        
        if (!empty($debtConditions)) {
            $debtQuery .= " AND " . implode(" AND ", $debtConditions);
        }
        
        $debtQuery .= " ORDER BY d.created_at DESC";
        
        $debtStmt = $conn->prepare($debtQuery);
        $debtStmt->bind_param($debtTypes, ...$debtParams);
        $debtStmt->execute();
        $debtTransactions = $debtStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $debtStmt->close();
        
        // وەرگرتنی پارەدانەکان
        $paymentQuery = "
            SELECT 
                CASE 
                    WHEN dp.notes LIKE '%کەمکردنەوەی قەرز بەهۆی گەڕاوە%'
                        OR dp.notes REGEXP 'RET-[0-9]{8}-[0-9]+'
                        THEN 'return_adjustment'
                    ELSE 'payment'
                END as type,
                dp.id as payment_id,
                d.sale_id,
                dp.payment_amount as amount,
                dp.payment_date as transaction_date,
                CASE
                    WHEN dp.notes LIKE '%کەمکردنەوەی قەرز بەهۆی گەڕاوە%'
                        OR dp.notes REGEXP 'RET-[0-9]{8}-[0-9]+'
                        THEN 'گەڕاوە'
                    ELSE 'پارەدان'
                END as description,
                dp.debt_id,
                dp.id as payment_id,
                dp.notes,
                COALESCE(s.currency, 'IQD') as currency
            FROM debt_payments dp
            LEFT JOIN debts d ON dp.debt_id = d.id
            LEFT JOIN sales s ON d.sale_id = s.id
            WHERE d.customer_id = ? AND d.user_id = ?
        ";
        
        $paymentConditions = [];
        $paymentParams = [$customerId, $userId];
        $paymentTypes = 'ii';
        
        if (!empty($dateFrom)) {
            $paymentConditions[] = "DATE(dp.payment_date) >= ?";
            $paymentParams[] = $dateFrom;
            $paymentTypes .= 's';
        }
        
        if (!empty($dateTo)) {
            $paymentConditions[] = "DATE(dp.payment_date) <= ?";
            $paymentParams[] = $dateTo;
            $paymentTypes .= 's';
        }
        
        if (!empty($paymentConditions)) {
            $paymentQuery .= " AND " . implode(" AND ", $paymentConditions);
        }
        
        $paymentQuery .= " ORDER BY dp.payment_date DESC";
        
        $paymentStmt = $conn->prepare($paymentQuery);
        $paymentStmt->bind_param($paymentTypes, ...$paymentParams);
        $paymentStmt->execute();
        $paymentTransactions = $paymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $paymentStmt->close();
        
        // تێکەڵکردنی وەسڵەکان (تەنها قەرز و پارەدان) و ڕیزکردن بەپێی هەڵبژاردنی بەکارهێنەر
        $allTransactionsMerged = array_merge($debtTransactions, $paymentTransactions);
        $allTransactionsFull = statementSortTransactions($allTransactionsMerged, $txnSortOrder);
        $typeFilterMap = [
            'credit' => $showCredit,
            'payment' => $showPayment,
            'return_adjustment' => $showReturn,
        ];
        $transactions = array_values(array_filter($allTransactionsFull, function ($t) use ($typeFilterMap) {
            $ty = $t['type'] ?? 'payment';
            return !empty($typeFilterMap[$ty]);
        }));

        // زانیاری کاڵا بۆ تەنها وەسڵی قەرز
        $saleIdsForItems = [];
        if ($statementShowCreditItemDetails) {
            foreach ($transactions as $t) {
                if (($t['type'] ?? '') === 'credit') {
                    $sid = isset($t['sale_id']) ? (int)$t['sale_id'] : 0;
                    if ($sid > 0) {
                        $saleIdsForItems[$sid] = true;
                    }
                }
            }
        }
        if (!empty($saleIdsForItems)) {
            $saleIdList = array_keys($saleIdsForItems);
            $placeholders = implode(',', array_fill(0, count($saleIdList), '?'));
            $itemTypes = 'i' . str_repeat('i', count($saleIdList));
            $itemParams = array_merge([$userId], $saleIdList);
            $itemsSql = "
                SELECT si.*,
                       COALESCE(p.name, si.product_name) AS product_name_display,
                       COALESCE(s.currency, 'IQD') AS currency
                FROM sale_items si
                INNER JOIN sales s ON si.sale_id = s.id AND s.user_id = ?
                LEFT JOIN products p ON si.product_id = p.id
                WHERE si.sale_id IN ($placeholders)
                ORDER BY si.sale_id ASC, si.id ASC
            ";
            $itemsStmt = $conn->prepare($itemsSql);
            $itemsStmt->bind_param($itemTypes, ...$itemParams);
            $itemsStmt->execute();
            $itemRows = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();
            foreach ($itemRows as $row) {
                $sid = (int)$row['sale_id'];
                if (!isset($itemsBySaleId[$sid])) {
                    $itemsBySaleId[$sid] = [];
                }
                $itemsBySaleId[$sid][] = $row;
            }
        }

        $returnNums = [];
        if ($statementShowReturnItemDetails) {
            foreach ($transactions as $t) {
                if (($t['type'] ?? '') !== 'return_adjustment' || empty($t['notes'])) {
                    continue;
                }
                if (preg_match('/RET-\d{8}-\d+/u', $t['notes'], $m)) {
                    $returnNums[$m[0]] = true;
                }
            }
        }
        if (!empty($returnNums)) {
            $nums = array_keys($returnNums);
            $placeholders = implode(',', array_fill(0, count($nums), '?'));
            $riTypes = str_repeat('s', count($nums)) . 'ii';
            $riParams = array_merge($nums, [$userId, $customerId]);
            $riSql = "
                SELECT r.return_number, ri.product_name, ri.quantity, ri.unit_price, ri.total_price
                FROM returns r
                INNER JOIN return_items ri ON ri.return_id = r.id
                WHERE r.return_number IN ($placeholders)
                  AND r.user_id = ?
                  AND r.customer_id = ?
                ORDER BY r.return_number ASC, ri.id ASC
            ";
            $riStmt = $conn->prepare($riSql);
            $riStmt->bind_param($riTypes, ...$riParams);
            $riStmt->execute();
            $riRows = $riStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $riStmt->close();
            foreach ($riRows as $riRow) {
                $rn = $riRow['return_number'] ?? '';
                if ($rn === '') {
                    continue;
                }
                if (!isset($itemsByReturnNumber[$rn])) {
                    $itemsByReturnNumber[$rn] = [];
                }
                $itemsByReturnNumber[$rn][] = $riRow;
            }
        }
        
        // حیسابکردنی کۆی گشتی بەپێی دراو
        $totalCreditIQD = 0;
        $totalCreditUSD = 0;
        $totalPaidIQD = 0;
        $totalPaidUSD = 0;
        $totalDebtIQD = 0;
        $totalDebtUSD = 0;
        
        foreach ($transactions as $transaction) {
            $currency = $transaction['currency'] ?? 'IQD';
            $amount = $transaction['amount'];
            
            if ($transaction['type'] === 'credit') {
                if ($currency === 'USD') {
                    $totalCreditUSD += $amount;
                    if (isset($transaction['remaining_amount'])) {
                        $totalDebtUSD += $transaction['remaining_amount'];
                    } else {
                        $totalDebtUSD += $amount;
                    }
                } else {
                    $totalCreditIQD += $amount;
                    if (isset($transaction['remaining_amount'])) {
                        $totalDebtIQD += $transaction['remaining_amount'];
                    } else {
                        $totalDebtIQD += $amount;
                    }
                }
            } elseif (in_array(($transaction['type'] ?? ''), ['payment', 'return_adjustment'], true)) {
                if ($currency === 'USD') {
                    $totalPaidUSD += $amount;
                } else {
                    $totalPaidIQD += $amount;
                }
            }
        }
        
        // قەرزی ماوە بە جیا بۆ هەر دراوێک لە debts (نەک تەنها customers.total_debt)
        $remDebtStmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) AS usd_rem,
                COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') <> 'USD' THEN d.remaining_amount ELSE 0 END), 0) AS iqd_rem
            FROM debts d
            LEFT JOIN sales s ON d.sale_id = s.id
            WHERE d.customer_id = ? AND d.user_id = ? AND d.status = 'active'
        ");
        $remDebtStmt->bind_param('ii', $customerId, $userId);
        $remDebtStmt->execute();
        $remRow = $remDebtStmt->get_result()->fetch_assoc();
        $remDebtStmt->close();
        $totalDebtUSD = (float)($remRow['usd_rem'] ?? 0);
        $totalDebtIQD = (float)($remRow['iqd_rem'] ?? 0);
    }
}

// Helper function بۆ شێوەکردنی بڕ بەپێی دراو
function formatAmount($amount, $currency = 'IQD') {
    if ($currency === 'USD') {
        return '$' . number_format((float)$amount, 2, '.', ',');
    }
    return number_format((float)$amount, 0) . ' دینار';
}

function formatMixedCurrency($iqdAmount, $usdAmount) {
    $iqd = (float)$iqdAmount;
    $usd = (float)$usdAmount;
    // تەنها ئەو دراوەی نیشان دەدرێت کە بڕی ڕاستەقینەی هەیە (بێ تێکەڵبوونی بڕی floatی بچووک)
    $hasIqd = $iqd >= 0.5;
    $hasUsd = $usd >= 0.005;
    if ($hasUsd && $hasIqd) {
        return formatAmount($iqd, 'IQD') . ' + ' . formatAmount($usd, 'USD');
    }
    if ($hasUsd) {
        return formatAmount($usd, 'USD');
    }
    if ($hasIqd) {
        return formatAmount($iqd, 'IQD');
    }
    return formatAmount(0, 'IQD');
}

function formatStatementQty($qty) {
    $q = (float)$qty;
    if (abs($q - round($q)) < 0.0001) {
        return number_format(round($q), 0, '.', ',');
    }
    return number_format($q, 3, '.', ',');
}

function getTransactionTypeMeta($type) {
    if ($type === 'credit') {
        return ['class' => 'statement-type-credit text-warning', 'text' => 'قەرز'];
    }
    if ($type === 'return_adjustment') {
        return ['class' => 'statement-type-return', 'text' => 'گەڕاوە'];
    }
    return ['class' => 'statement-type-payment', 'text' => 'پارەدان'];
}

function buildRunningDebtTransactions(array $transactionsDisplayOrder) {
    $chronoAsc = statementSortTransactions($transactionsDisplayOrder, 'asc');
    $runningDebtIQD = 0;
    $runningDebtUSD = 0;
    $withRunningByKey = [];

    foreach ($chronoAsc as $trans) {
        $currency = $trans['currency'] ?? 'IQD';
        $amount = (float)($trans['amount'] ?? 0);

        if (($trans['type'] ?? '') === 'credit') {
            if ($currency === 'USD') {
                $runningDebtUSD += $amount;
            } else {
                $runningDebtIQD += $amount;
            }
        } elseif (in_array(($trans['type'] ?? ''), ['payment', 'return_adjustment'], true)) {
            if ($currency === 'USD') {
                $runningDebtUSD -= $amount;
            } else {
                $runningDebtIQD -= $amount;
            }
        }

        $trans['running_debt'] = ($currency === 'USD') ? $runningDebtUSD : $runningDebtIQD;
        $withRunningByKey[statementTransactionSortKey($trans)] = $trans;
    }

    $out = [];
    foreach ($transactionsDisplayOrder as $trans) {
        $k = statementTransactionSortKey($trans);
        if (isset($withRunningByKey[$k])) {
            $out[] = $withRunningByKey[$k];
        }
    }
    return $out;
}

/**
 * کلیلی مامەڵەی کاتژمێردا دوایین بۆ گونجاندنی «قەرزی ماوە» لەگەڵ کۆی debts.remaining.
 * ئەگەر دوایین لە allTransactionsFull لە فلتەری جۆردا نەبێت، دوایین لە لیستی پیشاندان ($transactions).
 */
function accountStatementLedgerAnchorKey(array $allTransactionsFull, array $transactionsFiltered): ?string {
    if ($allTransactionsFull === []) {
        return null;
    }
    $chronoAll = statementSortTransactions($allTransactionsFull, 'asc');
    $lastFull = end($chronoAll);
    if (!is_array($lastFull)) {
        return null;
    }
    $keyFull = statementTransactionSortKey($lastFull);

    $filteredKeys = [];
    foreach ($transactionsFiltered as $t) {
        $filteredKeys[statementTransactionSortKey($t)] = true;
    }
    if (isset($filteredKeys[$keyFull])) {
        return $keyFull;
    }
    if ($transactionsFiltered === []) {
        return null;
    }
    $chronoF = statementSortTransactions($transactionsFiltered, 'asc');
    $lastF = end($chronoF);
    return is_array($lastF) ? statementTransactionSortKey($lastF) : null;
}

/** ستونی قەرزی ڕاکێشراو لە ڕیزی ئەنجامدا لەگەڵ $totalDebtIQD / $totalDebtUSD (هەمان سەرچاوەی پوختە) */
function accountStatementSyncLastRowRunningDebt(array &$rowsWithRunning, ?string $anchorKey, float $totalDebtIQD, float $totalDebtUSD): void {
    if ($anchorKey === null || $rowsWithRunning === []) {
        return;
    }
    foreach ($rowsWithRunning as &$row) {
        if (statementTransactionSortKey($row) === $anchorKey) {
            $cur = $row['currency'] ?? 'IQD';
            $row['running_debt'] = ($cur === 'USD') ? $totalDebtUSD : $totalDebtIQD;
            break;
        }
    }
    unset($row);
}

$transactionsWithRunningDebtFull = buildRunningDebtTransactions($allTransactionsFull);
$ledgerAnchorKey = accountStatementLedgerAnchorKey($allTransactionsFull, $transactions);
accountStatementSyncLastRowRunningDebt($transactionsWithRunningDebtFull, $ledgerAnchorKey, $totalDebtIQD, $totalDebtUSD);
$typeFilterMapDisplay = [
    'credit' => $showCredit,
    'payment' => $showPayment,
    'return_adjustment' => $showReturn,
];
$transactionsWithRunningDebt = array_values(array_filter($transactionsWithRunningDebtFull, function ($t) use ($typeFilterMapDisplay) {
    $ty = $t['type'] ?? 'payment';
    return !empty($typeFilterMapDisplay[$ty]);
}));
foreach ($transactionsWithRunningDebt as &$txn) {
    if (($txn['type'] ?? '') === 'return_adjustment' && !empty($txn['notes'])
        && preg_match('/RET-\d{8}-\d+/u', $txn['notes'], $rm)) {
        $txn['return_number'] = $rm[0];
    }
}
unset($txn);

$totalCreditText = formatMixedCurrency($totalCreditIQD, $totalCreditUSD);
$totalPaidText = formatMixedCurrency($totalPaidIQD, $totalPaidUSD);
$totalDebtText = formatMixedCurrency($totalDebtIQD, $totalDebtUSD);

$pageTitle = 'کەشف حسابی - کڕیاران';
$bodyClass = 'customers-module-page customers-statement-page customers-page';
$additionalCSS = ['customers/customers-pages.css', 'customers/customers-dark.css', 'customers/customers-responsive.css'];
include '../../includes/header.php';
?>

<div class="container-fluid statement-customer-page customers-page-content cu-wrap print-paper-<?php echo strtolower($paperSize); ?>">
    <header class="cu-hero d-print-none">
        <div>
            <div class="cu-kicker"><i class="bi bi-file-earmark-text"></i> کڕیاران</div>
            <h1><i class="bi bi-journal-richtext"></i> کەشف حسابی</h1>
            <p class="cu-hero-sub">قەرز، پارەدان و گەڕاوەی کڕیار لە یەک ڕاپۆرتدا ببینە و چاپ بکە</p>
        </div>
        <div class="cu-actions">
            <a class="cu-btn cu-btn-ghost" href="<?php echo url('user/customers/main.php'); ?>">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
        </div>
    </header>

    <div class="cu-panel mb-4 statement-filter-card customer-filter-card">
        <div class="cu-panel-head"><i class="bi bi-funnel"></i> فلتەرەکان</div>
        <div class="cu-panel-body">
            <form method="GET" class="row g-3 align-items-end statement-filter-form" id="filterForm">
                <div class="col-md-3 col-xl-2 statement-customer-picker">
                    <label for="customer_id" class="form-label">کڕیار <span class="text-danger">*</span></label>
                    <div class="customer-combobox">
                        <i class="bi bi-search customer-search-icon"></i>
                        <input type="text"
                               id="customerSearchInput"
                               class="form-control customer-search-input"
                               placeholder="بە ناو یان تەلەفۆن گەڕان بکە..."
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                        <div id="customerDropdown" class="customer-dropdown" role="listbox" aria-label="لیستی کڕیاران"></div>
                    </div>
                    <select class="form-select d-none" id="customer_id" name="customer_id" required>
                        <option value="">-- کڕیار هەڵبژێرە --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>"
                                    data-name="<?php echo htmlspecialchars((string)($customer['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-phone="<?php echo htmlspecialchars((string)($customer['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $customerId == $customer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?>
                                <?php if (!empty($customer['phone'])): ?>
                                    - <?php echo htmlspecialchars($customer['phone']); ?>
                                <?php endif; ?>
                                <?php if ($customer['total_debt'] > 0): ?>
                                    (قەرز: <?php echo number_format($customer['total_debt'], 0); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">لە ڕۆژی</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">بۆ ڕۆژی</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-2 col-xl-1">
                    <label for="paper" class="form-label">قەبارەی چاپ</label>
                    <select class="form-select" id="paper" name="paper">
                        <option value="A4" <?php echo $paperSize === 'A4' ? 'selected' : ''; ?>>A4</option>
                        <option value="A5" <?php echo $paperSize === 'A5' ? 'selected' : ''; ?>>A5</option>
                    </select>
                </div>
                <div class="col-md-2 col-xl-1">
                    <label for="txn_order" class="form-label">ڕیزبەندی مامەڵە</label>
                    <select class="form-select" id="txn_order" name="txn_order">
                        <option value="desc" <?php echo $txnSortOrder === 'desc' ? 'selected' : ''; ?>>تازە → کۆن</option>
                        <option value="asc" <?php echo $txnSortOrder === 'asc' ? 'selected' : ''; ?>>کۆن → تازە</option>
                    </select>
                </div>
                <div class="col-md-2 col-xl-1 d-print-none">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="cu-btn cu-btn-primary w-100">
                            <i class="bi bi-search me-1"></i>گەڕان
                        </button>
                    </div>
                </div>
                <div class="col-12 col-xl-4 mt-xl-0 mt-2">
                    <div class="statement-type-filters rounded-3 border px-3 py-2 py-xl-3 bg-light h-100">
                        <div class="fw-semibold small text-secondary mb-2">جۆری مامەڵەکان</div>
                        <div class="d-flex flex-wrap align-items-center gap-4">
                            <input type="hidden" name="show_credit" value="0" />
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="show_credit" value="1" id="show_credit"
                                    <?php echo $showCredit ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="show_credit">قەرز</label>
                            </div>
                            <input type="hidden" name="show_payment" value="0" />
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="show_payment" value="1" id="show_payment"
                                    <?php echo $showPayment ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="show_payment">پارەدان</label>
                            </div>
                            <input type="hidden" name="show_return" value="0" />
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="show_return" value="1" id="show_return"
                                    <?php echo $showReturn ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="show_return">گەڕاوە</label>
                            </div>
                            <input type="hidden" name="show_credit_items" value="0" />
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="show_credit_items" value="1" id="show_credit_items"
                                    <?php echo $statementShowCreditItemDetails ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="show_credit_items">زانیاری کاڵاکانی قەرز</label>
                            </div>
                            <input type="hidden" name="show_return_items" value="0" />
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="show_return_items" value="1" id="show_return_items"
                                    <?php echo $statementShowReturnItemDetails ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="show_return_items">زانیاری کاڵاکانی گەڕاوە</label>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($customerId > 0 && $customerInfo): ?>
        <div class="statement-top-print-band">

        <!-- زانیاری کڕیار -->
        <div class="card mb-3 print-section customer-info statement-card-plain">
            <div class="card-header d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
                <h5 class="card-title mb-0 fs-6">کڕیار</h5>
                <div class="d-flex flex-wrap gap-2 no-print">
                    <button type="button" class="btn btn-primary btn-sm statement-print1-btn" onclick="printStatement1()" title="پوختەی کڕیار لە یەک لاپەڕەدا">
                        <i class="bi bi-printer-fill me-1"></i>چاپکردنی ١
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="printStatement()">
                        <i class="bi bi-printer me-1"></i>چاپی 2
                    </button>
                </div>
            </div>
            <div class="card-body py-3">
                <div class="row g-3 align-items-center">
                    <div class="col-sm-6 col-lg-4">
                        <div class="text-muted small mb-0">ناو</div>
                        <div class="fw-semibold fs-6"><?php echo htmlspecialchars($customerInfo['name']); ?></div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="text-muted small mb-0">تەلەفۆن</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($customerInfo['phone'] ?? '—'); ?></div>
                    </div>
                    <div class="col-lg-5 d-print-none">
                        <div class="text-muted small mb-0">قەرزی ئێستا</div>
                        <div class="text-danger fw-bold fs-5"><?php echo $totalDebtText; ?></div>
                    </div>
                    <?php if (!empty(trim((string)($customerInfo['address'] ?? '')))): ?>
                    <div class="col-12 d-none d-print-block small text-muted">
                        <span class="text-muted">ناونیشان:</span>
                        <?php echo htmlspecialchars($customerInfo['address']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ئامارەکان -->
        <div class="row mb-3 g-2 stats-row print-section">
            <div class="col-md-4">
                <div class="card border-0 bg-light statement-stat-tile h-100">
                    <div class="card-body py-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-cart-check text-warning statement-stat-icon"></i>
                            <div>
                                <div class="fw-bold fs-6"><?php echo $totalCreditText; ?></div>
                                <div class="text-muted small mb-0">کڕین بە قەرز</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 bg-light statement-stat-tile h-100">
                    <div class="card-body py-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-arrow-down-circle text-success statement-stat-icon"></i>
                            <div>
                                <div class="fw-bold fs-6"><?php echo $totalPaidText; ?></div>
                                <div class="text-muted small mb-0">پارەدان و گەڕاوە</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 bg-light statement-stat-tile h-100">
                    <div class="card-body py-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-wallet2 text-danger statement-stat-icon"></i>
                            <div>
                                <div class="fw-bold fs-6"><?php echo $totalDebtText; ?></div>
                                <div class="text-muted small mb-0">قەرزی ماوە</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div><!-- /.statement-top-print-band -->

        <!-- خشتەی وەسڵەکان -->
        <div class="card print-section print-section-statement statement-card-plain" id="statementTable">
            <div class="card-body">
                <?php if (empty($transactions)): ?>
                    <div class="cu-empty">
                        <div class="cu-empty-icon"><i class="bi bi-inbox"></i></div>
                        <h3>هیچ وەسڵێک نەدۆزرایەوە</h3>
                        <p>لەگەڵ فلتەرەکانی هەڵبژاردوو هیچ وەسڵێک نەدۆزرایەوە</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle" id="transactionsTable">
                            <thead class="table-light statement-history-head">
                                <tr>
                                    <th class="text-muted fw-normal">#</th>
                                    <th class="text-muted fw-normal">جۆری پارەدان</th>
                                    <th class="text-muted fw-normal">بەروار</th>
                                    <th class="text-muted fw-normal statement-col-product">کاڵا</th>
                                    <th class="text-end text-muted fw-normal">بڕ</th>
                                    <th class="text-end text-muted fw-normal">نرخ</th>
                                    <th class="text-end text-muted fw-normal">کۆ</th>
                                    <th class="text-end text-muted fw-normal">قەرزی ماوە</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                include __DIR__ . '/account_statement_rows_partial.php';
                                ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($customerId > 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>کڕیار نەدۆزرایەوە
        </div>
    <?php else: ?>
        <div class="alert alert-info cu-panel cu-panel-body">
            <i class="bi bi-info-circle me-2"></i>تکایە کڕیارێک هەڵبژێرە بۆ بینینی کەشف حسابی
        </div>
    <?php endif; ?>

    <?php if ($customerId > 0 && $customerInfo): ?>
    <div id="statementPrint1Root" class="statement-print1-root" aria-hidden="true">
        <div class="statement-print1-sheet">
            <header class="statement-print1-hero">
                <div class="statement-print1-hero__glow"></div>
                <div class="statement-print1-hero__inner">
                    <div class="statement-print1-hero__brand">
                        <span class="statement-print1-hero__logo"><i class="bi bi-shop-window"></i></span>
                        <div>
                            <div class="statement-print1-hero__site"><?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="statement-print1-hero__tag">پوختەی هەژماری کڕیار</div>
                        </div>
                    </div>
                    <div class="statement-print1-hero__meta">
                        <span class="statement-print1-pill"><i class="bi bi-file-earmark-person me-1"></i><?php echo strtoupper(htmlspecialchars($paperSize, ENT_QUOTES, 'UTF-8')); ?></span>
                        <span class="statement-print1-pill"><i class="bi bi-calendar3 me-1"></i><?php echo date('Y/m/d H:i'); ?></span>
                    </div>
                </div>
            </header>

            <section class="statement-print1-identity">
                <div class="statement-print1-avatar" aria-hidden="true"><i class="bi bi-person-circle"></i></div>
                <div class="statement-print1-identity__text">
                    <h1 class="statement-print1-name"><?php echo htmlspecialchars($customerInfo['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div class="statement-print1-phone">
                        <i class="bi bi-telephone-outbound"></i>
                        <span><?php echo htmlspecialchars($customerInfo['phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="statement-print1-daterange" role="note">
                        <div class="statement-print1-daterange__inner">
                            <span class="statement-print1-daterange__leading">
                                <i class="bi bi-calendar-range" aria-hidden="true"></i>
                                <span>تێبینی بەروار</span>
                            </span>
                            <span class="statement-print1-daterange__rule" aria-hidden="true"></span>
                            <span class="statement-print1-daterange__chunk">
                                <span class="statement-print1-daterange__label">لە بەرواری:</span>
                                <span class="statement-print1-daterange__value"><?php echo !empty($dateFrom) ? htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
                            </span>
                            <span class="statement-print1-daterange__dot" aria-hidden="true">·</span>
                            <span class="statement-print1-daterange__chunk">
                                <span class="statement-print1-daterange__label">بۆ بەرواری:</span>
                                <span class="statement-print1-daterange__value"><?php echo !empty($dateTo) ? htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <div class="statement-print1-grid">
                <article class="statement-print1-tile statement-print1-tile--credit">
                    <div class="statement-print1-tile__icon"><i class="bi bi-cart-check"></i></div>
                    <div class="statement-print1-tile__body">
                        <div class="statement-print1-tile__label">کڕین بە قەرز</div>
                        <div class="statement-print1-tile__value"><?php echo htmlspecialchars($totalCreditText, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </article>
                <article class="statement-print1-tile statement-print1-tile--pay">
                    <div class="statement-print1-tile__icon"><i class="bi bi-cash-coin"></i></div>
                    <div class="statement-print1-tile__body">
                        <div class="statement-print1-tile__label">پارەدان و گەڕاوە</div>
                        <div class="statement-print1-tile__value"><?php echo htmlspecialchars($totalPaidText, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </article>
                <article class="statement-print1-tile statement-print1-tile--debt">
                    <div class="statement-print1-tile__icon"><i class="bi bi-wallet2"></i></div>
                    <div class="statement-print1-tile__body">
                        <div class="statement-print1-tile__label">قەرزی ماوە</div>
                        <div class="statement-print1-tile__value statement-print1-tile__value--accent"><?php echo htmlspecialchars($totalDebtText, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </article>
            </div>

            <footer class="statement-print1-foot">
                <span dir="ltr" class="statement-print1-foot__latin">NexoraCore.com</span>
            </footer>
        </div>
    </div>
    <?php endif; ?>

    <!-- فووتەری چاپ: لەسەر هەر لاپەڕەیەک (position: fixed لە چاپدا) -->
    <div class="print-only statement-print-running-footer" aria-hidden="true">
        <span class="statement-print-running-footer__seg">NexoraCore&nbsp;&nbsp;<span dir="ltr" class="statement-print-running-footer__latin">NexoraCore.com</span></span>
    </div>
</div>

<!-- Print Styles -->
<style id="printStyles" media="print">
    @page {
        size: <?php echo strtolower($paperSize) === 'a5' ? 'A5' : 'A4'; ?> portrait;
        /*
         * مارجینی خوارەوە: فووتەر position:fixed لە چاپدا لە هەندێک وێبگەڕ لەگەڵ کۆتا ڕیزەکانی خشتە تێکەڵ دەبێتەوە
         * ئەگەر کەم بێت — بەڵگە: ~٢٤–٢٦mm بۆ ناوچەی فووتەر + کەمێک بۆشایی.
         */
        margin: <?php echo strtolower($paperSize) === 'a5' ? '8mm 8mm 26mm 8mm' : '8mm 8mm 24mm 8mm'; ?>;
    }

    @media print {
        body {
            background: white !important;
            font-size: 10pt;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* لە چاپدا تێمەی تاریک لاببرێت */
        html[data-bs-theme='dark'] body,
        html[data-bs-theme='dark'] .statement-customer-page,
        html[data-bs-theme='dark'] .card,
        html[data-bs-theme='dark'] .card-header,
        html[data-bs-theme='dark'] .card-body,
        html[data-bs-theme='dark'] .table,
        html[data-bs-theme='dark'] .table th,
        html[data-bs-theme='dark'] .table td,
        html[data-bs-theme='dark'] .statement-print1-sheet,
        html[data-bs-theme='dark'] .statement-print1-tile {
            background: #ffffff !important;
            color: #000000 !important;
            border-color: #d1d5db !important;
            box-shadow: none !important;
        }

        /* بۆشایی کۆتایی دۆکیومێنت (تەنها کۆتا لاپەڕە) — یارمەتیدەر لەگەڵ تێکەڵبوونی کۆتا خشتە */
        .statement-customer-page {
            padding-bottom: 8mm !important;
            box-sizing: border-box;
        }
        
        /* هەموو بەشەکانی ناپێویست دەشارینەوە */
        .navbar,
        .breadcrumb,
        .page-title-box,
        .btn,
        .alert,
        form.card {
            display: none !important;
        }

        .statement-customer-page .statement-filter-card {
            display: block !important;
            page-break-inside: avoid;
            margin-bottom: 4px !important;
            font-size: 8pt !important;
            box-shadow: none !important;
        }

        .statement-customer-page .statement-filter-card .card-header {
            padding: 3px 6px !important;
            border-bottom: 1px solid #ccc !important;
        }

        .statement-customer-page .statement-filter-card .card-header .card-title {
            font-size: 8pt !important;
            margin: 0 !important;
            font-weight: 600 !important;
        }

        .statement-customer-page .statement-filter-card .card-header .bi-funnel {
            font-size: 0.85em;
        }

        .statement-customer-page .statement-filter-card .card-body {
            padding: 4px 6px !important;
        }

        .statement-customer-page .statement-filter-card #filterForm.statement-filter-form {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap !important;
            align-items: flex-end !important;
            row-gap: 4px !important;
            gap: 6px !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
            --bs-gutter-x: 0;
            --bs-gutter-y: 0;
        }

        .statement-customer-page .statement-filter-card #filterForm > [class*="col-"] {
            flex: 0 1 auto !important;
            width: auto !important;
            max-width: none !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            margin-top: 0 !important;
        }

        /* جۆری مامەڵەکان: لە چاپدا لە ڕیزی خوارتر (تەنها ئەم ستوونە) */
        .statement-customer-page .statement-filter-card #filterForm > div:has(.statement-type-filters) {
            flex: 1 1 100% !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            margin-top: 2px !important;
        }

        .statement-customer-page .statement-filter-card .form-label {
            font-size: 7pt !important;
            margin-bottom: 0 !important;
            line-height: 1.1 !important;
            white-space: nowrap;
        }

        .statement-customer-page .statement-filter-card .form-select,
        .statement-customer-page .statement-filter-card .form-control {
            font-size: 8pt !important;
            padding: 1px 4px !important;
            min-height: 1.6rem !important;
            height: 1.6rem !important;
        }

        .statement-customer-page .statement-filter-card #customer_id {
            max-width: 11rem;
            min-width: 6rem;
        }

        .statement-customer-page .statement-filter-card .statement-type-filters {
            padding: 3px 6px 4px !important;
            margin-top: 0 !important;
        }

        .statement-customer-page .statement-filter-card .statement-type-filters > .fw-semibold {
            font-size: 7.5pt !important;
            margin-bottom: 2px !important;
            line-height: 1.15 !important;
        }

        .statement-customer-page .statement-filter-card .statement-type-filters .d-flex {
            flex-wrap: wrap !important;
            gap: 0.5rem 0.65rem !important;
        }

        .statement-customer-page .statement-filter-card .form-check-label {
            font-size: 7.5pt !important;
        }

        .statement-customer-page .statement-filter-card .form-check-input {
            width: 0.85em !important;
            height: 0.85em !important;
            margin-top: 0.1em !important;
        }

        .statement-customer-page .statement-filter-card .d-grid {
            display: block !important;
        }

        .statement-customer-page.print-paper-a5 .statement-filter-card #filterForm.statement-filter-form {
            flex-wrap: wrap !important;
            row-gap: 4px !important;
        }

        .statement-customer-page.print-paper-a5 .statement-filter-card #customer_id {
            max-width: 9rem;
        }

        /*
         * A4: لەسەر لاپەڕەی یەکەم زۆرتر ڕیز لە خشتە دەچێتە خوارەوە → مارجینی خوارەوە گەورەتر + table-responsive
         *   نابێت overflow بگرێتەوە (بەپێچەوانەی A5 کە پانتایی تەسکترە زۆرجار کێشە کەمترە).
         */
        .statement-customer-page.print-paper-a4 #statementTable .table-responsive,
        .statement-customer-page.print-paper-a4 .table-responsive {
            overflow: visible !important;
            max-width: 100% !important;
        }

        .statement-customer-page.print-paper-a4 .statement-print-running-footer {
            max-width: calc(100% - 16mm);
        }

        .statement-customer-page.print-paper-a5 .statement-print-running-footer {
            max-width: calc(100% - 16mm);
        }

        /* تەنها بەشەکانی چاپکردن نیشان بدە */
        .print-section {
            display: block !important;
            page-break-inside: avoid;
            margin-bottom: 5px !important;
        }
        
        .print-section.print-section-statement {
            page-break-inside: auto !important;
        }
        
        .print-only {
            display: block !important;
        }
        
        .print-document-header {
            page-break-inside: avoid;
        }

        .statement-top-print-band {
            display: flex !important;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 6px;
            align-items: stretch;
            margin-bottom: 4px !important;
            page-break-inside: avoid;
        }

        .statement-top-print-band > .print-document-header,
        .statement-top-print-band > .customer-info,
        .statement-top-print-band > .stats-row {
            flex: 1 1 28%;
            min-width: 0;
            margin-bottom: 0 !important;
        }

        .statement-top-print-band .print-section {
            margin-bottom: 0 !important;
        }

        .statement-top-print-band > .print-document-header {
            page-break-inside: avoid;
        }

        .statement-top-print-band > .customer-info,
        .statement-top-print-band > .stats-row {
            page-break-inside: avoid;
        }

        .print-header-compact {
            text-align: right !important;
            border-bottom-width: 1px !important;
            padding-bottom: 4px !important;
            margin-bottom: 0 !important;
        }

        .print-header-line1 .print-header-business {
            font-size: 11pt !important;
        }

        .print-header-line1 .print-header-customer {
            max-width: 38%;
        }

        .print-header-line2 {
            font-size: 8.5pt !important;
        }

        .statement-top-print-band > .print-document-header.mb-3 {
            margin-bottom: 0 !important;
        }

        .statement-top-print-band .card-body {
            padding: 5px 6px !important;
        }

        .statement-top-print-band .customer-info .row {
            flex-wrap: wrap !important;
            --bs-gutter-x: 0.5rem;
            --bs-gutter-y: 0.25rem;
        }

        .statement-top-print-band .customer-info .fw-semibold.fs-6 {
            font-size: 0.95rem !important;
        }

        .statement-top-print-band .stats-row {
            display: flex !important;
            flex-wrap: nowrap !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
        }

        .statement-top-print-band .stats-row > [class*="col-"] {
            flex: 1 1 0 !important;
            max-width: none !important;
            width: auto !important;
            padding-left: calc(var(--bs-gutter-x, 0.5rem) * 0.5) !important;
            padding-right: calc(var(--bs-gutter-x, 0.5rem) * 0.5) !important;
        }

        .statement-top-print-band .statement-stat-icon {
            display: inline-block !important;
            font-size: 1.05rem;
            line-height: 1;
            vertical-align: middle;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .statement-top-print-band .statement-stat-tile .fw-bold.fs-6 {
            font-size: 0.9rem !important;
        }

        .statement-top-print-band .statement-stat-tile .small {
            font-size: 7.5pt !important;
        }

        .statement-top-print-band .statement-stat-tile .card-body {
            padding: 4px 5px !important;
        }
        
        /* دوگمەکان لە چاپدا دەشارینەوە */
        .card-header .btn {
            display: none !important;
        }
        
        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
            page-break-inside: avoid;
        }
        
        #statementTable.card {
            page-break-inside: auto !important;
        }
        
        .card-header {
            background-color: #f8f9fa !important;
            border-bottom: 2px solid #dee2e6 !important;
            padding: 10px !important;
        }

        .statement-customer-page .card-header {
            padding: 7px 8px !important;
        }

        .statement-top-print-band .card-header {
            padding: 5px 6px !important;
        }
        
        .card-body {
            padding: 15px !important;
        }

        .statement-customer-page .card-body {
            padding: 7px 8px !important;
        }

        .statement-top-print-band .card-body {
            padding: 5px 6px !important;
        }
        
        .table {
            border-collapse: collapse !important;
            width: 100% !important;
            font-size: 9pt !important;
        }
        
        .table th, .table td {
            border: 1px solid #ddd !important;
            padding: 6px !important;
            text-align: right !important;
        }

        .statement-customer-page .table th,
        .statement-customer-page .table td {
            padding: 4px 5px !important;
        }
        
        .table thead th {
            background-color: #f3f4f6 !important;
            color: #374151 !important;
            font-weight: bold !important;
        }

        #transactionsTable .statement-history-head th {
            background-color: #dceafe !important;
            color: #1e3a8a !important;
            border-color: #b6ccf6 !important;
        }

        #statementTable > .card-body:has(> .table-responsive) {
            padding-top: 0 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            padding-bottom: 0 !important;
        }

        #statementTable #transactionsTable > thead tr > th:first-child,
        #statementTable #transactionsTable > tbody tr > td:first-child {
            border-left: none !important;
        }

        #statementTable #transactionsTable > thead tr > th:last-child,
        #statementTable #transactionsTable > tbody tr > td:last-child {
            border-right: none !important;
        }

        .statement-items-table thead th {
            background-color: #f3f4f6 !important;
            color: #374151 !important;
            border-color: #d1d5db !important;
        }

        .statement-items-table--continued tbody tr:first-child td {
            border-top: 1px solid #9ca3af !important;
        }

        .statement-items-nested--continued td.bg-light-subtle {
            padding-top: 0.3rem !important;
        }
        
        .table tbody tr {
            page-break-inside: avoid;
        }
        
        #statementTable .table tbody tr {
            page-break-inside: auto !important;
        }
        
        /* نیشاندانی زانیاری کڕیار */
        .customer-info {
            page-break-inside: avoid;
            margin-bottom: 20px;
        }

        .statement-top-print-band .customer-info {
            margin-bottom: 0 !important;
        }
        
        /* نیشاندانی ئامارەکان */
        .stats-row {
            page-break-inside: avoid;
            margin-bottom: 20px;
        }

        .statement-top-print-band .stats-row {
            margin-bottom: 0 !important;
        }
        
        .stats-row .card {
            border: 1px solid #ddd !important;
        }

        /* فووتەر: تەنها دەق — بێ پاشبنەما/چوارچێوە لە پشت دەق (مارجینی @page ناوەڕۆک لە ژێرەوە هەڵدەگرێت) */
        .statement-print-running-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 3mm;
            z-index: 9999;
            display: flex !important;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.35rem 0.55rem;
            width: max-content;
            max-width: calc(100% - 16mm);
            margin: 0 auto;
            padding: 0;
            box-sizing: border-box;
            font-size: 7.5pt;
            line-height: 1.2;
            letter-spacing: 0.01em;
            color: #555 !important;
            background: transparent !important;
            border: none !important;
            border-radius: 0;
            box-shadow: none !important;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .statement-print-running-footer__seg {
            white-space: nowrap;
        }

        /* چاپکردنی ١: تەنها پوختەی کڕیار */
        #statementPrint1Root {
            display: none !important;
        }

        body.statement-print1-active .statement-filter-card,
        body.statement-print1-active .statement-top-print-band,
        body.statement-print1-active .print-section-statement,
        body.statement-print1-active .statement-print-running-footer,
        body.statement-print1-active .page-title-box,
        body.statement-print1-active .breadcrumb,
        body.statement-print1-active .navbar,
        body.statement-print1-active .alert,
        body.statement-print1-active .statement-customer-page > .row:first-of-type {
            display: none !important;
        }

        body.statement-print1-active .statement-customer-page {
            padding: 0 !important;
            max-width: 100% !important;
        }

        body.statement-print1-active #statementPrint1Root {
            display: block !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        body.statement-print1-active #statementPrint1Root .statement-print1-sheet {
            min-height: 0 !important;
            box-shadow: none !important;
            border: none !important;
        }
    }
.statement-filter-card {
    position: relative;
    z-index: 30;
    overflow: visible;
}

.statement-filter-card .card-body {
    overflow: visible;
}

.statement-customer-picker .form-label {
    font-weight: 700;
}

.customer-combobox {
    position: relative;
    z-index: 40;
}

.customer-combobox .customer-search-input {
    border-radius: 0.8rem;
    padding: 0.65rem 2.4rem 0.65rem 0.9rem;
    min-height: 44px;
    border: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.customer-combobox .customer-search-input:focus {
    border-color: rgba(var(--bs-primary-rgb), 0.55);
    background: var(--bs-body-bg);
    box-shadow: 0 0 0 0.2rem rgba(var(--bs-primary-rgb), 0.15);
}

.customer-combobox .customer-search-icon {
    position: absolute;
    top: 50%;
    right: 12px;
    transform: translateY(-50%);
    color: var(--bs-secondary-color);
    pointer-events: none;
    z-index: 2;
}

.customer-combobox .customer-dropdown {
    position: absolute !important;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    width: 100%;
    min-width: 100%;
    z-index: 1080;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.75rem;
    background: var(--bs-body-bg);
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.14);
    max-height: 240px;
    overflow-y: auto;
    overflow-x: hidden;
    display: none;
    direction: rtl;
    text-align: right;
}

.customer-combobox .customer-dropdown.show {
    display: block;
}

.customer-combobox .customer-option {
    cursor: pointer;
    padding: 0.62rem 0.85rem;
    border-bottom: 1px solid var(--bs-border-color-translucent);
    line-height: 1.35;
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
    white-space: normal;
    overflow-wrap: anywhere;
}

.customer-combobox .customer-option .meta {
    display: block;
    margin-top: 0.12rem;
    font-size: 0.83rem;
    color: var(--bs-secondary-color);
    white-space: normal;
    overflow-wrap: anywhere;
}

html[data-bs-theme='dark'] .customer-combobox .customer-dropdown {
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.45);
}

</style>

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

.statement-customer-page .card {
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    border: 1px solid #e8eaed;
}

.statement-customer-page .statement-stat-tile {
    border: 1px solid #eef0f2 !important;
}

.statement-stat-icon {
    font-size: 1.35rem;
    opacity: 0.9;
}

.statement-type-filters .form-check-input {
    width: 1.15em;
    height: 1.15em;
    margin-top: 0.15em;
}

.statement-type-filters .form-check-label {
    cursor: pointer;
    user-select: none;
}

.table th {
    border-top: none;
    font-weight: 600;
    color: #374151;
    background-color: #f3f4f6;
    white-space: nowrap;
}

/* سەرەوەی خشتەی مێژوو: هێڵی بێ ناوەڕۆک لەسەر ستوونەکان نەردەبێتەوە */
#transactionsTable thead.statement-history-head th {
    border-top: 1px solid var(--bs-border-color, #dee2e6);
}

#transactionsTable .statement-history-head th {
    background-color: #dceafe;
    color: #1e3a8a;
    border-color: #b6ccf6;
}

#statementTable > .card-body:has(> .table-responsive) {
    padding-top: 0;
    padding-left: 0;
    padding-right: 0;
}

/* لای چەپ و ڕاست: تەنها چوارچێوەی کارت بمێنێتەوە (نە خشتەی ناو ڕیزەکان) */
#statementTable #transactionsTable > thead tr > th:first-child,
#statementTable #transactionsTable > tbody tr > td:first-child {
    border-left: none;
}

#statementTable #transactionsTable > thead tr > th:last-child,
#statementTable #transactionsTable > tbody tr > td:last-child {
    border-right: none;
}

.page-title {
    color: #495057;
    font-weight: 600;
}

.breadcrumb {
    background: none;
    padding: 0;
}

.breadcrumb-item + .breadcrumb-item::before {
    content: ">";
    color: #6c757d;
}

#transactionsTable {
    font-size: 0.88rem;
}

#transactionsTable tbody tr:hover {
    background-color: #fafbfc;
}

#transactionsTable .statement-col-product {
    min-width: 7.5rem;
    max-width: 14rem;
}

.statement-items-nested td {
    padding-top: 0.45rem;
    padding-bottom: 0.45rem;
}

.statement-items-table th {
    background: #f3f4f6;
    color: #374151;
    border-color: #d1d5db;
    white-space: nowrap;
    font-size: 0.8rem;
}

.statement-items-table td {
    font-size: 0.82rem;
}

.statement-items-table--continued tbody tr:first-child td {
    border-top: 1px solid #9ca3af;
}

.statement-items-nested--continued td.bg-light-subtle {
    padding-top: 0.3rem;
}

/* تەنها لەسەر شاشە بشارەوە — لە چاپدا نابێت none بێت و ستایلی media=print بباتەوە */
@media screen {
    .print-only {
        display: none !important;
    }

    #statementPrint1Root {
        display: none !important;
    }
}

/* پوختەی چاپکردنی ١ (شاشە + چاپ) */
.statement-print1-root {
    font-family: system-ui, 'Segoe UI', Tahoma, sans-serif;
    color: #0f172a;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.statement-print1-sheet {
    max-width: 820px;
    margin: 0 auto;
    padding: 1.65rem 1.4rem 2rem;
    border-radius: 1.35rem;
    background: linear-gradient(165deg, #f8fafc 0%, #eef2ff 42%, #fff 100%);
    border: 1px solid #e2e8f0;
    box-shadow: 0 20px 56px rgba(15, 23, 42, 0.09);
}

.statement-print1-hero {
    position: relative;
    overflow: hidden;
    border-radius: 1.05rem;
    margin-bottom: 1.55rem;
    background: linear-gradient(120deg, #1e3a8a 0%, #2563eb 42%, #7c3aed 100%);
    color: #fff;
}

.statement-print1-hero__glow {
    position: absolute;
    inset: -40% -20% auto auto;
    width: 70%;
    height: 140%;
    background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.35) 0%, transparent 55%);
    pointer-events: none;
}

.statement-print1-hero__inner {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1.1rem 1.25rem;
    padding: 1.2rem 1.35rem;
}

.statement-print1-hero__brand {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}

.statement-print1-hero__logo {
    width: 3.35rem;
    height: 3.35rem;
    border-radius: 0.9rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    background: rgba(255, 255, 255, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.35);
}

.statement-print1-hero__site {
    font-weight: 800;
    font-size: 1.12rem;
    letter-spacing: 0.02em;
}

.statement-print1-hero__tag {
    font-size: 0.85rem;
    opacity: 0.92;
    margin-top: 0.2rem;
}

.statement-print1-hero__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.statement-print1-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 600;
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.35);
    white-space: nowrap;
}

.statement-print1-identity {
    display: flex;
    align-items: flex-start;
    gap: 1.25rem;
    margin-bottom: 1.65rem;
    padding: 1.3rem 1.35rem 1.35rem;
    background: #fff;
    border-radius: 1.05rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.07);
}

.statement-print1-identity__text {
    flex: 1;
    min-width: 0;
}

.statement-print1-avatar {
    flex-shrink: 0;
    width: 4.65rem;
    height: 4.65rem;
    border-radius: 1.05rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.15rem;
    color: #4338ca;
    background: linear-gradient(145deg, #eef2ff, #e0e7ff);
    border: 2px solid #c7d2fe;
}

.statement-print1-name {
    font-size: 1.52rem;
    font-weight: 800;
    margin: 0 0 0.45rem;
    line-height: 1.25;
    color: #0f172a;
}

.statement-print1-phone {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1.05rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.15rem;
}

.statement-print1-phone i {
    color: #2563eb;
    font-size: 1.1rem;
}

.statement-print1-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12.5rem, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.statement-print1-tile {
    display: flex;
    align-items: flex-start;
    gap: 0.8rem;
    padding: 1.05rem 1.1rem;
    border-radius: 1rem;
    border: 1px solid #e2e8f0;
    background: #fff;
    min-height: 6.1rem;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
}

.statement-print1-tile__icon {
    flex-shrink: 0;
    width: 2.7rem;
    height: 2.7rem;
    border-radius: 0.72rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: #fff;
}

.statement-print1-tile--credit .statement-print1-tile__icon {
    background: linear-gradient(135deg, #ea580c, #f59e0b);
}

.statement-print1-tile--pay .statement-print1-tile__icon {
    background: linear-gradient(135deg, #059669, #10b981);
}

.statement-print1-tile--debt .statement-print1-tile__icon {
    background: linear-gradient(135deg, #b91c1c, #ef4444);
}

.statement-print1-tile__label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #64748b;
    text-transform: none;
    margin-bottom: 0.28rem;
}

.statement-print1-tile__value {
    font-size: 1.05rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.4;
    word-break: break-word;
}

.statement-print1-tile__value--accent {
    color: #b91c1c;
}

.statement-print1-daterange {
    margin-top: 1.05rem;
    padding: 0.95rem 1.15rem;
    border-radius: 0.95rem;
    background: linear-gradient(180deg, #f1f5f9 0%, #f8fafc 55%);
    border: 1px solid #e2e8f0;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
}

.statement-print1-daterange__inner {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: nowrap;
    gap: 0.65rem 1rem;
    font-size: 0.88rem;
    line-height: 1.45;
}

.statement-print1-daterange__leading {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    flex-shrink: 0;
    font-weight: 800;
    font-size: 0.8rem;
    color: #334155;
    letter-spacing: 0.02em;
    white-space: nowrap;
}

.statement-print1-daterange__leading i {
    font-size: 1.05rem;
    color: #2563eb;
}

.statement-print1-daterange__rule {
    flex-shrink: 0;
    width: 1px;
    height: 1.35rem;
    background: linear-gradient(180deg, transparent, #cbd5e1 20%, #cbd5e1 80%, transparent);
    opacity: 0.95;
}

.statement-print1-daterange__dot {
    flex-shrink: 0;
    color: #94a3b8;
    font-weight: 700;
    font-size: 1.1rem;
    line-height: 1;
    padding: 0 0.1rem;
}

.statement-print1-daterange__chunk {
    display: inline-flex;
    align-items: baseline;
    flex-wrap: nowrap;
    gap: 0.35rem;
    flex-shrink: 0;
}

.statement-print1-daterange__label {
    font-weight: 700;
    color: #64748b;
    font-size: 0.8rem;
    white-space: nowrap;
}

.statement-print1-daterange__value {
    font-weight: 700;
    color: #0f172a;
    font-size: 0.9rem;
    font-variant-numeric: tabular-nums;
    padding: 0.12rem 0.45rem;
    border-radius: 0.4rem;
    background: rgba(255, 255, 255, 0.85);
    border: 1px solid #e2e8f0;
}

.statement-print1-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    font-size: 0.74rem;
    color: #64748b;
    padding-top: 1rem;
    margin-top: 0.25rem;
}

.statement-print1-foot__sep {
    opacity: 0.5;
}

.statement-print1-foot__latin {
    font-weight: 600;
    color: #475569;
}

.statement-customer-page.print-paper-a5 .statement-print1-sheet {
    padding: 1.35rem 1.1rem 1.65rem;
    max-width: 100%;
}

.statement-customer-page.print-paper-a5 .statement-print1-name {
    font-size: 1.28rem;
}

.statement-customer-page.print-paper-a5 .statement-print1-avatar {
    width: 3.85rem;
    height: 3.85rem;
    font-size: 1.75rem;
}

.statement-customer-page.print-paper-a5 .statement-print1-daterange {
    padding: 0.75rem 0.85rem;
}

.statement-customer-page.print-paper-a5 .statement-print1-daterange__inner {
    font-size: 0.78rem;
    gap: 0.45rem 0.65rem;
}

.statement-customer-page.print-paper-a5 .statement-print1-daterange__value {
    font-size: 0.82rem;
    padding: 0.08rem 0.35rem;
}

.statement-customer-page.print-paper-a5 .statement-print1-daterange__leading {
    font-size: 0.74rem;
}

.statement-customer-page.print-paper-a5 .statement-print1-grid {
    gap: 0.75rem;
}

.statement-customer-page.print-paper-a5 .statement-print1-tile {
    padding: 0.85rem 0.9rem;
    min-height: 0;
}

@media print {
    .statement-print1-sheet {
        max-width: 100% !important;
        padding: 6mm 7mm 9mm !important;
        box-shadow: none !important;
        border: none !important;
        background: #fff !important;
    }

    .statement-print1-hero {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .statement-print1-identity,
    .statement-print1-grid {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .statement-print1-tile {
        min-height: 0 !important;
    }
}

.statement-type-pill {
    display: inline-block;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
}

.statement-type-payment {
    color: #198754;
}

.statement-type-return {
    color: #0d6efd;
}

.statement-invoice {
    font-weight: 600;
    color: #0d6efd;
}

</style>

<script>
function printStatement() {
    window.print();
}

function printStatement1() {
    document.body.classList.add('statement-print1-active');
    var cleanup = function() {
        document.body.classList.remove('statement-print1-active');
        window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);
    requestAnimationFrame(function() {
        window.print();
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const customerSearchInput = document.getElementById('customerSearchInput');
    const customerDropdown = document.getElementById('customerDropdown');
    const customerSelect = document.getElementById('customer_id');

    if (!customerSearchInput || !customerDropdown || !customerSelect) {
        return;
    }

    const allCustomerOption = customerSelect.querySelector('option[value=""]');
    const customerOptions = Array.from(customerSelect.options)
        .filter(option => option.value !== '')
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

    function getCustomerMeta(customer) {
        const metaParts = [];
        if (customer.phone) metaParts.push(customer.phone);
        const debtMatch = customer.label.match(/\(قەرز:[^)]+\)/);
        if (debtMatch) metaParts.push(debtMatch[0]);
        return metaParts.join(' - ');
    }

    function renderDropdown() {
        const noResultHtml = '<div class="customer-option"><span class="meta">هیچ کڕیارێک نەدۆزرایەوە</span></div>';
        const defaultOptionHtml = `
            <div class="customer-option ${customerSelect.value === '' ? 'active' : ''}" data-id="" role="option">
                <span class="name">${escapeHtml(allCustomerOption ? allCustomerOption.textContent.trim() : '-- کڕیار هەڵبژێرە --')}</span>
            </div>
        `;

        const customersHtml = visibleCustomers.map((customer, index) => `
            <div class="customer-option ${index === activeIndex ? 'active' : ''}" data-id="${customer.id}" role="option">
                <span class="name">${escapeHtml(customer.name || 'بێ ناو')}</span>
                <span class="meta">${escapeHtml(getCustomerMeta(customer))}</span>
            </div>
        `).join('');

        const shouldShowDefaultOption = currentQuery === '';
        customerDropdown.innerHTML = (shouldShowDefaultOption ? defaultOptionHtml : '') + (customersHtml || noResultHtml);
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
        if (customerId === '') {
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
            setSelectedCustomer('', true);
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

        const optionId = optionElement.dataset.id ?? '';
        setSelectedCustomer(optionId, optionId === '');
        closeDropdown();
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.customer-combobox')) {
            closeDropdown();
        }
    });

    if (customerSelect.value) {
        setSelectedCustomer(customerSelect.value);
    } else {
        customerSearchInput.value = '';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>

