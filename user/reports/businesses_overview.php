<?php
/**
 * ڕاپۆرتی گشتی هەموو بزنسەکان (Multi-Business Consolidated Overview)
 * user/reports/businesses_overview.php
 *
 * تەنها خاوەنی ڕێکخراو (main-user کە owner_user_id ـیەتی) دەیبینێت.
 * دراوەکان (IQD/USD) هەرگیز تێکەڵ ناکرێن — هەر بزنس × هەر دراو بە جیا.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/zanyari_user_settings.php';
require_once __DIR__ . '/../../includes/profit_stats.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    $database = new Database();
    $conn = $database->connect();
}

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();

// دەستپێگەیشتن: تەنها خاوەنی ڕێکخراو
$ctx = getBusinessSwitchContext();
if ($ctx === null || count($ctx['businesses']) < 2) {
    setMessage('ئەم پەڕەیە تەنها بۆ خاوەنی چەندین بزنسە', 'warning');
    redirect(url('user/dashboard/index.php'));
}

$businesses = $ctx['businesses'];

// ماوەی بەروار — دیفۆڵت: مانگی ئێستا
$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$fromInput = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : $defaultFrom;
$toInput   = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : $today;

$fromObj = DateTime::createFromFormat('Y-m-d', $fromInput) ?: new DateTime($defaultFrom);
$toObj   = DateTime::createFromFormat('Y-m-d', $toInput) ?: new DateTime($today);
if ($fromObj > $toObj) { $t = $fromObj; $fromObj = $toObj; $toObj = $t; }
$fromDate = $fromObj->format('Y-m-d');
$toDate   = $toObj->format('Y-m-d');

// کۆکردنەوەی ئامار بۆ هەر بزنس، بە جیا بۆ IQD و USD
$rows = [];
$totals = [
    'IQD' => ['revenue' => 0.0, 'expenses' => 0.0, 'profit' => 0.0],
    'USD' => ['revenue' => 0.0, 'expenses' => 0.0, 'profit' => 0.0],
];

foreach ($businesses as $biz) {
    $bizId = (int)$biz['id'];
    $recognize = getRecognizeCustomerDebtRevenueAtSale($bizId);
    $statsByCurrency = calculateProfitStatsByCurrency($conn, $bizId, $fromDate, $toDate, null, false, $recognize);

    $row = ['business' => $biz, 'IQD' => null, 'USD' => null];
    foreach (['IQD', 'USD'] as $cur) {
        $s = $statsByCurrency[$cur] ?? [];
        $revenue = (float)($s['net_revenue'] ?? 0);
        $expenses = (float)($s['expenses'] ?? 0);
        $profit = (float)($s['profit'] ?? 0);
        $row[$cur] = ['revenue' => $revenue, 'expenses' => $expenses, 'profit' => $profit];
        $totals[$cur]['revenue'] += $revenue;
        $totals[$cur]['expenses'] += $expenses;
        $totals[$cur]['profit'] += $profit;
    }
    $rows[] = $row;
}

// دۆزینەوەی «باشترین داهات» و «زۆرترین خەرجی» بۆ هەر دراو
$best = [
    'IQD' => ['top_revenue' => null, 'top_expense' => null],
    'USD' => ['top_revenue' => null, 'top_expense' => null],
];
foreach (['IQD', 'USD'] as $cur) {
    $maxRev = -INF; $maxExp = -INF;
    foreach ($rows as $r) {
        if ($r[$cur]['revenue'] > $maxRev) { $maxRev = $r[$cur]['revenue']; $best[$cur]['top_revenue'] = $r['business']['business_name']; }
        if ($r[$cur]['expenses'] > $maxExp) { $maxExp = $r[$cur]['expenses']; $best[$cur]['top_expense'] = $r['business']['business_name']; }
    }
    if ($maxRev <= 0) { $best[$cur]['top_revenue'] = null; }
    if ($maxExp <= 0) { $best[$cur]['top_expense'] = null; }
}

/**
 * فۆرماتی دراو
 * @param float|int $amount
 * @param string $currency
 * @return string
 */
function mbo_fmt($amount, string $currency): string {
    if ($currency === 'USD') {
        return '$' . number_format((float)$amount, 2);
    }
    return number_format((float)$amount, 0) . ' د.ع';
}

$csrf_token = Security::generateCSRFToken();

