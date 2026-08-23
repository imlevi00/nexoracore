<?php
/**
 * بەڕێوەبردنی بینینی کەتەلۆگەکان - user/website/manage/manage_categories.php
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
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $isVisible = (int)($_POST['is_visible'] ?? 0);
        
        if ($categoryId > 0) {
            // چیککردنی ئەوەی کەتەلۆگ بە یوزەرەوە دەگەڕێت
            $checkStmt = $conn->prepare("SELECT id FROM categories WHERE id = ? AND user_id = ?");
            $checkStmt->bind_param("ii", $categoryId, $userId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                // نوێکردنەوەی visibility
                $updateStmt = $conn->prepare("UPDATE categories SET is_visible_on_website = ? WHERE id = ? AND user_id = ?");
                $updateStmt->bind_param("iii", $isVisible, $categoryId, $userId);
                
                if ($updateStmt->execute()) {
                    $success = 'دۆخی پیشاندانی کەتەلۆگ نوێکرایەوە';
                } else {
                    $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی دۆخی کەتەلۆگ';
                }
                $updateStmt->close();
            } else {
                $error = 'کەتەلۆگ نەدۆزرایەوە';
            }
            $checkStmt->close();
        } else {
            $error = 'کەتەلۆگی هەڵبژاردوو نادروستە';
        }
    }
    
    elseif ($action === 'bulk_update') {
        $visibility = $_POST['bulk_visibility'] ?? '';
        $selectedCategories = $_POST['selected_categories'] ?? [];
        
        if ($visibility !== '' && !empty($selectedCategories)) {
            $isVisible = $visibility === 'show' ? 1 : 0;
            $updated = 0;
            
            foreach ($selectedCategories as $categoryId) {
                $categoryId = (int)$categoryId;
                if ($categoryId > 0) {
                    $updateStmt = $conn->prepare("UPDATE categories SET is_visible_on_website = ? WHERE id = ? AND user_id = ?");
                    $updateStmt->bind_param("iii", $isVisible, $categoryId, $userId);
                    if ($updateStmt->execute()) {
                        $updated++;
                    }
                    $updateStmt->close();
                }
            }
            
            $success = "دۆخی {$updated} کەتەلۆگ نوێکرایەوە";
        } else {
            $error = 'تکایە کەتەلۆگ هەڵبژێرە و دۆخی پیشاندان دیاری بکە';
        }
    }
}

// وەرگرتنی کەتەلۆگەکان
$search = trim($_GET['search'] ?? '');

$whereConditions = ["c.user_id = ?"];
$params = [$userId];
$paramTypes = "i";

if (!empty($search)) {
    $whereConditions[] = "c.name LIKE ?";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $paramTypes .= "s";
}

$whereClause = implode(" AND ", $whereConditions);

$query = "SELECT c.id, c.name, c.description, 
                 COALESCE(c.is_visible_on_website, 1) as is_visible,
                 COUNT(p.id) as product_count
          FROM categories c
          LEFT JOIN products p ON c.id = p.category_id
          WHERE {$whereClause}
          GROUP BY c.id, c.name, c.description, c.is_visible_on_website
          ORDER BY c.name ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>بەڕێوەبردنی کەتەلۆگەکان - <?php echo SITE_NAME; ?></title>
    
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
                        <h2 class="mb-1">بەڕێوەبردنی کەتەلۆگەکان</h2>
                        <p class="text-muted mb-0">دیاری بکە چ کەتەلۆگێک لە وێب سایتەکە نیشان بدرێت</p>
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

        <!-- Search -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-6">
                        <label for="search" class="form-label">گەڕان</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="گەڕان بە ناوی کەتەلۆگ...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block w-100">
                            <i class="bi bi-search"></i> گەڕان
                        </button>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <a href="<?php echo url('user/website/manage/manage_categories.php'); ?>" class="btn btn-outline-secondary d-block w-100">
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
                                    هەڵبژاردنی هەموو کەتەلۆگەکان
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

        <!-- Categories List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-tags"></i>
                    کەتەلۆگەکان (<?php echo count($categories); ?> کەتەلۆگ)
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($categories)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-tags display-1 text-muted"></i>
                        <h5 class="mt-3">هیچ کەتەلۆگێک نەدۆزرایەوە</h5>
                        <p class="text-muted">کەتەلۆگ زیاد بکە یان گەڕانەکەت بگۆڕە</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle website-manage-table">
                            <thead class="table-light">
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="selectAllTable">
                                    </th>
                                    <th>ناوی کەتەلۆگ</th>
                                    <th>وەسف</th>
                                    <th>ژمارەی کاڵاکان</th>
                                    <th>دۆخی پیشاندان</th>
                                    <th width="100">کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="category-checkbox" 
                                                   form="bulkForm"
                                                   name="selected_categories[]" value="<?php echo $category['id']; ?>">
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($category['name']); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($category['description']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($category['description']); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $category['product_count']; ?> کاڵا</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $category['is_visible'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $category['is_visible'] ? 'نیشاندراو' : 'شاردراوە'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" action="" class="d-inline toggle-form">
                                                <input type="hidden" name="action" value="toggle_visibility">
                                                <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="is_visible" value="<?php echo $category['is_visible'] ? '0' : '1'; ?>">
                                                <button type="submit" class="btn btn-sm btn-<?php echo $category['is_visible'] ? 'outline-warning' : 'outline-success'; ?> toggle-btn">
                                                    <i class="bi bi-<?php echo $category['is_visible'] ? 'eye-slash' : 'eye'; ?>"></i>
                                                    <?php echo $category['is_visible'] ? 'شاردنەوە' : 'نیشاندان'; ?>
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
            const checkboxes = document.querySelectorAll('.category-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkSubmit();
        });
        
        document.getElementById('selectAllTable')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.category-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            document.getElementById('selectAll').checked = this.checked;
            updateBulkSubmit();
        });
        
        // Individual checkbox change
        document.querySelectorAll('.category-checkbox').forEach(checkbox => {
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
            const selectedCategories = document.querySelectorAll('.category-checkbox:checked');
            const visibility = document.querySelector('select[name="bulk_visibility"]').value;
            const submitBtn = document.getElementById('bulkSubmit');
            
            if (selectedCategories.length > 0 && visibility !== '') {
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<i class="bi bi-check2-all"></i> جێبەجێکردن (${selectedCategories.length} کەتەلۆگ)`;
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-check2-all"></i> جێبەجێکردن';
            }
        }
        
        function updateSelectAll() {
            const checkboxes = document.querySelectorAll('.category-checkbox');
            const checkedBoxes = document.querySelectorAll('.category-checkbox:checked');
            
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
            const selectedCategories = document.querySelectorAll('.category-checkbox:checked');
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> کاردەکات (${selectedCategories.length} کەتەلۆگ)...`;
        });
    </script>

</body>
</html>

