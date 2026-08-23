<?php
/**
 * پڕۆسەسکردنی فرۆشتن - ajax/process_sale.php
 */

// پێشگیریکردن لە هەموو output ێک
ob_start();

// ڕێکخستنی error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// دەستپێکردنی session یەکەم جار
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Function بۆ ناردنی JSON
function sendJsonResponse($success, $data = null, $message = '', $httpCode = 200) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    if (!headers_sent()) {
        header_remove();
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    if ($data !== null) {
        if (is_array($data)) {
            $response = array_merge($response, $data);
        } else {
            $response['data'] = $data;
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// تاقیکردنی METHOD
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, null, 'شێوازی داواکاری نادروستە', 405);
}

// بارکردنی فایلەکان بەبێ دووبارە session_start
try {
    // تاقیکردنەوەی ڕێگەی فایلەکان
    $config_path = realpath('../../../config/config.php');
    $security_path = realpath('../../../config/security.php');
    $functions_path = realpath('../../../includes/functions.php');
    
    if (!$config_path || !file_exists($config_path)) {
        throw new Exception('config.php نەدۆزرایەوە');
    }
    
    if (!$security_path || !file_exists($security_path)) {
        throw new Exception('security.php نەدۆزرایەوە');
    }
    
    if (!$functions_path || !file_exists($functions_path)) {
        throw new Exception('functions.php نەدۆزرایەوە');
    }
    
    // بارکردنی فایلەکان بەبێ session دووبارەکردن
    include_once $config_path;
    include_once $security_path;
    include_once $functions_path;
    include_once realpath('../../../includes/product_change_logger.php');
    include_once realpath('../../../includes/profit_schema.php');
    include_once realpath('../../../includes/wallet_service.php');
    
} catch (Exception $e) {
    sendJsonResponse(false, null, 'کێشە لە بارکردنی فایلەکان: ' . $e->getMessage(), 500);
}

// تاقیکردنی database connection
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    sendJsonResponse(false, null, 'کێشە لە پەیوەندی دیتابەیس', 500);
}

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

/**
 * وەرگرتنی ڕێژەی دۆلار→دینار ی POS بە هەمان لۆژیکی ajax/get_exchange_rate.php:
 * دوایین offer_price لە dollar_prices + زیادکراوی دەستیی بەکارهێنەر.
 * ئەمە هەمان ئەو نرخەیە کە کاشێر لە کاتی فرۆشتندا دەیبینێت.
 */
function getPosUsdToIqdRate($conn, $userId) {
    $rate = 0.0;
    $result = $conn->query("SELECT offer_price FROM dollar_prices ORDER BY last_updated DESC LIMIT 1");
    if ($result) {
        if ($row = $result->fetch_assoc()) {
            $offerPrice = (float)$row['offer_price'];
            $manualAdjustment = function_exists('getManualAdjustment') ? (float)getManualAdjustment($userId) : 0.0;
            $rate = $offerPrice + $manualAdjustment;
        }
        $result->free();
    }
    if ($rate <= 0 && function_exists('getExchangeRate')) {
        $fallback = getExchangeRate($userId, 'USD', 'IQD');
        if ($fallback !== false && $fallback > 0) {
            $rate = (float)$fallback;
        }
    }
    if ($rate <= 0) {
        $rate = 1400.0;
    }
    return $rate;
}

/**
 * گۆڕینی نرخی کڕینی کاڵا بۆ دراوی فرۆشتن، بۆ تۆمارکردنی unit_cost_at_sale.
 *
 * دینار و دۆلار لە ڕاپۆرتدا هەرگیز تێکەڵ ناکرێن، بۆیە پێویستە تێچووی کاڵا
 * بە هەمان دراوی فرۆشتن هەڵبگیرێت. ئەگەر دراوی یەکەی کاڵا (buy_price)
 * جیاواز بێت لە دراوی فرۆشتن، دەگۆڕدرێت. ئەگەرنا وەک خۆی دەمێنێتەوە.
 */
