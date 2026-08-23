<?php
/**
 * Employees overview - per-sub_user sales summary
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

// Require main user auth
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'employees.view', [
    'route' => '/user/employees/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireEmployeesStatsAccess();

$userId = $currentUser['id'];
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';
$subUserId = (int)($currentUser['sub_user_id'] ?? 0);

// Load sub_users with aggregated sales stats (total + today)
$subUsers = [];

if ($isSubUser && $subUserId > 0) {
    $query = "
        SELECT 
            su.id,
            su.username,
            su.email,
            su.full_name,
            su.is_active,
            su.last_login,
            COALESCE(SUM(s.final_amount), 0) AS total_revenue,
            COALESCE(SUM(CASE WHEN DATE(s.sale_date) = CURDATE() THEN s.final_amount ELSE 0 END), 0) AS today_revenue,
            COALESCE(SUM(CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS total_sales_count,
            COALESCE(SUM(CASE WHEN DATE(s.sale_date) = CURDATE() THEN 1 ELSE 0 END), 0) AS today_sales_count
        FROM sub_users su
        LEFT JOIN sales s 
            ON s.user_id = ?
           AND s.sub_user_id = su.id
        WHERE su.main_user_id = ?
          AND su.id = ?
        GROUP BY su.id
        ORDER BY su.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iii', $userId, $userId, $subUserId);
} else {
    $query = "
        SELECT 
            su.id,
            su.username,
            su.email,
            su.full_name,
            su.is_active,
            su.last_login,
            COALESCE(SUM(s.final_amount), 0) AS total_revenue,
            COALESCE(SUM(CASE WHEN DATE(s.sale_date) = CURDATE() THEN s.final_amount ELSE 0 END), 0) AS today_revenue,
            COALESCE(SUM(CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS total_sales_count,
            COALESCE(SUM(CASE WHEN DATE(s.sale_date) = CURDATE() THEN 1 ELSE 0 END), 0) AS today_sales_count
        FROM sub_users su
        LEFT JOIN sales s 
            ON s.user_id = ?
           AND s.sub_user_id = su.id
        WHERE su.main_user_id = ?
        GROUP BY su.id
        ORDER BY su.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $userId, $userId);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $subUsers[] = $row;
}
$stmt->close();

?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>کارمەندان و فرۆشتنەکان - <?php echo SITE_NAME; ?></title>

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
            --gradient-orange: linear-gradient(135deg, #FA8BFF 0%, #2BD2FF 50%, #2BFF88 100%);
            --page-bg: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            --panel-bg: #ffffff;
            --panel-muted-bg: #f8f9fa;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-soft: #cbd5e1;
            --chart-grid: rgba(0, 0, 0, 0.05);
        }

        [data-bs-theme="dark"],
        body.dark-mode {
            --page-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --panel-bg: #1e293b;
            --panel-muted-bg: #334155;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --border-soft: #475569;
            --chart-grid: rgba(148, 163, 184, 0.2);
        }

        body { 
            background: var(--page-bg);
            min-height: 100vh;
            color: var(--text-primary);
        }

        .navbar-gradient {
            background: var(--gradient-primary) !important;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .page-header {
            background: var(--panel-bg);
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
            right: 0;
            width: 300px;
            height: 300px;
            background: var(--gradient-violet);
            opacity: 0.1;
            border-radius: 50%;
            transform: translate(30%, -30%);
        }

        .employee-card { 
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 20px;
            border: none;
            background: var(--panel-bg);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .employee-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--gradient-primary);
        }

        .employee-card:hover { 
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.25);
        }

        .employee-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: var(--gradient-blue-purple);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        .employee-card:hover .employee-avatar {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .stat-badge { 
            font-size: 0.85rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: bold;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-box {
            background: var(--panel-muted-bg);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .badge-gradient {
            background: var(--gradient-violet);
            color: white;
            border: none;
            box-shadow: 0 2px 8px rgba(139, 92, 246, 0.3);
            padding: 0.4rem 0.8rem;
        }

        .badge-inactive {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }

        .muted { color: var(--text-secondary); }

        .btn-view-sales {
            background: var(--gradient-blue-purple);
            border: none;
            color: white;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);
        }

        .btn-view-sales:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 144, 226, 0.5);
            color: white;
        }

        .chart-container {
            background: var(--panel-bg);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            height: 400px;
        }

        .empty-state-icon {
            font-size: 5rem;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        [data-bs-theme="dark"] .text-muted,
        body.dark-mode .text-muted {
            color: var(--text-secondary) !important;
        }

        [data-bs-theme="dark"] .card,
        body.dark-mode .card {
            background: var(--panel-bg);
            color: var(--text-primary);
            border-color: var(--border-soft);
        }

    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    </head>
<body class="employees-module-page employees-list-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-header">
                    <div class="d-flex align-items-center justify-content-between position-relative">
                        <div>
                            <h2 class="mb-2">
                                <i class="bi bi-people-fill" style="background: var(--gradient-primary); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;"></i>
                                لیستی کارمەندان و فرۆشتنەکان
                            </h2>
                            <p class="text-muted mb-0">پیشاندانی ئاماری فرۆشتن بۆ هەر کارمەند</p>
                        </div>
                        <div class="text-end">
                            <div class="badge badge-gradient fs-6">
                                <i class="bi bi-graph-up"></i>
                                <?php echo count($subUsers); ?> کارمەند
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($subUsers)): ?>
        <!-- Charts Section -->
        <div class="row mb-4">
            <div class="col-lg-6 mb-4">
                <div class="chart-container">
                    <h5 class="mb-3 text-center">
                        <i class="bi bi-pie-chart-fill text-primary"></i>
                        دابەشبوونی فرۆشتنی ئەمڕۆ
                    </h5>
                    <canvas id="todaySalesChart"></canvas>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="chart-container">
                    <h5 class="mb-3 text-center">
                        <i class="bi bi-bar-chart-fill text-primary"></i>
                        بەراوردکردنی کۆی فرۆشتن
                    </h5>
                    <canvas id="totalSalesChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-3">
            <?php if (empty($subUsers)): ?>
                <div class="col-12">
                    <div class="card" style="border-radius: 20px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                        <div class="card-body py-5 text-center">
                            <i class="bi bi-person-x empty-state-icon"></i>
                            <h4 class="mt-3" style="color: #495057;">هیچ کارمەندێک نییە</h4>
                            <p class="text-muted">کارمەندان لە بەشی 
                                <a href="<?php echo url('user/employees/manage.php'); ?>" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 600;">بەڕێوەبردنی کارمەندان</a> دروست بکە
                            </p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($subUsers as $su): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6">
                        <div class="card employee-card h-100">
                            <div class="card-body text-center">
                                <div class="d-flex justify-content-end mb-2">
                                    <span class="badge <?php echo $su['is_active'] ? 'badge-gradient' : 'badge-inactive'; ?>">
                                        <?php echo $su['is_active'] ? 'چالاک' : 'ناچالاک'; ?>
                                    </span>
                                </div>

                                <div class="employee-avatar mx-auto">
                                    <i class="bi bi-person-fill"></i>
                                </div>

                                <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($su['full_name']); ?></h5>
                                <div class="small muted mb-3">@<?php echo htmlspecialchars($su['username']); ?></div>

                                <div class="stat-box">
                                    <div class="row text-center g-3">
                                        <div class="col-6">
                                            <div class="stat-badge">فرۆشتنی ئەمڕۆ</div>
                                            <div class="stat-value"><?php echo (int)$su['today_sales_count']; ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-badge">کۆی بڕی ئەمڕۆ</div>
                                            <div class="stat-value"><?php echo number_format((float)$su['today_revenue']); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between small text-muted mb-3 px-2">
                                    <div>
                                        <i class="bi bi-receipt text-primary"></i>
                                        <span class="fw-semibold"><?php echo (int)$su['total_sales_count']; ?></span>
                                    </div>
                                    <div>
                                        <i class="bi bi-cash-stack text-success"></i>
                                        <span class="fw-semibold"><?php echo number_format((float)$su['total_revenue']); ?></span>
                                    </div>
                                </div>

                                <?php if (!empty($su['last_login'])): ?>
                                    <div class="small text-muted mb-3">
                                        <i class="bi bi-clock-history"></i>
                                        <?php echo date('Y/m/d H:i', strtotime($su['last_login'])); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="d-grid">
                                    <a class="btn btn-view-sales" href="<?php echo url('user/employees/sales.php?sub_user_id=' . urlencode($su['id'])); ?>">
                                        <i class="bi bi-receipt"></i> بینینی وەسڵەکان
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if (!empty($subUsers)): ?>
    <script>
        // Prepare data for charts
        const employeesData = <?php echo json_encode($subUsers); ?>;
        const isDarkMode = document.body.classList.contains('dark-mode') || document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const chartTextColor = isDarkMode ? '#cbd5e1' : '#334155';
        const chartGridColor = isDarkMode ? 'rgba(148, 163, 184, 0.2)' : 'rgba(0, 0, 0, 0.05)';
        const chartTooltipBg = isDarkMode ? 'rgba(15, 23, 42, 0.92)' : 'rgba(0, 0, 0, 0.8)';
        
        // Gradient colors for charts
        const gradientColors = [
            'rgba(102, 126, 234, 0.8)',
            'rgba(139, 92, 246, 0.8)',
            'rgba(74, 144, 226, 0.8)',
            'rgba(155, 89, 182, 0.8)',
            'rgba(250, 139, 255, 0.8)',
            'rgba(43, 210, 255, 0.8)',
            'rgba(123, 104, 238, 0.8)',
            'rgba(147, 112, 219, 0.8)'
        ];

        const borderColors = [
            'rgba(102, 126, 234, 1)',
            'rgba(139, 92, 246, 1)',
            'rgba(74, 144, 226, 1)',
            'rgba(155, 89, 182, 1)',
            'rgba(250, 139, 255, 1)',
            'rgba(43, 210, 255, 1)',
            'rgba(123, 104, 238, 1)',
            'rgba(147, 112, 219, 1)'
        ];

        // Today's Sales Doughnut Chart
        const todayCtx = document.getElementById('todaySalesChart');
        if (todayCtx && employeesData.length > 0) {
            const todayData = employeesData.filter(e => e.today_revenue > 0);
            
            if (todayData.length > 0) {
                new Chart(todayCtx, {
                    type: 'doughnut',
                    data: {
                        labels: todayData.map(e => e.full_name),
                        datasets: [{
                            label: 'فرۆشتنی ئەمڕۆ (دینار)',
                            data: todayData.map(e => parseFloat(e.today_revenue)),
                            backgroundColor: gradientColors.slice(0, todayData.length),
                            borderColor: borderColors.slice(0, todayData.length),
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
                                    color: chartTextColor,
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
                                        let label = context.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        label += new Intl.NumberFormat('en-US').format(context.parsed) + ' دینار';
                                        return label;
                                    }
                                },
                                backgroundColor: chartTooltipBg,
                                padding: 12,
                                cornerRadius: 8,
                                titleFont: {
                                    size: 14
                                },
                                bodyFont: {
                                    size: 13
                                }
                            }
                        }
                    }
                });
            } else {
                todayCtx.parentElement.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-graph-down display-4 d-block mb-3"></i>هیچ فرۆشتنێک ئەمڕۆ نییە</div>';
            }
        }

        // Total Sales Bar Chart
        const totalCtx = document.getElementById('totalSalesChart');
        if (totalCtx && employeesData.length > 0) {
            new Chart(totalCtx, {
                type: 'bar',
                data: {
                    labels: employeesData.map(e => e.full_name),
                    datasets: [
                        {
                            label: 'فرۆشتنی ئەمڕۆ',
                            data: employeesData.map(e => parseFloat(e.today_revenue)),
                            backgroundColor: 'rgba(74, 144, 226, 0.7)',
                            borderColor: 'rgba(74, 144, 226, 1)',
                            borderWidth: 2,
                            borderRadius: 8,
                            borderSkipped: false
                        },
                        {
                            label: 'کۆی گشتی',
                            data: employeesData.map(e => parseFloat(e.total_revenue)),
                            backgroundColor: 'rgba(139, 92, 246, 0.7)',
                            borderColor: 'rgba(139, 92, 246, 1)',
                            borderWidth: 2,
                            borderRadius: 8,
                            borderSkipped: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: chartTextColor,
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
                                    label += new Intl.NumberFormat('en-US').format(context.parsed.y) + ' دینار';
                                    return label;
                                }
                            },
                            backgroundColor: chartTooltipBg,
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
                            beginAtZero: true,
                            ticks: {
                                color: chartTextColor,
                                callback: function(value) {
                                    return new Intl.NumberFormat('en-US').format(value);
                                },
                                font: {
                                    size: 11
                                }
                            },
                            grid: {
                                color: chartGridColor,
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: chartTextColor,
                                font: {
                                    size: 11
                                }
                            },
                            grid: {
                                display: false
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


