<?php
/**
 * دەستکاریکردنی تێمپلێتی نرخی سەر ڕەفە - user/products/shelf_price_tags/edit.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
requireShelfPriceTagsAccess();
$userId = $currentUser['id'];

$templateId = (int)($_GET['id'] ?? 0);

if (!$templateId) {
    setMessage('ID ی تێمپلێت پێویستە', 'error');
    redirect(url('user/products/shelf_price_tags/index.php'));
}

// Get template info
$stmt = $conn->prepare("SELECT * FROM shelf_price_tags WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $templateId, $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    setMessage('تێمپلێت نەدۆزرایەوە', 'error');
    redirect(url('user/products/shelf_price_tags/index.php'));
}

$template = $result->fetch_assoc();
$stmt->close();

// Get template items
$itemsStmt = $conn->prepare("
    SELECT * FROM shelf_price_tag_items 
    WHERE template_id = ?
    ORDER BY display_order ASC, id ASC
");
$itemsStmt->bind_param("i", $templateId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$items = [];
while ($row = $itemsResult->fetch_assoc()) {
    $items[] = $row;
}
$itemsStmt->close();

// Get business settings for preview
$settingsStmt = $conn->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
$settingsStmt->bind_param("i", $userId);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>دەستکاریکردنی تێمپلێت - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    
    <style>
        .item-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: white;
            transition: all 0.3s ease;
        }
        
        .item-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .item-card.dragging {
            opacity: 0.5;
        }
        
        .preview-container.shelf-tag-preview {
            background: white;
            border: 2px solid #333;
            border-radius: 8px;
            padding: 20px;
            max-width: 70mm;
            margin: 0 auto;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
        }
        
        .preview-item {
            margin-bottom: 10px;
            text-align: center;
        }
        
        .sortable-handle {
            cursor: move;
            color: #6c757d;
        }
        
        .sortable-handle:hover {
            color: #495057;
        }
        
        .btn-group-vertical .btn {
            margin-bottom: 5px;
        }
    </style>
</head>
<body class="products-module-page products-shelf-tags-page bg-body-secondary">

    <?php
    $productsNavId = 'productsShelfEditNav';
    $productsNavLinks = [
        ['href' => url('user/products/shelf_price_tags/index.php'), 'icon' => 'bi-tag', 'text' => 'نرخی سەر ڕەفە'],
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
    ];
    include dirname(__DIR__) . '/partials/products_nav.php';
    ?>

    <div class="container-fluid py-4 products-page-content">
        
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">دەستکاریکردنی تێمپلێت: <?php echo htmlspecialchars($template['name']); ?></h2>
                        <p class="text-muted mb-0">زیادکردن و دەستکاریکردنی دەقەکان</p>
                    </div>
                    <div>
                        <a href="<?php echo url("user/products/shelf_price_tags/print.php?id=$templateId"); ?>" 
                           class="btn btn-success" target="_blank">
                            <i class="bi bi-printer"></i> چاپکردن
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Items Management -->
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">دەقەکان</h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                            <i class="bi bi-plus-circle"></i> زیادکردنی دەق
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="itemsList">
                            <?php if (empty($items)): ?>
                            <div class="alert alert-info text-center">
                                هیچ دەقێک نییە. دەست پێ بکە بە زیادکردنی یەکەم دەقەکەت.
                            </div>
                            <?php else: ?>
                                <?php foreach ($items as $item): ?>
                                <div class="item-card" data-item-id="<?php echo $item['id']; ?>">
                                    <div class="d-flex align-items-start">
                                        <div class="sortable-handle me-2" style="cursor: move;">
                                            <i class="bi bi-grip-vertical" style="font-size: 20px;"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="mb-2">
                                                <strong><?php echo htmlspecialchars($item['text_content']); ?></strong>
                                                <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($item['text_type']); ?></span>
                                            </div>
                                            <div class="text-muted small">
                                                فۆنت: <?php echo $item['font_size']; ?>px, 
                                                قەڵەویی: <?php echo $item['font_weight']; ?>, 
                                                ڕیزکردن: <?php echo $item['text_align']; ?>
                                            </div>
                                        </div>
                                        <div class="btn-group-vertical btn-group-sm">
                                            <button class="btn btn-outline-primary" 
                                                    onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" 
                                                    onclick="deleteItem(<?php echo $item['id']; ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Preview -->
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">پیشاندان</h5>
                    </div>
                    <div class="card-body">
                        <div class="preview-container shelf-tag-preview" id="previewContainer">
                            <?php if (empty($items)): ?>
                            <div class="text-center text-muted">
                                دەق زیاد بکە بۆ پیشاندان
                            </div>
                            <?php else: ?>
                                <?php foreach ($items as $item): ?>
                                <div class="preview-item" 
                                     style="font-size: <?php echo $item['font_size']; ?>px; 
                                            font-weight: <?php echo $item['font_weight']; ?>; 
                                            text-align: <?php echo $item['text_align']; ?>;">
                                    <?php 
                                    $content = $item['text_content'];
                                    // Replace placeholders for preview
                                    $content = str_replace('{product_name}', 'ناوی کاڵا', $content);
                                    $content = str_replace('{price}', '10,000 د', $content);
                                    $content = str_replace('{barcode}', '1234567890', $content);
                                    $content = str_replace('{date}', date('Y/m/d'), $content);
                                    $content = str_replace('{business_name}', htmlspecialchars($currentUser['business_name']), $content);
                                    echo htmlspecialchars($content);
                                    ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-center mt-3">
                            <button class="btn btn-sm btn-secondary" onclick="refreshPreview()">
                                <i class="bi bi-arrow-clockwise"></i> نوێکردنەوەی پیشاندان
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Add/Edit Item Modal -->
    <div class="modal fade" id="addItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">زیادکردنی دەق</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="itemForm">
                        <input type="hidden" id="itemId" name="item_id">
                        <input type="hidden" name="template_id" value="<?php echo $templateId; ?>">
                        
                        <div class="mb-3">
                            <label for="text_content" class="form-label">ناوەڕۆکی دەق <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="text_content" name="text_content" rows="3" required></textarea>
                            <small class="form-text text-muted">
                                دەتوانیت placeholder بەکاربهێنیت: {product_name}, {price}, {barcode}, {date}, {business_name}
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="text_type" class="form-label">جۆری دەق</label>
                            <select class="form-select" id="text_type" name="text_type">
                                <option value="text">دەقی ساکار</option>
                                <option value="product_name">ناوی کاڵا</option>
                                <option value="price">نرخ</option>
                                <option value="barcode">کۆدی بارکۆد</option>
                                <option value="date">بەروار</option>
                                <option value="business_name">ناوی فرۆشگا</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="font_size" class="form-label">قەبارەی فۆنت</label>
                                <input type="number" class="form-control" id="font_size" name="font_size" value="12" min="8" max="48">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="font_weight" class="form-label">قەڵەویی فۆنت</label>
                                <select class="form-select" id="font_weight" name="font_weight">
                                    <option value="normal">Normal</option>
                                    <option value="bold">Bold</option>
                                    <option value="600">600</option>
                                    <option value="700">700</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="text_align" class="form-label">ڕیزکردن</label>
                                <select class="form-select" id="text_align" name="text_align">
                                    <option value="left">چەپ</option>
                                    <option value="center" selected>ناوەڕاست</option>
                                    <option value="right">ڕاست</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">هەڵوەشاندنەوە</button>
                    <button type="button" class="btn btn-primary" onclick="saveItem()">پاشەکەوتکردن</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    
    <script>
        const templateId = <?php echo $templateId; ?>;
        let currentEditingItem = null;
        let sortableInstance = null;
        
        function initSortable() {
            const itemsList = document.getElementById('itemsList');
            if (itemsList && !sortableInstance) {
                sortableInstance = new Sortable(itemsList, {
                    handle: '.sortable-handle',
                    animation: 150,
                    onEnd: function(evt) {
                        reorderItems();
                    }
                });
            }
        }
        
        // Initialize Sortable on page load
        initSortable();
        
        function refreshPreview() {
            loadItems();
        }
        
        function loadItems() {
            fetch(`api/templates.php?action=get_items&template_id=${templateId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateItemsList(data.data);
                        updatePreview(data.data);
                        // Reinitialize Sortable after DOM update
                        if (sortableInstance) {
                            sortableInstance.destroy();
                            sortableInstance = null;
                        }
                        initSortable();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('هەڵەیەک ڕوویدا لە بارکردنی دەقەکان');
                });
        }
        
        function updateItemsList(items) {
            const itemsList = document.getElementById('itemsList');
            if (items.length === 0) {
                itemsList.innerHTML = '<div class="alert alert-info text-center">هیچ دەقێک نییە. دەست پێ بکە بە زیادکردنی یەکەم دەقەکەت.</div>';
                return;
            }
            
            itemsList.innerHTML = items.map(item => `
                <div class="item-card" data-item-id="${item.id}">
                    <div class="d-flex align-items-start">
                        <div class="sortable-handle me-2" style="cursor: move;">
                            <i class="bi bi-grip-vertical" style="font-size: 20px;"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="mb-2">
                                <strong>${escapeHtml(item.text_content)}</strong>
                                <span class="badge bg-secondary ms-2">${item.text_type}</span>
                            </div>
                            <div class="text-muted small">
                                فۆنت: ${item.font_size}px, 
                                قەڵەویی: ${item.font_weight}, 
                                ڕیزکردن: ${item.text_align}
                            </div>
                        </div>
                        <div class="btn-group-vertical btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="editItem(${JSON.stringify(item).replace(/"/g, '&quot;')})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-outline-danger" onclick="deleteItem(${item.id})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        }
        
        function updatePreview(items) {
            const previewContainer = document.getElementById('previewContainer');
            if (items.length === 0) {
                previewContainer.innerHTML = '<div class="text-center text-muted">دەق زیاد بکە بۆ پیشاندان</div>';
                return;
            }
            
            const businessName = '<?php echo htmlspecialchars($currentUser['business_name'], ENT_QUOTES); ?>';
            
            previewContainer.innerHTML = items.map(item => {
                let content = item.text_content;
                content = content.replace(/{product_name}/g, 'ناوی کاڵا');
                content = content.replace(/{price}/g, '10,000 د');
                content = content.replace(/{barcode}/g, '1234567890');
                content = content.replace(/{date}/g, new Date().toLocaleDateString('ku'));
                content = content.replace(/{business_name}/g, businessName);
                
                return `<div class="preview-item" 
                             style="font-size: ${item.font_size}px; 
                                    font-weight: ${item.font_weight}; 
                                    text-align: ${item.text_align};">
                            ${escapeHtml(content)}
                        </div>`;
            }).join('');
        }
        
        function editItem(item) {
            currentEditingItem = item;
            document.getElementById('modalTitle').textContent = 'دەستکاریکردنی دەق';
            document.getElementById('itemId').value = item.id;
            document.getElementById('text_content').value = item.text_content;
            document.getElementById('text_type').value = item.text_type;
            document.getElementById('font_size').value = item.font_size;
            document.getElementById('font_weight').value = item.font_weight;
            document.getElementById('text_align').value = item.text_align;
            
            const modal = new bootstrap.Modal(document.getElementById('addItemModal'));
            modal.show();
        }
        
        function deleteItem(itemId) {
            if (!confirm('ئایا دڵنیایت لە سڕینەوەی ئەم دەقە؟')) {
                return;
            }
            
            fetch(`api/templates.php?action=delete_item&item_id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadItems();
                    } else {
                        alert(data.message || 'هەڵەیەک ڕوویدا');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('هەڵەیەک ڕوویدا');
                });
        }
        
        function saveItem() {
            const form = document.getElementById('itemForm');
            const formData = new FormData(form);
            const itemId = document.getElementById('itemId').value;
            
            const data = {
                template_id: templateId,
                text_content: document.getElementById('text_content').value,
                text_type: document.getElementById('text_type').value,
                font_size: parseInt(document.getElementById('font_size').value),
                font_weight: document.getElementById('font_weight').value,
                text_align: document.getElementById('text_align').value
            };
            
            if (!data.text_content.trim()) {
                alert('ناوەڕۆکی دەق پێویستە');
                return;
            }
            
            const url = itemId ? 'api/templates.php?action=update_item' : 'api/templates.php?action=add_item';
            if (itemId) {
                data.item_id = parseInt(itemId);
            }
            
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addItemModal'));
                    modal.hide();
                    form.reset();
                    document.getElementById('itemId').value = '';
                    document.getElementById('modalTitle').textContent = 'زیادکردنی دەق';
                    loadItems();
                } else {
                    alert(result.message || 'هەڵەیەک ڕوویدا');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('هەڵەیەک ڕوویدا');
            });
        }
        
        function reorderItems() {
            const items = Array.from(document.querySelectorAll('.item-card'));
            const itemOrders = items.map((item, index) => ({
                id: parseInt(item.dataset.itemId),
                order: index
            }));
            
            fetch('api/templates.php?action=reorder_items', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    template_id: templateId,
                    item_orders: itemOrders.map(item => item.id)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadItems();
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Reset modal when closed
        document.getElementById('addItemModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('itemForm').reset();
            document.getElementById('itemId').value = '';
            document.getElementById('modalTitle').textContent = 'زیادکردنی دەق';
            currentEditingItem = null;
        });
    </script>

</body>
</html>
