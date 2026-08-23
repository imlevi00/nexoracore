<?php
/**
 * ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان - user/reports/item_section_profit_report.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/zanyari_user_settings.php';
require_once '../../includes/profit_schema.php';
require_once '../../includes/item_profit_report_stats.php';
require_once '../../includes/reports_cache.php';
require_once '../products/includes/custom_fields_helpers.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'profits.view', [
    'route' => '/user/reports/item_section_profit_report.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
], 'redirect');

$userId = (int)$currentUser['id'];
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

if ($isSubUser && (!isset($userPermissions['profits']) || !$userPermissions['profits'])) {
    setMessage('دەسەڵاتی بینینی ئەم ڕاپۆرتەت نییە', 'danger');
    redirect(url('user/reports/main.php'));
}

requireItemSectionProfitReportAccess();

$today = date('Y-m-d');
$fromDateInput = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : $today;
$toDateInput = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : $today;

$fromDateObj = DateTime::createFromFormat('Y-m-d', $fromDateInput) ?: new DateTime($today);
$toDateObj = DateTime::createFromFormat('Y-m-d', $toDateInput) ?: new DateTime($today);
if ($fromDateObj > $toDateObj) {
    $tmp = $fromDateObj;
    $fromDateObj = $toDateObj;
    $toDateObj = $tmp;
}
$fromDate = $fromDateObj->format('Y-m-d');
$toDate = $toDateObj->format('Y-m-d');

$subUsers = [];
$selectedSubUserId = 0;
$effectiveSubUserId = null;
$selectedSubUserName = '';

if ($isSubUser) {
    if (isset($currentUser['sub_user_id']) && $currentUser['sub_user_id']) {
        $effectiveSubUserId = (int)$currentUser['sub_user_id'];
        $selectedSubUserName = $currentUser['full_name'] ?? ($currentUser['username'] ?? '');
    }
} else {
    $stmt = $conn->prepare("SELECT id, username, full_name FROM sub_users WHERE main_user_id = ? ORDER BY full_name ASC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $subUsers[] = $row;
    }
    $stmt->close();

    if (isset($_GET['sub_user_id']) && $_GET['sub_user_id'] !== '') {
        $selectedSubUserId = (int)$_GET['sub_user_id'];
    }
    if ($selectedSubUserId > 0) {
        $check = $conn->prepare('SELECT id, full_name, username FROM sub_users WHERE id = ? AND main_user_id = ?');
        $check->bind_param('ii', $selectedSubUserId, $userId);
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

$searchQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'sections' ? 'sections' : 'products';
$sortBy = isset($_GET['sort']) ? (string)$_GET['sort'] : 'qty';
$sortDir = isset($_GET['dir']) ? (string)$_GET['dir'] : 'desc';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;

$filterFieldId = isset($_GET['field_id']) ? (int)$_GET['field_id'] : 0;
$filterOptionId = isset($_GET['option_id']) ? (int)$_GET['option_id'] : 0;
$filterFieldName = '';
$filterOptionLabel = '';
$productIdsFilter = null;

if ($filterFieldId > 0 && $filterOptionId > 0 && productCustomFieldsFeatureAvailable($conn)) {
    $field = getProductCustomFieldById($conn, $userId, $filterFieldId, true);
    $option = getProductCustomFieldOptionById($conn, $userId, $filterOptionId, true);
    if ($field && $option && (int)$option['field_id'] === $filterFieldId && ($field['field_type'] ?? '') === 'select') {
        $filterFieldName = (string)$field['field_name'];
        $filterOptionLabel = resolveCustomFieldOptionPath($conn, $userId, $filterOptionId);
        if ($filterOptionLabel === '') {
            $filterOptionLabel = (string)($option['option_label'] ?? '');
        }
        $descendantIds = getCustomFieldOptionDescendantIds($conn, $userId, $filterOptionId, true);
        $productIdsFilter = getItemProfitReportProductIdsForFieldOption($conn, $userId, $filterFieldId, $descendantIds);
        if (empty($productIdsFilter)) {
            $productIdsFilter = [-1];
        }
    } else {
        $filterFieldId = 0;
        $filterOptionId = 0;
    }
}

$recognizeDebtRevenueAtSale = getRecognizeCustomerDebtRevenueAtSale($userId);

// کاشی واژوو‌بنەما: واژووی داتاکان لە کلیلەکەدایە، بۆیە کاشەکە دوای هەر
// فرۆشتن/گەڕانەوە/خەرجی/پارەدانی قەرزێکی نوێ خۆکارانە نوێ دەبێتەوە.
$dataSignature = getReportsDataSignature($conn, (int)$userId, $effectiveSubUserId);
$cacheKey = sha1(implode('|', [
    'item_profit_report_v3_multicurrency',
    (string)$userId,
    $fromDate,
    $toDate,
    (string)($effectiveSubUserId ?? 0),
    $searchQ,
    (string)$filterFieldId,
    (string)$filterOptionId,
    $sortBy,
    $sortDir,
    (string)$page,
    $activeTab,
    $dataSignature,
]));

// TTL درێژ (تۆڕی پاراستن)؛ ملمانێی ڕاستەقینە لەلایەن واژووەکەوە دەکرێت
$cached = loadItemProfitReportCached($cacheKey, 3600);
if (is_array($cached) && isset($cached['productReport'], $cached['optionRows'])) {
    $productReport = $cached['productReport'];
    $optionRows = $cached['optionRows'];
} else {
    $productReport = fetchItemProfitByProduct(
        $conn,
        $userId,
        $fromDate,
        $toDate,
        $effectiveSubUserId,
        $searchQ,
        $productIdsFilter,
        $sortBy,
        $sortDir,
        $page,
        $perPage
    );
    $optionRows = fetchItemProfitByCustomFieldOptions(
        $conn,
        $userId,
        $fromDate,
        $toDate,
        $effectiveSubUserId,
        $searchQ,
        $filterFieldId > 0 ? $filterFieldId : 0
    );
    saveItemProfitReportCached($cacheKey, [
        'productReport' => $productReport,
        'optionRows' => $optionRows,
    ]);

    cleanupStaleReportsCache(getReportsCacheDir() . DIRECTORY_SEPARATOR . $cacheKey . '.json');
}

$productRows = $productReport['rows'] ?? [];
$productTotalCount = (int)($productReport['total_count'] ?? 0);
$totals = $productReport['totals'] ?? fetchItemProfitReportTotals($conn, $userId, $fromDate, $toDate, $effectiveSubUserId, $searchQ, $productIdsFilter);

$totalPages = max(1, (int)ceil($productTotalCount / $perPage));

// جیاکردنەوەی کۆکراوەکان بەپێی دراو
$totalsIqd = $totals['IQD'] ?? ['revenue' => (float)($totals['revenue'] ?? 0), 'cogs' => (float)($totals['cogs'] ?? 0), 'profit' => (float)($totals['profit'] ?? 0)];
$totalsUsd = $totals['USD'] ?? ['revenue' => 0.0, 'cogs' => 0.0, 'profit' => 0.0];
$hasUsdActivity = ((float)($totalsUsd['revenue'] ?? 0) != 0.0 || (float)($totalsUsd['profit'] ?? 0) != 0.0);

// یارمەتیدەری فۆرماتکردنی نرخ بەپێی دراو (دینار = بێ خاڵ، دۆلار = $)
$fmtMoneyByCurrency = function ($val, $cur) {
    return ($cur === 'USD')
        ? formatCurrencyAmount((float)$val, 'USD')
        : number_format((float)$val);
};

$customFieldsAvailable = productCustomFieldsFeatureAvailable($conn);
$selectFieldsGrouped = ['sections' => [], 'ungrouped' => []];
$optionTreesByField = [];

if ($customFieldsAvailable && productCustomFieldOptionsAvailable($conn)) {
    $allFields = getProductCustomFields($conn, $userId, true);
    $selectFields = array_values(array_filter($allFields, function ($f) {
        return ($f['field_type'] ?? '') === 'select';
    }));
    $selectFieldsGrouped = groupProductCustomFieldsBySection($selectFields);
    foreach ($selectFields as $sf) {
        $fid = (int)$sf['id'];
        $optionTreesByField[$fid] = getProductCustomFieldOptionsTree($conn, $userId, $fid, true);
    }
}

$GLOBALS['ispr_is_sub_user'] = $isSubUser;

/**
 * @param array<string, mixed> $overrides
 */
