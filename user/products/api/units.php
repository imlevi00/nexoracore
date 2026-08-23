<?php
/**
 * Unit Management API - user/products/api/units.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_units':
            getUnits($conn, $userId);
            break;
            
        case 'get_product_units':
            $productId = (int)($_GET['product_id'] ?? 0);
            getProductUnits($conn, $userId, $productId);
            break;
            
        case 'add_unit':
            requireProductsUnitsManageApiAccess();
            addUnit($conn, $userId);
            break;
            
        case 'update_unit':
            requireProductsUnitsManageApiAccess();
            updateUnit($conn, $userId);
            break;
            
        case 'delete_unit':
            requireProductsUnitsManageApiAccess();
            deleteUnit($conn, $userId);
            break;
            
        case 'convert_units':
            requireProductsUnitsManageApiAccess();
            convertUnits($conn, $userId);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getUnits($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT id, name, name_ku, symbol, base_unit, conversion_factor, is_custom, description
        FROM unit_types 
        WHERE user_id = ? OR user_id = 0
        ORDER BY is_custom ASC, name ASC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $units = [];
    while ($row = $result->fetch_assoc()) {
        $units[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $units
    ]);
}

function getProductUnits($conn, $userId, $productId) {
    if (!$productId) {
        throw new Exception('Product ID is required');
    }
    
    // Verify product belongs to user
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $productId, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Product not found');
    }
    
    $stmt = $conn->prepare("
        SELECT pu.*, ut.name, ut.name_ku, ut.symbol, ut.base_unit, ut.conversion_factor
        FROM product_units pu
        JOIN unit_types ut ON pu.unit_type_id = ut.id
        WHERE pu.product_id = ?
        ORDER BY pu.is_primary DESC, pu.created_at ASC
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $units = [];
    while ($row = $result->fetch_assoc()) {
        $units[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $units
    ]);
}

function addUnit($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['name']) || !isset($input['name_ku']) || !isset($input['symbol'])) {
        throw new Exception('Name, name_ku, and symbol are required');
    }
    
    $name = cleanInput($input['name']);
    $nameKu = cleanInput($input['name_ku']);
    $symbol = cleanInput($input['symbol']);
    $baseUnit = cleanInput($input['base_unit'] ?? 'piece');
    $conversionFactor = (float)($input['conversion_factor'] ?? 1.0);
    $description = cleanInput($input['description'] ?? '');
    
    // Check if unit already exists
    $stmt = $conn->prepare("SELECT id FROM unit_types WHERE (user_id = ? OR user_id = 0) AND name = ?");
    $stmt->bind_param("is", $userId, $name);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('Unit with this name already exists');
    }
    
    $stmt = $conn->prepare("
        INSERT INTO unit_types (user_id, name, name_ku, symbol, base_unit, conversion_factor, is_custom, description)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
    ");
    $stmt->bind_param("issssds", $userId, $name, $nameKu, $symbol, $baseUnit, $conversionFactor, $description);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Unit added successfully',
            'unit_id' => $conn->insert_id
        ]);
    } else {
        throw new Exception('Failed to add unit');
    }
}

function updateUnit($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['unit_id'])) {
        throw new Exception('Unit ID is required');
    }
    
    $unitId = (int)$input['unit_id'];
    $name = cleanInput($input['name'] ?? '');
    $nameKu = cleanInput($input['name_ku'] ?? '');
    $symbol = cleanInput($input['symbol'] ?? '');
    $baseUnit = cleanInput($input['base_unit'] ?? '');
    $conversionFactor = (float)($input['conversion_factor'] ?? 1.0);
    $description = cleanInput($input['description'] ?? '');
    
    // Verify unit belongs to user
    $stmt = $conn->prepare("SELECT id FROM unit_types WHERE id = ? AND user_id = ? AND is_custom = 1");
    $stmt->bind_param("ii", $unitId, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Unit not found or not editable');
    }
    
    $stmt = $conn->prepare("
        UPDATE unit_types 
        SET name = ?, name_ku = ?, symbol = ?, base_unit = ?, conversion_factor = ?, description = ?
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ssssdsii", $name, $nameKu, $symbol, $baseUnit, $conversionFactor, $description, $unitId, $userId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Unit updated successfully'
        ]);
    } else {
        throw new Exception('Failed to update unit');
    }
}

function deleteUnit($conn, $userId) {
    $unitId = (int)($_GET['unit_id'] ?? 0);
    
    if (!$unitId) {
        throw new Exception('Unit ID is required');
    }
    
    // Verify unit belongs to user and is custom
    $stmt = $conn->prepare("SELECT id FROM unit_types WHERE id = ? AND user_id = ? AND is_custom = 1");
    $stmt->bind_param("ii", $unitId, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Unit not found or not deletable');
    }
    
    // Check if unit is being used by any products
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_units WHERE unit_type_id = ?");
    $stmt->bind_param("i", $unitId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result['count'] > 0) {
        throw new Exception('Cannot delete unit that is being used by products');
    }
    
    $stmt = $conn->prepare("DELETE FROM unit_types WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $unitId, $userId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Unit deleted successfully'
        ]);
    } else {
        throw new Exception('Failed to delete unit');
    }
}

function convertUnits($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['from_unit_id']) || !isset($input['to_unit_id']) || !isset($input['quantity'])) {
        throw new Exception('from_unit_id, to_unit_id, and quantity are required');
    }
    
    $fromUnitId = (int)$input['from_unit_id'];
    $toUnitId = (int)$input['to_unit_id'];
    $quantity = (float)$input['quantity'];
    
    // Get unit conversion factors
    $stmt = $conn->prepare("
        SELECT id, base_unit, conversion_factor 
        FROM unit_types 
        WHERE id IN (?, ?)
    ");
    $stmt->bind_param("ii", $fromUnitId, $toUnitId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $units = [];
    while ($row = $result->fetch_assoc()) {
        $units[$row['id']] = $row;
    }
    
    if (count($units) !== 2) {
        throw new Exception('Invalid unit IDs');
    }
    
    $fromUnit = $units[$fromUnitId];
    $toUnit = $units[$toUnitId];
    
    // Convert to base unit first, then to target unit
    $baseQuantity = $quantity * $fromUnit['conversion_factor'];
    $convertedQuantity = $baseQuantity / $toUnit['conversion_factor'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'from_quantity' => $quantity,
            'to_quantity' => $convertedQuantity,
            'from_unit' => $fromUnit,
            'to_unit' => $toUnit
        ]
    ]);
}
?>

