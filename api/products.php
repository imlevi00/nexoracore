<?php
/**
 * API کاڵاکان - api/products.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/security.php';
require_once '../includes/permissions.php';

/** JSON encode flags — JSON_INVALID_UTF8_SUBSTITUTE تەنها لە PHP 7.2+ */
function productsApiJsonEncodeFlags() {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return $flags;
}

// Function to send JSON response
function sendResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('c')
    ], productsApiJsonEncodeFlags());

    if ($payload === false) {
        error_log('products.php sendResponse json_encode failed: ' . json_last_error_msg());
        http_response_code(500);
        $fallbackPayload = json_encode([
            'success' => false,
            'data' => null,
            'message' => 'هەڵەیەک ڕوویدا لە سێرڤەر',
            'error' => 'encode_error',
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE);
        if ($fallbackPayload === false) {
            echo '{"success":false,"data":null,"message":"Server error","error":"encode_error"}';
        } else {
            echo $fallbackPayload;
        }
        exit();
    }

    echo $payload;
    exit();
}

function shouldIncludeZeroStockProducts() {
    return isset($_GET['include_zero_stock']) && $_GET['include_zero_stock'] === '1';
}

/**
 * تۆمارکردن بۆ پشکنینی production (لەگەڵ [POS fetch] لە console).
 * ئەگەر status=502/HTML: nginx/PHP-FPM timeout؛ ئەگەر gone away: MySQL reconnect/wait_timeout.
 */
function logProductsApiStmtFailure($context, mysqli_stmt $stmt) {
    error_log(sprintf(
        'products.php %s stmt execute failed: %s',
        $context,
        $stmt->error ?: 'unknown'
    ));
}

function getProductStockExistsSql($includeZeroStock) {
    return $includeZeroStock
        ? 'EXISTS (SELECT 1 FROM product_units pus WHERE pus.product_id = p.id)'
        : 'EXISTS (SELECT 1 FROM product_units pus WHERE pus.product_id = p.id AND pus.stock_quantity > 0)';
}

/**
 * ڕیزکردنی یەکەکان: سەرەکی یەکەم، پاشان کەمترین id
 */
function sortProductUnitsPrimaryFirst(array &$units): void {
    usort($units, function ($left, $right) {
        $leftPrimary = (int)($left['is_primary'] ?? 0);
        $rightPrimary = (int)($right['is_primary'] ?? 0);
        if ($leftPrimary !== $rightPrimary) {
            return $rightPrimary <=> $leftPrimary;
        }

        return ((int)$left['id']) <=> ((int)$right['id']);
    });
}

/**
 * یەکگرتنی نرخ/بڕ لەسەر یەکەی سەرەکی (units[0] دوای sort)
 */
function applyPrimaryUnitDefaults(array &$product): void {
    if (($product['unit_count'] ?? 0) <= 0 || empty($product['units'])) {
        return;
    }

    sortProductUnitsPrimaryFirst($product['units']);
    $firstUnit = $product['units'][0];
    $product['buy_price'] = $firstUnit['buy_price'];
    $product['sell_price'] = $firstUnit['sell_price'];
    $product['wholesale_price'] = $firstUnit['wholesale_price'];
    $product['special_price'] = $firstUnit['special_price'];
    $product['stock_quantity'] = $firstUnit['stock_quantity'];
    $product['min_stock'] = $firstUnit['min_stock'];
    $product['currency'] = $firstUnit['currency'] ?? ($product['currency'] ?? 'IQD');
    $product['default_unit_index'] = 0;
    $product['default_unit_id'] = $firstUnit['id'];
}

function buildProductUnitFromRow(array $row): array {
    return [
        'id' => (int)$row['product_unit_id'],
        'unit_id' => (int)$row['unit_id'],
        'unit_name' => $row['unit_name'],
        'unit_symbol' => $row['unit_symbol'],
        'buy_price' => (float)$row['unit_buy_price'],
        'sell_price' => (float)$row['unit_sell_price'],
        'wholesale_price' => (float)$row['unit_wholesale_price'],
        'special_price' => (float)$row['unit_special_price'],
        'stock_quantity' => (float)$row['unit_stock_quantity'],
        'min_stock' => (int)$row['unit_min_stock'],
        'currency' => $row['unit_currency'] ?? ($row['currency'] ?? 'IQD'),
        'is_primary' => (int)($row['unit_is_primary'] ?? 0),
    ];
}

