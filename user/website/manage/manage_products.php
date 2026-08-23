<?php
/**
 * بەڕێوەبردنی بینینی کاڵاکان - user/website/manage/manage_products.php
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

$success = '';
$error = '';

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
    $action = $_POST['action'] ?? '';
    
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    }
    
    elseif ($action === 'toggle_visibility') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $isVisible = (int)($_POST['is_visible'] ?? 0);
        
        if ($productId > 0) {
            // چیککردنی ئەوەی کاڵا بە یوزەرەوە دەگەڕێت
            $checkStmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
            $checkStmt->bind_param("ii", $productId, $userId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                // نوێکردنەوە یان زیادکردنی visibility
                $upsertStmt = $conn->prepare("INSERT INTO website_product_visibility (user_id, product_id, is_visible) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_visible = ?");
                $upsertStmt->bind_param("iiii", $userId, $productId, $isVisible, $isVisible);
                
                if ($upsertStmt->execute()) {
                    $success = 'دۆخی پیشاندانی کاڵا نوێکرایەوە';
                } else {
                    $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی دۆخی کاڵا';
                }
                $upsertStmt->close();
            } else {
                $error = 'کاڵا نەدۆزرایەوە';
            }
            $checkStmt->close();
        } else {
            $error = 'کاڵای هەڵبژاردوو نادروستە';
        }
    }
    
    elseif ($action === 'bulk_update') {
        $visibility = $_POST['bulk_visibility'] ?? '';
        $selectedProducts = $_POST['selected_products'] ?? [];
        
        if ($visibility !== '' && !empty($selectedProducts)) {
            $isVisible = $visibility === 'show' ? 1 : 0;
            $updated = 0;
            
            foreach ($selectedProducts as $productId) {
                $productId = (int)$productId;
                if ($productId > 0) {
                    $upsertStmt = $conn->prepare("INSERT INTO website_product_visibility (user_id, product_id, is_visible) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_visible = ?");
                    $upsertStmt->bind_param("iiii", $userId, $productId, $isVisible, $isVisible);
                    if ($upsertStmt->execute()) {
                        $updated++;
                    }
                    $upsertStmt->close();
                }
            }
            
            $success = "دۆخی {$updated} کاڵا نوێکرایەوە";
        } else {
            $error = 'تکایە کاڵا هەڵبژێرە و دۆخی پیشاندان دیاری بکە';
        }
    }
}

// وەرگرتنی کاڵاکان
$search = trim($_GET['search'] ?? '');
$categoryFilter = $_GET['category'] ?? '';

// پارامێتەری یەکەم بۆ LEFT JOIN
$params = [$userId];
$paramTypes = "i";

// پارامێتەرەکانی WHERE clause
$whereConditions = ["p.user_id = ?"];
$params[] = $userId;
$paramTypes .= "i";

if (!empty($search)) {
    $whereConditions[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $paramTypes .= "ss";
}

if (!empty($categoryFilter) && $categoryFilter !== 'all') {
    $whereConditions[] = "p.category_id = ?";
    $params[] = (int)$categoryFilter;
    $paramTypes .= "i";
}

$whereClause = implode(" AND ", $whereConditions);

$query = "SELECT p.id, p.name, p.barcode,
                 COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
                 COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
                 COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
                 p.image_path,
                 c.name as category_name,
                 COALESCE(wpv.is_visible, 1) as is_visible
          FROM products p
          LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
          LEFT JOIN product_units pu_any ON pu_any.id = (
              SELECT pu2.id FROM product_units pu2
              WHERE pu2.product_id = p.id
              ORDER BY pu2.is_primary DESC, pu2.id ASC
              LIMIT 1
          )
          LEFT JOIN categories c ON p.category_id = c.id
          LEFT JOIN website_product_visibility wpv ON (p.id = wpv.product_id AND wpv.user_id = ?)
          WHERE {$whereClause}
          ORDER BY p.name ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// وەرگرتنی کەتەلۆگەکان بۆ فلتەر
$categoriesStmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name ASC");
$categoriesStmt->bind_param("i", $userId);
$categoriesStmt->execute();
$categories = $categoriesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$categoriesStmt->close();

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>بەڕێوەبردنی کاڵاکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/website/assets/css/website-settings.css'); ?>" rel="stylesheet">
</head>
<body class="website-module-page website-manage-page bg-light">
    <?php include_once '../../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">
        
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">بەڕێوەبردنی کاڵاکان</h2>
                        <p class="text-muted mb-0">دیاری بکە چ کاڵایەک لە وێب سایتەکە نیشان بدرێت</p>
                    </div>
                    <div>
                        <a href="https://nexoracore.com/web/<?php echo $websiteSettings['website_slug']; ?>" 
                           target="_blank" class="btn btn-outline-primary">
                            <i class="bi bi-eye"></i> بینینی وێب سایت
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
                            <option value="all">هەموو کەتەلۆگەکان</option>
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
                        <a href="<?php echo url('user/website/manage/manage_products.php'); ?>" class="btn btn-outline-secondary d-block w-100">
                            <i class="bi bi-arrow-clockwise"></i> پاککردنەوە
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="POST" action="" id="bulkForm" name="bulkForm">
                    <input type="hidden" name="action" value="bulk_update">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                                <label class="form-check-label" for="selectAll">
                                    هەڵبژاردنی هەموو کاڵاکان
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" name="bulk_visibility" required>
                                <option value="">هەڵبژاردنی کردار...</option>
                                <option value="show">نیشاندان لە وێب سایت</option>
                                <option value="hide">شاردنەوە لە وێب سایت</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-warning" id="bulkSubmit" disabled>
                                <i class="bi bi-check2-all"></i> جێبەجێکردن
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Products List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-box-seam"></i>
                    کاڵاکان (<?php echo count($products); ?> کاڵا)
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($products)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam display-1 text-muted"></i>
                        <h5 class="mt-3">هیچ کاڵایەک نەدۆزرایەوە</h5>
                        <p class="text-muted">کاڵا زیاد بکە یان گەڕانەکەت بگۆڕە</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle website-manage-table">
                            <thead class="table-light">
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="selectAllTable">
                                    </th>
                                    <th>کاڵا</th>
                                    <th>کەتەلۆگ</th>
                                    <th>نرخەکان</th>
                                    <th>دۆخی پیشاندان</th>
                                    <th width="100">کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="product-checkbox" 
                                                   form="bulkForm"
                                                   name="selected_products[]" value="<?php echo $product['id']; ?>">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php if ($product['image_path'] && product_image_url($product['image_path'])): ?>
                                                    <img src="<?php echo htmlspecialchars(product_image_url($product['image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                                                         class="rounded me-3" width="40" height="40" style="object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="bg-light rounded me-3 d-flex align-items-center justify-content-center" 
                                                         style="width: 40px; height: 40px;">
                                                        <i class="bi bi-image text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($product['name']); ?></div>
                                                    <?php if ($product['barcode']): ?>
                                                        <small class="text-muted">بارکۆد: <?php echo htmlspecialchars($product['barcode']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo $product['category_name'] ? htmlspecialchars($product['category_name']) : '<span class="text-muted">بێ کەتەلۆگ</span>'; ?>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <?php if ($product['sell_price'] > 0): ?>
                                                    <div>تاک: <?php echo number_format($product['sell_price']); ?> دینار</div>
                                                <?php endif; ?>
                                                <?php if ($product['wholesale_price'] > 0): ?>
                                                    <div>جوملە: <?php echo number_format($product['wholesale_price']); ?> دینار</div>
                                                <?php endif; ?>
                                                <?php if ($product['special_price'] > 0): ?>
                                                    <div>تایبەت: <?php echo number_format($product['special_price']); ?> دینار</div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $product['is_visible'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $product['is_visible'] ? 'نیشاندراو' : 'شاردراوە'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" action="" class="d-inline toggle-form">
                                                <input type="hidden" name="action" value="toggle_visibility">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="is_visible" value="<?php echo $product['is_visible'] ? '0' : '1'; ?>">
                                                <button type="submit" class="btn btn-sm btn-<?php echo $product['is_visible'] ? 'outline-warning' : 'outline-success'; ?> toggle-btn">
                                                    <i class="bi bi-<?php echo $product['is_visible'] ? 'eye-slash' : 'eye'; ?>"></i>
                                                    <?php echo $product['is_visible'] ? 'شاردنەوە' : 'نیشاندان'; ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Select All functionality
        document.getElementById('selectAll')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkSubmit();
        });
        
        document.getElementById('selectAllTable')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            document.getElementById('selectAll').checked = this.checked;
            updateBulkSubmit();
        });
        
        // Individual checkbox change
        document.querySelectorAll('.product-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkSubmit();
                updateSelectAll();
            });
        });
        
        // Bulk form visibility change
        document.querySelector('select[name="bulk_visibility"]')?.addEventListener('change', function() {
            updateBulkSubmit();
        });
        
        function updateBulkSubmit() {
            const selectedProducts = document.querySelectorAll('.product-checkbox:checked');
            const visibility = document.querySelector('select[name="bulk_visibility"]').value;
            const submitBtn = document.getElementById('bulkSubmit');
            
            if (selectedProducts.length > 0 && visibility !== '') {
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<i class="bi bi-check2-all"></i> جێبەجێکردن (${selectedProducts.length} کاڵا)`;
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-check2-all"></i> جێبەجێکردن';
            }
        }
        
        function updateSelectAll() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
            
            document.getElementById('selectAll').checked = checkboxes.length === checkedBoxes.length;
            document.getElementById('selectAllTable').checked = checkboxes.length === checkedBoxes.length;
        }
        
        // Initialize
        updateBulkSubmit();
        
        // Prevent double submission for toggle forms
        document.querySelectorAll('.toggle-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const btn = this.querySelector('.toggle-btn');
                if (btn.disabled) {
                    e.preventDefault();
                    return false;
                }
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> کاردەکات...';
            });
        });
        
        // Prevent double submission for bulk form
        document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('bulkSubmit');
            if (btn.disabled) {
                e.preventDefault();
                return false;
            }
            const selectedProducts = document.querySelectorAll('.product-checkbox:checked');
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> کاردەکات (${selectedProducts.length} کاڵا)...`;
        });
    </script>

</body>
</html>
