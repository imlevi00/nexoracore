<?php
/**
 * API فرۆشتنەکان - api/sales.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate'); // ڕێگریکردن لە کاشکردن
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/security.php';
require_once '../includes/permissions.php';
require_once '../includes/product_change_logger.php';
require_once '../includes/customer_change_logger.php';
require_once '../includes/profit_schema.php';
require_once '../includes/wallet_service.php';
require_once '../includes/sale_return_service.php';
require_once '../user/telegram/telegram_helper.php';

// Function to send JSON response
function sendResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('c')
    ]);
    exit();
}

// Check if user is authenticated
if (!isUser()) {
    sendResponse(false, null, 'غیر مجاز - داخڵبوون پێویستە', 401);
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// کارمەندی سنووردار: ئەگەر sub_user بێت و sales.view_all ـی نەبێت، تەنها sub_user_id ی خۆی
// (بۆ پاراستنی هەموو endpoint ـەکان لە دەستگەیشتن بە فرۆشتنی هاوکار بە ID ی ڕاستەوخۆ)
$ownOnlySubUserId = getSalesOwnOnlySubUserId($currentUser);

$action = $_GET['action'] ?? '';
$actionPermissionMap = [
    'create' => 'pos.create',
    'list' => 'pos.view',
    'get' => 'pos.view',
    'delete' => 'pos.delete',
    'update' => 'pos.update',
    'daily_stats' => 'reports.view',
    'monthly_stats' => 'reports.view',
    'receipt' => 'pos.view',
    'return_context' => 'returns.create',
    'sale_cost_breakdown' => 'profits.view'
];

enforceAuthorizationOrDeny($currentUser, $actionPermissionMap[$action] ?? 'pos.view', [
    'route' => '/api/sales.php?action=' . $action,
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'json');

// Function to ensure currency columns exist
function ensureCurrencyColumns($conn) {
    // Check and add currency column to sales table
    $result = $conn->query("SHOW COLUMNS FROM sales LIKE 'currency'");
    if (!$result || $result->num_rows == 0) {
        $conn->query("ALTER TABLE `sales` ADD COLUMN `currency` ENUM('IQD', 'USD') NOT NULL DEFAULT 'IQD' AFTER `final_amount`");
    }
    if ($result) {
        $result->free();
    }
    
    // Check and add currency column to sale_items table
    $result = $conn->query("SHOW COLUMNS FROM sale_items LIKE 'currency'");
    if (!$result || $result->num_rows == 0) {
        $conn->query("ALTER TABLE `sale_items` ADD COLUMN `currency` ENUM('IQD', 'USD') NOT NULL DEFAULT 'IQD' AFTER `total_price`");
    }
    if ($result) {
        $result->free();
    }
}

function ensureSalesNotesColumn($conn) {
    $result = $conn->query("SHOW COLUMNS FROM sales LIKE 'notes'");
    if (!$result || $result->num_rows == 0) {
        $conn->query("ALTER TABLE `sales` ADD COLUMN `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `wallet_id`");
    }
    if ($result) {
        $result->free();
    }
}

/**
 * هەڵبژاردنی payment_status بەشێوەیەک کە لە enum ـی واقعی دیتابەیس وەربگیرێت
 * بۆ پێشگیری لە "Data truncated for column 'payment_status'".
 */
function resolveSalePaymentStatus($conn, $preferredStatus) {
    static $supportedStatuses = null;

    if ($supportedStatuses === null) {
        $supportedStatuses = ['paid', 'pending', 'partial'];
        $stmt = $conn->prepare("
            SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sales'
              AND COLUMN_NAME = 'payment_status'
            LIMIT 1
        ");

        if ($stmt) {
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && ($row = $result->fetch_assoc()) && !empty($row['COLUMN_TYPE'])) {
                    preg_match_all("/'([^']+)'/", (string)$row['COLUMN_TYPE'], $matches);
                    if (!empty($matches[1])) {
                        $supportedStatuses = $matches[1];
                    }
                }
                if ($result) {
                    $result->free();
                }
            }
            $stmt->close();
        }
    }

    if (in_array($preferredStatus, $supportedStatuses, true)) {
        return $preferredStatus;
    }

    if ($preferredStatus === 'partial' && in_array('pending', $supportedStatuses, true)) {
        return 'pending';
    }

    if (in_array('paid', $supportedStatuses, true)) {
        return 'paid';
    }

    return $supportedStatuses[0] ?? 'paid';
}

// Ensure currency columns exist
try {
    ensureCurrencyColumns($conn);
} catch (Exception $e) {
    // Log error but continue - migration might have already been run
    error_log('Currency migration check failed: ' . $e->getMessage());
}

try {
    ensureProfitSnapshotColumns($conn);
} catch (Exception $e) {
    error_log('Profit schema migration check failed: ' . $e->getMessage());
}

try {
    ensureSalesNotesColumn($conn);
} catch (Exception $e) {
    error_log('Sales notes migration check failed: ' . $e->getMessage());
}

function ensureSyncTables($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS sync_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            client_txn_id VARCHAR(128) NOT NULL,
            device_id VARCHAR(128) DEFAULT NULL,
            payload_hash VARCHAR(64) DEFAULT NULL,
            status ENUM('synced', 'duplicate', 'conflict', 'failed') NOT NULL DEFAULT 'synced',
            sale_id INT DEFAULT NULL,
            response_json JSON DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_sync_user_txn (user_id, client_txn_id),
            KEY idx_sync_status (user_id, status),
            KEY idx_sync_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS sync_conflicts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            client_txn_id VARCHAR(128) NOT NULL,
            device_id VARCHAR(128) DEFAULT NULL,
            conflict_type VARCHAR(64) NOT NULL,
            conflict_message TEXT,
            payload_json JSON DEFAULT NULL,
            status ENUM('needs_review', 'resolved', 'rejected') NOT NULL DEFAULT 'needs_review',
            resolution_note TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_conflict_user_status (user_id, status),
            KEY idx_conflict_txn (user_id, client_txn_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

try {
    ensureSyncTables($conn);
} catch (Exception $e) {
    error_log('Sync tables ensure failed: ' . $e->getMessage());
}

/**
 * بەرواری فرۆشتن لە داتای داواکارەوە دەخاتە شێوەی datetime ـی دروست؛ ئەگەر بەتاڵ یان نادروست بێت کاتی ئێستا بەکاردەهێنێت.
 */
function normalizeSaleDateFromRequest($raw) {
    if ($raw === null) {
        return date('Y-m-d H:i:s');
    }
    if (is_string($raw)) {
        $raw = trim($raw);
    }
    if ($raw === '' || $raw === false) {
        return date('Y-m-d H:i:s');
    }
    if (!is_string($raw)) {
        return date('Y-m-d H:i:s');
    }
    $raw = str_replace('T', ' ', $raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw . ' 00:00:00';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d H:i:s');
    }
    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }
    return date('Y-m-d H:i:s');
}

function normalizeAndAggregateSaleItems($items, $defaultCurrency = 'IQD') {
    $normalized = [];
    if (!is_array($items)) {
        return $normalized;
    }

    foreach ($items as $lineIndex => $item) {
        if (!is_array($item)) {
            continue;
        }

        $isExternal = !empty($item['isExternal']) ||
            (isset($item['product_id']) && ($item['product_id'] === null || $item['product_id'] === '' || (int)$item['product_id'] === 0));
        $productId = $isExternal ? null : (int)($item['product_id'] ?? 0);
        if (!$isExternal && $productId <= 0) {
            continue;
        }

        $productName = cleanInput($item['product_name'] ?? '');
        $quantity = (float)($item['quantity'] ?? 0);
        $unitPrice = (float)($item['unit_price'] ?? 0);
        $lineTotal = isset($item['total_price']) ? (float)$item['total_price'] : ($quantity * $unitPrice);
        $priceType = cleanInput($item['price_type'] ?? 'retail');
        $itemCurrency = !empty($item['currency']) && in_array($item['currency'], ['USD', 'IQD'], true) ? $item['currency'] : $defaultCurrency;
        $unitId = (isset($item['unit_id']) && $item['unit_id'] !== '') ? (int)$item['unit_id'] : null;
        $unitName = cleanInput($item['unit_name'] ?? 'دانە');
        $unitSymbol = cleanInput($item['unit_symbol'] ?? '');
        $costPrice = null;
        if (isset($item['cost_price']) && $item['cost_price'] !== '' && $item['cost_price'] !== null) {
            $costPrice = (float)$item['cost_price'];
        } elseif (isset($item['buy_price']) && $item['buy_price'] !== '' && $item['buy_price'] !== null) {
            $costPrice = (float)$item['buy_price'];
        }

        if ($quantity <= 0 || $productName === '') {
            continue;
        }

        // کاڵای دەرەکی: هەر هێڵێک بە جیا — کۆکردنەوە تەنها بۆ کاڵای ناوخۆیی
        if ($isExternal) {
            $mergeKey = 'ext_line|' . $lineIndex;
        } else {
            $mergeKey = implode('|', [
                'int',
                (string)$productId,
                $unitId === null ? 'null' : (string)$unitId,
                number_format($unitPrice, 6, '.', ''),
                $priceType,
                $itemCurrency
            ]);
        }

        $saleItemId = !empty($item['sale_item_id']) ? (int)$item['sale_item_id'] : null;

        if (!isset($normalized[$mergeKey])) {
            $normalized[$mergeKey] = [
                'isExternal' => $isExternal,
                'product_id' => $productId,
                'product_name' => $productName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
                'price_type' => $priceType,
                'currency' => $itemCurrency,
                'unit_id' => $unitId,
                'unit_name' => $unitName,
                'unit_symbol' => $unitSymbol,
                'cost_price' => $costPrice,
                'sale_item_id' => $saleItemId,
            ];
            continue;
        }

        $normalized[$mergeKey]['quantity'] += $quantity;
        $normalized[$mergeKey]['total_price'] += $lineTotal;
        if ($normalized[$mergeKey]['cost_price'] !== null && $costPrice !== null) {
            $prevQty = max(0.000001, $normalized[$mergeKey]['quantity'] - $quantity);
            $weighted = (($normalized[$mergeKey]['cost_price'] * $prevQty) + ($costPrice * $quantity)) / max(0.000001, $normalized[$mergeKey]['quantity']);
            $normalized[$mergeKey]['cost_price'] = $weighted;
        } elseif ($normalized[$mergeKey]['cost_price'] === null && $costPrice !== null) {
            $normalized[$mergeKey]['cost_price'] = $costPrice;
        }
    }

    return array_values($normalized);
}

function resolveSaleItemUnitCostAtSale($conn, $userId, $productId, $unitId = null, $saleCurrency = 'IQD') {
    $productId = (int)$productId;
    if ($productId <= 0) {
        return null;
    }
    $saleCurrency = in_array($saleCurrency, ['USD', 'IQD'], true) ? $saleCurrency : 'IQD';

    $buyPrice = null;
    $unitCurrency = 'IQD';

    if ($unitId !== null) {
        $unitId = (int)$unitId;
        $stmt = $conn->prepare("
            SELECT pu.buy_price, COALESCE(pu.currency, 'IQD') AS currency
            FROM product_units pu
            INNER JOIN products p ON p.id = pu.product_id
            WHERE pu.product_id = ? AND pu.unit_id = ? AND p.user_id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("iii", $productId, $unitId, $userId);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && $row['buy_price'] !== null) {
                    $buyPrice = (float)$row['buy_price'];
                    $unitCurrency = strtoupper((string)$row['currency']);
                }
            } else {
                $stmt->close();
            }
        }
    }

    if ($buyPrice === null) {
        $fallbackStmt = $conn->prepare("
            SELECT pu.buy_price, COALESCE(pu.currency, 'IQD') AS currency
            FROM product_units pu
            INNER JOIN products p ON p.id = pu.product_id
            WHERE pu.product_id = ? AND p.user_id = ?
            ORDER BY pu.is_primary DESC, pu.id ASC
            LIMIT 1
        ");
        if (!$fallbackStmt) {
            return null;
        }

        $fallbackStmt->bind_param("ii", $productId, $userId);
        if (!$fallbackStmt->execute()) {
            $fallbackStmt->close();
            return null;
        }

        $row = $fallbackStmt->get_result()->fetch_assoc();
        $fallbackStmt->close();
        if ($row && $row['buy_price'] !== null) {
            $buyPrice = (float)$row['buy_price'];
            $unitCurrency = strtoupper((string)$row['currency']);
        }
    }

    if ($buyPrice === null) {
        return null;
    }

    // گۆڕینی تێچووی کاڵا بۆ دراوی فرۆشتن. دینار و دۆلار لە ڕاپۆرتدا تێکەڵ ناکرێن،
    // بۆیە پێویستە تێچوو بە هەمان دراوی فرۆشتن هەڵبگیرێت — وەکو نرخی فرۆشتنیش
    // لە resolveCanonicalSaleUnitPrice دەگۆڕدرێت.
    $unitCurrency = ($unitCurrency === 'USD') ? 'USD' : 'IQD';
    if ($buyPrice <= 0 || $unitCurrency === $saleCurrency || !function_exists('getExchangeRate')) {
        return $buyPrice;
    }
    $rate = getExchangeRate($userId, 'USD', 'IQD');
    if ($rate === false || $rate <= 0) {
        return $buyPrice;
    }
    if ($unitCurrency === 'USD' && $saleCurrency === 'IQD') {
        return round($buyPrice * $rate);
    }
    if ($unitCurrency === 'IQD' && $saleCurrency === 'USD') {
        return round($buyPrice / $rate, 2);
    }
    return $buyPrice;
}

/**
 * نرخی فرۆشتن لە product_units بەپێی price_type، گەڕاندنەوە لە دراوی فرۆشتندا.
 */