// Check if user is authenticated for most operations
$requiresAuth = !in_array($_GET['action'] ?? '', ['barcode_public']);
if ($requiresAuth && !isUser()) {
    sendResponse(false, null, 'غیر مجاز - داخڵبوون پێویستە', 401);
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'] ?? null;

// Get action from request
$action = $_GET['action'] ?? '';

$actionPermissionMap = [
    'list' => 'products.view',
    'search' => 'products.view',
    'barcode' => 'products.view',
    'category_products' => 'products.view',
    'low_stock' => 'inventory.view',
    'expired' => 'inventory.view',
    'get' => 'products.view',
    'add' => 'products.create',
    'update' => 'products.update',
    'delete' => 'products.delete'
];

$productsSalesReadActions = ['list', 'search', 'barcode', 'category_products', 'get'];

if ($requiresAuth) {
    $permission = $actionPermissionMap[$action] ?? 'products.view';
    $authContext = [
        'route' => '/api/products.php?action=' . $action,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ];

    if (in_array($action, $productsSalesReadActions, true)) {
        if (!authorizeProductsReadInSalesContext($currentUser, $authContext)) {
            enforceAuthorizationOrDeny($currentUser, $permission, $authContext, 'json');
        }
    } else {
        enforceAuthorizationOrDeny($currentUser, $permission, $authContext, 'json');
    }
}

$productsReadActions = ['list', 'search', 'barcode', 'category_products', 'low_stock', 'expired', 'get'];
if (in_array($action, $productsReadActions, true)) {
    SessionManager::releaseSessionLockForParallelReads();
}

try {
    switch ($action) {
        case 'list':
            handleProductList();
            break;
            
        case 'search':
            handleProductSearch();
            break;
            
        case 'barcode':
            handleBarcodeSearch();
            break;
            
        case 'add':
            handleAddProduct();
            break;
            
        case 'update':
            handleUpdateProduct();
            break;
            
        case 'delete':
            handleDeleteProduct();
            break;
            
        case 'category_products':
            handleCategoryProducts();
            break;
            
        case 'low_stock':
            handleLowStockProducts();
            break;
            
        case 'expired':
            handleExpiredProducts();
            break;
            
        case 'get':
            handleGetProduct();
            break;
            
        default:
            sendResponse(false, null, 'کردارێکی نادروست', 400);
    }
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
}

/**
 * وەرگرتنی لیستی کاڵاکان
 */
function handleProductList() {
    global $conn, $userId;
    
    $category = $_GET['category'] ?? '';
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 75)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $includeZeroStock = shouldIncludeZeroStockProducts();
    $stockExistsSql = getProductStockExistsSql($includeZeroStock);
    
    $whereClause = "p.user_id = ?";
    $params = [$userId];
    $types = 'i';
    
    if (!empty($category) && is_numeric($category)) {
        $whereClause .= " AND p.category_id = ?";
        $params[] = $category;
        $types .= 'i';
    }
    
    // Get total count first (for distinct products)
    // Only include products that are not expired and have stock available
    // Only products with available stock in product_units
    $countQuery = "
        SELECT COUNT(DISTINCT p.id) as total
        FROM products p
        WHERE $whereClause
        AND $stockExistsSql
        AND (p.expiry_date IS NULL OR p.expiry_date >= CURDATE())
    ";
    
    $countStmt = $conn->prepare($countQuery);
    $countParams = [$userId];
    $countTypes = 'i';
    if (!empty($category) && is_numeric($category)) {
        $countParams[] = $category;
        $countTypes .= 'i';
    }
    $countStmt->bind_param($countTypes, ...$countParams);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    $query = "
        SELECT p.id, p.user_id, p.category_id, p.name, p.barcode, p.expiry_date, p.image_path, p.created_at, p.updated_at,
               c.name as category_name,
               COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as buy_price,
               COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
               COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
               COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
               COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
               COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) as min_stock,
               COALESCE(pu_primary.currency, pu_any.currency, 'IQD') as currency,
               pu.id as product_unit_id, pu.unit_id, pu.buy_price as unit_buy_price, 
               pu.sell_price as unit_sell_price, pu.wholesale_price as unit_wholesale_price,
               pu.special_price as unit_special_price, pu.stock_quantity as unit_stock_quantity,
               pu.min_stock as unit_min_stock, pu.currency as unit_currency, pu.is_primary as unit_is_primary,
               u.name as unit_name, u.symbol as unit_symbol,
               (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) as unit_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
        LEFT JOIN product_units pu_any ON pu_any.id = (
            SELECT pu2.id
            FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        LEFT JOIN product_units pu ON p.id = pu.product_id
        LEFT JOIN units u ON pu.unit_id = u.id
        WHERE $whereClause
        AND $stockExistsSql
        AND (p.expiry_date IS NULL OR p.expiry_date >= CURDATE())
        ORDER BY p.name ASC, pu.is_primary DESC, pu.id ASC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $rawProducts = $result->fetch_all(MYSQLI_ASSOC);
    
    // Group products by ID and collect units
    $products = [];
    $productUnits = [];
    
    foreach ($rawProducts as $row) {
        $productId = $row['id'];
        
        if (!isset($products[$productId])) {
            // First time seeing this product
            $products[$productId] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
                'name' => $row['name'],
                'barcode' => $row['barcode'],
                'expiry_date' => $row['expiry_date'],
                'buy_price' => (float)($row['buy_price'] ?? 0),
                'sell_price' => (float)($row['sell_price'] ?? 0),
                'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
                'special_price' => (float)($row['special_price'] ?? 0),
                'stock_quantity' => (float)($row['stock_quantity'] ?? 0),
                'min_stock' => (int)($row['min_stock'] ?? 0),
                'image_path' => $row['image_path'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'category_name' => $row['category_name'],
                'unit_count' => (int)$row['unit_count'],
                'units' => []
            ];
        }
        
        // Add unit to product
        if ($row['product_unit_id']) {
            $products[$productId]['units'][] = buildProductUnitFromRow($row);
        }
    }
    
    // Update product data based on units
    foreach ($products as $productId => &$product) {
        applyPrimaryUnitDefaults($product);
    }
    
    foreach ($products as &$product) {
        $product['image_url'] = product_image_url($product['image_path'] ?? null);
    }
    unset($product);

    // Convert to simple array
    $products = array_values($products);
    
    sendResponse(true, [
        'products' => $products, 
        'count' => count($products),
        'total' => (int)$totalCount,
        'hasMore' => ($offset + count($products) < $totalCount)
    ]);
}

