<?php
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../config/kasher_logs/database.php';

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$ownerUserId = (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub' && !empty($currentUser['parent_user_id']))
    ? (int)$currentUser['parent_user_id']
    : (int)$userId;
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

if ($isSubUser && empty($userPermissions['products'])) {
    setMessage('دەسەڵاتی بینینی مێژووی کاڵات نییە', 'error');
    redirect(url('user/products/main.php'));
}

requireProductsHistoryAccess();

$eventType = trim((string)($_GET['event_type'] ?? ''));
$productSearch = trim((string)($_GET['product_search'] ?? ''));
$actorType = trim((string)($_GET['actor_type'] ?? ''));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

$logs = [];
$total = 0;
$logsError = null;

if (!$conn_kasher_logs) {
    $logsError = 'پەیوەندی بە داتابەیسی مێژوو ناکرێت.';
} else {
    $where = ['user_id = ?'];
    $types = 'i';
    $params = [$userId];

    if ($eventType !== '') {
        $where[] = 'event_type = ?';
        $types .= 's';
        $params[] = $eventType;
    }
    if ($productSearch !== '') {
        $searchTerm = '%' . $productSearch . '%';
        if (preg_match('/^\d+$/', $productSearch) === 1 && (int)$productSearch > 0) {
            $where[] = "(product_id = ? OR JSON_UNQUOTE(JSON_EXTRACT(after_state_json, '$.product.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(before_state_json, '$.product.name')) LIKE ?)";
            $types .= 'iss';
            $params[] = (int)$productSearch;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        } else {
            $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(after_state_json, '$.product.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(before_state_json, '$.product.name')) LIKE ?)";
            $types .= 'ss';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
    }
    if ($actorType === 'sub_user') {
        $where[] = 'sub_user_id IS NOT NULL';
    } elseif ($actorType === 'user') {
        $where[] = 'sub_user_id IS NULL';
    }
    if ($fromDate !== '') {
        $where[] = 'DATE(created_at) >= ?';
        $types .= 's';
        $params[] = $fromDate;
    }
    if ($toDate !== '') {
        $where[] = 'DATE(created_at) <= ?';
        $types .= 's';
        $params[] = $toDate;
    }

    $whereSql = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) AS total FROM product_change_logs WHERE $whereSql";
    $countStmt = $conn_kasher_logs->prepare($countSql);
    if ($countStmt) {
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $totalRow = $countStmt->get_result()->fetch_assoc();
        $total = (int)($totalRow['total'] ?? 0);
        $countStmt->close();
    }

    $dataSql = "SELECT * FROM product_change_logs WHERE $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $dataStmt = $conn_kasher_logs->prepare($dataSql);
    if ($dataStmt) {
        $typesWithLimit = $types . 'ii';
        $paramsWithLimit = $params;
        $paramsWithLimit[] = $limit;
        $paramsWithLimit[] = $offset;
        $dataStmt->bind_param($typesWithLimit, ...$paramsWithLimit);
        $dataStmt->execute();
        $result = $dataStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        $dataStmt->close();
    }
}

$totalPages = max(1, (int)ceil($total / $limit));
$eventTypeOptions = [
    'product.create',
    'product.update',
    'product.delete',
    'sale.create',
    'sale.update',
    'sale.delete',
    'purchase_receipt.create',
    'purchase_receipt.update',
    'purchase_receipt.delete',
    'purchase_receipt_item.create',
    'purchase_receipt_item.delete'
];

function h($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function decodeLogJson($jsonText)
{
    if (!is_string($jsonText) || trim($jsonText) === '') {
        return null;
    }
    $decoded = json_decode($jsonText, true);
    return is_array($decoded) ? $decoded : null;
}

function resolveProductNameFromStates($beforeState, $afterState)
{
    $afterProduct = isset($afterState['product']) && is_array($afterState['product']) ? $afterState['product'] : [];
    $beforeProduct = isset($beforeState['product']) && is_array($beforeState['product']) ? $beforeState['product'] : [];

    $afterName = trim((string)($afterProduct['name'] ?? ''));
    if ($afterName !== '') {
        return $afterName;
    }

    $beforeName = trim((string)($beforeProduct['name'] ?? ''));
    if ($beforeName !== '') {
        return $beforeName;
    }

    return 'نادیار';
}

function formatStateCompact($state)
{
    if (!is_array($state) || empty($state)) {
        return 'هیچ داتایەک نییە';
    }

    $segments = [];
    $product = isset($state['product']) && is_array($state['product']) ? $state['product'] : [];
    $units = isset($state['units']) && is_array($state['units']) ? $state['units'] : [];

    if (!empty($product['name'])) {
        $segments[] = 'ناو: ' . (string)$product['name'];
    }
    if (array_key_exists('barcode', $product) && $product['barcode'] !== null && $product['barcode'] !== '') {
        $segments[] = 'بارکۆد: ' . (string)$product['barcode'];
    }

    if (!empty($units)) {
        $firstUnit = $units[0];
        $unitName = trim(((string)($firstUnit['unit_name'] ?? '')) . ' ' . ((string)($firstUnit['unit_symbol'] ?? '')));
        $unitLabel = $unitName !== '' ? $unitName : 'یەکە';
        $stock = isset($firstUnit['stock_quantity']) ? (float)$firstUnit['stock_quantity'] : null;
        if ($stock !== null) {
            $segments[] = 'کۆگا: ' . rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.') . ' ' . $unitLabel;
        }
        $buyPrice = isset($firstUnit['buy_price']) ? (float)$firstUnit['buy_price'] : null;
        $sellPrice = isset($firstUnit['sell_price']) ? (float)$firstUnit['sell_price'] : null;
        if ($buyPrice !== null) {
            $segments[] = 'کڕین: ' . rtrim(rtrim(number_format($buyPrice, 3, '.', ''), '0'), '.');
        }
        if ($sellPrice !== null) {
            $segments[] = 'فرۆشتن: ' . rtrim(rtrim(number_format($sellPrice, 3, '.', ''), '0'), '.');
        }
    }

    return !empty($segments) ? implode(' | ', $segments) : 'هیچ داتایەک نییە';
}

function normalizeDiffValue($value)
{
    if ($value === null) {
        return 'بەتاڵ';
    }
    if (is_bool($value)) {
        return $value ? 'بەڵێ' : 'نەخێر';
    }
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : 'داتای ناتەواو';
    }
    $text = trim((string)$value);
    return $text === '' ? 'بەتاڵ' : $text;
}

function getChangedFieldsRows($beforeState, $afterState)
{
    $labels = [
        'name' => 'ناوی کاڵا',
        'barcode' => 'بارکۆد',
        'expiry_date' => 'بەسەرچوون',
        'category_id' => 'پۆل',
        'currency' => 'دراو',
        'buy_price' => 'نرخی کڕین',
        'sell_price' => 'نرخی فرۆشتن',
        'wholesale_price' => 'نرخی کۆ',
        'special_price' => 'نرخی تایبەت',
        'stock_quantity' => 'بڕی کۆگا',
        'min_stock' => 'کەمترین کۆگا',
        'unit_name' => 'ناوی یەکە',
        'unit_symbol' => 'نیشانەی یەکە',
        'conversion_ratio' => 'ڕێژەی گۆڕین',
        'conversion_rate' => 'نرخی گۆڕین',
        'is_primary' => 'یەکەی سەرەکی'
    ];

    $rows = [];
    $beforeProduct = isset($beforeState['product']) && is_array($beforeState['product']) ? $beforeState['product'] : [];
    $afterProduct = isset($afterState['product']) && is_array($afterState['product']) ? $afterState['product'] : [];
    $beforeUnit = isset($beforeState['units'][0]) && is_array($beforeState['units'][0]) ? $beforeState['units'][0] : [];
    $afterUnit = isset($afterState['units'][0]) && is_array($afterState['units'][0]) ? $afterState['units'][0] : [];

    foreach ($labels as $key => $label) {
        $oldRaw = array_key_exists($key, $beforeProduct) ? $beforeProduct[$key] : (array_key_exists($key, $beforeUnit) ? $beforeUnit[$key] : null);
        $newRaw = array_key_exists($key, $afterProduct) ? $afterProduct[$key] : (array_key_exists($key, $afterUnit) ? $afterUnit[$key] : null);

        $oldValue = normalizeDiffValue($oldRaw);
        $newValue = normalizeDiffValue($newRaw);
        if ($oldValue !== $newValue) {
            $rows[] = [
                'label' => $label,
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }

    if (empty($rows)) {
        $beforeText = normalizeDiffValue($beforeState);
        $afterText = normalizeDiffValue($afterState);
        if ($beforeText !== $afterText) {
            $rows[] = [
                'label' => 'گۆڕانکاری گشتی',
                'old' => $beforeText,
                'new' => $afterText
            ];
        }
    }

    return $rows;
}

function getEventTypeLabel($eventType)
{
    static $eventTypeMap = [
        'product.create' => 'دروستکردنی کاڵای نوێ',
        'product.update' => 'نوێکردنەوەی زانیاریی کاڵا',
        'product.delete' => 'سڕینەوەی کاڵا',
        'sale.create' => 'تۆمارکردنی فرۆشتنی نوێ',
        'sale.update' => 'نوێکردنەوەی زانیاریی فرۆشتن',
        'sale.delete' => 'سڕینەوەی فرۆشتن',
        'purchase_receipt.create' => 'دروستکردنی وەسڵی کڕین',
        'purchase_receipt.update' => 'نوێکردنەوەی وەسڵی کڕین',
        'purchase_receipt.delete' => 'سڕینەوەی وەسڵی کڕین',
        'purchase_receipt_item.create' => 'زیادکردنی بڕگەی وەسڵی کڕین',
        'purchase_receipt_item.delete' => 'سڕینەوەی بڕگەی وەسڵی کڕین'
    ];

    return $eventTypeMap[$eventType] ?? 'جۆری کردار دیار نەکراوە';
}

function getSourceLabel($sourceModule)
{
    static $sourceMap = [
        'user/products/add.php' => 'دروستکردنی کاڵای نوێ',
        'user/products/edit.php' => 'دەستکاری زانیاریی کاڵا',
        'user/products/delete.php' => 'سڕینەوەی کاڵا',
        'user/purchases/add.php' => 'دروستکردنی وەسڵی کڕین',
        'user/purchases/edit.php' => 'دەستکاری وەسڵی کڕین',
        'user/pos/ajax/process_sale.php' => 'تۆمارکردنی فرۆشتن لە کاونتەری POS',
        'user/api/add_receipt_item.php' => 'زیادکردنی بڕگە بۆ وەسڵی کڕین',
        'user/api/delete_receipt_item.php' => 'سڕینەوەی بڕگە لە وەسڵی کڕین',
        'user/api/delete_receipt.php' => 'سڕینەوەی وەسڵی کڕین',
        'api/sales.php' => 'بەڕێوەبردنی فرۆشتن'
    ];

    if (!is_string($sourceModule) || trim($sourceModule) === '') {
        return 'سەرچاوە دیار نەکراوە';
    }

    return $sourceMap[$sourceModule] ?? 'سەرچاوەی نەناسراو';
}

function getActorTypeLabel($actorType)
{
    if ($actorType === 'sub_user') {
        return 'کارمەند';
    }
    if ($actorType === 'user') {
        return 'خاوەن هەژمار';
    }
    return 'هەموو';
}

function resolveActorName($row, $subUserNames)
{
    $subUserId = isset($row['sub_user_id']) ? (int)$row['sub_user_id'] : 0;
    if ($subUserId > 0) {
        return $subUserNames[$subUserId] ?? 'ناوی کارمەند نەدۆزرایەوە';
    }
    return 'خاوەن هەژمار';
}

$subUserIds = [];
foreach ($logs as $logRow) {
    $subUserId = isset($logRow['sub_user_id']) ? (int)$logRow['sub_user_id'] : 0;
    if ($subUserId > 0) {
        $subUserIds[$subUserId] = $subUserId;
    }
}

$subUserNames = [];
if (!empty($subUserIds)) {
    $subUserIdList = array_values($subUserIds);
    $placeholders = implode(',', array_fill(0, count($subUserIdList), '?'));
    $typesSubUsers = str_repeat('i', count($subUserIdList));

    $subUsersSql = "SELECT id, full_name, username FROM sub_users WHERE main_user_id = ? AND id IN ($placeholders)";
    $subUsersStmt = $conn->prepare($subUsersSql);
    if ($subUsersStmt) {
        $typesBind = 'i' . $typesSubUsers;
        $paramsBind = array_merge([$ownerUserId], $subUserIdList);
        $subUsersStmt->bind_param($typesBind, ...$paramsBind);
        $subUsersStmt->execute();
        $subUsersResult = $subUsersStmt->get_result();
        while ($subUserRow = $subUsersResult->fetch_assoc()) {
            $id = (int)$subUserRow['id'];
            $fullName = trim((string)($subUserRow['full_name'] ?? ''));
            $username = trim((string)($subUserRow['username'] ?? ''));
            $subUserNames[$id] = $username !== '' ? $username : ($fullName !== '' ? $fullName : ('کارمەند #' . $id));
        }
        $subUsersStmt->close();
    }

    $unresolvedSubUserIds = array_values(array_diff($subUserIdList, array_keys($subUserNames)));
    if (!empty($unresolvedSubUserIds)) {
        $placeholdersFallback = implode(',', array_fill(0, count($unresolvedSubUserIds), '?'));
        $typesFallback = str_repeat('i', count($unresolvedSubUserIds));
        $subUsersFallbackSql = "SELECT id, full_name, username FROM sub_users WHERE id IN ($placeholdersFallback)";
        $subUsersFallbackStmt = $conn->prepare($subUsersFallbackSql);
        if ($subUsersFallbackStmt) {
            $subUsersFallbackStmt->bind_param($typesFallback, ...$unresolvedSubUserIds);
            $subUsersFallbackStmt->execute();
            $subUsersFallbackResult = $subUsersFallbackStmt->get_result();
            while ($subUserFallbackRow = $subUsersFallbackResult->fetch_assoc()) {
                $id = (int)$subUserFallbackRow['id'];
                $fullName = trim((string)($subUserFallbackRow['full_name'] ?? ''));
                $username = trim((string)($subUserFallbackRow['username'] ?? ''));
                $subUserNames[$id] = $username !== '' ? $username : ($fullName !== '' ? $fullName : 'ناوی کارمەند نەدۆزرایەوە');
            }
            $subUsersFallbackStmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>مێژووی کاڵاکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-subpages.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="products-module-page products-history-page">
<?php
$productsNavId = 'productsHistoryNav';
$productsNavLinks = [
    ['href' => url('user/products/index.php'), 'icon' => 'bi-box-seam', 'text' => 'لیستی کاڵاکان'],
    ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
];
include __DIR__ . '/partials/products_nav.php';
?>
<div class="container-fluid py-4 history-page products-page-content pp-wrap">
    <header class="pp-hero">
        <div>
            <div class="pp-kicker"><i class="bi bi-clock-history"></i> تۆمارەکان</div>
            <h1><i class="bi bi-journal-text"></i> مێژووی گۆڕانکاریی کاڵاکان</h1>
            <p class="pp-hero-sub">پوختەی گۆڕانکارییەکان ببینە و لە وردەکارییەکان داتای تەواو بخوێنەوە</p>
            <div class="pp-hero-pills">
                <span class="pp-pill"><i class="bi bi-collection"></i> <?php echo number_format((int)$total); ?> تۆمار</span>
                <span class="pp-pill"><i class="bi bi-file-earmark-text"></i> پەڕەی <?php echo (int)$page; ?></span>
            </div>
        </div>
        <div class="pp-actions">
            <a class="pp-btn pp-btn-ghost" href="<?php echo url('user/products/main.php'); ?>">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
        </div>
    </header>

    <div class="pp-panel history-filter-card mb-3">
        <div class="pp-panel-head"><i class="bi bi-funnel"></i> فلتەر</div>
        <div class="pp-filter history-form">
            <form class="row g-2" method="GET">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">جۆری کردار</label>
                    <select class="form-select" name="event_type">
                        <option value="">هەموو</option>
                        <?php foreach ($eventTypeOptions as $opt): ?>
                            <option value="<?php echo h($opt); ?>" <?php echo $eventType === $opt ? 'selected' : ''; ?>>
                                <?php echo h(getEventTypeLabel($opt)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">ژمارە یان ناوی کاڵا</label>
                    <input class="form-control" type="text" name="product_search" value="<?php echo h($productSearch); ?>" placeholder="وەک: 123 یان برنج">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label">ئەنجامدەر</label>
                    <select class="form-select" name="actor_type">
                        <option value=""><?php echo h(getActorTypeLabel('')); ?></option>
                        <option value="user" <?php echo $actorType === 'user' ? 'selected' : ''; ?>><?php echo h(getActorTypeLabel('user')); ?></option>
                        <option value="sub_user" <?php echo $actorType === 'sub_user' ? 'selected' : ''; ?>><?php echo h(getActorTypeLabel('sub_user')); ?></option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label">لە بەرواری</label>
                    <input class="form-control" type="date" name="from_date" value="<?php echo h($fromDate); ?>">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label">بۆ بەرواری</label>
                    <input class="form-control" type="date" name="to_date" value="<?php echo h($toDate); ?>">
                </div>
                <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                    <button class="pp-btn pp-btn-primary" type="submit"><i class="bi bi-search"></i> گەڕان</button>
                    <a class="pp-btn pp-btn-ghost" href="<?php echo url('user/products/history.php'); ?>">پاککردنەوە</a>
                </div>
            </form>
        </div>
    </div>

    <div class="pp-panel">
        <div class="pp-panel-body">
            <?php if ($logsError): ?>
                <div class="alert alert-danger mb-0"><?php echo h($logsError); ?></div>
            <?php elseif (empty($logs)): ?>
                <div class="pp-empty">
                    <div class="pp-empty-icon"><i class="bi bi-clock-history"></i></div>
                    <h3>هیچ تۆمارێک نەدۆزرایەوە</h3>
                    <p>فلتەرەکان بگۆڕە یان دوای گۆڕانکارییەکی نوێ بگەڕێوە.</p>
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="small text-body-secondary">کۆی تۆمارەکان: <?php echo (int)$total; ?></div>
                </div>
                <div class="accordion" id="historyAccordion">
                    <?php foreach ($logs as $row): ?>
                        <?php
                        $rowId = (int)$row['id'];
                        $beforeState = decodeLogJson($row['before_state_json'] ?? null);
                        $afterState = decodeLogJson($row['after_state_json'] ?? null);
                        $eventLabel = getEventTypeLabel((string)($row['event_type'] ?? ''));
                        $sourceLabel = getSourceLabel((string)($row['source_module'] ?? ''));
                        $actorName = resolveActorName($row, $subUserNames);
                        $changedRows = getChangedFieldsRows($beforeState, $afterState);
                        $resolvedProductName = resolveProductNameFromStates($beforeState, $afterState);
                        $collapseId = 'logDetails' . $rowId;
                        $headingId = 'logHeading' . $rowId;
                        $eventTypeKey = (string)($row['event_type'] ?? '');
                        ?>
                        <div class="log-row" data-event="<?php echo h($eventTypeKey); ?>">
                            <div class="log-summary">
                                <div class="history-event">
                                    <span class="history-dot"></span>
                                    <div>
                                        <div class="fw-semibold"><?php echo h($eventLabel); ?></div>
                                        <div class="small text-body-secondary">تۆمار #<?php echo $rowId; ?></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="small text-body-secondary">ئەنجامدەر</div>
                                    <div class="fw-semibold"><?php echo h($actorName); ?></div>
                                </div>
                                <div>
                                    <div class="small text-body-secondary">ژمارەی کاڵا</div>
                                    <div class="fw-semibold"><?php echo (int)($row['product_id'] ?? 0); ?></div>
                                </div>
                                <div>
                                    <div class="small text-body-secondary">ناوی کاڵا</div>
                                    <div class="fw-semibold"><?php echo h($resolvedProductName); ?></div>
                                </div>
                                <div>
                                    <div class="small text-body-secondary">کات</div>
                                    <div class="fw-semibold"><?php echo h((string)$row['created_at']); ?></div>
                                </div>
                                <div class="align-end-mobile text-end">
                                    <button
                                        class="pp-btn pp-btn-ghost pp-btn-sm"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?php echo h($collapseId); ?>"
                                        aria-expanded="false"
                                        aria-controls="<?php echo h($collapseId); ?>"
                                        id="<?php echo h($headingId); ?>"
                                    >
                                        <i class="bi bi-list-ul"></i> وردەکاری
                                    </button>
                                </div>
                            </div>
                            <div id="<?php echo h($collapseId); ?>" class="collapse" aria-labelledby="<?php echo h($headingId); ?>" data-bs-parent="#historyAccordion">
                                <div class="log-details">
                                    <div class="mb-3 d-flex flex-wrap gap-2">
                                        <span class="meta-chip"><i class="bi bi-diagram-2"></i> <?php echo h($sourceLabel); ?></span>
                                        <span class="meta-chip"><i class="bi bi-code-slash"></i> <?php echo h((string)($row['entity_type'] ?? 'نادیار')); ?></span>
                                        <span class="meta-chip"><i class="bi bi-hash"></i> <?php echo (int)($row['entity_id'] ?? 0); ?></span>
                                    </div>
                                    <?php if (empty($changedRows)): ?>
                                        <div class="alert alert-light border mb-0">هیچ خانەی گۆڕاو نەدۆزرایەوە.</div>
                                    <?php else: ?>
                                        <div class="details-table-wrap table-responsive">
                                            <table class="table details-table align-middle">
                                                <thead>
                                                <tr>
                                                    <th>خانە</th>
                                                    <th>پێشوو</th>
                                                    <th>نوێ</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($changedRows as $changedRow): ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo h($changedRow['label']); ?></td>
                                                        <td class="text-danger"><?php echo h($changedRow['old']); ?></td>
                                                        <td class="text-success"><?php echo h($changedRow['new']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <nav class="mt-3">
                    <ul class="pagination justify-content-center mb-0">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query([
                                    'event_type' => $eventType,
                                    'product_search' => $productSearch !== '' ? $productSearch : null,
                                    'actor_type' => $actorType,
                                    'from_date' => $fromDate,
                                    'to_date' => $toDate,
                                    'page' => $p
                                ]); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