function resolveCanonicalSaleUnitPrice($conn, $userId, $productId, $unitId = null, $priceType = 'retail', $saleCurrency = 'IQD') {
    $productId = (int)$productId;
    $userId = (int)$userId;
    if ($productId <= 0) {
        return null;
    }

    $priceType = in_array($priceType, ['retail', 'wholesale', 'special'], true) ? $priceType : 'retail';
    $saleCurrency = in_array($saleCurrency, ['USD', 'IQD'], true) ? $saleCurrency : 'IQD';

    $row = null;
    if ($unitId !== null && (int)$unitId > 0) {
        $unitId = (int)$unitId;
        $stmt = $conn->prepare("
            SELECT pu.sell_price, pu.wholesale_price, pu.special_price, COALESCE(pu.currency, 'IQD') AS currency
            FROM product_units pu
            INNER JOIN products p ON p.id = pu.product_id
            WHERE pu.product_id = ? AND pu.unit_id = ? AND p.user_id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('iii', $productId, $unitId, $userId);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
            }
            $stmt->close();
        }
    }

    if (!$row) {
        $fallbackStmt = $conn->prepare("
            SELECT pu.sell_price, pu.wholesale_price, pu.special_price, COALESCE(pu.currency, 'IQD') AS currency
            FROM product_units pu
            INNER JOIN products p ON p.id = pu.product_id
            WHERE pu.product_id = ? AND p.user_id = ?
            ORDER BY pu.is_primary DESC, pu.id ASC
            LIMIT 1
        ");
        if (!$fallbackStmt) {
            return null;
        }
        $fallbackStmt->bind_param('ii', $productId, $userId);
        if (!$fallbackStmt->execute()) {
            $fallbackStmt->close();
            return null;
        }
        $row = $fallbackStmt->get_result()->fetch_assoc();
        $fallbackStmt->close();
    }

    if (!$row) {
        return null;
    }

    $sell = (float)($row['sell_price'] ?? 0);
    $wholesale = (float)($row['wholesale_price'] ?? 0);
    $special = (float)($row['special_price'] ?? 0);
    $basePrice = $sell;
    if ($priceType === 'wholesale' && $wholesale > 0) {
        $basePrice = $wholesale;
    } elseif ($priceType === 'special' && $special > 0) {
        $basePrice = $special;
    }

    if ($basePrice <= 0) {
        return null;
    }

    $productCurrency = strtoupper((string)($row['currency'] ?? 'IQD'));
    if ($productCurrency === $saleCurrency) {
        return $saleCurrency === 'USD' ? round($basePrice, 2) : round($basePrice);
    }

    if (!function_exists('getExchangeRate')) {
        return null;
    }

    $rate = getExchangeRate($userId, 'USD', 'IQD');
    if ($rate === false || $rate <= 0) {
        return null;
    }

    if ($productCurrency === 'USD' && $saleCurrency === 'IQD') {
        $converted = $basePrice * $rate;
        return round($converted);
    }
    if ($productCurrency === 'IQD' && $saleCurrency === 'USD') {
        $converted = $basePrice / $rate;
        return round($converted, 2);
    }

    return null;
}

/**
 * نرخی نێردراو لە POS دەپارێزرێت؛ canonical تەنها fallback ـە کاتێک unit_price نەبوو/سفرە.
 * جیاوازی لە نرخی DB تەنها بۆ audit log دەتۆمارێت، بەبێ گۆڕینی نرخ.
 */
