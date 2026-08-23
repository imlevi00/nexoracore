<?php
/**
 * ڕاپۆرتەکانی کڕین - user/purchases/reports.php
 */

require_once '../../includes/functions.php';
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنەوەی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
requireCompaniesModuleAccess();
$userId = $currentUser['id'];

// فلتەرەکان
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // سەرەتای مانگ
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$company_filter = (int)($_GET['company_id'] ?? 0);

// ئاماری گشتی
$generalStats = [];

// کۆی گشتی لە ماوەی دیاریکراو
$stmt = $conn->prepare("
    SELECT
        COUNT(*) as total_receipts,
        COALESCE(SUM(CASE WHEN currency = 'IQD' THEN final_amount ELSE 0 END), 0) as total_amount_iqd,
        COALESCE(SUM(CASE WHEN currency = 'USD' THEN final_amount ELSE 0 END), 0) as total_amount_usd,
        COALESCE(AVG(CASE WHEN currency = 'IQD' THEN final_amount END), 0) as avg_amount,
        COUNT(DISTINCT company_id) as unique_companies,
        MIN(receipt_date) as first_date,
        MAX(receipt_date) as last_date
    FROM purchase_receipts
    WHERE user_id = ? AND receipt_date BETWEEN ? AND ?
    " . ($company_filter ? "AND company_id = ?" : "")
);

