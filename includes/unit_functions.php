<?php
/**
 * Unit Management Functions
 * Functions for handling product units and pricing
 */

/**
 * Get all units for a user
 */
function getUserUnits($userId) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM units WHERE user_id = ? AND is_active = 1 ORDER BY is_default DESC, name ASC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get default unit for a user
 */
function getDefaultUnit($userId) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM units WHERE user_id = ? AND is_default = 1 AND is_active = 1 LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Create default units for a user if they don't exist
 */
function createDefaultUnits($userId) {
    global $conn;
    
    // Check if user already has units
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM units WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result['count'] > 0) {
        return; // User already has units
    }
    
    // Insert default units
    $defaultUnits = [
        ['name' => 'دانە', 'symbol' => 'دانە', 'is_default' => 1],
        ['name' => 'مەتر', 'symbol' => 'م', 'is_default' => 0],
        ['name' => 'کیلۆ', 'symbol' => 'کگ', 'is_default' => 0],
        ['name' => 'تۆپ', 'symbol' => 'تۆپ', 'is_default' => 0],
        ['name' => 'پاکەت', 'symbol' => 'پاکەت', 'is_default' => 0]
    ];
    
    $stmt = $conn->prepare("INSERT INTO units (user_id, name, symbol, is_default) VALUES (?, ?, ?, ?)");
    
    foreach ($defaultUnits as $unit) {
        $stmt->bind_param("issi", $userId, $unit['name'], $unit['symbol'], $unit['is_default']);
        $stmt->execute();
    }
}

/**
 * Ensure default unit exists for a user
 * Creates "دانە" unit if user has no units
 */
function ensureDefaultUnit($userId) {
    global $conn;
    
    // Check if user has any units
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM units WHERE user_id = ? AND is_active = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result['count'] == 0) {
        // User has no units, create default "دانە" unit
        $stmt = $conn->prepare("INSERT INTO units (user_id, name, symbol, is_default, is_active) VALUES (?, 'دانە', 'دانە', 1, 1)");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        
        return $conn->insert_id;
    } else {
        // User has units, check if "دانە" exists
        $stmt = $conn->prepare("SELECT id FROM units WHERE user_id = ? AND name = 'دانە' AND is_active = 1 LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            return $result['id'];
        } else {
            // "دانە" doesn't exist, create it
            $stmt = $conn->prepare("INSERT INTO units (user_id, name, symbol, is_default, is_active) VALUES (?, 'دانە', 'دانە', 1, 1)");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            return $conn->insert_id;
        }
    }
}

/**
 * Add a new unit for a user
 */
function addUnit($userId, $name, $symbol) {
    global $conn;
    
    $stmt = $conn->prepare("INSERT INTO units (user_id, name, symbol, is_default, is_active) VALUES (?, ?, ?, 0, 1)");
    $stmt->bind_param("iss", $userId, $name, $symbol);
    
    return $stmt->execute();
}

/**
 * Get unit prices for a product
 */
function getProductUnitPrices($productId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT pup.*, pu.name as unit_name, pu.symbol as unit_symbol 
        FROM product_unit_prices pup 
        JOIN product_units pu ON pup.unit_id = pu.id 
        WHERE pup.product_id = ? 
        ORDER BY pup.is_primary DESC, pu.name ASC
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Save product unit prices
 */
function saveProductUnitPrices($productId, $unitPrices) {
    global $conn;
    
    // Delete existing unit prices
    $stmt = $conn->prepare("DELETE FROM product_unit_prices WHERE product_id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    
    // Insert new unit prices
    $stmt = $conn->prepare("
        INSERT INTO product_unit_prices 
        (product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, conversion_factor, is_primary) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($unitPrices as $price) {
        $stmt->bind_param(
            "iidddddi",
            $productId,
            $price['unit_id'],
            $price['buy_price'],
            $price['sell_price'],
            $price['wholesale_price'],
            $price['special_price'],
            $price['conversion_factor'],
            $price['is_primary']
        );
        $stmt->execute();
    }
}

/**
 * Get unit label for display
 */
function getUnitLabel($unitName, $unitSymbol) {
    return $unitName . ($unitSymbol ? ' (' . $unitSymbol . ')' : '');
}

/**
 * Format price with unit
 */
function formatPriceWithUnit($price, $unitName, $unitSymbol = '') {
    $formattedPrice = formatMoney($price);
    $unitLabel = $unitSymbol ? $unitSymbol : $unitName;
    return $formattedPrice . ' / ' . $unitLabel;
}
 
/**
 * Get primary unit for a product
 */
function getProductPrimaryUnit($productId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT pu.* 
        FROM product_unit_prices pup 
        JOIN product_units pu ON pup.unit_id = pu.id 
        WHERE pup.product_id = ? AND pup.is_primary = 1 
        LIMIT 1
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Update product primary unit
 */
function updateProductPrimaryUnit($productId, $unitId) {
    global $conn;
    
    $stmt = $conn->prepare("UPDATE products SET unit_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $unitId, $productId);
    
    return $stmt->execute();
}
?>