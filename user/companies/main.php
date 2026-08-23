<?php
/**
 * بەشی سەرەکی کۆمپانیاکان - user/companies/main.php
 */
 
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

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
    <title>کۆمپانیاکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="companies-module-page companies-hub-page bg-light">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container-fluid py-4 hub-page-content">
        
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center hub-page-header flex-wrap gap-2">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-building"></i>
                            کۆمپانیاکان
                        </h2>
                        <p class="text-muted mb-0">بەڕێوەبردنی کۆمپانیاکان و وەسڵەکان</p>
                    </div>
                    <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
                    </a>
                </div>
            </div>
        </div>

        <!-- Company Cards -->
        <div class="row g-3">
            
            <?php if (!$isSubUser || (isset($userPermissions['companies']) && $userPermissions['companies'])): ?>
            <!-- کۆمپانیاکان -->
            <div class="col-12 col-sm-6 col-lg-3 dashboard-card-wrapper">
                <a href="<?php echo url('user/companies/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-companies-list p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-building"></i>
                            </div>
                            <h6 class="mb-0">کۆمپانیاکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (!$isSubUser || (isset($userPermissions['purchases']) && $userPermissions['purchases'])): ?>
            <!-- وەسڵی نوێ -->
            <div class="col-12 col-sm-6 col-lg-3 dashboard-card-wrapper">
                <a href="<?php echo url('user/purchases/add.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-new-receipt p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-plus-square"></i>
                            </div>
                            <h6 class="mb-0">وەسڵی نوێ</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (!$isSubUser || (isset($userPermissions['purchases']) && $userPermissions['purchases'])): ?>
            <!-- لیستی وەسڵەکان -->
            <div class="col-12 col-sm-6 col-lg-3 dashboard-card-wrapper">
                <a href="<?php echo url('user/purchases/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-receipt-list p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <h6 class="mb-0">لیستی وەسڵەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (!$isSubUser || (isset($userPermissions['purchases']) && $userPermissions['purchases'])): ?>
            <!-- ڕاپۆرتی کڕین -->
            <div class="col-12 col-sm-6 col-lg-3 dashboard-card-wrapper">
                <a href="<?php echo url('user/purchases/reports.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-purchase-report p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-bar-chart"></i>
                            </div>
                            <h6 class="mb-0">ڕاپۆرتی کڕین</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (!$isSubUser || (isset($userPermissions['purchases']) && $userPermissions['purchases'])): ?>
            <!-- بەدواداچوونی کاڵا -->
            <div class="col-12 col-sm-6 col-lg-3 dashboard-card-wrapper">
                <a href="<?php echo url('user/purchases/product_tracking.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-product-tracking p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-search-heart"></i>
                            </div>
                            <h6 class="mb-0">بەدواداچوونی کاڵا</h6>
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

