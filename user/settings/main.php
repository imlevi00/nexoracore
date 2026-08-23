<?php
/**
 * بەشی سەرەکی ڕێکخستنەکان - user/settings/main.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'settings.view', [
    'route' => '/user/settings/main.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];
$authContext = [
    'route' => '/user/settings/main.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
];
$canAccessSettings = !empty(authorize($currentUser, 'settings.view', $authContext)['allowed']);
$canAccessTelegram = !empty(authorize($currentUser, 'telegram.view', $authContext)['allowed']);
$canAccessDollarPrice = !empty(authorize($currentUser, 'dollar_price.view', $authContext)['allowed']);
$canAccessCurrencyExchange = !empty(authorize($currentUser, 'dollar_price.update', $authContext)['allowed']);

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ڕێکخستنەکان - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#0d6efd">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="settings-module-page settings-hub-page bg-light">

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
                            <i class="bi bi-gear"></i>
                            ڕێکخستنەکان
                        </h2>
                        <p class="text-muted mb-0">بەڕێوەبردنی ڕێکخستنەکان</p>
                    </div>
                    <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
                    </a>
                </div>
            </div>
        </div>

        <!-- Settings Cards -->
        <div class="row g-3">
            
            <?php if ($canAccessSettings): ?>
            <!-- ڕێکخستنەکان -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/settings/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-settings p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-gear"></i>
                            </div>
                            <h6 class="mb-0">ڕێکخستنەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- وردەکارییەکان -->
            <?php if ($canAccessSettings): ?>
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/settings/user_detail_settings.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-detail-settings p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-sliders"></i>
                            </div>
                            <h6 class="mb-0">وردەکارییەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- ئاگادارکەرەوەکان + باک ئەپی تیلیگرام -->
            <?php if ($canAccessTelegram): ?>
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/telegram/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-telegram p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-telegram"></i>
                            </div>
                            <h6 class="mb-0">ئاگادارکەرەوەکان + باک ئەپی تیلیگرام</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
        
            
            <?php if ($canAccessDollarPrice): ?>
            <!-- نرخی دۆلار -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/settings/nrxy_dolar/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-dollar-price p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-currency-dollar"></i>
                            </div>
                            <h6 class="mb-0">نرخی دۆلار</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if ($canAccessCurrencyExchange): ?>
            <!-- ڕێژەی ئاڵوگۆڕکردنی دراوە -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/settings/currency_exchange.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-dollar-price p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-currency-exchange"></i>
                            </div>
                            <h6 class="mb-0">ڕێژەی ئاڵوگۆڕکردن</h6>
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

