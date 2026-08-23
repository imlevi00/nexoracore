<?php
/**
 * بەشی سەرەکی ڕاپۆرتەکان - user/reports/main.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/zanyari_user_settings.php';
require_once '../../includes/profit_stats.php';
require_once '../../includes/reports_cache.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی دەسەڵاتەکانی بەکارهێنەر
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

$effectiveSubUserId = null;
if ($isSubUser && isset($currentUser['sub_user_id']) && $currentUser['sub_user_id']) {
    $effectiveSubUserId = (int)$currentUser['sub_user_id'];
}

$recognizeDebtRevenueAtSale = getRecognizeCustomerDebtRevenueAtSale($userId);

$periodCards = [];

$systemStartDate = date('Y-m-d');
$scopeClauseSales = $effectiveSubUserId !== null ? ' AND sub_user_id = ?' : '';
$scopeClauseReturns = $effectiveSubUserId !== null ? ' AND sub_user_id = ?' : '';

// هەوڵی وەرگرتنی کۆنترین بەروار لە داتاکانی بەکارهێنەر.
$minDates = [];

$salesSql = "SELECT MIN(DATE(sale_date)) AS min_date FROM sales WHERE user_id = ?" . $scopeClauseSales;
$stmt = $conn->prepare($salesSql);
if ($stmt) {
    if ($effectiveSubUserId !== null) {
        $stmt->bind_param("ii", $userId, $effectiveSubUserId);
    } else {
        $stmt->bind_param("i", $userId);
    }
    $stmt->execute();
    $salesRow = $stmt->get_result()->fetch_assoc();
    if (!empty($salesRow['min_date'])) {
        $minDates[] = $salesRow['min_date'];
    }
    $stmt->close();
}

$returnsSql = "SELECT MIN(DATE(return_date)) AS min_date FROM returns WHERE user_id = ?" . $scopeClauseReturns;
$stmt = $conn->prepare($returnsSql);
if ($stmt) {
    if ($effectiveSubUserId !== null) {
        $stmt->bind_param("ii", $userId, $effectiveSubUserId);
    } else {
        $stmt->bind_param("i", $userId);
    }
    $stmt->execute();
    $returnsRow = $stmt->get_result()->fetch_assoc();
    if (!empty($returnsRow['min_date'])) {
        $minDates[] = $returnsRow['min_date'];
    }
    $stmt->close();
}

if ($effectiveSubUserId === null) {
    $stmt = $conn->prepare("SELECT MIN(DATE(expense_date)) AS min_date FROM expenses WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $expensesRow = $stmt->get_result()->fetch_assoc();
        if (!empty($expensesRow['min_date'])) {
            $minDates[] = $expensesRow['min_date'];
        }
        $stmt->close();
    }
}

if (!empty($minDates)) {
    $systemStartDate = min($minDates);
}

$todayObj = new DateTime('today');
$today = $todayObj->format('Y-m-d');
$yesterday = (clone $todayObj)->modify('-1 day')->format('Y-m-d');

$thisMonthStart = (clone $todayObj)->modify('first day of this month')->format('Y-m-d');
$lastMonthStartObj = (clone $todayObj)->modify('first day of last month');
$lastMonthStart = $lastMonthStartObj->format('Y-m-d');
$lastMonthEnd = (clone $lastMonthStartObj)->modify('last day of this month')->format('Y-m-d');

$thisYearStart = (clone $todayObj)->setDate((int)$todayObj->format('Y'), 1, 1)->format('Y-m-d');
$lastYearStartObj = (clone $todayObj)->setDate((int)$todayObj->format('Y') - 1, 1, 1);
$lastYearStart = $lastYearStartObj->format('Y-m-d');
$lastYearEnd = (clone $lastYearStartObj)->setDate((int)$lastYearStartObj->format('Y'), 12, 31)->format('Y-m-d');

$periodDefinitions = [
    'system_to_now' => ['label' => 'لە کاتی دانانی سیستەم تا ئێستا', 'from' => $systemStartDate, 'to' => $today, 'icon' => 'bi bi-clock-history'],
    'last_year' => ['label' => 'ساڵی ڕابردوو', 'from' => $lastYearStart, 'to' => $lastYearEnd, 'icon' => 'bi bi-calendar3'],
    'this_year' => ['label' => 'ئەم ساڵ', 'from' => $thisYearStart, 'to' => $today, 'icon' => 'bi bi-calendar2-check'],
    'last_month' => ['label' => 'مانگی ڕابردوو', 'from' => $lastMonthStart, 'to' => $lastMonthEnd, 'icon' => 'bi bi-calendar-minus'],
    'this_month' => ['label' => 'ئەم مانگە', 'from' => $thisMonthStart, 'to' => $today, 'icon' => 'bi bi-calendar-month'],
    'yesterday' => ['label' => 'دوێنی', 'from' => $yesterday, 'to' => $yesterday, 'icon' => 'bi bi-sunrise'],
    'today' => ['label' => 'ئەمڕۆ', 'from' => $today, 'to' => $today, 'icon' => 'bi bi-sun'],
];

/*
 * کاشی واژوو‌بنەما (signature-based cache):
 * کاشەکە بۆ خێرایی دەمێنێتەوە، بەڵام کلیلەکەی واژووی داتاکانی تێدایە.
 * هەر کاتێک فرۆشتن/گەڕانەوە/خەرجی/پارەدانی قەرز نوێ تۆمار یان دەستکاری
 * بکرێت، واژووەکە دەگۆڕێت و کاشە کۆنەکە بەکارنایەت — واتە خۆکارانە
 * نوێ دەبێتەوە بەبێ هیچ دواکەوتنێک.
 */
