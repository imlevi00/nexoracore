<?php
/**
 * بەشی فرۆشتن - user/pos/main.php
 * پەیجی سەرەکی بۆ فرۆشتن کە 4 کارد پیشان دەدات
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
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
    <title>فرۆشتن - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/sales-hub.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/pos-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="sales-hub-page">

    <nav class="navbar navbar-expand-lg navbar-dark bg-primary kasher-navbar sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo url('user/dashboard/index.php'); ?>">
                <i class="bi bi-shop"></i>
                <?php echo htmlspecialchars($currentUser['business_name']); ?>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#salesHubNav"
                    aria-controls="salesHubNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="salesHubNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url('user/dashboard/index.php'); ?>">
                            <i class="bi bi-house"></i> داشبۆرد
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link logout-link" href="<?php echo url('user/auth/logout.php'); ?>">
                            <i class="bi bi-box-arrow-right"></i> دەرچوون
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="sales-hub-hero">
            <div>
                <h2><i class="bi bi-cash-coin"></i> فرۆشتن</h2>
                <p>POS، لیستی وەسڵەکان و گەڕاندنەوە — هەڵبژاردنی بەشەکەت</p>
            </div>
            <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-secondary back-button">
                <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
            </a>
        </div>

        <div class="sales-hub-grid">
            <?php $showPos = true; if ($showPos): ?>
            <a href="<?php echo url('user/pos/index.php'); ?>" class="text-decoration-none">
                <div class="sales-card sales-card-pos">
                    <div class="card-icon"><i class="bi bi-cart-check"></i></div>
                    <div>
                        <h5>فرۆشتن</h5>
                        <div class="sales-card-desc">POS — فرۆشتنی خێرا</div>
                    </div>
                </div>
            </a>

            <a href="<?php echo url('user/pos/sales.php'); ?>" class="text-decoration-none">
                <div class="sales-card sales-card-list">
                    <div class="card-icon"><i class="bi bi-receipt-cutoff"></i></div>
                    <div>
                        <h5>لیستی فرۆشتنەکان</h5>
                        <div class="sales-card-desc">مێژوو و وەسڵەکان</div>
                    </div>
                </div>
            </a>
            <?php endif; ?>

            <?php $showReturns = true; if ($showReturns): ?>
            <a href="<?php echo url('user/returns/index.php'); ?>" class="text-decoration-none">
                <div class="sales-card sales-card-returns">
                    <div class="card-icon"><i class="bi bi-arrow-return-left"></i></div>
                    <div>
                        <h5>گەڕاندنەوەکان</h5>
                        <div class="sales-card-desc">بەڕێوەبردنی گەڕاندنەوە</div>
                    </div>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <div class="sales-hub-tips">
            <span><i class="bi bi-lightning-charge"></i> فرۆشتن → POS خێرا</span>
            <span><i class="bi bi-receipt"></i> لیست → مێژووی وەسڵ</span>
            <span><i class="bi bi-arrow-return-left"></i> گەڕاندنەوە → بەڕێوەبردنی گەڕاو</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
