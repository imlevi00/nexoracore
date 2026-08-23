<?php
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'wallets.view', [
    'route' => '/user/wallets/main.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
], 'redirect');
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl"> 
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>قاسەکان - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#2563eb">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="wallets-module-page wallets-hub-page bg-light">
<?php include_once '../../includes/navigation.php'; ?>
<div class="container-fluid py-4 hub-page-content">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center hub-page-header">
                <div>
                    <h2 class="mb-1">
                        <i class="bi bi-wallet2"></i>
                        قاسەکان
                    </h2>
                    <p class="text-muted mb-0">بەڕێوەبردنی قاسەکان و جوڵە مالییەکان</p>
                </div>
                <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
                </a>
            </div>
        </div>
    </div>
    <div class="row g-3">
        <div class="dashboard-card-wrapper">
            <a href="<?php echo url('user/wallets/index.php'); ?>" class="text-decoration-none">
                <div class="dashboard-card section-wallets-list p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon mx-auto mb-3">
                            <i class="bi bi-list-ul"></i>
                        </div>
                        <h6 class="mb-0">لیستی قاسەکان</h6>
                    </div>
                </div>
            </a>
        </div>
        <div class="dashboard-card-wrapper">
            <a href="<?php echo url('user/wallets/add.php'); ?>" class="text-decoration-none">
                <div class="dashboard-card section-wallets-add p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon mx-auto mb-3">
                            <i class="bi bi-plus-lg"></i>
                        </div>
                        <h6 class="mb-0">زیادکردنی قاسە</h6>
                    </div>
                </div>
            </a>
        </div>
        <div class="dashboard-card-wrapper">
            <a href="<?php echo url('user/wallets/transfer.php'); ?>" class="text-decoration-none">
                <div class="dashboard-card section-wallets-transfer p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon mx-auto mb-3">
                            <i class="bi bi-arrow-left-right"></i>
                        </div>
                        <h6 class="mb-0">گواستنەوەی پارە</h6>
                    </div>
                </div>
            </a>
        </div>
        <div class="dashboard-card-wrapper">
            <a href="<?php echo url('user/wallets/adjustment.php'); ?>" class="text-decoration-none">
                <div class="dashboard-card section-wallets-adjust p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon mx-auto mb-3">
                            <i class="bi bi-sliders2-vertical"></i>
                        </div>
                        <h6 class="mb-0">زیاد/کەمکردنی دەستی</h6>
                    </div>
                </div>
            </a>
        </div>
        <div class="dashboard-card-wrapper">
            <a href="<?php echo url('user/wallets/history.php'); ?>" class="text-decoration-none">
                <div class="dashboard-card section-wallets-history p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon mx-auto mb-3">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <h6 class="mb-0">مێژووی قاسەکان</h6>
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

