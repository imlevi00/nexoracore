<?php
/**
 * مێژووی فرۆشتنەکان - user/pos/sales.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    redirect(url('user/auth/login.php'));
}
 
$currentUser = getCurrentUser();

$canCreateSaleReturn = authorize($currentUser, 'returns.create')['allowed'] ?? false;
$canViewSaleCost = authorize($currentUser, 'profits.view')['allowed'] ?? false;

// پارامەتری دەستکاریکردنی وەسڵ (لە لاپەڕەکانی دیکەوە)
$editSaleId = isset($_GET['edit_sale_id']) ? (int)$_GET['edit_sale_id'] : 0;

// فلتەرەکان
$defaultDateFrom = date('Y-m-01');
$defaultDateTo = date('Y-m-d');
$dateFromInput = trim((string)($_GET['date_from'] ?? ''));
$dateToInput = trim((string)($_GET['date_to'] ?? ''));
$paymentMethod = $_GET['payment_method'] ?? 'all';
$search = trim((string)($_GET['search'] ?? ''));
$itemSearch = trim((string)($_GET['item_search'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$hasTextSearch = $search !== '' || $itemSearch !== '';
$datesMarkedExplicit = isset($_GET['explicit_dates']) && (string)$_GET['explicit_dates'] === '1';
$datesAreExplicit = $datesMarkedExplicit
    || (
        ($dateFromInput !== '' || $dateToInput !== '')
        && !($dateFromInput === $defaultDateFrom && $dateToInput === $defaultDateTo)
    );

if ($hasTextSearch) {
    // گەڕان بە ژمارەی وەسڵ: تەنها کاتێک بەروار بە ڕوونی دیاری کرابێت لەو ماوەیەدا سنووردار بکرێت
    if ($datesAreExplicit) {
        $dateFrom = $dateFromInput;
        $dateTo = $dateToInput;
    } else {
        $dateFrom = '';
        $dateTo = '';
    }
} else {
    $dateFrom = $dateFromInput !== '' ? $dateFromInput : $defaultDateFrom;
    $dateTo = $dateToInput !== '' ? $dateToInput : $defaultDateTo;
}

$formDateFrom = $dateFromInput !== '' ? $dateFromInput : ($hasTextSearch ? '' : $defaultDateFrom);
$formDateTo = $dateToInput !== '' ? $dateToInput : ($hasTextSearch ? '' : $defaultDateTo);

// دروستکردنی query
$whereConditions = ["s.user_id = ?"];
$params = [$currentUser['id']];
$types = 'i';

// کارمەند (sub_user) کە دەسەڵاتی sales.view_all ـی نییە تەنها فرۆشتنی خۆی ببینێت
$ownOnlySubUserId = getSalesOwnOnlySubUserId($currentUser);
if ($ownOnlySubUserId !== null) {
    $whereConditions[] = "s.sub_user_id = ?";
    $params[] = $ownOnlySubUserId;
    $types .= 'i';
}

if ($dateFrom) {
    $whereConditions[] = "DATE(s.sale_date) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo) {
    $whereConditions[] = "DATE(s.sale_date) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

if ($paymentMethod && $paymentMethod !== 'all') {
    $whereConditions[] = "s.payment_method = ?";
    $params[] = $paymentMethod;
    $types .= 's';
}

if ($search !== '') {
    $searchId = ltrim($search, '#');
    if (ctype_digit($searchId)) {
        $whereConditions[] = "(s.id = ? OR s.invoice_number LIKE ? OR s.customer_name LIKE ?)";
        $params[] = (int)$searchId;
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $types .= 'iss';
    } else {
        $whereConditions[] = "(s.id LIKE ? OR s.invoice_number LIKE ? OR s.customer_name LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'sss';
    }
}

if ($itemSearch) {
    $whereConditions[] = "EXISTS (
        SELECT 1
        FROM sale_items si_filter
        LEFT JOIN products p_filter ON si_filter.product_id = p_filter.id
        WHERE si_filter.sale_id = s.id
          AND (
              COALESCE(p_filter.name, si_filter.product_name) LIKE ?
              OR p_filter.barcode LIKE ?
              OR si_filter.product_name LIKE ?
          )
    )";
    $itemSearchParam = '%' . $itemSearch . '%';
    $params[] = $itemSearchParam;
    $params[] = $itemSearchParam;
    $params[] = $itemSearchParam;
    $types .= 'sss';
}

$whereClause = implode(' AND ', $whereConditions);

// وەرگرتنی فرۆشتنەکان
$stmt = $conn->prepare("
    SELECT s.*,
           COALESCE(s.currency, 'IQD') as currency,
           DATE_FORMAT(s.sale_date, '%d/%m/%Y') as formatted_date,
           DATE_FORMAT(s.sale_date, '%H:%i:%s') as time,
           COUNT(si.id) as items_count,
           GROUP_CONCAT(
               DISTINCT CONCAT(
                   COALESCE(p.name, si.product_name),
                   '||',
                   COALESCE(p.barcode, '')
               )
               ORDER BY si.id SEPARATOR '، '
           ) AS item_names
    FROM sales s
    LEFT JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN products p ON si.product_id = p.id
    WHERE $whereClause
    GROUP BY s.id
    ORDER BY s.sale_date DESC
    LIMIT ? OFFSET ?
");

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt->bind_param($types, ...$params);
$stmt->execute();
$sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ژمارەی کۆی فرۆشتنەکان
$countStmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM sales s
    WHERE $whereClause
");

// Remove limit and offset params for count
array_pop($params);
array_pop($params);
$countTypes = substr($types, 0, -2);

$countStmt->bind_param($countTypes, ...$params);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);

// ئاماری ئەم ماوەیە
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_sales,
        IFNULL(SUM(final_amount), 0) as total_amount,
        IFNULL(AVG(final_amount), 0) as average_sale,
        IFNULL(SUM(CASE WHEN payment_method = 'cash' THEN final_amount ELSE 0 END), 0) as cash_sales,
        IFNULL(SUM(CASE WHEN payment_method = 'card' THEN final_amount ELSE 0 END), 0) as card_sales,
        IFNULL(SUM(CASE WHEN payment_method = 'debt' THEN final_amount ELSE 0 END), 0) as debt_sales
    FROM sales s
    WHERE $whereClause
");

$statsStmt->bind_param($countTypes, ...$params);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// حیساب کردنی کۆی گەڕانەوەکان بۆ هەمان ماوە
// Convert WHERE clause from sales table to returns table
$returnsWhereClause = str_replace(['s.', 'sale_date', 'invoice_number'], ['r.', 'return_date', 'return_number'], $whereClause);
$returnsStmt = $conn->prepare("
    SELECT 
        IFNULL(SUM(final_amount), 0) as total_returns
    FROM returns r
    WHERE " . $returnsWhereClause . "
");

$returnsStmt->bind_param($countTypes, ...$params);
$returnsStmt->execute();
$returnsData = $returnsStmt->get_result()->fetch_assoc();
$stats['total_returns'] = $returnsData['total_returns'];
$stats['net_amount'] = $stats['total_amount'] - $stats['total_returns'];

$statsTotalAmount = (float)($stats['total_amount'] ?? 0);
$statsCash = (float)($stats['cash_sales'] ?? 0);
$statsDebt = (float)($stats['debt_sales'] ?? 0);
$statsCard = (float)($stats['card_sales'] ?? 0);
$cashPct = $statsTotalAmount > 0 ? (int) round(($statsCash / $statsTotalAmount) * 100) : 0;
$debtPct = $statsTotalAmount > 0 ? (int) round(($statsDebt / $statsTotalAmount) * 100) : 0;
$cardPct = $statsTotalAmount > 0 ? (int) round(($statsCard / $statsTotalAmount) * 100) : 0;

if ($dateFrom && $dateTo) {
    $periodLabel = date('d/m/Y', strtotime($dateFrom)) . ' – ' . date('d/m/Y', strtotime($dateTo));
} else {
    $periodLabel = 'هەموو ماوەکان';
}

$todayYmd = date('Y-m-d');
$yesterdayYmd = date('Y-m-d', strtotime('-1 day'));
$weekAgoYmd = date('Y-m-d', strtotime('-7 days'));
$monthStartYmd = date('Y-m-01');
$quickRange = '';
if ($formDateFrom === $todayYmd && $formDateTo === $todayYmd) {
    $quickRange = 'today';
} elseif ($formDateFrom === $yesterdayYmd && $formDateTo === $yesterdayYmd) {
    $quickRange = 'yesterday';
} elseif ($formDateFrom === $weekAgoYmd && $formDateTo === $todayYmd) {
    $quickRange = 'week';
} elseif ($formDateFrom === $monthStartYmd && $formDateTo === $todayYmd) {
    $quickRange = 'month';
}

$filtersActive = $search !== ''
    || $itemSearch !== ''
    || ($paymentMethod !== 'all' && $paymentMethod !== '')
    || $datesMarkedExplicit
    || $formDateFrom !== $defaultDateFrom
    || $formDateTo !== $defaultDateTo;

$pageTitle = "مێژووی فرۆشتنەکان";
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="<?php echo url('assets/css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/sales-list.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/pos-responsive.css'); ?>" rel="stylesheet">
    <?php renderPremiumLockStylesheetLink(); ?>
    
    <style>
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

        .sale-item-names {
            margin-top: 0.15rem;
            font-size: 0.8rem;
            line-height: 1.2;
            white-space: normal;
            word-break: break-word;
        }

        .sale-item-highlight {
            background-color: #fff3cd;
            color: #856404;
            padding: 0 0.15rem;
            border-radius: 0.15rem;
        }

        .edit-product-search-results {
            display: none;
            position: absolute;
            z-index: 10001;
            width: calc(100% - 1.5rem);
            max-height: 300px;
            overflow-y: auto;
            background: var(--surface-1, #ffffff);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border-radius: 0.375rem;
            border: 1px solid var(--border-default, #dee2e6);
        }

        html[data-bs-theme='dark'] .edit-product-search-results {
            background: #111827;
            border-color: #374151;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
        }

        /* Dark mode readability fixes */
        html[data-bs-theme='dark'] body.bg-light {
            background: #0b1220 !important;
            color: #e5e7eb;
        }

        html[data-bs-theme='dark'] .card,
        html[data-bs-theme='dark'] .modal-content,
        html[data-bs-theme='dark'] .modal-body,
        html[data-bs-theme='dark'] .modal-footer,
        html[data-bs-theme='dark'] .table,
        html[data-bs-theme='dark'] .table-responsive {
            background-color: #111827;
            color: #e5e7eb;
            border-color: #374151;
        }

        html[data-bs-theme='dark'] .card-header,
        html[data-bs-theme='dark'] .table-light,
        html[data-bs-theme='dark'] .bg-light {
            background-color: #1f2937 !important;
            color: #e5e7eb !important;
            border-color: #374151 !important;
        }

        html[data-bs-theme='dark'] .table td,
        html[data-bs-theme='dark'] .table th,
        html[data-bs-theme='dark'] .border,
        html[data-bs-theme='dark'] .border-bottom,
        html[data-bs-theme='dark'] .border-start {
            border-color: #374151 !important;
        }

        html[data-bs-theme='dark'] .table-hover tbody tr:hover {
            background-color: #1f2937;
        }

        /* Keep row content readable while hovering */
        html[data-bs-theme='dark'] .table-hover tbody tr:hover td,
        html[data-bs-theme='dark'] .table-hover tbody tr:hover div,
        html[data-bs-theme='dark'] .table-hover tbody tr:hover strong,
        html[data-bs-theme='dark'] .table-hover tbody tr:hover small {
            color: #f3f4f6 !important;
        }

        html[data-bs-theme='dark'] .table-hover tbody tr:hover .text-muted {
            color: #cbd5e1 !important;
        }

        html[data-bs-theme='dark'] .table-hover tbody tr:hover .text-primary {
            color: #bfdbfe !important;
        }

        html[data-bs-theme='dark'] .table-hover tbody tr:hover .text-success {
            color: #86efac !important;
        }

        html[data-bs-theme='dark'] .table-hover tbody tr:hover .badge.bg-secondary {
            background-color: #475569 !important;
            color: #f8fafc !important;
        }

        html[data-bs-theme='dark'] .table-hover tbody tr:hover .badge.bg-warning {
            background-color: #facc15 !important;
            color: #111827 !important;
        }

        html[data-bs-theme='dark'] .table-hover tbody tr:hover .badge.bg-success {
            background-color: #22c55e !important;
            color: #0b1220 !important;
        }

        .sales-row-actions .btn {
            padding: 0.45rem 0.7rem;
            font-size: 1.05rem;
            line-height: 1;
        }

        html[data-bs-theme='dark'] .table-hover tbody tr:hover .btn-outline-primary,
        html[data-bs-theme='dark'] .table-hover tbody tr:hover .btn-outline-secondary,
        html[data-bs-theme='dark'] .table-hover tbody tr:hover .btn-outline-info,
        html[data-bs-theme='dark'] .table-hover tbody tr:hover .btn-outline-success,
        html[data-bs-theme='dark'] .table-hover tbody tr:hover .btn-outline-warning,
        html[data-bs-theme='dark'] .table-hover tbody tr:hover .btn-outline-danger {
            border-color: #94a3b8 !important;
            color: #f8fafc !important;
            background: rgba(15, 23, 42, 0.25);
        }

        html[data-bs-theme='dark'] .text-muted {
            color: #9ca3af !important;
        }

        html[data-bs-theme='dark'] .text-primary {
            color: #93c5fd !important;
        }

        html[data-bs-theme='dark'] .text-success {
            color: #86efac !important;
        }

        html[data-bs-theme='dark'] .text-danger {
            color: #fca5a5 !important;
        }

        html[data-bs-theme='dark'] .form-control,
        html[data-bs-theme='dark'] .form-select,
        html[data-bs-theme='dark'] .input-group-text {
            background: #1f2937;
            color: #f3f4f6;
            border-color: #4b5563;
        }

        html[data-bs-theme='dark'] .btn-outline-secondary,
        html[data-bs-theme='dark'] .btn-outline-danger {
            color: #d1d5db;
            border-color: #6b7280;
        }

        html[data-bs-theme='dark'] .btn-outline-secondary:hover,
        html[data-bs-theme='dark'] .btn-outline-danger:hover {
            background: #374151;
            color: #f9fafb;
        }

        html[data-bs-theme='dark'] #editSelectedCustomerDisplay.alert-info {
            background: #1e3a8a;
            color: #dbeafe;
            border-color: #1d4ed8;
        }

        #saleCostModal .modal-content {
            border: none;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.12);
        }

        #saleCostModal .sale-cost-header {
            background: linear-gradient(135deg, #f8f9fc 0%, #eef2f7 100%);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.5rem 1.75rem;
        }

        #saleCostModal .sale-cost-header .modal-title {
            font-size: 1.35rem;
            font-weight: 700;
        }

        #saleCostModal .sale-cost-header-meta {
            font-size: 0.95rem;
            color: #6c757d;
            margin-top: 0.35rem;
        }

        #saleCostModal .sale-cost-body {
            padding: 1.25rem 1.75rem 0.5rem;
        }

        #saleCostModal .sale-cost-table-wrap {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 0.875rem;
            overflow: hidden;
        }

        #saleCostModal .sale-cost-table {
            margin-bottom: 0;
        }

        #saleCostModal .sale-cost-table thead th {
            background: #f3f6fb;
            color: #495057;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            white-space: nowrap;
        }

        #saleCostModal .sale-cost-table tbody td {
            vertical-align: middle;
            font-size: 1rem;
            padding: 1rem 1.1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
        }

        #saleCostModal .sale-cost-table tbody tr:last-child td {
            border-bottom: none;
        }

        #saleCostModal .sale-cost-table tbody tr:hover {
            background: rgba(13, 110, 253, 0.04);
        }

        #saleCostModal .sale-cost-product-name {
            font-size: 1.05rem;
            font-weight: 600;
            line-height: 1.4;
        }

        #saleCostModal .sale-cost-badge-external {
            font-size: 0.72rem;
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            border-radius: 999px;
            background: #e9ecef;
            color: #495057;
        }

        #saleCostModal .sale-cost-qty {
            display: inline-block;
            min-width: 4.5rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            background: #f1f3f5;
            font-size: 0.92rem;
            font-weight: 500;
        }

        #saleCostModal .sale-cost-price-buy {
            color: #6c757d;
            font-weight: 600;
        }

        #saleCostModal .sale-cost-price-sell {
            color: #0d6efd;
            font-weight: 700;
        }

        #saleCostModal .sale-cost-profit-positive {
            color: #198754;
            font-weight: 700;
            font-size: 1.02rem;
        }

        #saleCostModal .sale-cost-profit-negative {
            color: #dc3545;
            font-weight: 700;
            font-size: 1.02rem;
        }

        #saleCostModal .sale-cost-missing {
            color: #adb5bd;
            cursor: help;
            font-size: 1.1rem;
        }

        #saleCostModal .sale-cost-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            width: 100%;
        }

        @media (max-width: 767.98px) {
            #saleCostModal .sale-cost-summary-grid {
                grid-template-columns: 1fr;
            }
        }

        #saleCostModal .sale-cost-summary-card {
            border-radius: 0.875rem;
            padding: 1.1rem 1rem;
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.06);
            background: #f8f9fa;
        }

        #saleCostModal .sale-cost-summary-card .summary-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 0.35rem;
        }

        #saleCostModal .sale-cost-summary-card .summary-value {
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1.3;
        }

        #saleCostModal .sale-cost-summary-card.summary-cost {
            background: linear-gradient(180deg, #f8f9fa 0%, #f1f3f5 100%);
        }

        #saleCostModal .sale-cost-summary-card.summary-revenue {
            background: linear-gradient(180deg, #eef4ff 0%, #e7f0ff 100%);
        }

        #saleCostModal .sale-cost-summary-card.summary-revenue .summary-value {
            color: #0d6efd;
        }

        #saleCostModal .sale-cost-summary-card.summary-profit {
            background: linear-gradient(180deg, #edf7f0 0%, #e3f2e8 100%);
        }

        #saleCostModal .sale-cost-missing-note {
            border-radius: 0.75rem;
            background: #f8f9fa;
            border: 1px dashed #ced4da;
            color: #6c757d;
            font-size: 0.92rem;
        }

        #saleCostModal .modal-footer {
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.25rem 1.75rem 1.5rem;
            background: #fafbfc;
        }

        html[data-bs-theme='dark'] #saleCostModal .modal-content {
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.45);
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-header {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            border-bottom-color: #374151;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-header-meta {
            color: #9ca3af;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-table-wrap {
            background: #111827;
            border-color: #374151;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-table thead th {
            background: #1f2937;
            color: #e5e7eb;
            border-bottom-color: #374151;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-table tbody td {
            border-bottom-color: #1f2937;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-table tbody tr:hover {
            background: rgba(59, 130, 246, 0.08);
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-badge-external {
            background: #374151;
            color: #d1d5db;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-qty {
            background: #1f2937;
            color: #e5e7eb;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-price-buy {
            color: #9ca3af;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-price-sell {
            color: #60a5fa;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-profit-positive {
            color: #4ade80;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-profit-negative {
            color: #f87171;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-summary-card {
            background: #1f2937;
            border-color: #374151;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-summary-card.summary-cost {
            background: linear-gradient(180deg, #1f2937 0%, #111827 100%);
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-summary-card.summary-revenue {
            background: linear-gradient(180deg, #1e3a5f 0%, #172554 100%);
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-summary-card.summary-revenue .summary-value {
            color: #93c5fd;
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-summary-card.summary-profit {
            background: linear-gradient(180deg, #14532d 0%, #052e16 100%);
        }

        html[data-bs-theme='dark'] #saleCostModal .sale-cost-missing-note {
            background: #1f2937;
            border-color: #4b5563;
            color: #9ca3af;
        }

        html[data-bs-theme='dark'] #saleCostModal .modal-footer {
            background: #111827;
            border-top-color: #374151;
        }

        html[data-bs-theme='dark'] #saleCostModal .modal-footer .btn-light {
            background: #1f2937;
            border-color: #374151;
            color: #e5e7eb;
        }

        html[data-bs-theme='dark'] #saleCostModal .modal-footer .btn-light:hover {
            background: #374151;
            color: #f9fafb;
        }

        @media print {
            #saleCostModal {
                display: none !important;
            }
        }
    </style>
</head>
<body class="sales-list-page">
    <?php include_once '../../includes/navigation.php'; ?>
    
    <div class="container-fluid sl-wrap">
        <div class="row">
            <div class="col-12">
                <header class="sl-hero">
                    <div class="sl-hero-main">
                        <div class="sl-kicker"><i class="bi bi-shop"></i> بەشی فرۆشتن</div>
                        <h1><i class="bi bi-receipt-cutoff"></i> <?php echo $pageTitle; ?></h1>
                        <p class="sl-hero-sub">وەسڵەکان، گەڕان و ئاماری فرۆشتن لە یەک شوێن</p>
                        <div class="sl-hero-pills">
                            <span class="sl-pill"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($periodLabel); ?></span>
                            <span class="sl-pill"><i class="bi bi-receipt"></i> <?php echo number_format($totalRecords); ?> وەسڵ</span>
                        </div>
                    </div>
                    <div class="sl-hero-actions">
                        <a href="<?php echo url('user/pos/main.php'); ?>" class="sl-btn sl-btn-ghost">
                            <i class="bi bi-arrow-right"></i> بەشی فرۆشتن
                        </a>
                        <button type="button" class="sl-btn sl-btn-ghost" onclick="openA4SettingsModal()">
                            <i class="bi bi-sliders"></i> چاپی A4
                        </button>
                        <a href="<?php echo url('user/pos/index.php'); ?>" class="sl-btn sl-btn-primary">
                            <i class="bi bi-plus-lg"></i> فرۆشتنی نوێ
                        </a>
                    </div>
                </header>

                <section class="sl-stats">
                    <div class="sl-stat sl-stat-count">
                        <div class="sl-stat-icon"><i class="bi bi-receipt"></i></div>
                        <div class="sl-stat-body">
                            <div class="sl-stat-label">کۆی فرۆشتن</div>
                            <div class="sl-stat-value"><?php echo number_format($stats['total_sales']); ?></div>
                            <div class="sl-stat-meta">وەسڵ لەم ماوەیەدا</div>
                        </div>
                    </div>
                    <div class="sl-stat sl-stat-revenue">
                        <div class="sl-stat-icon"><i class="bi bi-currency-exchange"></i></div>
                        <div class="sl-stat-body">
                            <div class="sl-stat-label">کۆی داهات (پاک)</div>
                            <div class="sl-stat-value"><?php echo number_format($stats['net_amount'], 0); ?></div>
                            <div class="sl-stat-meta">دینار<?php if ($stats['total_returns'] > 0): ?> · فرۆشتن: <?php echo number_format($stats['total_amount'], 0); ?> · گەڕانەوە: -<?php echo number_format($stats['total_returns'], 0); ?><?php endif; ?></div>
                        </div>
                    </div>
                    <div class="sl-stat sl-stat-avg">
                        <div class="sl-stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                        <div class="sl-stat-body">
                            <div class="sl-stat-label">ناوەندی فرۆشتن</div>
                            <div class="sl-stat-value"><?php echo number_format($stats['average_sale'], 0); ?></div>
                            <div class="sl-stat-meta">دینار بۆ هەر وەسڵێک</div>
                        </div>
                    </div>
                    <div class="sl-stat sl-stat-mix">
                        <div class="sl-stat-icon"><i class="bi bi-pie-chart"></i></div>
                        <div class="sl-stat-body">
                            <div class="sl-stat-label">شێوازی پارەدان</div>
                            <div class="sl-stat-value"><?php echo $cashPct; ?>% نەقد</div>
                            <div class="sl-mix-bar" aria-hidden="true">
                                <span class="sl-mix-cash" style="width: <?php echo (int) $cashPct; ?>%"></span>
                                <span class="sl-mix-debt" style="width: <?php echo (int) $debtPct; ?>%"></span>
                                <?php if ($cardPct > 0): ?>
                                    <span class="sl-mix-card" style="width: <?php echo (int) $cardPct; ?>%"></span>
                                <?php endif; ?>
                            </div>
                            <div class="sl-mix-legend">
                                <span>قەرز <b><?php echo $debtPct; ?>%</b></span>
                                <?php if ($cardPct > 0): ?>
                                    <span>کارت <b><?php echo $cardPct; ?>%</b></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="card sales-filter-card">
                    <div class="card-body">
                        <div class="sl-filter-head">
                            <h2 class="sl-filter-title"><i class="bi bi-funnel"></i> فلتەر و گەڕان</h2>
                            <div class="sl-chips">
                                <button type="button" class="sl-chip<?php echo $quickRange === 'today' ? ' is-active' : ''; ?>" onclick="setDateRange('today')">ئەمڕۆ</button>
                                <button type="button" class="sl-chip<?php echo $quickRange === 'yesterday' ? ' is-active' : ''; ?>" onclick="setDateRange('yesterday')">دوێنێ</button>
                                <button type="button" class="sl-chip<?php echo $quickRange === 'week' ? ' is-active' : ''; ?>" onclick="setDateRange('week')">٧ ڕۆژی ڕابردوو</button>
                                <button type="button" class="sl-chip<?php echo $quickRange === 'month' ? ' is-active' : ''; ?>" onclick="setDateRange('month')">ئەم مانگە</button>
                                <?php if ($filtersActive): ?>
                                    <a class="sl-chip sl-chip-reset" href="<?php echo url('user/pos/sales.php'); ?>">
                                        <i class="bi bi-x-lg"></i> پاککردنەوە
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <form method="GET" class="row g-3 sales-filter-form">
                            <input type="hidden" name="explicit_dates" id="explicitDatesFlag"
                                   value="<?php echo $datesMarkedExplicit ? '1' : '0'; ?>">
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label">لە بەرواری</label>
                                <input type="date" name="date_from" class="form-control"
                                       value="<?php echo htmlspecialchars($formDateFrom); ?>">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label">تا بەرواری</label>
                                <input type="date" name="date_to" class="form-control"
                                       value="<?php echo htmlspecialchars($formDateTo); ?>">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label">شێوازی پارەدان</label>
                                <select name="payment_method" class="form-select">
                                    <option value="all" <?php echo $paymentMethod == 'all' ? 'selected' : ''; ?>>هەموو</option>
                                    <option value="cash" <?php echo $paymentMethod == 'cash' ? 'selected' : ''; ?>>پارەی نەقد</option>
                                    <option value="debt" <?php echo $paymentMethod == 'debt' ? 'selected' : ''; ?>>قەرز</option>
                                </select>
                            </div>
                            <div class="col-md-3 col-lg-3">
                                <label class="form-label">وەسڵ یان کڕیار</label>
                                <input type="text" name="search" class="form-control"
                                       placeholder="ژمارەی وەسڵ یان ناوی کڕیار..."
                                       value="<?php echo htmlspecialchars($search); ?>"
                                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                            </div>
                            <div class="col-md-8 col-lg-2">
                                <label class="form-label">کاڵا / بارکۆد</label>
                                <input type="text" name="item_search" class="form-control"
                                       placeholder="ناوی کاڵا یان بارکۆد..."
                                       value="<?php echo htmlspecialchars($itemSearch); ?>">
                            </div>
                            <div class="col-md-4 col-lg-1">
                                <label class="form-label d-none d-lg-block">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i>
                                    <span class="d-lg-none"> گەڕان</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sales Table -->
                <div class="card sales-table-card">
                    <div class="card-header sl-table-head">
                        <h2><i class="bi bi-list-ul"></i> لیستی فرۆشتنەکان</h2>
                        <span class="sl-count-badge"><?php echo number_format($totalRecords); ?> فرۆشتن</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ژ.وەسڵ</th>
                                        <th>بەروار/کات</th>
                                        <th>ناوی کڕیار</th>
                                        <th>ئایتم</th>
                                        <th>کۆی گشتی</th>
                                        <th>داشکاندن</th>
                                        <th>کۆی کۆتایی</th>
                                        <th>شێوازی پارەدان</th>
                                        <th>تێبینی</th>
                                        <th>کردارەکان</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($sales) > 0): ?>
                                        <?php foreach ($sales as $sale): ?>
                                            <?php
                                            $customerName = trim((string)($sale['customer_name'] ?? ''));
                                            if ($customerName === '' || $customerName === '-') {
                                                $customerDisplay = 'کڕیاری گشتی';
                                            } else {
                                                $customerDisplay = $customerName;
                                            }
                                            $customerInitial = function_exists('mb_substr')
                                                ? mb_substr($customerDisplay, 0, 1, 'UTF-8')
                                                : substr($customerDisplay, 0, 1);
                                            $avatarHue = abs(crc32($customerDisplay)) % 360;

                                            $payMethod = (string)($sale['payment_method'] ?? '');
                                            if ($payMethod === 'cash') {
                                                $paymentBadge = '<span class="sl-pay sl-pay-cash"><i class="bi bi-cash-stack"></i> نەقد</span>';
                                            } elseif ($payMethod === 'debt' || $payMethod === 'credit') {
                                                $paymentBadge = '<span class="sl-pay sl-pay-debt"><i class="bi bi-clock-history"></i> قەرز</span>';
                                            } elseif ($payMethod === 'card') {
                                                $paymentBadge = '<span class="sl-pay sl-pay-card"><i class="bi bi-credit-card"></i> کارت</span>';
                                            } else {
                                                $paymentBadge = '<span class="sl-pay sl-pay-other">' . htmlspecialchars($payMethod) . '</span>';
                                            }
                                            ?>
                                            <tr>
                                                <td data-label="ژ.وەسڵ">
                                                    <span class="sl-invoice">#<?php echo (int) $sale['id']; ?></span>
                                                </td>
                                                <td data-label="بەروار/کات">
                                                    <div class="sl-datetime">
                                                        <span class="sl-date"><?php echo $sale['formatted_date']; ?></span>
                                                        <span class="sl-time"><?php echo $sale['time']; ?></span>
                                                    </div>
                                                </td>
                                                <td data-label="ناوی کڕیار">
                                                    <div class="sl-customer">
                                                        <span class="sl-avatar" style="--hue: <?php echo (int) $avatarHue; ?>"><?php echo htmlspecialchars($customerInitial); ?></span>
                                                        <span class="sl-customer-name"><?php echo htmlspecialchars($customerDisplay); ?></span>
                                                    </div>
                                                </td>
                                                <td data-label="ئایتم">
                                                    <span class="sl-item-count"><?php echo (int) $sale['items_count']; ?> ئایتم</span>
                                                    <?php if (!empty($sale['item_names'])): ?>
                                                        <?php
                                                        $rawItems = explode('، ', $sale['item_names']);
                                                        $displayParts = [];
                                                        foreach ($rawItems as $rawItem) {
                                                            if ($rawItem === '') {
                                                                continue;
                                                            }
                                                            $parts = explode('||', $rawItem, 2);
                                                            $itemName = $parts[0] ?? '';
                                                            $itemBarcode = $parts[1] ?? '';

                                                            $shouldHighlight = false;
                                                            if (!empty($itemSearch)) {
                                                                $searchLower = mb_strtolower($itemSearch, 'UTF-8');
                                                                $nameLower = mb_strtolower($itemName, 'UTF-8');
                                                                $barcodeLower = mb_strtolower($itemBarcode, 'UTF-8');

                                                                if (mb_strpos($nameLower, $searchLower, 0, 'UTF-8') !== false ||
                                                                    ($barcodeLower !== '' && mb_strpos($barcodeLower, $searchLower, 0, 'UTF-8') !== false)) {
                                                                    $shouldHighlight = true;
                                                                }
                                                            }

                                                            $safeName = htmlspecialchars($itemName);
                                                            if ($shouldHighlight) {
                                                                $displayParts[] = '<span class="sale-item-highlight">' . $safeName . '</span>';
                                                            } else {
                                                                $displayParts[] = $safeName;
                                                            }
                                                        }
                                                        $displayHtml = implode('، ', $displayParts);
                                                        ?>
                                                        <div class="sale-item-names">
                                                            <?php echo $displayHtml; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="کۆی گشتی"><?php echo formatCurrencyAmount($sale['total_amount'], $sale['currency'] ?? 'IQD'); ?></td>
                                                <td data-label="داشکاندن">
                                                    <?php if ($sale['discount'] > 0): ?>
                                                        <span class="sl-discount">-<?php echo formatCurrencyAmount($sale['discount'], $sale['currency'] ?? 'IQD'); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="کۆی کۆتایی">
                                                    <span class="sl-amount"><?php echo formatCurrencyAmount($sale['final_amount'], $sale['currency'] ?? 'IQD'); ?></span>
                                                </td>
                                                <td data-label="شێوازی پارەدان">
                                                    <?php echo $paymentBadge; ?>
                                                </td>
                                                <td data-label="تێبینی">
                                                    <?php if (!empty($sale['notes'])): ?>
                                                        <?php
                                                        $saleNotesDisplay = $sale['notes'];
                                                        $saleNotesShort = function_exists('mb_substr')
                                                            ? mb_substr($saleNotesDisplay, 0, 60, 'UTF-8')
                                                            : substr($saleNotesDisplay, 0, 60);
                                                        $saleNotesTruncated = (function_exists('mb_strlen') ? mb_strlen($saleNotesDisplay, 'UTF-8') : strlen($saleNotesDisplay)) > 60;
                                                        ?>
                                                        <span class="sl-notes sale-notes-cell"
                                                              <?php if ($saleNotesTruncated): ?>title="<?php echo htmlspecialchars($saleNotesDisplay, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                                                            <?php echo htmlspecialchars($saleNotesShort, ENT_QUOTES, 'UTF-8'); ?><?php echo $saleNotesTruncated ? '…' : ''; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="کردارەکان">
                                                    <div class="sales-row-actions">
                                                        <a href="<?php echo url('user/pos/receipt.php?id=' . $sale['id'] . '&print=1'); ?>"
                                                           class="btn btn-outline-secondary"
                                                           title="چاپکردن"
                                                           target="_blank">
                                                            <i class="bi bi-printer"></i>
                                                        </a>
                                                        <button type="button"
                                                                class="btn btn-outline-info"
                                                                title="پرێنتی A4"
                                                                onclick="printA4OrShowModal(<?php echo $sale['id']; ?>)">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </button>
                                                        <?php if ($canViewSaleCost): ?>
                                                        <button type="button"
                                                                class="btn btn-outline-dark"
                                                                title="نرخی کڕین لە کاتی فرۆشتن"
                                                                onclick="openSaleCostModal(<?php echo (int)$sale['id']; ?>)">
                                                            <i class="bi bi-graph-up-arrow"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if ($canCreateSaleReturn): ?>
                                                        <button type="button"
                                                                class="btn btn-outline-success"
                                                                title="گەڕاندنەوەی کاڵا"
                                                                onclick="openSaleReturnModal(<?php echo (int)$sale['id']; ?>)">
                                                            <i class="bi bi-arrow-return-left"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-outline-warning"
                                                                onclick="editSale(<?php echo $sale['id']; ?>)"
                                                                title="دەستکاری">
                                                            <i class="bi bi-pencil-square"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger"
                                                                onclick="deleteSale(<?php echo $sale['id']; ?>)"
                                                                title="سڕینەوە">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10">
                                                <div class="sales-empty-state">
                                                    <div class="sl-empty-icon"><i class="bi bi-inbox"></i></div>
                                                    <h3>هیچ فرۆشتنێک نەدۆزرایەوە</h3>
                                                    <p>فلتەرەکان بگۆڕە یان فرۆشتنێکی نوێ تۆمار بکە</p>
                                                    <a href="<?php echo url('user/pos/index.php'); ?>" class="sl-btn sl-btn-primary">
                                                        <i class="bi bi-plus-lg"></i> فرۆشتنی نوێ
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav class="sl-pagination" aria-label="پەڕەکان">
                        <div class="sl-page-info">پەڕەی <?php echo (int) $page; ?> لە <?php echo (int) $totalPages; ?></div>
                        <ul class="pagination justify-content-center mb-0">
                            <?php
                            $currentUrl = url('user/pos/sales.php');
                            $queryParams = $_GET;
                            ?>

                            <?php if ($page > 1): ?>
                                <?php
                                $queryParams['page'] = $page - 1;
                                $prevUrl = $currentUrl . '?' . http_build_query($queryParams);
                                ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo $prevUrl; ?>">پێشوو</a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <?php
                                $queryParams['page'] = $i;
                                $pageUrl = $currentUrl . '?' . http_build_query($queryParams);
                                ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo $pageUrl; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <?php
                                $queryParams['page'] = $page + 1;
                                $nextUrl = $currentUrl . '?' . http_build_query($queryParams);
                                ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo $nextUrl; ?>">دواتر</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function deleteSale(saleId) {
            if (!confirm('دڵنیای لە سڕینەوەی ئەم فرۆشتنە؟\n\nئەم کارە:\n• وەسڵەکە بە تەواوی دەسڕێتەوە\n• بڕی کاڵاکان دەگەڕێتەوە بۆ کۆگا\n• بڕی پارەکە لە داهات کەم دەکرێتەوە')) {
                return;
            }
            
            // Show loading state
            const deleteBtn = event.target.closest('button');
            const originalContent = deleteBtn.innerHTML;
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            
            // Use relative path - go up two levels from user/pos/ to reach root, then go to api/
            fetch('../../api/sales.php?action=delete&_t=' + new Date().getTime(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: saleId })
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response ok:', response.ok);
                
                // Check if response is ok
                if (!response.ok) {
                    return response.text().then(text => {
                        console.error('Server error response:', text);
                        throw new Error('Server returned ' + response.status);
                    });
                }
                
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    if (data.data && data.data.void_token) {
                        window.open('receipt_void.php?token=' + encodeURIComponent(data.data.void_token) + '&print=1', '_blank');
                    }
                    location.reload();
                } else {
                    alert(data.message || 'هەڵەیەک ڕوویدا');
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = originalContent;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('هەڵەیەک ڕوویدا لە پەیوەندی کردن بە سێرڤەر: ' + error.message);
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = originalContent;
            });
        }
        
        // Quick date filters
        function markExplicitDates() {
            const flag = document.getElementById('explicitDatesFlag');
            if (flag) {
                flag.value = '1';
            }
        }

        function setDateRange(range) {
            const today = new Date();
            let startDate, endDate = today;

            switch(range) {
                case 'today':
                    startDate = today;
                    break;
                case 'yesterday':
                    startDate = new Date(today);
                    startDate.setDate(today.getDate() - 1);
                    endDate = startDate;
                    break;
                case 'week':
                    startDate = new Date(today);
                    startDate.setDate(today.getDate() - 7);
                    break;
                case 'month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    break;
            }

            const toYmd = (d) => {
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            };

            document.querySelector('input[name="date_from"]').value = toYmd(startDate);
            document.querySelector('input[name="date_to"]').value = toYmd(endDate);
            markExplicitDates();
            const form = document.querySelector('.sales-filter-form');
            if (form) {
                form.submit();
            }
        }
        
        // Add quick filter buttons
        document.addEventListener('DOMContentLoaded', function() {
            const dateFromInput = document.querySelector('input[name="date_from"]');
            const dateToInput = document.querySelector('input[name="date_to"]');

            if (dateFromInput) {
                dateFromInput.addEventListener('change', markExplicitDates);
            }
            if (dateToInput) {
                dateToInput.addEventListener('change', markExplicitDates);
            }
        });
        
        // ===== A4 PRINT FIELD SETTINGS =====
        let a4SettingsMode = false;

        function applyA4FieldSelection(savedFields) {
            const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]');
            if (!checkboxes.length) return;

            // ئەگەر هیچ ڕێکخستنێک نییە، هەموو خانەکان دیفۆلت هەڵبژێردراون
            if (!Array.isArray(savedFields) || savedFields.length === 0) {
                checkboxes.forEach(cb => cb.checked = true);
                return;
            }

            checkboxes.forEach(cb => {
                cb.checked = savedFields.includes(cb.value);
            });
        }

        /** وەرگرتنی ڕێکخستنەکانی فیڵدەکان؛ ئەگەر هەبوون وەک لیست دەگەڕێنێتەوە، ئەگەر نەبوون null */
        async function getA4FieldSettings() {
            try {
                const response = await fetch('../../api/a4_field_settings.php', {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) return null;
                const data = await response.json();
                if (data && data.success && data.data && Array.isArray(data.data.fields) && data.data.fields.length > 0) {
                    return data.data.fields;
                }
                return null;
            } catch (error) {
                console.error('Error loading A4 field settings:', error);
                return null;
            }
        }

        async function loadA4FieldSettings() {
            const fields = await getA4FieldSettings();
            applyA4FieldSelection(fields);
        }

        function updateA4ModalButtons() {
            const modalEl = document.getElementById('printA4Modal');
            const printBtn = document.getElementById('printA4ModalPrintButton');
            const saveBtn = document.getElementById('printA4ModalSaveButton');
            const hasSaleId = !!modalEl.getAttribute('data-sale-id');

            if (printBtn) {
                if (a4SettingsMode || !hasSaleId) {
                    printBtn.classList.add('d-none');
                    printBtn.disabled = true;
                } else {
                    printBtn.classList.remove('d-none');
                    printBtn.disabled = false;
                }
            }

            if (saveBtn) {
                saveBtn.classList.remove('d-none');
                saveBtn.disabled = false;
            }
        }

        async function openA4SettingsModal() {
            a4SettingsMode = true;
            const modalEl = document.getElementById('printA4Modal');
            modalEl.removeAttribute('data-sale-id');
            await loadA4FieldSettings();
            updateA4ModalButtons();
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }

        /** ئەگەر ڕێکخستنی فیڵدەکان هەبوو ڕاستەوخۆ چاپ، ئەگەر نەبوو مۆدالی هەڵبژاردن نیشان بدە */
        async function printA4OrShowModal(saleId) {
            const savedFields = await getA4FieldSettings();
            if (savedFields && savedFields.length > 0) {
                let url = '<?php echo url("user/pos/receipt_a4.php"); ?>?id=' + saleId + '&fields=' + savedFields.join(',');
                window.open(url, '_blank');
                return;
            }
            showPrintA4Modal(saleId);
        }

        async function showPrintA4Modal(saleId) {
            a4SettingsMode = false;
            const modalEl = document.getElementById('printA4Modal');
            modalEl.setAttribute('data-sale-id', saleId);
            await loadA4FieldSettings();
            updateA4ModalButtons();
            const modal = new bootstrap.Modal(modalEl);
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
        
        async function saveA4FieldSettings(showAlert = true) {
            const checked = document.querySelectorAll('#printA4Modal input[type="checkbox"]:checked');
            const fields = [];

            checked.forEach(cb => {
                fields.push(cb.value);
            });

            try {
                const response = await fetch('../../api/a4_field_settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ fields })
                });

                const data = await response.json().catch(() => null);

                if (!response.ok || !data || !data.success) {
                    if (showAlert) {
                        alert(data && data.message ? data.message : 'نەتوانرا ڕێکخستن پاشەکەوت بکرێت');
                    }
                    return false;
                }

                if (showAlert) {
                    alert(data.message || 'ڕێکخستنەکانی چاپی A4 پاشەکەوت کران');
                }
                return true;
            } catch (error) {
                console.error('Error saving A4 field settings:', error);
                if (showAlert) {
                    alert('هەڵەیەک ڕوویدا لە پاشەکەوتکردنی ڕێکخستنەکان');
                }
                return false;
            }
        }

        async function printA4Receipt() {
            const modalEl = document.getElementById('printA4Modal');
            const saleId = modalEl.getAttribute('data-sale-id');
            if (!saleId) {
                alert('سەبارەت بە چاپی A4، پێویستە وەسڵێک هەڵبژێریت');
                return;
            }

            const checkboxes = document.querySelectorAll('#printA4Modal input[type="checkbox"]:checked');
            
            // If no fields selected, show all fields (default behavior)
            let fields = [];
            if (checkboxes.length > 0) {
                checkboxes.forEach(cb => {
                    fields.push(cb.value);
                });
            }

            // پاشەکەوتکردنی ڕێکخستنەکانی هەنووکە بە شێوەی ناهمەهنگ
            saveA4FieldSettings(false);
            
            // Build URL
            let url = '<?php echo url("user/pos/receipt_a4.php"); ?>?id=' + saleId;
            if (fields.length > 0) {
                url += '&fields=' + fields.join(',');
            }
            
            // Open in new tab
            window.open(url, '_blank');
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) {
                modal.hide();
            }
        }
    </script>
    
    <!-- Edit Sale Modal -->
    <div class="modal fade" id="editSaleModal" tabindex="-1" aria-labelledby="editSaleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header bg-warning bg-opacity-10 border-bottom">
                    <h5 class="modal-title" id="editSaleModalLabel">
                        <i class="bi bi-pencil-square"></i> دەستکاریکردنی وەسڵ <span id="editSaleIdDisplay"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="container-fluid h-100">
                        <div class="row h-100">
                            <!-- Right Side: Cart -->
                            <div class="col-lg-8 col-md-7 p-3" style="overflow-y: auto; max-height: calc(100vh - 130px);">
                                <div id="editSaleReturnAlert" class="alert alert-warning d-none mb-3 py-2 small" role="alert"></div>
                                <!-- Product Search -->
                                <div class="card mb-3" style="position:relative; z-index:10000; overflow:visible;">
                                    <div class="card-body p-2" style="position:relative; overflow:visible;">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                                            <input type="text" class="form-control" id="editProductSearch" 
                                                   placeholder="گەڕان بە ناو، بارکۆد..." 
                                                   autocomplete="off">
                                        </div>
                                        <div id="editProductSearchResults" class="list-group mt-1 edit-product-search-results"></div>
                                    </div>
                                </div>

                                <!-- Cart Table -->
                                <div class="card">
                                    <div class="card-header bg-dark text-white py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-cart3"></i> کاڵاکان</span>
                                            <span class="badge bg-warning text-dark" id="editCartCount">0</span>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive" style="max-height: calc(100vh - 400px); overflow-y: auto;">
                                            <table class="table table-hover table-sm mb-0">
                                                <thead class="table-light sticky-top">
                                                    <tr>
                                                        <th style="width:5%">#</th>
                                                        <th style="width:25%">کاڵا</th>
                                                        <th style="width:15%">بڕ</th>
                                                        <th style="width:10%">یەکە</th>
                                                        <th style="width:15%">نرخی یەکە</th>
                                                        <th style="width:15%">کۆ</th>
                                                        <th style="width:15%">کردار</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="editCartTableBody">
                                                    <tr>
                                                        <td colspan="7" class="text-center py-4 text-muted">
                                                            <i class="bi bi-cart-x display-6"></i>
                                                            <p class="mt-2">چاوەڕوانی داتا...</p>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Totals -->
                                <div class="card mt-3">
                                    <div class="card-body py-2">
                                        <div class="row text-center">
                                            <div class="col">
                                                <small class="text-muted">کۆی گشتی</small>
                                                <div class="fw-bold" id="editTotalAmount">0</div>
                                            </div>
                                            <div class="col">
                                                <small class="text-muted">داشکاندن</small>
                                                <div class="fw-bold text-success" id="editDiscountDisplay">0</div>
                                            </div>
                                            <div class="col">
                                                <small class="text-danger">کۆی کۆتایی</small>
                                                <div class="fw-bold text-danger fs-5" id="editFinalAmount">0</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Left Side: Controls -->
                            <div class="col-lg-4 col-md-5 bg-light p-3 border-start" style="overflow-y: auto; max-height: calc(100vh - 130px);">
                                <input type="hidden" id="editSaleId" value="">
                                <input type="hidden" id="editSelectedCustomerId" value="">

                                <!-- Customer -->
                                <div class="card mb-3">
                                    <div class="card-header py-2">
                                        <i class="bi bi-person"></i> کڕیار
                                    </div>
                                    <div class="card-body p-2">
                                        <div class="input-group input-group-sm mb-2">
                                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                                            <input type="text" class="form-control" id="editCustomerSearch" 
                                                   placeholder="گەڕانی کڕیار..." autocomplete="off">
                                        </div>
                                        <div id="editCustomerSearchResults" class="list-group mb-2" style="display:none; max-height:200px; overflow-y:auto;"></div>
                                        <div id="editSelectedCustomerDisplay" class="alert alert-info py-1 px-2 mb-0 small" style="display:none;">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span id="editCustomerInfo"></span>
                                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="editClearCustomer()">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <input type="text" class="form-control form-control-sm mt-2" id="editCustomerName" 
                                               placeholder="ناوی کڕیار (ئارەزوومەندانە)">
                                    </div>
                                </div>

                                <!-- Payment Method -->
                                <div class="card mb-3">
                                    <div class="card-header py-2">
                                        <i class="bi bi-credit-card"></i> شێوازی پارەدان
                                    </div>
                                    <div class="card-body p-2">
                                        <select class="form-select form-select-sm mb-2" id="editPaymentMethod" onchange="editHandlePaymentMethodChange()">
                                            <option value="cash">نەقد</option>
                                            <option value="credit">قەرز</option>
                                        </select>
                                        <div id="editPaidAmountGroup" style="display:none;">
                                            <label class="form-label small mb-1">بڕی واسڵکراو</label>
                                            <input type="number" class="form-control form-control-sm" id="editPaidAmount" 
                                                   min="0" step="any" value="0" oninput="editUpdateTotals()">
                                        </div>
                                    </div>
                                </div>

                                <!-- Discount -->
                                <div class="card mb-3">
                                    <div class="card-header py-2">
                                        <i class="bi bi-tag"></i> داشکاندن
                                    </div>
                                    <div class="card-body p-2">
                                        <input type="number" class="form-control form-control-sm" id="editDiscount" 
                                               min="0" step="any" value="0" oninput="editUpdateTotals()">
                                    </div>
                                </div>

                                <!-- بەرواری وەسڵ -->
                                <div class="card mb-3">
                                    <div class="card-header py-2">
                                        <i class="bi bi-calendar-event"></i> بەروار و کاتی وەسڵ
                                    </div>
                                    <div class="card-body p-2">
                                        <label class="form-label small mb-1">بەروار</label>
                                        <input type="date" class="form-control form-control-sm mb-2" id="editSaleDate">
                                        <label class="form-label small mb-1">کات</label>
                                        <input type="time" class="form-control form-control-sm" id="editSaleTime" step="60">
                                    </div>
                                </div>

                                <!-- Currency -->
                                <div class="card mb-3">
                                    <div class="card-header py-2">
                                        <i class="bi bi-currency-exchange"></i> دراو
                                    </div>
                                    <div class="card-body p-2">
                                        <select class="form-select form-select-sm" id="editCurrency">
                                            <option value="IQD">دیناری عێراقی (IQD)</option>
                                            <option value="USD">دۆلاری ئەمریکی (USD)</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Save / Cancel -->
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-warning btn-lg" id="editSaveBtn" onclick="saveEditedSale()">
                                        <i class="bi bi-check-circle"></i> پاشەکەوتکردن
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle"></i> پاشگەزبوونەوە
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sale Cost Breakdown Modal (internal use only) -->
    <div class="modal fade" id="saleCostModal" tabindex="-1" aria-labelledby="saleCostModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header sale-cost-header">
                    <div>
                        <h5 class="modal-title mb-0" id="saleCostModalLabel">
                            <i class="bi bi-graph-up-arrow text-primary"></i>
                            نرخی کڕین لە کاتی فرۆشتن
                            <span id="saleCostModalSaleId" class="text-muted"></span>
                        </h5>
                        <div class="sale-cost-header-meta" id="saleCostModalMeta"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
                </div>
                <div class="modal-body sale-cost-body p-0">
                    <div id="saleCostModalLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                            <span class="visually-hidden">بارکردن...</span>
                        </div>
                        <p class="text-muted mt-3 mb-0 fs-5">بارکردنی داتا...</p>
                    </div>
                    <div id="saleCostModalError" class="alert alert-danger mx-3 my-3 d-none" role="alert"></div>
                    <div id="saleCostModalContent" class="d-none">
                        <div class="sale-cost-table-wrap">
                            <div class="table-responsive">
                                <table class="table sale-cost-table align-middle">
                                    <thead>
                                        <tr>
                                            <th style="width:3.5rem">#</th>
                                            <th>ناوی کاڵا</th>
                                            <th class="text-center" style="width:8rem">بڕ</th>
                                            <th class="text-end" style="width:9rem">نرخی کڕین</th>
                                            <th class="text-end" style="width:9rem">نرخی فرۆشتن</th>
                                            <th class="text-end" style="width:9rem">قازانج</th>
                                        </tr>
                                    </thead>
                                    <tbody id="saleCostModalTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                        <div id="saleCostModalMissingNote" class="sale-cost-missing-note mx-0 mt-3 mb-0 px-3 py-3 d-none" role="status">
                            <i class="bi bi-info-circle"></i>
                            هەندێک کاڵا لە کاتی فرۆشتن نرخی کڕینیان تۆمار نەکراوە (—).
                        </div>
                    </div>
                </div>
                <div class="modal-footer flex-column align-items-stretch d-none" id="saleCostModalFooter">
                    <div class="sale-cost-summary-grid">
                        <div class="sale-cost-summary-card summary-cost">
                            <div class="summary-label"><i class="bi bi-bag-check"></i> کۆی نرخی کڕین</div>
                            <div class="summary-value" id="saleCostTotalCost">—</div>
                        </div>
                        <div class="sale-cost-summary-card summary-revenue">
                            <div class="summary-label"><i class="bi bi-cash-coin"></i> کۆی فرۆشتن</div>
                            <div class="summary-value" id="saleCostTotalRevenue">—</div>
                        </div>
                        <div class="sale-cost-summary-card summary-profit">
                            <div class="summary-label"><i class="bi bi-trophy"></i> کۆی قازانج</div>
                            <div class="summary-value" id="saleCostTotalProfit">—</div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-light btn-lg w-100 mt-3" data-bs-dismiss="modal">داخستن</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ===== EDIT SALE STATE =====
    const EditSale = {
        cart: [],
        saleId: null,
        currency: 'IQD',
        csrfToken: '<?php echo Security::generateCSRFToken(); ?>',
        userId: <?php echo $currentUser['id']; ?>,
        selectedCustomer: null,
        debtSummary: {
            has_payments: false,
            payment_count: 0,
            historical_paid: 0
        }
    };

    /** پێشووترین شێوازی پارەدان لە مۆداڵی دەستکاری (بۆ ناسینی گۆڕین نەقد → قەرز) */
    let editPaymentMethodPrev = 'cash';

    // ===== EDIT SALE: FETCH & OPEN =====
    async function editSale(saleId) {
        try {
            const response = await fetch(`../../api/sales.php?action=get&id=${saleId}&_t=${new Date().getTime()}`);
            const data = await response.json();
            
            if (!data.success || !data.data || !data.data.sale) {
                alert('نەتوانرا داتای وەسڵ بخوێنرێتەوە');
                return;
            }

            const sale = data.data.sale;
            EditSale.saleId = sale.id;
            EditSale.currency = sale.currency || 'IQD';
            EditSale.cart = [];
            EditSale.selectedCustomer = null;
            EditSale.debtSummary = sale.debt_summary || {
                has_payments: false,
                payment_count: 0,
                historical_paid: 0
            };

            // Populate header
            document.getElementById('editSaleId').value = sale.id;
            document.getElementById('editSaleIdDisplay').textContent = `#${sale.id}`;

            // Populate customer
            if (sale.customer_id) {
                try {
                    const custResp = await fetch(`ajax/search_customers.php?id=${sale.customer_id}&_t=${new Date().getTime()}`);
                    const custData = await custResp.json();
                    if (custData.customers && custData.customers.length > 0) {
                        editSelectCustomer(custData.customers[0]);
                    }
                } catch(e) {
                    // fallback: just show name
                    document.getElementById('editCustomerName').value = sale.customer_name || '';
                    document.getElementById('editSelectedCustomerId').value = sale.customer_id || '';
                }
            } else {
                editClearCustomer();
                document.getElementById('editCustomerName').value = sale.customer_name || '';
            }

            // Payment method (editPaymentMethodPrev پێش handler دانرێت بۆ ئەوەی بارکردنی وەسڵی قەرز بڕی واسڵکراو نەسڕێتەوە)
            const loadedPaymentSelect = (sale.payment_method === 'debt' ? 'credit' : sale.payment_method) || 'cash';
            editPaymentMethodPrev = loadedPaymentSelect;
            document.getElementById('editPaymentMethod').value = loadedPaymentSelect;
            editHandlePaymentMethodChange();

            // Paid amount
            document.getElementById('editPaidAmount').value = parseFloat(sale.paid_amount) || 0;

            // Discount
            document.getElementById('editDiscount').value = parseFloat(sale.discount) || 0;

            // Currency
            document.getElementById('editCurrency').value = EditSale.currency;

            // بەروار و کات (هەمان داتا کە لە داتابەیس هەیە)
            const sd = sale.sale_date || '';
            if (sd) {
                const dPart = sd.substring(0, 10);
                const tMatch = String(sd).match(/\d{2}:\d{2}/);
                document.getElementById('editSaleDate').value = /^\d{4}-\d{2}-\d{2}$/.test(dPart) ? dPart : '';
                document.getElementById('editSaleTime').value = tMatch ? tMatch[0] : '00:00';
            } else {
                const now = new Date();
                document.getElementById('editSaleDate').value = now.toISOString().slice(0, 10);
                document.getElementById('editSaleTime').value = now.toTimeString().slice(0, 5);
            }

            // Load cart items (بڕی ماوە دوای گەڕاوە)
            EditSale.returnSummary = sale.return_summary || { has_returns: false };
            EditSale.cart = [];
            if (sale.items && sale.items.length > 0) {
                sale.items.forEach(item => {
                    const returnedQty = parseFloat(item.returned_qty) || 0;
                    const originalQty = parseFloat(item.original_quantity ?? item.quantity) || 0;
                    const editableQty = parseFloat(item.editable_quantity ?? (originalQty - returnedQty)) || 0;
                    const soldLineTotal = parseFloat(item.total_price) || 0;
                    const unitPrice = originalQty > 0
                        ? (soldLineTotal / originalQty)
                        : (parseFloat(item.unit_price) || 0);

                    if (editableQty <= 0 && returnedQty <= 0) {
                        return;
                    }

                    EditSale.cart.push({
                        sale_item_id: item.sale_item_id ? parseInt(item.sale_item_id, 10) : (item.id ? parseInt(item.id, 10) : null),
                        product_id: item.product_id ? parseInt(item.product_id) : null,
                        product_name: item.product_name,
                        quantity: editableQty,
                        original_quantity: originalQty,
                        returned_qty: returnedQty,
                        min_quantity: returnedQty,
                        unit_price: unitPrice,
                        total_price: roundEditPrice(editableQty * unitPrice),
                        price_type: item.price_type || 'retail',
                        unit_id: item.unit_id ? parseInt(item.unit_id) : null,
                        unit_name: item.unit_name || 'دانە',
                        unit_symbol: item.unit_symbol || '',
                        currency: item.currency || sale.currency || 'IQD',
                        barcode: item.barcode || '',
                        isExternal: !item.product_id,
                        isFullyReturned: editableQty <= 0 && returnedQty > 0
                    });
                });
            }

            const returnAlert = document.getElementById('editSaleReturnAlert');
            if (returnAlert) {
                if (EditSale.returnSummary.has_returns) {
                    returnAlert.classList.remove('d-none');
                    returnAlert.innerHTML = '<i class="bi bi-arrow-return-left me-1"></i> ئەم وەسڵە گەڕاندنەوەی تۆمارکراوی هەیە. تەنها دەتوانیت بڕی <strong>ماوە</strong> دەستکاری بکەیت (نەک بڕی گەڕاوە).';
                } else {
                    returnAlert.classList.add('d-none');
                    returnAlert.innerHTML = '';
                }
            }

            editRenderCart();
            editUpdateTotals();

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('editSaleModal'));
            modal.show();

        } catch (error) {
            console.error('Edit sale error:', error);
            alert('هەڵەیەک ڕوویدا: ' + error.message);
        }
    }

    // ===== EDIT SALE: CART RENDERING =====
    function editRenderCart() {
        const tbody = document.getElementById('editCartTableBody');
        const countBadge = document.getElementById('editCartCount');

        if (EditSale.cart.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                        <i class="bi bi-cart-x display-6"></i>
                        <p class="mt-2">سەبەتە بەتاڵە</p>
                    </td>
                </tr>`;
            countBadge.textContent = '0';
            return;
        }

        const totalItems = EditSale.cart.reduce((sum, item) => sum + item.quantity, 0);
        countBadge.textContent = totalItems;

        tbody.innerHTML = EditSale.cart.map((item, index) => {
            const isExternal = item.isExternal || !item.product_id;
            const returnedQty = parseFloat(item.returned_qty) || 0;
            const originalQty = parseFloat(item.original_quantity) || item.quantity;
            const minQty = parseFloat(item.min_quantity) || 0;
            const isFullyReturned = item.isFullyReturned || (item.quantity <= 0 && returnedQty > 0);
            const qtyDisabled = isFullyReturned ? 'disabled' : '';
            const minAttr = minQty > 0 ? `min="${minQty}"` : 'min="0.01"';
            const returnedBadge = returnedQty > 0
                ? `<div class="mt-1"><span class="badge bg-warning text-dark" style="font-size:0.65rem">گەڕاوە: ${returnedQty} لە ${originalQty}</span></div>`
                : '';
            return `
            <tr class="${isFullyReturned ? 'table-warning' : ''}">
                <td>${index + 1}</td>
                <td>
                    <div class="fw-bold small">${escapeHtml(item.product_name)}</div>
                    ${item.barcode ? `<small class="text-muted">${escapeHtml(item.barcode)}</small>` : ''}
                    ${isExternal ? '<span class="badge bg-secondary ms-1" style="font-size:0.6rem">دەرەکی</span>' : ''}
                    ${returnedBadge}
                </td>
                <td>
                    <div class="input-group input-group-sm" style="width:120px">
                        <button class="btn btn-outline-secondary" type="button" onclick="editChangeQty(${index}, -1)" ${qtyDisabled}>-</button>
                        <input type="number" class="form-control text-center px-1" 
                               value="${item.quantity}" ${minAttr} step="any"
                               onchange="editSetQty(${index}, this.value)" style="width:50px" ${qtyDisabled}>
                        <button class="btn btn-outline-secondary" type="button" onclick="editChangeQty(${index}, 1)" ${qtyDisabled}>+</button>
                    </div>
                </td>
                <td><small>${escapeHtml(item.unit_name)}</small></td>
                <td>
                    <input type="number" class="form-control form-control-sm" 
                           value="${item.unit_price}" min="0" step="any"
                           onchange="editSetPrice(${index}, this.value)" style="width:100px" ${isFullyReturned ? 'disabled' : ''}>
                </td>
                <td class="fw-bold">${formatEditCurrency(item.total_price)}</td>
                <td>
                    ${(isFullyReturned || minQty > 0) ? '' : `<button class="btn btn-sm btn-outline-danger" onclick="editRemoveItem(${index})"><i class="bi bi-trash"></i></button>`}
                </td>
            </tr>`;
        }).join('');
    }

    // ===== EDIT SALE: CART OPERATIONS =====
    function editGetMinQty(item) {
        return parseFloat(item.min_quantity) || 0;
    }

    function editChangeQty(index, delta) {
        if (index < 0 || index >= EditSale.cart.length) return;
        const item = EditSale.cart[index];
        if (item.isFullyReturned) return;
        const minQty = editGetMinQty(item);
        const newQty = item.quantity + delta;
        if (newQty < minQty) {
            alert(`ناتوانیت بڕ کەمتر بکەیت لە ${minQty} چونکە ئەوەش پێشتر گەڕاوەتەوە`);
            return;
        }
        if (newQty <= 0) {
            if (minQty > 0) {
                alert('ناتوانیت ئەم کاڵایە بسڕیتەوە چونکە بەشێکی پێشتر گەڕاوەتەوە');
                return;
            }
            editRemoveItem(index);
            return;
        }
        item.quantity = newQty;
        item.total_price = roundEditPrice(item.quantity * item.unit_price);
        editRenderCart();
        editUpdateTotals();
    }

    function editSetQty(index, val) {
        if (index < 0 || index >= EditSale.cart.length) return;
        const item = EditSale.cart[index];
        if (item.isFullyReturned) return;
        const qty = parseFloat(val);
        const minQty = editGetMinQty(item);
        if (isNaN(qty) || qty < minQty) {
            alert(`کەمترین بڕ ${minQty}ە (بڕی پێشتر گەڕاوە)`);
            return;
        }
        if (qty <= 0) {
            if (minQty > 0) {
                alert('ناتوانیت ئەم کاڵایە بسڕیتەوە چونکە بەشێکی پێشتر گەڕاوەتەوە');
                return;
            }
            return;
        }
        item.quantity = qty;
        item.total_price = roundEditPrice(qty * item.unit_price);
        editRenderCart();
        editUpdateTotals();
    }

    function editSetPrice(index, val) {
        if (index < 0 || index >= EditSale.cart.length) return;
        const price = parseFloat(val);
        if (isNaN(price) || price < 0) return;
        EditSale.cart[index].unit_price = price;
        EditSale.cart[index].total_price = roundEditPrice(EditSale.cart[index].quantity * price);
        editRenderCart();
        editUpdateTotals();
    }

    function editRemoveItem(index) {
        if (index < 0 || index >= EditSale.cart.length) return;
        const item = EditSale.cart[index];
        if (editGetMinQty(item) > 0 || item.isFullyReturned) {
            alert('ناتوانیت ئەم کاڵایە بسڕیتەوە چونکە بەشێکی پێشتر گەڕاوەتەوە');
            return;
        }
        EditSale.cart.splice(index, 1);
        editRenderCart();
        editUpdateTotals();
    }

    // ===== EDIT SALE: TOTALS =====
    function editUpdateTotals() {
        const totalAmount = EditSale.cart.reduce((sum, item) => sum + item.total_price, 0);
        const discount = parseFloat(document.getElementById('editDiscount').value) || 0;
        const finalAmount = Math.max(0, totalAmount - discount);
        const currency = document.getElementById('editCurrency').value || 'IQD';

        document.getElementById('editTotalAmount').textContent = formatEditCurrencyAmount(totalAmount, currency);
        document.getElementById('editDiscountDisplay').textContent = formatEditCurrencyAmount(discount, currency);
        document.getElementById('editFinalAmount').textContent = formatEditCurrencyAmount(finalAmount, currency);
    }

    // ===== EDIT SALE: PAYMENT METHOD =====
    function editHandlePaymentMethodChange() {
        const method = document.getElementById('editPaymentMethod').value;
        const paidGroup = document.getElementById('editPaidAmountGroup');
        if (method === 'credit') {
            paidGroup.style.display = 'block';
            if (editPaymentMethodPrev === 'cash') {
                document.getElementById('editPaidAmount').value = 0;
            }
        } else {
            paidGroup.style.display = 'none';
        }
        editPaymentMethodPrev = method;
    }

    // ===== EDIT SALE: CUSTOMER =====
    let editCustomerSearchTimeout = null;
    document.addEventListener('DOMContentLoaded', function() {
        const custSearch = document.getElementById('editCustomerSearch');
        if (custSearch) {
            custSearch.addEventListener('input', function() {
                clearTimeout(editCustomerSearchTimeout);
                const query = this.value.trim();
                if (query.length < 1) {
                    document.getElementById('editCustomerSearchResults').style.display = 'none';
                    return;
                }
                editCustomerSearchTimeout = setTimeout(() => {
                    editSearchCustomers(query);
                }, 300);
            });
        }

        // Product search
        const prodSearch = document.getElementById('editProductSearch');
        if (prodSearch) {
            let prodTimeout = null;
            prodSearch.addEventListener('input', function() {
                clearTimeout(prodTimeout);
                const query = this.value.trim();
                if (query.length < 1) {
                    document.getElementById('editProductSearchResults').style.display = 'none';
                    return;
                }
                prodTimeout = setTimeout(() => {
                    editSearchProducts(query);
                }, 300);
            });
            prodSearch.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const query = this.value.trim();
                    if (query) editSearchProducts(query);
                }
            });
        }
    });

    async function editSearchCustomers(query) {
        try {
            const response = await fetch(`ajax/search_customers.php?q=${encodeURIComponent(query)}&_t=${new Date().getTime()}`);
            const data = await response.json();
            const resultsDiv = document.getElementById('editCustomerSearchResults');
            
            if (data.customers && data.customers.length > 0) {
                resultsDiv.innerHTML = data.customers.map(c => `
                    <button type="button" class="list-group-item list-group-item-action py-1 px-2 small" 
                            onclick='editSelectCustomer(${JSON.stringify(c).replace(/'/g, "&#39;")})'>
                        <div class="fw-bold">${escapeHtml(c.name)}</div>
                        <small class="text-muted">${c.phone || ''} ${(c.current_debt_iqd > 0 || c.current_debt_usd > 0) ? '| قەرز: ' + 
                            ((c.current_debt_iqd > 0) ? formatEditCurrencyAmount(c.current_debt_iqd, 'IQD') : '') + 
                            ((c.current_debt_iqd > 0 && c.current_debt_usd > 0) ? ' | ' : '') + 
                            ((c.current_debt_usd > 0) ? formatEditCurrencyAmount(c.current_debt_usd, 'USD') : '') : ''}</small>
                    </button>
                `).join('');
                resultsDiv.style.display = 'block';
            } else {
                resultsDiv.innerHTML = '<div class="list-group-item small text-muted">هیچ کڕیارێک نەدۆزرایەوە</div>';
                resultsDiv.style.display = 'block';
            }
        } catch(e) {
            console.error('Customer search error:', e);
        }
    }

    function editSelectCustomer(customer) {
        EditSale.selectedCustomer = customer;
        document.getElementById('editSelectedCustomerId').value = customer.id;
        document.getElementById('editCustomerName').value = customer.name;
        document.getElementById('editCustomerInfo').innerHTML = 
            `<strong>${escapeHtml(customer.name)}</strong> ${customer.phone ? '- ' + customer.phone : ''} ${
                (customer.current_debt_iqd > 0) ? '<br>قەرز (دینار): ' + formatEditCurrencyAmount(customer.current_debt_iqd, 'IQD') : ''
            } ${
                (customer.current_debt_usd > 0) ? '<br>قەرز (دۆلار): ' + formatEditCurrencyAmount(customer.current_debt_usd, 'USD') : ''
            }`;
        document.getElementById('editSelectedCustomerDisplay').style.display = 'block';
        document.getElementById('editCustomerSearchResults').style.display = 'none';
        document.getElementById('editCustomerSearch').value = '';
    }

    function editClearCustomer() {
        EditSale.selectedCustomer = null;
        document.getElementById('editSelectedCustomerId').value = '';
        document.getElementById('editCustomerName').value = '';
        document.getElementById('editSelectedCustomerDisplay').style.display = 'none';
    }

    // ===== EDIT SALE: PRODUCT SEARCH & ADD =====
    async function editSearchProducts(query) {
        try {
            const response = await fetch(`../../api/products.php?action=search&q=${encodeURIComponent(query)}&user_id=${EditSale.userId}&limit=20&_t=${new Date().getTime()}`);
            const data = await response.json();
            const resultsDiv = document.getElementById('editProductSearchResults');

            if (data.success && data.data && data.data.products && data.data.products.length > 0) {
                resultsDiv.innerHTML = data.data.products.map(p => `
                    <button type="button" class="list-group-item list-group-item-action py-1 px-2 small" 
                            onclick='editAddProductFromSearch(${JSON.stringify({
                                id: p.id,
                                name: p.name,
                                barcode: p.barcode || "",
                                sell_price: p.sell_price,
                                stock_quantity: p.stock_quantity,
                                units: p.units || [],
                                currency: p.currency || "IQD"
                            }).replace(/'/g, "&#39;")})'>
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold">${escapeHtml(p.name)}</span>
                            <span class="text-success">${p.sell_price || 0}</span>
                        </div>
                        <small class="text-muted">${p.barcode || ''} | بەردەست: ${p.stock_quantity || 0}</small>
                    </button>
                `).join('');
                resultsDiv.style.display = 'block';
            } else {
                resultsDiv.innerHTML = '<div class="list-group-item small text-muted">هیچ کاڵایەک نەدۆزرایەوە</div>';
                resultsDiv.style.display = 'block';
            }
        } catch(e) {
            console.error('Product search error:', e);
        }
    }

    function editAddProductFromSearch(product) {
        document.getElementById('editProductSearchResults').style.display = 'none';
        document.getElementById('editProductSearch').value = '';

        let unitId = null;
        let unitName = 'دانە';
        let unitSymbol = '';
        let price = parseFloat(product.sell_price) || 0;

        if (product.units && product.units.length > 0) {
            const firstUnit = product.units[0];
            unitId = firstUnit.unit_id || null;
            unitName = firstUnit.unit_name || 'دانە';
            unitSymbol = firstUnit.unit_symbol || '';
            price = parseFloat(firstUnit.sell_price) || price;
        }

        // Check if already in cart (same product + same unit)
        const existingIdx = EditSale.cart.findIndex(item => 
            item.product_id === parseInt(product.id) && item.unit_id === unitId
        );

        if (existingIdx >= 0) {
            EditSale.cart[existingIdx].quantity += 1;
            EditSale.cart[existingIdx].total_price = roundEditPrice(
                EditSale.cart[existingIdx].quantity * EditSale.cart[existingIdx].unit_price
            );
        } else {
            EditSale.cart.push({
                product_id: parseInt(product.id),
                product_name: product.name,
                quantity: 1,
                unit_price: price,
                total_price: price,
                price_type: 'retail',
                unit_id: unitId,
                unit_name: unitName,
                unit_symbol: unitSymbol,
                currency: product.currency || EditSale.currency || 'IQD',
                barcode: product.barcode || '',
                isExternal: false
            });
        }

        editRenderCart();
        editUpdateTotals();
    }

    // ===== EDIT SALE: SAVE =====
    function editChooseDebtAdjustmentPolicy(finalAmount, historicalPaid) {
        const policyChoice = window.prompt(
            `ئەم وەسڵە ${historicalPaid} پارەدانەی پێشوو هەیە.\n` +
            `کۆی نوێ: ${finalAmount}\n\n` +
            'هەڵبژاردەی policy هەڵبژێرە:\n' +
            '1) پارەدانەکان بپارێزە و paid_amount لە سنووری کۆی نوێدا clamp بکە\n' +
            '2) پارەدانەکان مەپارێزە (fresh debt payment)\n' +
            '3) ئەگەر کۆی نوێ کەمتر بوو لە پارەی پێشوو، save ڕەتبکەرەوە\n\n' +
            '1 یان 2 یان 3 بنووسە',
            '1'
        );

        if (policyChoice === null) return null;

        const normalized = String(policyChoice).trim();
        if (normalized === '2') {
            return {
                payment_adjustment_policy: 'clamp_to_final_no_credit',
                preserve_payment_history: false
            };
        }
        if (normalized === '3') {
            return {
                payment_adjustment_policy: 'reject_edit',
                preserve_payment_history: true
            };
        }
        return {
            payment_adjustment_policy: 'clamp_to_final_no_credit',
            preserve_payment_history: true
        };
    }

    async function saveEditedSale() {
        const activeItems = EditSale.cart.filter(item => !item.isFullyReturned && parseFloat(item.quantity) > 0);
        if (activeItems.length === 0) {
            alert('سەبەتە بەتاڵە، کاڵا زیاد بکە');
            return;
        }

        const saleId = parseInt(document.getElementById('editSaleId').value);
        const customerId = document.getElementById('editSelectedCustomerId').value;
        const customerName = document.getElementById('editCustomerName').value.trim();
        const paymentMethod = document.getElementById('editPaymentMethod').value;
        const discount = parseFloat(document.getElementById('editDiscount').value) || 0;
        const paidAmount = parseFloat(document.getElementById('editPaidAmount').value) || 0;
        const currency = document.getElementById('editCurrency').value || 'IQD';

        const totalAmount = activeItems.reduce((sum, item) => sum + item.total_price, 0);
        const finalAmount = Math.max(0, totalAmount - discount);

        if (finalAmount <= 0) {
            alert('بڕی کۆتایی ناتوانێت سفر یان کەمتر بێت');
            return;
        }

        if (paymentMethod === 'credit' && !customerId) {
            alert('بۆ پارەدانی قەرز، پێویستە کڕیارێک هەڵبژێریت');
            return;
        }

        const decimals = currency === 'USD' ? 2 : 0;

        const editSaleDate = document.getElementById('editSaleDate').value;
        const editSaleTime = document.getElementById('editSaleTime').value;
        let saleDatePayload = null;
        if (editSaleDate && editSaleTime) {
            saleDatePayload = editSaleDate + ' ' + editSaleTime + ':00';
        } else if (editSaleDate) {
            saleDatePayload = editSaleDate + ' 00:00:00';
        }

        const updateData = {
            sale_id: saleId,
            customer_id: customerId ? parseInt(customerId) : null,
            customer_name: customerName,
            payment_method: paymentMethod === 'credit' ? 'debt' : paymentMethod,
            total_amount: Number(totalAmount.toFixed(decimals)),
            discount: Number(discount.toFixed(decimals)),
            final_amount: Number(finalAmount.toFixed(decimals)),
            paid_amount: paidAmount,
            currency: currency,
            sale_date: saleDatePayload,
            items: activeItems.map(item => ({
                sale_item_id: item.sale_item_id || null,
                product_id: item.isExternal ? null : item.product_id,
                product_name: item.product_name,
                quantity: parseFloat(item.quantity),
                unit_price: Number(item.unit_price.toFixed(decimals)),
                total_price: Number(item.total_price.toFixed(decimals)),
                price_type: item.price_type || 'retail',
                unit_id: item.unit_id || null,
                unit_name: item.unit_name || 'دانە',
                unit_symbol: item.unit_symbol || '',
                isExternal: item.isExternal || false,
                currency: item.currency || currency
            })),
            csrf_token: EditSale.csrfToken
        };

        if (paymentMethod === 'credit' && EditSale.debtSummary && EditSale.debtSummary.has_payments) {
            const policy = editChooseDebtAdjustmentPolicy(finalAmount, EditSale.debtSummary.historical_paid || 0);
            if (!policy) {
                return;
            }
            updateData.payment_adjustment_policy = policy.payment_adjustment_policy;
            updateData.preserve_payment_history = policy.preserve_payment_history;
        }

        // Disable save button
        const saveBtn = document.getElementById('editSaveBtn');
        const origText = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> چاوەڕوان بە...';

        try {
            const response = await fetch('../../api/sales.php?action=update&_t=' + new Date().getTime(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(updateData)
            });

            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch(e) {
                throw new Error('وەڵامی سێرڤەر JSON نییە');
            }

            if (result.success) {
                alert('وەسڵەکە بە سەرکەوتوویی دەستکاری کرا');
                // Close modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('editSaleModal'));
                if (modal) modal.hide();
                // Reload page
                location.reload();
            } else {
                alert('هەڵە: ' + (result.message || 'نەزانراو'));
                saveBtn.disabled = false;
                saveBtn.innerHTML = origText;
            }
        } catch(error) {
            console.error('Save error:', error);
            alert('هەڵەیەک ڕوویدا: ' + error.message);
            saveBtn.disabled = false;
            saveBtn.innerHTML = origText;
        }
    }

    // ===== HELPER FUNCTIONS =====
    function roundEditPrice(val) {
        const currency = document.getElementById('editCurrency') ? document.getElementById('editCurrency').value : 'IQD';
        if (currency === 'USD') return Math.round(val * 100) / 100;
        return Math.round(val);
    }

    function formatEditCurrency(amount) {
        const currency = document.getElementById('editCurrency') ? document.getElementById('editCurrency').value : 'IQD';
        return formatEditCurrencyAmount(amount, currency);
    }

    function formatEditCurrencyAmount(amount, currency) {
        if (currency === 'USD') {
            return '$' + Number(amount).toFixed(2);
        }
        return Number(amount).toLocaleString('en') + ' د.ع';
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ===== SALE COST BREAKDOWN (profits.view only) =====
    let saleCostModalCurrency = 'IQD';

    function formatSaleCostPrice(amount, currency, priceClass) {
        if (amount === null || amount === undefined || Number.isNaN(Number(amount))) {
            return '<span class="sale-cost-missing" title="لە کاتی فرۆشتن تۆمار نەکراوە">—</span>';
        }
        const cls = priceClass ? ' class="' + priceClass + '"' : '';
        return '<span' + cls + '>' + escapeHtml(formatEditCurrencyAmount(amount, currency || saleCostModalCurrency)) + '</span>';
    }

    function formatSaleCostProfit(amount, currency) {
        if (amount === null || amount === undefined || Number.isNaN(Number(amount))) {
            return '<span class="sale-cost-missing" title="لە کاتی فرۆشتن تۆمار نەکراوە">—</span>';
        }
        const cls = amount >= 0 ? 'sale-cost-profit-positive' : 'sale-cost-profit-negative';
        return '<span class="' + cls + '">' + escapeHtml(formatEditCurrencyAmount(amount, currency || saleCostModalCurrency)) + '</span>';
    }

    function formatSaleCostDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) {
            return escapeHtml(String(dateStr));
        }
        return d.toLocaleDateString('ku-IQ') + ' — ' + d.toLocaleTimeString('ku-IQ', { hour: '2-digit', minute: '2-digit' });
    }

    function renderSaleCostModal(data) {
        const sale = data.sale || {};
        const items = data.items || [];
        const summary = data.summary || {};

        saleCostModalCurrency = sale.currency || 'IQD';

        document.getElementById('saleCostModalSaleId').textContent = sale.invoice_number
            ? ' — ' + sale.invoice_number
            : ' — #' + (sale.id || '');
        document.getElementById('saleCostModalMeta').textContent = formatSaleCostDate(sale.sale_date);

        const tbody = document.getElementById('saleCostModalTableBody');
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">هیچ کاڵایەک نییە</td></tr>';
        } else {
            tbody.innerHTML = items.map(function (item, index) {
                const externalBadge = item.is_external
                    ? ' <span class="sale-cost-badge-external ms-1">دەرەکی</span>'
                    : '';
                const qtyLabel = Number(item.quantity) + ' ' + escapeHtml(item.unit_name || 'دانە');
                const itemCurrency = item.currency || saleCostModalCurrency;

                return '<tr>' +
                    '<td class="text-muted">' + (index + 1) + '</td>' +
                    '<td><div class="sale-cost-product-name">' + escapeHtml(item.product_name) + externalBadge + '</div></td>' +
                    '<td class="text-center"><span class="sale-cost-qty">' + qtyLabel + '</span></td>' +
                    '<td class="text-end">' + formatSaleCostPrice(item.buy_price_at_sale, itemCurrency, 'sale-cost-price-buy') + '</td>' +
                    '<td class="text-end">' + formatSaleCostPrice(item.sell_price, itemCurrency, 'sale-cost-price-sell') + '</td>' +
                    '<td class="text-end">' + formatSaleCostProfit(item.line_profit, itemCurrency) + '</td>' +
                    '</tr>';
            }).join('');
        }

        const missingNote = document.getElementById('saleCostModalMissingNote');
        if (summary.has_missing_cost) {
            missingNote.classList.remove('d-none');
        } else {
            missingNote.classList.add('d-none');
        }

        document.getElementById('saleCostTotalCost').textContent = formatEditCurrencyAmount(summary.total_cost || 0, saleCostModalCurrency);
        document.getElementById('saleCostTotalRevenue').textContent = formatEditCurrencyAmount(summary.total_revenue || 0, saleCostModalCurrency);

        const profitEl = document.getElementById('saleCostTotalProfit');
        const totalProfit = summary.total_profit;
        if (summary.has_missing_cost) {
            profitEl.innerHTML = formatSaleCostProfit(totalProfit, saleCostModalCurrency) +
                ' <small class="text-muted d-block" style="font-weight:normal">(تەنها کاڵاکانی تۆمارکراو)</small>';
        } else {
            profitEl.innerHTML = formatSaleCostProfit(totalProfit, saleCostModalCurrency);
        }
    }

    async function openSaleCostModal(saleId) {
        const modalEl = document.getElementById('saleCostModal');
        const loadingEl = document.getElementById('saleCostModalLoading');
        const errorEl = document.getElementById('saleCostModalError');
        const contentEl = document.getElementById('saleCostModalContent');
        const footerEl = document.getElementById('saleCostModalFooter');

        loadingEl.classList.remove('d-none');
        errorEl.classList.add('d-none');
        errorEl.textContent = '';
        contentEl.classList.add('d-none');
        footerEl.classList.add('d-none');
        document.getElementById('saleCostModalTableBody').innerHTML = '';

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        try {
            const response = await fetch('../../api/sales.php?action=sale_cost_breakdown&id=' + encodeURIComponent(saleId) + '&_t=' + Date.now());
            const data = await response.json().catch(function () { return null; });

            if (!response.ok || !data || !data.success) {
                throw new Error((data && data.message) ? data.message : 'نەتوانرا داتا بخوێنرێتەوە');
            }

            renderSaleCostModal(data.data || {});
            loadingEl.classList.add('d-none');
            contentEl.classList.remove('d-none');
            footerEl.classList.remove('d-none');
        } catch (error) {
            loadingEl.classList.add('d-none');
            errorEl.textContent = error.message || 'هەڵەیەک ڕوویدا';
            errorEl.classList.remove('d-none');
        }
    }

    // کردنەوەی خۆکارانەی مۆدالی دەستکاریکردن کاتێک edit_sale_id هەیە
    document.addEventListener('DOMContentLoaded', function () {
        var editId = <?php echo (int)$editSaleId; ?>;
        if (editId > 0) {
            editSale(editId);
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
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" value="sale_note" id="field_sale_note" checked>
                                <label class="form-check-label" for="field_sale_note">
                                    تێبینی فرۆشتن
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> داخستن
                    </button>
                    <button type="button"
                            class="btn btn-outline-primary"
                            id="printA4ModalSaveButton"
                            onclick="saveA4FieldSettings(true)">
                        <i class="bi bi-save"></i> پاشەکەوتکردنی ڕێکخستن
                    </button>
                    <button type="button"
                            class="btn btn-primary"
                            id="printA4ModalPrintButton"
                            onclick="printA4Receipt()">
                        <i class="bi bi-printer"></i> چاپکردن
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreateSaleReturn): ?>
        <?php include __DIR__ . '/partials/sale_return_modal.php'; ?>
        <script>
            window.SaleReturnConfig = {
                apiBase: '../../api/',
                csrfToken: '<?php echo Security::generateCSRFToken(); ?>'
            };
        </script>
        <script src="<?php echo asset_url('assets/js/sale-return.js'); ?>"></script>
    <?php endif; ?>
</body>
</html>