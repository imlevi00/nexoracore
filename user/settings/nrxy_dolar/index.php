<?php
/**
 * پەڕەی نرخی دۆلار - user/settings/nrxy_dolar/index.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/theme_bootstrap.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'dollar_price.view', [
    'route' => '/user/settings/nrxy_dolar/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

$dollarPrice = null;
$lastUpdated = null;
$timeAgo = '';

try {
    $result = $conn->query("SELECT 
        offer_price,
        DATE_FORMAT(last_updated, '%Y-%m-%d %H:%i:%s') as last_updated,
        TIMESTAMPDIFF(MINUTE, last_updated, NOW()) as minutes_ago,
        TIMESTAMPDIFF(HOUR, last_updated, NOW()) as hours_ago,
        TIMESTAMPDIFF(DAY, last_updated, NOW()) as days_ago
    FROM dollar_prices 
    ORDER BY last_updated DESC 
    LIMIT 1");

    if ($result && $row = $result->fetch_assoc()) {
        $dollarPrice = floatval($row['offer_price']);
        $lastUpdated = $row['last_updated'];
        $minutesAgo = intval($row['minutes_ago']);
        $hoursAgo = intval($row['hours_ago']);
        $daysAgo = intval($row['days_ago']);

        if ($minutesAgo < 1) {
            $timeAgo = 'ئێستا';
        } elseif ($minutesAgo < 60) {
            $timeAgo = $minutesAgo . ' خولەک پێش ئێستا';
        } elseif ($hoursAgo < 24) {
            $timeAgo = $hoursAgo . ' کاتژمێر پێش ئێستا';
        } else {
            $timeAgo = $daysAgo . ' ڕۆژ پێش ئێستا';
        }
    }
} catch (Exception $e) {
}

$selfUrl = url('user/settings/nrxy_dolar/index.php');
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>نرخی دۆلار - <?php echo htmlspecialchars($currentUser['business_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/theme-modern.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/settings/settings.css'); ?>" rel="stylesheet">

    <style>
        .dollar-widget-card {
            background: var(--surface-1);
            border: 1px solid var(--border-default);
            border-radius: 24px;
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }
        .dollar-widget-card::before {
            content: '';
            position: absolute;
            top: 0;
            inset-inline: 0;
            height: 4px;
            background: linear-gradient(90deg, #059669, #10b981, #06b6d4);
        }
        .dollar-rate-value {
            font-size: clamp(2.5rem, 9vw, 3.75rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #10b981;
            line-height: 1.1;
        }
        .dollar-badge-icon {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }
        .dollar-stat-row {
            background: var(--surface-2);
            border: 1px solid var(--border-default);
            border-radius: 14px;
            padding: 0.9rem 1.25rem;
        }
    </style>
</head>
<body class="settings-module-page settings-page">

    <?php include_once '../../../includes/navigation.php'; ?>

    <div class="container py-4">
        
        <!-- Header -->
        <div class="settings-header-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <nav class="small text-muted mb-2" aria-label="breadcrumb">
                        <a href="<?php echo url('user/dashboard/index.php'); ?>" class="text-decoration-none text-muted">
                            <i class="bi bi-speedometer2"></i> داشبۆرد
                        </a>
                        <span class="mx-2">/</span>
                        <a href="<?php echo url('user/settings/main.php'); ?>" class="text-decoration-none text-muted">
                            ڕێکخستنەکان
                        </a>
                        <span class="mx-2">/</span>
                        <span class="text-primary fw-medium">نرخی دۆلار</span>
                    </nav>
                    <div class="d-flex align-items-center gap-3">
                        <div class="settings-icon section-dollar-price" style="width:52px;height:52px;font-size:24px;">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                        <div>
                            <h2 class="mb-0">نرخی دۆلاری ئەمڕۆ</h2>
                            <p class="text-muted small mb-0">بینینی نرخی کاتی بازاڕ و دوایین نوێکردنەوە لە سیستەم</p>
                        </div>
                    </div>
                </div>
                <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">
                <div class="dollar-widget-card p-4 p-md-5 text-center">

                    <div class="dollar-badge-icon mx-auto mb-4" aria-hidden="true">
                        <i class="bi bi-currency-dollar"></i>
                    </div>

                    <?php if ($dollarPrice): ?>
                        <p class="text-muted fw-semibold mb-2">نرخی پیشنیارکراوی دۆلار بە دیناری عێراقی</p>
                        <div class="dollar-rate-value mb-2">
                            <?php echo rtrim(rtrim(number_format($dollarPrice, 3, '.', ','), '0'), '.'); ?>
                        </div>
                        <p class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill mb-4">
                            بۆ هەر ١ دۆلاری ئەمریکی ($1)
                        </p>

                        <div class="d-flex flex-column gap-2 text-start small mb-4">
                            <div class="dollar-stat-row d-flex justify-content-between align-items-center">
                                <span class="text-muted"><i class="bi bi-calendar-event me-1"></i> دوایین بەرواری نوێکردنەوە</span>
                                <span class="fw-bold"><?php echo date('Y/m/d', strtotime($lastUpdated)); ?> <span class="text-muted fw-normal"><?php echo date('H:i', strtotime($lastUpdated)); ?></span></span>
                            </div>
                            <div class="dollar-stat-row d-flex justify-content-between align-items-center">
                                <span class="text-muted"><i class="bi bi-stopwatch me-1"></i> کاتی تێپەڕیو</span>
                                <span class="fw-bold text-success"><i class="bi bi-clock-history me-1"></i><?php echo htmlspecialchars($timeAgo); ?></span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <a href="<?php echo htmlspecialchars($selfUrl); ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> نوێکردنەوەی پەڕە
                            </a>
                            <a href="<?php echo url('user/settings/currency_exchange.php'); ?>" class="btn btn-save">
                                <i class="bi bi-sliders"></i> ڕێکخستنی ڕێژەی ئاڵوگۆڕ
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="py-4">
                            <i class="bi bi-exclamation-circle text-warning fs-1 d-block mb-3"></i>
                            <h5 class="fw-bold mb-2">هیچ نرخێک تۆمار نەکراوە</h5>
                            <p class="text-muted small mb-4">کاتێک نرخ لە سیستەمەکەدا نوێ بکرێتەوە، لێرەدا دەردەکەوێت.</p>
                            <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-save">
                                <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ ڕێکخستنەکان
                            </a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
