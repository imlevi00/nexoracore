<?php
/**
 * Sync scale products with products/product_units for POS and sales.
 */

require_once __DIR__ . '/scale_barcode_parser.php';
require_once __DIR__ . '/unit_functions.php';

function scaleTablesReady(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'scale_barcode_settings'");
    if (!$result) {
        return false;
    }
    $ready = $result->num_rows > 0;
    $result->free();

    return $ready;
}

function getScaleBarcodeSettingsForUser(mysqli $conn, int $userId): array
{
    $defaults = getDefaultScaleBarcodeSettings();

    if (!scaleTablesReady($conn)) {
        return $defaults;
    }

    $stmt = $conn->prepare('
        SELECT prefix, total_digits, product_code_digits, price_digits, validate_check_digit, is_enabled
        FROM scale_barcode_settings
        WHERE user_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        return $defaults;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return $defaults;
    }

    return normalizeScaleBarcodeSettings($row);
}

function saveScaleBarcodeSettingsForUser(mysqli $conn, int $userId, array $settings): bool
{
    if (!scaleTablesReady($conn)) {
        return false;
    }

    $normalized = normalizeScaleBarcodeSettings($settings);
    $errors = validateScaleBarcodeSettings($normalized);
    if (!empty($errors)) {
        return false;
    }

    $validateCheck = (int)$normalized['validate_check_digit'];
    $isEnabled = (int)$normalized['is_enabled'];
    $prefix = $normalized['prefix'];
    $totalDigits = (int)$normalized['total_digits'];
    $productCodeDigits = (int)$normalized['product_code_digits'];
    $priceDigits = (int)$normalized['price_digits'];

    $stmt = $conn->prepare('
        INSERT INTO scale_barcode_settings (
            user_id, prefix, total_digits, product_code_digits, price_digits,
            validate_check_digit, is_enabled, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            prefix = VALUES(prefix),
            total_digits = VALUES(total_digits),
            product_code_digits = VALUES(product_code_digits),
            price_digits = VALUES(price_digits),
            validate_check_digit = VALUES(validate_check_digit),
            is_enabled = VALUES(is_enabled),
            updated_at = NOW()
    ');

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'isiiiii',
        $userId,
        $prefix,
        $totalDigits,
        $productCodeDigits,
        $priceDigits,
        $validateCheck,
        $isEnabled
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function getOrCreateKgUnit(int $userId): int
{
    global $conn;

    createDefaultUnits($userId);

    $stmt = $conn->prepare("
        SELECT id FROM units
        WHERE user_id = ? AND is_active = 1 AND (name = 'کیلۆ' OR symbol = 'کگ')
        ORDER BY id ASC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return (int)$row['id'];
        }
    }

    $insertStmt = $conn->prepare("
        INSERT INTO units (user_id, name, symbol, is_default, is_active)
        VALUES (?, 'کیلۆ', 'کگ', 0, 1)
    ");
    if (!$insertStmt) {
        return 0;
    }
    $insertStmt->bind_param('i', $userId);
    $insertStmt->execute();
    $unitId = (int)$conn->insert_id;
    $insertStmt->close();

    return $unitId;
}

function buildScaleProductBarcode(array $settings, string $productCode): string
{
    $normalized = normalizeScaleBarcodeSettings($settings);

    return $normalized['prefix'] . padScaleProductCode($productCode, $normalized['product_code_digits']);
}

function createLinkedProductForScale(
    mysqli $conn,
    int $userId,
    array $scaleProduct,
    array $settings
): int {
    $kgUnitId = getOrCreateKgUnit($userId);
    if ($kgUnitId <= 0) {
        throw new RuntimeException('نەتوانرا یەکەی کیلۆ دابنرێت.');
    }

    $name = trim((string)$scaleProduct['name']);
    $barcode = buildScaleProductBarcode($settings, (string)$scaleProduct['product_code']);
    $currency = 'IQD';

    $stmt = $conn->prepare('
        INSERT INTO products (user_id, category_id, name, barcode, expiry_date, currency, image_path, created_at)
        VALUES (?, NULL, ?, ?, NULL, ?, NULL, NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException('هەڵەی ئامادەکردنی زیادکردنی کاڵا.');
    }

    $stmt->bind_param('isss', $userId, $name, $barcode, $currency);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('هەڵە لە زیادکردنی کاڵای پەیوەست.');
    }
    $productId = (int)$conn->insert_id;
    $stmt->close();

    $buyPrice = (float)$scaleProduct['buy_price'];
    $sellPrice = (float)$scaleProduct['sell_price'];
    $wholesalePrice = (float)$scaleProduct['wholesale_price'];
    $specialPrice = (float)$scaleProduct['special_price'];
    $stockQty = (float)$scaleProduct['stock_quantity'];
    $conversionRatio = 1.0;
    $conversionRate = 1.0;
    $minStock = 0;
    $isPrimary = 1;

    $unitStmt = $conn->prepare('
        INSERT INTO product_units (
            product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency,
            stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    if (!$unitStmt) {
        throw new RuntimeException('هەڵەی ئامادەکردنی یەکەی کاڵا.');
    }

    $unitStmt->bind_param(
        'iiddddsddddi',
        $productId,
        $kgUnitId,
        $buyPrice,
        $sellPrice,
        $wholesalePrice,
        $specialPrice,
        $currency,
        $stockQty,
        $minStock,
        $conversionRatio,
        $conversionRate,
        $isPrimary
    );
    if (!$unitStmt->execute()) {
        $unitStmt->close();
        throw new RuntimeException('هەڵە لە زیادکردنی یەکەی کیلۆ.');
    }
    $unitStmt->close();

    return $productId;
}

function syncLinkedProductForScale(
    mysqli $conn,
    int $userId,
    int $productId,
    array $scaleProduct,
    array $settings
): void {
    $kgUnitId = getOrCreateKgUnit($userId);
    if ($kgUnitId <= 0) {
        throw new RuntimeException('نەتوانرا یەکەی کیلۆ دابنرێت.');
    }

    $name = trim((string)$scaleProduct['name']);
    $barcode = buildScaleProductBarcode($settings, (string)$scaleProduct['product_code']);

    $stmt = $conn->prepare('
        UPDATE products
        SET name = ?, barcode = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ');
    if (!$stmt) {
        throw new RuntimeException('هەڵەی ئامادەکردنی نوێکردنەوەی کاڵا.');
    }
    $stmt->bind_param('ssii', $name, $barcode, $productId, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('هەڵە لە نوێکردنەوەی کاڵای پەیوەست.');
    }
    $stmt->close();

    $buyPrice = (float)$scaleProduct['buy_price'];
    $sellPrice = (float)$scaleProduct['sell_price'];
    $wholesalePrice = (float)$scaleProduct['wholesale_price'];
    $specialPrice = (float)$scaleProduct['special_price'];
    $stockQty = (float)$scaleProduct['stock_quantity'];
    $conversionRatio = 1.0;
    $conversionRate = 1.0;
    $minStock = 0;
    $isPrimary = 1;
    $currency = 'IQD';

    $deleteStmt = $conn->prepare('DELETE FROM product_units WHERE product_id = ?');
    if ($deleteStmt) {
        $deleteStmt->bind_param('i', $productId);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    $unitStmt = $conn->prepare('
        INSERT INTO product_units (
            product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency,
            stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    if (!$unitStmt) {
        throw new RuntimeException('هەڵەی ئامادەکردنی یەکەی کاڵا.');
    }

    $unitStmt->bind_param(
        'iiddddsddddi',
        $productId,
        $kgUnitId,
        $buyPrice,
        $sellPrice,
        $wholesalePrice,
        $specialPrice,
        $currency,
        $stockQty,
        $minStock,
        $conversionRatio,
        $conversionRate,
        $isPrimary
    );
    if (!$unitStmt->execute()) {
        $unitStmt->close();
        throw new RuntimeException('هەڵە لە نوێکردنەوەی یەکەی کیلۆ.');
    }
    $unitStmt->close();
}

function scaleProductHasSales(mysqli $conn, int $productId): bool
{
    if ($productId <= 0) {
        return false;
    }

    $stmt = $conn->prepare('SELECT id FROM sale_items WHERE product_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

function deleteLinkedProductForScale(mysqli $conn, int $userId, int $productId): void
{
    if ($productId <= 0) {
        return;
    }

    if (scaleProductHasSales($conn, $productId)) {
        throw new RuntimeException('ئەم کاڵایە لە فرۆشتندا بەکارهاتووە و ناتوانرێت بسڕدرێتەوە.');
    }

    $stmt = $conn->prepare('DELETE FROM products WHERE id = ? AND user_id = ?');
    if (!$stmt) {
        throw new RuntimeException('هەڵەی ئامادەکردنی سڕینەوەی کاڵا.');
    }
    $stmt->bind_param('ii', $productId, $userId);
    $stmt->execute();
    $stmt->close();
}

function loadScaleProductRow(mysqli $conn, int $userId, int $scaleProductId): ?array
{
    $stmt = $conn->prepare('
        SELECT id, user_id, product_code, name, buy_price, sell_price, wholesale_price, special_price,
               stock_quantity, product_id, created_at, updated_at
        FROM scale_products
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $scaleProductId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function loadScaleProductByCode(mysqli $conn, int $userId, string $productCode): ?array
{
    $stmt = $conn->prepare('
        SELECT id, user_id, product_code, name, buy_price, sell_price, wholesale_price, special_price,
               stock_quantity, product_id, created_at, updated_at
        FROM scale_products
        WHERE user_id = ? AND product_code = ?
        LIMIT 1
    ');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('is', $userId, $productCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function scaleBuildProductUnitFromRow(array $row): array
{
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

function scaleApplyPrimaryUnitDefaults(array &$product): void
{
    if (($product['unit_count'] ?? 0) <= 0 || empty($product['units'])) {
        return;
    }

    usort($product['units'], static function ($a, $b) {
        return ($b['is_primary'] ?? 0) <=> ($a['is_primary'] ?? 0);
    });

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

function loadProductForScaleApi(mysqli $conn, int $userId, int $productId): ?array
{
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
        LEFT JOIN product_units pu ON pu.product_id = p.id
        LEFT JOIN units u ON pu.unit_id = u.id
        WHERE p.user_id = ? AND p.id = ?
        ORDER BY pu.is_primary DESC, pu.id ASC
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $userId, $productId);
    $stmt->execute();
    $rawProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rawProducts)) {
        return null;
    }

    $row = $rawProducts[0];
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
        'image_url' => function_exists('product_image_url') ? product_image_url($row['image_path'] ?? null) : null,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'category_name' => $row['category_name'],
        'unit_count' => (int)$row['unit_count'],
        'units' => [],
        'is_scale_product' => true,
    ];

    foreach ($rawProducts as $unitRow) {
        if ($unitRow['product_unit_id']) {
            $product['units'][] = scaleBuildProductUnitFromRow($unitRow);
        }
    }

    scaleApplyPrimaryUnitDefaults($product);

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
    $product['has_stock'] = $hasStock;
    $product['is_expired'] = $isExpired;
    $product['is_available'] = $hasStock && !$isExpired;

    return $product;
}
