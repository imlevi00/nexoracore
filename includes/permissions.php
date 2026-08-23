<?php
/**
 * سیستەمی دەسەڵاتەکان - includes/permissions.php
 */

require_once __DIR__ . '/../config/kasher_logs/database.php';
require_once __DIR__ . '/package_features.php';

const ABAC_POLICY_EFFECT_ALLOW = 'allow';
const ABAC_POLICY_EFFECT_DENY = 'deny';

/**
 * کەتەلۆگی مۆڵەتەکان بە شێوازی module.action
 */
function getPermissionCatalog() {
    return [
        'dashboard' => ['view'],
        'pos' => ['view', 'create', 'update', 'delete', 'checkout', 'discount', 'refund', 'price_edit', 'total_edit'],
        'products' => ['view', 'create', 'update', 'delete', 'import', 'export', 'price_update'],
        'inventory' => ['view', 'create', 'update', 'delete', 'adjust'],
        'categories' => ['view', 'create', 'update', 'delete'],
        'returns' => ['view', 'create', 'update', 'delete', 'approve'],
        'customers' => ['view', 'create', 'update', 'delete', 'export'],
        'debts' => ['view', 'create', 'update', 'delete', 'collect'],
        'customer_info' => ['view'],
        'reports' => ['view', 'export'],
        'profits' => ['view'],
        'expenses' => ['view', 'create', 'update', 'delete'],
        'expense_credits' => ['view', 'create', 'update', 'delete'],
        'expense_stats' => ['view'],
        'notebooks' => ['view', 'create', 'update', 'delete'],
        'settings' => ['view', 'update'],
        'backup' => ['view', 'create', 'restore', 'delete'],
        'telegram' => ['view', 'update'],
        'dollar_price' => ['view', 'update'],
        'employees' => ['view', 'create', 'update', 'delete', 'policy_manage'],
        'companies' => ['view'],
        'purchases' => ['view'],
        'wallets' => ['view', 'create', 'transfer', 'adjust']
    ];
}

function getPermissionCatalogFlat() {
    $flat = [];
    foreach (getPermissionCatalog() as $module => $actions) {
        foreach ($actions as $action) {
            $flat[] = $module . '.' . $action;
        }
    }
    return $flat;
}

function normalizePermissionKey($permission) {
    if (!is_string($permission) || $permission === '') {
        return null;
    }
    $permission = strtolower(trim($permission));
    if (strpos($permission, '.') === false) {
        return $permission . '.view';
    }
    return $permission;
}

function getSectionToPermissionMap() {
    return [
        'dashboard' => 'dashboard.view',
        'pos' => 'pos.view',
        'products' => 'products.view',
        'inventory' => 'inventory.view',
        'categories' => 'categories.view',
        'returns' => 'returns.view',
        'customers' => 'customers.view',
        'debts' => 'debts.view',
        'customer_info' => 'customer_info.view',
        'reports' => 'reports.view',
        'profits' => 'profits.view',
        'expenses' => 'expenses.view',
        'expense_credits' => 'expense_credits.view',
        'expense_stats' => 'expense_stats.view',
        'notebooks' => 'notebooks.view',
        'settings' => 'settings.view',
        'backup' => 'backup.view',
        'telegram' => 'telegram.view',
        'dollar_price' => 'dollar_price.view',
        'employees' => 'employees.view',
        'companies' => 'companies.view',
        'purchases' => 'purchases.view',
        'wallets' => 'wallets.view'
    ];
}

function resolveLegacySectionFromPermission($permission) {
    $permission = normalizePermissionKey($permission);
    if ($permission === null) {
        return null;
    }
    foreach (getSectionToPermissionMap() as $section => $mappedPermission) {
        if ($mappedPermission === $permission) {
            return $section;
        }
    }
    return null;
}
 
/**
 * وەرگرتنی دەسەڵاتەکانی بەکارهێنەر
 */
function getUserPermissions($userId) {
    $userId = (int)$userId;
    $defaultPermissions = [];
    foreach (array_keys(getSectionToPermissionMap()) as $sectionKey) {
        $defaultPermissions[$sectionKey] = false;
    }

    $currentUser = getCurrentUser();
    if (!$currentUser) {
        return $defaultPermissions;
    }

    // Owner/admin exception: main user has full access.
    if (($currentUser['user_type'] ?? 'main') !== 'sub') {
        foreach ($defaultPermissions as $sectionKey => $_) {
            $defaultPermissions[$sectionKey] = true;
        }
        return $defaultPermissions;
    }

    // For sub-users, derive section permissions from ABAC evaluation only.
    $permissions = $defaultPermissions;
    $baseContext = [
        'route' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ];
    foreach (getSectionToPermissionMap() as $sectionKey => $permissionKey) {
        $result = authorize($currentUser, $permissionKey, $baseContext);
        $permissions[$sectionKey] = !empty($result['allowed']);
    }
    return $permissions;
}

/**
 * چیکردنی دەسەڵاتی بەکارهێنەر بۆ بەشێکی دیاریکراو
 */
function hasPermission($userId, $section) {
    $permissions = getUserPermissions($userId);
    return isset($permissions[$section]) && $permissions[$section] === true;
}

/**
 * چیکردنی دەسەڵاتی بەکارهێنەری ئێستا
 */
function currentUserHasPermission($section) {
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        return false;
    }

    $permissionKey = normalizePermissionKey($section);
    if ($permissionKey === null) {
        return false;
    }

    $result = authorize($currentUser, $permissionKey, [
        'route' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ]);

    return !empty($result['allowed']);
}

function getAuthorizationSubjectFromUser($user = null) {
    if ($user === null) {
        $user = getCurrentUser();
    }
    if (!$user || !isset($user['id'])) {
        return null;
    }

    $isSub = !empty($user['user_type']) && $user['user_type'] === 'sub';
    return [
        'subject_type' => $isSub ? 'sub_user' : 'user',
        'subject_id' => $isSub ? (int)($user['sub_user_id'] ?? 0) : (int)$user['id'],
        'main_user_id' => (int)$user['id']
    ];
}

function getAbacCacheKey($subjectType, $subjectId) {
    return 'abac_policy_' . $subjectType . '_' . $subjectId;
}

function invalidateAuthorizationCacheForUser($subjectId, $subjectType = 'sub_user') {
    if (function_exists('deleteCachedData')) {
        deleteCachedData(getAbacCacheKey($subjectType, (int)$subjectId));
    }
}

