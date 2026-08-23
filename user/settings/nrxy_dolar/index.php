<?php
/**
 * پەڕەی نرخی دۆلار - user/settings/nrxy_dolar/index.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';

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
    <title>نرخی دۆلار - <?php echo htmlspecialchars($currentUser['business_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    <style>
        .dollar-page .price-block {
            font-size: clamp(2.25rem, 8vw, 3.25rem);
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #0f766e;
            line-height: 1.15;
        }
        .dollar-page .meta-line {
            font-size: 0.9rem;
            color: #64748b;
        }
        .dollar-page .card-main {
            border: none;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 8px 24px rgba(15, 23, 42, 0.06);
        }
        .dollar-page .soft-badge {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: rgba(15, 118, 110, 0.1);
            color: #0f766e;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
        }
        .dollar-page .divider {
            height: 1px;
            background: #e2e8f0;
            margin: 1.25rem 0;
        }
    </style>
</head>
<body class="settings-module-page dollar-page bg-light">

    <?php include_once '../../../includes/navigation.php'; ?>

    <div class="container py-4">
        <div class="row mb-3">
            <div class="col-12">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <nav class="small text-muted mb-1" aria-label="breadcrumb">
                            <a href="<?php echo url('user/settings/main.php'); ?>" class="text-decoration-none text-muted">ڕێکخستنەکان</a>
                            <span class="mx-1">/</span>
                            <span>نرخی دۆلار</span>
                        </nav>
                        <h1 class="h3 mb-0 fw-semibold">نرخی دۆلاری ئەمڕۆ</h1>
                    </div>
                    <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-right"></i> گەڕانەوە
                    </a>
                </div>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">
                <div class="card card-main">
                    <div class="card-body p-4 p-md-5 text-center">

                        <div class="soft-badge mx-auto mb-3" aria-hidden="true">
                            <i class="bi bi-currency-dollar"></i>
                        </div>

                        <?php if ($dollarPrice): ?>
                            <p class="meta-line mb-2">نرخی پیشنیارکراو (دینار)</p>
                            <div class="price-block">
                                <?php echo rtrim(rtrim(number_format($dollarPrice, 3, '.', ','), '0'), '.'); ?>
                            </div>
                            <p class="small text-muted mt-2 mb-0">بۆ هەر ١ دۆلار</p>

                            <div class="divider"></div>

                            <div class="text-start small">
                                <div class="d-flex justify-content-between py-1 border-bottom border-light-subtle">
                                    <span class="text-muted">دوایین نوێکردنەوە</span>
                                    <span class="fw-medium"><?php echo date('Y-m-d', strtotime($lastUpdated)); ?> <span class="text-muted"><?php echo date('H:i', strtotime($lastUpdated)); ?></span></span>
                                </div>
                                <div class="d-flex justify-content-between py-2">
                                    <span class="text-muted">کاتی تێپەڕیو</span>
                                    <span class="text-teal" style="color:#0f766e;"><?php echo htmlspecialchars($timeAgo); ?></span>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
                                <a href="<?php echo htmlspecialchars($selfUrl); ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> نوێکردنەوە
                                </a>
                                <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-primary">
                                    <i class="bi bi-house"></i> داشبۆرد
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-danger mb-3">
                                <i class="bi bi-exclamation-circle"></i>
                                هیچ نرخێک تۆمار نەکراوە
                            </p>
                            <p class="meta-line mb-4">کاتێک نرخ لە سیستەمەکەدا هەبێت، لێرە دەردەکەوێت.</p>
                            <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-primary">
                                <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ ڕێکخستنەکان
                            </a>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
