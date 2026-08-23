<?php
/**
 * بەڕێوەبردنی خانە زیادەکانی کاڵا
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once 'includes/custom_fields_helpers.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'products.view', [
    'route' => '/user/products/custom_fields.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');

$userId = (int)$currentUser['id'];
$errors = [];
$fieldTypes = getProductCustomFieldTypes();
$sectionsAvailable = productCustomFieldSectionsAvailable($conn);
$optionsAvailable = productCustomFieldOptionsAvailable($conn);
$sections = $sectionsAvailable ? getProductCustomFieldSections($conn, $userId, true) : [];

$editFieldId = isset($_GET['field_id']) ? (int)$_GET['field_id'] : 0;
$editField = $editFieldId > 0 ? getProductCustomFieldById($conn, $userId, $editFieldId, false) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_section' && $sectionsAvailable) {
            $sectionName = trim((string)($_POST['section_name'] ?? ''));
            [$ok, $result] = addProductCustomFieldSection($conn, $userId, $sectionName);
            if ($ok) {
                setMessage('بەشەکە بە سەرکەوتوویی زیادکرا', 'success');
                redirect(url('user/products/custom_fields.php'));
            }
            $errors[] = is_string($result) ? $result : 'نەتوانرا بەش زیاد بکرێت';
        } elseif ($action === 'add_field') {
            $fieldName = trim((string)($_POST['field_name'] ?? ''));
            $fieldType = trim((string)($_POST['field_type'] ?? 'text'));
            $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
            $allowedTypes = array_keys($fieldTypes);

            if ($fieldName === '') {
                $errors[] = 'ناوی خانە پێویستە';
            }
            if (!in_array($fieldType, $allowedTypes, true)) {
                $errors[] = 'جۆری خانە نادروستە';
            }

            if (empty($errors)) {
                $fieldKeyBase = buildProductCustomFieldKey($fieldName);
                $fieldKey = $fieldKeyBase;
                $suffix = 2;

                while (true) {
                    $existsStmt = $conn->prepare("SELECT id FROM product_custom_fields WHERE user_id = ? AND field_key = ? LIMIT 1");
                    $existsStmt->bind_param("is", $userId, $fieldKey);
                    $existsStmt->execute();
                    $exists = $existsStmt->get_result()->fetch_assoc();
                    $existsStmt->close();
                    if (!$exists) {
                        break;
                    }
                    $fieldKey = substr($fieldKeyBase, 0, 145) . '_' . $suffix;
                    $suffix++;
                }

                $orderStmt = $conn->prepare("SELECT COALESCE(MAX(field_order), 0) + 1 AS next_order FROM product_custom_fields WHERE user_id = ?");
                $orderStmt->bind_param("i", $userId);
                $orderStmt->execute();
                $nextOrder = (int)($orderStmt->get_result()->fetch_assoc()['next_order'] ?? 1);
                $orderStmt->close();

                $sectionParam = ($sectionsAvailable && $sectionId > 0) ? $sectionId : null;

                if ($sectionsAvailable) {
                    $insertStmt = $conn->prepare("
                        INSERT INTO product_custom_fields (user_id, field_name, field_key, field_type, section_id, field_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                    ");
                    $insertStmt->bind_param("isssii", $userId, $fieldName, $fieldKey, $fieldType, $sectionParam, $nextOrder);
                } else {
                    $insertStmt = $conn->prepare("
                        INSERT INTO product_custom_fields (user_id, field_name, field_key, field_type, field_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                    ");
                    $insertStmt->bind_param("isssi", $userId, $fieldName, $fieldKey, $fieldType, $nextOrder);
                }

                if ($insertStmt->execute()) {
                    $newFieldId = (int)$insertStmt->insert_id;
                    $insertStmt->close();
                    setMessage('خانە زیادەکە بە سەرکەوتوویی زیادکرا', 'success');
                    if ($fieldType === 'select' && $optionsAvailable) {
                        redirect(url('user/products/custom_fields.php?field_id=' . $newFieldId));
                    }
                    redirect(url('user/products/custom_fields.php'));
                }
                $insertStmt->close();
                $errors[] = 'هەڵەیەک ڕوویدا لە زیادکردنی خانەکە';
            }
        } elseif ($action === 'update_field') {
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $fieldName = trim((string)($_POST['field_name'] ?? ''));
            $fieldType = trim((string)($_POST['field_type'] ?? 'text'));
            $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
            $sectionParam = ($sectionsAvailable && $sectionId > 0) ? $sectionId : null;

            [$ok, $msg] = updateProductCustomField($conn, $userId, $fieldId, $fieldName, $fieldType, $sectionParam);
            if ($ok) {
                setMessage('خانەکە نوێکرایەوە', 'success');
                redirect(url('user/products/custom_fields.php?field_id=' . $fieldId));
            }
            $errors[] = $msg ?: 'نەتوانرا خانەکە نوێ بکرێتەوە';
        } elseif ($action === 'delete_field') {
            $fieldId = (int)($_POST['field_id'] ?? 0);
            if ($fieldId <= 0) {
                $errors[] = 'خانە دۆزرایەوە';
            } else {
                $stmt = $conn->prepare("UPDATE product_custom_fields SET is_active = 0, updated_at = NOW() WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $fieldId, $userId);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    setMessage('خانەکە سڕایەوە', 'success');
                    $stmt->close();
                    redirect(url('user/products/custom_fields.php'));
                }
                $stmt->close();
                $errors[] = 'خانەکە نەدۆزرایەوە یان نەتوانرا بسڕدرێتەوە';
            }
        } elseif ($action === 'add_option' && $optionsAvailable) {
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $parentId = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
            $optionLabel = trim((string)($_POST['option_label'] ?? ''));
            [$ok, $result] = addProductCustomFieldOption($conn, $userId, $fieldId, $optionLabel, $parentId);
            if ($ok) {
                setMessage('بژاردەکە زیادکرا', 'success');
                redirect(url('user/products/custom_fields.php?field_id=' . $fieldId));
            }
            $errors[] = is_string($result) ? $result : 'نەتوانرا بژاردە زیاد بکرێت';
        } elseif ($action === 'rename_option' && $optionsAvailable) {
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $optionId = (int)($_POST['option_id'] ?? 0);
            $optionLabel = trim((string)($_POST['option_label'] ?? ''));
            [$ok, $msg] = renameProductCustomFieldOption($conn, $userId, $optionId, $optionLabel);
            if ($ok) {
                setMessage('ناوی بژاردە نوێکرایەوە', 'success');
                redirect(url('user/products/custom_fields.php?field_id=' . $fieldId));
            }
            $errors[] = $msg ?: 'نەتوانرا ناو نوێ بکرێتەوە';
        } elseif ($action === 'delete_option' && $optionsAvailable) {
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $optionId = (int)($_POST['option_id'] ?? 0);
            if (deactivateProductCustomFieldOptionSubtree($conn, $userId, $optionId)) {
                setMessage('بژاردەکە سڕایەوە', 'success');
                redirect(url('user/products/custom_fields.php?field_id=' . $fieldId));
            }
            $errors[] = 'نەتوانرا بژاردە بسڕدرێتەوە';
        } elseif ($action === 'reorder_option' && $optionsAvailable) {
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $optionId = (int)($_POST['option_id'] ?? 0);
            $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
            [$ok, $msg] = reorderProductCustomFieldOption($conn, $userId, $optionId, $direction);
            if ($ok) {
                redirect(url('user/products/custom_fields.php?field_id=' . $fieldId));
            }
            $errors[] = $msg ?: 'نەتوانرا ڕیز بگۆڕدرێت';
        }
    }
}

$fields = getProductCustomFields($conn, $userId, false);
$grouped = groupProductCustomFieldsBySection($fields);
$csrf_token = Security::generateCSRFToken();

$totalFieldsCount = count($fields);
$activeFieldsCount = count(array_filter($fields, function ($f) {
    return (int)($f['is_active'] ?? 0) === 1;
}));
$sectionsCount = count($grouped['sections']);
$selectFieldsCount = count(array_filter($fields, function ($f) {
    return ($f['field_type'] ?? '') === 'select' && (int)($f['is_active'] ?? 0) === 1;
}));

$optionsTree = [];
$optionsFlat = [];
if ($editField && ($editField['field_type'] ?? '') === 'select' && $optionsAvailable) {
    $optionsFlat = getProductCustomFieldOptionsFlat($conn, $userId, $editFieldId, false);
    $optionsTree = buildProductCustomFieldOptionsTree(
        array_filter($optionsFlat, function ($row) {
            return (int)$row['is_active'] === 1;
        }),
        null
    );
}

function renderFieldTableRow(array $field, array $fieldTypes, $csrfToken)
{
    $fieldType = $field['field_type'] ?? 'text';
    $typeMeta = getProductCustomFieldTypeMeta($fieldType);
    $typeLabel = $fieldTypes[$fieldType] ?? $fieldType;
    ob_start();
    ?>
    <tr>
        <td>
            <span class="fw-semibold">
                <i class="bi bi-input-cursor-text text-primary me-1"></i>
                <?php echo htmlspecialchars($field['field_name'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </td>
        <td>
            <span class="badge rounded-pill cf-type-badge <?php echo htmlspecialchars($typeMeta['badge_class'], ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi <?php echo htmlspecialchars($typeMeta['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </td>
        <td>
            <?php if ((int)$field['is_active'] === 1): ?>
                <span class="badge bg-success">چالاک</span>
            <?php else: ?>
                <span class="badge bg-secondary">ناچالاک</span>
            <?php endif; ?>
        </td>
        <td class="text-center">
            <?php if ((int)$field['is_active'] === 1): ?>
                <div class="cf-actions">
                    <a href="<?php echo url('user/products/custom_fields.php?field_id=' . (int)$field['id']); ?>"
                       class="btn btn-sm btn-outline-primary" title="دەستکاری">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('ئایا دڵنیایت لە سڕینەوەی خانەکە؟');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete_field">
                        <input type="hidden" name="field_id" value="<?php echo (int)$field['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="سڕینەوە">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function renderFieldsTableRows(array $fields, array $fieldTypes, $csrfToken)
{
    if (empty($fields)) {
        return '<p class="text-muted p-3 mb-0">هیچ خانەیەک نییە</p>';
    }
    ob_start();
    ?>
    <table class="table table-hover cf-table align-middle mb-0">
        <thead class="cf-table-head">
            <tr>
                <th>ناوی خانە</th>
                <th>جۆر</th>
                <th>دۆخ</th>
                <th class="text-center">کردار</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fields as $field): ?>
                <?php echo renderFieldTableRow($field, $fieldTypes, $csrfToken); ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

/**
 * Render options tree for admin (recursive).
 */
