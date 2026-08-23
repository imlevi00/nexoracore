<?php
/**
 * وەرگرتنی کاڵاکان بۆ POS - ajax/get_products.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';

SessionManager::requireAuth('user');


// تاقیکردنی داواکاری
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !Security::validateCSRFToken($input['csrf_token'] ?? '')) {
    sendJsonResponse(false, null, 'ناردنی ناپێویست', 403);
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

try {
    // وەرگرتنی هەموو کاڵاکان
    $query = "
        SELECT p.id, p.user_id, p.category_id, p.name, p.barcode, p.expiry_date, p.image_path, p.created_at, p.updated_at,
               c.name as category_name,
               COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as buy_price,
               COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
               COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
               COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
               COALESCE(pu_primary.currency, pu_any.currency, 'IQD') as currency,
               COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
               COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) as min_stock,
               DATE_FORMAT(p.expiry_date, '%Y-%m-%d') as expiry_date_formatted,
               CASE 
                   WHEN p.expiry_date IS NULL THEN 0
                   WHEN p.expiry_date < CURDATE() THEN 1 
                   ELSE 0 
               END as is_expired,
               CASE 
                   WHEN COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) <= COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) THEN 1 
                   ELSE 0 
               END as is_low_stock
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
        WHERE p.user_id = ? 
        ORDER BY p.name ASC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('هەڵەیەک ڕوویدا لە ئامادەکردنی داواکاری: ' . $conn->error);
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $products = [];
    while ($row = $result->fetch_assoc()) {
        // پاککردنەوە و گۆڕینی داتاکان
        $product = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
            'category_name' => $row['category_name'] ?? 'هیچ کەتەگۆرییەک',
            'name' => htmlspecialchars($row['name']),
            'barcode' => $row['barcode'] ?? '',
            'buy_price' => (float)($row['buy_price'] ?? 0),
            'sell_price' => (float)($row['sell_price'] ?? 0),
            'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
            'special_price' => (float)($row['special_price'] ?? 0),
            'currency' => $row['currency'] ?? 'IQD',
            'stock_quantity' => (int)($row['stock_quantity'] ?? 0),
            'min_stock' => (int)($row['min_stock'] ?? 0),
            'expiry_date' => $row['expiry_date'],
            'expiry_date_formatted' => $row['expiry_date_formatted'],
            'is_expired' => (bool)$row['is_expired'],
            'is_low_stock' => (bool)$row['is_low_stock'],
            'image_path' => $row['image_path'] ?? null,
            'image_url' => product_image_url($row['image_path'] ?? null),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];

        // حیسابکردنی قازانج
        $product['profit_per_unit'] = $product['sell_price'] - $product['buy_price'];
        $product['profit_percentage'] = $product['buy_price'] > 0 ? 
            (($product['profit_per_unit'] / $product['buy_price']) * 100) : 0;

        // تاقیکردنەوەی ئاگاداری
        $warnings = [];
        
        if ($product['is_expired']) {
            $warnings[] = 'بەسەرچووە';
        } elseif ($row['expiry_date'] && strtotime($row['expiry_date']) <= strtotime('+7 days')) {
            $warnings[] = 'بەم نزیکانە بەسەردەچێت';
        }
        
        if ($product['is_low_stock']) {
            $warnings[] = 'مەوجودی کەمە';
        }
        
        if ($product['stock_quantity'] <= 0) {
            $warnings[] = 'تەواو بووە';
        }

        $product['warnings'] = $warnings;
        $product['has_warnings'] = !empty($warnings);

        $products[] = $product;
    }

    // نارەوەی ئەنجام
    sendJsonResponse(true, [
        'products' => $products,
        'count' => count($products),
        'timestamp' => date('Y-m-d H:i:s')
    ], 'کاڵاکان بە سەرکەوتووی بارکران');

} catch (Exception $e) {
    error_log("Get products error: " . $e->getMessage());
    sendJsonResponse(false, null, 'هەڵەیەک ڕوویدا: ' . $e->getMessage(), 500);
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