/**
 * گەڕان لە کاڵاکان
 */
function handleProductSearch() {
    global $conn, $userId;
    
    $query = $_GET['q'] ?? '';
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
    /** POS: کاتێک ڕێکخستنی بەکارهێنەر نیشاندانی کاڵای سفر ڕێگە دەدات */
    $includeZeroStock = shouldIncludeZeroStockProducts();
    
    if (empty($query) || strlen($query) < 2) {
        sendResponse(true, ['products' => []]);
    }
    
    $searchTerm = '%' . $query . '%';
    $stockExistsSql = getProductStockExistsSql($includeZeroStock);

    $sql = "
        SELECT p.id, p.user_id, p.category_id, p.name, p.barcode, p.expiry_date, p.image_path, p.created_at, p.updated_at,
               c.name as category_name,
               COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as buy_price,
               COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
               COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
               COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
               COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
               COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) as min_stock,
               COALESCE(pu_primary.currency, pu_any.currency, 'IQD') as currency,
               pu.id as product_unit_id, pu.unit_id, pu.buy_price as unit_buy_price, 
               pu.sell_price as unit_sell_price, pu.wholesale_price as unit_wholesale_price,
               pu.special_price as unit_special_price, pu.stock_quantity as unit_stock_quantity,
               pu.min_stock as unit_min_stock, pu.currency as unit_currency, pu.is_primary as unit_is_primary,
               u.name as unit_name, u.symbol as unit_symbol,
               (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) as unit_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
        LEFT JOIN product_units pu_any ON pu_any.id = (
            SELECT pu2.id
            FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        LEFT JOIN product_units pu ON p.id = pu.product_id
        LEFT JOIN units u ON pu.unit_id = u.id
        WHERE p.user_id = ? AND (
            p.name LIKE ? OR 
            p.barcode LIKE ? OR 
            c.name LIKE ?
        )
        AND $stockExistsSql
        AND (p.expiry_date IS NULL OR p.expiry_date >= CURDATE())
        ORDER BY 
            CASE 
                WHEN p.name LIKE ? THEN 1 
                WHEN p.barcode LIKE ? THEN 2 
                ELSE 3 
            END,
            p.name ASC, pu.is_primary DESC, pu.id ASC
        LIMIT ?
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('products.php handleProductSearch prepare failed: ' . $conn->error);
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
        return;
    }
    $stmt->bind_param('isssssi', $userId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit);
    if (!$stmt->execute()) {
        logProductsApiStmtFailure('handleProductSearch', $stmt);
        $stmt->close();
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
        return;
    }

    $result = $stmt->get_result();
    $rawProducts = $result->fetch_all(MYSQLI_ASSOC);
    
    // Group products by ID and collect units
    $products = [];
    
    foreach ($rawProducts as $row) {
        $productId = $row['id'];
        
        if (!isset($products[$productId])) {
            // First time seeing this product
            $products[$productId] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
                'name' => $row['name'],
                'barcode' => $row['barcode'],
                'expiry_date' => $row['expiry_date'],
                'buy_price' => (float)($row['buy_price'] ?? 0),
                'sell_price' => (float)($row['sell_price'] ?? 0),
                'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
                'special_price' => (float)($row['special_price'] ?? 0),
                'stock_quantity' => (float)($row['stock_quantity'] ?? 0),
                'min_stock' => (int)($row['min_stock'] ?? 0),
                'image_path' => $row['image_path'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'category_name' => $row['category_name'],
                'unit_count' => (int)$row['unit_count'],
                'units' => []
            ];
        }
        
        // Add unit to product
        if ($row['product_unit_id']) {
            $products[$productId]['units'][] = buildProductUnitFromRow($row);
        }
    }
    
    // Update product data based on units
    foreach ($products as $productId => &$product) {
        applyPrimaryUnitDefaults($product);
    }

    foreach ($products as &$product) {
        $product['image_url'] = product_image_url($product['image_path'] ?? null);
    }
    unset($product);
    
    // Convert to simple array
    $products = array_values($products);
    
    sendResponse(true, ['products' => $products]);
}

