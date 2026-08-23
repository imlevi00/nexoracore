<?php
/**
 * بەشی قازانج - user/reports/index.php
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
enforceAuthorizationOrDeny($currentUser, 'profits.view', [
    'route' => '/user/reports/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

$packageInfo = getUserPackageInfo($userId);
// تاقیکردنی جۆری بەکارهێنەر لە ماوەی فلتەری کارمەند
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

// ڕێکخستنی ماوەی قازانج بەپێی "لە بەرواری ... تا بەرواری ..."
$today = date('Y-m-d');

// وەرگرتنی بەروارەکان لە GET یەوە، دیفۆڵت: ئەمڕۆ
$fromDateInput = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : $today;
$toDateInput   = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : $today;

// دڵنیابوون لە شێوەی بەروار و چوونەناو ماوەی ڕاست
$fromDateObj = DateTime::createFromFormat('Y-m-d', $fromDateInput) ?: new DateTime($today);
$toDateObj   = DateTime::createFromFormat('Y-m-d', $toDateInput) ?: new DateTime($today);

// ئەگەر بەرواری دەستپێک لەدوای کۆتاییبوو، گۆڕینیان
if ($fromDateObj > $toDateObj) {
    $tmp = $fromDateObj;
    $fromDateObj = $toDateObj;
    $toDateObj = $tmp;
}

$fromDate = $fromDateObj->format('Y-m-d');
$toDate   = $toDateObj->format('Y-m-d');

// فلتەری كارمەند (sub_user) بۆ بەڕێوەبەری سەرەکی
$subUsers = [];
$selectedSubUserId = 0;
$effectiveSubUserId = null;
$selectedSubUserName = '';

if ($isSubUser) {
    // كاتێك sub-user داخڵبووە، هەموو قازانج بە پێی ئەم كارمەندە حیساب بكرێت
    if (isset($currentUser['sub_user_id']) && $currentUser['sub_user_id']) {
        $effectiveSubUserId = (int)$currentUser['sub_user_id'];
        if (!empty($currentUser['full_name'])) {
            $selectedSubUserName = $currentUser['full_name'];
        } elseif (!empty($currentUser['username'])) {
            $selectedSubUserName = $currentUser['username'];
        }
    }
} else {
    // بەڕێوەبەری سەرەکی: وەرگرتنی لیستی كارمەندان بۆ dropdown
    $stmt = $conn->prepare("SELECT id, username, full_name FROM sub_users WHERE main_user_id = ? ORDER BY full_name ASC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $subUsers[] = $row;
    }
    $stmt->close();

    // وەرگرتنی فلتەری كارمەند لە GET
    if (isset($_GET['sub_user_id']) && $_GET['sub_user_id'] !== '') {
        $selectedSubUserId = (int)$_GET['sub_user_id'];
    }

    if ($selectedSubUserId > 0) {
        // دڵنیابوون لەوەی ئەم sub_user-ە بەرزی ئەم بەڕێوەبەرەیە
        $check = $conn->prepare("SELECT id, full_name, username FROM sub_users WHERE id = ? AND main_user_id = ?");
        $check->bind_param("ii", $selectedSubUserId, $userId);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();

        if ($row) {
            $effectiveSubUserId = (int)$row['id'];
            $selectedSubUserName = $row['full_name'] ?: $row['username'];
        } else {
            $selectedSubUserId = 0;
        }
    }
}

// تێبینی: كاتێك $effectiveSubUserId !== null بێت، هەموو حیسابكردنەكان بە پێی ئەو كارمەندە دەكرێن

$recognizeDebtRevenueAtSale = getRecognizeCustomerDebtRevenueAtSale($userId);

// کاشی واژوو‌بنەما: کلیلەکە واژووی داتاکانی تێدایە، بۆیە هەر کاتێک فرۆشتن/
// گەڕانەوە/خەرجی/پارەدانی قەرز نوێ تۆمار یان دەستکاری بکرێت، کاشەکە خۆکارانە
// نوێ دەبێتەوە. TTL تەنها وەک تۆڕی پاراستنە بۆ پاکسازی.
$cacheTtlSeconds = 3600;
$cacheDir = getReportsCacheDir();

$dataSignature = getReportsDataSignature($conn, (int)$userId, $effectiveSubUserId);
$cacheKey = sha1(implode('|', [
    'reports_v5_multicurrency',
    (string)$userId,
    $fromDate,
    $toDate,
    (string)($effectiveSubUserId ?? 0),
    $isSubUser ? '1' : '0',
    $recognizeDebtRevenueAtSale ? '1' : '0',
    $dataSignature
]));
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';
$cachedReport = null;

if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) <= $cacheTtlSeconds) {
    $cachedJson = @file_get_contents($cacheFile);
    if ($cachedJson !== false) {
        $cachedReport = json_decode($cachedJson, true);
    }
}

if (is_array($cachedReport) && isset($cachedReport['statsByCurrency'], $cachedReport['chartData'], $cachedReport['weeklySummary'])) {
    $statsByCurrency = $cachedReport['statsByCurrency'];
    $chartData = $cachedReport['chartData'];
    $weeklySummary = $cachedReport['weeklySummary'];
} else {
    // حیسابکردنی ئامارەکانی سەرەکی بۆ هەردوو دراو بە جیایی
    $statsByCurrency = calculateProfitStatsByCurrency($conn, $userId, $fromDate, $toDate, $effectiveSubUserId, $isSubUser, $recognizeDebtRevenueAtSale);

    // حیسابکردنی داتای چارت بەپێی ماوەی هەڵبژێردراو
    $fromTimestamp = strtotime($fromDate);
    $toTimestamp   = strtotime($toDate);

    // ژمارەی ڕۆژەکان لە نێوان ماوەکە (بە شێوەی ناوەڕاست وەک 1، 2، ...)
    $daysDiff = (int)floor(($toTimestamp - $fromTimestamp) / 86400) + 1;
    if ($daysDiff < 1) {
        $daysDiff = 1;
    }

    // سنووردانی چارت بۆ پاراستنی کارایی (تا 30 ڕۆژ)
    $maxChartDays = 30;
    if ($daysDiff > $maxChartDays) {
        $chartFromDate = date('Y-m-d', strtotime($toDate . ' -' . ($maxChartDays - 1) . ' days'));
        $daysForChart = $maxChartDays;
    } else {
        $chartFromDate = $fromDate;
        $daysForChart = $daysDiff;
    }

    $chartData = [];
    $chartDataByDate = [];
    for ($i = 0; $i < $daysForChart; $i++) {
        $date = date('Y-m-d', strtotime($chartFromDate . ' +' . $i . ' days'));
        $dayIqd = calculateProfitStats($conn, $userId, $date, $date, $effectiveSubUserId, $isSubUser, $recognizeDebtRevenueAtSale, 'IQD');
        $dayUsd = calculateProfitStats($conn, $userId, $date, $date, $effectiveSubUserId, $isSubUser, $recognizeDebtRevenueAtSale, 'USD');
        $chartRow = [
            'date' => $date,
            'profit' => $dayIqd['profit'],
            'revenue' => $dayIqd['net_revenue'],
            'cost' => $dayIqd['net_cost_of_goods'],
            'profit_usd' => $dayUsd['profit'],
            'revenue_usd' => $dayUsd['net_revenue'],
            'cost_usd' => $dayUsd['net_cost_of_goods']
        ];
        $chartData[] = $chartRow;
        $chartDataByDate[$date] = $chartRow;
    }

    // حیسابکردنی کورتەی هەفتانە (تا 7 ڕۆژی کۆتایی لە ناو ماوەکەدا)
    $weeklySummary = [];
    $kurdishDays = [
        'Saturday' => 'شەممە',
        'Sunday' => 'یەکشەممە',
        'Monday' => 'دووشەممە',
        'Tuesday' => 'سێشەممە',
        'Wednesday' => 'چوارشەممە',
        'Thursday' => 'پێنجشەممە',
        'Friday' => 'هەینی'
    ];

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime($toDate . ' -' . $i . ' days'));

        // تەنیا ئەو ڕۆژانە دابنێ کە لە نێوان ماوەی دیاریکراودا بن
        if ($date < $fromDate) {
            continue;
        }

        if (!isset($chartDataByDate[$date])) {
            continue;
        }

        $dayStats = $chartDataByDate[$date];
        $dayName = date('l', strtotime($date));

        $weeklySummary[] = [
            'date' => $date,
            'day_name' => $kurdishDays[$dayName] ?? $dayName,
            'profit' => $dayStats['profit'],
            'revenue' => $dayStats['revenue'],
            'profit_usd' => $dayStats['profit_usd'] ?? 0,
            'revenue_usd' => $dayStats['revenue_usd'] ?? 0
        ];
    }

    @file_put_contents($cacheFile, json_encode([
        'statsByCurrency' => $statsByCurrency,
        'chartData' => $chartData,
        'weeklySummary' => $weeklySummary
    ], JSON_UNESCAPED_UNICODE));

    cleanupStaleReportsCache($cacheFile);
}

// جیاکردنەوەی ئامارەکان بەپێی دراو
$mainStatsIqd = $statsByCurrency['IQD'] ?? [];
$mainStatsUsd = $statsByCurrency['USD'] ?? [];

// ئایا هیچ چالاکیەکی دۆلاری هەیە لەم ماوەیەدا؟ (بۆ ئەوەی بەشی دۆلار تەنها
// کاتێک پیشان بدرێت کە پێویست بێت)
$hasUsdActivity = (
    (float)($mainStatsUsd['revenue'] ?? 0) != 0.0
    || (float)($mainStatsUsd['cost_of_goods'] ?? 0) != 0.0
    || (float)($mainStatsUsd['returns'] ?? 0) != 0.0
    || (float)($mainStatsUsd['expenses'] ?? 0) != 0.0
);

// یارمەتیدەری فۆرماتکردن
$fmtIqd = function ($v) { return number_format((float)$v) . ' دینار'; };
$fmtUsd = function ($v) { return formatCurrencyAmount((float)$v, 'USD'); };

$csrf_token = Security::generateCSRFToken();

$fromLabel = date('Y/m/d', strtotime($fromDate));
$toLabel   = date('Y/m/d', strtotime($toDate));
$rangeLabel = ($fromDate === $toDate)
    ? "لە بەرواری {$fromLabel}"
    : "لە بەرواری {$fromLabel} تا بەرواری {$toLabel}";

$filterScopeLabel = 'قازانجی گشتی';
if ($effectiveSubUserId !== null) {
    if ($selectedSubUserName !== '') {
        $filterScopeLabel = "قازانجی كارمەند: " . htmlspecialchars($selectedSubUserName);
    } else {
        $filterScopeLabel = "قازانجی كارمەند";
    }
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>بەشی قازانج - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/reports/reports-pages.css'); ?>" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="reports-module-page reports-profit-page reports-page">
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
                <div class="rp-kicker"><i class="bi bi-graph-up-arrow"></i> ڕاپۆرتەکان</div>
                <h1><i class="bi bi-wallet2"></i> بەشی قازانج</h1>
                <p class="rp-hero-sub"><?php echo htmlspecialchars($currentUser['business_name']); ?></p>
                <div class="rp-hero-pills">
                    <span class="rp-pill"><?php echo $filterScopeLabel; ?></span>
                    <span class="rp-pill"><i class="bi bi-calendar3"></i> <?php echo $rangeLabel; ?></span>
                </div>
            </div>
            <div class="rp-hero-profit">
                <a href="<?php echo url('user/reports/main.php'); ?>" class="rp-btn rp-btn-ghost mb-2">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
                <p class="rp-profit-iqd <?php echo ($mainStatsIqd['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                    <?php echo $fmtIqd($mainStatsIqd['profit'] ?? 0); ?>
                </p>
                <?php if ($hasUsdActivity): ?>
                    <div class="rp-profit-usd <?php echo ($mainStatsUsd['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        <?php echo $fmtUsd($mainStatsUsd['profit'] ?? 0); ?>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <section class="rp-panel filter-section">
            <div class="rp-panel-head">
                <span><i class="bi bi-funnel"></i> فیلتەرکردن و چاپ</span>
            </div>
            <div class="rp-panel-body">
            <form id="reportFilterForm" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="from_date" class="form-label">لە بەرواری:</label>
                    <input
                        type="date"
                        name="from_date"
                        id="from_date"
                        class="form-control"
                        value="<?php echo htmlspecialchars($fromDate); ?>"
                    >
                </div>

                <div class="col-md-3">
                    <label for="to_date" class="form-label">تا بەرواری:</label>
                    <input
                        type="date"
                        name="to_date"
                        id="to_date"
                        class="form-control"
                        value="<?php echo htmlspecialchars($toDate); ?>"
                    >
                </div>

                <?php if (!$isSubUser): ?>
                <div class="col-md-3">
                    <label for="sub_user_id" class="form-label">كارمەند:</label>
                    <select
                        name="sub_user_id"
                        id="sub_user_id"
                        class="form-select"
                    >
                        <option value="0"<?php echo $selectedSubUserId === 0 ? ' selected' : ''; ?>>سەرەکی / هەموو كارمەندان</option>
                        <?php foreach ($subUsers as $su): ?>
                            <option
                                value="<?php echo (int)$su['id']; ?>"
                                <?php echo $selectedSubUserId === (int)$su['id'] ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars(($su['full_name'] ?: $su['username']) . ' (@' . $su['username'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="rp-btn rp-btn-primary">
                        <i class="bi bi-search"></i> پیشاندان
                    </button>
                </div>

                <div class="col-12">
                    <hr class="my-1">
                </div>

                <div class="col-md-4">
                    <label for="receiptShowMode" class="form-label">ناوەڕۆکی وەسڵ:</label>
                    <select id="receiptShowMode" class="form-select">
                        <option value="both">داهات و قازانج</option>
                        <option value="revenue">تەنها داهات</option>
                        <option value="profit">تەنها قازانج</option>
                    </select>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <button type="button" id="printCashierReceiptBtn" class="rp-btn rp-btn-ghost">
                        <i class="bi bi-printer"></i> چاپکردنی وەسڵ
                    </button>
                </div>
            </form>
            </div>
        </section>

        <div class="rp-stats rp-stats-4">
            <div class="stat-card" style="--stat-accent:#10b981">
                <div class="stat-number profit-positive"><?php echo number_format($mainStatsIqd['net_revenue'] ?? 0); ?></div>
                <div class="rp-stat-label">کۆی داهات</div>
                <small class="text-muted">دینار</small>
                <?php if ((float)($mainStatsIqd['returns'] ?? 0) > 0): ?>
                    <small class="text-muted d-block mt-1">
                        فرۆشتن: <?php echo number_format($mainStatsIqd['revenue'] ?? 0); ?>
                    </small>
                <?php endif; ?>
                <?php if ($hasUsdActivity): ?>
                    <div class="stat-usd profit-positive">
                        <span class="stat-usd-label">بە دۆلار</span>
                        <?php echo $fmtUsd($mainStatsUsd['net_revenue'] ?? 0); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="stat-card" style="--stat-accent:#f59e0b">
                <div class="stat-number text-warning"><?php echo number_format($mainStatsIqd['cost_of_goods'] ?? 0); ?></div>
                <div class="rp-stat-label">نرخی کڕین</div>
                <small class="text-muted">دینار</small>
                <?php if (isset($mainStatsIqd['net_cost_of_goods'])): ?>
                    <small class="text-muted d-block mt-1">
                        نرخی کڕینی پاک: <?php echo number_format($mainStatsIqd['net_cost_of_goods']); ?>
                    </small>
                <?php endif; ?>
                <?php if ($hasUsdActivity): ?>
                    <div class="stat-usd text-warning">
                        <span class="stat-usd-label">بە دۆلار</span>
                        <?php echo $fmtUsd($mainStatsUsd['cost_of_goods'] ?? 0); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="stat-card" style="--stat-accent:#ef4444">
                <div class="stat-number text-danger"><?php echo number_format($mainStatsIqd['expenses'] ?? 0); ?></div>
                <div class="rp-stat-label">خەرجیەکان</div>
                <small class="text-muted">دینار</small>
                <?php if ($hasUsdActivity): ?>
                    <div class="stat-usd text-danger">
                        <span class="stat-usd-label">بە دۆلار</span>
                        <?php echo $fmtUsd($mainStatsUsd['expenses'] ?? 0); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="stat-card" style="--stat-accent:#0ea5e9">
                <div class="stat-number text-info"><?php echo number_format($mainStatsIqd['returns'] ?? 0); ?></div>
                <div class="rp-stat-label">گەڕاندنەوەکان</div>
                <small class="text-muted">دینار</small>
                <?php if ($hasUsdActivity): ?>
                    <div class="stat-usd text-info">
                        <span class="stat-usd-label">بە دۆلار</span>
                        <?php echo $fmtUsd($mainStatsUsd['returns'] ?? 0); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rp-stats rp-stats-2">
            <div class="stat-card" style="--stat-accent:#9333ea">
                <div class="stat-number <?php echo ($mainStatsIqd['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                    <?php echo number_format($mainStatsIqd['profit'] ?? 0); ?>
                </div>
                <div class="rp-stat-label">قازانجی پاک</div>
                <small class="text-muted">دینار</small>
                <?php if ($hasUsdActivity): ?>
                    <div class="stat-usd <?php echo ($mainStatsUsd['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        <span class="stat-usd-label">بە دۆلار</span>
                        <?php echo $fmtUsd($mainStatsUsd['profit'] ?? 0); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="stat-card" style="--stat-accent:#6366f1">
                <div class="stat-number <?php echo ($mainStatsIqd['profit_margin'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                    <?php echo number_format($mainStatsIqd['profit_margin'] ?? 0, 2); ?>%
                </div>
                <div class="rp-stat-label">ڕێژەی قازانج (دینار)</div>
                <small class="text-muted">لەسەدی</small>
                <?php if ($hasUsdActivity): ?>
                    <div class="stat-usd <?php echo ($mainStatsUsd['profit_margin'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        <span class="stat-usd-label">ڕێژەی قازانجی دۆلار</span>
                        <?php echo number_format($mainStatsUsd['profit_margin'] ?? 0, 2); ?>%
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <section class="rp-panel">
            <div class="rp-panel-head">
                <span><i class="bi bi-graph-up"></i> چارتی قازانج بە دینار (30 ڕۆژی ڕابردوو)</span>
            </div>
            <div class="rp-panel-body">
                <div class="chart-container">
                    <canvas id="profitChart"></canvas>
                </div>
            </div>
        </section>

        <section class="rp-panel">
            <div class="rp-panel-head">
                <span><i class="bi bi-calendar-week"></i> کورتەی هەفتانە (7 ڕۆژی ڕابردوو)</span>
            </div>
            <div class="rp-panel-body">
                <div class="row g-3">
                    <?php foreach ($weeklySummary as $day): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="weekly-card">
                            <div class="fw-bold text-primary"><?php echo $day['day_name']; ?></div>
                            <div class="small text-muted"><?php echo date('Y/m/d', strtotime($day['date'])); ?></div>
                            <div class="h5 mt-2 <?php echo $day['profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                <?php echo number_format($day['profit']); ?>
                            </div>
                            <div class="small text-muted">دینار</div>
                            <div class="small text-success">
                                داهات: <?php echo number_format($day['revenue']); ?>
                            </div>
                            <?php if ($hasUsdActivity): ?>
                                <div class="stat-usd mt-2 <?php echo ($day['profit_usd'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>" style="font-size:1rem;">
                                    <span class="stat-usd-label">بە دۆلار</span>
                                    <?php echo $fmtUsd($day['profit_usd'] ?? 0); ?>
                                </div>
                                <div class="small text-success">
                                    داهات: <?php echo $fmtUsd($day['revenue_usd'] ?? 0); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

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

        const reportFilterForm = document.getElementById('reportFilterForm');
        if (reportFilterForm) {
            reportFilterForm.addEventListener('submit', () => {
                showPageLoader();
            });
        }

        document.querySelectorAll('.js-show-loader').forEach((el) => {
            el.addEventListener('click', () => {
                showPageLoader();
            });
        });

        window.addEventListener('pageshow', () => {
            completeLoaderAndHide();
        });

        const printCashierReceiptBtn = document.getElementById('printCashierReceiptBtn');
        const receiptShowMode = document.getElementById('receiptShowMode');
        const printReceiptBaseUrl = <?php echo json_encode(url('user/reports/print_receipt.php')); ?>;

        if (printCashierReceiptBtn && reportFilterForm) {
            printCashierReceiptBtn.addEventListener('click', () => {
                const params = new URLSearchParams();
                const fromDateEl = reportFilterForm.querySelector('[name="from_date"]');
                const toDateEl = reportFilterForm.querySelector('[name="to_date"]');
                const subUserEl = reportFilterForm.querySelector('[name="sub_user_id"]');

                if (fromDateEl && fromDateEl.value) {
                    params.set('from_date', fromDateEl.value);
                }
                if (toDateEl && toDateEl.value) {
                    params.set('to_date', toDateEl.value);
                }
                if (subUserEl && subUserEl.value && subUserEl.value !== '0') {
                    params.set('sub_user_id', subUserEl.value);
                }
                if (receiptShowMode && receiptShowMode.value) {
                    params.set('show', receiptShowMode.value);
                }
                params.set('print', '1');

                window.open(printReceiptBaseUrl + '?' + params.toString(), '_blank');
            });
        }

        // Chart Data
        const chartData = <?php echo json_encode($chartData); ?>;
        
        // Create Profit Chart
        const ctx = document.getElementById('profitChart').getContext('2d');
        const isDarkTheme = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const chartTextColor = isDarkTheme ? '#cbd5e1' : '#475569';
        const chartGridColor = isDarkTheme ? 'rgba(148, 163, 184, 0.2)' : 'rgba(100, 116, 139, 0.15)';

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.map(item => {
                    const date = new Date(item.date);
                    return date.toLocaleDateString('ku-Arab-IQ');
                }),
                datasets: [{
                    label: 'قازانج (دینار)',
                    data: chartData.map(item => item.profit),
                    borderColor: '#9333ea',
                    backgroundColor: 'rgba(147, 51, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'داهات (دینار)',
                    data: chartData.map(item => item.revenue),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: false
                }, {
                    label: 'نرخی کڕین (دینار)',
                    data: chartData.map(item => item.cost),
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    tension: 0.4,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: chartTextColor
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: chartGridColor
                        },
                        ticks: {
                            color: chartTextColor,
                            callback: function(value) {
                                return new Intl.NumberFormat('en-US').format(value) + ' دینار';
                            }
                        }
                    },
                    x: {
                        grid: {
                            color: chartGridColor
                        },
                        ticks: {
                            color: chartTextColor
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });
    </script>

</body>
</html>
