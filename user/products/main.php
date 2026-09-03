<?php
/**
 * داشبۆردی کاڵاکان - user/products/main.php
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
$isPharmacyMode = false;

$settingsStmt = $conn->prepare("
    SELECT s.business_type_id, bt.code AS business_type_code
    FROM settings s
    LEFT JOIN business_types bt ON bt.id = s.business_type_id
    WHERE s.user_id = ?
    LIMIT 1
");
if ($settingsStmt) {
    $settingsStmt->bind_param("i", $userId);
    $settingsStmt->execute();
    $settingsRow = $settingsStmt->get_result()->fetch_assoc();
    $settingsStmt->close();

    if ($settingsRow) {
        $businessTypeId = (int)($settingsRow['business_type_id'] ?? 0);
        $businessTypeCode = trim((string)($settingsRow['business_type_code'] ?? ''));
        if (
            in_array($businessTypeId, [1, 3], true) ||
            in_array($businessTypeCode, ['pharmacy', 'pharmacy_and_medical_center'], true)
        ) {
            $isPharmacyMode = true;
        }
    }
}

require_once '../../includes/business_type_helpers.php';
$isCurtainShopMode = isCurtainShopMode($conn, (int)$userId);

// ژماردنی کاڵای کەم، تەواوبوو و بەسەرچوو بۆ نیشاندانی نیشانە (badge) لەسەر کارتەکان
$lowStockCount = 0;
$outOfStockCount = 0;
$expiredCount = 0;

$excludeScaleClause = '';
$scaleCheck = $conn->query("SHOW TABLES LIKE 'scale_products'");
if ($scaleCheck && $scaleCheck->num_rows > 0) {
    $excludeScaleClause = " AND NOT EXISTS (SELECT 1 FROM scale_products sp_hide WHERE sp_hide.product_id = p.id)";
}
if ($scaleCheck) {
    $scaleCheck->free();
}

// پێناسەی «تەواوبوو» = هیچ یەکەیەک بڕی بەردەستی > 0 نییە (هەمان لۆجیکی index.php/POS).
$hasPositiveStockExpr = "EXISTS (
    SELECT 1 FROM product_units pu_pos
    WHERE pu_pos.product_id = p.id AND pu_pos.stock_quantity > 0
)";
// یەکەی سەرەکی بۆ مەرجی «کەم/زەرەر»
$primaryStockExpr = "COALESCE(
    (SELECT pu_primary.stock_quantity FROM product_units pu_primary
     WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
     ORDER BY pu_primary.id ASC LIMIT 1),
    (SELECT pu_any.stock_quantity FROM product_units pu_any
     WHERE pu_any.product_id = p.id
     ORDER BY pu_any.id ASC LIMIT 1),
    0
)";
$primaryMinExpr = "COALESCE(
    (SELECT pu_primary.min_stock FROM product_units pu_primary
     WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
     ORDER BY pu_primary.id ASC LIMIT 1),
    (SELECT pu_any.min_stock FROM product_units pu_any
     WHERE pu_any.product_id = p.id
     ORDER BY pu_any.id ASC LIMIT 1),
    0
)";
$primaryBuyExpr = "COALESCE(
    (SELECT pu_primary.buy_price FROM product_units pu_primary
     WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
     ORDER BY pu_primary.id ASC LIMIT 1),
    (SELECT pu_any.buy_price FROM product_units pu_any
     WHERE pu_any.product_id = p.id
     ORDER BY pu_any.id ASC LIMIT 1),
    0
)";
$primarySellExpr = "COALESCE(
    (SELECT pu_primary.sell_price FROM product_units pu_primary
     WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
     ORDER BY pu_primary.id ASC LIMIT 1),
    (SELECT pu_any.sell_price FROM product_units pu_any
     WHERE pu_any.product_id = p.id
     ORDER BY pu_any.id ASC LIMIT 1),
    0
)";

// کاڵای کەم: کاڵا بەردەستی هەیە (یەکەیەک > 0) بەڵام یەکەی سەرەکی <= کەمترین بڕ
$lowStockSql = "
    SELECT COUNT(*) AS c
    FROM products p
    WHERE p.user_id = ?$excludeScaleClause
    AND $hasPositiveStockExpr
    AND $primaryStockExpr <= $primaryMinExpr
";
if ($lowStmt = $conn->prepare($lowStockSql)) {
    $lowStmt->bind_param("i", $userId);
    $lowStmt->execute();
    $lowStockCount = (int)($lowStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $lowStmt->close();
}

// کاڵای تەواوبوو: هیچ یەکەیەک بڕی بەردەستی > 0 نییە (کاڵای بێ یەکەش لێرەیە)
$outOfStockSql = "
    SELECT COUNT(*) AS c
    FROM products p
    WHERE p.user_id = ?$excludeScaleClause
    AND NOT $hasPositiveStockExpr
";
if ($outStmt = $conn->prepare($outOfStockSql)) {
    $outStmt->bind_param("i", $userId);
    $outStmt->execute();
    $outOfStockCount = (int)($outStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $outStmt->close();
}

// کاڵا بەزەرەر تۆمارکراوەکان: نرخی کڕینی یەکەی سەرەکی > نرخی فرۆشتنی
$lossCount = 0;
$lossSql = "
    SELECT COUNT(*) AS c
    FROM products p
    WHERE p.user_id = ?$excludeScaleClause
    AND (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) > 0
    AND $primaryBuyExpr > $primarySellExpr
";
if ($lossStmt = $conn->prepare($lossSql)) {
    $lossStmt->bind_param("i", $userId);
    $lossStmt->execute();
    $lossCount = (int)($lossStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $lossStmt->close();
}

// کاڵا بەسەرچووەکان
$expiredSql = "
    SELECT COUNT(*) AS c
    FROM products p
    WHERE p.user_id = ? AND p.expiry_date IS NOT NULL AND p.expiry_date <= CURDATE()
    AND COALESCE(
            (SELECT pu_primary.stock_quantity FROM product_units pu_primary
             WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
             ORDER BY pu_primary.id ASC LIMIT 1),
            (SELECT pu_any.stock_quantity FROM product_units pu_any
             WHERE pu_any.product_id = p.id
             ORDER BY pu_any.id ASC LIMIT 1),
            0
        ) > 0
";
if ($expStmt = $conn->prepare($expiredSql)) {
    $expStmt->bind_param("i", $userId);
    $expStmt->execute();
    $expiredCount = (int)($expStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $expStmt->close();
}

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
</head>
<body class="products-module-page products-hub-page bg-body-secondary">

    <?php
    $productsNavId = 'productsHubNav';
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

            <!-- بەڕێوەبردنی خزمەتگوزاری (Services Management) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/services.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-products p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-briefcase" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">خزمەتگوزاری</h6>
                        </div>
                    </div>
                </a>
            </div>

            <!-- کاڵای نوێ (Inventory) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/add.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-inventory p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-boxes" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کاڵای نوێ</h6>
                        </div>
                    </div>
                </a>
            </div>

            <?php if (empty($isCurtainShopMode)): ?>
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/scale_settings.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-products p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-sliders" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">ڕێکخستنەکانی قەپانی زیرەک</h6>
                        </div>
                    </div>
                </a>
            </div>

            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/scale_products.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-products p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-speedometer2" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کاڵاکانی قەپانی زیرەک</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/custom_fields.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-products p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-ui-checks-grid" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">دووگمە زیادەکانی کاڵا</h6>
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
                        <?php if ($lowStockCount > 0): ?>
                        <span class="card-count-badge"><?php echo $lowStockCount > 99 ? '99+' : $lowStockCount; ?></span>
                        <?php endif; ?>
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
                        <?php if ($outOfStockCount > 0): ?>
                        <span class="card-count-badge"><?php echo $outOfStockCount > 99 ? '99+' : $outOfStockCount; ?></span>
                        <?php endif; ?>
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
                        <?php if ($expiredCount > 0): ?>
                        <span class="card-count-badge"><?php echo $expiredCount > 99 ? '99+' : $expiredCount; ?></span>
                        <?php endif; ?>
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
            
            <!-- کاڵای نرخی کڕین زیاترە (Products with Purchase Price Higher or Equal) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/index.php?filter=loss'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-inventory p-4 h-100">
                        <?php if ($lossCount > 0): ?>
                        <span class="card-count-badge"><?php echo $lossCount > 99 ? '99+' : $lossCount; ?></span>
                        <?php endif; ?>
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-graph-down-arrow" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">کاڵا بەزەرەر تۆمارکراوەکان</h6>
                        </div>
                    </div>
                </a>
            </div>
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
            
            <?php if (!$isSubUser || (isset($userPermissions['products']) && $userPermissions['products'])): ?>
            <!-- دروستکردنی نرخی سەر ڕەفە (Shelf Price Tags) -->
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/shelf_price_tags/index.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-products p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-tag" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">دروستکردنی نرخی سەر ڕەفە</h6>
                        </div>
                    </div>
                </a>
            </div>

            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/history.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-products p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-clock-history" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">مێژووی کاڵاکان</h6>
                        </div>
                    </div>
                </a>
            </div>

            <?php if ($isPharmacyMode): ?>
            <div class="dashboard-card-wrapper p-2">
                <a href="<?php echo url('user/products/drug_interactions.php'); ?>" class="text-decoration-none">
                    <div class="dashboard-card section-products p-4 h-100">
                        <div class="text-center text-white">
                            <div class="card-icon mx-auto mb-2" style="width: 55px; height: 55px; border-radius: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                                <i class="bi bi-shield-exclamation" style="font-size: 26px; line-height: 55px;"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 15px; font-weight: 600;">دەرمانی نەگونجاو</h6>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            
        </div>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
