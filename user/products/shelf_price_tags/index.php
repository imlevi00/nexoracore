<?php
/**
 * بەڕێوەبردنی تێمپلێتەکانی نرخی سەر ڕەفە - user/products/shelf_price_tags/index.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'products.view', [
    'route' => '/user/products/shelf_price_tags/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireShelfPriceTagsAccess();
$userId = $currentUser['id'];

// Check if user has any templates, if not, create default templates
$checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM shelf_price_tags WHERE user_id = ?");
$checkStmt->bind_param("i", $userId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($checkResult['count'] == 0) {
    // Create 2 default templates
    $defaultTemplates = [
        [
            'name' => 'تێمپلێتی ساکار',
            'type' => 'simple',
            'items' => [
                ['text' => '{product_name}', 'type' => 'product_name', 'size' => 14, 'weight' => 'bold', 'align' => 'center'],
                ['text' => '{price}', 'type' => 'price', 'size' => 16, 'weight' => 'bold', 'align' => 'center'],
                ['text' => 'بەروار: {date}', 'type' => 'date', 'size' => 10, 'weight' => 'normal', 'align' => 'center']
            ]
        ],
        [
            'name' => 'تێمپلێتی ناساندن',
            'type' => 'detailed',
            'items' => [
                ['text' => '{product_name}', 'type' => 'product_name', 'size' => 14, 'weight' => 'bold', 'align' => 'center'],
                ['text' => 'نرخ: {price}', 'type' => 'price', 'size' => 16, 'weight' => 'bold', 'align' => 'center'],
                ['text' => 'کۆد: {barcode}', 'type' => 'barcode', 'size' => 10, 'weight' => 'normal', 'align' => 'center'],
                ['text' => 'بەروار: {date}', 'type' => 'date', 'size' => 10, 'weight' => 'normal', 'align' => 'center'],
                ['text' => '{business_name}', 'type' => 'business_name', 'size' => 9, 'weight' => 'normal', 'align' => 'center']
            ]
        ]
    ];
    
    foreach ($defaultTemplates as $index => $templateData) {
        // Only the first template should be default
        $isDefault = ($index === 0) ? 1 : 0;
        
        // Insert template
        $insertTemplateStmt = $conn->prepare("INSERT INTO shelf_price_tags (user_id, name, template_type, is_default, created_at) VALUES (?, ?, ?, ?, NOW())");
        $insertTemplateStmt->bind_param("issi", $userId, $templateData['name'], $templateData['type'], $isDefault);
        $insertTemplateStmt->execute();
        $templateId = $insertTemplateStmt->insert_id;
        $insertTemplateStmt->close();
        
        // Insert template items
        $order = 0;
        foreach ($templateData['items'] as $itemData) {
            $insertItemStmt = $conn->prepare("INSERT INTO shelf_price_tag_items (template_id, text_content, text_type, font_size, font_weight, text_align, display_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $insertItemStmt->bind_param("ississi", $templateId, $itemData['text'], $itemData['type'], $itemData['size'], $itemData['weight'], $itemData['align'], $order);
            $insertItemStmt->execute();
            $insertItemStmt->close();
            $order++;
        }
    }
}

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
                $templateType = cleanInput($_POST['template_type'] ?? 'simple');
                
                if (empty($name)) {
                    $errors[] = 'ناوی تێمپلێت پێویستە';
                } else {
                    // Check if user already has 3 templates
                    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM shelf_price_tags WHERE user_id = ?");
                    $countStmt->bind_param("i", $userId);
                    $countStmt->execute();
                    $countResult = $countStmt->get_result()->fetch_assoc();
                    
                    if ($countResult['count'] >= 3) {
                        $errors[] = 'تەنها دەتوانیت تا 3 تێمپلێت دروست بکەیت';
                    } else {
                        // Check if template name already exists for this user
                        $stmt = $conn->prepare("SELECT id FROM shelf_price_tags WHERE name = ? AND user_id = ?");
                        $stmt->bind_param("si", $name, $userId);
                        $stmt->execute();
                        
                        if ($stmt->get_result()->num_rows > 0) {
                            $errors[] = 'ئەم ناوەی تێمپلێت پێشتر بەکارهاتووە';
                        } else {
                            // Insert new template
                            $insertStmt = $conn->prepare("INSERT INTO shelf_price_tags (user_id, name, template_type, created_at) VALUES (?, ?, ?, NOW())");
                            $insertStmt->bind_param("iss", $userId, $name, $templateType);
                            
                            if ($insertStmt->execute()) {
                                $templateId = $insertStmt->insert_id;
                                $success = "تێمپلێتی '$name' بە سەرکەوتوویی زیادکرا";
                                writeLog("Shelf price tag template added: $name by user: {$currentUser['email']}");
                                
                                // Redirect to edit page
                                redirect(url("user/products/shelf_price_tags/edit.php?id=$templateId"));
                            } else {
                                $errors[] = 'هەڵەیەک ڕوویدا لە زیادکردنی تێمپلێت';
                            }
                            $insertStmt->close();
                        }
                        $stmt->close();
                    }
                    $countStmt->close();
                }
                break;
                
            case 'delete':
                $templateId = (int)($_POST['template_id'] ?? 0);
                
                if ($templateId > 0) {
                    // Check if template belongs to user
                    $stmt = $conn->prepare("SELECT name FROM shelf_price_tags WHERE id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $templateId, $userId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 0) {
                        $errors[] = 'تێمپلێت نەدۆزرایەوە یان دەسەڵاتت نییە';
                    } else {
                        $template = $result->fetch_assoc();
                        
                        // Delete template (items will be deleted via CASCADE)
                        $deleteStmt = $conn->prepare("DELETE FROM shelf_price_tags WHERE id = ? AND user_id = ?");
                        $deleteStmt->bind_param("ii", $templateId, $userId);
                        
                        if ($deleteStmt->execute()) {
                            $success = "تێمپلێتی '{$template['name']}' بە سەرکەوتوویی سڕایەوە";
                            writeLog("Shelf price tag template deleted: {$template['name']} (ID: $templateId) by user: {$currentUser['email']}");
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە سڕینەوەی تێمپلێت';
                        }
                        $deleteStmt->close();
                    }
                    $stmt->close();
                }
                break;
                
            case 'set_default':
                $templateId = (int)($_POST['template_id'] ?? 0);
                
                if ($templateId > 0) {
                    // Check if template belongs to user
                    $stmt = $conn->prepare("SELECT name FROM shelf_price_tags WHERE id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $templateId, $userId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 0) {
                        $errors[] = 'تێمپلێت نەدۆزرایەوە یان دەسەڵاتت نییە';
                    } else {
                        $template = $result->fetch_assoc();
                        
                        // First, set all templates to not default
                        $resetStmt = $conn->prepare("UPDATE shelf_price_tags SET is_default = 0 WHERE user_id = ?");
                        $resetStmt->bind_param("i", $userId);
                        $resetStmt->execute();
                        $resetStmt->close();
                        
                        // Then set the selected template as default
                        $setDefaultStmt = $conn->prepare("UPDATE shelf_price_tags SET is_default = 1 WHERE id = ? AND user_id = ?");
                        $setDefaultStmt->bind_param("ii", $templateId, $userId);
                        
                        if ($setDefaultStmt->execute()) {
                            $success = "تێمپلێتی '{$template['name']}' وەک بنەڕەتی دیاریکرا";
                            writeLog("Shelf price tag template set as default: {$template['name']} (ID: $templateId) by user: {$currentUser['email']}");
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە دیاریکردنی تێمپلێتی بنەڕەتی';
                        }
                        $setDefaultStmt->close();
                    }
                    $stmt->close();
                }
                break;
        }
    }
}

// Get all templates for this user
$templates = [];
$stmt = $conn->prepare("
    SELECT spt.*, 
           COUNT(spti.id) as item_count
    FROM shelf_price_tags spt
    LEFT JOIN shelf_price_tag_items spti ON spt.id = spti.template_id
    WHERE spt.user_id = ?
    GROUP BY spt.id
    ORDER BY spt.created_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $templates[] = $row;
}
$stmt->close();

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>نرخی سەر ڕەفە - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-subpages.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="products-module-page products-shelf-tags-page">

    <?php
    $productsNavId = 'productsShelfTagsNav';
    $productsNavLinks = [
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
    ];
    include dirname(__DIR__) . '/partials/products_nav.php';
    ?>

    <div class="container-fluid py-4 products-page-content pp-wrap">
        
        <header class="pp-hero">
            <div>
                <div class="pp-kicker"><i class="bi bi-tag"></i> چاپ و نیشانە</div>
                <h1><i class="bi bi-upc"></i> نرخی سەر ڕەفە</h1>
                <p class="pp-hero-sub">تێمپلێتەکانی نرخی سەر ڕەفە دروست بکە، دەستکاری بکە و چاپ بکە</p>
                <div class="pp-hero-pills">
                    <span class="pp-pill"><i class="bi bi-layers"></i> <?php echo count($templates); ?> تێمپلێت</span>
                    <span class="pp-pill"><i class="bi bi-shield-check"></i> سنوور: ٣ تێمپلێت</span>
                </div>
            </div>
            <div class="pp-actions">
                <?php if (count($templates) < 3): ?>
                <button type="button" class="pp-btn pp-btn-primary" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
                    <i class="bi bi-plus-lg"></i> تێمپلێتی نوێ
                </button>
                <?php else: ?>
                <button type="button" class="pp-btn pp-btn-ghost" disabled title="تەنها دەتوانیت تا 3 تێمپلێت دروست بکەیت">
                    <i class="bi bi-plus-lg"></i> سنووردار (٣/٣)
                </button>
                <?php endif; ?>
                <a href="<?php echo url('user/products/index.php'); ?>" class="pp-btn pp-btn-ghost">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </header>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (empty($templates)): ?>
            <div class="pp-panel">
                <div class="pp-empty">
                    <div class="pp-empty-icon"><i class="bi bi-upc-scan"></i></div>
                    <h3>هیچ تێمپلێتێکت نییە</h3>
                    <p>دەست پێ بکە بە دروستکردنی یەکەم تێمپلێتی نرخی سەر ڕەفە.</p>
                    <button type="button" class="pp-btn pp-btn-primary" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
                        <i class="bi bi-plus-lg"></i> یەکەم تێمپلێت
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="tag-grid">
                <?php foreach ($templates as $template): ?>
                    <?php
                    $typeNames = [
                        'simple' => 'ساکار',
                        'detailed' => 'ناساندن',
                        'professional' => 'پیشەسازی'
                    ];
                    $typeLabel = $typeNames[$template['template_type']] ?? $template['template_type'];
                    ?>
                    <article class="tag-card<?php echo $template['is_default'] ? ' is-default' : ''; ?>">
                        <div class="tag-head">
                            <h3 class="tag-name"><?php echo htmlspecialchars($template['name']); ?></h3>
                            <div class="d-flex flex-column align-items-end gap-1">
                                <?php if ($template['is_default']): ?>
                                <span class="pp-pill" style="background:color-mix(in srgb,#10b981 16%,white);color:#047857;border-color:transparent">
                                    <i class="bi bi-star-fill"></i> بنەڕەتی
                                </span>
                                <?php endif; ?>
                                <span class="tag-type tag-type-<?php echo htmlspecialchars($template['template_type']); ?>">
                                    <?php echo htmlspecialchars($typeLabel); ?>
                                </span>
                            </div>
                        </div>

                        <div class="tag-preview">
                            <div class="name">ناوی کاڵا</div>
                            <div class="price">١٢٬٠٠٠ IQD</div>
                            <div class="meta"><?php echo (int)$template['item_count']; ?> دەق · <?php echo htmlspecialchars($typeLabel); ?></div>
                        </div>

                        <div class="tag-actions">
                            <a href="<?php echo url("user/products/shelf_price_tags/edit.php?id={$template['id']}"); ?>"
                               class="pp-btn pp-btn-primary pp-btn-sm">
                                <i class="bi bi-pencil"></i> دەستکاری
                            </a>
                            <a href="<?php echo url("user/products/shelf_price_tags/print.php?id={$template['id']}"); ?>"
                               class="pp-btn pp-btn-success pp-btn-sm" target="_blank">
                                <i class="bi bi-printer"></i> چاپ
                            </a>
                            <button type="button" class="pp-btn pp-btn-danger pp-btn-sm pp-btn-icon"
                                    onclick="deleteTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['name'], ENT_QUOTES); ?>')"
                                    title="سڕینەوە">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <?php if (!$template['is_default']): ?>
                        <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm w-100 mt-2"
                                onclick="setAsDefault(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['name'], ENT_QUOTES); ?>')">
                            <i class="bi bi-star"></i> بنەڕەتی بکە
                        </button>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Add Template Modal -->
    <div class="modal fade" id="addTemplateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title">زیادکردنی تێمپلێتی نوێ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label for="name" class="form-label">ناوی تێمپلێت <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="template_type" class="form-label">جۆری تێمپلێت</label>
                            <select class="form-select" id="template_type" name="template_type">
                                <option value="simple">ساکار</option>
                                <option value="detailed">ناساندن</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">هەڵوەشاندنەوە</button>
                        <button type="submit" class="btn btn-primary">زیادکردن</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="template_id" id="deleteTemplateId">
    </form>

    <!-- Set Default Form -->
    <form id="setDefaultForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="set_default">
        <input type="hidden" name="template_id" id="setDefaultTemplateId">
    </form>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function deleteTemplate(templateId, templateName) {
            if (confirm(`ئایا دڵنیایت لە سڕینەوەی تێمپلێتی "${templateName}"؟`)) {
                document.getElementById('deleteTemplateId').value = templateId;
                document.getElementById('deleteForm').submit();
            }
        }
        
        function setAsDefault(templateId, templateName) {
            if (confirm(`ئایا دڵنیایت لە دیاریکردنی تێمپلێتی "${templateName}" وەک بنەڕەتی؟`)) {
                document.getElementById('setDefaultTemplateId').value = templateId;
                document.getElementById('setDefaultForm').submit();
            }
        }
    </script>

</body>
</html>
