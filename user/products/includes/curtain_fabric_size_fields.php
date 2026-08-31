<?php
/**
 * خانەکانی قیاس و ڕەنگی قوماش — تەنها بۆ دوکانی پەردە
 *
 * پێویستە: $isCurtainShopMode, $fabricColorValue, $fabricWidthValue, $fabricHeightValue, $fabricMeasureUnit
 */
if (empty($isCurtainShopMode)) {
    return;
}

$fabricColorValue = $fabricColorValue ?? '';
$fabricWidthValue = $fabricWidthValue ?? '';
$fabricHeightValue = $fabricHeightValue ?? '';
$fabricMeasureUnit = in_array(($fabricMeasureUnit ?? 'm'), ['cm', 'm'], true) ? $fabricMeasureUnit : 'm';
$colorSuggestions = function_exists('getCurtainColorSuggestions') ? getCurtainColorSuggestions() : [];
?>
<div class="col-12 mb-3 curtain-fields-box p-3 rounded-3 border border-primary-subtle bg-primary-subtle bg-opacity-10">
    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom border-primary-subtle border-opacity-25">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-window-split text-primary fs-5"></i>
            <strong class="text-primary">تایبەتمەندی و قەبارەی قوماش / تۆپ</strong>
        </div>
        <span class="badge bg-primary text-white px-2 py-1 small">تایبەت بە دوکانی پەردە</span>
    </div>

    <!-- Fabric Color Section -->
    <div class="mb-3">
        <label for="fabric_color" class="form-label fw-medium">
            <i class="bi bi-palette text-primary"></i> ڕەنگی قوماش
        </label>
        <div class="input-group">
            <span class="input-group-text bg-body-secondary"><i class="bi bi-paint-bucket"></i></span>
            <input type="text" class="form-control" id="fabric_color" name="fabric_color"
                   list="curtain_colors_list"
                   value="<?php echo htmlspecialchars((string)$fabricColorValue); ?>"
                   placeholder="ڕەنگ بنووسە یان لە خوارەوە هەڵیبژێرە (وەک: سپی، شیری، بێژ، زێڕین...)"
                   autocomplete="off">
            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('fabric_color').value=''; document.getElementById('fabric_color').focus();" title="سڕینەوە">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <datalist id="curtain_colors_list">
            <?php foreach ($colorSuggestions as $cs): ?>
                <option value="<?php echo htmlspecialchars($cs['name']); ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <!-- Clickable quick color chips -->
        <?php if (!empty($colorSuggestions)): ?>
        <div class="d-flex flex-wrap gap-1 mt-2 align-items-center">
            <span class="small text-muted me-1">پێشنیارەکان:</span>
            <?php foreach ($colorSuggestions as $cs): ?>
                <button type="button" 
                        class="btn btn-sm btn-light border py-0 px-2 rounded-pill d-inline-flex align-items-center gap-1 shadow-sm curtain-color-chip"
                        style="font-size: 0.78rem; transition: transform 0.15s ease;"
                        onclick="document.getElementById('fabric_color').value='<?php echo htmlspecialchars($cs['name'], ENT_QUOTES); ?>';">
                    <span class="d-inline-block rounded-circle" 
                          style="width: 10px; height: 10px; background: <?php echo htmlspecialchars($cs['hex']); ?>; <?php echo !empty($cs['border']) ? 'border: 1px solid #cbd5e1;' : ''; ?>"></span>
                    <span><?php echo htmlspecialchars($cs['name']); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Fabric Dimensions Section (Height & Length/Width) -->
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label for="fabric_height" class="form-label fw-medium">
                <i class="bi bi-arrow-down-up text-primary"></i> بەرزی قوماش / تۆپ
            </label>
            <input type="number" class="form-control" id="fabric_height" name="fabric_height"
                   step="0.001" min="0" inputmode="decimal"
                   value="<?php echo htmlspecialchars((string)$fabricHeightValue); ?>"
                   placeholder="بەرزی (نموونە: 2.80 یان 3.00)">
        </div>
        <div class="col-md-4">
            <label for="fabric_width" class="form-label fw-medium">
                <i class="bi bi-arrow-left-right text-primary"></i> درێژی / پانی قوماش
            </label>
            <input type="number" class="form-control" id="fabric_width" name="fabric_width"
                   step="0.001" min="0" inputmode="decimal"
                   value="<?php echo htmlspecialchars((string)$fabricWidthValue); ?>"
                   placeholder="درێژی (نموونە: 3.50)">
        </div>
        <div class="col-md-4">
            <label for="fabric_measure_unit" class="form-label fw-medium">
                <i class="bi bi-rulers text-primary"></i> یەکەی پێوانە
            </label>
            <select class="form-select" id="fabric_measure_unit" name="fabric_measure_unit">
                <option value="m" <?php echo $fabricMeasureUnit === 'm' ? 'selected' : ''; ?>>مەتر (m)</option>
                <option value="cm" <?php echo $fabricMeasureUnit === 'cm' ? 'selected' : ''; ?>>سانتیمەتر (cm)</option>
            </select>
        </div>
    </div>
</div>
