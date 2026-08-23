<?php
/**
 * Helpers for product custom fields.
 */

if (!function_exists('getProductCustomFieldTypes')) {
    function getProductCustomFieldTypes()
    {
        return [
            'text' => 'نووسین',
            'number' => 'ژمارە',
            'select' => 'هەڵبژاردن',
        ];
    }
}

if (!function_exists('getProductCustomFieldTypeMeta')) {
    /**
     * Display metadata for custom field type (icon, badge class).
     *
     * @return array{icon: string, badge_class: string}
     */
    function getProductCustomFieldTypeMeta(string $type): array
    {
        $map = [
            'text' => ['icon' => 'bi-fonts', 'badge_class' => 'cf-type-badge--text bg-primary-subtle text-primary border border-primary-subtle'],
            'number' => ['icon' => 'bi-123', 'badge_class' => 'cf-type-badge--number bg-info-subtle text-info border border-info-subtle'],
            'select' => ['icon' => 'bi-list-ul', 'badge_class' => 'cf-type-badge--select bg-success-subtle text-success border border-success-subtle'],
        ];

        return $map[$type] ?? ['icon' => 'bi-ui-checks', 'badge_class' => 'cf-type-badge--default bg-secondary-subtle text-secondary border border-secondary-subtle'];
    }
}

if (!function_exists('productCustomFieldOptionPathSeparator')) {
    function productCustomFieldOptionPathSeparator()
    {
        return ' › ';
    }
}

if (!function_exists('productCustomFieldsMaxOptionDepth')) {
    function productCustomFieldsMaxOptionDepth()
    {
        return 8;
    }
}

if (!function_exists('productCustomFieldsFeatureAvailable')) {
    function productCustomFieldsFeatureAvailable(mysqli $conn)
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        $requiredTables = [
            'product_custom_fields',
            'product_custom_field_values',
        ];
        foreach ($requiredTables as $tableName) {
            $safeName = str_replace('`', '``', $tableName);
            $result = $conn->query("SHOW TABLES LIKE '{$safeName}'");
            if (!$result || $result->num_rows === 0) {
                $available = false;
                return false;
            }
        }

        $available = true;
        return true;
    }
}

if (!function_exists('productCustomFieldSectionsAvailable')) {
    function productCustomFieldSectionsAvailable(mysqli $conn)
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        if (!productCustomFieldsFeatureAvailable($conn)) {
            $available = false;
            return false;
        }
        $result = $conn->query("SHOW TABLES LIKE 'product_custom_field_sections'");
        $available = $result && $result->num_rows > 0;
        return $available;
    }
}

if (!function_exists('productCustomFieldOptionsAvailable')) {
    function productCustomFieldOptionsAvailable(mysqli $conn)
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        if (!productCustomFieldsFeatureAvailable($conn)) {
            $available = false;
            return false;
        }
        $result = $conn->query("SHOW TABLES LIKE 'product_custom_field_options'");
        $available = $result && $result->num_rows > 0;
        return $available;
    }
}

if (!function_exists('buildProductCustomFieldKey')) {
    function buildProductCustomFieldKey($fieldName)
    {
        $normalized = mb_strtolower(trim((string)$fieldName), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '_', $normalized);
        $normalized = trim((string)$normalized, '_');

        if ($normalized === '') {
            $normalized = 'field';
        }

        return substr($normalized, 0, 150);
    }
}

if (!function_exists('getProductCustomFieldSections')) {
    function getProductCustomFieldSections(mysqli $conn, $userId, $activeOnly = true)
    {
        if (!productCustomFieldSectionsAvailable($conn)) {
            return [];
        }

        $sql = "SELECT id, user_id, section_name, section_order, is_active, created_at, updated_at
                FROM product_custom_field_sections
                WHERE user_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY section_order ASC, id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows ?: [];
    }
}

