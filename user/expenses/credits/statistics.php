<?php
/**
 * ئاماری قەرزەکانی خەرجیەکان - user/expenses/credits/statistics.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expense_stats.view', [
    'route' => '/user/expenses/credits/statistics.php',
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
$date_condition = "AND ec.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$period_title = "مانگی ڕابردوو";

switch ($period) {
    case 'week':
        $date_condition = "AND ec.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $period_title = "هەفتەی ڕابردوو";
        break;
    case 'month':
        $date_condition = "AND ec.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $period_title = "مانگی ڕابردوو";
        break;
    case 'year':
        $date_condition = "AND ec.created_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
        $period_title = "ساڵی ڕابردوو";
        break;
    case 'custom':
        if (!empty($custom_from) && !empty($custom_to)) {
            $date_condition = "AND DATE(ec.created_at) BETWEEN ? AND ?";
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
    'paid_iqd' => 0,
    'paid_usd' => 0,
    'active_iqd' => 0,
    'active_usd' => 0,
    'overdue_iqd' => 0,
    'overdue_usd' => 0,
    'active_count' => 0,
    'completed_count' => 0,
    'overdue_count' => 0
];
$total_stats_query = "
    SELECT
        COUNT(*) as total_count,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.total_amount ELSE 0 END) as total_iqd,
        SUM(CASE WHEN ec.currency = 'USD' THEN ec.total_amount ELSE 0 END) as total_usd,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.paid_amount ELSE 0 END) as paid_iqd,
        SUM(CASE WHEN ec.currency = 'USD' THEN ec.paid_amount ELSE 0 END) as paid_usd,
        SUM(CASE WHEN ec.currency = 'IQD' AND ec.status IN ('active', 'pending')  THEN ec.remaining_amount ELSE 0 END) as active_iqd,
        SUM(CASE WHEN ec.currency = 'USD' AND ec.status IN ('active', 'pending')  THEN ec.remaining_amount ELSE 0 END) as active_usd,
        SUM(CASE WHEN ec.currency = 'IQD' AND ec.status = 'overdue' THEN ec.remaining_amount ELSE 0 END) as overdue_iqd,
        SUM(CASE WHEN ec.currency = 'USD' AND ec.status = 'overdue' THEN ec.remaining_amount ELSE 0 END) as overdue_usd,
        COUNT(CASE WHEN ec.status IN ('active', 'pending') THEN 1 END) as active_count,
        COUNT(CASE WHEN ec.status = 'completed' THEN 1 END) as completed_count,
        COUNT(CASE WHEN ec.status = 'overdue' THEN 1 END) as overdue_count
    FROM expense_credits ec
    WHERE ec.user_id = ? $date_condition
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

// ئاماری قەرزکارەکان
$creditors_stats = [];
$creditors_stats_query = "
    SELECT
        ec.creditor_name,
        COUNT(ec.id) as credit_count,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.total_amount ELSE 0 END) as total_iqd,
        SUM(CASE WHEN ec.currency = 'USD' THEN ec.total_amount ELSE 0 END) as total_usd,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.remaining_amount ELSE 0 END) as remaining_iqd,
        SUM(CASE WHEN ec.currency = 'USD' THEN ec.remaining_amount ELSE 0 END) as remaining_usd,
        SUM(ec.total_amount) as total_amount_sort
    FROM expense_credits ec
    WHERE ec.user_id = ? $date_condition
    GROUP BY ec.creditor_name
    ORDER BY total_amount_sort DESC
    LIMIT 10
";
$cstmt = $conn->prepare($creditors_stats_query);
if ($cstmt) {
    $cstmt->bind_param($date_types, ...$date_params);
    $cstmt->execute();
    $creditors_stats = $cstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cstmt->close();
}

// ئاماری مانگانە
$monthly_stats = [];
$monthly_stats_query = "
    SELECT 
        MONTH(ec.created_at) as month_num,
        YEAR(ec.created_at) as year_num,
        MONTHNAME(ec.created_at) as month_name,
        COUNT(ec.id) as credit_count,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.total_amount ELSE 0 END) as total_amount,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.paid_amount ELSE 0 END) as paid_amount
    FROM expense_credits ec
    WHERE ec.user_id = ?
    AND ec.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY YEAR(ec.created_at), MONTH(ec.created_at), MONTHNAME(ec.created_at)
    ORDER BY year_num, month_num
";
$mstmt = $conn->prepare($monthly_stats_query);
if ($mstmt) {
    $mstmt->bind_param("i", $userId);
    $mstmt->execute();
    $monthly_stats = $mstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mstmt->close();
}

// ئاماری پارەدانەوەکان
$payment_stats = [
    'total_payments' => 0,
    'avg_payment_iqd' => 0,
    'avg_payment_usd' => 0,
    'cash_payments' => 0,
    'bank_payments' => 0,
    'check_payments' => 0,
    'cash_amount_iqd' => 0,
    'cash_amount_usd' => 0,
    'bank_amount_iqd' => 0,
    'bank_amount_usd' => 0,
    'check_amount_iqd' => 0,
    'check_amount_usd' => 0
];
$payment_stats_query = "
    SELECT 
        COUNT(ecp.id) as total_payments,
        AVG(CASE WHEN ecp.currency = 'IQD' THEN ecp.payment_amount END) as avg_payment_iqd,
        AVG(CASE WHEN ecp.currency = 'USD' THEN ecp.payment_amount END) as avg_payment_usd,
        COUNT(CASE WHEN ecp.payment_method = 'cash' THEN 1 END) as cash_payments,
        COUNT(CASE WHEN ecp.payment_method = 'bank_transfer' THEN 1 END) as bank_payments,
        COUNT(CASE WHEN ecp.payment_method = 'check' THEN 1 END) as check_payments,
        SUM(CASE WHEN ecp.payment_method = 'cash' AND ecp.currency = 'IQD' THEN ecp.payment_amount ELSE 0 END) as cash_amount_iqd,
        SUM(CASE WHEN ecp.payment_method = 'cash' AND ecp.currency = 'USD' THEN ecp.payment_amount ELSE 0 END) as cash_amount_usd,
        SUM(CASE WHEN ecp.payment_method = 'bank_transfer' AND ecp.currency = 'IQD' THEN ecp.payment_amount ELSE 0 END) as bank_amount_iqd,
        SUM(CASE WHEN ecp.payment_method = 'bank_transfer' AND ecp.currency = 'USD' THEN ecp.payment_amount ELSE 0 END) as bank_amount_usd,
        SUM(CASE WHEN ecp.payment_method = 'check' AND ecp.currency = 'IQD' THEN ecp.payment_amount ELSE 0 END) as check_amount_iqd,
        SUM(CASE WHEN ecp.payment_method = 'check' AND ecp.currency = 'USD' THEN ecp.payment_amount ELSE 0 END) as check_amount_usd
    FROM expense_credit_payments ecp
    LEFT JOIN expense_credits ec ON ecp.expense_credit_id = ec.id
    WHERE ec.user_id = ? $date_condition
";
$p_stmt = $conn->prepare($payment_stats_query);
if ($p_stmt) {
    $p_stmt->bind_param($date_types, ...$date_params);
    $p_stmt->execute();
    $p_row = $p_stmt->get_result()->fetch_assoc();
    if ($p_row) {
        $payment_stats = $p_row;
    }
    $p_stmt->close();
}

// قەرزە درەنگکراوەکان
$overdue_credits = [];
$overdue_credits_query = "
    SELECT 
        ec.*,
        e.expense_name,
        DATEDIFF(CURDATE(), ec.due_date) as days_overdue
    FROM expense_credits ec
    LEFT JOIN expenses e ON ec.expense_id = e.id
    WHERE ec.user_id = ? 
    AND ec.status = 'overdue'
    AND ec.due_date < CURDATE()
    ORDER BY days_overdue DESC
    LIMIT 10
";
$od_stmt = $conn->prepare($overdue_credits_query);
if ($od_stmt) {
    $od_stmt->bind_param("i", $userId);
    $od_stmt->execute();
    $overdue_credits = $od_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $od_stmt->close();
}

// قەرزەکانی نزیک لە وادە
$upcoming_due = [];
$upcoming_due_query = "
    SELECT 
        ec.*,
        e.expense_name,
        DATEDIFF(ec.due_date, CURDATE()) as days_until_due
    FROM expense_credits ec
    LEFT JOIN expenses e ON ec.expense_id = e.id
    WHERE ec.user_id = ? 
    AND ec.status IN ('active', 'pending')
    AND ec.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY ec.due_date ASC
    LIMIT 10
";
$up_stmt = $conn->prepare($upcoming_due_query);
if ($up_stmt) {
    $up_stmt->bind_param("i", $userId);
    $up_stmt->execute();
    $upcoming_due = $up_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $up_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ئاماری قەرزەکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="expenses-module-page expenses-stats-page bg-light">

    <!-- Navigation -->
    <?php include_once '../../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container py-4">
        
        <!-- Page Header -->
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-graph-up text-success"></i>
                        ئاماری قەرزەکان
                    </h1>
                    <div>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Period Filter -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="bi bi-calendar3"></i> هەڵبژاردنی ماوەی کات
                </h6>
            </div>
            <div class="card-body">
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
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> نیشاندان
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Current Period Title -->
        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle"></i>
            <strong>ئاماری <?php echo $period_title; ?></strong>
        </div>

        <!-- Main Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">کۆی گشتی قەرز</h6>
                                <h4 class="card-title mb-0"><?php echo number_format($total_stats['total_iqd'] ?? 0, 0); ?> <small class="opacity-75">دینار</small></h4>
                                <?php if (($total_stats['total_usd'] ?? 0) != 0): ?>
                                    <h6 class="mb-0 mt-1"><?php echo formatCurrencyAmount($total_stats['total_usd'], 'USD'); ?></h6>
                                <?php endif; ?>
                            </div>
                            <i class="bi bi-cash-stack display-5 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">دراوەتەوە</h6>
                                <h4 class="card-title mb-0"><?php echo number_format($total_stats['paid_iqd'] ?? 0, 0); ?> <small class="opacity-75">دینار</small></h4>
                                <?php if (($total_stats['paid_usd'] ?? 0) != 0): ?>
                                    <h6 class="mb-0 mt-1"><?php echo formatCurrencyAmount($total_stats['paid_usd'], 'USD'); ?></h6>
                                <?php endif; ?>
                            </div>
                            <i class="bi bi-check-circle display-5 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <?php
                                    $rem_iqd = ($total_stats['active_iqd'] ?? 0) + ($total_stats['overdue_iqd'] ?? 0);
                                    $rem_usd = ($total_stats['active_usd'] ?? 0) + ($total_stats['overdue_usd'] ?? 0);
                                ?>
                                <h6 class="card-subtitle mb-2 opacity-75">ماوەتەوە</h6>
                                <h4 class="card-title mb-0"><?php echo number_format($rem_iqd, 0); ?> <small class="opacity-75">دینار</small></h4>
                                <?php if ($rem_usd != 0): ?>
                                    <h6 class="mb-0 mt-1"><?php echo formatCurrencyAmount($rem_usd, 'USD'); ?></h6>
                                <?php endif; ?>
                            </div>
                            <i class="bi bi-hourglass-split display-5 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">درەنگکراو</h6>
                                <h4 class="card-title mb-0"><?php echo number_format($total_stats['overdue_iqd'] ?? 0, 0); ?> <small class="opacity-75">دینار</small></h4>
                                <?php if (($total_stats['overdue_usd'] ?? 0) != 0): ?>
                                    <h6 class="mb-0 mt-1"><?php echo formatCurrencyAmount($total_stats['overdue_usd'], 'USD'); ?></h6>
                                <?php endif; ?>
                            </div>
                            <i class="bi bi-exclamation-triangle display-5 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="row g-3 mb-4">
            <div class="col-lg-4 col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-warning"><?php echo $total_stats['active_count'] ?? 0; ?></h5>
                        <p class="card-text">قەرزی چالاک</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-success"><?php echo $total_stats['completed_count'] ?? 0; ?></h5>
                        <p class="card-text">قەرزی تەواوبوو</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-danger"><?php echo $total_stats['overdue_count'] ?? 0; ?></h5>
                        <p class="card-text">قەرزی درەنگکراو</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row g-4 mb-4">
            <!-- Monthly Credits Chart -->
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-bar-chart"></i> قەرزی مانگانە
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="monthlyCreditsChart" height="100"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Status Distribution Pie Chart -->
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-pie-chart"></i> دابەشبوونی بارودۆخ
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="statusChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Row -->
        <div class="row g-4 mb-4">
            <!-- Top Creditors -->
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-people"></i> زۆرترین قەرزکارەکان
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($creditors_stats)): ?>
                            <div class="text-center py-3">
                                <i class="bi bi-inbox display-4 text-muted"></i>
                                <p class="text-muted mt-2">هیچ قەرزکارێک نەدۆزرایەوە</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($creditors_stats as $creditor): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($creditor['creditor_name']); ?></h6>
                                        <small class="text-muted"><?php echo $creditor['credit_count']; ?> قەرز</small>
                                    </div>
                                    <div class="text-end">
                                        <strong class="text-primary"><?php echo formatDualCurrency($creditor['total_iqd'] ?? 0, $creditor['total_usd'] ?? 0, '<br>'); ?></strong>
                                        <small class="text-danger d-block"><?php echo formatDualCurrency($creditor['remaining_iqd'] ?? 0, $creditor['remaining_usd'] ?? 0, ' + '); ?> ماوە</small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Payment Methods Stats -->
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-credit-card"></i> ئاماری پارەدانەوەکان
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <h5 class="text-success"><?php echo $payment_stats['cash_payments'] ?? 0; ?></h5>
                                <small class="text-muted">نەقد</small>
                                <p class="mb-0 small"><?php echo formatDualCurrency($payment_stats['cash_amount_iqd'] ?? 0, $payment_stats['cash_amount_usd'] ?? 0, '<br>'); ?></p>
                            </div>
                            <div class="col-4">
                                <h5 class="text-primary"><?php echo $payment_stats['bank_payments'] ?? 0; ?></h5>
                                <small class="text-muted">بانکی</small>
                                <p class="mb-0 small"><?php echo formatDualCurrency($payment_stats['bank_amount_iqd'] ?? 0, $payment_stats['bank_amount_usd'] ?? 0, '<br>'); ?></p>
                            </div>
                            <div class="col-4">
                                <h5 class="text-warning"><?php echo $payment_stats['check_payments'] ?? 0; ?></h5>
                                <small class="text-muted">چێک</small>
                                <p class="mb-0 small"><?php echo formatDualCurrency($payment_stats['check_amount_iqd'] ?? 0, $payment_stats['check_amount_usd'] ?? 0, '<br>'); ?></p>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <h6 class="text-muted">کۆی پارەدانەوەکان</h6>
                                <h4 class="text-success"><?php echo $payment_stats['total_payments'] ?? 0; ?></h4>
                            </div>
                            <div class="col-6">
                                <h6 class="text-muted">ناوەندی پارەدانەوە</h6>
                                <h5 class="text-info"><?php echo number_format($payment_stats['avg_payment_iqd'] ?? 0, 0); ?> <small class="text-muted">دینار</small></h5>
                                <?php if (($payment_stats['avg_payment_usd'] ?? 0) != 0): ?>
                                    <h6 class="text-info mb-0"><?php echo formatCurrencyAmount($payment_stats['avg_payment_usd'], 'USD'); ?></h6>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Urgent Attention Section -->
        <div class="row g-4">
            <!-- Overdue Credits -->
            <?php if (!empty($overdue_credits)): ?>
            <div class="col-lg-6">
                <div class="card shadow-sm border-danger">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">
                            <i class="bi bi-exclamation-triangle"></i> قەرزە درەنگکراوەکان
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <?php foreach ($overdue_credits as $credit): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($credit['creditor_name']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($credit['expense_name']); ?></small>
                                        <br><small class="text-danger">درەنگ بە <?php echo $credit['days_overdue']; ?> ڕۆژ</small>
                                    </div>
                                    <div class="text-end">
                                        <strong class="text-danger"><?php echo formatCurrencyAmount($credit['remaining_amount'], $credit['currency'] ?? 'IQD'); ?></strong>
                                        <a href="details.php?id=<?php echo $credit['id']; ?>" class="btn btn-sm btn-outline-danger d-block mt-1">وردەکاری</a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Upcoming Due Credits -->
            <?php if (!empty($upcoming_due)): ?>
            <div class="col-lg-6">
                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0">
                            <i class="bi bi-clock"></i> قەرزەکانی نزیک لە وادە
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming_due as $credit): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($credit['creditor_name']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($credit['expense_name']); ?></small>
                                        <br><small class="text-warning"><?php echo $credit['days_until_due']; ?> ڕۆژ ماوە</small>
                                    </div>
                                    <div class="text-end">
                                        <strong class="text-warning"><?php echo formatCurrencyAmount($credit['remaining_amount'], $credit['currency'] ?? 'IQD'); ?></strong>
                                        <a href="payment.php?id=<?php echo $credit['id']; ?>" class="btn btn-sm btn-outline-success d-block mt-1">پارەدانەوە</a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
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

        // Monthly Credits Chart
        const monthlyData = <?php echo json_encode($monthly_stats); ?>;
        const monthlyCtx = document.getElementById('monthlyCreditsChart').getContext('2d');
        
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: monthlyData.map(item => `${item.year_num}/${item.month_num}`),
                datasets: [{
                    label: 'کۆی قەرز (دینار)',
                    data: monthlyData.map(item => item.total_amount),
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                    borderColor: 'rgb(54, 162, 235)',
                    borderWidth: 2
                }, {
                    label: 'دراوەتەوە (دینار)',
                    data: monthlyData.map(item => item.paid_amount),
                    backgroundColor: 'rgba(75, 192, 192, 0.8)',
                    borderColor: 'rgb(75, 192, 192)',
                    borderWidth: 2
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
                }
            }
        });

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['چالاک', 'تەواوبوو', 'درەنگکراو'],
                datasets: [{
                    data: [
                        <?php echo $total_stats['active_count'] ?? 0; ?>,
                        <?php echo $total_stats['completed_count'] ?? 0; ?>,
                        <?php echo $total_stats['overdue_count'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(220, 53, 69, 0.8)'
                    ],
                    borderColor: [
                        'rgb(255, 193, 7)',
                        'rgb(40, 167, 69)',
                        'rgb(220, 53, 69)'
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
                    }
                }
            }
        });
    </script>
</body>
</html>