/**
 * گەڕان بە بارکۆد
 */
function handleBarcodeSearch() {
    global $conn, $userId;
    
    $barcode = $_GET['code'] ?? '';
    
    if (empty($barcode)) {
        sendResponse(false, null, 'بارکۆد پێویستە');
    }
    
    // First, find the product_id by checking both products.barcode and product_barcodes.barcode
    $productId = null;
    
    // Check in products.barcode
    $checkStmt = $conn->prepare("SELECT id FROM products WHERE user_id = ? AND barcode = ?");
    if (!$checkStmt) {
        error_log('products.php handleBarcodeSearch check prepare failed: ' . $conn->error);
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
        return;
    }
    $checkStmt->bind_param('is', $userId, $barcode);
    if (!$checkStmt->execute()) {
        logProductsApiStmtFailure('handleBarcodeSearch.products_barcode', $checkStmt);
        $checkStmt->close();
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
        return;
    }
    $result = $checkStmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $productId = (int)$row['id'];
    }
    $checkStmt->close();
    
    // If not found, check in product_barcodes.barcode
    if (!$productId) {
        $checkStmt = $conn->prepare("SELECT pb.product_id FROM product_barcodes pb 
                                     INNER JOIN products p ON pb.product_id = p.id 
                                     WHERE p.user_id = ? AND pb.barcode = ?");
        if (!$checkStmt) {
            error_log('products.php handleBarcodeSearch alt prepare failed: ' . $conn->error);
            sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
            return;
        }
        $checkStmt->bind_param('is', $userId, $barcode);
        if (!$checkStmt->execute()) {
            logProductsApiStmtFailure('handleBarcodeSearch.product_barcodes', $checkStmt);
            $checkStmt->close();
            sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
            return;
        }
        $result = $checkStmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $productId = (int)$row['product_id'];
        }
        $checkStmt->close();
    }
    
    // If product not found, return error
    if (!$productId) {
        sendResponse(false, null, 'کاڵا بەم بارکۆدە نەدۆزرایەوە');
    }
    
    // وەرگرتنی کاڵا — هاوشێوەی handleGetProduct (پشتیوانی کاڵای بێ product_units)
    $stmt = $conn->prepare("
        SELECT p.id, p.user_id, p.category_id, p.name, p.barcode, p.expiry_date, p.image_path, p.created_at, p.updated_at,
               c.name as category_name,
               COALESCE(pu_primary.buy_price, pu_primary_fallback.buy_price, 0) as buy_price,
               COALESCE(pu_primary.sell_price, pu_primary_fallback.sell_price, 0) as sell_price,
               COALESCE(pu_primary.wholesale_price, pu_primary_fallback.wholesale_price, 0) as wholesale_price,
               COALESCE(pu_primary.special_price, pu_primary_fallback.special_price, 0) as special_price,
               COALESCE(pu_primary.stock_quantity, pu_primary_fallback.stock_quantity, 0) as stock_quantity,
               COALESCE(pu_primary.min_stock, pu_primary_fallback.min_stock, 0) as min_stock,
               COALESCE(pu_primary.currency, pu_primary_fallback.currency, 'IQD') as currency,
               pu.id as product_unit_id, pu.unit_id, pu.buy_price as unit_buy_price, 
               pu.sell_price as unit_sell_price, pu.wholesale_price as unit_wholesale_price,
               pu.special_price as unit_special_price, pu.stock_quantity as unit_stock_quantity,
               pu.min_stock as unit_min_stock, pu.currency as unit_currency, pu.is_primary as unit_is_primary,
               u.name as unit_name, u.symbol as unit_symbol,
               (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) as unit_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
        LEFT JOIN product_units pu_primary_fallback ON pu_primary_fallback.id = (
            SELECT pu2.id
            FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        LEFT JOIN product_units pu ON p.id = pu.product_id
        LEFT JOIN units u ON pu.unit_id = u.id
        WHERE p.user_id = ? AND p.id = ?
        ORDER BY pu.is_primary DESC, pu.id ASC
    ");
    
    if (!$stmt) {
        error_log('products.php handleBarcodeSearch load prepare failed: ' . $conn->error);
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
        return;
    }
    $stmt->bind_param('ii', $userId, $productId);
    if (!$stmt->execute()) {
        logProductsApiStmtFailure('handleBarcodeSearch.load', $stmt);
        $stmt->close();
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
        return;
    }

    $result = $stmt->get_result();
    $rawProducts = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (!empty($rawProducts)) {
        $row = $rawProducts[0];
        
        // Build product with units
        $product = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'expiry_date' => $row['expiry_date'],
            'buy_price' => (float)($row['buy_price'] ?? 0),
            'sell_price' => (float)($row['sell_price'] ?? 0),
            'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
            'special_price' => (float)($row['special_price'] ?? 0),
            'stock_quantity' => (float)($row['stock_quantity'] ?? 0),
            'min_stock' => (int)($row['min_stock'] ?? 0),
            'image_path' => $row['image_path'],
            'image_url' => product_image_url($row['image_path'] ?? null),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'category_name' => $row['category_name'],
            'unit_count' => (int)$row['unit_count'],
            'units' => []
        ];
        
        // Add all units for this product
        foreach ($rawProducts as $unitRow) {
            if ($unitRow['product_unit_id']) {
                $product['units'][] = buildProductUnitFromRow($unitRow);
            }
        }
        
        applyPrimaryUnitDefaults($product);
        
        // Check if product is available (not expired and has stock)
        $hasStock = false;
        foreach ($product['units'] as $unit) {
            if ($unit['stock_quantity'] > 0) {
                $hasStock = true;
                break;
            }
        }
        if (!$hasStock && $product['stock_quantity'] > 0) {
            $hasStock = true;
        }
        
        $isExpired = $product['expiry_date'] && strtotime($product['expiry_date']) <= time();
        
        // Add availability info to product
        $product['has_stock'] = $hasStock;
        $product['is_expired'] = $isExpired;
        $product['is_available'] = $hasStock && !$isExpired;
        
        sendResponse(true, ['product' => $product]);
    } else {
        sendResponse(false, null, 'کاڵا بەم بارکۆدە نەدۆزرایەوە');
    }
}