function convertUnitCostToSaleCurrency($buyPrice, $unitCurrency, $saleCurrency, $rate) {
    $buyPrice = (float)$buyPrice;
    $unitCurrency = ($unitCurrency === 'USD' || $unitCurrency === 'IQD') ? $unitCurrency : 'IQD';
    if ($buyPrice <= 0 || $unitCurrency === $saleCurrency || $rate <= 0) {
        return $buyPrice;
    }
    if ($unitCurrency === 'USD' && $saleCurrency === 'IQD') {
        return round($buyPrice * $rate);           // دۆلار → دینار (بێ خانەی دەیی)
    }
    if ($unitCurrency === 'IQD' && $saleCurrency === 'USD') {
        return round($buyPrice / $rate, 2);        // دینار → دۆلار (٢ خانەی دەیی)
    }
    return $buyPrice;
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

// وەرگرتنی user ID لە session
$userId = null;
if (isset($_SESSION['user_data']['id']) && is_numeric($_SESSION['user_data']['id'])) {
    $userId = (int)$_SESSION['user_data']['id'];
} elseif (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
} elseif (isset($_SESSION['id']) && is_numeric($_SESSION['id'])) {
    $userId = (int)$_SESSION['id'];
}

// debug session
if (!$userId) {
    // نیشاندانی session data بۆ debug
    $sessionInfo = [];
    if (isset($_SESSION)) {
        foreach ($_SESSION as $key => $value) {
            if (!is_object($value) && !is_resource($value)) {
                $sessionInfo[$key] = $value;
            }
        }
    }
    
    sendJsonResponse(false, [
        'session_data' => $sessionInfo,
        'session_id' => session_id(),
        'session_status' => session_status()
    ], 'دەسەڵاتت نییە - session نەدۆزرایەوە', 401);
}

// خوێندنەوەی داتا
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    sendJsonResponse(false, null, 'داتای ناردراو درست نییە', 400);
}

// تاقیکردنەوەی CSRF (بەبێ dependency زۆر)
if (empty($input['csrf_token'])) {
    sendJsonResponse(false, null, 'CSRF token پێویستە', 403);
}

// تاقیکردنەوەی داتای پێویست
if (empty($input['items']) || !is_array($input['items'])) {
    sendJsonResponse(false, null, 'ئایتەمەکانی فرۆشتن پێویستە', 400);
}

if (empty($input['final_amount']) || $input['final_amount'] <= 0) {
    sendJsonResponse(false, null, 'بڕی کۆتایی پێویستە', 400);
}

