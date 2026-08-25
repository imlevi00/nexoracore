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
    <meta name="theme-color" content="#4f46e5">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('user/settings/settings.css'); ?>" rel="stylesheet">
</head>
<body class="settings-module-page settings-page">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container py-4">
        
        <!-- Page Header -->
        <div class="settings-header-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <nav class="small text-muted mb-2" aria-label="breadcrumb">
                        <a href="<?php echo url('user/dashboard/index.php'); ?>" class="text-decoration-none text-muted">
                            <i class="bi bi-speedometer2"></i> داشبۆرد
                        </a>
                        <span class="mx-2">/</span>
                        <span class="text-primary fw-medium">ڕێکخستنەکان</span>
                    </nav>
                    <h2 class="mb-1 d-flex align-items-center gap-2">
                        <i class="bi bi-gear-wide-connected text-primary"></i>
                        ڕێکخستنەکانی سیستەم
                    </h2>
                    <p class="text-muted mb-0">بەڕێوەبردنی ئەکاونت، ڕووکار، ئاگادارکەرەوە و تایبەتمەندییەکان</p>
                </div>
                <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
                </a>
            </div>
        </div>

        <!-- Settings Bento Grid -->
        <div class="row g-3">
            
            <?php if ($canAccessSettings): ?>
            <!-- ڕێکخستنەکانی ئەکاونت و فرۆشگا -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="<?php echo url('user/settings/index.php'); ?>" class="hub-card-link">
                    <div class="hub-bento-card hub-accent-settings">
                        <div class="hub-bento-icon">
                            <i class="bi bi-gear"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h3 class="hub-bento-title">ڕێکخستنە سەرەکییەکان</h3>
                            <p class="hub-bento-desc">ناوی فرۆشگا، پاسۆرد، دۆخی ڕووکار، لۆگۆ و وەسڵەکان</p>
                        </div>
                        <div class="hub-bento-arrow">
                            <i class="bi bi-chevron-left"></i>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- وردەکارییەکان -->
            <?php if ($canAccessSettings): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <a href="<?php echo url('user/settings/user_detail_settings.php'); ?>" class="hub-card-link">
                    <div class="hub-bento-card hub-accent-details">
                        <div class="hub-bento-icon">
                            <i class="bi bi-sliders2-vertical"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h3 class="hub-bento-title">وردەکارییەکانی هەژمار</h3>
                            <p class="hub-bento-desc">ڕێکخستنی POS، نرخی تێکڕا، قەبارەی فۆنت و داهات</p>
                        </div>
                        <div class="hub-bento-arrow">
                            <i class="bi bi-chevron-left"></i>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- ئاگادارکەرەوەکان + باک ئەپی تیلیگرام -->
            <?php if ($canAccessTelegram): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <a href="<?php echo url('user/telegram/index.php'); ?>" class="hub-card-link">
                    <div class="hub-bento-card hub-accent-telegram">
                        <div class="hub-bento-icon">
                            <i class="bi bi-telegram"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h3 class="hub-bento-title">باک ئەپ و تیلیگرام</h3>
                            <p class="hub-bento-desc">ئاگاداری ڕاستەوخۆ و ناردنی باک ئەپی ڕۆژانەی داتا</p>
                        </div>
                        <div class="hub-bento-arrow">
                            <i class="bi bi-chevron-left"></i>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if ($canAccessDollarPrice): ?>
            <!-- نرخی دۆلار -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="<?php echo url('user/settings/nrxy_dolar/index.php'); ?>" class="hub-card-link">
                    <div class="hub-bento-card hub-accent-dollar">
                        <div class="hub-bento-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h3 class="hub-bento-title">نرخی دۆلاری ئەمڕۆ</h3>
                            <p class="hub-bento-desc">بینینی نرخی کاتی بازاڕ و دوایین نوێکردنەوە</p>
                        </div>
                        <div class="hub-bento-arrow">
                            <i class="bi bi-chevron-left"></i>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if ($canAccessCurrencyExchange): ?>
            <!-- ڕێژەی ئاڵوگۆڕکردنی دراوە -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="<?php echo url('user/settings/currency_exchange.php'); ?>" class="hub-card-link">
                    <div class="hub-bento-card hub-accent-exchange">
                        <div class="hub-bento-icon">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h3 class="hub-bento-title">ڕێژەی ئاڵوگۆڕکردن</h3>
                            <p class="hub-bento-desc">دەستکاری کردنی زیادی/کەمی و نرخی تایبەتی فرۆشتن</p>
                        </div>
                        <div class="hub-bento-arrow">
                            <i class="bi bi-chevron-left"></i>
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