/**
 * زیادکردنی کاڵای نوێ
 */
function handleAddProduct() {
    global $conn, $userId, $currentUser;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, null, 'تەنها POST ڕێگەپێدراوە', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendResponse(false, null, 'داتای نادروست', 400);
    }
    
    // Validate required fields
    $required = ['name', 'sell_price'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            sendResponse(false, null, "خانەی $field پێویستە");
        }
    }
    
    // Prepare data
    $name = cleanInput($input['name']);
    $category_id = !empty($input['category_id']) ? (int)$input['category_id'] : null;
    $barcode = cleanInput($input['barcode'] ?? '');
    $expiry_date = !empty($input['expiry_date']) ? $input['expiry_date'] : null;
    $buy_price = (float)($input['buy_price'] ?? 0);
    $sell_price = (float)$input['sell_price'];
    $wholesale_price = (float)($input['wholesale_price'] ?? 0);
    $special_price = (float)($input['special_price'] ?? 0);
    $stock_quantity = (int)($input['stock_quantity'] ?? 0);
    $min_stock = (int)($input['min_stock'] ?? 5);
    $image_path = $input['image_path'] ?? null;
    
    // Generate barcode if not provided
    if (empty($barcode)) {
        $barcode = generateBarcode();
        
        // Ensure uniqueness
        $attempts = 0;
        do {
            $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND user_id = ?");
            $stmt->bind_param("si", $barcode, $userId);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            
            if ($exists) {
                $barcode = generateBarcode();
                $attempts++;
            }
        } while ($exists && $attempts < 10);
        
        if ($attempts >= 10) {
            sendResponse(false, null, 'نەتوانرا بارکۆدێکی یەکتا دروست بکرێت');
        }
    } else {
        // Check if barcode already exists
        $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND user_id = ?");
        $stmt->bind_param("si", $barcode, $userId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            sendResponse(false, null, 'ئەم بارکۆدە پێشتر بەکارهاتووە');
        }
        $stmt->close();
    }
    
    // Insert product (core fields only)
    $stmt = $conn->prepare("
        INSERT INTO products (
            user_id, category_id, name, barcode, expiry_date,
            image_path, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, NOW()
        )
    ");
    
    $stmt->bind_param(
        "iissss",
        $userId, $category_id, $name, $barcode, $expiry_date,
        $image_path
    );
    
    if ($stmt->execute()) {
        $productId = $conn->insert_id;

        // Create default primary unit row so pricing/stock remains available
        $defaultUnitId = !empty($input['unit_id']) ? (int)$input['unit_id'] : 1;
        $unitStmt = $conn->prepare("
            INSERT INTO product_units (
                product_id, unit_id, buy_price, sell_price, wholesale_price, special_price,
                currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary
            ) VALUES (?, ?, ?, ?, ?, ?, 'IQD', ?, ?, 1.0000, 1.000, 1)
        ");
        $unitStmt->bind_param(
            "iidddddi",
            $productId, $defaultUnitId, $buy_price, $sell_price, $wholesale_price, $special_price, $stock_quantity, $min_stock
        );
        $unitStmt->execute();
        
        // Log activity
        writeLog("Product added via API: $name (ID: $productId) by user: {$currentUser['email']}");
        
        sendResponse(true, ['product_id' => $productId], 'کاڵا بە سەرکەوتوویی زیادکرا');
    } else {
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە زیادکردنی کاڵا');
    }
}

/**
 * نوێکردنەوەی کاڵا
 */
function handleUpdateProduct() {
    global $conn, $userId;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendResponse(false, null, 'تەنها PUT ڕێگەپێدراوە', 405);
    }
    
    $productId = (int)($_GET['id'] ?? 0);
    if (!$productId) {
        sendResponse(false, null, 'ID ی کاڵا پێویستە');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendResponse(false, null, 'داتای نادروست', 400);
    }
    
    // Check if product belongs to user
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $productId, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        sendResponse(false, null, 'کاڵا نەدۆزرایەوە یان دەسەڵاتت نییە', 404);
    }
    $stmt->close();
    
    // خانەکانی سەر خشتەی products
    $allowedFields = ['name', 'category_id', 'barcode', 'expiry_date', 'image_path'];

    $updateFields = [];
    $params = [];
    $types = '';

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";

            switch ($field) {
                case 'category_id':
                    $params[] = (int)$input[$field];
                    $types .= 'i';
                    break;
                default:
                    $params[] = $input[$field];
                    $types .= 's';
            }
        }
    }

    // خانەکانی نرخ/بڕ کە لە خشتەی product_units پارێزراون (یەکەی سەرەکی/fallback)
    $unitUpdateParts = [];
    $unitParams = [];
    $unitTypes = '';

    if (isset($input['buy_price'])) { $unitUpdateParts[] = "buy_price = ?"; $unitParams[] = (float)$input['buy_price']; $unitTypes .= 'd'; }
    if (isset($input['sell_price'])) { $unitUpdateParts[] = "sell_price = ?"; $unitParams[] = (float)$input['sell_price']; $unitTypes .= 'd'; }
    if (isset($input['wholesale_price'])) { $unitUpdateParts[] = "wholesale_price = ?"; $unitParams[] = (float)$input['wholesale_price']; $unitTypes .= 'd'; }
    if (isset($input['special_price'])) { $unitUpdateParts[] = "special_price = ?"; $unitParams[] = (float)$input['special_price']; $unitTypes .= 'd'; }
    if (isset($input['stock_quantity'])) { $unitUpdateParts[] = "stock_quantity = ?"; $unitParams[] = (float)$input['stock_quantity']; $unitTypes .= 'd'; }
    if (isset($input['min_stock'])) { $unitUpdateParts[] = "min_stock = ?"; $unitParams[] = (int)$input['min_stock']; $unitTypes .= 'i'; }

    if (empty($updateFields) && empty($unitUpdateParts)) {
        sendResponse(false, null, 'هیچ خانەیەک بۆ نوێکردنەوە دیارینەکراوە');
    }

    // نوێکردنەوەی خشتەی products (ئەگەر خانەی گونجاو هەبوو)
    if (!empty($updateFields)) {
        $productParams = $params;
        $productTypes = $types;
        $productParams[] = $productId;
        $productParams[] = $userId;
        $productTypes .= 'ii';

        $sql = "UPDATE products SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($productTypes, ...$productParams);
        if (!$stmt->execute()) {
            sendResponse(false, null, 'هەڵەیەک ڕوویدا لە نوێکردنەوەی کاڵا');
        }
    }

    // نوێکردنەوەی خشتەی product_units (ئەگەر نرخ/بڕ گۆڕدرابوو)
    if (!empty($unitUpdateParts)) {
        $unitSql = "UPDATE product_units SET " . implode(', ', $unitUpdateParts) . ", updated_at = NOW() WHERE id = (SELECT pux.id FROM (SELECT pu.id FROM product_units pu WHERE pu.product_id = ? ORDER BY pu.is_primary DESC, pu.id ASC LIMIT 1) AS pux)";
        $unitParams[] = $productId;
        $unitTypes .= 'i';
        $unitStmt = $conn->prepare($unitSql);
        $unitStmt->bind_param($unitTypes, ...$unitParams);
        if (!$unitStmt->execute()) {
            sendResponse(false, null, 'هەڵەیەک ڕوویدا لە نوێکردنەوەی بەردەست');
        }
    }

    sendResponse(true, null, 'کاڵا بە سەرکەوتوویی نوێکرایەوە');
}

