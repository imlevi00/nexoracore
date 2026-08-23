<?php
/**
 * بەڕێوەبردنی خزمەتگوزارییەکان - user/products/services.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

if ($isSubUser && empty($userPermissions['products'])) {
    setMessage('دەسەڵاتی بەڕێوەبردنی خزمەتگوزاریت نییە', 'error');
    redirect(url('user/products/main.php'));
}

requireProductsServicesAccess();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $name = cleanInput($_POST['name'] ?? '');
        $costPrice = (float)($_POST['cost_price'] ?? 0);
        $sellPrice = (float)($_POST['sell_price'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);

        if (in_array($action, ['add', 'edit'], true)) {
            if ($name === '') {
                $errors[] = 'ناوی خزمەتگوزاری پێویستە.';
            }
            if ($costPrice < 0) {
                $errors[] = 'نرخی تێچوو نابێت لە ژێر سفر بێت.';
            }
            if ($sellPrice < 0) {
                $errors[] = 'نرخی فرۆشتن نابێت لە ژێر سفر بێت.';
            }
        }

        if (empty($errors)) {
            if ($action === 'add') {
                $checkStmt = $conn->prepare('SELECT id FROM services WHERE user_id = ? AND name = ? LIMIT 1');
                if ($checkStmt) {
                    $checkStmt->bind_param('is', $userId, $name);
                    $checkStmt->execute();
                    $exists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                    if ($exists) {
                        $errors[] = 'ئەم ناوەی خزمەتگوزارییە پێشتر هەیە.';
                    }
                }

                if (empty($errors)) {
                    $insertStmt = $conn->prepare('
                        INSERT INTO services (user_id, name, cost_price, sell_price, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ');
                    if ($insertStmt) {
                        $insertStmt->bind_param('isdd', $userId, $name, $costPrice, $sellPrice);
                        if ($insertStmt->execute()) {
                            $success = 'خزمەتگوزاری بە سەرکەوتوویی زیادکرا.';
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە زیادکردنی خزمەتگوزاری.';
                        }
                        $insertStmt->close();
                    } else {
                        $errors[] = 'هەڵەی ئامادەکردنی داتاڕێژە.';
                    }
                }
            } elseif ($action === 'edit' && $serviceId > 0) {
                $checkOwnerStmt = $conn->prepare('SELECT id FROM services WHERE id = ? AND user_id = ? LIMIT 1');
                if ($checkOwnerStmt) {
                    $checkOwnerStmt->bind_param('ii', $serviceId, $userId);
                    $checkOwnerStmt->execute();
                    $exists = $checkOwnerStmt->get_result()->num_rows > 0;
                    $checkOwnerStmt->close();
                    if (!$exists) {
                        $errors[] = 'خزمەتگوزاری نەدۆزرایەوە یان دەسەڵاتت نییە.';
                    }
                }

                if (empty($errors)) {
                    $duplicateStmt = $conn->prepare('SELECT id FROM services WHERE user_id = ? AND name = ? AND id != ? LIMIT 1');
                    if ($duplicateStmt) {
                        $duplicateStmt->bind_param('isi', $userId, $name, $serviceId);
                        $duplicateStmt->execute();
                        if ($duplicateStmt->get_result()->num_rows > 0) {
                            $errors[] = 'ئەم ناوەی خزمەتگوزارییە پێشتر بەکارهاتووە.';
                        }
                        $duplicateStmt->close();
                    }
                }

                if (empty($errors)) {
                    $updateStmt = $conn->prepare('
                        UPDATE services
                        SET name = ?, cost_price = ?, sell_price = ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ');
                    if ($updateStmt) {
                        $updateStmt->bind_param('sddii', $name, $costPrice, $sellPrice, $serviceId, $userId);
                        if ($updateStmt->execute()) {
                            $success = 'خزمەتگوزاری بە سەرکەوتوویی نوێکرایەوە.';
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی خزمەتگوزاری.';
                        }
                        $updateStmt->close();
                    }
                }
            } elseif ($action === 'delete' && $serviceId > 0) {
                $deleteStmt = $conn->prepare('DELETE FROM services WHERE id = ? AND user_id = ?');
                if ($deleteStmt) {
                    $deleteStmt->bind_param('ii', $serviceId, $userId);
                    if ($deleteStmt->execute()) {
                        if ($deleteStmt->affected_rows > 0) {
                            $success = 'خزمەتگوزاری بە سەرکەوتوویی سڕایەوە.';
                        } else {
                            $errors[] = 'خزمەتگوزاری نەدۆزرایەوە یان دەسەڵاتت نییە.';
                        }
                    } else {
                        $errors[] = 'هەڵەیەک ڕوویدا لە سڕینەوەی خزمەتگوزاری.';
                    }
                    $deleteStmt->close();
                } else {
                    $errors[] = 'هەڵەی ئامادەکردنی فەرمانی سڕینەوە.';
                }
            }
        }
    }
}

$services = [];
$totalServices = 0;
$totalPotentialRevenue = 0.0;

$listStmt = $conn->prepare('
    SELECT id, name, cost_price, sell_price, created_at, updated_at
    FROM services
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
');
if ($listStmt) {
    $listStmt->bind_param('i', $userId);
    $listStmt->execute();
    $services = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $listStmt->close();
}

$totalServices = count($services);
foreach ($services as $serviceRow) {
    $totalPotentialRevenue += (float)$serviceRow['sell_price'];
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>بەڕێوەبردنی خزمەتگوزاری - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    <style>
        .service-card {
            border: 1px solid var(--bs-border-color);
            border-radius: 12px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            background-color: var(--bs-body-bg);
        }
        .service-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .12);
        }
        .service-price {
            font-weight: 700;
            font-size: 1rem;
        }
        .stats-card {
            border: 0;
            border-radius: 12px;
            color: #fff !important;
        }
        .stats-card.bg-primary {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%) !important;
        }
        .stats-card.bg-success {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%) !important;
        }
        .stats-card .card-body,
        .stats-card .card-body h6,
        .stats-card .card-body h2 {
            color: #fff !important;
        }
    </style>
</head>
<body class="products-module-page products-services-page bg-body-secondary">
    <?php
    $productsNavId = 'productsServicesNav';
    $productsNavLinks = [
        ['href' => url('user/products/index.php'), 'icon' => 'bi-box-seam', 'text' => 'لیستی کاڵاکان'],
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <div class="container py-4 products-page-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">
                    <i class="bi bi-briefcase text-primary"></i>
                    بەڕێوەبردنی خزمەتگوزاری
                </h1>
                <p class="text-muted mb-0">زیادکردن، دەستکاری و سڕینەوەی خزمەتگوزارییەکان</p>
            </div>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                <i class="bi bi-plus-circle"></i> خزمەتگوزاری نوێ
            </button>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <strong>هەڵە:</strong>
                <ul class="mb-0 mt-2">
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
                <div class="card stats-card shadow-sm bg-primary text-white">
                    <div class="card-body">
                        <h6 class="opacity-75 mb-2">کۆی خزمەتگوزارییەکان</h6>
                        <h2 class="mb-0"><?php echo (int)$totalServices; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card stats-card shadow-sm bg-success text-white">
                    <div class="card-body">
                        <h6 class="opacity-75 mb-2">کۆی نرخی فرۆشتن</h6>
                        <h2 class="mb-0"><?php echo number_format($totalPotentialRevenue, 0); ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($services)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-briefcase display-4 text-muted"></i>
                    <h4 class="mt-3">هیچ خزمەتگوزارییەک نییە</h4>
                    <p class="text-muted mb-4">خزمەتگوزاری نوێ زیاد بکە بۆ بەکارهێنانی لە POS.</p>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                        <i class="bi bi-plus-circle"></i> زیادکردنی یەکەم خزمەتگوزاری
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($services as $service): ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="service-card h-100 p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="mb-1"><?php echo htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8'); ?></h5>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button class="dropdown-item" onclick='editService(<?php echo json_encode($service, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                                <i class="bi bi-pencil"></i> دەستکاری
                                            </button>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button class="dropdown-item text-danger" onclick="deleteService(<?php echo (int)$service['id']; ?>, '<?php echo htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8'); ?>')">
                                                <i class="bi bi-trash"></i> سڕینەوە
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="small text-muted mb-3">
                                دروستکراوە: <?php echo date('Y/m/d H:i', strtotime((string)$service['created_at'])); ?>
                            </div>
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-muted small">تێچوو</div>
                                    <div class="service-price text-warning"><?php echo number_format((float)$service['cost_price'], 0); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="text-muted small">فرۆشتن</div>
                                    <div class="service-price text-success"><?php echo number_format((float)$service['sell_price'], 0); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="addServiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle text-success"></i> زیادکردنی خزمەتگوزاری</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">ناوی خزمەتگوزاری</label>
                            <input type="text" name="name" class="form-control" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نرخی تێچوو</label>
                            <input type="number" name="cost_price" class="form-control" min="0" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نرخی فرۆشتن</label>
                            <input type="number" name="sell_price" class="form-control" min="0" step="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                        <button type="submit" class="btn btn-success">زیادکردن</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editServiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil text-warning"></i> دەستکاری خزمەتگوزاری</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="service_id" id="edit_service_id">
                        <div class="mb-3">
                            <label class="form-label">ناوی خزمەتگوزاری</label>
                            <input type="text" name="name" id="edit_name" class="form-control" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نرخی تێچوو</label>
                            <input type="number" name="cost_price" id="edit_cost_price" class="form-control" min="0" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نرخی فرۆشتن</label>
                            <input type="number" name="sell_price" id="edit_sell_price" class="form-control" min="0" step="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                        <button type="submit" class="btn btn-warning">نوێکردنەوە</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form id="deleteServiceForm" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="service_id" id="delete_service_id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editService(service) {
            document.getElementById('edit_service_id').value = service.id || '';
            document.getElementById('edit_name').value = service.name || '';
            document.getElementById('edit_cost_price').value = service.cost_price || 0;
            document.getElementById('edit_sell_price').value = service.sell_price || 0;

            const editModal = new bootstrap.Modal(document.getElementById('editServiceModal'));
            editModal.show();
        }

        function deleteService(serviceId, serviceName) {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی "' + serviceName + '"؟')) {
                document.getElementById('delete_service_id').value = serviceId;
                document.getElementById('deleteServiceForm').submit();
            }
        }
    </script>
</body>
</html>
