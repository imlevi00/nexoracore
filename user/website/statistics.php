<?php
/**
 * Order Statistics - user/website/statistics.php
 * View statistics for online shop orders
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// Check user authentication
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Check if user is main user (not sub-user)
if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    header('Location: ' . url('user/dashboard/index.php'));
    exit;
}

// Get website settings
$stmt = $conn->prepare("SELECT * FROM website_settings WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$websiteSettings = $result->fetch_assoc();
$stmt->close();

if (!$websiteSettings) {
    header('Location: ' . url('user/website/index.php'));
    exit;
}

// Get date ranges
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$yearStart = date('Y-01-01');

// Function to get statistics for a date range
function getStatistics($conn, $userId, $startDate, $endDate = null) {
    $stats = [
        'total_orders' => 0,
        'pending_orders' => 0,
        'completed_orders' => 0,
        'cancelled_orders' => 0,
        'total_revenue' => 0,
        'completed_revenue' => 0,
        'total_profit' => 0,
        'completed_profit' => 0
    ];
    
    $dateCondition = "DATE(created_at) >= ?";
    $params = "is";
    $paramValues = [$userId, $startDate];
    
    if ($endDate) {
        $dateCondition .= " AND DATE(created_at) <= ?";
        $params .= "s";
        $paramValues[] = $endDate;
    }
    
    // Get total orders and revenue
    $query = "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                SUM(total_amount) as total_revenue,
                SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as completed_revenue
              FROM web_orders 
              WHERE user_id = ? AND {$dateCondition}";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($params, ...$paramValues);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        $stats['total_orders'] = intval($row['total_orders']);
        $stats['pending_orders'] = intval($row['pending_orders']);
        $stats['completed_orders'] = intval($row['completed_orders']);
        $stats['cancelled_orders'] = intval($row['cancelled_orders']);
        $stats['total_revenue'] = floatval($row['total_revenue']);
        $stats['completed_revenue'] = floatval($row['completed_revenue']);
    }
    
    // Calculate profit by parsing order items
    $profitQuery = "SELECT id, items, status FROM web_orders WHERE user_id = ? AND {$dateCondition}";
    $profitStmt = $conn->prepare($profitQuery);
    $profitStmt->bind_param($params, ...$paramValues);
    $profitStmt->execute();
    $profitResult = $profitStmt->get_result();
    
    while ($order = $profitResult->fetch_assoc()) {
        $items = json_decode($order['items'], true);
        if (!$items || !is_array($items)) continue;
        
        $orderProfit = 0;
        
        foreach ($items as $item) {
            $quantity = floatval($item['quantity'] ?? 0);
            $sellPrice = floatval($item['price'] ?? 0);
            $productId = isset($item['id']) ? intval($item['id']) : 0;
            $unitId = isset($item['unitId']) ? intval($item['unitId']) : 0;
            
            if ($quantity <= 0 || $productId <= 0) continue;
            
            // Get buy price
            $buyPrice = 0;
            if ($unitId > 0) {
                // Get buy_price from product_units
                $priceStmt = $conn->prepare("
                    SELECT COALESCE(
                        pu.buy_price,
                        (
                            SELECT pu2.buy_price
                            FROM product_units pu2
                            WHERE pu2.product_id = p.id
                            ORDER BY pu2.is_primary DESC, pu2.id ASC
                            LIMIT 1
                        ),
                        0
                    ) as buy_price
                    FROM product_units pu
                    INNER JOIN products p ON pu.product_id = p.id
                    WHERE pu.id = ? AND p.user_id = ?
                ");
                $priceStmt->bind_param("ii", $unitId, $userId);
                $priceStmt->execute();
                $priceResult = $priceStmt->get_result();
                if ($priceRow = $priceResult->fetch_assoc()) {
                    $buyPrice = floatval($priceRow['buy_price']);
                }
                $priceStmt->close();
            } else {
                // Get buy_price from primary/fallback product unit
                $priceStmt = $conn->prepare("
                    SELECT COALESCE(
                        pu_primary.buy_price,
                        pu_any.buy_price,
                        0
                    ) AS buy_price
                    FROM products p
                    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    LEFT JOIN product_units pu_any ON pu_any.id = (
                        SELECT pu2.id
                        FROM product_units pu2
                        WHERE pu2.product_id = p.id
                        ORDER BY pu2.is_primary DESC, pu2.id ASC
                        LIMIT 1
                    )
                    WHERE p.id = ? AND p.user_id = ?
                ");
                $priceStmt->bind_param("ii", $productId, $userId);
                $priceStmt->execute();
                $priceResult = $priceStmt->get_result();
                if ($priceRow = $priceResult->fetch_assoc()) {
                    $buyPrice = floatval($priceRow['buy_price']);
                }
                $priceStmt->close();
            }
            
            // Calculate profit for this item
            $itemProfit = ($sellPrice - $buyPrice) * $quantity;
            $orderProfit += $itemProfit;
        }
        
        $stats['total_profit'] += $orderProfit;
        if ($order['status'] === 'completed') {
            $stats['completed_profit'] += $orderProfit;
        }
    }
    
    $profitStmt->close();
    
    return $stats;
}

// Function to display statistics card
function displayStatsCard($stats) {
    if ($stats['total_orders'] > 0): ?>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon bg-primary">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">کۆی گشتی وەسڵەکان</div>
                        <div class="stat-value"><?php echo $stats['total_orders']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon bg-success">
                        <i class="bi bi-currency-exchange"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">کۆی داهات</div>
                        <div class="stat-value"><?php echo number_format($stats['total_revenue'], 0); ?> <small>دینار</small></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon bg-info">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">کۆی قازانج</div>
                        <div class="stat-value text-info"><?php echo number_format($stats['total_profit'], 0); ?> <small>دینار</small></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon-sm bg-success-subtle">
                        <i class="bi bi-check-circle text-success"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">تەواوبووەکان</div>
                        <div class="stat-value text-success"><?php echo $stats['completed_orders']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon-sm bg-warning-subtle">
                        <i class="bi bi-clock-history text-warning"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">ناردراوەکان</div>
                        <div class="stat-value text-warning"><?php echo $stats['pending_orders']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon-sm bg-danger-subtle">
                        <i class="bi bi-x-circle text-danger"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">هەڵوەشاوەکان</div>
                        <div class="stat-value text-danger"><?php echo $stats['cancelled_orders']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p>هیچ وەسڵێک نییە لەم ماوەیەدا</p>
        </div>
    <?php endif;
}

// Get statistics for different periods
$todayStats = getStatistics($conn, $userId, $today, $today);
$weekStats = getStatistics($conn, $userId, $weekStart);
$monthStats = getStatistics($conn, $userId, $monthStart);
$yearStats = getStatistics($conn, $userId, $yearStart);

// Get daily statistics for the current month (for chart)
$dailyStatsQuery = "SELECT 
                        DATE(created_at) as order_date,
                        COUNT(*) as total_orders,
                        SUM(total_amount) as daily_revenue
                    FROM web_orders 
                    WHERE user_id = ? AND DATE(created_at) >= ?
                    GROUP BY DATE(created_at)
                    ORDER BY order_date ASC";

$dailyStatsStmt = $conn->prepare($dailyStatsQuery);
$dailyStatsStmt->bind_param("is", $userId, $monthStart);
$dailyStatsStmt->execute();
$dailyStatsResult = $dailyStatsStmt->get_result();
$dailyStats = $dailyStatsResult->fetch_all(MYSQLI_ASSOC);
$dailyStatsStmt->close();

// Calculate daily profit
foreach ($dailyStats as &$dayStat) {
    $orderDate = $dayStat['order_date'];
    $dailyProfit = 0;
    
    // Get orders for this day
    $dayOrdersQuery = "SELECT id, items FROM web_orders WHERE user_id = ? AND DATE(created_at) = ?";
    $dayOrdersStmt = $conn->prepare($dayOrdersQuery);
    $dayOrdersStmt->bind_param("is", $userId, $orderDate);
    $dayOrdersStmt->execute();
    $dayOrdersResult = $dayOrdersStmt->get_result();
    
    while ($order = $dayOrdersResult->fetch_assoc()) {
        $items = json_decode($order['items'], true);
        if (!$items || !is_array($items)) continue;
        
        foreach ($items as $item) {
            $quantity = floatval($item['quantity'] ?? 0);
            $sellPrice = floatval($item['price'] ?? 0);
            $productId = isset($item['id']) ? intval($item['id']) : 0;
            $unitId = isset($item['unitId']) ? intval($item['unitId']) : 0;
            
            if ($quantity <= 0 || $productId <= 0) continue;
            
            $buyPrice = 0;
            if ($unitId > 0) {
                $priceStmt = $conn->prepare("
                    SELECT COALESCE(
                        pu.buy_price,
                        (
                            SELECT pu2.buy_price
                            FROM product_units pu2
                            WHERE pu2.product_id = p.id
                            ORDER BY pu2.is_primary DESC, pu2.id ASC
                            LIMIT 1
                        ),
                        0
                    ) as buy_price
                    FROM product_units pu
                    INNER JOIN products p ON pu.product_id = p.id
                    WHERE pu.id = ? AND p.user_id = ?
                ");
                $priceStmt->bind_param("ii", $unitId, $userId);
                $priceStmt->execute();
                $priceResult = $priceStmt->get_result();
                if ($priceRow = $priceResult->fetch_assoc()) {
                    $buyPrice = floatval($priceRow['buy_price']);
                }
                $priceStmt->close();
            } else {
                $priceStmt = $conn->prepare("
                    SELECT COALESCE(
                        pu_primary.buy_price,
                        pu_any.buy_price,
                        0
                    ) AS buy_price
                    FROM products p
                    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    LEFT JOIN product_units pu_any ON pu_any.id = (
                        SELECT pu2.id
                        FROM product_units pu2
                        WHERE pu2.product_id = p.id
                        ORDER BY pu2.is_primary DESC, pu2.id ASC
                        LIMIT 1
                    )
                    WHERE p.id = ? AND p.user_id = ?
                ");
                $priceStmt->bind_param("ii", $productId, $userId);
                $priceStmt->execute();
                $priceResult = $priceStmt->get_result();
                if ($priceRow = $priceResult->fetch_assoc()) {
                    $buyPrice = floatval($priceRow['buy_price']);
                }
                $priceStmt->close();
            }
            
            $dailyProfit += ($sellPrice - $buyPrice) * $quantity;
        }
    }
    
    $dayOrdersStmt->close();
    $dayStat['daily_profit'] = $dailyProfit;
}
unset($dayStat);

// Prepare data for chart
$chartLabels = [];
$chartOrders = [];
$chartRevenue = [];
$chartProfit = [];

foreach ($dailyStats as $dayStat) {
    $chartLabels[] = date('m/d', strtotime($dayStat['order_date']));
    $chartOrders[] = $dayStat['total_orders'];
    $chartRevenue[] = $dayStat['daily_revenue'];
    $chartProfit[] = $dayStat['daily_profit'];
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ئامارەکانی وەسڵەکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
    
    <style>
        :root {
            --page-bg: #f5f5f5;
            --panel-bg: #ffffff;
            --surface-bg: #f8f9fa;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --border-soft: #e9ecef;
            --hover-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        [data-bs-theme="dark"],
        body.dark-mode {
            --page-bg: #0f172a;
            --panel-bg: #1e293b;
            --surface-bg: #334155;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --border-soft: #475569;
            --hover-shadow: 0 10px 20px rgba(0,0,0,0.35);
        }

        body {
            background: var(--page-bg);
            color: var(--text-primary);
        }

        .page-header {
            background: var(--panel-bg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .stats-container {
            background: var(--panel-bg);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .nav-tabs {
            border-bottom: 2px solid var(--border-soft);
            padding: 0 1.5rem;
        }

        .nav-tabs .nav-link {
            border: none;
            color: var(--text-secondary);
            font-weight: 500;
            padding: 1rem 1.5rem;
            margin-bottom: -2px;
        }

        .nav-tabs .nav-link:hover {
            color: var(--primary-color);
            border-color: transparent;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            background: transparent;
        }

        .tab-content {
            padding: 2rem 1.5rem;
        }

        .stat-card {
            background: var(--surface-bg);
            border-radius: 8px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--hover-shadow);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            flex-shrink: 0;
        }

        .stat-icon-sm {
            width: 45px;
            height: 45px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-value small {
            font-size: 0.875rem;
            font-weight: 500;
        }

        .chart-wrapper {
            background: var(--surface-bg);
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state p {
            margin: 0;
            font-size: 1.1rem;
        }

        [data-bs-theme="dark"] .text-muted,
        body.dark-mode .text-muted {
            color: var(--text-secondary) !important;
        }
    </style>
</head>
<body class="website-module-page website-stats-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">
        
        <!-- Header -->
        <div class="page-header">
            <h2 class="mb-2 fw-bold" style="color: var(--text-primary);">
                <i class="bi bi-graph-up-arrow text-primary"></i>
                ئامارەکانی وەسڵەکان
            </h2>
            <p class="text-muted mb-0">
                <i class="bi bi-info-circle"></i>
                بینینی ئامارەکانی ماوەکانی جیاواز
            </p>
        </div>

        <!-- Statistics Tabs -->
        <div class="stats-container">
            <ul class="nav nav-tabs" id="statsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="today-tab" data-bs-toggle="tab" data-bs-target="#today" type="button" role="tab">
                        <i class="bi bi-calendar-day"></i> ئەمرۆ
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="week-tab" data-bs-toggle="tab" data-bs-target="#week" type="button" role="tab">
                        <i class="bi bi-calendar-week"></i> ئەم هەفتەیە
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="month-tab" data-bs-toggle="tab" data-bs-target="#month" type="button" role="tab">
                        <i class="bi bi-calendar-month"></i> ئەم مانگە
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="year-tab" data-bs-toggle="tab" data-bs-target="#year" type="button" role="tab">
                        <i class="bi bi-calendar-range"></i> ئەمساڵ
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="statsTabContent">
                <!-- Today Tab -->
                <div class="tab-pane fade show active" id="today" role="tabpanel">
                    <?php displayStatsCard($todayStats); ?>
                </div>

                <!-- Week Tab -->
                <div class="tab-pane fade" id="week" role="tabpanel">
                    <?php displayStatsCard($weekStats); ?>
                </div>

                <!-- Month Tab -->
                <div class="tab-pane fade" id="month" role="tabpanel">
                    <?php displayStatsCard($monthStats); ?>
                    
                    <?php if ($monthStats['total_orders'] > 0 && !empty($dailyStats)): ?>
                    <div class="chart-wrapper">
                        <div class="chart-title">
                            <i class="bi bi-bar-chart-line-fill"></i>
                            نەخشەی ڕۆژانەی ئەم مانگە
                        </div>
                        <canvas id="monthlyChart" style="max-height: 350px;"></canvas>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Year Tab -->
                <div class="tab-pane fade" id="year" role="tabpanel">
                    <?php displayStatsCard($yearStats); ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <?php if (!empty($dailyStats)): ?>
    <script>
        // Monthly Chart
        const ctx = document.getElementById('monthlyChart');
        
        const isDarkMode = document.body.classList.contains('dark-mode') || document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const chartTextColor = isDarkMode ? '#cbd5e1' : '#334155';
        const chartGridColor = isDarkMode ? 'rgba(148, 163, 184, 0.25)' : 'rgba(15, 23, 42, 0.1)';

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: 'ژمارەی وەسڵەکان',
                        data: <?php echo json_encode($chartOrders); ?>,
                        backgroundColor: 'rgba(13, 110, 253, 0.6)',
                        borderColor: 'rgb(13, 110, 253)',
                        borderWidth: 2,
                        yAxisID: 'y'
                    },
                    {
                        label: 'داهات (دینار)',
                        data: <?php echo json_encode($chartRevenue); ?>,
                        backgroundColor: 'rgba(25, 135, 84, 0.6)',
                        borderColor: 'rgb(25, 135, 84)',
                        borderWidth: 2,
                        type: 'line',
                        yAxisID: 'y1'
                    },
                    {
                        label: 'قازانج (دینار)',
                        data: <?php echo json_encode($chartProfit); ?>,
                        backgroundColor: 'rgba(13, 202, 240, 0.6)',
                        borderColor: 'rgb(13, 202, 240)',
                        borderWidth: 2,
                        type: 'line',
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: chartTextColor,
                            font: {
                                size: 13,
                                family: 'Arial'
                            },
                            padding: 12
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 10,
                        titleFont: {
                            size: 13
                        },
                        bodyFont: {
                            size: 12
                        },
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    if (context.datasetIndex === 1 || context.datasetIndex === 2) {
                                        label += new Intl.NumberFormat('en-US').format(context.parsed.y) + ' دینار';
                                    } else {
                                        label += context.parsed.y;
                                    }
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'ژمارەی وەسڵەکان',
                            color: chartTextColor,
                            font: {
                                size: 12
                            }
                        },
                        ticks: {
                            color: chartTextColor,
                            stepSize: 1
                        },
                        grid: {
                            color: chartGridColor
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'داهات و قازانج (دینار)',
                            color: chartTextColor,
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                        ticks: {
                            color: chartTextColor,
                            callback: function(value) {
                                return new Intl.NumberFormat('en-US').format(value);
                            }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'بەروار',
                            color: chartTextColor,
                            font: {
                                size: 12
                            }
                        },
                        ticks: {
                            color: chartTextColor
                        },
                        grid: {
                            color: chartGridColor
                        }
                    }
                }
            }
        });
    </script>
    <?php endif; ?>

</body>
</html>
