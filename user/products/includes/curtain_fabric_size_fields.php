<?php
/**
 * خانەکانی پانی و بەرزی قوماش — تەنها بۆ دوکانی پەردە
 *
 * پێویستە: $isCurtainShopMode, $fabricWidthValue, $fabricHeightValue, $fabricMeasureUnit
 */
if (empty($isCurtainShopMode)) {
    return;
}

$fabricWidthValue = $fabricWidthValue ?? '';
$fabricHeightValue = $fabricHeightValue ?? '';
$fabricMeasureUnit = in_array(($fabricMeasureUnit ?? 'cm'), ['cm', 'm'], true) ? $fabricMeasureUnit : 'cm';
?>
<div class="col-12 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label for="fabric_width" class="form-label">
                <i class="bi bi-arrow-left-right"></i> پانی
            </label>
            <input type="number" class="form-control" id="fabric_width" name="fabric_width"
                   step="0.001" min="0" inputmode="decimal"
                   value="<?php echo htmlspecialchars((string)$fabricWidthValue); ?>"
                   placeholder="پانی">
        </div>
        <div class="col-md-4">
            <label for="fabric_height" class="form-label">
                <i class="bi bi-arrow-down-up"></i> بەرزی
            </label>
            <input type="number" class="form-control" id="fabric_height" name="fabric_height"
                   step="0.001" min="0" inputmode="decimal"
                   value="<?php echo htmlspecialchars((string)$fabricHeightValue); ?>"
                   placeholder="بەرزی">
        </div>
        <div class="col-md-4">
            <label for="fabric_measure_unit" class="form-label">
                <i class="bi bi-rulers"></i> یەکە
            </label>
            <select class="form-select" id="fabric_measure_unit" name="fabric_measure_unit">
                <option value="cm" <?php echo $fabricMeasureUnit === 'cm' ? 'selected' : ''; ?>>سم</option>
                <option value="m" <?php echo $fabricMeasureUnit === 'm' ? 'selected' : ''; ?>>مەتر</option>
            </select>
        </div>
    </div>
    <small class="text-muted">قیاسی قوماش — پانی و بەرزی</small>
</div>
