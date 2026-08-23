<?php
/**
 * Drug Interactions Management - user/products/drug_interactions.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../config/kasher_platform/database.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

if ($isSubUser && empty($userPermissions['products'])) {
    setMessage('دەسەڵاتی ئەم بەشەت نییە', 'danger');
    redirect(url('user/products/main.php'));
}

$isPharmacyMode = false;
$settingsStmt = $conn->prepare("
    SELECT s.business_type_id, bt.code AS business_type_code
    FROM settings s
    LEFT JOIN business_types bt ON bt.id = s.business_type_id
    WHERE s.user_id = ?
    LIMIT 1
");
if ($settingsStmt) {
    $settingsStmt->bind_param("i", $userId);
    $settingsStmt->execute();
    $settingsRow = $settingsStmt->get_result()->fetch_assoc();
    $settingsStmt->close();
    if ($settingsRow) {
        $businessTypeId = (int)($settingsRow['business_type_id'] ?? 0);
        $businessTypeCode = trim((string)($settingsRow['business_type_code'] ?? ''));
        if (
            in_array($businessTypeId, [1, 3], true) ||
            in_array($businessTypeCode, ['pharmacy', 'pharmacy_and_medical_center'], true)
        ) {
            $isPharmacyMode = true;
        }
    }
}

if (!$isPharmacyMode) {
    setMessage('ئەم بەشە تەنها بۆ دەرمانخانە و سەنتەری پزیشکی + دەرمانخانەیە', 'warning');
    redirect(url('user/products/main.php'));
}

requireDrugInteractionsAccess();

if (!($conn_kasher_platform instanceof mysqli)) {
    setMessage('داتابەیسی platform بەردەست نییە. تکایە لەگەڵ بەڕێوەبەر پەیوەندی بکە.', 'danger');
    redirect(url('user/products/main.php'));
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        if (in_array($action, ['add', 'edit'], true)) {
            $interactionId = (int)($_POST['interaction_id'] ?? 0);
            $productId1 = (int)($_POST['product_id_1'] ?? 0);
            $productId2 = (int)($_POST['product_id_2'] ?? 0);
            $riskLevel = trim((string)($_POST['risk_level'] ?? 'medium'));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($productId1 <= 0 || $productId2 <= 0) {
                $errors[] = 'دیاریکردنی هەردوو دەرمان پێویستە';
            }
            if ($productId1 === $productId2) {
                $errors[] = 'دوو دەرمان نابێت یەکسان بن';
            }
            if (!in_array($riskLevel, ['low', 'medium', 'high'], true)) {
                $errors[] = 'ئاستی مەترسی نادروستە';
            }

            [$normalizedA, $normalizedB] = normalizePair($productId1, $productId2);

            if (!validateProductOwnership($conn, $userId, $normalizedA) || !validateProductOwnership($conn, $userId, $normalizedB)) {
                $errors[] = 'یەکێک لە دەرمانەکان بۆ ئەم ئەکاونتە نییە';
            }

            if (empty($errors)) {
                if (hasDuplicatePair($conn_kasher_platform, $userId, $normalizedA, $normalizedB, $action === 'edit' ? $interactionId : 0)) {
                    $errors[] = 'ئەم دوو دەرمانە پێشتر تۆمارکراون';
                } else {
                    if ($action === 'add') {
                        $stmt = $conn_kasher_platform->prepare("
                            INSERT INTO drug_interactions
                            (user_id, product_id_1, product_id_2, risk_level, note, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        $stmt->bind_param('iiiss', $userId, $normalizedA, $normalizedB, $riskLevel, $note);
                        if ($stmt->execute()) {
                            $success = 'دەرمانی نەگونجاو بە سەرکەوتوویی زیادکرا';
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە زیادکردن';
                        }
                        $stmt->close();
                    } else {
                        $stmt = $conn_kasher_platform->prepare("
                            UPDATE drug_interactions
                            SET product_id_1 = ?, product_id_2 = ?, risk_level = ?, note = ?, updated_at = NOW()
                            WHERE id = ? AND user_id = ?
                        ");
                        $stmt->bind_param('iissii', $normalizedA, $normalizedB, $riskLevel, $note, $interactionId, $userId);
                        if ($stmt->execute()) {
                            $success = 'زانیاری نەگونجاوی دەرمان نوێکرایەوە';
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە نوێکردنەوە';
                        }
                        $stmt->close();
                    }
                }
            }
        } elseif ($action === 'delete') {
            $interactionId = (int)($_POST['interaction_id'] ?? 0);
            if ($interactionId <= 0) {
                $errors[] = 'ناسنامەی نەگونجاوی دەرمان نادروستە';
            } else {
                $stmt = $conn_kasher_platform->prepare("DELETE FROM drug_interactions WHERE id = ? AND user_id = ?");
                $stmt->bind_param('ii', $interactionId, $userId);
                if ($stmt->execute()) {
                    $success = 'دەرمانی نەگونجاو بە سەرکەوتوویی سڕایەوە';
                } else {
                    $errors[] = 'هەڵەیەک ڕوویدا لە سڕینەوە';
                }
                $stmt->close();
            }
        }
    }
}

$interactionsStmt = $conn_kasher_platform->prepare("
    SELECT di.id,
           di.product_id_1,
           di.product_id_2,
           di.risk_level,
           COALESCE(di.note, '') AS note,
           di.updated_at
    FROM drug_interactions di
    WHERE di.user_id = ?
    ORDER BY di.updated_at DESC, di.id DESC
");
$interactionsStmt->bind_param('i', $userId);
$interactionsStmt->execute();
$interactions = $interactionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$interactionsStmt->close();

if (!empty($interactions)) {
    $productIds = [];
    foreach ($interactions as $interaction) {
        $productIds[(int)$interaction['product_id_1']] = true;
        $productIds[(int)$interaction['product_id_2']] = true;
    }

    $productMeta = fetchProductMetaByIds($conn, $userId, array_keys($productIds));
    foreach ($interactions as &$interaction) {
        $p1 = (int)$interaction['product_id_1'];
        $p2 = (int)$interaction['product_id_2'];
        $interaction['drug_name_1'] = $productMeta[$p1]['name'] ?? 'Unknown';
        $interaction['barcode_1'] = $productMeta[$p1]['barcode'] ?? '';
        $interaction['drug_name_2'] = $productMeta[$p2]['name'] ?? 'Unknown';
        $interaction['barcode_2'] = $productMeta[$p2]['barcode'] ?? '';
    }
    unset($interaction);
}

$csrf_token = Security::generateCSRFToken();

function normalizePair(int $first, int $second): array {
    if ($first <= $second) {
        return [$first, $second];
    }
    return [$second, $first];
}

function validateProductOwnership(mysqli $conn, int $userId, int $productId): bool {
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $productId, $userId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function hasDuplicatePair(mysqli $connPlatform, int $userId, int $productId1, int $productId2, int $excludeId = 0): bool {
    if ($excludeId > 0) {
        $stmt = $connPlatform->prepare("
            SELECT id FROM drug_interactions
            WHERE user_id = ? AND product_id_1 = ? AND product_id_2 = ? AND id != ?
            LIMIT 1
        ");
        $stmt->bind_param('iiii', $userId, $productId1, $productId2, $excludeId);
    } else {
        $stmt = $connPlatform->prepare("
            SELECT id FROM drug_interactions
            WHERE user_id = ? AND product_id_1 = ? AND product_id_2 = ?
            LIMIT 1
        ");
        $stmt->bind_param('iii', $userId, $productId1, $productId2);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function fetchProductMetaByIds(mysqli $conn, int $userId, array $productIds): array {
    $cleanIds = [];
    foreach ($productIds as $id) {
        $value = (int)$id;
        if ($value > 0) {
            $cleanIds[$value] = $value;
        }
    }

    if (empty($cleanIds)) {
        return [];
    }

    $cleanIds = array_values($cleanIds);
    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    $types = 'i' . str_repeat('i', count($cleanIds));
    $sql = "SELECT id, name, COALESCE(barcode, '') AS barcode FROM products WHERE user_id = ? AND id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $params = array_merge([$userId], $cleanIds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $result = [];
    foreach ($rows as $row) {
        $result[(int)$row['id']] = [
            'name' => $row['name'],
            'barcode' => $row['barcode']
        ];
    }
    return $result;
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>دەرمانی نەگونجاو - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    <style>
        .search-results {
            position: absolute;
            z-index: 1050;
            inset-inline: 0;
            max-height: 220px;
            overflow-y: auto;
            border-radius: 0.75rem;
            margin-top: 0.35rem;
            display: none;
        }
        .search-result-item { cursor: pointer; }
        .risk-low { background-color: rgba(25, 135, 84, 0.2); }
        .risk-medium { background-color: rgba(255, 193, 7, 0.25); }
        .risk-high { background-color: rgba(220, 53, 69, 0.25); }
    </style>
</head>
<body class="products-module-page products-drug-page bg-body-secondary">
    <?php
    $productsNavId = 'productsDrugNav';
    $productsNavLinks = [
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <div class="container py-4 products-page-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 mb-1"><i class="bi bi-shield-exclamation text-danger"></i> بەڕێوەبردنی دەرمانی نەگونجاو</h1>
                <p class="text-muted mb-0">دیاریکردنی دەرمانە نەگونجاوەکان بە ئاستی مەترسی و تێبینی</p>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body-tertiary">
                <strong>زیادکردنی دوو دەرمانی نەگونجاو</strong>
            </div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="row g-3">
                        <div class="col-md-6 position-relative">
                            <label class="form-label">دەرمانی یەکەم</label>
                            <input type="text" class="form-control product-search-input" data-target-id="product_id_1" placeholder="گەڕان بە ناو یان بارکۆد">
                            <input type="hidden" name="product_id_1" id="product_id_1">
                            <div class="search-results list-group shadow-sm bg-body"></div>
                        </div>
                        <div class="col-md-6 position-relative">
                            <label class="form-label">دەرمانی دووەم</label>
                            <input type="text" class="form-control product-search-input" data-target-id="product_id_2" placeholder="گەڕان بە ناو یان بارکۆد">
                            <input type="hidden" name="product_id_2" id="product_id_2">
                            <div class="search-results list-group shadow-sm bg-body"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ئاستی مەترسی</label>
                            <select class="form-select" name="risk_level" required>
                                <option value="low">کەم</option>
                                <option value="medium" selected>ناوەند</option>
                                <option value="high">زۆر</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">تێبینی</label>
                            <textarea class="form-control" name="note" rows="2" placeholder="تێبینی دەربارەی مەترسییەکە"></textarea>
                        </div>
                    </div>
                    <div class="mt-3 text-end">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-plus-circle"></i> زیادکردن
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-body-tertiary">
                <strong>لیستی دەرمانە نەگونجاوەکان</strong>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 products-drug-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>دەرمانی یەکەم</th>
                            <th>دەرمانی دووەم</th>
                            <th>مەترسی</th>
                            <th>تێبینی</th>
                            <th>کردارەکان</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($interactions)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">هێشتا هیچ دەرمانێکی نەگونجاو تۆمار نەکراوە</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($interactions as $index => $row): ?>
                            <?php
                            $riskClass = 'risk-medium';
                            $riskText = 'ناوەند';
                            if ($row['risk_level'] === 'low') {
                                $riskClass = 'risk-low';
                                $riskText = 'کەم';
                            } elseif ($row['risk_level'] === 'high') {
                                $riskClass = 'risk-high';
                                $riskText = 'زۆر';
                            }
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['drug_name_1'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['barcode_1'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['drug_name_2'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['barcode_2'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><span class="badge text-dark <?php echo $riskClass; ?>"><?php echo $riskText; ?></span></td>
                                <td><?php echo htmlspecialchars($row['note'] !== '' ? $row['note'] : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-nowrap">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary edit-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editModal"
                                        data-id="<?php echo (int)$row['id']; ?>"
                                        data-product1-id="<?php echo (int)$row['product_id_1']; ?>"
                                        data-product2-id="<?php echo (int)$row['product_id_2']; ?>"
                                        data-product1-name="<?php echo htmlspecialchars($row['drug_name_1'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-product2-name="<?php echo htmlspecialchars($row['drug_name_2'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-risk="<?php echo htmlspecialchars($row['risk_level'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-note="<?php echo htmlspecialchars($row['note'], ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="interaction_id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('دڵنیایت لە سڕینەوە؟')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" autocomplete="off">
                    <div class="modal-header">
                        <h5 class="modal-title">دەستکاریکردنی دەرمانی نەگونجاو</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="interaction_id" id="edit_interaction_id">
                        <div class="mb-3 position-relative">
                            <label class="form-label">دەرمانی یەکەم</label>
                            <input type="text" class="form-control product-search-input" data-target-id="edit_product_id_1" id="edit_product_name_1">
                            <input type="hidden" name="product_id_1" id="edit_product_id_1">
                            <div class="search-results list-group shadow-sm bg-body"></div>
                        </div>
                        <div class="mb-3 position-relative">
                            <label class="form-label">دەرمانی دووەم</label>
                            <input type="text" class="form-control product-search-input" data-target-id="edit_product_id_2" id="edit_product_name_2">
                            <input type="hidden" name="product_id_2" id="edit_product_id_2">
                            <div class="search-results list-group shadow-sm bg-body"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ئاستی مەترسی</label>
                            <select class="form-select" name="risk_level" id="edit_risk_level" required>
                                <option value="low">کەم</option>
                                <option value="medium">ناوەند</option>
                                <option value="high">زۆر</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">تێبینی</label>
                            <textarea class="form-control" name="note" id="edit_note" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                        <button type="submit" class="btn btn-primary">نوێکردنەوە</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const searchEndpoint = '<?php echo url('user/products/api/drug_interactions.php?action=search_products'); ?>';

        function initSearchInputs() {
            document.querySelectorAll('.product-search-input').forEach(function(input) {
                const wrapper = input.closest('.position-relative');
                const hiddenId = document.getElementById(input.dataset.targetId);
                const results = wrapper ? wrapper.querySelector('.search-results') : null;
                let timer = null;

                if (!results || !hiddenId) {
                    return;
                }

                input.addEventListener('input', function() {
                    hiddenId.value = '';
                    const query = input.value.trim();
                    if (query.length < 2) {
                        results.style.display = 'none';
                        results.innerHTML = '';
                        return;
                    }
                    clearTimeout(timer);
                    timer = setTimeout(async function() {
                        try {
                            const response = await fetch(searchEndpoint + '&q=' + encodeURIComponent(query), { credentials: 'same-origin' });
                            const payload = await response.json();
                            if (!payload.success || !Array.isArray(payload.data) || payload.data.length === 0) {
                                results.innerHTML = '<div class="list-group-item text-muted">هیچ نەدۆزرایەوە</div>';
                                results.style.display = 'block';
                                return;
                            }

                            results.innerHTML = payload.data.map(function(item) {
                                const barcode = item.barcode ? ('<small class="text-muted">' + item.barcode + '</small>') : '<small class="text-muted">-</small>';
                                return '<button type="button" class="list-group-item list-group-item-action search-result-item" data-id="' + item.id + '" data-name="' + escapeHtml(item.name) + '">' +
                                    '<div class="d-flex justify-content-between"><span>' + escapeHtml(item.name) + '</span>' + barcode + '</div>' +
                                    '</button>';
                            }).join('');
                            results.style.display = 'block';

                            results.querySelectorAll('.search-result-item').forEach(function(btn) {
                                btn.addEventListener('click', function() {
                                    hiddenId.value = btn.dataset.id;
                                    input.value = btn.dataset.name;
                                    results.style.display = 'none';
                                });
                            });
                        } catch (error) {
                            results.innerHTML = '<div class="list-group-item text-danger">هەڵە لە گەڕان</div>';
                            results.style.display = 'block';
                        }
                    }, 220);
                });

                input.addEventListener('blur', function() {
                    setTimeout(function() {
                        results.style.display = 'none';
                    }, 180);
                });
            });
        }

        function escapeHtml(value) {
            return String(value || '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        }

        document.querySelectorAll('.edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('edit_interaction_id').value = btn.dataset.id;
                document.getElementById('edit_product_id_1').value = btn.dataset.product1Id;
                document.getElementById('edit_product_id_2').value = btn.dataset.product2Id;
                document.getElementById('edit_product_name_1').value = btn.dataset.product1Name;
                document.getElementById('edit_product_name_2').value = btn.dataset.product2Name;
                document.getElementById('edit_risk_level').value = btn.dataset.risk;
                document.getElementById('edit_note').value = btn.dataset.note;
            });
        });

        initSearchInputs();
    </script>
</body>
</html>
