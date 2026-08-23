<?php
/**
 * بەدواداچوونی کاڵا لە وەسڵەکاندا - user/purchases/product_tracking.php
 *
 * بەکارهێنەر ناوی کاڵایەک و ماوەی نێوان دوو بەروار دیاری دەکات و دەبینێت
 * کە ئەم کاڵایە لە چەند وەسڵدا هاتووە، بە چ بڕێک و بە چ تێچوونێک.
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
$product_query  = trim((string)($_GET['product'] ?? ''));
$date_from      = $_GET['date_from'] ?? date('Y-m-01');
$date_to        = $_GET['date_to'] ?? date('Y-m-d');
$company_filter = (int)($_GET['company_id'] ?? 0);
$hasQuery       = ($product_query !== '');

// فۆرماتکردنی بڕ بەبێ سفرەکانی دوای خاڵ
$fmtQty = static function ($value): string {
    $value = (float)$value;
    $s = number_format($value, 3, '.', ',');
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s;
};

// دراوی ڕیز
$rowCurrencyOf = static function ($cur): string {
    return (($cur ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
};

$overallStats     = ['receipts_count' => 0, 'companies_count' => 0, 'total_quantity' => 0];
$currencyStats    = [];
$productBreakdown = [];
$detailRows       = [];

if ($hasQuery) {
    $like = '%' . $product_query . '%';

    // بەندی هاوبەشی WHERE + پارامێتەرەکان
    $whereExtra = $company_filter ? ' AND pr.company_id = ?' : '';

    // ---- کۆی گشتی (بەبێ جیاکردنەوەی دراو) ----
    $sql = "
        SELECT
            COUNT(DISTINCT pr.id) AS receipts_count,
            COUNT(DISTINCT pr.company_id) AS companies_count,
            COALESCE(SUM(pri.quantity), 0) AS total_quantity
        FROM purchase_receipt_items pri
        INNER JOIN purchase_receipts pr ON pri.purchase_receipt_id = pr.id
        WHERE pr.user_id = ? AND pr.receipt_date BETWEEN ? AND ?
          AND pri.product_name LIKE ?" . $whereExtra;
    $stmt = $conn->prepare($sql);
    $params = [$userId, $date_from, $date_to, $like];
    $types  = 'isss';
    if ($company_filter) { $params[] = $company_filter; $types .= 'i'; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $overallStats = $stmt->get_result()->fetch_assoc() ?: $overallStats;
    $stmt->close();

    // ---- کۆی تێچوون بەپێی دراو ----
    $sql = "
        SELECT
            pr.currency AS currency,
            COUNT(DISTINCT pr.id) AS receipts_count,
            COALESCE(SUM(pri.quantity), 0) AS total_quantity,
            COALESCE(SUM(pri.total_cost), 0) AS total_cost,
            COALESCE(AVG(pri.buy_price), 0) AS avg_buy_price
        FROM purchase_receipt_items pri
        INNER JOIN purchase_receipts pr ON pri.purchase_receipt_id = pr.id
        WHERE pr.user_id = ? AND pr.receipt_date BETWEEN ? AND ?
          AND pri.product_name LIKE ?" . $whereExtra . "
        GROUP BY pr.currency";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $currencyStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ---- کورتەی هەر ناوێکی کاڵا بەپێی یەکە و دراو ----
    $sql = "
        SELECT
            pri.product_name,
            pr.currency AS currency,
            u.name AS unit_name,
            u.symbol AS unit_symbol,
            COUNT(DISTINCT pr.id) AS receipts_count,
            COALESCE(SUM(pri.quantity), 0) AS total_quantity,
            COALESCE(SUM(pri.total_cost), 0) AS total_cost,
            COALESCE(AVG(pri.buy_price), 0) AS avg_buy_price
        FROM purchase_receipt_items pri
        INNER JOIN purchase_receipts pr ON pri.purchase_receipt_id = pr.id
        LEFT JOIN units u ON pri.unit_id = u.id
        WHERE pr.user_id = ? AND pr.receipt_date BETWEEN ? AND ?
          AND pri.product_name LIKE ?" . $whereExtra . "
        GROUP BY pri.product_name, pr.currency, pri.unit_id, u.name, u.symbol
        ORDER BY total_quantity DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $productBreakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ---- لیستی وردی وەسڵەکان ----
    $sql = "
        SELECT
            pr.id AS receipt_id,
            pr.receipt_number,
            pr.receipt_date,
            pr.currency AS currency,
            pr.payment_type,
            c.name AS company_name,
            pri.product_name,
            pri.quantity,
            pri.buy_price,
            pri.total_cost,
            pri.expiry_date,
            u.name AS unit_name,
            u.symbol AS unit_symbol
        FROM purchase_receipt_items pri
        INNER JOIN purchase_receipts pr ON pri.purchase_receipt_id = pr.id
        LEFT JOIN companies c ON pr.company_id = c.id
        LEFT JOIN units u ON pri.unit_id = u.id
        WHERE pr.user_id = ? AND pr.receipt_date BETWEEN ? AND ?
          AND pri.product_name LIKE ?" . $whereExtra . "
        ORDER BY pr.receipt_date DESC, pr.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $detailRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// لیستی کۆمپانیاکان بۆ فلتەر
$companiesStmt = $conn->prepare("SELECT id, name FROM companies WHERE user_id = ? AND status = 'active' ORDER BY name");
$companiesStmt->bind_param("i", $userId);
$companiesStmt->execute();
$companies = $companiesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$companiesStmt->close();

// ناوی کۆمپانیای فلتەرکراو بۆ نیشاندان
$filter_company_display = '';
if ($company_filter > 0) {
    foreach ($companies as $_co) {
        if ((int)$_co['id'] === $company_filter) { $filter_company_display = (string)$_co['name']; break; }
    }
    if ($filter_company_display === '') { $filter_company_display = 'نەزانراو'; }
}

// ناوی کاڵاکان بۆ datalist (پێشنیاری خۆکار)
$productNames = [];
$namesStmt = $conn->prepare("
    SELECT DISTINCT pri.product_name
    FROM purchase_receipt_items pri
    INNER JOIN purchase_receipts pr ON pri.purchase_receipt_id = pr.id
    WHERE pr.user_id = ? AND pri.product_name <> ''
    ORDER BY pri.product_name
    LIMIT 1000
");
$namesStmt->bind_param("i", $userId);
$namesStmt->execute();
$namesRes = $namesStmt->get_result();
while ($r = $namesRes->fetch_assoc()) { $productNames[] = $r['product_name']; }
$namesStmt->close();

$pageTitle = 'بەدواداچوونی کاڵا';
$bodyClass = 'purchases-module-page page-product-tracking';
$additionalCSS = ['purchases/purchases-pages.css'];
include '../../includes/header.php';
?>

<?php if (false): ?>
<style>
body.page-product-tracking {
    --pt-bg: #eef1f6;
    --pt-surface: #ffffff;
    --pt-border: #e1e6ee;
    --pt-text: #12151c;
    --pt-muted: #5f6675;
    --pt-accent: #1d63d8;
    --pt-k1: #1d63d8; --pt-k1-soft: #e8f0ff;
    --pt-k2: #0f6b4c; --pt-k2-soft: #e4f5ed;
    --pt-k3: #6b4c0f; --pt-k3-soft: #f5f0e4;
    --pt-k4: #5c3d9e; --pt-k4-soft: #efe8ff;
    --pt-stat-top: #ffffff;
    --pt-radius: 14px;
    --pt-shadow: 0 1px 2px rgba(18, 24, 33, 0.05), 0 6px 18px rgba(18, 24, 33, 0.06);
    background-color: var(--pt-bg) !important;
    min-height: 100vh;
    color: var(--pt-text);
}
body.page-product-tracking .pt-shell { max-width: 1200px; margin-inline: auto; }
body.page-product-tracking .pt-surface {
    background: var(--pt-surface);
    border: 1px solid var(--pt-border);
    border-radius: var(--pt-radius);
    box-shadow: var(--pt-shadow);
}
body.page-product-tracking .pt-hero { padding: 1.25rem 1.35rem; }
body.page-product-tracking .pt-hero-title { font-size: 1.2rem; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
body.page-product-tracking .pt-hero-meta { font-size: 0.875rem; color: var(--pt-muted); margin-top: 0.35rem; }
body.page-product-tracking .pt-toolbar h1 { font-size: 1.35rem; font-weight: 700; margin: 0; letter-spacing: -0.02em; }
body.page-product-tracking .pt-toolbar .text-muted { color: var(--pt-muted) !important; font-size: 0.875rem; }
body.page-product-tracking .pt-stat {
    border-radius: var(--pt-radius);
    border: 1px solid var(--pt-border);
    padding: 1rem 1.1rem;
    height: 100%;
    background: var(--pt-surface);
    box-shadow: var(--pt-shadow);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
body.page-product-tracking .pt-stat:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(18, 24, 33, 0.08); }
body.page-product-tracking .pt-stat-label { font-size: 0.8rem; font-weight: 600; color: var(--pt-muted); margin-bottom: 0.35rem; }
body.page-product-tracking .pt-stat-value { font-size: 1.35rem; font-weight: 800; letter-spacing: -0.02em; }
body.page-product-tracking .pt-stat--k1 { border-top: 3px solid var(--pt-k1); background: linear-gradient(180deg, var(--pt-stat-top) 0%, var(--pt-k1-soft) 100%); }
body.page-product-tracking .pt-stat--k1 .pt-stat-value { color: var(--pt-k1); }
body.page-product-tracking .pt-stat--k2 { border-top: 3px solid var(--pt-k2); background: linear-gradient(180deg, var(--pt-stat-top) 0%, var(--pt-k2-soft) 100%); }
body.page-product-tracking .pt-stat--k2 .pt-stat-value { color: var(--pt-k2); }
body.page-product-tracking .pt-stat--k3 { border-top: 3px solid var(--pt-k3); background: linear-gradient(180deg, var(--pt-stat-top) 0%, var(--pt-k3-soft) 100%); }
body.page-product-tracking .pt-stat--k3 .pt-stat-value { color: var(--pt-k3); }
body.page-product-tracking .pt-stat--k4 { border-top: 3px solid var(--pt-k4); background: linear-gradient(180deg, var(--pt-stat-top) 0%, var(--pt-k4-soft) 100%); }
body.page-product-tracking .pt-stat--k4 .pt-stat-value { color: var(--pt-k4); }
body.page-product-tracking .pt-panel-card {
    border: 1px solid var(--pt-border);
    border-radius: var(--pt-radius);
    overflow: hidden;
    box-shadow: var(--pt-shadow);
    background: var(--pt-surface);
}
body.page-product-tracking .pt-panel-card .card-header {
    background: #f8f9fc;
    border-bottom: 1px solid var(--pt-border);
    padding: 0.85rem 1.1rem;
}
body.page-product-tracking .pt-panel-card .card-title { font-size: 1rem; font-weight: 700; margin: 0; }
body.page-product-tracking .pt-table thead th {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--pt-muted);
    border-bottom: 1px solid var(--pt-border);
    background: #f8f9fc !important;
    white-space: nowrap;
}
body.page-product-tracking .pt-table tbody td { vertical-align: middle; font-size: 0.9rem; }
body.page-product-tracking .pt-empty { padding: 2.75rem 1rem; text-align: center; color: var(--pt-muted); }
body.page-product-tracking .pt-empty i { font-size: 2.75rem; opacity: 0.45; }
body.page-product-tracking .pt-footnote { font-size: 0.75rem; color: var(--pt-muted); }
body.page-product-tracking .pt-preset-row .btn { border-radius: 10px; font-weight: 600; font-size: 0.8rem; }
body.page-product-tracking .pt-cost-line { font-weight: 800; letter-spacing: -0.02em; }
html[data-bs-theme='dark'] body.page-product-tracking {
    --pt-bg: #0b1220;
    --pt-surface: #111827;
    --pt-border: #253247;
    --pt-text: #e5e7eb;
    --pt-muted: #9ca3af;
    --pt-k1-soft: #1e293b;
    --pt-k2-soft: #0f2a21;
    --pt-k3-soft: #3f3315;
    --pt-k4-soft: #2b1f4a;
    --pt-stat-top: #111827;
    --pt-shadow: 0 2px 10px rgba(0, 0, 0, 0.35), 0 10px 30px rgba(0, 0, 0, 0.28);
}
html[data-bs-theme='dark'] body.page-product-tracking .card,
html[data-bs-theme='dark'] body.page-product-tracking .card-body,
html[data-bs-theme='dark'] body.page-product-tracking .card-header {
    background-color: var(--pt-surface);
    border-color: var(--pt-border);
    color: var(--pt-text);
}
html[data-bs-theme='dark'] body.page-product-tracking .pt-panel-card .card-header,
html[data-bs-theme='dark'] body.page-product-tracking .pt-table thead th { background: #172132 !important; }
html[data-bs-theme='dark'] body.page-product-tracking .pt-table tbody td,
html[data-bs-theme='dark'] body.page-product-tracking .pt-table tbody tr { color: var(--pt-text); border-color: var(--pt-border); }
html[data-bs-theme='dark'] body.page-product-tracking .table-hover > tbody > tr:hover > * { background-color: #1b2738; color: #f8fafc; }
html[data-bs-theme='dark'] body.page-product-tracking .form-control,
html[data-bs-theme='dark'] body.page-product-tracking .form-select { background-color: #0f172a; border-color: var(--pt-border); color: var(--pt-text); }
html[data-bs-theme='dark'] body.page-product-tracking .form-control:focus,
html[data-bs-theme='dark'] body.page-product-tracking .form-select:focus { background-color: #0f172a; color: var(--pt-text); }
html[data-bs-theme='dark'] body.page-product-tracking .border-top { border-color: var(--pt-border) !important; }
html[data-bs-theme='dark'] body.page-product-tracking .btn-outline-secondary { color: #cbd5e1; border-color: #4b5563; }
html[data-bs-theme='dark'] body.page-product-tracking .btn-outline-secondary:hover { background-color: #374151; color: #f8fafc; }
html[data-bs-theme='dark'] body.page-product-tracking .text-primary { color: #93c5fd !important; }
@media print {
    body.page-product-tracking { background: #fff !important; font-size: 12px; }
    html[data-bs-theme='dark'] body.page-product-tracking,
    html[data-bs-theme='dark'] body.page-product-tracking .card,
    html[data-bs-theme='dark'] body.page-product-tracking .card-body,
    html[data-bs-theme='dark'] body.page-product-tracking .card-header,
    html[data-bs-theme='dark'] body.page-product-tracking .table,
    html[data-bs-theme='dark'] body.page-product-tracking .table th,
    html[data-bs-theme='dark'] body.page-product-tracking .table td {
        background: #ffffff !important; color: #000000 !important; border-color: #d1d5db !important; box-shadow: none !important;
    }
    body.page-product-tracking .navbar,
    body.page-product-tracking #flashMessages,
    body.page-product-tracking .loading-overlay,
    body.page-product-tracking .d-print-none { display: none !important; }
    body.page-product-tracking .pt-panel-card,
    body.page-product-tracking .pt-surface,
    body.page-product-tracking .pt-stat { box-shadow: none !important; break-inside: avoid; }
    body.page-product-tracking .pt-panel-card { border: 1px solid #ccc; margin-bottom: 1rem; }
    body.page-product-tracking .table { border-collapse: collapse; }
    body.page-product-tracking .table th,
    body.page-product-tracking .table td { border: 1px solid #ddd; padding: 6px; }
    body.page-product-tracking .badge { background-color: #e9ecef !important; color: #000 !important; border: 1px solid #ddd; }
    body.page-product-tracking h1, body.page-product-tracking h2, body.page-product-tracking h3,
    body.page-product-tracking .pt-hero-title { color: #000 !important; }
    @page { margin: 1cm; }
}
</style>
<?php endif; ?>

<div class="container-fluid py-4 hub-page-content pu-wrap">

    <header class="pu-hero">
        <div>
            <div class="pu-kicker"><i class="bi bi-search-heart"></i> بەشی کڕین</div>
            <h1><i class="bi bi-search"></i> بەدواداچوونی کاڵا</h1>
            <p class="pu-hero-sub">بزانە کاڵایەکی دیاریکراو لە ماوەیەکدا لە چەند وەسڵدا هاتووە و بڕی چەند بووە</p>
            <?php if ($hasQuery): ?>
                <div class="pu-hero-pills">
                    <span class="pu-pill is-active"><i class="bi bi-box-seam"></i> <?php echo htmlspecialchars($product_query); ?></span>
                    <span class="pu-pill"><i class="bi bi-calendar3"></i>
                        <?php echo htmlspecialchars(date('Y-m-d', strtotime($date_from))); ?>
                        — <?php echo htmlspecialchars(date('Y-m-d', strtotime($date_to))); ?>
                    </span>
                    <?php if ($company_filter > 0): ?>
                        <span class="pu-pill"><i class="bi bi-building"></i> <?php echo htmlspecialchars($filter_company_display); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="pu-actions d-print-none">
            <?php if ($hasQuery): ?>
                <button type="button" onclick="window.print()" class="pu-btn pu-btn-ghost">
                    <i class="bi bi-printer"></i> چاپکردن
                </button>
            <?php endif; ?>
            <a href="<?php echo url('user/companies/main.php'); ?>" class="pu-btn pu-btn-primary">
                <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ کۆمپانیاکان
            </a>
        </div>
    </header>

    <section class="pu-panel d-print-none">
        <div class="pu-panel-head">
            <span><i class="bi bi-funnel"></i> گەڕان بە ناوی کاڵا</span>
        </div>
        <div class="pu-panel-body">
            <form method="GET" id="pt-filter-form">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4 col-sm-12">
                        <label for="product" class="form-label">ناوی کاڵا <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="product" name="product" list="productNamesList"
                               value="<?php echo htmlspecialchars($product_query); ?>"
                               placeholder="بۆ نموونە: قوتوویەک شیر..." required autofocus>
                        <datalist id="productNamesList">
                            <?php foreach ($productNames as $pn): ?>
                                <option value="<?php echo htmlspecialchars($pn); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label for="date_from" class="form-label">لە بەروار</label>
                        <input type="date" class="form-control" id="date_from" name="date_from"
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label for="date_to" class="form-label">تا بەروار</label>
                        <input type="date" class="form-control" id="date_to" name="date_to"
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label for="company_id" class="form-label">کۆمپانیا</label>
                        <select class="form-select" id="company_id" name="company_id">
                            <option value="">هەموو کۆمپانیاکان</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo (int)$company['id']; ?>"
                                        <?php echo ($company_filter === (int)$company['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6 d-flex flex-wrap gap-2">
                        <button type="submit" class="pu-btn pu-btn-primary flex-grow-1">
                            <i class="bi bi-search"></i> گەڕان
                        </button>
                        <a href="<?php echo url('user/purchases/product_tracking.php'); ?>" class="pu-btn pu-btn-ghost flex-grow-1">
                            <i class="bi bi-arrow-clockwise"></i> پاککردنەوە
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <?php if (!$hasQuery): ?>
        <section class="pu-panel">
            <div class="pu-empty">
                <div class="pu-empty-icon"><i class="bi bi-box-seam"></i></div>
                <h3>ناوی کاڵایەک بنووسە بۆ دەستپێکردن</h3>
                <p>ناوی کاڵاکە و ماوەی بەروار دیاری بکە، پاشان کلیک لە «گەڕان» بکە بۆ بینینی هەموو وەسڵەکانی ئەم کاڵایە.</p>
            </div>
        </section>
    <?php else: ?>

        <div class="pu-stats pu-stats-4">
            <div class="pu-stat" style="--stat-accent:#0d9488">
                <div class="pu-stat-icon"><i class="bi bi-receipt"></i></div>
                <div>
                    <div class="pu-stat-label">ژمارەی وەسڵەکان</div>
                    <div class="pu-stat-value"><?php echo number_format((int)($overallStats['receipts_count'] ?? 0), 0); ?></div>
                </div>
            </div>
            <div class="pu-stat" style="--stat-accent:#0f6b4c">
                <div class="pu-stat-icon"><i class="bi bi-boxes"></i></div>
                <div>
                    <div class="pu-stat-label">کۆی بڕی هاوردەکراو</div>
                    <div class="pu-stat-value"><?php echo $fmtQty($overallStats['total_quantity'] ?? 0); ?></div>
                </div>
            </div>
            <div class="pu-stat" style="--stat-accent:#1d63d8">
                <div class="pu-stat-icon"><i class="bi bi-building"></i></div>
                <div>
                    <div class="pu-stat-label">کۆمپانیا جیاواز</div>
                    <div class="pu-stat-value"><?php echo number_format((int)($overallStats['companies_count'] ?? 0), 0); ?></div>
                </div>
            </div>
            <div class="pu-stat" style="--stat-accent:#d97706">
                <div class="pu-stat-icon"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="pu-stat-label">کۆی تێچوون</div>
                    <?php if (empty($currencyStats)): ?>
                        <div class="pu-stat-value">—</div>
                    <?php else: ?>
                        <?php foreach ($currencyStats as $cs): $cur = $rowCurrencyOf($cs['currency']); ?>
                            <div class="<?php echo $cur === 'USD' ? 'pu-stat-meta' : 'pu-stat-value'; ?>">
                                <?php echo htmlspecialchars(formatCurrencyAmount((float)$cs['total_cost'], $cur)); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (empty($detailRows)): ?>
            <section class="pu-panel">
                <div class="pu-empty">
                    <div class="pu-empty-icon"><i class="bi bi-search"></i></div>
                    <h3>هیچ ئەنجامێک نەدۆزرایەوە</h3>
                    <p>هیچ وەسڵێک بەم ناوە لەم ماوەیەدا نەدۆزرایەوە. ناوەکە یان ماوەی بەروار بگۆڕە و دووبارە هەوڵ بدە.</p>
                </div>
            </section>
        <?php else: ?>

            <section class="pu-panel">
                <div class="pu-panel-head">
                    <span><i class="bi bi-list-check"></i> کورتەی کاڵا <small class="fw-normal text-muted">(بەپێی یەکە و دراو)</small></span>
                </div>
                <div class="pu-panel-body-flush">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle pu-table">
                            <thead>
                                <tr>
                                    <th>ناوی کاڵا</th>
                                    <th>یەکە</th>
                                    <th>دراو</th>
                                    <th>ژمارەی وەسڵ</th>
                                    <th>کۆی بڕ</th>
                                    <th>ناوەندی نرخی کڕین</th>
                                    <th>کۆی تێچوون</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productBreakdown as $pb): $cur = $rowCurrencyOf($pb['currency']); ?>
                                    <tr>
                                        <td data-label="ناوی کاڵا" class="fw-semibold"><?php echo htmlspecialchars($pb['product_name']); ?></td>
                                        <td data-label="یەکە">
                                            <?php if (!empty($pb['unit_name'])): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($pb['unit_name']); ?></span>
                                            <?php else: ?>
                                                <small class="text-muted">—</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="دراو"><span class="badge <?php echo $cur === 'USD' ? 'text-bg-success' : 'text-bg-light border'; ?>"><?php echo $cur === 'USD' ? 'دۆلار' : 'دینار'; ?></span></td>
                                        <td data-label="ژمارەی وەسڵ"><?php echo number_format((int)$pb['receipts_count'], 0); ?></td>
                                        <td data-label="کۆی بڕ" class="fw-semibold"><?php echo $fmtQty($pb['total_quantity']); ?></td>
                                        <td data-label="ناوەندی نرخی کڕین"><?php echo htmlspecialchars(formatCurrencyAmount((float)$pb['avg_buy_price'], $cur)); ?></td>
                                        <td data-label="کۆی تێچوون" class="fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount((float)$pb['total_cost'], $cur)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="pu-panel">
                <div class="pu-panel-head">
                    <span><i class="bi bi-receipt"></i> وەسڵەکان بەوردی</span>
                    <span class="pu-pill is-active"><?php echo number_format(count($detailRows), 0); ?> ڕیز</span>
                </div>
                <div class="pu-panel-body-flush">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle pu-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>بەروار</th>
                                    <th>کۆمپانیا</th>
                                    <th>ژمارەی وەسڵ</th>
                                    <th>ناوی کاڵا</th>
                                    <th>بڕ</th>
                                    <th>یەکە</th>
                                    <th>نرخی کڕین</th>
                                    <th>کۆی تێچوون</th>
                                    <th>بەسەرچوون</th>
                                    <th class="d-print-none">کردار</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detailRows as $i => $row): $cur = $rowCurrencyOf($row['currency']); ?>
                                    <tr>
                                        <td data-label="#"><?php echo $i + 1; ?></td>
                                        <td data-label="بەروار" class="fw-semibold"><?php echo htmlspecialchars(date('Y-m-d', strtotime($row['receipt_date']))); ?></td>
                                        <td data-label="کۆمپانیا"><?php echo htmlspecialchars($row['company_name'] ?? '—'); ?></td>
                                        <td data-label="ژمارەی وەسڵ">
                                            <?php if (!empty($row['receipt_number'])): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($row['receipt_number']); ?></span>
                                            <?php else: ?>
                                                <small class="text-muted">—</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="ناوی کاڵا"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                        <td data-label="بڕ" class="fw-semibold"><?php echo $fmtQty($row['quantity']); ?></td>
                                        <td data-label="یەکە">
                                            <?php if (!empty($row['unit_name'])): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($row['unit_name']); ?></span>
                                            <?php else: ?>
                                                <small class="text-muted">—</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="نرخی کڕین"><?php echo htmlspecialchars(formatCurrencyAmount((float)$row['buy_price'], $cur)); ?></td>
                                        <td data-label="کۆی تێچوون" class="fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount((float)$row['total_cost'], $cur)); ?></td>
                                        <td data-label="بەسەرچوون"><small><?php echo !empty($row['expiry_date']) ? htmlspecialchars(date('Y-m-d', strtotime($row['expiry_date']))) : '—'; ?></small></td>
                                        <td data-label="کردار" class="d-print-none">
                                            <a href="<?php echo url('user/purchases/view.php?id=' . (int)$row['receipt_id']); ?>" target="_blank" class="btn btn-sm btn-outline-info" title="بینینی وەسڵ">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

        <?php endif; ?>
    <?php endif; ?>

    <p class="pu-footnote">
        ڕاپۆرت دروستکراوە لە: <?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?>
        · سیستەمی NexoraCore
    </p>

</div>

<?php include '../../includes/footer.php'; ?>
