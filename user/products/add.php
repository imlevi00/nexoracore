<?php
/**
 * زیادکردنی کاڵای نوێ - user/products/add.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
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
$useWholesaleUnitsUi = $isWholesaleDistributionMode && $canManageProductUnits;
$fabricWidth = null;
$fabricHeight = null;
$fabricMeasureUnit = 'cm';
$productsIndexBase = url('user/products/index.php');
$returnUrlInput = $_POST['return_url'] ?? ($_GET['return_url'] ?? $productsIndexBase);
$returnUrl = (strpos($returnUrlInput, $productsIndexBase) === 0) ? $returnUrlInput : $productsIndexBase;

$errors = [];
$success = false;
$customFieldDefinitions = getProductCustomFields($conn, $userId, true);
$parsedCustomFieldValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $stay_on_page = isset($_POST['add_and_continue']);
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
        if (!$canManageProductUnits && empty($product_units)) {
            $errors[] = 'یەکەی دانە نەدۆزرایەوە. تکایە لە بەڕێوەبردنی یەکەکان یەکەی بنەڕەت دیاری بکە.';
        }

        // یەکەی بنەڕەت و ڕێژەی گۆڕین (چەند یەکەی بنەڕەت لە ١ یەکەی ئەمەدایە)
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
        
        // Check if barcode exists (if provided) - check in both products and product_barcodes
        if (!empty($barcode)) {
            // Check in products table
            $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND user_id = ?");
            $stmt->bind_param("si", $barcode, $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'ئەم بارکۆدە پێشتر بەکارهاتووە';
            }
            $stmt->close();
            
            // Check in product_barcodes table
            $stmt = $conn->prepare("SELECT pb.id FROM product_barcodes pb 
                                    INNER JOIN products p ON pb.product_id = p.id 
                                    WHERE pb.barcode = ? AND p.user_id = ?");
            $stmt->bind_param("si", $barcode, $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'ئەم بارکۆدە پێشتر بەکارهاتووە';
            }
            $stmt->close();
        } else {
            // Generate barcode if not provided
            $barcode = generateBarcode();
            
            // Make sure generated barcode is unique
            $attempts = 0;
            do {
                $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
                $stmt->bind_param("s", $barcode);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                
                if ($exists) {
                    $barcode = generateBarcode();
                    $attempts++;
                }
            } while ($exists && $attempts < 10);
            
            if ($attempts >= 10) {
                $errors[] = 'نەتوانرا بارکۆدێکی یەکتا دروست بکرێت';
            }
        }
        
        

        // Validate category exists
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
        
      // Handle image upload (DigitalOcean Spaces)
$imagePath = null;
if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
    $uploadResult = upload_product_image_to_spaces($_FILES['product_image']);
    if ($uploadResult['success']) {
        $imagePath = $uploadResult['db_path'];
    } else {
        $errors = array_merge($errors, $uploadResult['errors'] ?? []);
    }
}
        
        // Insert product if no errors
        if (empty($errors)) {
            $conn->begin_transaction();
            
            try {
                // Get currency from primary unit (or default to IQD)
                $productCurrency = 'IQD';
                if (!empty($product_units)) {
                    foreach ($product_units as $u) {
                        if (!empty($u['is_primary'])) {
                            $productCurrency = $u['currency'] ?? 'IQD';
                            break;
                        }
                    }
                }
                
                // Insert main product (without removed price/stock columns)
                if ($isCurtainShopMode) {
                    $stmt = $conn->prepare("
                        INSERT INTO products (
                            user_id, category_id, name, barcode, expiry_date,
                            currency, image_path, fabric_width, fabric_height, fabric_measure_unit, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                        )
                    ");
                    $fabricWidthBind = $fabricWidth === null ? null : number_format($fabricWidth, 3, '.', '');
                    $fabricHeightBind = $fabricHeight === null ? null : number_format($fabricHeight, 3, '.', '');
                    $stmt->bind_param(
                        "iissssssss",
                        $userId, $category_id, $name, $barcode, $expiry_date,
                        $productCurrency, $imagePath, $fabricWidthBind, $fabricHeightBind, $fabricMeasureUnit
                    );
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO products (
                            user_id, category_id, name, barcode, expiry_date, 
                            currency, image_path, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, NOW()
                        )
                    ");
                    $stmt->bind_param(
                        "iisssss",
                        $userId, $category_id, $name, $barcode, $expiry_date,
                        $productCurrency, $imagePath
                    );
                }
            
            if ($stmt->execute()) {
                $productId = $conn->insert_id;
                    $stmt->close();
                    
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
                    
                    // Insert additional barcodes (only if product has units)
                    if (!empty($product_units) && !empty($additional_barcodes)) {
                        // Validate additional barcodes don't exist
                        foreach ($additional_barcodes as $addBarcode) {
                            // Check in products table
                            $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND user_id = ?");
                            $stmt->bind_param("si", $addBarcode, $userId);
                            $stmt->execute();
                            if ($stmt->get_result()->num_rows > 0) {
                                throw new Exception("بارکۆدی '$addBarcode' پێشتر بەکارهاتووە");
                            }
                            $stmt->close();
                            
                            // Check in product_barcodes table
                            $stmt = $conn->prepare("SELECT pb.id FROM product_barcodes pb 
                                                    INNER JOIN products p ON pb.product_id = p.id 
                                                    WHERE pb.barcode = ? AND p.user_id = ?");
                            $stmt->bind_param("si", $addBarcode, $userId);
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

                    if (!empty($customFieldDefinitions)) {
                        saveProductCustomFieldValues($conn, $userId, $productId, $parsedCustomFieldValues);
                    }
                    
                    $conn->commit();
                    $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $productId);
                    logProductChangeEvent(
                        'product.create',
                        'product',
                        $productId,
                        null,
                        $afterSnapshot,
                        [
                            'user_id' => $userId,
                            'current_user' => $currentUser,
                            'product_id' => $productId,
                            'source_module' => 'user/products/add.php',
                            'source_reference' => 'product_add'
                        ]
                    );
                
                // Log activity
                writeLog("Product added: $name (ID: $productId) with units by user: {$currentUser['email']}");
                
                // Log activity for analytics
                logActivity(
                    'product_add',
                    "کاڵای '$name' (ID: $productId) بە سەرکەوتوویی زیادکرا لەگەڵ " . count($product_units) . " یەکە"
                );
                
                setMessage('کاڵا بە سەرکەوتوویی زیادکرا', 'success');
                if ($stay_on_page) {
                    redirect(url('user/products/add.php?return_url=' . urlencode($returnUrl)));
                } else {
                    redirect($returnUrl);
                }
            } else {
                    throw new Exception('Failed to insert product');
                }
            } catch (Exception $e) {
                $conn->rollback();
                writeLog("Product add custom fields error for user {$userId}: " . $e->getMessage());
                $errors[] = 'هەڵەیەک ڕوویدا لە زیادکردنی کاڵا. دووبارە هەوڵ بدەرەوە';
            }
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

$csrf_token = Security::generateCSRFToken();
// تەنها لە GET: دوای «زیادکردن و مانەوە» یان سەردانی سەرەتایی — localStorage لە JS جێبەجێ دەکرێت
$apply_saved_category_from_browser = ($_SERVER['REQUEST_METHOD'] !== 'POST');
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>زیادکردنی کاڵای نوێ - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-form.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    <style>
        /* Barcode Scanner Styles */
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
<body class="products-module-page products-form-page">

    <?php
    $productsNavId = 'productsAddNav';
    $productsNavLinks = [
        ['href' => $returnUrl, 'icon' => 'bi-arrow-right', 'text' => 'گەڕانەوە بۆ کاڵاکان'],
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <!-- Main Content -->
    <div class="container py-4 products-page-content pf-wrap">
        <header class="pf-hero">
            <div class="pf-hero-main">
                <div class="pf-kicker"><i class="bi bi-box-seam"></i> بەشی کاڵاکان</div>
                <h1><i class="bi bi-plus-circle"></i> زیادکردنی کاڵای نوێ</h1>
                <p class="pf-hero-sub">ناو، بارکۆد، وێنە، نرخ و یەکەکان لە یەک فۆرم تۆمار بکە</p>
            </div>
            <a href="<?php echo htmlspecialchars($returnUrl); ?>" class="pf-btn pf-btn-ghost">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
        </header>

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

                        <form method="POST" enctype="multipart/form-data" class="needs-validation pf-form" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($returnUrl); ?>">

                            <section class="pf-panel">
                                <h2 class="pf-panel-head"><i class="bi bi-info-circle"></i> زانیاری بنەڕەت</h2>
                                <div class="pf-panel-body">
                            <div class="row">
                                <!-- Product Image -->
                                <div class="col-lg-4">
                                    <div class="mb-4 mb-lg-0">
                                        <label class="form-label">وێنەی کاڵا</label>
                                        
                                        <input type="file" name="product_image" id="product_image" 
                                               accept="image/*" class="d-none">
                                        <div class="file-upload-area text-center">
                                            <div id="imagePreview">
                                                <i class="bi bi-camera display-4 mb-3"></i>
                                                <p class="mb-2 fw-semibold">کلیک بکە یان وێنە ڕابکێشە</p>
                                                <small class="text-muted">JPG, PNG, GIF — زیاتر لە 5MB نەبێت</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Product Details -->
                                <div class="col-lg-8">
                                    <div class="row">
                                       <!-- Barcode - یەکەم خانە -->
<div class="col-md-12 mb-3">
    <label for="barcode" class="form-label">
        <i class="bi bi-upc-scan"></i> بارکۆد <span class="pf-req">*</span>
    </label>
    <div class="input-group">
        <input type="text" class="form-control" id="barcode" name="barcode" 
               value="<?php echo htmlspecialchars($_POST['barcode'] ?? ''); ?>" 
               placeholder="بارکۆد داخڵ بکە" autofocus>
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
    <small class="text-muted">بارکۆد بەتاڵ بهێڵەرەوە بۆ دروستکردنی خۆکار</small>
</div>


<!-- Product Name - دووەم خانە -->
<div class="col-md-12 mb-3">
    <label for="name" class="form-label">
        <i class="bi bi-box"></i> ناوی کاڵا <span class="pf-req">*</span>
    </label>
    <input type="text" class="form-control" id="name" name="name" 
           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
           required
           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
</div>
<?php
$fabricWidthValue = $_POST['fabric_width'] ?? formatCurtainFabricSizeNumber($fabricWidth);
$fabricHeightValue = $_POST['fabric_height'] ?? formatCurtainFabricSizeNumber($fabricHeight);
include __DIR__ . '/includes/curtain_fabric_size_fields.php';
?>

<!-- Category -->
<div class="col-md-12 mb-3">
    <label for="category_id" class="form-label">
        <i class="bi bi-tags"></i> کەتەلۆگ
    </label>
    <div class="d-flex align-items-center">
        <select class="form-select me-2" id="category_id" name="category_id">
            <option value="">هیچ کەتەلۆگێک نییە</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?php echo $category['id']; ?>"
                        <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($category['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a href="<?php echo url('user/products/categories.php?action=add'); ?>" 
           target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-plus-circle"></i> نوێ
        </a>
    </div>
</div>

                                        <!-- Expiry Date -->
                                        <?php if (empty($isCurtainShopMode)): ?>
                                        <div class="col-md-6 mb-3">
                                            <label for="expiry_date" class="form-label">
                                                <i class="bi bi-calendar-x"></i> بەرواری بەسەرچوون
                                            </label>
                                            <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                                                   value="<?php echo $_POST['expiry_date'] ?? ''; ?>">
                                            <div class="form-text">ئەگەر کاڵاکە بەسەر ناچێت، بەتاڵ جێی بهێڵە</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                                </div>
                            </section>

                            <!-- Units Section -->
                            <section class="pf-panel">
                                <h2 class="pf-panel-head"><i class="bi bi-rulers"></i> یەکەکان</h2>
                                <div class="pf-panel-body">
                            <div class="row">
                                <div class="col-12">
                                    <?php if ($useWholesaleUnitsUi): ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bi bi-boxes"></i>
                                        فۆرمی تایبەت بۆ دابەشکاری جوملە — نرخ و بڕی کارتۆن داخڵ بکە، نرخی دانە خۆکار دەردەکەوێت.
                                    </div>
                                    <?php elseif ($canManageProductUnits): ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i>
                                        یەکە هەڵبژێرە و کلیک لە زیادکردن بکە. نرخی کڕین، نرخی فرۆشتن و بڕی بەردەست ئۆپشناڵن؛ دەتوانیت بەتاڵ جێی بهێڵیتەوە.
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-secondary mb-0">
                                        <i class="bi bi-lock"></i>
                                        تەنها یەکەی <strong>دانە</strong> بەردەستە بۆ ئەم پاکێجە. نرخ و بڕی بەردەست لە خوارەوە داخڵ بکە.
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
                                        <!-- Units will be added here dynamically -->
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                                </div>
                            </section>

                            <!-- Additional Barcodes Section (only for products with units) -->
                            <section class="pf-panel" id="additional_barcodes_section" style="display: none;">
                                <h2 class="pf-panel-head"><i class="bi bi-upc-scan"></i> بارکۆدە زیادەکان</h2>
                                <div class="pf-panel-body">
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i>
                                        دەتوانیت چەند بارکۆدێکی تر زیاد بکەیت بۆ هەمان کاڵا. لە کاتی فرۆشتندا هەر بارکۆدێکم لێدا هەمان کاڵا دەگەڕێتەوە.
                                    </div>
                                    
                                    <div id="additional_barcodes_container">
                                        <!-- Additional barcodes will be added here dynamically -->
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="add_barcode_btn">
                                            <i class="bi bi-plus-circle"></i> زیادکردنی بارکۆدی نوێ
                                        </button>
                                    </div>
                                </div>
                            </section>


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
                                    $valuesByFieldId[$fid] = $_POST['custom_fields'][$fid] ?? '';
                                }
                                include __DIR__ . '/includes/custom_fields_form_partial.php';
                            }
                            ?>

                            <!-- Action Buttons -->
                            <div class="pf-actions">
                                <a href="<?php echo htmlspecialchars($returnUrl); ?>" class="pf-btn pf-btn-ghost">
                                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                                </a>
                                <div class="pf-actions-end">
                                    <button type="submit" name="add_and_continue" value="1" class="pf-btn pf-btn-outline">
                                        <i class="bi bi-plus-circle"></i> زیادکردن و مانەوە
                                    </button>
                                    <button type="submit" class="pf-btn pf-btn-primary">
                                        <i class="bi bi-check-circle"></i> زیادکردنی کاڵا
                                    </button>
                                </div>
                            </div>
                        </form>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo asset('js/main.js'); ?>"></script>
    
    <!-- JsBarcode Library -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    
    <!-- QuaggaJS for Barcode Scanning -->
    <script src="https://cdn.jsdelivr.net/npm/quagga@0.12.1/dist/quagga.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ADD_PRODUCT_CATEGORY_LS = 'kasher_add_product_category_id_u<?php echo (int)$userId; ?>';
            const categorySelect = document.getElementById('category_id');
            const applySavedCategory = <?php echo $apply_saved_category_from_browser ? 'true' : 'false'; ?>;

            if (applySavedCategory && categorySelect) {
                try {
                    const saved = localStorage.getItem(ADD_PRODUCT_CATEGORY_LS);
                    if (saved !== null && saved !== '') {
                        const hasOption = Array.from(categorySelect.options).some(function(opt) {
                            return opt.value === saved;
                        });
                        if (hasOption) {
                            categorySelect.value = saved;
                        }
                    }
                } catch (e) { /* وێبگەڕی تایبەت یان قەدەغەکراو */ }
            }

            const stayBtn = document.querySelector('button[name="add_and_continue"]');
            if (stayBtn && categorySelect) {
                stayBtn.addEventListener('click', function() {
                    try {
                        localStorage.setItem(ADD_PRODUCT_CATEGORY_LS, categorySelect.value || '');
                    } catch (e) { /* localStorage نەدەستە */ }
                });
            }

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

            // Drag and drop
            fileUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('border-primary');
            });

            fileUploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('border-primary');
            });

            fileUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('border-primary');
                
                const files = e.dataTransfer.files;
                if (files.length > 0 && files[0].type.startsWith('image/')) {
                    handleImagePreview(files[0]);
                }
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

            // Profit calculation is now handled per-unit in the unit cards

            // Format money function
            function formatMoney(amount) {
                return new Intl.NumberFormat('ar-IQ', {
                    style: 'decimal',
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0
                }).format(amount) + ' دینار';
            }
        });

        function clearImagePreview() {
            const imageInput = document.getElementById('product_image');
            const imagePreview = document.getElementById('imagePreview');
            
            imageInput.value = '';
            imagePreview.innerHTML = `
                <i class="bi bi-camera display-4 text-muted mb-3"></i>
                <p class="mb-2">کلیک بکە یان وێنە ڕابکێشە</p>
                <small class="text-muted">JPG, PNG, GIF - زیاتر لە 5MB نەبێت</small>
            `;
        }

        // Units management
        <?php if (!$useWholesaleUnitsUi): ?>
        const units = <?php echo json_encode($units); ?>;
        const unitsManageEnabled = <?php echo $canManageProductUnits ? 'true' : 'false'; ?>;
        let unitCounter = 0;
        
        // Helper function to format numbers (show whole numbers without decimals)
        function formatNumber(value) {
            if (!value) return '';
            const num = parseFloat(value);
            return num === Math.floor(num) ? num.toString() : num.toFixed(2);
        }
        
        function getDefaultUnitFromList() {
            const flagged = units.find(u => String(u.is_default) === '1' || u.is_default === 1);
            if (flagged) return flagged;
            return units.find(u => u.name === 'دانە') || units[0] || null;
        }

        window.onDefaultUnitChanged = function(newDefaultId) {
            if (!unitsManageEnabled) return;
            const cards = document.querySelectorAll('#units_container .unit-card');
            if (cards.length !== 1) return;
            const primary = cards[0];
            if (primary.getAttribute('data-is-primary') !== '1') return;
            const hid = primary.querySelector('input[name*="[unit_id]"]');
            if (!hid || String(hid.value) === String(newDefaultId)) return;
            primary.remove();
            addUnitToContainer(newDefaultId, true, null, 1);
        };

        document.addEventListener('DOMContentLoaded', function() {
            const defaultUnit = getDefaultUnitFromList();
            if (defaultUnit) {
                addUnitToContainer(defaultUnit.id, true, null, 1);
            }
            updateUnitDropdown();
            updateAdditionalBarcodesSection();
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
                <div class="card-header d-flex justify-content-between align-items-center pf-unit-head">
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
        <?php endif; ?>
        
        // Additional barcodes management
        let barcodeCounter = 0;
        
        // Update visibility of additional barcodes section
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
            const hasUnits = unitsContainer ? unitsContainer.querySelectorAll('.unit-card').length > 0 : false;
            
            if (hasUnits) {
                barcodesSection.style.display = 'block';
            } else {
                barcodesSection.style.display = 'none';
                document.getElementById('additional_barcodes_container').innerHTML = '';
                barcodeCounter = 0;
            }
        }
        
        // Add barcode button
        document.getElementById('add_barcode_btn').addEventListener('click', function() {
            addBarcodeInput();
        });
        
        function addBarcodeInput(barcodeValue = '') {
            const container = document.getElementById('additional_barcodes_container');
            const barcodeDiv = document.createElement('div');
            barcodeDiv.className = 'input-group mb-2';
            barcodeDiv.innerHTML = `
                <input type="text" 
                       class="form-control" 
                       name="additional_barcodes[]" 
                       placeholder="بارکۆد داخڵ بکە" 
                       value="${barcodeValue}">
                <button type="button" class="btn btn-outline-danger remove-barcode" title="سڕینەوە">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            
            container.appendChild(barcodeDiv);
            
            // Add remove event listener
            const removeBtn = barcodeDiv.querySelector('.remove-barcode');
            removeBtn.addEventListener('click', function() {
                barcodeDiv.remove();
            });
            
            barcodeCounter++;
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
    <?php if ($useWholesaleUnitsUi): ?>
    <script src="<?php echo url('user/products/assets/wholesale_units.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initWholesaleUnitsForm(<?php echo json_encode($units); ?>, null);
        });
    </script>
    <?php endif; ?>
    
    <!-- Include Barcode Modal -->
    <?php include 'includes/barcode-modal.php'; ?>

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
    
    <!-- Include Units Management Modal -->
    <?php if ($canManageProductUnits): ?>
    <?php include 'includes/units-management-modal.php'; ?>
    <?php endif; ?>

</body>
</html>