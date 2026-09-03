<?php
/**
 * گەڕانی کاڵا بە بارکۆد یان ناو - ajax/search_product.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/business_type_helpers.php';

// تاقیکردنی دەسەڵات
SessionManager::requireAuth('user');

// تاقیکردنی داواکاری
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !Security::validateCSRFToken($input['csrf_token'] ?? '')) {
    sendJsonResponse(false, null, 'ناردنی ناپێویست', 403);
}

$query = trim($input['query'] ?? '');
if (empty($query)) {
    sendJsonResponse(false, null, 'تکایە شتێک بنووسە بۆ گەڕان');
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$isCurtainShopMode = isCurtainShopMode($conn, (int)$userId);

try {
    // گەڕان بە بارکۆد یەکەم جار (لە products.barcode یان product_barcodes.barcode)
    // تەنها ئەو کاڵایانە بگەڕێ کە یەکەیان هەیە و بەردەستیان > 0
    $stmt = $conn->prepare("
        SELECT p.id, p.user_id, p.category_id, p.name, p.barcode, p.expiry_date, p.image_path, p.created_at, p.updated_at,
               c.name as category_name,
               COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as buy_price,
               COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
               COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
               COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
               COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
               COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) as min_stock,
               CASE 
                   WHEN p.expiry_date IS NULL THEN 0
                   WHEN p.expiry_date < CURDATE() THEN 1 
                   ELSE 0 
               END as is_expired
        FROM products p 
        LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
        LEFT JOIN product_units pu_any ON pu_any.id = (
            SELECT pu2.id
            FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        LEFT JOIN categories c ON p.category_id = c.id 
        LEFT JOIN product_barcodes pb ON p.id = pb.product_id
        WHERE p.user_id = ? 
        AND (p.barcode = ? OR pb.barcode = ?)
        AND EXISTS (SELECT 1 FROM product_units WHERE product_id = p.id)
        AND EXISTS (
            SELECT 1 FROM product_units pu
            WHERE pu.product_id = p.id AND pu.stock_quantity > 0
        )
        LIMIT 1
    ");

    $stmt->bind_param('iss', $userId, $query, $query);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();

    // ئەگەر بە بارکۆد نەدۆزرایەوە، گەڕان بە ناو
    if (!$product) {
        $stmt = $conn->prepare("
            SELECT p.id, p.user_id, p.category_id, p.name, p.barcode, p.expiry_date, p.image_path, p.created_at, p.updated_at,
                   c.name as category_name,
                   COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as buy_price,
                   COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
                   COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
                   COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
                   COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
                   COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) as min_stock,
                   CASE 
                       WHEN p.expiry_date IS NULL THEN 0
                       WHEN p.expiry_date < CURDATE() THEN 1 
                       ELSE 0 
                   END as is_expired
            FROM products p 
            LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
            LEFT JOIN product_units pu_any ON pu_any.id = (
                SELECT pu2.id
                FROM product_units pu2
                WHERE pu2.product_id = p.id
                ORDER BY pu2.is_primary DESC, pu2.id ASC
                LIMIT 1
            )
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.user_id = ? AND p.name LIKE ?
            ORDER BY 
                CASE WHEN p.name = ? THEN 1 ELSE 2 END,
                p.name ASC
            LIMIT 1
        ");

        $searchTerm = '%' . $query . '%';
        $stmt->bind_param('iss', $userId, $searchTerm, $query);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
    }

    if (!$product) {
        sendJsonResponse(false, null, 'کاڵا نەدۆزرایەوە');
    }

    // پاککردنەوە و ئامادەکردنی داتاکان
    $productData = [
        'id' => (int)$product['id'],
        'user_id' => (int)$product['user_id'],
        'category_id' => $product['category_id'] ? (int)$product['category_id'] : null,
        'category_name' => $product['category_name'] ?? 'هیچ کەتەگۆرییەک',
        'name' => htmlspecialchars($product['name']),
        'barcode' => $product['barcode'] ?? '',
        'buy_price' => (float)$product['buy_price'],
        'sell_price' => (float)$product['sell_price'],
        'wholesale_price' => (float)$product['wholesale_price'],
        'special_price' => (float)$product['special_price'],
        'stock_quantity' => (int)$product['stock_quantity'],
        'min_stock' => (int)$product['min_stock'],
        'expiry_date' => $isCurtainShopMode ? null : $product['expiry_date'],
        'is_expired' => $isCurtainShopMode ? false : (bool)$product['is_expired'],
        'image_path' => $product['image_path'] ?? null,
        'image_url' => product_image_url($product['image_path'] ?? null),
        'created_at' => $product['created_at'],
        'updated_at' => $product['updated_at']
    ];

    // حیسابکردنی قازانج
    $productData['profit_per_unit'] = $productData['sell_price'] - $productData['buy_price'];
    $productData['profit_percentage'] = $productData['buy_price'] > 0 ? 
        (($productData['profit_per_unit'] / $productData['buy_price']) * 100) : 0;

    // تاقیکردنەوەی ئاگاداریەکان
    $warnings = [];
    
    if (empty($isCurtainShopMode)) {
        if ($productData['is_expired']) {
            $warnings[] = 'بەسەرچووە';
        } elseif ($product['expiry_date'] && strtotime($product['expiry_date']) <= strtotime('+7 days')) {
            $warnings[] = 'بەم نزیکانە بەسەردەچێت';
        }
    }
    
    if ($productData['stock_quantity'] <= $productData['min_stock']) {
        $warnings[] = 'مەوجودی کەمە';
    }
    
    if ($productData['stock_quantity'] <= 0) {
        $warnings[] = 'ئەم کاڵایە بەردەست نییە';
    }

    $productData['warnings'] = $warnings;
    $productData['has_warnings'] = !empty($warnings);

    // لۆگکردنی گەڕان
    logActivity($userId, 'product_search', "گەڕان بۆ: $query - دۆزرایەوە: " . $productData['name']);

    sendJsonResponse(true, [
        'product' => $productData,
        'search_query' => $query,
        'search_method' => isset($product['barcode']) && $product['barcode'] === $query ? 'barcode' : 'name'
    ], 'کاڵا بە سەرکەوتووی دۆزرایەوە');

} catch (Exception $e) {
    error_log("Search product error: " . $e->getMessage());
    sendJsonResponse(false, null, 'هەڵەیەک ڕوویدا لە گەڕان', 500);
}

/**
 * لۆگکردنی چالاکی
 */
function logActivity($userId, $action, $description) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->bind_param('issss', $userId, $action, $description, $ipAddress, $userAgent);
        $stmt->execute();
    } catch (Exception $e) {
        // لۆگکردنی هەڵە بەبێ ئەوەی کاریگەری لەسەر function ی سەرەکی هەبێت
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * ناردنی وەڵامی JSON
 */
function sendJsonResponse($success, $data = null, $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
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
?>