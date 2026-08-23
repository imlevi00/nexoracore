<?php
/**
 * بەشی سەرەکی کڕیاران - user/customers/main.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی دەسەڵاتەکان
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>کڕیاران - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-dark.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="customers-module-page customers-hub-page bg-light customers-page">

    <?php
    $customersNavId = 'customersHubNav';
    $customersNavLinks = [
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
        ['href' => url('user/auth/logout.php'), 'icon' => 'bi-box-arrow-right', 'text' => 'دەرچوون', 'class' => 'logout-link'],
    ];
    include __DIR__ . '/partials/customers_nav.php';
    ?>

    <div class="container-fluid py-4 customers-page-content">
        
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">کڕیاران</h2>
                        <p class="text-muted mb-0">بەڕێوەبردنی کڕیاران و قەرزەکان</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Cards -->
        <div class="row g-3 mb-4">
            
            <?php if (!$isSubUser || (isset($userPermissions['customers']) && $userPermissions['customers'])): ?>
            <!-- کڕیاری نوێ -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/customers/index.php?action=add'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-customers p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-person-plus" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کڕیاری نوێ</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- لیستی کڕیاران -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/customers/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-customers p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-people" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">لیستی کڕیاران</h6>
                        </div>
                    </div>
                </a>
            </div>

            <!-- مێژووی کڕیاران -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/customers/history.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-customers p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-clock-history" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">مێژووی کڕیاران</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (!$isSubUser || (isset($userPermissions['debts']) && $userPermissions['debts'])): ?>
            <!-- مێژووی پارەدانەکان -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/debts/history.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-customers p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-clock-history" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">مێژووی پارەدانەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- قەرزی پارە -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/customers/money_debts.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-customers p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-wallet2" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">قەرزی پارە</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (!$isSubUser || (isset($userPermissions['customer_info']) && $userPermissions['customer_info'])): ?>
            <!-- فرۆشتنی قەرز -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/customers/credit_sales.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-customers p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-credit-card-2-front" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">فرۆشتنی قەرز</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- فرۆشتنی کاش -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/customers/cash_purchases.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-customers p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-cash-stack" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">فرۆشتنی کاش</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- کەشف حسابی -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/customers/account_statement.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-customers p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-file-earmark-text" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کەشف حسابی</h6>
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

