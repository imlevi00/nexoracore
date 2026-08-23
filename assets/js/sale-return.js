/**
 * Sale-linked return modal (user/pos/sales.php)
 */
(function () {
    const state = {
        saleId: null,
        context: null,
        modal: null,
        extraItems: [],
        searchTimer: null,
    };

    function getApiBase() {
        return window.SaleReturnConfig?.apiBase || '../../api/';
    }

    function getCsrfToken() {
        return window.SaleReturnConfig?.csrfToken || '';
    }

    function formatQty(value) {
        const num = parseFloat(value);
        if (Number.isNaN(num)) {
            return '0';
        }
        const normalized = num.toFixed(6).replace(/\.?0+$/, '');
        return normalized || '0';
    }

    function formatMoney(amount, currency) {
        const decimals = currency === 'USD' ? 2 : 0;
        const formatted = Number(amount || 0).toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
        return formatted + (currency === 'USD' ? ' $' : ' دینار');
    }

    function getCurrency() {
        return state.context?.sale?.currency || 'IQD';
    }

    function getDecimals(currency) {
        return (currency || getCurrency()) === 'USD' ? 2 : 0;
    }

    function paymentMethodLabel(method, hasDebtSplit) {
        if (hasDebtSplit) {
            return 'کەمکردنەوەی قەرز + گەڕاندنەوەی نەقد';
        }
        const map = {
            cash: 'نەقد (گەڕاندنەوە لە قاسە)',
            debt: 'کەمکردنەوەی قەرز',
            credit: 'کەمکردنەوەی قەرز',
            installment: 'کەمکردنەوەی قەرز',
        };
        return map[method] || method;
    }

    function getOutstandingDebt() {
        const ctx = state.context;
        if (!ctx) {
            return 0;
        }
        const customerOutstanding = parseFloat(ctx.customer_outstanding_amount) || 0;
        if (customerOutstanding > 0) {
            return customerOutstanding;
        }
        if (ctx.debt_summary?.has_debt && (ctx.debt_summary.status || '') === 'active') {
            return parseFloat(ctx.debt_summary.remaining_amount) || 0;
        }
        return 0;
    }

    function computeRefundSplit(total) {
        const outstanding = getOutstandingDebt();
        const isDebtSale = !!state.context?.is_debt_sale;
        if (!isDebtSale || outstanding <= 0) {
            return { debtPortion: 0, cashPortion: total, hasDebtSplit: false };
        }
        const debtPortion = Math.min(total, outstanding);
        const cashPortion = Math.max(0, total - debtPortion);
        return {
            debtPortion,
            cashPortion,
            hasDebtSplit: debtPortion > 0 && cashPortion > 0,
        };
    }

    function showError(message) {
        const el = document.getElementById('saleReturnError');
        if (!el) {
            return;
        }
        el.textContent = message;
        el.classList.remove('d-none');
    }

    function hideError() {
        const el = document.getElementById('saleReturnError');
        if (el) {
            el.classList.add('d-none');
            el.textContent = '';
        }
    }

    function setLoading(isLoading) {
        document.getElementById('saleReturnLoading')?.classList.toggle('d-none', !isLoading);
        document.getElementById('saleReturnContent')?.classList.toggle('d-none', isLoading);
    }

    function renderSaleInfo(sale, debtSummary) {
        const info = document.getElementById('saleReturnSaleInfo');
        if (!info) {
            return;
        }
        const currency = sale.currency || 'IQD';
        let html = `<strong>وەسڵ #${sale.id}</strong>`;
        if (sale.customer_name) {
            html += ` — ${escapeHtml(sale.customer_name)}`;
        }
        html += `<br><small class="text-muted">کۆی وەسڵ: ${formatMoney(sale.final_amount, currency)}</small>`;
        if (debtSummary?.has_debt) {
            html += `<br><small class="text-warning">قەرزی ماوەی ئەم وەسڵە: ${formatMoney(debtSummary.remaining_amount, currency)}</small>`;
        }
        const customerOutstanding = parseFloat(state.context?.customer_outstanding_amount) || 0;
        if (customerOutstanding > 0 && state.context?.is_debt_sale) {
            html += `<br><small class="text-info">کۆی قەرزی ماوەی کڕیار: ${formatMoney(customerOutstanding, currency)}</small>`;
        }
        info.innerHTML = html;
    }

    function renderPriorReturns(priorReturns, currency) {
        const wrap = document.getElementById('saleReturnPriorWrap');
        const body = document.getElementById('saleReturnPriorBody');
        if (!wrap || !body) {
            return;
        }
        if (!priorReturns || priorReturns.length === 0) {
            wrap.classList.add('d-none');
            body.innerHTML = '';
            return;
        }
        wrap.classList.remove('d-none');
        body.innerHTML = priorReturns.map((row) => {
            const date = row.return_date ? String(row.return_date).substring(0, 16) : '';
            return `<tr>
                <td>${escapeHtml(row.return_number || '')}</td>
                <td>${escapeHtml(date)}</td>
                <td>${formatMoney(row.final_amount, currency)}</td>
            </tr>`;
        }).join('');
    }

    function renderWallets(wallets, suggestedId) {
        const wrap = document.getElementById('saleReturnWalletWrap');
        const select = document.getElementById('saleReturnWalletId');
        if (!wrap || !select) {
            return;
        }
        select.innerHTML = (wallets || []).map((w) => {
            const selected = Number(w.id) === Number(suggestedId) ? ' selected' : '';
            return `<option value="${w.id}"${selected}>${escapeHtml(w.name)}</option>`;
        }).join('');
    }

    function renderItems(items, currency) {
        const body = document.getElementById('saleReturnItemsBody');
        if (!body) {
            return;
        }
        body.innerHTML = items.map((item) => {
            const returned = parseFloat(item.returned_qty) || 0;
            const returnable = parseFloat(item.returnable_qty) || 0;
            const sold = parseFloat(item.quantity_sold) || 0;
            const rowClass = returned > 0 ? 'table-warning' : '';
            const disabled = returnable <= 0 || item.is_external ? 'disabled' : '';
            const unitLabel = item.unit_symbol || item.unit_name || '';
            return `<tr class="${rowClass}" data-sale-item-id="${item.sale_item_id}">
                <td>
                    ${escapeHtml(item.product_name)}
                    ${item.is_external ? '<br><small class="text-muted">کاڵای دەرەکی</small>' : ''}
                </td>
                <td class="text-center">${formatQty(sold)} ${escapeHtml(unitLabel)}</td>
                <td class="text-center ${returned > 0 ? 'text-danger fw-semibold' : ''}">${formatQty(returned)}</td>
                <td class="text-center">${formatQty(returnable)}</td>
                <td class="text-center">
                    <input type="number" class="form-control form-control-sm sale-return-qty text-center"
                           min="0" max="${returnable}" step="any" value="0"
                           data-unit-price="${item.unit_price}" ${disabled}>
                    <small class="text-muted d-block mt-1">نرخ/یەک: ${formatMoney(item.unit_price, currency)}</small>
                </td>
                <td class="text-end sale-return-line-total">0</td>
            </tr>`;
        }).join('');

        body.querySelectorAll('.sale-return-qty').forEach((input) => {
            input.addEventListener('input', updateTotals);
        });
    }

    function extraItemKey(productId, unitId) {
        return `${productId}_${unitId || 0}`;
    }

    function renderExtraItems() {
        const wrap = document.getElementById('saleReturnExtraWrap');
        const body = document.getElementById('saleReturnExtraBody');
        if (!wrap || !body) {
            return;
        }

        if (!state.extraItems.length) {
            wrap.classList.add('d-none');
            body.innerHTML = '';
            return;
        }

        wrap.classList.remove('d-none');
        const currency = getCurrency();
        const decimals = getDecimals(currency);

        body.innerHTML = state.extraItems.map((item) => {
            const key = extraItemKey(item.product_id, item.unit_id);
            const unitLabel = item.unit_symbol || item.unit_name || '';
            return `<tr data-extra-key="${escapeHtml(key)}">
                <td>
                    ${escapeHtml(item.product_name)}
                    <br><small class="text-muted">کاڵای زیادکراو</small>
                </td>
                <td class="text-center">${escapeHtml(unitLabel)}</td>
                <td class="text-center">
                    <input type="number" class="form-control form-control-sm sale-return-extra-qty text-center"
                           min="0.000001" step="any" value="${formatQty(item.quantity)}"
                           data-extra-key="${escapeHtml(key)}">
                </td>
                <td class="text-center">
                    <input type="number" class="form-control form-control-sm sale-return-extra-price text-center"
                           min="0" step="${currency === 'USD' ? '0.01' : '1'}"
                           value="${Number(item.unit_price).toFixed(decimals)}"
                           data-extra-key="${escapeHtml(key)}">
                </td>
                <td class="text-end sale-return-extra-line-total">0</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger sale-return-extra-remove"
                            data-extra-key="${escapeHtml(key)}" title="سڕینەوە">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');

        body.querySelectorAll('.sale-return-extra-qty').forEach((input) => {
            input.addEventListener('input', onExtraFieldChange);
        });
        body.querySelectorAll('.sale-return-extra-price').forEach((input) => {
            input.addEventListener('input', onExtraFieldChange);
        });
        body.querySelectorAll('.sale-return-extra-remove').forEach((btn) => {
            btn.addEventListener('click', () => removeExtraItem(btn.dataset.extraKey));
        });
    }

    function onExtraFieldChange(event) {
        const key = event.target.dataset.extraKey;
        const item = state.extraItems.find((i) => extraItemKey(i.product_id, i.unit_id) === key);
        if (!item) {
            return;
        }
        if (event.target.classList.contains('sale-return-extra-qty')) {
            item.quantity = parseFloat(event.target.value) || 0;
        } else {
            item.unit_price = parseFloat(event.target.value) || 0;
        }
        updateTotals();
    }

    function removeExtraItem(key) {
        state.extraItems = state.extraItems.filter((i) => extraItemKey(i.product_id, i.unit_id) !== key);
        renderExtraItems();
        updateTotals();
    }

    function addExtraProduct(product, unit) {
        const currency = getCurrency();
        const unitCurrency = unit?.currency || product.currency || 'IQD';
        if (unitCurrency !== currency) {
            showError(`ئەم کاڵایە بە ${unitCurrency} ـە، بەڵام وەسڵەکە ${currency} ـە`);
            return;
        }

        const unitId = unit?.unit_id || null;
        const key = extraItemKey(product.id, unitId);
        const existing = state.extraItems.find((i) => extraItemKey(i.product_id, i.unit_id) === key);
        if (existing) {
            existing.quantity = (parseFloat(existing.quantity) || 0) + 1;
        } else {
            const sellPrice = parseFloat(unit?.sell_price ?? product.sell_price) || 0;
            state.extraItems.push({
                product_id: product.id,
                product_name: product.name,
                unit_id: unitId,
                unit_name: unit?.unit_name || 'دانە',
                unit_symbol: unit?.unit_symbol || '',
                price_type: 'retail',
                quantity: 1,
                unit_price: sellPrice,
            });
        }

        hideError();
        clearProductSearch();
        renderExtraItems();
        updateTotals();
    }

    function clearProductSearch() {
        const input = document.getElementById('saleReturnProductSearch');
        const results = document.getElementById('saleReturnSearchResults');
        if (input) {
            input.value = '';
        }
        if (results) {
            results.classList.add('d-none');
            results.innerHTML = '';
        }
    }

    function hideSearchResults() {
        document.getElementById('saleReturnSearchResults')?.classList.add('d-none');
    }

    function renderSearchResults(products) {
        const results = document.getElementById('saleReturnSearchResults');
        if (!results) {
            return;
        }
        if (!products.length) {
            results.innerHTML = '<div class="list-group-item text-muted small">هیچ کاڵایەک نەدۆزرایەوە</div>';
            results.classList.remove('d-none');
            return;
        }

        results.innerHTML = products.map((product) => {
            const units = product.units || [];
            if (units.length <= 1) {
                const unit = units[0] || {};
                const price = parseFloat(unit.sell_price ?? product.sell_price) || 0;
                const unitLabel = unit.unit_symbol || unit.unit_name || '';
                return `<button type="button" class="list-group-item list-group-item-action sale-return-search-pick py-2"
                        data-product-id="${product.id}" data-unit-id="${unit.unit_id || ''}">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>${escapeHtml(product.name)}</span>
                        <small class="text-muted">${formatMoney(price, getCurrency())} ${escapeHtml(unitLabel)}</small>
                    </div>
                </button>`;
            }
            return units.map((unit) => {
                const price = parseFloat(unit.sell_price) || 0;
                const unitLabel = unit.unit_symbol || unit.unit_name || '';
                return `<button type="button" class="list-group-item list-group-item-action sale-return-search-pick py-2"
                        data-product-id="${product.id}" data-unit-id="${unit.unit_id || ''}">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>${escapeHtml(product.name)} <small class="text-muted">(${escapeHtml(unitLabel)})</small></span>
                        <small class="text-muted">${formatMoney(price, getCurrency())}</small>
                    </div>
                </button>`;
            }).join('');
        }).join('');

        results.classList.remove('d-none');
        results.querySelectorAll('.sale-return-search-pick').forEach((btn) => {
            btn.addEventListener('click', () => {
                const productId = parseInt(btn.dataset.productId, 10);
                const unitId = btn.dataset.unitId ? parseInt(btn.dataset.unitId, 10) : null;
                const product = products.find((p) => Number(p.id) === productId);
                if (!product) {
                    return;
                }
                const unit = (product.units || []).find((u) => Number(u.unit_id) === Number(unitId))
                    || (product.units || [])[0]
                    || null;
                addExtraProduct(product, unit);
            });
        });
    }

    function renderSearchMessage(message, isError) {
        const results = document.getElementById('saleReturnSearchResults');
        if (!results) {
            return;
        }
        const cls = isError ? 'text-danger' : 'text-muted';
        results.innerHTML = `<div class="list-group-item ${cls} small">${escapeHtml(message)}</div>`;
        results.classList.remove('d-none');
    }

    async function searchProducts(query) {
        if (!query || query.length < 2) {
            hideSearchResults();
            return;
        }
        try {
            const url = `${getApiBase()}products.php?action=search&q=${encodeURIComponent(query)}&include_zero_stock=1&limit=15`;
            const response = await fetch(url);
            let data = null;
            try {
                data = await response.json();
            } catch (parseErr) {
                renderSearchMessage('وەڵامی سێرڤەر نادروست بوو', true);
                return;
            }
            if (!response.ok || !data.success) {
                if (response.status === 403) {
                    renderSearchMessage('دەسەڵاتت نییە بۆ گەڕانی کاڵا', true);
                } else {
                    renderSearchMessage(data?.message || 'نەتوانرا گەڕان بکرێت', true);
                }
                return;
            }
            const products = data.data?.products || [];
            renderSearchResults(products);
        } catch (err) {
            console.error('Product search failed', err);
            renderSearchMessage('هەڵە لە پەیوەندی بە سێرڤەرەوە', true);
        }
    }

    function onProductSearchInput(event) {
        const query = event.target.value.trim();
        clearTimeout(state.searchTimer);
        state.searchTimer = setTimeout(() => searchProducts(query), 300);
    }

    function updateTotals() {
        const currency = getCurrency();
        const decimals = getDecimals(currency);
        let total = 0;
        let hasQty = false;

        document.querySelectorAll('#saleReturnItemsBody tr').forEach((row) => {
            const input = row.querySelector('.sale-return-qty');
            const lineEl = row.querySelector('.sale-return-line-total');
            if (!input || !lineEl) {
                return;
            }
            const qty = parseFloat(input.value) || 0;
            const unitPrice = parseFloat(input.dataset.unitPrice) || 0;
            const lineTotal = qty * unitPrice;
            if (qty > 0) {
                hasQty = true;
            }
            total += lineTotal;
            lineEl.textContent = formatMoney(lineTotal, currency);
        });

        state.extraItems.forEach((item) => {
            const qty = parseFloat(item.quantity) || 0;
            const unitPrice = parseFloat(item.unit_price) || 0;
            const lineTotal = qty * unitPrice;
            if (qty > 0) {
                hasQty = true;
            }
            total += lineTotal;
            const key = extraItemKey(item.product_id, item.unit_id);
            const lineEl = document.querySelector(`.sale-return-extra-line-total[data-extra-key="${key}"]`)
                || document.querySelector(`tr[data-extra-key="${key}"] .sale-return-extra-line-total`);
            if (lineEl) {
                lineEl.textContent = formatMoney(lineTotal, currency);
            }
        });

        total = Number(total.toFixed(decimals));
        const split = computeRefundSplit(total);

        const totalEl = document.getElementById('saleReturnTotal');
        if (totalEl) {
            totalEl.value = formatMoney(total, currency);
        }

        const summaryTotal = document.getElementById('saleReturnSummaryTotal');
        const summaryDebt = document.getElementById('saleReturnSummaryDebt');
        const summaryCash = document.getElementById('saleReturnSummaryCash');
        const debtWrap = document.getElementById('saleReturnSummaryDebtWrap');
        const cashWrap = document.getElementById('saleReturnSummaryCashWrap');

        if (summaryTotal) {
            summaryTotal.textContent = formatMoney(total, currency);
        }
        if (summaryDebt) {
            summaryDebt.textContent = formatMoney(split.debtPortion, currency);
        }
        if (summaryCash) {
            summaryCash.textContent = formatMoney(split.cashPortion, currency);
        }
        if (debtWrap) {
            debtWrap.classList.toggle('d-none', split.debtPortion <= 0);
        }
        if (cashWrap) {
            cashWrap.classList.toggle('d-none', split.cashPortion <= 0);
        }

        const paymentInput = document.getElementById('saleReturnPaymentMethod');
        if (paymentInput) {
            const method = state.context?.suggested_return_payment_method || 'cash';
            paymentInput.value = paymentMethodLabel(method, split.hasDebtSplit);
        }

        const walletWrap = document.getElementById('saleReturnWalletWrap');
        const walletHint = document.getElementById('saleReturnWalletHint');
        if (walletWrap) {
            const needsWallet = split.cashPortion > 0;
            walletWrap.classList.toggle('opacity-50', !needsWallet);
            if (walletHint) {
                walletHint.textContent = needsWallet ? '' : '(پێویست نییە)';
            }
        }

        const submitBtn = document.getElementById('saleReturnSubmitBtn');
        if (submitBtn) {
            const validExtra = state.extraItems.every((i) => (parseFloat(i.quantity) || 0) > 0);
            submitBtn.disabled = !hasQty || !validExtra;
        }
    }

    function escapeHtml(str) {
        if (str == null) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function loadContext(saleId) {
        hideError();
        setLoading(true);
        state.extraItems = [];
        clearProductSearch();
        const response = await fetch(`${getApiBase()}sales.php?action=return_context&id=${saleId}&_t=${Date.now()}`);
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'نەتوانرا داتای وەسڵ بار بکرێت');
        }
        state.context = data.data;
        setLoading(false);
        renderContext();
    }

    function renderContext() {
        const ctx = state.context;
        if (!ctx) {
            return;
        }
        const sale = ctx.sale;
        const currency = sale.currency || 'IQD';

        renderSaleInfo(sale, ctx.debt_summary);
        renderItems(ctx.items || [], currency);
        renderExtraItems();
        renderPriorReturns(ctx.prior_returns || [], currency);
        renderWallets(ctx.wallets || [], ctx.suggested_wallet_id);

        updateTotals();
    }

    function collectPayload() {
        const ctx = state.context;
        const sale = ctx.sale;
        const currency = sale.currency || 'IQD';
        const decimals = getDecimals(currency);
        const items = [];

        document.querySelectorAll('#saleReturnItemsBody tr').forEach((row) => {
            const saleItemId = parseInt(row.dataset.saleItemId, 10);
            const input = row.querySelector('.sale-return-qty');
            const qty = parseFloat(input?.value) || 0;
            if (qty <= 0) {
                return;
            }
            const ctxItem = (ctx.items || []).find((i) => Number(i.sale_item_id) === saleItemId);
            if (!ctxItem) {
                return;
            }
            const unitPrice = parseFloat(ctxItem.unit_price) || 0;
            items.push({
                sale_item_id: saleItemId,
                product_id: ctxItem.product_id,
                product_name: ctxItem.product_name,
                quantity: qty,
                unit_price: unitPrice,
                total_price: Number((qty * unitPrice).toFixed(decimals)),
                price_type: ctxItem.price_type || 'retail',
                unit_id: ctxItem.unit_id,
                unit_name: ctxItem.unit_name || 'دانە',
                unit_symbol: ctxItem.unit_symbol || '',
            });
        });

        state.extraItems.forEach((item) => {
            const qty = parseFloat(item.quantity) || 0;
            if (qty <= 0) {
                return;
            }
            const unitPrice = parseFloat(item.unit_price) || 0;
            items.push({
                sale_item_id: null,
                product_id: item.product_id,
                product_name: item.product_name,
                quantity: qty,
                unit_price: unitPrice,
                total_price: Number((qty * unitPrice).toFixed(decimals)),
                price_type: item.price_type || 'retail',
                unit_id: item.unit_id,
                unit_name: item.unit_name || 'دانە',
                unit_symbol: item.unit_symbol || '',
            });
        });

        let totalAmount = items.reduce((sum, item) => sum + item.total_price, 0);
        totalAmount = Number(totalAmount.toFixed(decimals));

        const split = computeRefundSplit(totalAmount);
        const walletId = parseInt(document.getElementById('saleReturnWalletId')?.value, 10)
            || ctx.suggested_wallet_id
            || 0;

        const payload = {
            sale_id: state.saleId,
            customer_id: sale.customer_id || null,
            customer_name: sale.customer_name || null,
            payment_method: ctx.suggested_return_payment_method || 'cash',
            currency,
            total_amount: totalAmount,
            discount: 0,
            final_amount: totalAmount,
            return_reason: document.getElementById('saleReturnReason')?.value?.trim() || null,
            items,
            csrf_token: getCsrfToken(),
            wallet_id: walletId,
        };

        if (split.cashPortion > 0 && walletId <= 0) {
            throw new Error('تکایە قاسەی گەڕاندنەوەی نەقد هەڵبژێرە');
        }

        return payload;
    }

    async function submitSaleReturn() {
        if (!state.saleId || !state.context) {
            return;
        }
        hideError();
        let payload;
        try {
            payload = collectPayload();
        } catch (err) {
            showError(err.message || 'هەڵە لە ئامادەکردنی داتا');
            return;
        }
        if (!payload.items.length) {
            showError('تکایە لانیکەم یەک کاڵا بۆ گەڕاندنەوە دیاری بکە');
            return;
        }
        if (!confirm('ئایا دڵنیایت لە تۆمارکردنی ئەم گەڕاندنەوەیە؟')) {
            return;
        }

        const submitBtn = document.getElementById('saleReturnSubmitBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
        }

        try {
            const response = await fetch(`${getApiBase()}returns.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'هەڵە لە تۆمارکردنی گەڕاندنەوە');
            }
            if (typeof window.showNotification === 'function') {
                window.showNotification('گەڕاندنەوە بە سەرکەوتوویی تۆمار کرا', 'success');
            } else {
                alert('گەڕاندنەوە بە سەرکەوتوویی تۆمار کرا');
            }
            state.modal?.hide();
            window.location.reload();
        } catch (error) {
            showError(error.message || 'هەڵەیەک ڕوویدا');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                updateTotals();
            }
        }
    }

    window.openSaleReturnModal = function (saleId) {
        state.saleId = parseInt(saleId, 10);
        if (!state.saleId) {
            return;
        }
        hideError();
        state.extraItems = [];
        const modalEl = document.getElementById('saleReturnModal');
        if (!modalEl) {
            return;
        }
        state.modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        state.modal.show();
        loadContext(state.saleId).catch((err) => {
            setLoading(false);
            showError(err.message || 'هەڵە لە بارکردن');
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('saleReturnSubmitBtn')?.addEventListener('click', submitSaleReturn);
        document.getElementById('saleReturnProductSearch')?.addEventListener('input', onProductSearchInput);
        document.addEventListener('click', (event) => {
            const searchInput = document.getElementById('saleReturnProductSearch');
            const results = document.getElementById('saleReturnSearchResults');
            if (!searchInput || !results) {
                return;
            }
            if (!searchInput.contains(event.target) && !results.contains(event.target)) {
                hideSearchResults();
            }
        });
    });
})();
