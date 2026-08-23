<?php
/**
 * UI ـی یەکەکان بۆ دابەشکاری جوملە (business_type = 4)
 *
 * @var array<int, array<string, mixed>> $units
 */

$whPrimaryDefaultId = '';
$whSecondaryDefaultId = '';

foreach ($units as $u) {
    if ($whPrimaryDefaultId === '' && (!empty($u['is_default']) || ($u['name'] ?? '') === 'دانە')) {
        $whPrimaryDefaultId = (string)$u['id'];
    }
    if ($whSecondaryDefaultId === '' && ($u['name'] ?? '') === 'کارتۆن') {
        $whSecondaryDefaultId = (string)$u['id'];
    }
}

if ($whPrimaryDefaultId === '' && !empty($units)) {
    $whPrimaryDefaultId = (string)$units[0]['id'];
}

if ($whSecondaryDefaultId === '' && !empty($units)) {
    foreach ($units as $u) {
        if ((string)$u['id'] !== $whPrimaryDefaultId) {
            $whSecondaryDefaultId = (string)$u['id'];
            break;
        }
    }
}
?>
<div class="col-12" id="wholesale_units_form">
    <div class="alert alert-primary border-0 mb-3">
        <i class="bi bi-boxes"></i>
        <strong>دابەشکاری جوملە:</strong>
        نرخ و بڕی کارتۆن داخڵ بکە — نرخی دانە و بڕی گشتی خۆکار حساب دەکرێت.
    </div>

    <div class="card border-secondary mb-3">
        <div class="card-header bg-body-secondary">
            <h6 class="mb-0"><i class="bi bi-diagram-3"></i> یەکەکان و ڕێژە</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="wh_primary_unit_id" class="form-label">یەکەی سەرەکی دیاری بکە</label>
                    <select class="form-select" id="wh_primary_unit_id">
                        <option value="">هەڵبژێرە</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?php echo (int)$unit['id']; ?>"
                                <?php echo ((string)$unit['id'] === $whPrimaryDefaultId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($unit['name']); ?>
                                <?php if (!empty($unit['symbol'])): ?>
                                    (<?php echo htmlspecialchars($unit['symbol']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="wh_secondary_unit_id" class="form-label">یەکەی لاوەکی دیاری بکە</label>
                    <select class="form-select" id="wh_secondary_unit_id">
                        <option value="">هەڵبژێرە</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?php echo (int)$unit['id']; ?>"
                                <?php echo ((string)$unit['id'] === $whSecondaryDefaultId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($unit['name']); ?>
                                <?php if (!empty($unit['symbol'])): ?>
                                    (<?php echo htmlspecialchars($unit['symbol']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="wh_conversion_factor" class="form-label">
                        <span id="wh_conversion_label">١ کارتۆن چەند دانەیە؟</span>
                    </label>
                    <input type="number" class="form-control" id="wh_conversion_factor"
                           min="0.0001" step="any" placeholder="نموونە: 6" value="">
                </div>

                <div class="col-12">
                    <button type="button" class="btn btn-outline-primary btn-sm"
                            id="wh_manage_units_btn" data-bs-toggle="modal" data-bs-target="#unitsManagementModal">
                        <i class="bi bi-gear"></i> بەڕێوەبردنی یەکەکان
                    </button>
                    <?php if (empty($units)): ?>
                        <span class="text-warning small ms-2">هیچ یەکەیەک نییە — سەرەتا یەکە زیاد بکە.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-secondary mb-3">
        <div class="card-header bg-body-secondary d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-currency-exchange"></i> نرخەکانی <span id="wh_secondary_price_label">کارتۆن</span></h6>
            <select class="form-select form-select-sm w-auto" id="wh_currency">
                <option value="IQD">دینار (IQD)</option>
                <option value="USD">دۆلار (USD)</option>
            </select>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <label for="wh_carton_buy_price" class="form-label">نرخی کڕینی <span class="wh-secondary-name">کارتۆن</span></label>
                    <div class="input-group">
                        <input type="number" class="form-control wh-carton-price" id="wh_carton_buy_price"
                               data-price-field="buy_price" min="0" step="0.001" value="">
                        <span class="input-group-text wh-currency-suffix">دینار</span>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="wh_carton_sell_price" class="form-label">نرخی فرۆشتنی تاکی <span class="wh-secondary-name">کارتۆن</span></label>
                    <div class="input-group">
                        <input type="number" class="form-control wh-carton-price" id="wh_carton_sell_price"
                               data-price-field="sell_price" min="0" step="0.001" value="">
                        <span class="input-group-text wh-currency-suffix">دینار</span>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="wh_carton_wholesale_price" class="form-label">نرخی فرۆشتنی جوملەی <span class="wh-secondary-name">کارتۆن</span></label>
                    <div class="input-group">
                        <input type="number" class="form-control wh-carton-price" id="wh_carton_wholesale_price"
                               data-price-field="wholesale_price" min="0" step="0.001" value="">
                        <span class="input-group-text wh-currency-suffix">دینار</span>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="wh_carton_special_price" class="form-label">نرخی فرۆشتنی تایبەتی <span class="wh-secondary-name">کارتۆن</span></label>
                    <div class="input-group">
                        <input type="number" class="form-control wh-carton-price" id="wh_carton_special_price"
                               data-price-field="special_price" min="0" step="0.001" value="">
                        <span class="input-group-text wh-currency-suffix">دینار</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-secondary mb-3">
        <div class="card-header bg-body-secondary">
            <h6 class="mb-0"><i class="bi bi-calculator"></i> نرخی <span id="wh_primary_price_label">دانە</span> (خۆکار)</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <label class="form-label text-muted">نرخی کڕین</label>
                    <div class="form-control bg-body-secondary text-body" id="wh_piece_buy_display" readonly>—</div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label text-muted">نرخی فرۆشتن (تاک)</label>
                    <div class="form-control bg-body-secondary text-body" id="wh_piece_sell_display" readonly>—</div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label text-muted">نرخی جوملە</label>
                    <div class="form-control bg-body-secondary text-body" id="wh_piece_wholesale_display" readonly>—</div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label text-muted">نرخی تایبەت</label>
                    <div class="form-control bg-body-secondary text-body" id="wh_piece_special_display" readonly>—</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-secondary mb-3">
        <div class="card-header bg-body-secondary">
            <h6 class="mb-0"><i class="bi bi-box-seam"></i> بڕی بەردەست</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="wh_carton_stock" class="form-label">
                        بڕی بەردەست بە <span class="wh-secondary-name">کارتۆن</span>
                    </label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="wh_carton_stock"
                               min="0" step="0.001" value="0">
                        <span class="input-group-text wh-secondary-name-text">کارتۆن</span>
                    </div>
                    <div class="form-text" id="wh_piece_stock_hint">= 0 <span class="wh-primary-name">دانە</span></div>
                </div>
                <div class="col-md-6">
                    <label for="wh_carton_min_stock" class="form-label">
                        کەمترین بەردەست بە <span class="wh-secondary-name">کارتۆن</span>
                    </label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="wh_carton_min_stock"
                               min="0" step="0.001" value="0">
                        <span class="input-group-text wh-secondary-name-text">کارتۆن</span>
                    </div>
                    <div class="form-text" id="wh_piece_min_stock_hint">= 0 <span class="wh-primary-name">دانە</span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-secondary border-secondary mb-0" id="wh_summary_box">
        <i class="bi bi-info-circle"></i>
        <span id="wh_summary_text">ڕێژە و بڕ داخڵ بکە بۆ پیشاندانی کورتە.</span>
    </div>
</div>

<!-- Hidden POST fields for units[0] primary and units[1] secondary -->
<div id="units_container" class="d-none" aria-hidden="true">
    <input type="hidden" name="units[0][unit_id]" id="wh_hidden_primary_unit_id" value="">
    <input type="hidden" name="units[0][is_primary]" value="1">
    <input type="hidden" name="units[0][conversion_factor]" value="1">
    <input type="hidden" name="units[0][currency]" id="wh_hidden_primary_currency" value="IQD">
    <input type="hidden" name="units[0][buy_price]" id="wh_hidden_primary_buy_price" value="">
    <input type="hidden" name="units[0][sell_price]" id="wh_hidden_primary_sell_price" value="">
    <input type="hidden" name="units[0][wholesale_price]" id="wh_hidden_primary_wholesale_price" value="">
    <input type="hidden" name="units[0][special_price]" id="wh_hidden_primary_special_price" value="">
    <input type="hidden" name="units[0][stock_quantity]" id="wh_hidden_primary_stock" value="0">
    <input type="hidden" name="units[0][min_stock]" id="wh_hidden_primary_min_stock" value="0">

    <input type="hidden" name="units[1][unit_id]" id="wh_hidden_secondary_unit_id" value="">
    <input type="hidden" name="units[1][is_primary]" value="0">
    <input type="hidden" name="units[1][conversion_factor]" id="wh_hidden_secondary_factor" value="">
    <input type="hidden" name="units[1][currency]" id="wh_hidden_secondary_currency" value="IQD">
    <input type="hidden" name="units[1][buy_price]" id="wh_hidden_secondary_buy_price" value="">
    <input type="hidden" name="units[1][sell_price]" id="wh_hidden_secondary_sell_price" value="">
    <input type="hidden" name="units[1][wholesale_price]" id="wh_hidden_secondary_wholesale_price" value="">
    <input type="hidden" name="units[1][special_price]" id="wh_hidden_secondary_special_price" value="">
    <input type="hidden" name="units[1][stock_quantity]" id="wh_hidden_secondary_stock" value="0">
    <input type="hidden" name="units[1][min_stock]" id="wh_hidden_secondary_min_stock" value="0">
</div>