/**
 * سڕینەوەی کاڵا
 */
function handleDeleteProduct() {
    global $conn, $userId, $currentUser;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        sendResponse(false, null, 'تەنها DELETE ڕێگەپێدراوە', 405);
    }
    
    $productId = (int)($_GET['id'] ?? 0);
    if (!$productId) {
        sendResponse(false, null, 'ID ی کاڵا پێویستە');
    }
    
    // Check if product belongs to user and get product info
    $stmt = $conn->prepare("SELECT name, image_path FROM products WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $productId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($product = $result->fetch_assoc()) {
        // Delete the product
        $deleteStmt = $conn->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
        $deleteStmt->bind_param("ii", $productId, $userId);
        
        if ($deleteStmt->execute()) {
            if ($product['image_path']) {
                delete_product_image_from_spaces($product['image_path']);
            }
            
            // Log activity
            writeLog("Product deleted via API: {$product['name']} (ID: $productId) by user: {$currentUser['email']}");
            
            sendResponse(true, null, 'کاڵا بە سەرکەوتوویی سڕایەوە');
        } else {
            sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سڕینەوەی کاڵا');
        }
    } else {
        sendResponse(false, null, 'کاڵا نەدۆزرایەوە یان دەسەڵاتت نییە', 404);
    }
}

