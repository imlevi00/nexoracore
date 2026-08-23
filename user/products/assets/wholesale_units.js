/**
 * UI ـی یەکەکان بۆ دابەشکاری جوملە (business_type = 4)
 */
(function () {
    'use strict';

    const PRICE_FIELDS = ['buy_price', 'sell_price', 'wholesale_price', 'special_price'];

    let unitsCatalog = [];
    let initialData = null;

    function $(id) {
        return document.getElementById(id);
    }

    function parseNum(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : 0;
    }

    function formatPrice(value, currency) {
        if (!Number.isFinite(value) || value <= 0) {
            return '—';
        }
        const formatted = new Intl.NumberFormat('ar-IQ', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 3,
        }).format(value);
        return currency === 'USD' ? formatted + ' $' : formatted + ' دینار';
    }

    function formatQty(value) {
        if (!Number.isFinite(value)) {
            return '0';
        }
        const s = value.toFixed(8).replace(/\.?0+$/, '');
        return s === '' ? '0' : s;
    }

    function getUnitById(unitId) {
        return unitsCatalog.find(function (u) {
            return String(u.id) === String(unitId);
        }) || null;
    }

    function getPrimaryUnitId() {
        return $('wh_primary_unit_id') ? $('wh_primary_unit_id').value : '';
    }

    function getSecondaryUnitId() {
        return $('wh_secondary_unit_id') ? $('wh_secondary_unit_id').value : '';
    }

    function getConversionFactor() {
        const factor = parseNum($('wh_conversion_factor') ? $('wh_conversion_factor').value : '');
        return factor > 0 ? factor : 0;
    }

    function getCurrency() {
        return $('wh_currency') && $('wh_currency').value === 'USD' ? 'USD' : 'IQD';
    }

    function getPrimaryName() {
        const unit = getUnitById(getPrimaryUnitId());
        return unit ? unit.name : 'دانە';
    }

    function getSecondaryName() {
        const unit = getUnitById(getSecondaryUnitId());
        return unit ? unit.name : 'کارتۆن';
    }

    function updateLabels() {
        const primaryName = getPrimaryName();
        const secondaryName = getSecondaryName();
        const conversionLabel = $('wh_conversion_label');
        const secondaryPriceLabel = $('wh_secondary_price_label');
        const primaryPriceLabel = $('wh_primary_price_label');

        if (conversionLabel) {
            conversionLabel.textContent = '١ ' + secondaryName + ' چەند ' + primaryName + ' ـە؟';
        }
        if (secondaryPriceLabel) {
            secondaryPriceLabel.textContent = secondaryName;
        }
        if (primaryPriceLabel) {
            primaryPriceLabel.textContent = primaryName;
        }

        document.querySelectorAll('.wh-secondary-name').forEach(function (el) {
            el.textContent = secondaryName;
        });
        document.querySelectorAll('.wh-secondary-name-text').forEach(function (el) {
            el.textContent = secondaryName;
        });
        document.querySelectorAll('.wh-primary-name').forEach(function (el) {
            el.textContent = primaryName;
        });
    }

    function updateCurrencyLabels() {
        const currency = getCurrency();
        const suffix = currency === 'USD' ? '$' : 'دینار';
        document.querySelectorAll('.wh-currency-suffix').forEach(function (el) {
            el.textContent = suffix;
        });
    }

    function getCartonPrice(field) {
        const input = document.querySelector('.wh-carton-price[data-price-field="' + field + '"]');
        return input ? parseNum(input.value) : 0;
    }

    function syncPriceFromCarton() {
        const factor = getConversionFactor();
        const currency = getCurrency();

        PRICE_FIELDS.forEach(function (field) {
            const cartonPrice = getCartonPrice(field);
            const piecePrice = factor > 0 && cartonPrice > 0 ? cartonPrice / factor : 0;

            const hiddenPrimary = $('wh_hidden_primary_' + field);
            const hiddenSecondary = $('wh_hidden_secondary_' + field);
            if (hiddenPrimary) {
                hiddenPrimary.value = piecePrice > 0 ? formatQty(piecePrice) : '';
            }
            if (hiddenSecondary) {
                hiddenSecondary.value = cartonPrice > 0 ? formatQty(cartonPrice) : '';
            }

            const displayMap = {
                buy_price: 'wh_piece_buy_display',
                sell_price: 'wh_piece_sell_display',
                wholesale_price: 'wh_piece_wholesale_display',
                special_price: 'wh_piece_special_display',
            };
            const displayEl = $(displayMap[field]);
            if (displayEl) {
                displayEl.textContent = formatPrice(piecePrice, currency);
            }
        });

        if ($('wh_hidden_primary_currency')) {
            $('wh_hidden_primary_currency').value = currency;
        }
        if ($('wh_hidden_secondary_currency')) {
            $('wh_hidden_secondary_currency').value = currency;
        }
    }

    function syncStockFromCarton() {
        const factor = getConversionFactor();
        const cartonStock = parseNum($('wh_carton_stock') ? $('wh_carton_stock').value : '');
        const cartonMin = parseNum($('wh_carton_min_stock') ? $('wh_carton_min_stock').value : '');
        const primaryName = getPrimaryName();
        const secondaryName = getSecondaryName();

        const pieceStock = factor > 0 ? cartonStock * factor : 0;
        const pieceMin = factor > 0 ? cartonMin * factor : 0;

        if ($('wh_hidden_primary_stock')) {
            $('wh_hidden_primary_stock').value = formatQty(pieceStock);
        }
        if ($('wh_hidden_primary_min_stock')) {
            $('wh_hidden_primary_min_stock').value = formatQty(pieceMin);
        }
        if ($('wh_hidden_secondary_stock')) {
            $('wh_hidden_secondary_stock').value = formatQty(cartonStock);
        }
        if ($('wh_hidden_secondary_min_stock')) {
            $('wh_hidden_secondary_min_stock').value = formatQty(cartonMin);
        }

        if ($('wh_piece_stock_hint')) {
            $('wh_piece_stock_hint').innerHTML = '= ' + formatQty(pieceStock) + ' <span class="wh-primary-name">' + primaryName + '</span>';
        }
        if ($('wh_piece_min_stock_hint')) {
            $('wh_piece_min_stock_hint').innerHTML = '= ' + formatQty(pieceMin) + ' <span class="wh-primary-name">' + primaryName + '</span>';
        }

        updateSummary();
    }

    function updateSummary() {
        const factor = getConversionFactor();
        const primaryName = getPrimaryName();
        const secondaryName = getSecondaryName();
        const cartonStock = parseNum($('wh_carton_stock') ? $('wh_carton_stock').value : '');
        const summaryEl = $('wh_summary_text');

        if (!summaryEl) {
            return;
        }

        if (factor <= 0) {
            summaryEl.textContent = 'ڕێژەی گۆڕین داخڵ بکە (نموونە: ١ ' + secondaryName + ' = 6 ' + primaryName + ')';
            return;
        }

        const pieceStock = cartonStock * factor;
        summaryEl.textContent =
            '١ ' + secondaryName + ' = ' + formatQty(factor) + ' ' + primaryName +
            ' | ' + formatQty(cartonStock) + ' ' + secondaryName + ' = ' + formatQty(pieceStock) + ' ' + primaryName;
    }

    function buildHiddenUnitFields() {
        const primaryId = getPrimaryUnitId();
        const secondaryId = getSecondaryUnitId();
        const factor = getConversionFactor();

        if ($('wh_hidden_primary_unit_id')) {
            $('wh_hidden_primary_unit_id').value = primaryId;
        }
        if ($('wh_hidden_secondary_unit_id')) {
            $('wh_hidden_secondary_unit_id').value = secondaryId;
        }
        if ($('wh_hidden_secondary_factor')) {
            $('wh_hidden_secondary_factor').value = factor > 0 ? formatQty(factor) : '';
        }

        syncPriceFromCarton();
        syncStockFromCarton();
    }

    function validateWholesaleForm() {
        const primaryId = getPrimaryUnitId();
        const secondaryId = getSecondaryUnitId();
        const factor = getConversionFactor();

        if (!primaryId) {
            return 'تکایە یەکەی سەرەکی هەڵبژێرە';
        }
        if (!secondaryId) {
            return 'تکایە یەکەی لاوەکی هەڵبژێرە';
        }
        if (primaryId === secondaryId) {
            return 'یەکەی سەرەکی و لاوەکی دەبێت جیاواز بن';
        }
        if (factor <= 0) {
            return 'ڕێژەی گۆڕین دەبێت لە سفر گەورەتر بێت (نموونە: ١ کارتۆن = 6 دانە)';
        }

        return '';
    }

    function bindEvents() {
        const primarySelect = $('wh_primary_unit_id');
        const secondarySelect = $('wh_secondary_unit_id');
        const factorInput = $('wh_conversion_factor');
        const currencySelect = $('wh_currency');
        const cartonStockInput = $('wh_carton_stock');
        const cartonMinInput = $('wh_carton_min_stock');

        if (primarySelect) {
            primarySelect.addEventListener('change', function () {
                updateLabels();
                syncStockFromCarton();
            });
        }
        if (secondarySelect) {
            secondarySelect.addEventListener('change', function () {
                updateLabels();
                syncStockFromCarton();
            });
        }
        if (factorInput) {
            factorInput.addEventListener('input', function () {
                syncPriceFromCarton();
                syncStockFromCarton();
            });
        }
        if (currencySelect) {
            currencySelect.addEventListener('change', function () {
                updateCurrencyLabels();
                syncPriceFromCarton();
            });
        }

        document.querySelectorAll('.wh-carton-price').forEach(function (input) {
            input.addEventListener('input', syncPriceFromCarton);
        });

        if (cartonStockInput) {
            cartonStockInput.addEventListener('input', syncStockFromCarton);
        }
        if (cartonMinInput) {
            cartonMinInput.addEventListener('input', syncStockFromCarton);
        }

        const productForm = document.querySelector('form.needs-validation');
        if (productForm) {
            productForm.addEventListener('submit', function (e) {
                buildHiddenUnitFields();
                const error = validateWholesaleForm();
                if (error) {
                    e.preventDefault();
                    e.stopPropagation();
                    showWholesaleMessage(error, 'warning');
                }
            });
        }
    }

    function applyInitialData(data) {
        if (!data) {
            return;
        }

        const primary = data.primary || null;
        const secondary = data.secondary || null;

        if (primary && $('wh_primary_unit_id')) {
            $('wh_primary_unit_id').value = String(primary.unit_id || '');
        }
        if (secondary && $('wh_secondary_unit_id')) {
            $('wh_secondary_unit_id').value = String(secondary.unit_id || '');
        }

        const factor = secondary
            ? parseNum(secondary.conversion_factor || secondary.conversion_rate || secondary.conversion_ratio)
            : 0;
        if (factor > 0 && $('wh_conversion_factor')) {
            $('wh_conversion_factor').value = formatQty(factor);
        }

        const currency = (secondary && secondary.currency) || (primary && primary.currency) || 'IQD';
        if ($('wh_currency')) {
            $('wh_currency').value = currency === 'USD' ? 'USD' : 'IQD';
        }

        if (secondary) {
            PRICE_FIELDS.forEach(function (field) {
                const input = document.querySelector('.wh-carton-price[data-price-field="' + field + '"]');
                if (input && secondary[field] !== undefined && secondary[field] !== null && secondary[field] !== '') {
                    input.value = formatQty(parseNum(secondary[field]));
                }
            });

            const secStock = parseNum(secondary.stock_quantity);
            if ($('wh_carton_stock')) {
                $('wh_carton_stock').value = formatQty(secStock);
            }
            const secMin = parseNum(secondary.min_stock);
            if ($('wh_carton_min_stock')) {
                $('wh_carton_min_stock').value = formatQty(secMin);
            }
        } else if (primary) {
            const priStock = parseNum(primary.stock_quantity);
            const priFactor = factor > 0 ? factor : 1;
            if ($('wh_carton_stock')) {
                $('wh_carton_stock').value = formatQty(priStock / priFactor);
            }
            const priMin = parseNum(primary.min_stock);
            if ($('wh_carton_min_stock')) {
                $('wh_carton_min_stock').value = formatQty(priMin / priFactor);
            }
        }
    }

    function showWholesaleMessage(message, type) {
        if (typeof showMessage === 'function') {
            showMessage(message, type || 'info');
            return;
        }

        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-' + (type || 'info') + ' alert-dismissible fade show';
        alertDiv.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        const container = document.querySelector('.container');
        if (container) {
            container.insertBefore(alertDiv, container.firstChild);
        }
    }

    function updateAdditionalBarcodesSectionWholesale() {
        const barcodesSection = $('additional_barcodes_section');
        if (!barcodesSection) {
            return;
        }
        const primaryId = getPrimaryUnitId();
        const secondaryId = getSecondaryUnitId();
        if (primaryId && secondaryId && primaryId !== secondaryId) {
            barcodesSection.style.display = 'block';
        } else {
            barcodesSection.style.display = 'none';
        }
    }

    function refreshBarcodesSectionVisibility() {
        if (typeof updateAdditionalBarcodesSection === 'function') {
            updateAdditionalBarcodesSection();
            return;
        }
        updateAdditionalBarcodesSectionWholesale();
    }

    window.initWholesaleUnitsForm = function (units, data) {
        unitsCatalog = Array.isArray(units) ? units : [];
        initialData = data || null;

        updateLabels();
        updateCurrencyLabels();
        applyInitialData(initialData);
        buildHiddenUnitFields();
        bindEvents();

        const primarySelect = $('wh_primary_unit_id');
        const secondarySelect = $('wh_secondary_unit_id');
        if (primarySelect) {
            primarySelect.addEventListener('change', refreshBarcodesSectionVisibility);
        }
        if (secondarySelect) {
            secondarySelect.addEventListener('change', refreshBarcodesSectionVisibility);
        }
        refreshBarcodesSectionVisibility();
    };

    window.onDefaultUnitChanged = function () {
        /* wholesale UI uses explicit dropdowns; modal refresh handled separately if needed */
    };

    window.buildWholesaleHiddenUnitFields = buildHiddenUnitFields;
    window.validateWholesaleForm = validateWholesaleForm;
})();