$params = [$userId, $date_from, $date_to];
$types = 'iss';
if ($company_filter) {
    $params[] = $company_filter;
    $types .= 'i';
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$generalStats = $stmt->get_result()->fetch_assoc();

// ئاماری کۆمپانیاکان
$stmt = $conn->prepare("
    SELECT
        c.name as company_name,
        pr.currency as currency,
        COUNT(pr.id) as receipts_count,
        COALESCE(SUM(pr.final_amount), 0) as total_amount,
        COALESCE(AVG(pr.final_amount), 0) as avg_amount,
        MAX(pr.receipt_date) as last_purchase_date
    FROM companies c
    LEFT JOIN purchase_receipts pr ON c.id = pr.company_id
        AND pr.receipt_date BETWEEN ? AND ?
    WHERE c.user_id = ? AND c.status = 'active'
    " . ($company_filter ? "AND c.id = ?" : "") . "
    GROUP BY c.id, c.name, pr.currency
    HAVING receipts_count > 0
    ORDER BY total_amount DESC
");

$params = [$date_from, $date_to, $userId];
$types = 'ssi';
if ($company_filter) {
    $params[] = $company_filter;
    $types .= 'i';
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$companyStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ئاماری ڕۆژانە
$stmt = $conn->prepare("
    SELECT
        DATE(receipt_date) as purchase_date,
        currency as currency,
        COUNT(*) as receipts_count,
        COALESCE(SUM(final_amount), 0) as total_amount
    FROM purchase_receipts
    WHERE user_id = ? AND receipt_date BETWEEN ? AND ?
    " . ($company_filter ? "AND company_id = ?" : "") . "
    GROUP BY DATE(receipt_date), currency
    ORDER BY purchase_date DESC
    LIMIT 30
");

$stmt->bind_param($types, ...$params);
$stmt->execute();
$dailyStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// زۆرترین کاڵا کڕدراوەکان
$stmt = $conn->prepare("
    SELECT
        pri.product_name,
        pr.currency as currency,
        SUM(pri.quantity) as total_quantity,
        COALESCE(SUM(pri.total_cost), 0) as total_cost,
        COALESCE(AVG(pri.buy_price), 0) as avg_buy_price,
        COUNT(DISTINCT pr.company_id) as suppliers_count
    FROM purchase_receipt_items pri
    INNER JOIN purchase_receipts pr ON pri.purchase_receipt_id = pr.id
    WHERE pr.user_id = ? AND pr.receipt_date BETWEEN ? AND ?
    " . ($company_filter ? "AND pr.company_id = ?" : "") . "
    GROUP BY pri.product_name, pr.currency
    ORDER BY total_quantity DESC
    LIMIT 20
");

$stmt->bind_param($types, ...$params);
$stmt->execute();
$topProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی لیستی کۆمپانیاکان بۆ فلتەر
$companiesStmt = $conn->prepare("SELECT * FROM companies WHERE user_id = ? AND status = 'active' ORDER BY name");
$companiesStmt->bind_param("i", $userId);
$companiesStmt->execute();
$companies = $companiesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ناوی کۆمپانیا بۆ ژێرنووس (گەڕانەوەی ئەمن)
$filter_company_display = '';
if ($company_filter > 0) {
    foreach ($companies as $_co) {
        if ((int) $_co['id'] === $company_filter) {
            $filter_company_display = (string) $_co['name'];
            break;
        }
    }
    if ($filter_company_display === '') {
        $filter_company_display = 'نەزانراو';
    }
}

$todayYmd = date('Y-m-d');
$presetThisMonthStart = date('Y-m-01');
$presetLast7From = date('Y-m-d', strtotime('-6 days'));
$presetLast30From = date('Y-m-d', strtotime('-29 days'));
$presetYearStart = date('Y-01-01');

$purchaseReportsPresetUrl = static function (string $from, string $to, int $companyId): string {
    $q = ['date_from' => $from, 'date_to' => $to];
    if ($companyId > 0) {
        $q['company_id'] = (string) $companyId;
    }
    return url('user/purchases/reports.php?' . http_build_query($q));
};

$pageTitle = 'ڕاپۆرتەکانی کڕین';
$bodyClass = 'purchases-module-page page-purchase-reports';
$additionalCSS = ['purchases/purchases-pages.css'];
include '../../includes/header.php';
?>

<?php if (false): ?>
<style>
body.page-purchase-reports {
    --pr-bg: #eef1f6;
    --pr-surface: #ffffff;
    --pr-border: #e1e6ee;
    --pr-text: #12151c;
    --pr-muted: #5f6675;
    --pr-accent: #1d63d8;
    --pr-accent-soft: #e8f0ff;
    --pr-k1: #1d63d8;
    --pr-k1-soft: #e8f0ff;
    --pr-k2: #0f6b4c;
    --pr-k2-soft: #e4f5ed;
    --pr-k3: #6b4c0f;
    --pr-k3-soft: #f5f0e4;
    --pr-k4: #5c3d9e;
    --pr-k4-soft: #efe8ff;
    --pr-stat-top: #ffffff;
    --pr-radius: 14px;
    --pr-shadow: 0 1px 2px rgba(18, 24, 33, 0.05), 0 6px 18px rgba(18, 24, 33, 0.06);
    background-color: var(--pr-bg) !important;
    min-height: 100vh;
    color: var(--pr-text);
}
body.page-purchase-reports .pr-shell {
    max-width: 1200px;
    margin-inline: auto;
}
body.page-purchase-reports .pr-surface {
    background: var(--pr-surface);
    border: 1px solid var(--pr-border);
    border-radius: var(--pr-radius);
    box-shadow: var(--pr-shadow);
}
body.page-purchase-reports .pr-hero {
    padding: 1.25rem 1.35rem;
}
body.page-purchase-reports .pr-hero-title {
    font-size: 1.2rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    margin: 0;
}
body.page-purchase-reports .pr-hero-meta {
    font-size: 0.875rem;
    color: var(--pr-muted);
    margin-top: 0.35rem;
}
body.page-purchase-reports .pr-toolbar h1 {
    font-size: 1.35rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.02em;
}
body.page-purchase-reports .pr-toolbar .text-muted {
    color: var(--pr-muted) !important;
    font-size: 0.875rem;
}
body.page-purchase-reports .pr-stat {
    border-radius: var(--pr-radius);
    border: 1px solid var(--pr-border);
    padding: 1rem 1.1rem;
    height: 100%;
    background: var(--pr-surface);
    box-shadow: var(--pr-shadow);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
body.page-purchase-reports .pr-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(18, 24, 33, 0.08);
}
body.page-purchase-reports .pr-stat-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--pr-muted);
    margin-bottom: 0.35rem;
}
body.page-purchase-reports .pr-stat-value {
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}
body.page-purchase-reports .pr-stat--k1 { border-top: 3px solid var(--pr-k1); background: linear-gradient(180deg, var(--pr-stat-top) 0%, var(--pr-k1-soft) 100%); }
body.page-purchase-reports .pr-stat--k1 .pr-stat-value { color: var(--pr-k1); }
body.page-purchase-reports .pr-stat--k2 { border-top: 3px solid var(--pr-k2); background: linear-gradient(180deg, var(--pr-stat-top) 0%, var(--pr-k2-soft) 100%); }
body.page-purchase-reports .pr-stat--k2 .pr-stat-value { color: var(--pr-k2); }
body.page-purchase-reports .pr-stat--k3 { border-top: 3px solid var(--pr-k3); background: linear-gradient(180deg, var(--pr-stat-top) 0%, var(--pr-k3-soft) 100%); }
body.page-purchase-reports .pr-stat--k3 .pr-stat-value { color: var(--pr-k3); }
body.page-purchase-reports .pr-stat--k4 { border-top: 3px solid var(--pr-k4); background: linear-gradient(180deg, var(--pr-stat-top) 0%, var(--pr-k4-soft) 100%); }
body.page-purchase-reports .pr-stat--k4 .pr-stat-value { color: var(--pr-k4); }
body.page-purchase-reports .pr-panel-card {
    border: 1px solid var(--pr-border);
    border-radius: var(--pr-radius);
    overflow: hidden;
    box-shadow: var(--pr-shadow);
    background: var(--pr-surface);
}
body.page-purchase-reports .pr-panel-card .card-header {
    background: #f8f9fc;
    border-bottom: 1px solid var(--pr-border);
    padding: 0.85rem 1.1rem;
}
body.page-purchase-reports .pr-panel-card .card-title {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
}
body.page-purchase-reports .pr-table thead th {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--pr-muted);
    border-bottom: 1px solid var(--pr-border);
    background: #f8f9fc !important;
}
body.page-purchase-reports .pr-table tbody td {
    vertical-align: middle;
    font-size: 0.9rem;
}
body.page-purchase-reports .pr-empty {
    padding: 2.25rem 1rem;
    text-align: center;
    color: var(--pr-muted);
}
body.page-purchase-reports .pr-empty i {
    font-size: 2.5rem;
    opacity: 0.45;
}
body.page-purchase-reports .pr-footnote {
    font-size: 0.75rem;
    color: var(--pr-muted);
}
body.page-purchase-reports .pr-preset-row .btn {
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.8rem;
}
html[data-bs-theme='dark'] body.page-purchase-reports {
    --pr-bg: #0b1220;
    --pr-surface: #111827;
    --pr-border: #253247;
    --pr-text: #e5e7eb;
    --pr-muted: #9ca3af;
    --pr-accent-soft: #172554;
    --pr-k1-soft: #1e293b;
    --pr-k2-soft: #0f2a21;
    --pr-k3-soft: #3f3315;
    --pr-k4-soft: #2b1f4a;
    --pr-stat-top: #111827;
    --pr-shadow: 0 2px 10px rgba(0, 0, 0, 0.35), 0 10px 30px rgba(0, 0, 0, 0.28);
}
html[data-bs-theme='dark'] body.page-purchase-reports .card,
html[data-bs-theme='dark'] body.page-purchase-reports .card-body,
html[data-bs-theme='dark'] body.page-purchase-reports .card-header {
    background-color: var(--pr-surface);
    border-color: var(--pr-border);
    color: var(--pr-text);
}
html[data-bs-theme='dark'] body.page-purchase-reports .pr-panel-card .card-header,
html[data-bs-theme='dark'] body.page-purchase-reports .pr-table thead th {
    background: #172132 !important;
}
html[data-bs-theme='dark'] body.page-purchase-reports .pr-table tbody td,
html[data-bs-theme='dark'] body.page-purchase-reports .pr-table tbody tr {
    color: var(--pr-text);
    border-color: var(--pr-border);
}
html[data-bs-theme='dark'] body.page-purchase-reports .table-hover > tbody > tr:hover > * {
    background-color: #1b2738;
    color: #f8fafc;
}
html[data-bs-theme='dark'] body.page-purchase-reports .form-control,
html[data-bs-theme='dark'] body.page-purchase-reports .form-select {
    background-color: #0f172a;
    border-color: var(--pr-border);
    color: var(--pr-text);
}
html[data-bs-theme='dark'] body.page-purchase-reports .form-control:focus,
html[data-bs-theme='dark'] body.page-purchase-reports .form-select:focus {
    background-color: #0f172a;
    color: var(--pr-text);
}
html[data-bs-theme='dark'] body.page-purchase-reports .border-top {
    border-color: var(--pr-border) !important;
}
html[data-bs-theme='dark'] body.page-purchase-reports .btn-outline-secondary {
    color: #cbd5e1;
    border-color: #4b5563;
}
html[data-bs-theme='dark'] body.page-purchase-reports .btn-outline-secondary:hover {
    background-color: #374151;
    color: #f8fafc;
}
html[data-bs-theme='dark'] body.page-purchase-reports .text-primary {
    color: #93c5fd !important;
}
@media print {
    body.page-purchase-reports {
        background: #fff !important;
        font-size: 12px;
    }
    html[data-bs-theme='dark'] body.page-purchase-reports,
    html[data-bs-theme='dark'] body.page-purchase-reports .card,
    html[data-bs-theme='dark'] body.page-purchase-reports .card-body,
    html[data-bs-theme='dark'] body.page-purchase-reports .card-header,
    html[data-bs-theme='dark'] body.page-purchase-reports .table,
    html[data-bs-theme='dark'] body.page-purchase-reports .table th,
    html[data-bs-theme='dark'] body.page-purchase-reports .table td {
        background: #ffffff !important;
        color: #000000 !important;
        border-color: #d1d5db !important;
        box-shadow: none !important;
    }
    body.page-purchase-reports .navbar,
    body.page-purchase-reports #flashMessages,
    body.page-purchase-reports .loading-overlay,
    body.page-purchase-reports .d-print-none {
        display: none !important;
    }
    body.page-purchase-reports .pr-panel-card,
    body.page-purchase-reports .pr-surface,
    body.page-purchase-reports .pr-stat {
        box-shadow: none !important;
        break-inside: avoid;
    }
    body.page-purchase-reports .pr-panel-card {
        border: 1px solid #ccc;
        margin-bottom: 1rem;
    }
    body.page-purchase-reports .table {
        border-collapse: collapse;
    }
    body.page-purchase-reports .table th,
    body.page-purchase-reports .table td {
        border: 1px solid #ddd;
        padding: 6px;
    }
    body.page-purchase-reports .badge {
        background-color: #e9ecef !important;
        color: #000 !important;
        border: 1px solid #ddd;
    }
    body.page-purchase-reports h1,
    body.page-purchase-reports h2,
    body.page-purchase-reports h3,
    body.page-purchase-reports .pr-hero-title {
        color: #000 !important;
    }
    @page { margin: 1cm; }
}
</style>
<?php endif; ?>

<div class="container-fluid py-4 hub-page-content pu-wrap">

    <header class="pu-hero">
        <div>
            <div class="pu-kicker"><i class="bi bi-graph-up-arrow"></i> بەشی کڕین</div>
            <h1><i class="bi bi-bar-chart-line"></i> ڕاپۆرتەکانی کڕین</h1>
            <p class="pu-hero-sub">ئاماری وەسڵ، کۆمپانیا، کاڵا و ڕۆژانە لە ماوەی دیاریکراودا</p>
            <div class="pu-hero-pills">
                <span class="pu-pill"><i class="bi bi-calendar3"></i>
                    <?php echo htmlspecialchars(date('Y-m-d', strtotime($date_from))); ?>
                    — <?php echo htmlspecialchars(date('Y-m-d', strtotime($date_to))); ?>
                </span>
                <?php if ($company_filter > 0): ?>
                    <span class="pu-pill is-active"><i class="bi bi-building"></i> <?php echo htmlspecialchars($filter_company_display); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="pu-actions d-print-none">
            <button type="button" onclick="window.print()" class="pu-btn pu-btn-ghost">
                <i class="bi bi-printer"></i> چاپکردن
            </button>
            <a href="<?php echo url('user/purchases/index.php'); ?>" class="pu-btn pu-btn-primary">
                <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ وەسڵەکان
            </a>
        </div>
    </header>

    <section class="pu-panel d-print-none">
        <div class="pu-panel-head">
            <span><i class="bi bi-funnel"></i> فلتەری ماوە و کۆمپانیا</span>
        </div>
        <div class="pu-panel-body">
            <form method="GET" id="pr-filter-form">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label for="date_from" class="form-label">لە بەروار</label>
                        <input type="date" class="form-control" id="date_from" name="date_from"
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label for="date_to" class="form-label">تا بەروار</label>
                        <input type="date" class="form-control" id="date_to" name="date_to"
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label for="company_id" class="form-label">کۆمپانیا</label>
                        <select class="form-select" id="company_id" name="company_id">
                            <option value="">هەموو کۆمپانیاکان</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo (int) $company['id']; ?>"
                                        <?php echo ($company_filter === (int) $company['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6 d-flex flex-wrap gap-2">
                        <button type="submit" class="pu-btn pu-btn-primary flex-grow-1">
                            <i class="bi bi-funnel"></i> فلتەرکردن
                        </button>
                        <a href="<?php echo url('user/purchases/reports.php'); ?>" class="pu-btn pu-btn-ghost flex-grow-1">
                            <i class="bi bi-arrow-clockwise"></i> ڕێکخستنەوە
                        </a>
                    </div>
                </div>
            </form>
            <div class="pu-hero-pills mt-3 pt-3 border-top">
                <span class="pu-pill"><i class="bi bi-lightning"></i> بەرواری خێرا</span>
                <a class="pu-pill" href="<?php echo htmlspecialchars($purchaseReportsPresetUrl($presetThisMonthStart, $todayYmd, $company_filter)); ?>">ئەم مانگە</a>
                <a class="pu-pill" href="<?php echo htmlspecialchars($purchaseReportsPresetUrl($presetLast7From, $todayYmd, $company_filter)); ?>">٧ ڕۆژی ڕابردوو</a>
                <a class="pu-pill" href="<?php echo htmlspecialchars($purchaseReportsPresetUrl($presetLast30From, $todayYmd, $company_filter)); ?>">٣٠ ڕۆژی ڕابردوو</a>
                <a class="pu-pill" href="<?php echo htmlspecialchars($purchaseReportsPresetUrl($presetYearStart, $todayYmd, $company_filter)); ?>">ئەم ساڵە</a>
            </div>
        </div>
    </section>

    <div class="pu-stats pu-stats-4">
        <div class="pu-stat" style="--stat-accent:#1d63d8">
            <div class="pu-stat-icon"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="pu-stat-label">کۆی وەسڵەکان</div>
                <div class="pu-stat-value"><?php echo number_format((int) ($generalStats['total_receipts'] ?? 0), 0); ?></div>
            </div>
        </div>
        <div class="pu-stat" style="--stat-accent:#0f6b4c">
            <div class="pu-stat-icon"><i class="bi bi-currency-exchange"></i></div>
            <div>
                <div class="pu-stat-label">کۆی گشتی</div>
                <div class="pu-stat-value"><?php echo htmlspecialchars(formatCurrencyAmount((float) ($generalStats['total_amount_iqd'] ?? 0), 'IQD')); ?></div>
                <?php if ((float) ($generalStats['total_amount_usd'] ?? 0) > 0): ?>
                    <div class="pu-stat-meta"><?php echo htmlspecialchars(formatCurrencyAmount((float) $generalStats['total_amount_usd'], 'USD')); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="pu-stat" style="--stat-accent:#d97706">
            <div class="pu-stat-icon"><i class="bi bi-calculator"></i></div>
            <div>
                <div class="pu-stat-label">ناوەندی وەسڵ (دینار)</div>
                <div class="pu-stat-value"><?php echo number_format((float) ($generalStats['avg_amount'] ?? 0), 0); ?></div>
            </div>
        </div>
        <div class="pu-stat" style="--stat-accent:#5c3d9e">
            <div class="pu-stat-icon"><i class="bi bi-building"></i></div>
            <div>
                <div class="pu-stat-label">کۆمپانیا جیاواز</div>
                <div class="pu-stat-value"><?php echo number_format((int) ($generalStats['unique_companies'] ?? 0), 0); ?></div>
            </div>
        </div>
    </div>

    <section class="pu-panel">
        <div class="pu-panel-head">
            <span><i class="bi bi-building"></i> ئاماری کۆمپانیاکان</span>
        </div>
        <div class="pu-panel-body-flush">
            <?php if (empty($companyStats)): ?>
                <div class="pu-empty">
                    <div class="pu-empty-icon"><i class="bi bi-building"></i></div>
                    <h3>هیچ کۆمپانیایەک لەم ماوەیەدا وەسڵی تۆمار نەکراوە</h3>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle pu-table">
                        <thead>
                            <tr>
                                <th>ناوی کۆمپانیا</th>
                                <th>دراو</th>
                                <th>ژمارەی وەسڵەکان</th>
                                <th>کۆی بڕی کڕین</th>
                                <th>ناوەندی وەسڵ</th>
                                <th>دوا کڕین</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($companyStats as $company): $rowCur = (($company['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD'; ?>
                                <tr>
                                    <td data-label="ناوی کۆمپانیا" class="fw-semibold"><?php echo htmlspecialchars($company['company_name']); ?></td>
                                    <td data-label="دراو"><span class="badge <?php echo $rowCur === 'USD' ? 'text-bg-success' : 'text-bg-light border'; ?>"><?php echo $rowCur === 'USD' ? 'دۆلار' : 'دینار'; ?></span></td>
                                    <td data-label="ژمارەی وەسڵەکان"><?php echo number_format((int) $company['receipts_count'], 0); ?></td>
                                    <td data-label="کۆی بڕی کڕین" class="fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount((float) $company['total_amount'], $rowCur)); ?></td>
                                    <td data-label="ناوەندی وەسڵ"><?php echo htmlspecialchars(formatCurrencyAmount((float) $company['avg_amount'], $rowCur)); ?></td>
                                    <td data-label="دوا کڕین">
                                        <?php echo $company['last_purchase_date'] ? htmlspecialchars(date('Y-m-d', strtotime($company['last_purchase_date']))) : '—'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="pu-panel">
        <div class="pu-panel-head">
            <span><i class="bi bi-trophy"></i> زۆرترین کاڵا کڕدراوەکان</span>
        </div>
        <div class="pu-panel-body-flush">
            <?php if (empty($topProducts)): ?>
                <div class="pu-empty">
                    <div class="pu-empty-icon"><i class="bi bi-box-seam"></i></div>
                    <h3>هیچ کاڵایەک لەم ماوەیەدا نەدۆزرایەوە</h3>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle pu-table">
                        <thead>
                            <tr>
                                <th>ناوی کاڵا</th>
                                <th>دراو</th>
                                <th>کۆی ژمارە</th>
                                <th>کۆی تێچوون</th>
                                <th>ناوەندی نرخی کڕین</th>
                                <th>ژمارەی دابینکەرەکان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProducts as $index => $product): $rowCur = (($product['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD'; ?>
                                <tr>
                                    <td data-label="ناوی کاڵا">
                                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle me-2"><?php echo (int) $index + 1; ?></span>
                                        <?php echo htmlspecialchars($product['product_name']); ?>
                                    </td>
                                    <td data-label="دراو"><span class="badge <?php echo $rowCur === 'USD' ? 'text-bg-success' : 'text-bg-light border'; ?>"><?php echo $rowCur === 'USD' ? 'دۆلار' : 'دینار'; ?></span></td>
                                    <td data-label="کۆی ژمارە" class="fw-semibold"><?php echo number_format((float) $product['total_quantity'], 0); ?></td>
                                    <td data-label="کۆی تێچوون" class="fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount((float) $product['total_cost'], $rowCur)); ?></td>
                                    <td data-label="ناوەندی نرخی کڕین"><?php echo htmlspecialchars(formatCurrencyAmount((float) $product['avg_buy_price'], $rowCur)); ?></td>
                                    <td data-label="ژمارەی دابینکەرەکان">
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><?php echo (int) $product['suppliers_count']; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="pu-panel">
        <div class="pu-panel-head">
            <span><i class="bi bi-calendar-week"></i> ئاماری ڕۆژانە <small class="fw-normal text-muted">(دوایین ٣٠ ڕۆژ لە ماوەکە)</small></span>
        </div>
        <div class="pu-panel-body-flush">
            <?php if (empty($dailyStats)): ?>
                <div class="pu-empty">
                    <div class="pu-empty-icon"><i class="bi bi-calendar-x"></i></div>
                    <h3>هیچ ڕۆژێک لەم ماوەیەدا وەسڵ تۆمار نەکراوە</h3>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle pu-table">
                        <thead>
                            <tr>
                                <th>بەروار</th>
                                <th>دراو</th>
                                <th>ژمارەی وەسڵەکان</th>
                                <th>کۆی بڕی کڕین</th>
                                <th>ناوەندی وەسڵ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyStats as $daily): $rowCur = (($daily['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD'; ?>
                                <tr>
                                    <td data-label="بەروار" class="fw-semibold"><?php echo htmlspecialchars(date('Y-m-d', strtotime($daily['purchase_date']))); ?></td>
                                    <td data-label="دراو"><span class="badge <?php echo $rowCur === 'USD' ? 'text-bg-success' : 'text-bg-light border'; ?>"><?php echo $rowCur === 'USD' ? 'دۆلار' : 'دینار'; ?></span></td>
                                    <td data-label="ژمارەی وەسڵەکان"><?php echo number_format((int) $daily['receipts_count'], 0); ?></td>
                                    <td data-label="کۆی بڕی کڕین" class="fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount((float) $daily['total_amount'], $rowCur)); ?></td>
                                    <td data-label="ناوەندی وەسڵ">
                                        <?php
                                        $rc = (int) $daily['receipts_count'];
                                        $avg = $rc > 0 ? ((float) $daily['total_amount']) / $rc : 0;
                                        echo htmlspecialchars(formatCurrencyAmount($avg, $rowCur));
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <p class="pu-footnote">
        ڕاپۆرت دروستکراوە لە: <?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?>
        · سیستەمی NexoraCore
    </p>

</div>

<?php include '../../includes/footer.php'; ?>
