<?php
/**
 * بەشی سەرەکی خەرجیەکان - user/expenses/main.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expenses.view', [
    'route' => '/user/expenses/main.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();

if (function_exists('ensureExpensesSchemaTables')) {
    ensureExpensesSchemaTables($conn);
}

$userId = (int)$currentUser['id'];
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>خەرجیەکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="expenses-module-page expenses-hub-page bg-light">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container-fluid py-4 hub-page-content">
        
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center hub-page-header">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-wallet2"></i>
                            خەرجیەکان
                        </h2>
                        <p class="text-muted mb-0">بەڕێوەبردنی خەرجیەکان و قەرزەکان</p>
                    </div>
                    <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
                    </a>
                </div>
            </div>
        </div>

        <!-- Expense Cards -->
        <div class="row g-3">
            
            <?php if (!$isSubUser || currentUserHasPermission('expenses.create') || currentUserHasPermission('expenses.view')): ?>
            <!-- خەرجی نوێ -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/expenses/add.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-expenses-new p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-plus-lg"></i>
                            </div>
                            <h6 class="mb-0">خەرجی نوێ</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (!$isSubUser || currentUserHasPermission('expenses.view')): ?>
            <!-- بەڕێوەبردنی خەرجی -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/expenses/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-expenses-manage p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-credit-card-2-back"></i>
                            </div>
                            <h6 class="mb-0">بەڕێوەبردنی خەرجی</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (!$isSubUser || currentUserHasPermission('expense_credits.view')): ?>
            <!-- قەرزی خەرجیەکان -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/expenses/credits/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-expenses-credits p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-currency-exchange"></i>
                            </div>
                            <h6 class="mb-0">قەرزی خەرجیەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (!$isSubUser || currentUserHasPermission('expense_stats.view')): ?>
            <!-- ئاماری خەرجیەکان -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/expenses/statistics.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-expenses-stats p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-bar-chart-line"></i>
                            </div>
                            <h6 class="mb-0">ئاماری خەرجیەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
        </div>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
