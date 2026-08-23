<?php
/**
 * بەڕێوەبردنی وردەکاریەکانی کاڵا - user/website/manage/manage_product_details.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// تاقیکردنی ئەوەی یوزەر سەرەکیە (نەک لاوەکی)
if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    header('Location: ' . url('user/dashboard/index.php'));
    exit;
}

/** CSRF لە سەرەتای داواکاری دابنێ (پێش POST) بۆ هاوتاکردنی session لەگەڵ فۆڕم */
$csrf_token = Security::generateCSRFToken();

$success = '';
$error = '';
$mode = $_GET['mode'] ?? 'list'; // list or edit
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// وەرگرتنی ڕێکخستنەکانی وێب سایت
$stmt = $conn->prepare("SELECT * FROM website_settings WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$websiteSettings = $result->fetch_assoc();
$stmt->close();

if (!$websiteSettings) {
    header('Location: ' . url('user/website/index.php'));
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    // ئەگەر post_max_size تێپەڕێنرێت، $_POST بەتاڵ دەبێت — csrf ناگات و هەڵەی ئامنیەت دەردەکەوێت
    if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
        $error = 'قەبارەی ناردن زۆر گەورەیە (سنووری post_max_size لە PHP). وێنەکان بچووک بکە یان لە php.ini سنوورەکان بەرز بکەرەوە.';
    } elseif (!Security::validateCSRFToken(trim((string)($_POST['csrf_token'] ?? '')))) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
        if (isset($_POST['product_id']) && (int)$_POST['product_id'] > 0) {
            $productId = (int)$_POST['product_id'];
            $mode = 'edit';
        }
    } else {
        $productId = (int)($_POST['product_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $discountPrice = !empty($_POST['discount_price']) ? (float)$_POST['discount_price'] : null;
        
        // Check if product belongs to user
        $checkStmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
        $checkStmt->bind_param("ii", $productId, $userId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            $error = 'کاڵا نەدۆزرایەوە';
        } else {
            $checkStmt->close();
            
            // Process main image (DigitalOcean Spaces)
            $mainImage = null;
            if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = upload_product_image_to_spaces($_FILES['main_image']);
                if ($uploadResult['success']) {
                    $mainImage = $uploadResult['db_path'];
                } else {
                    $error = implode(', ', $uploadResult['errors'] ?? []);
                }
            }
            
            // Process sub images
            $subImages = [];
            if (isset($_FILES['sub_images'])) {
                $imageCount = count($_FILES['sub_images']['name']);
                
                for ($i = 0; $i < min($imageCount, 5); $i++) {
                    if ($_FILES['sub_images']['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $_FILES['sub_images']['name'][$i],
                            'type' => $_FILES['sub_images']['type'][$i],
                            'tmp_name' => $_FILES['sub_images']['tmp_name'][$i],
                            'error' => $_FILES['sub_images']['error'][$i],
                            'size' => $_FILES['sub_images']['size'][$i]
                        ];
                        
                        $uploadResult = upload_product_image_to_spaces($file);
                        if ($uploadResult['success']) {
                            $subImages[] = $uploadResult['db_path'];
                        }
                    }
                }
            }
            
            if (empty($error)) {
                // Get existing data
                $existingStmt = $conn->prepare("SELECT main_image, sub_images FROM product_details WHERE product_id = ?");
                $existingStmt->bind_param("i", $productId);
                $existingStmt->execute();
                $existingResult = $existingStmt->get_result();
                $existingData = $existingResult->fetch_assoc();
                $existingStmt->close();
                
                if ($mainImage && !empty($existingData['main_image']) && $existingData['main_image'] !== $mainImage) {
                    delete_product_image_from_spaces($existingData['main_image']);
                }

                // Prepare final images
                $finalMainImage = $mainImage ? $mainImage : ($existingData['main_image'] ?? null);
                
                $existingSubImages = [];
                if ($existingData && $existingData['sub_images']) {
                    $existingSubImages = json_decode($existingData['sub_images'], true) ?: [];
                }
                
                // Handle deleted sub images
                $deletedSubImages = [];
                if (!empty($_POST['deleted_sub_images'])) {
                    $deletedSubImages = json_decode($_POST['deleted_sub_images'], true) ?: [];
                    // Remove deleted images from existing images
                    $existingSubImages = array_filter($existingSubImages, function($img) use ($deletedSubImages) {
                        return !in_array($img, $deletedSubImages);
                    });
                    // Re-index array
                    $existingSubImages = array_values($existingSubImages);
                    
                    foreach ($deletedSubImages as $deletedImage) {
                        delete_product_image_from_spaces($deletedImage);
                    }
                }
                
                // Merge with new sub images
                $finalSubImages = array_merge($existingSubImages, $subImages);
                $finalSubImages = array_slice($finalSubImages, 0, 5); // Keep only first 5
                $subImagesJson = json_encode($finalSubImages);
                
                // Insert or update product_details
                $upsertStmt = $conn->prepare("
                    INSERT INTO product_details (product_id, description, discount_price, main_image, sub_images)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        description = VALUES(description),
                        discount_price = VALUES(discount_price),
                        main_image = IF(VALUES(main_image) IS NOT NULL, VALUES(main_image), main_image),
                        sub_images = VALUES(sub_images),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $upsertStmt->bind_param("isiss", $productId, $description, $discountPrice, $finalMainImage, $subImagesJson);
                
                if ($upsertStmt->execute()) {
                    $success = 'وردەکاریەکانی کاڵا بەسەرکەوتوویی پاشەکەوت کرا';
                } else {
                    $error = 'هەڵەیەک ڕوویدا لە پاشەکەوتکردن';
                }
                $upsertStmt->close();
            }
        }
    }
}

// Get product details if in edit mode
$productData = null;
$productDetails = null;
if ($mode === 'edit' && $productId > 0) {
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name, pd.description, pd.discount_price, pd.main_image, pd.sub_images
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_details pd ON p.id = pd.product_id
        WHERE p.id = ? AND p.user_id = ?
    ");
    $stmt->bind_param("ii", $productId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $productData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$productData) {
        header('Location: ' . url('user/website/manage/manage_product_details.php'));
        exit;
    }
    
    $productDetails = [
        'description' => $productData['description'] ?? '',
        'discount_price' => $productData['discount_price'] ?? '',
        'main_image' => $productData['main_image'] ?? '',
        'sub_images' => $productData['sub_images'] ? json_decode($productData['sub_images'], true) : []
    ];

    // Determine product currency and display sell price (respect units setup)
    $productCurrency = $productData['currency'] ?? 'IQD';
    $productDisplaySellPrice = $productData['sell_price'] ?? 0;

    // Try to get primary/unit-based sell price if main sell_price is zero
    if (empty($productDisplaySellPrice) || $productDisplaySellPrice == 0) {
        $unitStmt = $conn->prepare("
            SELECT pu.sell_price, pu.currency
            FROM product_units pu
            WHERE pu.product_id = ?
            ORDER BY pu.is_primary DESC, pu.id ASC
        ");
        if ($unitStmt) {
            $unitStmt->bind_param("i", $productId);
            $unitStmt->execute();
            $unitResult = $unitStmt->get_result();
            while ($unitRow = $unitResult->fetch_assoc()) {
                if (!empty($unitRow['sell_price']) && $unitRow['sell_price'] > 0) {
                    $productDisplaySellPrice = (float)$unitRow['sell_price'];
                    if (!empty($unitRow['currency'])) {
                        $productCurrency = $unitRow['currency'];
                    }
                    break;
                }
            }
            $unitStmt->close();
        }
    }
}

// Get products list for selection
$search = trim($_GET['search'] ?? '');
$categoryFilter = $_GET['category'] ?? '';

$whereConditions = ["p.user_id = ?"];
$params = [$userId];
$paramTypes = "i";

if (!empty($search)) {
    $whereConditions[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $paramTypes .= "ss";
}

if (!empty($categoryFilter)) {
    $whereConditions[] = "p.category_id = ?";
    $params[] = (int)$categoryFilter;
    $paramTypes .= "i";
}

$whereClause = implode(" AND ", $whereConditions);

$productsQuery = "
    SELECT p.id, p.name, p.barcode, p.image_path, c.name as category_name,
           pd.description, pd.main_image
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_details pd ON p.id = pd.product_id
    WHERE {$whereClause}
    ORDER BY GREATEST(p.created_at, p.updated_at, COALESCE(pd.updated_at, p.created_at)) DESC, p.id DESC
";

$stmt = $conn->prepare($productsQuery);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get categories for filter
$categoriesStmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name ASC");
$categoriesStmt->bind_param("i", $userId);
$categoriesStmt->execute();
$categories = $categoriesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$categoriesStmt->close();

?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>بەڕێوەبردنی وردەکاریەکانی کاڵا - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/website/assets/css/website-settings.css'); ?>" rel="stylesheet">
    
    <style>
        :root {
            --upload-border: #cbd5e1;
            --upload-bg: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            --upload-drag-bg: linear-gradient(145deg, #e7f1ff 0%, #dae8ff 100%);
            --panel-bg: #ffffff;
            --panel-muted-bg: #f8f9fa;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-soft: #d1d5db;
        }

        [data-bs-theme="dark"],
        body.dark-mode {
            --upload-border: #475569;
            --upload-bg: linear-gradient(145deg, #1e293b 0%, #334155 100%);
            --upload-drag-bg: linear-gradient(145deg, #1d4d7a 0%, #1e3a5f 100%);
            --panel-bg: #1e293b;
            --panel-muted-bg: #334155;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --border-soft: #475569;
        }

        .product-card {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .product-card.has-details {
            border-right: 4px solid #28a745;
        }
        
        /* Image Upload Styles from register.php */
        .modern-upload-area {
            position: relative;
            border: 3px dashed var(--upload-border);
            border-radius: 20px;
            padding: 40px 20px;
            background: var(--upload-bg);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            overflow: hidden;
        }
        
        .modern-upload-area:hover {
            border-color: #0d6efd;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.15);
        }
        
        .modern-upload-area.dragover {
            border-color: #0d6efd;
            background: var(--upload-drag-bg);
        }
        
        .upload-icon-wrapper {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #0d6efd 0%, #6f42c1 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.3);
        }
        
        .upload-icon {
            font-size: 2.5rem;
            color: white;
        }
        
        .preview-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .preview-item {
            position: relative;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            background: var(--panel-bg);
        }
        
        .preview-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .preview-image-wrapper {
            position: relative;
            padding-top: 100%;
            background: var(--panel-muted-bg);
            overflow: hidden;
        }
        
        .preview-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .btn-remove-image {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(220, 53, 69, 0.95);
            border: 2px solid white;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 10;
        }
        
        .preview-item:hover .btn-remove-image {
            opacity: 1;
        }
        
        .btn-remove-image:hover {
            background: #bb2d3b;
            transform: scale(1.1) rotate(90deg);
        }
        
        .preview-item {
            transition: opacity 0.3s ease;
        }

        [data-bs-theme="dark"],
        body.dark-mode {
            color: var(--text-primary);
        }

        [data-bs-theme="dark"] .card,
        body.dark-mode .card {
            background: var(--panel-bg);
            border-color: var(--border-soft);
            color: var(--text-primary);
        }

        [data-bs-theme="dark"] .card-header,
        body.dark-mode .card-header {
            background: var(--panel-muted-bg);
            border-color: var(--border-soft);
            color: var(--text-primary);
        }

        [data-bs-theme="dark"] .form-control,
        [data-bs-theme="dark"] .form-select,
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background: #0f172a;
            color: var(--text-primary);
            border-color: var(--border-soft);
        }

        [data-bs-theme="dark"] .bg-light,
        body.dark-mode .bg-light {
            background: var(--panel-muted-bg) !important;
        }

        [data-bs-theme="dark"] .text-muted,
        body.dark-mode .text-muted {
            color: var(--text-secondary) !important;
        }
    </style>
</head>
<body class="website-module-page website-manage-page bg-light">
    <?php include_once '../../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">
        
        <?php if ($mode === 'list'): ?>
        
        <!-- List Mode -->
        <div class="row mb-4">
            <div class="col-12">
                <h2 class="mb-1">بەڕێوەبردنی وردەکاریەکانی کاڵا</h2>
                <p class="text-muted mb-0">وەسف و وێنە زیاد بکە بۆ کاڵاکان — نوێترین کاڵا (زیادکراو یان دەستکاریکراو) لە سەرەوە</p>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Search and Filter -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4">
                        <label for="search" class="form-label">گەڕان</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="گەڕان بە ناو یان بارکۆد...">
                    </div>
                    <div class="col-md-3">
                        <label for="category" class="form-label">کەتەلۆگ</label>
                        <select class="form-select" id="category" name="category">
                            <option value="">هەموو کەتەلۆگەکان</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                        <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block w-100">
                            <i class="bi bi-search"></i> گەڕان
                        </button>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <a href="<?php echo url('user/website/manage/manage_product_details.php'); ?>" 
                           class="btn btn-outline-secondary d-block w-100">
                            <i class="bi bi-arrow-clockwise"></i> پاککردنەوە
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="row">
            <?php if (empty($products)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam display-1 text-muted"></i>
                        <h5 class="mt-3">هیچ کاڵایەک نەدۆزرایەوە</h5>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                <div class="col-lg-3 col-md-4 col-sm-6 mb-4">
                    <div class="card product-card <?php echo $product['description'] || $product['main_image'] ? 'has-details' : ''; ?>"
                         onclick="window.location.href='?mode=edit&id=<?php echo $product['id']; ?>'">
                        <div class="position-relative">
                            <?php 
                            $displayImage = $product['main_image'] ?: $product['image_path'];
                            $displayImageUrl = $displayImage ? product_image_url($displayImage) : null;
                            if ($displayImageUrl):
                            ?>
                                <img src="<?php echo htmlspecialchars($displayImageUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                                     class="card-img-top" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                     style="height: 200px; object-fit: cover;">
                            <?php else: ?>
                                <div class="bg-light d-flex align-items-center justify-content-center" 
                                     style="height: 200px;">
                                    <i class="bi bi-image display-4 text-muted"></i>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($product['description'] || $product['main_image']): ?>
                                <div class="position-absolute top-0 end-0 m-2">
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle"></i> تەواو
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-body">
                            <h6 class="card-title mb-2"><?php echo htmlspecialchars($product['name']); ?></h6>
                            <?php if ($product['category_name']): ?>
                                <p class="text-muted small mb-2">
                                    <i class="bi bi-tag"></i>
                                    <?php echo htmlspecialchars($product['category_name']); ?>
                                </p>
                            <?php endif; ?>
                            
                            <?php if ($product['description']): ?>
                                <p class="text-muted small mb-2">
                                    <?php echo mb_substr(strip_tags($product['description']), 0, 50) . '...'; ?>
                                </p>
                            <?php else: ?>
                                <p class="text-muted small mb-2 fst-italic">
                                    وەسف زیاد نەکراوە
                                </p>
                            <?php endif; ?>
                            
                            <div class="d-grid">
                                <button class="btn btn-sm btn-primary">
                                    <i class="bi bi-pencil"></i> دەستکاریکردن
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        
        <!-- Edit Mode -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">دەستکاریکردنی وردەکاریەکانی کاڵا</h2>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($productData['name']); ?></p>
                    </div>
                    <div>
                        <a href="<?php echo url('user/website/manage/manage_product_details.php'); ?>" 
                           class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Edit Form — action ڕوون بۆ پاراستنی ?mode=edit&id= لەگەڵ نصب لە ژێر فۆڵدەر -->
        <form method="POST" enctype="multipart/form-data" id="productDetailsForm"
              action="<?php echo htmlspecialchars(url('user/website/manage/manage_product_details.php') . '?mode=edit&id=' . (int)$productId, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
            <input type="hidden" name="deleted_sub_images" id="deletedSubImages" value="">
            
            <div class="row">
                <div class="col-lg-8">
                    <!-- Description -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-card-text"></i> وەسفی کاڵا
                            </h5>
                        </div>
                        <div class="card-body">
                            <textarea class="form-control" name="description" rows="8" 
                                      placeholder="وەسفی کاڵا بنووسە..."><?php echo htmlspecialchars($productDetails['description']); ?></textarea>
                            <small class="text-muted">
                                وەسفێکی تەواو و ڕوون بنووسە کە کڕیارەکان تێبگەن لە کاڵاکە
                            </small>
                        </div>
                    </div>
                    
                    <!-- Discount Price -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-tag"></i> نرخی داشکاندن
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="discount_price" class="form-label">
                                    نرخی داشکاندن (<?php echo $productCurrency === 'USD' ? 'دۆلار' : 'دینار'; ?>)
                                </label>
                                <input type="number" 
                                       class="form-control" 
                                       id="discount_price" 
                                       name="discount_price" 
                                       step="0.001" 
                                       min="0"
                                       value="<?php echo htmlspecialchars($productDetails['discount_price'] ?? ''); ?>" 
                                       placeholder="0.000">
                                <small class="text-muted">
                                    نرخی داشکاندنی کاڵا بۆ پیشاندان لە وێب سایت (بەم بێت بەتاڵ بێت)
                                </small>
                            </div>
                        </div>
                    </div>
                     
                    <!-- Main Image -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-image"></i> وێنەی سەرەکی
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($productDetails['main_image']): ?>
                                <div class="mb-3">
                                    <p class="text-muted mb-2">وێنەی ئێستا:</p>
                                    <img src="<?php echo htmlspecialchars(product_image_url($productDetails['main_image']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                         class="img-thumbnail" style="max-height: 200px;">
                                </div>
                            <?php endif; ?>
                            
                            <div class="modern-upload-area" id="mainImageUploadArea">
                                <input type="file" name="main_image" id="main_image" 
                                       accept="image/*" class="d-none">
                                <div class="upload-content text-center" id="mainImageUploadContent">
                                    <div class="upload-icon-wrapper">
                                        <i class="bi bi-cloud-arrow-up upload-icon"></i>
                                    </div>
                                    <h6 class="mt-3 mb-2">وێنەی سەرەکی هەڵبژێرە</h6>
                                    <p class="text-muted small mb-3">کلیک بکە یان وێنە ڕابکێشە بۆ ئێرە</p>
                                    <button type="button" class="btn btn-primary btn-sm" 
                                            onclick="document.getElementById('main_image').click()">
                                        <i class="bi bi-folder2-open"></i> هەڵبژاردن
                                    </button>
                                </div>
                            </div>
                            
                            <div class="preview-gallery mt-3" id="mainImagePreview"></div>
                        </div>
                    </div>
                    
                    <!-- Sub Images -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-images"></i> وێنە لاوەکیەکان (تا 5 وێنە)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($productDetails['sub_images'])): ?>
                                <div class="mb-3">
                                    <p class="text-muted mb-2">وێنە لاوەکیە ئێستاکان:</p>
                                    <div class="preview-gallery" id="existingSubImages">
                                        <?php foreach ($productDetails['sub_images'] as $index => $subImage): ?>
                                            <div class="preview-item" data-image-path="<?php echo htmlspecialchars($subImage); ?>">
                                                <div class="preview-image-wrapper">
                                                    <img src="<?php echo htmlspecialchars(product_image_url($subImage) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                                         class="preview-image" alt="Sub Image <?php echo $index + 1; ?>">
                                                    <button type="button" class="btn-remove-image" onclick="removeExistingSubImage(this, '<?php echo htmlspecialchars($subImage); ?>')">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="modern-upload-area" id="subImagesUploadArea">
                                <input type="file" name="sub_images[]" id="sub_images" 
                                       accept="image/*" multiple class="d-none">
                                <div class="upload-content text-center" id="subImagesUploadContent">
                                    <div class="upload-icon-wrapper">
                                        <i class="bi bi-cloud-arrow-up upload-icon"></i>
                                    </div>
                                    <h6 class="mt-3 mb-2">وێنە لاوەکیەکان هەڵبژێرە</h6>
                                    <p class="text-muted small mb-3">کلیک بکە یان وێنەکان ڕابکێشە بۆ ئێرە</p>
                                    <button type="button" class="btn btn-primary btn-sm" 
                                            onclick="document.getElementById('sub_images').click()">
                                        <i class="bi bi-folder2-open"></i> هەڵبژاردن (تا 5 وێنە)
                                    </button>
                                </div>
                            </div>
                            
                            <div class="preview-gallery mt-3" id="subImagesPreview"></div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Product Info -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-info-circle"></i> زانیاریەکانی کاڵا
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong>ناو:</strong><br>
                                <?php echo htmlspecialchars($productData['name']); ?>
                            </div>
                            
                            <?php if ($productData['category_name']): ?>
                            <div class="mb-3">
                                <strong>کەتەلۆگ:</strong><br>
                                <?php echo htmlspecialchars($productData['category_name']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($productData['barcode']): ?>
                            <div class="mb-3">
                                <strong>بارکۆد:</strong><br>
                                <?php echo htmlspecialchars($productData['barcode']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($productDisplaySellPrice) && $productDisplaySellPrice > 0): ?>
                            <div class="mb-3">
                                <strong>نرخی فرۆشتن:</strong><br>
                                <?php echo formatCurrencyAmount($productDisplaySellPrice, $productCurrency); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($productDetails['discount_price'])): ?>
                            <div class="mb-3">
                                <strong>نرخی داشکاندن:</strong><br>
                                <span class="text-success fw-bold">
                                    <?php echo formatCurrencyAmount($productDetails['discount_price'], $productCurrency); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($productData['image_path']): ?>
                            <div class="mb-3">
                                <strong>وێنەی POS:</strong><br>
                                <img src="<?php echo htmlspecialchars(product_image_url($productData['image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                     class="img-thumbnail mt-2" style="max-height: 100px;">
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="card">
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> پاشەکەوتکردن
                                </button>
                                <a href="<?php echo url('user/website/manage/manage_product_details.php'); ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> هەڵوەشاندنەوە
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Image compression function (from register.php)
        function compressImage(file, maxWidth = 1920, maxHeight = 1080, quality = 0.8) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const canvas = document.createElement('canvas');
                        const ctx = canvas.getContext('2d');
                        
                        let { width, height } = calculateDimensions(img.width, img.height, maxWidth, maxHeight);
                        
                        canvas.width = width;
                        canvas.height = height;
                        ctx.drawImage(img, 0, 0, width, height);
                        
                        canvas.toBlob(function(blob) {
                            const compressedFile = new File([blob], file.name, {
                                type: 'image/jpeg',
                                lastModified: Date.now()
                            });
                            resolve(compressedFile);
                        }, 'image/jpeg', quality);
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            });
        }
        
        function calculateDimensions(originalWidth, originalHeight, maxWidth, maxHeight) {
            let width = originalWidth;
            let height = originalHeight;
            const aspectRatio = originalWidth / originalHeight;
            
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
        
        // Main Image Upload
        const mainImageInput = document.getElementById('main_image');
        const mainImagePreview = document.getElementById('mainImagePreview');
        const mainImageUploadArea = document.getElementById('mainImageUploadArea');
        
        if (mainImageInput) {
            mainImageUploadArea.addEventListener('click', function(e) {
                if (e.target.tagName !== 'BUTTON') {
                    mainImageInput.click();
                }
            });
            
            mainImageUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            mainImageUploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            mainImageUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
                if (files.length > 0) {
                    handleMainImageUpload(files[0]);
                }
            });
            
            mainImageInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    handleMainImageUpload(this.files[0]);
                }
            });
        }
        
        async function handleMainImageUpload(file) {
            const compressedFile = await compressImage(file);
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(compressedFile);
            mainImageInput.files = dataTransfer.files;
            
            displayMainImagePreview(compressedFile);
        }
        
        function displayMainImagePreview(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                mainImagePreview.innerHTML = `
                    <div class="preview-item">
                        <div class="preview-image-wrapper">
                            <img src="${e.target.result}" class="preview-image" alt="Main Image">
                        </div>
                    </div>
                `;
            };
            reader.readAsDataURL(file);
        }
        
        // Sub Images Upload
        const subImagesInput = document.getElementById('sub_images');
        const subImagesPreview = document.getElementById('subImagesPreview');
        const subImagesUploadArea = document.getElementById('subImagesUploadArea');
        
        if (subImagesInput) {
            subImagesUploadArea.addEventListener('click', function(e) {
                if (e.target.tagName !== 'BUTTON') {
                    subImagesInput.click();
                }
            });
            
            subImagesUploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            subImagesUploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            subImagesUploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
                if (files.length > 0) {
                    handleSubImagesUpload(files.slice(0, 5));
                }
            });
            
            subImagesInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    handleSubImagesUpload(Array.from(this.files).slice(0, 5));
                }
            });
        }
        
        async function handleSubImagesUpload(files) {
            const compressedFiles = [];
            for (const file of files) {
                const compressed = await compressImage(file);
                compressedFiles.push(compressed);
            }
            
            const dataTransfer = new DataTransfer();
            compressedFiles.forEach(f => dataTransfer.items.add(f));
            subImagesInput.files = dataTransfer.files;
            
            displaySubImagesPreview(compressedFiles);
        }
        
        function displaySubImagesPreview(files) {
            subImagesPreview.innerHTML = '';
            files.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.innerHTML = `
                        <div class="preview-image-wrapper">
                            <img src="${e.target.result}" class="preview-image" alt="Sub Image ${index + 1}">
                            <button type="button" class="btn-remove-image" onclick="removeNewSubImage(${index})">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    `;
                    subImagesPreview.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        }
        
        // Track deleted images
        let deletedSubImages = [];
        
        // Remove existing sub image
        function removeExistingSubImage(button, imagePath) {
            if (confirm('دڵنیایت لە سڕینەوەی ئەم وێنەیە؟')) {
                // Add to deleted list
                deletedSubImages.push(imagePath);
                document.getElementById('deletedSubImages').value = JSON.stringify(deletedSubImages);
                
                // Remove from UI
                const previewItem = button.closest('.preview-item');
                previewItem.style.opacity = '0';
                setTimeout(() => previewItem.remove(), 300);
            }
        }
        
        // Remove new sub image (not yet uploaded)
        function removeNewSubImage(index) {
            const dataTransfer = new DataTransfer();
            const files = Array.from(subImagesInput.files);
            
            files.forEach((file, i) => {
                if (i !== index) {
                    dataTransfer.items.add(file);
                }
            });
            
            subImagesInput.files = dataTransfer.files;
            displaySubImagesPreview(Array.from(dataTransfer.files));
        }
    </script>

</body>
</html>