function applyCanonicalPricesToSaleItems($conn, $userId, array &$items, $saleCurrency = 'IQD') {
    foreach ($items as &$item) {
        if (!is_array($item) || !empty($item['isExternal'])) {
            continue;
        }
        $productId = (int)($item['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $unitId = isset($item['unit_id']) && $item['unit_id'] !== '' && $item['unit_id'] !== null
            ? (int)$item['unit_id']
            : null;
        $priceType = cleanInput($item['price_type'] ?? 'retail');
        $clientPrice = (float)($item['unit_price'] ?? 0);
        $quantity = (float)($item['quantity'] ?? 0);
        if ($quantity <= 0) {
            continue;
        }

        $canonical = resolveCanonicalSaleUnitPrice($conn, $userId, $productId, $unitId, $priceType, $saleCurrency);

        if ($clientPrice <= 0 && $canonical !== null) {
            $item['unit_price'] = $canonical;
            $item['total_price'] = $quantity * $canonical;
            continue;
        }

        if ($clientPrice > 0 && $canonical !== null) {
            $tolerance = ($saleCurrency === 'USD') ? 0.02 : 1.0;
            if (abs($clientPrice - $canonical) > $tolerance) {
                error_log(
                    'POS sale price override (audit): product_id=' . $productId
                    . ' client=' . $clientPrice
                    . ' canonical=' . $canonical
                );
            }
        }
    }
    unset($item);
}

function calculateCanonicalTotalsFromItems($items, $discount = 0.0) {
    $totalAmount = 0.0;
    foreach ($items as $item) {
        $totalAmount += (float)($item['total_price'] ?? 0);
    }
    $discount = max(0.0, (float)$discount);
    $finalAmount = max(0.0, $totalAmount - $discount);
    return [
        'total_amount' => $totalAmount,
        'discount' => $discount,
        'final_amount' => $finalAmount
    ];
}

function collectProductIdsFromSaleItemsForLogs($items) {
    $ids = [];
    if (!is_array($items)) {
        return $ids;
    }
    foreach ($items as $item) {
        $pid = isset($item['product_id']) ? (int)$item['product_id'] : 0;
        if ($pid > 0) {
            $ids[$pid] = $pid;
        }
    }
    return array_values($ids);
}

function reserveOrLoadTxnState($conn, $userId, $clientTxnId, $deviceId, $payloadHash = null) {
    if ($clientTxnId === '') {
        return null;
    }

    $insertStmt = $conn->prepare("
        INSERT INTO sync_transactions (user_id, client_txn_id, device_id, payload_hash, status, sale_id, response_json)
        VALUES (?, ?, ?, ?, 'failed', NULL, NULL)
        ON DUPLICATE KEY UPDATE
            device_id = VALUES(device_id),
            payload_hash = VALUES(payload_hash),
            updated_at = CURRENT_TIMESTAMP
    ");
    $insertStmt->bind_param("isss", $userId, $clientTxnId, $deviceId, $payloadHash);
    if (!$insertStmt->execute()) {
        throw new Exception('txn_reserve_failed');
    }
    $insertStmt->close();

    $selectStmt = $conn->prepare("
        SELECT status, sale_id, response_json
        FROM sync_transactions
        WHERE user_id = ? AND client_txn_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $selectStmt->bind_param("is", $userId, $clientTxnId);
    $selectStmt->execute();
    $row = $selectStmt->get_result()->fetch_assoc();
    $selectStmt->close();

    return $row ?: null;
}

// Get action from request
$action = $_GET['action'] ?? '';

// If no action specified, determine from method
if (!$action) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check if it's a delete, update, or create request with POST method
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['sale_id']) && isset($input['items'])) {
            $action = 'update';
        } elseif (isset($input['id']) && !isset($input['items'])) {
            $action = 'delete';
        } else {
            $action = 'create';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $action = 'delete';
    }
}

$salesReadActions = ['list', 'get', 'daily_stats', 'monthly_stats', 'receipt', 'return_context', 'sale_cost_breakdown'];
if (in_array($action, $salesReadActions, true)) {
    SessionManager::releaseSessionLockForParallelReads();
}

try {
    switch ($action) {
        case 'create':
            handleCreateSale();
            break;
            
        case 'list':
            handleSalesList();
            break;
            
        case 'get':
            handleGetSale();
            break;
            
        case 'delete':
            handleDeleteSale();
            break;
            
        case 'update':
            handleUpdateSale();
            break;
            
        case 'daily_stats':
            handleDailyStats();
            break;
            
        case 'monthly_stats':
            handleMonthlyStats();
            break;
            
        case 'receipt':
            handleGetReceipt();
            break;

        case 'return_context':
            handleSaleReturnContext();
            break;

        case 'sale_cost_breakdown':
            handleSaleCostBreakdown();
            break;
            
        default:
            sendResponse(false, null, 'کردارێکی نادروست', 400);
    }
} catch (Exception $e) {
    error_log('Sales API Error: ' . $e->getMessage());
    sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
}

/**
 * دروستکردنی فرۆشتنی نوێ
 */
function handleCreateSale() {
    global $conn, $userId, $currentUser;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, null, 'تەنها POST ڕێگەپێدراوە', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Debug: Log input data
    error_log('=== SALES API DEBUG ===');
    error_log('Received sale data: ' . json_encode($input));
    error_log('Payment method: ' . ($input['payment_method'] ?? 'NOT SET'));
    error_log('Customer ID: ' . ($input['customer_id'] ?? 'NOT SET'));
    error_log('Customer name: ' . ($input['customer_name'] ?? 'NOT SET'));
    error_log('========================');
    
    if (!$input) {
        error_log('Error: Invalid JSON input');
        sendResponse(false, null, 'داتای نادروست', 400);
    }
    
    // Validate CSRF token
    if (!Security::validateCSRFToken($input['csrf_token'] ?? '')) {
        sendResponse(false, null, 'نادروستی ئامنیەتی', 403);
    }
    
    // Validate required fields
    if (empty($input['items']) || !is_array($input['items'])) {
        sendResponse(false, null, 'ئایتمەکانی فرۆشتن پێویستە');
    }
    
    // Prepare sale data
    $customer_id = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
    $customer_name = cleanInput($input['customer_name'] ?? '');
    $payment_method = cleanInput($input['payment_method'] ?? 'cash');
    $wallet_id = isset($input['wallet_id']) ? (int)$input['wallet_id'] : 0;
    $discount = (float)($input['discount'] ?? 0);
    $paid_amount = (float)($input['paid_amount'] ?? 0);
    $debt_payment_date = $input['debt_payment_date'] ?? null;
    $sale_date = normalizeSaleDateFromRequest($input['sale_date'] ?? null);
    $clientTxnId = isset($input['client_txn_id']) ? trim((string)$input['client_txn_id']) : '';
    $deviceId = isset($input['device_id']) ? trim((string)$input['device_id']) : '';
    $notes = cleanInput($input['notes'] ?? '');
    if (function_exists('mb_substr')) {
        $notes = mb_substr($notes, 0, 500, 'UTF-8');
    } else {
        $notes = substr($notes, 0, 500);
    }
    if ($notes === '') {
        $notes = null;
    }
    // Get currency from input or default to IQD
    $saleCurrency = !empty($input['currency']) && in_array($input['currency'], ['USD', 'IQD']) ? $input['currency'] : 'IQD';
    $items = normalizeAndAggregateSaleItems($input['items'], $saleCurrency);
    applyCanonicalPricesToSaleItems($conn, $userId, $items, $saleCurrency);
    $totals = calculateCanonicalTotalsFromItems($items, $discount);
    $total_amount = $totals['total_amount'];
    $final_amount = $totals['final_amount'];
    if ($final_amount <= 0) {
        sendResponse(false, null, 'بڕی کۆتایی پێویستە');
    }
    
    // If customer_id is provided, get customer info
    if ($customer_id) {
        $customerStmt = $conn->prepare("SELECT name, phone FROM customers WHERE id = ? AND user_id = ?");
        $customerStmt->bind_param("ii", $customer_id, $userId);
        $customerStmt->execute();
        $customerResult = $customerStmt->get_result();
        
        if ($customerData = $customerResult->fetch_assoc()) {
            $customer_name = $customerData['name'];
        }
        $customerStmt->close();
    }
    
    // Validate payment method
    $allowedPaymentMethods = ['cash', 'credit', 'debt', 'installment'];
    if (!in_array($payment_method, $allowedPaymentMethods)) {
        sendResponse(false, null, 'شێوەی پارەدان نادروستە');
    }
    if ($payment_method === 'cash' && $wallet_id <= 0) {
        sendResponse(false, null, 'بۆ فرۆشتنی نەقد، قاسە پێویستە', 422);
    }
    
    // Determine payment status and amounts based on payment method
    $desired_payment_status = 'paid';
    $remaining_amount = 0;
    
    if ($payment_method === 'debt') {
        $desired_payment_status = 'pending';
        $remaining_amount = $final_amount - $paid_amount;
    } elseif ($payment_method === 'installment') {
        $desired_payment_status = 'partial';
        $remaining_amount = $final_amount - $paid_amount;
    } elseif ($payment_method === 'cash') {
        $desired_payment_status = 'paid';
        $paid_amount = $final_amount;
        $remaining_amount = 0;
    }
    $payment_status = resolveSalePaymentStatus($conn, $desired_payment_status);
    if ($payment_status !== $desired_payment_status) {
        error_log('payment_status fallback applied in sales create: ' . $desired_payment_status . ' => ' . $payment_status);
    }
    
    // Generate invoice number
    $invoice_number = generateInvoiceNumber();
    
    // Ensure invoice number is unique
    $attempts = 0;
    do {
        $stmt = $conn->prepare("SELECT id FROM sales WHERE invoice_number = ? AND user_id = ?");
        $stmt->bind_param("si", $invoice_number, $userId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        
        if ($exists) {
            $invoice_number = generateInvoiceNumber();
            $attempts++;
        }
    } while ($exists && $attempts < 10);
    
    if ($attempts >= 10) {
        sendResponse(false, null, 'نەتوانرا ژمارەی پسوولەی یەکتا دروست بکرێت');
    }
    
    // Determine user type and sub-user ID
    $userType = 'main';
    $subUserId = null;
    
    if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
        $userType = 'sub';
        $subUserId = isset($currentUser['sub_user_id']) ? (int)$currentUser['sub_user_id'] : null;
    }
    
    // Start transaction
    $affectedProductIds = collectProductIdsFromSaleItemsForLogs($items);
    $beforeSnapshots = [];
    foreach ($affectedProductIds as $pid) {
        $beforeSnapshots[$pid] = getProductSnapshotForLogs($conn, $userId, $pid);
    }
    $beforeCustomerSnapshotForHistory = null;
    if (!empty($customer_id)) {
        $beforeCustomerSnapshotForHistory = getCustomerSnapshotForLogs($conn, $userId, (int)$customer_id);
    }
    $conn->begin_transaction();
    
    try {
        $payloadHash = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE));
        $existingTxn = reserveOrLoadTxnState($conn, $userId, $clientTxnId, $deviceId, $payloadHash);
        if ($existingTxn && in_array($existingTxn['status'], ['synced', 'duplicate'], true) && !empty($existingTxn['response_json'])) {
            $decoded = json_decode($existingTxn['response_json'], true);
            if (is_array($decoded) && !empty($decoded['sale']['id'])) {
                $conn->commit();
                sendResponse(true, ['sale' => $decoded['sale']], 'فرۆشتن پێشتر تۆمار کراوە');
            }
        }

        // Insert sale record
        $stmt = $conn->prepare("
            INSERT INTO sales (
                user_id, user_type, sub_user_id, customer_id, invoice_number, customer_name, 
                total_amount, discount, final_amount, currency, payment_method, wallet_id, notes,
                payment_status, paid_amount, remaining_amount, sale_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Bind parameters - sub_user_id can be NULL, so handle it properly
        // For NULL integer values in mysqli, we still use 'i' but pass null
        $__salesBindTypes = "isiissdddssissdds";
        $stmt->bind_param(
            $__salesBindTypes, 
            $userId, $userType, $subUserId, $customer_id, $invoice_number, $customer_name, 
            $total_amount, $discount, $final_amount, $saleCurrency, $payment_method, $wallet_id, $notes,
            $payment_status, $paid_amount, $remaining_amount, $sale_date
        );

        if (!$stmt->execute()) {
            error_log('Sale insert failed: ' . $stmt->error);
            error_log('Sale insert error code: ' . $stmt->errno);
            throw new Exception('نەتوانرا فرۆشتنەکە تۆمار بکرێت: ' . $stmt->error);
        }
        
        error_log('Sale record created successfully');
        
        $sale_id = $conn->insert_id;
        $externalCostsTableEnsured = false;
        
        // Insert sale items and update stock
        foreach ($items as $item) {
            // Debug: Log item data
            error_log('Processing item: ' . json_encode($item));
            
            // Check if this is an external product (product_id = null or 0 means external)
            $isExternal = !empty($item['isExternal']) || 
                         (isset($item['product_id']) && ($item['product_id'] === null || $item['product_id'] === '' || (int)$item['product_id'] === 0));
            
            // Validate item data with detailed error messages
            if (!$isExternal && (empty($item['product_id']) || (int)$item['product_id'] <= 0)) {
                error_log('Error: product_id is empty or invalid');
                throw new Exception('داتای ئایتم نادروستە: product_id بەتاڵە');
            }
            
            if (!isset($item['quantity'])) {
                error_log('Error: quantity is not set');
                throw new Exception('داتای ئایتم نادروستە: quantity نەدۆزرایەوە');
            }
            
            if ($item['quantity'] <= 0) {
                error_log('Error: quantity is <= 0: ' . $item['quantity']);
                throw new Exception('داتای ئایتم نادروستە: quantity ناتوانێت سفر یان کەمتر بێت');
            }
            
            if (empty($item['product_name'])) {
                error_log('Error: product_name is empty');
                throw new Exception('داتای ئایتم نادروستە: product_name بەتاڵە');
            }
            
            // Use NULL for external products, otherwise use the actual product_id
            $product_id = $isExternal ? null : (int)$item['product_id'];
            $product_name = cleanInput($item['product_name']);
            $quantity = (float)$item['quantity'];
            $unit_price = (float)$item['unit_price'];
            $total_price = (float)$item['total_price'];
            $price_type = cleanInput($item['price_type'] ?? 'retail');
            
            // Get currency from item or use sale currency
            $itemCurrency = !empty($item['currency']) && in_array($item['currency'], ['USD', 'IQD']) 
                ? $item['currency'] 
                : $saleCurrency;
            
            // Check if product has units
            $unit_id = $item['unit_id'] ?? null;
            $unit_name = $item['unit_name'] ?? 'دانە';
            $unit_symbol = $item['unit_symbol'] ?? '';
            
            // Pre-check: Verify if unit_id exists in product_units. If not, but product exists, fallback to null unit_id
            if (!$isExternal && $unit_id) {
                $checkUnitStmt = $conn->prepare("SELECT id FROM product_units WHERE product_id = ? AND unit_id = ?");
                $checkUnitStmt->bind_param("ii", $product_id, $unit_id);
                $checkUnitStmt->execute();
                if ($checkUnitStmt->get_result()->num_rows === 0) {
                     error_log("Unit ID $unit_id not found for product $product_id. Attempting to find alternative unit.");
                     
                     // Try to find ANY unit for this product
                     $altUnitStmt = $conn->prepare("
                        SELECT pu.unit_id, u.name 
                        FROM product_units pu 
                        JOIN units u ON pu.unit_id = u.id 
                        WHERE pu.product_id = ?
                     ");
                     $altUnitStmt->bind_param("i", $product_id);
                     $altUnitStmt->execute();
                     $altResult = $altUnitStmt->get_result();
                     
                     $foundAlt = false;
                     $firstUnitId = null;
                     $firstUnitName = null;
                     
                     while ($row = $altResult->fetch_assoc()) {
                         if (!$firstUnitId) {
                             $firstUnitId = $row['unit_id'];
                             $firstUnitName = $row['name'];
                         }
                         // Prefer 'دانە' if available
                         if ($row['name'] === 'دانە') {
                             $unit_id = $row['unit_id'];
                             $unit_name = $row['name'];
                             $foundAlt = true;
                             break;
                         }
                     }
                     
                     if (!$foundAlt && $firstUnitId) {
                         // Fallback to first available unit
                         $unit_id = $firstUnitId;
                         $unit_name = $firstUnitName;
                         $foundAlt = true;
                     }
                     
                     if ($foundAlt) {
                         error_log("Falling back to alternative unit: $unit_name ($unit_id)");
                     } else {
                         // No units found at all, assume simple product
                         error_log("No units found for product $product_id, falling back to simple product logic");
                         $unit_id = null;
                     }
                }
            }

            error_log("Checking product: ID=$product_id, Unit ID=$unit_id, Unit Name=$unit_name, Quantity=$quantity, IsExternal=" . ($isExternal ? 'true' : 'false'));
            
            // Skip stock validation for external products
            if (!$isExternal && $unit_id) {
                // Product has units - check product_units table
                error_log("Checking product with units...");
                $productStmt = $conn->prepare("
                    SELECT pu.stock_quantity, p.name, u.name as unit_name, u.symbol as unit_symbol
                    FROM product_units pu
                    JOIN products p ON pu.product_id = p.id
                    JOIN units u ON pu.unit_id = u.id
                    WHERE pu.product_id = ? AND pu.unit_id = ? AND p.user_id = ?
                ");
                $productStmt->bind_param("iii", $product_id, $unit_id, $userId);
                $productStmt->execute();
                $productResult = $productStmt->get_result();
                
                if (!$productData = $productResult->fetch_assoc()) {
                    error_log("Product with units not found: product_id=$product_id, unit_id=$unit_id, user_id=$userId");
                    throw new Exception("کاڵا بە ID $product_id و یەکەی $unit_name نەدۆزرایەوە");
                }
                
                error_log("Product found with units: " . json_encode($productData));
                
                if ($productData['stock_quantity'] < $quantity) {
                    error_log("Insufficient stock: required=$quantity, available={$productData['stock_quantity']}");
                    throw new Exception("بەردەست ناتەواوە بۆ کاڵای: {$productData['name']} - {$productData['unit_name']}");
                }
            } else if (!$isExternal) {
                // Product without explicit unit: use primary/fallback unit stock
                error_log("Checking product with primary/fallback unit...");
                $productStmt = $conn->prepare("
                    SELECT
                        COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) AS stock_quantity,
                        p.name
                    FROM products p
                    LEFT JOIN product_units pu_primary
                        ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    LEFT JOIN product_units pu_any
                        ON pu_any.id = (
                            SELECT pu2.id
                            FROM product_units pu2
                            WHERE pu2.product_id = p.id
                            ORDER BY pu2.is_primary DESC, pu2.id ASC
                            LIMIT 1
                        )
                    WHERE p.id = ? AND p.user_id = ?
                ");
                $productStmt->bind_param("ii", $product_id, $userId);
                $productStmt->execute();
                $productResult = $productStmt->get_result();
                
                if (!$productData = $productResult->fetch_assoc()) {
                    error_log("Product without units not found: product_id=$product_id, user_id=$userId");
                    throw new Exception("کاڵا بە ID $product_id نەدۆزرایەوە");
                }
                
                error_log("Product found without units: " . json_encode($productData));
                
                if ($productData['stock_quantity'] < $quantity) {
                    error_log("Insufficient stock: required=$quantity, available={$productData['stock_quantity']}");
                    throw new Exception("بەردەست ناتەواوە بۆ کاڵای: {$productData['name']}");
                }
            }
            
            // Insert sale item
            error_log("Inserting sale item: sale_id=$sale_id, product_id=$product_id, product_name=$product_name, quantity=$quantity, unit_price=$unit_price, total_price=$total_price, price_type=$price_type, unit_id=$unit_id, unit_name=$unit_name, unit_symbol=$unit_symbol");
            
            $unit_cost_at_sale = null;
            if (!$isExternal && $product_id !== null) {
                $unit_cost_at_sale = resolveSaleItemUnitCostAtSale($conn, $userId, $product_id, $unit_id, $saleCurrency);
            }

            $itemStmt = $conn->prepare("
                INSERT INTO sale_items (
                    sale_id, product_id, product_name, quantity, 
                    unit_price, total_price, unit_cost_at_sale, price_type, currency, unit_id, unit_name, unit_symbol
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Bind parameters - product_id can be NULL for external products
            // Use 's' type for product_id to properly handle NULL values
            $product_id_param = $product_id === null ? null : (string)$product_id;
            $unitCostAtSaleParam = $unit_cost_at_sale === null ? null : (string)$unit_cost_at_sale;
            $itemStmt->bind_param(
                "issdddsssiss", 
                $sale_id, $product_id_param, $product_name, $quantity, 
                $unit_price, $total_price, $unitCostAtSaleParam, $price_type, $itemCurrency, $unit_id, $unit_name, $unit_symbol
            );
            
            if (!$itemStmt->execute()) {
                error_log("Failed to insert sale item: " . $itemStmt->error);
                throw new Exception('نەتوانرا ئایتمی فرۆشتن تۆمار بکرێت: ' . $itemStmt->error);
            }
            
            $sale_item_id = $conn->insert_id;
            error_log("Sale item inserted successfully");
            
            // Store buy_price for external products (for profit calculation)
            if ($isExternal && $sale_item_id) {
                $cost_price = isset($item['cost_price']) ? (float)$item['cost_price'] : (isset($item['buy_price']) ? (float)$item['buy_price'] : null);
                if ($cost_price !== null && $cost_price >= 0) {
                    if (!$externalCostsTableEnsured) {
                        $conn->query("CREATE TABLE IF NOT EXISTS `sale_item_external_costs` (
                            `id` int NOT NULL AUTO_INCREMENT,
                            `sale_item_id` int NOT NULL,
                            `buy_price` decimal(10,3) NOT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `uk_sale_item` (`sale_item_id`),
                            CONSTRAINT `fk_external_costs_sale_item`
                                FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                        $externalCostsTableEnsured = true;
                    }
                    $extCostStmt = $conn->prepare("INSERT INTO sale_item_external_costs (sale_item_id, buy_price) VALUES (?, ?)");
                    if ($extCostStmt) {
                        $extCostStmt->bind_param("id", $sale_item_id, $cost_price);
                        $extCostStmt->execute();
                        $extCostStmt->close();
                    }
                }
            }
            
            // Update product stock (skip for external products)
            if (!$isExternal && $unit_id) {
                // Update product_units table for products with units
                error_log("Updating stock in product_units: product_id=$product_id, unit_id=$unit_id, quantity=$quantity");
                
                // First, update the sold unit
                $updateStockStmt = $conn->prepare("
                    UPDATE product_units 
                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                    WHERE product_id = ? AND unit_id = ?
                ");
                
                $updateStockStmt->bind_param("dii", $quantity, $product_id, $unit_id);
                
                if (!$updateStockStmt->execute()) {
                    error_log("Failed to update stock: " . $updateStockStmt->error);
                    throw new Exception('نەتوانرا بەردەست نوێ بکرێتەوە: ' . $updateStockStmt->error);
                }
                
                error_log("Stock updated successfully for sold unit");
                
                // Now update other units of the same product using conversion ratio
                // وەرگرتنی هەموو یەکەکانی تری ئەم کاڵایە
                $otherUnitsStmt = $conn->prepare("
                    SELECT unit_id, conversion_ratio, stock_quantity
                    FROM product_units
                    WHERE product_id = ? AND unit_id != ?
                ");
                $otherUnitsStmt->bind_param("ii", $product_id, $unit_id);
                $otherUnitsStmt->execute();
                $otherUnitsResult = $otherUnitsStmt->get_result();
                
                // Get the conversion ratio of the sold unit
                $soldUnitStmt = $conn->prepare("
                    SELECT conversion_ratio 
                    FROM product_units 
                    WHERE product_id = ? AND unit_id = ?
                ");
                $soldUnitStmt->bind_param("ii", $product_id, $unit_id);
                $soldUnitStmt->execute();
                $soldUnitData = $soldUnitStmt->get_result()->fetch_assoc();
                $sold_unit_ratio = $soldUnitData['conversion_ratio'] ?? 1.0;
                
                // Update each other unit based on conversion ratio
                while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                    $other_unit_id = $otherUnit['unit_id'];
                    $other_unit_ratio = $otherUnit['conversion_ratio'];

                    // پارێزەری دابەشکردن بەسەر سفر — ئەگەر ڕێژە نەزانراو/سفر بێت ئەم یەکەیە تێپەڕ بکە
                    if ($other_unit_ratio === null || (float)$other_unit_ratio <= 0) {
                        error_log("Stock sync skipped for unit_id=$other_unit_id (product_id=$product_id): invalid conversion_ratio");
                        continue;
                    }

                    // Calculate how much to deduct from this unit
                    // Formula: sold_quantity * (sold_unit_ratio / other_unit_ratio)
                    $deduction_amount = $quantity * ($sold_unit_ratio / $other_unit_ratio);
                    
                    error_log("Updating other unit: unit_id=$other_unit_id, deduction=$deduction_amount (sold_qty=$quantity, sold_ratio=$sold_unit_ratio, other_ratio=$other_unit_ratio)");
                    
                    // Update the other unit's stock
                    $updateOtherUnitStmt = $conn->prepare("
                        UPDATE product_units 
                        SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                        WHERE product_id = ? AND unit_id = ?
                    ");
                    $updateOtherUnitStmt->bind_param("dii", $deduction_amount, $product_id, $other_unit_id);
                    
                    if (!$updateOtherUnitStmt->execute()) {
                        error_log("Failed to update other unit stock: " . $updateOtherUnitStmt->error);
                        // Don't throw exception, just log the error
                    } else {
                        error_log("Successfully updated other unit stock");
                    }
                }
            } else if (!$isExternal) {
                // Update primary/fallback unit stock instead of removed products.stock_quantity
                error_log("Updating stock in product_units primary/fallback: product_id=$product_id, user_id=$userId, quantity=$quantity");
                $updateStockStmt = $conn->prepare("
                    UPDATE product_units
                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                    WHERE id = (
                        SELECT pux.id
                        FROM (
                            SELECT pu.id
                            FROM product_units pu
                            JOIN products p ON p.id = pu.product_id
                            WHERE pu.product_id = ? AND p.user_id = ?
                            ORDER BY pu.is_primary DESC, pu.id ASC
                            LIMIT 1
                        ) AS pux
                    )
                ");
                
                $updateStockStmt->bind_param("dii", $quantity, $product_id, $userId);
                
                if (!$updateStockStmt->execute()) {
                    error_log("Failed to update stock: " . $updateStockStmt->error);
                    throw new Exception('نەتوانرا بەردەست نوێ بکرێتەوە: ' . $updateStockStmt->error);
                }
                
                error_log("Stock updated successfully");
            }
            
            // For external products, skip stock updates
            if ($isExternal) {
                error_log("Skipping stock update for external product: $product_name");
            }
        }
        
        // Handle cash purchases for customers
        if ($payment_method === 'cash' && $customer_id) {
            error_log('Creating cash purchase record for customer: ' . $customer_id);
            
            $cashPurchaseStmt = $conn->prepare("
                INSERT INTO customer_cash_purchases (
                    user_id, customer_id, sale_id, invoice_number, 
                    total_amount, discount, final_amount, purchase_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $cashPurchaseStmt->bind_param(
                "iiisddds",
                $userId, $customer_id, $sale_id, $invoice_number,
                $total_amount, $discount, $final_amount, $sale_date
            );
            
            if (!$cashPurchaseStmt->execute()) {
                error_log('Cash purchase insert failed: ' . $cashPurchaseStmt->error);
                error_log('Cash purchase insert error code: ' . $cashPurchaseStmt->errno);
                throw new Exception('نەتوانرا کڕینی کاش تۆمار بکرێت: ' . $cashPurchaseStmt->error);
            }
            
            error_log('Cash purchase record created successfully');
        }

        if ($payment_method === 'cash') {
            if (!walletPostEntry(
                $conn,
                (int)$userId,
                (int)$wallet_id,
                'sale_income',
                'in',
                $saleCurrency,
                $final_amount,
                'sale',
                (int)$sale_id,
                'POS sale income',
                (int)$userId
            )) {
                throw new Exception('نەتوانرا جوڵەی قاسەی فرۆشتن تۆمار بکرێت');
            }
        }
        
        // Handle debt/credit if applicable (only for non-cash payments)
        error_log('Checking payment method for debt handling: ' . $payment_method);
        if ($payment_method === 'debt' || $payment_method === 'credit') {
            error_log('Creating debt record for payment method: ' . $payment_method);
            $installment_months = null;
            $monthly_amount = null;
            $next_payment_date = null;
            
            if ($payment_method === 'debt' && $debt_payment_date) {
                // For debt payments, use the specified payment date as next payment date
                $next_payment_date = $debt_payment_date;
            }
            
            // Convert NULL values to appropriate types for binding
            if ($installment_months === null) {
                $installment_months = 0; // Use 0 instead of NULL for integer
            }
            if ($monthly_amount === null) {
                $monthly_amount = 0.0; // Use 0.0 instead of NULL for decimal
            }
            
            $debtStmt = $conn->prepare("
                INSERT INTO debts (
                    user_id, customer_id, sale_id, customer_name, customer_phone, total_debt, 
                    paid_amount, remaining_amount, debt_type, installment_months, 
                    monthly_amount, next_payment_date, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?
                )
            ");
            
            // Get customer phone if customer_id exists
            $customer_phone = '';
            if ($customer_id) {
                $phoneStmt = $conn->prepare("SELECT phone FROM customers WHERE id = ? AND user_id = ?");
                $phoneStmt->bind_param("ii", $customer_id, $userId);
                $phoneStmt->execute();
                $phoneResult = $phoneStmt->get_result();
                if ($phoneData = $phoneResult->fetch_assoc()) {
                    $customer_phone = $phoneData['phone'] ?: '';
                }
                $phoneStmt->close();
            }
            
            // Map payment method to debt_type
            if ($payment_method === 'credit') {
                $debt_type = 'credit';
            } elseif ($payment_method === 'installment') {
                $debt_type = 'installment';
            } else {
                $debt_type = 'debt';
            }
            
            // Ensure debt_type is valid and properly encoded
            $valid_debt_types = ['debt', 'credit', 'installment'];
            if (!in_array($debt_type, $valid_debt_types)) {
                error_log('Invalid debt_type: ' . $debt_type . ', defaulting to debt');
                $debt_type = 'debt';
            }
            
            error_log('Mapped payment method "' . $payment_method . '" to debt_type "' . $debt_type . '"');
            
            // Debug: Log all parameters before binding
            error_log('Debt insert parameters:');
            error_log('  user_id: ' . $userId);
            error_log('  customer_id: ' . ($customer_id ?: 'NULL'));
            error_log('  sale_id: ' . $sale_id);
            error_log('  customer_name: ' . $customer_name);
            error_log('  customer_phone: ' . $customer_phone);
            error_log('  final_amount: ' . $final_amount);
            error_log('  debt_type: ' . $debt_type);
            error_log('  installment_months: ' . $installment_months);
            error_log('  monthly_amount: ' . $monthly_amount);
            error_log('  next_payment_date: ' . ($next_payment_date ?: 'NULL'));
            
            // حیسابکردنی بڕی قەرزی ماوە پاش کەمکردنەوەی بڕی واسڵکراو
            $remaining_debt = $final_amount - $paid_amount;
            
            $debtStmt->bind_param(
                "iiissdddsddss",
                $userId, $customer_id, $sale_id, $customer_name, $customer_phone, $final_amount,
                $paid_amount, $remaining_debt, $debt_type, $installment_months,
                $monthly_amount, $next_payment_date, $sale_date
            );
            
            if (!$debtStmt->execute()) {
                error_log('Debt insert failed: ' . $debtStmt->error);
                error_log('Debt insert error code: ' . $debtStmt->errno);
                error_log('SQL State: ' . $debtStmt->sqlstate);
                throw new Exception('نەتوانرا قەرز/قیست تۆمار بکرێت: ' . $debtStmt->error);
            }
            
            error_log('Debt record created successfully');
            error_log('Total debt: ' . $final_amount . ', Paid amount: ' . $paid_amount . ', Remaining: ' . $remaining_debt);
            
            // ئەگەر بڕێکی پارە واسڵکراوە، وەک پارەدانێک تۆمار بکە
            if ($paid_amount > 0 && $customer_id) {
                $debtId = $conn->insert_id;
                
                $paymentStmt = $conn->prepare("
                    INSERT INTO debt_payments (
                        debt_id, payment_amount, payment_date, notes
                    ) VALUES (?, ?, ?, ?)
                ");
                
                $debtPaymentNote = 'پارەدان لە کاتی فرۆشتن';
                $paymentStmt->bind_param("idss", $debtId, $paid_amount, $sale_date, $debtPaymentNote);
                
                if (!$paymentStmt->execute()) {
                    error_log('Debt payment insert failed: ' . $paymentStmt->error);
                    throw new Exception('نەتوانرا پارەدانەکە تۆمار بکرێت: ' . $paymentStmt->error);
                }
                
                error_log('Debt payment recorded successfully');
            }
            
            // Update customer's total debt
            if ($customer_id) {
                $updateCustomerDebt = $conn->prepare("
                    UPDATE customers 
                    SET total_debt = (
                        SELECT COALESCE(SUM(remaining_amount), 0) 
                        FROM debts 
                        WHERE customer_id = ? AND status = 'active'
                    )
                    WHERE id = ?
                ");
                $updateCustomerDebt->bind_param("ii", $customer_id, $customer_id);
                $updateCustomerDebt->execute();
            }
        }
        
        // Get complete sale data for response
        $saleData = getSaleData($sale_id);
        
        // Log activity
        writeLog("Sale created: Invoice $invoice_number, Amount: $final_amount by user: {$currentUser['email']}");
        
        if ($clientTxnId !== '') {
            $respJson = json_encode(['sale' => $saleData], JSON_UNESCAPED_UNICODE);
            $payloadHash = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE));
            $markStmt = $conn->prepare("
                UPDATE sync_transactions
                SET device_id = ?, payload_hash = ?, status = 'synced', sale_id = ?, response_json = ?, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ? AND client_txn_id = ?
            ");
            $markStmt->bind_param("ssisis", $deviceId, $payloadHash, $sale_id, $respJson, $userId, $clientTxnId);
            $markStmt->execute();
            $markStmt->close();
        }

        // Commit transaction
        $conn->commit();
        foreach ($affectedProductIds as $pid) {
            $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $pid);
            logProductChangeEvent(
                'sale.create',
                'sale',
                $sale_id,
                $beforeSnapshots[$pid] ?? null,
                $afterSnapshot,
                [
                    'user_id' => $userId,
                    'current_user' => $currentUser,
                    'product_id' => $pid,
                    'source_module' => 'api/sales.php',
                    'source_reference' => (string)$invoice_number
                ]
            );
        }
        if (!empty($customer_id)) {
            $afterSaleSnapshotForCustomerHistory = getSaleSnapshotForCustomerLogs($conn, $userId, $sale_id);
            $afterCustomerSnapshotForHistory = getCustomerSnapshotForLogs($conn, $userId, (int)$customer_id);
            logCustomerChangeEvent(
                'sale.create',
                'sale',
                $sale_id,
                [
                    'sale_snapshot' => null,
                    'customer_snapshot' => $beforeCustomerSnapshotForHistory
                ],
                [
                    'sale_snapshot' => $afterSaleSnapshotForCustomerHistory,
                    'customer_snapshot' => $afterCustomerSnapshotForHistory
                ],
                [
                    'user_id' => $userId,
                    'current_user' => $currentUser,
                    'customer_id' => (int)$customer_id,
                    'sale_id' => $sale_id,
                    'currency' => (string)$saleCurrency,
                    'source_module' => 'api/sales.php',
                    'source_reference' => (string)$invoice_number
                ]
            );
        }

        sendResponse(true, ['sale' => $saleData], 'فرۆشتن بە سەرکەوتوویی تەواو بوو');
        
    } catch (Exception $e) {
        $conn->rollback();
        sendResponse(false, null, $e->getMessage());
    }
}

/**
 * لیستی فرۆشتنەکان
 */
function handleSalesList() {
    global $conn, $userId, $ownOnlySubUserId;

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $paymentMethod = $_GET['payment_method'] ?? '';

    // Build WHERE clause
    $whereConditions = ["s.user_id = ?"];
    $params = [$userId];
    $types = 'i';

    // کارمەندی سنووردار تەنها فرۆشتنی خۆی
    if ($ownOnlySubUserId !== null) {
        $whereConditions[] = "s.sub_user_id = ?";
        $params[] = $ownOnlySubUserId;
        $types .= 'i';
    }

    if (!empty($dateFrom)) {
        $whereConditions[] = "DATE(s.sale_date) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    
    if (!empty($dateTo)) {
        $whereConditions[] = "DATE(s.sale_date) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }
    
    if (!empty($paymentMethod)) {
        $whereConditions[] = "s.payment_method = ?";
        $params[] = $paymentMethod;
        $types .= 's';
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get sales with pagination
    $query = "
        SELECT 
            s.*,
            COUNT(si.id) as item_count
        FROM sales s
        LEFT JOIN sale_items si ON s.id = si.sale_id
        WHERE $whereClause
        GROUP BY s.id
        ORDER BY s.sale_date DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $sales = [];
    
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row;
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM sales s WHERE $whereClause";
    $countParams = array_slice($params, 0, -2); // Remove limit and offset
    $countTypes = substr($types, 0, -2);
    
    $countStmt = $conn->prepare($countQuery);
    if (!empty($countParams)) {
        $countStmt->bind_param($countTypes, ...$countParams);
    }
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    sendResponse(true, [
        'sales' => $sales,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_records' => $total,
            'per_page' => $limit
        ]
    ]);
}

/**
 * وەرگرتنی فرۆشتنێکی تایبەت
 */
function handleGetSale() {
    global $conn, $userId;

    $saleId = (int)($_GET['id'] ?? 0);
    if (!$saleId) {
        sendResponse(false, null, 'ID ی فرۆشتن پێویستە');
    }

    $saleData = getSaleData($saleId);

    if (!$saleData) {
        sendResponse(false, null, 'فرۆشتن نەدۆزرایەوە', 404);
    }

    sendResponse(true, ['sale' => $saleData]);
}

/**
 * بینینی نرخی کڕین/فرۆشتن لە کاتی فرۆشتن (تەنها profits.view)
 */
function handleSaleCostBreakdown() {
    global $conn, $userId, $ownOnlySubUserId;

    $saleId = (int)($_GET['id'] ?? 0);
    if ($saleId <= 0) {
        sendResponse(false, null, 'ID ی فرۆشتن پێویستە', 400);
    }

    $ownFilter = ($ownOnlySubUserId !== null) ? " AND s.sub_user_id = ?" : "";
    $saleStmt = $conn->prepare("
        SELECT s.id, s.invoice_number, s.sale_date, COALESCE(s.currency, 'IQD') AS currency
        FROM sales s
        WHERE s.id = ? AND s.user_id = ?" . $ownFilter . "
        LIMIT 1
    ");
    if (!$saleStmt) {
        sendResponse(false, null, 'هەڵەیەک ڕوویدا', 500);
    }

    if ($ownOnlySubUserId !== null) {
        $saleStmt->bind_param('iii', $saleId, $userId, $ownOnlySubUserId);
    } else {
        $saleStmt->bind_param('ii', $saleId, $userId);
    }
    $saleStmt->execute();
    $sale = $saleStmt->get_result()->fetch_assoc();
    $saleStmt->close();

    if (!$sale) {
        sendResponse(false, null, 'فرۆشتن نەدۆزرایەوە', 404);
    }

    $hasExternalCostsTable = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sale_item_external_costs'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $hasExternalCostsTable = true;
    }
    if ($tableCheck) {
        $tableCheck->free();
    }

    $itemsSql = "
        SELECT
            si.id,
            si.product_id,
            si.product_name,
            si.quantity,
            si.unit_price,
            si.total_price,
            si.unit_cost_at_sale,
            COALESCE(si.currency, ?) AS currency,
            si.unit_name
    ";
    if ($hasExternalCostsTable) {
        $itemsSql .= ", e.buy_price AS external_buy_price";
    } else {
        $itemsSql .= ", NULL AS external_buy_price";
    }
    $itemsSql .= "
        FROM sale_items si
    ";
    if ($hasExternalCostsTable) {
        $itemsSql .= " LEFT JOIN sale_item_external_costs e ON e.sale_item_id = si.id";
    }
    $itemsSql .= "
        WHERE si.sale_id = ?
        ORDER BY si.id ASC
    ";

    $defaultCurrency = (string)$sale['currency'];
    $itemsStmt = $conn->prepare($itemsSql);
    if (!$itemsStmt) {
        sendResponse(false, null, 'هەڵەیەک ڕوویدا', 500);
    }

    $itemsStmt->bind_param('si', $defaultCurrency, $saleId);
    $itemsStmt->execute();
    $rows = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();

    $items = [];
    $totalCost = 0.0;
    $totalRevenue = 0.0;
    $totalProfit = 0.0;
    $hasMissingCost = false;

    foreach ($rows as $row) {
        $productId = $row['product_id'] !== null ? (int)$row['product_id'] : null;
        $quantity = (float)$row['quantity'];
        $unitPrice = (float)$row['unit_price'];
        $lineRevenue = (float)$row['total_price'];
        $itemCurrency = !empty($row['currency']) && in_array($row['currency'], ['USD', 'IQD'], true)
            ? $row['currency']
            : $defaultCurrency;

        $buyPriceAtSale = null;
        if ($productId === null || $productId <= 0) {
            if ($row['external_buy_price'] !== null && $row['external_buy_price'] !== '') {
                $buyPriceAtSale = (float)$row['external_buy_price'];
            }
        } elseif ($row['unit_cost_at_sale'] !== null && $row['unit_cost_at_sale'] !== '') {
            $buyPriceAtSale = (float)$row['unit_cost_at_sale'];
        }

        $lineCost = null;
        $lineProfit = null;
        if ($buyPriceAtSale !== null) {
            $lineCost = round($buyPriceAtSale * $quantity, 3);
            $lineProfit = round($lineRevenue - $lineCost, 3);
            $totalCost += $lineCost;
            $totalProfit += $lineProfit;
        } else {
            $hasMissingCost = true;
        }

        $totalRevenue += $lineRevenue;

        $items[] = [
            'product_name' => (string)$row['product_name'],
            'quantity' => $quantity,
            'unit_name' => (string)($row['unit_name'] ?: 'دانە'),
            'buy_price_at_sale' => $buyPriceAtSale,
            'sell_price' => $unitPrice,
            'line_cost' => $lineCost,
            'line_revenue' => round($lineRevenue, 3),
            'line_profit' => $lineProfit,
            'currency' => $itemCurrency,
            'is_external' => ($productId === null || $productId <= 0),
            'cost_recorded' => $buyPriceAtSale !== null,
        ];
    }

    sendResponse(true, [
        'sale' => [
            'id' => (int)$sale['id'],
            'invoice_number' => $sale['invoice_number'] ?? null,
            'sale_date' => $sale['sale_date'] ?? null,
            'currency' => $defaultCurrency,
        ],
        'items' => $items,
        'summary' => [
            'total_cost' => round($totalCost, 3),
            'total_revenue' => round($totalRevenue, 3),
            'total_profit' => round($totalProfit, 3),
            'has_missing_cost' => $hasMissingCost,
        ],
    ]);
}

/**
 * داتای گەڕاندنەوەی بەستراو بە وەسڵ
 */
function handleSaleReturnContext() {
    global $conn, $userId, $ownOnlySubUserId;

    $saleId = (int)($_GET['id'] ?? 0);
    if (!$saleId) {
        sendResponse(false, null, 'ID ی فرۆشتن پێویستە', 400);
    }

    ensureSaleReturnLinkColumns($conn);
    $context = getSaleReturnContext($conn, $userId, $saleId, $ownOnlySubUserId);
    if (!$context) {
        sendResponse(false, null, 'فرۆشتن نەدۆزرایەوە', 404);
    }

    $wallets = walletGetUserWallets($conn, (int)$userId, true);

    $saleCurrency = $context['sale']['currency'] ?? 'IQD';
    $customerId = !empty($context['sale']['customer_id']) ? (int)$context['sale']['customer_id'] : 0;
    $customerOutstanding = $customerId > 0
        ? saleReturnGetCustomerOutstanding($conn, (int)$userId, $customerId, $saleCurrency)
        : 0.0;

    sendResponse(true, [
        'sale' => $context['sale'],
        'items' => $context['items'],
        'prior_returns' => $context['prior_returns'],
        'debt_summary' => $context['debt_summary'],
        'is_debt_sale' => $context['is_debt_sale'],
        'suggested_return_payment_method' => $context['suggested_return_payment_method'],
        'suggested_wallet_id' => $context['suggested_wallet_id'],
        'customer_outstanding_amount' => $customerOutstanding,
        'wallets' => $wallets,
    ]);
}

/**
 * ئامارەکانی ڕۆژانە
 */
function handleDailyStats() {
    global $conn, $userId, $ownOnlySubUserId;

    $date = $_GET['date'] ?? date('Y-m-d');

    // کارمەندی سنووردار: ئامار تەنها بۆ فرۆشتنی خۆی
    $ownFilter = ($ownOnlySubUserId !== null) ? " AND sub_user_id = ?" : "";

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) as total_sales,
            IFNULL(SUM(final_amount), 0) as total_revenue,
            IFNULL(AVG(final_amount), 0) as average_sale,
            COUNT(CASE WHEN payment_method = 'cash' THEN 1 END) as cash_sales,
            COUNT(CASE WHEN payment_method = 'credit' THEN 1 END) as credit_sales,
            COUNT(CASE WHEN payment_method = 'debt' THEN 1 END) as debt_sales,
            COUNT(CASE WHEN payment_method = 'installment' THEN 1 END) as installment_sales
        FROM sales
        WHERE user_id = ? AND DATE(sale_date) = ?" . $ownFilter . "
    ");

    if ($ownOnlySubUserId !== null) {
        $stmt->bind_param('isi', $userId, $date, $ownOnlySubUserId);
    } else {
        $stmt->bind_param('is', $userId, $date);
    }
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();

    // Get hourly breakdown
    $hourlyStmt = $conn->prepare("
        SELECT
            HOUR(sale_date) as hour,
            COUNT(*) as sales_count,
            SUM(final_amount) as revenue
        FROM sales
        WHERE user_id = ? AND DATE(sale_date) = ?" . $ownFilter . "
        GROUP BY HOUR(sale_date)
        ORDER BY hour
    ");

    if ($ownOnlySubUserId !== null) {
        $hourlyStmt->bind_param('isi', $userId, $date, $ownOnlySubUserId);
    } else {
        $hourlyStmt->bind_param('is', $userId, $date);
    }
    $hourlyStmt->execute();
    $hourlyData = $hourlyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    sendResponse(true, [
        'stats' => $stats,
        'hourly_breakdown' => $hourlyData,
        'date' => $date
    ]);
}

/**
 * ئامارەکانی مانگانە
 */
function handleMonthlyStats() {
    global $conn, $userId, $ownOnlySubUserId;

    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));

    // کارمەندی سنووردار: ئامار تەنها بۆ فرۆشتنی خۆی
    $ownFilter = ($ownOnlySubUserId !== null) ? " AND sub_user_id = ?" : "";

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) as total_sales,
            IFNULL(SUM(final_amount), 0) as total_revenue,
            IFNULL(AVG(final_amount), 0) as average_sale,
            COUNT(DISTINCT DATE(sale_date)) as active_days
        FROM sales
        WHERE user_id = ? AND YEAR(sale_date) = ? AND MONTH(sale_date) = ?" . $ownFilter . "
    ");

    if ($ownOnlySubUserId !== null) {
        $stmt->bind_param('iiii', $userId, $year, $month, $ownOnlySubUserId);
    } else {
        $stmt->bind_param('iii', $userId, $year, $month);
    }
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();

    // Get daily breakdown
    $dailyStmt = $conn->prepare("
        SELECT
            DAY(sale_date) as day,
            COUNT(*) as sales_count,
            SUM(final_amount) as revenue
        FROM sales
        WHERE user_id = ? AND YEAR(sale_date) = ? AND MONTH(sale_date) = ?" . $ownFilter . "
        GROUP BY DAY(sale_date)
        ORDER BY day
    ");

    if ($ownOnlySubUserId !== null) {
        $dailyStmt->bind_param('iiii', $userId, $year, $month, $ownOnlySubUserId);
    } else {
        $dailyStmt->bind_param('iii', $userId, $year, $month);
    }
    $dailyStmt->execute();
    $dailyData = $dailyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    sendResponse(true, [
        'stats' => $stats,
        'daily_breakdown' => $dailyData,
        'year' => $year,
        'month' => $month
    ]);
}

/**
 * وەرگرتنی وەسڵ
 */
function handleGetReceipt() {
    global $conn, $userId;
    
    $saleId = (int)($_GET['sale_id'] ?? 0);
    if (!$saleId) {
        sendResponse(false, null, 'ID ی فرۆشتن پێویستە');
    }
    
    $saleData = getSaleData($saleId);
    
    if (!$saleData) {
        sendResponse(false, null, 'فرۆشتن نەدۆزرایەوە', 404);
    }
    
    // Get user settings for receipt
    $settingsStmt = $conn->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
    $settingsStmt->bind_param('i', $userId);
    $settingsStmt->execute();
    $settings = $settingsStmt->get_result()->fetch_assoc();
    
    sendResponse(true, [
        'sale' => $saleData,
        'settings' => $settings
    ]);
}

/**
 * سڕینەوەی فرۆشتن
 */
function handleDeleteSale() {
    global $conn, $userId, $currentUser, $ownOnlySubUserId;
    
    // Accept both DELETE and POST methods
    if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
        sendResponse(false, null, 'تەنها DELETE یان POST ڕێگەپێدراوە', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['id'])) {
        sendResponse(false, null, 'ID ی فرۆشتن پێویستە', 400);
    }
    
    $saleId = (int)$input['id'];
    $walletTxId = 0;
    $walletReversedAmount = 0.0;
    $walletName = '';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get sale data first to verify ownership
        $ownFilter = ($ownOnlySubUserId !== null) ? " AND sub_user_id = ?" : "";
        $saleStmt = $conn->prepare("
            SELECT id, final_amount, payment_method, customer_id, wallet_id,
                   currency, invoice_number, customer_name, sale_date,
                   total_amount, discount
            FROM sales
            WHERE id = ? AND user_id = ?" . $ownFilter . "
        ");
        if ($ownOnlySubUserId !== null) {
            $saleStmt->bind_param('iii', $saleId, $userId, $ownOnlySubUserId);
        } else {
            $saleStmt->bind_param('ii', $saleId, $userId);
        }
        $saleStmt->execute();
        $sale = $saleStmt->get_result()->fetch_assoc();
        
        if (!$sale) {
            throw new Exception('فرۆشتن نەدۆزرایەوە یان مافی سڕینەوەت نییە');
        }

        $returnCheckStmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM returns
            WHERE sale_id = ? AND user_id = ?
        ");
        $returnCheckStmt->bind_param('ii', $saleId, $userId);
        $returnCheckStmt->execute();
        $returnCheck = $returnCheckStmt->get_result()->fetch_assoc();
        $returnCheckStmt->close();
        if ((int)($returnCheck['cnt'] ?? 0) > 0) {
            throw new Exception('ئەم فرۆشتنە گەڕاندنەوەی پێوە هەیە. پێش سڕینەوە گەڕاندنەوەکان بسڕەوە');
        }

        $beforeSaleSnapshot = getSaleSnapshotForCustomerLogs($conn, $userId, $saleId);
        $beforeCustomerSnapshot = null;
        if (!empty($sale['customer_id'])) {
            $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)$sale['customer_id']);
        }
        
        // Get sale items to restore stock
        $itemsStmt = $conn->prepare("
            SELECT product_id, quantity, unit_id
            FROM sale_items 
            WHERE sale_id = ?
        ");
        $itemsStmt->bind_param('i', $saleId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $affectedProductIds = collectProductIdsFromSaleItemsForLogs($items);
        $beforeSnapshots = [];
        foreach ($affectedProductIds as $pid) {
            $beforeSnapshots[$pid] = getProductSnapshotForLogs($conn, $userId, $pid);
        }
        
        // Restore stock for each item (skip external products with NULL product_id)
        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $quantity = $item['quantity'];
            $unit_id = $item['unit_id'];
            
            // Skip external products (product_id is NULL)
            if ($product_id === null || $product_id === '') {
                error_log("Skipping stock restoration for external product (product_id is NULL)");
                continue;
            }
            
            if ($unit_id) {
                // Product has units - restore stock in product_units table
                $restoreStockStmt = $conn->prepare("
                    UPDATE product_units 
                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                    WHERE product_id = ? AND unit_id = ?
                ");
                $restoreStockStmt->bind_param("dii", $quantity, $product_id, $unit_id);
                
                if (!$restoreStockStmt->execute()) {
                    throw new Exception('نەتوانرا بەردەست بگەڕێتەوە بۆ کاڵای ID: ' . $product_id);
                }
                
                // Restore stock for other units using conversion ratio
                $soldUnitStmt = $conn->prepare("
                    SELECT conversion_ratio 
                    FROM product_units 
                    WHERE product_id = ? AND unit_id = ?
                ");
                $soldUnitStmt->bind_param("ii", $product_id, $unit_id);
                $soldUnitStmt->execute();
                $soldUnitData = $soldUnitStmt->get_result()->fetch_assoc();
                $sold_unit_ratio = $soldUnitData['conversion_ratio'] ?? 1.0;
                
                // Get other units
                $otherUnitsStmt = $conn->prepare("
                    SELECT unit_id, conversion_ratio
                    FROM product_units
                    WHERE product_id = ? AND unit_id != ?
                ");
                $otherUnitsStmt->bind_param("ii", $product_id, $unit_id);
                $otherUnitsStmt->execute();
                $otherUnitsResult = $otherUnitsStmt->get_result();
                
                while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                    $other_unit_id = $otherUnit['unit_id'];
                    $other_unit_ratio = $otherUnit['conversion_ratio'];

                    // پارێزەری دابەشکردن بەسەر سفر — ئەگەر ڕێژە نەزانراو/سفر بێت ئەم یەکەیە تێپەڕ بکە
                    if ($other_unit_ratio === null || (float)$other_unit_ratio <= 0) {
                        error_log("Stock restore skipped for unit_id=$other_unit_id (product_id=$product_id): invalid conversion_ratio");
                        continue;
                    }

                    // Calculate restoration amount
                    $restoration_amount = $quantity * ($sold_unit_ratio / $other_unit_ratio);
                    
                    $restoreOtherUnitStmt = $conn->prepare("
                        UPDATE product_units 
                        SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                        WHERE product_id = ? AND unit_id = ?
                    ");
                    $restoreOtherUnitStmt->bind_param("dii", $restoration_amount, $product_id, $other_unit_id);
                    $restoreOtherUnitStmt->execute();
                }
            } else {
                // Product without explicit unit - restore in primary/fallback unit
                $restoreStockStmt = $conn->prepare("
                    UPDATE product_units
                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                    WHERE id = (
                        SELECT pux.id
                        FROM (
                            SELECT pu.id
                            FROM product_units pu
                            JOIN products p ON p.id = pu.product_id
                            WHERE pu.product_id = ? AND p.user_id = ?
                            ORDER BY pu.is_primary DESC, pu.id ASC
                            LIMIT 1
                        ) AS pux
                    )
                ");
                $restoreStockStmt->bind_param("dii", $quantity, $product_id, $userId);
                
                if (!$restoreStockStmt->execute()) {
                    throw new Exception('نەتوانرا بەردەست بگەڕێتەوە بۆ کاڵای ID: ' . $product_id);
                }
            }
        }
        
        // Delete related debt records if payment was debt/credit
        if (in_array($sale['payment_method'], ['debt', 'credit'])) {
            $deleteDebtStmt = $conn->prepare("
                DELETE FROM debts 
                WHERE sale_id = ? AND user_id = ?
            ");
            $deleteDebtStmt->bind_param('ii', $saleId, $userId);
            $deleteDebtStmt->execute();
            
            // Update customer's total debt if customer exists
            if ($sale['customer_id']) {
                $updateCustomerDebt = $conn->prepare("
                    UPDATE customers 
                    SET total_debt = (
                        SELECT COALESCE(SUM(remaining_amount), 0) 
                        FROM debts 
                        WHERE customer_id = ? AND status = 'active'
                    )
                    WHERE id = ?
                ");
                $updateCustomerDebt->bind_param("ii", $sale['customer_id'], $sale['customer_id']);
                $updateCustomerDebt->execute();
            }
        }
        
        // Delete cash purchase record if exists
        if ($sale['payment_method'] === 'cash' && $sale['customer_id']) {
            $deleteCashPurchaseStmt = $conn->prepare("
                DELETE FROM customer_cash_purchases 
                WHERE sale_id = ? AND user_id = ?
            ");
            $deleteCashPurchaseStmt->bind_param('ii', $saleId, $userId);
            $deleteCashPurchaseStmt->execute();
        }

        if ($sale['payment_method'] === 'cash') {
            $walletId = (int)($sale['wallet_id'] ?? 0);
            $finalAmount = (float)($sale['final_amount'] ?? 0);
            $saleCurrency = strtoupper((string)($sale['currency'] ?? 'IQD')) === 'USD' ? 'USD' : 'IQD';

            if ($walletId > 0 && $finalAmount > 0) {
                $reversalResult = walletReverseSaleCash(
                    $conn,
                    $userId,
                    $saleId,
                    $walletId,
                    $finalAmount,
                    $saleCurrency,
                    $userId
                );
                if ($reversalResult === false) {
                    throw new Exception('نەتوانرا جوڵەی گەڕاندنەوەی قاسە تۆمار بکرێت');
                }
                $walletTxId = (int)$reversalResult;
                $walletReversedAmount = $finalAmount;
                $wallet = walletGetById($conn, $userId, $walletId);
                $walletName = trim((string)($wallet['name'] ?? ''));
            }
        }
        
        // Delete sale items
        $deleteItemsStmt = $conn->prepare("
            DELETE FROM sale_items 
            WHERE sale_id = ?
        ");
        $deleteItemsStmt->bind_param('i', $saleId);
        
        if (!$deleteItemsStmt->execute()) {
            throw new Exception('نەتوانرا ئایتمەکانی فرۆشتن بسڕێتەوە');
        }
        
        // Delete the sale
        $deleteSaleStmt = $conn->prepare("
            DELETE FROM sales 
            WHERE id = ? AND user_id = ?
        ");
        $deleteSaleStmt->bind_param('ii', $saleId, $userId);
        
        if (!$deleteSaleStmt->execute()) {
            throw new Exception('نەتوانرا فرۆشتنەکە بسڕێتەوە');
        }
        
        // Commit transaction
        $conn->commit();
        foreach ($affectedProductIds as $pid) {
            $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $pid);
            logProductChangeEvent(
                'sale.delete',
                'sale',
                $saleId,
                $beforeSnapshots[$pid] ?? null,
                $afterSnapshot,
                [
                    'user_id' => $userId,
                    'current_user' => $currentUser,
                    'product_id' => $pid,
                    'source_module' => 'api/sales.php',
                    'source_reference' => (string)$saleId
                ]
            );
        }
        
        // Log activity
        writeLog("Sale deleted: ID $saleId by user: {$currentUser['email']}");
        $afterCustomerSnapshot = null;
        if (!empty($sale['customer_id'])) {
            $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)$sale['customer_id']);
            logCustomerChangeEvent(
                'sale.delete',
                'sale',
                $saleId,
                [
                    'sale_snapshot' => $beforeSaleSnapshot,
                    'customer_snapshot' => $beforeCustomerSnapshot
                ],
                [
                    'sale_snapshot' => null,
                    'customer_snapshot' => $afterCustomerSnapshot
                ],
                [
                    'user_id' => $userId,
                    'current_user' => $currentUser,
                    'customer_id' => (int)$sale['customer_id'],
                    'sale_id' => $saleId,
                    'currency' => (string)($beforeSaleSnapshot['sale']['currency'] ?? 'IQD'),
                    'source_module' => 'api/sales.php',
                    'source_reference' => (string)$saleId
                ]
            );
        }

        try {
            $deleteMessage = TelegramHelper::buildSaleDeletedMessage(
                $beforeSaleSnapshot,
                $currentUser['email'] ?? '',
                $currentUser['business_name'] ?? ''
            );
            if ($deleteMessage !== '') {
                TelegramHelper::notifyUser($userId, 'sale_delete', $deleteMessage);
            }
        } catch (Exception $telegramException) {
            error_log('Telegram sale delete notification failed: ' . $telegramException->getMessage());
        }

        $voidToken = bin2hex(random_bytes(16));
        if (!isset($_SESSION['sale_void_receipt']) || !is_array($_SESSION['sale_void_receipt'])) {
            $_SESSION['sale_void_receipt'] = [];
        }
        $_SESSION['sale_void_receipt'][$voidToken] = [
            'sale' => $beforeSaleSnapshot['sale'] ?? [],
            'items' => $beforeSaleSnapshot['items'] ?? [],
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => trim((string)($currentUser['name'] ?? $currentUser['email'] ?? '')),
            'wallet_tx_id' => $walletTxId > 0 ? $walletTxId : null,
            'wallet_reversed_amount' => $walletReversedAmount,
            'wallet_name' => $walletName,
            'expires_at' => time() + 1800,
            'user_id' => $userId,
        ];
        
        sendResponse(true, [
            'void_token' => $voidToken,
            'wallet_tx_id' => $walletTxId > 0 ? $walletTxId : null,
        ], 'فرۆشتنەکە بە سەرکەوتوویی سڕایەوە');
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Delete sale error: ' . $e->getMessage());
        sendResponse(false, null, $e->getMessage());
    }
}

/**
 * دەستکاریکردنی فرۆشتن (نوێکردنەوە)
 */
function handleUpdateSale() {
    global $conn, $userId, $currentUser, $ownOnlySubUserId;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, null, 'تەنها POST ڕێگەپێدراوە', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendResponse(false, null, 'داتای نادروست', 400);
    }
    
    // Validate CSRF token
    if (!Security::validateCSRFToken($input['csrf_token'] ?? '')) {
        sendResponse(false, null, 'نادروستی ئامنیەتی', 403);
    }
    
    // Validate required fields
    $saleId = (int)($input['sale_id'] ?? 0);
    if (!$saleId) {
        sendResponse(false, null, 'ID ی فرۆشتن پێویستە', 400);
    }
    
    if (empty($input['items']) || !is_array($input['items'])) {
        sendResponse(false, null, 'ئایتمەکانی فرۆشتن پێویستە', 400);
    }
    
    // Prepare new sale data
    $new_customer_id = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
    $new_customer_name = cleanInput($input['customer_name'] ?? '');
    $new_payment_method = cleanInput($input['payment_method'] ?? 'cash');
    $new_discount = (float)($input['discount'] ?? 0);
    $new_paid_amount = (float)($input['paid_amount'] ?? 0);
    $new_currency = !empty($input['currency']) && in_array($input['currency'], ['USD', 'IQD']) ? $input['currency'] : 'IQD';
    $payment_adjustment_policy = cleanInput($input['payment_adjustment_policy'] ?? 'clamp_to_final_no_credit');
    $preserve_payment_history = array_key_exists('preserve_payment_history', $input)
        ? (bool)$input['preserve_payment_history']
        : true;
    $allowedAdjustmentPolicies = ['clamp_to_final_no_credit', 'reject_edit'];
    if (!in_array($payment_adjustment_policy, $allowedAdjustmentPolicies, true)) {
        $payment_adjustment_policy = 'clamp_to_final_no_credit';
    }
    $new_items = normalizeAndAggregateSaleItems($input['items'], $new_currency);
    applyCanonicalPricesToSaleItems($conn, $userId, $new_items, $new_currency);

    try {
        $returnedBySaleItem = getSaleItemReturnedQuantities($conn, $userId, $saleId);
        validateSaleEditWithReturns($conn, $userId, $saleId, $new_items, $returnedBySaleItem);
    } catch (InvalidArgumentException $e) {
        sendResponse(false, null, $e->getMessage(), 422);
    }

    $newTotals = calculateCanonicalTotalsFromItems($new_items, $new_discount);
    $new_total_amount = $newTotals['total_amount'];
    $new_final_amount = $newTotals['final_amount'];
    if ($new_final_amount <= 0) {
        sendResponse(false, null, 'بڕی کۆتایی پێویستە', 400);
    }
    
    // If customer_id is provided, get customer info
    if ($new_customer_id) {
        $customerStmt = $conn->prepare("SELECT name, phone FROM customers WHERE id = ? AND user_id = ?");
        $customerStmt->bind_param("ii", $new_customer_id, $userId);
        $customerStmt->execute();
        $customerResult = $customerStmt->get_result();
        if ($customerData = $customerResult->fetch_assoc()) {
            $new_customer_name = $customerData['name'];
        }
        $customerStmt->close();
    }
    
    // Validate payment method
    $allowedPaymentMethods = ['cash', 'card', 'credit', 'debt', 'installment'];
    if (!in_array($new_payment_method, $allowedPaymentMethods)) {
        sendResponse(false, null, 'شێوەی پارەدان نادروستە', 400);
    }
    
    // Determine payment status
    $new_payment_status = 'paid';
    $new_remaining_amount = 0;
    
    if ($new_payment_method === 'debt' || $new_payment_method === 'credit') {
        $new_payment_status = 'pending';
        $new_remaining_amount = $new_final_amount - $new_paid_amount;
    } elseif ($new_payment_method === 'installment') {
        $new_payment_status = 'partial';
        $new_remaining_amount = $new_final_amount - $new_paid_amount;
    } elseif ($new_payment_method === 'cash' || $new_payment_method === 'card') {
        $new_payment_status = 'paid';
        $new_paid_amount = $new_final_amount;
        $new_remaining_amount = 0;
    }
    
    $affectedProductIds = collectProductIdsFromSaleItemsForLogs($new_items);
    $beforeSaleSnapshotForCustomerHistory = getSaleSnapshotForCustomerLogs($conn, $userId, $saleId);
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // ========================================
        // PHASE 1: REVERSE OLD SALE
        // ========================================
        
        // Get old sale data
        $ownFilter = ($ownOnlySubUserId !== null) ? " AND sub_user_id = ?" : "";
        $oldSaleStmt = $conn->prepare("
            SELECT id, final_amount, payment_method, customer_id, invoice_number, sale_date
            FROM sales
            WHERE id = ? AND user_id = ?" . $ownFilter . "
        ");
        if ($ownOnlySubUserId !== null) {
            $oldSaleStmt->bind_param('iii', $saleId, $userId, $ownOnlySubUserId);
        } else {
            $oldSaleStmt->bind_param('ii', $saleId, $userId);
        }
        $oldSaleStmt->execute();
        $oldSale = $oldSaleStmt->get_result()->fetch_assoc();

        if (!$oldSale) {
            throw new Exception('فرۆشتن نەدۆزرایەوە یان مافی دەستکاریت نییە');
        }
        $beforeOldCustomerSnapshot = null;
        if (!empty($oldSale['customer_id'])) {
            $beforeOldCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)$oldSale['customer_id']);
        }
        
        $old_customer_id = $oldSale['customer_id'];
        $old_payment_method = $oldSale['payment_method'];
        $oldDebtPayments = [];
        $historical_paid_total = 0.0;

        if (in_array($old_payment_method, ['debt', 'credit'], true)) {
            $oldPaymentsStmt = $conn->prepare("
                SELECT dp.payment_amount, dp.payment_date, dp.notes
                FROM debt_payments dp
                INNER JOIN debts d ON d.id = dp.debt_id
                WHERE d.sale_id = ? AND d.user_id = ?
                ORDER BY dp.payment_date ASC, dp.id ASC
            ");
            $oldPaymentsStmt->bind_param("ii", $saleId, $userId);
            $oldPaymentsStmt->execute();
            $oldPaymentsResult = $oldPaymentsStmt->get_result();
            while ($paymentRow = $oldPaymentsResult->fetch_assoc()) {
                $amount = (float)($paymentRow['payment_amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $oldDebtPayments[] = [
                    'payment_amount' => $amount,
                    'payment_date' => $paymentRow['payment_date'] ?? $oldSale['sale_date'],
                    'notes' => $paymentRow['notes'] ?? 'پارەدان'
                ];
                $historical_paid_total += $amount;
            }
            $oldPaymentsStmt->close();
        }

        if (in_array($new_payment_method, ['debt', 'credit'], true) && $historical_paid_total > 0) {
            if ($preserve_payment_history) {
                $new_paid_amount = max($new_paid_amount, $historical_paid_total);
            }
            if ($new_paid_amount > $new_final_amount) {
                if ($payment_adjustment_policy === 'reject_edit') {
                    throw new Exception('کۆی نوێی وەسڵ کەمترە لە پارەدانەکانی پێشوو. تکایە policyی تر هەڵبژێرە.');
                }
                $new_paid_amount = $new_final_amount;
            }
        }

        // نەقد/کارت → قەرز: پارەی کاشی پێشوو نابێت وەک debt_payments لە کەشفدا دەرکەوێت
        if (in_array($old_payment_method, ['cash', 'card'], true)
            && in_array($new_payment_method, ['debt', 'credit'], true)) {
            $new_paid_amount = 0.0;
            $new_payment_status = 'pending';
            $new_remaining_amount = $new_final_amount - $new_paid_amount;
        }
        
        $new_sale_date = !empty($input['sale_date'])
            ? normalizeSaleDateFromRequest($input['sale_date'])
            : (!empty($oldSale['sale_date']) ? $oldSale['sale_date'] : date('Y-m-d H:i:s'));
        
        // Get old sale items to restore stock (تەنها بڕی ماوە دوای گەڕاوە)
        $returnedBySaleItem = getSaleItemReturnedQuantities($conn, $userId, $saleId);
        $oldItemsStmt = $conn->prepare("
            SELECT id, product_id, quantity, unit_id
            FROM sale_items 
            WHERE sale_id = ?
        ");
        $oldItemsStmt->bind_param('i', $saleId);
        $oldItemsStmt->execute();
        $oldItems = $oldItemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($oldItems as $oldItemForLog) {
            $oldPid = isset($oldItemForLog['product_id']) ? (int)$oldItemForLog['product_id'] : 0;
            if ($oldPid > 0) {
                $affectedProductIds[] = $oldPid;
            }
        }
        $affectedProductIds = array_values(array_unique($affectedProductIds));
        $beforeSnapshots = [];
        foreach ($affectedProductIds as $pid) {
            $beforeSnapshots[$pid] = getProductSnapshotForLogs($conn, $userId, $pid);
        }
        
        // Restore stock for each old item
        foreach ($oldItems as $oldItem) {
            $product_id = $oldItem['product_id'];
            $soldQuantity = (float)$oldItem['quantity'];
            $saleItemId = (int)($oldItem['id'] ?? 0);
            $returnedQty = (float)($returnedBySaleItem[$saleItemId] ?? 0);
            $quantity = max(0, $soldQuantity - $returnedQty);
            $unit_id = $oldItem['unit_id'];

            if ($quantity <= 0) {
                continue;
            }
            
            // Skip external products (product_id is NULL)
            if ($product_id === null || $product_id === '') {
                continue;
            }
            
            if ($unit_id) {
                // Product has units - restore stock in product_units table
                $restoreStockStmt = $conn->prepare("
                    UPDATE product_units 
                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                    WHERE product_id = ? AND unit_id = ?
                ");
                $restoreStockStmt->bind_param("dii", $quantity, $product_id, $unit_id);
                if (!$restoreStockStmt->execute()) {
                    throw new Exception('نەتوانرا بەردەست بگەڕێتەوە بۆ کاڵای ID: ' . $product_id);
                }
                
                // Restore stock for other units using conversion ratio
                $soldUnitStmt = $conn->prepare("
                    SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?
                ");
                $soldUnitStmt->bind_param("ii", $product_id, $unit_id);
                $soldUnitStmt->execute();
                $soldUnitData = $soldUnitStmt->get_result()->fetch_assoc();
                $sold_unit_ratio = $soldUnitData['conversion_ratio'] ?? 1.0;
                
                $otherUnitsStmt = $conn->prepare("
                    SELECT unit_id, conversion_ratio FROM product_units WHERE product_id = ? AND unit_id != ?
                ");
                $otherUnitsStmt->bind_param("ii", $product_id, $unit_id);
                $otherUnitsStmt->execute();
                $otherUnitsResult = $otherUnitsStmt->get_result();
                
                while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                    $other_unit_ratio = $otherUnit['conversion_ratio'];
                    if ($other_unit_ratio === null || (float)$other_unit_ratio <= 0) {
                        error_log("Stock restore skipped for unit_id={$otherUnit['unit_id']} (product_id=$product_id): invalid conversion_ratio");
                        continue;
                    }
                    $restoration_amount = $quantity * ($sold_unit_ratio / $other_unit_ratio);
                    $restoreOtherStmt = $conn->prepare("
                        UPDATE product_units SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                        WHERE product_id = ? AND unit_id = ?
                    ");
                    $restoreOtherStmt->bind_param("dii", $restoration_amount, $product_id, $otherUnit['unit_id']);
                    $restoreOtherStmt->execute();
                }
            } else {
                // Product without explicit unit - restore in primary/fallback unit
                $restoreStockStmt = $conn->prepare("
                    UPDATE product_units
                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                    WHERE id = (
                        SELECT pux.id
                        FROM (
                            SELECT pu.id
                            FROM product_units pu
                            JOIN products p ON p.id = pu.product_id
                            WHERE pu.product_id = ? AND p.user_id = ?
                            ORDER BY pu.is_primary DESC, pu.id ASC
                            LIMIT 1
                        ) AS pux
                    )
                ");
                $restoreStockStmt->bind_param("dii", $quantity, $product_id, $userId);
                if (!$restoreStockStmt->execute()) {
                    throw new Exception('نەتوانرا بەردەست بگەڕێتەوە بۆ کاڵای ID: ' . $product_id);
                }
            }
        }
        
        // Delete old debt records if payment was debt/credit
        if (in_array($old_payment_method, ['debt', 'credit'])) {
            // First delete debt_payments linked to debts of this sale
            $debtIdsStmt = $conn->prepare("SELECT id FROM debts WHERE sale_id = ? AND user_id = ?");
            $debtIdsStmt->bind_param('ii', $saleId, $userId);
            $debtIdsStmt->execute();
            $debtIdsResult = $debtIdsStmt->get_result();
            while ($debtRow = $debtIdsResult->fetch_assoc()) {
                $deletePaymentsStmt = $conn->prepare("DELETE FROM debt_payments WHERE debt_id = ?");
                $deletePaymentsStmt->bind_param('i', $debtRow['id']);
                $deletePaymentsStmt->execute();
            }
            
            // Delete debts
            $deleteDebtStmt = $conn->prepare("DELETE FROM debts WHERE sale_id = ? AND user_id = ?");
            $deleteDebtStmt->bind_param('ii', $saleId, $userId);
            $deleteDebtStmt->execute();
            
            // Update old customer's total debt
            if ($old_customer_id) {
                $updateOldCustDebt = $conn->prepare("
                    UPDATE customers SET total_debt = (
                        SELECT COALESCE(SUM(remaining_amount), 0) FROM debts WHERE customer_id = ? AND status = 'active'
                    ) WHERE id = ?
                ");
                $updateOldCustDebt->bind_param("ii", $old_customer_id, $old_customer_id);
                $updateOldCustDebt->execute();
            }
        }
        
        // Delete old cash purchase record if exists
        if ($old_payment_method === 'cash' && $old_customer_id) {
            $deleteCashStmt = $conn->prepare("DELETE FROM customer_cash_purchases WHERE sale_id = ? AND user_id = ?");
            $deleteCashStmt->bind_param('ii', $saleId, $userId);
            $deleteCashStmt->execute();
        }
        
        // Delete old sale items
        $deleteOldItemsStmt = $conn->prepare("DELETE FROM sale_items WHERE sale_id = ?");
        $deleteOldItemsStmt->bind_param('i', $saleId);
        if (!$deleteOldItemsStmt->execute()) {
            throw new Exception('نەتوانرا ئایتمەکانی کۆن بسڕێتەوە');
        }
        
        // ========================================
        // PHASE 2: APPLY NEW SALE DATA
        // ========================================
        if ($new_payment_method === 'debt' || $new_payment_method === 'credit') {
            if ($new_paid_amount <= 0) {
                $new_payment_status = 'pending';
                $new_remaining_amount = $new_final_amount;
            } elseif ($new_paid_amount >= $new_final_amount) {
                $new_payment_status = 'paid';
                $new_paid_amount = $new_final_amount;
                $new_remaining_amount = 0;
            } else {
                $new_payment_status = 'partial';
                $new_remaining_amount = $new_final_amount - $new_paid_amount;
            }
        } elseif ($new_payment_method === 'installment') {
            if ($new_paid_amount <= 0) {
                $new_payment_status = 'partial';
                $new_remaining_amount = $new_final_amount;
            } elseif ($new_paid_amount >= $new_final_amount) {
                $new_payment_status = 'paid';
                $new_paid_amount = $new_final_amount;
                $new_remaining_amount = 0;
            } else {
                $new_payment_status = 'partial';
                $new_remaining_amount = $new_final_amount - $new_paid_amount;
            }
        } else {
            $new_payment_status = 'paid';
            $new_paid_amount = $new_final_amount;
            $new_remaining_amount = 0;
        }
        $resolved_payment_status = resolveSalePaymentStatus($conn, $new_payment_status);
        if ($resolved_payment_status !== $new_payment_status) {
            error_log('payment_status fallback applied in sales update: ' . $new_payment_status . ' => ' . $resolved_payment_status);
            $new_payment_status = $resolved_payment_status;
        }
        
        // Update sales record (keep same ID and invoice_number)
        $updateSaleStmt = $conn->prepare("
            UPDATE sales SET
                customer_id = ?, customer_name = ?,
                total_amount = ?, discount = ?, final_amount = ?,
                currency = ?, payment_method = ?, payment_status = ?,
                paid_amount = ?, remaining_amount = ?,
                sale_date = ?
            WHERE id = ? AND user_id = ?
        ");
        $updateSaleStmt->bind_param(
            "isdddsssddsii",
            $new_customer_id, $new_customer_name,
            $new_total_amount, $new_discount, $new_final_amount,
            $new_currency, $new_payment_method, $new_payment_status,
            $new_paid_amount, $new_remaining_amount,
            $new_sale_date,
            $saleId, $userId
        );
        
        if (!$updateSaleStmt->execute()) {
            throw new Exception('نەتوانرا فرۆشتنەکە نوێ بکرێتەوە: ' . $updateSaleStmt->error);
        }
        
        // Insert new sale items and update stock
        foreach ($new_items as $item) {
            $isExternal = !empty($item['isExternal']) || 
                         (isset($item['product_id']) && ($item['product_id'] === null || $item['product_id'] === '' || (int)$item['product_id'] === 0));
            
            // Validate item data
            if (!$isExternal && (empty($item['product_id']) || (int)$item['product_id'] <= 0)) {
                throw new Exception('داتای ئایتم نادروستە: product_id بەتاڵە');
            }
            if (!isset($item['quantity']) || $item['quantity'] <= 0) {
                throw new Exception('داتای ئایتم نادروستە: quantity نادروستە');
            }
            if (empty($item['product_name'])) {
                throw new Exception('داتای ئایتم نادروستە: product_name بەتاڵە');
            }
            
            $product_id = $isExternal ? null : (int)$item['product_id'];
            $product_name = cleanInput($item['product_name']);
            $quantity = (float)$item['quantity'];
            $unit_price = (float)$item['unit_price'];
            $total_price = (float)$item['total_price'];
            $price_type = cleanInput($item['price_type'] ?? 'retail');
            $itemCurrency = !empty($item['currency']) && in_array($item['currency'], ['USD', 'IQD']) ? $item['currency'] : $new_currency;
            $unit_id = $item['unit_id'] ?? null;
            $unit_name = $item['unit_name'] ?? 'دانە';
            $unit_symbol = $item['unit_symbol'] ?? '';
            
            // Pre-check: Verify if unit_id exists in product_units. If not, but product exists, fallback to null unit_id
            if (!$isExternal && $unit_id) {
                $checkUnitStmt = $conn->prepare("SELECT id FROM product_units WHERE product_id = ? AND unit_id = ?");
                $checkUnitStmt->bind_param("ii", $product_id, $unit_id);
                $checkUnitStmt->execute();
                if ($checkUnitStmt->get_result()->num_rows === 0) {
                     error_log("Unit ID $unit_id not found for product $product_id. Attempting to find alternative unit.");
                     
                     // Try to find ANY unit for this product
                     $altUnitStmt = $conn->prepare("
                        SELECT pu.unit_id, u.name 
                        FROM product_units pu 
                        JOIN units u ON pu.unit_id = u.id 
                        WHERE pu.product_id = ?
                     ");
                     $altUnitStmt->bind_param("i", $product_id);
                     $altUnitStmt->execute();
                     $altResult = $altUnitStmt->get_result();
                     
                     $foundAlt = false;
                     $firstUnitId = null;
                     $firstUnitName = null;
                     
                     while ($row = $altResult->fetch_assoc()) {
                         if (!$firstUnitId) {
                             $firstUnitId = $row['unit_id'];
                             $firstUnitName = $row['name'];
                         }
                         // Prefer 'دانە' if available
                         if ($row['name'] === 'دانە') {
                             $unit_id = $row['unit_id'];
                             $unit_name = $row['name'];
                             $foundAlt = true;
                             break;
                         }
                     }
                     
                     if (!$foundAlt && $firstUnitId) {
                         // Fallback to first available unit
                         $unit_id = $firstUnitId;
                         $unit_name = $firstUnitName;
                         $foundAlt = true;
                     }
                     
                     if ($foundAlt) {
                         error_log("Falling back to alternative unit: $unit_name ($unit_id)");
                     } else {
                         // No units found at all, assume simple product
                         error_log("No units found for product $product_id, falling back to simple product logic");
                         $unit_id = null;
                     }
                }
            }
            
            // Validate stock for non-external products
            if (!$isExternal && $unit_id) {
                $productStmt = $conn->prepare("
                    SELECT pu.stock_quantity, p.name, u.name as unit_name
                    FROM product_units pu
                    JOIN products p ON pu.product_id = p.id
                    JOIN units u ON pu.unit_id = u.id
                    WHERE pu.product_id = ? AND pu.unit_id = ? AND p.user_id = ?
                ");
                $productStmt->bind_param("iii", $product_id, $unit_id, $userId);
                $productStmt->execute();
                $productResult = $productStmt->get_result();
                
                if (!$productData = $productResult->fetch_assoc()) {
                    throw new Exception("کاڵا بە ID $product_id و یەکەی $unit_name نەدۆزرایەوە");
                }
                if ($productData['stock_quantity'] < $quantity) {
                    throw new Exception("بەردەست ناتەواوە بۆ کاڵای: {$productData['name']} - {$productData['unit_name']}");
                }
            } else if (!$isExternal) {
                $productStmt = $conn->prepare("
                    SELECT
                        COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) AS stock_quantity,
                        p.name
                    FROM products p
                    LEFT JOIN product_units pu_primary
                        ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    LEFT JOIN product_units pu_any
                        ON pu_any.id = (
                            SELECT pu2.id
                            FROM product_units pu2
                            WHERE pu2.product_id = p.id
                            ORDER BY pu2.is_primary DESC, pu2.id ASC
                            LIMIT 1
                        )
                    WHERE p.id = ? AND p.user_id = ?
                ");
                $productStmt->bind_param("ii", $product_id, $userId);
                $productStmt->execute();
                $productResult = $productStmt->get_result();
                
                if (!$productData = $productResult->fetch_assoc()) {
                    throw new Exception("کاڵا بە ID $product_id نەدۆزرایەوە");
                }
                if ($productData['stock_quantity'] < $quantity) {
                    throw new Exception("بەردەست ناتەواوە بۆ کاڵای: {$productData['name']}");
                }
            }
            
            // Insert sale item
            $unit_cost_at_sale = null;
            if (!$isExternal && $product_id !== null) {
                $unit_cost_at_sale = resolveSaleItemUnitCostAtSale($conn, $userId, $product_id, $unit_id, $new_currency);
            }
            $itemStmt = $conn->prepare("
                INSERT INTO sale_items (
                    sale_id, product_id, product_name, quantity,
                    unit_price, total_price, unit_cost_at_sale, price_type, currency, unit_id, unit_name, unit_symbol
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $product_id_param = $product_id === null ? null : (string)$product_id;
            $unitCostAtSaleParam = $unit_cost_at_sale === null ? null : (string)$unit_cost_at_sale;
            $itemStmt->bind_param(
                "issdddsssiss",
                $saleId, $product_id_param, $product_name, $quantity,
                $unit_price, $total_price, $unitCostAtSaleParam, $price_type, $itemCurrency, $unit_id, $unit_name, $unit_symbol
            );
            
            if (!$itemStmt->execute()) {
                throw new Exception('نەتوانرا ئایتمی فرۆشتن تۆمار بکرێت: ' . $itemStmt->error);
            }
            
            // Update product stock (skip for external products)
            if (!$isExternal && $unit_id) {
                // Update product_units table for products with units
                $updateStockStmt = $conn->prepare("
                    UPDATE product_units SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                    WHERE product_id = ? AND unit_id = ?
                ");
                $updateStockStmt->bind_param("dii", $quantity, $product_id, $unit_id);
                if (!$updateStockStmt->execute()) {
                    throw new Exception('نەتوانرا بەردەست نوێ بکرێتەوە');
                }
                
                // Update other units using conversion ratio
                $soldUnitStmt = $conn->prepare("
                    SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?
                ");
                $soldUnitStmt->bind_param("ii", $product_id, $unit_id);
                $soldUnitStmt->execute();
                $soldUnitData = $soldUnitStmt->get_result()->fetch_assoc();
                $sold_unit_ratio = $soldUnitData['conversion_ratio'] ?? 1.0;
                
                $otherUnitsStmt = $conn->prepare("
                    SELECT unit_id, conversion_ratio FROM product_units WHERE product_id = ? AND unit_id != ?
                ");
                $otherUnitsStmt->bind_param("ii", $product_id, $unit_id);
                $otherUnitsStmt->execute();
                $otherUnitsResult = $otherUnitsStmt->get_result();
                
                while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                    $other_unit_ratio = $otherUnit['conversion_ratio'];
                    if ($other_unit_ratio === null || (float)$other_unit_ratio <= 0) {
                        error_log("Stock sync skipped for unit_id={$otherUnit['unit_id']} (product_id=$product_id): invalid conversion_ratio");
                        continue;
                    }
                    $deduction_amount = $quantity * ($sold_unit_ratio / $other_unit_ratio);
                    $updateOtherStmt = $conn->prepare("
                        UPDATE product_units SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                        WHERE product_id = ? AND unit_id = ?
                    ");
                    $updateOtherStmt->bind_param("dii", $deduction_amount, $product_id, $otherUnit['unit_id']);
                    $updateOtherStmt->execute();
                }
            } else if (!$isExternal) {
                // Update primary/fallback unit stock instead of removed products.stock_quantity
                $updateStockStmt = $conn->prepare("
                    UPDATE product_units
                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                    WHERE id = (
                        SELECT pux.id
                        FROM (
                            SELECT pu.id
                            FROM product_units pu
                            JOIN products p ON p.id = pu.product_id
                            WHERE pu.product_id = ? AND p.user_id = ?
                            ORDER BY pu.is_primary DESC, pu.id ASC
                            LIMIT 1
                        ) AS pux
                    )
                ");
                $updateStockStmt->bind_param("dii", $quantity, $product_id, $userId);
                if (!$updateStockStmt->execute()) {
                    throw new Exception('نەتوانرا بەردەست نوێ بکرێتەوە');
                }
            }
        }
        
        // Handle cash purchases for new customer
        if ($new_payment_method === 'cash' && $new_customer_id) {
            $invoice_number = $oldSale['invoice_number'];
            $cashPurchaseStmt = $conn->prepare("
                INSERT INTO customer_cash_purchases (
                    user_id, customer_id, sale_id, invoice_number, 
                    total_amount, discount, final_amount, purchase_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $cashPurchaseStmt->bind_param(
                "iiisddds",
                $userId, $new_customer_id, $saleId, $invoice_number,
                $new_total_amount, $new_discount, $new_final_amount, $new_sale_date
            );
            if (!$cashPurchaseStmt->execute()) {
                throw new Exception('نەتوانرا کڕینی کاش تۆمار بکرێت: ' . $cashPurchaseStmt->error);
            }
        }
        
        // Handle debt/credit for new payment method
        if ($new_payment_method === 'debt' || $new_payment_method === 'credit') {
            $customer_phone = '';
            if ($new_customer_id) {
                $phoneStmt = $conn->prepare("SELECT phone FROM customers WHERE id = ? AND user_id = ?");
                $phoneStmt->bind_param("ii", $new_customer_id, $userId);
                $phoneStmt->execute();
                $phoneResult = $phoneStmt->get_result();
                if ($phoneData = $phoneResult->fetch_assoc()) {
                    $customer_phone = $phoneData['phone'] ?: '';
                }
                $phoneStmt->close();
            }
            
            $debt_type = ($new_payment_method === 'credit') ? 'credit' : 'debt';
            $remaining_debt = $new_final_amount - $new_paid_amount;
            $installment_months = 0;
            $monthly_amount = 0.0;
            $next_payment_date = null;
            
            $debtStmt = $conn->prepare("
                INSERT INTO debts (
                    user_id, customer_id, sale_id, customer_name, customer_phone, total_debt, 
                    paid_amount, remaining_amount, debt_type, installment_months, 
                    monthly_amount, next_payment_date, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
            ");
            $debtStmt->bind_param(
                "iiissdddsddss",
                $userId, $new_customer_id, $saleId, $new_customer_name, $customer_phone, $new_final_amount,
                $new_paid_amount, $remaining_debt, $debt_type, $installment_months,
                $monthly_amount, $next_payment_date, $new_sale_date
            );
            
            if (!$debtStmt->execute()) {
                throw new Exception('نەتوانرا قەرز تۆمار بکرێت: ' . $debtStmt->error);
            }
            $debtId = $conn->insert_id;
            
            $replayedHistoricalPayments = false;
            if ($preserve_payment_history && !empty($oldDebtPayments)) {
                $remainingToReplay = min($new_paid_amount, $new_final_amount);
                foreach ($oldDebtPayments as $oldPayment) {
                    if ($remainingToReplay <= 0) {
                        break;
                    }
                    $amountToInsert = min((float)$oldPayment['payment_amount'], $remainingToReplay);
                    if ($amountToInsert <= 0) {
                        continue;
                    }
                    $paymentStmt = $conn->prepare("
                        INSERT INTO debt_payments (debt_id, payment_amount, payment_date, notes)
                        VALUES (?, ?, ?, ?)
                    ");
                    $paymentDate = !empty($oldPayment['payment_date']) ? $oldPayment['payment_date'] : $new_sale_date;
                    $paymentNote = !empty($oldPayment['notes']) ? $oldPayment['notes'] : 'پارەدان';
                    $paymentStmt->bind_param("idss", $debtId, $amountToInsert, $paymentDate, $paymentNote);
                    if (!$paymentStmt->execute()) {
                        throw new Exception('نەتوانرا مێژووی پارەدان دووبارە تۆمار بکرێت');
                    }
                    $remainingToReplay -= $amountToInsert;
                }
                $replayedHistoricalPayments = true;
            }

            // Record payment if paid amount > 0
            if (!$replayedHistoricalPayments && $new_paid_amount > 0) {
                $paymentStmt = $conn->prepare("
                    INSERT INTO debt_payments (debt_id, payment_amount, payment_date, notes)
                    VALUES (?, ?, ?, ?)
                ");
                $editDebtPaymentNote = 'پارەدان لە کاتی دەستکاریکردنی وەسڵ';
                $paymentStmt->bind_param("idss", $debtId, $new_paid_amount, $new_sale_date, $editDebtPaymentNote);
                if (!$paymentStmt->execute()) {
                    throw new Exception('نەتوانرا پارەدانەکە تۆمار بکرێت');
                }
            }
            
            // Update new customer's total debt
            if ($new_customer_id) {
                $updateNewCustDebt = $conn->prepare("
                    UPDATE customers SET total_debt = (
                        SELECT COALESCE(SUM(remaining_amount), 0) FROM debts WHERE customer_id = ? AND status = 'active'
                    ) WHERE id = ?
                ");
                $updateNewCustDebt->bind_param("ii", $new_customer_id, $new_customer_id);
                $updateNewCustDebt->execute();
            }
        }
        
        // If customer changed and old customer had debt/cash, update old customer's debt too
        // (Already handled above for debt reversal, but also handle if new payment is not debt
        //  but old was, and customer changed)
        if ($old_customer_id && $old_customer_id != $new_customer_id) {
            $updateOldCustDebt2 = $conn->prepare("
                UPDATE customers SET total_debt = (
                    SELECT COALESCE(SUM(remaining_amount), 0) FROM debts WHERE customer_id = ? AND status = 'active'
                ) WHERE id = ?
            ");
            $updateOldCustDebt2->bind_param("ii", $old_customer_id, $old_customer_id);
            $updateOldCustDebt2->execute();
        }
        
        // Commit transaction
        $conn->commit();
        foreach ($affectedProductIds as $pid) {
            $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $pid);
            logProductChangeEvent(
                'sale.update',
                'sale',
                $saleId,
                $beforeSnapshots[$pid] ?? null,
                $afterSnapshot,
                [
                    'user_id' => $userId,
                    'current_user' => $currentUser,
                    'product_id' => $pid,
                    'source_module' => 'api/sales.php',
                    'source_reference' => (string)$saleId
                ]
            );
        }
        
        // Get complete sale data for response
        $saleData = getSaleData($saleId);
        
        // Log activity
        writeLog("Sale updated: ID $saleId, Amount: $new_final_amount by user: {$currentUser['email']}");
        $afterSaleSnapshotForCustomerHistory = getSaleSnapshotForCustomerLogs($conn, $userId, $saleId);
        $afterOldCustomerSnapshot = null;
        if (!empty($old_customer_id)) {
            $afterOldCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)$old_customer_id);
        }
        $afterNewCustomerSnapshot = null;
        if (!empty($new_customer_id)) {
            $afterNewCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, (int)$new_customer_id);
        }
        $customerMetaId = !empty($new_customer_id) ? (int)$new_customer_id : (int)$old_customer_id;
        logCustomerChangeEvent(
            'sale.update',
            'sale',
            $saleId,
            [
                'sale_snapshot' => $beforeSaleSnapshotForCustomerHistory,
                'old_customer_snapshot' => $beforeOldCustomerSnapshot
            ],
            [
                'sale_snapshot' => $afterSaleSnapshotForCustomerHistory,
                'old_customer_snapshot' => $afterOldCustomerSnapshot,
                'new_customer_snapshot' => $afterNewCustomerSnapshot
            ],
            [
                'user_id' => $userId,
                'current_user' => $currentUser,
                'customer_id' => $customerMetaId > 0 ? $customerMetaId : null,
                'sale_id' => $saleId,
                'currency' => (string)$new_currency,
                'source_module' => 'api/sales.php',
                'source_reference' => (string)$saleId
            ]
        );
        
        sendResponse(true, ['sale' => $saleData], 'فرۆشتنەکە بە سەرکەوتوویی نوێ کرایەوە');
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Update sale error: ' . $e->getMessage());
        sendResponse(false, null, $e->getMessage());
    }
}

/**
 * یارمەتیدەر: وەرگرتنی داتای تەواوی فرۆشتن
 */
function getSaleData($saleId) {
    global $conn, $userId, $ownOnlySubUserId;

    // Get sale basic data with customer phone
    $ownFilter = ($ownOnlySubUserId !== null) ? " AND s.sub_user_id = ?" : "";
    $stmt = $conn->prepare("
        SELECT s.*, c.phone as customer_phone
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.id = ? AND s.user_id = ?" . $ownFilter . "
    ");
    if ($ownOnlySubUserId !== null) {
        $stmt->bind_param('iii', $saleId, $userId, $ownOnlySubUserId);
    } else {
        $stmt->bind_param('ii', $saleId, $userId);
    }
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    
    if (!$sale) {
        return null;
    }

    $debtSummaryStmt = $conn->prepare("
        SELECT
            COALESCE(SUM(dp.payment_amount), 0) AS historical_paid,
            COUNT(dp.id) AS payment_count
        FROM debts d
        LEFT JOIN debt_payments dp ON dp.debt_id = d.id
        WHERE d.sale_id = ? AND d.user_id = ?
    ");
    $debtSummaryStmt->bind_param('ii', $saleId, $userId);
    $debtSummaryStmt->execute();
    $debtSummary = $debtSummaryStmt->get_result()->fetch_assoc();
    $debtSummaryStmt->close();
    $sale['debt_summary'] = [
        'historical_paid' => (float)($debtSummary['historical_paid'] ?? 0),
        'payment_count' => (int)($debtSummary['payment_count'] ?? 0),
        'has_payments' => ((int)($debtSummary['payment_count'] ?? 0) > 0)
    ];
    
    // Get sale items
    $itemsStmt = $conn->prepare("
        SELECT si.*, p.barcode 
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        WHERE si.sale_id = ?
        ORDER BY si.id
    ");
    $itemsStmt->bind_param('i', $saleId);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $returnedBySaleItem = getSaleItemReturnedQuantities($conn, $userId, $saleId);
    $totalReturnedAmount = 0.0;

    foreach ($items as &$item) {
        $saleItemId = (int)$item['id'];
        $soldQty = (float)$item['quantity'];
        $returnedQty = (float)($returnedBySaleItem[$saleItemId] ?? 0);
        $editableQty = max(0, $soldQty - $returnedQty);

        $item['sale_item_id'] = $saleItemId;
        $item['original_quantity'] = $soldQty;
        $item['returned_qty'] = $returnedQty;
        $item['editable_quantity'] = $editableQty;
        $item['min_quantity'] = $returnedQty;

        if ($returnedQty > 0 && $soldQty > 0) {
            $lineTotalSold = (float)$item['total_price'];
            $totalReturnedAmount += ($lineTotalSold / $soldQty) * $returnedQty;
        }
    }
    unset($item);

    $sale['items'] = $items;
    $sale['return_summary'] = [
        'has_returns' => !empty($returnedBySaleItem),
        'total_returned_amount' => round($totalReturnedAmount, 3),
    ];
    
    return $sale;
}

?>