/**
 * کاڵاکانی کەتەلۆگ
 */
function handleCategoryProducts() {
    global $conn, $userId;
    
    $categoryId = (int)($_GET['category_id'] ?? 0);
    $includeZeroStock = shouldIncludeZeroStockProducts();
    $stockClause = getProductStockExistsSql($includeZeroStock);
    if (!$categoryId) {
        sendResponse(false, null, 'ID ی کەتەلۆگ پێویستە');
    }
    
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name,
               COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
        LEFT JOIN product_units pu_any ON pu_any.id = (
            SELECT pu2.id FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        WHERE p.user_id = ? AND p.category_id = ?
        AND $stockClause
        AND (p.expiry_date IS NULL OR p.expiry_date >= CURDATE())
        ORDER BY p.name ASC
    ");
    
    $stmt->bind_param('ii', $userId, $categoryId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    sendResponse(true, ['products' => $products]);
}

/**
 * کاڵا کەمەکان
 */
function handleLowStockProducts() {
    global $conn, $userId;
    
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name,
               COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
               COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) as min_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
        LEFT JOIN product_units pu_any ON pu_any.id = (
            SELECT pu2.id FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        WHERE p.user_id = ? AND COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) <= COALESCE(pu_primary.min_stock, pu_any.min_stock, 0)
        ORDER BY COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) ASC, p.name ASC
    ");
    
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    sendResponse(true, ['products' => $products]);
}

