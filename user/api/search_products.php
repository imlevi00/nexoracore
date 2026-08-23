<?php
/**
 * API گەڕان بۆ کاڵاکان - user/api/search_products.php
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
$query = trim($input['q'] ?? '');

if (empty($query)) {
    echo json_encode(['success' => false, 'message' => 'Query is required']);
    exit;
}

try {
    $searchQuery = "%$query%";
    
    $stmt = $conn->prepare("
        SELECT
            p.id, p.name, p.barcode, p.expiry_date,
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
        WHERE p.user_id = ?
        AND (p.name LIKE ? OR p.barcode LIKE ?)
        ORDER BY p.name
        LIMIT 10
    ");
    
    $stmt->bind_param('iss', $userId, $searchQuery, $searchQuery);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'category_name' => $row['category_name'],
            'buy_price' => (float)($row['buy_price'] ?? 0),
            'sell_price' => (float)($row['sell_price'] ?? 0),
            'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
            'special_price' => (float)($row['special_price'] ?? 0),
            'stock_quantity' => (int)($row['stock_quantity'] ?? 0),
            'min_stock' => (int)($row['min_stock'] ?? 0),
            'expiry_date' => $row['expiry_date']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'products' => $products
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}
?>