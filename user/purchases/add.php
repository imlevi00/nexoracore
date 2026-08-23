<?php
/**
 * تۆمارکردنی وەسڵی کڕدراو - user/purchases/add.php
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

// تاقیکردنەوەی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

// AJAX keep-alive + CSRF refresh بۆ کاتی مانەوەی زۆر لە پەڕە
if (isset($_GET['ajax']) && $_GET['ajax'] === 'csrf_refresh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'csrf_token' => Security::generateCSRFToken(),
        'server_time' => time(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = getCurrentUser();
requireCompaniesModuleAccess();
$userId = $currentUser['id'];

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

// بۆ مۆدی دەرمانخانە: وەرگرتنی وەڵامی پێشووی «پاکەت چەند شیتە؟» و زانیاری شیت بۆ هەر کاڵا (دوایین وەسڵی کڕین)
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
    unset($unit);
}
unset($product);

$errors = [];
$success = false;
$submittedItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $restoreErrors = [];
    $submittedItems = purchaseReceiptParseRawRowsFromPost($_POST, $restoreErrors);
    if (!empty($restoreErrors)) {
        $errors = array_merge($errors, $restoreErrors);
    }
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

function getDefaultUnitId($conn, $userId) {
    $unit = getDefaultPieceUnitForUser($conn, (int)$userId);
    return (int)($unit['id'] ?? 0);
}

function resolveOrCreateProductForPurchase($conn, $userId, array $item, $isPharmacyMode, $defaultUnitId, $packetUnitId = 0, $currency = 'IQD') {
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
        $catId = !empty($item['category_id']) ? (int)$item['category_id'] : null;
        $expProd = $item['expiry_date'] ?: null;
        if ($catId !== null) {
            $insProd = $conn->prepare("
                INSERT INTO products (user_id, category_id, name, barcode, currency, expiry_date)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insProd->bind_param("iissss", $userId, $catId, $productName, $resolvedBarcode, $currency, $expProd);
        } else {
            $insProd = $conn->prepare("
                INSERT INTO products (user_id, name, barcode, currency, expiry_date)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insProd->bind_param("issss", $userId, $productName, $resolvedBarcode, $currency, $expProd);
        }
        $insProd->execute();
        $resolvedProductId = (int)$conn->insert_id;
        $insProd->close();
    }

    if ($resolvedProductId > 0 && $resolvedBarcode !== '') {
        $syncBarcodeStmt = $conn->prepare("
            UPDATE products
            SET barcode = ?
            WHERE id = ? AND user_id = ? AND (barcode IS NULL OR TRIM(barcode) = '')
        ");
        $syncBarcodeStmt->bind_param("sii", $resolvedBarcode, $resolvedProductId, $userId);
        $syncBarcodeStmt->execute();
        $syncBarcodeStmt->close();
    }

    if ($isPharmacyMode) {
        $resolvedUnitId = (int)$packetUnitId;
    }
    if ($resolvedUnitId <= 0) {
        $resolvedUnitId = (int)$defaultUnitId;
    }
    if ($resolvedUnitId <= 0) {
        throw new Exception('هیچ یەکەیەکی چالاک بۆ دروستکردنی کاڵا نەدۆزرایەوە.');
    }

    $findPrimaryUnit = $conn->prepare("
        SELECT id, unit_id
        FROM product_units
        WHERE product_id = ?
        ORDER BY is_primary DESC, id ASC
        LIMIT 1
    ");
    $findPrimaryUnit->bind_param("i", $resolvedProductId);
    $findPrimaryUnit->execute();
    $primaryUnit = $findPrimaryUnit->get_result()->fetch_assoc();
    $findPrimaryUnit->close();

    if (!$primaryUnit) {
        $seedBuy = (float)($item['effective_buy_price'] ?? $item['buy_price'] ?? 0);
        $seedSell = (float)($item['sell_price'] ?? 0);
        $seedWholesale = (float)($item['wholesale_price'] ?? 0);
        $seedSpecial = (float)($item['special_price'] ?? 0);
        $insertPrimary = $conn->prepare("
            INSERT INTO product_units (product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 1, 1, 1)
        ");
        $insertPrimary->bind_param("iidddds", $resolvedProductId, $resolvedUnitId, $seedBuy, $seedSell, $seedWholesale, $seedSpecial, $currency);
        $insertPrimary->execute();
        $insertPrimary->close();
    } else {
        if ((int)$resolvedUnitId <= 0) {
            $resolvedUnitId = (int)$primaryUnit['unit_id'];
        }
    }

    if (!$isPharmacyMode) {
        $ensureCurrentUnit = $conn->prepare("SELECT id FROM product_units WHERE product_id = ? AND unit_id = ? LIMIT 1");
        $ensureCurrentUnit->bind_param("ii", $resolvedProductId, $resolvedUnitId);
        $ensureCurrentUnit->execute();
        $unitRow = $ensureCurrentUnit->get_result()->fetch_assoc();
        $ensureCurrentUnit->close();

        if (!$unitRow) {
            $unitConversionRate = (float)($item['conversion_rate'] ?? 1.0);
            if ($unitConversionRate <= 0) {
                $unitConversionRate = 1.0;
            }
            $unitConversionRatio = 1 / $unitConversionRate;
            $seedBuy = (float)($item['effective_buy_price'] ?? $item['buy_price'] ?? 0);
            $seedSell = (float)($item['sell_price'] ?? 0);
            $seedWholesale = (float)($item['wholesale_price'] ?? 0);
            $seedSpecial = (float)($item['special_price'] ?? 0);
            $insertUnit = $conn->prepare("
                INSERT INTO product_units (product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0)
            ");
            $insertUnit->bind_param("iiddddsdd", $resolvedProductId, $resolvedUnitId, $seedBuy, $seedSell, $seedWholesale, $seedSpecial, $currency, $unitConversionRatio, $unitConversionRate);
            $insertUnit->execute();
            $insertUnit->close();
        }
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

// لە مۆدی دەرمانخانەدا دڵنیاکردنەوە لە بوونی یەکەکانی پاکەت و شیت (تەنها یەک جار ئەگەر نەبوون)
if ($isPharmacyMode) {
    getOrCreateUnit($conn, $userId, 'پاکەت');
    getOrCreateUnit($conn, $userId, 'شیت');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $company_id = (int)($_POST['company_id'] ?? 0);
        $receipt_number = cleanInput($_POST['receipt_number'] ?? '');
        $payment_type = $_POST['payment_type'] ?? 'cash';
        // دراوی وەسڵ (هەموو کاڵا و قەرزی ئەم وەسڵە بەم دراوەن)
        $receipt_currency = (($_POST['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
        $receipt_debt_column = $receipt_currency === 'USD' ? 'debt_amount_usd' : 'debt_amount';
        $wallet_id = (int)($_POST['wallet_id'] ?? 0);
        $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d');
        $discount_type = $_POST['discount_type'] ?? 'amount'; // amount or percentage
        $discount_value = (float)($_POST['discount_value'] ?? 0);
        $discount_items_type = $_POST['discount_items_type'] ?? 'amount'; // amount or percentage بۆ کاڵاکان
        $discount_items_value = (float)($_POST['discount_items_value'] ?? 0);
        $additional_charges = (float)($_POST['additional_charges'] ?? 0);
        $notes = cleanInput($_POST['notes'] ?? '');

        // لە مۆدی دەرمانخانەدا داشکاندنی گشتی و داشکاندنی کاڵاکان بە فۆرمەکان ناچالن دەکەین
        // و تەنها داشکاندنی سەدی هەر کاڵا بەکاردەھێنین
        if ($isPharmacyMode) {
            $discount_type = 'amount';
            $discount_value = 0.0;
            $discount_items_type = 'amount';
            $discount_items_value = 0.0;
        }
        
        // Validation
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
        $parsedItems = purchaseReceiptBuildItemsFromPost($_POST, $isPharmacyMode, $errors, 'add');
        $items = $parsedItems['items'];
        $global_item_discount_percent = $parsedItems['global_item_discount_percent'];
        $total_amount = $parsedItems['total_amount'];
        $gross_total_amount = $parsedItems['gross_total_amount'];
        $submittedItems = purchaseReceiptParseRawRowsFromPost($_POST, $errors);
        
        // حیسابکردنی داشکاندنی کاڵاکان
        // مۆدی ئاسایی: هاوبەشکردنی داشکاندن لەسەر هەموو کاڵاکان و دابەشکردن بەپێی نرخی کاڵا
        // مۆدی دەرمانخانە: داشکاندنی سەدی بۆ هەر ڕیز (هەر کاڵایەک)
        $discount_items_amount = 0;
        if ($isPharmacyMode) {
            // داشکاندنی هەر ڕیز بەپێی ڕێژەی سەدی
            foreach ($items as &$item) {
                $qty = $item['quantity'] > 0 ? $item['quantity'] : 0;
                $grossLineTotal = $item['buy_price'] * $qty;
                $percent = isset($item['discount_percent']) ? (float)$item['discount_percent'] : 0.0;

                // سنووردارکردنی ڕێژە لەنێوان 0 و 100
                if ($percent < 0) {
                    $percent = 0;
                } elseif ($percent > 100) {
                    $percent = 100;
                }

                $lineDiscount = 0.0;
                if ($percent > 0 && $grossLineTotal > 0) {
                    $lineDiscount = ($grossLineTotal * $percent) / 100.0;
                }

                $item['discount_percent'] = $percent;
                $item['discount_amount'] = $lineDiscount;
                $discount_items_amount += $lineDiscount;

                $net_cost = $grossLineTotal - $lineDiscount;
                if ($net_cost < 0) {
                    $net_cost = 0;
                }

                $item['total_cost'] = $net_cost;

                // نرخی تێکڕای کڕین دوای داشکاندن بەپێی پاکەت و بۆنس
                if ($qty > 0) {
                    $packetBonus = isset($item['packet_bonus']) ? (float)$item['packet_bonus'] : 0;
                    $totalPackets = $qty + $packetBonus;
                    if ($totalPackets > 0) {
                        $item['effective_buy_price'] = $net_cost / $totalPackets;
                    }
                }
            }
            unset($item);
        } else {
            if ($discount_items_value > 0 && $gross_total_amount > 0) {
                if ($discount_items_type === 'percentage') {
                    $discount_items_amount = ($gross_total_amount * $discount_items_value) / 100;
                } else {
                    $discount_items_amount = min($discount_items_value, $gross_total_amount);
                }
            }

            // دابەشکردنی داشکاندنی کاڵاکان بەسەر هەر کاڵایەک و حیسابکردنی نرخی تێکڕای نوێ
            if ($discount_items_amount > 0 && $gross_total_amount > 0) {
                $distributed = 0;
                $lastIndex = count($items) - 1;
                foreach ($items as $index => &$item) {
                    $share = $item['total_cost'] / $gross_total_amount;
                    $item_discount = ($index === $lastIndex)
                        ? ($discount_items_amount - $distributed)
                        : ($discount_items_amount * $share);
                    $distributed += $item_discount;

                    $net_cost = $item['total_cost'] - $item_discount;
                    if ($net_cost < 0) {
                        $net_cost = 0;
                    }

                    $item['total_cost'] = $net_cost;

                    // نرخی تێکڕای کڕین دوای داشکاندن بۆ هەر کاڵا
                    $qty = $item['quantity'] > 0 ? $item['quantity'] : 0;
                    if ($qty > 0) {
                        $item['effective_buy_price'] = $net_cost / $qty;
                    }
                }
                unset($item);
            }
        }

        // حیسابکردنی داشکاندنی سەرەکی وەسڵ (تەنها بۆ مۆدی ئاسایی)
        $discount_header_amount = 0;
        if (!$isPharmacyMode && $discount_value > 0) {
            if ($discount_type === 'percentage') {
                $discount_header_amount = ($total_amount * $discount_value) / 100;
            } else {
                $discount_header_amount = $discount_value;
            }
        }

        $discount_amount = $discount_header_amount + $discount_items_amount;
        $final_amount = $total_amount - $discount_amount + $additional_charges;

        // ڕێژەی داشکاندنی سەرەکی وەسڵ لە خشتەی purchase_receipts
        $receipt_discount_percent = 0.0;
        if ($isPharmacyMode) {
            // لە مۆدی دەرمانخانەدا داشکاندن هەمیشە بە ڕێژەی سەدی دەستنیشان دەکرێت
            $receipt_discount_percent = $global_item_discount_percent;
        } elseif (!$isPharmacyMode && $discount_type === 'percentage' && $discount_value > 0) {
            // لە مۆدی ئاسایی تەنیا کاتێک هەڵگیراوە بە ئاستی سەدی
            $receipt_discount_percent = $discount_value;
        }
        
        // پڕۆسێسی وێنە
        $receipt_image = null;
        if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = uploadPurchaseReceiptImageToSpaces($_FILES['receipt_image'], $userId);
            
            if ($uploadResult['success']) {
                $receipt_image = $uploadResult['url'];
            } else {
                $errors = array_merge($errors, $uploadResult['errors']);
            }
        }
        
        if (empty($errors)) {
            try {
                $affectedProductIds = [];
                foreach ($items as $affectedItem) {
                    $pid = (int)($affectedItem['product_id'] ?? 0);
                    if ($pid > 0) {
                        $affectedProductIds[$pid] = $pid;
                    }
                }
                $beforeSnapshots = [];
                foreach ($affectedProductIds as $pid) {
                    $beforeSnapshots[$pid] = getProductSnapshotForLogs($conn, $userId, $pid);
                }
                $conn->begin_transaction();

                $useWeighted = getPurchasesUseWeightedAvgPrices($userId);
                $inventoryStrategy = $useWeighted ? 1 : 0;
                
                // تۆمارکردنی وەسڵەکە
                $stmt = $conn->prepare("
                    INSERT INTO purchase_receipts
                    (user_id, company_id, receipt_number, receipt_image, payment_type, wallet_id, receipt_date,
                     total_amount, discount_percent, discount_amount, additional_charges, final_amount, notes, inventory_price_strategy, currency)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param("iisssissddddsis",
                    $userId, $company_id, $receipt_number, $receipt_image, $payment_type, $wallet_id,
                    $receipt_date, $total_amount, $receipt_discount_percent, $discount_amount, $additional_charges, $final_amount, $notes, $inventoryStrategy, $receipt_currency
                );
                
                $stmt->execute();
                $receiptId = $conn->insert_id;
                
                $packet_unit_id = null;
                $sheet_unit_id = null;
                if ($isPharmacyMode) {
                    $packet_unit_id = getOrCreateUnit($conn, $userId, 'پاکەت');
                    $sheet_unit_id = getOrCreateUnit($conn, $userId, 'شیت');
                }
                $defaultUnitId = getDefaultUnitId($conn, $userId);
                
                $stmtItem = $conn->prepare("
                    INSERT INTO purchase_receipt_items 
                    (purchase_receipt_id, product_id, product_name, quantity, buy_price, sell_price, 
                     wholesale_price, special_price, expiry_date, total_cost, unit_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtItemPharmacy = null;
                if ($isPharmacyMode) {
                    $stmtItemPharmacy = $conn->prepare("
                        INSERT INTO purchase_receipt_items 
                        (purchase_receipt_id, product_id, product_name, quantity, buy_price, sell_price, 
                         wholesale_price, special_price, expiry_date, total_cost, unit_id, packet_bonus, sheets_per_packet, discount_amount) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                }
                
                foreach ($items as $item) {
                    $effectiveProductId = $item['product_id'];
                    $effectiveUnitId = $item['unit_id'];
                    $quantityForStock = $item['quantity'];
                    $conversionRateForStock = $item['conversion_rate'];
                    // نرخی تێکڕای کڕینی نوێ دوای داشکاندنی کاڵاکان (ئەگەر هەبوو)
                    $buyPriceForAvg = isset($item['effective_buy_price']) && $item['effective_buy_price'] > 0
                        ? $item['effective_buy_price']
                        : $item['buy_price'];
                    $sellPriceForAvg = $item['sell_price'];
                    
                    if ($isPharmacyMode) {
                        $effectiveUnitId = $packet_unit_id;
                        $packetBonus = $item['packet_bonus'] ?? 0;
                        $sheetsPerPacket = $item['sheets_per_packet'] ?? 0;
                        $totalPackets = $item['quantity'] + $packetBonus;
                        if ($totalPackets > 0) {
                            // لە مۆدی دەرمانخانە: دوای داشکاندنی کاڵاکان، total_cost نێتیە
                            $lineCostForAvg = isset($item['total_cost']) ? (float)$item['total_cost'] : ($item['quantity'] * $item['buy_price']);
                            $buyPriceForAvg = $lineCostForAvg / $totalPackets;
                        }
                        $quantityForStock = $totalPackets;
                        $conversionRateForStock = 1;
                    }

                    $item['effective_buy_price'] = $buyPriceForAvg;
                    $resolved = resolveOrCreateProductForPurchase(
                        $conn,
                        $userId,
                        $item,
                        $isPharmacyMode,
                        $defaultUnitId,
                        (int)($packet_unit_id ?? 0),
                        $receipt_currency
                    );
                    $effectiveProductId = (int)$resolved['product_id'];
                    $effectiveUnitId = (int)$resolved['unit_id'];
                    $item['product_id'] = $effectiveProductId;

                    // وێنەی کاڵا: ئەگەر بەکارهێنەر وێنەی بۆ ئەم کاڵا بارکردبوو و کاڵاکە وێنەی نەبوو
                    purchaseReceiptApplyItemProductImage($conn, (int)$userId, $effectiveProductId, (string)($item['image_key'] ?? ''));

                    if ($isPharmacyMode && $sheet_unit_id && !empty($item['sheets_per_packet']) && (int)$item['sheets_per_packet'] > 0) {
                        $checkSheetUnit = $conn->prepare("SELECT id FROM product_units WHERE product_id = ? AND unit_id = ? LIMIT 1");
                        $checkSheetUnit->bind_param("ii", $effectiveProductId, $sheet_unit_id);
                        $checkSheetUnit->execute();
                        $existingSheetUnit = $checkSheetUnit->get_result()->fetch_assoc();
                        $checkSheetUnit->close();
                        if (!$existingSheetUnit) {
                            $sheetsPerPacket = (int)$item['sheets_per_packet'];
                            $sheetBuy = isset($item['sheet_buy_price']) ? (float)$item['sheet_buy_price'] : ($buyPriceForAvg / $sheetsPerPacket);
                            $sheetSell = isset($item['sheet_sell_price']) ? (float)$item['sheet_sell_price'] : ($sellPriceForAvg / $sheetsPerPacket);
                            $sheetConversionRatio = 1.0 / $sheetsPerPacket;
                            $sheetConversionRate = 1.0 / $sheetsPerPacket;
                            $sheetWholesale = 0.0;
                            $sheetSpecial = 0.0;
                            $insPuSheet = $conn->prepare("
                                INSERT INTO product_units (product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0)
                            ");
                            $insPuSheet->bind_param("iiddddsdd", $effectiveProductId, $sheet_unit_id, $sheetBuy, $sheetSell, $sheetWholesale, $sheetSpecial, $receipt_currency, $sheetConversionRatio, $sheetConversionRate);
                            $insPuSheet->execute();
                            $insPuSheet->close();
                        }
                    }
                    
                    $expiryDate = $item['expiry_date'] ?: null;
                    if ($isPharmacyMode && $stmtItemPharmacy) {
                        $packetBonus = $item['packet_bonus'] ?? 0;
                        $sheetsPerPacket = (int)($item['sheets_per_packet'] ?? 0);
                        $discountAmount = isset($item['discount_amount']) ? (float)$item['discount_amount'] : 0.0;
                        $stmtItemPharmacy->bind_param(
                            "iisdddddsdiddd",
                            $receiptId, $effectiveProductId, $item['product_name'], $item['quantity'],
                            $item['buy_price'], $item['sell_price'], $item['wholesale_price'],
                            $item['special_price'], $expiryDate, $item['total_cost'], $effectiveUnitId,
                            $packetBonus, $sheetsPerPacket, $discountAmount
                        );
                        if (!$stmtItemPharmacy->execute()) {
                            throw new Exception('هەڵە لە زیادکردنی کاڵا: ' . $item['product_name']);
                        }
                    } else {
                        $stmtItem->bind_param(
                            "iisdddddsdi",
                            $receiptId, $effectiveProductId, $item['product_name'], $item['quantity'],
                            $item['buy_price'], $item['sell_price'], $item['wholesale_price'],
                            $item['special_price'], $expiryDate, $item['total_cost'], $effectiveUnitId
                        );
                        if (!$stmtItem->execute()) {
                            throw new Exception('هەڵە لە زیادکردنی کاڵا: ' . $item['product_name']);
                        }
                    }

                    $purchaseItemId = (int)$conn->insert_id;
                    
                    // نوێکردنەوەی کۆگا (بەپێی conversion_rate یان بۆ دەرمانخانە: بڕ+بۆنس)
                    if ($effectiveProductId > 0) {
                        $quantityToAdd = $quantityForStock * $conversionRateForStock;
                        $unitQuantityToAdd = $isPharmacyMode ? $quantityForStock : $item['quantity'];
                        
                        // Product-level stock/price columns were removed; inventory and pricing are managed in product_units only.
                        
                        // نوێکردنەوەی بەرواری بەسەرچوون بۆ کاڵا ئەگەر بەروار هەبێت
                        if (!empty($item['expiry_date']) && $item['expiry_date'] !== null) {
                            $updateExpiryStmt = $conn->prepare("
                                UPDATE products SET 
                                    expiry_date = ?
                                WHERE id = ? AND user_id = ?
                            ");
                            $updateExpiryStmt->bind_param("sii", 
                                $item['expiry_date'], 
                                $effectiveProductId, 
                                $userId
                            );
                            $updateExpiryStmt->execute();
                        }
                        
                        // نوێکردنەوەی کۆگای یەکەی کاڵا
                        if ($effectiveUnitId > 0) {
                            // وەرگرتنی نرخەکان و بڕی کۆگای پێشووی یەکە
                            $getUnitPriceStmt = $conn->prepare("
                                SELECT buy_price, sell_price, wholesale_price, special_price, stock_quantity, currency
                                FROM product_units
                                WHERE product_id = ? AND unit_id = ?
                            ");
                            $getUnitPriceStmt->bind_param("ii", $effectiveProductId, $effectiveUnitId);
                            $getUnitPriceStmt->execute();
                            $currentUnit = $getUnitPriceStmt->get_result()->fetch_assoc();
                            
                            if (!$useWeighted && $purchaseItemId > 0 && $currentUnit) {
                                $revB = (float)$currentUnit['buy_price'];
                                $revS = (float)$currentUnit['sell_price'];
                                $revW = (float)$currentUnit['wholesale_price'];
                                $revSp = (float)$currentUnit['special_price'];
                                $updRev = $conn->prepare("
                                    UPDATE purchase_receipt_items SET
                                        revert_buy_price = ?, revert_sell_price = ?, revert_wholesale_price = ?, revert_special_price = ?
                                    WHERE id = ?
                                ");
                                $updRev->bind_param("ddddi", $revB, $revS, $revW, $revSp, $purchaseItemId);
                                $updRev->execute();
                                $updRev->close();
                            }

                            $updateUnitStockStmt = $conn->prepare("
                                UPDATE product_units SET 
                                    stock_quantity = stock_quantity + ?
                                WHERE product_id = ? AND unit_id = ?
                            ");
                            
                            $updateUnitStockStmt->bind_param(
                                "dii",
                                $unitQuantityToAdd, 
                                $effectiveProductId, 
                                $effectiveUnitId
                            );
                            $updateUnitStockStmt->execute();
                            
                            // حیسابکردن و نوێکردنەوەی نرخەکان: تێکڕا یان جێگیر
                            if ($currentUnit) {
                                $Q_old_unit = (float)$currentUnit['stock_quantity'];
                                $Q_new_unit = $unitQuantityToAdd;
                                
                                if (($Q_old_unit + $Q_new_unit) > 0) {
                                    // ئەگەر دراوی یەکەی کۆن جیاواز بێت لە دراوی وەسڵ، ناکرێت تێکڕا بکرێن
                                    // (نرخی دینار و دۆلار تێکەڵ ناکرێن) — لەبری ئەوە نرخی نوێ دادەنرێت
                                    $unitCurrencyMismatch = (($currentUnit['currency'] ?? 'IQD') !== $receipt_currency);
                                    if ($useWeighted && !$unitCurrencyMismatch) {
                                        $P_old_unit_buy = (float)$currentUnit['buy_price'];
                                        $P_new_unit_buy = $buyPriceForAvg;
                                        $newWeightedAverageUnitBuy = (($Q_old_unit * $P_old_unit_buy) + ($Q_new_unit * $P_new_unit_buy)) / ($Q_old_unit + $Q_new_unit);
                                        
                                        $P_old_unit_sell = (float)$currentUnit['sell_price'];
                                        $P_new_unit_sell = $sellPriceForAvg;
                                        $newWeightedAverageUnitSell = (($Q_old_unit * $P_old_unit_sell) + ($Q_new_unit * $P_new_unit_sell)) / ($Q_old_unit + $Q_new_unit);
                                        
                                        $P_old_unit_wholesale = (float)$currentUnit['wholesale_price'];
                                        $P_new_unit_wholesale = (float)$item['wholesale_price'];
                                        $newWeightedAverageUnitWholesale = (($Q_old_unit * $P_old_unit_wholesale) + ($Q_new_unit * $P_new_unit_wholesale)) / ($Q_old_unit + $Q_new_unit);
                                        
                                        $P_old_unit_special = (float)$currentUnit['special_price'];
                                        $P_new_unit_special = (float)$item['special_price'];
                                        $newWeightedAverageUnitSpecial = (($Q_old_unit * $P_old_unit_special) + ($Q_new_unit * $P_new_unit_special)) / ($Q_old_unit + $Q_new_unit);
                                    } else {
                                        $newWeightedAverageUnitBuy = (float)$buyPriceForAvg;
                                        $newWeightedAverageUnitSell = (float)$sellPriceForAvg;
                                        $newWeightedAverageUnitWholesale = (float)$item['wholesale_price'];
                                        $newWeightedAverageUnitSpecial = (float)$item['special_price'];
                                    }
                                    
                                    $updateUnitPriceStmt = $conn->prepare("
                                        UPDATE product_units SET
                                            buy_price = ?,
                                            sell_price = ?,
                                            wholesale_price = ?,
                                            special_price = ?,
                                            currency = ?
                                        WHERE product_id = ? AND unit_id = ?
                                    ");
                                    $updateUnitPriceStmt->bind_param("ddddsii",
                                        $newWeightedAverageUnitBuy,
                                        $newWeightedAverageUnitSell,
                                        $newWeightedAverageUnitWholesale,
                                        $newWeightedAverageUnitSpecial,
                                        $receipt_currency,
                                        $effectiveProductId,
                                        $effectiveUnitId
                                    );
                                    $updateUnitPriceStmt->execute();
                                }
                            }
                        }
                        
                        // هاوکاتکردنی (sync) یەکەکانی تری ئەم کاڵایە بەپێی conversion_ratio — هاوشێوەی api/sales.php
                        // تەنها مۆدی ئاسایی: لە مۆدی دەرمانخانە پاکەت/شیت بە دەستی مامەڵە دەکرێن (بۆ ڕێگری لە دووجار ژماردن).
                        if (!$isPharmacyMode && $effectiveUnitId > 0) {
                            $txnRatioStmt = $conn->prepare("
                                SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?
                            ");
                            $txnRatioStmt->bind_param("ii", $effectiveProductId, $effectiveUnitId);
                            $txnRatioStmt->execute();
                            $txnRatioRow = $txnRatioStmt->get_result()->fetch_assoc();
                            $txn_unit_ratio = $txnRatioRow['conversion_ratio'] ?? null;

                            if ($txn_unit_ratio === null || (float)$txn_unit_ratio <= 0) {
                                error_log("Purchase stock sync skipped (add): invalid txn conversion_ratio for product_id=$effectiveProductId, unit_id=$effectiveUnitId");
                            } else {
                                $otherUnitsStmt = $conn->prepare("
                                    SELECT unit_id, conversion_ratio
                                    FROM product_units
                                    WHERE product_id = ? AND unit_id != ?
                                ");
                                $otherUnitsStmt->bind_param("ii", $effectiveProductId, $effectiveUnitId);
                                $otherUnitsStmt->execute();
                                $otherUnitsResult = $otherUnitsStmt->get_result();

                                while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                                    $other_unit_id = (int)$otherUnit['unit_id'];
                                    $other_unit_ratio = $otherUnit['conversion_ratio'];

                                    // پارێزەری دابەشکردن بەسەر سفر
                                    if ($other_unit_ratio === null || (float)$other_unit_ratio <= 0) {
                                        error_log("Purchase stock sync skipped for unit_id=$other_unit_id (product_id=$effectiveProductId): invalid conversion_ratio");
                                        continue;
                                    }

                                    // فۆرمولا: بڕی هاوکات = بڕ × (ratioی یەکەی کڕدراو ÷ ratioی یەکەی تر)
                                    $sync_amount = $unitQuantityToAdd * ((float)$txn_unit_ratio / (float)$other_unit_ratio);

                                    $updSyncStmt = $conn->prepare("
                                        UPDATE product_units SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                                        WHERE product_id = ? AND unit_id = ?
                                    ");
                                    $updSyncStmt->bind_param("dii", $sync_amount, $effectiveProductId, $other_unit_id);
                                    $updSyncStmt->execute();
                                }
                            }
                        }

                        // بۆ دەرمانخانە: نوێکردنەوەی کۆگای یەکەی شیت ئەگەر sheets_per_packet > 0
                        if ($isPharmacyMode && $sheet_unit_id && !empty($item['sheets_per_packet']) && (int)$item['sheets_per_packet'] > 0) {
                            $sheetQtyToAdd = $quantityForStock * (int)$item['sheets_per_packet'];
                            $sheetBuy = isset($item['sheet_buy_price']) ? (float)$item['sheet_buy_price'] : ($buyPriceForAvg / (int)$item['sheets_per_packet']);
                            $sheetSell = isset($item['sheet_sell_price']) ? (float)$item['sheet_sell_price'] : ($sellPriceForAvg / (int)$item['sheets_per_packet']);
                            $getSheetUnitStmt = $conn->prepare("SELECT stock_quantity, buy_price, sell_price FROM product_units WHERE product_id = ? AND unit_id = ?");
                            $getSheetUnitStmt->bind_param("ii", $effectiveProductId, $sheet_unit_id);
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

                            $upSheetStock = $conn->prepare("UPDATE product_units SET stock_quantity = stock_quantity + ? WHERE product_id = ? AND unit_id = ?");
                            $upSheetStock->bind_param("dii", $sheetQtyToAdd, $effectiveProductId, $sheet_unit_id);
                            $upSheetStock->execute();
                            $upSheetStock->close();
                            if ($currentSheetUnit && ((float)$currentSheetUnit['stock_quantity'] + $sheetQtyToAdd) > 0) {
                                $Q_old_s = (float)$currentSheetUnit['stock_quantity'];
                                if ($useWeighted) {
                                    $newAvgBuy = ($Q_old_s * (float)$currentSheetUnit['buy_price'] + $sheetQtyToAdd * $sheetBuy) / ($Q_old_s + $sheetQtyToAdd);
                                    $newAvgSell = ($Q_old_s * (float)$currentSheetUnit['sell_price'] + $sheetQtyToAdd * $sheetSell) / ($Q_old_s + $sheetQtyToAdd);
                                } else {
                                    $newAvgBuy = (float)$sheetBuy;
                                    $newAvgSell = (float)$sheetSell;
                                }
                                $upSheetPrice = $conn->prepare("UPDATE product_units SET buy_price = ?, sell_price = ?, wholesale_price = 0, special_price = 0 WHERE product_id = ? AND unit_id = ?");
                                $upSheetPrice->bind_param("ddii", $newAvgBuy, $newAvgSell, $effectiveProductId, $sheet_unit_id);
                                $upSheetPrice->execute();
                                $upSheetPrice->close();
                            }
                        }
                    }
                }
                
                // ئەگەر وەسڵەکە قەرزە: نوێکردنەوەی قەرزی کۆمپانیا و تۆمار لە company_debts
                if ($payment_type === 'debt' && $company_id > 0) {
                    $applyDebtStmt = $conn->prepare("
                        UPDATE companies
                        SET {$receipt_debt_column} = {$receipt_debt_column} + ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $applyDebtStmt->bind_param("dii", $final_amount, $company_id, $userId);
                    if (!$applyDebtStmt->execute()) {
                        throw new Exception('هەڵە لە زیادکردنی قەرزی کۆمپانیا');
                    }
                    $debtDescription = 'وەسڵی کڕین #' . ($receipt_number ?: $receiptId);
                    $insertDebtStmt = $conn->prepare("
                        INSERT INTO company_debts (user_id, company_id, purchase_receipt_id, amount, currency, description, type, date)
                        VALUES (?, ?, ?, ?, ?, ?, 'debt', ?)
                    ");
                    $insertDebtStmt->bind_param("iiidsss", $userId, $company_id, $receiptId, $final_amount, $receipt_currency, $debtDescription, $receipt_date);
                    if (!$insertDebtStmt->execute()) {
                        throw new Exception('هەڵە لە تۆمارکردنی قەرزی کۆمپانیا');
                    }
                }

                if ($payment_type === 'cash') {
                    if (!walletPostEntry(
                        $conn,
                        (int)$userId,
                        (int)$wallet_id,
                        'purchase_out',
                        'out',
                        $receipt_currency,
                        (float)$final_amount,
                        'purchase_receipt',
                        (int)$receiptId,
                        'Purchase receipt outflow',
                        (int)$userId
                    )) {
                        throw new Exception('هەڵە لە تۆمارکردنی جوڵەی قاسە');
                    }
                }
                
                $conn->commit();
                foreach ($affectedProductIds as $pid) {
                    $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $pid);
                    logProductChangeEvent(
                        'purchase_receipt.create',
                        'purchase_receipt',
                        $receiptId,
                        $beforeSnapshots[$pid] ?? null,
                        $afterSnapshot,
                        [
                            'user_id' => $userId,
                            'current_user' => $currentUser,
                            'product_id' => $pid,
                            'source_module' => 'user/purchases/add.php',
                            'source_reference' => (string)$receiptId
                        ]
                    );
                }
                
                // Log activity for analytics
                logActivity(
                    "وەسڵی کڕین تۆمارکرا - ژمارەی وەسڵ: $receipt_number",
                    "وەسڵی کڕین بە سەرکەوتوویی تۆمارکرا - کۆمپانیا: $company_id - کۆی بڕ: $final_amount دینار"
                );
                
                $success = true;
                $saveAction = $_POST['save_action'] ?? 'save';
                setMessage('وەسڵەکە بە سەرکەوتوویی تۆمار کرا', 'success');
                if ($saveAction === 'continue') {
                    // پاشکەوتکردن و بەردەوامبوون: مانەوە لەسەر هەمان وەسڵ بۆ بەردەوامبوون لە دەستکاریکردنی
                    redirect(url("user/purchases/edit.php?id=$receiptId"));
                } else {
                    redirect(url("user/purchases/view.php?id=$receiptId"));
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'هەڵەیەک ڕوویدا: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'تۆمارکردنی وەسڵی کڕدراو';
$bodyClass = 'purchases-module-page purchases-add-page';
$additionalCSS = ['purchases/purchases-pages.css'];
include '../../includes/header.php';
?>

<style>
/* ============================================================
   دیزاینی نوێی وەسڵی کڕدراو — Add  (Comfortable · Indigo accent)
   ============================================================ */
