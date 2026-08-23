<?php
/**
 * بەڕێوەبردنی ناوچەکانی کڕیاران - user/customers/regions.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'customers.view', [
    'route' => '/user/customers/regions.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

$showAddForm = isset($_GET['action']) && $_GET['action'] === 'add';
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'add':
                enforceAuthorizationOrDeny($currentUser, 'customers.update', [
                    'route' => '/user/customers/regions.php',
                    'request_method' => 'POST'
                ], 'redirect');

                $name = cleanInput($_POST['name'] ?? '');

                if (empty($name)) {
                    $errors[] = 'ناوی ناوچە پێویستە';
                } else {
                    $stmt = $conn->prepare("SELECT id FROM customer_regions WHERE name = ? AND user_id = ?");
                    $stmt->bind_param("si", $name, $userId);
                    $stmt->execute();

                    if ($stmt->get_result()->num_rows > 0) {
                        $errors[] = 'ئەم ناوەی ناوچە پێشتر بەکارهاتووە';
                    } else {
                        $insertStmt = $conn->prepare("INSERT INTO customer_regions (user_id, name) VALUES (?, ?)");
                        $insertStmt->bind_param("is", $userId, $name);

                        if ($insertStmt->execute()) {
                            $success = "ناوچەی '$name' بە سەرکەوتوویی زیادکرا";
                            writeLog("Customer region added: $name by user: {$currentUser['email']}");
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە زیادکردنی ناوچە';
                        }
                        $insertStmt->close();
                    }
                    $stmt->close();
                }
                break;

            case 'edit':
                enforceAuthorizationOrDeny($currentUser, 'customers.update', [
                    'route' => '/user/customers/regions.php',
                    'request_method' => 'POST'
                ], 'redirect');

                $regionId = (int)($_POST['region_id'] ?? 0);
                $name = cleanInput($_POST['name'] ?? '');

                if (empty($name)) {
                    $errors[] = 'ناوی ناوچە پێویستە';
                } else {
                    $stmt = $conn->prepare("SELECT name FROM customer_regions WHERE id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $regionId, $userId);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows === 0) {
                        $errors[] = 'ناوچە نەدۆزرایەوە یان دەسەڵاتت نییە';
                    } else {
                        $oldRegion = $result->fetch_assoc();

                        if ($name !== $oldRegion['name']) {
                            $checkStmt = $conn->prepare("SELECT id FROM customer_regions WHERE name = ? AND user_id = ? AND id != ?");
                            $checkStmt->bind_param("sii", $name, $userId, $regionId);
                            $checkStmt->execute();

                            if ($checkStmt->get_result()->num_rows > 0) {
                                $errors[] = 'ئەم ناوەی ناوچە پێشتر بەکارهاتووە';
                            }
                            $checkStmt->close();
                        }

                        if (empty($errors)) {
                            $updateStmt = $conn->prepare("UPDATE customer_regions SET name = ? WHERE id = ? AND user_id = ?");
                            $updateStmt->bind_param("sii", $name, $regionId, $userId);

                            if ($updateStmt->execute()) {
                                $success = 'ناوچە بە سەرکەوتوویی نوێکرایەوە';
                                writeLog("Customer region updated: $name (ID: $regionId) by user: {$currentUser['email']}");
                            } else {
                                $errors[] = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی ناوچە';
                            }
                            $updateStmt->close();
                        }
                    }
                    $stmt->close();
                }
                break;

            case 'delete':
                enforceAuthorizationOrDeny($currentUser, 'customers.update', [
                    'route' => '/user/customers/regions.php',
                    'request_method' => 'POST'
                ], 'redirect');

                $regionId = (int)($_POST['region_id'] ?? 0);

                if ($regionId) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM customers WHERE region_id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $regionId, $userId);
                    $stmt->execute();
                    $customerCount = (int)$stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();

                    if ($customerCount > 0) {
                        $errors[] = "ناتوانیت ئەم ناوچەیە بسڕیتەوە چونکە $customerCount کڕیاری تێدایە";
                    } else {
                        $stmt = $conn->prepare("SELECT name FROM customer_regions WHERE id = ? AND user_id = ?");
                        $stmt->bind_param("ii", $regionId, $userId);
                        $stmt->execute();
                        $regionName = $stmt->get_result()->fetch_assoc()['name'] ?? '';
                        $stmt->close();

                        $deleteStmt = $conn->prepare("DELETE FROM customer_regions WHERE id = ? AND user_id = ?");
                        $deleteStmt->bind_param("ii", $regionId, $userId);

                        if ($deleteStmt->execute()) {
                            $success = 'ناوچە بە سەرکەوتوویی سڕایەوە';
                            writeLog("Customer region deleted: $regionName (ID: $regionId) by user: {$currentUser['email']}");
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە سڕینەوەی ناوچە';
                        }
                        $deleteStmt->close();
                    }
                }
                break;
        }
    }
}

$stmt = $conn->prepare("
    SELECT cr.*, COUNT(c.id) as customer_count
    FROM customer_regions cr
    LEFT JOIN customers c ON c.region_id = cr.id AND c.user_id = cr.user_id
    WHERE cr.user_id = ?
    GROUP BY cr.id
    ORDER BY cr.name ASC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$regions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM customers WHERE user_id = ? AND (region_id IS NULL OR region_id = 0)");
$stmt->bind_param("i", $userId);
$stmt->execute();
$noRegionCount = (int)$stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>بەڕێوەبردنی ناوچەکان - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-dark.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-responsive.css'); ?>" rel="stylesheet">

    <style>
        .region-card {
            border: none;
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .region-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12) !important;
        }
        .region-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 152, 0, 0.15));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: #d97706;
        }
    </style>
</head>
<body class="customers-module-page customers-regions-page bg-light customers-page">

    <?php
    $customersNavId = 'customersRegionsNav';
    $customersNavLinks = [
        ['href' => url('user/customers/index.php'), 'icon' => 'bi-people', 'text' => 'لیستی کڕیاران'],
        ['href' => url('user/customers/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کڕیاران'],
    ];
    include __DIR__ . '/partials/customers_nav.php';
    ?>

    <div class="container-fluid py-4 customers-page-content">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-0">
                    <i class="bi bi-geo-alt text-warning"></i>
                    بەڕێوەبردنی ناوچەکان
                </h1>
                <p class="text-muted mb-0">ناوچەکانی کڕیاران ڕێکبخە — بۆ نموونە: پێنجوێن، سەیدسادق</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addRegionModal">
                    <i class="bi bi-plus-lg"></i> ناوچەی نوێ
                </button>
                <a href="<?php echo url('user/customers/index.php'); ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i>
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

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">کۆی ناوچەکان</h6>
                                <h2 class="card-title mb-0"><?php echo count($regions); ?></h2>
                            </div>
                            <div class="opacity-75">
                                <i class="bi bi-geo-alt display-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">کڕیار بە ناوچە</h6>
                                <h2 class="card-title mb-0"><?php echo array_sum(array_column($regions, 'customer_count')); ?></h2>
                            </div>
                            <div class="opacity-75">
                                <i class="bi bi-people display-4"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm bg-secondary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-subtitle mb-2 opacity-75">کڕیار بێ ناوچە</h6>
                                <h2 class="card-title mb-0"><?php echo $noRegionCount; ?></h2>
                            </div>
                            <div class="opacity-75">
                                <i class="bi bi-person-dash display-4"></i>
                            </div>
                        </div>
                        <?php if ($noRegionCount > 0): ?>
                            <div class="mt-2">
                                <a href="<?php echo url('user/customers/index.php?region=__no_region__'); ?>" class="btn btn-light btn-sm">
                                    <i class="bi bi-eye"></i> بینین
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($regions)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-geo-alt display-3 text-muted"></i>
                    <h4 class="mt-3 text-muted">هیچ ناوچەیەک دروست نەکراوە</h4>
                    <p class="text-muted mb-4">ناوچەکان یارمەتیت دەدەن کڕیارانت بەپێی شوێن ڕێکبخەیت</p>
                    <button type="button" class="btn btn-warning btn-lg" data-bs-toggle="modal" data-bs-target="#addRegionModal">
                        <i class="bi bi-plus-lg"></i> یەکەم ناوچە دروست بکە
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($regions as $region): ?>
                    <div class="col-lg-6 col-xl-4">
                        <div class="card h-100 shadow-sm region-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="region-icon">
                                            <i class="bi bi-geo-alt-fill"></i>
                                        </div>
                                        <div>
                                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($region['name']); ?></h5>
                                            <small class="text-muted">
                                                <i class="bi bi-calendar"></i>
                                                <?php echo date('Y/m/d', strtotime($region['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="<?php echo url('user/customers/index.php?region=' . $region['id']); ?>">
                                                    <i class="bi bi-people"></i> بینینی کڕیاران
                                                </a>
                                            </li>
                                            <li>
                                                <button class="dropdown-item" onclick="editRegion(<?php echo htmlspecialchars(json_encode($region)); ?>)">
                                                    <i class="bi bi-pencil"></i> دەستکاری
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button class="dropdown-item text-danger"
                                                        onclick="deleteRegion(<?php echo $region['id']; ?>, <?php echo htmlspecialchars(json_encode($region['name'])); ?>, <?php echo (int)$region['customer_count']; ?>)">
                                                    <i class="bi bi-trash"></i> سڕینەوە
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </div>

                                <div class="bg-light rounded p-3 text-center">
                                    <h3 class="text-primary mb-1"><?php echo (int)$region['customer_count']; ?></h3>
                                    <small class="text-muted">کڕیار</small>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent">
                                <a href="<?php echo url('user/customers/index.php?region=' . $region['id']); ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-people"></i> بینینی کڕیاران
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="addRegionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg text-warning"></i> زیادکردنی ناوچەی نوێ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="add_name" class="form-label"><i class="bi bi-geo-alt"></i> ناوی ناوچە *</label>
                            <input type="text" class="form-control" id="add_name" name="name"
                                   placeholder="بۆ نموونە: پێنجوێن، سەیدسادق..." required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                        <button type="submit" class="btn btn-warning">زیادکردن</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editRegionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil text-warning"></i> دەستکاری ناوچە</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="region_id" id="edit_region_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label"><i class="bi bi-geo-alt"></i> ناوی ناوچە *</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
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

    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="region_id" id="delete_region_id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($showAddForm): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new bootstrap.Modal(document.getElementById('addRegionModal')).show();
        });
        <?php endif; ?>

        function editRegion(region) {
            document.getElementById('edit_region_id').value = region.id;
            document.getElementById('edit_name').value = region.name;
            new bootstrap.Modal(document.getElementById('editRegionModal')).show();
        }

        function deleteRegion(regionId, regionName, customerCount) {
            if (customerCount > 0) {
                alert('ناوچەی "' + regionName + '" ' + customerCount + ' کڕیاری تێدایە. ناتوانیت بیسڕیتەوە.');
                return;
            }
            if (confirm('ئایا دڵنیایت لە سڕینەوەی ناوچەی "' + regionName + '"؟')) {
                document.getElementById('delete_region_id').value = regionId;
                document.getElementById('deleteForm').submit();
            }
        }

        document.getElementById('addRegionModal').addEventListener('shown.bs.modal', function() {
            document.getElementById('add_name').focus();
        });
    </script>
</body>
</html>
