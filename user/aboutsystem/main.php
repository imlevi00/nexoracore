<?php
/**
 * بەشی سەرەکی دەربارەی ئێمە و سیستەم - user/aboutsystem/main.php
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
    <title>دەربارەی ئێمە و سیستەم - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#0d6efd">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="aboutsystem-module-page aboutsystem-hub-page bg-body-secondary">

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
                            <i class="bi bi-info-circle"></i>
                            دەربارەی ئێمە و سیستەم
                        </h2>
                        <p class="text-muted mb-0">زانیاری و پاڵپشتی</p>
                    </div>
                    <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
                    </a>
                </div>
            </div>
        </div>

        <!-- About System Cards -->
        <div class="row g-3">
            
            <!-- دەربارەی ئێمە -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/about/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-about p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-info-circle"></i>
                            </div>
                            <h6 class="mb-0">دەربارەی ئێمە</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- پاڵپشتی -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('user/support/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-support p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-heart-fill"></i>
                            </div>
                            <h6 class="mb-0">پاڵپشتی</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- پرسیار و وەڵامە باوەکان -->
            <div class="dashboard-card-wrapper">
                <a href="<?php echo url('questions_and_answers.html'); ?>" target="_blank" class="text-decoration-none">
                    <div class="dashboard-card section-faq p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-3">
                                <i class="bi bi-question-circle"></i>
                            </div>
                            <h6 class="mb-0">پرسیار و وەڵامە باوەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            
        </div>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

