<?php
/**
 * Partial: product custom fields form (text, number, cascading select).
 *
 * Expected variables:
 * - $customFieldDefinitions (array)
 * - $valuesByFieldId (array field_id => raw stored value)
 * - $optionsTreesByFieldId (array field_id => tree) — optional
 * - $conn, $userId — for resolving select paths when needed
 */

if (empty($customFieldDefinitions)) {
    return;
}

$optionsTreesByFieldId = $optionsTreesByFieldId ?? [];
$valuesByFieldId = $valuesByFieldId ?? [];
$grouped = groupProductCustomFieldsBySection($customFieldDefinitions);

$cascadeScriptUrl = url('user/products/assets/custom_fields_cascade.js');
$hasSelectFields = false;
foreach ($customFieldDefinitions as $f) {
    if (($f['field_type'] ?? '') === 'select') {
        $hasSelectFields = true;
        break;
    }
}

$renderFieldInput = function (array $field) use ($valuesByFieldId, $optionsTreesByFieldId, $conn, $userId) {
    $fieldId = (int)$field['id'];
    $fieldType = $field['field_type'] ?? 'text';
    $fieldName = $field['field_name'] ?? '';
    $fieldValue = $valuesByFieldId[$fieldId] ?? '';
    ?>
    <div class="col-md-6 mb-3">
        <label class="form-label"><?php echo htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8'); ?></label>
        <?php if ($fieldType === 'number'): ?>
            <input type="number" step="0.001" class="form-control"
                   name="custom_fields[<?php echo $fieldId; ?>]"
                   value="<?php echo htmlspecialchars((string)$fieldValue, ENT_QUOTES, 'UTF-8'); ?>">
        <?php elseif ($fieldType === 'select' && !empty($optionsTreesByFieldId[$fieldId])): ?>
            <?php
            $tree = $optionsTreesByFieldId[$fieldId];
            $selectedPath = [];
            $optionId = (int)$fieldValue;
            if ($optionId > 0 && isset($conn, $userId)) {
                $selectedPath = getCustomFieldOptionAncestorIds($conn, $userId, $optionId);
            }
            ?>
            <div class="cf-cascade-field"
                 data-field-id="<?php echo $fieldId; ?>"
                 data-options-tree="<?php echo htmlspecialchars(json_encode($tree, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                 data-selected-path="<?php echo htmlspecialchars(json_encode($selectedPath, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" class="cf-cascade-hidden" name="custom_fields[<?php echo $fieldId; ?>]" value="<?php echo $optionId > 0 ? (int)$optionId : ''; ?>">
                <div class="cf-cascade-levels"></div>
            </div>
        <?php elseif ($fieldType === 'select'): ?>
            <input type="text" class="form-control" disabled placeholder="هیچ بژاردەیەک دیاری نەکراوە">
        <?php else: ?>
            <input type="text" class="form-control"
                   name="custom_fields[<?php echo $fieldId; ?>]"
                   value="<?php echo htmlspecialchars((string)$fieldValue, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
    </div>
    <?php
};
?>
<div class="row mt-4 product-custom-fields-block">
    <div class="col-12">
        <h6 class="border-bottom pb-2 mb-3">
            <i class="bi bi-ui-checks-grid text-primary"></i> خانە زیادەکان
        </h6>
    </div>

    <?php foreach ($grouped['sections'] as $sectionGroup): ?>
        <div class="col-12 mb-2">
            <div class="card border-primary-subtle">
                <div class="card-header py-2 bg-body-tertiary">
                    <strong class="small"><i class="bi bi-folder2"></i> <?php echo htmlspecialchars($sectionGroup['section_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($sectionGroup['fields'] as $field): ?>
                            <?php $renderFieldInput($field); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($grouped['ungrouped'])): ?>
        <?php if (!empty($grouped['sections'])): ?>
            <div class="col-12"><p class="small text-muted mb-2">خانەکانی تر</p></div>
        <?php endif; ?>
        <div class="row">
            <?php foreach ($grouped['ungrouped'] as $field): ?>
                <?php $renderFieldInput($field); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php if ($hasSelectFields): ?>
<script src="<?php echo htmlspecialchars($cascadeScriptUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