if (!function_exists('getProductCustomFieldSectionById')) {
    function getProductCustomFieldSectionById(mysqli $conn, $userId, $sectionId)
    {
        if (!productCustomFieldSectionsAvailable($conn) || $sectionId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT id, user_id, section_name, section_order, is_active
            FROM product_custom_field_sections
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("ii", $sectionId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('addProductCustomFieldSection')) {
    function addProductCustomFieldSection(mysqli $conn, $userId, $sectionName)
    {
        if (!productCustomFieldSectionsAvailable($conn)) {
            return [false, 'تایبەتمەندی بەشەکان بەردەست نییە'];
        }
        $sectionName = trim((string)$sectionName);
        if ($sectionName === '') {
            return [false, 'ناوی بەش پێویستە'];
        }

        $orderStmt = $conn->prepare("SELECT COALESCE(MAX(section_order), 0) + 1 AS next_order FROM product_custom_field_sections WHERE user_id = ?");
        if (!$orderStmt) {
            return [false, 'هەڵەیەک ڕوویدا'];
        }
        $orderStmt->bind_param("i", $userId);
        $orderStmt->execute();
        $nextOrder = (int)($orderStmt->get_result()->fetch_assoc()['next_order'] ?? 1);
        $orderStmt->close();

        $stmt = $conn->prepare("
            INSERT INTO product_custom_field_sections (user_id, section_name, section_order, is_active, created_at, updated_at)
            VALUES (?, ?, ?, 1, NOW(), NOW())
        ");
        if (!$stmt) {
            return [false, 'هەڵەیەک ڕوویدا'];
        }
        $stmt->bind_param("isi", $userId, $sectionName, $nextOrder);
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();

        return $ok ? [true, $newId] : [false, 'نەتوانرا بەش زیاد بکرێت'];
    }
}

if (!function_exists('getProductCustomFields')) {
    function getProductCustomFields(mysqli $conn, $userId, $activeOnly = true)
    {
        if (!productCustomFieldsFeatureAvailable($conn)) {
            return [];
        }

        $hasSections = productCustomFieldSectionsAvailable($conn);
        if ($hasSections) {
            $sql = "SELECT f.id, f.user_id, f.field_name, f.field_key, f.field_type, f.section_id,
                           f.field_order, f.is_active, f.created_at, f.updated_at,
                           s.section_name, s.section_order
                    FROM product_custom_fields f
                    LEFT JOIN product_custom_field_sections s ON s.id = f.section_id AND s.user_id = f.user_id
                    WHERE f.user_id = ?";
            if ($activeOnly) {
                $sql .= " AND f.is_active = 1";
            }
            $sql .= " ORDER BY COALESCE(s.section_order, 999999) ASC, f.field_order ASC, f.id ASC";
        } else {
            $sql = "SELECT id, user_id, field_name, field_key, field_type, field_order, is_active, created_at, updated_at
                    FROM product_custom_fields
                    WHERE user_id = ?";
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY field_order ASC, id ASC";
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows ?: [];
    }
}

if (!function_exists('getProductCustomFieldById')) {
    function getProductCustomFieldById(mysqli $conn, $userId, $fieldId, $activeOnly = false)
    {
        if (!productCustomFieldsFeatureAvailable($conn) || $fieldId <= 0) {
            return null;
        }

        $sql = "SELECT id, user_id, field_name, field_key, field_type, section_id, field_order, is_active
                FROM product_custom_fields
                WHERE id = ? AND user_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("ii", $fieldId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('updateProductCustomField')) {
    function updateProductCustomField(mysqli $conn, $userId, $fieldId, $fieldName, $fieldType, $sectionId = null)
    {
        $field = getProductCustomFieldById($conn, $userId, $fieldId, false);
        if (!$field) {
            return [false, 'خانەکە نەدۆزرایەوە'];
        }

        $fieldName = trim((string)$fieldName);
        $allowedTypes = array_keys(getProductCustomFieldTypes());
        if ($fieldName === '') {
            return [false, 'ناوی خانە پێویستە'];
        }
        if (!in_array($fieldType, $allowedTypes, true)) {
            return [false, 'جۆری خانە نادروستە'];
        }

        if ($sectionId !== null && $sectionId > 0 && productCustomFieldSectionsAvailable($conn)) {
            if (!getProductCustomFieldSectionById($conn, $userId, $sectionId)) {
                return [false, 'بەشەکە نەدۆزرایەوە'];
            }
        } else {
            $sectionId = null;
        }

        if (productCustomFieldSectionsAvailable($conn)) {
            $stmt = $conn->prepare("
                UPDATE product_custom_fields
                SET field_name = ?, field_type = ?, section_id = ?, updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            if (!$stmt) {
                return [false, 'هەڵەیەک ڕوویدا'];
            }
            $sectionParam = $sectionId;
            $stmt->bind_param("ssiii", $fieldName, $fieldType, $sectionParam, $fieldId, $userId);
        } else {
            $stmt = $conn->prepare("
                UPDATE product_custom_fields
                SET field_name = ?, field_type = ?, updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            if (!$stmt) {
                return [false, 'هەڵەیەک ڕوویدا'];
            }
            $stmt->bind_param("ssii", $fieldName, $fieldType, $fieldId, $userId);
        }

        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? [true, null] : [false, 'نەتوانرا خانەکە نوێ بکرێتەوە'];
    }
}

if (!function_exists('getProductCustomFieldOptionsFlat')) {
    function getProductCustomFieldOptionsFlat(mysqli $conn, $userId, $fieldId, $activeOnly = true)
    {
        if (!productCustomFieldOptionsAvailable($conn) || $fieldId <= 0) {
            return [];
        }

        $sql = "SELECT id, user_id, field_id, parent_id, option_label, option_order, is_active
                FROM product_custom_field_options
                WHERE user_id = ? AND field_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY COALESCE(parent_id, 0) ASC, option_order ASC, id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("ii", $userId, $fieldId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows ?: [];
    }
}

if (!function_exists('buildProductCustomFieldOptionsTree')) {
    function buildProductCustomFieldOptionsTree(array $flatRows, $parentId = null)
    {
        $tree = [];
        foreach ($flatRows as $row) {
            $rowParent = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            if ($rowParent === $parentId) {
                $node = [
                    'id' => (int)$row['id'],
                    'label' => $row['option_label'],
                    'order' => (int)$row['option_order'],
                    'children' => buildProductCustomFieldOptionsTree($flatRows, (int)$row['id']),
                ];
                $tree[] = $node;
            }
        }
        return $tree;
    }
}

if (!function_exists('getProductCustomFieldOptionsTree')) {
    function getProductCustomFieldOptionsTree(mysqli $conn, $userId, $fieldId, $activeOnly = true)
    {
        $flat = getProductCustomFieldOptionsFlat($conn, $userId, $fieldId, $activeOnly);
        return buildProductCustomFieldOptionsTree($flat, null);
    }
}

if (!function_exists('getProductCustomFieldOptionsTreesForFields')) {
    function getProductCustomFieldOptionsTreesForFields(mysqli $conn, $userId, array $fieldIds)
    {
        $trees = [];
        if (!productCustomFieldOptionsAvailable($conn)) {
            return $trees;
        }

        $fieldIds = array_values(array_filter(array_map('intval', $fieldIds), function ($id) {
            return $id > 0;
        }));
        if (empty($fieldIds)) {
            return $trees;
        }

        foreach ($fieldIds as $fieldId) {
            $trees[$fieldId] = getProductCustomFieldOptionsTree($conn, $userId, $fieldId, true);
        }

        return $trees;
    }
}

if (!function_exists('getProductCustomFieldOptionById')) {
    function getProductCustomFieldOptionById(mysqli $conn, $userId, $optionId, $activeOnly = false)
    {
        if (!productCustomFieldOptionsAvailable($conn) || $optionId <= 0) {
            return null;
        }

        $sql = "SELECT id, user_id, field_id, parent_id, option_label, option_order, is_active
                FROM product_custom_field_options
                WHERE id = ? AND user_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("ii", $optionId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('productCustomFieldOptionHasActiveChildren')) {
    function productCustomFieldOptionHasActiveChildren(mysqli $conn, $userId, $fieldId, $optionId)
    {
        if (!productCustomFieldOptionsAvailable($conn)) {
            return false;
        }
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM product_custom_field_options
            WHERE user_id = ? AND field_id = ? AND parent_id = ? AND is_active = 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("iii", $userId, $fieldId, $optionId);
        $stmt->execute();
        $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        return $cnt > 0;
    }
}

if (!function_exists('productCustomFieldOptionDepth')) {
    function productCustomFieldOptionDepth(mysqli $conn, $userId, $optionId)
    {
        $depth = 0;
        $currentId = $optionId;
        $visited = [];

        while ($currentId > 0 && $depth < 32) {
            if (isset($visited[$currentId])) {
                break;
            }
            $visited[$currentId] = true;
            $opt = getProductCustomFieldOptionById($conn, $userId, $currentId, false);
            if (!$opt) {
                break;
            }
            $depth++;
            $currentId = $opt['parent_id'] !== null ? (int)$opt['parent_id'] : 0;
        }

        return $depth;
    }
}

if (!function_exists('productCustomFieldWouldCreateCycle')) {
    function productCustomFieldWouldCreateCycle(mysqli $conn, $userId, $optionId, $newParentId)
    {
        if ($newParentId <= 0 || $optionId <= 0) {
            return false;
        }
        if ($optionId === $newParentId) {
            return true;
        }

        $currentId = $newParentId;
        $visited = [];
        while ($currentId > 0) {
            if ($currentId === $optionId) {
                return true;
            }
            if (isset($visited[$currentId])) {
                break;
            }
            $visited[$currentId] = true;
            $opt = getProductCustomFieldOptionById($conn, $userId, $currentId, false);
            if (!$opt) {
                break;
            }
            $currentId = $opt['parent_id'] !== null ? (int)$opt['parent_id'] : 0;
        }

        return false;
    }
}

if (!function_exists('addProductCustomFieldOption')) {
    function addProductCustomFieldOption(mysqli $conn, $userId, $fieldId, $optionLabel, $parentId = null)
    {
        if (!productCustomFieldOptionsAvailable($conn)) {
            return [false, 'تایبەتمەندی بژاردەکان بەردەست نییە'];
        }

        $field = getProductCustomFieldById($conn, $userId, $fieldId, false);
        if (!$field || ($field['field_type'] ?? '') !== 'select') {
            return [false, 'خانەکە بۆ هەڵبژاردن نییە'];
        }

        $optionLabel = trim((string)$optionLabel);
        if ($optionLabel === '') {
            return [false, 'ناوی بژاردە پێویستە'];
        }

        $parentId = $parentId !== null && (int)$parentId > 0 ? (int)$parentId : null;
        if ($parentId !== null) {
            $parent = getProductCustomFieldOptionById($conn, $userId, $parentId, false);
            if (!$parent || (int)$parent['field_id'] !== $fieldId) {
                return [false, 'بژاردەی سەرەکی نادروستە'];
            }
            $parentDepth = productCustomFieldOptionDepth($conn, $userId, $parentId);
            if ($parentDepth >= productCustomFieldsMaxOptionDepth()) {
                return [false, 'قووڵایی زۆرە؛ ناتوانرێت ئاستی تر زیاد بکرێت'];
            }
        }

        if ($parentId !== null) {
            $orderStmt = $conn->prepare("
                SELECT COALESCE(MAX(option_order), 0) + 1 AS next_order
                FROM product_custom_field_options
                WHERE user_id = ? AND field_id = ? AND parent_id = ?
            ");
            $orderStmt->bind_param("iii", $userId, $fieldId, $parentId);
        } else {
            $orderStmt = $conn->prepare("
                SELECT COALESCE(MAX(option_order), 0) + 1 AS next_order
                FROM product_custom_field_options
                WHERE user_id = ? AND field_id = ? AND parent_id IS NULL
            ");
            $orderStmt->bind_param("ii", $userId, $fieldId);
        }
        if (!$orderStmt) {
            return [false, 'هەڵەیەک ڕوویدا'];
        }
        $orderStmt->execute();
        $nextOrder = (int)($orderStmt->get_result()->fetch_assoc()['next_order'] ?? 1);
        $orderStmt->close();

        $stmt = $conn->prepare("
            INSERT INTO product_custom_field_options (user_id, field_id, parent_id, option_label, option_order, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        if (!$stmt) {
            return [false, 'هەڵەیەک ڕوویدا'];
        }
        $stmt->bind_param("iiisi", $userId, $fieldId, $parentId, $optionLabel, $nextOrder);
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();

        return $ok ? [true, $newId] : [false, 'نەتوانرا بژاردە زیاد بکرێت'];
    }
}

if (!function_exists('renameProductCustomFieldOption')) {
    function renameProductCustomFieldOption(mysqli $conn, $userId, $optionId, $optionLabel)
    {
        $option = getProductCustomFieldOptionById($conn, $userId, $optionId, false);
        if (!$option) {
            return [false, 'بژاردەکە نەدۆزرایەوە'];
        }
        $optionLabel = trim((string)$optionLabel);
        if ($optionLabel === '') {
            return [false, 'ناوی بژاردە پێویستە'];
        }

        $stmt = $conn->prepare("
            UPDATE product_custom_field_options
            SET option_label = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        if (!$stmt) {
            return [false, 'هەڵەیەک ڕوویدا'];
        }
        $stmt->bind_param("sii", $optionLabel, $optionId, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? [true, null] : [false, 'نەتوانرا ناو نوێ بکرێتەوە'];
    }
}

if (!function_exists('deactivateProductCustomFieldOptionSubtree')) {
    function deactivateProductCustomFieldOptionSubtree(mysqli $conn, $userId, $optionId)
    {
        $option = getProductCustomFieldOptionById($conn, $userId, $optionId, false);
        if (!$option) {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE product_custom_field_options
            SET is_active = 0, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ii", $optionId, $userId);
        $stmt->execute();
        $stmt->close();

        $children = getProductCustomFieldOptionsFlat($conn, $userId, (int)$option['field_id'], false);
        foreach ($children as $child) {
            if ((int)($child['parent_id'] ?? 0) === $optionId && (int)$child['is_active'] === 1) {
                deactivateProductCustomFieldOptionSubtree($conn, $userId, (int)$child['id']);
            }
        }

        return true;
    }
}

if (!function_exists('reorderProductCustomFieldOption')) {
    function reorderProductCustomFieldOption(mysqli $conn, $userId, $optionId, $direction)
    {
        $option = getProductCustomFieldOptionById($conn, $userId, $optionId, true);
        if (!$option) {
            return [false, 'بژاردەکە نەدۆزرایەوە'];
        }

        $fieldId = (int)$option['field_id'];
        $parentId = $option['parent_id'] !== null ? (int)$option['parent_id'] : null;
        $currentOrder = (int)$option['option_order'];

        $siblings = [];
        $flat = getProductCustomFieldOptionsFlat($conn, $userId, $fieldId, true);
        foreach ($flat as $row) {
            $rowParent = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            if ($rowParent === $parentId) {
                $siblings[] = $row;
            }
        }

        usort($siblings, function ($a, $b) {
            $oa = (int)$a['option_order'];
            $ob = (int)$b['option_order'];
            if ($oa === $ob) {
                return (int)$a['id'] <=> (int)$b['id'];
            }
            return $oa <=> $ob;
        });

        $index = null;
        foreach ($siblings as $i => $s) {
            if ((int)$s['id'] === $optionId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return [false, 'بژاردەکە نەدۆزرایەوە'];
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapIndex < 0 || $swapIndex >= count($siblings)) {
            return [false, 'ناتوانرێت ڕیز بگۆڕدرێت'];
        }

        $other = $siblings[$swapIndex];
        $otherId = (int)$other['id'];
        $otherOrder = (int)$other['option_order'];

        $stmt1 = $conn->prepare("UPDATE product_custom_field_options SET option_order = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt2 = $conn->prepare("UPDATE product_custom_field_options SET option_order = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        if (!$stmt1 || !$stmt2) {
            return [false, 'هەڵەیەک ڕوویدا'];
        }

        $stmt1->bind_param("iii", $otherOrder, $optionId, $userId);
        $stmt1->execute();
        $stmt1->close();

        $stmt2->bind_param("iii", $currentOrder, $otherId, $userId);
        $stmt2->execute();
        $stmt2->close();

        return [true, null];
    }
}

if (!function_exists('resolveCustomFieldOptionPath')) {
    function resolveCustomFieldOptionPath(mysqli $conn, $userId, $optionId)
    {
        if (!productCustomFieldOptionsAvailable($conn) || $optionId <= 0) {
            return '';
        }

        $labels = [];
        $currentId = $optionId;
        $visited = [];

        while ($currentId > 0) {
            if (isset($visited[$currentId])) {
                break;
            }
            $visited[$currentId] = true;
            $opt = getProductCustomFieldOptionById($conn, $userId, $currentId, false);
            if (!$opt) {
                break;
            }
            array_unshift($labels, $opt['option_label']);
            $currentId = $opt['parent_id'] !== null ? (int)$opt['parent_id'] : 0;
        }

        return implode(productCustomFieldOptionPathSeparator(), $labels);
    }
}

if (!function_exists('getCustomFieldOptionDescendantIds')) {
    /**
     * هەموو ناسنامەکانی منداڵ (و ئەگەر $includeSelf) بۆ فلتەرکردنی کاڵا بەپێی لق/بەش.
     *
     * @return int[]
     */
    function getCustomFieldOptionDescendantIds(mysqli $conn, $userId, $optionId, $includeSelf = true)
    {
        $optionId = (int)$optionId;
        if ($optionId <= 0 || !productCustomFieldOptionsAvailable($conn)) {
            return $includeSelf && $optionId > 0 ? [$optionId] : [];
        }

        $option = getProductCustomFieldOptionById($conn, $userId, $optionId, false);
        if (!$option) {
            return [];
        }

        $fieldId = (int)$option['field_id'];
        $flat = getProductCustomFieldOptionsFlat($conn, $userId, $fieldId, false);

        $childrenByParent = [];
        foreach ($flat as $row) {
            $parentKey = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
            if (!isset($childrenByParent[$parentKey])) {
                $childrenByParent[$parentKey] = [];
            }
            $childrenByParent[$parentKey][] = (int)$row['id'];
        }

        $ids = $includeSelf ? [$optionId] : [];
        $queue = [$optionId];
        $visited = [];

        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            foreach ($childrenByParent[$current] ?? [] as $childId) {
                if (!isset($visited[$childId])) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('getCustomFieldOptionAncestorIds')) {
    function getCustomFieldOptionAncestorIds(mysqli $conn, $userId, $optionId)
    {
        $ids = [];
        $currentId = $optionId;
        $visited = [];

        while ($currentId > 0) {
            if (isset($visited[$currentId])) {
                break;
            }
            $visited[$currentId] = true;
            $opt = getProductCustomFieldOptionById($conn, $userId, $currentId, false);
            if (!$opt) {
                break;
            }
            $ids[] = $currentId;
            $currentId = $opt['parent_id'] !== null ? (int)$opt['parent_id'] : 0;
        }

        return array_reverse($ids);
    }
}

if (!function_exists('validateCustomFieldOptionSelection')) {
    function validateCustomFieldOptionSelection(mysqli $conn, $userId, $fieldId, $optionId)
    {
        if (!productCustomFieldOptionsAvailable($conn) || $optionId <= 0) {
            return [false, 'هەڵبژاردن نادروستە'];
        }

        $option = getProductCustomFieldOptionById($conn, $userId, $optionId, true);
        if (!$option || (int)$option['field_id'] !== $fieldId) {
            return [false, 'هەڵبژاردن نادروستە'];
        }

        if (productCustomFieldOptionHasActiveChildren($conn, $userId, $fieldId, $optionId)) {
            return [false, 'پێویستە ئاستی خوارتر هەڵبژێردرێت'];
        }

        return [true, null];
    }
}

if (!function_exists('formatProductCustomFieldDisplayValue')) {
    function formatProductCustomFieldDisplayValue(mysqli $conn, $userId, $fieldType, $storedValue)
    {
        if ($fieldType === 'select' && productCustomFieldOptionsAvailable($conn)) {
            $optionId = (int)$storedValue;
            if ($optionId <= 0) {
                return '';
            }
            $path = resolveCustomFieldOptionPath($conn, $userId, $optionId);
            return $path !== '' ? $path : (string)$storedValue;
        }

        return $storedValue === null ? '' : (string)$storedValue;
    }
}

if (!function_exists('getProductCustomFieldValuesMap')) {
    function getProductCustomFieldValuesMap(mysqli $conn, $userId, array $productIds)
    {
        if (!productCustomFieldsFeatureAvailable($conn)) {
            return [];
        }

        $productIds = array_values(array_filter(array_map('intval', $productIds), function ($id) {
            return $id > 0;
        }));
        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = 'i' . str_repeat('i', count($productIds));
        $params = array_merge([$userId], $productIds);

        $sql = "SELECT v.product_id, v.field_id, v.value_text, v.value_number, f.field_name, f.field_type
                FROM product_custom_field_values v
                INNER JOIN product_custom_fields f ON f.id = v.field_id
                WHERE v.user_id = ? AND v.product_id IN ($placeholders) AND f.user_id = ? AND f.is_active = 1
                ORDER BY f.field_order ASC, f.id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $types .= 'i';
        $params[] = $userId;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $map = [];
        foreach ($rows as $row) {
            $pid = (int)$row['product_id'];
            if (!isset($map[$pid])) {
                $map[$pid] = [];
            }
            $fieldType = $row['field_type'] ?? 'text';
            if ($fieldType === 'number') {
                $value = $row['value_number'];
            } elseif ($fieldType === 'select') {
                $value = trim((string)($row['value_text'] ?? ''));
            } else {
                $value = $row['value_text'];
            }

            $displayValue = formatProductCustomFieldDisplayValue($conn, $userId, $fieldType, $value);

            $map[$pid][] = [
                'field_id' => (int)$row['field_id'],
                'field_name' => $row['field_name'],
                'field_type' => $fieldType,
                'value' => $value,
                'display_value' => $displayValue,
            ];
        }

        return $map;
    }
}

if (!function_exists('parseProductCustomFieldInput')) {
    function parseProductCustomFieldInput(mysqli $conn, $userId, array $definitions, array $postedValues)
    {
        $result = [];
        $errors = [];
        foreach ($definitions as $field) {
            $fieldId = (int)$field['id'];
            $fieldType = $field['field_type'] ?? 'text';
            $rawValue = $postedValues[$fieldId] ?? '';

            if ($fieldType === 'number') {
                $rawValue = trim((string)$rawValue);
                if ($rawValue === '') {
                    $result[$fieldId] = ['value_text' => null, 'value_number' => null];
                    continue;
                }
                if (!is_numeric($rawValue)) {
                    $errors[] = "بەهای خانەی '{$field['field_name']}' دەبێت ژمارە بێت";
                    continue;
                }
                $result[$fieldId] = ['value_text' => null, 'value_number' => (float)$rawValue];
                continue;
            }

            if ($fieldType === 'select') {
                $rawValue = trim((string)$rawValue);
                if ($rawValue === '') {
                    $result[$fieldId] = ['value_text' => null, 'value_number' => null];
                    continue;
                }
                $optionId = (int)$rawValue;
                if ($optionId <= 0) {
                    $errors[] = "هەڵبژاردنی خانەی '{$field['field_name']}' نادروستە";
                    continue;
                }
                [$valid, $msg] = validateCustomFieldOptionSelection($conn, $userId, $fieldId, $optionId);
                if (!$valid) {
                    $errors[] = $msg ?: "هەڵبژاردنی خانەی '{$field['field_name']}' نادروستە";
                    continue;
                }
                $result[$fieldId] = ['value_text' => (string)$optionId, 'value_number' => null];
                continue;
            }

            $result[$fieldId] = ['value_text' => trim((string)$rawValue), 'value_number' => null];
        }

        return [$result, $errors];
    }
}

if (!function_exists('saveProductCustomFieldValues')) {
    function saveProductCustomFieldValues(mysqli $conn, $userId, $productId, array $parsedValues)
    {
        if (!productCustomFieldsFeatureAvailable($conn)) {
            return;
        }

        $deleteStmt = $conn->prepare("DELETE FROM product_custom_field_values WHERE user_id = ? AND product_id = ?");
        if (!$deleteStmt) {
            throw new RuntimeException('Failed preparing custom value delete statement');
        }
        $deleteStmt->bind_param("ii", $userId, $productId);
        if (!$deleteStmt->execute()) {
            $deleteStmt->close();
            throw new RuntimeException('Failed deleting old custom values');
        }
        $deleteStmt->close();

        if (empty($parsedValues)) {
            return;
        }

        $insertTextStmt = $conn->prepare("
            INSERT INTO product_custom_field_values (product_id, field_id, user_id, value_text, value_number, created_at, updated_at)
            VALUES (?, ?, ?, ?, NULL, NOW(), NOW())
        ");
        if (!$insertTextStmt) {
            throw new RuntimeException('Failed preparing text custom value insert statement');
        }

        $insertNumberStmt = $conn->prepare("
            INSERT INTO product_custom_field_values (product_id, field_id, user_id, value_text, value_number, created_at, updated_at)
            VALUES (?, ?, ?, NULL, ?, NOW(), NOW())
        ");
        if (!$insertNumberStmt) {
            $insertTextStmt->close();
            throw new RuntimeException('Failed preparing number custom value insert statement');
        }

        foreach ($parsedValues as $fieldId => $valueSet) {
            $fieldId = (int)$fieldId;
            $valueText = $valueSet['value_text'];
            $valueNumber = $valueSet['value_number'];
            $hasValue = ($valueText !== null && $valueText !== '') || $valueNumber !== null;
            if (!$hasValue) {
                continue;
            }

            if ($valueNumber !== null) {
                $numberParam = (float)$valueNumber;
                $insertNumberStmt->bind_param("iiid", $productId, $fieldId, $userId, $numberParam);
                if (!$insertNumberStmt->execute()) {
                    $insertTextStmt->close();
                    $insertNumberStmt->close();
                    throw new RuntimeException('Failed inserting number custom value');
                }
                continue;
            }

            $textParam = (string)$valueText;
            $insertTextStmt->bind_param("iiis", $productId, $fieldId, $userId, $textParam);
            if (!$insertTextStmt->execute()) {
                $insertTextStmt->close();
                $insertNumberStmt->close();
                throw new RuntimeException('Failed inserting text custom value');
            }
        }
        $insertTextStmt->close();
        $insertNumberStmt->close();
    }
}

if (!function_exists('groupProductCustomFieldsBySection')) {
    function groupProductCustomFieldsBySection(array $fields)
    {
        $groups = [];
        $ungrouped = [];

        foreach ($fields as $field) {
            $sectionId = isset($field['section_id']) ? (int)$field['section_id'] : 0;
            $sectionName = trim((string)($field['section_name'] ?? ''));
            if ($sectionId > 0 && $sectionName !== '') {
                if (!isset($groups[$sectionId])) {
                    $groups[$sectionId] = [
                        'section_id' => $sectionId,
                        'section_name' => $sectionName,
                        'section_order' => (int)($field['section_order'] ?? 0),
                        'fields' => [],
                    ];
                }
                $groups[$sectionId]['fields'][] = $field;
            } else {
                $ungrouped[] = $field;
            }
        }

        uasort($groups, function ($a, $b) {
            return ($a['section_order'] ?? 0) <=> ($b['section_order'] ?? 0);
        });

        return ['sections' => array_values($groups), 'ungrouped' => $ungrouped];
    }
}
