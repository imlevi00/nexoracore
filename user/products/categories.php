<?php
/**
 * بەڕێوەبردنی کەتەلۆگەکان - user/products/categories.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
// چەککردنی ئایا action=add هاتووە
$showAddForm = isset($_GET['action']) && $_GET['action'] === 'add';


$errors = [];
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                $name = cleanInput($_POST['name'] ?? '');
                $description = cleanInput($_POST['description'] ?? '');
                
                if (empty($name)) {
                    $errors[] = 'ناوی کەتەلۆگ پێویستە';
                } else {
                    // Check if category name already exists for this user
                    $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND user_id = ?");
                    $stmt->bind_param("si", $name, $userId);
                    $stmt->execute();
                    
                    if ($stmt->get_result()->num_rows > 0) {
                        $errors[] = 'ئەم ناوەی کەتەلۆگ پێشتر بەکارهاتووە';
                    } else {
                        // Insert new category
                        $insertStmt = $conn->prepare("INSERT INTO categories (user_id, name, description, created_at) VALUES (?, ?, ?, NOW())");
                        $insertStmt->bind_param("iss", $userId, $name, $description);
                        
                        if ($insertStmt->execute()) {
                            $success = "کەتەلۆگی '$name' بە سەرکەوتوویی زیادکرا";
                            writeLog("Category added: $name by user: {$currentUser['email']}");
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە زیادکردنی کەتەلۆگ';
                        }
                        $insertStmt->close();
                    }
                    $stmt->close();
                }
                break;
                
            case 'edit':
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $name = cleanInput($_POST['name'] ?? '');
                $description = cleanInput($_POST['description'] ?? '');
                
                if (empty($name)) {
                    $errors[] = 'ناوی کەتەلۆگ پێویستە';
                } else {
                    // Check if category belongs to user and name doesn't conflict
                    $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $categoryId, $userId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 0) {
                        $errors[] = 'کەتەلۆگ نەدۆزرایەوە یان دەسەڵاتت نییە';
                    } else {
                        $oldCategory = $result->fetch_assoc();
                        
                        // Check for name conflicts if name changed
                        if ($name !== $oldCategory['name']) {
                            $checkStmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND user_id = ? AND id != ?");
                            $checkStmt->bind_param("sii", $name, $userId, $categoryId);
                            $checkStmt->execute();
                            
                            if ($checkStmt->get_result()->num_rows > 0) {
                                $errors[] = 'ئەم ناوەی کەتەلۆگ پێشتر بەکارهاتووە';
                            }
                            $checkStmt->close();
                        }
                        
                        if (empty($errors)) {
                            // Update category
                            $updateStmt = $conn->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ? AND user_id = ?");
                            $updateStmt->bind_param("ssii", $name, $description, $categoryId, $userId);
                            
                            if ($updateStmt->execute()) {
                                $success = "کەتەلۆگ بە سەرکەوتوویی نوێکرایەوە";
                                writeLog("Category updated: $name (ID: $categoryId) by user: {$currentUser['email']}");
                            } else {
                                $errors[] = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی کەتەلۆگ';
                            }
                            $updateStmt->close();
                        }
                    }
                    $stmt->close();
                }
                break;
                
            case 'delete':
                $categoryId = (int)($_POST['category_id'] ?? 0);
                
                if ($categoryId) {
                    // Check if category has products
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $categoryId, $userId);
                    $stmt->execute();
                    $productCount = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    if ($productCount > 0) {
                        $errors[] = "ناتوانیت ئەم کەتەلۆگە بسڕیتەوە چونکە $productCount کاڵای تێدایە";
                    } else {
                        // Get category name for logging
                        $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ? AND user_id = ?");
                        $stmt->bind_param("ii", $categoryId, $userId);
                        $stmt->execute();
                        $categoryName = $stmt->get_result()->fetch_assoc()['name'] ?? '';
                        $stmt->close();
                        
                        // Delete category
                        $deleteStmt = $conn->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
                        $deleteStmt->bind_param("ii", $categoryId, $userId);
                        
                        if ($deleteStmt->execute()) {
                            $success = "کەتەلۆگ بە سەرکەوتوویی سڕایەوە";
                            writeLog("Category deleted: $categoryName (ID: $categoryId) by user: {$currentUser['email']}");
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە سڕینەوەی کەتەلۆگ';
                        }
                        $deleteStmt->close();
                    }
                }
                break;
        }
    }
}

// Get all categories with product counts
$stmt = $conn->prepare("
    SELECT c.*, 
           COUNT(p.id) as product_count,
           COALESCE(SUM(COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0)), 0) as total_stock
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
    LEFT JOIN product_units pu_any ON pu_any.id = (
        SELECT pu2.id FROM product_units pu2
        WHERE pu2.product_id = p.id
        ORDER BY pu2.is_primary DESC, pu2.id ASC
        LIMIT 1
    )
    WHERE c.user_id = ?
    GROUP BY c.id
    ORDER BY c.name ASC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get products without category
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE user_id = ? AND (category_id IS NULL OR category_id = 0)");
$stmt->bind_param("i", $userId);
$stmt->execute();
$uncategorizedCount = $stmt->get_result()->fetch_assoc()['count'];

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>بەڕێوەبردنی کەتەلۆگەکان - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-subpages.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="products-module-page products-categories-page">

    <?php
    $productsNavId = 'productsCategoriesNav';
    $productsNavLinks = [
        ['href' => url('user/products/index.php'), 'icon' => 'bi-box-seam', 'text' => 'لیستی کاڵاکان'],
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <div class="container-fluid py-4 products-page-content pp-wrap">
        
        <header class="pp-hero">
            <div>
                <div class="pp-kicker"><i class="bi bi-tags"></i> ڕێکخستن</div>
                <h1><i class="bi bi-folder2-open"></i> بەڕێوەبردنی کەتەلۆگەکان</h1>
                <p class="pp-hero-sub">کەتەلۆگەکان دروست بکە و کاڵاکانت بە شێوەیەکی ڕوون پۆلێن بکە</p>
                <div class="pp-hero-pills">
                    <span class="pp-pill"><i class="bi bi-tags"></i> <?php echo count($categories); ?> کەتەلۆگ</span>
                    <span class="pp-pill"><i class="bi bi-question-circle"></i> <?php echo (int)$uncategorizedCount; ?> بێ کەتەلۆگ</span>
                </div>
            </div>
            <div class="pp-actions">
                <button type="button" class="pp-btn pp-btn-success" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="bi bi-plus-lg"></i> کەتەلۆگی نوێ
                </button>
                <a href="<?php echo url('user/products/index.php'); ?>" class="pp-btn pp-btn-ghost">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </header>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong>هەڵەکان:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i>
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="pp-stats pp-stats-3">
            <div class="pp-stat" style="--stat-accent:#06b6d4">
                <div class="pp-stat-icon"><i class="bi bi-tags"></i></div>
                <div>
                    <div class="pp-stat-label">کۆی کەتەلۆگەکان</div>
                    <div class="pp-stat-value"><?php echo count($categories); ?></div>
                </div>
            </div>
            <div class="pp-stat" style="--stat-accent:#4f46e5">
                <div class="pp-stat-icon"><i class="bi bi-box-seam"></i></div>
                <div>
                    <div class="pp-stat-label">کاڵا بە کەتەلۆگ</div>
                    <div class="pp-stat-value"><?php echo number_format(array_sum(array_column($categories, 'product_count'))); ?></div>
                </div>
            </div>
            <a class="pp-stat" style="--stat-accent:#f59e0b" href="<?php echo url('user/products/index.php?category='); ?>">
                <div class="pp-stat-icon"><i class="bi bi-question-circle"></i></div>
                <div>
                    <div class="pp-stat-label">کاڵا بێ کەتەلۆگ</div>
                    <div class="pp-stat-value"><?php echo (int)$uncategorizedCount; ?></div>
                    <?php if ($uncategorizedCount > 0): ?>
                        <div class="pp-stat-meta">کلیک بۆ بینین</div>
                    <?php endif; ?>
                </div>
            </a>
        </div>

        <?php if (empty($categories)): ?>
            <div class="pp-panel">
                <div class="pp-empty">
                    <div class="pp-empty-icon"><i class="bi bi-tags"></i></div>
                    <h3>هیچ کەتەلۆگێک دروست نەکراوە</h3>
                    <p>کەتەلۆگەکان یارمەتیت دەدەن کاڵاکانت بە باشتری ڕێکبخەیت</p>
                    <button type="button" class="pp-btn pp-btn-success" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="bi bi-plus-lg"></i> یەکەم کەتەلۆگ دروست بکە
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="cat-grid">
                <?php foreach ($categories as $category): ?>
                    <?php $hue = abs(crc32((string)$category['name'])) % 360; ?>
                    <article class="cat-card" style="--hue: <?php echo $hue; ?>">
                        <div class="cat-top">
                            <div class="cat-title">
                                <div class="cat-ava"><?php echo htmlspecialchars(mb_substr($category['name'], 0, 1)); ?></div>
                                <div>
                                    <h3 class="cat-name"><?php echo htmlspecialchars($category['name']); ?></h3>
                                    <?php if ($category['description']): ?>
                                        <p class="cat-desc"><?php echo htmlspecialchars($category['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="dropdown">
                                <button class="cat-menu" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-three-dots"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item"
                                           href="<?php echo url('user/products/index.php?category=' . $category['id']); ?>">
                                            <i class="bi bi-eye"></i> بینینی کاڵاکان
                                        </a>
                                    </li>
                                    <li>
                                        <button class="dropdown-item"
                                                onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)">
                                            <i class="bi bi-pencil"></i> دەستکاری
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button class="dropdown-item text-danger"
                                                onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>', <?php echo $category['product_count']; ?>)">
                                            <i class="bi bi-trash"></i> سڕینەوە
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="cat-metrics">
                            <div class="cat-metric">
                                <b><?php echo (int)$category['product_count']; ?></b>
                                <span>کاڵا</span>
                            </div>
                            <div class="cat-metric">
                                <b><?php echo number_format((float)$category['total_stock']); ?></b>
                                <span>بەردەست</span>
                            </div>
                        </div>

                        <div class="cat-foot">
                            <a href="<?php echo url('user/products/index.php?category=' . $category['id']); ?>"
                               class="pp-btn pp-btn-ghost pp-btn-sm">
                                <i class="bi bi-box-seam"></i> بینینی کاڵاکان
                            </a>
                            <?php if ($category['product_count'] > 0): ?>
                                <span class="cat-status is-on"><i class="bi bi-check-circle-fill"></i> چالاک</span>
                            <?php else: ?>
                                <span class="cat-status is-off"><i class="bi bi-circle"></i> بەتاڵ</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle text-success"></i>
                        زیادکردنی کەتەلۆگی نوێ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="add_name" class="form-label">
                                <i class="bi bi-tag"></i> ناوی کەتەلۆگ *
                            </label>
                            <input type="text" class="form-control" id="add_name" name="name" 
                                   placeholder="بۆ نموونە: خواردن، پۆشاک، ئەلیکترۆنی..." required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="add_description" class="form-label">
                                <i class="bi bi-file-text"></i> وەسف (ئیختیاری)
                            </label>
                            <textarea class="form-control" id="add_description" name="description" rows="3"
                                      placeholder="وەسفێکی کورت لەبارەی ئەم کەتەلۆگەوە..."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x"></i> پاشگەزبوونەوە
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> زیادکردن
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil text-warning"></i>
                        دەستکاری کەتەلۆگ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">
                                <i class="bi bi-tag"></i> ناوی کەتەلۆگ *
                            </label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">
                                <i class="bi bi-file-text"></i> وەسف (ئیختیاری)
                            </label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x"></i> پاشگەزبوونەوە
                        </button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-circle"></i> نوێکردنەوە
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="category_id" id="delete_category_id">
    </form>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo asset('js/main.js'); ?>"></script>
    
    <script>
    
    
    // ئەگەر action=add بێت، راستەوخۆ modal ی add category نیشان بدە
<?php if ($showAddForm): ?>
document.addEventListener('DOMContentLoaded', function() {
    const addModal = new bootstrap.Modal(document.getElementById('addCategoryModal'));
    addModal.show();
});
<?php endif; ?>


        function editCategory(category) {
            document.getElementById('edit_category_id').value = category.id;
            document.getElementById('edit_name').value = category.name;
            document.getElementById('edit_description').value = category.description || '';
            
            const editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
            editModal.show();
        }
        
        function deleteCategory(categoryId, categoryName, productCount) {
            let message = `ئایا دڵنیایت لە سڕینەوەی کەتەلۆگی "${categoryName}"؟`;
            
            if (productCount > 0) {
                message = `کەتەلۆگی "${categoryName}" ${productCount} کاڵای تێدایە. ناتوانیت بیسڕیتەوە.`;
                alert(message);
                return;
            }
            
            if (confirm(message)) {
                document.getElementById('delete_category_id').value = categoryId;
                document.getElementById('deleteForm').submit();
            }
        }
        
        // Add hover effects
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.hover-shadow');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = '0 0.5rem 1rem rgba(0,0,0,0.15)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '';
                });
            });
            
            // Auto-focus on modal inputs
            document.getElementById('addCategoryModal').addEventListener('shown.bs.modal', function() {
                document.getElementById('add_name').focus();
            });
            
            document.getElementById('editCategoryModal').addEventListener('shown.bs.modal', function() {
                document.getElementById('edit_name').focus();
            });
        });
        
        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const nameInput = this.querySelector('input[name="name"]');
                if (nameInput && !nameInput.value.trim()) {
                    e.preventDefault();
                    nameInput.focus();
                    alert('تکایە ناوی کەتەلۆگ داخڵ بکە');
                }
            });
        });
    </script>
</body>
</html>