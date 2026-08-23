<?php
/**
 * ئاماری خەرجیەکانی فرۆشگا - user/expenses/statistics.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expense_stats.view', [
    'route' => '/user/expenses/statistics.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

// ماوەی فیلتەر
$period = $_GET['period'] ?? 'month';
$custom_from = trim($_GET['custom_from'] ?? '');
$custom_to = trim($_GET['custom_to'] ?? '');

$date_params = [$userId];
$date_types = 'i';
$date_condition = "AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$period_title = "مانگی ڕابردوو";

switch ($period) {
    case 'week':
        $date_condition = "AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $period_title = "هەفتەی ڕابردوو";
        break;
    case 'month':
        $date_condition = "AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $period_title = "مانگی ڕابردوو";
        break;
    case 'year':
        $date_condition = "AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
        $period_title = "ساڵی ڕابردوو";
        break;
    case 'custom':
        if (!empty($custom_from) && !empty($custom_to)) {
            $date_condition = "AND DATE(e.expense_date) BETWEEN ? AND ?";
            $date_params[] = $custom_from;
            $date_params[] = $custom_to;
            $date_types .= 'ss';
            $period_title = "لە " . htmlspecialchars($custom_from) . " تا " . htmlspecialchars($custom_to);
        }
        break;
}

// ئاماری گشتی
$total_stats = [
    'total_count' => 0,
    'total_iqd' => 0,
    'total_usd' => 0,
    'cash_iqd' => 0,
    'cash_usd' => 0,
    'credit_iqd' => 0,
    'credit_usd' => 0,
    'avg_iqd' => 0,
    'avg_usd' => 0
];
$total_stats_query = "
    SELECT
        COUNT(*) as total_count,
        SUM(CASE WHEN e.currency = 'IQD' THEN e.amount ELSE 0 END) as total_iqd,
        SUM(CASE WHEN e.currency = 'USD' THEN e.amount ELSE 0 END) as total_usd,
        SUM(CASE WHEN e.currency = 'IQD' AND e.payment_method = 'cash'   THEN e.amount ELSE 0 END) as cash_iqd,
        SUM(CASE WHEN e.currency = 'USD' AND e.payment_method = 'cash'   THEN e.amount ELSE 0 END) as cash_usd,
        SUM(CASE WHEN e.currency = 'IQD' AND e.payment_method = 'credit' THEN e.amount ELSE 0 END) as credit_iqd,
        SUM(CASE WHEN e.currency = 'USD' AND e.payment_method = 'credit' THEN e.amount ELSE 0 END) as credit_usd,
        AVG(CASE WHEN e.currency = 'IQD' THEN e.amount END) as avg_iqd,
        AVG(CASE WHEN e.currency = 'USD' THEN e.amount END) as avg_usd
    FROM expenses e
    WHERE e.user_id = ? $date_condition
";

$tstmt = $conn->prepare($total_stats_query);
if ($tstmt) {
    $tstmt->bind_param($date_types, ...$date_params);
    $tstmt->execute();
    $trow = $tstmt->get_result()->fetch_assoc();
    if ($trow) {
        $total_stats = $trow;
    }
    $tstmt->close();
}

// ئاماری ڕۆژانە
$daily_stats = [];
$daily_stats_query = "
    SELECT
        DATE(e.expense_date) as expense_day,
        COUNT(*) as day_count,
        SUM(CASE WHEN e.currency = 'IQD' THEN e.amount ELSE 0 END) as day_amount_iqd,
        SUM(CASE WHEN e.currency = 'USD' THEN e.amount ELSE 0 END) as day_amount_usd,
        SUM(CASE WHEN e.currency = 'IQD' AND e.payment_method = 'cash'   THEN e.amount ELSE 0 END) as day_cash_iqd,
        SUM(CASE WHEN e.currency = 'USD' AND e.payment_method = 'cash'   THEN e.amount ELSE 0 END) as day_cash_usd,
        SUM(CASE WHEN e.currency = 'IQD' AND e.payment_method = 'credit' THEN e.amount ELSE 0 END) as day_credit_iqd,
        SUM(CASE WHEN e.currency = 'USD' AND e.payment_method = 'credit' THEN e.amount ELSE 0 END) as day_credit_usd
    FROM expenses e
    WHERE e.user_id = ? $date_condition
    GROUP BY DATE(e.expense_date)
    ORDER BY expense_day DESC
    LIMIT 15
";
$dstmt = $conn->prepare($daily_stats_query);
if ($dstmt) {
    $dstmt->bind_param($date_types, ...$date_params);
    $dstmt->execute();
    $daily_stats = $dstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dstmt->close();
}

// ئاماری بەپێی جۆری خەرجی
$type_stats = [];
$type_stats_query = "
    SELECT
        et.name as type_name,
        COUNT(e.id) as type_count,
        SUM(CASE WHEN e.currency = 'IQD' THEN e.amount ELSE 0 END) as type_amount_iqd,
        SUM(CASE WHEN e.currency = 'USD' THEN e.amount ELSE 0 END) as type_amount_usd,
        SUM(e.amount) as type_amount_sort
    FROM expenses e
    LEFT JOIN expense_types et ON e.expense_type_id = et.id
    WHERE e.user_id = ? $date_condition
    GROUP BY et.id, et.name
    ORDER BY type_amount_sort DESC
    LIMIT 10
";
$typ_stmt = $conn->prepare($type_stats_query);
if ($typ_stmt) {
    $typ_stmt->bind_param($date_types, ...$date_params);
    $typ_stmt->execute();
    $type_stats = $typ_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $typ_stmt->close();
}

// زۆرترین خەرجیەکان
$top_expenses = [];
$top_expenses_query = "
    SELECT
        e.expense_name,
        e.amount,
        e.currency,
        e.payment_method,
        e.expense_date,
        et.name as type_name
    FROM expenses e
    LEFT JOIN expense_types et ON e.expense_type_id = et.id
    WHERE e.user_id = ? $date_condition
    ORDER BY e.amount DESC
    LIMIT 10
";
$top_stmt = $conn->prepare($top_expenses_query);
if ($top_stmt) {
    $top_stmt->bind_param($date_types, ...$date_params);
    $top_stmt->execute();
    $top_expenses = $top_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $top_stmt->close();
}

// ئاماری هەفتانە (بۆ چارت)
$weekly_chart_data = [];
$weekly_chart_query = "
    SELECT 
        WEEK(expense_date) as week_num,
        YEAR(expense_date) as year_num,
        SUM(amount) as week_amount,
        COUNT(*) as week_count
    FROM expenses 
    WHERE user_id = ? 
    AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
    GROUP BY YEAR(expense_date), WEEK(expense_date)
    ORDER BY year_num, week_num
";
$wstmt = $conn->prepare($weekly_chart_query);
if ($wstmt) {
    $wstmt->bind_param("i", $userId);
    $wstmt->execute();
    $weekly_chart_data = $wstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $wstmt->close();
}

// ئاماری مانگانە (بۆ چارت)
$monthly_chart_data = [];
$monthly_chart_query = "
    SELECT 
        MONTH(expense_date) as month_num,
        YEAR(expense_date) as year_num,
        MONTHNAME(expense_date) as month_name,
        SUM(amount) as month_amount,
        COUNT(*) as month_count
    FROM expenses 
    WHERE user_id = ? 
    AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY YEAR(expense_date), MONTH(expense_date), MONTHNAME(expense_date)
    ORDER BY year_num, month_num
";

$mstmt = $conn->prepare($monthly_chart_query);
if ($mstmt) {
    $mstmt->bind_param("i", $userId);
    $mstmt->execute();
    $monthly_chart_data = $mstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mstmt->close();
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ئاماری خەرجیەکان - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/expenses/expenses-pages.css'); ?>" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="expenses-module-page expenses-stats-page">

    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container py-4 ex-wrap">

        <header class="ex-hero">
            <div>
                <div class="ex-kicker"><i class="bi bi-graph-up"></i> ئاماری خەرجی</div>
                <h1><i class="bi bi-bar-chart-line"></i> ئاماری خەرجیەکان</h1>
                <p class="ex-hero-sub">کۆی گشتی، نەقد، قەرز و خەرجی ڕۆژانە بۆ <?php echo htmlspecialchars($period_title); ?></p>
                <div class="ex-hero-pills">
                    <a href="?period=week" class="ex-pill <?php echo $period === 'week' ? 'is-active' : ''; ?>">هەفتەی ڕابردوو</a>
                    <a href="?period=month" class="ex-pill <?php echo $period === 'month' ? 'is-active' : ''; ?>">مانگی ڕابردوو</a>
                    <a href="?period=year" class="ex-pill <?php echo $period === 'year' ? 'is-active' : ''; ?>">ساڵی ڕابردوو</a>
                    <a href="?period=custom" class="ex-pill <?php echo $period === 'custom' ? 'is-active' : ''; ?>">ماوەی دیاریکراو</a>
                </div>
            </div>
            <div class="ex-actions">
                <a href="index.php" class="ex-btn ex-btn-ghost">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
                <a href="add.php" class="ex-btn ex-btn-primary">
                    <i class="bi bi-plus-lg"></i> خەرجی نوێ
                </a>
            </div>
        </header>

        <section class="ex-panel">
            <div class="ex-panel-head">
                <span><i class="bi bi-calendar3"></i> هەڵبژاردنی ماوەی کات</span>
            </div>
            <div class="ex-panel-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">ماوەی کات</label>
                        <select class="form-select" name="period" onchange="toggleCustomDates()">
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>هەفتەی ڕابردوو</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>مانگی ڕابردوو</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>ساڵی ڕابردوو</option>
                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>ماوەی دیاریکراو</option>
                        </select>
                    </div>

                    <div class="col-md-3" id="custom_from_div" style="<?php echo $period === 'custom' ? '' : 'display:none;'; ?>">
                        <label class="form-label">لە بەرواری</label>
                        <input type="date" class="form-control" name="custom_from" value="<?php echo htmlspecialchars($custom_from); ?>">
                    </div>

                    <div class="col-md-3" id="custom_to_div" style="<?php echo $period === 'custom' ? '' : 'display:none;'; ?>">
                        <label class="form-label">تا بەرواری</label>
                        <input type="date" class="form-control" name="custom_to" value="<?php echo htmlspecialchars($custom_to); ?>">
                    </div>

                    <div class="col-md-3">
                        <button type="submit" class="ex-btn ex-btn-primary">
                            <i class="bi bi-search"></i> نیشاندان
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle"></i>
            <strong>ئاماری <?php echo $period_title; ?></strong>
        </div>

        <div class="ex-stats ex-stats-4">
            <div class="ex-stat" style="--stat-accent:#6366f1">
                <div class="ex-stat-icon"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="ex-stat-label">کۆی گشتی</div>
                    <div class="ex-stat-value"><?php echo number_format($total_stats['total_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($total_stats['total_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($total_stats['total_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ex-stat" style="--stat-accent:#10b981">
                <div class="ex-stat-icon"><i class="bi bi-cash"></i></div>
                <div>
                    <div class="ex-stat-label">نەقد</div>
                    <div class="ex-stat-value"><?php echo number_format($total_stats['cash_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($total_stats['cash_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($total_stats['cash_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ex-stat" style="--stat-accent:#f59e0b">
                <div class="ex-stat-icon"><i class="bi bi-credit-card"></i></div>
                <div>
                    <div class="ex-stat-label">قەرز</div>
                    <div class="ex-stat-value"><?php echo number_format($total_stats['credit_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($total_stats['credit_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($total_stats['credit_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ex-stat" style="--stat-accent:#0ea5e9">
                <div class="ex-stat-icon"><i class="bi bi-calculator"></i></div>
                <div>
                    <div class="ex-stat-label">ناوەندی خەرجی</div>
                    <div class="ex-stat-value"><?php echo number_format($total_stats['avg_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($total_stats['avg_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($total_stats['avg_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-8">
                <section class="ex-panel">
                    <div class="ex-panel-head">
                        <span><i class="bi bi-bar-chart"></i> خەرجی ڕۆژانە</span>
                    </div>
                    <div class="ex-panel-body">
                        <div class="ex-chart">
                            <canvas id="dailyExpensesChart" height="100"></canvas>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-xl-4">
                <section class="ex-panel">
                    <div class="ex-panel-head">
                        <span><i class="bi bi-pie-chart"></i> شێوازەکانی پارەدان</span>
                    </div>
                    <div class="ex-panel-body">
                        <div class="ex-chart">
                            <canvas id="paymentMethodsChart" height="200"></canvas>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <section class="ex-panel">
                    <div class="ex-panel-head">
                        <span><i class="bi bi-list-stars"></i> زۆرترین جۆرەکانی خەرجی</span>
                    </div>
                    <div class="ex-panel-body">
                        <?php if (empty($type_stats)): ?>
                            <div class="ex-empty py-3">
                                <div class="ex-empty-icon"><i class="bi bi-inbox"></i></div>
                                <p>هیچ خەرجیەک نەدۆزرایەوە</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($type_stats as $index => $type): ?>
                            <div class="ex-rank">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="ex-rank-num"><?php echo $index + 1; ?></span>
                                    <div>
                                        <strong><?php echo htmlspecialchars($type['type_name'] ?: 'یەک جار'); ?></strong>
                                        <div class="ex-stat-meta"><?php echo $type['type_count']; ?> خەرجی</div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <strong class="text-danger"><?php echo formatDualCurrency($type['type_amount_iqd'] ?? 0, $type['type_amount_usd'] ?? 0, '<br>'); ?></strong>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <div class="col-lg-6">
                <section class="ex-panel">
                    <div class="ex-panel-head">
                        <span><i class="bi bi-trophy"></i> زۆرترین خەرجیەکان</span>
                    </div>
                    <div class="ex-panel-body">
                        <?php if (empty($top_expenses)): ?>
                            <div class="ex-empty py-3">
                                <div class="ex-empty-icon"><i class="bi bi-inbox"></i></div>
                                <p>هیچ خەرجیەک نەدۆزرایەوە</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($top_expenses as $index => $expense): ?>
                            <div class="ex-rank">
                                <div class="d-flex align-items-start gap-2">
                                    <span class="ex-rank-num"><?php echo $index + 1; ?></span>
                                    <div>
                                        <strong><?php echo htmlspecialchars($expense['expense_name']); ?></strong>
                                        <div class="ex-stat-meta">
                                            <?php echo date('Y/m/d', strtotime($expense['expense_date'])); ?>
                                            <?php if ($expense['type_name']): ?>
                                                - <?php echo htmlspecialchars($expense['type_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <strong class="text-danger"><?php echo formatCurrencyAmount($expense['amount'], $expense['currency'] ?? 'IQD'); ?></strong>
                                    <div>
                                        <?php if ($expense['payment_method'] === 'cash'): ?>
                                            <span class="badge bg-success">نەقد</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">قەرز</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>

        <?php if (!empty($daily_stats)): ?>
        <section class="ex-panel mt-4">
            <div class="ex-panel-head">
                <span><i class="bi bi-calendar-week"></i> وردەکاری ڕۆژانە</span>
            </div>
            <div class="ex-panel-body-flush">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 expenses-daily-table">
                        <thead>
                            <tr>
                                <th>بەروار</th>
                                <th>ژمارەی خەرجی</th>
                                <th>کۆی گشتی</th>
                                <th>نەقد</th>
                                <th>قەرز</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily_stats as $day): ?>
                            <tr>
                                <td data-label="بەروار">
                                    <strong><?php echo date('Y/m/d', strtotime($day['expense_day'])); ?></strong>
                                    <br><small class="text-muted"><?php echo date('l', strtotime($day['expense_day'])); ?></small>
                                </td>
                                <td data-label="ژمارەی خەرجی">
                                    <span class="badge bg-primary"><?php echo $day['day_count']; ?></span>
                                </td>
                                <td data-label="کۆی گشتی">
                                    <strong class="text-danger"><?php echo formatDualCurrency($day['day_amount_iqd'] ?? 0, $day['day_amount_usd'] ?? 0, '<br>'); ?></strong>
                                </td>
                                <td data-label="نەقد">
                                    <span class="text-success"><?php echo formatDualCurrency($day['day_cash_iqd'] ?? 0, $day['day_cash_usd'] ?? 0, '<br>'); ?></span>
                                </td>
                                <td data-label="قەرز">
                                    <span class="text-warning"><?php echo formatDualCurrency($day['day_credit_iqd'] ?? 0, $day['day_credit_usd'] ?? 0, '<br>'); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleCustomDates() {
            const period = document.querySelector('select[name="period"]').value;
            const customFromDiv = document.getElementById('custom_from_div');
            const customToDiv = document.getElementById('custom_to_div');
            
            if (period === 'custom') {
                customFromDiv.style.display = 'block';
                customToDiv.style.display = 'block';
            } else {
                customFromDiv.style.display = 'none';
                customToDiv.style.display = 'none';
            }
        }

        // Daily Expenses Chart
        const dailyData = <?php echo json_encode($daily_stats); ?>;
        const dailyCtx = document.getElementById('dailyExpensesChart').getContext('2d');
        
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dailyData.map(item => item.expense_day).reverse(),
                datasets: [{
                    label: 'خەرجی ڕۆژانە (دینار)',
                    data: dailyData.map(item => item.day_amount_iqd).reverse(),
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('en-US').format(value) + ' دینار';
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'خەرجی: ' + new Intl.NumberFormat('en-US').format(context.parsed.y) + ' دینار';
                            }
                        }
                    }
                }
            }
        });

        // Payment Methods Pie Chart
        const paymentCtx = document.getElementById('paymentMethodsChart').getContext('2d');
        
        new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: ['نەقد', 'قەرز'],
                datasets: [{
                    data: [
                        <?php echo (float)($total_stats['cash_iqd'] ?? 0); ?>,
                        <?php echo (float)($total_stats['credit_iqd'] ?? 0); ?>
                    ],
                    backgroundColor: [
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(251, 191, 36, 0.8)'
                    ],
                    borderColor: [
                        'rgb(34, 197, 94)',
                        'rgb(251, 191, 36)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = new Intl.NumberFormat('en-US').format(context.parsed);
                                return label + ': ' + value + ' دینار';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>