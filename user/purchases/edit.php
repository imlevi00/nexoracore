<?php
/**
 * دەستکاری وەسڵی کڕدراو - user/purchases/edit.php
 * NexoraCore - Amir Technology
 * AmirTechOne.com
 */

require_once '../../includes/functions.php';
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/product_change_logger.php';
require_once '../../includes/permissions.php';
require_once '../../includes/zanyari_user_settings.php';
require_once '../../includes/wallet_service.php';
require_once '../../includes/purchase_receipt_request_items.php';
require_once '../../includes/product_units_package.php';

function uploadPurchaseReceiptImageToSpaces(array $file, int $userId): array
{
    if (!function_exists('product_spaces_enabled') || !product_spaces_enabled()) {
        return ['success' => false, 'errors' => ['ڕێکخستنی Spaces تەواو نییە']];
    }

    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($fileExtension, $allowedExtensions, true)) {
        return ['success' => false, 'errors' => ['جۆری فایل پەسەند نییە. تەنها JPG, PNG, GIF, WEBP']];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'errors' => ['قەبارەی فایل گەورەیە. تکایە فایلێکی بچووکتر هەڵبژێرە (کەمتر لە 5MB)']];
    }

    $payload = spaces_optimized_image_upload_payload($file['tmp_name'], $file['name'] ?? '');
    if ($payload['body'] === false) {
        return ['success' => false, 'errors' => ['نەتوانرا فایل بخوێنرێتەوە']];
    }

    $objectKey = 'img/receipts/purchase_receipt/purchase_receipt_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExtension;
    try {
        spaces_put_object($objectKey, $payload['body'], $payload['mime']);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strlen($msg) > 200) {
            $msg = substr($msg, 0, 200) . '…';
        }
        return ['success' => false, 'errors' => ['هەڵە لە بارکردنی وێنە بۆ DigitalOcean: ' . $msg]];
    }

    $receiptImageUrl = spaces_public_url_for_object_key($objectKey);
    if ($receiptImageUrl === null) {
        return ['success' => false, 'errors' => ['ڕێکخستنی URL ی Spaces تەواو نییە']];
    }
    return ['success' => true, 'url' => $receiptImageUrl];
}

function deletePurchaseReceiptImageFromSpaces(?string $receiptImageUrl): void
{
    spaces_delete_object_from_public_url($receiptImageUrl);
}

function formatEditDecimal($value, int $precision = 3): string
{
    if ($value === '' || $value === null) {
        return '';
    }

    $formatted = number_format((float)$value, $precision, '.', '');
    $trimmed = rtrim(rtrim($formatted, '0'), '.');

    return ($trimmed === '' || $trimmed === '-0') ? '0' : $trimmed;
}

// تاقیکردنەوەی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
requireCompaniesModuleAccess();
$userId = $currentUser['id'];

// وەرگرتنی ID ی وەسڵەکە
$receiptId = (int)($_GET['id'] ?? 0);
if (!$receiptId) {
    setMessage('وەسڵ نەدۆزرایەوە', 'error');
    redirect(url('user/purchases/index.php'));
}

