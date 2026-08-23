<?php
/**
 * Employees receipts list - filterable by sub_user and date range
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

// Require main user auth
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'employees.view', [
    'route' => '/user/employees/sales.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');

$userId = $currentUser['id'];
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';
$sessionSubUserId = (int)($currentUser['sub_user_id'] ?? 0);

// Fetch sub users for dropdown (belonging to current main user)
$subUsers = [];
$subUsersSql = "SELECT id, username, full_name FROM sub_users WHERE main_user_id = ?";
$subUsersTypes = 'i';
$subUsersParams = [$userId];
if ($isSubUser && $sessionSubUserId > 0) {
    $subUsersSql .= " AND id = ?";
    $subUsersTypes .= 'i';
    $subUsersParams[] = $sessionSubUserId;
}
$subUsersSql .= " ORDER BY full_name ASC";
$stmt = $conn->prepare($subUsersSql);
$stmt->bind_param($subUsersTypes, ...$subUsersParams);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $subUsers[] = $row; }
$stmt->close();

// Filters
$selectedSubUserId = isset($_GET['sub_user_id']) ? (int)$_GET['sub_user_id'] : 0;
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : '';

// Validate selected sub_user belongs to current user (or ignore)
if ($isSubUser) {
    $selectedSubUserId = $sessionSubUserId;
} elseif ($selectedSubUserId > 0) {
    $check = $conn->prepare("SELECT id FROM sub_users WHERE id = ? AND main_user_id = ?");
    $check->bind_param('ii', $selectedSubUserId, $userId);
    $check->execute();
    $valid = $check->get_result()->num_rows > 0;
    $check->close();
    if (!$valid) { $selectedSubUserId = 0; }
}

// Build WHERE clause
$where = ["s.user_id = ?"]; // restrict to current main user
$params = [$userId];
$types = 'i';

if ($selectedSubUserId > 0) {
    $where[] = "s.sub_user_id = ?";
    $params[] = $selectedSubUserId;
    $types .= 'i';
}

if ($dateFrom !== '') {
    $where[] = "DATE(s.sale_date) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = "DATE(s.sale_date) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Pagination
$perPage = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Totals (count and sum) for summary
$countSql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(s.final_amount),0) AS total_amount FROM sales s $whereSql";
$stmt = $conn->prepare($countSql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch page data
$listSql = "
    SELECT 
        s.id,
        s.invoice_number,
        s.sale_date,
        s.final_amount,
        COALESCE(s.currency, 'IQD') as currency,
        s.payment_method,
        s.payment_status,
        s.customer_name,
        s.sub_user_id,
        su.full_name AS sub_full_name,
        su.username AS sub_username
    FROM sales s
    LEFT JOIN sub_users su ON su.id = s.sub_user_id
    $whereSql
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT ? OFFSET ?
";

// Bind with dynamic + limit/offset
$listTypes = $types . 'ii';
$listParams = array_merge($params, [$perPage, $offset]);
$stmt = $conn->prepare($listSql);
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($r = $result->fetch_assoc()) { $rows[] = $r; }
$stmt->close();

// Total pages
$totalCount = (int)($totals['cnt'] ?? 0);
$totalPages = (int)ceil($totalCount / $perPage);

// Helper to keep query params on pagination links
function buildQuery(array $extra) {
    $params = $_GET;
    foreach ($extra as $k => $v) { $params[$k] = $v; }
    return http_build_query($params);
}

// Chart data: Daily sales for line chart
$dailySalesQuery = "
    SELECT 
        DATE(s.sale_date) AS sale_day,
        COUNT(*) AS sales_count,
        SUM(s.final_amount) AS total_amount
    FROM sales s
    $whereSql
    GROUP BY DATE(s.sale_date)
    ORDER BY DATE(s.sale_date) DESC
    LIMIT 30
";
$stmt = $conn->prepare($dailySalesQuery);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$dailySalesResult = $stmt->get_result();
$dailySales = [];
while ($r = $dailySalesResult->fetch_assoc()) { $dailySales[] = $r; }
$stmt->close();
$dailySales = array_reverse($dailySales); // Oldest first for chart

// Chart data: Payment methods distribution
$paymentMethodsQuery = "
    SELECT 
        s.payment_method,
        COUNT(*) AS count,
        SUM(s.final_amount) AS total
    FROM sales s
    $whereSql
    GROUP BY s.payment_method
";
$stmt = $conn->prepare($paymentMethodsQuery);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$paymentMethodsResult = $stmt->get_result();
$paymentMethods = [];
while ($r = $paymentMethodsResult->fetch_assoc()) { $paymentMethods[] = $r; }
$stmt->close();

// Chart data: Payment status distribution
$paymentStatusQuery = "
    SELECT 
        s.payment_status,
        COUNT(*) AS count,
        SUM(s.final_amount) AS total
    FROM sales s
    $whereSql
    GROUP BY s.payment_status
";
$stmt = $conn->prepare($paymentStatusQuery);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$paymentStatusResult = $stmt->get_result();
$paymentStatus = [];
while ($r = $paymentStatusResult->fetch_assoc()) { $paymentStatus[] = $r; }
$stmt->close();

?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>لیستی وەسڵەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    <link href="<?php echo asset('css/employees/employees-dark.css'); ?>" rel="stylesheet">
    <style>
        :root {
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #5B73E8 0%, #9B59B6 100%);
            --gradient-blue-purple: linear-gradient(135deg, #4A90E2 0%, #8B5CF6 100%);
            --gradient-violet: linear-gradient(135deg, #8B5CF6 0%, #C084FC 100%);
            --gradient-success: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --gradient-info: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        body { 
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        .navbar-gradient {
            background: var(--gradient-primary) !important;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .page-header {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 300px;
            height: 300px;
            background: var(--gradient-violet);
            opacity: 0.1;
            border-radius: 50%;
            transform: translate(-30%, -30%);
        }

        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: none;
            margin-bottom: 2rem;
        }

        .filter-label { 
            font-size: 0.9rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 0.6rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
        }

        .btn-filter {
            background: var(--gradient-blue-purple);
            border: none;
            color: white;
            padding: 0.6rem 2rem;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 144, 226, 0.5);
            color: white;
        }

        .btn-reset {
            border: 2px solid #667eea;
            color: #667eea;
            padding: 0.6rem 2rem;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            background: white;
        }

        .btn-reset:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .summary-card { 
            border-radius: 20px;
            border: none;
            background: white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-success);
        }

        .summary-item {
            padding: 1rem;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: bold;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .table-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: none;
            overflow: hidden;
        }

        .table thead th { 
            white-space: nowrap;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            font-weight: 600;
            border: none;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            transform: scale(1.01);
        }

        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }

        .badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .badge-paid {
            background: var(--gradient-success);
            color: white;
            box-shadow: 0 2px 8px rgba(17, 153, 142, 0.3);
        }

        .badge-pending {
            background: linear-gradient(135deg, #ffd89b 0%, #19547b 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
        }

        .btn-print {
            background: var(--gradient-info);
            border: none;
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 10px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(79, 172, 254, 0.3);
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 172, 254, 0.5);
            color: white;
        }

        .pagination .page-link {
            border-radius: 10px;
            margin: 0 0.2rem;
            border: 2px solid #e9ecef;
            color: #667eea;
            transition: all 0.3s ease;
        }

        .pagination .page-link:hover {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
            transform: translateY(-2px);
        }

        .pagination .page-item.active .page-link {
            background: var(--gradient-primary);
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .chart-container {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            height: 350px;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="employees-module-page employees-sales-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-header">
                    <div class="d-flex align-items-center justify-content-between position-relative">
                        <div>
                            <h3 class="mb-2">
                                <i class="bi bi-receipt-cutoff" style="background: var(--gradient-primary); background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
                                لیستی وەسڵەکان
                            </h3>
                            <p class="text-muted mb-0">فلتەرکردن و بینینی وردەکاری وەسڵەکان بە گوێرەی کارمەند و بەروار</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($rows)): ?>
        <!-- Charts Section -->
        <div class="row mb-4">
            <div class="col-lg-8 mb-4 mb-lg-0">
                <div class="chart-container">
                    <h5 class="mb-3">
                        <i class="bi bi-graph-up-arrow text-primary"></i>
                        گۆڕانکاری فرۆشتن بە پێی ڕۆژەکان
                    </h5>
                    <canvas id="dailySalesChart"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="chart-container">
                    <h5 class="mb-3">
                        <i class="bi bi-pie-chart-fill text-primary"></i>
                        دابەشبوونی جۆری پارەدان
                    </h5>
                    <canvas id="paymentMethodsChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-12">
                <div class="filter-card">
                    <h5 class="mb-4">
                        <i class="bi bi-funnel-fill text-primary"></i>
                        فلتەری گەڕان
                    </h5>
                    <form class="row g-3" method="GET">
                        <div class="col-md-3">
                            <label class="filter-label">
                                <i class="bi bi-person-badge"></i>
                                کارمەند
                            </label>
                            <select name="sub_user_id" class="form-select">
                                <option value="0">- هەمووی -</option>
                                <?php foreach ($subUsers as $su): ?>
                                    <option value="<?php echo (int)$su['id']; ?>" <?php echo $selectedSubUserId === (int)$su['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($su['full_name'] . ' (@' . $su['username'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="filter-label">
                                <i class="bi bi-calendar-event"></i>
                                لە بەرواری
                            </label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="filter-label">
                                <i class="bi bi-calendar-check"></i>
                                بۆ بەرواری
                            </label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="form-control">
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <button class="btn btn-filter flex-fill" type="submit">
                                <i class="bi bi-search"></i> فلتەر
                            </button>
                            <a class="btn btn-reset" href="<?php echo url('user/employees/sales.php'); ?>" title="ریسێتکردنەوە">
                                <i class="bi bi-arrow-clockwise"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="summary-card">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="summary-item">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="text-muted small mb-1">
                                            <i class="bi bi-receipt-cutoff"></i>
                                            کۆی ژمارەی وەسڵەکان
                                        </div>
                                        <div class="summary-value"><?php echo number_format($totalCount); ?></div>
                                    </div>
                                    <div class="text-end">
                                        <i class="bi bi-file-earmark-text" style="font-size: 2.5rem; color: rgba(102, 126, 234, 0.3);"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="summary-item">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="text-muted small mb-1">
                                            <i class="bi bi-currency-dollar"></i>
                                            کۆی بڕەکان
                                        </div>
                                        <div class="summary-value"><?php 
                                            // Note: This shows total in default currency, individual sales show their own currency
                                            echo formatCurrencyAmount((float)($totals['total_amount'] ?? 0), 'IQD'); 
                                        ?></div>
                                    </div>
                                    <div class="text-end">
                                        <i class="bi bi-cash-stack" style="font-size: 2.5rem; color: rgba(139, 92, 246, 0.3);"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 employees-sales-table">
                            <thead>
                                <tr>
                                    <th><i class="bi bi-hash"></i> فاتورە</th>
                                    <th><i class="bi bi-calendar3"></i> بەروار</th>
                                    <th><i class="bi bi-person"></i> کارمەند</th>
                                    <th><i class="bi bi-bag"></i> کڕیار</th>
                                    <th class="text-end"><i class="bi bi-cash"></i> کۆی بڕ</th>
                                    <th><i class="bi bi-credit-card"></i> جۆری پارەدان</th>
                                    <th><i class="bi bi-check-circle"></i> دۆخ</th>
                                    <th><i class="bi bi-gear"></i> کردار</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">هیچ داتایەک نەدۆزرایەوە</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td>
                                                <span class="fw-bold" style="color: #667eea;">
                                                    #<?php echo htmlspecialchars($row['invoice_number']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="small"><?php echo date('Y/m/d', strtotime($row['sale_date'])); ?></div>
                                                <div class="text-muted small"><?php echo date('H:i', strtotime($row['sale_date'])); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars(($row['sub_full_name'] ?: '-')); ?></div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-at"></i><?php echo htmlspecialchars(($row['sub_username'] ?: '-')); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <i class="bi bi-person-circle text-primary"></i>
                                                <?php echo htmlspecialchars($row['customer_name'] ?: '-'); ?>
                                            </td>
                                            <td class="text-end">
                                                <span class="fw-bold" style="color: #11998e;">
                                                    <?php echo formatCurrencyAmount((float)$row['final_amount'], $row['currency'] ?? 'IQD'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-modern" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                                    <?php echo htmlspecialchars($row['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-modern <?php echo $row['payment_status'] === 'paid' ? 'badge-paid' : 'badge-pending'; ?>">
                                                    <i class="bi bi-<?php echo $row['payment_status'] === 'paid' ? 'check-circle' : 'clock-history'; ?>"></i>
                                                    <?php echo $row['payment_status'] === 'paid' ? 'پارەدراو' : 'قەرز'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a class="btn btn-sm btn-print" href="<?php echo url('user/pos/receipt.php?id=' . urlencode($row['id'])); ?>" target="_blank">
                                                    <i class="bi bi-printer"></i> بینینی وەسڵ
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="p-4">
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo buildQuery(['page' => $p]); ?>">
                                                <?php echo $p; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if (!empty($rows)): ?>
    <script>
        // Chart data from PHP
        const dailySalesData = <?php echo json_encode($dailySales); ?>;
        const paymentMethodsData = <?php echo json_encode($paymentMethods); ?>;
        const paymentStatusData = <?php echo json_encode($paymentStatus); ?>;

        // Daily Sales Line Chart
        const dailyCtx = document.getElementById('dailySalesChart');
        if (dailyCtx && dailySalesData.length > 0) {
            new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: dailySalesData.map(item => item.sale_day),
                    datasets: [
                        {
                            label: 'ژمارەی فرۆشتنەکان',
                            data: dailySalesData.map(item => parseInt(item.sales_count)),
                            borderColor: 'rgba(74, 144, 226, 1)',
                            backgroundColor: 'rgba(74, 144, 226, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            pointBackgroundColor: 'rgba(74, 144, 226, 1)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            yAxisID: 'y'
                        },
                        {
                            label: 'کۆی بڕی فرۆشتن (دینار)',
                            data: dailySalesData.map(item => parseFloat(item.total_amount)),
                            borderColor: 'rgba(139, 92, 246, 1)',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            pointBackgroundColor: 'rgba(139, 92, 246, 1)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                padding: 15,
                                font: {
                                    size: 12,
                                    family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                                },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.datasetIndex === 1) {
                                        label += new Intl.NumberFormat('en-US').format(context.parsed.y) + ' دینار';
                                    } else {
                                        label += context.parsed.y;
                                    }
                                    return label;
                                }
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            cornerRadius: 8,
                            titleFont: {
                                size: 14
                            },
                            bodyFont: {
                                size: 13
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'ژمارەی فرۆشتنەکان',
                                font: {
                                    size: 11
                                }
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)',
                                drawBorder: false
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'کۆی بڕ (دینار)',
                                font: {
                                    size: 11
                                }
                            },
                            ticks: {
                                callback: function(value) {
                                    return new Intl.NumberFormat('en-US').format(value);
                                },
                                font: {
                                    size: 11
                                }
                            },
                            grid: {
                                display: false
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 10
                                },
                                maxRotation: 45,
                                minRotation: 45
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Payment Methods Doughnut Chart
        const paymentMethodsCtx = document.getElementById('paymentMethodsChart');
        if (paymentMethodsCtx && paymentMethodsData.length > 0) {
            const gradientColors = [
                'rgba(102, 126, 234, 0.8)',
                'rgba(139, 92, 246, 0.8)',
                'rgba(74, 144, 226, 0.8)',
                'rgba(17, 153, 142, 0.8)',
                'rgba(250, 139, 255, 0.8)',
                'rgba(43, 210, 255, 0.8)'
            ];

            const borderColors = [
                'rgba(102, 126, 234, 1)',
                'rgba(139, 92, 246, 1)',
                'rgba(74, 144, 226, 1)',
                'rgba(17, 153, 142, 1)',
                'rgba(250, 139, 255, 1)',
                'rgba(43, 210, 255, 1)'
            ];

            new Chart(paymentMethodsCtx, {
                type: 'doughnut',
                data: {
                    labels: paymentMethodsData.map(item => item.payment_method || 'نادیار'),
                    datasets: [{
                        label: 'ژمارەی وەسڵەکان',
                        data: paymentMethodsData.map(item => parseInt(item.count)),
                        backgroundColor: gradientColors.slice(0, paymentMethodsData.length),
                        borderColor: borderColors.slice(0, paymentMethodsData.length),
                        borderWidth: 2,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 12,
                                font: {
                                    size: 11,
                                    family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                                },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += context.parsed + ' وەسڵ';
                                    
                                    // Add percentage
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    label += ' (' + percentage + '%)';
                                    
                                    return label;
                                }
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            cornerRadius: 8,
                            titleFont: {
                                size: 13
                            },
                            bodyFont: {
                                size: 12
                            }
                        }
                    }
                }
            });
        }
    </script>
    <?php endif; ?>
</body>
</html>