function isprBuildQueryUrl(array $overrides = [])
{
    $params = [
        'from_date' => $_GET['from_date'] ?? date('Y-m-d'),
        'to_date' => $_GET['to_date'] ?? date('Y-m-d'),
        'q' => $_GET['q'] ?? '',
        'tab' => $_GET['tab'] ?? 'products',
        'sort' => $_GET['sort'] ?? 'qty',
        'dir' => $_GET['dir'] ?? 'desc',
        'page' => $_GET['page'] ?? 1,
    ];
    if (empty($GLOBALS['ispr_is_sub_user']) && isset($_GET['sub_user_id']) && (int)$_GET['sub_user_id'] > 0) {
        $params['sub_user_id'] = $_GET['sub_user_id'];
    }
    if (!empty($_GET['field_id'])) {
        $params['field_id'] = $_GET['field_id'];
    }
    if (!empty($_GET['option_id'])) {
        $params['option_id'] = $_GET['option_id'];
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return url('user/reports/item_section_profit_report.php?' . http_build_query($params));
}

function isprSortLink($column, $label, $currentSort, $currentDir)
{
    $newDir = ($currentSort === $column && $currentDir === 'desc') ? 'asc' : 'desc';
    $href = isprBuildQueryUrl(['sort' => $column, 'dir' => $newDir, 'page' => 1, 'tab' => 'products']);
    $active = $currentSort === $column ? ' active' : '';
    $icon = '';
    if ($currentSort === $column) {
        $icon = $currentDir === 'asc' ? ' <i class="bi bi-sort-up"></i>' : ' <i class="bi bi-sort-down"></i>';
    }
    return '<a class="ispr-sort-link' . $active . '" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . $icon . '</a>';
}

function isprRenderOptionButtons($tree, $fieldId, $filterFieldId, $filterOptionId, $depth = 0)
{
    $html = '';
    foreach ($tree as $node) {
        $optId = (int)$node['id'];
        $label = htmlspecialchars((string)$node['label']);
        $isActive = ($filterFieldId === $fieldId && $filterOptionId === $optId);
        $btnClass = 'btn btn-sm ispr-option-btn ' . ($isActive ? 'btn-primary active' : 'btn-outline-secondary');
        $href = isprBuildQueryUrl([
            'field_id' => $fieldId,
            'option_id' => $optId,
            'page' => 1,
            'tab' => 'products',
        ]);
        $margin = $depth > 0 ? ' style="margin-inline-start:' . (int)($depth * 12) . 'px"' : '';
        $html .= '<a href="' . htmlspecialchars($href) . '" class="' . $btnClass . '"' . $margin . '>' . $label . '</a>';
        if (!empty($node['children'])) {
            $html .= isprRenderOptionButtons($node['children'], $fieldId, $filterFieldId, $filterOptionId, $depth + 1);
        }
    }
    return $html;
}

$fromLabel = date('Y/m/d', strtotime($fromDate));
$toLabel = date('Y/m/d', strtotime($toDate));
$rangeLabel = $fromDate === $toDate
    ? "لە بەرواری {$fromLabel}"
    : "لە بەرواری {$fromLabel} تا بەرواری {$toLabel}";
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo url('user/reports/assets/item_section_profit_report.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/reports/reports-pages.css'); ?>" rel="stylesheet">
</head>
<body class="reports-module-page reports-item-page">

    <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content rp-wrap">

    <?php if (!$recognizeDebtRevenueAtSale): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div>
            ئەم ڕاپۆرتە فرۆش و قازانج بە <strong>بەرواری فرۆشتن</strong> حیساب دەکات.
            لە ڕێکخستنی قەرزدا داهات بە ڕۆژی وەرگرتنی پارە دەناسرێتەوە؛ کۆی ئەم پەڕەیە لەوانەیە لە «ڕاپۆرت + قازانج» جیاواز بێت.
        </div>
    </div>
    <?php endif; ?>

    <header class="rp-hero">
        <div>
            <div class="rp-kicker"><i class="bi bi-box-seam"></i> ڕاپۆرتی کاڵا</div>
            <h1><i class="bi bi-pie-chart"></i> ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان</h1>
            <p class="rp-hero-sub"><?php echo htmlspecialchars($rangeLabel); ?></p>
            <div class="rp-hero-pills">
                <?php if ($effectiveSubUserId !== null && $selectedSubUserName !== ''): ?>
                    <span class="rp-pill"><i class="bi bi-person"></i> <?php echo htmlspecialchars($selectedSubUserName); ?></span>
                <?php endif; ?>
                <?php if ($filterFieldId > 0 && $filterOptionLabel !== ''): ?>
                    <span class="rp-pill"><?php echo htmlspecialchars($filterFieldName); ?>: <?php echo htmlspecialchars($filterOptionLabel); ?></span>
                    <a href="<?php echo htmlspecialchars(isprBuildQueryUrl(['field_id' => null, 'option_id' => null, 'page' => 1])); ?>" class="rp-pill">سڕینەوەی فلتەر</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="rp-hero-profit">
            <a href="<?php echo url('user/reports/main.php'); ?>" class="rp-btn rp-btn-ghost mb-2">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
            <p class="rp-profit-iqd <?php echo ($totalsIqd['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                <?php echo number_format((float)($totalsIqd['profit'] ?? 0)); ?> دینار
            </p>
            <?php if ($hasUsdActivity): ?>
            <div class="rp-profit-usd ispr-header-usd <?php echo ($totalsUsd['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                <?php echo htmlspecialchars(formatCurrencyAmount((float)($totalsUsd['profit'] ?? 0), 'USD')); ?>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="rp-stats rp-stats-4">
        <div class="ispr-stat-card">
            <div class="ispr-stat-number"><?php echo number_format((float)($totalsIqd['revenue'] ?? 0)); ?></div>
            <div class="rp-stat-label">کۆی داهات (دینار)</div>
            <?php if ($hasUsdActivity): ?>
            <div class="ispr-stat-usd"><?php echo htmlspecialchars(formatCurrencyAmount((float)($totalsUsd['revenue'] ?? 0), 'USD')); ?></div>
            <?php endif; ?>
        </div>
        <div class="ispr-stat-card">
            <div class="ispr-stat-number <?php echo ($totalsIqd['profit'] ?? 0) >= 0 ? 'ispr-profit-positive' : 'ispr-profit-negative'; ?>">
                <?php echo number_format((float)($totalsIqd['profit'] ?? 0)); ?>
            </div>
            <div class="rp-stat-label">کۆی قازانج (دینار)</div>
            <?php if ($hasUsdActivity): ?>
            <div class="ispr-stat-usd <?php echo ($totalsUsd['profit'] ?? 0) >= 0 ? '' : 'ispr-profit-negative'; ?>"><?php echo htmlspecialchars(formatCurrencyAmount((float)($totalsUsd['profit'] ?? 0), 'USD')); ?></div>
            <?php endif; ?>
        </div>
        <div class="ispr-stat-card">
            <div class="ispr-stat-number"><?php echo number_format((float)($totals['qty_sold'] ?? 0), 3); ?></div>
            <div class="rp-stat-label">کۆی بڕی فرۆشراو</div>
        </div>
        <div class="ispr-stat-card">
            <div class="ispr-stat-number"><?php echo (int)($totals['product_count'] ?? 0); ?></div>
            <div class="rp-stat-label">ژمارەی کاڵا</div>
        </div>
    </div>

    <div class="ispr-filter-section">
        <form method="GET" class="row g-3">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <?php if ($filterFieldId > 0): ?>
                <input type="hidden" name="field_id" value="<?php echo (int)$filterFieldId; ?>">
            <?php endif; ?>
            <?php if ($filterOptionId > 0): ?>
                <input type="hidden" name="option_id" value="<?php echo (int)$filterOptionId; ?>">
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label" for="from_date">لە بەرواری</label>
                <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="to_date">تا بەرواری</label>
                <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo htmlspecialchars($toDate); ?>">
            </div>
            <?php if (!$isSubUser): ?>
            <div class="col-md-3">
                <label class="form-label" for="sub_user_id">کارمەند</label>
                <select class="form-select" id="sub_user_id" name="sub_user_id">
                    <option value="0"<?php echo $selectedSubUserId === 0 ? ' selected' : ''; ?>>هەموو / سەرەکی</option>
                    <?php foreach ($subUsers as $su): ?>
                        <option value="<?php echo (int)$su['id']; ?>"<?php echo $selectedSubUserId === (int)$su['id'] ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars(($su['full_name'] ?: $su['username'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label" for="q">گەڕان بە ناوی کاڵا</label>
                <input type="search" class="form-control" id="q" name="q" value="<?php echo htmlspecialchars($searchQ); ?>" placeholder="ناوی کاڵا...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> پیشاندان</button>
            </div>
        </form>
    </div>

    <?php if ($customFieldsAvailable && !empty($optionTreesByField)): ?>
    <div class="ispr-filter-section mb-4">
        <h6 class="mb-3"><i class="bi bi-ui-checks-grid"></i> فلتەر بەپێی خانە / لق</h6>
        <?php foreach ($selectFieldsGrouped['sections'] as $section): ?>
            <?php if (empty($section['fields'])) continue; ?>
            <div class="mb-2">
                <div class="ispr-section-label"><i class="bi bi-folder"></i> <?php echo htmlspecialchars($section['section_name']); ?></div>
                <?php foreach ($section['fields'] as $field): ?>
                    <?php $fid = (int)$field['id']; if (empty($optionTreesByField[$fid])) continue; ?>
                    <div class="ispr-field-group">
                        <strong class="small"><?php echo htmlspecialchars($field['field_name']); ?></strong>
                        <div class="mt-1 flex-wrap d-flex">
                            <?php echo isprRenderOptionButtons($optionTreesByField[$fid], $fid, $filterFieldId, $filterOptionId); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($selectFieldsGrouped['ungrouped'] as $field): ?>
            <?php $fid = (int)$field['id']; if (empty($optionTreesByField[$fid])) continue; ?>
            <div class="ispr-field-group">
                <strong class="small"><?php echo htmlspecialchars($field['field_name']); ?></strong>
                <div class="mt-1 flex-wrap d-flex">
                    <?php echo isprRenderOptionButtons($optionTreesByField[$fid], $fid, $filterFieldId, $filterOptionId); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php elseif (!$customFieldsAvailable): ?>
    <div class="alert alert-info">
        خانە زیادەکان چالاک نین.
        <a href="<?php echo url('user/products/custom_fields.php'); ?>">ڕێکخستنی دووگمە زیادەکان</a>
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'products' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(isprBuildQueryUrl(['tab' => 'products'])); ?>">کاڵاکان</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'sections' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(isprBuildQueryUrl(['tab' => 'sections', 'page' => 1])); ?>">بەپێی خانە / لق</a>
        </li>
    </ul>

    <?php if ($activeTab === 'sections'): ?>
    <div class="ispr-table-wrap">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 item-profit-table">
                <thead class="table-light">
                    <tr>
                        <th>خانە</th>
                        <th>لق / بژاردە</th>
                        <th class="text-end">بڕی فرۆشراو</th>
                        <th class="text-end">داهات</th>
                        <th class="text-end">قازانج</th>
                        <th class="text-end">ڕێژەی فرۆشراو</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($optionRows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">هیچ داتایەک لەم ماوەیەدا نییە</td></tr>
                    <?php else: ?>
                    <?php foreach ($optionRows as $row): ?>
                    <tr>
                        <td data-label="خانە"><?php echo htmlspecialchars($row['field_name']); ?></td>
                        <td data-label="لق / بژاردە">
                            <a href="<?php echo htmlspecialchars(isprBuildQueryUrl([
                                'field_id' => (int)$row['field_id'],
                                'option_id' => (int)$row['option_id'],
                                'tab' => 'products',
                                'page' => 1,
                            ])); ?>">
                                <?php echo htmlspecialchars($row['option_label']); ?>
                            </a>
                        </td>
                        <td data-label="بڕی فرۆشراو" class="text-end"><?php echo number_format((float)$row['qty_sold'], 3); ?></td>
                        <td data-label="داهات" class="text-end">
                            <?php echo number_format((float)$row['revenue']); ?>
                            <?php if ((float)($row['revenue_usd'] ?? 0) != 0.0): ?>
                                <span class="ispr-usd-line"><?php echo htmlspecialchars(formatCurrencyAmount((float)$row['revenue_usd'], 'USD')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="قازانج" class="text-end <?php echo ($row['profit'] ?? 0) >= 0 ? 'ispr-profit-positive' : 'ispr-profit-negative'; ?>">
                            <?php echo number_format((float)$row['profit']); ?>
                            <?php if ((float)($row['profit_usd'] ?? 0) != 0.0): ?>
                                <span class="ispr-usd-line"><?php echo htmlspecialchars(formatCurrencyAmount((float)$row['profit_usd'], 'USD')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="ڕێژەی فرۆشراو" class="text-end"><?php echo number_format((float)($row['sold_rate_pct'] ?? 0), 2); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>

    <div class="ispr-table-wrap">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 item-profit-table">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th><?php echo isprSortLink('name', 'ناوی کاڵا', $sortBy, $sortDir); ?></th>
                        <th class="text-end"><?php echo isprSortLink('qty', 'بڕی فرۆشراو', $sortBy, $sortDir); ?></th>
                        <th class="text-end"><?php echo isprSortLink('revenue', 'داهات', $sortBy, $sortDir); ?></th>
                        <th class="text-end"><?php echo isprSortLink('profit', 'قازانج', $sortBy, $sortDir); ?></th>
                        <th class="text-end"><?php echo isprSortLink('sold_rate', 'ڕێژەی فرۆشراو', $sortBy, $sortDir); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productRows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">هیچ کاڵایەک لەم فلتەرەدا نەدۆزرایەوە</td></tr>
                    <?php else: ?>
                    <?php
                    $rowNum = ($page - 1) * $perPage;
                    foreach ($productRows as $row):
                        $rowNum++;
                    ?>
                    <?php $rowCur = ($row['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD'; ?>
                    <tr>
                        <td data-label="#"><?php echo $rowNum; ?></td>
                        <td data-label="ناوی کاڵا">
                            <?php echo htmlspecialchars($row['product_name']); ?>
                            <span class="ispr-cur-badge <?php echo $rowCur === 'USD' ? 'usd' : 'iqd'; ?>"><?php echo $rowCur === 'USD' ? 'دۆلار' : 'دینار'; ?></span>
                        </td>
                        <td data-label="بڕی فرۆشراو" class="text-end"><?php echo number_format((float)$row['qty_sold'], 3); ?></td>
                        <td data-label="داهات" class="text-end ispr-cell-usd"><?php echo htmlspecialchars($fmtMoneyByCurrency((float)$row['revenue'], $rowCur)); ?></td>
                        <td data-label="قازانج" class="text-end ispr-cell-usd <?php echo ($row['profit'] ?? 0) >= 0 ? 'ispr-profit-positive' : 'ispr-profit-negative'; ?>">
                            <?php echo htmlspecialchars($fmtMoneyByCurrency((float)$row['profit'], $rowCur)); ?>
                        </td>
                        <td data-label="ڕێژەی فرۆشراو" class="text-end"><?php echo number_format((float)($row['sold_rate_pct'] ?? 0), 2); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="پەڕەکردن">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo htmlspecialchars(isprBuildQueryUrl(['page' => $page - 1, 'tab' => 'products'])); ?>">پێشوو</a>
                </li>
                <?php endif; ?>
                <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo htmlspecialchars(isprBuildQueryUrl(['page' => $page + 1, 'tab' => 'products'])); ?>">دواتر</a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
