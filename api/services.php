<?php
/**
 * API خزمەتگوزارییەکان - api/services.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/security.php';
require_once '../includes/permissions.php';

function sendResponse($success, $data = null, $message = '', $code = 200)
{
    http_response_code($code);
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('c')
    ]);
    exit();
}

if (!isUser()) {
    sendResponse(false, null, 'غیر مجاز - داخڵبوون پێویستە', 401);
}

$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$action = trim((string)($_GET['action'] ?? 'list'));

$servicesAuthContext = [
    'route' => '/api/services.php?action=' . $action,
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
];
if (!authorizeProductsReadInSalesContext($currentUser, $servicesAuthContext)) {
    enforceAuthorizationOrDeny($currentUser, 'products.view', $servicesAuthContext, 'json');
}

if (!hasProductsServicesAccess()) {
    sendResponse(false, null, 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نیە', 403);
}

SessionManager::releaseSessionLockForParallelReads();

if ($action !== 'list') {
    sendResponse(false, null, 'کردارێکی نادروست', 400);
}

$limit = min(200, max(1, (int)($_GET['limit'] ?? 150)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$search = trim((string)($_GET['search'] ?? ''));

$where = 'user_id = ?';
$types = 'i';
$params = [$userId];

if ($search !== '') {
    $where .= ' AND name LIKE ?';
    $types .= 's';
    $params[] = '%' . $search . '%';
}

$countSql = "SELECT COUNT(*) AS total FROM services WHERE $where";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
}
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$listSql = "
    SELECT id, name, cost_price, sell_price, created_at, updated_at
    FROM services
    WHERE $where
    ORDER BY name ASC
    LIMIT ? OFFSET ?
";
$listStmt = $conn->prepare($listSql);
if (!$listStmt) {
    sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
}

$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $limit;
$listParams[] = $offset;
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$rows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$services = array_map(static function ($row) {
    return [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'cost_price' => (float)$row['cost_price'],
        'sell_price' => (float)$row['sell_price'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at']
    ];
}, $rows);

sendResponse(true, [
    'services' => $services,
    'total' => $total,
    'hasMore' => ($offset + count($services)) < $total
], 'Services fetched');
