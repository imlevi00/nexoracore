<?php
/**
 * دەستکاری کاڵا - user/products/edit.php
 */

require_once '../../includes/functions.php';
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/product_units_package.php';
require_once '../../includes/product_change_logger.php';
require_once '../../includes/business_type_helpers.php';
require_once 'includes/custom_fields_helpers.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$canManageProductUnits = hasProductsUnitsManageAccess();
$isWholesaleDistributionMode = isWholesaleDistributionMode($conn, (int)$userId);
$isCurtainShopMode = isCurtainShopMode($conn, (int)$userId);
$fabricWidth = null;
$fabricHeight = null;
$fabricMeasureUnit = 'cm';
$productsIndexBase = url('user/products/index.php');
$returnUrlInput = $_POST['return_url'] ?? ($_GET['return_url'] ?? $productsIndexBase);
$returnUrl = (strpos($returnUrlInput, $productsIndexBase) === 0) ? $returnUrlInput : $productsIndexBase;

// Get product ID
$productId = (int)($_GET['id'] ?? 0);
if (!$productId) {
    setMessage('کاڵا نەدۆزرایەوە', 'error');
    redirect($returnUrl);
}
 
// Get product data
$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name,
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
        SELECT pu2.id FROM product_units pu2
        WHERE pu2.product_id = p.id
        ORDER BY pu2.is_primary DESC, pu2.id ASC
        LIMIT 1
    )
    WHERE p.id = ? AND p.user_id = ?
");
$stmt->bind_param("ii", $productId, $userId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    setMessage('کاڵا نەدۆزرایەوە یان دەسەڵاتت نییە', 'error');
    redirect($returnUrl);
}

