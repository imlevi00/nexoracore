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

if ($isSubUser && empty($userPermissions['customers'])) {
    setMessage('دەسەڵاتی بینینی مێژووی کڕیارانت نییە', 'error');
    redirect(url('user/customers/main.php'));
}

requireCustomersHistoryAccess();

$eventType = trim((string)($_GET['event_type'] ?? ''));
$customerSearch = trim((string)($_GET['customer_search'] ?? ''));
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

    if ($customerSearch !== '') {
        $searchTerm = '%' . $customerSearch . '%';
        if (preg_match('/^\d+$/', $customerSearch) === 1 && (int)$customerSearch > 0) {
            $where[] = "(customer_id = ? OR JSON_UNQUOTE(JSON_EXTRACT(after_state_json, '$.customer_snapshot.customer.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(before_state_json, '$.customer_snapshot.customer.name')) LIKE ?)";
            $types .= 'iss';
            $params[] = (int)$customerSearch;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        } else {
            $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(after_state_json, '$.customer_snapshot.customer.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(before_state_json, '$.customer_snapshot.customer.name')) LIKE ?)";
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

    $countSql = "SELECT COUNT(*) AS total FROM customer_change_logs WHERE $whereSql";
    $countStmt = $conn_kasher_logs->prepare($countSql);
    if ($countStmt) {
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $totalRow = $countStmt->get_result()->fetch_assoc();
        $total = (int)($totalRow['total'] ?? 0);
        $countStmt->close();
    }

    $dataSql = "SELECT * FROM customer_change_logs WHERE $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
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
    'sale.create',
    'customer.create',
    'customer.update',
    'customer.delete',
    'customer_debt.increase',
    'customer_debt.payment',
    'customer_debt.payment_delete',
    'customer_debt.transaction_delete',
    'sale.update',
    'sale.delete'
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

function formatDiffNumber($value, $decimals = 3)
{
    if (!is_numeric($value)) {
        return trim((string)$value);
    }
    $formatted = number_format((float)$value, $decimals, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

function formatSaleItemsForDiff($items)
{
    if (!is_array($items) || empty($items)) {
        return null;
    }

    $parts = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $productName = trim((string)($item['product_name'] ?? ''));
        if ($productName === '') {
            $productName = 'کاڵای نادیار';
        }

        $quantity = formatDiffNumber($item['quantity'] ?? 0, 3);
        $unitName = trim((string)($item['unit_name'] ?? ''));
        if ($unitName === '') {
            $unitName = 'دانە';
        }
        $unitPrice = formatDiffNumber($item['unit_price'] ?? 0, 3);
        $totalPrice = formatDiffNumber($item['total_price'] ?? 0, 3);
        $currency = strtoupper(trim((string)($item['currency'] ?? 'IQD')));
        $currencyLabel = $currency === 'USD' ? 'دۆلار' : 'دینار';

        $parts[] = ($index + 1) . ') '
            . $productName
            . ' (' . $quantity . ' ' . $unitName
            . ' × ' . $unitPrice
            . ' = ' . $totalPrice . ' ' . $currencyLabel . ')';
    }

    if (empty($parts)) {
        return null;
    }

    return implode(' | ', $parts);
}

function normalizeDiffValue($value, $fieldPath = '')
{
    if ($value === null) {
        return 'بەتاڵ';
    }
    if (is_bool($value)) {
        return $value ? 'بەڵێ' : 'نەخێر';
    }
    if (is_array($value)) {
        if ($fieldPath !== '' && preg_match('/(^|\\.)items$/', $fieldPath) === 1) {
            $itemsText = formatSaleItemsForDiff($value);
            if (is_string($itemsText) && $itemsText !== '') {
                return $itemsText;
            }
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : 'داتای ناتەواو';
    }
    $text = trim((string)$value);
    return $text === '' ? 'بەتاڵ' : $text;
}

function flattenStateForDiff($state, $prefix = '')
{
    $rows = [];
    if (!is_array($state)) {
        return $rows;
    }

    foreach ($state as $key => $value) {
        $label = $prefix === '' ? (string)$key : ($prefix . '.' . $key);
        if (is_array($value)) {
            if (empty($value)) {
                $rows[$label] = '[]';
                continue;
            }
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                $rows = array_merge($rows, flattenStateForDiff($value, $label));
            } else {
                $rows[$label] = normalizeDiffValue($value, $label);
            }
        } else {
            $rows[$label] = normalizeDiffValue($value, $label);
        }
    }

    return $rows;
}

function getChangedFieldsRows($beforeState, $afterState)
{
    $beforeFlat = flattenStateForDiff(is_array($beforeState) ? $beforeState : []);
    $afterFlat = flattenStateForDiff(is_array($afterState) ? $afterState : []);
    $allKeys = array_unique(array_merge(array_keys($beforeFlat), array_keys($afterFlat)));
    sort($allKeys);

    $hiddenMixedDebtKeys = [
        'customer_snapshot.debt_summary.active_debt_total',
        'snapshot.debt_summary.active_debt_total',
        'debt_summary.active_debt_total',
        'customer_snapshot.customer.total_debt',
        'snapshot.customer.total_debt',
        'customer.total_debt'
    ];

    $rows = [];
    foreach ($allKeys as $key) {
        if (in_array($key, $hiddenMixedDebtKeys, true)) {
            continue;
        }
        $oldValue = $beforeFlat[$key] ?? 'بەتاڵ';
        $newValue = $afterFlat[$key] ?? 'بەتاڵ';
        if ($oldValue !== $newValue) {
            $rows[] = [
                'label' => getChangedFieldLabel($key),
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }

    return $rows;
}

function getChangedFieldLabel($key)
{
    static $labelMap = [
        'customer_snapshot.customer.total_debt' => 'قەرزی دینار (لە خشتەی customers)',
        'customer_snapshot.debt_summary.active_debt_iqd' => 'قەرزی فرۆشتن - دینار',
        'customer_snapshot.debt_summary.active_debt_usd' => 'قەرزی فرۆشتن - دۆلار',
        'customer_snapshot.money_debt_summary.money_debt_iqd' => 'قەرزی پارە - دینار',
        'customer_snapshot.money_debt_summary.money_debt_usd' => 'قەرزی پارە - دۆلار',
        'snapshot.customer.total_debt' => 'قەرزی دینار (لە خشتەی customers)',
        'snapshot.debt_summary.active_debt_iqd' => 'قەرزی فرۆشتن - دینار',
        'snapshot.debt_summary.active_debt_usd' => 'قەرزی فرۆشتن - دۆلار',
        'snapshot.money_debt_summary.money_debt_iqd' => 'قەرزی پارە - دینار',
        'snapshot.money_debt_summary.money_debt_usd' => 'قەرزی پارە - دۆلار'
    ];

    if (isset($labelMap[$key])) {
        return $labelMap[$key];
    }

    return translateFieldPathToKurdish($key);
}

function translateFieldPathToKurdish($path)
{
    static $segmentMap = [
        'customer_snapshot' => 'سناپشۆتی کڕیار',
        'snapshot' => 'سناپشۆت',
        'customer' => 'کڕیار',
        'sale_snapshot' => 'سناپشۆتی وەسڵ',
        'sale' => 'وەسڵ',
        'items' => 'بڕگەکان',
        'debt_summary' => 'پوختەی قەرز',
        'money_debt_summary' => 'پوختەی قەرزی پارە',
        'selected_debts' => 'قەرزە هەڵبژێردراوەکان',
        'paid_debts' => 'قەرزە پارەدراوەکان',
        'old_customer_snapshot' => 'سناپشۆتی کڕیاری پێشوو',
        'new_customer_snapshot' => 'سناپشۆتی کڕیاری نوێ',
        'delete_impact' => 'کاریگەری سڕینەوە',
        'transaction' => 'مامەڵە',
        'payment' => 'پارەدان',
        'currency' => 'دراو',
        'amount' => 'بڕ',
        'payment_amount' => 'بڕی پارەدان',
        'remaining_before' => 'ماوەی پێشوو',
        'remaining_after' => 'ماوەی نوێ',
        'remaining_amount' => 'بڕی ماوە',
        'remaining_selected_total' => 'کۆی ماوەی هەڵبژێردراو',
        'receipt_id' => 'ژمارەی وەسڵ',
        'receipt_number' => 'ژمارەی وەسڵ',
        'active_debt_iqd' => 'قەرزی چالاکی دینار',
        'active_debt_usd' => 'قەرزی چالاکی دۆلار',
        'active_debt_count' => 'ژمارەی قەرزی چالاک',
        'money_debt_iqd' => 'قەرزی پارەی دینار',
        'money_debt_usd' => 'قەرزی پارەی دۆلار',
        'total_debt' => 'کۆی قەرز',
        'paid_amount' => 'بڕی پارەدراو',
        'debt' => 'قەرز',
        'debt_id' => 'ژمارەی قەرز',
        'sale_id' => 'ژمارەی وەسڵ',
        'invoice_number' => 'ژمارەی فاتورە',
        'total_amount' => 'کۆی بڕ',
        'discount' => 'داشکاندن',
        'final_amount' => 'بڕی کۆتایی',
        'payment_method' => 'شێوازی پارەدان',
        'payment_status' => 'دۆخی پارەدان',
        'sale_date' => 'بەرواری فرۆشتن',
        'debt_type' => 'جۆری قەرز',
        'status' => 'دۆخ',
        'name' => 'ناو',
        'customer_name' => 'ناوی کڕیار',
        'phone' => 'ژمارەی مۆبایل',
        'customer_phone' => 'مۆبایلی کڕیار',
        'address' => 'ناونیشان',
        'notes' => 'تێبینی',
        'description' => 'وردەکاری',
        'date' => 'بەروار',
        'created_at' => 'دروستکراو لە',
        'updated_at' => 'نوێکراوەتەوە لە',
        'id' => 'ژمارە',
        'user_id' => 'ژمارەی بەکارهێنەر',
        'product_id' => 'ژمارەی کاڵا',
        'product_name' => 'ناوی کاڵا',
        'quantity' => 'بڕ',
        'unit_price' => 'نرخی یەکە',
        'price_type' => 'جۆری نرخ',
        'unit_id' => 'ژمارەی یەکە',
        'unit_name' => 'ناوی یەکە',
        'unit_symbol' => 'نیشانەی یەکە',
        'source_module' => 'سەرچاوەی مۆدیول',
        'source_reference' => 'ئاماژەی سەرچاوە',
        'before_exists' => 'دۆخی پێشوو هەبوو',
        'after_exists' => 'دۆخی نوێ هەیە'
    ];

    if (!is_string($path) || trim($path) === '') {
        return 'خانەی نادیار';
    }

    $parts = explode('.', $path);
    $hiddenLeadingParts = ['sale_snapshot', 'snapshot', 'customer_snapshot'];
    $translated = [];
    foreach ($parts as $idx => $part) {
        if ($part === '') {
            continue;
        }
        if (
            $idx === 0 &&
            count($parts) > 1 &&
            in_array($part, $hiddenLeadingParts, true)
        ) {
            continue;
        }
        if (isset($segmentMap[$part])) {
            $translated[] = $segmentMap[$part];
            continue;
        }
        if (preg_match('/^\d+$/', $part) === 1) {
            $translated[] = '#' . $part;
            continue;
        }
        $translated[] = str_replace('_', ' ', $part);
    }

    if (empty($translated)) {
        return 'خانەی نادیار';
    }

    return implode(' / ', $translated);
}

function resolveDebtAmount($state, $path, $default = 0.0)
{
    if (!is_array($state)) {
        return (float)$default;
    }
    $cursor = $state;
    foreach ($path as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            return (float)$default;
        }
        $cursor = $cursor[$part];
    }
    return is_numeric($cursor) ? (float)$cursor : (float)$default;
}

function extractCustomerDebtCurrencies($state)
{
    $iqd = resolveDebtAmount($state, ['customer_snapshot', 'debt_summary', 'active_debt_iqd']);
    $usd = resolveDebtAmount($state, ['customer_snapshot', 'debt_summary', 'active_debt_usd']);

    if ($iqd === 0.0 && $usd === 0.0) {
        $iqd = resolveDebtAmount($state, ['snapshot', 'debt_summary', 'active_debt_iqd']);
        $usd = resolveDebtAmount($state, ['snapshot', 'debt_summary', 'active_debt_usd']);
    }

    return ['iqd' => $iqd, 'usd' => $usd];
}

function formatDebtCurrencyValue($amount, $currency)
{
    $amount = (float)$amount;
    if ($currency === 'USD') {
        return rtrim(rtrim(number_format($amount, 2, '.', ','), '0'), '.');
    }
    return number_format((float)round($amount), 0, '.', ',');
}

function resolveCustomerNameFromStates($beforeState, $afterState)
{
    $paths = [
        ['customer_snapshot', 'customer', 'name'],
        ['snapshot', 'customer', 'name'],
        ['customer', 'name'],
        ['sale_snapshot', 'sale', 'customer_name']
    ];

    foreach ($paths as $path) {
        $value = resolvePathValue($afterState, $path);
        if ($value !== '') {
            return $value;
        }
    }
    foreach ($paths as $path) {
        $value = resolvePathValue($beforeState, $path);
        if ($value !== '') {
            return $value;
        }
    }

    return 'نادیار';
}

function resolvePathValue($state, $path)
{
    if (!is_array($state)) {
        return '';
    }
    $cursor = $state;
    foreach ($path as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            return '';
        }
        $cursor = $cursor[$part];
    }
    return trim((string)$cursor);
}

function getEventTypeLabel($eventType)
{
    static $eventTypeMap = [
        'sale.create' => 'دروستکردنی وەسڵی فرۆشتن',
        'customer.create' => 'زیادکردنی کڕیار',
        'customer.update' => 'دەستکاریکردنی کڕیار',
        'customer.delete' => 'سڕینەوەی کڕیار',
        'customer_debt.increase' => 'زیادکردنی قەرز',
        'customer_debt.payment' => 'پارەدانەوەی قەرز',
        'customer_debt.payment_delete' => 'سڕینەوەی پارەدان',
        'customer_debt.transaction_delete' => 'سڕینەوەی مامەڵەی قەرز',
        'sale.update' => 'دەستکاریکردنی وەسڵی فرۆشتن',
        'sale.delete' => 'سڕینەوەی وەسڵی فرۆشتن'
    ];
    return $eventTypeMap[$eventType] ?? 'جۆری کردار دیار نەکراوە';
}

function getSourceLabel($sourceModule)
{
    static $sourceMap = [
        'user/customers/index.php' => 'بەڕێوەبردنی کڕیاران',
        'user/customers/credit_sales.php' => 'قەرزی فرۆشتن',
        'user/customers/money_debts.php' => 'قەرزی پارە',
        'user/debts/delete_payment.php' => 'سڕینەوەی پارەدان',
        'api/sales.php' => 'API فرۆشتن'
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
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>مێژووی کڕیاران - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-pages.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="customers-module-page customers-history-page">
<?php
$customersNavId = 'customersHistoryNav';
$customersNavLinks = [
    ['href' => url('user/customers/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کڕیاران'],
    ['href' => url('user/customers/index.php'), 'icon' => 'bi-people', 'text' => 'لیستی کڕیاران'],
];
include __DIR__ . '/partials/customers_nav.php';
?>
<div class="container-fluid py-4 history-page customers-page-content cu-wrap">
    <header class="cu-hero">
        <div>
            <div class="cu-kicker"><i class="bi bi-clock-history"></i> تۆمارەکان</div>
            <h1><i class="bi bi-journal-text"></i> مێژووی گۆڕانکاریی کڕیاران</h1>
            <p class="cu-hero-sub">هەموو گۆڕانکارییەکان و کاریگەرییەکانی لەسەر قەرز ببینە</p>
            <div class="cu-hero-pills">
                <span class="cu-pill"><i class="bi bi-collection"></i> <?php echo number_format((int)$total); ?> تۆمار</span>
            </div>
        </div>
        <div class="cu-actions">
            <a class="cu-btn cu-btn-ghost" href="<?php echo url('user/customers/main.php'); ?>">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
        </div>
    </header>

    <div class="cu-panel history-filter-card mb-3">
        <div class="cu-panel-head"><i class="bi bi-funnel"></i> فلتەر</div>
        <div class="cu-panel-body history-form">
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
                    <label class="form-label">ژمارە یان ناوی کڕیار</label>
                    <input class="form-control" type="text" name="customer_search" value="<?php echo h($customerSearch); ?>" placeholder="وەک: 123 یان ئاوات">
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
                    <button class="cu-btn cu-btn-warn" type="submit"><i class="bi bi-search"></i> گەڕان</button>
                    <a class="cu-btn cu-btn-ghost" href="<?php echo url('user/customers/history.php'); ?>">پاککردنەوە</a>
                </div>
            </form>
        </div>
    </div>

    <div class="cu-panel">
        <div class="cu-panel-body">
            <?php if ($logsError): ?>
                <div class="alert alert-danger mb-0"><?php echo h($logsError); ?></div>
            <?php elseif (empty($logs)): ?>
                <div class="cu-empty">
                    <div class="cu-empty-icon"><i class="bi bi-clock-history"></i></div>
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
                        $resolvedCustomerName = resolveCustomerNameFromStates($beforeState, $afterState);
                        $beforeDebtCurrencies = extractCustomerDebtCurrencies($beforeState);
                        $afterDebtCurrencies = extractCustomerDebtCurrencies($afterState);
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
                                    <div class="small text-body-secondary">ژمارەی کڕیار</div>
                                    <div class="fw-semibold"><?php echo (int)($row['customer_id'] ?? 0); ?></div>
                                </div>
                                <div>
                                    <div class="small text-body-secondary">ناوی کڕیار</div>
                                    <div class="fw-semibold"><?php echo h($resolvedCustomerName); ?></div>
                                </div>
                                <div>
                                    <div class="small text-body-secondary">کات</div>
                                    <div class="fw-semibold"><?php echo h((string)$row['created_at']); ?></div>
                                </div>
                                <div class="align-end-mobile text-end">
                                    <button class="cu-btn cu-btn-ghost cu-btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo h($collapseId); ?>" aria-expanded="false" aria-controls="<?php echo h($collapseId); ?>" id="<?php echo h($headingId); ?>">
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
                                        <?php if (!empty($row['currency'])): ?>
                                            <span class="meta-chip"><i class="bi bi-currency-exchange"></i> <?php echo h((string)$row['currency']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (
                                        $beforeDebtCurrencies['iqd'] !== 0.0 || $beforeDebtCurrencies['usd'] !== 0.0 ||
                                        $afterDebtCurrencies['iqd'] !== 0.0 || $afterDebtCurrencies['usd'] !== 0.0
                                    ): ?>
                                        <div class="mb-3 d-flex flex-wrap gap-2">
                                            <span class="meta-chip">
                                                <i class="bi bi-cash-stack"></i>
                                                قەرزی دینار: <?php echo h(formatDebtCurrencyValue($beforeDebtCurrencies['iqd'], 'IQD')); ?> → <?php echo h(formatDebtCurrencyValue($afterDebtCurrencies['iqd'], 'IQD')); ?>
                                            </span>
                                            <span class="meta-chip">
                                                <i class="bi bi-currency-dollar"></i>
                                                قەرزی دۆلار: <?php echo h(formatDebtCurrencyValue($beforeDebtCurrencies['usd'], 'USD')); ?> → <?php echo h(formatDebtCurrencyValue($afterDebtCurrencies['usd'], 'USD')); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
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
                                    'customer_search' => $customerSearch !== '' ? $customerSearch : null,
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