// وەرگرتنی زانیاری وەسڵەکە
$stmt = $conn->prepare("
    SELECT pr.*, c.name as company_name, c.address as company_address, c.phone as company_phone
    FROM purchase_receipts pr 
    LEFT JOIN companies c ON pr.company_id = c.id 
    WHERE pr.id = ? AND pr.user_id = ?
");
$stmt->bind_param("ii", $receiptId, $userId);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();

if (!$receipt) {
    setMessage('وەسڵ نەدۆزرایەوە یان دەسەڵاتت نیە', 'error');
    redirect(url('user/purchases/index.php'));
}

// وەرگرتنی کاڵاکانی وەسڵەکە لەگەڵ یەکەکانیان
$stmt = $conn->prepare("
    SELECT pri.*, p.name as current_product_name,
           COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
           p.barcode,
           COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as current_buy_price,
           COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as current_sell_price,
           COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as current_wholesale_price,
           COALESCE(pu_primary.special_price, pu_any.special_price, 0) as current_special_price,
           p.expiry_date as product_expiry_date,
           u.name as unit_name, u.symbol as unit_symbol
    FROM purchase_receipt_items pri
    LEFT JOIN products p ON pri.product_id = p.id
    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
    LEFT JOIN product_units pu_any ON pu_any.id = (
        SELECT pu2.id
        FROM product_units pu2
        WHERE pu2.product_id = p.id
        ORDER BY pu2.is_primary DESC, pu2.id ASC
        LIMIT 1
    )
    LEFT JOIN units u ON pri.unit_id = u.id
    WHERE pri.purchase_receipt_id = ?
    ORDER BY pri.id
");
$stmt->bind_param("i", $receiptId);
$stmt->execute();
$receiptItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی لیستی کۆمپانیاکان
$stmt = $conn->prepare("SELECT * FROM companies WHERE user_id = ? AND status = 'active' ORDER BY name");
$stmt->bind_param("i", $userId);
$stmt->execute();
$companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$wallets = walletGetUserWallets($conn, (int)$userId, true);

// وەرگرتنی جۆری ئیش (دەرمانخانە و سەنتەری پزیشکی + دەرمانخانە) لە ڕێکخستنەکان
$isPharmacyMode = false;
$settingsStmt = $conn->prepare("
    SELECT s.business_type_id, bt.code AS business_type_code
    FROM settings s
    LEFT JOIN business_types bt ON bt.id = s.business_type_id
    WHERE s.user_id = ?
    LIMIT 1
");
$settingsStmt->bind_param("i", $userId);
$settingsStmt->execute();
$settingsRow = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();
if ($settingsRow) {
    $businessTypeId = (int)($settingsRow['business_type_id'] ?? 0);
    $businessTypeCode = trim((string)($settingsRow['business_type_code'] ?? ''));
    if (
        in_array($businessTypeId, [1, 3], true) ||
        in_array($businessTypeCode, ['pharmacy', 'pharmacy_and_medical_center'], true)
    ) {
        $isPharmacyMode = true;
    }
}

// وەرگرتنی کەتەلۆگەکان بۆ مۆدی دەرمانخانە
$categories = [];
if ($isPharmacyMode) {
    $catStmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name");
    $catStmt->bind_param("i", $userId);
    $catStmt->execute();
    $categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $catStmt->close();
}

$unitsStmt = $conn->prepare("SELECT id, name, symbol, is_default FROM units WHERE user_id = ? AND is_active = 1 ORDER BY is_default DESC, name ASC");
$unitsStmt->bind_param("i", $userId);
$unitsStmt->execute();
$userUnits = $unitsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$unitsStmt->close();

// وەرگرتنی لیستی کاڵاکان بۆ autocomplete لەگەڵ یەکەکانیان
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.barcode, p.category_id,
           COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as buy_price,
           COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
           COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
           COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
           COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
           p.expiry_date, p.image_path,
           pu.id as product_unit_id, pu.unit_id, u.name as unit_name, u.symbol as unit_symbol,
           pu.buy_price as unit_buy_price, pu.sell_price as unit_sell_price,
           pu.wholesale_price as unit_wholesale_price, pu.special_price as unit_special_price,
           pu.conversion_rate, pu.is_primary
    FROM products p
    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
    LEFT JOIN product_units pu_any ON pu_any.id = (
        SELECT pu2.id
        FROM product_units pu2
        WHERE pu2.product_id = p.id
        ORDER BY pu2.is_primary DESC, pu2.id ASC
        LIMIT 1
    )
    LEFT JOIN product_units pu ON p.id = pu.product_id
    LEFT JOIN units u ON pu.unit_id = u.id
    WHERE p.user_id = ?
    ORDER BY p.name, pu.is_primary DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$productsRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// تێکەڵکردنی کاڵاکان و یەکەکانیان
$products = [];
foreach ($productsRaw as $row) {
    $productId = $row['id'];
    
    if (!isset($products[$productId])) {
        $products[$productId] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
            'buy_price' => (float)($row['buy_price'] ?? 0),
            'sell_price' => (float)($row['sell_price'] ?? 0),
            'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
            'special_price' => (float)($row['special_price'] ?? 0),
            'stock_quantity' => $row['stock_quantity'] ?? 0,
            'expiry_date' => $row['expiry_date'],
            'image_url' => product_image_url($row['image_path'] ?? null),
            'units' => []
        ];
    }
    
    // زیادکردنی یەکەکان
    if ($row['unit_id']) {
        $products[$productId]['units'][] = [
            'unit_id' => (int)$row['unit_id'],
            'unit_name' => $row['unit_name'],
            'unit_symbol' => $row['unit_symbol'],
            'buy_price' => (float)$row['unit_buy_price'],
            'sell_price' => (float)$row['unit_sell_price'],
            'wholesale_price' => (float)$row['unit_wholesale_price'],
            'special_price' => (float)$row['unit_special_price'],
            'conversion_rate' => (float)$row['conversion_rate'],
            'is_primary' => (int)$row['is_primary']
        ];
    }
}

// بۆ مۆدی دەرمانخانە: وەرگرتنی وەڵامی پێشووی «پاکەت چەند شیتە؟» و زانیاری شیت بۆ هەر کاڵا
if ($isPharmacyMode) {
    $lastSheetStmt = $conn->prepare("
        SELECT pri.product_id, pri.sheets_per_packet, pri.packet_bonus, pri.buy_price, pri.sell_price
        FROM purchase_receipt_items pri
        INNER JOIN purchase_receipts pr ON pr.id = pri.purchase_receipt_id
        WHERE pr.user_id = ?
        AND pri.sheets_per_packet IS NOT NULL AND pri.sheets_per_packet > 0
        ORDER BY pri.id DESC
    ");
    $lastSheetStmt->bind_param("i", $userId);
    $lastSheetStmt->execute();
    $lastSheetRows = $lastSheetStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lastSheetStmt->close();
    $lastSheetByProduct = [];
    foreach ($lastSheetRows as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($lastSheetByProduct[$pid])) {
            $sp = (int)$r['sheets_per_packet'];
            $lastSheetByProduct[$pid] = [
                'last_sheets_per_packet' => $sp,
                'last_packet_bonus' => (float)($r['packet_bonus'] ?? 0),
                'last_sheet_buy_price' => $sp > 0 ? (float)$r['buy_price'] / $sp : null,
                'last_sheet_sell_price' => $sp > 0 ? (float)$r['sell_price'] / $sp : null
            ];
        }
    }
    foreach ($lastSheetByProduct as $pid => $data) {
        if (isset($products[$pid])) {
            $products[$pid] = array_merge($products[$pid], $data);
        }
    }
}

// گۆڕینی بۆ indexed array بۆ JSON
$products = array_values($products);

// نەخشەی URLـی وێنەی کاڵا بەپێی id — بۆ نیشاندانی وێنە لە ڕیزە هەبووەکان
$productImageUrlById = [];
foreach ($products as $p) {
    $productImageUrlById[(int)$p['id']] = $p['image_url'] ?? null;
}

/**
 * ڕەندەرکردنی خانەی وێنەی کاڵا بۆ ڕیزی وەسڵ.
 * ئەگەر وێنە هەبوو → دۆخی نیشاندان، ئەگەر نەبوو → دۆخی داناندن.
 */
if (!function_exists('renderPurchaseItemImageCell')) {
    function renderPurchaseItemImageCell(string $imageKey, ?string $imageUrl = null): void
    {
        $hasImage = $imageUrl !== null && $imageUrl !== '';
        $mode = $hasImage ? 'view' : 'edit';
        $keyAttr = htmlspecialchars($imageKey, ENT_QUOTES);
        $existingAttr = $hasImage ? htmlspecialchars($imageUrl, ENT_QUOTES) : '';
        ?>
        <div class="product-image-cell" data-mode="<?php echo $mode; ?>" data-existing-url="<?php echo $existingAttr; ?>">
            <input type="hidden" name="image_key[]" value="<?php echo $keyAttr; ?>">
            <input type="file" class="product-image-input d-none" name="product_image[<?php echo $keyAttr; ?>]" accept="image/*">
            <div class="product-image-thumb">
                <img class="product-image-preview" alt="وێنەی کاڵا" src="<?php echo $hasImage ? htmlspecialchars($imageUrl, ENT_QUOTES) : ''; ?>" style="<?php echo $hasImage ? 'display:block;' : 'display:none;'; ?>">
                <div class="product-image-placeholder" style="<?php echo $hasImage ? 'display:none;' : 'display:flex;'; ?>"><i class="bi bi-image"></i><span>وێنە دابنێ</span></div>
                <button type="button" class="product-image-remove" title="لابردنی وێنە"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="product-image-info">
                <span class="pi-title"><i class="bi bi-card-image"></i> وێنەی کاڵا</span>
                <?php if ($hasImage): ?>
                    <span class="pi-hint"><span class="product-image-badge"><i class="bi bi-check-circle-fill"></i> وێنەی کاڵا بەردەستە</span></span>
                <?php else: ?>
                    <span class="pi-hint">کلیک بکە بۆ هەڵبژاردنی وێنە</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

// Format prices for JavaScript to remove .000
foreach ($products as &$product) {
    $product['buy_price'] = (int)$product['buy_price'];
    $product['sell_price'] = (int)$product['sell_price'];
    $product['wholesale_price'] = (int)$product['wholesale_price'];
    $product['special_price'] = (int)$product['special_price'];
    
    // Format unit prices
    foreach ($product['units'] as &$unit) {
        $unit['buy_price'] = (float)$unit['buy_price'];
        $unit['sell_price'] = (float)$unit['sell_price'];
        $unit['wholesale_price'] = (float)$unit['wholesale_price'];
        $unit['special_price'] = (float)$unit['special_price'];
    }
    unset($unit); // پاککردنەوەی ڕەفەرەنس
}
unset($product); // پاککردنەوەی ڕەفەرەنس

// Format prices for receipt items to remove .000
foreach ($receiptItems as &$item) {
    $rawBuyPrice = (float)($item['buy_price'] ?? 0);
    $rawSellPrice = (float)($item['sell_price'] ?? 0);
    $quantity = (float)($item['quantity'] ?? 0);
    $packetBonus = (float)($item['packet_bonus'] ?? 0);
    $sheetsPerPacket = (int)($item['sheets_per_packet'] ?? 0);
    $totalPackets = $quantity + $packetBonus;
    $lineTotal = $quantity * $rawBuyPrice;
    $lineDiscountAmount = (float)($item['discount_amount'] ?? 0);
    $netLineTotal = (float)($item['total_cost'] ?? 0);
    if ($netLineTotal <= 0 && $lineTotal > 0) {
        $netLineTotal = max(0, $lineTotal - $lineDiscountAmount);
    }
    $effectivePacketBuy = $totalPackets > 0 ? ($netLineTotal / $totalPackets) : null;

    $item['buy_price'] = (int)$item['buy_price'];
    $item['sell_price'] = (int)$item['sell_price'];
    $item['wholesale_price'] = (int)$item['wholesale_price'];
    $item['special_price'] = (int)$item['special_price'];
    $item['display_avg_packet_buy'] = $effectivePacketBuy !== null
        ? formatEditDecimal($effectivePacketBuy, 3)
        : '';
    $item['display_avg_sheet_buy'] = ($effectivePacketBuy !== null && $sheetsPerPacket > 0)
        ? formatEditDecimal($effectivePacketBuy / $sheetsPerPacket, 3)
        : '';
    $item['display_sheet_sell_price'] = $sheetsPerPacket > 0
        ? formatEditDecimal($rawSellPrice / $sheetsPerPacket, 3)
        : '';
}
unset($item); // پاککردنەوەی ڕەفەرەنس بۆ ڕێگری لە بەگی کلاسیکی PHP
$receiptPharmacyDiscountPercent = 0.0;
if ($isPharmacyMode && (float)($receipt['total_amount'] ?? 0) > 0) {
    $receiptPharmacyDiscountPercent = ((float)($receipt['discount_amount'] ?? 0) / (float)$receipt['total_amount']) * 100;
}

$errors = [];
$success = false;

function getDefaultUnitId($conn, $userId) {
    $unit = getDefaultPieceUnitForUser($conn, (int)$userId);
    return (int)($unit['id'] ?? 0);
}

/**
 * دۆزینەوە یان دروستکردنی یەکە بۆ user (بۆ مۆدی دەرمانخانە: پاکەت، شیت)
 */
function getOrCreateUnit($conn, $userId, $unitName) {
    $name = $unitName;
    $chk = $conn->prepare("SELECT id FROM units WHERE user_id = ? AND name = ? LIMIT 1");
    $chk->bind_param("is", $userId, $name);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($row) {
        return (int)$row['id'];
    }
    $ins = $conn->prepare("INSERT INTO units (user_id, name, is_active) VALUES (?, ?, 1)");
    $ins->bind_param("is", $userId, $name);
    $ins->execute();
    $id = $conn->insert_id;
    $ins->close();
    return (int)$id;
}

function resolveOrCreateProductForPurchaseEdit($conn, $userId, array $item, $defaultUnitId, $packetUnitId = 0, $currency = 'IQD') {
    $currency = ($currency === 'USD') ? 'USD' : 'IQD';
    $resolvedProductId = (int)($item['product_id'] ?? 0);
    $resolvedBarcode = trim((string)($item['barcode'] ?? ''));
    $productName = trim((string)($item['product_name'] ?? ''));
    $resolvedUnitId = (int)($item['unit_id'] ?? 0);

    if ($resolvedProductId <= 0 && $resolvedBarcode !== '') {
        $findByBarcode = $conn->prepare("SELECT id FROM products WHERE user_id = ? AND barcode = ? LIMIT 1");
        $findByBarcode->bind_param("is", $userId, $resolvedBarcode);
        $findByBarcode->execute();
        $row = $findByBarcode->get_result()->fetch_assoc();
        $findByBarcode->close();
        if ($row) {
            $resolvedProductId = (int)$row['id'];
        }
    }

    if ($resolvedProductId <= 0 && $productName !== '') {
        $findByName = $conn->prepare("SELECT id FROM products WHERE user_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
        $findByName->bind_param("is", $userId, $productName);
        $findByName->execute();
        $row = $findByName->get_result()->fetch_assoc();
        $findByName->close();
        if ($row) {
            $resolvedProductId = (int)$row['id'];
        }
    }

    if ($resolvedProductId <= 0) {
        $expProd = $item['expiry_date'] ?: null;
        $insProd = $conn->prepare("
            INSERT INTO products (user_id, name, barcode, currency, expiry_date)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insProd->bind_param("issss", $userId, $productName, $resolvedBarcode, $currency, $expProd);
        $insProd->execute();
        $resolvedProductId = (int)$conn->insert_id;
        $insProd->close();
    }

    if ($resolvedProductId > 0 && $resolvedBarcode !== '') {
        // دڵنیابوونەوە کە بارکۆدە نوێیەکە لەلایەن کاڵایەکی تر بەکارنەهاتووە
        $barcodeConflictStmt = $conn->prepare("SELECT id FROM products WHERE user_id = ? AND barcode = ? AND id != ? LIMIT 1");
        $barcodeConflictStmt->bind_param("isi", $userId, $resolvedBarcode, $resolvedProductId);
        $barcodeConflictStmt->execute();
        $barcodeConflict = $barcodeConflictStmt->get_result()->fetch_assoc();
        $barcodeConflictStmt->close();

        // نوێکردنەوەی بارکۆد ئەگەر گۆڕدرابێت و ململانێی نەبێت لەگەڵ کاڵایەکی تر
        if (!$barcodeConflict) {
            $syncBarcodeStmt = $conn->prepare("
                UPDATE products
                SET barcode = ?
                WHERE id = ? AND user_id = ?
            ");
            $syncBarcodeStmt->bind_param("sii", $resolvedBarcode, $resolvedProductId, $userId);
            $syncBarcodeStmt->execute();
            $syncBarcodeStmt->close();
        }
    }

    if ((int)$packetUnitId > 0) {
        $resolvedUnitId = (int)$packetUnitId;
    }
    if ($resolvedUnitId <= 0) {
        $resolvedUnitId = (int)$defaultUnitId;
    }
    if ($resolvedUnitId <= 0) {
        throw new Exception('هیچ یەکەیەکی چالاک بۆ دروستکردنی کاڵا نەدۆزرایەوە.');
    }

    $findPrimary = $conn->prepare("SELECT id, unit_id FROM product_units WHERE product_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1");
    $findPrimary->bind_param("i", $resolvedProductId);
    $findPrimary->execute();
    $primaryUnit = $findPrimary->get_result()->fetch_assoc();
    $findPrimary->close();

    if (!$primaryUnit) {
        $insertPrimary = $conn->prepare("
            INSERT INTO product_units (product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 1, 1, 1)
        ");
        $insertPrimary->bind_param("iidddds", $resolvedProductId, $resolvedUnitId, $item['buy_price'], $item['sell_price'], $item['wholesale_price'], $item['special_price'], $currency);
        $insertPrimary->execute();
        $insertPrimary->close();
    } else if ($resolvedUnitId <= 0) {
        $resolvedUnitId = (int)$primaryUnit['unit_id'];
    }

    $ensureUnit = $conn->prepare("SELECT id FROM product_units WHERE product_id = ? AND unit_id = ? LIMIT 1");
    $ensureUnit->bind_param("ii", $resolvedProductId, $resolvedUnitId);
    $ensureUnit->execute();
    $unitRow = $ensureUnit->get_result()->fetch_assoc();
    $ensureUnit->close();
    if (!$unitRow) {
        $unitConversionRate = (float)($item['conversion_rate'] ?? 1.0);
        if ($unitConversionRate <= 0) {
            $unitConversionRate = 1.0;
        }
        $unitConversionRatio = 1 / $unitConversionRate;
        $insertUnit = $conn->prepare("
            INSERT INTO product_units (product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0)
        ");
        $insertUnit->bind_param("iiddddsdd", $resolvedProductId, $resolvedUnitId, $item['buy_price'], $item['sell_price'], $item['wholesale_price'], $item['special_price'], $currency, $unitConversionRatio, $unitConversionRate);
        $insertUnit->execute();
        $insertUnit->close();
    }

    // هاوئاهەنگکردنی دراوی کاڵا لەگەڵ دراوی وەسڵ (بۆ ئەوەی POS و لیستی کاڵاکان بە هەمان دراو فرۆشتنی پێوە بکرێت)
    if ($resolvedProductId > 0) {
        $syncProdCurrency = $conn->prepare("UPDATE products SET currency = ? WHERE id = ? AND user_id = ?");
        $syncProdCurrency->bind_param("sii", $currency, $resolvedProductId, $userId);
        $syncProdCurrency->execute();
        $syncProdCurrency->close();
    }

    return ['product_id' => $resolvedProductId, 'unit_id' => $resolvedUnitId];
}

// پڕۆسێسکردنی فۆڕم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        
        // وەرگرتنی زانیاری سەرەکی
        $company_id = (int)($_POST['company_id'] ?? 0);
        $receipt_number = cleanInput($_POST['receipt_number'] ?? '');
        $payment_type = $_POST['payment_type'] ?? 'cash';
        // دراوی نوێی وەسڵ (لە فۆرمەوە)
        $receipt_currency = (($_POST['currency'] ?? ($receipt['currency'] ?? 'IQD')) === 'USD') ? 'USD' : 'IQD';
        $receipt_debt_column = $receipt_currency === 'USD' ? 'debt_amount_usd' : 'debt_amount';
        $wallet_id = (int)($_POST['wallet_id'] ?? 0);
        $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d');
        $discount_type = $_POST['discount_type'] ?? 'amount'; // amount or percentage
        $discount_value = (float)($_POST['discount_value'] ?? 0);
        $additional_charges = (float)($_POST['additional_charges'] ?? 0);
        $notes = cleanInput($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Validation بۆ status value
        $validStatuses = ['active', 'completed', 'cancelled'];
        if (!in_array($status, $validStatuses)) {
            $status = 'active'; // Default to active if invalid
        }
        
        // Validation بۆ زانیاری سەرەکی
        if (!$company_id) {
            $errors[] = 'تکایە کۆمپانیا دیاری بکە';
        }
        
        if (empty($receipt_date)) {
            $errors[] = 'تکایە بەروار دیاری بکە';
        }
        if ($payment_type === 'cash' && $wallet_id <= 0) {
            $errors[] = 'تکایە قاسە هەڵبژێرە';
        }
        
        // وەرگرتنی زانیاری کاڵاکان (JSON payload یان fallback بۆ POST arrays)
        $parsedItems = purchaseReceiptBuildItemsFromPost($_POST, $isPharmacyMode, $errors, 'edit');
        $items = $parsedItems['items'];
        $global_item_discount_percent = $parsedItems['global_item_discount_percent'];
        $total_amount = $parsedItems['total_amount'];

        if ($isPharmacyMode && !empty($items)) {
            $discount_type = 'percentage';
            $discount_value = $global_item_discount_percent;
        }
        
        // حیسابکردنی داشکاندن
        $discount_amount = 0;
        if ($isPharmacyMode) {
            $gross_total = 0.0;
            foreach ($items as &$itemRef) {
                $lineTotal = (float)$itemRef['quantity'] * (float)$itemRef['buy_price'];
                $lineDiscount = ($lineTotal * $global_item_discount_percent) / 100;
                $itemRef['discount_amount'] = $lineDiscount;
                $itemRef['total_cost'] = max(0, $lineTotal - $lineDiscount);
                $gross_total += $lineTotal;
            }
            unset($itemRef);
            $total_amount = $gross_total;
            $discount_amount = ($gross_total * $global_item_discount_percent) / 100;
        } else {
            if ($discount_value > 0) {
                if ($discount_type === 'percentage') {
                    $discount_amount = ($total_amount * $discount_value) / 100;
                } else {
                    $discount_amount = $discount_value;
                }
            }
        }
        
        $final_amount = $total_amount - $discount_amount + $additional_charges;
        
        // پڕۆسێسی وێنە
        $receipt_image = $receipt['receipt_image']; // وێنەی پێشوو
        
        if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = uploadPurchaseReceiptImageToSpaces($_FILES['receipt_image'], $userId);
            
            if ($uploadResult['success']) {
                deletePurchaseReceiptImageFromSpaces($receipt['receipt_image'] ?? null);
                $receipt_image = $uploadResult['url'];
            } else {
                $errors = array_merge($errors, $uploadResult['errors']);
            }
        }
        
        // ئەگەر هەڵەیەک نەبێت، نوێکردنەوە بکە
        if (empty($errors)) {
            try {
                $affectedProductIds = [];
                foreach ($items as $newItemForLog) {
                    $newPid = (int)($newItemForLog['product_id'] ?? 0);
                    if ($newPid > 0) {
                        $affectedProductIds[$newPid] = $newPid;
                    }
                }
                $conn->begin_transaction();

                $useWeighted = getPurchasesUseWeightedAvgPrices($userId);
                $newInventoryStrategy = $useWeighted ? 1 : 0;
                $oldStrategy = (int)($receipt['inventory_price_strategy'] ?? 1);
                $defaultUnitId = getDefaultUnitId($conn, $userId);

                // بۆ مۆدی دەرمانخانە: یەکەی پاکەت (primary) و شیت (secondary) — هاوشێوەی add.php
                $packet_unit_id = $isPharmacyMode ? getOrCreateUnit($conn, $userId, 'پاکەت') : null;
                $sheet_unit_id  = $isPharmacyMode ? getOrCreateUnit($conn, $userId, 'شیت')  : null;
                
                // وەرگرتنی داتای کۆنی وەسڵ بۆ گەڕاندنەوە
                $oldPaymentType = $receipt['payment_type'];
                $oldFinalAmount = (float)$receipt['final_amount'];
                $oldCompanyId = (int)$receipt['company_id'];
                // دراوی کۆنی وەسڵ (بۆ گەڕاندنەوەی قەرزی کۆن بە دراوی ڕاست)
                $oldReceiptCurrency = (($receipt['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
                $oldDebtColumn = $oldReceiptCurrency === 'USD' ? 'debt_amount_usd' : 'debt_amount';
                
                // وەرگرتنی کاڵاکانی کۆن لەگەڵ نرخەکان بۆ گەڕاندنەوە
                $oldItemsStmt = $conn->prepare("
                    SELECT pri.id AS pri_id,
                           pri.product_id, pri.quantity, pri.packet_bonus, pri.sheets_per_packet, pri.unit_id, 
                           COALESCE(pu.conversion_rate, 1.0) as conversion_rate,
                           pri.buy_price, pri.sell_price, pri.wholesale_price, pri.special_price,
                           pri.expiry_date,
                           pri.revert_buy_price, pri.revert_sell_price, pri.revert_wholesale_price, pri.revert_special_price,
                           pri.revert_sheet_buy_price, pri.revert_sheet_sell_price,
                           COALESCE(pu_primary.stock_quantity, pu_fallback.stock_quantity, 0) as current_stock,
                           pu.stock_quantity as current_unit_stock
                    FROM purchase_receipt_items pri
                    LEFT JOIN products p ON pri.product_id = p.id AND p.user_id = ?
                    LEFT JOIN product_units pu ON pri.product_id = pu.product_id AND pri.unit_id = pu.unit_id
                    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                    LEFT JOIN product_units pu_fallback ON pu_fallback.id = (
                        SELECT pu2.id
                        FROM product_units pu2
                        WHERE pu2.product_id = p.id
                        ORDER BY pu2.is_primary DESC, pu2.id ASC
                        LIMIT 1
                    )
                    WHERE pri.purchase_receipt_id = ? AND pri.product_id > 0
                ");
                $oldItemsStmt->bind_param("ii", $userId, $receiptId);
                $oldItemsStmt->execute();
                $oldItems = $oldItemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($oldItems as $oldItemForLog) {
                    $oldPid = (int)($oldItemForLog['product_id'] ?? 0);
                    if ($oldPid > 0) {
                        $affectedProductIds[$oldPid] = $oldPid;
                    }
                }
                $beforeSnapshots = [];
                foreach ($affectedProductIds as $pid) {
                    $beforeSnapshots[$pid] = getProductSnapshotForLogs($conn, $userId, $pid);
                }
                
                // نوێکردنەوەی وەسڵەکە
                $stmt = $conn->prepare("
                    UPDATE purchase_receipts SET
                        company_id = ?, receipt_number = ?, receipt_image = ?, payment_type = ?, wallet_id = ?,
                        receipt_date = ?, total_amount = ?, discount_amount = ?, additional_charges = ?,
                        final_amount = ?, notes = ?, status = ?, inventory_price_strategy = ?, currency = ?, updated_at = NOW()
                    WHERE id = ? AND user_id = ?
                ");

                $stmt->bind_param(
                    "isssissdddssisii",
                    $company_id, $receipt_number, $receipt_image, $payment_type, $wallet_id, $receipt_date,
                    $total_amount, $discount_amount, $additional_charges, $final_amount,
                    $notes, $status, $newInventoryStrategy, $receipt_currency, $receiptId, $userId
                );
                
                if (!$stmt->execute()) {
                    throw new Exception('هەڵە لە نوێکردنەوەی وەسڵ');
                }
                
                // **گەڕاندنەوەی کاریگەرییەکانی وەسڵی کۆن**
                // 1. گەڕاندنەوەی قەرزی کۆمپانیا ئەگەر payment_type کۆن debt بووە
                if ($oldPaymentType === 'debt' && $oldCompanyId > 0) {
                    // کەمکردنەوەی قەرز لە کۆمپانیای کۆن
                    $revertDebtStmt = $conn->prepare("
                        UPDATE companies
                        SET {$oldDebtColumn} = GREATEST(0, {$oldDebtColumn} - ?), updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $revertDebtStmt->bind_param("dii", $oldFinalAmount, $oldCompanyId, $userId);
                    if (!$revertDebtStmt->execute()) {
                        throw new Exception('هەڵە لە گەڕاندنەوەی قەرزی کۆمپانیا');
                    }
                    
                    // سڕینەوەی تۆمارەکانی قەرزی کۆن لە company_debts
                    $deleteOldDebtsStmt = $conn->prepare("
                        DELETE FROM company_debts 
                        WHERE user_id = ? AND company_id = ? AND type = 'debt' 
                        AND description LIKE ?
                    ");
                    $debtDescription = "%#" . ($receipt['receipt_number'] ?: $receiptId) . "%";
                    $deleteOldDebtsStmt->bind_param("iis", $userId, $oldCompanyId, $debtDescription);
                    $deleteOldDebtsStmt->execute();
                }
                
                // 2. گەڕاندنەوەی کۆگا و نرخەکان
                // سەرەتا هەموو مەوجوداتەکان بگەڕێنەوە، پاشان نرخەکان بگەڕێنەوە
                // بۆ ئەوەی حیسابکردنی تێکڕا بە دروستی ئەنجام بدرێت
                
                foreach ($oldItems as $oldItem) {
                    $oldProductId = (int)$oldItem['product_id'];
                    $oldQuantity = (float)$oldItem['quantity'];
                    $oldPacketBonus = (float)($oldItem['packet_bonus'] ?? 0);
                    $oldUnitId = (int)$oldItem['unit_id'];
                    // بەپێی داواکاری: کۆگا تەنها بە هەمان بڕی نووسراو گۆڕانکاری بکات (نە بە conversion_rate)
                    // لە مۆدی دەرمانخانەدا بۆنسی پاکەتیش کە لە کۆگا زیادکراوە دەبێت بگەڕێندرێتەوە.
                    $quantityToSubtract = $isPharmacyMode
                        ? ($oldQuantity + $oldPacketBonus)
                        : $oldQuantity;
                    $primaryUnitRowId = 0;
                    
                    // وەرگرتنی داتای ئێستای کاڵا
                    $getOldProductStmt = $conn->prepare("
                        SELECT 
                            COALESCE(pu.buy_price, 0) AS buy_price,
                            COALESCE(pu.sell_price, 0) AS sell_price,
                            COALESCE(pu.wholesale_price, 0) AS wholesale_price,
                            COALESCE(pu.special_price, 0) AS special_price,
                            COALESCE(pu.stock_quantity, 0) AS stock_quantity,
                            COALESCE(pu.id, 0) AS primary_unit_row_id,
                            COALESCE(pu.conversion_ratio, 1) AS primary_ratio,
                            p.expiry_date
                        FROM products p
                        LEFT JOIN product_units pu ON pu.id = (
                            SELECT pu2.id
                            FROM product_units pu2
                            WHERE pu2.product_id = p.id
                            ORDER BY pu2.is_primary DESC, pu2.id ASC
                            LIMIT 1
                        )
                        WHERE p.id = ? AND p.user_id = ?
                    ");
                    $getOldProductStmt->bind_param("ii", $oldProductId, $userId);
                    $getOldProductStmt->execute();
                    $oldProductData = $getOldProductStmt->get_result()->fetch_assoc();

                    if ($oldProductData) {
                        $currentStock = (float)$oldProductData['stock_quantity'];
                        $currentBuyPrice = (float)$oldProductData['buy_price'];
                        $currentSellPrice = (float)$oldProductData['sell_price'];
                        $currentWholesalePrice = (float)$oldProductData['wholesale_price'];
                        $currentSpecialPrice = (float)$oldProductData['special_price'];
                        $primaryUnitRowId = (int)($oldProductData['primary_unit_row_id'] ?? 0);

                        // کەمکردنەوەی یەکەی سەرەکی بەپێی conversion (تەنها مۆدی ئاسایی):
                        // ئەگەر یەکەی کڕدراو ناسەرەکی بێت، بڕی هاوتای سەرەکی کەم دەکرێتەوە.
                        // کەیسی باو (کڕین بە یەکەی سەرەکی) یان دەرمانخانە: scale = 1، هیچ ناگۆڕێت.
                        $primaryRatio = (float)($oldProductData['primary_ratio'] ?? 1);
                        $purchasedRatio = $primaryRatio;
                        if (!$isPharmacyMode && $oldUnitId > 0) {
                            $puRatioStmt = $conn->prepare("SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?");
                            $puRatioStmt->bind_param("ii", $oldProductId, $oldUnitId);
                            $puRatioStmt->execute();
                            $puRatioRow = $puRatioStmt->get_result()->fetch_assoc();
                            $fetchedRatio = $puRatioRow['conversion_ratio'] ?? null;
                            if ($fetchedRatio !== null && (float)$fetchedRatio > 0) {
                                $purchasedRatio = (float)$fetchedRatio;
                            }
                        }
                        $primaryScale = ($primaryRatio > 0) ? ($purchasedRatio / $primaryRatio) : 1.0;
                        $primaryStockDeduction = $quantityToSubtract * $primaryScale;

                        // بڕی کۆگا پێش زیادکردنی وەسڵی کۆن
                        $stockAfterRevert = $currentStock - $quantityToSubtract;

                        // گەڕاندنەوەی کۆگا
                        $revertStockStmt = $conn->prepare("
                            UPDATE product_units
                            SET stock_quantity = GREATEST(0, stock_quantity - ?), updated_at = NOW()
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
                        $revertStockStmt->bind_param("dii", $primaryStockDeduction, $oldProductId, $userId);
                        $revertStockStmt->execute();
                        
                        // گەڕاندنەوەی نرخەکان: تێکڕا یان لە snapshot (جێگیر)
                        if ($quantityToSubtract > 0) {
                            $havePriSnap = $oldStrategy === 0
                                && isset($oldItem['revert_buy_price'], $oldItem['revert_sell_price'], $oldItem['revert_wholesale_price'], $oldItem['revert_special_price'])
                                && $oldItem['revert_buy_price'] !== null && $oldItem['revert_sell_price'] !== null
                                && $oldItem['revert_wholesale_price'] !== null && $oldItem['revert_special_price'] !== null;

                            if ($havePriSnap) {
                                $revertedBuyPrice = max(0, round((float)$oldItem['revert_buy_price'], 4));
                                $revertedSellPrice = max(0, round((float)$oldItem['revert_sell_price'], 4));
                                $revertedWholesalePrice = max(0, round((float)$oldItem['revert_wholesale_price'], 4));
                                $revertedSpecialPrice = max(0, round((float)$oldItem['revert_special_price'], 4));
                                $revertPriceStmt = $conn->prepare("
                                    UPDATE product_units
                                    SET buy_price = ?, sell_price = ?, wholesale_price = ?, special_price = ?, updated_at = NOW()
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
                                $revertPriceStmt->bind_param("ddddii",
                                    $revertedBuyPrice,
                                    $revertedSellPrice,
                                    $revertedWholesalePrice,
                                    $revertedSpecialPrice,
                                    $oldProductId,
                                    $userId
                                );
                                $revertPriceStmt->execute();
                            } else {
                                // فۆرمۆلای پێچەوانەی تێکڕا (یان fallback کاتێک snapshot نییە)
                                $oldBuyPrice = (float)$oldItem['buy_price'];
                                $oldSellPrice = (float)$oldItem['sell_price'];
                                $oldWholesalePrice = (float)$oldItem['wholesale_price'];
                                $oldSpecialPrice = (float)$oldItem['special_price'];
                                
                                $actualStockAfterRevert = max(0, $stockAfterRevert);
                                
                                if ($actualStockAfterRevert > 0) {
                                    $actualPurchaseInStock = min($quantityToSubtract, $currentStock);
                                    
                                    if ($actualPurchaseInStock > 0 && $currentStock > 0) {
                                        $revertedBuyPrice = (($currentStock * $currentBuyPrice) - ($actualPurchaseInStock * $oldBuyPrice)) / $actualStockAfterRevert;
                                        $revertedSellPrice = (($currentStock * $currentSellPrice) - ($actualPurchaseInStock * $oldSellPrice)) / $actualStockAfterRevert;
                                        $revertedWholesalePrice = (($currentStock * $currentWholesalePrice) - ($actualPurchaseInStock * $oldWholesalePrice)) / $actualStockAfterRevert;
                                        $revertedSpecialPrice = (($currentStock * $currentSpecialPrice) - ($actualPurchaseInStock * $oldSpecialPrice)) / $actualStockAfterRevert;
                                    } else {
                                        $revertedBuyPrice = $currentBuyPrice;
                                        $revertedSellPrice = $currentSellPrice;
                                        $revertedWholesalePrice = $currentWholesalePrice;
                                        $revertedSpecialPrice = $currentSpecialPrice;
                                    }
                                    
                                    $revertedBuyPrice = max(0, round($revertedBuyPrice, 4));
                                    $revertedSellPrice = max(0, round($revertedSellPrice, 4));
                                    $revertedWholesalePrice = max(0, round($revertedWholesalePrice, 4));
                                    $revertedSpecialPrice = max(0, round($revertedSpecialPrice, 4));
                                    
                                    $revertPriceStmt = $conn->prepare("
                                        UPDATE product_units
                                        SET buy_price = ?, sell_price = ?, wholesale_price = ?, special_price = ?, updated_at = NOW()
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
                                    $revertPriceStmt->bind_param("ddddii", 
                                        $revertedBuyPrice, 
                                        $revertedSellPrice, 
                                        $revertedWholesalePrice, 
                                        $revertedSpecialPrice,
                                        $oldProductId, 
                                        $userId
                                    );
                                    $revertPriceStmt->execute();
                                } else {
                                    $zeroPrice = 0;
                                    $revertPriceStmt = $conn->prepare("
                                        UPDATE product_units
                                        SET buy_price = ?, sell_price = ?, wholesale_price = ?, special_price = ?, updated_at = NOW()
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
                                    $revertPriceStmt->bind_param("ddddii", 
                                        $zeroPrice, $zeroPrice, $zeroPrice, $zeroPrice,
                                        $oldProductId, 
                                        $userId
                                    );
                                    $revertPriceStmt->execute();
                                }
                            }
                        }
                        
                        // گەڕاندنەوەی بەرواری بەسەرچوون ئەگەر وەسڵی کۆن بەرواری هەبووە
                        if (!empty($oldItem['expiry_date']) && $oldItem['expiry_date'] !== null) {
                            $revertExpiryStmt = $conn->prepare("
                                UPDATE products SET expiry_date = NULL
                                WHERE id = ? AND user_id = ? AND expiry_date = ?
                            ");
                            $revertExpiryStmt->bind_param("iis", $oldProductId, $userId, $oldItem['expiry_date']);
                            $revertExpiryStmt->execute();
                        }
                    }
                    
                    // گەڕاندنەوەی بڕی یەکە
                    if ($oldUnitId > 0) {
                        // وەرگرتنی داتای ئێستای یەکە پێش گەڕاندنەوە
                        $getOldUnitStmt = $conn->prepare("
                            SELECT id AS unit_row_id, buy_price, sell_price, wholesale_price, special_price, stock_quantity
                            FROM product_units 
                            WHERE product_id = ? AND unit_id = ?
                        ");
                        $getOldUnitStmt->bind_param("ii", $oldProductId, $oldUnitId);
                        $getOldUnitStmt->execute();
                        $oldUnitData = $getOldUnitStmt->get_result()->fetch_assoc();

                        $oldUnitRowId = (int)($oldUnitData['unit_row_id'] ?? 0);
                        // ئەگەر unit هەمان primary بێت، دووجار حیسابکردن ڕووئەدات؛ بۆیە تەنها یەکجار بژمێرە
                        if ($oldUnitData && $oldUnitRowId > 0 && $oldUnitRowId !== $primaryUnitRowId) {
                            $revertUnitStockStmt = $conn->prepare("
                                UPDATE product_units SET 
                                    stock_quantity = GREATEST(0, stock_quantity - ?)
                                WHERE product_id = ? AND unit_id = ?
                            ");
                            $revertUnitStockStmt->bind_param("dii", $oldQuantity, $oldProductId, $oldUnitId);
                            $revertUnitStockStmt->execute();

                            // گەڕاندنەوەی نرخەکانی یەکە (یەکەی لاوەکی): snapshot تەنها کاتێک هەیە کە لە ڕیزەکەدا هەمان نرخەکان بەکاربهێنرابن — ئێستا تێکڕا وەک fallback
                            if ($oldQuantity > 0) {
                            $currentUnitStock = (float)$oldUnitData['stock_quantity'];
                            $unitStockAfterRevert = $currentUnitStock - $oldQuantity;
                            $actualUnitStockAfterRevert = max(0, $unitStockAfterRevert);
                            
                            if ($actualUnitStockAfterRevert > 0) {
                                $oldUnitBuyPrice = (float)$oldItem['buy_price'];
                                $oldUnitSellPrice = (float)$oldItem['sell_price'];
                                $oldUnitWholesalePrice = (float)$oldItem['wholesale_price'];
                                $oldUnitSpecialPrice = (float)$oldItem['special_price'];
                                
                                $currentUnitBuyPrice = (float)$oldUnitData['buy_price'];
                                $currentUnitSellPrice = (float)$oldUnitData['sell_price'];
                                $currentUnitWholesalePrice = (float)$oldUnitData['wholesale_price'];
                                $currentUnitSpecialPrice = (float)$oldUnitData['special_price'];
                                
                                $actualUnitPurchaseInStock = min($oldQuantity, $currentUnitStock);
                                
                                if ($actualUnitPurchaseInStock > 0 && $currentUnitStock > 0) {
                                    $revertedUnitBuyPrice = max(0, round((($currentUnitStock * $currentUnitBuyPrice) - ($actualUnitPurchaseInStock * $oldUnitBuyPrice)) / $actualUnitStockAfterRevert, 4));
                                    $revertedUnitSellPrice = max(0, round((($currentUnitStock * $currentUnitSellPrice) - ($actualUnitPurchaseInStock * $oldUnitSellPrice)) / $actualUnitStockAfterRevert, 4));
                                    $revertedUnitWholesalePrice = max(0, round((($currentUnitStock * $currentUnitWholesalePrice) - ($actualUnitPurchaseInStock * $oldUnitWholesalePrice)) / $actualUnitStockAfterRevert, 4));
                                    $revertedUnitSpecialPrice = max(0, round((($currentUnitStock * $currentUnitSpecialPrice) - ($actualUnitPurchaseInStock * $oldUnitSpecialPrice)) / $actualUnitStockAfterRevert, 4));
                                } else {
                                    $revertedUnitBuyPrice = $currentUnitBuyPrice;
                                    $revertedUnitSellPrice = $currentUnitSellPrice;
                                    $revertedUnitWholesalePrice = $currentUnitWholesalePrice;
                                    $revertedUnitSpecialPrice = $currentUnitSpecialPrice;
                                }
                                
                                $revertUnitPriceStmt = $conn->prepare("
                                    UPDATE product_units SET 
                                        buy_price = ?, 
                                        sell_price = ?, 
                                        wholesale_price = ?, 
                                        special_price = ?
                                    WHERE product_id = ? AND unit_id = ?
                                ");
                                $revertUnitPriceStmt->bind_param("ddddii", 
                                    $revertedUnitBuyPrice, 
                                    $revertedUnitSellPrice, 
                                    $revertedUnitWholesalePrice, 
                                    $revertedUnitSpecialPrice,
                                    $oldProductId, 
                                    $oldUnitId
                                );
                                $revertUnitPriceStmt->execute();
                            } else {
                                $zeroPrice = 0;
                                $revertUnitPriceStmt = $conn->prepare("
                                    UPDATE product_units SET 
                                        buy_price = ?, 
                                        sell_price = ?, 
                                        wholesale_price = ?, 
                                        special_price = ?
                                    WHERE product_id = ? AND unit_id = ?
                                ");
                                $revertUnitPriceStmt->bind_param("ddddii", 
                                    $zeroPrice, $zeroPrice, $zeroPrice, $zeroPrice,
                                    $oldProductId, 
                                    $oldUnitId
                                );
                                $revertUnitPriceStmt->execute();
                            }
                        }
                    }
                }
                }

                // 2.a هاوکاتکردنی (sync) یەکەکانی تری ماوە بەپێی conversion_ratio — پێچەوانەی add (تەنها مۆدی ئاسایی).
                // لوپی سەرەوە یەکەی سەرەکی و یەکەی کڕدراوی گەڕاندەوە؛ ئەمە تەنها یەکەکانی تر ڕاست دەکاتەوە.
                if (!$isPharmacyMode) {
                    foreach ($oldItems as $oldSyncItem) {
                        $syncProductId = (int)($oldSyncItem['product_id'] ?? 0);
                        $syncQuantity = (float)($oldSyncItem['quantity'] ?? 0);
                        $syncUnitId = (int)($oldSyncItem['unit_id'] ?? 0);
                        if ($syncProductId <= 0 || $syncQuantity <= 0) {
                            continue;
                        }

                        // یەکەی سەرەکی (بۆ دەرکردن لە sync چونکە لوپی سەرەوە کارەکەی کردووە)
                        $priUnitStmt = $conn->prepare("
                            SELECT unit_id, conversion_ratio
                            FROM product_units
                            WHERE product_id = ?
                            ORDER BY is_primary DESC, id ASC
                            LIMIT 1
                        ");
                        $priUnitStmt->bind_param("i", $syncProductId);
                        $priUnitStmt->execute();
                        $priUnitRow = $priUnitStmt->get_result()->fetch_assoc();
                        $syncPrimaryUnitId = (int)($priUnitRow['unit_id'] ?? 0);

                        // یەکەی ئەنکەر (کڕدراو) و ڕێژەکەی
                        $syncRefUnitId = $syncUnitId;
                        $syncRefRatio = null;
                        if ($syncRefUnitId <= 0) {
                            $syncRefUnitId = $syncPrimaryUnitId;
                            $syncRefRatio = $priUnitRow['conversion_ratio'] ?? null;
                        } else {
                            $syncRefRatioStmt = $conn->prepare("SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?");
                            $syncRefRatioStmt->bind_param("ii", $syncProductId, $syncRefUnitId);
                            $syncRefRatioStmt->execute();
                            $syncRefRatioRow = $syncRefRatioStmt->get_result()->fetch_assoc();
                            $syncRefRatio = $syncRefRatioRow['conversion_ratio'] ?? null;
                        }

                        if ($syncRefRatio === null || (float)$syncRefRatio <= 0) {
                            error_log("Purchase edit revert sync skipped: invalid ref conversion_ratio for product_id=$syncProductId, unit_id=$syncRefUnitId");
                            continue;
                        }

                        $otherStmt = $conn->prepare("
                            SELECT unit_id, conversion_ratio
                            FROM product_units
                            WHERE product_id = ? AND unit_id != ? AND unit_id != ?
                        ");
                        $otherStmt->bind_param("iii", $syncProductId, $syncRefUnitId, $syncPrimaryUnitId);
                        $otherStmt->execute();
                        $otherRes = $otherStmt->get_result();
                        while ($ou = $otherRes->fetch_assoc()) {
                            $ouId = (int)$ou['unit_id'];
                            $ouRatio = $ou['conversion_ratio'];
                            if ($ouRatio === null || (float)$ouRatio <= 0) {
                                error_log("Purchase edit revert sync skipped for unit_id=$ouId (product_id=$syncProductId): invalid conversion_ratio");
                                continue;
                            }
                            $subAmount = $syncQuantity * ((float)$syncRefRatio / (float)$ouRatio);
                            $updSync = $conn->prepare("
                                UPDATE product_units SET stock_quantity = GREATEST(0, stock_quantity - ?), updated_at = NOW()
                                WHERE product_id = ? AND unit_id = ?
                            ");
                            $updSync->bind_param("dii", $subAmount, $syncProductId, $ouId);
                            $updSync->execute();
                        }
                    }
                }

                // 2.b گەڕاندنەوەی کۆگا و نرخی یەکەی شیت (تەنها مۆدی دەرمانخانە) — هاوشێوەی add.php بەڵام بە پێچەوانە
                if ($isPharmacyMode && $sheet_unit_id) {
                    foreach ($oldItems as $oldSheetItem) {
                        $oldSheetProductId = (int)($oldSheetItem['product_id'] ?? 0);
                        $oldSheetsPerPacket = (int)($oldSheetItem['sheets_per_packet'] ?? 0);
                        if ($oldSheetProductId <= 0 || $oldSheetsPerPacket <= 0) {
                            continue;
                        }
                        $oldSheetQty = ((float)$oldSheetItem['quantity'] + (float)($oldSheetItem['packet_bonus'] ?? 0)) * $oldSheetsPerPacket;
                        if ($oldSheetQty <= 0) {
                            continue;
                        }

                        $getSheetStmt = $conn->prepare("SELECT stock_quantity, buy_price, sell_price FROM product_units WHERE product_id = ? AND unit_id = ?");
                        $getSheetStmt->bind_param("ii", $oldSheetProductId, $sheet_unit_id);
                        $getSheetStmt->execute();
                        $currentSheetUnit = $getSheetStmt->get_result()->fetch_assoc();
                        $getSheetStmt->close();
                        if (!$currentSheetUnit) {
                            continue;
                        }

                        $revertSheetStockStmt = $conn->prepare("
                            UPDATE product_units SET stock_quantity = GREATEST(0, stock_quantity - ?), updated_at = NOW()
                            WHERE product_id = ? AND unit_id = ?
                        ");
                        $revertSheetStockStmt->bind_param("dii", $oldSheetQty, $oldSheetProductId, $sheet_unit_id);
                        $revertSheetStockStmt->execute();
                        $revertSheetStockStmt->close();

                        $currentSheetStock = (float)$currentSheetUnit['stock_quantity'];
                        $sheetStockAfterRevert = max(0, $currentSheetStock - $oldSheetQty);
                        $haveSheetSnap = $oldStrategy === 0
                            && isset($oldSheetItem['revert_sheet_buy_price'], $oldSheetItem['revert_sheet_sell_price'])
                            && $oldSheetItem['revert_sheet_buy_price'] !== null && $oldSheetItem['revert_sheet_sell_price'] !== null;
                        if ($haveSheetSnap) {
                            $revertedSheetBuy = max(0, round((float)$oldSheetItem['revert_sheet_buy_price'], 4));
                            $revertedSheetSell = max(0, round((float)$oldSheetItem['revert_sheet_sell_price'], 4));
                            $upRevSheetPrice = $conn->prepare("UPDATE product_units SET buy_price = ?, sell_price = ?, updated_at = NOW() WHERE product_id = ? AND unit_id = ?");
                            $upRevSheetPrice->bind_param("ddii", $revertedSheetBuy, $revertedSheetSell, $oldSheetProductId, $sheet_unit_id);
                            $upRevSheetPrice->execute();
                            $upRevSheetPrice->close();
                        } elseif ($sheetStockAfterRevert > 0 && $currentSheetStock > 0) {
                            $oldSheetBuy = (float)$oldSheetItem['buy_price'] / $oldSheetsPerPacket;
                            $oldSheetSell = (float)$oldSheetItem['sell_price'] / $oldSheetsPerPacket;
                            $actualSheetInStock = min($oldSheetQty, $currentSheetStock);
                            $revertedSheetBuy = max(0, round((($currentSheetStock * (float)$currentSheetUnit['buy_price']) - ($actualSheetInStock * $oldSheetBuy)) / $sheetStockAfterRevert, 4));
                            $revertedSheetSell = max(0, round((($currentSheetStock * (float)$currentSheetUnit['sell_price']) - ($actualSheetInStock * $oldSheetSell)) / $sheetStockAfterRevert, 4));
                            $upRevSheetPrice = $conn->prepare("UPDATE product_units SET buy_price = ?, sell_price = ?, updated_at = NOW() WHERE product_id = ? AND unit_id = ?");
                            $upRevSheetPrice->bind_param("ddii", $revertedSheetBuy, $revertedSheetSell, $oldSheetProductId, $sheet_unit_id);
                            $upRevSheetPrice->execute();
                            $upRevSheetPrice->close();
                        }
                    }
                }

                // 3. سڕینەوەی کاڵای پێشوو
                $stmt = $conn->prepare("DELETE FROM purchase_receipt_items WHERE purchase_receipt_id = ?");
                $stmt->bind_param("i", $receiptId);
                if (!$stmt->execute()) {
                    throw new Exception('هەڵە لە سڕینەوەی کاڵای پێشوو');
                }
                
                // **جێبەجێکردنی داتای نوێ**
                // 4. زیادکردنی کاڵای نوێ
                if ($isPharmacyMode) {
                    $stmt = $conn->prepare("
                        INSERT INTO purchase_receipt_items 
                        (purchase_receipt_id, product_id, product_name, quantity, buy_price, sell_price, 
                         wholesale_price, special_price, expiry_date, total_cost, unit_id, packet_bonus, sheets_per_packet, discount_amount) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO purchase_receipt_items 
                        (purchase_receipt_id, product_id, product_name, quantity, buy_price, sell_price, 
                         wholesale_price, special_price, expiry_date, total_cost, unit_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                }
                
                foreach ($items as $item) {
                    $resolved = resolveOrCreateProductForPurchaseEdit(
                        $conn,
                        $userId,
                        $item,
                        $defaultUnitId,
                        $isPharmacyMode ? (int)($packet_unit_id ?? 0) : 0,
                        $receipt_currency
                    );
                    $item['product_id'] = (int)$resolved['product_id'];
                    $item['unit_id'] = (int)$resolved['unit_id'];

                    // وێنەی کاڵا: ئەگەر بەکارهێنەر وێنەی بۆ ئەم کاڵا بارکردبوو و کاڵاکە وێنەی نەبوو
                    purchaseReceiptApplyItemProductImage($conn, (int)$userId, (int)$item['product_id'], (string)($item['image_key'] ?? ''));

                    // نرخی تێکڕای کڕینی پاکەت بۆ مۆدی دەرمانخانە: total_cost ÷ (بڕ + بۆنس) — هاوشێوەی add.php
                    $buyPriceForAvg = (float)$item['buy_price'];
                    $sellPriceForAvg = (float)$item['sell_price'];
                    if ($isPharmacyMode) {
                        $totalPackets = (float)$item['quantity'] + (float)($item['packet_bonus'] ?? 0);
                        if ($totalPackets > 0) {
                            $lineCostForAvg = isset($item['total_cost']) ? (float)$item['total_cost'] : ((float)$item['quantity'] * (float)$item['buy_price']);
                            $buyPriceForAvg = $lineCostForAvg / $totalPackets;
                        }
                    }

                    // Handle NULL expiry_date properly
                    $expiryDate = $item['expiry_date'] ?: null;
                    
                    if ($isPharmacyMode) {
                        $stmt->bind_param(
                            "iisdddddsdidid",
                            $receiptId, $item['product_id'], $item['product_name'], $item['quantity'],
                            $item['buy_price'], $item['sell_price'], $item['wholesale_price'],
                            $item['special_price'], $expiryDate, $item['total_cost'], $item['unit_id'],
                            $item['packet_bonus'], $item['sheets_per_packet'], $item['discount_amount']
                        );
                    } else {
                        $stmt->bind_param(
                            "iisdddddsdi", 
                            $receiptId, $item['product_id'], $item['product_name'], $item['quantity'],
                            $item['buy_price'], $item['sell_price'], $item['wholesale_price'], 
                            $item['special_price'], $expiryDate, $item['total_cost'], $item['unit_id']
                        );
                    }
                    
                    if (!$stmt->execute()) {
                        throw new Exception('هەڵە لە زیادکردنی کاڵا: ' . $item['product_name']);
                    }

                    $purchaseItemId = (int)$conn->insert_id;
                    
                    // 5. نوێکردنەوەی کۆگا و نرخەکان (بەپێی conversion_rate)
                    if ($item['product_id'] > 0) {
                        $primaryUnitRowId = 0;
                        $unitRowId = 0;

                        // دڵنیابوون لە بوونی ڕیزی بنەڕەتی بۆ product_units
                        $ensurePrimaryStmt = $conn->prepare("
                            SELECT pu.id
                            FROM product_units pu
                            JOIN products p ON p.id = pu.product_id
                            WHERE pu.product_id = ? AND p.user_id = ?
                            ORDER BY pu.is_primary DESC, pu.id ASC
                            LIMIT 1
                        ");
                        $ensurePrimaryStmt->bind_param("ii", $item['product_id'], $userId);
                        $ensurePrimaryStmt->execute();
                        $primaryUnitRow = $ensurePrimaryStmt->get_result()->fetch_assoc();
                        $primaryUnitRowId = (int)($primaryUnitRow['id'] ?? 0);

                        if (!$primaryUnitRow) {
                            $seedUnitId = (int)($item['unit_id'] ?? 0);
                            $seedConversionRate = (float)($item['conversion_rate'] ?? 1.0);

                            if ($seedUnitId <= 0) {
                                throw new Exception('هەڵەی کۆگا: یەکەی بنەڕەتی بۆ کاڵای ' . $item['product_name'] . ' دیاری نەکراوە.');
                            }

                            $insertPrimaryUnitStmt = $conn->prepare("
                                INSERT INTO product_units (
                                    product_id, unit_id, buy_price, sell_price, wholesale_price, special_price,
                                    currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary
                                )
                                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 1, ?, 1)
                            ");
                            $insertPrimaryUnitStmt->bind_param(
                                "iiddddsd",
                                $item['product_id'],
                                $seedUnitId,
                                $item['buy_price'],
                                $item['sell_price'],
                                $item['wholesale_price'],
                                $item['special_price'],
                                $receipt_currency,
                                $seedConversionRate
                            );
                            if (!$insertPrimaryUnitStmt->execute()) {
                                throw new Exception('هەڵە لە دروستکردنی یەکەی بنەڕەتی بۆ کاڵای: ' . $item['product_name']);
                            }
                            $primaryUnitRowId = (int)$conn->insert_id;
                        }

                        // ئەگەر unit هەڵبژێردراوە، دڵنیابوون لە بوونی ڕیزەکەی
                        if ((int)$item['unit_id'] > 0) {
                            $ensureUnitStmt = $conn->prepare("
                                SELECT id
                                FROM product_units
                                WHERE product_id = ? AND unit_id = ?
                                LIMIT 1
                            ");
                            $ensureUnitStmt->bind_param("ii", $item['product_id'], $item['unit_id']);
                            $ensureUnitStmt->execute();
                            $unitRow = $ensureUnitStmt->get_result()->fetch_assoc();
                            $unitRowId = (int)($unitRow['id'] ?? 0);

                            if (!$unitRow) {
                                $unitConversionRate = (float)($item['conversion_rate'] ?? 1.0);
                                $unitConversionRatio = $unitConversionRate > 0 ? (1 / $unitConversionRate) : 1.0;
                                $insertUnitStmt = $conn->prepare("
                                    INSERT INTO product_units (
                                        product_id, unit_id, buy_price, sell_price, wholesale_price, special_price,
                                        currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary
                                    )
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0)
                                ");
                                $insertUnitStmt->bind_param(
                                    "iiddddsdd",
                                    $item['product_id'],
                                    $item['unit_id'],
                                    $item['buy_price'],
                                    $item['sell_price'],
                                    $item['wholesale_price'],
                                    $item['special_price'],
                                    $receipt_currency,
                                    $unitConversionRatio,
                                    $unitConversionRate
                                );
                                if (!$insertUnitStmt->execute()) {
                                    throw new Exception('هەڵە لە دروستکردنی یەکەی کاڵا: ' . $item['product_name']);
                                }
                                $unitRowId = (int)$conn->insert_id;
                            }
                        }

                        // بەپێی داواکاری: تەنها هەمان بڕی دیاریکراو زیاد/کەم بکرێت
                        $quantityToAdd = $isPharmacyMode
                            ? ((float)$item['quantity'] + (float)($item['packet_bonus'] ?? 0))
                            : (float)$item['quantity'];
                        
                        // وەرگرتنی نرخەکان و بڕی کۆگای پێشوو بۆ حیسابکردنی نرخی تێکڕا
                        $getCurrentPriceStmt = $conn->prepare("
                            SELECT
                                COALESCE(pu.buy_price, 0) AS buy_price,
                                COALESCE(pu.sell_price, 0) AS sell_price,
                                COALESCE(pu.wholesale_price, 0) AS wholesale_price,
                                COALESCE(pu.special_price, 0) AS special_price,
                                COALESCE(pu.stock_quantity, 0) AS stock_quantity,
                                COALESCE(pu.conversion_ratio, 1) AS primary_ratio
                            FROM products p
                            LEFT JOIN product_units pu ON pu.id = (
                                SELECT pu2.id
                                FROM product_units pu2
                                WHERE pu2.product_id = p.id
                                ORDER BY pu2.is_primary DESC, pu2.id ASC
                                LIMIT 1
                            )
                            WHERE p.id = ? AND p.user_id = ?
                        ");
                        $getCurrentPriceStmt->bind_param("ii", $item['product_id'], $userId);
                        $getCurrentPriceStmt->execute();
                        $currentProduct = $getCurrentPriceStmt->get_result()->fetch_assoc();

                        if (!$useWeighted && $purchaseItemId > 0 && $currentProduct) {
                            $revB = (float)$currentProduct['buy_price'];
                            $revS = (float)$currentProduct['sell_price'];
                            $revW = (float)$currentProduct['wholesale_price'];
                            $revSp = (float)$currentProduct['special_price'];
                            $updRevEd = $conn->prepare("
                                UPDATE purchase_receipt_items SET
                                    revert_buy_price = ?, revert_sell_price = ?, revert_wholesale_price = ?, revert_special_price = ?
                                WHERE id = ?
                            ");
                            $updRevEd->bind_param("ddddi", $revB, $revS, $revW, $revSp, $purchaseItemId);
                            $updRevEd->execute();
                            $updRevEd->close();
                        }
                        
                        $updateStockStmt = $conn->prepare("
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
                        
                        // زیادکردنی یەکەی سەرەکی بەپێی conversion (تەنها مۆدی ئاسایی):
                        // ئەگەر یەکەی کڕدراو ناسەرەکی بێت، بڕی هاوتای سەرەکی زیاد دەکرێت.
                        // کەیسی باو (کڕین بە یەکەی سەرەکی) یان دەرمانخانە: scale = 1، هیچ ناگۆڕێت.
                        $primaryRatioAdd = (float)($currentProduct['primary_ratio'] ?? 1);
                        $purchasedRatioAdd = $primaryRatioAdd;
                        if (!$isPharmacyMode && (int)($item['unit_id'] ?? 0) > 0) {
                            $puRatioStmt = $conn->prepare("SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?");
                            $puRatioStmt->bind_param("ii", $item['product_id'], $item['unit_id']);
                            $puRatioStmt->execute();
                            $puRatioRow = $puRatioStmt->get_result()->fetch_assoc();
                            $fetchedRatioAdd = $puRatioRow['conversion_ratio'] ?? null;
                            if ($fetchedRatioAdd !== null && (float)$fetchedRatioAdd > 0) {
                                $purchasedRatioAdd = (float)$fetchedRatioAdd;
                            }
                        }
                        $primaryScaleAdd = ($primaryRatioAdd > 0) ? ($purchasedRatioAdd / $primaryRatioAdd) : 1.0;
                        $primaryStockAdd = $quantityToAdd * $primaryScaleAdd;

                        $updateStockStmt->bind_param(
                            "dii",
                            $primaryStockAdd,
                            $item['product_id'],
                            $userId
                        );
                        if (!$updateStockStmt->execute()) {
                            throw new Exception('هەڵە لە نوێکردنەوەی کۆگای سەرەکی بۆ کاڵای: ' . $item['product_name']);
                        }
                        if ($updateStockStmt->affected_rows < 1) {
                            throw new Exception('نوێکردنەوەی کۆگای سەرەکی بۆ کاڵای ' . $item['product_name'] . ' ئەنجام نەدرا.');
                        }
                        
                        // حیسابکردن و نوێکردنەوەی نرخەکان: تێکڕا یان جێگیر
                        if ($currentProduct) {
                            $Q_old = max(0, (float)$currentProduct['stock_quantity']);
                            $Q_new = $quantityToAdd;
                            $Q_total = $Q_old + $Q_new;
                            
                            if ($Q_total > 0) {
                                if ($useWeighted) {
                                    if ($Q_old <= 0) {
                                        $newWeightedAverageBuy = $buyPriceForAvg;
                                        $newWeightedAverageSell = (float)$sellPriceForAvg;
                                        $newWeightedAverageWholesale = (float)$item['wholesale_price'];
                                        $newWeightedAverageSpecial = (float)$item['special_price'];
                                    } else {
                                        $P_old_buy = (float)$currentProduct['buy_price'];
                                        $P_new_buy = $buyPriceForAvg;
                                        $newWeightedAverageBuy = (($Q_old * $P_old_buy) + ($Q_new * $P_new_buy)) / $Q_total;
                                        
                                        $P_old_sell = (float)$currentProduct['sell_price'];
                                        $P_new_sell = (float)$sellPriceForAvg;
                                        $newWeightedAverageSell = (($Q_old * $P_old_sell) + ($Q_new * $P_new_sell)) / $Q_total;
                                        
                                        $P_old_wholesale = (float)$currentProduct['wholesale_price'];
                                        $P_new_wholesale = (float)$item['wholesale_price'];
                                        $newWeightedAverageWholesale = (($Q_old * $P_old_wholesale) + ($Q_new * $P_new_wholesale)) / $Q_total;
                                        
                                        $P_old_special = (float)$currentProduct['special_price'];
                                        $P_new_special = (float)$item['special_price'];
                                        $newWeightedAverageSpecial = (($Q_old * $P_old_special) + ($Q_new * $P_new_special)) / $Q_total;
                                    }
                                } else {
                                    $newWeightedAverageBuy = (float)$buyPriceForAvg;
                                    $newWeightedAverageSell = (float)$sellPriceForAvg;
                                    $newWeightedAverageWholesale = (float)$item['wholesale_price'];
                                    $newWeightedAverageSpecial = (float)$item['special_price'];
                                }
                                
                                $updatePriceStmt = $conn->prepare("
                                    UPDATE product_units
                                    SET buy_price = ?, sell_price = ?, wholesale_price = ?, special_price = ?, currency = '{$receipt_currency}', updated_at = NOW()
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
                                $updatePriceStmt->bind_param("ddddii", 
                                    $newWeightedAverageBuy, 
                                    $newWeightedAverageSell, 
                                    $newWeightedAverageWholesale, 
                                    $newWeightedAverageSpecial,
                                    $item['product_id'], 
                                    $userId
                                );
                                $updatePriceStmt->execute();
                            }
                        }
                        
                        // نوێکردنەوەی بەرواری بەسەرچوون بۆ کاڵا ئەگەر بەروار هەبێت
                        if (!empty($item['expiry_date']) && $item['expiry_date'] !== null) {
                            $updateExpiryStmt = $conn->prepare("
                                UPDATE products SET 
                                    expiry_date = ?
                                WHERE id = ? AND user_id = ?
                            ");
                            $updateExpiryStmt->bind_param("sii", 
                                $item['expiry_date'], 
                                $item['product_id'], 
                                $userId
                            );
                            $updateExpiryStmt->execute();
                        }
                        
                        // نوێکردنەوەی کۆگای یەکەی کاڵا
                        // لە مۆدی دەرمانخانە تەنها یەکەی شیت وەک یەکەی لاوەکی مامەڵە دەکرێت (لە خوارەوە)
                        if (!$isPharmacyMode && $item['unit_id'] > 0 && $unitRowId > 0 && $unitRowId !== $primaryUnitRowId) {
                            // وەرگرتنی نرخەکان و بڕی کۆگای پێشووی یەکە
                            $getUnitPriceStmt = $conn->prepare("
                                SELECT buy_price, sell_price, wholesale_price, special_price, stock_quantity 
                                FROM product_units 
                                WHERE product_id = ? AND unit_id = ?
                            ");
                            $getUnitPriceStmt->bind_param("ii", $item['product_id'], $item['unit_id']);
                            $getUnitPriceStmt->execute();
                            $currentUnit = $getUnitPriceStmt->get_result()->fetch_assoc();
                            
                            $updateUnitStockStmt = $conn->prepare("
                                UPDATE product_units SET 
                                    stock_quantity = stock_quantity + ?
                                WHERE product_id = ? AND unit_id = ?
                            ");
                            
                            $updateUnitStockStmt->bind_param(
                                "dii",
                                $item['quantity'], 
                                $item['product_id'], 
                                $item['unit_id']
                            );
                            if (!$updateUnitStockStmt->execute()) {
                                throw new Exception('هەڵە لە نوێکردنەوەی کۆگای یەکە بۆ کاڵای: ' . $item['product_name']);
                            }
                            if ($updateUnitStockStmt->affected_rows < 1) {
                                throw new Exception('نوێکردنەوەی کۆگای یەکە بۆ کاڵای ' . $item['product_name'] . ' ئەنجام نەدرا.');
                            }
                            
                            // نرخی یەکەی لاوەکی: تێکڕا یان جێگیر (بێ snapshotی جیا لە ڕیزەکە)
                            if ($currentUnit) {
                                $Q_old_unit = max(0, (float)$currentUnit['stock_quantity']);
                                $Q_new_unit = $item['quantity'];
                                $Q_total_unit = $Q_old_unit + $Q_new_unit;
                                
                                if ($Q_total_unit > 0) {
                                    if ($useWeighted) {
                                        if ($Q_old_unit <= 0) {
                                            $newWeightedAverageUnitBuy = $item['buy_price'];
                                            $newWeightedAverageUnitSell = (float)$item['sell_price'];
                                            $newWeightedAverageUnitWholesale = (float)$item['wholesale_price'];
                                            $newWeightedAverageUnitSpecial = (float)$item['special_price'];
                                        } else {
                                            $P_old_unit_buy = (float)$currentUnit['buy_price'];
                                            $P_new_unit_buy = $item['buy_price'];
                                            $newWeightedAverageUnitBuy = (($Q_old_unit * $P_old_unit_buy) + ($Q_new_unit * $P_new_unit_buy)) / $Q_total_unit;
                                            
                                            $P_old_unit_sell = (float)$currentUnit['sell_price'];
                                            $P_new_unit_sell = (float)$item['sell_price'];
                                            $newWeightedAverageUnitSell = (($Q_old_unit * $P_old_unit_sell) + ($Q_new_unit * $P_new_unit_sell)) / $Q_total_unit;
                                            
                                            $P_old_unit_wholesale = (float)$currentUnit['wholesale_price'];
                                            $P_new_unit_wholesale = (float)$item['wholesale_price'];
                                            $newWeightedAverageUnitWholesale = (($Q_old_unit * $P_old_unit_wholesale) + ($Q_new_unit * $P_new_unit_wholesale)) / $Q_total_unit;
                                            
                                            $P_old_unit_special = (float)$currentUnit['special_price'];
                                            $P_new_unit_special = (float)$item['special_price'];
                                            $newWeightedAverageUnitSpecial = (($Q_old_unit * $P_old_unit_special) + ($Q_new_unit * $P_new_unit_special)) / $Q_total_unit;
                                        }
                                    } else {
                                        $newWeightedAverageUnitBuy = (float)$item['buy_price'];
                                        $newWeightedAverageUnitSell = (float)$item['sell_price'];
                                        $newWeightedAverageUnitWholesale = (float)$item['wholesale_price'];
                                        $newWeightedAverageUnitSpecial = (float)$item['special_price'];
                                    }
                                    
                                    $updateUnitPriceStmt = $conn->prepare("
                                        UPDATE product_units SET
                                            buy_price = ?,
                                            sell_price = ?,
                                            wholesale_price = ?,
                                            special_price = ?,
                                            currency = '{$receipt_currency}'
                                        WHERE product_id = ? AND unit_id = ?
                                    ");
                                    $updateUnitPriceStmt->bind_param("ddddii",
                                        $newWeightedAverageUnitBuy,
                                        $newWeightedAverageUnitSell,
                                        $newWeightedAverageUnitWholesale,
                                        $newWeightedAverageUnitSpecial,
                                        $item['product_id'],
                                        $item['unit_id']
                                    );
                                    $updateUnitPriceStmt->execute();
                                }
                            }
                        }

                        // هاوکاتکردنی (sync) یەکەکانی تری ماوە بەپێی conversion_ratio — هاوشێوەی add.php (تەنها مۆدی ئاسایی).
                        // لۆجیکی سەرەوە یەکەی سەرەکی و یەکەی کڕدراوی نوێکردەوە؛ ئەمە تەنها یەکەکانی تر ڕاست دەکاتەوە.
                        if (!$isPharmacyMode) {
                            $syncProductId = (int)$item['product_id'];
                            $syncQuantity = (float)$item['quantity'];
                            $syncUnitId = (int)($item['unit_id'] ?? 0);

                            // یەکەی سەرەکی (بۆ دەرکردن لە sync)
                            $priUnitStmt = $conn->prepare("
                                SELECT unit_id, conversion_ratio
                                FROM product_units
                                WHERE product_id = ?
                                ORDER BY is_primary DESC, id ASC
                                LIMIT 1
                            ");
                            $priUnitStmt->bind_param("i", $syncProductId);
                            $priUnitStmt->execute();
                            $priUnitRow = $priUnitStmt->get_result()->fetch_assoc();
                            $syncPrimaryUnitId = (int)($priUnitRow['unit_id'] ?? 0);

                            // یەکەی ئەنکەر (کڕدراو) و ڕێژەکەی
                            $syncRefUnitId = $syncUnitId > 0 ? $syncUnitId : $syncPrimaryUnitId;
                            $syncRefRatio = null;
                            if ($syncUnitId > 0) {
                                $syncRefRatioStmt = $conn->prepare("SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?");
                                $syncRefRatioStmt->bind_param("ii", $syncProductId, $syncRefUnitId);
                                $syncRefRatioStmt->execute();
                                $syncRefRatioRow = $syncRefRatioStmt->get_result()->fetch_assoc();
                                $syncRefRatio = $syncRefRatioRow['conversion_ratio'] ?? null;
                            } else {
                                $syncRefRatio = $priUnitRow['conversion_ratio'] ?? null;
                            }

                            if ($syncRefRatio === null || (float)$syncRefRatio <= 0 || $syncQuantity <= 0) {
                                error_log("Purchase edit add sync skipped: invalid ref conversion_ratio/qty for product_id=$syncProductId, unit_id=$syncRefUnitId");
                            } else {
                                $otherStmt = $conn->prepare("
                                    SELECT unit_id, conversion_ratio
                                    FROM product_units
                                    WHERE product_id = ? AND unit_id != ? AND unit_id != ?
                                ");
                                $otherStmt->bind_param("iii", $syncProductId, $syncRefUnitId, $syncPrimaryUnitId);
                                $otherStmt->execute();
                                $otherRes = $otherStmt->get_result();
                                while ($ou = $otherRes->fetch_assoc()) {
                                    $ouId = (int)$ou['unit_id'];
                                    $ouRatio = $ou['conversion_ratio'];
                                    if ($ouRatio === null || (float)$ouRatio <= 0) {
                                        error_log("Purchase edit add sync skipped for unit_id=$ouId (product_id=$syncProductId): invalid conversion_ratio");
                                        continue;
                                    }
                                    $addAmount = $syncQuantity * ((float)$syncRefRatio / (float)$ouRatio);
                                    $updSync = $conn->prepare("
                                        UPDATE product_units SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                                        WHERE product_id = ? AND unit_id = ?
                                    ");
                                    $updSync->bind_param("dii", $addAmount, $syncProductId, $ouId);
                                    $updSync->execute();
                                }
                            }
                        }

                        // بۆ مۆدی دەرمانخانە: دروستکردن/نوێکردنەوەی یەکەی شیت (کۆگا + نرخ) — هاوشێوەی add.php
                        if ($isPharmacyMode && $sheet_unit_id && (int)($item['sheets_per_packet'] ?? 0) > 0) {
                            $sheetsPerPacket = (int)$item['sheets_per_packet'];
                            $sheetBuy = isset($item['sheet_buy_price']) && (float)$item['sheet_buy_price'] > 0
                                ? (float)$item['sheet_buy_price']
                                : ($buyPriceForAvg / $sheetsPerPacket);
                            $sheetSell = isset($item['sheet_sell_price']) && (float)$item['sheet_sell_price'] > 0
                                ? (float)$item['sheet_sell_price']
                                : ($sellPriceForAvg / $sheetsPerPacket);

                            // دڵنیابوون لە بوونی ڕیزی یەکەی شیت
                            $checkSheetUnit = $conn->prepare("SELECT id FROM product_units WHERE product_id = ? AND unit_id = ? LIMIT 1");
                            $checkSheetUnit->bind_param("ii", $item['product_id'], $sheet_unit_id);
                            $checkSheetUnit->execute();
                            $existingSheetUnit = $checkSheetUnit->get_result()->fetch_assoc();
                            $checkSheetUnit->close();
                            if (!$existingSheetUnit) {
                                $sheetConversionRatio = 1.0 / $sheetsPerPacket;
                                $sheetConversionRate = 1.0 / $sheetsPerPacket;
                                $sheetWholesale = 0.0;
                                $sheetSpecial = 0.0;
                                $insPuSheet = $conn->prepare("
                                    INSERT INTO product_units (product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0)
                                ");
                                $insPuSheet->bind_param("iiddddsdd", $item['product_id'], $sheet_unit_id, $sheetBuy, $sheetSell, $sheetWholesale, $sheetSpecial, $receipt_currency, $sheetConversionRatio, $sheetConversionRate);
                                $insPuSheet->execute();
                                $insPuSheet->close();
                            }

                            $sheetQtyToAdd = $quantityToAdd * $sheetsPerPacket;
                            $getSheetUnitStmt = $conn->prepare("SELECT stock_quantity, buy_price, sell_price FROM product_units WHERE product_id = ? AND unit_id = ?");
                            $getSheetUnitStmt->bind_param("ii", $item['product_id'], $sheet_unit_id);
                            $getSheetUnitStmt->execute();
                            $currentSheetUnit = $getSheetUnitStmt->get_result()->fetch_assoc();
                            $getSheetUnitStmt->close();

                            if (!$useWeighted && $purchaseItemId > 0 && $currentSheetUnit) {
                                $rsb = (float)$currentSheetUnit['buy_price'];
                                $rss = (float)$currentSheetUnit['sell_price'];
                                $updRevSh = $conn->prepare("
                                    UPDATE purchase_receipt_items SET revert_sheet_buy_price = ?, revert_sheet_sell_price = ? WHERE id = ?
                                ");
                                $updRevSh->bind_param("ddi", $rsb, $rss, $purchaseItemId);
                                $updRevSh->execute();
                                $updRevSh->close();
                            }

                            $upSheetStock = $conn->prepare("UPDATE product_units SET stock_quantity = stock_quantity + ?, updated_at = NOW() WHERE product_id = ? AND unit_id = ?");
                            $upSheetStock->bind_param("dii", $sheetQtyToAdd, $item['product_id'], $sheet_unit_id);
                            $upSheetStock->execute();
                            $upSheetStock->close();

                            if ($currentSheetUnit && ((float)$currentSheetUnit['stock_quantity'] + $sheetQtyToAdd) > 0) {
                                $Q_old_s = max(0, (float)$currentSheetUnit['stock_quantity']);
                                if ($useWeighted && $Q_old_s > 0) {
                                    $newAvgBuy = ($Q_old_s * (float)$currentSheetUnit['buy_price'] + $sheetQtyToAdd * $sheetBuy) / ($Q_old_s + $sheetQtyToAdd);
                                    $newAvgSell = ($Q_old_s * (float)$currentSheetUnit['sell_price'] + $sheetQtyToAdd * $sheetSell) / ($Q_old_s + $sheetQtyToAdd);
                                } else {
                                    $newAvgBuy = (float)$sheetBuy;
                                    $newAvgSell = (float)$sheetSell;
                                }
                                $upSheetPrice = $conn->prepare("UPDATE product_units SET buy_price = ?, sell_price = ?, wholesale_price = 0, special_price = 0, updated_at = NOW() WHERE product_id = ? AND unit_id = ?");
                                $upSheetPrice->bind_param("ddii", $newAvgBuy, $newAvgSell, $item['product_id'], $sheet_unit_id);
                                $upSheetPrice->execute();
                                $upSheetPrice->close();
                            }
                        }
                    }
                }
                
                // 6. جێبەجێکردنی قەرزی نوێ ئەگەر payment_type نوێ debt بێت
                if ($payment_type === 'debt' && $company_id > 0) {
                    // زیادکردنی قەرز لە کۆمپانیای نوێ
                    $applyDebtStmt = $conn->prepare("
                        UPDATE companies
                        SET {$receipt_debt_column} = {$receipt_debt_column} + ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $applyDebtStmt->bind_param("dii", $final_amount, $company_id, $userId);
                    if (!$applyDebtStmt->execute()) {
                        throw new Exception('هەڵە لە زیادکردنی قەرزی کۆمپانیا');
                    }

                    // دروستکردنی تۆماری لە company_debts (بە دراوی وەسڵ)
                    $description = "وەسڵی کڕین #" . ($receipt_number ?: $receiptId);
                    $insertDebtStmt = $conn->prepare("
                        INSERT INTO company_debts (user_id, company_id, purchase_receipt_id, amount, currency, description, type, date)
                        VALUES (?, ?, ?, ?, ?, ?, 'debt', ?)
                    ");
                    $insertDebtStmt->bind_param("iiidsss", $userId, $company_id, $receiptId, $final_amount, $receipt_currency, $description, $receipt_date);
                    if (!$insertDebtStmt->execute()) {
                        throw new Exception('هەڵە لە تۆمارکردنی قەرزی کۆمپانیا');
                    }
                }
                
                // 7. مامەڵەکردن لەگەڵ گۆڕانکاری لە company_id
                if ($oldCompanyId != $company_id) {
                    // ئەگەر کۆمپانیا گۆڕدرا و کۆمپانیای کۆن قەرزی هەبووە
                    if ($oldPaymentType === 'debt' && $oldCompanyId > 0 && $oldCompanyId != $company_id) {
                        // قەرزی کۆمپانیای کۆن کەم بکەرەوە (ئەگەر پێشتر کەم نەکرابێتەوە)
                        // لەبەر ئەوەی لە سەرەوە کەمکرایەوە، تەنها دڵنیابوونەوە
                    }
                    
                    // ئەگەر کۆمپانیای نوێ payment_type='debt' بێت، قەرز زیاد بکە
                    // ئەمە لە سەرەوە جێبەجێ کرا
                }
                
                $conn->commit();
                foreach ($affectedProductIds as $pid) {
                    $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $pid);
                    logProductChangeEvent(
                        'purchase_receipt.update',
                        'purchase_receipt',
                        $receiptId,
                        $beforeSnapshots[$pid] ?? null,
                        $afterSnapshot,
                        [
                            'user_id' => $userId,
                            'current_user' => $currentUser,
                            'product_id' => $pid,
                            'source_module' => 'user/purchases/edit.php',
                            'source_reference' => (string)$receiptId
                        ]
                    );
                }
                
                // لۆگکردنی چالاکی
                logActivity("Purchase Receipt Updated", "Updated receipt ID: $receiptId, Company: " . $companies[array_search($company_id, array_column($companies, 'id'))]['name'] ?? 'Unknown');
                
                setMessage('وەسڵ بە سەرکەوتووی نوێکرایەوە', 'success');
                
                // ڕیدایڕێکت بەپێی ئارەزووی بەکارهێنەر  
                if (isset($_POST['save_and_continue'])) {
                    redirect(url("user/purchases/edit.php?id=$receiptId"));
                } elseif (isset($_POST['save_and_view'])) {
                    redirect(url("user/purchases/view.php?id=$receiptId"));
                } else {
                    redirect(url('user/purchases/index.php'));
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'دەستکاری وەسڵی کڕدراو';
include '../../includes/header.php';
?>

<style>
/* ============================================================
   دیزاینی نوێی وەسڵی کڕدراو — Edit  (Comfortable · Indigo accent)
   ============================================================ */
.receipt-edit-container {
    --accent: #667eea;
    --accent-2: #5a67d8;
    --accent-strong: #0d6efd;
    --accent-soft: rgba(102, 126, 234, 0.12);
    --accent-ring: rgba(102, 126, 234, 0.22);
    --grad: linear-gradient(135deg, #667eea 0%, #5a67d8 100%);

    --page-bg: #eef1f8;
    --surface: #ffffff;
    --surface-2: #f6f8fc;
    --surface-3: #eef1f7;
    --border: #e4e8f0;
    --border-strong: #d4dae6;
    --text: #1f2937;
    --muted: #6b7280;

    --radius: 18px;
    --radius-md: 14px;
    --radius-sm: 11px;
    --shadow-sm: 0 1px 2px rgba(16,24,40,.04), 0 2px 6px rgba(16,24,40,.06);
    --shadow-md: 0 6px 18px rgba(16,24,40,.08);
    --shadow-lg: 0 22px 48px rgba(16,24,40,.13);
    --ease: 0.22s cubic-bezier(.4,0,.2,1);

    background: var(--page-bg);
    min-height: calc(100vh - 100px);
    padding: 28px 0 56px;
    color: var(--text);
}

/* ---------- کارتی سەرەکی ---------- */
.receipt-edit-container .receipt-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.receipt-edit-container .receipt-header {
    position: relative;
    background: var(--surface);
    color: var(--text);
    padding: 22px 26px;
    border-bottom: 1px solid var(--border);
}
.receipt-edit-container .receipt-header::before {
    content: "";
    position: absolute;
    inset: 0 0 auto 0;
    height: 4px;
    background: var(--grad);
}
.receipt-edit-container .receipt-header h4 {
    font-weight: 800;
    letter-spacing: -.2px;
    display: flex;
    align-items: center;
    gap: .6rem;
    margin-bottom: .2rem;
}
.receipt-edit-container .receipt-header h4 i {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 13px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: 1.3rem;
    flex: 0 0 auto;
}
.receipt-edit-container .receipt-header p { color: var(--muted); }

/* ---------- خانەکانی فۆڕم ---------- */
.receipt-edit-container .form-label {
    font-weight: 600;
    color: #344054;
    margin-bottom: .45rem;
    font-size: .9rem;
}
.receipt-edit-container .form-label i { color: var(--accent); }

.receipt-edit-container .form-control,
.receipt-edit-container .form-select {
    border: 1.5px solid var(--border-strong);
    border-radius: var(--radius-sm);
    padding: 12px 15px;
    background: var(--surface);
    color: var(--text);
    transition: border-color var(--ease), box-shadow var(--ease), background var(--ease);
}
.receipt-edit-container .form-control::placeholder { color: #9aa3b2; }
.receipt-edit-container .form-control:hover,
.receipt-edit-container .form-select:hover { border-color: #b9c2d4; }
.receipt-edit-container .form-control:focus,
.receipt-edit-container .form-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 4px var(--accent-ring);
    background: var(--surface);
}
.receipt-edit-container textarea.form-control { min-height: 90px; }

/* sub-card بۆ گرووپی خانەکان */
.receipt-edit-container .form-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 18px 18px 4px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
}
.receipt-edit-container .section-title {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-weight: 700;
    font-size: .98rem;
    color: var(--text);
    margin: 0 0 16px;
}
.receipt-edit-container .section-title i { color: var(--accent); }

/* ---------- دوگمەکان ---------- */
.receipt-edit-container .btn {
    border-radius: var(--radius-sm);
    font-weight: 600;
    transition: transform var(--ease), box-shadow var(--ease), filter var(--ease), background var(--ease), border-color var(--ease), color var(--ease);
}
.receipt-edit-container .btn:not(.btn-sm) { padding: 11px 22px; }
.receipt-edit-container .btn:active { transform: translateY(0) scale(.99); }
.receipt-edit-container .btn-primary {
    background: var(--grad);
    border: none;
    color: #fff;
    box-shadow: 0 6px 16px rgba(102,126,234,.35);
}
.receipt-edit-container .btn-primary:hover {
    filter: brightness(1.05);
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(102,126,234,.42);
}
.receipt-edit-container .btn-outline-primary { color: var(--accent-2); border-color: var(--accent); }
.receipt-edit-container .btn-outline-primary:hover { background: var(--accent); border-color: var(--accent); color: #fff; transform: translateY(-1px); }
.receipt-edit-container .btn-info:hover,
.receipt-edit-container .btn-success:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

/* ---------- کۆنتەینەری کاڵاکان ---------- */
.receipt-edit-container .items-container {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 20px;
    margin: 22px 0;
}
.receipt-edit-container .items-container h5 {
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: .45rem;
}
.receipt-edit-container .items-container h5 i { color: var(--accent); }

/* ---------- ڕیزی کاڵا ---------- */
.receipt-edit-container .item-row {
    position: relative;
    background: var(--surface);
    border: 1px solid var(--border);
    border-inline-start: 4px solid var(--accent);
    border-radius: var(--radius-md);
    padding: 20px 22px;
    margin-bottom: 16px;
    box-shadow: var(--shadow-sm);
    transition: box-shadow var(--ease), transform var(--ease), border-color var(--ease);
}
.receipt-edit-container .item-row:last-child { margin-bottom: 0; }
.receipt-edit-container .item-row:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.receipt-edit-container .item-row:focus-within {
    border-inline-start-color: var(--accent-2);
    box-shadow: 0 0 0 4px var(--accent-ring), var(--shadow-md);
}

.receipt-edit-container .item-row .form-control,
.receipt-edit-container .item-row .form-select {
    font-size: .92rem;
    padding: 10px 13px;
    border: 1.5px solid var(--border-strong);
    border-radius: 10px;
}
.receipt-edit-container .item-row .form-control:focus,
.receipt-edit-container .item-row .form-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-ring);
}
.receipt-edit-container .item-row .form-label {
    font-weight: 600;
    color: #475467;
    margin-bottom: 6px;
    font-size: .82rem;
}

/* ژمارەی ڕیز وەک badge */
.receipt-edit-container .product-name-label .item-row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.6rem;
    height: 1.6rem;
    padding: 0 .45rem;
    margin-inline-end: .35rem;
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent-2);
    font-weight: 700;
    font-size: .78rem;
    vertical-align: middle;
}

/* جیاکردنەوەی ناسک لە نێوان گرووپە ڕیزەکاندا (مۆدی دەرمانخانە) */
.receipt-edit-container .pharmacy-item-row .row + .row { margin-top: .85rem; }

/* خانە تەنها-خوێندنەوەکان */
.receipt-edit-container .item-row .bg-light,
.receipt-edit-container .avg-buy-after-bonus-discount,
.receipt-edit-container .sheet-buy-price {
    background: var(--surface-3) !important;
    border-style: dashed !important;
    color: #475467;
    font-weight: 600;
}

/* دوگمەی سڕینەوە */
.receipt-edit-container .delete-item.btn { font-weight: 600; }
.receipt-edit-container .delete-item:hover { transform: translateY(-1px); }

/* ---------- پانێلی کورتە ---------- */
.receipt-edit-container .floating-summary { position: sticky; top: 20px; z-index: 100; }

.receipt-edit-container .summary-card {
    background: var(--grad);
    color: #fff;
    border-radius: var(--radius-md);
    padding: 22px;
    box-shadow: 0 16px 34px rgba(102,126,234,.35);
}
.receipt-edit-container .summary-card h5 { font-weight: 700; display: flex; align-items: center; gap: .45rem; }
.receipt-edit-container .summary-card .d-flex { font-size: .95rem; }
.receipt-edit-container .summary-card hr { border-color: rgba(255,255,255,.4); opacity: 1; }
.receipt-edit-container .summary-card #finalTotal,
.receipt-edit-container .summary-card strong { font-size: 1.18rem; }

.receipt-edit-container .floating-summary .card {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    background: var(--surface);
}
.receipt-edit-container .floating-summary .card-header {
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    color: var(--text);
}
.receipt-edit-container .floating-summary .card-header i { color: var(--accent); }

/* ---------- وێنە ---------- */
.receipt-edit-container .image-preview {
    max-width: 220px;
    max-height: 220px;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border);
    cursor: pointer;
    transition: transform var(--ease);
}
.receipt-edit-container .image-preview:hover { transform: scale(1.04); }

/* ---------- autocomplete ---------- */
.receipt-edit-container .autocomplete-container { position: relative; }
.receipt-edit-container .autocomplete-suggestions {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    max-height: 260px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: var(--shadow-lg);
    padding: 6px;
}
.receipt-edit-container .autocomplete-suggestion {
    padding: 10px 12px;
    cursor: pointer;
    border-radius: 9px;
    transition: background var(--ease);
}
.receipt-edit-container .autocomplete-suggestion:hover,
.receipt-edit-container .autocomplete-suggestion.active { background: var(--accent-soft); }

/* ---------- وێنەی کاڵا لە ڕیزەکاندا ---------- */
.receipt-edit-container .product-image-cell {
    --img-size: 78px;
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 0 0 16px;
    padding: 12px 14px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}
.receipt-edit-container .product-image-thumb {
    position: relative;
    width: var(--img-size);
    height: var(--img-size);
    flex: 0 0 auto;
    border-radius: 13px;
    border: 1.5px dashed var(--border-strong);
    background: var(--surface);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    cursor: pointer;
    transition: border-color var(--ease), box-shadow var(--ease), transform var(--ease);
}
.receipt-edit-container .product-image-thumb:hover {
    border-color: var(--accent);
    box-shadow: 0 0 0 4px var(--accent-ring);
    transform: translateY(-1px);
}
.receipt-edit-container .product-image-cell[data-mode="view"] .product-image-thumb {
    cursor: pointer;
    border-style: solid;
    border-color: var(--border);
}
.receipt-edit-container .product-image-cell[data-mode="view"] .product-image-thumb::after {
    content: "گۆڕین";
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(17,24,39,.55);
    color: #fff;
    font-size: .72rem;
    font-weight: 700;
    opacity: 0;
    transition: opacity var(--ease);
    pointer-events: none;
}
.receipt-edit-container .product-image-cell[data-mode="view"] .product-image-thumb:hover::after {
    opacity: 1;
}
.receipt-edit-container .product-image-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: none;
}
.receipt-edit-container .product-image-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    color: var(--muted);
    font-size: .66rem;
    font-weight: 600;
    text-align: center;
    padding: 4px;
    line-height: 1.25;
}
.receipt-edit-container .product-image-placeholder i {
    font-size: 1.3rem;
    color: var(--accent);
}
.receipt-edit-container .product-image-remove {
    position: absolute;
    top: 4px;
    inset-inline-end: 4px;
    width: 21px;
    height: 21px;
    border: 2px solid #fff;
    border-radius: 50%;
    background: #dc3545;
    color: #fff;
    font-size: .6rem;
    display: none;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    box-shadow: var(--shadow-sm);
}
.receipt-edit-container .product-image-remove:hover { background: #b02a37; }
.receipt-edit-container .product-image-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.receipt-edit-container .product-image-info .pi-title {
    font-weight: 700;
    font-size: .85rem;
    color: #344054;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.receipt-edit-container .product-image-info .pi-title i { color: var(--accent); }
.receipt-edit-container .product-image-info .pi-hint {
    font-size: .74rem;
    color: var(--muted);
}
.receipt-edit-container .product-image-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: .72rem;
    font-weight: 700;
    color: #0a7d33;
    background: rgba(16,185,129,.13);
    padding: 3px 10px;
    border-radius: 999px;
    width: max-content;
}
html[data-bs-theme='dark'] .receipt-edit-container .product-image-info .pi-title { color: #c3ccda; }
html[data-bs-theme='dark'] .receipt-edit-container .product-image-badge {
    color: #34d399;
    background: rgba(16,185,129,.18);
}

/* ---------- یوتیلیتییەکان ---------- */
.loading { opacity: 0.7; pointer-events: none; }
.spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 1s ease-in-out infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.alert-sm {
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
}
.btn-link.text-warning:hover { text-decoration: underline !important; }

/* ---------- مۆداڵی وێنە ---------- */
#imageModal .modal-content { border-radius: 16px; overflow: hidden; }

/* ---------- ڕێسپۆنسیڤ ---------- */
@media (max-width: 991.98px) {
    .receipt-edit-container .floating-summary { position: static; margin-top: 8px; }
}
@media (max-width: 768px) {
    .receipt-edit-container { padding: 14px 0 40px; }
    .receipt-edit-container .receipt-card { border-radius: var(--radius-md); }
    .receipt-edit-container .item-row { padding: 16px; }
    .receipt-edit-container .receipt-header { padding: 18px; }
}

/* ============================================================
   دۆخی تاریک
   ============================================================ */
html[data-bs-theme='dark'] .receipt-edit-container {
    --page-bg: #0b1220;
    --surface: #111827;
    --surface-2: #0f1a2b;
    --surface-3: #16213a;
    --border: #25324a;
    --border-strong: #324158;
    --text: #e5e7eb;
    --muted: #94a3b8;
    --accent-soft: rgba(102,126,234,.20);
    --shadow-sm: 0 1px 2px rgba(0,0,0,.4);
    --shadow-md: 0 8px 22px rgba(0,0,0,.5);
    --shadow-lg: 0 22px 48px rgba(0,0,0,.6);
    color: var(--text);
}
html[data-bs-theme='dark'] .receipt-edit-container .form-label { color: #c3ccda; }
html[data-bs-theme='dark'] .receipt-edit-container .item-row .form-label { color: #9fb0c7; }
html[data-bs-theme='dark'] .receipt-edit-container .text-muted,
html[data-bs-theme='dark'] .receipt-edit-container .form-text { color: var(--muted) !important; }
html[data-bs-theme='dark'] .receipt-edit-container .form-control,
html[data-bs-theme='dark'] .receipt-edit-container .form-select {
    background: var(--surface-2);
    color: var(--text);
    border-color: var(--border-strong);
}
html[data-bs-theme='dark'] .receipt-edit-container .form-control::placeholder { color: #64748b; }
html[data-bs-theme='dark'] .receipt-edit-container .item-row .bg-light,
html[data-bs-theme='dark'] .receipt-edit-container .avg-buy-after-bonus-discount,
html[data-bs-theme='dark'] .receipt-edit-container .sheet-buy-price {
    background: var(--surface-3) !important;
    color: #c3ccda;
}
</style>

<div class="receipt-edit-container">
    <div class="container">
        <div class="row">
            <div class="col-lg-8">
                <div class="receipt-card">
                    <div class="receipt-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bi bi-receipt-cutoff"></i>
                                    دەستکاری وەسڵی کڕدراو
                                </h4>
                                <p class="mb-0 opacity-75">وەسڵ ژمارە: <?php echo htmlspecialchars($receipt['id']); ?></p>
                            </div>
                            <div class="text-end">
                                <a href="<?php echo url("user/purchases/view.php?id=$receiptId"); ?>" class="btn btn-light btn-sm me-2">
                                    <i class="bi bi-eye"></i> بینین
                                </a>
                                <a href="<?php echo url('user/purchases/index.php'); ?>" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-arrow-left"></i> گەڕانەوە
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="p-4">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <h6><i class="bi bi-exclamation-triangle"></i> هەڵەکان:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="editReceiptForm">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                            <input type="hidden" name="purchase_items_json" id="purchase_items_json" value="">
                            
                            <!-- زانیاری سەرەکی -->
                            <div class="form-section">
                            <h6 class="section-title"><i class="bi bi-info-circle"></i> زانیاری وەسڵ</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="company_id" class="form-label">
                                            <i class="bi bi-building"></i>
                                            کۆمپانیا <span class="text-danger">*</span>
                                        </label>
                                        <select name="company_id" id="company_id" class="form-select" required>
                                            <option value="">کۆمپانیا هەڵبژێرە</option>
                                            <?php foreach ($companies as $company): ?>
                                                <option value="<?php echo $company['id']; ?>" 
                                                    <?php echo $company['id'] == $receipt['company_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($company['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                      </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="receipt_number" class="form-label">
                                            <i class="bi bi-hash"></i>
                                            ژمارەی وەسڵ
                                        </label>
                                        <input type="text" class="form-control" name="receipt_number" id="receipt_number" 
                                               value="<?php echo htmlspecialchars($receipt['receipt_number']); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="payment_type" class="form-label">
                                            <i class="bi bi-credit-card"></i>
                                            جۆری پارەدان
                                        </label>
                                        <select name="payment_type" id="payment_type" class="form-select">
                                            <option value="cash" <?php echo $receipt['payment_type'] == 'cash' ? 'selected' : ''; ?>>کاش</option>
                                            <option value="debt" <?php echo $receipt['payment_type'] == 'debt' ? 'selected' : ''; ?>>قەرز</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="receipt_currency" class="form-label">
                                            <i class="bi bi-currency-exchange"></i>
                                            دراوی وەسڵ <span class="text-danger">*</span>
                                        </label>
                                        <?php $curVal = (($receipt['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD'; ?>
                                        <select name="currency" id="receipt_currency" class="form-select">
                                            <option value="IQD" <?php echo $curVal === 'USD' ? '' : 'selected'; ?>>دینار (IQD)</option>
                                            <option value="USD" <?php echo $curVal === 'USD' ? 'selected' : ''; ?>>دۆلار (USD)</option>
                                        </select>
                                        <small class="text-muted">هەموو نرخەکان و قەرزی ئەم وەسڵە بەم دراوە دەبن</small>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="receipt_date" class="form-label">
                                            <i class="bi bi-calendar"></i>
                                            بەروار <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" class="form-control" name="receipt_date" id="receipt_date"
                                               value="<?php echo $receipt['receipt_date']; ?>" required>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="wallet_id" class="form-label">
                                            <i class="bi bi-wallet2"></i>
                                            قاسە
                                        </label>
                                        <select name="wallet_id" id="wallet_id" class="form-select">
                                            <option value="">قاسە هەڵبژێرە</option>
                                            <?php foreach ($wallets as $wallet): ?>
                                                <option value="<?php echo (int)$wallet['id']; ?>" <?php echo ((int)($receipt['wallet_id'] ?? 0) === (int)$wallet['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string)$wallet['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="status" class="form-label">
                                            <i class="bi bi-flag"></i>
                                            دۆخ
                                        </label>
                                        <select name="status" id="status" class="form-select">
                                            <option value="active" <?php echo $receipt['status'] == 'active' ? 'selected' : ''; ?>>چالاک</option>
                                            <option value="completed" <?php echo $receipt['status'] == 'completed' ? 'selected' : ''; ?>>تەواو</option>
                                            <option value="cancelled" <?php echo $receipt['status'] == 'cancelled' ? 'selected' : ''; ?>>هەڵوەشاندراوە</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- وێنەی وەسڵ -->
                            <div class="mb-3">
                                <label for="receipt_image" class="form-label">
                                    <i class="bi bi-image"></i>
                                    وێنەی وەسڵ
                                </label>
                                <input type="file" class="form-control" name="receipt_image" id="receipt_image" 
                                       accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" 
                                       onchange="previewImageEdit(this)">
                                
                                <div class="form-text">
                                    <i class="bi bi-info-circle"></i>
                                    سنووری قەبارە: 5MB | جۆرەکانی پشتگیریکراو: JPG, PNG, GIF, WEBP
                                </div>
                                <div id="file-size-error-edit" class="text-danger mt-2" style="display: none;">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <span id="file-size-message-edit"></span>
                                </div>
                                
                                <?php if (!empty($receipt['receipt_image'])): ?>
                                    <div class="mt-2">
                                        <img src="<?php echo htmlspecialchars($receipt['receipt_image']); ?>" 
                                             alt="وێنەی وەسڵ" class="image-preview" 
                                             onclick="showImageModal(this.src)">
                                    </div>
                                <?php endif; ?>
                                
                                <div id="image_preview_edit" style="display: none;" class="mt-2">
                                    <img id="preview_img_edit" src="" alt="وێنەی نوێ" class="image-preview"
                                         onclick="showImageModal(this.src)">
                                </div>
                            </div>
                            </div>

                            <!-- کاڵاکان -->
                            <div class="items-container">
                                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                    <h5 class="mb-0">
                                        <i class="bi bi-box"></i>
                                        کاڵاکان <span class="text-danger">*</span>
                                    </h5>
                                    <a href="<?php echo url('user/products/add.php'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-box-seam"></i>
                                        کاڵای نوێ بۆ سیستەم
                                    </a>
                                </div>

                                <div id="itemsContainer">
                                    <?php if (!empty($receiptItems)): ?>
                                        <?php foreach ($receiptItems as $index => $item): ?>
                                            <?php if ($isPharmacyMode): ?>
                                            <div class="item-row pharmacy-item-row" data-index="<?php echo $index; ?>" data-pharmacy="1">
                                                <input type="hidden" name="unit_id[]" value="0">
                                                <input type="hidden" name="conversion_rate[]" class="conversion-rate" value="1">
                                                <?php renderPurchaseItemImageCell('img_' . bin2hex(random_bytes(6)), $productImageUrlById[(int)$item['product_id']] ?? null); ?>
                                                <div class="row g-2 align-items-end">
                                                    <div class="col-md-5">
                                                        <label class="form-label product-name-label"><span class="item-row-number"><?php echo $index + 1; ?>-</span> ناوی کاڵا <span class="text-danger">*</span></label>
                                                        <div class="autocomplete-container">
                                                            <input type="hidden" name="product_id[]" value="<?php echo $item['product_id']; ?>">
                                                            <input type="text" class="form-control product-name" name="product_name[]" value="<?php echo htmlspecialchars($item['product_name']); ?>" placeholder="ناوی کاڵا" required autocomplete="off">
                                                            <div class="autocomplete-suggestions"></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">بارکۆد</label>
                                                        <div class="autocomplete-container">
                                                            <div class="input-group">
                                                                <input type="text" class="form-control item-barcode" name="barcode[]" value="<?php echo htmlspecialchars($item['barcode'] ?? ''); ?>" placeholder="بارکۆد" autocomplete="off">
                                                                <button type="button" class="btn btn-outline-info print-barcode-btn" title="پرێنتی بارکۆد" tabindex="-1"><i class="bi bi-printer"></i></button>
                                                            </div>
                                                            <div class="autocomplete-suggestions"></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">کەتەلۆگ</label>
                                                        <select class="form-select item-category" name="category_id[]">
                                                            <option value="">--</option>
                                                            <?php foreach ($categories as $cat): ?>
                                                            <option value="<?php echo (int)$cat['id']; ?>" <?php echo ((int)($item['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($cat['name']); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="row g-2 align-items-end mt-2">
                                                    <div class="col-md-2">
                                                        <label class="form-label">بڕی پاکەت <span class="text-danger">*</span></label>
                                                        <input type="number" class="form-control quantity" name="quantity[]" value="<?php echo (int)$item['quantity']; ?>" min="1" step="1" required>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">پاکەت چەند شیتە؟</label>
                                                        <input type="number" class="form-control sheets-per-packet" name="sheets_per_packet[]" value="<?php echo (int)($item['sheets_per_packet'] ?? 0); ?>" min="0" step="1">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">بۆنسی پاکەت</label>
                                                        <input type="number" class="form-control packet-bonus" name="packet_bonus[]" value="<?php echo (float)($item['packet_bonus'] ?? 0); ?>" min="0" step="0.5">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">داشکاندن (%)</label>
                                                        <input type="number" class="form-control item-discount-percent" name="item_discount_percent[]" value="<?php echo formatEditDecimal($receiptPharmacyDiscountPercent, 2); ?>" min="0" max="100" step="any">
                                                    </div>
                                                </div>

                                                <div class="row g-2 align-items-end mt-2 pharmacy-sheet-block" style="display: flex;">
                                                    <div class="col-md-2">
                                                        <label class="form-label">نرخی کڕین <span class="text-danger">*</span></label>
                                                        <input type="number" class="form-control buy-price step-1000" name="buy_price[]" value="<?php echo number_format($item['buy_price'], 0, '.', ''); ?>" step="any" min="0" required>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">نرخی تێکڕای کڕینی پاکەت</label>
                                                        <input type="text" class="form-control bg-light avg-buy-after-bonus-discount" value="<?php echo htmlspecialchars($item['display_avg_packet_buy']); ?>" readonly tabindex="-1">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">نرخی تێکڕای کڕینی شیت</label>
                                                        <input type="text" class="form-control bg-light sheet-buy-price" name="sheet_buy_price[]" value="<?php echo htmlspecialchars($item['display_avg_sheet_buy']); ?>" readonly tabindex="-1">
                                                    </div>
                                                </div>

                                                <div class="row g-2 align-items-end mt-2">
                                                    <div class="col-md-2">
                                                        <label class="form-label">نرخی فرۆشتنی پاکەت</label>
                                                        <input type="number" class="form-control sell-price step-1000" name="sell_price[]" value="<?php echo number_format($item['sell_price'], 0, '.', ''); ?>" step="any" min="0">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">نرخی جوملەی پاکەت</label>
                                                        <input type="number" class="form-control step-1000" name="wholesale_price[]" value="<?php echo number_format($item['wholesale_price'], 0, '.', ''); ?>" step="any" min="0">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">نرخی تایبەت پاکەت</label>
                                                        <input type="number" class="form-control step-1000" name="special_price[]" value="<?php echo number_format($item['special_price'], 0, '.', ''); ?>" step="any" min="0">
                                                    </div>
                                                </div>

                                                <div class="row g-2 align-items-end mt-2">
                                                    <div class="col-md-2">
                                                        <label class="form-label">نرخی فرۆشتنی شیت</label>
                                                        <input type="number" class="form-control sheet-sell-price step-1000" name="sheet_sell_price[]" value="<?php echo htmlspecialchars($item['display_sheet_sell_price']); ?>" step="any" min="0">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">بەرواری بەسەرچوون</label>
                                                        <input type="date" class="form-control expiry-date" name="expiry_date[]" value="<?php echo $item['expiry_date']; ?>">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label d-block">&nbsp;</label>
                                                        <button type="button" class="delete-item btn btn-danger btn-sm" onclick="removeItem(this)" title="سڕینەوە">
                                                            <i class="bi bi-trash"></i> سڕینەوە
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <div class="item-row" data-index="<?php echo $index; ?>">
                                                <?php renderPurchaseItemImageCell('img_' . bin2hex(random_bytes(6)), $productImageUrlById[(int)$item['product_id']] ?? null); ?>
                                                <div class="row align-items-end">
                                                    <div class="col-md-3">
                                                        <label class="form-label product-name-label"><span class="item-row-number"><?php echo $index + 1; ?>-</span> ناوی کاڵا <span class="text-danger">*</span></label>
                                                        <div class="autocomplete-container">
                                                            <input type="hidden" name="product_id[]" value="<?php echo $item['product_id']; ?>">
                                                            <input type="text" class="form-control product-name" name="product_name[]" 
                                                                   value="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                                                   placeholder="ناوی کاڵا" required autocomplete="off">
                                                            <div class="autocomplete-suggestions"></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">بارکۆد</label>
                                                        <div class="autocomplete-container">
                                                            <div class="input-group">
                                                                <input type="text" class="form-control item-barcode" name="barcode[]" value="<?php echo htmlspecialchars($item['barcode'] ?? ''); ?>" placeholder="بارکۆد" autocomplete="off">
                                                                <button type="button" class="btn btn-outline-info print-barcode-btn" title="پرێنتی بارکۆد" tabindex="-1"><i class="bi bi-printer"></i></button>
                                                            </div>
                                                            <div class="autocomplete-suggestions"></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">بڕ <span class="text-danger">*</span></label>
                                                        <input type="number" class="form-control quantity" name="quantity[]" 
                                                               value="<?php echo $item['quantity']; ?>" min="0.001" step="0.001" required>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">یەکە</label>
                                                        <select class="form-control unit-select" name="unit_id[]" onchange="updateUnitPrices(this)">
                                                            <option value="0">یەکەی بنەڕەتی</option>
                                                        </select>
                                                        <input type="hidden" name="conversion_rate[]" class="conversion-rate" value="<?php echo isset($item['conversion_rate']) ? $item['conversion_rate'] : 1; ?>">
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">نرخی کڕین <span class="text-danger">*</span></label>
                                                        <input type="number" class="form-control buy-price" name="buy_price[]" 
                                                               value="<?php echo number_format($item['buy_price'], 0, '.', ''); ?>" step="0.001" min="0" required>
                                                    </div>
                                                </div>
                                                
                                                <div class="row align-items-end mt-3">
                                                    <div class="col-md-3">
                                                        <label class="form-label">نرخی فرۆشتن</label>
                                                        <input type="number" class="form-control sell-price" name="sell_price[]" 
                                                               value="<?php echo number_format($item['sell_price'], 0, '.', ''); ?>" step="0.001" min="0">
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">بەرواری بەسەرچوون</label>
                                                        <input type="date" class="form-control expiry-date" name="expiry_date[]" 
                                                               value="<?php echo $item['expiry_date']; ?>">
                                                    </div>
                                                    
                                                    <div class="col-md-6">
                                                        <button type="button" class="delete-item btn btn-danger btn-sm" onclick="removeItem(this)" title="سڕینەوە">
                                                            <i class="bi bi-trash"></i> سڕینەوەی کاڵا
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div class="row mt-2">
                                                    <div class="col-md-6">
                                                        <label class="form-label">نرخی جوملە</label>
                                                        <input type="number" class="form-control" name="wholesale_price[]" 
                                                               value="<?php echo number_format($item['wholesale_price'], 0, '.', ''); ?>" step="0.001" min="0">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">نرخی تایبەت</label>
                                                        <input type="number" class="form-control" name="special_price[]" 
                                                               value="<?php echo number_format($item['special_price'], 0, '.', ''); ?>" step="0.001" min="0">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php if ($isPharmacyMode): ?>
                                        <div class="item-row pharmacy-item-row" data-index="0" data-pharmacy="1">
                                            <input type="hidden" name="unit_id[]" value="0">
                                            <input type="hidden" name="conversion_rate[]" class="conversion-rate" value="1">
                                            <?php renderPurchaseItemImageCell('img_' . bin2hex(random_bytes(6))); ?>
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-5">
                                                    <label class="form-label product-name-label"><span class="item-row-number">1-</span> ناوی کاڵا <span class="text-danger">*</span></label>
                                                    <div class="autocomplete-container">
                                                        <input type="hidden" name="product_id[]" value="0">
                                                        <input type="text" class="form-control product-name" name="product_name[]" placeholder="ناوی کاڵا" required autocomplete="off">
                                                        <div class="autocomplete-suggestions"></div>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">بارکۆد</label>
                                                    <div class="autocomplete-container">
                                                        <div class="input-group">
                                                            <input type="text" class="form-control item-barcode" name="barcode[]" placeholder="بارکۆد" autocomplete="off">
                                                            <button type="button" class="btn btn-outline-info print-barcode-btn" title="پرێنتی بارکۆد" tabindex="-1"><i class="bi bi-printer"></i></button>
                                                        </div>
                                                        <div class="autocomplete-suggestions"></div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">کەتەلۆگ</label>
                                                    <select class="form-select item-category" name="category_id[]">
                                                        <option value="">--</option>
                                                        <?php foreach ($categories as $cat): ?>
                                                        <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row g-2 align-items-end mt-2">
                                                <div class="col-md-2">
                                                    <label class="form-label">بڕی پاکەت <span class="text-danger">*</span></label>
                                                    <input type="number" class="form-control quantity" name="quantity[]" value="1" min="1" step="1" required>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">پاکەت چەند شیتە؟</label>
                                                    <input type="number" class="form-control sheets-per-packet" name="sheets_per_packet[]" value="0" min="0" step="1">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">بۆنسی پاکەت</label>
                                                    <input type="number" class="form-control packet-bonus" name="packet_bonus[]" value="0" min="0" step="0.5">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">داشکاندن (%)</label>
                                                    <input type="number" class="form-control item-discount-percent" name="item_discount_percent[]" value="0" min="0" max="100" step="any">
                                                </div>
                                            </div>
                                            <div class="row g-2 align-items-end mt-2 pharmacy-sheet-block" style="display:flex;">
                                                <div class="col-md-2">
                                                    <label class="form-label">نرخی کڕین <span class="text-danger">*</span></label>
                                                    <input type="number" class="form-control buy-price step-1000" name="buy_price[]" step="any" min="0" required>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">نرخی تێکڕای کڕینی پاکەت</label>
                                                    <input type="text" class="form-control bg-light avg-buy-after-bonus-discount" readonly tabindex="-1">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">نرخی تێکڕای کڕینی شیت</label>
                                                    <input type="text" class="form-control bg-light sheet-buy-price" name="sheet_buy_price[]" readonly tabindex="-1">
                                                </div>
                                            </div>
                                            <div class="row g-2 align-items-end mt-2">
                                                <div class="col-md-2">
                                                    <label class="form-label">نرخی فرۆشتنی پاکەت</label>
                                                    <input type="number" class="form-control sell-price step-1000" name="sell_price[]" step="any" min="0">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">نرخی جوملەی پاکەت</label>
                                                    <input type="number" class="form-control step-1000" name="wholesale_price[]" step="any" min="0">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">نرخی تایبەت پاکەت</label>
                                                    <input type="number" class="form-control step-1000" name="special_price[]" step="any" min="0">
                                                </div>
                                            </div>
                                            <div class="row g-2 align-items-end mt-2">
                                                <div class="col-md-2">
                                                    <label class="form-label">نرخی فرۆشتنی شیت</label>
                                                    <input type="number" class="form-control sheet-sell-price step-1000" name="sheet_sell_price[]" step="any" min="0">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">بەرواری بەسەرچوون</label>
                                                    <input type="date" class="form-control expiry-date" name="expiry_date[]">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label d-block">&nbsp;</label>
                                                    <button type="button" class="delete-item btn btn-danger btn-sm" onclick="removeItem(this)" title="سڕینەوە">
                                                        <i class="bi bi-trash"></i> سڕینەوە
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <div class="item-row" data-index="0">
                                            <?php renderPurchaseItemImageCell('img_' . bin2hex(random_bytes(6))); ?>
                                            <div class="row align-items-end">
                                                <div class="col-md-3">
                                                    <label class="form-label product-name-label"><span class="item-row-number">1-</span> ناوی کاڵا <span class="text-danger">*</span></label>
                                                    <div class="autocomplete-container">
                                                        <input type="hidden" name="product_id[]" value="0">
                                                        <input type="text" class="form-control product-name" name="product_name[]" 
                                                               placeholder="ناوی کاڵا" required autocomplete="off">
                                                        <div class="autocomplete-suggestions"></div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-3">
                                                    <label class="form-label">بارکۆد</label>
                                                    <div class="autocomplete-container">
                                                        <div class="input-group">
                                                            <input type="text" class="form-control item-barcode" name="barcode[]" placeholder="بارکۆد" autocomplete="off">
                                                            <button type="button" class="btn btn-outline-info print-barcode-btn" title="پرێنتی بارکۆد" tabindex="-1"><i class="bi bi-printer"></i></button>
                                                        </div>
                                                        <div class="autocomplete-suggestions"></div>
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-3">
                                                    <label class="form-label">بڕ <span class="text-danger">*</span></label>
                                                    <input type="number" class="form-control quantity" name="quantity[]" value="1" min="0.001" step="0.001" required>
                                                </div>
                                                
                                                <div class="col-md-3">
                                                    <label class="form-label">یەکە</label>
                                                    <select class="form-control unit-select" name="unit_id[]" onchange="updateUnitPrices(this)">
                                                        <option value="0">یەکەی بنەڕەتی</option>
                                                    </select>
                                                    <input type="hidden" name="conversion_rate[]" class="conversion-rate" value="1">
                                                </div>
                                                
                                                <div class="col-md-3">
                                                    <label class="form-label">نرخی کڕین <span class="text-danger">*</span></label>
                                                    <input type="number" class="form-control buy-price" name="buy_price[]" step="0.001" min="0" required>
                                                </div>
                                            </div>
                                            
                                            <div class="row align-items-end mt-3">
                                                <div class="col-md-3">
                                                    <label class="form-label">نرخی فرۆشتن</label>
                                                    <input type="number" class="form-control sell-price" name="sell_price[]" step="0.001" min="0">
                                                </div>
                                                
                                                <div class="col-md-3">
                                                    <label class="form-label">بەرواری بەسەرچوون</label>
                                                    <input type="date" class="form-control expiry-date" name="expiry_date[]">
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <button type="button" class="delete-item btn btn-danger btn-sm" onclick="removeItem(this)" title="سڕینەوە">
                                                        <i class="bi bi-trash"></i> سڕینەوەی کاڵا
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="row mt-2">
                                                <div class="col-md-6">
                                                    <label class="form-label">نرخی جوملە</label>
                                                    <input type="number" class="form-control" name="wholesale_price[]" step="0.001" min="0">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">نرخی تایبەت</label>
                                                    <input type="number" class="form-control" name="special_price[]" step="0.001" min="0">
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- دوگمەی زیادکردنی کاڵای نوێ -->
                                <div class="mt-3 text-center">
                                    <button type="button" class="btn btn-success" onclick="addNewItem()">
                                        <i class="bi bi-plus-circle"></i>
                                        کاڵای نوێ زیاد بکە
                                    </button>
                                </div>

                            </div>

                            <!-- داشکاندن و کرێی زیادە -->
                            <div class="form-section">
                            <h6 class="section-title"><i class="bi bi-percent"></i> داشکاندن، کرێ و تێبینی</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="discount_type" class="form-label">
                                            <i class="bi bi-percent"></i>
                                            جۆری داشکاندن
                                        </label>
                                        <select class="form-select" name="discount_type" id="discount_type">
                                            <option value="amount">بڕی دیناری</option>
                                            <option value="percentage">ڕێژە (%)</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="discount_value" class="form-label">
                                            <i class="bi bi-dash-circle"></i>
                                            بڕی داشکاندن
                                        </label>
                                        <input type="number" class="form-control" name="discount_value" id="discount_value" 
                                               value="<?php echo number_format($receipt['discount_amount'], 0, '.', ''); ?>" step="0.001" min="0">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label for="additional_charges" class="form-label">
                                            <i class="bi bi-plus-circle"></i>
                                            کرێی زیادە (گواستنەوە، بار، هتد)
                                        </label>
                                        <input type="number" class="form-control" name="additional_charges" id="additional_charges" 
                                               value="<?php echo number_format($receipt['additional_charges'], 0, '.', ''); ?>" step="0.001" min="0">
                                    </div>
                                </div>
                            </div>

                            <!-- تێبینی -->
                            <div class="mb-3">
                                <label for="notes" class="form-label">
                                    <i class="bi bi-journal-text"></i>
                                    تێبینی
                                </label>
                                <textarea class="form-control" name="notes" id="notes" rows="3"
                                          placeholder="تێبینی یان وەسفی زیادە"><?php echo htmlspecialchars($receipt['notes']); ?></textarea>
                            </div>
                            </div>

                            <!-- دوگمەکان -->
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button type="submit" class="btn btn-primary me-2" id="saveBtn">
                                        <span class="spinner d-none"></span>
                                        <i class="bi bi-check-circle"></i>
                                        پاشکەوتکردن
                                    </button>
                                    <button type="submit" name="save_and_continue" class="btn btn-outline-primary me-2">
                                        <i class="bi bi-arrow-repeat"></i>
                                        پاشکەوت & بەردەوامبوون
                                    </button>
                                    <button type="submit" name="save_and_view" class="btn btn-info">
                                        <i class="bi bi-eye"></i>
                                        پاشکەوت & بینین
                                    </button>
                                </div>
                                
                                <div>
                                    <a href="<?php echo url('user/purchases/index.php'); ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle"></i>
                                        هەڵوەشاندنەوە
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- پانێلی ئامار -->
            <div class="col-lg-4">
                <div class="floating-summary">
                    <div class="summary-card">
                        <h5 class="mb-3">
                            <i class="bi bi-calculator"></i>
                            کورتەی حیساب
                        </h5>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>کۆی کاڵاکان:</span>
                            <span id="itemsCount"><?php echo count($receiptItems); ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>کۆی گشتی:</span>
                            <span id="subtotal"><?php echo number_format($receipt['total_amount'], 0, '.', ','); ?> IQD</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>داشکاندن:</span>
                            <span id="discountDisplay">-<?php echo number_format($receipt['discount_amount'], 0, '.', ','); ?> IQD</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>کرێی زیادە:</span>
                            <span id="chargesDisplay">+<?php echo number_format($receipt['additional_charges'], 0, '.', ','); ?> IQD</span>
                        </div>
                        
                        <hr class="bg-white">
                        
                        <div class="d-flex justify-content-between">
                            <strong>کۆی کۆتایی:</strong>
                            <strong id="finalTotal"><?php echo number_format($receipt['final_amount'], 0, '.', ','); ?> IQD</strong>
                        </div>
                        
                        <div class="mt-3">
                            <small class="opacity-75">
                                <i class="bi bi-info-circle"></i>
                                حیساب بەشێوەی ئۆتۆماتیکی نوێ دەکرێتەوە
                            </small>
                        </div>
                    </div>

                    <!-- کارت زانیاری کۆمپانیا -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-building"></i>
                                زانیاری کۆمپانیا
                            </h6>
                        </div>
                        <div class="card-body" id="companyInfo">
                            <p class="mb-1"><strong>ناو:</strong> <?php echo htmlspecialchars($receipt['company_name']); ?></p>
                            <?php if (!empty($receipt['company_address'])): ?>
                                <p class="mb-1"><strong>ناونیشان:</strong> <?php echo htmlspecialchars($receipt['company_address']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($receipt['company_phone'])): ?>
                                <p class="mb-0"><strong>تەلەفۆن:</strong> <?php echo htmlspecialchars($receipt['company_phone']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- میژووی وەسڵ -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-clock-history"></i>
                                میژووی وەسڵ
                            </h6>
                        </div>
                        <div class="card-body">
                            <small class="text-muted">
                                <strong>دروستکراوە:</strong> <?php echo date('d/m/Y H:i', strtotime($receipt['created_at'])); ?><br>
                                <?php if ($receipt['updated_at'] != $receipt['created_at']): ?>
                                    <strong>دوایین نوێکردنەوە:</strong> <?php echo date('d/m/Y H:i', strtotime($receipt['updated_at'])); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal بۆ نیشاندانی وێنە -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">وێنەی وەسڵ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" alt="وێنەی وەسڵ" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<script>
// داتای کاڵاکان بۆ autocomplete
const productsArray = <?php echo json_encode($products); ?>;
const companies = <?php echo json_encode($companies); ?>;
const receiptItemsData = <?php echo json_encode($receiptItems); ?>;
const userUnits = <?php echo json_encode($userUnits); ?>;
const isPharmacyMode = <?php echo $isPharmacyMode ? 'true' : 'false'; ?>;
const categories = <?php echo json_encode($categories); ?>;
const productBarcodeApiUrl = <?php echo json_encode(url('api/products.php'), JSON_UNESCAPED_UNICODE); ?>;
const barcodePrintUrl = <?php echo json_encode(url('user/products/barcode/index.php'), JSON_UNESCAPED_UNICODE); ?>;

// پرێنتی بارکۆدی هەر ڕیزێک (event delegation)
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.print-barcode-btn');
    if (!btn) return;
    const row = btn.closest('.item-row');
    if (!row) return;
    const barcodeInput = row.querySelector('.item-barcode');
    const code = barcodeInput ? (barcodeInput.value || '').trim() : '';
    if (!code) {
        if (barcodeInput && typeof flashBarcodeInputError === 'function') flashBarcodeInputError(barcodeInput);
        alert('تکایە سەرەتا بارکۆد داخڵ بکە');
        return;
    }
    let printUrl = barcodePrintUrl + '?barcode=' + encodeURIComponent(code);
    const nameInput = row.querySelector('.product-name');
    if (nameInput && nameInput.value.trim()) {
        printUrl += '&name=' + encodeURIComponent(nameInput.value.trim());
    }
    const priceInput = row.querySelector('.sell-price');
    if (priceInput && priceInput.value.trim()) {
        printUrl += '&price=' + encodeURIComponent(priceInput.value.trim());
    }
    window.open(printUrl, '_blank');
});

// گۆڕینی products بۆ object لەگەڵ id وەک key بۆ گەڕانی خێراتر
const products = {};
productsArray.forEach(p => {
    products[p.id] = p;
});

let itemIndex = <?php echo count($receiptItems) > 0 ? max(array_keys($receiptItems)) + 1 : 1; ?>;

function formatEditDecimal(value, precision = 3) {
    if (value === '' || value === null || value === undefined) {
        return '';
    }

    const num = typeof value === 'number' ? value : parseFloat(value);
    if (!Number.isFinite(num)) {
        return '';
    }

    const fixed = num.toFixed(precision);
    const trimmed = fixed.replace(/\.?0+$/, '');

    return trimmed === '' || trimmed === '-0' ? '0' : trimmed;
}

function syncItemRowNumbers() {
    document.querySelectorAll('#itemsContainer .item-row').forEach((row, index) => {
        const rowNumber = row.querySelector('.product-name-label .item-row-number');
        if (rowNumber) {
            rowNumber.textContent = `${index + 1}-`;
        }
    });
}

// فانکشنی یەکەم بارکردن
document.addEventListener('DOMContentLoaded', function() {
    initializeForm();
});

function initializeForm() {
    // تێکەڵکردنی event listener ەکان
    bindEvents();
    
    // بارکردنی یەکەکان بۆ ئایتەمە هەبووەکان
    loadExistingItemsUnits();
    
    // حیسابکردنی ئەمجارە
    updateSummary();
    
    // Initialize خانەی وێنە بۆ ڕیزە هەبووەکان
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        initProductImageCell(row);
    });

    // Initialize autocomplete بۆ کاڵاکانی هەبوو
    document.querySelectorAll('.product-name').forEach(input => {
        initializeAutocomplete(input);
    });
    document.querySelectorAll('.item-barcode').forEach(input => {
        initializeBarcodeAutocomplete(input);
    });
    if (!isPharmacyMode) {
        document.querySelectorAll('.item-row').forEach(row => applyUserUnitsForNewProduct(row));
    } else {
        document.querySelectorAll('.pharmacy-item-row').forEach(row => {
            bindPharmacyRowEvents(row);
            updatePharmacyRowSheet(row);
        });
        lockExtraDiscountInputs();
    }

    syncItemRowNumbers();
}

function getDefaultUnitFromList() {
    const flagged = userUnits.find(u => String(u.is_default) === '1' || u.is_default === 1);
    if (flagged) return flagged;
    return userUnits.find(u => u.name === 'دانە') || userUnits[0] || null;
}

function applyUserUnitsForNewProduct(row) {
    const unitSelect = row.querySelector('.unit-select');
    const productIdInput = row.querySelector('[name="product_id[]"]');
    const conversionRateInput = row.querySelector('.conversion-rate');
    if (!unitSelect || !productIdInput) return;
    if ((productIdInput.value || '0') !== '0') return;

    unitSelect.innerHTML = '<option value="0">یەکەی بنەڕەتی</option>';
    userUnits.forEach(unit => {
        const option = document.createElement('option');
        option.value = unit.id;
        option.textContent = `${unit.name}${unit.symbol ? ' (' + unit.symbol + ')' : ''}`;
        option.dataset.conversionRate = 1;
        unitSelect.appendChild(option);
    });

    const defaultUnit = getDefaultUnitFromList();
    if (defaultUnit) {
        unitSelect.value = String(defaultUnit.id);
    }

    if (conversionRateInput) conversionRateInput.value = 1;
}

function loadExistingItemsUnits() {
    // بارکردنی یەکەکان بۆ هەر کاڵایەکی هەبوو
    const itemRows = document.querySelectorAll('.item-row');
    itemRows.forEach((row, index) => {
        const productId = row.querySelector('[name="product_id[]"]').value;
        const unitSelect = row.querySelector('.unit-select');
        const conversionRateInput = row.querySelector('.conversion-rate');
        
        if (productId && productId != '0' && products[productId]) {
            const product = products[productId];
            const savedUnitId = receiptItemsData[index] ? receiptItemsData[index].unit_id : 0;
            
            // پڕکردنەوەی یەکەکان
            unitSelect.innerHTML = '<option value="0">یەکەی بنەڕەتی</option>';
            
            if (product.units && product.units.length > 0) {
                product.units.forEach(unit => {
                    const option = document.createElement('option');
                    option.value = unit.unit_id;
                    option.textContent = `${unit.unit_name}${unit.unit_symbol ? ' (' + unit.unit_symbol + ')' : ''}`;
                    option.dataset.buyPrice = unit.buy_price;
                    option.dataset.sellPrice = unit.sell_price;
                    option.dataset.wholesalePrice = unit.wholesale_price;
                    option.dataset.specialPrice = unit.special_price;
                    option.dataset.conversionRate = unit.conversion_rate;
                    
                    if (unit.unit_id == savedUnitId) {
                        option.selected = true;
                    }
                    
                    unitSelect.appendChild(option);
                });
                
                // دانانی conversion_rate
                const selectedOption = unitSelect.options[unitSelect.selectedIndex];
                if (selectedOption && selectedOption.dataset.conversionRate) {
                    conversionRateInput.value = selectedOption.dataset.conversionRate;
                }
            }
        }
    });
}

function bindEvents() {
    document.addEventListener('keydown', function(e) {
        if (!e.target.matches('.step-1000')) return;
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        e.preventDefault();
        const input = e.target;
        let val = parseFloat(input.value) || 0;
        if (e.key === 'ArrowUp') val += 1000;
        else val = Math.max(0, val - 1000);
        input.value = val;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // تێکەڵکردنی event بۆ گۆڕانی کۆمپانیا
    document.getElementById('company_id').addEventListener('change', updateCompanyInfo);
    
    // تێکەڵکردنی events بۆ حیسابکردن
    document.addEventListener('input', function(e) {
        if (e.target.matches('.quantity, .buy-price, #discount_value, #additional_charges, .item-discount-percent')) {
            updateSummary();
        }
        if (isPharmacyMode && e.target.matches('.packet-bonus, .sheets-per-packet, .quantity, .buy-price, .sell-price')) {
            const row = e.target.closest('.pharmacy-item-row');
            if (row) updatePharmacyRowSheet(row);
            updateSummary();
        }
        if (isPharmacyMode && e.target.matches('.pharmacy-item-row .item-discount-percent')) {
            const firstDiscountInput = document.querySelector('.pharmacy-item-row .item-discount-percent');
            if (firstDiscountInput && e.target === firstDiscountInput) {
                let p = parseFloat(firstDiscountInput.value) || 0;
                if (p < 0) {
                    p = 0;
                    firstDiscountInput.value = 0;
                } else if (p > 100) {
                    p = 100;
                    firstDiscountInput.value = 100;
                }
                const rawValue = firstDiscountInput.value;
                document.querySelectorAll('.pharmacy-item-row .item-discount-percent').forEach((input, idx) => {
                    if (input !== firstDiscountInput) {
                        input.value = rawValue;
                    }
                    input.disabled = idx !== 0;
                });

                const discountValueEl = document.getElementById('discount_value');
                if (discountValueEl) {
                    discountValueEl.value = rawValue;
                }

                document.querySelectorAll('.pharmacy-item-row').forEach(updatePharmacyRowSheet);
            }
        }
        
        // نیشانکردنی نرخەکان وەک دەستکاری‌کراو کاتێک بەکارهێنەر دەستیان‌دەکات
        if (e.target.matches('.buy-price, .sell-price, [name="wholesale_price[]"], [name="special_price[]"]')) {
            e.target.dataset.modified = 'true';
        }
        
        // نیشانکردنی بەرواری بەسەرچوون وەک دەستکاری‌کراو
        if (e.target.matches('.expiry-date')) {
            e.target.dataset.modified = 'true';
        }
    });
    
    // تێکەڵکردنی event بۆ گۆڕانی جۆری داشکاندن
    document.getElementById('discount_type').addEventListener('change', updateSummary);

    // گۆڕینی دراوی وەسڵ: نوێکردنەوەی نیشانەی دراو لە کۆکاندا
    const editReceiptCurrencyEl = document.getElementById('receipt_currency');
    if (editReceiptCurrencyEl) {
        editReceiptCurrencyEl.addEventListener('change', updateSummary);
    }
    
    // Prevent form submission بۆ enter key لە autocomplete (بارکۆد لە initializeBarcodeAutocomplete مامەڵە دەکرێت)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.classList.contains('product-name')) {
            e.preventDefault();
        }
    });
    
    // Form submission
    document.getElementById('editReceiptForm').addEventListener('submit', function(e) {
        if (!validateForm()) {
            e.preventDefault();
            return false;
        }

        serializePurchaseItemsForSubmit(this);
        
        // Show loading
        const saveBtn = document.getElementById('saveBtn');
        const spinner = saveBtn.querySelector('.spinner');
        const icon = saveBtn.querySelector('.bi-check-circle');
        
        spinner.classList.remove('d-none');
        icon.classList.add('d-none');
        saveBtn.disabled = true;
        document.body.classList.add('loading');
    });
}

function getPurchaseRowFieldValue(row, selector) {
    const el = row.querySelector(selector);
    return el ? el.value : '';
}

function serializePurchaseItemsForSubmit(form) {
    const items = [];
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        items.push({
            product_id: getPurchaseRowFieldValue(row, '[name="product_id[]"]'),
            product_name: getPurchaseRowFieldValue(row, '[name="product_name[]"]'),
            barcode: getPurchaseRowFieldValue(row, '[name="barcode[]"]'),
            quantity: getPurchaseRowFieldValue(row, '[name="quantity[]"]'),
            unit_id: getPurchaseRowFieldValue(row, '[name="unit_id[]"]'),
            conversion_rate: getPurchaseRowFieldValue(row, '[name="conversion_rate[]"]'),
            buy_price: getPurchaseRowFieldValue(row, '[name="buy_price[]"]'),
            sell_price: getPurchaseRowFieldValue(row, '[name="sell_price[]"]'),
            wholesale_price: getPurchaseRowFieldValue(row, '[name="wholesale_price[]"]'),
            special_price: getPurchaseRowFieldValue(row, '[name="special_price[]"]'),
            expiry_date: getPurchaseRowFieldValue(row, '[name="expiry_date[]"]'),
            category_id: getPurchaseRowFieldValue(row, '[name="category_id[]"]'),
            packet_bonus: getPurchaseRowFieldValue(row, '[name="packet_bonus[]"]'),
            sheets_per_packet: getPurchaseRowFieldValue(row, '[name="sheets_per_packet[]"]'),
            sheet_buy_price: getPurchaseRowFieldValue(row, '[name="sheet_buy_price[]"]'),
            sheet_sell_price: getPurchaseRowFieldValue(row, '[name="sheet_sell_price[]"]'),
            item_discount_percent: getPurchaseRowFieldValue(row, '[name="item_discount_percent[]"]'),
            image_key: getPurchaseRowFieldValue(row, '[name="image_key[]"]')
        });
    });

    const jsonInput = document.getElementById('purchase_items_json');
    if (jsonInput) {
        jsonInput.value = JSON.stringify(items);
    }

    form.querySelectorAll('#itemsContainer [name$="[]"]').forEach(el => {
        el.disabled = true;
    });
}

function generateImageKey() {
    return 'img_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
}

function productImageCellHtml() {
    const key = generateImageKey();
    return `
        <div class="product-image-cell" data-mode="edit">
            <input type="hidden" name="image_key[]" value="${key}">
            <input type="file" class="product-image-input d-none" name="product_image[${key}]" accept="image/*">
            <div class="product-image-thumb">
                <img class="product-image-preview" alt="وێنەی کاڵا">
                <div class="product-image-placeholder"><i class="bi bi-image"></i><span>وێنە دابنێ</span></div>
                <button type="button" class="product-image-remove" title="لابردنی وێنە"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="product-image-info">
                <span class="pi-title"><i class="bi bi-card-image"></i> وێنەی کاڵا</span>
                <span class="pi-hint">کلیک بکە بۆ هەڵبژاردنی وێنە</span>
            </div>
        </div>
    `;
}

function initProductImageCell(row) {
    const cell = row.querySelector('.product-image-cell');
    if (!cell || cell.dataset.bound === '1') return;
    cell.dataset.bound = '1';

    const input = cell.querySelector('.product-image-input');
    const thumb = cell.querySelector('.product-image-thumb');
    const preview = cell.querySelector('.product-image-preview');
    const placeholder = cell.querySelector('.product-image-placeholder');
    const removeBtn = cell.querySelector('.product-image-remove');
    const hint = cell.querySelector('.pi-hint');

    // کلیک لەسەر تەمبنەیل هەمیشە هەڵبژاردن دەکاتەوە (بۆ داناندن یان گۆڕین)
    thumb.addEventListener('click', function() {
        input.click();
    });

    input.addEventListener('change', function() {
        const file = input.files && input.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) { input.value = ''; return; }
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
            removeBtn.style.display = 'flex';
            if (hint) hint.textContent = 'وێنەی نوێ هەڵبژێردرا';
        };
        reader.readAsDataURL(file);
    });

    // پاشگەزبوونەوە: ئەگەر وێنەی کۆن هەبوو بۆی دەگەڕێتەوە، ئەگەرنا دۆخی داناندن
    removeBtn.addEventListener('click', function(ev) {
        ev.stopPropagation();
        input.value = '';
        const existingUrl = cell.dataset.existingUrl || '';
        if (existingUrl) {
            cell.dataset.mode = 'view';
            preview.src = existingUrl;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
            removeBtn.style.display = 'none';
            if (hint) hint.innerHTML = '<span class="product-image-badge"><i class="bi bi-check-circle-fill"></i> وێنەی کاڵا بەردەستە</span>';
        } else {
            cell.dataset.mode = 'edit';
            preview.src = '';
            preview.style.display = 'none';
            placeholder.style.display = 'flex';
            removeBtn.style.display = 'none';
            if (hint) hint.textContent = 'کلیک بکە بۆ هەڵبژاردنی وێنە';
        }
    });
}

// نیشاندانی وێنەی کاڵای هەڵبژێردراو (ئەگەر هەیبوو → نیشاندان، ئەگەر نەبوو → داناندنی نوێ)
function applyProductImageToRow(row, product) {
    const cell = row.querySelector('.product-image-cell');
    if (!cell) return;

    const input = cell.querySelector('.product-image-input');
    const preview = cell.querySelector('.product-image-preview');
    const placeholder = cell.querySelector('.product-image-placeholder');
    const removeBtn = cell.querySelector('.product-image-remove');
    const hint = cell.querySelector('.pi-hint');

    // ئەگەر بەکارهێنەر خۆی وێنەیەکی هەڵبژاردبوو، دەستکاری ناکەین
    if (input && input.files && input.files.length > 0) return;

    const url = product && product.image_url ? product.image_url : '';
    if (url) {
        cell.dataset.mode = 'view';
        cell.dataset.existingUrl = url;
        if (input) { input.disabled = false; input.value = ''; }
        preview.src = url;
        preview.style.display = 'block';
        placeholder.style.display = 'none';
        removeBtn.style.display = 'none';
        if (hint) hint.innerHTML = '<span class="product-image-badge"><i class="bi bi-check-circle-fill"></i> وێنەی کاڵا بەردەستە</span>';
    } else {
        cell.dataset.mode = 'edit';
        cell.dataset.existingUrl = '';
        if (input) { input.disabled = false; }
        preview.src = '';
        preview.style.display = 'none';
        placeholder.style.display = 'flex';
        removeBtn.style.display = 'none';
        if (hint) hint.textContent = 'کلیک بکە بۆ هەڵبژاردنی وێنە';
    }
}

function getPharmacyRowHtml() {
    const catOpts = (categories || []).map(c => '<option value="' + c.id + '">' + String(c.name).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</option>').join('');
    return `
        <input type="hidden" name="unit_id[]" value="0">
        <input type="hidden" name="conversion_rate[]" class="conversion-rate" value="1">
        ${productImageCellHtml()}
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label product-name-label"><span class="item-row-number">1-</span> ناوی کاڵا <span class="text-danger">*</span></label>
                <div class="autocomplete-container">
                    <input type="hidden" name="product_id[]" value="0">
                    <input type="text" class="form-control product-name" name="product_name[]" placeholder="ناوی کاڵا" required autocomplete="off">
                    <div class="autocomplete-suggestions"></div>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">بارکۆد</label>
                <div class="autocomplete-container">
                    <div class="input-group">
                        <input type="text" class="form-control item-barcode" name="barcode[]" placeholder="بارکۆد" autocomplete="off">
                        <button type="button" class="btn btn-outline-info print-barcode-btn" title="پرێنتی بارکۆد" tabindex="-1"><i class="bi bi-printer"></i></button>
                    </div>
                    <div class="autocomplete-suggestions"></div>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">کەتەلۆگ</label>
                <select class="form-select item-category" name="category_id[]">
                    <option value="">--</option>
                    ${catOpts}
                </select>
            </div>
        </div>
        <div class="row g-2 align-items-end mt-2">
            <div class="col-md-2">
                <label class="form-label">بڕی پاکەت <span class="text-danger">*</span></label>
                <input type="number" class="form-control quantity" name="quantity[]" value="1" min="1" step="1" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">پاکەت چەند شیتە؟</label>
                <input type="number" class="form-control sheets-per-packet" name="sheets_per_packet[]" value="0" min="0" step="1">
            </div>
            <div class="col-md-2">
                <label class="form-label">بۆنسی پاکەت</label>
                <input type="number" class="form-control packet-bonus" name="packet_bonus[]" value="0" min="0" step="0.5">
            </div>
            <div class="col-md-2">
                <label class="form-label">داشکاندن (%)</label>
                <input type="number" class="form-control item-discount-percent" name="item_discount_percent[]" value="0" min="0" max="100" step="any">
            </div>
        </div>
        <div class="row g-2 align-items-end mt-2 pharmacy-sheet-block" style="display: flex;">
            <div class="col-md-2">
                <label class="form-label">نرخی کڕین <span class="text-danger">*</span></label>
                <input type="number" class="form-control buy-price step-1000" name="buy_price[]" step="any" min="0" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">نرخی تێکڕای کڕینی پاکەت</label>
                <input type="text" class="form-control bg-light avg-buy-after-bonus-discount" readonly tabindex="-1">
            </div>
            <div class="col-md-2">
                <label class="form-label">نرخی تێکڕای کڕینی شیت</label>
                <input type="text" class="form-control bg-light sheet-buy-price" name="sheet_buy_price[]" readonly tabindex="-1">
            </div>
        </div>
        <div class="row g-2 align-items-end mt-2">
            <div class="col-md-2">
                <label class="form-label">نرخی فرۆشتنی پاکەت</label>
                <input type="number" class="form-control sell-price step-1000" name="sell_price[]" step="any" min="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">نرخی جوملەی پاکەت</label>
                <input type="number" class="form-control step-1000" name="wholesale_price[]" step="any" min="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">نرخی تایبەت پاکەت</label>
                <input type="number" class="form-control step-1000" name="special_price[]" step="any" min="0">
            </div>
        </div>
        <div class="row g-2 align-items-end mt-2">
            <div class="col-md-2">
                <label class="form-label">نرخی فرۆشتنی شیت</label>
                <input type="number" class="form-control sheet-sell-price step-1000" name="sheet_sell_price[]" step="any" min="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">بەرواری بەسەرچوون</label>
                <input type="date" class="form-control expiry-date" name="expiry_date[]">
            </div>
            <div class="col-md-2">
                <label class="form-label d-block">&nbsp;</label>
                <button type="button" class="delete-item btn btn-danger btn-sm" onclick="removeItem(this)" title="سڕینەوە">
                    <i class="bi bi-trash"></i> سڕینەوە
                </button>
            </div>
        </div>
    `;
}

function lockExtraDiscountInputs() {
    if (!isPharmacyMode) return;
    const discounts = document.querySelectorAll('.item-discount-percent');
    let firstDiscount = '0';
    discounts.forEach((input, index) => {
        if (!input) return;
        if (index === 0) {
            firstDiscount = formatEditDecimal(input.value, 2);
            input.value = firstDiscount;
        } else {
            input.value = firstDiscount;
        }
        input.disabled = index !== 0;
    });
    const discountTypeEl = document.getElementById('discount_type');
    const discountValueEl = document.getElementById('discount_value');
    if (discountTypeEl) {
        discountTypeEl.value = 'percentage';
        discountTypeEl.disabled = true;
    }
    if (discountValueEl) {
        discountValueEl.value = firstDiscount;
        discountValueEl.readOnly = true;
    }
}

function updatePharmacyRowSheet(row) {
    const qty = parseFloat(row.querySelector('.quantity')?.value) || 0;
    const bonus = parseFloat(row.querySelector('.packet-bonus')?.value) || 0;
    const sheetsPerPacket = parseInt(row.querySelector('.sheets-per-packet')?.value, 10) || 0;
    const buyPrice = parseFloat(row.querySelector('.buy-price')?.value) || 0;
    const sellPrice = parseFloat(row.querySelector('.sell-price')?.value) || 0;
    const discountPercent = Math.max(0, Math.min(100, parseFloat(row.querySelector('.item-discount-percent')?.value) || 0));
    const totalPackets = qty + bonus;
    const lineTotal = qty * buyPrice;
    const lineDiscount = (lineTotal * discountPercent) / 100;
    const netLineTotal = lineTotal - lineDiscount;
    const effectivePacketBuy = totalPackets > 0 ? netLineTotal / totalPackets : NaN;
    const avgBuyInput = row.querySelector('.avg-buy-after-bonus-discount');
    if (avgBuyInput) {
        avgBuyInput.value = Number.isFinite(effectivePacketBuy) ? formatEditDecimal(effectivePacketBuy, 3) : '';
    }
    const sheetBlock = row.querySelector('.pharmacy-sheet-block');
    const sheetBuyInput = row.querySelector('.sheet-buy-price');
    const sheetSellInput = row.querySelector('.sheet-sell-price');
    if (sheetBlock) {
        sheetBlock.style.display = 'flex';
    }
    if (sheetsPerPacket > 0) {
        if (sheetBuyInput) {
            sheetBuyInput.value = Number.isFinite(effectivePacketBuy)
                ? formatEditDecimal(effectivePacketBuy / sheetsPerPacket, 3)
                : '';
            sheetBuyInput.dataset.modified = 'false';
        }
        if (sheetSellInput) {
            sheetSellInput.value = formatEditDecimal(sellPrice / sheetsPerPacket, 3);
            sheetSellInput.dataset.modified = 'false';
        }
    } else {
        if (sheetBuyInput) {
            sheetBuyInput.value = '';
            sheetBuyInput.dataset.modified = 'false';
        }
        if (sheetSellInput) {
            sheetSellInput.value = '';
            sheetSellInput.dataset.modified = 'false';
        }
    }
}

function bindPharmacyRowEvents(row) {
    ['quantity', 'packet-bonus', 'buy-price', 'sell-price', 'sheets-per-packet'].forEach(className => {
        const el = row.querySelector('.' + className);
        if (el) el.addEventListener('input', function() { updatePharmacyRowSheet(row); updateSummary(); });
    });
}

function addNewItem() {
    const itemsContainer = document.getElementById('itemsContainer');
    const newItem = document.createElement('div');
    newItem.className = 'item-row' + (isPharmacyMode ? ' pharmacy-item-row' : '');
    newItem.setAttribute('data-index', itemIndex);
    if (isPharmacyMode) newItem.setAttribute('data-pharmacy', '1');
    
    if (isPharmacyMode) {
        newItem.innerHTML = getPharmacyRowHtml();
    } else {
        newItem.innerHTML = `
        ${productImageCellHtml()}
        <div class="row align-items-end">
            <div class="col-md-3">
                <label class="form-label product-name-label"><span class="item-row-number">1-</span> ناوی کاڵا <span class="text-danger">*</span></label>
                <div class="autocomplete-container">
                    <input type="hidden" name="product_id[]" value="0">
                    <input type="text" class="form-control product-name" name="product_name[]"
                           placeholder="ناوی کاڵا" required autocomplete="off">
                    <div class="autocomplete-suggestions"></div>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">بارکۆد</label>
                <div class="autocomplete-container">
                    <div class="input-group">
                        <input type="text" class="form-control item-barcode" name="barcode[]" placeholder="بارکۆد" autocomplete="off">
                        <button type="button" class="btn btn-outline-info print-barcode-btn" title="پرێنتی بارکۆد" tabindex="-1"><i class="bi bi-printer"></i></button>
                    </div>
                    <div class="autocomplete-suggestions"></div>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">بڕ <span class="text-danger">*</span></label>
                <input type="number" class="form-control quantity" name="quantity[]" value="1" min="0.001" step="0.001" required>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">یەکە</label>
                <select class="form-control unit-select" name="unit_id[]" onchange="updateUnitPrices(this)">
                    <option value="0">یەکەی بنەڕەتی</option>
                </select>
                <input type="hidden" name="conversion_rate[]" class="conversion-rate" value="1">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">نرخی کڕین <span class="text-danger">*</span></label>
                <input type="number" class="form-control buy-price" name="buy_price[]" step="0.001" min="0" required>
            </div>
        </div>
        
        <div class="row align-items-end mt-3">
            <div class="col-md-3">
                <label class="form-label">نرخی فرۆشتن</label>
                <input type="number" class="form-control sell-price" name="sell_price[]" step="0.001" min="0">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">بەرواری بەسەرچوون</label>
                <input type="date" class="form-control expiry-date" name="expiry_date[]">
            </div>
            
            <div class="col-md-6">
                <button type="button" class="delete-item btn btn-danger btn-sm" onclick="removeItem(this)" title="سڕینەوە">
                    <i class="bi bi-trash"></i> سڕینەوەی کاڵا
                </button>
            </div>
        </div>
        
        <div class="row mt-2">
            <div class="col-md-6">
                <label class="form-label">نرخی جوملە</label>
                <input type="number" class="form-control" name="wholesale_price[]" step="0.001" min="0">
            </div>
            <div class="col-md-6">
                <label class="form-label">نرخی تایبەت</label>
                <input type="number" class="form-control" name="special_price[]" step="0.001" min="0">
            </div>
        </div>
    `;
    }
    
    itemsContainer.appendChild(newItem);

    initProductImageCell(newItem);

    // Initialize autocomplete بۆ کاڵای نوێ
    const productNameInput = newItem.querySelector('.product-name');
    initializeAutocomplete(productNameInput);
    const barcodeInput = newItem.querySelector('.item-barcode');
    if (barcodeInput) initializeBarcodeAutocomplete(barcodeInput);
    if (isPharmacyMode) {
        bindPharmacyRowEvents(newItem);
        lockExtraDiscountInputs();
        updatePharmacyRowSheet(newItem);
    } else {
        applyUserUnitsForNewProduct(newItem);
    }
    syncItemRowNumbers();
    
    // Focus بکە لەسەر input ی نوێ
    productNameInput.focus();
    
    itemIndex++;
    updateSummary();
}

function removeItem(button) {
    const itemRow = button.closest('.item-row');
    const itemsContainer = document.getElementById('itemsContainer');
    
    // مەهێڵە لانی کەم یەک کاڵا بمێنێتەوە
    if (itemsContainer.children.length > 1) {
        itemRow.remove();
        if (isPharmacyMode) {
            lockExtraDiscountInputs();
            document.querySelectorAll('.pharmacy-item-row').forEach(updatePharmacyRowSheet);
        }
        syncItemRowNumbers();
        updateSummary();
    } else {
        alert('لانی کەم یەک کاڵا دەبێت هەبێت!');
    }
}

function initializeAutocomplete(input) {
    const suggestions = input.nextElementSibling;
    const hiddenInput = input.previousElementSibling;
    let selectedIndex = -1;
    
    input.addEventListener('input', function() {
        const value = this.value.toLowerCase();
        suggestions.innerHTML = '';
        selectedIndex = -1;
        hiddenInput.value = '0';
        
        if (value.length >= 2) {
            const matches = productsArray.filter(product => 
                product.name.toLowerCase().includes(value) || 
                (product.barcode && product.barcode.includes(value))
            ).slice(0, 10);
            
            if (matches.length > 0) {
                matches.forEach((product, index) => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-suggestion';
                    div.innerHTML = `
                        <strong>${product.name}</strong>
                        ${product.barcode ? `<br><small class="text-muted">بارکۆد: ${product.barcode}</small>` : ''}
                        <br><small class="text-success">نرخی کڕین: ${parseFloat(product.buy_price).toLocaleString()} | فرۆشتن: ${parseFloat(product.sell_price).toLocaleString()}</small>
                        <br><small class="text-info">کۆگا: ${product.stock_quantity}</small>
                    `;
                    
                    div.addEventListener('click', function() {
                        selectProduct(input, product);
                    });
                    
                    suggestions.appendChild(div);
                });
                
                suggestions.style.display = 'block';
            } else {
                suggestions.style.display = 'none';
            }
        } else {
            suggestions.style.display = 'none';
        }
    });
    
    input.addEventListener('keydown', function(e) {
        const suggestionItems = suggestions.querySelectorAll('.autocomplete-suggestion');
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIndex = (selectedIndex + 1) % suggestionItems.length;
            updateSelectedSuggestion();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIndex = selectedIndex <= 0 ? suggestionItems.length - 1 : selectedIndex - 1;
            updateSelectedSuggestion();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedIndex >= 0 && suggestionItems[selectedIndex]) {
                suggestionItems[selectedIndex].click();
            }
        } else if (e.key === 'Escape') {
            suggestions.style.display = 'none';
            selectedIndex = -1;
        }
        
        function updateSelectedSuggestion() {
            suggestionItems.forEach((item, index) => {
                item.classList.toggle('active', index === selectedIndex);
            });
        }
    });
    
    // Close suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !suggestions.contains(e.target)) {
            suggestions.style.display = 'none';
        }
    });
}

function normalizeProductFromApi(apiProduct) {
    const normalized = {
        id: apiProduct.id,
        name: apiProduct.name,
        barcode: apiProduct.barcode || '',
        category_id: apiProduct.category_id || null,
        buy_price: apiProduct.buy_price ?? 0,
        sell_price: apiProduct.sell_price ?? 0,
        wholesale_price: apiProduct.wholesale_price ?? 0,
        special_price: apiProduct.special_price ?? 0,
        stock_quantity: apiProduct.stock_quantity ?? 0,
        expiry_date: apiProduct.expiry_date || null,
        image_url: apiProduct.image_url || null,
        units: (apiProduct.units || []).map(u => ({
            unit_id: u.unit_id,
            unit_name: u.unit_name,
            unit_symbol: u.unit_symbol || '',
            buy_price: u.buy_price ?? 0,
            sell_price: u.sell_price ?? 0,
            wholesale_price: u.wholesale_price ?? 0,
            special_price: u.special_price ?? 0,
            conversion_rate: u.conversion_rate ?? 1,
            is_primary: u.is_primary ?? 0
        }))
    };
    const local = products[normalized.id];
    if (local) {
        if (!normalized.image_url && local.image_url) normalized.image_url = local.image_url;
        if (local.last_sheets_per_packet != null) normalized.last_sheets_per_packet = local.last_sheets_per_packet;
        if (local.last_packet_bonus != null) normalized.last_packet_bonus = local.last_packet_bonus;
        if (local.last_sheet_buy_price != null) normalized.last_sheet_buy_price = local.last_sheet_buy_price;
        if (local.last_sheet_sell_price != null) normalized.last_sheet_sell_price = local.last_sheet_sell_price;
    }
    return normalized;
}

function upsertProductCache(product) {
    products[product.id] = product;
    const idx = productsArray.findIndex(p => p.id === product.id);
    if (idx >= 0) {
        productsArray[idx] = product;
    } else {
        productsArray.push(product);
    }
}

async function lookupProductByBarcode(code) {
    const trimmed = (code || '').trim();
    if (!trimmed) return null;

    const localMatch = productsArray.find(p => p.barcode && String(p.barcode) === trimmed);
    if (localMatch) return localMatch;

    try {
        const url = `${productBarcodeApiUrl}?action=barcode&code=${encodeURIComponent(trimmed)}`;
        const response = await fetch(url);
        const data = await response.json();
        if (data && data.success && data.data && data.data.product) {
            const product = normalizeProductFromApi(data.data.product);
            upsertProductCache(product);
            return product;
        }
    } catch (err) {
        console.error('Barcode lookup failed:', err);
    }
    return null;
}

function flashBarcodeInputError(input) {
    input.classList.add('is-invalid');
    setTimeout(() => input.classList.remove('is-invalid'), 2000);
}

function clearBarcodeInputError(input) {
    input.classList.remove('is-invalid');
}

async function fillRowFromBarcode(barcodeInput) {
    const code = (barcodeInput.value || '').trim();
    if (!code) return false;

    const row = barcodeInput.closest('.item-row');
    if (!row) return false;

    const productNameInput = row.querySelector('.product-name');
    if (!productNameInput) return false;

    const product = await lookupProductByBarcode(code);
    if (product) {
        selectProduct(productNameInput, product);
        barcodeInput.value = product.barcode || code;
        clearBarcodeInputError(barcodeInput);
        return true;
    }
    flashBarcodeInputError(barcodeInput);
    return false;
}

function initializeBarcodeAutocomplete(barcodeInput) {
    const container = barcodeInput.closest('.autocomplete-container');
    const suggestions = container ? container.querySelector('.autocomplete-suggestions') : barcodeInput.nextElementSibling;
    let selectedIndex = -1;

    barcodeInput.addEventListener('input', function() {
        const value = (this.value || '').trim();
        suggestions.innerHTML = '';
        selectedIndex = -1;
        clearBarcodeInputError(barcodeInput);

        if (value.length >= 1) {
            const matches = productsArray.filter(product =>
                product.barcode && String(product.barcode).includes(value)
            ).slice(0, 10);

            if (matches.length > 0) {
                matches.forEach((product) => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-suggestion';
                    div.innerHTML = `
                        <strong>${product.name}</strong>
                        ${product.barcode ? `<br><small class="text-muted">بارکۆد: ${product.barcode}</small>` : ''}
                        <br><small class="text-success">نرخی کڕین: ${parseFloat(product.buy_price).toLocaleString()} | فرۆشتن: ${parseFloat(product.sell_price).toLocaleString()}</small>
                        <br><small class="text-info">کۆگا: ${product.stock_quantity}</small>
                    `;

                    div.addEventListener('click', function() {
                        const row = barcodeInput.closest('.item-row');
                        const productNameInput = row ? row.querySelector('.product-name') : null;
                        if (productNameInput) selectProduct(productNameInput, product);
                        suggestions.style.display = 'none';
                    });

                    suggestions.appendChild(div);
                });
                suggestions.style.display = 'block';
            } else {
                suggestions.style.display = 'none';
            }
        } else {
            suggestions.style.display = 'none';
        }
    });

    barcodeInput.addEventListener('keydown', function(e) {
        const suggestionItems = suggestions.querySelectorAll('.autocomplete-suggestion');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIndex = suggestionItems.length ? (selectedIndex + 1) % suggestionItems.length : -1;
            updateSelectedSuggestion();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIndex = suggestionItems.length ? (selectedIndex <= 0 ? suggestionItems.length - 1 : selectedIndex - 1) : -1;
            updateSelectedSuggestion();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedIndex >= 0 && suggestionItems[selectedIndex]) {
                suggestionItems[selectedIndex].click();
            } else {
                fillRowFromBarcode(barcodeInput);
            }
        } else if (e.key === 'Escape') {
            suggestions.style.display = 'none';
            selectedIndex = -1;
        }

        function updateSelectedSuggestion() {
            suggestionItems.forEach((item, index) => {
                item.classList.toggle('active', index === selectedIndex);
            });
        }
    });

    barcodeInput.addEventListener('blur', function() {
        setTimeout(async () => {
            const row = barcodeInput.closest('.item-row');
            if (!row) return;
            const hiddenId = row.querySelector('[name="product_id[]"]');
            const code = (barcodeInput.value || '').trim();
            if (code.length >= 4 && hiddenId && (!hiddenId.value || hiddenId.value === '0')) {
                await fillRowFromBarcode(barcodeInput);
            }
        }, 150);
    });

    document.addEventListener('click', function(e) {
        if (!barcodeInput.contains(e.target) && !suggestions.contains(e.target)) {
            suggestions.style.display = 'none';
        }
    });
}

function selectProduct(input, product) {
    const row = input.closest('.item-row');
    const hiddenInput = input.previousElementSibling;
    const suggestions = input.nextElementSibling;
    
    // پڕکردنەوەی زانیاری کاڵا
    input.value = product.name;
    hiddenInput.value = product.id;
    const barcodeInput = row.querySelector('.item-barcode');
    if (barcodeInput) barcodeInput.value = product.barcode || '';
    const categorySelect = row.querySelector('.item-category');
    if (categorySelect && product.category_id) {
        categorySelect.value = String(product.category_id);
    }

    // نیشاندانی وێنەی کاڵا (ئەگەر هەیبوو) یان ڕێگەدان بۆ داناندنی نوێ
    applyProductImageToRow(row, product);

    if (row.classList.contains('pharmacy-item-row')) {
        const sheetsPerPacketInput = row.querySelector('.sheets-per-packet');
        const packetBonusInput = row.querySelector('.packet-bonus');
        if (sheetsPerPacketInput && product.last_sheets_per_packet != null && product.last_sheets_per_packet > 0) {
            sheetsPerPacketInput.value = product.last_sheets_per_packet;
            if (packetBonusInput && product.last_packet_bonus != null) packetBonusInput.value = product.last_packet_bonus;
        }
    }
    
    // هەڵگرتنی نرخەکانی بنەڕەتی کاڵا بۆ بەراوردکردن
    row.dataset.productBuyPrice = product.buy_price;
    row.dataset.productSellPrice = product.sell_price;
    row.dataset.productWholesalePrice = product.wholesale_price;
    row.dataset.productSpecialPrice = product.special_price;
    row.dataset.productExpiryDate = product.expiry_date || '';
    
    // بارکردنی یەکەکان
    const unitSelect = row.querySelector('.unit-select');
    const conversionRateInput = row.querySelector('.conversion-rate');
    if (conversionRateInput) conversionRateInput.value = 1;
    if (!unitSelect) {
        setPrices(row, product);
        const expiryInput = row.querySelector('.expiry-date');
        if (expiryInput) {
            expiryInput.value = product.expiry_date || '';
            expiryInput.dataset.modified = 'false';
        }
        markPricesAsUnmodified(row);
        suggestions.style.display = 'none';
        updateSummary();
        if (row.classList.contains('pharmacy-item-row')) {
            updatePharmacyRowSheet(row);
        }
        return;
    }
    unitSelect.innerHTML = '<option value="0">یەکەی بنەڕەتی</option>';
    
    if (product.units && product.units.length > 0) {
        let primaryUnitIndex = -1;
        let firstUnitIndex = 0;
        
        // دروستکردنی option ەکان و دۆزینەوەی یەکەی بنەڕەتی
        product.units.forEach((unit, index) => {
            const option = document.createElement('option');
            option.value = unit.unit_id;
            option.textContent = `${unit.unit_name}${unit.unit_symbol ? ' (' + unit.unit_symbol + ')' : ''}`;
            option.dataset.buyPrice = unit.buy_price;
            option.dataset.sellPrice = unit.sell_price;
            option.dataset.wholesalePrice = unit.wholesale_price;
            option.dataset.specialPrice = unit.special_price;
            option.dataset.conversionRate = unit.conversion_rate;
            
            // دۆزینەوەی یەکەی بنەڕەتی
            if (unit.is_primary) {
                primaryUnitIndex = index + 1; // +1 چونکە option ی یەکەم "یەکەی بنەڕەتی"ە
            }
            
            unitSelect.appendChild(option);
        });
        
        // هەڵبژاردنی یەکەی بنەڕەتی یان یەکەمی یەکەکان
        let selectedUnitIndex = primaryUnitIndex >= 0 ? primaryUnitIndex : (firstUnitIndex + 1);
        if (selectedUnitIndex > 0 && selectedUnitIndex < unitSelect.options.length) {
            unitSelect.selectedIndex = selectedUnitIndex;
        }
        
        // بارکردنی نرخەکانی یەکەی هەڵبژێردراو
        const selectedOption = unitSelect.options[unitSelect.selectedIndex];
        if (selectedOption && selectedOption.value != '0') {
            // دۆزینەوەی یەکەی هەڵبژێردراو لە لیستی یەکەکان
            const selectedUnit = product.units.find(u => u.unit_id == selectedOption.value);
            
            if (selectedUnit) {
                // Helper function to get value if it's set (handles 0 correctly)
                const getValue = (unitValue, fallbackValue) => {
                    return (unitValue !== null && unitValue !== undefined && unitValue !== '') ? unitValue : fallbackValue;
                };
                
                // بەکارهێنانی نرخەکانی یەکە (ئەگەر هەبن) یان نرخی بنەڕەتی کاڵا
                const priceData = {
                    buyPrice: getValue(selectedUnit.buy_price, product.buy_price),
                    sellPrice: getValue(selectedUnit.sell_price, product.sell_price),
                    wholesalePrice: getValue(selectedUnit.wholesale_price, product.wholesale_price),
                    specialPrice: getValue(selectedUnit.special_price, product.special_price)
                };
                setPrices(row, priceData);
                conversionRateInput.value = selectedUnit.conversion_rate || 1;
            } else {
                // ئەگەر یەکەکە نەدۆزرایەوە، نرخی بنەڕەتی کاڵا بەکار بهێنە
                setPrices(row, product);
            }
        } else {
            // ئەگەر یەکەی بنەڕەتی هەڵبژێردرابێت، نرخی بنەڕەتی کاڵا بەکار بهێنە
            setPrices(row, product);
        }
        
        // دانانی بەرواری بەسەرچوون بەپێی کاڵا (هەمیشە پڕ بکەرەوە یان پاک بکەرەوە)
        const expiryInput = row.querySelector('.expiry-date');
        if (expiryInput) {
            expiryInput.value = product.expiry_date || '';
            expiryInput.dataset.modified = 'false'; // نیشان بکە کە دەستکاری نەکراوە
        }
    } else {
        // کاڵا یەکەی نیە، نرخی بنەڕەتی بەکار بێنە
        setPrices(row, product);
        
        // دانانی بەرواری بەسەرچوون (پڕ بکەرەوە یان پاک بکەرەوە)
        const expiryInput = row.querySelector('.expiry-date');
        if (expiryInput) {
            expiryInput.value = product.expiry_date || '';
            expiryInput.dataset.modified = 'false'; // نیشان بکە کە دەستکاری نەکراوە
        }
    }
    
    // نیشانکردن کە نرخەکان دەستکاری نەکراون (سەرەتایین)
    markPricesAsUnmodified(row);
    
    suggestions.style.display = 'none';
    updateSummary();
}

// فانکشنی یارمەتیدەر بۆ دانانی نرخەکان
function setPrices(row, priceData) {
    // Helper function to get price value (handles both camelCase and snake_case, and handles 0 values correctly)
    const getPrice = (camelKey, snakeKey) => {
        // چێککردن بۆ camelCase (لە پێشەوە)
        if (camelKey in priceData && priceData[camelKey] !== null && priceData[camelKey] !== '') {
            return priceData[camelKey];
        }
        // چێککردن بۆ snake_case
        if (snakeKey in priceData && priceData[snakeKey] !== null && priceData[snakeKey] !== '') {
            return priceData[snakeKey];
        }
        return '';
    };
    
    row.querySelector('.buy-price').value = getPrice('buyPrice', 'buy_price');
    row.querySelector('.sell-price').value = getPrice('sellPrice', 'sell_price');
    row.querySelector('[name="wholesale_price[]"]').value = getPrice('wholesalePrice', 'wholesale_price');
    row.querySelector('[name="special_price[]"]').value = getPrice('specialPrice', 'special_price');
}

// نیشانکردنی نرخەکان وەک دەستکاری‌نەکراو
function markPricesAsUnmodified(row) {
    row.querySelector('.buy-price').dataset.modified = 'false';
    row.querySelector('.sell-price').dataset.modified = 'false';
    row.querySelector('[name="wholesale_price[]"]').dataset.modified = 'false';
    row.querySelector('[name="special_price[]"]').dataset.modified = 'false';
}

function updateUnitPrices(selectElement) {
    const row = selectElement.closest('.item-row');
    const productId = row.querySelector('[name="product_id[]"]').value;
    const unitId = selectElement.value;
    const conversionRateInput = row.querySelector('.conversion-rate');
    
    if (productId && productId != '0' && products[productId]) {
        const product = products[productId];
        
        const buyPriceInput = row.querySelector('.buy-price');
        const sellPriceInput = row.querySelector('.sell-price');
        const wholesalePriceInput = row.querySelector('[name="wholesale_price[]"]');
        const specialPriceInput = row.querySelector('[name="special_price[]"]');
        const expiryInput = row.querySelector('.expiry-date');
        
        if (unitId != '0' && product.units && product.units.length > 0) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            if (selectedOption) {
                // نوێکردنەوەی نرخەکان بەپێی دۆخیان
                updatePriceIfNotModified(buyPriceInput, selectedOption.dataset.buyPrice, product.buy_price);
                updatePriceIfNotModified(sellPriceInput, selectedOption.dataset.sellPrice, product.sell_price);
                updatePriceIfNotModified(wholesalePriceInput, selectedOption.dataset.wholesalePrice, product.wholesale_price);
                updatePriceIfNotModified(specialPriceInput, selectedOption.dataset.specialPrice, product.special_price);
                
                conversionRateInput.value = selectedOption.dataset.conversionRate || 1;
                
                // دانانی بەرواری بەسەرچوون بەپێی کاڵا (ئەگەر بەروار هەبێت و دەستکاری نەکرابێت)
                if (expiryInput && product.expiry_date && !expiryInput.dataset.modified) {
                    expiryInput.value = product.expiry_date;
                }
            }
        } else {
            // گەڕانەوە بۆ نرخی بنەڕەتی - تەنها ئەگەر دەستکاری نەکرابێت
            updatePriceIfNotModified(buyPriceInput, product.buy_price, product.buy_price);
            updatePriceIfNotModified(sellPriceInput, product.sell_price, product.sell_price);
            updatePriceIfNotModified(wholesalePriceInput, product.wholesale_price, product.wholesale_price);
            updatePriceIfNotModified(specialPriceInput, product.special_price, product.special_price);
            
            conversionRateInput.value = 1;
            
            // دانانی بەرواری بەسەرچوون بەپێی کاڵا (ئەگەر بەروار هەبێت و دەستکاری نەکرابێت)
            if (expiryInput && product.expiry_date && !expiryInput.dataset.modified) {
                expiryInput.value = product.expiry_date;
            }
        }
    }
    
    updateSummary();
}

// نوێکردنەوەی نرخ تەنها ئەگەر دەستکاری نەکرابێت
function updatePriceIfNotModified(input, newPrice, basePrice) {
    const isModified = input.dataset.modified === 'true';
    
    if (!isModified) {
        // نرخەکە دەستکاری نەکراوە، بۆیە نرخی نوێی یەکە بەکار بهێنە
        input.value = newPrice ? parseFloat(newPrice) : '';
    } else {
        // نرخەکە دەستکاری کراوە، بەڕێژەی گۆڕانکاری حیسابی بکەوە
        const currentValue = parseFloat(input.value) || 0;
        const baseValue = parseFloat(basePrice) || 1;
        const newBaseValue = parseFloat(newPrice) || 1;
        
        if (baseValue > 0 && currentValue !== baseValue) {
            // حیسابی ڕێژەی گۆڕانکاری
            const ratio = currentValue / baseValue;
            input.value = parseFloat((newBaseValue * ratio).toFixed(3));
        }
    }
}

function updateSummary() {
    const quantities = document.querySelectorAll('.quantity');
    const buyPrices = document.querySelectorAll('.buy-price');
    const discountType = document.getElementById('discount_type').value;
    const discountValue = parseFloat(document.getElementById('discount_value').value) || 0;
    const additionalCharges = parseFloat(document.getElementById('additional_charges').value) || 0;
    
    let subtotal = 0;
    let itemCount = 0;
    
    for (let i = 0; i < quantities.length; i++) {
        const quantity = parseFloat(quantities[i].value) || 0;
        const buyPrice = parseFloat(buyPrices[i].value) || 0;
        
        if (quantity > 0 && buyPrice >= 0) {
            const row = quantities[i].closest('.item-row');
            const lineTotal = quantity * buyPrice;
            if (isPharmacyMode && row) {
                const itemDiscountPercent = parseFloat(row.querySelector('.item-discount-percent')?.value) || 0;
                const lineDiscount = (lineTotal * itemDiscountPercent) / 100;
                subtotal += Math.max(0, lineTotal - lineDiscount);
            } else {
                subtotal += lineTotal;
            }
            itemCount++;
        }
    }
    
    // حیسابکردنی داشکاندن
    let discountAmount = 0;
    if (isPharmacyMode) {
        let grossSubtotal = 0;
        for (let i = 0; i < quantities.length; i++) {
            const quantity = parseFloat(quantities[i].value) || 0;
            const buyPrice = parseFloat(buyPrices[i].value) || 0;
            if (quantity > 0 && buyPrice >= 0) {
                grossSubtotal += quantity * buyPrice;
            }
        }
        discountAmount = Math.max(0, grossSubtotal - subtotal);
    } else if (discountValue > 0) {
        if (discountType === 'percentage') {
            discountAmount = (subtotal * discountValue) / 100;
        } else {
            discountAmount = discountValue;
        }
    }
    
    const finalTotal = subtotal - discountAmount + additionalCharges;
    
    // نوێکردنەوەی UI
    const curUnit = editReceiptCurrencyUnit();
    document.getElementById('itemsCount').textContent = itemCount;
    document.getElementById('subtotal').textContent = Math.round(subtotal).toLocaleString() + ' ' + curUnit;

    // پیشاندانی داشکاندن بەپێی جۆرەکەی
    let discountText = '-' + Math.round(discountAmount).toLocaleString() + ' ' + curUnit;
    if (!isPharmacyMode && discountType === 'percentage' && discountValue > 0) {
        discountText += ` (${discountValue}%)`;
    }
    document.getElementById('discountDisplay').textContent = discountText;

    document.getElementById('chargesDisplay').textContent = '+' + Math.round(additionalCharges).toLocaleString() + ' ' + curUnit;
    document.getElementById('finalTotal').textContent = Math.round(finalTotal).toLocaleString() + ' ' + curUnit;
}

// یەکەی دراوی وەسڵی هەڵبژێردراو
function editReceiptCurrencyUnit() {
    const sel = document.getElementById('receipt_currency');
    return (sel && sel.value === 'USD') ? 'USD' : 'IQD';
}

function updateCompanyInfo() {
    const companyId = document.getElementById('company_id').value;
    const companyInfoDiv = document.getElementById('companyInfo');
    
    if (companyId) {
        const company = companies.find(c => c.id == companyId);
        if (company) {
            let html = `<p class="mb-1"><strong>ناو:</strong> ${company.name}</p>`;
            if (company.address) {
                html += `<p class="mb-1"><strong>ناونیشان:</strong> ${company.address}</p>`;
            }
            if (company.phone) {
                html += `<p class="mb-0"><strong>تەلەفۆن:</strong> ${company.phone}</p>`;
            }
            companyInfoDiv.innerHTML = html;
        }
    } else {
        companyInfoDiv.innerHTML = '<p class="text-muted mb-0">کۆمپانیا هەڵنەبژێردراوە</p>';
    }
}

function validateForm() {
    const errors = [];
    
    // Company validation
    if (!document.getElementById('company_id').value) {
        errors.push('تکایە کۆمپانیا هەڵبژێرە');
    }
    
    // Date validation
    if (!document.getElementById('receipt_date').value) {
        errors.push('تکایە بەروار دیاری بکە');
    }
    
    // Items validation
    const productNames = document.querySelectorAll('.product-name');
    const quantities = document.querySelectorAll('.quantity');
    const buyPrices = document.querySelectorAll('.buy-price');
    
    let hasValidItem = false;
    
    for (let i = 0; i < productNames.length; i++) {
        if (productNames[i].value.trim()) {
            hasValidItem = true;
            
            if (!quantities[i].value || quantities[i].value <= 0) {
                errors.push(`بڕی کاڵای "${productNames[i].value}" نابێت سفر یان منفی بێت`);
            }
            
            if (!buyPrices[i].value || buyPrices[i].value < 0) {
                errors.push(`نرخی کڕینی کاڵای "${productNames[i].value}" نادروستە`);
            }
        }
    }
    
    if (!hasValidItem) {
        errors.push('لانی کەم یەک کاڵا دەبێت زیاد بکرێت');
    }
    
    // Show errors if any
    if (errors.length > 0) {
        let errorHtml = '<div class="alert alert-danger"><h6><i class="bi bi-exclamation-triangle"></i> هەڵەکان:</h6><ul class="mb-0">';
        errors.forEach(error => {
            errorHtml += `<li>${error}</li>`;
        });
        errorHtml += '</ul></div>';
        
        // Insert errors at top of form
        const form = document.getElementById('editReceiptForm');
        const existingAlert = form.querySelector('.alert-danger');
        if (existingAlert) {
            existingAlert.remove();
        }
        form.insertAdjacentHTML('afterbegin', errorHtml);
        
        // Scroll to top
        form.scrollIntoView({ behavior: 'smooth' });
        
        return false;
    }
    
    return true;
}

function previewImageEdit(input) {
    const fileSizeError = document.getElementById('file-size-error-edit');
    const fileSizeMessage = document.getElementById('file-size-message-edit');
    const imagePreview = document.getElementById('image_preview_edit');
    
    // Hide previous errors
    fileSizeError.style.display = 'none';
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const maxSizeBeforeCompression = 50 * 1024 * 1024; // 50MB قبل الضغط
        const fileSize = file.size;
        
        // تحقق من الحجم قبل الضغط
        if (fileSize > maxSizeBeforeCompression) {
            fileSizeMessage.textContent = `فایلەکە زۆر زۆر گەورەیە. حەدئکثر 50MB پێش کێشکردنەوە (ئێستا: ${(fileSize / 1024 / 1024).toFixed(2)}MB)`;
            fileSizeError.style.display = 'block';
            input.value = ''; // Clear the input
            imagePreview.style.display = 'none';
            return;
        }
        
        // Check file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            fileSizeMessage.textContent = 'جۆری فایل ڕێگەپێدراو نییە. تەنها JPG، PNG، GIF، WEBP ڕێگەپێدراوە';
            fileSizeError.style.display = 'block';
            input.value = ''; // Clear the input
            imagePreview.style.display = 'none';
            return;
        }
        
        // کێشکردنەوە و پیشاندان
        compressReceiptImageEdit(file, function(compressedFile) {
            // نوێکردنەوەی input بە وێنەی کێشکراوە
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(compressedFile);
            input.files = dataTransfer.files;
            
            // پیشاندانی preview
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('preview_img_edit').src = e.target.result;
                imagePreview.style.display = 'block';
            }
            reader.readAsDataURL(compressedFile);
        });
    } else {
        imagePreview.style.display = 'none';
    }
}

function compressReceiptImageEdit(file, callback) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // حیسابکردنی قەبارەی نوێ (حەدئکثر 1920x1080)
            let { width, height } = calculateImageDimensionsEdit(img.width, img.height, 1920, 1080);
            
            canvas.width = width;
            canvas.height = height;
            
            // کێشانی وێنەی کێشکراوە
            ctx.drawImage(img, 0, 0, width, height);
            
            // گۆڕینی بۆ blob بە quality 0.8
            canvas.toBlob(function(blob) {
                // دروستکردنی فایلێکی نوێ بە داتای کێشکراوە
                const compressedFile = new File([blob], file.name, {
                    type: 'image/jpeg',
                    lastModified: Date.now()
                });
                
                callback(compressedFile);
            }, 'image/jpeg', 0.8);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function calculateImageDimensionsEdit(originalWidth, originalHeight, maxWidth, maxHeight) {
    let width = originalWidth;
    let height = originalHeight;
    
    // حیسابکردنی aspect ratio
    const aspectRatio = originalWidth / originalHeight;
    
    // کێشکردنەوە ئەگەر زۆر گەورە بێت
    if (width > maxWidth) {
        width = maxWidth;
        height = width / aspectRatio;
    }
    
    if (height > maxHeight) {
        height = maxHeight;
        width = height * aspectRatio;
    }
    
    return { width: Math.round(width), height: Math.round(height) };
}

function showImageModal(src) {
    document.getElementById('modalImage').src = src;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey || e.metaKey) {
        switch (e.key) {
            case 's':
                e.preventDefault();
                document.getElementById('saveBtn').click();
                break;
            case '=':
            case '+':
                e.preventDefault();
                addNewItem();
                break;
        }
    }
});

// Auto-save draft (every 2 minutes)
setInterval(function() {
    const formData = new FormData(document.getElementById('editReceiptForm'));
    
    // Save to localStorage as backup
    const draftData = {};
    for (let [key, value] of formData.entries()) {
        if (draftData[key]) {
            if (Array.isArray(draftData[key])) {
                draftData[key].push(value);
            } else {
                draftData[key] = [draftData[key], value];
            }
        } else {
            draftData[key] = value;
        }
    }
    
    localStorage.setItem('receipt_edit_draft_' + <?php echo $receiptId; ?>, JSON.stringify(draftData));
}, 120000); // 2 minutes

// Load draft on page load if available
window.addEventListener('load', function() {
    const draftKey = 'receipt_edit_draft_' + <?php echo $receiptId; ?>;
    const draft = localStorage.getItem(draftKey);
    
    if (draft && confirm('پێشنووسەی پاشکەوتکراو هەیە، دەتەوێت باری بکەیتەوە؟')) {
        try {
            const draftData = JSON.parse(draft);
            // Load draft logic here if needed
        } catch (e) {
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>