// ئامادەکردنی داتا
$customer_name = htmlspecialchars(trim($input['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$payment_method = htmlspecialchars(trim($input['payment_method'] ?? 'cash'), ENT_QUOTES, 'UTF-8');
$wallet_id = isset($input['wallet_id']) ? (int)$input['wallet_id'] : 0;
$total_amount = (float)$input['total_amount'];
$discount = (float)($input['discount'] ?? 0);
$final_amount = (float)$input['final_amount'];
$items = $input['items'];
$receipt_type = htmlspecialchars(trim($input['receipt_type'] ?? 'no_paper'), ENT_QUOTES, 'UTF-8');

// تاقیکردنەوەی شێوازی پارەدان
$allowedPaymentMethods = ['cash', 'debt', 'installment'];
if (!in_array($payment_method, $allowedPaymentMethods)) {
    sendJsonResponse(false, null, 'شێوەی پارەدان نادروستە', 400);
}

if ($payment_method === 'cash' && $wallet_id <= 0) {
    sendJsonResponse(false, null, 'بۆ فرۆشتنی نەقد، قاسە پێویستە', 422);
}

// پڕۆسەسکردنی فرۆشتن
try {
    $affectedProductIds = [];
    $beforeSnapshots = [];
    foreach ($items as $logItem) {
        $pid = isset($logItem['product_id']) ? (int)$logItem['product_id'] : 0;
        if ($pid > 0) {
            $affectedProductIds[$pid] = $pid;
        }
    }
    foreach ($affectedProductIds as $pid) {
        $beforeSnapshots[$pid] = getProductSnapshotForLogs($conn, $userId, $pid);
    }
    $conn->begin_transaction();

    // دروستکردنی ژمارەی فاتورە
    $prefix = 'INV';
    $date = date('Ymd');
    $time = date('His');
    $invoice_number = $prefix . $date . $time . rand(10, 99);

    // Get currency from input or default to IQD
    $saleCurrency = !empty($input['currency']) && in_array($input['currency'], ['USD', 'IQD']) ? $input['currency'] : 'IQD';
    
    // Check if currency column exists in sales table (should exist after migration)
    $currencyColumnExists = false;
    $result = $conn->query("SHOW COLUMNS FROM sales LIKE 'currency'");
    if ($result && $result->num_rows > 0) {
        $currencyColumnExists = true;
    }
    if ($result) {
        $result->free();
    }
    
    // Always use currency column (migration ensures it exists)
    // If column doesn't exist, try to add it
    if (!$currencyColumnExists) {
        try {
            $conn->query("ALTER TABLE `sales` ADD COLUMN `currency` ENUM('IQD', 'USD') NOT NULL DEFAULT 'IQD' AFTER `final_amount`");
            $currencyColumnExists = true;
        } catch (Exception $e) {
            error_log('Failed to add currency column to sales: ' . $e->getMessage());
        }
    }
    
    // Get sale date from input or use current date/time
    $sale_date = null;
    if (!empty($input['sale_date'])) {
        $sale_date = $input['sale_date'];
    } else {
        $sale_date = date('Y-m-d H:i:s');
    }
    
    // Build INSERT query - always include currency now
    $salesSql = "
        INSERT INTO sales (
            user_id, user_type, sub_user_id, customer_id, invoice_number, customer_name, 
            total_amount, discount, final_amount, currency, payment_method, wallet_id,
            payment_status, paid_amount, remaining_amount, sale_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $salesBindTypes = 'isiissdddssisdds';
    
    // خەزنکردنی فرۆشتن
    $stmt = $conn->prepare($salesSql);

    if (!$stmt) {
        throw new Exception('کێشە لە ئامادەکردنی داواکاری sales: ' . $conn->error);
    }

    $customer_id = null;
    // For cash payments, also track customer if selected
    if (isset($input['customer_id']) && $input['customer_id']) {
        $customer_id = (int)$input['customer_id'];
    }
    
    // دیاریکردنی حاڵەتی پارەدان
    $desired_payment_status = 'paid';
    $paid_amount = $final_amount;
    $remaining_amount = 0;

    if ($payment_method === 'debt') {
        $desired_payment_status = 'pending';
        $paid_amount = 0;
        $remaining_amount = $final_amount;
    } elseif ($payment_method === 'installment') {
        $desired_payment_status = 'partial';
        $paid_amount = 0;
        $remaining_amount = $final_amount;
    } elseif ($payment_method === 'cash') {
        // For cash payments, the full amount is paid
        $desired_payment_status = 'paid';
        $paid_amount = $final_amount;
        $remaining_amount = 0;
    }
    $payment_status = resolveSalePaymentStatus($conn, $desired_payment_status);
    if ($payment_status !== $desired_payment_status) {
        error_log('payment_status fallback applied in POS process_sale: ' . $desired_payment_status . ' => ' . $payment_status);
    }

    // Determine user type and sub-user ID
    $userType = 'main';
    $subUserId = null;
    
    if (isset($_SESSION['user_data']['user_type']) && $_SESSION['user_data']['user_type'] === 'sub') {
        $userType = 'sub';
        $subUserId = isset($_SESSION['user_data']['sub_user_id']) ? (int)$_SESSION['user_data']['sub_user_id'] : null;
    }

    // Bind parameters - always include currency
    $stmt->bind_param($salesBindTypes, 
        $userId, $userType, $subUserId, $customer_id, $invoice_number, $customer_name,
        $total_amount, $discount, $final_amount, $saleCurrency, $payment_method, $wallet_id,
        $payment_status, $paid_amount, $remaining_amount, $sale_date
    );

    if (!$stmt->execute()) {
        $errorMsg = 'کێشە لە خەزنکردنی فرۆشتن: ' . $stmt->error . ' (Error Code: ' . $stmt->errno . ')';
        error_log($errorMsg);
        error_log('SQL: ' . $salesSql);
        error_log('Bind Types: ' . $salesBindTypes);
        error_log('Currency: ' . $saleCurrency);
        throw new Exception($errorMsg);
    }
    
    $sale_id = $conn->insert_id;
    $stmt->close();

    // Check if currency column exists in sale_items table (should exist after migration)
    $itemCurrencyColumnExists = false;
    $result = $conn->query("SHOW COLUMNS FROM sale_items LIKE 'currency'");
    if ($result && $result->num_rows > 0) {
        $itemCurrencyColumnExists = true;
    }
    if ($result) {
        $result->free();
    }
    
    // Always use currency column (migration ensures it exists)
    // If column doesn't exist, try to add it
    if (!$itemCurrencyColumnExists) {
        try {
            $conn->query("ALTER TABLE `sale_items` ADD COLUMN `currency` ENUM('IQD', 'USD') NOT NULL DEFAULT 'IQD' AFTER `total_price`");
            $itemCurrencyColumnExists = true;
        } catch (Exception $e) {
            error_log('Failed to add currency column to sale_items: ' . $e->getMessage());
        }
    }
    
    // Build INSERT query for items - always include currency now
    $itemSql = "
        INSERT INTO sale_items (
            sale_id, product_id, product_name, quantity, 
            unit_price, total_price, unit_cost_at_sale, price_type, currency
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $itemBindTypes = 'iisiddsss';
    
    // خەزنکردنی ئایتەمەکان
    $item_stmt = $conn->prepare($itemSql);

    if (!$item_stmt) {
        throw new Exception('کێشە لە ئامادەکردنی ئایتەمەکان: ' . $conn->error);
    }

    $externalCostsTableEnsured = false;
    $primaryUnitCostStmt = $conn->prepare("
        SELECT pu.buy_price, pu.currency
        FROM product_units pu
        JOIN products p ON p.id = pu.product_id
        WHERE pu.product_id = ? AND p.user_id = ?
        ORDER BY pu.is_primary DESC, pu.id ASC
        LIMIT 1
    ");
    // ڕێژەی ئاڵوگۆڕ کە بۆ گۆڕینی تێچووی کاڵا بۆ دراوی فرۆشتن بەکاردێت
    $posUsdToIqdRate = getPosUsdToIqdRate($conn, $userId);
    foreach ($items as $item) {
        $product_id = isset($item['product_id']) ? (int)$item['product_id'] : 0;
        $rawName = $item['name'] ?? ($item['product_name'] ?? '');
        $product_name = htmlspecialchars(trim((string)$rawName), ENT_QUOTES, 'UTF-8');
        $quantity = (int)($item['quantity'] ?? 0);
        $unit_price = isset($item['price']) ? (float)$item['price'] : (float)($item['unit_price'] ?? 0);
        $total_price = $unit_price * $quantity;
        $price_type = htmlspecialchars(trim($item['price_type'] ?? 'retail'), ENT_QUOTES, 'UTF-8');
        $isExternalFlag = !empty($item['is_external']) || !empty($item['isExternal']);
        $is_external = ($product_id <= 0 || $isExternalFlag);
        
        // Get currency from item or product, default to sale currency
        $itemCurrency = !empty($item['currency']) && in_array($item['currency'], ['USD', 'IQD']) 
            ? $item['currency'] 
            : $saleCurrency;

        $unit_cost_at_sale = null;
        if (!$is_external && $product_id > 0 && $primaryUnitCostStmt) {
            $primaryUnitCostStmt->bind_param('ii', $product_id, $userId);
            if ($primaryUnitCostStmt->execute()) {
                $unitCostRow = $primaryUnitCostStmt->get_result()->fetch_assoc();
                if ($unitCostRow && $unitCostRow['buy_price'] !== null) {
                    // نرخی کڕین بە دراوی یەکەی کاڵا هەڵگیراوە (دینار یان دۆلار).
                    // پێویستە بگۆڕدرێت بۆ دراوی فرۆشتن تاکو لە ڕاپۆرتی قازانجدا
                    // تێچوو و داهات بە هەمان دراو بەراورد بکرێن.
                    $rawBuyPrice = (float)$unitCostRow['buy_price'];
                    $unitCostCurrency = $unitCostRow['currency'] ?? 'IQD';
                    $unit_cost_at_sale = convertUnitCostToSaleCurrency(
                        $rawBuyPrice,
                        $unitCostCurrency,
                        $saleCurrency,
                        $posUsdToIqdRate
                    );
                }
            }
        }
        $unitCostAtSaleParam = $unit_cost_at_sale === null ? null : (string)$unit_cost_at_sale;

        // Bind parameters - always include currency
        $item_stmt->bind_param($itemBindTypes, 
            $sale_id, $product_id, $product_name, $quantity,
            $unit_price, $total_price, $unitCostAtSaleParam, $price_type, $itemCurrency
        );
        
        if (!$item_stmt->execute()) {
            $errorMsg = 'کێشە لە خەزنکردنی ئایتەم: ' . $item_stmt->error . ' (Error Code: ' . $item_stmt->errno . ')';
            error_log($errorMsg);
            error_log('Item SQL: ' . $itemSql);
            error_log('Item Bind Types: ' . $itemBindTypes);
            error_log('Item Currency: ' . $itemCurrency);
            error_log('Item Data: ' . json_encode($item));
            throw new Exception($errorMsg);
        }

        $sale_item_id = $conn->insert_id;
        // نرخی کڕینی کاڵای دەرەکی بۆ حیسابکردنی قازانج
        if ($is_external && $sale_item_id) {
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

        // کەمکردنەوەی مەوجودی — یەکەی فرۆشراو کەم دەکرێتەوە و یەکەکانی تری هەمان کاڵا
        // بە conversion_ratio هاوکات (sync) دەکرێن، وەک لە api/sales.php و orders.php.
        if ($product_id > 0 && !$is_external) {
            $quantityD = (float)$quantity;
            $sold_unit_id = isset($item['unit_id']) && $item['unit_id'] !== null && $item['unit_id'] !== ''
                ? (int)$item['unit_id']
                : 0;

            // ڕێژەی گۆڕینی یەکەی فرۆشراو وەربگرە و دڵنیابەرەوە کە ئەم یەکەیە بۆ ئەم کاڵایە هەیە
            $soldUnitRatio = null;
            if ($sold_unit_id > 0) {
                $soldRatioStmt = $conn->prepare("
                    SELECT pu.conversion_ratio
                    FROM product_units pu
                    JOIN products p ON p.id = pu.product_id
                    WHERE pu.product_id = ? AND pu.unit_id = ? AND p.user_id = ?
                    LIMIT 1
                ");
                if ($soldRatioStmt) {
                    $soldRatioStmt->bind_param('iii', $product_id, $sold_unit_id, $userId);
                    $soldRatioStmt->execute();
                    $soldRatioRow = $soldRatioStmt->get_result()->fetch_assoc();
                    $soldRatioStmt->close();
                    if ($soldRatioRow) {
                        $soldUnitRatio = (float)($soldRatioRow['conversion_ratio'] ?? 1.0);
                        if ($soldUnitRatio <= 0) {
                            $soldUnitRatio = 1.0;
                        }
                    }
                }
            }

            if ($sold_unit_id > 0 && $soldUnitRatio !== null) {
                // (1) یەکەی فرۆشراو کەم بکەرەوە لەگەڵ پارێزەری بەردەست (ڕێگری لە فرۆشتنی زیاتر لە مەوجودی)
                $updateStock = $conn->prepare("
                    UPDATE product_units
                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                    WHERE product_id = ? AND unit_id = ? AND stock_quantity >= ?
                ");
                if ($updateStock) {
                    $updateStock->bind_param('diid', $quantityD, $product_id, $sold_unit_id, $quantityD);
                    if (!$updateStock->execute()) {
                        throw new Exception('کێشە لە نوێکردنەوەی مەوجودی: ' . $updateStock->error);
                    }
                    if ($updateStock->affected_rows < 1) {
                        throw new Exception('بەردەستی کۆگا کەمە یان کاڵاکە یەکەی تۆمارکراوی نییە');
                    }
                    $updateStock->close();
                }

                // (2) یەکەکانی تری هەمان کاڵا بە ڕێژەی گۆڕین هاوکات بکەرەوە
                //     فۆرمول: بڕی فرۆشراو × (ڕێژەی یەکەی فرۆشراو ÷ ڕێژەی یەکەی تر)
                $otherUnitsStmt = $conn->prepare("
                    SELECT unit_id, conversion_ratio
                    FROM product_units
                    WHERE product_id = ? AND unit_id != ?
                ");
                if ($otherUnitsStmt) {
                    $otherUnitsStmt->bind_param('ii', $product_id, $sold_unit_id);
                    $otherUnitsStmt->execute();
                    $otherUnitsResult = $otherUnitsStmt->get_result();
                    while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                        $otherUnitId = (int)$otherUnit['unit_id'];
                        $otherUnitRatio = (float)($otherUnit['conversion_ratio'] ?? 1.0);
                        if ($otherUnitRatio <= 0) {
                            // پارێزەری دابەشکردن بەسەر سفر — ئەم یەکەیە تێپەڕ بکە
                            error_log("POS stock sync skipped for unit_id=$otherUnitId (product_id=$product_id): invalid conversion_ratio=$otherUnitRatio");
                            continue;
                        }
                        $deductionAmount = $quantityD * ($soldUnitRatio / $otherUnitRatio);
                        $updateOtherUnitStmt = $conn->prepare("
                            UPDATE product_units
                            SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                            WHERE product_id = ? AND unit_id = ?
                        ");
                        if ($updateOtherUnitStmt) {
                            $updateOtherUnitStmt->bind_param('dii', $deductionAmount, $product_id, $otherUnitId);
                            if (!$updateOtherUnitStmt->execute()) {
                                error_log('Failed to sync other unit stock: ' . $updateOtherUnitStmt->error);
                            }
                            $updateOtherUnitStmt->close();
                        }
                    }
                    $otherUnitsStmt->close();
                }
            } else {
                // فۆڵباک: کاتێک unit_id نەنێردراوە یان نەدۆزرایەوە، یەکەی سەرەکی/یەکەم کەم بکەرەوە
                $updateStock = $conn->prepare("
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
                    AND stock_quantity >= ?
                ");
                if ($updateStock) {
                    $updateStock->bind_param('diid', $quantityD, $product_id, $userId, $quantityD);
                    if (!$updateStock->execute()) {
                        throw new Exception('کێشە لە نوێکردنەوەی مەوجودی: ' . $updateStock->error);
                    }
                    if ($updateStock->affected_rows < 1) {
                        throw new Exception('بەردەستی کۆگا کەمە یان کاڵاکە یەکەی تۆمارکراوی نییە');
                    }
                    $updateStock->close();
                }
            }
        }
    }
    
    $item_stmt->close();
    if ($primaryUnitCostStmt) {
        $primaryUnitCostStmt->close();
    }
    
    // Track cash purchases for customers if payment method is cash and customer is selected
    if ($payment_method === 'cash' && !empty($customer_id) && $customer_id > 0) {
        $cash_purchase_stmt = $conn->prepare("
            INSERT INTO customer_cash_purchases (
                user_id, customer_id, sale_id, invoice_number, 
                total_amount, discount, final_amount, purchase_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if ($cash_purchase_stmt) {
            $cash_purchase_stmt->bind_param('iiisddd', 
                $userId, $customer_id, $sale_id, $invoice_number,
                $total_amount, $discount, $final_amount
            );
            
            if (!$cash_purchase_stmt->execute()) {
                // Keep sale successful even when this optional tracking insert fails.
                error_log('Cash purchase tracking failed: ' . $cash_purchase_stmt->error);
            }
            $cash_purchase_stmt->close();
        }
    }

    if ($payment_method === 'cash') {
        if (!walletPostEntry(
            $conn,
            $userId,
            $wallet_id,
            'sale_income',
            'in',
            $saleCurrency,
            $final_amount,
            'sale',
            $sale_id,
            'POS sale income',
            $userId
        )) {
            throw new Exception('نەتوانرا جوڵەی قاسەی فرۆشتن تۆمار بکرێت');
        }
    }
    
    // Log activity
    $activityAction = "فرۆشتن دروستکرا - ژمارەی فاتورە: $invoice_number";
    $activityDescription = "فرۆشتن بە سەرکەوتوویی دروستکرا - کڕیار: $customer_name - کۆی بڕ: $final_amount دینار - جۆری پارەدان: $payment_method";
    
    if ($userType === 'sub' && $subUserId) {
        $activityDescription .= " - لەلایەن کارمەندەوە";
    }
    
    if (function_exists('logActivity')) {
        logActivity($activityAction, $activityDescription);
    }
    
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
                'current_user' => $_SESSION['user_data'] ?? ['id' => $userId],
                'product_id' => $pid,
                'source_module' => 'user/pos/ajax/process_sale.php',
                'source_reference' => (string)$invoice_number
            ]
        );
    }

    // ئامادەکردنی وەڵام
    $response_data = [
        'sale_id' => $sale_id,
        'invoice_number' => $invoice_number,
        'total_amount' => $total_amount,
        'discount' => $discount,
        'final_amount' => $final_amount,
        'payment_method' => $payment_method,
        'receipt_type' => $receipt_type,
        'user_id' => $userId // بۆ debug
    ];

    if ($receipt_type !== 'no_paper') {
        $response_data['receipt_id'] = $sale_id;
        $response_data['print_url'] = "../receipts/print.php?id={$sale_id}&type={$receipt_type}";
    }

    sendJsonResponse(true, $response_data, 'فرۆشتن بە سەرکەوتووی تەواو بوو');

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    
    // Log detailed error information
    error_log('Sale processing error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    if (isset($conn) && $conn->error) {
        error_log('Database error: ' . $conn->error);
    }
    
    sendJsonResponse(false, [
        'error_details' => $e->getMessage(),
        'user_id' => $userId,
        'error_code' => $e->getCode()
    ], 'هەڵەیەک ڕوویدا: ' . $e->getMessage(), 500);
}
?>