$dataSignature = getReportsDataSignature($conn, (int)$userId, $effectiveSubUserId);
$periodCacheKey = sha1(implode('|', [
    'reports_main_period_cards_v4_multicurrency',
    (string)$userId,
    (string)($effectiveSubUserId ?? 0),
    $isSubUser ? '1' : '0',
    $recognizeDebtRevenueAtSale ? '1' : '0',
    $today,
    $dataSignature,
]));
$periodCacheFile = getReportsCacheDir() . DIRECTORY_SEPARATOR . $periodCacheKey . '.json';
$cachedPeriodCards = null;

// TTL ـێکی درێژ وەک تۆڕی پاراستن؛ ملمانێی ڕاستەقینە لەلایەن واژووەکەوە دەکرێت
if (is_file($periodCacheFile) && (time() - (int)@filemtime($periodCacheFile)) <= 3600) {
    $cachedJson = @file_get_contents($periodCacheFile);
    if ($cachedJson !== false) {
        $decoded = json_decode($cachedJson, true);
        if (is_array($decoded)) {
            $cachedPeriodCards = $decoded;
        }
    }
}

if ($cachedPeriodCards !== null) {
    $periodCards = $cachedPeriodCards;
} else {
    foreach ($periodDefinitions as $periodKey => $periodMeta) {
        $periodStatsByCurrency = calculateProfitStatsByCurrency(
            $conn,
            $userId,
            $periodMeta['from'],
            $periodMeta['to'],
            $effectiveSubUserId,
            $isSubUser,
            $recognizeDebtRevenueAtSale
        );
        $periodStatsIqd = $periodStatsByCurrency['IQD'];
        $periodStatsUsd = $periodStatsByCurrency['USD'];

        $periodCards[] = [
            'key' => $periodKey,
            'label' => $periodMeta['label'],
            'icon' => $periodMeta['icon'],
            'from' => $periodMeta['from'],
            'to' => $periodMeta['to'],
            'revenue' => (float)($periodStatsIqd['net_revenue'] ?? 0),
            'profit' => (float)($periodStatsIqd['profit'] ?? 0),
            'revenue_usd' => (float)($periodStatsUsd['net_revenue'] ?? 0),
            'profit_usd' => (float)($periodStatsUsd['profit'] ?? 0),
        ];
    }

    @file_put_contents($periodCacheFile, json_encode($periodCards, JSON_UNESCAPED_UNICODE));

    // پاککردنەوەی کاشە کۆنەکانی هەمان بەکارهێنەر (واژووە کۆنەکان) بۆ ئەوەی
    // فۆڵدەری کاش گەورە نەبێت
    cleanupStaleReportsCache($periodCacheFile);
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ڕاپۆرتەکان - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#0d6efd">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/reports/reports-pages.css'); ?>" rel="stylesheet">
</head>
<body class="reports-module-page reports-hub-page">
    <div id="pageLoader" class="page-loader" aria-hidden="true">
        <div class="page-loader-card">
            <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>
            <h6 class="mb-1">تکایە چاوەڕێبە...</h6>
            <small class="text-muted">لاپەڕەکە لە کاردایە</small>
            <div class="loader-progress">
                <div id="loaderProgressBar" class="loader-progress-bar"></div>
            </div>
            <small id="loaderProgressText" class="text-muted d-block mt-2">0%</small>
        </div>
    </div>
    <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content rp-wrap">

        <header class="rp-hero">
            <div>
                <div class="rp-kicker"><i class="bi bi-bar-chart-line"></i> سەنتەری ڕاپۆرت</div>
                <h1><i class="bi bi-graph-up-arrow"></i> ڕاپۆرتەکان</h1>
                <p class="rp-hero-sub">بەشی سەرەکی ڕاپۆرت و ئامارەکان</p>
            </div>
            <div class="rp-actions">
                <a href="<?php echo url('user/dashboard/index.php'); ?>" class="rp-btn rp-btn-ghost">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
                </a>
            </div>
        </header>

        <?php if (!$isSubUser || (isset($userPermissions['profits']) && $userPermissions['profits'])): ?>
        <div class="rp-period-grid">
            <?php foreach ($periodCards as $periodCard): ?>
                <div class="period-stat-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="period-stat-title"><?php echo htmlspecialchars($periodCard['label']); ?></div>
                        <i class="<?php echo htmlspecialchars($periodCard['icon']); ?> fs-5"></i>
                    </div>
                    <div class="mb-2">
                        <div class="period-stat-label">داهات (دینار)</div>
                        <div class="period-stat-value"><?php echo number_format($periodCard['revenue']); ?> دینار</div>
                    </div>
                    <div>
                        <div class="period-stat-label">قازانج (دینار)</div>
                        <div class="period-stat-value period-stat-profit <?php echo $periodCard['profit'] < 0 ? 'period-stat-profit-negative' : ''; ?>">
                            <?php echo number_format($periodCard['profit']); ?> دینار
                        </div>
                    </div>
                    <?php
                        $periodRevenueUsd = (float)($periodCard['revenue_usd'] ?? 0);
                        $periodProfitUsd = (float)($periodCard['profit_usd'] ?? 0);
                        $periodHasUsd = ($periodRevenueUsd != 0.0 || $periodProfitUsd != 0.0);
                    ?>
                    <?php if ($periodHasUsd): ?>
                    <div class="period-usd-divider"></div>
                    <div class="period-usd-heading">بە دۆلار</div>
                    <div class="mb-2">
                        <div class="period-stat-label">داهات</div>
                        <div class="period-stat-usd"><?php echo htmlspecialchars(formatCurrencyAmount($periodRevenueUsd, 'USD')); ?></div>
                    </div>
                    <div>
                        <div class="period-stat-label">قازانج</div>
                        <div class="period-stat-usd period-stat-profit <?php echo $periodProfitUsd < 0 ? 'period-stat-profit-negative' : ''; ?>">
                            <?php echo htmlspecialchars(formatCurrencyAmount($periodProfitUsd, 'USD')); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$isSubUser || (isset($userPermissions['profits']) && $userPermissions['profits'])): ?>
        <div class="cashier-receipt-bar">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <h6 class="mb-1"><i class="bi bi-printer"></i> وەسڵی کۆتایی ئەمڕۆ</h6>
                    <small class="text-muted">چاپکردنی وەسڵی کاشێر بۆ پێشکەشکردن لەگەڵ پارەی قاسە</small>
                </div>
                <div class="col-md-4">
                    <label for="mainReceiptShowMode" class="form-label mb-1">ناوەڕۆکی وەسڵ:</label>
                    <select id="mainReceiptShowMode" class="form-select form-select-sm">
                        <option value="both">داهات و قازانج</option>
                        <option value="revenue">تەنها داهات</option>
                        <option value="profit">تەنها قازانج</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="button" id="mainPrintCashierReceiptBtn" class="rp-btn rp-btn-primary w-100">
                        <i class="bi bi-printer"></i> چاپکردنی وەسڵ
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="rp-nav-grid mb-4">
            <?php if (!$isSubUser || (isset($userPermissions['profits']) && $userPermissions['profits'])): ?>
            <a href="<?php echo url('user/reports/index.php'); ?>" class="rp-nav-card rp-nav-profit js-show-loader">
                <div class="rp-nav-icon"><i class="bi bi-wallet2"></i></div>
                <div>
                    <h6>ڕاپۆرت + قازانج</h6>
                    <small>داهات، خەرجی، چارت و کورتەی هەفتانە</small>
                </div>
            </a>
            <a href="<?php echo url('user/reports/item_section_profit_report.php'); ?>" class="rp-nav-card rp-nav-items js-show-loader">
                <div class="rp-nav-icon"><i class="bi bi-box-seam"></i></div>
                <div>
                    <h6>ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان</h6>
                    <small>وردەکاری فرۆش و قازانج بۆ هەر کاڵا</small>
                </div>
            </a>
            <?php endif; ?>
        </div>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const pageLoader = document.getElementById('pageLoader');
        const loaderProgressBar = document.getElementById('loaderProgressBar');
        const loaderProgressText = document.getElementById('loaderProgressText');
        let loaderProgress = 0;
        let loaderTimer = null;

        function showPageLoader() {
            if (!pageLoader) {
                return;
            }

            pageLoader.classList.add('active');
            pageLoader.setAttribute('aria-hidden', 'false');
            loaderProgress = 8;
            loaderProgressBar.style.width = loaderProgress + '%';
            loaderProgressText.textContent = loaderProgress + '%';

            if (loaderTimer) {
                clearInterval(loaderTimer);
            }

            loaderTimer = setInterval(() => {
                if (loaderProgress < 92) {
                    loaderProgress += (loaderProgress < 60 ? 6 : 2);
                    loaderProgress = Math.min(loaderProgress, 92);
                    loaderProgressBar.style.width = loaderProgress + '%';
                    loaderProgressText.textContent = loaderProgress + '%';
                }
            }, 220);
        }

        function completeLoaderAndHide() {
            if (!pageLoader) {
                return;
            }

            if (loaderTimer) {
                clearInterval(loaderTimer);
                loaderTimer = null;
            }

            loaderProgress = 100;
            loaderProgressBar.style.width = '100%';
            loaderProgressText.textContent = '100%';

            setTimeout(() => {
                pageLoader.classList.remove('active');
                pageLoader.setAttribute('aria-hidden', 'true');
            }, 140);
        }

        document.querySelectorAll('.js-show-loader').forEach((el) => {
            el.addEventListener('click', () => {
                showPageLoader();
            });
        });

        window.addEventListener('pageshow', () => {
            completeLoaderAndHide();
        });

        const mainPrintCashierReceiptBtn = document.getElementById('mainPrintCashierReceiptBtn');
        const mainReceiptShowMode = document.getElementById('mainReceiptShowMode');
        const mainPrintReceiptBaseUrl = <?php echo json_encode(url('user/reports/print_receipt.php')); ?>;
        const mainTodayDate = <?php echo json_encode($today); ?>;

        if (mainPrintCashierReceiptBtn) {
            mainPrintCashierReceiptBtn.addEventListener('click', () => {
                const params = new URLSearchParams();
                params.set('from_date', mainTodayDate);
                params.set('to_date', mainTodayDate);
                if (mainReceiptShowMode && mainReceiptShowMode.value) {
                    params.set('show', mainReceiptShowMode.value);
                }
                params.set('print', '1');
                window.open(mainPrintReceiptBaseUrl + '?' + params.toString(), '_blank');
            });
        }
    </script>

</body>
</html>

