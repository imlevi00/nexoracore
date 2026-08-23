<?php
require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/theme_bootstrap.php';

// تاقیکردنی داخڵبوون
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'customers.view', [
    'route' => '/user/customers/print/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

$showZeroDebt = !isset($_GET['show_zero_debt']) || $_GET['show_zero_debt'] === '1';
$regionFilter = $_GET['region'] ?? 'all';

// وەرگرتنی ناوچەکان بۆ فلتەر
$regionsStmt = $conn->prepare("
    SELECT cr.id, cr.name, COUNT(c.id) AS customer_count
    FROM customer_regions cr
    LEFT JOIN customers c ON c.region_id = cr.id AND c.user_id = cr.user_id
    WHERE cr.user_id = ?
    GROUP BY cr.id, cr.name
    ORDER BY cr.name ASC
");
$regionsStmt->bind_param("i", $userId);
$regionsStmt->execute();
$customerRegions = $regionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$regionsStmt->close();

$selectedRegionLabel = 'هەموو ناوچەکان';
if ($regionFilter === '__no_region__') {
    $selectedRegionLabel = 'بێ ناوچە';
} elseif ($regionFilter !== 'all' && ctype_digit((string)$regionFilter)) {
    foreach ($customerRegions as $regionOption) {
        if ((string)$regionOption['id'] === (string)$regionFilter) {
            $selectedRegionLabel = $regionOption['name'];
            break;
        }
    }
}

// وەرگرتنی لیستی کڕیاران بۆ چاپ (بێ پەیجینەیشن)
$whereConditions = ["c.user_id = ?"];
$params = [$userId];
$types = 'i';

if ($regionFilter === '__no_region__') {
    $whereConditions[] = "(c.region_id IS NULL OR c.region_id = 0)";
} elseif ($regionFilter !== 'all' && ctype_digit((string)$regionFilter)) {
    $whereConditions[] = "c.region_id = ?";
    $params[] = (int)$regionFilter;
    $types .= 'i';
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

$query = "
    SELECT c.id,
           c.name,
           c.phone,
           cr.name AS region_name,
           COUNT(d.id) as active_debts,
           COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_iqd,
           COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_usd
    FROM customers c
    LEFT JOIN customer_regions cr ON cr.id = c.region_id AND cr.user_id = c.user_id
    LEFT JOIN debts d ON c.id = d.customer_id AND d.status = 'active'
    LEFT JOIN sales s ON d.sale_id = s.id
    $whereClause
    GROUP BY c.id, c.name, c.phone, cr.name
    ORDER BY cr.name ASC, c.name ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$filteredCustomers = array_values(array_filter($customers, static function ($customer) use ($showZeroDebt) {
    if ($showZeroDebt) {
        return true;
    }

    $debtIqd = (float)($customer['current_debt_iqd'] ?? 0);
    $debtUsd = (float)($customer['current_debt_usd'] ?? 0);
    return $debtIqd > 0.00001 || $debtUsd > 0.00001;
}));
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>چاپکردنی لیستی کڕیاران - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-dark.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-responsive.css'); ?>" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .print-header {
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
        }

        .print-controls {
            margin-bottom: 1rem;
        }

        .print-region-label {
            color: #0d6efd;
            font-weight: 600;
        }

        .table thead th {
            background-color: #f1f3f5;
        }

        @media print {
            body {
                background-color: #ffffff;
            }

            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .card,
            html[data-bs-theme='dark'] .table,
            html[data-bs-theme='dark'] .table th,
            html[data-bs-theme='dark'] .table td,
            html[data-bs-theme='dark'] .text-muted {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #d1d5db !important;
            }

            .print-controls {
                display: none !important;
            }

            .print-header {
                margin-bottom: 0.5rem;
                border-bottom-width: 1px;
            }

            .print-region-label {
                color: #000000 !important;
            }

            .card {
                border: none;
                box-shadow: none;
            }
        }
    </style>
</head>
<body class="customers-module-page customers-print-page">
    <div class="container-fluid py-3 customers-page-content">
        <div class="print-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">
                    <?php echo htmlspecialchars($currentUser['business_name']); ?>
                </h5>
                <small class="text-muted">لیستی کڕیاران بۆ چاپ</small>
                <?php if ($regionFilter !== 'all'): ?>
                    <div class="small mt-1 print-region-label">
                        ناوچە: <?php echo htmlspecialchars($selectedRegionLabel); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="text-muted small">
                <div>بەروار: <?php echo date('Y/m/d'); ?></div>
                <div>کات: <?php echo date('H:i'); ?></div>
            </div>
        </div>

        <div class="print-controls d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <button class="btn btn-primary btn-sm" onclick="window.print()">
                    چاپکردن
                </button>
                <a href="<?php echo url('user/customers/index.php'); ?>" class="btn btn-outline-secondary btn-sm">
                    گەڕانەوە بۆ لیستی کڕیاران
                </a>
            </div>
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <label for="region" class="form-label mb-0 small text-muted">ناوچە:</label>
                <select id="region" name="region" class="form-select form-select-sm">
                    <option value="all" <?php echo $regionFilter === 'all' ? 'selected' : ''; ?>>هەموو ناوچەکان</option>
                    <option value="__no_region__" <?php echo $regionFilter === '__no_region__' ? 'selected' : ''; ?>>بێ ناوچە</option>
                    <?php foreach ($customerRegions as $regionOption): ?>
                        <option value="<?php echo (int)$regionOption['id']; ?>" <?php echo (string)$regionFilter === (string)$regionOption['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($regionOption['name']); ?> (<?php echo (int)$regionOption['customer_count']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="show_zero_debt" class="form-label mb-0 small text-muted">فلتەری قەرز:</label>
                <select id="show_zero_debt" name="show_zero_debt" class="form-select form-select-sm">
                    <option value="1" <?php echo $showZeroDebt ? 'selected' : ''; ?>>هەموو کڕیارەکان (قەرزی 0 لەگەڵ)</option>
                    <option value="0" <?php echo !$showZeroDebt ? 'selected' : ''; ?>>تەنها کڕیارانی قەرزدار</option>
                </select>
                <button type="submit" class="btn btn-outline-primary btn-sm">جێبەجێکردن</button>
            </form>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body p-0">
                <?php if (empty($filteredCustomers)): ?>
                    <div class="text-center py-5">
                        <p class="text-muted mb-0">هیچ کڕیارێک بۆ چاپ نییە</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#</th>
                                    <th>ناوی کڕیار</th>
                                    <th>ژمارەی تەلەفۆن</th>
                                    <th>ناوچە</th>
                                    <th>قەرزی دینار</th>
                                    <th>قەرزی دۆلار</th>
                                    <th>ژمارەی قەرزە چالاکەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $index = 1; ?>
                                <?php foreach ($filteredCustomers as $customer): ?>
                                    <?php
                                        $debtIqd = (float)($customer['current_debt_iqd'] ?? 0);
                                        $debtUsd = (float)($customer['current_debt_usd'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?php echo $index++; ?></td>
                                        <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['phone'] ?: '-'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['region_name'] ?: 'بێ ناوچە'); ?></td>
                                        <td><?php echo formatMoney($debtIqd, 'IQD'); ?></td>
                                        <td><?php echo formatMoney($debtUsd, 'USD'); ?></td>
                                        <td><?php echo (int)$customer['active_debts']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-center mt-2">
            <div class="text-center px-4 py-2 border border-2 border-primary rounded-pill" style="border-style: dashed;">
                <div class="fw-semibold">سیستەمی NexoraCore</div>
                <div class="small text-muted">NexoraCore.com</div>
            </div>
        </div>
    </div>
</body>
</html>

