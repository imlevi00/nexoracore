<?php
/**
 * داشبۆردی کاڵاکان - user/products/dashboard.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی دەسەڵاتەکانی بەکارهێنەر
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

require_once '../../includes/business_type_helpers.php';
$isCurtainShopMode = isCurtainShopMode($conn, (int)$userId);

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>کاڵا کان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    
    <!-- Custom Dashboard Styles -->
    <style>
        :root {
            --products-color: #007bff;
        }
        
        .dashboard-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            min-height: 140px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .dashboard-card:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        
        a.text-decoration-none .dashboard-card {
            cursor: pointer;
        }
        
        a.text-decoration-none:focus .dashboard-card {
            outline: 2px solid rgba(255, 255, 255, 0.5);
            outline-offset: 2px;
        }
        
        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover .card-icon {
            transform: scale(1.1) rotate(5deg);
        }
        
        h6.mb-0 {
            font-weight: 600;
            letter-spacing: 0.2px;
        }
        
        .section-products { 
            background: linear-gradient(135deg, 
                color-mix(in srgb, var(--products-color) 80%, black 20%), 
                color-mix(in srgb, #20c997 80%, black 20%)
            ); 
        }
        
        .section-inventory {
            background: linear-gradient(135deg, 
                color-mix(in srgb, var(--products-color) 80%, black 20%), 
                color-mix(in srgb, #20c997 80%, black 20%)
            ); 
        }
        
        .section-categories { 
            background: linear-gradient(135deg, 
                color-mix(in srgb, var(--products-color) 80%, black 20%), 
                color-mix(in srgb, #20c997 80%, black 20%)
            ); 
        }
        
        .section-products .card-icon {
            background: linear-gradient(135deg, rgba(32, 201, 151, 0.25), rgba(16, 185, 129, 0.3)) !important;
        }
        
        .section-inventory .card-icon {
            background: linear-gradient(135deg, rgba(32, 201, 151, 0.25), rgba(16, 185, 129, 0.3)) !important;
        }
        
        .section-categories .card-icon {
            background: linear-gradient(135deg, rgba(32, 201, 151, 0.25), rgba(16, 185, 129, 0.3)) !important;
        }
        
        .dashboard-card-wrapper {
            padding: 0.5rem;
        }
        
        @media (min-width: 992px) {
            .dashboard-card-wrapper {
                flex: 0 0 20%;
                max-width: 20%;
            }
        }
        
        @media (max-width: 991px) and (min-width: 768px) {
            .dashboard-card-wrapper {
                flex: 0 0 25%;
                max-width: 25%;
            }
        }
        
        @media (max-width: 767px) and (min-width: 576px) {
            .dashboard-card-wrapper {
                flex: 0 0 33.333%;
                max-width: 33.333%;
            }
        }
        
        @media (max-width: 575px) {
            .dashboard-card-wrapper {
                flex: 0 0 50%;
                max-width: 50%;
            }
            
            .dashboard-card h6 {
                font-size: 12px !important;
            }
            
            .card-icon {
                width: 45px !important;
                height: 45px !important;
            }
            
            .card-icon i {
                font-size: 20px !important;
                line-height: 45px !important;
            }
        }
        
        .back-button {
            margin-bottom: 20px;
        }
    </style>
</head>
<body class="products-module-page products-hub-page bg-body-secondary">

    <?php
    $productsNavId = 'productsDashboardNav';
    $productsNavLinks = [
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
        ['href' => url('user/auth/logout.php'), 'icon' => 'bi-box-arrow-right', 'text' => 'دەرچوون', 'class' => 'logout-link'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <div class="container-fluid py-4 products-page-content">
        
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">کاڵا کان</h2>
                        <p class="text-muted mb-0">بەڕێوەبردنی کاڵاکان و کەتەلۆگەکان</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Products Dashboard Cards -->
        <div class="row g-3 mb-4">
            
            <?php if (!$isSubUser || (isset($userPermissions['products']) && $userPermissions['products'])): ?>
            <!-- کاڵاینوێ (Inventory) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-inventory p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-boxes" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کاڵاینوێ</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- بەڕێوەبردنی کاڵا (Product Management) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-products p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-box-seam" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">بەڕێوەبردنی کاڵا</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if (!$isSubUser || (isset($userPermissions['inventory']) && $userPermissions['inventory'])): ?>
            <!-- کاڵای کەم (Low Stock) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/index.php?filter=low_stock'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-inventory p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-exclamation-triangle" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کاڵای کەم</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- کاڵای تەواوبوو (Finished Products) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/index.php?filter=out_of_stock'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-inventory p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-x-circle" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کاڵای تەواوبوو</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- کاڵا بەسەرچووەکان (Expired Products) -->
            <?php if (empty($isCurtainShopMode)): ?>
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/expired.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-inventory p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-calendar-x" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کاڵا بەسەرچووەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$isSubUser || (isset($userPermissions['categories']) && $userPermissions['categories'])): ?>
            <!-- زیادکردنی کەتەلۆگ (Add Catalog) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/categories.php?action=add'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-categories p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-plus-square" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">زیادکردنی کەتەلۆگ</h6>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- بەڕێوەبردنی کەتەلۆگ (Catalog Management) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/categories.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-categories p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-tags" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">بەڕێوەبردنی کەتەلۆگ</h6>
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

