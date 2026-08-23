<?php
require_once '../../../config/config.php';
require_once '../../../config/security.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'add_product_unit':
            addProductUnit($conn, $userId);
            break;
        case 'update_product_unit':
            updateProductUnit($conn, $userId);
            break;
        case 'delete_product_unit':
            deleteProductUnit($conn, $userId);
            break;
        case 'set_primary_unit':
            setPrimaryUnit($conn, $userId);
            break;
        case 'get_conversion_info':
            getConversionInfo($conn, $userId);
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function assertProductOwner($conn, $userId, $productId) {
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $productId, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Product not found');
    }
}

function addProductUnit($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $productId = (int)($input['product_id'] ?? 0);
    $unitId = (int)($input['unit_id'] ?? 0);
    if (!$productId || !$unitId) {
        throw new Exception('product_id and unit_id are required');
    }
    assertProductOwner($conn, $userId, $productId);

    $buyPrice = (float)($input['buy_price'] ?? 0);
    $sellPrice = (float)($input['sell_price'] ?? 0);
    $wholesalePrice = (float)($input['wholesale_price'] ?? 0);
    $specialPrice = (float)($input['special_price'] ?? 0);
    $stockQuantity = (float)($input['stock_quantity'] ?? 0);
    $minStock = (int)($input['min_stock'] ?? 0);
    $conversionRatio = (float)($input['conversion_ratio'] ?? 1);
    $conversionRate = (float)($input['conversion_rate'] ?? 1);
    $currency = in_array(($input['currency'] ?? 'IQD'), ['IQD', 'USD'], true) ? $input['currency'] : 'IQD';
    $isPrimary = !empty($input['is_primary']) ? 1 : 0;

    $stmt = $conn->prepare("SELECT id FROM units WHERE id = ?");
    $stmt->bind_param("i", $unitId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Unit not found');
    }

    $stmt = $conn->prepare("SELECT id FROM product_units WHERE product_id = ? AND unit_id = ?");
    $stmt->bind_param("ii", $productId, $unitId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('Unit already exists for this product');
    }

    if ($isPrimary) {
        $stmt = $conn->prepare("UPDATE product_units SET is_primary = 0 WHERE product_id = ?");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
    }

    $stmt = $conn->prepare("
        INSERT INTO product_units
        (product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iiddddsdiddi",
        $productId, $unitId, $buyPrice, $sellPrice, $wholesalePrice, $specialPrice, $currency, $stockQuantity, $minStock, $conversionRatio, $conversionRate, $isPrimary
    );
    if (!$stmt->execute()) {
        throw new Exception('Failed to add product unit');
    }

    echo json_encode(['success' => true, 'message' => 'Product unit added', 'product_unit_id' => $conn->insert_id]);
}

function updateProductUnit($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $productUnitId = (int)($input['product_unit_id'] ?? 0);
    if (!$productUnitId) {
        throw new Exception('product_unit_id is required');
    }

    $stmt = $conn->prepare("
        SELECT pu.product_id FROM product_units pu
        JOIN products p ON p.id = pu.product_id
        WHERE pu.id = ? AND p.user_id = ?
    ");
    $stmt->bind_param("ii", $productUnitId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        throw new Exception('Product unit not found');
    }
    $productId = (int)$row['product_id'];

    $isPrimary = !empty($input['is_primary']) ? 1 : 0;
    if ($isPrimary) {
        $stmt = $conn->prepare("UPDATE product_units SET is_primary = 0 WHERE product_id = ? AND id != ?");
        $stmt->bind_param("ii", $productId, $productUnitId);
        $stmt->execute();
    }

    $stmt = $conn->prepare("
        UPDATE product_units
        SET buy_price = ?, sell_price = ?, wholesale_price = ?, special_price = ?,
            stock_quantity = ?, min_stock = ?, conversion_ratio = ?, conversion_rate = ?,
            currency = ?, is_primary = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $buyPrice = (float)($input['buy_price'] ?? 0);
    $sellPrice = (float)($input['sell_price'] ?? 0);
    $wholesalePrice = (float)($input['wholesale_price'] ?? 0);
    $specialPrice = (float)($input['special_price'] ?? 0);
    $stockQuantity = (float)($input['stock_quantity'] ?? 0);
    $minStock = (int)($input['min_stock'] ?? 0);
    $conversionRatio = (float)($input['conversion_ratio'] ?? 1);
    $conversionRate = (float)($input['conversion_rate'] ?? 1);
    $currency = in_array(($input['currency'] ?? 'IQD'), ['IQD', 'USD'], true) ? $input['currency'] : 'IQD';
    $stmt->bind_param(
        "dddddiddsii",
        $buyPrice, $sellPrice, $wholesalePrice, $specialPrice, $stockQuantity, $minStock, $conversionRatio, $conversionRate, $currency, $isPrimary, $productUnitId
    );
    if (!$stmt->execute()) {
        throw new Exception('Failed to update product unit');
    }

    echo json_encode(['success' => true, 'message' => 'Product unit updated']);
}

function deleteProductUnit($conn, $userId) {
    $productUnitId = (int)($_GET['product_unit_id'] ?? 0);
    if (!$productUnitId) {
        throw new Exception('product_unit_id is required');
    }

    $stmt = $conn->prepare("
        SELECT pu.product_id, pu.is_primary
        FROM product_units pu
        JOIN products p ON p.id = pu.product_id
        WHERE pu.id = ? AND p.user_id = ?
    ");
    $stmt->bind_param("ii", $productUnitId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        throw new Exception('Product unit not found');
    }

    $productId = (int)$row['product_id'];
    $isPrimary = (int)$row['is_primary'] === 1;

    $stmt = $conn->prepare("DELETE FROM product_units WHERE id = ?");
    $stmt->bind_param("i", $productUnitId);
    if (!$stmt->execute()) {
        throw new Exception('Failed to delete product unit');
    }

    if ($isPrimary) {
        $stmt = $conn->prepare("
            SELECT id FROM product_units
            WHERE product_id = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $newPrimary = $stmt->get_result()->fetch_assoc();
        if ($newPrimary) {
            $stmt = $conn->prepare("UPDATE product_units SET is_primary = 1 WHERE id = ?");
            $stmt->bind_param("i", $newPrimary['id']);
            $stmt->execute();
        }
    }

    echo json_encode(['success' => true, 'message' => 'Product unit deleted']);
}

function setPrimaryUnit($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $productUnitId = (int)($input['product_unit_id'] ?? 0);
    if (!$productUnitId) {
        throw new Exception('product_unit_id is required');
    }

    $stmt = $conn->prepare("
        SELECT pu.product_id
        FROM product_units pu
        JOIN products p ON p.id = pu.product_id
        WHERE pu.id = ? AND p.user_id = ?
    ");
    $stmt->bind_param("ii", $productUnitId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        throw new Exception('Product unit not found');
    }
    $productId = (int)$row['product_id'];

    $stmt = $conn->prepare("UPDATE product_units SET is_primary = 0 WHERE product_id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();

    $stmt = $conn->prepare("UPDATE product_units SET is_primary = 1 WHERE id = ?");
    $stmt->bind_param("i", $productUnitId);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Primary unit updated']);
}

function getConversionInfo($conn, $userId) {
    $productId = (int)($_GET['product_id'] ?? 0);
    $fromUnitId = (int)($_GET['from_unit_id'] ?? 0);
    $toUnitId = (int)($_GET['to_unit_id'] ?? 0);
    if (!$productId || !$fromUnitId || !$toUnitId) {
        throw new Exception('product_id, from_unit_id, and to_unit_id are required');
    }

    assertProductOwner($conn, $userId, $productId);

    $stmt = $conn->prepare("
        SELECT pu.*, u.name, u.symbol
        FROM product_units pu
        JOIN units u ON u.id = pu.unit_id
        WHERE pu.product_id = ? AND pu.unit_id IN (?, ?)
    ");
    $stmt->bind_param("iii", $productId, $fromUnitId, $toUnitId);
    $stmt->execute();
    $result = $stmt->get_result();

    $units = [];
    while ($row = $result->fetch_assoc()) {
        $units[(int)$row['unit_id']] = $row;
    }
    if (!isset($units[$fromUnitId], $units[$toUnitId])) {
        throw new Exception('Invalid unit IDs for this product');
    }

    $from = $units[$fromUnitId];
    $to = $units[$toUnitId];
    $conversionRate = ((float)$to['conversion_rate'] > 0) ? ((float)$from['conversion_rate'] / (float)$to['conversion_rate']) : 1;

    echo json_encode([
        'success' => true,
        'data' => ['from_unit' => $from, 'to_unit' => $to, 'conversion_rate' => $conversionRate]
    ]);
}
?>