function renderCustomFieldOptionsAdminTree(array $nodes, $fieldId, $csrfToken, $depth = 0)
{
    if (empty($nodes)) {
        return;
    }
    $wrapperClass = $depth === 0 ? 'cf-options-tree' : 'cf-option-node__children';
    echo '<div class="' . $wrapperClass . '">';
    foreach ($nodes as $node) {
        $optionId = (int)$node['id'];
        $label = htmlspecialchars($node['label'], ENT_QUOTES, 'UTF-8');
        $collapseId = 'cf-opt-forms-' . $optionId;
        echo '<div class="cf-option-node" data-depth="' . (int)$depth . '">';
        echo '<div class="cf-option-node__toolbar">';
        echo '<div class="cf-option-node__label-wrap">';
        echo '<span class="cf-option-node__label"><i class="bi bi-tag text-primary"></i> ' . $label . '</span>';
        if ($depth > 0) {
            echo '<span class="badge rounded-pill cf-depth-badge bg-secondary-subtle text-secondary border">ئاست ' . ((int)$depth + 1) . '</span>';
        }
        echo '</div>';
        echo '<div class="cf-actions">';
        echo '<div class="btn-group btn-group-sm" role="group" aria-label="ڕیزکردن">';
        echo '<form method="POST" class="d-inline">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="action" value="reorder_option">';
        echo '<input type="hidden" name="field_id" value="' . $fieldId . '">';
        echo '<input type="hidden" name="option_id" value="' . $optionId . '">';
        echo '<input type="hidden" name="direction" value="up">';
        echo '<button type="submit" class="btn btn-outline-secondary" title="سەرەوە"><i class="bi bi-arrow-up"></i></button>';
        echo '</form>';
        echo '<form method="POST" class="d-inline">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="action" value="reorder_option">';
        echo '<input type="hidden" name="field_id" value="' . $fieldId . '">';
        echo '<input type="hidden" name="option_id" value="' . $optionId . '">';
        echo '<input type="hidden" name="direction" value="down">';
        echo '<button type="submit" class="btn btn-outline-secondary" title="خوارەوە"><i class="bi bi-arrow-down"></i></button>';
        echo '</form>';
        echo '</div>';
        echo '<form method="POST" class="d-inline" onsubmit="return confirm(\'ئایا دڵنیایت؟\');">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="action" value="delete_option">';
        echo '<input type="hidden" name="field_id" value="' . $fieldId . '">';
        echo '<input type="hidden" name="option_id" value="' . $optionId . '">';
        echo '<button type="submit" class="btn btn-sm btn-outline-danger" title="سڕینەوە"><i class="bi bi-trash"></i></button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<button class="btn btn-sm btn-outline-secondary cf-option-node__toggle" type="button" data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '" aria-expanded="false" aria-controls="' . $collapseId . '">';
        echo '<i class="bi bi-sliders"></i> وردەکاری';
        echo '</button>';

        echo '<div class="collapse" id="' . $collapseId . '">';
        echo '<div class="cf-option-node__forms mt-2">';
        echo '<form method="POST" class="mb-2">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="action" value="rename_option">';
        echo '<input type="hidden" name="field_id" value="' . $fieldId . '">';
        echo '<input type="hidden" name="option_id" value="' . $optionId . '">';
        echo '<label class="form-label small text-muted mb-1">گۆڕینی ناو</label>';
        echo '<div class="input-group input-group-sm">';
        echo '<input type="text" name="option_label" class="form-control" value="' . $label . '" maxlength="120" required>';
        echo '<button type="submit" class="btn btn-outline-primary">پاشەکەوت</button>';
        echo '</div>';
        echo '</form>';

        echo '<form method="POST">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="action" value="add_option">';
        echo '<input type="hidden" name="field_id" value="' . $fieldId . '">';
        echo '<input type="hidden" name="parent_id" value="' . $optionId . '">';
        echo '<label class="form-label small text-muted mb-1">بژاردەی لاوەکی</label>';
        echo '<div class="input-group input-group-sm">';
        echo '<input type="text" name="option_label" class="form-control" placeholder="ناوی لاوەکی..." maxlength="120" required>';
        echo '<button type="submit" class="btn btn-success"><i class="bi bi-plus"></i> زیادکردن</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        if (!empty($node['children'])) {
            renderCustomFieldOptionsAdminTree($node['children'], $fieldId, $csrfToken, $depth + 1);
        }
        echo '</div>';
    }
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>دووگمە زیادەکانی کاڵا - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo url('user/products/assets/custom_fields_admin.css'); ?>" rel="stylesheet">
</head>
<body class="products-module-page products-custom-fields-page bg-body-secondary">
    <?php
    $productsNavId = 'productsCustomFieldsNav';
    $productsNavLinks = [
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
        ['href' => url('user/products/index.php'), 'icon' => 'bi-box-seam', 'text' => 'لیستی کاڵاکان'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <div class="container py-4 products-page-content">
        <div class="cf-page-header">
            <nav class="cf-breadcrumb-bar" aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo url('user/products/main.php'); ?>">کاڵاکان</a></li>
                    <?php if ($editField && (int)$editField['is_active'] === 1): ?>
                        <li class="breadcrumb-item"><a href="<?php echo url('user/products/custom_fields.php'); ?>">دووگمە زیادەکان</a></li>
                        <li class="breadcrumb-item active" aria-current="page">دەستکاری: <?php echo htmlspecialchars($editField['field_name'], ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page">دووگمە زیادەکان</li>
                    <?php endif; ?>
                </ol>
            </nav>
            <h1 class="mt-2"><i class="bi bi-ui-checks-grid text-primary"></i> دووگمە زیادەکانی کاڵا</h1>
            <p>بەڕێوەبردنی خانە زیادەکان، بەشەکان، و بژاردەکانی هەڵبژاردن بۆ فۆرمی کاڵا</p>
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

        <?php $message = getMessage(); ?>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message['type'], ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($message['message'], ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($editField && (int)$editField['is_active'] === 1): ?>
            <div class="row g-4">
                <div class="col-12">
                    <div class="cf-panel cf-panel--edit">
                        <div class="cf-panel__head d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5><i class="bi bi-pencil-square text-primary"></i> دەستکاری: <?php echo htmlspecialchars($editField['field_name'], ENT_QUOTES, 'UTF-8'); ?></h5>
                            <a href="<?php echo url('user/products/custom_fields.php'); ?>" class="btn btn-sm btn-outline-primary">گەڕانەوە بۆ لیست</a>
                        </div>
                        <div class="cf-panel__body">
                            <div class="cf-edit-meta">
                                <form method="POST" class="row g-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="update_field">
                                    <input type="hidden" name="field_id" value="<?php echo (int)$editField['id']; ?>">
                                    <div class="col-md-4">
                                        <label class="form-label">ناوی خانە</label>
                                        <input type="text" name="field_name" class="form-control" required maxlength="120"
                                               value="<?php echo htmlspecialchars($editField['field_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">جۆری خانە</label>
                                        <select name="field_type" class="form-select">
                                            <?php foreach ($fieldTypes as $typeKey => $typeLabel): ?>
                                                <option value="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>"
                                                    <?php echo ($editField['field_type'] ?? '') === $typeKey ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php if ($sectionsAvailable): ?>
                                    <div class="col-md-3">
                                        <label class="form-label">بەش</label>
                                        <select name="section_id" class="form-select">
                                            <option value="0">— بەبێ بەش —</option>
                                            <?php foreach ($sections as $sec): ?>
                                                <option value="<?php echo (int)$sec['id']; ?>"
                                                    <?php echo (int)($editField['section_id'] ?? 0) === (int)$sec['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($sec['section_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save"></i> پاشەکەوت</button>
                                    </div>
                                </form>
                            </div>

                            <?php if (($editField['field_type'] ?? '') === 'select' && $optionsAvailable): ?>
                                <div class="cf-panel-options">
                                    <h6 class="cf-panel-options__title"><i class="bi bi-diagram-3 text-primary"></i> بژاردەکانی هەڵبژاردن</h6>

                                    <div class="cf-add-option-bar">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="add_option">
                                            <input type="hidden" name="field_id" value="<?php echo (int)$editField['id']; ?>">
                                            <label class="form-label small text-muted mb-1">بژاردەی سەرەکی</label>
                                            <div class="input-group">
                                                <input type="text" name="option_label" class="form-control" placeholder="نموونە: کۆمپانیای یەکەم" maxlength="120" required>
                                                <button type="submit" class="btn btn-success"><i class="bi bi-plus-circle"></i> زیادکردن</button>
                                            </div>
                                        </form>
                                    </div>

                                    <?php if (empty($optionsTree)): ?>
                                        <p class="text-muted mb-0">هێشتا هیچ بژاردەیەک نییە. یەکەم بژاردەی سەرەکی زیاد بکە.</p>
                                    <?php else: ?>
                                        <?php renderCustomFieldOptionsAdminTree($optionsTree, (int)$editField['id'], $csrf_token); ?>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (($editField['field_type'] ?? '') === 'select'): ?>
                                <div class="alert alert-warning mb-0">تایبەتمەندی بژاردەکان چالاک نییە. migration جێبەجێ بکە.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="cf-stat-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="cf-stat-card__label">کۆی خانەکان</div>
                            <div class="cf-stat-card__value"><?php echo $totalFieldsCount; ?></div>
                        </div>
                        <i class="bi bi-ui-checks-grid cf-stat-card__icon"></i>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="cf-stat-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="cf-stat-card__label">چالاک</div>
                            <div class="cf-stat-card__value"><?php echo $activeFieldsCount; ?></div>
                        </div>
                        <i class="bi bi-check-circle cf-stat-card__icon"></i>
                    </div>
                </div>
                <?php if ($sectionsAvailable): ?>
                <div class="col-6 col-md-3">
                    <div class="cf-stat-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="cf-stat-card__label">بەشەکان</div>
                            <div class="cf-stat-card__value"><?php echo $sectionsCount; ?></div>
                        </div>
                        <i class="bi bi-folder2 cf-stat-card__icon"></i>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-3">
                    <div class="cf-stat-card d-flex justify-content-between align-items-center">
                        <div>
                            <div class="cf-stat-card__label">هەڵبژاردن</div>
                            <div class="cf-stat-card__value"><?php echo $selectFieldsCount; ?></div>
                        </div>
                        <i class="bi bi-list-ul cf-stat-card__icon"></i>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="cf-panel cf-panel-list">
                        <div class="cf-panel__head">
                            <h5><i class="bi bi-list-check text-primary"></i> خانەکانی تۆمارکراو</h5>
                        </div>
                        <div class="cf-panel__body">
                            <?php if (empty($fields)): ?>
                                <div class="cf-empty-state">
                                    <i class="bi bi-ui-checks-grid display-4 d-block"></i>
                                    <p class="mt-3 mb-3">هێشتا هیچ خانەیەکی زیادت تۆمار نەکردووە</p>
                                    <a href="#cf-add-field-panel" class="btn btn-success">
                                        <i class="bi bi-plus-circle"></i> یەکەم خانە زیاد بکە
                                    </a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($grouped['sections'] as $sectionGroup): ?>
                                    <?php $sectionFieldCount = count($sectionGroup['fields']); ?>
                                    <div class="cf-section-card">
                                        <div class="cf-section-card__head">
                                            <strong><i class="bi bi-folder2-open text-primary"></i> <?php echo htmlspecialchars($sectionGroup['section_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle"><?php echo $sectionFieldCount; ?> خانە</span>
                                        </div>
                                        <div class="cf-section-card__body">
                                            <?php echo renderFieldsTableRows($sectionGroup['fields'], $fieldTypes, $csrf_token); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <?php if (!empty($grouped['ungrouped'])): ?>
                                    <div class="cf-section-card cf-section-card--ungrouped<?php echo !empty($grouped['sections']) ? ' mt-3' : ''; ?>">
                                        <div class="cf-section-card__head">
                                            <strong><i class="bi bi-grid text-secondary"></i> خانەکانی تر</strong>
                                            <span class="badge rounded-pill bg-secondary-subtle text-secondary border"><?php echo count($grouped['ungrouped']); ?> خانە</span>
                                        </div>
                                        <div class="cf-section-card__body">
                                            <div class="table-responsive">
                                                <table class="table table-hover cf-table align-middle mb-0">
                                                    <thead class="cf-table-head">
                                                        <tr>
                                                            <th>ناوی خانە</th>
                                                            <th>جۆر</th>
                                                            <th>دۆخ</th>
                                                            <th class="text-center">کردار</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($grouped['ungrouped'] as $field): ?>
                                                            <?php echo renderFieldTableRow($field, $fieldTypes, $csrf_token); ?>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 cf-sidebar cf-sidebar-sticky">
                    <?php if ($sectionsAvailable): ?>
                    <div class="cf-panel">
                        <div class="cf-panel__head">
                            <h6><i class="bi bi-folder-plus text-secondary"></i> زیادکردنی بەش</h6>
                        </div>
                        <div class="cf-panel__body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="add_section">
                                <label class="form-label small text-muted">ناوی بەش</label>
                                <div class="input-group">
                                    <input type="text" name="section_name" class="form-control" placeholder="ناوی بەش" maxlength="120" required>
                                    <button type="submit" class="btn btn-secondary">تۆمار</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="cf-panel" id="cf-add-field-panel">
                        <div class="cf-panel__head">
                            <h5><i class="bi bi-plus-circle text-success"></i> زیادکردنی خانەی نوێ</h5>
                        </div>
                        <div class="cf-panel__body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="add_field">

                                <div class="mb-3">
                                    <label class="form-label">ناوی خانە</label>
                                    <input type="text" name="field_name" class="form-control" required maxlength="120" placeholder="نموونە: کۆمپانیا">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">جۆری خانە</label>
                                    <select name="field_type" class="form-select">
                                        <?php foreach ($fieldTypes as $typeKey => $typeLabel): ?>
                                            <option value="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if ($sectionsAvailable): ?>
                                <div class="mb-3">
                                    <label class="form-label">بەش</label>
                                    <select name="section_id" class="form-select">
                                        <option value="0">— بەبێ بەش —</option>
                                        <?php foreach ($sections as $sec): ?>
                                            <option value="<?php echo (int)$sec['id']; ?>">
                                                <?php echo htmlspecialchars($sec['section_name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-save"></i> تۆمارکردن
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