$errors = [];
$success = false;
$customFieldDefinitions = getProductCustomFields($conn, $userId, true);
$parsedCustomFieldValues = [];
$existingCustomValuesMap = getProductCustomFieldValuesMap($conn, $userId, [$productId]);
$existingCustomValues = [];
if (!empty($existingCustomValuesMap[$productId])) {
    foreach ($existingCustomValuesMap[$productId] as $customValue) {
        $existingCustomValues[(int)$customValue['field_id']] = $customValue['value'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        // Get form data
        $name = cleanInput($_POST['name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $barcode = cleanInput($_POST['barcode'] ?? '');
        $expiry_date = (!$isCurtainShopMode && !empty($_POST['expiry_date'])) ? $_POST['expiry_date'] : null;
        // No primary unit system - all units are equal
        $buy_price = (float)($_POST['buy_price'] ?? 0);
        $sell_price = (float)($_POST['sell_price'] ?? 0);
        $wholesale_price = (float)($_POST['wholesale_price'] ?? 0);
        $special_price = (float)($_POST['special_price'] ?? 0);
        $stock_quantity = (float)($_POST['stock_quantity'] ?? 0);
        $min_stock = (float)($_POST['min_stock'] ?? 5);
        
        // Handle multiple units data (unit_id only required; prices and stock optional)
        $product_units = [];
        if (isset($_POST['units']) && is_array($_POST['units'])) {
            foreach ($_POST['units'] as $unitData) {
                if (!empty($unitData['unit_id'])) {
                    $product_units[] = [
                        'unit_id' => (int)$unitData['unit_id'],
                        'buy_price' => (float)($unitData['buy_price'] ?? 0),
                        'sell_price' => (float)($unitData['sell_price'] ?? 0),
                        'wholesale_price' => (float)($unitData['wholesale_price'] ?? 0),
                        'special_price' => (float)($unitData['special_price'] ?? 0),
                        'stock_quantity' => (float)($unitData['stock_quantity'] ?? 0),
                        'min_stock' => (float)($unitData['min_stock'] ?? 0),
                        'currency' => !empty($unitData['currency']) && in_array($unitData['currency'], ['USD', 'IQD']) ? $unitData['currency'] : 'IQD',
                        'is_primary' => !empty($unitData['is_primary']) && (string)$unitData['is_primary'] === '1',
                        'conversion_factor' => (float)($unitData['conversion_factor'] ?? 1),
                    ];
                }
            }
        }
         
        // Validation
        if (empty($name)) {
            $errors[] = 'ناوی کاڵا پێویستە';
        }

        if ($isCurtainShopMode) {
            $parsedFabric = parseCurtainFabricSizeInput($_POST);
            $fabricWidth = $parsedFabric['width'];
            $fabricHeight = $parsedFabric['height'];
            $fabricMeasureUnit = $parsedFabric['unit'];
            if (!empty($parsedFabric['errors'])) {
                $errors = array_merge($errors, $parsedFabric['errors']);
            }
        }

        [$parsedCustomFieldValues, $customFieldErrors] = parseProductCustomFieldInput(
            $conn,
            $userId,
            $customFieldDefinitions,
            $_POST['custom_fields'] ?? []
        );
        if (!empty($customFieldErrors)) {
            $errors = array_merge($errors, $customFieldErrors);
        }

        $product_units = normalizeProductUnitsForPackageAccess($product_units, $conn, (int)$userId);

        if (empty($product_units)) {
            $errors[] = 'پێویستە لانیکەم یەک یەکە بۆ کاڵا دیاری بکرێت';
        }
        if (!$canManageProductUnits && empty($product_units)) {
            $errors[] = 'یەکەی دانە نەدۆزرایەوە. تکایە لە بەڕێوەبردنی یەکەکان یەکەی بنەڕەت دیاری بکە.';
        }

        if ($canManageProductUnits && !empty($product_units)) {
            $primaryCount = 0;
            foreach ($product_units as $u) {
                if (!empty($u['is_primary'])) {
                    $primaryCount++;
                }
            }
            if (count($product_units) === 1) {
                $product_units[0]['is_primary'] = true;
                $product_units[0]['conversion_factor'] = 1.0;
            } elseif ($primaryCount !== 1) {
                $errors[] = 'پێویستە تەنها یەک یەکە وەک یەکەی بنەڕەت دیاری بکرێت';
            }
            foreach ($product_units as $idx => $u) {
                if (!empty($u['is_primary'])) {
                    $product_units[$idx]['conversion_ratio'] = 1.0;
                    $product_units[$idx]['conversion_rate'] = 1.0;
                } else {
                    $f = (float)($u['conversion_factor'] ?? 0);
                    if ($f <= 0) {
                        $errors[] = 'ڕێژەی گۆڕینی یەکەکان (چەند یەکەی بنەڕەت لە یەک یەکەی تر) دەبێت لە سفر گەورەتر بێت';
                        break;
                    }
                    $product_units[$idx]['conversion_ratio'] = $f;
                    $product_units[$idx]['conversion_rate'] = $f;
                }
            }
        }

        $primaryStockForProduct = $stock_quantity;
        $primaryMinForProduct = $min_stock;
        if (!empty($product_units)) {
            foreach ($product_units as $u) {
                if (!empty($u['is_primary'])) {
                    $primaryStockForProduct = $u['stock_quantity'];
                    $primaryMinForProduct = $u['min_stock'];
                    break;
                }
            }
        }
        
        // Handle multiple barcodes (only for products with units)
        $additional_barcodes = [];
        if (!empty($product_units) && isset($_POST['additional_barcodes']) && is_array($_POST['additional_barcodes'])) {
            foreach ($_POST['additional_barcodes'] as $addBarcode) {
                $addBarcode = trim($addBarcode);
                if (!empty($addBarcode)) {
                    $additional_barcodes[] = cleanInput($addBarcode);
                }
            }
        }
        
        // Check if barcode exists for other products - check in both products and product_barcodes
        if (!empty($barcode) && $barcode !== $product['barcode']) {
            // Check in products table
            $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND user_id = ? AND id != ?");
            $stmt->bind_param("sii", $barcode, $userId, $productId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'ئەم بارکۆدە پێشتر بەکارهاتووە';
            }
            $stmt->close();
            
            // Check in product_barcodes table
            $stmt = $conn->prepare("SELECT pb.id FROM product_barcodes pb 
                                    INNER JOIN products p ON pb.product_id = p.id 
                                    WHERE pb.barcode = ? AND p.user_id = ? AND pb.product_id != ?");
            $stmt->bind_param("sii", $barcode, $userId, $productId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'ئەم بارکۆدە پێشتر بەکارهاتووە';
            }
            $stmt->close();
        }
        
        // Validate category exists if provided
        if ($category_id) {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $category_id, $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $errors[] = 'کەتەلۆگی هەڵبژێردراو نادروستە';
                $category_id = null;
            }
            $stmt->close();
        }
        
        // Handle image upload
        $imagePath = $product['image_path']; // Keep current image by default
        $deleteCurrentImage = isset($_POST['delete_image']) && $_POST['delete_image'] === '1';
        
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = upload_product_image_to_spaces($_FILES['product_image']);
            if ($uploadResult['success']) {
                if ($product['image_path']) {
                    delete_product_image_from_spaces($product['image_path']);
                }
                $imagePath = $uploadResult['db_path'];
            } else {
                $errors = array_merge($errors, $uploadResult['errors'] ?? []);
            }
        } elseif ($deleteCurrentImage) {
            if ($product['image_path']) {
                delete_product_image_from_spaces($product['image_path']);
            }
            $imagePath = null;
        }
        
        // Update product if no errors
        if (empty($errors)) {
            $beforeSnapshot = getProductSnapshotForLogs($conn, $userId, $productId);
            $conn->begin_transaction();
            
            try {
                $productCurrency = $product['currency'] ?? 'IQD';
                if (!empty($product_units)) {
                    foreach ($product_units as $u) {
                        if (!empty($u['is_primary'])) {
                            $productCurrency = $u['currency'] ?? 'IQD';
                            break;
                        }
                    }
                }
                
                // Update main product (without removed price/stock columns)
                if ($isCurtainShopMode) {
                    $stmt = $conn->prepare("
                        UPDATE products SET
                            category_id = ?, name = ?, barcode = ?, expiry_date = ?,
                            currency = ?, image_path = ?, fabric_width = ?, fabric_height = ?,
                            fabric_measure_unit = ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $fabricWidthBind = $fabricWidth === null ? null : number_format($fabricWidth, 3, '.', '');
                    $fabricHeightBind = $fabricHeight === null ? null : number_format($fabricHeight, 3, '.', '');
                    $stmt->bind_param(
                        "issssssssii",
                        $category_id, $name, $barcode, $expiry_date,
                        $productCurrency, $imagePath, $fabricWidthBind, $fabricHeightBind,
                        $fabricMeasureUnit, $productId, $userId
                    );
                } else {
                    $stmt = $conn->prepare("
                        UPDATE products SET 
                            category_id = ?, name = ?, barcode = ?, expiry_date = ?, 
                            currency = ?, image_path = ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->bind_param(
                        "isssssii",
                        $category_id, $name, $barcode, $expiry_date,
                        $productCurrency, $imagePath, $productId, $userId
                    );
                }
                
                if ($stmt->execute()) {
                    $stmt->close();
                    
                    // Delete existing product units
                    $deleteStmt = $conn->prepare("DELETE FROM product_units WHERE product_id = ?");
                    $deleteStmt->bind_param("i", $productId);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                    
                    if (!empty($product_units)) {
                        $unitStmt = $conn->prepare("
                            INSERT INTO product_units (
                                product_id, unit_id, buy_price, sell_price, wholesale_price, special_price, currency,
                                stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        foreach ($product_units as $unit) {
                            $unitCurrency = $unit['currency'] ?? 'IQD';
                            $isPrimaryInt = !empty($unit['is_primary']) ? 1 : 0;
                            $unitStmt->bind_param(
                                "iiddddsddddi",
                                $productId, $unit['unit_id'],
                                $unit['buy_price'], $unit['sell_price'], $unit['wholesale_price'], $unit['special_price'], $unitCurrency,
                                $unit['stock_quantity'], $unit['min_stock'], $unit['conversion_ratio'], $unit['conversion_rate'], $isPrimaryInt
                            );
                            $unitStmt->execute();
                        }
                        $unitStmt->close();
                    }
                    
                    // Handle additional barcodes (only if product has units)
                    if (!empty($product_units)) {
                        // Delete existing additional barcodes
                        $deleteBarcodeStmt = $conn->prepare("DELETE FROM product_barcodes WHERE product_id = ?");
                        $deleteBarcodeStmt->bind_param("i", $productId);
                        $deleteBarcodeStmt->execute();
                        $deleteBarcodeStmt->close();
                        
                        // Insert new additional barcodes
                        if (!empty($additional_barcodes)) {
                            // Validate additional barcodes don't exist
                            foreach ($additional_barcodes as $addBarcode) {
                                // Check in products table
                                $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND user_id = ? AND id != ?");
                                $stmt->bind_param("sii", $addBarcode, $userId, $productId);
                                $stmt->execute();
                                if ($stmt->get_result()->num_rows > 0) {
                                    throw new Exception("بارکۆدی '$addBarcode' پێشتر بەکارهاتووە");
                                }
                                $stmt->close();
                                
                                // Check in product_barcodes table (for other products)
                                $stmt = $conn->prepare("SELECT pb.id FROM product_barcodes pb 
                                                        INNER JOIN products p ON pb.product_id = p.id 
                                                        WHERE pb.barcode = ? AND p.user_id = ? AND pb.product_id != ?");
                                $stmt->bind_param("sii", $addBarcode, $userId, $productId);
                                $stmt->execute();
                                if ($stmt->get_result()->num_rows > 0) {
                                    throw new Exception("بارکۆدی '$addBarcode' پێشتر بەکارهاتووە");
                                }
                                $stmt->close();
                            }
                            
                            // Insert additional barcodes
                            $barcodeStmt = $conn->prepare("INSERT INTO product_barcodes (product_id, barcode) VALUES (?, ?)");
                            foreach ($additional_barcodes as $addBarcode) {
                                $barcodeStmt->bind_param("is", $productId, $addBarcode);
                                $barcodeStmt->execute();
                            }
                            $barcodeStmt->close();
                        }
                    } else {
                        // If product has no units, delete all additional barcodes
                        $deleteBarcodeStmt = $conn->prepare("DELETE FROM product_barcodes WHERE product_id = ?");
                        $deleteBarcodeStmt->bind_param("i", $productId);
                        $deleteBarcodeStmt->execute();
                        $deleteBarcodeStmt->close();
                    }

                    if (!empty($customFieldDefinitions)) {
                        saveProductCustomFieldValues($conn, $userId, $productId, $parsedCustomFieldValues);
                    }
                    
                    $conn->commit();
                    $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $productId);
                    logProductChangeEvent(
                        'product.update',
                        'product',
                        $productId,
                        $beforeSnapshot,
                        $afterSnapshot,
                        [
                            'user_id' => $userId,
                            'current_user' => $currentUser,
                            'product_id' => $productId,
                            'source_module' => 'user/products/edit.php',
                            'source_reference' => 'product_edit'
                        ]
                    );
                    
                    // Log activity
                    writeLog("Product updated: $name (ID: $productId) with units by user: {$currentUser['email']}");
                    
                    // Log activity for analytics
                    logActivity(
                        'product_edit',
                        "کاڵای '$name' (ID: $productId) بە سەرکەوتوویی نوێکرایەوە لەگەڵ " . count($product_units) . " یەکە"
                    );                  
                    setMessage('کاڵا بە سەرکەوتوویی نوێکرایەوە', 'success');
                    
                    // Redirect based on user preference
                    if (isset($_POST['save_and_continue'])) {
                        redirect(url('user/products/edit.php?id=' . $productId . '&return_url=' . urlencode($returnUrl)));
                    } else {
                        redirect($returnUrl);
                    }
                } else {
                    throw new Exception('Failed to update product');
                }
            } catch (Exception $e) {
                $conn->rollback();
                writeLog("Product edit custom fields error for user {$userId}, product {$productId}: " . $e->getMessage());
                $errors[] = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی کاڵا. دووبارە هەوڵ بدەرەوە';
            }
        }
        
        // Refresh product data if there were errors
        if (!empty($errors)) {
            $stmt = $conn->prepare("
                SELECT p.*, c.name as category_name,
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
                    SELECT pu2.id FROM product_units pu2
                    WHERE pu2.product_id = p.id
                    ORDER BY pu2.is_primary DESC, pu2.id ASC
                    LIMIT 1
                )
                WHERE p.id = ? AND p.user_id = ?
            ");
            $stmt->bind_param("ii", $productId, $userId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
        }
    }
}

// وەرگرتنی کەتەلۆگەکان
$categories = [];
$result = $conn->query("SELECT id, name FROM categories WHERE user_id = $userId ORDER BY name");
if ($result) {
    $categories = $result->fetch_all(MYSQLI_ASSOC);
}

// وەرگرتنی یەکەکان
$units = [];
$result = $conn->query("SELECT id, name, symbol, is_default FROM units WHERE user_id = $userId AND is_active = 1 ORDER BY is_default DESC, name");
if ($result) {
    $units = $result->fetch_all(MYSQLI_ASSOC);
}

// وەرگرتنی یەکەکانی کاڵا
$productUnits = [];
$stmt = $conn->prepare("
    SELECT pu.*, u.name as unit_name, u.symbol as unit_symbol 
    FROM product_units pu 
    JOIN units u ON pu.unit_id = u.id 
    WHERE pu.product_id = ? 
    ORDER BY pu.id
");
$stmt->bind_param("i", $productId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $productUnits[] = $row;
}
$stmt->close();

// وەرگرتنی بارکۆدە زیادەکان (تەنها ئەگەر کاڵاکە یەکەی هەبێت)
$additionalBarcodes = [];
if (!empty($productUnits)) {
    $stmt = $conn->prepare("SELECT id, barcode FROM product_barcodes WHERE product_id = ? ORDER BY id");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $additionalBarcodes[] = $row;
    }
    $stmt->close();
}

// If no units found, create a default unit from the main product data
if (empty($productUnits)) {
    // Find default unit (دانە)
    $defaultUnit = null;
    $stmt = $conn->prepare("SELECT id, name, symbol FROM units WHERE user_id = ? AND is_default = 1 AND is_active = 1 LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $defaultUnit = $row;
    }
    $stmt->close();
    
    // If no default unit found, get the first available unit
    if (!$defaultUnit) {
        $stmt = $conn->prepare("SELECT id, name, symbol FROM units WHERE user_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $defaultUnit = $row;
        }
        $stmt->close();
    }
    
    // Create a default unit entry from the main product data
    if ($defaultUnit) {
        $productUnits[] = [
            'unit_id' => $defaultUnit['id'],
            'unit_name' => $defaultUnit['name'],
            'unit_symbol' => $defaultUnit['symbol'],
            'buy_price' => $product['buy_price'],
            'sell_price' => $product['sell_price'],
            'wholesale_price' => $product['wholesale_price'],
            'special_price' => $product['special_price'],
            'stock_quantity' => $product['stock_quantity'],
            'min_stock' => $product['min_stock'],
            'currency' => $product['currency'] ?? 'IQD',
            'is_primary' => 1,
            'conversion_factor' => 1,
        ];
    }
}

$productUnits = filterProductUnitsForPackageDisplay($productUnits, $conn, (int)$userId);

// نۆڕمالایزکردنی یەکەی بنەڕەت و conversion_factor بۆ JavaScript
if (!empty($productUnits)) {
    $anyPrimary = false;
    foreach ($productUnits as $pu) {
        if (!empty($pu['is_primary'])) {
            $anyPrimary = true;
            break;
        }
    }
    if (!$anyPrimary) {
        usort($productUnits, function ($a, $b) {
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });
        foreach ($productUnits as $i => &$pu) {
            if (count($productUnits) === 1) {
                $pu['is_primary'] = 1;
                $pu['conversion_factor'] = 1;
            } elseif ($i === 0) {
                $pu['is_primary'] = 1;
                $pu['conversion_factor'] = 1;
            } else {
                $pu['is_primary'] = 0;
                $f = (float)($pu['conversion_rate'] ?? $pu['conversion_ratio'] ?? 1);
                $pu['conversion_factor'] = $f > 0 ? $f : 1;
            }
        }
        unset($pu);
    } else {
        foreach ($productUnits as &$pu) {
            if (!empty($pu['is_primary'])) {
                $pu['conversion_factor'] = 1;
            } else {
                $f = (float)($pu['conversion_rate'] ?? $pu['conversion_ratio'] ?? 1);
                $pu['conversion_factor'] = $f > 0 ? $f : 1;
            }
        }
        unset($pu);
    }
}

$useWholesaleUnitsUi = $isWholesaleDistributionMode && $canManageProductUnits;
$wholesaleUnitsUiFallback = false;
if ($useWholesaleUnitsUi && !canUseWholesaleUnitsUiForProduct($productUnits)) {
    $useWholesaleUnitsUi = false;
    $wholesaleUnitsUiFallback = true;
}

$wholesaleInitialData = ['primary' => null, 'secondary' => null];
if ($useWholesaleUnitsUi && !empty($productUnits)) {
    foreach ($productUnits as $pu) {
        if (!empty($pu['is_primary'])) {
            $wholesaleInitialData['primary'] = $pu;
        } else {
            $wholesaleInitialData['secondary'] = $pu;
        }
    }
}

// Get sale history for this product
$salesHistory = [];
$stmt = $conn->prepare("
    SELECT si.*, s.sale_date, s.invoice_number, s.customer_name 
    FROM sale_items si 
    JOIN sales s ON si.sale_id = s.id 
    WHERE si.product_id = ? AND s.user_id = ? 
    ORDER BY s.sale_date DESC 
    LIMIT 10
");
$stmt->bind_param("ii", $productId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $salesHistory[] = $row;
}
$stmt->close();

// مێژووی گەڕانەوە (POS لە return_items + returns، و گەڕانەوەی تاک لە product_returns)
$returnsHistory = [];
$stmt = $conn->prepare("
    SELECT * FROM (
        SELECT r.return_number AS invoice_number, r.customer_name, r.return_date AS sale_date,
               ri.total_price, ri.quantity
        FROM return_items ri
        INNER JOIN returns r ON ri.return_id = r.id
        WHERE ri.product_id = ? AND r.user_id = ?
        UNION ALL
        SELECT pr.return_number, pr.customer_name, pr.return_date, pr.total_amount, pr.quantity
        FROM product_returns pr
        WHERE pr.product_id = ? AND pr.user_id = ?
    ) AS combined_returns
    ORDER BY sale_date DESC
    LIMIT 10
");
$stmt->bind_param("iiii", $productId, $userId, $productId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $returnsHistory[] = $row;
}
$stmt->close();

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>دەستکاری کاڵا - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    <style>
        #interactive {
            position: relative;
            width: 100%;
            height: 300px;
            overflow: hidden;
        }
        #interactive video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        #interactive canvas {
            display: none;
        }
        .drawingBuffer {
            position: absolute;
            top: 0;
            left: 0;
        }
        @media (max-width: 768px) {
            #interactive {
                height: 250px;
            }
        }
    </style>
</head>
<body class="products-module-page products-form-page bg-body-secondary">

    <?php
    $productsNavId = 'productsEditNav';
    $productsNavLinks = [
        ['href' => $returnUrl, 'icon' => 'bi-arrow-right', 'text' => 'گەڕانەوە بۆ کاڵاکان'],
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <!-- Main Content -->
    <div class="container py-4 products-page-content">
        <div class="row">
            <div class="col-xl-8">
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="bi bi-pencil-square text-warning"></i>
                            دەستکاری کاڵا
                        </h1>
                        <p class="text-muted mb-0">
                            دەستکاری: <?php echo htmlspecialchars($product['name']); ?>
                        </p>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="<?php echo htmlspecialchars($returnUrl); ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                        </a>
                        <button type="button" class="btn btn-outline-danger" onclick="confirmDelete()">
                            <i class="bi bi-trash"></i> سڕینەوە
                        </button>
                    </div>
                </div>

                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <strong>هەڵەکان:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Flash Messages -->
                <?php
                $message = getMessage();
                if ($message):
                ?>
                    <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show">
                        <i class="bi bi-info-circle-fill"></i>
                        <?php echo $message['message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Product Form -->
                <div class="card shadow">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="bi bi-pencil-square"></i>
                            زانیارییەکانی کاڵا
                        </h5>
                    </div>
                    
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($returnUrl); ?>">
                            
                            <div class="row">
                                <!-- Product Image -->
                                <div class="col-lg-4">
                                    <div class="mb-4">
                                        <label class="form-label">وێنەی کاڵا</label>
                                        
                                        <div class="text-center">
                                            <?php if ($product['image_path']): ?>
                                                <div id="currentImage" class="mb-3">
                                                    <img src="<?php echo htmlspecialchars(product_image_url($product['image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                                         alt="Product Image" 
                                                         class="img-fluid rounded border" 
                                                         style="max-height: 200px;">
                                                    
                                                    <div class="mt-2">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="delete_image" value="1" id="deleteImage">
                                                            <label class="form-check-label text-danger" for="deleteImage">
                                                                سڕینەوەی وێنە
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="bg-body-secondary rounded p-4 mb-3">
                                                    <i class="bi bi-camera display-4 text-muted"></i>
                                                    <p class="text-muted mb-0">هیچ وێنەیەک نییە</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <input type="file" name="product_image" id="product_image" 
                                               accept="image/*" class="d-none">
                                        <div class="file-upload-area text-center p-3 border-2 border-dashed rounded">
                                            <div id="imagePreview">
                                                <i class="bi bi-cloud-upload display-4 text-muted mb-2"></i>
                                                <p class="mb-1">کلیک بکە یان وێنە ڕابکێشە</p>
                                                <small class="text-muted">JPG, PNG, GIF - زیاتر لە 5MB نەبێت</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Product Details -->
                                <div class="col-lg-8">
                                    <div class="row">
                                        <!-- Product Name -->
                                        <div class="col-md-8 mb-3">
                                            <label for="name" class="form-label">
                                                <i class="bi bi-tag"></i> ناوی کاڵا *
                                            </label>
                                            <input type="text" class="form-control" id="name" name="name" 
                                                   value="<?php echo htmlspecialchars($product['name']); ?>" 
                                                   required
                                                   autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                                            <div class="invalid-feedback">تکایە ناوی کاڵا داخڵ بکە</div>
                                        </div>

                                        <!-- Category -->
                                        <div class="col-md-4 mb-3">
                                            <label for="category_id" class="form-label">
                                                <i class="bi bi-tags"></i> کەتەلۆگ
                                            </label>
                                            <select class="form-select" id="category_id" name="category_id">
                                                <option value="">هیچ کەتەلۆگێک نییە</option>
                                                <?php foreach ($categories as $category): ?>
                                                    <option value="<?php echo $category['id']; ?>"
                                                            <?php echo ($product['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($category['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php
                                        $fabricWidthValue = $_POST['fabric_width'] ?? formatCurtainFabricSizeNumber($product['fabric_width'] ?? null);
                                        $fabricHeightValue = $_POST['fabric_height'] ?? formatCurtainFabricSizeNumber($product['fabric_height'] ?? null);
                                        $fabricMeasureUnit = $_POST['fabric_measure_unit'] ?? ($product['fabric_measure_unit'] ?? 'cm');
                                        include __DIR__ . '/includes/curtain_fabric_size_fields.php';
                                        ?>

                                        <!-- Barcode -->
                                        <div class="col-md-8 mb-3">
                                            <label for="barcode" class="form-label">
                                                <i class="bi bi-upc-scan"></i> بارکۆد
                                            </label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="barcode" name="barcode" 
                                                       value="<?php echo htmlspecialchars($product['barcode']); ?>">
                                                <button type="button" class="btn btn-outline-primary" id="scanBarcodeBtn"
                                                        data-bs-toggle="modal" data-bs-target="#barcodeScannerModal">
                                                    <i class="bi bi-camera"></i> سکان
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary" id="generateBarcode">
                                                    <i class="bi bi-arrow-clockwise"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info" id="printBarcodeBtn">
                                                    <i class="bi bi-printer"></i> پرێنت
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Expiry Date -->
                                        <?php if (empty($isCurtainShopMode)): ?>
                                        <div class="col-md-6 mb-3">
                                            <label for="expiry_date" class="form-label">
                                                <i class="bi bi-calendar-x"></i> بەرواری بەسەرچوون
                                            </label>
                                            <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                                                   value="<?php echo $product['expiry_date']; ?>">
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Units Section -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <h6 class="border-bottom pb-2 mb-3">
                                        <i class="bi bi-rulers text-primary"></i> یەکەکان
                                    </h6>
                                    <?php if ($wholesaleUnitsUiFallback): ?>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        ئەم کاڵایە زیاتر لە ٢ یەکەی هەیە — UI ـی ئاسایی پیشان دەدرێت.
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($useWholesaleUnitsUi): ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bi bi-boxes"></i>
                                        فۆرمی تایبەت بۆ دابەشکاری جوملە — نرخ و بڕی کارتۆن دەستکاری بکە، نرخی دانە خۆکار دەردەکەوێت.
                                    </div>
                                    <?php elseif ($canManageProductUnits): ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i>
                                        بۆ هەر یەکەیەک نرخەکانی کڕین و فرۆشتن و جوملە و تایبەت و بڕی بەردەست داخڵ بکە
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-secondary mb-0">
                                        <i class="bi bi-lock"></i>
                                        تەنها یەکەی <strong>دانە</strong> بەردەستە بۆ ئەم پاکێجە. نرخ و بڕی بەردەست لە خوارەوە دەستکاری بکە.
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($canManageProductUnits && !$useWholesaleUnitsUi): ?>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        <i class="bi bi-rulers"></i> یەکە هەڵبژێرە
                                    </label>
                                    <div class="d-flex align-items-center">
                                        <select class="form-select me-2" id="unit_selection_dropdown">
                                            <option value="">یەکە هەڵبژێرە</option>
                                            <?php foreach ($units as $unit): ?>
                                                <option value="<?php echo $unit['id']; ?>">
                                                    <?php echo htmlspecialchars($unit['name']); ?><?php echo $unit['symbol'] ? ' (' . htmlspecialchars($unit['symbol']) . ')' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-success btn-sm" id="add_selected_unit_btn">
                                            <i class="bi bi-plus-circle"></i> زیادکردن
                                        </button>
                                    </div>
                                    <div class="form-text">یەکەیەک هەڵبژێرە و کلیک لە زیادکردن بکە</div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        <i class="bi bi-gear"></i> بەڕێوەبردنی یەکەکان
                                    </label>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-outline-primary w-100" id="manage_units_btn" data-bs-toggle="modal" data-bs-target="#unitsManagementModal">
                                            <i class="bi bi-gear"></i> بەڕێوەبردنی یەکەکان
                                        </button>
                                    </div>
                                    <div class="form-text">کلیک بکە بۆ بەڕێوەبردنی یەکەکان</div>
                                </div>
                                <?php endif; ?>

                                <div class="col-12 mb-2">
                                    <p class="small text-muted mb-0" id="default_unit_hint">
                                        <?php
                                        $defHint = null;
                                        foreach ($units as $u) {
                                            if (!empty($u['is_default'])) {
                                                $defHint = $u;
                                                break;
                                            }
                                        }
                                        if (!$defHint && !empty($units)) {
                                            $defHint = $units[0];
                                        }
                                        ?>
                                        <?php if ($defHint): ?>
                                            یەکەی بنەڕەتی بۆ کاڵای نوێ: <strong><?php echo htmlspecialchars($defHint['name']); ?></strong><?php if (!empty($defHint['symbol'])): ?><span class="text-muted"> (<?php echo htmlspecialchars($defHint['symbol']); ?>)</span><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-warning">هیچ یەکەیەک نییە — لە بەڕێوەبردن یەکە زیاد بکە.</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Units Container -->
                            <div class="row mt-4" id="units_section">
                                <?php if ($useWholesaleUnitsUi): ?>
                                    <?php include __DIR__ . '/includes/wholesale-units-section.php'; ?>
                                <?php else: ?>
                                <div class="col-12">
                                    <div id="units_container">
                                        <!-- Units will be loaded here -->
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Additional Barcodes Section (only for products with units) -->
                            <?php if (!empty($productUnits)): ?>
                            <div class="row mt-4" id="additional_barcodes_section">
                                <div class="col-12">
                                    <h6 class="border-bottom pb-2 mb-3">
                                        <i class="bi bi-upc-scan text-primary"></i> بارکۆدە زیادەکان
                                    </h6>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i>
                                        دەتوانیت چەند بارکۆدێکی تر زیاد بکەیت بۆ هەمان کاڵا. لە کاتی فرۆشتندا هەر بارکۆدێکم لێدا هەمان کاڵا دەگەڕێتەوە.
                                    </div>
                                    
                                    <div id="additional_barcodes_container">
                                        <?php foreach ($additionalBarcodes as $barcode): ?>
                                            <div class="input-group mb-2">
                                                <input type="text" 
                                                       class="form-control" 
                                                       name="additional_barcodes[]" 
                                                       placeholder="بارکۆد داخڵ بکە" 
                                                       value="<?php echo htmlspecialchars($barcode['barcode']); ?>">
                                                <button type="button" class="btn btn-outline-danger remove-barcode" title="سڕینەوە">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="add_barcode_btn">
                                            <i class="bi bi-plus-circle"></i> زیادکردنی بارکۆدی نوێ
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Profit Calculation Display -->
                            <div class="row mt-4" id="profitCalculation" style="display: none;">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <h6 class="alert-heading">
                                            <i class="bi bi-calculator"></i> حیسابی قازانج
                                        </h6>
                                        <div class="row text-center">
                                            <div class="col-md-4">
                                                <strong>قازانجی تاک:</strong>
                                                <div class="h5 text-success" id="retailProfit">-</div>
                                            </div>
                                            <div class="col-md-4" id="wholesaleProfitDiv" style="display: none;">
                                                <strong>قازانجی جوملە:</strong>
                                                <div class="h5 text-success" id="wholesaleProfit">-</div>
                                            </div>
                                            <div class="col-md-4" id="specialProfitDiv" style="display: none;">
                                                <strong>قازانجی تایبەت:</strong>
                                                <div class="h5 text-success" id="specialProfit">-</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php
                            if (!empty($customFieldDefinitions)) {
                                $customFieldIds = array_map(function ($f) {
                                    return (int)$f['id'];
                                }, $customFieldDefinitions);
                                $optionsTreesByFieldId = getProductCustomFieldOptionsTreesForFields($conn, $userId, $customFieldIds);
                                $valuesByFieldId = [];
                                foreach ($customFieldDefinitions as $field) {
                                    $fid = (int)$field['id'];
                                    $valuesByFieldId[$fid] = $_POST['custom_fields'][$fid] ?? ($existingCustomValues[$fid] ?? '');
                                }
                                include __DIR__ . '/includes/custom_fields_form_partial.php';
                            }
                            ?>

                            <!-- Action Buttons -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between">
                                        <a href="<?php echo htmlspecialchars($returnUrl); ?>" class="btn btn-secondary">
                                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                                        </a>
                                        
                                        <div class="d-flex gap-2">
                                            <button type="submit" name="save_and_continue" class="btn btn-outline-success">
                                                <i class="bi bi-check"></i> پاشەکەوت و بەردەوامبوون
                                            </button>
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-check-circle"></i> پاشەکەوتکردن و گەڕانەوە
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-xl-4">
                <!-- Product Stats -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-graph-up"></i> ئامارەکانی کاڵا
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php
                        // حیسابی بەردەست و نرخەکان لە یەکەکان
                        $displayStock = 0;
                        $displayPrice = 0;
                        $displayUnitName = '';
                        $displayCurrency = $product['currency'] ?? 'IQD';
                        $totalStock = 0;
                        $totalMinStock = 0;
                        $unitCount = count($productUnits);
                        
                        if ($unitCount > 1) {
                            // ئەگەر زیاتر لە یەک یەکە هەبوو، ئەو یەکەیە دۆزینەوە کە کەمترین بەردەستی هەیە
                            $minStockUnit = null;
                            foreach ($productUnits as $unit) {
                                $totalStock += $unit['stock_quantity'];
                                $totalMinStock += $unit['min_stock'];
                                if ($minStockUnit === null || $unit['stock_quantity'] < $minStockUnit['stock_quantity']) {
                                    $minStockUnit = $unit;
                                }
                            }
                            // پیشاندانی یەکەی کەمترین بەردەست
                            $displayStock = $minStockUnit['stock_quantity'];
                            $displayPrice = $minStockUnit['sell_price'];
                            $displayUnitName = $minStockUnit['unit_name'];
                            $displayCurrency = $minStockUnit['currency'] ?? $displayCurrency;
                        } elseif ($unitCount > 0) {
                            // ئەگەر تەنها یەک یەکە هەبوو
                            $unit = $productUnits[0];
                            $displayStock = $unit['stock_quantity'];
                            $displayPrice = $unit['sell_price'];
                            $displayUnitName = $unit['unit_name'];
                            $totalStock = $unit['stock_quantity'];
                            $totalMinStock = $unit['min_stock'];
                            $displayCurrency = $unit['currency'] ?? $displayCurrency;
                        } else {
                            // ئەگەر هیچ یەکەیەک نییە، زانیارییەکانی سەرەکی بەکاربهێنە
                            $displayStock = $product['stock_quantity'];
                            $displayPrice = $product['sell_price'];
                            $totalStock = $product['stock_quantity'];
                            $totalMinStock = $product['min_stock'];
                        }
                        ?>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="text-center p-3 bg-body-secondary rounded">
                                    <h4 class="text-primary mb-1"><?php echo $displayStock == floor($displayStock) ? number_format($displayStock, 0) : number_format($displayStock, 2); ?></h4>
                                    <small class="text-muted">بەردەست</small>
                                    <?php if ($unitCount > 1 && !empty($displayUnitName)): ?>
                                        <div class="mt-2 pt-2 border-top">
                                            <small class="text-muted d-block">گەوورەترین یەکە</small>
                                            <strong class="text-warning"><?php echo htmlspecialchars($displayUnitName); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-3 bg-body-secondary rounded">
                                    <h4 class="text-success mb-1"><?php echo formatCurrencyAmount($displayPrice, $displayCurrency); ?></h4>
                                    <small class="text-muted">نرخ</small>
                                    <?php if ($unitCount > 1): ?>
                                        <div class="mt-2 pt-2 border-top">
                                            <small class="text-muted d-block"><?php echo $unitCount; ?> یەکە</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($unitCount > 1): ?>
                        <div class="alert alert-info mt-3 mb-0 p-2 small">
                            <i class="bi bi-info-circle"></i>
                            زیاتر لە یەک یەکە هەیە، کەمترین بەردەست پیشان دراوە
                        </div>
                        <?php endif; ?>
                        
                        
                        <div class="mt-3">
                            <?php
                            $status = getProductStatus($totalStock, $totalMinStock, empty($isCurtainShopMode) ? $product['expiry_date'] : null);
                            ?>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-<?php echo $status['class']; ?> me-2">
                                    <?php echo $status['text']; ?>
                                </span>
                                
                                <?php if (empty($isCurtainShopMode) && $product['expiry_date']): ?>
                                    <small class="text-muted">
                                        بەسەرچوون: <?php echo date('Y/m/d', strtotime($product['expiry_date'])); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mt-3 small text-muted">
                            <div class="d-flex justify-content-between">
                                <span>دروستکراوە:</span>
                                <span><?php echo date('Y/m/d', strtotime($product['created_at'])); ?></span>
                            </div>
                            <?php if ($product['updated_at'] != $product['created_at']): ?>
                                <div class="d-flex justify-content-between">
                                    <span>دوایین نوێکردنەوە:</span>
                                    <span><?php echo date('Y/m/d', strtotime($product['updated_at'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sales History -->
                <?php if (!empty($salesHistory)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-clock-history"></i> مێژووی فرۆشتن (دوایین 10)
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($salesHistory as $sale): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <small class="text-muted">
                                                    <?php echo $sale['invoice_number']; ?>
                                                    <?php if ($sale['customer_name']): ?>
                                                        - <?php echo htmlspecialchars($sale['customer_name']); ?>
                                                    <?php endif; ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo date('Y/m/d H:i', strtotime($sale['sale_date'])); ?>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <span class="fw-bold text-success">
                                                    <?php echo formatMoney($sale['total_price']); ?>
                                                </span>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo $sale['quantity']; ?> دانە
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($returnsHistory)): ?>
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-arrow-return-left"></i> مێژووی گەڕانەوە (دوایین 10)
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($returnsHistory as $ret): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($ret['invoice_number']); ?>
                                                    <?php if (!empty($ret['customer_name'])): ?>
                                                        - <?php echo htmlspecialchars($ret['customer_name']); ?>
                                                    <?php endif; ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo date('Y/m/d H:i', strtotime($ret['sale_date'])); ?>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <span class="fw-bold text-danger">
                                                    <?php echo formatMoney($ret['total_price']); ?>
                                                </span>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo $ret['quantity'] == floor($ret['quantity']) ? number_format($ret['quantity'], 0) : number_format($ret['quantity'], 2); ?> دانە
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Form -->
    <form id="deleteForm" method="POST" action="<?php echo url('user/products/delete.php'); ?>" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="id" value="<?php echo $productId; ?>">
        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($returnUrl); ?>">
    </form>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo asset('js/main.js'); ?>"></script>
    
    <!-- JsBarcode Library -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quagga@0.12.1/dist/quagga.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation
            const form = document.querySelector('.needs-validation');
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);

            // Image upload handling
            const imageInput = document.getElementById('product_image');
            const imagePreview = document.getElementById('imagePreview');
            const fileUploadArea = imagePreview.parentElement;

            // Click to upload
            fileUploadArea.addEventListener('click', function() {
                imageInput.click();
            });

            // File input change
            imageInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    handleImagePreview(this.files[0]);
                }
            });

            function handleImagePreview(file) {
                if (file.type.startsWith('image/')) {
                    // Compress and resize image before preview
                    compressImage(file, function(compressedFile) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            imagePreview.innerHTML = `
                                <img src="${e.target.result}" alt="Preview" 
                                     class="img-fluid rounded mb-2" style="max-height: 200px;">
                             
                                <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="clearImagePreview()">
                                    <i class="bi bi-trash"></i> سڕینەوە
                                </button>
                            `;
                        };
                        reader.readAsDataURL(compressedFile);
                        
                        // Update the file input with compressed file
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(compressedFile);
                        imageInput.files = dataTransfer.files;
                    });
                }
            }

            // Compress single image
            function compressImage(file, callback) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const canvas = document.createElement('canvas');
                        const ctx = canvas.getContext('2d');
                        
                        // Calculate new dimensions (max 1920x1080)
                        let { width, height } = calculateDimensions(img.width, img.height, 1920, 1080);
                        
                        canvas.width = width;
                        canvas.height = height;
                        
                        // Draw resized image
                        ctx.drawImage(img, 0, 0, width, height);
                        
                        // Convert to blob with quality 0.8
                        canvas.toBlob(function(blob) {
                            // Create new file with compressed data
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

            function calculateDimensions(originalWidth, originalHeight, maxWidth, maxHeight) {
                let width = originalWidth;
                let height = originalHeight;
                
                // Calculate aspect ratio
                const aspectRatio = originalWidth / originalHeight;
                
                // Resize if too large
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

            // Generate barcode
            document.getElementById('generateBarcode').addEventListener('click', function() {
                const barcodeInput = document.getElementById('barcode');
                const timestamp = Date.now();
                const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                barcodeInput.value = timestamp.toString().slice(-8) + random;
            });

            // Print barcode - open barcode page with barcode value and product name
            document.getElementById('printBarcodeBtn').addEventListener('click', function() {
                const barcodeInput = document.getElementById('barcode');
                const nameInput = document.getElementById('name');
                const barcodeValue = barcodeInput.value.trim();
                const productName = nameInput.value.trim();
                if (barcodeValue) {
                    let url = 'barcode/index.php?barcode=' + encodeURIComponent(barcodeValue);
                    if (productName) {
                        url += '&name=' + encodeURIComponent(productName);
                    }
                    const sellPriceInput = document.querySelector('input[name*="[sell_price]"]');
                    const currencySelect = document.querySelector('select[name*="[currency]"]');
                    const sellPriceValue = sellPriceInput ? sellPriceInput.value.trim() : '';
                    const currencyValue = currencySelect ? currencySelect.value : 'IQD';
                    if (sellPriceValue) {
                        url += '&price=' + encodeURIComponent(sellPriceValue) + '&currency=' + encodeURIComponent(currencyValue);
                    }
                    window.open(url, '_blank');
                } else {
                    alert('تکایە سەرەتا بارکۆد داخڵ بکە');
                }
            });

            // Delete image checkbox
            document.getElementById('deleteImage')?.addEventListener('change', function() {
                const currentImage = document.getElementById('currentImage');
                if (this.checked) {
                    currentImage.style.opacity = '0.5';
                } else {
                    currentImage.style.opacity = '1';
                }
            });
        });

        function clearImagePreview() {
            const imageInput = document.getElementById('product_image');
            const imagePreview = document.getElementById('imagePreview');
            
            imageInput.value = '';
            imagePreview.innerHTML = `
                <i class="bi bi-cloud-upload display-4 text-muted mb-2"></i>
                <p class="mb-1">کلیک بکە یان وێنە ڕابکێشە</p>
                <small class="text-muted">JPG, PNG, GIF - زیاتر لە 5MB نەبێت</small>
            `;
        }

        function confirmDelete() {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم کاڵایە؟ ئەم کردارە ناگەڕێتەوە!')) {
                document.getElementById('deleteForm').submit();
            }
        }

        // Auto-save draft (optional feature)
        let autoSaveTimeout;
        const formInputs = document.querySelectorAll('input, select, textarea');
        
        formInputs.forEach(input => {
            input.addEventListener('input', function() {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(saveDraft, 2000); // Save after 2 seconds of inactivity
            });
        });

        function saveDraft() {
            // This would save form data to localStorage for recovery
            const formData = new FormData(document.querySelector('form'));
            const draftData = {};
            
            for (let [key, value] of formData.entries()) {
                if (key !== 'csrf_token' && key !== 'product_image') {
                    draftData[key] = value;
                }
            }
            
            localStorage.setItem('product_edit_draft_<?php echo $productId; ?>', JSON.stringify(draftData));
        }

        // Show unsaved changes warning
        let formChanged = false;
        formInputs.forEach(input => {
            input.addEventListener('change', function() {
                formChanged = true;
            });
        });

        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'تۆ گۆڕانکاری کردووت کە پاشەکەوت نەکراون. ئایا دڵنیایت لە جێهێشتنی پەڕەکە؟';
            }
        });

        // Remove warning when form is submitted
        document.querySelector('form').addEventListener('submit', function() {
            formChanged = false;
        });
        
        // Additional Barcodes Management Functions
        function updateAdditionalBarcodesSection() {
            const barcodesSection = document.getElementById('additional_barcodes_section');
            if (!barcodesSection) {
                return;
            }
            <?php if ($useWholesaleUnitsUi): ?>
            const primaryId = document.getElementById('wh_primary_unit_id')?.value;
            const secondaryId = document.getElementById('wh_secondary_unit_id')?.value;
            if (primaryId && secondaryId && primaryId !== secondaryId) {
                barcodesSection.style.display = 'block';
            } else {
                barcodesSection.style.display = 'none';
            }
            return;
            <?php endif; ?>
            const unitsContainer = document.getElementById('units_container');
            const units = unitsContainer ? unitsContainer.querySelectorAll('.unit-card') : [];
            
            if (units.length > 0) {
                barcodesSection.style.display = 'block';
            } else {
                barcodesSection.style.display = 'none';
            }
        }
        
        // Add new barcode button event listener
        const addBarcodeBtn = document.getElementById('add_barcode_btn');
        if (addBarcodeBtn) {
            addBarcodeBtn.addEventListener('click', function() {
                const container = document.getElementById('additional_barcodes_container');
                if (container) {
                    const newBarcodeDiv = document.createElement('div');
                    newBarcodeDiv.className = 'input-group mb-2';
                    newBarcodeDiv.innerHTML = `
                        <input type="text" 
                               class="form-control" 
                               name="additional_barcodes[]" 
                               placeholder="بارکۆد داخڵ بکە" 
                               value="">
                        <button type="button" class="btn btn-outline-danger remove-barcode" title="سڕینەوە">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                    container.appendChild(newBarcodeDiv);
                    
                    // Add event listener to the new remove button
                    const removeBtn = newBarcodeDiv.querySelector('.remove-barcode');
                    if (removeBtn) {
                        removeBtn.addEventListener('click', function() {
                            newBarcodeDiv.remove();
                        });
                    }
                }
            });
        }
        
        // Event delegation for remove barcode buttons (handles both existing and dynamically added buttons)
        const barcodesContainer = document.getElementById('additional_barcodes_container');
        if (barcodesContainer) {
            barcodesContainer.addEventListener('click', function(e) {
                if (e.target.closest('.remove-barcode')) {
                    const barcodeGroup = e.target.closest('.input-group');
                    if (barcodeGroup) {
                        barcodeGroup.remove();
                    }
                }
            });
        }
        
        // Initialize barcodes section visibility on page load
        updateAdditionalBarcodesSection();
    </script>
    
    <!-- Units Management JavaScript -->
    <?php if (!$useWholesaleUnitsUi): ?>
    <script>
        // Units management
        const units = <?php echo json_encode($units); ?>;
        const productUnits = <?php echo json_encode($productUnits); ?>;
        const unitsManageEnabled = <?php echo $canManageProductUnits ? 'true' : 'false'; ?>;
        let unitCounter = 0;

        productUnits.sort(function(a, b) {
            return (parseInt(b.is_primary, 10) || 0) - (parseInt(a.is_primary, 10) || 0);
        });
        
        function formatNumber(value) {
            if (value === '' || value === null || value === undefined) return '';
            const num = parseFloat(value);
            if (isNaN(num)) return '';
            return num === Math.floor(num) ? num.toString() : num.toFixed(2);
        }

        function formatQtyForInput(n) {
            if (typeof n !== 'number' || isNaN(n)) return '0';
            const s = n.toFixed(8).replace(/\.?0+$/, '');
            return s === '' ? '0' : s;
        }

        function syncDerivedUnitStock() {
            const primary = document.querySelector('#units_container .unit-card[data-is-primary="1"]');
            if (!primary) return;
            const baseStock = parseFloat(primary.querySelector('.unit-stock-primary[name*="[stock_quantity]"]')?.value) || 0;
            const baseMin = parseFloat(primary.querySelector('.unit-stock-primary[name*="[min_stock]"]')?.value) || 0;
            document.querySelectorAll('#units_container .unit-card[data-is-primary="0"]').forEach(function(card) {
                const factor = parseFloat(card.querySelector('input[name*="[conversion_factor]"]')?.value) || 1;
                const stockIn = card.querySelector('.unit-stock-derived[name*="[stock_quantity]"]');
                const minIn = card.querySelector('.unit-stock-derived[name*="[min_stock]"]');
                if (stockIn && factor > 0) stockIn.value = formatQtyForInput(baseStock / factor);
                if (minIn && factor > 0) minIn.value = formatQtyForInput(baseMin / factor);
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            productUnits.forEach(function(unit) {
                const isP = parseInt(unit.is_primary, 10) === 1;
                const cf = parseFloat(unit.conversion_factor) > 0 ? parseFloat(unit.conversion_factor) : 1;
                addUnitToContainer(unit.unit_id, isP, unit, isP ? 1 : cf);
            });
            updateUnitDropdown();
            syncDerivedUnitStock();
        });

        let pendingUnitIdToAdd = null;

        if (unitsManageEnabled) {
        document.getElementById('add_selected_unit_btn').addEventListener('click', function() {
            const selectedUnitId = document.getElementById('unit_selection_dropdown').value;
            if (!selectedUnitId) {
                showMessage('تکایە یەکە هەڵبژێرە', 'warning');
                return;
            }
            const existingUnits = document.querySelectorAll('input[name*="[unit_id]"]');
            for (let input of existingUnits) {
                if (input.value == selectedUnitId) {
                    showMessage('ئەم یەکەیە پێشتر زیادکراوە', 'warning');
                    return;
                }
            }

            const existingCards = document.querySelectorAll('#units_container .unit-card');
            if (existingCards.length === 0) {
                addUnitToContainer(selectedUnitId, true, null, 1);
                showMessage('یەکە زیادکرا', 'success');
                document.getElementById('unit_selection_dropdown').value = '';
                return;
            }

            const primaryCard = document.querySelector('#units_container .unit-card[data-is-primary="1"]');
            const primaryUnitIdEl = primaryCard ? primaryCard.querySelector('input[name*="[unit_id]"]') : null;
            const primaryUnitId = primaryUnitIdEl ? primaryUnitIdEl.value : null;
            const primaryUnit = units.find(u => String(u.id) === String(primaryUnitId)) || null;
            const newUnit = units.find(u => String(u.id) === String(selectedUnitId));
            pendingUnitIdToAdd = selectedUnitId;
            const fEl = document.getElementById('unitConversionFactorInput');
            const modalEl = document.getElementById('unitConversionModal');
            if (!fEl || !modalEl || !primaryUnit || !newUnit) {
                showMessage('مۆداڵی ڕێژە نەدۆزرایەوە. پەڕەکە نوێ بکەرەوە.', 'danger');
                return;
            }
            fEl.value = '';
            populateConversionSelects(newUnit, primaryUnit);
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        });
        }

        // پڕکردنەوەی لیستەکانی مۆداڵی ڕێژە بە یەکەی بنەڕەت و یەکەی نوێ
        function populateConversionSelects(newUnit, primaryUnit) {
            const leftSel = document.getElementById('unitConvLeftSelect');
            const rightSel = document.getElementById('unitConvRightSelect');
            if (!leftSel || !rightSel || !newUnit || !primaryUnit) return;
            const opts = [primaryUnit, newUnit];
            function fill(sel, selectedId) {
                sel.innerHTML = '';
                opts.forEach(function(u) {
                    const o = document.createElement('option');
                    o.value = u.id;
                    o.textContent = u.name + (u.symbol ? ' (' + u.symbol + ')' : '');
                    if (String(u.id) === String(selectedId)) o.selected = true;
                    sel.appendChild(o);
                });
            }
            // بنەڕەت: ١ یەکەی نوێ = چەند یەکەی بنەڕەت
            fill(leftSel, newUnit.id);
            fill(rightSel, primaryUnit.id);
            updateConversionPreview();
        }

        // پاراستنی جیاوازی نێوان دوو لیستەکە (هەردووکیان یەک یەکە نەبن)
        function syncConversionSelects(changed) {
            const leftSel = document.getElementById('unitConvLeftSelect');
            const rightSel = document.getElementById('unitConvRightSelect');
            if (!leftSel || !rightSel) return;
            if (changed === 'left') {
                Array.from(rightSel.options).forEach(function(o) { o.selected = (o.value !== leftSel.value); });
            } else {
                Array.from(leftSel.options).forEach(function(o) { o.selected = (o.value !== rightSel.value); });
            }
            updateConversionPreview();
        }

        function updateConversionPreview() {
            const leftSel = document.getElementById('unitConvLeftSelect');
            const rightSel = document.getElementById('unitConvRightSelect');
            const fEl = document.getElementById('unitConversionFactorInput');
            const qEl = document.getElementById('unitConversionQuestion');
            const calcEl = document.getElementById('unitConversionCalcHint');
            if (!leftSel || !rightSel || !qEl) return;
            const leftName = leftSel.selectedIndex >= 0 ? leftSel.options[leftSel.selectedIndex].textContent : '';
            const rightName = rightSel.selectedIndex >= 0 ? rightSel.options[rightSel.selectedIndex].textContent : '';
            const n = fEl && fEl.value ? fEl.value : '؟';
            qEl.innerHTML = `<span class="badge bg-primary fs-6 px-3 py-2">١ ${leftName} = ${n} ${rightName}</span>`;

            if (calcEl) {
                if (leftName.includes('تۆپ') || rightName.includes('تۆپ') || leftName.includes('مەتر') || rightName.includes('مەتر')) {
                    const factorNum = parseFloat(n) || 0;
                    calcEl.innerHTML = `
                        <div class="alert alert-info py-2 px-3 mt-2 mb-0 small">
                            <i class="bi bi-calculator me-1"></i>
                            <strong>یاسای حیسابکردن:</strong> قەبارەی قوماش (بە مەتر) = <strong>ژمارەی تۆپ × ${factorNum > 0 ? factorNum : 'مەتری تۆپ'}</strong>
                        </div>
                    `;
                } else {
                    calcEl.innerHTML = '';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const leftSel = document.getElementById('unitConvLeftSelect');
            const rightSel = document.getElementById('unitConvRightSelect');
            const fEl = document.getElementById('unitConversionFactorInput');
            if (leftSel) leftSel.addEventListener('change', function() { syncConversionSelects('left'); });
            if (rightSel) rightSel.addEventListener('change', function() { syncConversionSelects('right'); });
            if (fEl) fEl.addEventListener('input', updateConversionPreview);

            const confirmBtn = document.getElementById('unitConversionConfirmBtn');
            if (!confirmBtn) return;
            confirmBtn.addEventListener('click', function() {
                const n = parseFloat(document.getElementById('unitConversionFactorInput').value);
                const lSel = document.getElementById('unitConvLeftSelect');
                if (!pendingUnitIdToAdd || !(n > 0) || !lSel) {
                    showMessage('تکایە ژمارەیەکی دروست بنووسە (لە سفر گەورەتر)', 'warning');
                    return;
                }
                // conversion_factor = چەند یەکەی بنەڕەت لە ١ یەکەی نوێدایە
                let factor;
                if (String(lSel.value) === String(pendingUnitIdToAdd)) {
                    // ١ یەکەی نوێ = n یەکەی بنەڕەت
                    factor = n;
                } else {
                    // ١ یەکەی بنەڕەت = n یەکەی نوێ
                    factor = 1 / n;
                }
                if (!(factor > 0)) {
                    showMessage('تکایە ژمارەیەکی دروست بنووسە (لە سفر گەورەتر)', 'warning');
                    return;
                }
                addUnitToContainer(pendingUnitIdToAdd, false, null, factor);
                showMessage('یەکە زیادکرا', 'success');
                document.getElementById('unit_selection_dropdown').value = '';
                pendingUnitIdToAdd = null;
                const m = document.getElementById('unitConversionModal');
                if (m) {
                    const inst = bootstrap.Modal.getInstance(m) || bootstrap.Modal.getOrCreateInstance(m);
                    inst.hide();
                }
                syncDerivedUnitStock();
            });
        });
        
        // Update dropdown options to hide already added units
        function updateUnitDropdown() {
            if (!unitsManageEnabled) return;
            const dropdown = document.getElementById('unit_selection_dropdown');
            const existingUnits = document.querySelectorAll('input[name*="[unit_id]"]');
            const addedUnitIds = Array.from(existingUnits).map(input => input.value);
            
            // Show/hide options based on whether they're already added
            Array.from(dropdown.options).forEach(option => {
                if (option.value && addedUnitIds.includes(option.value)) {
                    option.style.display = 'none';
                } else {
                    option.style.display = 'block';
                }
            });
            
            // Check if all units are added
            const availableOptions = Array.from(dropdown.options).filter(option => 
                option.value && option.style.display !== 'none'
            );
            
            if (availableOptions.length === 0) {
                dropdown.disabled = true;
                document.getElementById('add_selected_unit_btn').disabled = true;
                showMessage('هەموو یەکەکان زیادکراون', 'info');
            } else {
                dropdown.disabled = false;
                document.getElementById('add_selected_unit_btn').disabled = false;
            }
        }
        
        
        let isSyncingStock = false;
        function unitConversionFactorOf(card) {
            const f = parseFloat(card.querySelector('input[name*="[conversion_factor]"]')?.value);
            return f > 0 ? f : 1;
        }
        // کاتێک بڕی بەردەستی هەر یەکەیەک گۆڕدرا، بڕی بەردەستی یەکەکانی تر بەپێی ڕێژە خۆکارانە نوێ بکەرەوە
        function recalcStockFromInput(inputEl) {
            if (isSyncingStock || !inputEl) return;
            const card = inputEl.closest('.unit-card');
            if (!card) return;
            const isMin = /\[min_stock\]/.test(inputEl.name || '');
            const fieldSel = isMin ? 'input[name*="[min_stock]"]' : 'input[name*="[stock_quantity]"]';
            const factor = unitConversionFactorOf(card);
            const val = parseFloat(inputEl.value);
            const base = (isNaN(val) ? 0 : val) * factor; // بڕ بە یەکەی بنەڕەت
            isSyncingStock = true;
            document.querySelectorAll('#units_container .unit-card').forEach(function(other) {
                if (other === card) return;
                const target = other.querySelector(fieldSel);
                if (!target) return;
                const of = unitConversionFactorOf(other);
                target.value = of > 0 ? formatQtyForInput(base / of) : '0';
            });
            isSyncingStock = false;
        }

        let isSyncingPrice = false;
        function formatPriceForInput(n) {
            if (typeof n !== 'number' || isNaN(n)) return '';
            const r = Math.round(n * 1000) / 1000;
            const s = r.toFixed(3).replace(/\.?0+$/, '');
            return s === '' ? '0' : s;
        }
        // کاتێک نرخی هەر یەکەیەک گۆڕدرا، نرخی یەکەکانی تر بەپێی ڕێژە خۆکارانە حیساب بکە
        // (نرخ بەپێچەوانەی بڕ: یەکەی گەورەتر نرخی زیاتری هەیە)
        function recalcPriceFromInput(inputEl) {
            if (isSyncingPrice || !inputEl) return;
            const card = inputEl.closest('.unit-card');
            if (!card) return;
            const m = (inputEl.name || '').match(/\[(buy_price|sell_price|wholesale_price|special_price)\]/);
            if (!m) return;
            const fieldSel = 'input[name*="[' + m[1] + ']"]';
            const factor = unitConversionFactorOf(card);
            const raw = inputEl.value;
            const val = parseFloat(raw);
            const basePerPrimary = (isNaN(val) ? 0 : val) / (factor > 0 ? factor : 1); // نرخ بۆ یەکەی بنەڕەت
            isSyncingPrice = true;
            document.querySelectorAll('#units_container .unit-card').forEach(function(other) {
                if (other === card) return;
                const target = other.querySelector(fieldSel);
                if (!target) return;
                const of = unitConversionFactorOf(other);
                target.value = (raw === '') ? '' : formatPriceForInput(basePerPrimary * of);
            });
            isSyncingPrice = false;
        }
        // پڕکردنەوەی نرخی یەکەی نوێ لە نرخی یەکەی بنەڕەتەوە بەپێی ڕێژە
        function seedDerivedPricesFromPrimary(card, factor) {
            const primary = document.querySelector('#units_container .unit-card[data-is-primary="1"]');
            if (!primary || !card || !(factor > 0)) return;
            ['buy_price', 'sell_price', 'wholesale_price', 'special_price'].forEach(function(field) {
                const pIn = primary.querySelector('input[name*="[' + field + ']"]');
                const tIn = card.querySelector('input[name*="[' + field + ']"]');
                if (!pIn || !tIn) return;
                const pv = parseFloat(pIn.value);
                if (pIn.value !== '' && !isNaN(pv)) {
                    tIn.value = formatPriceForInput(pv * factor);
                }
            });
        }

        function addUnitToContainer(unitId = null, isPrimary = false, existingData = null, conversionFactor = 1) {
            const container = document.getElementById('units_container');
            const unitCard = document.createElement('div');
            unitCard.className = 'card mb-3 unit-card';
            unitCard.innerHTML = createUnitCardHTML(unitId, isPrimary, existingData, conversionFactor);
            const unit = unitId ? units.find(u => String(u.id) === String(unitId)) : null;
            unitCard.setAttribute('data-is-primary', isPrimary ? '1' : '0');
            unitCard.setAttribute('data-unit-label', unit ? unit.name : '');
            container.insertBefore(unitCard, container.firstChild);
            addUnitCardEventListeners(unitCard);
            if (!isPrimary && !existingData) {
                const seedFactor = parseFloat(conversionFactor) > 0 ? parseFloat(conversionFactor) : 1;
                seedDerivedPricesFromPrimary(unitCard, seedFactor);
            }
            updateUnitDropdown();
            updateAdditionalBarcodesSection();
            unitCounter++;
            syncDerivedUnitStock();
        }
        
        function createUnitCardHTML(unitId = null, isPrimary = false, existingData = null, conversionFactor = 1) {
            const unit = unitId ? units.find(u => String(u.id) === String(unitId)) : null;
            const unitName = unit ? unit.name : '';
            const unitSymbol = unit ? unit.symbol : '';
            const buyPrice = existingData ? formatNumber(existingData.buy_price) : '';
            const sellPrice = existingData ? formatNumber(existingData.sell_price) : '';
            const wholesalePrice = existingData ? formatNumber(existingData.wholesale_price) : '';
            const specialPrice = existingData ? formatNumber(existingData.special_price) : '';
            const stockQuantity = existingData ? formatNumber(existingData.stock_quantity) : '0';
            const minStock = existingData ? formatNumber(existingData.min_stock) : '0';
            const currency = existingData && existingData.currency ? existingData.currency : 'IQD';
            const factorVal = isPrimary ? 1 : (parseFloat(conversionFactor) > 0 ? parseFloat(conversionFactor) : 1);
            const stockReadonly = '';
            const minReadonly = '';
            const stockClass = isPrimary ? 'unit-stock-primary' : 'unit-stock-derived';
            const minClass = isPrimary ? 'unit-stock-primary' : 'unit-stock-derived';

            const primaryUnitName = (units.find(u => u.is_default == 1) || units[0] || {}).name || 'مەتر';
            const ratioBadge = !isPrimary && factorVal != 1 
                ? `<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-2"><i class="bi bi-link-45deg"></i> ١ ${unitName} = ${factorVal} ${primaryUnitName}</span>` 
                : '';

            return `
                <div class="card-header d-flex justify-content-between align-items-center bg-body-secondary">
                    <h6 class="mb-0">
                        <i class="bi bi-rulers"></i> ${unitName} ${unitSymbol ? '(' + unitSymbol + ')' : ''}
                        ${isPrimary ? '<span class="badge bg-primary ms-2">یەکەی بنەڕەت</span>' : ''}
                        ${ratioBadge}
                    </h6>
                    <div class="d-flex gap-2">
                        ${unitsManageEnabled ? `<button type="button" class="btn btn-sm btn-outline-danger remove-unit">
                            <i class="bi bi-trash"></i> سڕینەوە
                        </button>` : ''}
                    </div>
                </div>
                <div class="card-body">
                    <input type="hidden" name="units[${unitCounter}][unit_id]" value="${unitId || ''}">
                    <input type="hidden" name="units[${unitCounter}][is_primary]" value="${isPrimary ? '1' : '0'}">
                    <input type="hidden" name="units[${unitCounter}][conversion_factor]" value="${factorVal}">
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">
                                <i class="bi bi-currency-exchange"></i> دراوە
                            </label>
                            <select class="form-select unit-currency-select" name="units[${unitCounter}][currency]" data-unit-index="${unitCounter}">
                                <option value="IQD" ${currency === 'IQD' ? 'selected' : ''}>دینار (IQD)</option>
                                <option value="USD" ${currency === 'USD' ? 'selected' : ''}>دۆلار (USD)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">نرخی کڕین</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="units[${unitCounter}][buy_price]" 
                                       min="0" step="0.001" value="${buyPrice}">
                                <span class="input-group-text currency-label-${unitCounter}">${currency === 'USD' ? '$' : 'دینار'}</span>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">نرخی فرۆشتن (تاک)</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="units[${unitCounter}][sell_price]" 
                                       min="0" step="0.001" value="${sellPrice}">
                                <span class="input-group-text currency-label-${unitCounter}">${currency === 'USD' ? '$' : 'دینار'}</span>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">نرخی جوملە</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="units[${unitCounter}][wholesale_price]" 
                                       min="0" step="0.001" value="${wholesalePrice}">
                                <span class="input-group-text currency-label-${unitCounter}">${currency === 'USD' ? '$' : 'دینار'}</span>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">نرخی تایبەت</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="units[${unitCounter}][special_price]" 
                                       min="0" step="0.001" value="${specialPrice}">
                                <span class="input-group-text currency-label-${unitCounter}">${currency === 'USD' ? '$' : 'دینار'}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">بڕی بەردەست ${isPrimary ? '<small class="text-muted">(بنەڕەت)</small>' : '<small class="text-muted">(هاوتا)</small>'}</label>
                            <div class="input-group">
                                <input type="number" class="form-control ${stockClass}" name="units[${unitCounter}][stock_quantity]" 
                                       min="0" step="0.001" value="${stockQuantity}" ${stockReadonly}>
                                <span class="input-group-text">${unitSymbol || 'یەکە'}</span>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">کەمترین بەردەست ${isPrimary ? '<small class="text-muted">(بنەڕەت)</small>' : '<small class="text-muted">(هاوتا)</small>'}</label>
                            <div class="input-group">
                                <input type="number" class="form-control ${minClass}" name="units[${unitCounter}][min_stock]" 
                                       min="0" step="0.001" value="${minStock}" ${minReadonly}>
                                <span class="input-group-text">${unitSymbol || 'یەکە'}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function addUnitCardEventListeners(card) {
            const removeBtn = card.querySelector('.remove-unit');
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    const isPrimary = card.getAttribute('data-is-primary') === '1';
                    const total = document.querySelectorAll('#units_container .unit-card').length;
                    if (isPrimary && total > 1) {
                        showMessage('ناتوانیت یەکەی بنەڕەت بسڕیتەوە کاتێک یەکەی تر هەیە. سەرەتا یەکەکانی تر بسڕەرەوە.', 'warning');
                        return;
                    }
                    if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم یەکەیە؟')) {
                        card.remove();
                        showMessage('یەکە سڕایەوە', 'info');
                        updateUnitDropdown();
                        updateAdditionalBarcodesSection();
                        const remaining = document.querySelectorAll('#units_container .unit-card');
                        if (remaining.length === 1) {
                            const only = remaining[0];
                            only.setAttribute('data-is-primary', '1');
                            const hid = only.querySelector('input[name*="[is_primary]"]');
                            if (hid) hid.value = '1';
                            const cf = only.querySelector('input[name*="[conversion_factor]"]');
                            if (cf) cf.value = '1';
                            only.querySelectorAll('.unit-stock-derived').forEach(el => {
                                el.classList.remove('unit-stock-derived', 'bg-body-secondary');
                                el.classList.add('unit-stock-primary');
                                el.removeAttribute('readonly');
                                el.removeAttribute('tabindex');
                            });
                            const badge = only.querySelector('.card-header .badge');
                            if (badge) badge.remove();
                            const nh = only.querySelector('.card-header h6');
                            if (nh && !nh.querySelector('.badge')) {
                                nh.insertAdjacentHTML('beforeend', '<span class="badge bg-primary ms-2">یەکەی بنەڕەت</span>');
                            }
                        }
                        syncDerivedUnitStock();
                    }
                });
            }
            
            const currencySelect = card.querySelector('.unit-currency-select');
            if (currencySelect) {
                const unitIndex = currencySelect.getAttribute('data-unit-index');
                currencySelect.addEventListener('change', function() {
                    const selectedCurrency = this.value;
                    const currencyLabels = card.querySelectorAll('.currency-label-' + unitIndex);
                    currencyLabels.forEach(function(label) {
                        label.textContent = selectedCurrency === 'USD' ? '$' : 'دینار';
                    });
                });
            }

            card.querySelectorAll('.unit-stock-primary, .unit-stock-derived').forEach(function(inp) {
                inp.addEventListener('input', function() { recalcStockFromInput(inp); });
            });

            card.querySelectorAll('input[name*="[buy_price]"], input[name*="[sell_price]"], input[name*="[wholesale_price]"], input[name*="[special_price]"]').forEach(function(inp) {
                inp.addEventListener('input', function() { recalcPriceFromInput(inp); });
            });
        }
        
        function showMessage(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container');
            container.insertBefore(alertDiv, container.firstChild);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 3000);
        }
    </script>
    <?php else: ?>
    <script>
        function showMessage(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-' + (type || 'info') + ' alert-dismissible fade show';
            alertDiv.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            const container = document.querySelector('.container');
            if (container) {
                container.insertBefore(alertDiv, container.firstChild);
            }
            setTimeout(function() {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 3000);
        }
    </script>
    <script src="<?php echo url('user/products/assets/wholesale_units.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initWholesaleUnitsForm(
                <?php echo json_encode($units); ?>,
                <?php echo json_encode($wholesaleInitialData); ?>
            );
        });
    </script>
    <?php endif; ?>

    <?php if (!$useWholesaleUnitsUi): ?>
    <div class="modal fade" id="unitConversionModal" tabindex="-1" aria-labelledby="unitConversionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="unitConversionModalLabel"><i class="bi bi-arrow-left-right"></i> ڕێژەی یەکەکان</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        <i class="bi bi-info-circle"></i>
                        ڕێژەی نێوان یەکەکان دیاری بکە (بۆ نموونە: <strong>ئەم تۆپە چەند مەترە؟</strong>).
                    </p>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="fw-semibold">١</span>
                        <select id="unitConvLeftSelect" class="form-select" style="width:auto; min-width:120px;"></select>
                        <span class="fw-semibold">=</span>
                        <input type="number" class="form-control" id="unitConversionFactorInput" style="width:110px;" min="0.0001" step="any" placeholder="مەتری تۆپ">
                        <select id="unitConvRightSelect" class="form-select" style="width:auto; min-width:120px;"></select>
                    </div>

                    <!-- Quick buttons for common curtain roll lengths -->
                    <div class="d-flex flex-wrap gap-1 mt-2 align-items-center">
                        <span class="small text-muted me-1">هەڵبژاردنی خێرا:</span>
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill" onclick="document.getElementById('unitConversionFactorInput').value='20'; updateConversionPreview();">٢٠ مەتر</button>
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill" onclick="document.getElementById('unitConversionFactorInput').value='25'; updateConversionPreview();">٢٥ مەتر</button>
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill" onclick="document.getElementById('unitConversionFactorInput').value='30'; updateConversionPreview();">٣٠ مەتر</button>
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill" onclick="document.getElementById('unitConversionFactorInput').value='50'; updateConversionPreview();">٥٠ مەتر</button>
                    </div>

                    <p id="unitConversionQuestion" class="fw-semibold mt-3 mb-0 text-primary"></p>
                    <div id="unitConversionCalcHint"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                    <button type="button" class="btn btn-primary" id="unitConversionConfirmBtn">پشتڕاستکردنەوە</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Include Barcode Modal -->
    <?php include 'includes/barcode-modal.php'; ?>
    
    <!-- Include Units Management Modal -->
    <?php if ($canManageProductUnits): ?>
    <?php include 'includes/units-management-modal.php'; ?>
    <?php endif; ?>
</body>
</html>