/**
 * کاڵا بەسەرچووەکان
 */
function handleExpiredProducts() {
    global $conn, $userId;
    
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.user_id = ? AND p.expiry_date IS NOT NULL AND p.expiry_date <= CURDATE()
        ORDER BY p.expiry_date ASC, p.name ASC
    ");
    
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    sendResponse(true, ['products' => $products]);
}

/**
 * وەرگرتنی کاڵایەک بە ID
 */
function handleGetProduct() {
    global $conn, $userId;
    
    $productId = (int)($_GET['id'] ?? 0);
    if (!$productId) {
        sendResponse(false, null, 'ID ی کاڵا پێویستە');
    }
    
    $stmt = $conn->prepare("
        SELECT p.id, p.user_id, p.category_id, p.name, p.barcode, p.expiry_date, p.image_path, p.created_at, p.updated_at,
               c.name as category_name,
               COALESCE(pu_primary.buy_price, pu_primary_fallback.buy_price, 0) as buy_price,
               COALESCE(pu_primary.sell_price, pu_primary_fallback.sell_price, 0) as sell_price,
               COALESCE(pu_primary.wholesale_price, pu_primary_fallback.wholesale_price, 0) as wholesale_price,
               COALESCE(pu_primary.special_price, pu_primary_fallback.special_price, 0) as special_price,
               COALESCE(pu_primary.stock_quantity, pu_primary_fallback.stock_quantity, 0) as stock_quantity,
               COALESCE(pu_primary.min_stock, pu_primary_fallback.min_stock, 0) as min_stock,
               COALESCE(pu_primary.currency, pu_primary_fallback.currency, 'IQD') as currency,
               pu.id as product_unit_id, pu.unit_id, pu.buy_price as unit_buy_price, 
               pu.sell_price as unit_sell_price, pu.wholesale_price as unit_wholesale_price,
               pu.special_price as unit_special_price, pu.stock_quantity as unit_stock_quantity,
               pu.min_stock as unit_min_stock, pu.currency as unit_currency, pu.is_primary as unit_is_primary,
               u.name as unit_name, u.symbol as unit_symbol,
               (SELECT COUNT(*) FROM product_units WHERE product_id = p.id) as unit_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
        LEFT JOIN product_units pu_primary_fallback ON pu_primary_fallback.id = (
            SELECT pu2.id
            FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        LEFT JOIN product_units pu ON p.id = pu.product_id
        LEFT JOIN units u ON pu.unit_id = u.id
        WHERE p.user_id = ? AND p.id = ?
        ORDER BY pu.is_primary DESC, pu.id ASC
    ");
    
    $stmt->bind_param('ii', $userId, $productId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $rawProducts = $result->fetch_all(MYSQLI_ASSOC);
    
    if (!empty($rawProducts)) {
        $row = $rawProducts[0];
        
        // Build product with units
        $product = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'expiry_date' => $row['expiry_date'],
            'buy_price' => (float)($row['buy_price'] ?? 0),
            'sell_price' => (float)($row['sell_price'] ?? 0),
            'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
            'special_price' => (float)($row['special_price'] ?? 0),
            'stock_quantity' => (float)($row['stock_quantity'] ?? 0),
            'min_stock' => (int)($row['min_stock'] ?? 0),
            'image_path' => $row['image_path'],
            'image_url' => product_image_url($row['image_path'] ?? null),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'category_name' => $row['category_name'],
            'unit_count' => (int)$row['unit_count'],
            'units' => []
        ];
        
        // Add all units for this product
        foreach ($rawProducts as $unitRow) {
            if ($unitRow['product_unit_id']) {
                $product['units'][] = buildProductUnitFromRow($unitRow);
            }
        }
        
        applyPrimaryUnitDefaults($product);
        
        sendResponse(true, ['product' => $product]);
    } else {
        sendResponse(false, null, 'کاڵا نەدۆزرایەوە');
    }
}

?>