function getUserAbacPolicies($subjectId, $subjectType = 'sub_user', $mainUserId = null) {
    global $conn;
    $subjectId = (int)$subjectId;
    $subjectType = ($subjectType === 'user') ? 'user' : 'sub_user';

    $cacheKey = getAbacCacheKey($subjectType, $subjectId);
    if (function_exists('getCachedData')) {
        $cached = getCachedData($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $query = "SELECT id, subject_type, subject_id, main_user_id, resource, action, conditions_json, effect, priority, is_active FROM user_abac_policies WHERE subject_type = ? AND subject_id = ? AND is_active = 1";
    $types = 'si';
    $params = [$subjectType, $subjectId];

    if ($mainUserId !== null) {
        $query .= " AND main_user_id = ?";
        $types .= 'i';
        $params[] = (int)$mainUserId;
    }

    $query .= " ORDER BY priority ASC, id ASC";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $policies = [];
    while ($row = $result->fetch_assoc()) {
        $row['conditions'] = !empty($row['conditions_json']) ? json_decode($row['conditions_json'], true) : [];
        if (!is_array($row['conditions'])) {
            $row['conditions'] = [];
        }
        $policies[] = $row;
    }
    $stmt->close();

    if (function_exists('cacheData')) {
        cacheData($cacheKey, $policies, 300);
    }
    return $policies;
}

function validateAbacConditionSet($conditions) {
    if ($conditions === null || $conditions === '') {
        return ['ok' => true, 'normalized' => []];
    }
    if (is_string($conditions)) {
        $decoded = json_decode($conditions, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'conditions_format_invalid'];
        }
        $conditions = $decoded;
    }
    if (!is_array($conditions)) {
        return ['ok' => false, 'error' => 'conditions_must_be_object'];
    }

    $allowedOps = ['eq', 'neq', 'in', 'nin', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists'];
    $allowedFields = ['request_method', 'route', 'ip', 'user_type', 'sub_user_id', 'resource_id', 'owner_user_id', 'currency', 'status'];
    $normalized = [];

    foreach ($conditions as $index => $rule) {
        if (!is_array($rule)) {
            return ['ok' => false, 'error' => 'rule_not_object_' . $index];
        }
        $field = $rule['field'] ?? '';
        $op = strtolower((string)($rule['op'] ?? ''));
        if (!in_array($field, $allowedFields, true) || !in_array($op, $allowedOps, true)) {
            return ['ok' => false, 'error' => 'rule_invalid_' . $index];
        }
        $normalized[] = [
            'field' => $field,
            'op' => $op,
            'value' => $rule['value'] ?? null
        ];
    }

    return ['ok' => true, 'normalized' => $normalized];
}

function evaluateSingleAbacCondition($rule, $context) {
    $field = $rule['field'];
    $op = $rule['op'];
    $ruleValue = $rule['value'] ?? null;
    $actual = $context[$field] ?? null;

    switch ($op) {
        case 'eq': return $actual == $ruleValue;
        case 'neq': return $actual != $ruleValue;
        case 'in': return is_array($ruleValue) ? in_array($actual, $ruleValue, true) : false;
        case 'nin': return is_array($ruleValue) ? !in_array($actual, $ruleValue, true) : true;
        case 'gt': return is_numeric($actual) && is_numeric($ruleValue) && $actual > $ruleValue;
        case 'gte': return is_numeric($actual) && is_numeric($ruleValue) && $actual >= $ruleValue;
        case 'lt': return is_numeric($actual) && is_numeric($ruleValue) && $actual < $ruleValue;
        case 'lte': return is_numeric($actual) && is_numeric($ruleValue) && $actual <= $ruleValue;
        case 'contains': return is_string($actual) && is_string($ruleValue) && strpos($actual, $ruleValue) !== false;
        case 'exists': return (bool)$ruleValue ? array_key_exists($field, $context) : !array_key_exists($field, $context);
        default: return false;
    }
}

function evaluateAbacConditions($conditions, $context) {
    if (empty($conditions)) {
        return true;
    }
    foreach ($conditions as $rule) {
        if (!evaluateSingleAbacCondition($rule, $context)) {
            return false;
        }
    }
    return true;
}

function authorize($user, $permission, $context = []) {
    $permission = normalizePermissionKey($permission);
    if ($permission === null || !$user) {
        return ['allowed' => false, 'reason' => 'invalid_subject_or_permission', 'status' => 403];
    }
    $subject = getAuthorizationSubjectFromUser($user);
    if (!$subject || $subject['subject_id'] <= 0) {
        return ['allowed' => false, 'reason' => 'invalid_subject', 'status' => 403];
    }

    $context['request_method'] = $context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $context['route'] = $context['route'] ?? ($_SERVER['REQUEST_URI'] ?? '');
    $context['user_type'] = $subject['subject_type'] === 'sub_user' ? 'sub' : 'main';
    $context['sub_user_id'] = $subject['subject_type'] === 'sub_user' ? $subject['subject_id'] : null;

    // Main owner/admin keeps full access, ABAC is enforced only for sub-users.
    if ($subject['subject_type'] === 'user') {
        return ['allowed' => true, 'reason' => 'owner_full_access', 'status' => 200];
    }

    $policies = getUserAbacPolicies($subject['subject_id'], $subject['subject_type'], $subject['main_user_id']);
    foreach ($policies as $policy) {
        if ($policy['resource'] !== '*' && $policy['resource'] !== $permission && strpos($permission, $policy['resource'] . '.') !== 0) {
            continue;
        }
        if ($policy['action'] !== '*' && $policy['action'] !== explode('.', $permission)[1]) {
            continue;
        }
        if (!evaluateAbacConditions($policy['conditions'], $context)) {
            continue;
        }

        if ($policy['effect'] === ABAC_POLICY_EFFECT_DENY) {
            return ['allowed' => false, 'reason' => 'abac_policy_deny', 'status' => 403];
        }
        if ($policy['effect'] === ABAC_POLICY_EFFECT_ALLOW) {
            return ['allowed' => true, 'reason' => 'abac_policy_allow', 'status' => 200];
        }
    }

    return ['allowed' => false, 'reason' => 'no_matching_policy', 'status' => 403];
}

/**
 * سکۆپی فرۆشتنی کارمەند (sub_user):
 * - ئەگەر session خاوەن (main) بێت → null (هیچ سنوور، هەموو فرۆشتنەکان).
 * - ئەگەر کارمەند بێت و دەسەڵاتی sales.view_all ـی هەبێت → null (هەموو دەبینێت).
 * - ئەگەر کارمەند بێت و ئەم دەسەڵاتەی نەبێت → sub_user_id (تەنها فرۆشتنی خۆی).
 *
 * ئەم helper ـە لە هەموو شوێنێک بەکاردێت (لیستی فرۆشتن، API، وەسڵ، گەڕانەوە)
 * تا کارمەندی سنووردار نەتوانێت دەستبگات بە فرۆشتنی هاوکارەکانی.
 */
function getSalesOwnOnlySubUserId($user = null, $context = []) {
    if ($user === null) {
        $user = getCurrentUser();
    }
    if (!$user || ($user['user_type'] ?? 'main') !== 'sub') {
        return null;
    }
    $subUserId = (int)($user['sub_user_id'] ?? 0);
    if ($subUserId <= 0) {
        return null;
    }
    $result = authorize($user, 'sales.view_all', $context);
    if (!empty($result['allowed'])) {
        return null; // ڕێگەپێدراوە هەموو فرۆشتنەکان ببینێت
    }
    return $subUserId;
}

/**
 * دەسەڵاتی خوێندنەوەی کاڵا بۆ سیاقەکانی فرۆشتن (POS، گەڕاندنەوە).
 * products.view ـی تەواو نییە؛ pos.view یان returns.create بەسە.
 */
function authorizeProductsReadInSalesContext($user, $context = []) {
    $alternatives = ['products.view', 'pos.view', 'returns.create'];
    foreach ($alternatives as $permission) {
        $result = authorize($user, $permission, $context);
        if (!empty($result['allowed'])) {
            return true;
        }
    }
    return false;
}

/**
 * دەسەڵاتی گۆڕینی خانەیەک لە فرۆشتن (نرخ / کۆی گشتی) — مۆدێلی deny.
 * بەشێوەی بنەڕەت ڕێگەپێدراوە (وەک خاوەن و کارمەندانی ئێستا)؛ تەنها کاتێک
 * یاسایەکی deny ـی ڕوون هەبێت بۆ کارمەندەکە، ڕێگری دەکرێت.
 */
function posSubUserCanEditField($user, $permission) {
    $result = authorize($user, $permission, [
        'route' => '/user/pos/index.php',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ]);
    return ($result['reason'] ?? '') !== 'abac_policy_deny';
}

function enforceAuthorizationOrDeny($user, $permission, $context = [], $responseType = 'json') {
    $result = authorize($user, $permission, $context);
    if ($result['allowed']) {
        return true;
    }

    if ($responseType === 'redirect') {
        setMessage('دەسەڵاتت نییە بۆ ئەم کردارە', 'danger');
        redirect(url('user/dashboard/index.php'));
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'دەسەڵاتت نییە بۆ ئەم کردارە',
        'error' => 'forbidden',
        'reason' => $result['reason']
    ]);
    exit();
}

function saveUserAbacPolicies($actorMainUserId, $subjectType, $subjectId, $policies) {
    global $conn;
    $subjectType = ($subjectType === 'user') ? 'user' : 'sub_user';
    $subjectId = (int)$subjectId;
    $actorMainUserId = (int)$actorMainUserId;

    $conn->begin_transaction();
    try {
        $deleteStmt = $conn->prepare("DELETE FROM user_abac_policies WHERE subject_type = ? AND subject_id = ? AND main_user_id = ?");
        $deleteStmt->bind_param("sii", $subjectType, $subjectId, $actorMainUserId);
        $deleteStmt->execute();
        $deleteStmt->close();

        if (!is_array($policies)) {
            $policies = [];
        }
        $insertStmt = $conn->prepare("INSERT INTO user_abac_policies (subject_type, subject_id, main_user_id, resource, action, conditions_json, effect, priority, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        foreach ($policies as $policy) {
            $resource = trim((string)($policy['resource'] ?? ''));
            $action = trim((string)($policy['action'] ?? ''));
            $effect = strtolower((string)($policy['effect'] ?? ABAC_POLICY_EFFECT_ALLOW));
            $priority = (int)($policy['priority'] ?? 100);
            $validation = validateAbacConditionSet($policy['conditions'] ?? []);
            if ($resource === '' || $action === '' || !$validation['ok']) {
                continue;
            }
            if ($effect !== ABAC_POLICY_EFFECT_ALLOW && $effect !== ABAC_POLICY_EFFECT_DENY) {
                $effect = ABAC_POLICY_EFFECT_ALLOW;
            }
            $conditionsJson = json_encode($validation['normalized'], JSON_UNESCAPED_UNICODE);
            $insertStmt->bind_param("siissssi", $subjectType, $subjectId, $actorMainUserId, $resource, $action, $conditionsJson, $effect, $priority);
            $insertStmt->execute();
        }
        $insertStmt->close();
        $conn->commit();
        invalidateAuthorizationCacheForUser($subjectId, $subjectType);
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

/**
 * ڕێگەگرتن لە دەستپێگەیشتن ئەگەر دەسەڵات نەبوو
 */
function requirePermission($section, $redirectUrl = null) {
    $currentUser = getCurrentUser();
    $result = authorize($currentUser, $section . '.view', [
        'route' => $_SERVER['REQUEST_URI'] ?? '',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ]);
    if (!$result['allowed']) {
        if ($redirectUrl) {
            setMessage('دەسەڵاتت نییە بۆ دەستپێگەیشتنی ئەم بەشە', 'danger');
            redirect($redirectUrl);
        } else {
            http_response_code(403);
            die('دەسەڵاتت نییە بۆ دەستپێگەیشتنی ئەم بەشە');
        }
    }
}

/**
 * وەرگرتنی لیستی بەشە مۆڵەتدراوەکان بۆ بەکارهێنەر
 */
function getAllowedSections($userId) {
    $permissions = getUserPermissions($userId);
    $allowedSections = [];
    
    foreach ($permissions as $section => $allowed) {
        if ($allowed) {
            $allowedSections[] = $section;
        }
    }
    
    return $allowedSections;
}

/**
 * وەرگرتنی ناوی بەشەکان بە کوردی
 */
function getSectionLabels() {
    return [
        'dashboard' => 'داشبۆرد',
        'pos' => 'فرۆشتن',
        'products' => 'کاڵاکان',
        'inventory' => 'بارەکان و کۆگا',
        'categories' => 'کەتەلۆگەکان',
        'returns' => 'گەڕاندنەوەکان',
        'customers' => 'کڕیاران',
        'debts' => 'قەرزەکانی کڕیاران',
        'customer_info' => 'زانیاری کڕیارەکان',
        'reports' => 'ڕاپۆرت و ئامار',
        'profits' => 'قازانج',
        'expenses' => 'خەرجیەکان',
        'expense_credits' => 'قەرزی خەرجیەکان',
        'expense_stats' => 'ئاماری خەرجیەکان',
        'notebooks' => 'دەفتەری تێبینی',
        'settings' => 'ڕێکخستنەکان',
        'backup' => 'پاشەکەوت',
        'telegram' => 'ئاگادارکەرەوەکان',
        'dollar_price' => 'نرخی دۆلار',
        'companies' => 'کۆمپانیاکان',
        'purchases' => 'کڕینەکان',
        'wallets' => 'قاسەکان'
    ];
}

/**
 * وەرگرتنی ئایکۆنی بەشەکان
 */
function getSectionIcons() {
    return [
        'dashboard' => 'bi-speedometer2',
        'pos' => 'bi-cash-coin',
        'products' => 'bi-box-seam',
        'inventory' => 'bi-boxes',
        'categories' => 'bi-tags',
        'returns' => 'bi-arrow-return-left',
        'customers' => 'bi-people',
        'debts' => 'bi-credit-card',
        'customer_info' => 'bi-person-lines-fill',
        'reports' => 'bi-graph-up',
        'profits' => 'bi-pie-chart',
        'expenses' => 'bi-credit-card-2-back',
        'expense_credits' => 'bi-currency-exchange',
        'expense_stats' => 'bi-bar-chart-line',
        'notebooks' => 'bi-book',
        'settings' => 'bi-gear',
        'backup' => 'bi-download',
        'telegram' => 'bi-telegram',
        'dollar_price' => 'bi-currency-dollar',
        'companies' => 'bi-building',
        'purchases' => 'bi-bag-check',
        'wallets' => 'bi-wallet2'
    ];
}

/**
 * دروستکردنی مینووی ناڤیگەیشن بەپێی دەسەڵاتەکان
 */
function generateNavigationMenu($userId, $currentPage = '') {
    $permissions = getUserPermissions($userId);
    $labels = getSectionLabels();
    $icons = getSectionIcons();
    
    $menuItems = [];
    
    // نەخشەی URLەکان
    $sectionUrls = [
        'dashboard' => 'user/dashboard/index.php',
        'pos' => 'user/pos/index.php',
        'products' => 'user/products/index.php',
        'inventory' => 'user/products/index.php?filter=low_stock',
        'categories' => 'user/products/categories.php',
        'returns' => 'user/returns/index.php',
        'customers' => 'user/customers/index.php',
        'debts' => 'user/debts/history.php',
        'customer_info' => 'user/customers/credit_sales.php',
        'reports' => 'user/reports/index.php',
        'profits' => 'user/reports/profits.php',
        'expenses' => 'user/expenses/index.php',
        'expense_credits' => 'user/expenses/credits/index.php',
        'expense_stats' => 'user/expenses/statistics.php',
        'notebooks' => 'user/notebooks/index.php',
        'settings' => 'user/settings/index.php',
        'backup' => 'user/settings/backup.php',
        'telegram' => 'user/telegram/index.php',
        'dollar_price' => 'user/settings/nrxy_dolar/index.php',
        'companies' => 'user/companies/main.php',
        'purchases' => 'user/purchases/main.php',
        'wallets' => 'user/wallets/main.php'
    ];
    
    foreach ($permissions as $section => $allowed) {
        if ($allowed && isset($labels[$section]) && isset($sectionUrls[$section])) {
            $menuItems[] = [
                'section' => $section,
                'label' => $labels[$section],
                'icon' => $icons[$section],
                'url' => $sectionUrls[$section],
                'active' => $currentPage === $section
            ];
        }
    }
    
    return $menuItems;
}

/**
 * چیکردنی ئەوەی بەکارهێنەرە لاوەکیەکان دەسەڵاتی زیاتریان هەیە یان نا لە یوزەری سەرەکی
 */
function validateSubUserPermissions($parentUserId, $subUserPermissions) {
    $parentPermissions = getUserPermissions($parentUserId);
    
    foreach ($subUserPermissions as $section => $allowed) {
        if ($allowed && (!isset($parentPermissions[$section]) || !$parentPermissions[$section])) {
            return false; // بەکارهێنەرە لاوەکیەکە دەسەڵاتی زیاتری هەیە
        }
    }
    
    return true;
}

/**
 * سنووردارکردنی دەسەڵاتەکانی بەکارهێنەرە لاوەکیەکان
 */
function limitSubUserPermissions($parentUserId, $requestedPermissions) {
    $parentPermissions = getUserPermissions($parentUserId);
    $limitedPermissions = [];
    
    foreach ($requestedPermissions as $section => $requested) {
        // تەنها ئەو دەسەڵاتانە بدە کە یوزەری سەرەکی هەیەتی
        $limitedPermissions[$section] = $requested && 
                                      isset($parentPermissions[$section]) && 
                                      $parentPermissions[$section];
    }
    
    return $limitedPermissions;
}

/**
 * وەرگرتنی زانیاری پاکێجی بەکارهێنەر
 */
function getUserPackageInfo($userId) {
    global $conn;
    
    $query = $conn->prepare("
        SELECT p.id, p.name, p.description, p.permissions, p.is_active
        FROM users u 
        LEFT JOIN packages p ON u.package_id = p.id 
        WHERE u.id = ?
    ");
    $query->bind_param("i", $userId);
    $query->execute();
    $result = $query->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * نوێکردنەوەی پاکێجی بەکارهێنەر
 */
function updateUserPackage($userId, $packageId) {
    global $conn;

    if ($packageId === null) {
        $query = $conn->prepare("UPDATE users SET package_id = NULL WHERE id = ?");
        $query->bind_param("i", $userId);
        return $query->execute();
    }

    $query = $conn->prepare("UPDATE users SET package_id = ? WHERE id = ?");
    $query->bind_param("ii", $packageId, $userId);

    return $query->execute();
}

/**
 * وەرگرتنی هەموو پاکێجە چالاکەکان
 */
function getActivePackages() {
    global $conn;
    
    $result = $conn->query("SELECT * FROM packages WHERE is_active = 1 ORDER BY name");
    $packages = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $packages[] = $row;
        }
    }
    
    return $packages;
}

/**
 * وەرگرتنی ID ی پاکێجی بەکارهێنەری ئێستا (main user بۆ هەردوو user و sub_user)
 */
function getCurrentUserPackageId() {
    global $conn;
    
    $currentUser = getCurrentUser();
    if (!$currentUser || !isset($currentUser['id'])) {
        return null;
    }
    
    $userId = (int)$currentUser['id']; // لە حالەتی sub_user دا ئەمە ID ی یوزەری سەرەکیە
    
    $stmt = $conn->prepare("
        SELECT u.package_id
        FROM users u
        WHERE u.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    if ($row && !empty($row['package_id'])) {
        return (int)$row['package_id'];
    }
    
    return null;
}

/**
 * دەقی ستاندارد بۆ کارتی قوفڵی پاکێج (هاوشێوەی پەڕەکانی user)
 *
 * @param string|null $usageSuffix بۆ نموونە: «بەکارهێنانی بەشی قاسەکان»
 */
function getPremiumLockPackageTexts($usageSuffix = null) {
    $title = 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نیە';
    $suffix = trim((string)$usageSuffix);
    if ($suffix !== '') {
        $description = ' پاکێجەکەت بەرزبکەرەوە بۆ ' . $suffix . '.';
    } else {
        $description = ' پاکێجەکەت بەرزبکەرەوە بۆ بەکارهێنانی ئەم خزمەتگوزاریە.';
    }

    return [
        'title' => $title,
        'description' => $description,
    ];
}

/**
 * لینکی CSS ـی قوفڵی پاکێج
 */
function renderPremiumLockStylesheetLink(): void
{
    $lockCssUrl = function_exists('asset') ? asset('css/premium-lock.css') : '/assets/css/premium-lock.css';
    $safeLockCssUrl = htmlspecialchars($lockCssUrl, ENT_QUOTES, 'UTF-8');
    echo '<link href="' . $safeLockCssUrl . '" rel="stylesheet">';
}

/**
 * کلاسی wrapper بۆ ناوەڕۆکی قوفڵکراو
 */
function premiumLockContentClass(bool $allowed): string
{
    return $allowed ? '' : 'locked-content';
}

/**
 * Overlay ـی inline لەسەر پەڕەی ئێستا (hub / preview)
 *
 * @param string|null $usageSuffix
 * @param array{back_url?: string|null, back_label?: string|null, lock_message?: string|null} $options
 */
function renderPremiumLockInlineOverlay($usageSuffix = null, array $options = []): void
{
    $texts = getPremiumLockPackageTexts($usageSuffix);
    $message = $options['lock_message'] ?? $texts['description'];
    $backUrl = $options['back_url'] ?? null;
    $backLabel = $options['back_label'] ?? null;

    $cardMarkup = renderPremiumLockCardMarkup($message, $texts['title'], $backUrl, $backLabel);
    echo '<div class="premium-lock-overlay">' . $cardMarkup . '</div>';
}

/**
 * Overlay ـی section-scoped (بۆ بەشێکی بچووک لە پەڕە، وەک کارتی مەخزەن)
 *
 * @param string|null $usageSuffix
 * @param array{lock_message?: string|null, compact?: bool} $options
 */
function renderPremiumLockSectionOverlay($usageSuffix = null, array $options = []): void
{
    $texts = getPremiumLockPackageTexts($usageSuffix);
    $message = $options['lock_message'] ?? $texts['description'];
    $cardOptions = [];
    if (!empty($options['compact'])) {
        $cardOptions['compact'] = true;
        $cardOptions['hide_back'] = true;
    }

    $cardMarkup = renderPremiumLockCardMarkup($message, $texts['title'], null, null, $cardOptions);
    echo '<div class="premium-lock-section-overlay">' . $cardMarkup . '</div>';
}

/**
 * چالاکی module ی کۆمپانیاکان بەپێی پاکێج
 */
function hasCompaniesModuleAccess(): bool
{
    return hasFeaturePermission('companies_manage');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ module ی کۆمپانیاکان
 */
function requireCompaniesModuleAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('companies_manage', 'بەکارهێنانی بەشی کۆمپانیاکان');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('companies_manage', $lockMessage, $usageSuffix, [
        'page_title' => 'کۆمپانیاکان',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/dashboard/index.php') : '/user/dashboard/index.php',
        'back_label' => 'گەڕانەوە بۆ داشبۆرد',
    ]);
}

/**
 * چالاکی module ی بەڕێوەبردنی پزیشکی بەپێی پاکێج
 */
function hasMedicalStaffModuleAccess(): bool
{
    return hasFeaturePermission('medical_staff_manage');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ module ی بەڕێوەبردنی پزیشکی
 */
function requireMedicalStaffModuleAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('medical_staff_manage', 'بەکارهێنانی بەشی بەڕێوەبردنی پزیشکی');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('medical_staff_manage', $lockMessage, $usageSuffix, [
        'page_title' => 'بەڕێوەبردنی پزیشکی',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/medical_staff/main.php') : '/user/medical_staff/main.php',
        'back_label' => 'گەڕانەوە بۆ بەڕێوەبردنی پزیشکی',
    ]);
}

/**
 * چالاکی module ی بەڕێوەبردنی سەنتەری جوانکاری بەپێی پاکێج
 */
function hasCosmeticStaffModuleAccess(): bool
{
    return hasFeaturePermission('cosmetic_staff_manage');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ module ی بەڕێوەبردنی سەنتەری جوانکاری
 */
function requireCosmeticStaffModuleAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('cosmetic_staff_manage', 'بەکارهێنانی بەشی بەڕێوەبردنی سەنتەری جوانکاری');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('cosmetic_staff_manage', $lockMessage, $usageSuffix, [
        'page_title' => 'بەڕێوەبردنی جوانکاری',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/cosmetic_staff/main.php') : '/user/cosmetic_staff/main.php',
        'back_label' => 'گەڕانەوە بۆ بەڕێوەبردنی جوانکاری',
    ]);
}

/**
 * چالاکی بەڕێوەبردنی کارمەندان بەپێی پاکێج
 */
function hasEmployeesManageAccess(): bool
{
    return hasFeaturePermission('employees_manage');
}

/**
 * چالاکی ئاماری کارمەندان بەپێی پاکێج
 */
function hasEmployeesStatsAccess(): bool
{
    return hasFeaturePermission('employees_stats');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ بەڕێوەبردنی کارمەندان
 */
function requireEmployeesManageAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('employees_manage', 'بەڕێوەبردنی کارمەندان');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('employees_manage', $lockMessage, $usageSuffix, [
        'page_title' => 'کارمەندان',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/employees/main.php') : '/user/employees/main.php',
        'back_label' => 'گەڕانەوە بۆ کارمەندان',
    ]);
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ ئاماری کارمەندان
 */
function requireEmployeesStatsAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('employees_stats', 'بینینی ئاماری کارمەندان');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('employees_stats', $lockMessage, $usageSuffix, [
        'page_title' => 'ئاماری کارمەندان',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/employees/main.php') : '/user/employees/main.php',
        'back_label' => 'گەڕانەوە بۆ کارمەندان',
    ]);
}

/**
 * چالاکی ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان بەپێی پاکێج
 */
function hasItemSectionProfitReportAccess(): bool
{
    return hasFeaturePermission('reports_item_section_profit');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان
 */
function requireItemSectionProfitReportAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('reports_item_section_profit', 'بینینی ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('reports_item_section_profit', $lockMessage, $usageSuffix, [
        'page_title' => 'ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/reports/main.php') : '/user/reports/main.php',
        'back_label' => 'گەڕانەوە بۆ ڕاپۆرتەکان',
    ]);
}

/**
 * چالاکی نرخی سەر ڕەفە بەپێی پاکێج
 */
function hasShelfPriceTagsAccess(): bool
{
    return hasFeaturePermission('products_shelf_price_tags');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ نرخی سەر ڕەفە
 */
function requireShelfPriceTagsAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('products_shelf_price_tags', 'بەکارهێنانی نرخی سەر ڕەفە');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('products_shelf_price_tags', $lockMessage, $usageSuffix, [
        'page_title' => 'نرخی سەر ڕەفە',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/products/main.php') : '/user/products/main.php',
        'back_label' => 'گەڕانەوە بۆ کاڵاکان',
    ]);
}

/**
 * چالاکی بەڕێوەبردنی خزمەتگوزاری بەپێی پاکێج
 */
function hasProductsServicesAccess(): bool
{
    return hasFeaturePermission('products_services_manage');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ بەڕێوەبردنی خزمەتگوزاری
 */
function requireProductsServicesAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('products_services_manage', 'بەڕێوەبردنی خزمەتگوزاری');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('products_services_manage', $lockMessage, $usageSuffix, [
        'page_title' => 'خزمەتگوزاری',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/products/main.php') : '/user/products/main.php',
        'back_label' => 'گەڕانەوە بۆ کاڵاکان',
    ]);
}

/**
 * چالاکی قەپانی زیرەک بەپێی پاکێج
 */
function hasSmartScaleAccess(): bool
{
    return hasFeaturePermission('products_smart_scale');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ قەپانی زیرەک
 */
function requireSmartScaleAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('products_smart_scale', 'بەکارهێنانی قەپانی زیرەک');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('products_smart_scale', $lockMessage, $usageSuffix, [
        'page_title' => 'قەپانی زیرەک',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/products/main.php') : '/user/products/main.php',
        'back_label' => 'گەڕانەوە بۆ کاڵاکان',
    ]);
}

/**
 * چالاکی دەرمانی نەگونجاو (دەرمانخانە) بەپێی پاکێج
 */
function hasDrugInteractionsAccess(): bool
{
    return hasFeaturePermission('products_drug_interactions');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ بەڕێوەبردنی دەرمانی نەگونجاو
 */
function requireDrugInteractionsAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('products_drug_interactions', 'بەڕێوەبردنی دەرمانی نەگونجاو');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('products_drug_interactions', $lockMessage, $usageSuffix, [
        'page_title' => 'دەرمانی نەگونجاو',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/products/main.php') : '/user/products/main.php',
        'back_label' => 'گەڕانەوە بۆ کاڵاکان',
    ]);
}

/**
 * چالاکی مێژووی کاڵاکان بەپێی پاکێج
 */
function hasProductsHistoryAccess(): bool
{
    return hasFeaturePermission('products_history');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ مێژووی کاڵاکان
 */
function requireProductsHistoryAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('products_history', 'بینینی مێژووی کاڵاکان');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('products_history', $lockMessage, $usageSuffix, [
        'page_title' => 'مێژووی کاڵاکان',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/products/main.php') : '/user/products/main.php',
        'back_label' => 'گەڕانەوە بۆ کاڵاکان',
    ]);
}

/**
 * چالاکی دروستکردنی بارکۆد بەپێی پاکێج
 */
function hasProductsBarcodeAccess(): bool
{
    return hasFeaturePermission('products_barcode_generate');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ دروستکردنی بارکۆد
 */
function requireProductsBarcodeAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('products_barcode_generate', 'دروستکردنی بارکۆد');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('products_barcode_generate', $lockMessage, $usageSuffix, [
        'page_title' => 'دروستکردنی بارکۆد',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/products/main.php') : '/user/products/main.php',
        'back_label' => 'گەڕانەوە بۆ کاڵاکان',
    ]);
}

/**
 * چالاکی کارتی مەخزەن (نرخی کڕین و فرۆشتن) بەپێی پاکێج
 */
function hasProductsInventoryValueCardsAccess(): bool
{
    return hasFeaturePermission('products_inventory_value_cards');
}

/**
 * چالاکی بەڕێوەبردنی یەکەکانی جیاواز بۆ کاڵا بەپێی پاکێج
 */
function hasProductsUnitsManageAccess(): bool
{
    return hasFeaturePermission('products_units_manage');
}

/**
 * ڕێگەگرتن لە API ـی بەڕێوەبردنی یەکەکان کاتێک feature ناچالاکە
 */
function requireProductsUnitsManageApiAccess(): void
{
    if (!hasProductsUnitsManageAccess()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => getPremiumLockPackageTexts(
                getPackageFeatureUsageSuffix('products_units_manage', 'بەکارهێنانی یەکەکانی جیاواز بۆ کاڵا')
            )['title'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * چالاکی مێژووی کڕیاران بەپێی پاکێج
 */
function hasCustomersHistoryAccess(): bool
{
    return hasFeaturePermission('customers_history');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ مێژووی کڕیاران
 */
function requireCustomersHistoryAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('customers_history', 'بینینی مێژووی کڕیاران');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('customers_history', $lockMessage, $usageSuffix, [
        'page_title' => 'مێژووی کڕیاران',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/customers/main.php') : '/user/customers/main.php',
        'back_label' => 'گەڕانەوە بۆ کڕیاران',
    ]);
}

/**
 * چالاکی کەشف حسابی بەپێی پاکێج
 */
function hasCustomersAccountStatementAccess(): bool
{
    return hasFeaturePermission('customers_account_statement');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ کەشف حسابی
 */
function requireCustomersAccountStatementAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('customers_account_statement', 'بینینی کەشف حسابی');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('customers_account_statement', $lockMessage, $usageSuffix, [
        'page_title' => 'کەشف حسابی',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/customers/main.php') : '/user/customers/main.php',
        'back_label' => 'گەڕانەوە بۆ کڕیاران',
    ]);
}

/**
 * چالاکی بەڕێوەبردنی خەرجیەکان بەپێی پاکێج
 */
function hasExpensesModuleAccess(): bool
{
    return hasFeaturePermission('expenses_manage');
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ بەڕێوەبردنی خەرجیەکان
 */
function requireExpensesModuleAccess($lockMessage = null): void
{
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('expenses_manage', 'بەڕێوەبردنی خەرجیەکان');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('expenses_manage', $lockMessage, $usageSuffix, [
        'page_title' => 'خەرجیەکان',
        'skeleton' => 'generic',
        'back_url' => function_exists('url') ? url('user/expenses/main.php') : '/user/expenses/main.php',
        'back_label' => 'گەڕانەوە بۆ خەرجیەکان',
    ]);
}

/**
 * HTML ـی ناوەڕۆکی کارتی قوفڵ (premium-lock-card)
 *
 * @param array{compact?: bool, hide_back?: bool} $options
 */
function renderPremiumLockCardMarkup($description, $title = null, $backUrl = null, $backLabel = null, array $options = []) {
    $texts = getPremiumLockPackageTexts();
    if ($title === null || trim((string)$title) === '') {
        $title = $texts['title'];
    }

    $telegramUrl = defined('TELEGRAM_VERIFICATION_URL') ? TELEGRAM_VERIFICATION_URL : '#';
    $hideBack = !empty($options['hide_back']);
    if (!$hideBack) {
        if ($backUrl === null || trim((string)$backUrl) === '') {
            $backUrl = function_exists('url')
                ? url('user/dashboard/index.php')
                : '/user/dashboard/index.php';
        }
        if ($backLabel === null || trim((string)$backLabel) === '') {
            $backLabel = 'گەڕانەوە بۆ داشبۆرد';
        }
    }

    $cardClass = 'premium-lock-card';
    if (!empty($options['compact'])) {
        $cardClass .= ' premium-lock-card--compact';
    }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeDescription = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $safeTelegramUrl = htmlspecialchars($telegramUrl, ENT_QUOTES, 'UTF-8');
    $safeCardClass = htmlspecialchars($cardClass, ENT_QUOTES, 'UTF-8');

    $markup = '<div class="' . $safeCardClass . '">'
        . '<div class="lock-icon-container"><i class="bi bi-lock-fill"></i></div>'
        . '<h3 class="premium-lock-card__title">' . $safeTitle . '</h3>'
        . '<p>' . $safeDescription . '</p>'
        . '<a href="' . $safeTelegramUrl . '" target="_blank" class="upgrade-btn">'
        . '<i class="bi bi-telegram"></i> پەیوەندی بە بەڕێوەبەر'
        . '</a>';

    if (!$hideBack) {
        $safeBackUrl = htmlspecialchars((string)$backUrl, ENT_QUOTES, 'UTF-8');
        $safeBackLabel = htmlspecialchars((string)$backLabel, ENT_QUOTES, 'UTF-8');
        $markup .= '<a href="' . $safeBackUrl . '" class="back-btn">'
            . '<i class="bi bi-arrow-right"></i> ' . $safeBackLabel
            . '</a>';
    }

    return $markup . '</div>';
}

/**
 * پاشبنەمای blur ـکراو وەک receipt.php (بۆ قوفڵی تەواو پەڕە)
 */
function renderPremiumLockSkeletonMarkup($skeleton = 'wallets') {
    if ($skeleton === 'wallets') {
        return '<div class="container-fluid py-4 premium-lock-skeleton">'
            . '<div class="d-flex justify-content-between align-items-center mb-4">'
            . '<div><h4 class="mb-1"><i class="bi bi-wallet2"></i> قاسەکان</h4>'
            . '<p class="text-muted mb-0">بەڕێوەبردنی قاسە و باڵانس</p></div>'
            . '<span class="btn btn-primary disabled">قاسەی نوێ</span></div>'
            . '<div class="row g-3 mb-4">'
            . '<div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="placeholder-glow">'
            . '<span class="placeholder col-7"></span><span class="placeholder col-4"></span></div></div></div></div>'
            . '<div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="placeholder-glow">'
            . '<span class="placeholder col-6"></span><span class="placeholder col-8"></span></div></div></div></div>'
            . '<div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="placeholder-glow">'
            . '<span class="placeholder col-5"></span><span class="placeholder col-7"></span></div></div></div></div>'
            . '<div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="placeholder-glow">'
            . '<span class="placeholder col-8"></span><span class="placeholder col-4"></span></div></div></div></div>'
            . '</div>'
            . '<div class="wallet-table-shell p-3">'
            . '<table class="table table-hover mb-0"><thead><tr>'
            . '<th>ناوی قاسە</th><th>باڵانس</th><th>جۆر</th><th>دۆخ</th></tr></thead>'
            . '<tbody><tr><td colspan="4"><span class="placeholder col-12"></span></td></tr>'
            . '<tr><td colspan="4"><span class="placeholder col-10"></span></td></tr>'
            . '<tr><td colspan="4"><span class="placeholder col-11"></span></td></tr></tbody></table>'
            . '</div></div>';
    }

    return '<div class="container-fluid py-4 premium-lock-skeleton">'
        . '<div class="row g-3"><div class="col-lg-8">'
        . '<div class="card shadow-sm"><div class="card-body"><div class="placeholder-glow">'
        . '<span class="placeholder col-7 mb-2"></span><span class="placeholder col-4 mb-3"></span>'
        . '<span class="placeholder col-12 mb-2"></span><span class="placeholder col-10"></span>'
        . '</div></div></div></div><div class="col-lg-4">'
        . '<div class="card shadow-sm"><div class="card-body"><div class="placeholder-glow">'
        . '<span class="placeholder col-8 mb-2"></span><span class="placeholder col-6"></span>'
        . '</div></div></div></div></div></div>';
}

/**
 * پەڕەی تەواوی قوفڵ — هاوشێوەی user/pos/receipt.php (nav + blur + overlay)
 */
function renderPremiumLockScreen($message = null, $usageSuffix = null, array $options = []) {
    $texts = getPremiumLockPackageTexts($usageSuffix);
    if ($message === null) {
        $message = $texts['description'];
    }

    $defaults = [
        'page_title' => 'خزمەتگوزاری ناچالاکە',
        'back_url' => null,
        'back_label' => 'گەڕانەوە بۆ داشبۆرد',
        'skeleton' => 'wallets',
        'show_navigation' => true,
    ];
    $options = array_merge($defaults, $options);

    $siteName = defined('SITE_NAME') ? SITE_NAME : 'NexoraCore';
    $pageTitle = trim((string)$options['page_title']);
    $styleCssUrl = function_exists('asset') ? asset('css/style.css') : '/assets/css/style.css';
    $lockCssUrl = function_exists('asset') ? asset('css/premium-lock.css') : '/assets/css/premium-lock.css';

    $safePageTitle = htmlspecialchars($pageTitle . ' - ' . $siteName, ENT_QUOTES, 'UTF-8');
    $safeStyleCssUrl = htmlspecialchars($styleCssUrl, ENT_QUOTES, 'UTF-8');
    $safeLockCssUrl = htmlspecialchars($lockCssUrl, ENT_QUOTES, 'UTF-8');

    $themeMarkup = function_exists('kasher_get_theme_bootstrap_markup')
        ? kasher_get_theme_bootstrap_markup()
        : '';

    $cardMarkup = renderPremiumLockCardMarkup(
        $message,
        $texts['title'],
        $options['back_url'],
        $options['back_label']
    );
    $skeletonMarkup = renderPremiumLockSkeletonMarkup((string)$options['skeleton']);

    echo '<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . $themeMarkup
        . '<title>' . $safePageTitle . '</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="' . $safeStyleCssUrl . '" rel="stylesheet">
    <link href="' . $safeLockCssUrl . '" rel="stylesheet">
</head>
<body class="bg-light premium-lock-screen">';

    if (!empty($options['show_navigation'])) {
        $navigationFile = __DIR__ . '/navigation.php';
        if (is_file($navigationFile)) {
            include $navigationFile;
        }
    }

    echo '<div class="locked-content">' . $skeletonMarkup . '</div>'
        . '<div class="premium-lock-overlay">' . $cardMarkup . '</div>'
        . '</body></html>';
}

/**
 * پیشاندانی overlay ـی بنەڕەتی کاتێک feature ناچالاکە
 */
function renderDefaultFeatureLockOverlay($message = null, $usageSuffix = null, array $screenOptions = []) {
    $defaults = ['skeleton' => 'wallets'];
    if ($usageSuffix === 'بەکارهێنانی بەشی قاسەکان') {
        $defaults['page_title'] = 'قاسەکان';
    }
    renderPremiumLockScreen($message, $usageSuffix, array_merge($defaults, $screenOptions));
}

/**
 * feature ـەکانی هەمیشە چالاک (بەبێ کۆنتڕۆڵی پاکێج لە Admin)
 */
function getAlwaysEnabledFeatureKeys(): array {
    return ['pos_receipt_edit'];
}

/**
 * تاقیکردنی ئەوەی ئایا بەکارهێنەر دەسەڵاتی بۆ feature ـێکی دیاریکراو هەیە (بەبێ وەستاندنی سیستەم)
 */
function hasFeaturePermission($featureKey) {
    global $conn;

    if (in_array($featureKey, getAlwaysEnabledFeatureKeys(), true)) {
        return isUser();
    }
    
    // تەنها بۆ بەکارهێنەرانی داخڵبوو
    if (!isUser()) {
        return false;
    }
    
    $packageId = getCurrentUserPackageId();
    
    // ئەگەر پاکێج نییە، بۆ هەنگامی پێشوو بە چالاکی هەموو featureکان ڕەفتار بکە
    if (!$packageId) {
        return true;
    }
    
    $stmt = $conn->prepare("
        SELECT is_enabled
        FROM package_feature_permissions
        WHERE package_id = ? AND feature_key = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        // ئەگەر خشتەکە نییە یان هەڵەی دابەزیناوی هەیە، هەموو featureکان بە چالاکی هەڵبگرە بۆ ئەوەی سیستەمەکە نەکێشێت
        return true;
    }
     
    $stmt->bind_param("is", $packageId, $featureKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    // ئەگەر هیچ تۆمارێک نییە، بنەڕەتی کاتالۆگ بەکاربهێنە
    if (!$row) {
        return isPackageFeatureEnabledByDefault($featureKey);
    }
    
    return !empty($row['is_enabled']);
}

/**
 * تاقیکردنی feature بۆ userId ـی دیاریکراو (بەبێ پشتبەستن بە session)
 */
function hasFeaturePermissionForUser(int $userId, string $featureKey): bool
{
    global $conn;

    if (in_array($featureKey, getAlwaysEnabledFeatureKeys(), true)) {
        return true;
    }

    $userId = (int)$userId;
    if ($userId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("SELECT package_id FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return true;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row || empty($row['package_id'])) {
        return true;
    }

    $packageId = (int)$row['package_id'];

    $permStmt = $conn->prepare("
        SELECT is_enabled
        FROM package_feature_permissions
        WHERE package_id = ? AND feature_key = ?
        LIMIT 1
    ");

    if (!$permStmt) {
        return true;
    }

    $permStmt->bind_param('is', $packageId, $featureKey);
    $permStmt->execute();
    $permResult = $permStmt->get_result();
    $permRow = $permResult ? $permResult->fetch_assoc() : null;
    $permStmt->close();

    if (!$permRow) {
        return isPackageFeatureEnabledByDefault($featureKey);
    }

    return !empty($permRow['is_enabled']);
}

/**
 * چیکردنی چالاکی module ی قاسەکان بەپێی پاکێجی بەکارهێنەری ئێستا
 */
function isWalletsModuleEnabledForCurrentPackage() {
    return hasFeaturePermission('wallets_manage');
}

/**
 * چالاکی module ی قاسەکان بەپێی پاکێج
 */
function hasWalletsModuleAccess(): bool
{
    return isWalletsModuleEnabledForCurrentPackage();
}

/**
 * چیکردنی دەستڕاگەیشتن بۆ module ی قاسەکان (ABAC + package feature)
 */
function canCurrentUserAccessWalletsModule($context = []) {
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        return false;
    }

    $authResult = authorize($currentUser, 'wallets.view', [
        'route' => $context['route'] ?? ($_SERVER['REQUEST_URI'] ?? ''),
        'request_method' => $context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    ]);
    if (empty($authResult['allowed'])) {
        return false;
    }

    return isWalletsModuleEnabledForCurrentPackage();
}

/**
 * ڕێگەپێدان/ڕێگەگرتن بۆ module ی قاسەکان (hide-and-block policy)
 */
function requireWalletsModuleAccess($lockMessage = null) {
    if (!isUser()) {
        return;
    }

    $usageSuffix = getPackageFeatureUsageSuffix('wallets_manage', 'بەکارهێنانی بەشی قاسەکان');
    if ($lockMessage === null) {
        $lockMessage = getPremiumLockPackageTexts($usageSuffix)['description'];
    }

    requireFeaturePermission('wallets_manage', $lockMessage, $usageSuffix, [
        'page_title' => 'قاسەکان',
        'skeleton' => 'wallets',
        'back_url' => function_exists('url') ? url('user/dashboard/index.php') : '/user/dashboard/index.php',
        'back_label' => 'گەڕانەوە بۆ داشبۆرد',
    ]);
}

/**
 * وەرگرتنی زۆرترین ژمارەی کارمەندان بۆ پاکێجێکی دیاریکراو
 * 
 * @param int|null $packageId - ID ی پاکێج (ئەگەر null بوو، پاکێجی بەکارهێنەری ئێستا بەکاردەهێنرێت)
 * @return int - زۆرترین ژمارەی کارمەندان (بەردەفرەت: 3)
 */
function getMaxEmployeesForPackage($packageId = null) {
    global $conn;
    
    if ($packageId === null) {
        $packageId = getCurrentUserPackageId();
    }
    
    // ئەگەر پاکێج نییە، بەردەفرەت 3 بگەڕێنەوە
    if (!$packageId) {
        return 3;
    }
    
    $stmt = $conn->prepare("
        SELECT is_enabled 
        FROM package_feature_permissions 
        WHERE package_id = ? AND feature_key = 'employees_max_count'
        LIMIT 1
    ");
    
    if (!$stmt) {
        return 3;
    }
    
    $stmt->bind_param("i", $packageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    // ئەگەر هیچ تۆمارێک نییە، بەردەفرەت 3 بگەڕێنەوە
    if ($row === null) {
        return 3;
    }
    
    return (int)$row['is_enabled'];
}

/**
 * وەرگرتنی زۆرترین ژمارەی کارمەندان بۆ بەکارهێنەری سەرەکی (بەپێی main_user_id)
 * 
 * @param int $mainUserId - ID ی بەکارهێنەری سەرەکی
 * @return int - زۆرترین ژمارەی کارمەندان (بەردەفرەت: 3)
 */
function getMaxEmployeesForUser($mainUserId) {
    global $conn;
    
    // وەرگرتنی package_id ی بەکارهێنەر
    $stmt = $conn->prepare("SELECT package_id FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return 3;
    }
    
    $stmt->bind_param("i", $mainUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    if (!$row || empty($row['package_id'])) {
        return 3;
    }
    
    return getMaxEmployeesForPackage((int)$row['package_id']);
}

/**
 * وەرگرتنی ژمارەی کارمەندانی هەنووکەی بۆ بەکارهێنەری سەرەکی
 * 
 * @param int $mainUserId - ID ی بەکارهێنەری سەرەکی
 * @return int - ژمارەی کارمەندان
 */
function getEmployeeCountForUser($mainUserId) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM sub_users WHERE main_user_id = ?");
    if (!$stmt) {
        return 0;
    }
    
    $stmt->bind_param("i", $mainUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    return $row ? (int)$row['count'] : 0;
}

/**
 * تاقیکردنی دەسەڵاتی پاکێج بۆ feature ـێکی دیاریکراو و پیشاندانی کارتی قوفڵ ئەگەر ناچالاک بوو
 */
function requireFeaturePermission($featureKey, $lockMessage = null, $usageSuffix = null, array $screenOptions = []) {
    global $conn;

    if (in_array($featureKey, getAlwaysEnabledFeatureKeys(), true)) {
        return;
    }
    
    // تەنها بۆ بەکارهێنەرانی داخڵبوو
    if (!isUser()) {
        return;
    }
    
    $packageId = getCurrentUserPackageId();
    
    // ئەگەر پاکێج نییە، بۆ هەنگامی پێشوو بە چالاکی هەموو featureکان ڕەفتار بکە
    if (!$packageId) {
        return;
    }
    
    $stmt = $conn->prepare("
        SELECT is_enabled, lock_html
        FROM package_feature_permissions
        WHERE package_id = ? AND feature_key = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        // ئەگەر خشتەکە نییە یان هەڵەی دابەزیناوی هەیە، هەموو featureکان بە چالاکی هەڵبگرە بۆ ئەوەی سیستەمەکە نەکێشێت
        return;
    }
     
    $stmt->bind_param("is", $packageId, $featureKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    // ئەگەر هیچ تۆمارێک نییە، بنەڕەتی کاتالۆگ بەکاربهێنە
    if (!$row) {
        if (isPackageFeatureEnabledByDefault($featureKey)) {
            return;
        }
    } elseif (!empty($row['is_enabled'])) {
        // is_enabled = 1 → ڕێگەپێدراو
        return;
    }

    // is_enabled = 0 یان بنەڕەتی ناچالاک → قوفڵکراو
    if (!empty($row['lock_html'])) {
        // قەبارەی HTML/CSSی تایبەتی بۆ ئەم پاکێجە و feature ـە
        echo $row['lock_html'];
    } else {
        // fallback بۆ overlay ـی بنەڕەتی
        renderDefaultFeatureLockOverlay($lockMessage, $usageSuffix, $screenOptions);
    }
    
    exit;
}

?>