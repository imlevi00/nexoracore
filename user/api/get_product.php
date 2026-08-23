<?php
/**
 * API بۆ وەرگرتنی تەواوی زانیاری کاڵایەک - user/api/get_product.php
 */

require_once '../../includes/functions.php';
require_once '../../config/config.php';
require_once '../../config/security.php';

SessionManager::requireAuth('user');

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);

if (!$productId) {
    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            p.id, p.name, p.barcode, p.expiry_date, p.image_path,
            c.name as category_name,
            COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as buy_price,
            COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
            COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
            COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
            COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
            COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) as min_stock
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
        WHERE p.id = ? AND p.user_id = ?
    ");
    
    $stmt->bind_param('ii', $productId, $userId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    
    // تبدیل أنواع البیانات
    $productData = [
        'id' => (int)$product['id'],
        'name' => $product['name'],
        'barcode' => $product['barcode'],
        'category_name' => $product['category_name'],
        'buy_price' => (float)$product['buy_price'],
        'sell_price' => (float)$product['sell_price'],
        'wholesale_price' => (float)$product['wholesale_price'],
        'special_price' => (float)$product['special_price'],
        'stock_quantity' => (int)$product['stock_quantity'],
        'min_stock' => (int)$product['min_stock'],
        'expiry_date' => $product['expiry_date'],
        'image_path' => $product['image_path'],
        'image_url' => product_image_url($product['image_path'] ?? null)
    ];
    
    echo json_encode([
        'success' => true,
        'product' => $productData
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}
?>