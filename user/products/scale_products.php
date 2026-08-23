<?php
/**
 * کاڵاکانی قەپانی زیرەک - user/products/scale_products.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/scale_barcode_parser.php';
require_once '../../includes/scale_product_sync.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

if ($isSubUser && empty($userPermissions['products'])) {
    setMessage('دەسەڵاتی بەڕێوەبردنی کاڵاکانت نییە', 'error');
    redirect(url('user/products/main.php'));
}

requireSmartScaleAccess();

$settings = getScaleBarcodeSettingsForUser($conn, $userId);
$tablesReady = scaleTablesReady($conn);
$codeDigits = (int)$settings['product_code_digits'];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tablesReady) {
        $errors[] = 'خشتەکانی قەپانی زیرەک لە داتابەیسدا نییە.';
    } elseif (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $name = cleanInput($_POST['name'] ?? '');
        $rawCode = cleanInput($_POST['product_code'] ?? '');
        $productCode = padScaleProductCode($rawCode, $codeDigits);
        $buyPrice = (float)($_POST['buy_price'] ?? 0);
        $sellPrice = (float)($_POST['sell_price'] ?? 0);
        $wholesalePrice = (float)($_POST['wholesale_price'] ?? 0);
        $specialPrice = (float)($_POST['special_price'] ?? 0);
        $stockQty = (float)($_POST['stock_quantity'] ?? 0);
        $scaleId = (int)($_POST['scale_product_id'] ?? 0);

        if (in_array($action, ['add', 'edit'], true)) {
            if ($name === '') {
                $errors[] = 'ناوی کاڵا پێویستە.';
            }
            if ($productCode === '') {
                $errors[] = 'کۆدی کاڵا پێویستە.';
            }
            if ($sellPrice <= 0) {
                $errors[] = 'نرخی فرۆشتن دەبێت لە سفر زیاتر بێت.';
            }
            if ($buyPrice < 0 || $wholesalePrice < 0 || $specialPrice < 0 || $stockQty < 0) {
                $errors[] = 'نرخ و بڕ نابێت لە ژێر سفر بن.';
            }
        }

        if (empty($errors)) {
            $payload = [
                'product_code' => $productCode,
                'name' => $name,
                'buy_price' => $buyPrice,
                'sell_price' => $sellPrice,
                'wholesale_price' => $wholesalePrice,
                'special_price' => $specialPrice,
                'stock_quantity' => $stockQty,
            ];

            try {
                $conn->begin_transaction();

                if ($action === 'add') {
                    $dupStmt = $conn->prepare('SELECT id FROM scale_products WHERE user_id = ? AND product_code = ? LIMIT 1');
                    $dupStmt->bind_param('is', $userId, $productCode);
                    $dupStmt->execute();
                    if ($dupStmt->get_result()->num_rows > 0) {
                        $dupStmt->close();
                        throw new RuntimeException('ئەم کۆدی کاڵایە پێشتر هەیە.');
                    }
                    $dupStmt->close();

                    $linkedBarcode = buildScaleProductBarcode($settings, $productCode);
                    $barcodeStmt = $conn->prepare('SELECT id FROM products WHERE user_id = ? AND barcode = ? LIMIT 1');
                    $barcodeStmt->bind_param('is', $userId, $linkedBarcode);
                    $barcodeStmt->execute();
                    if ($barcodeStmt->get_result()->num_rows > 0) {
                        $barcodeStmt->close();
                        throw new RuntimeException('بارکۆدی پەیوەست پێشتر بەکارهاتووە.');
                    }
                    $barcodeStmt->close();

                    $productId = createLinkedProductForScale($conn, $userId, $payload, $settings);
                    $insertStmt = $conn->prepare('
                        INSERT INTO scale_products (
                            user_id, product_code, name, buy_price, sell_price, wholesale_price, special_price,
                            stock_quantity, product_id, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ');
                    $insertStmt->bind_param(
                        'issdddddi',
                        $userId,
                        $productCode,
                        $name,
                        $buyPrice,
                        $sellPrice,
                        $wholesalePrice,
                        $specialPrice,
                        $stockQty,
                        $productId
                    );
                    if (!$insertStmt->execute()) {
                        $insertStmt->close();
                        throw new RuntimeException('هەڵە لە زیادکردنی کاڵا.');
                    }
                    $insertStmt->close();
                    $success = 'کاڵای قەپان بە سەرکەوتوویی زیادکرا.';
                } elseif ($action === 'edit' && $scaleId > 0) {
                    $existing = loadScaleProductRow($conn, $userId, $scaleId);
                    if (!$existing) {
                        throw new RuntimeException('کاڵا نەدۆزرایەوە یان دەسەڵاتت نییە.');
                    }

                    if ($productCode !== $existing['product_code']) {
                        $dupStmt = $conn->prepare('SELECT id FROM scale_products WHERE user_id = ? AND product_code = ? AND id != ? LIMIT 1');
                        $dupStmt->bind_param('isi', $userId, $productCode, $scaleId);
                        $dupStmt->execute();
                        if ($dupStmt->get_result()->num_rows > 0) {
                            $dupStmt->close();
                            throw new RuntimeException('ئەم کۆدی کاڵایە پێشتر هەیە.');
                        }
                        $dupStmt->close();
                    }

                    $productId = (int)($existing['product_id'] ?? 0);
                    if ($productId <= 0) {
                        $productId = createLinkedProductForScale($conn, $userId, $payload, $settings);
                    } else {
                        syncLinkedProductForScale($conn, $userId, $productId, $payload, $settings);
                    }

                    $updateStmt = $conn->prepare('
                        UPDATE scale_products
                        SET product_code = ?, name = ?, buy_price = ?, sell_price = ?, wholesale_price = ?,
                            special_price = ?, stock_quantity = ?, product_id = ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ');
                    $updateStmt->bind_param(
                        'ssddddddii',
                        $productCode,
                        $name,
                        $buyPrice,
                        $sellPrice,
                        $wholesalePrice,
                        $specialPrice,
                        $stockQty,
                        $productId,
                        $scaleId,
                        $userId
                    );
                    if (!$updateStmt->execute()) {
                        $updateStmt->close();
                        throw new RuntimeException('هەڵە لە نوێکردنەوەی کاڵا.');
                    }
                    $updateStmt->close();
                    $success = 'کاڵای قەپان بە سەرکەوتوویی نوێکرایەوە.';
                } elseif ($action === 'delete' && $scaleId > 0) {
                    $existing = loadScaleProductRow($conn, $userId, $scaleId);
                    if (!$existing) {
                        throw new RuntimeException('کاڵا نەدۆزرایەوە یان دەسەڵاتت نییە.');
                    }

                    $productId = (int)($existing['product_id'] ?? 0);
                    $delStmt = $conn->prepare('DELETE FROM scale_products WHERE id = ? AND user_id = ?');
                    $delStmt->bind_param('ii', $scaleId, $userId);
                    $delStmt->execute();
                    $deleted = $delStmt->affected_rows > 0;
                    $delStmt->close();

                    if (!$deleted) {
                        throw new RuntimeException('کاڵا نەدۆزرایەوە.');
                    }

                    deleteLinkedProductForScale($conn, $userId, $productId);
                    $success = 'کاڵای قەپان بە سەرکەوتوویی سڕایەوە.';
                }

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = $e->getMessage();
            }
        }
    }
}

$products = [];
if ($tablesReady) {
    $listStmt = $conn->prepare('
        SELECT id, product_code, name, buy_price, sell_price, wholesale_price, special_price,
               stock_quantity, product_id, created_at, updated_at
        FROM scale_products
        WHERE user_id = ?
        ORDER BY product_code ASC, id ASC
    ');
    if ($listStmt) {
        $listStmt->bind_param('i', $userId);
        $listStmt->execute();
        $products = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $listStmt->close();
    }
}

$totalProducts = count($products);
$totalStockKg = 0.0;
foreach ($products as $row) {
    $totalStockKg += (float)$row['stock_quantity'];
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>کاڵاکانی قەپانی زیرەک - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    <style>
        .scale-card {
            border: 1px solid var(--bs-border-color);
            border-radius: 12px;
            background-color: var(--bs-body-bg);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .scale-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .12);
        }
        .code-badge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            direction: ltr;
            display: inline-block;
        }
    </style>
</head>
<body class="products-module-page products-scale-page bg-body-secondary">
    <?php
    $productsNavId = 'productsScaleNav';
    $productsNavLinks = [
        ['href' => url('user/products/scale_settings.php'), 'icon' => 'bi-sliders', 'text' => 'ڕێکخستنەکانی قەپان'],
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <div class="container py-4 products-page-content">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-1">
                    <i class="bi bi-speedometer2 text-primary"></i>
                    کاڵاکانی قەپانی زیرەک
                </h1>
                <p class="text-muted mb-0">کاڵاکانی کیلۆیی قەپان — یەکەی هەمیشە کیلۆیە</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo url('user/products/scale_settings.php'); ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-sliders"></i> ڕێکخستنەکان
                </a>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addScaleProductModal">
                    <i class="bi bi-plus-circle"></i> کاڵای نوێ
                </button>
            </div>
        </div>

        <?php if (!$tablesReady): ?>
            <div class="alert alert-danger">
                <i class="bi bi-database-exclamation"></i>
                خشتەکانی قەپانی زیرەک لە داتابەیسدا نییە. تکایە فایلی
                <code>database/migrations/2026_07_07_smart_scale.sql</code>
                جێبەجێ بکە.
            </div>
        <?php endif; ?>

        <?php if (empty($settings['is_enabled'])): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                قەپانی زیرەک لە ڕێکخستنەکاندا چالاک نییە.
                <a href="<?php echo url('user/products/scale_settings.php'); ?>">چالاکی بکە</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm bg-primary text-white">
                    <div class="card-body">
                        <h6 class="opacity-75 mb-2">کۆی کاڵاکان</h6>
                        <h2 class="mb-0"><?php echo (int)$totalProducts; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm bg-success text-white">
                    <div class="card-body">
                        <h6 class="opacity-75 mb-2">کۆی بڕی بەردەست (کگ)</h6>
                        <h2 class="mb-0"><?php echo number_format($totalStockKg, 3); ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($products)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-speedometer2 display-4 text-muted"></i>
                    <h4 class="mt-3">هیچ کاڵایەکی قەپان نییە</h4>
                    <p class="text-muted mb-4">یەکەم کاڵای قەپان زیاد بکە بۆ بەکارهێنان لە فرۆشتن.</p>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addScaleProductModal">
                        <i class="bi bi-plus-circle"></i> زیادکردنی یەکەم کاڵا
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive card shadow-sm">
                <table class="table table-hover mb-0 align-middle products-scale-table">
                    <thead class="table-light">
                        <tr>
                            <th>کۆدی کاڵا</th>
                            <th>ناو</th>
                            <th>کڕین/کگ</th>
                            <th>فرۆشتن/کگ</th>
                            <th>جوملە/کگ</th>
                            <th>تایبەت/کگ</th>
                            <th>بڕ (کگ)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><span class="badge bg-secondary code-badge"><?php echo htmlspecialchars($product['product_code'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((float)$product['buy_price'], 0); ?></td>
                                <td><?php echo number_format((float)$product['sell_price'], 0); ?></td>
                                <td><?php echo number_format((float)$product['wholesale_price'], 0); ?></td>
                                <td><?php echo number_format((float)$product['special_price'], 0); ?></td>
                                <td><?php echo number_format((float)$product['stock_quantity'], 3); ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-warning"
                                        onclick='editScaleProduct(<?php echo json_encode($product, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="deleteScaleProduct(<?php echo (int)$product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php
    $formFields = static function (string $prefix = '') {
        global $codeDigits;
        ?>
        <div class="mb-3">
            <label class="form-label">کۆدی کاڵا (<?php echo (int)$codeDigits; ?> ژمارە)</label>
            <input type="text" name="product_code" id="<?php echo $prefix; ?>product_code" class="form-control" inputmode="numeric" required>
            <div class="form-text">نموونە: 2 → <?php echo str_pad('2', $codeDigits, '0', STR_PAD_LEFT); ?></div>
        </div>
        <div class="mb-3">
            <label class="form-label">ناوی کاڵا</label>
            <input type="text" name="name" id="<?php echo $prefix; ?>name" class="form-control" maxlength="200" required>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">نرخی کڕین بۆ هەر کیلۆیەک</label>
                <input type="number" name="buy_price" id="<?php echo $prefix; ?>buy_price" class="form-control" min="0" step="0.001" value="0">
            </div>
            <div class="col-md-6">
                <label class="form-label">نرخی فرۆشتن بۆ هەر کیلۆیەک</label>
                <input type="number" name="sell_price" id="<?php echo $prefix; ?>sell_price" class="form-control" min="0.001" step="0.001" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">نرخی جوملە بۆ هەر کیلۆیەک</label>
                <input type="number" name="wholesale_price" id="<?php echo $prefix; ?>wholesale_price" class="form-control" min="0" step="0.001" value="0">
            </div>
            <div class="col-md-6">
                <label class="form-label">نرخی تایبەت بۆ هەر کیلۆیەک</label>
                <input type="number" name="special_price" id="<?php echo $prefix; ?>special_price" class="form-control" min="0" step="0.001" value="0">
            </div>
            <div class="col-md-6">
                <label class="form-label">بڕی بەردەست (کیلۆ)</label>
                <input type="number" name="stock_quantity" id="<?php echo $prefix; ?>stock_quantity" class="form-control" min="0" step="0.001" value="0">
            </div>
        </div>
        <?php
    };
    ?>

    <div class="modal fade" id="addScaleProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle text-success"></i> زیادکردنی کاڵای قەپان</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="add">
                        <?php $formFields('add_'); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                        <button type="submit" class="btn btn-success">زیادکردن</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editScaleProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil text-warning"></i> دەستکاری کاڵای قەپان</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="scale_product_id" id="edit_scale_product_id">
                        <?php $formFields('edit_'); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                        <button type="submit" class="btn btn-warning">نوێکردنەوە</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form id="deleteScaleProductForm" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="scale_product_id" id="delete_scale_product_id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editScaleProduct(product) {
            document.getElementById('edit_scale_product_id').value = product.id || '';
            document.getElementById('edit_product_code').value = product.product_code || '';
            document.getElementById('edit_name').value = product.name || '';
            document.getElementById('edit_buy_price').value = product.buy_price || 0;
            document.getElementById('edit_sell_price').value = product.sell_price || 0;
            document.getElementById('edit_wholesale_price').value = product.wholesale_price || 0;
            document.getElementById('edit_special_price').value = product.special_price || 0;
            document.getElementById('edit_stock_quantity').value = product.stock_quantity || 0;
            new bootstrap.Modal(document.getElementById('editScaleProductModal')).show();
        }

        function deleteScaleProduct(id, name) {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی "' + name + '"؟')) {
                document.getElementById('delete_scale_product_id').value = id;
                document.getElementById('deleteScaleProductForm').submit();
            }
        }
    </script>
</body>
</html>