.receipt-add-container {
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
.receipt-add-container .receipt-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.receipt-add-container .receipt-header {
    position: relative;
    background: var(--surface);
    color: var(--text);
    padding: 22px 26px;
    border-bottom: 1px solid var(--border);
}
.receipt-add-container .receipt-header::before {
    content: "";
    position: absolute;
    inset: 0 0 auto 0;
    height: 4px;
    background: var(--grad);
}
.receipt-add-container .receipt-header h4 {
    font-weight: 800;
    letter-spacing: -.2px;
    display: flex;
    align-items: center;
    gap: .6rem;
    margin-bottom: .2rem;
}
.receipt-add-container .receipt-header h4 i {
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
.receipt-add-container .receipt-header p { color: var(--muted); }

/* ---------- خانەکانی فۆڕم ---------- */
.receipt-add-container .form-label {
    font-weight: 600;
    color: #344054;
    margin-bottom: .45rem;
    font-size: .9rem;
}
.receipt-add-container .form-label i { color: var(--accent); }

.receipt-add-container .form-control,
.receipt-add-container .form-select {
    border: 1.5px solid var(--border-strong);
    border-radius: var(--radius-sm);
    padding: 12px 15px;
    background: var(--surface);
    color: var(--text);
    transition: border-color var(--ease), box-shadow var(--ease), background var(--ease);
}
.receipt-add-container .form-control::placeholder { color: #9aa3b2; }
.receipt-add-container .form-control:hover,
.receipt-add-container .form-select:hover { border-color: #b9c2d4; }
.receipt-add-container .form-control:focus,
.receipt-add-container .form-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 4px var(--accent-ring);
    background: var(--surface);
}
.receipt-add-container textarea.form-control { min-height: 90px; }

/* sub-card بۆ گرووپی خانەکان */
.receipt-add-container .form-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 18px 18px 4px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
}
.receipt-add-container .section-title {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-weight: 700;
    font-size: .98rem;
    color: var(--text);
    margin: 0 0 16px;
}
.receipt-add-container .section-title i { color: var(--accent); }

/* ---------- دوگمەکان ---------- */
.receipt-add-container .btn {
    border-radius: var(--radius-sm);
    font-weight: 600;
    transition: transform var(--ease), box-shadow var(--ease), filter var(--ease), background var(--ease), border-color var(--ease), color var(--ease);
}
.receipt-add-container .btn:not(.btn-sm) { padding: 11px 22px; }
.receipt-add-container .btn:active { transform: translateY(0) scale(.99); }
.receipt-add-container .btn-primary {
    background: var(--grad);
    border: none;
    color: #fff;
    box-shadow: 0 6px 16px rgba(102,126,234,.35);
}
.receipt-add-container .btn-primary:hover {
    filter: brightness(1.05);
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(102,126,234,.42);
}
.receipt-add-container .btn-outline-primary { color: var(--accent-2); border-color: var(--accent); }
.receipt-add-container .btn-outline-primary:hover { background: var(--accent); border-color: var(--accent); color: #fff; transform: translateY(-1px); }
.receipt-add-container .btn-success:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(25,135,84,.3); }

/* ---------- کۆنتەینەری کاڵاکان ---------- */
.receipt-add-container .items-container {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 20px;
    margin: 22px 0;
}
.receipt-add-container .items-container h5 {
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: .45rem;
}
.receipt-add-container .items-container h5 i { color: var(--accent); }

/* ---------- ڕیزی کاڵا ---------- */
.receipt-add-container .item-row {
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
.receipt-add-container .item-row:last-child { margin-bottom: 0; }
.receipt-add-container .item-row:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.receipt-add-container .item-row:focus-within {
    border-inline-start-color: var(--accent-2);
    box-shadow: 0 0 0 4px var(--accent-ring), var(--shadow-md);
}

.receipt-add-container .item-row .form-control,
.receipt-add-container .item-row .form-select {
    font-size: .92rem;
    padding: 10px 13px;
    border: 1.5px solid var(--border-strong);
    border-radius: 10px;
}
.receipt-add-container .item-row .form-control:focus,
.receipt-add-container .item-row .form-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-ring);
}
.receipt-add-container .item-row .form-label {
    font-weight: 600;
    color: #475467;
    margin-bottom: 6px;
    font-size: .82rem;
}

/* ژمارەی ڕیز وەک badge */
.receipt-add-container .product-name-label .item-row-number {
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
.receipt-add-container .pharmacy-item-row .row + .row { margin-top: .85rem; }

/* خانە تەنها-خوێندنەوەکان */
.receipt-add-container .item-row .bg-light,
.receipt-add-container .avg-buy-after-bonus-discount,
.receipt-add-container .sheet-buy-price {
    background: var(--surface-3) !important;
    border-style: dashed !important;
    color: #475467;
    font-weight: 600;
}

/* دوگمەی سڕینەوە */
.receipt-add-container .delete-item.btn { font-weight: 600; }
.receipt-add-container .delete-item:hover { transform: translateY(-1px); }

/* ---------- پانێلی کورتە ---------- */
.receipt-add-container .floating-summary { position: sticky; top: 20px; z-index: 100; }

.receipt-add-container .summary-card {
    background: var(--grad);
    color: #fff;
    border-radius: var(--radius-md);
    padding: 22px;
    box-shadow: 0 16px 34px rgba(102,126,234,.35);
}
.receipt-add-container .summary-card h5 { font-weight: 700; display: flex; align-items: center; gap: .45rem; }
.receipt-add-container .summary-card .d-flex { font-size: .95rem; }
.receipt-add-container .summary-card hr { border-color: rgba(255,255,255,.4); opacity: 1; }
.receipt-add-container .summary-card #finalTotal,
.receipt-add-container .summary-card strong { font-size: 1.18rem; }

.receipt-add-container .floating-summary .card {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    background: var(--surface);
}
.receipt-add-container .floating-summary .card-header {
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    color: var(--text);
}
.receipt-add-container .floating-summary .card-header i { color: var(--accent); }

/* ---------- وێنە ---------- */
.receipt-add-container .image-preview {
    max-width: 220px;
    max-height: 220px;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border);
}

/* ---------- autocomplete ---------- */
.receipt-add-container .autocomplete-container { position: relative; }
.receipt-add-container .autocomplete-suggestions {
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
.receipt-add-container .autocomplete-suggestion {
    padding: 10px 12px;
    cursor: pointer;
    border-radius: 9px;
    transition: background var(--ease);
}
.receipt-add-container .autocomplete-suggestion:hover,
.receipt-add-container .autocomplete-suggestion.active { background: var(--accent-soft); }

/* ---------- وێنەی کاڵا لە ڕیزەکاندا ---------- */
.receipt-add-container .product-image-cell {
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
.receipt-add-container .product-image-thumb {
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
.receipt-add-container .product-image-thumb:hover {
    border-color: var(--accent);
    box-shadow: 0 0 0 4px var(--accent-ring);
    transform: translateY(-1px);
}
.receipt-add-container .product-image-cell[data-mode="view"] .product-image-thumb {
    cursor: pointer;
    border-style: solid;
    border-color: var(--border);
}
.receipt-add-container .product-image-cell[data-mode="view"] .product-image-thumb::after {
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
.receipt-add-container .product-image-cell[data-mode="view"] .product-image-thumb:hover::after {
    opacity: 1;
}
.receipt-add-container .product-image-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: none;
}
.receipt-add-container .product-image-placeholder {
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
.receipt-add-container .product-image-placeholder i {
    font-size: 1.3rem;
    color: var(--accent);
}
.receipt-add-container .product-image-remove {
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
.receipt-add-container .product-image-remove:hover { background: #b02a37; }
.receipt-add-container .product-image-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.receipt-add-container .product-image-info .pi-title {
    font-weight: 700;
    font-size: .85rem;
    color: #344054;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.receipt-add-container .product-image-info .pi-title i { color: var(--accent); }
.receipt-add-container .product-image-info .pi-hint {
    font-size: .74rem;
    color: var(--muted);
}
.receipt-add-container .product-image-badge {
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
html[data-bs-theme='dark'] .receipt-add-container .product-image-info .pi-title { color: #c3ccda; }
html[data-bs-theme='dark'] .receipt-add-container .product-image-badge {
    color: #34d399;
    background: rgba(16,185,129,.18);
}

/* ---------- ڕێسپۆنسیڤ ---------- */
@media (max-width: 991.98px) {
    .receipt-add-container .floating-summary { position: static; margin-top: 8px; }
}
@media (max-width: 768px) {
    .receipt-add-container { padding: 14px 0 40px; }
    .receipt-add-container .receipt-card { border-radius: var(--radius-md); }
    .receipt-add-container .item-row { padding: 16px; }
    .receipt-add-container .receipt-header { padding: 18px; }
}

/* ============================================================
   دۆخی تاریک
   ============================================================ */
html[data-bs-theme='dark'] .receipt-add-container {
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
html[data-bs-theme='dark'] .receipt-add-container .form-label { color: #c3ccda; }
html[data-bs-theme='dark'] .receipt-add-container .item-row .form-label { color: #9fb0c7; }
html[data-bs-theme='dark'] .receipt-add-container .text-muted,
html[data-bs-theme='dark'] .receipt-add-container .form-text { color: var(--muted) !important; }
html[data-bs-theme='dark'] .receipt-add-container .form-control,
html[data-bs-theme='dark'] .receipt-add-container .form-select {
    background: var(--surface-2);
    color: var(--text);
    border-color: var(--border-strong);
}
html[data-bs-theme='dark'] .receipt-add-container .form-control::placeholder { color: #64748b; }
html[data-bs-theme='dark'] .receipt-add-container .item-row .bg-light,
html[data-bs-theme='dark'] .receipt-add-container .avg-buy-after-bonus-discount,
html[data-bs-theme='dark'] .receipt-add-container .sheet-buy-price {
    background: var(--surface-3) !important;
    color: #c3ccda;
}
</style>

<div class="receipt-add-container">
    <div class="container">
        <div class="row">
            <div class="col-lg-8">
                <div class="receipt-card">
                    <div class="receipt-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <div class="pu-kicker"><i class="bi bi-bag-plus"></i> بەشی کڕین</div>
                                <h4 class="mb-1">
                                    <i class="bi bi-receipt-cutoff"></i>
                                    تۆمارکردنی وەسڵی کڕدراو
                                </h4>
                                <p class="mb-0">زیادکردنی وەسڵێکی نوێ بۆ کۆمپانیا و کاڵاکان</p>
                            </div>
                            <div class="pu-actions">
                                <a href="<?php echo url('user/purchases/index.php'); ?>" class="pu-btn pu-btn-ghost">
                                    <i class="bi bi-arrow-right"></i> گەڕانەوە
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

                        <form method="POST" enctype="multipart/form-data" id="addReceiptForm">
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
                                                    <?php echo (isset($_POST['company_id']) && $_POST['company_id'] == $company['id']) ? 'selected' : ''; ?>>
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
                                               value="<?php echo htmlspecialchars($_POST['receipt_number'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="payment_type" class="form-label">
                                            <i class="bi bi-credit-card"></i>
                                            جۆری پارەدان
                                        </label>
                                        <select name="payment_type" id="payment_type" class="form-select">
                                            <option value="cash" <?php echo (isset($_POST['payment_type']) && $_POST['payment_type'] == 'cash') ? 'selected' : 'selected'; ?>>نەقد</option>
                                            <option value="debt" <?php echo (isset($_POST['payment_type']) && $_POST['payment_type'] == 'debt') ? 'selected' : ''; ?>>قەرز</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="wallet_id" class="form-label">
                                            <i class="bi bi-wallet2"></i>
                                            قاسە
                                        </label>
                                        <select name="wallet_id" id="wallet_id" class="form-select">
                                            <option value="">قاسە هەڵبژێرە</option>
                                            <?php foreach ($wallets as $wallet): ?>
                                                <option value="<?php echo (int)$wallet['id']; ?>" <?php echo ((int)($_POST['wallet_id'] ?? 0) === (int)$wallet['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string)$wallet['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">تەنها بۆ پارەدانی نەقد پێویستە</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="receipt_date" class="form-label">
                                            <i class="bi bi-calendar"></i>
                                            بەروار <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" class="form-control" name="receipt_date" id="receipt_date"
                                               value="<?php echo $_POST['receipt_date'] ?? date('Y-m-d'); ?>" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="receipt_currency" class="form-label">
                                            <i class="bi bi-currency-exchange"></i>
                                            دراوی وەسڵ <span class="text-danger">*</span>
                                        </label>
                                        <select name="currency" id="receipt_currency" class="form-select">
                                            <option value="IQD" <?php echo (($_POST['currency'] ?? 'IQD') === 'USD') ? '' : 'selected'; ?>>دینار (IQD)</option>
                                            <option value="USD" <?php echo (($_POST['currency'] ?? 'IQD') === 'USD') ? 'selected' : ''; ?>>دۆلار (USD)</option>
                                        </select>
                                        <small class="text-muted">هەموو نرخەکان و قەرزی ئەم وەسڵە بەم دراوە دەبن</small>
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
                                       onchange="previewImageAdd(this)">
                                
                                <div class="form-text">
                                    <i class="bi bi-info-circle"></i>
                                    سنووری قەبارە: 5MB | جۆرەکانی پشتگیریکراو: JPG, PNG, GIF, WEBP
                                </div>
                                <div id="file-size-error-add" class="text-danger mt-2" style="display: none;">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <span id="file-size-message-add"></span>
                                </div>
                                
                                <div id="image_preview_add" style="display: none;" class="mt-2">
                                    <img id="preview_img_add" src="" alt="وێنەی نوێ" class="image-preview">
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

                                <?php $initialImageKey = 'img_' . bin2hex(random_bytes(6)); ?>
                                <div id="itemsContainer">
                                    <?php if ($isPharmacyMode): ?>
                                    <!-- ڕیزی دەرمانخانە -->
                                    <div class="item-row pharmacy-item-row" data-index="0" data-pharmacy="1">
                                        <input type="hidden" name="unit_id[]" value="0">
                                        <input type="hidden" name="conversion_rate[]" class="conversion-rate" value="1">

                                        <!-- وێنەی کاڵا -->
                                        <div class="product-image-cell" data-mode="edit">
                                            <input type="hidden" name="image_key[]" value="<?php echo $initialImageKey; ?>">
                                            <input type="file" class="product-image-input d-none" name="product_image[<?php echo $initialImageKey; ?>]" accept="image/*">
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

                                        <!-- ڕیزی یەکەم: ناو، بارکۆد، کەتەلۆگ -->
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

                                        <!-- ڕیزی دووەم: بڕ، شیت، بۆنس، داشکاندن -->
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

                                        <!-- ڕیزی سێیەم: نرخی کڕین ، نرخی تێکڕای کڕینی پاکەت ، نرخی تێکڕای کڕینی شیت -->
                                        <div class="row g-2 align-items-end mt-2 pharmacy-sheet-block" style="display: flex;">
                                            <div class="col-md-2">
                                                <label class="form-label">نرخی کڕین <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control buy-price step-1000" name="buy_price[]" step="any" min="0" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">نرخی تێکڕای کڕینی پاکەت</label>
                                                <input type="text" class="form-control bg-light avg-buy-after-bonus-discount" readonly tabindex="-1" title="نرخی تێکڕای کڕینی پاکەت دوای بۆنس و داشکاندن">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">نرخی تێکڕای کڕینی شیت</label>
                                                <input type="text" class="form-control bg-light sheet-buy-price" name="sheet_buy_price[]" readonly tabindex="-1">
                                            </div>
                                        </div>

                                        <!-- ڕیزی چوارەم: نرخی فرۆشتنی پاکەت ، نرخی جوملەی پاکەت ، نرخی تایبەت پاکەت -->
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

                                        <!-- ڕیزی پێنجەم: نرخی فرۆشتنی شیت ، بەرواری بەسەرچوون -->
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
                                        <!-- وێنەی کاڵا -->
                                        <div class="product-image-cell" data-mode="edit">
                                            <input type="hidden" name="image_key[]" value="<?php echo $initialImageKey; ?>">
                                            <input type="file" class="product-image-input d-none" name="product_image[<?php echo $initialImageKey; ?>]" accept="image/*">
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
                                            <div class="col-md-3">
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
                            <?php if (!$isPharmacyMode): ?>
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
                                               value="<?php echo $_POST['discount_value'] ?? 0; ?>" step="0.001" min="0">
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label for="additional_charges" class="form-label">
                                            <i class="bi bi-plus-circle"></i>
                                            کرێی زیادە (گواستنەوە، بار، هتد)
                                        </label>
                                        <input type="number" class="form-control" name="additional_charges" id="additional_charges" 
                                               value="<?php echo $_POST['additional_charges'] ?? 0; ?>" step="0.001" min="0">
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
                                          placeholder="تێبینی یان وەسفی زیادە"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                            </div>
                            </div>

                            <!-- دوگمەکان -->
                            <div class="d-flex justify-content-between">
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="submit" name="save_action" value="save" class="btn btn-primary" id="saveBtn">
                                        <i class="bi bi-check-circle"></i>
                                        تۆمارکردن
                                    </button>
                                    <button type="submit" name="save_action" value="continue" class="btn btn-outline-primary" id="saveContinueBtn">
                                        <i class="bi bi-plus-circle"></i>
                                        پاشکەوتکردن و بەردەوامبوون
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
                            <span id="itemsCount">0</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>کۆی گشتی:</span>
                            <span id="subtotal">0 IQD</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>داشکاندن:</span>
                            <span id="discountDisplay">-0 IQD</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>کرێی زیادە:</span>
                            <span id="chargesDisplay">+0 IQD</span>
                        </div>
                        
                        <hr class="bg-white">
                        
                        <div class="d-flex justify-content-between">
                            <strong>کۆی کۆتایی:</strong>
                            <strong id="finalTotal">0 IQD</strong>
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
                            <p class="text-muted mb-0">کۆمپانیا هەڵنەبژێردراوە</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// داتای کاڵاکان بۆ autocomplete
const productsArray = <?php echo json_encode($products); ?>;
const companies = <?php echo json_encode($companies); ?>;
const isPharmacyMode = <?php echo $isPharmacyMode ? 'true' : 'false'; ?>;
const categories = <?php echo json_encode($categories); ?>;
const userUnits = <?php echo json_encode($userUnits); ?>;
const submittedItems = <?php echo json_encode($submittedItems, JSON_UNESCAPED_UNICODE); ?>;
const csrfRefreshUrl = <?php echo json_encode(url('user/purchases/add.php?ajax=csrf_refresh'), JSON_UNESCAPED_UNICODE); ?>;
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

let itemIndex = 1;

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
    bindEvents();
    const restored = restoreSubmittedItems();

    if (!restored) {
        document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
            initProductImageCell(row);
        });
        document.querySelectorAll('.product-name').forEach(input => {
            initializeAutocomplete(input);
        });
        document.querySelectorAll('.item-barcode').forEach(input => {
            initializeBarcodeAutocomplete(input);
        });
        if (!isPharmacyMode) {
            document.querySelectorAll('.item-row').forEach(row => applyUserUnitsForNewProduct(row));
        }
        
        if (isPharmacyMode) {
            document.querySelectorAll('.pharmacy-item-row').forEach(row => {
                bindPharmacyRowEvents(row);
            });
        }
    }

    if (isPharmacyMode) {
        lockExtraDiscountInputs();
    }

    syncItemRowNumbers();
    startCsrfKeepAlive();
    updateSummary();
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

function bindEvents() {
    // تێکەڵکردنی event بۆ گۆڕانی کۆمپانیا
    document.getElementById('company_id').addEventListener('change', updateCompanyInfo);

    // گۆڕینی دراوی وەسڵ: نوێکردنەوەی نیشانەی دراو لە کۆکاندا
    const receiptCurrencyEl = document.getElementById('receipt_currency');
    if (receiptCurrencyEl) {
        receiptCurrencyEl.addEventListener('change', updateSummary);
    }

    // بۆ خانەکانی step-1000: کلیلی سەرەوە/خوارەوە بە 1000 زیاد/کەم بکات، بەڵام هەر بڕێک دەتوانرێت بنووسرێت (وەک 5500)
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
    
    // تێکەڵکردنی events بۆ حیسابکردن
    document.addEventListener('input', function(e) {
        if (e.target.matches('.quantity, .buy-price, #discount_value, #additional_charges, #discount_items_value, .item-discount-percent')) {
            updateSummary();
        }
        if (isPharmacyMode && e.target.matches('.packet-bonus, .sheets-per-packet, .quantity, .buy-price')) {
            const row = e.target.closest('.pharmacy-item-row');
            if (row) updatePharmacyRowSheet(row);
            updateSummary();
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
    const discountTypeSelect = document.getElementById('discount_type');
    if (discountTypeSelect) {
        discountTypeSelect.addEventListener('change', updateSummary);
    }
    const discountItemsType = document.getElementById('discount_items_type');
    if (discountItemsType) {
        discountItemsType.addEventListener('change', updateSummary);
    }
    
    // Prevent form submission بۆ enter key لە autocomplete (بارکۆد لە initializeBarcodeAutocomplete مامەڵە دەکرێت)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.classList.contains('product-name')) {
            e.preventDefault();
        }
    });
    
    // Form submission
    document.getElementById('addReceiptForm').addEventListener('submit', function(e) {
        if (!validateForm()) {
            e.preventDefault();
            return false;
        }
        serializePurchaseItemsForSubmit(this);
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
        <!-- ڕیزی یەکەم: ناو، بارکۆد، کەتەلۆگ -->
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

        <!-- ڕیزی دووەم: بڕ، شیت، بۆنس، داشکاندن -->
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

        <!-- ڕیزی سێیەم: نرخی کڕین ، نرخی تێکڕای کڕینی پاکەت ، نرخی تێکڕای کڕینی شیت -->
        <div class="row g-2 align-items-end mt-2 pharmacy-sheet-block" style="display: flex;">
            <div class="col-md-2">
                <label class="form-label">نرخی کڕین <span class="text-danger">*</span></label>
                <input type="number" class="form-control buy-price step-1000" name="buy_price[]" step="any" min="0" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">نرخی تێکڕای کڕینی پاکەت</label>
                <input type="text" class="form-control bg-light avg-buy-after-bonus-discount" readonly tabindex="-1" title="نرخی تێکڕای کڕینی پاکەت دوای بۆنس و داشکاندن">
            </div>
            <div class="col-md-2">
                <label class="form-label">نرخی تێکڕای کڕینی شیت</label>
                <input type="text" class="form-control bg-light sheet-buy-price" name="sheet_buy_price[]" readonly tabindex="-1">
            </div>

        </div>

        <!-- ڕیزی چوارەم: نرخی فرۆشتنی پاکەت ، نرخی جوملەی پاکەت ، نرخی تایبەت پاکەت -->
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

        <!-- ڕیزی پێنجەم: نرخی فرۆشتنی شیت ، بەرواری بەسەرچوون -->
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
    discounts.forEach((input, index) => {
        if (!input) return;
        // تەنیا یەکەم خانە کارا بێت
        input.disabled = index !== 0;
    });
}

function addNewItem(options = {}) {
    const { focus = true, scroll = true } = options;
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

    const productNameInput = newItem.querySelector('.product-name');
    initializeAutocomplete(productNameInput);
    
    const barcodeInput = newItem.querySelector('.item-barcode');
    if (barcodeInput) initializeBarcodeAutocomplete(barcodeInput);
    if (isPharmacyMode) {
        bindPharmacyRowEvents(newItem);
        lockExtraDiscountInputs();
    } else {
        applyUserUnitsForNewProduct(newItem);
    }

    syncItemRowNumbers();
    
    if (focus) {
        productNameInput.focus();
    }
    itemIndex++;
    updateSummary();
    
    // سکڕۆڵ بۆ کاڵای نوێ
    if (scroll) {
        setTimeout(() => {
            newItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);
    }
}

function setRowInputValue(row, selector, value, markModified = false) {
    const input = row.querySelector(selector);
    if (!input) return;
    input.value = value ?? '';
    if (markModified && input.value !== '') {
        input.dataset.modified = 'true';
    }
}

function restoreSubmittedItems() {
    if (!Array.isArray(submittedItems) || submittedItems.length === 0) {
        return false;
    }

    const itemsContainer = document.getElementById('itemsContainer');
    if (!itemsContainer) return false;

    itemsContainer.innerHTML = '';
    itemIndex = 0;

    submittedItems.forEach(item => {
        addNewItem({ focus: false, scroll: false });
        const row = itemsContainer.lastElementChild;
        if (!row) return;

        const productNameInput = row.querySelector('.product-name');
        const productIdInput = row.querySelector('[name="product_id[]"]');

        setRowInputValue(row, '.product-name', item.product_name ?? '');
        setRowInputValue(row, '.item-barcode', item.barcode ?? '');
        setRowInputValue(row, '[name="product_id[]"]', item.product_id ?? '0');
        setRowInputValue(row, '.quantity', item.quantity ?? '');
        setRowInputValue(row, '.buy-price', item.buy_price ?? '', true);
        setRowInputValue(row, '.sell-price', item.sell_price ?? '', true);
        setRowInputValue(row, '[name="wholesale_price[]"]', item.wholesale_price ?? '', true);
        setRowInputValue(row, '[name="special_price[]"]', item.special_price ?? '', true);
        setRowInputValue(row, '.expiry-date', item.expiry_date ?? '', true);
        setRowInputValue(row, '.conversion-rate', item.conversion_rate ?? '1');

        const productId = parseInt(item.product_id || '0', 10);
        if (productId > 0 && productNameInput && products[productId]) {
            selectProduct(productNameInput, products[productId]);
            if (productIdInput) productIdInput.value = String(productId);
        }

        if (isPharmacyMode) {
            setRowInputValue(row, '.item-category', item.category_id ?? '');
            setRowInputValue(row, '.packet-bonus', item.packet_bonus ?? '0');
            setRowInputValue(row, '.sheets-per-packet', item.sheets_per_packet ?? '0');
            setRowInputValue(row, '.sheet-buy-price', item.sheet_buy_price ?? '', true);
            setRowInputValue(row, '.sheet-sell-price', item.sheet_sell_price ?? '', true);
            setRowInputValue(row, '.item-discount-percent', item.item_discount_percent ?? '0');
            updatePharmacyRowSheet(row);
        } else {
            const unitSelect = row.querySelector('.unit-select');
            if (unitSelect && item.unit_id) {
                unitSelect.value = item.unit_id;
                updateUnitPrices(unitSelect);
            }
            setRowInputValue(row, '.buy-price', item.buy_price ?? '', true);
            setRowInputValue(row, '.sell-price', item.sell_price ?? '', true);
            setRowInputValue(row, '[name="wholesale_price[]"]', item.wholesale_price ?? '', true);
            setRowInputValue(row, '[name="special_price[]"]', item.special_price ?? '', true);
        }
    });

    return true;
}

function refreshCsrfTokenAndKeepSession() {
    fetch(csrfRefreshUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('csrf refresh failed');
        }
        return response.json();
    })
    .then(data => {
        if (!data || !data.success || !data.csrf_token) return;
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput) {
            csrfInput.value = data.csrf_token;
        }
    })
    .catch(() => {
        // intentionally ignored: do not interrupt user workflow
    });
}

function startCsrfKeepAlive() {
    // زۆرترین ماوەی مانەوە لەسەر لاپەڕە بێت، سێشن و token نوێ بمێننەوە
    setInterval(refreshCsrfTokenAndKeepSession, 5 * 60 * 1000);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            refreshCsrfTokenAndKeepSession();
        }
    });
}

function updatePharmacyRowSheet(row) {
    const qty = parseFloat(row.querySelector('.quantity').value) || 0;
    const bonus = parseFloat(row.querySelector('.packet-bonus').value) || 0;
    const sheetsPerPacket = parseInt(row.querySelector('.sheets-per-packet').value, 10) || 0;
    const buyPrice = parseFloat(row.querySelector('.buy-price').value) || 0;
    const sellPrice = parseFloat(row.querySelector('.sell-price').value) || 0;
    const totalPackets = qty + bonus;
    const formatNumber = (val) => {
        if (!isFinite(val)) return '';
        return Math.round(val).toString();
    };
    // نرخی تێکڕای کڕین دوای بۆنس: (بڕ × نرخی کڕین) / (بڕ + بۆنس)
    const avgBuyInput = row.querySelector('.avg-buy-after-bonus');
    if (avgBuyInput) {
        if (totalPackets > 0) {
            const netBuy = (qty * buyPrice) / totalPackets;
            avgBuyInput.value = formatNumber(netBuy);
        } else {
            avgBuyInput.value = '';
        }
    }
    const sheetBlock = row.querySelector('.pharmacy-sheet-block');
    const sheetQtyInput = row.querySelector('.sheet-quantity');
    const sheetBuyInput = row.querySelector('.sheet-buy-price');
    const sheetSellInput = row.querySelector('.sheet-sell-price');
    if (sheetBlock) {
        sheetBlock.style.display = 'flex';
    }
    if (sheetsPerPacket > 0) {
        const sheetQty = totalPackets * sheetsPerPacket;
        if (sheetQtyInput) sheetQtyInput.value = sheetQty;
        if (totalPackets > 0) {
            // نرخی کڕینی شیت بەپێی بۆنس (و بڕ و نرخ و شیت لە پاکەت) هەمیشە نوێ دەکرێتەوە
            const netBuy = (qty * buyPrice) / totalPackets;
            if (sheetBuyInput) {
                sheetBuyInput.value = formatNumber(netBuy / sheetsPerPacket);
                sheetBuyInput.dataset.modified = 'false';
            }
            // نرخی فرۆشتنی شیت = نرخی فرۆشتنی پاکەت / پاکەت چەند شیتە؟
            if (sheetSellInput) {
                const sheetSell = sheetsPerPacket > 0 ? (sellPrice / sheetsPerPacket) : 0;
                sheetSellInput.value = formatNumber(sheetSell);
                sheetSellInput.dataset.modified = 'false';
            }
        }
    } else {
        if (sheetQtyInput) sheetQtyInput.value = '';
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
    ['quantity', 'packet-bonus', 'buy-price', 'sell-price', 'sheets-per-packet', 'item-discount-percent'].forEach(className => {
        const el = row.querySelector('.' + className);
        if (el) el.addEventListener('input', function() { updatePharmacyRowSheet(row); updateSummary(); });
    });
    const sheetBuy = row.querySelector('.sheet-buy-price');
    const sheetSell = row.querySelector('.sheet-sell-price');
    if (sheetBuy) sheetBuy.addEventListener('input', function() { this.dataset.modified = 'true'; });
    if (sheetSell) sheetSell.addEventListener('input', function() { this.dataset.modified = 'true'; });
}

function removeItem(button) {
    const itemRow = button.closest('.item-row');
    const itemsContainer = document.getElementById('itemsContainer');
    
    // مەهێڵە لانی کەم یەک کاڵا بمێنێتەوە
    if (itemsContainer.children.length > 1) {
        itemRow.remove();
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
        // ئەگەر کاڵاکە پێشتر هەبوو و کڕدرابێت: وەڵامی پێشووی «پاکەت چەند شیتە؟» و زانیاری شیت دابنێتەوە
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
    
    // بارکردنی یەکەکان (مۆدی دەرمانخانە یەکە هەمیشە پاکەتە، unit-select نییە)
    const unitSelect = row.querySelector('.unit-select');
    const conversionRateInput = row.querySelector('.conversion-rate');
    if (conversionRateInput) conversionRateInput.value = 1;
    if (!unitSelect) {
        setPrices(row, product);
        const expiryInput = row.querySelector('.expiry-date');
        if (expiryInput) { expiryInput.value = product.expiry_date || ''; expiryInput.dataset.modified = 'false'; }
        markPricesAsUnmodified(row);
        suggestions.style.display = 'none';
        updateSummary();
        if (row.classList.contains('pharmacy-item-row')) {
            updatePharmacyRowSheet(row);
            if (product.last_sheet_buy_price != null) {
                const sb = row.querySelector('.sheet-buy-price');
                if (sb) { sb.value = Math.round(parseFloat(product.last_sheet_buy_price)).toString(); sb.dataset.modified = 'false'; }
            }
            if (product.last_sheet_sell_price != null) {
                const ss = row.querySelector('.sheet-sell-price');
                if (ss) { ss.value = Math.round(parseFloat(product.last_sheet_sell_price)).toString(); ss.dataset.modified = 'false'; }
            }
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
    if (row.classList.contains('pharmacy-item-row')) {
        updatePharmacyRowSheet(row);
        if (product.last_sheet_buy_price != null) {
            const sb = row.querySelector('.sheet-buy-price');
            if (sb) { sb.value = parseFloat(product.last_sheet_buy_price); sb.dataset.modified = 'false'; }
        }
        if (product.last_sheet_sell_price != null) {
            const ss = row.querySelector('.sheet-sell-price');
            if (ss) { ss.value = parseFloat(product.last_sheet_sell_price); ss.dataset.modified = 'false'; }
        }
    }
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
    const rows = document.querySelectorAll('.item-row');

    const discountTypeSelect = document.getElementById('discount_type');
    const discountValueInput = document.getElementById('discount_value');
    const discountType = discountTypeSelect ? discountTypeSelect.value : 'amount';
    const discountValue = discountValueInput ? (parseFloat(discountValueInput.value) || 0) : 0;

    const discountItemsTypeInput = document.getElementById('discount_items_type');
    const discountItemsValueInput = document.getElementById('discount_items_value');
    const discountItemsType = discountItemsTypeInput ? discountItemsTypeInput.value : 'amount';
    const discountItemsValue = discountItemsValueInput ? (parseFloat(discountItemsValueInput.value) || 0) : 0;

    const additionalCharges = parseFloat(document.getElementById('additional_charges').value) || 0;
    
    let subtotal = 0;
    let itemCount = 0;
    const lineTotals = [];

    for (let i = 0; i < quantities.length; i++) {
        const quantity = parseFloat(quantities[i].value) || 0;
        const buyPrice = parseFloat(buyPrices[i].value) || 0;
        
        if (quantity > 0 && buyPrice >= 0) {
            const lineTotal = quantity * buyPrice;
            subtotal += lineTotal;
            lineTotals.push({ lineTotal, quantity, row: rows[i] });
            itemCount++;
        } else {
            lineTotals.push({ lineTotal: 0, quantity, row: rows[i] });
        }
    }

    // داشکاندنی تایبەت بۆ کاڵاکان
    let discountItemsAmount = 0;

    if (isPharmacyMode) {
        // ڕێژەی داشکاندنی گشتی لەسەر بنەمای یەکەم خانەی داشکاندن
        let globalPercent = 0;
        const allDiscountInputs = document.querySelectorAll('.item-discount-percent');
        if (allDiscountInputs.length > 0) {
            let p = parseFloat(allDiscountInputs[0].value) || 0;
            if (p < 0) {
                p = 0;
                allDiscountInputs[0].value = 0;
            } else if (p > 100) {
                p = 100;
                allDiscountInputs[0].value = 100;
            }
            globalPercent = p;
        }

        // مۆدی دەرمانخانە: هەمان ڕێژە بۆ هەموو ڕیزەکانی دەرمان
        for (let i = 0; i < lineTotals.length; i++) {
            const { lineTotal, quantity, row } = lineTotals[i];
            if (!row || lineTotal <= 0 || quantity <= 0) continue;
            if (!row.classList.contains('pharmacy-item-row')) continue;

            const discountInput = row.querySelector('.item-discount-percent');
            const bonusInput = row.querySelector('.packet-bonus');
            const avgAfterBonusDiscountInput = row.querySelector('.avg-buy-after-bonus-discount');
            const sheetsPerPacketInput = row.querySelector('.sheets-per-packet');
            const sheetBuyInput = row.querySelector('.sheet-buy-price');

            const percent = globalPercent;
            if (discountInput && discountInput !== allDiscountInputs[0]) {
                // بۆ هەموو ڕیزەکان هەمان ڕێژە پیشان بدە
                discountInput.value = allDiscountInputs[0].value;
            }

            let lineDiscount = 0;
            if (percent > 0) {
                lineDiscount = (lineTotal * percent) / 100;
            }
            discountItemsAmount += lineDiscount;

            const netLineTotal = lineTotal - lineDiscount;

            // تێکڕای کڕینی پاکەت و شیت دوای بۆنس و داشکاندن
            const qty = parseFloat(quantity) || 0;
            const bonus = bonusInput ? (parseFloat(bonusInput.value) || 0) : 0;
            const totalPackets = qty + bonus;
            if (avgAfterBonusDiscountInput) {
                if (totalPackets > 0) {
                    const effectivePacketBuy = netLineTotal / totalPackets;
                    avgAfterBonusDiscountInput.value = Math.round(effectivePacketBuy).toString();

                    const sheetsPerPacket = sheetsPerPacketInput ? (parseInt(sheetsPerPacketInput.value, 10) || 0) : 0;
                    if (sheetBuyInput && sheetsPerPacket > 0) {
                        const effectiveSheetBuy = effectivePacketBuy / sheetsPerPacket;
                        sheetBuyInput.value = Math.round(effectiveSheetBuy).toString();
                        sheetBuyInput.dataset.modified = 'false';
                    }
                } else {
                    avgAfterBonusDiscountInput.value = '';
                    if (sheetBuyInput) {
                        sheetBuyInput.value = '';
                    }
                }
            }
        }
    } else {
        // مۆدی ئاسایی: داشکاندنی گشتی بۆ هەموو کاڵاکان و دابەشکردنی بەپێی نرخی کاڵا
        if (discountItemsValue > 0 && subtotal > 0) {
            if (discountItemsType === 'percentage') {
                discountItemsAmount = (subtotal * discountItemsValue) / 100;
            } else {
                discountItemsAmount = Math.min(discountItemsValue, subtotal);
            }
        }

        // پاشکشکردنی داشکاندنی کاڵاکان بەسەر هەر ڕیزێک و نیشانکردنی نرخی تێکڕای نوێ
        if (discountItemsAmount > 0 && subtotal > 0) {
            for (let i = 0; i < lineTotals.length; i++) {
                const { lineTotal, quantity, row } = lineTotals[i];
                if (!row || lineTotal <= 0 || quantity <= 0) continue;

                const share = lineTotal / subtotal;
                const lineDiscount = discountItemsAmount * share;
                const netLineTotal = lineTotal - lineDiscount;

                // مۆدی ئاسایی: تێکڕای کڕین دوای داشکاندن بەپێی یەکە
                const avgAfterDiscountInput = row.querySelector('.avg-buy-after-discount');
                if (avgAfterDiscountInput) {
                    const effectiveUnitBuy = netLineTotal / quantity;
                    avgAfterDiscountInput.value = effectiveUnitBuy.toFixed(3).replace(/\.?0+$/, '');
                }
            }
        } else {
            // ئەگەر داشکاندنی کاڵاکان نییە، خانەکانی تێکڕای نوێ پاک بکە
            document.querySelectorAll('.avg-buy-after-bonus-discount, .avg-buy-after-discount').forEach(input => {
                input.value = '';
            });
        }
    }
    
    // داشکاندنی سەرەکی وەسڵ (پێشوو) لەسەر کۆی گشتی - تەنها بۆ مۆدی ئاسایی
    let discountHeaderAmount = 0;
    if (!isPharmacyMode && discountValue > 0) {
        if (discountType === 'percentage') {
            discountHeaderAmount = (subtotal * discountValue) / 100;
        } else {
            discountHeaderAmount = discountValue;
        }
    }

    const totalDiscount = discountHeaderAmount + discountItemsAmount;
    const finalTotal = subtotal - totalDiscount + additionalCharges;
    
    // نوێکردنەوەی UI
    const curUnit = receiptCurrencyUnit();
    document.getElementById('itemsCount').textContent = itemCount;
    document.getElementById('subtotal').textContent = Math.round(subtotal).toLocaleString() + ' ' + curUnit;

    // پیشاندانی داشکاندن (کۆی داشکاندن سەرەکی + داشکاندنی کاڵاکان)
    let discountText = '-' + Math.round(totalDiscount).toLocaleString() + ' ' + curUnit;
    if (discountType === 'percentage' && discountValue > 0) {
        discountText += ` (${discountValue}%)`;
    }
    if (discountItemsAmount > 0 && discountItemsType === 'percentage') {
        discountText += ` + داشکاندنی کاڵاکان ${discountItemsValue}%`;
    }
    document.getElementById('discountDisplay').textContent = discountText;

    document.getElementById('chargesDisplay').textContent = '+' + Math.round(additionalCharges).toLocaleString() + ' ' + curUnit;
    document.getElementById('finalTotal').textContent = Math.round(finalTotal).toLocaleString() + ' ' + curUnit;
}

// یەکەی دراوی وەسڵی هەڵبژێردراو (بۆ نیشاندانی کۆکان)
function receiptCurrencyUnit() {
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
            const row = productNames[i].closest('.item-row');
            const hiddenId = productNames[i].previousElementSibling;
            const isNewProduct = !hiddenId || !hiddenId.value || hiddenId.value == '0';
            if (isPharmacyMode) {
                if (isNewProduct) {
                    const barcodeInput = row ? row.querySelector('.item-barcode') : null;
                    if (!barcodeInput || !barcodeInput.value.trim()) {
                        errors.push(`بۆ کاڵای نوێ "${productNames[i].value}" تکایە بارکۆد بنووسە`);
                    }
                }
            }
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
        const form = document.getElementById('addReceiptForm');
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

function previewImageAdd(input) {
    const fileSizeError = document.getElementById('file-size-error-add');
    const fileSizeMessage = document.getElementById('file-size-message-add');
    const imagePreview = document.getElementById('image_preview_add');
    
    fileSizeError.style.display = 'none';
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const maxSizeBeforeCompression = 50 * 1024 * 1024; // 50MB قبل الضغط
        const fileSize = file.size;
        
        // تحقق من الحجم قبل الضغط
        if (fileSize > maxSizeBeforeCompression) {
            fileSizeMessage.textContent = `فایلەکە زۆر زۆر گەورەیە. حەدئکثر 50MB پێش کێشکردنەوە (ئێستا: ${(fileSize / 1024 / 1024).toFixed(2)}MB)`;
            fileSizeError.style.display = 'block';
            input.value = '';
            imagePreview.style.display = 'none';
            return;
        }
        
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            fileSizeMessage.textContent = 'جۆری فایل ڕێگەپێدراو نییە. تەنها JPG، PNG، GIF، WEBP ڕێگەپێدراوە';
            fileSizeError.style.display = 'block';
            input.value = '';
            imagePreview.style.display = 'none';
            return;
        }
        
        // کێشکردنەوە و پیشاندان
        compressReceiptImage(file, function(compressedFile) {
            // نوێکردنەوەی input بە وێنەی کێشکراوە
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(compressedFile);
            input.files = dataTransfer.files;
            
            // پیشاندانی preview
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('preview_img_add').src = e.target.result;
                imagePreview.style.display = 'block';
            }
            reader.readAsDataURL(compressedFile);
        });
    } else {
        imagePreview.style.display = 'none';
    }
}

function compressReceiptImage(file, callback) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // حیسابکردنی قەبارەی نوێ (حەدئکثر 1920x1080)
            let { width, height } = calculateImageDimensions(img.width, img.height, 1920, 1080);
            
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

function calculateImageDimensions(originalWidth, originalHeight, maxWidth, maxHeight) {
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
</script>

<?php include '../../includes/footer.php'; ?>