// یاریدەدەرەکانی ڕوخسار
$bizGradients = [
    'linear-gradient(135deg,#0d6efd,#3b82f6)',
    'linear-gradient(135deg,#059669,#10b981)',
    'linear-gradient(135deg,#d97706,#f59e0b)',
    'linear-gradient(135deg,#7c3aed,#a855f7)',
    'linear-gradient(135deg,#0891b2,#06b6d4)',
    'linear-gradient(135deg,#db2777,#ec4899)',
];
$bizFirstChar = static function ($name) {
    $name = trim((string)$name);
    return $name === '' ? '؟' : mb_substr($name, 0, 1, 'UTF-8');
};
$curMeta = [
    'IQD' => ['label' => 'دینار', 'grad' => 'linear-gradient(135deg,#1e40af,#4338ca)', 'icon' => 'bi-cash-stack'],
    'USD' => ['label' => 'دۆلار', 'grad' => 'linear-gradient(135deg,#047857,#0f766e)', 'icon' => 'bi-currency-dollar'],
];
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ڕاپۆرتی گشتی بزنسەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <style>
        body { background:#eef1f6; }
        .cur-iqd { color: #4338ca; }
        .cur-usd { color: #0f766e; }
        .profit-pos { color: #15803d; font-weight: 600; }
        .profit-neg { color: #dc3545; font-weight: 600; }

        /* سەردێری بزنس */
        .mbo-hero{ border:0; border-radius:18px; overflow:hidden; box-shadow:0 6px 22px rgba(30,64,175,.12); }
        .mbo-hero-head{ background:linear-gradient(135deg,#1e40af,#4338ca); color:#fff; padding:20px 24px; }
        .mbo-hero-head h4{ margin:0; font-weight:700; }

        /* کارتی دراو */
        .cur-block{ border:0; border-radius:16px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,.07); }
        .cur-block-head{ color:#fff; padding:14px 20px; display:flex; align-items:center; gap:.6rem; font-weight:700; }
        .kpi{ text-align:center; padding:18px 10px; }
        .kpi .kpi-ico{ width:44px; height:44px; border-radius:12px; display:inline-flex; align-items:center;
            justify-content:center; font-size:20px; margin-bottom:8px; }
        .kpi .kpi-label{ font-size:.8rem; color:#8b93a5; display:block; }
        .kpi .kpi-val{ font-size:1.35rem; font-weight:700; }
        .kpi + .kpi{ border-right:1px solid #eef0f4; }
        .best-chip{ display:inline-flex; align-items:center; gap:.35rem; background:#f6f8fc;
            border-radius:999px; padding:.3rem .7rem; font-size:.8rem; margin:.15rem; }

        /* خشتەی بزنس */
        .mbo-table{ margin:0; }
        .mbo-table td, .mbo-table th{ vertical-align:middle; }
        .biz-ava-sm{ width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center;
            justify-content:center; font-weight:700; color:#fff; font-size:.85rem; }
    </style>
</head>
<body>

<?php require_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4">

    <!-- سەردێری هێرۆ -->
    <div class="mbo-hero mb-4">
        <div class="mbo-hero-head d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h4><i class="bi bi-bar-chart-line"></i> ڕاپۆرتی گشتی بزنسەکان</h4>
                <p class="mb-0 opacity-75">
                    <i class="bi bi-diagram-3"></i> <?php echo htmlspecialchars($ctx['organization']['name']); ?>
                    — <?php echo count($businesses); ?> بزنس
                    <span class="mx-1">·</span>
                    <i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($fromDate); ?> ← <?php echo htmlspecialchars($toDate); ?>
                </p>
            </div>
            <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-light">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
        </div>
    </div>

    <!-- فلتەری بەروار -->
    <form method="GET" class="card card-body border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label mb-1"><i class="bi bi-calendar-event"></i> لە بەرواری</label>
                <input type="date" name="from_date" value="<?php echo htmlspecialchars($fromDate); ?>" class="form-control">
            </div>
            <div class="col-md-5">
                <label class="form-label mb-1"><i class="bi bi-calendar-check"></i> تا بەرواری</label>
                <input type="date" name="to_date" value="<?php echo htmlspecialchars($toDate); ?>" class="form-control">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> پیشاندان</button>
            </div>
        </div>
    </form>

    <?php
        $anyActivity = false;
        foreach (['IQD', 'USD'] as $c) {
            if ($totals[$c]['revenue'] != 0 || $totals[$c]['expenses'] != 0 || $totals[$c]['profit'] != 0) { $anyActivity = true; break; }
        }
    ?>

    <?php if (!$anyActivity): ?>
        <div class="card border-0 shadow-sm text-center py-5" style="border-radius:14px;">
            <div class="card-body text-muted">
                <i class="bi bi-inbox" style="font-size:2.4rem;"></i>
                <p class="mt-2 mb-0">هیچ چالاکییەک لەم ماوەیەدا نییە</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- کۆی گشتی + وردەکاری بۆ هەر دراوێک بە جیا -->
    <?php foreach (['IQD', 'USD'] as $cur): ?>
        <?php
            $hasAny = ($totals[$cur]['revenue'] != 0 || $totals[$cur]['expenses'] != 0 || $totals[$cur]['profit'] != 0);
            if (!$hasAny) continue;
            $m = $curMeta[$cur];
            $curClass = $cur === 'USD' ? 'cur-usd' : 'cur-iqd';
        ?>
        <div class="cur-block mb-4">
            <div class="cur-block-head" style="background:<?php echo $m['grad']; ?>">
                <i class="bi <?php echo $m['icon']; ?>"></i> کۆی گشتی — <?php echo $m['label']; ?>
            </div>
            <div class="card-body bg-white p-0">
                <!-- KPI سێ خانە -->
                <div class="row g-0 border-bottom">
                    <div class="col-4 kpi">
                        <span class="kpi-ico" style="background:rgba(21,128,61,.12); color:#15803d;"><i class="bi bi-graph-up-arrow"></i></span>
                        <span class="kpi-label">داهات</span>
                        <span class="kpi-val"><?php echo mbo_fmt($totals[$cur]['revenue'], $cur); ?></span>
                    </div>
                    <div class="col-4 kpi">
                        <span class="kpi-ico" style="background:rgba(220,53,69,.12); color:#dc3545;"><i class="bi bi-graph-down-arrow"></i></span>
                        <span class="kpi-label">خەرجی</span>
                        <span class="kpi-val"><?php echo mbo_fmt($totals[$cur]['expenses'], $cur); ?></span>
                    </div>
                    <div class="col-4 kpi">
                        <span class="kpi-ico" style="background:rgba(67,56,202,.12); color:#4338ca;"><i class="bi bi-wallet2"></i></span>
                        <span class="kpi-label">قازانج</span>
                        <span class="kpi-val <?php echo $totals[$cur]['profit'] >= 0 ? 'profit-pos' : 'profit-neg'; ?>"><?php echo mbo_fmt($totals[$cur]['profit'], $cur); ?></span>
                    </div>
                </div>

                <!-- چیپی باشترین/زۆرترین -->
                <?php if ($best[$cur]['top_revenue'] || $best[$cur]['top_expense']): ?>
                <div class="px-3 py-2 border-bottom">
                    <?php if ($best[$cur]['top_revenue']): ?>
                        <span class="best-chip"><i class="bi bi-trophy text-warning"></i> باشترین داهات: <strong><?php echo htmlspecialchars($best[$cur]['top_revenue']); ?></strong></span>
                    <?php endif; ?>
                    <?php if ($best[$cur]['top_expense']): ?>
                        <span class="best-chip"><i class="bi bi-fire text-danger"></i> زۆرترین خەرجی: <strong><?php echo htmlspecialchars($best[$cur]['top_expense']); ?></strong></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- خشتەی بزنسەکان بۆ ئەم دراوە -->
                <div class="table-responsive">
                    <table class="table table-hover mbo-table">
                        <thead class="table-light">
                            <tr>
                                <th>بزنس</th>
                                <th class="text-end">داهات</th>
                                <th class="text-end">خەرجی</th>
                                <th class="text-end">قازانج</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $i => $r): ?>
                                <?php $grad = $bizGradients[$i % count($bizGradients)]; ?>
                                <tr>
                                    <td>
                                        <span class="biz-ava-sm me-1" style="background:<?php echo $grad; ?>"><?php echo htmlspecialchars($bizFirstChar($r['business']['business_name'])); ?></span>
                                        <?php echo htmlspecialchars($r['business']['business_name']); ?>
                                        <?php if ((int)$r['business']['id'] === (int)$ctx['organization']['owner_user_id']): ?>
                                            <span class="badge bg-primary">خاوەن</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?php echo mbo_fmt($r[$cur]['revenue'], $cur); ?></td>
                                    <td class="text-end"><?php echo mbo_fmt($r[$cur]['expenses'], $cur); ?></td>
                                    <td class="text-end <?php echo $r[$cur]['profit'] >= 0 ? 'profit-pos' : 'profit-neg'; ?>"><?php echo mbo_fmt($r[$cur]['profit'], $cur); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th>کۆی گشتی</th>
                                <th class="text-end"><?php echo mbo_fmt($totals[$cur]['revenue'], $cur); ?></th>
                                <th class="text-end"><?php echo mbo_fmt($totals[$cur]['expenses'], $cur); ?></th>
                                <th class="text-end <?php echo $totals[$cur]['profit'] >= 0 ? 'profit-pos' : 'profit-neg'; ?>"><?php echo mbo_fmt($totals[$cur]['profit'], $cur); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <p class="text-muted small mt-3">
        <i class="bi bi-info-circle"></i>
        دراوی دینار و دۆلار بە تەواوی جیا حیساب دەکرێن و هەرگیز تێکەڵ ناکرێن.
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
