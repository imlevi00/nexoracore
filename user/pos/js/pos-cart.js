        // ===== CART MANAGEMENT =====
        async function fetchProductAndAddToCart(productId, unitIndex = null, quantity = 1, productUnitId = null) {
            try {
                const response = await posFetch(`../../api/products.php?action=get&id=${productId}&user_id=${POS.userId}`);
                const data = await response.json();

                if (data.success && data.data.product) {
                    const product = normalizeProductUnits(data.data.product);
                    upsertProduct(product);
                    let resolvedUnitIndex;
                    if (productUnitId !== null && productUnitId !== undefined) {
                        // یەکە بەپێی ناسنامەی یەکەی کاڵا (product_unit_id) دیاری دەکرێت
                        resolvedUnitIndex = getUnitIndexById(product, productUnitId);
                    } else if (unitIndex !== null && unitIndex !== undefined) {
                        resolvedUnitIndex = unitIndex;
                    } else {
                        resolvedUnitIndex = getPrimaryUnitIndex(product);
                    }
                    const qty = Number(quantity) > 0 ? Number(quantity) : 1;
                    addProductToCart(product, qty, resolvedUnitIndex);
                } else {
                    showNotification('کاڵا نەدۆزرایەوە', 'danger');
                }
            } catch (error) {
                showNotification('هەڵەیەک ڕوویدا لە گەڕانی کاڵا', 'danger');
            }
        }
        
        function addProductToCart(product, quantity = 1, selectedUnitIndex = null) {
            // Add product directly to cart without lookup
            if (!product) {
                showNotification('کاڵا نەدۆزرایەوە', 'danger');
                return;
            }

            const normalized = normalizeProductUnits({ ...product });
            upsertProduct(normalized);
            const resolvedUnitIndex = selectedUnitIndex !== null && selectedUnitIndex !== undefined
                ? selectedUnitIndex
                : getPrimaryUnitIndex(normalized);
            addToCart(normalized.id, quantity, resolvedUnitIndex);
        }
        
        function addToCart(productId, quantity = 1, selectedUnitIndex = null) {
            // Find product by ID - convert both to numbers for comparison
            const product = POS.products.find(p => Number(p.id) === Number(productId));
            
            if (!product) {
  
                showNotification('کاڵا نەدۆزرایەوە', 'danger');
                return;
            }

            let resolvedUnitIndex = selectedUnitIndex;
            if (resolvedUnitIndex === null || resolvedUnitIndex === undefined) {
                const cardIndex = getSelectedUnitIndexFromCard(productId);
                resolvedUnitIndex = cardIndex !== null ? cardIndex : getPrimaryUnitIndex(product);
            }
            
            // Determine which data to use - units or main product
            let productData = product;
            let unitData = null;
            
            if (product.units && product.units.length > 0) {
                // Use the selected unit (default to primary unit)
                unitData = product.units[resolvedUnitIndex] || product.units[getPrimaryUnitIndex(product)];
                productData = {
                    ...product,
                    buy_price: unitData.buy_price,
                    sell_price: unitData.sell_price,
                    wholesale_price: unitData.wholesale_price,
                    special_price: unitData.special_price,
                    stock_quantity: unitData.stock_quantity,
                    min_stock: unitData.min_stock,
                    currency: unitData.currency || product.currency || 'IQD'
                };
            }
            
            // Check if product is expired
            if (isProductExpired(product)) {
                showNotification('ئەم کاڵایە بەردەست نییە', 'warning');
                return;
            }
            
            // Determine price based on current price type with fallback to retail and convert currency
            let price = getConvertedPrice(productData, POS.currentPriceType);
            let priceType = 'retail';
            
            if (POS.currentPriceType === 'wholesale' && productData.wholesale_price && productData.wholesale_price > 0) {
                priceType = 'wholesale';
            } else if (POS.currentPriceType === 'special' && productData.special_price && productData.special_price > 0) {
                priceType = 'special';
            } else if (POS.currentPriceType !== 'retail') {
                // If wholesale or special price is not available, use retail price
                showNotification(`نرخی ${POS.currentPriceType === 'wholesale' ? 'جوملە' : 'تایبەت'} بۆ ${product.name} بەردەست نییە، نرخی تاک بەکارهێنراوە`, 'info');
            }
            
            // Check existing item (check by actual price type used, not selected type)
            const existingIndex = POS.cart.findIndex(item => 
                item.id === product.id && 
                item.price_type === priceType && 
                item.unit_id === (unitData ? unitData.unit_id : null)
            );
            
            if (existingIndex >= 0) {
                const newQuantity = POS.cart[existingIndex].quantity + quantity;
                
                POS.cart[existingIndex].quantity = newQuantity;
                POS.cart[existingIndex].total = roundPriceByCurrency(POS.cart[existingIndex].quantity * POS.cart[existingIndex].price, POS.currentCurrency);
                POS.cart[existingIndex].stock = parseFloat(productData.stock_quantity);
            } else {
                const roundedPrice = roundPriceByCurrency(price, POS.currentCurrency);
                POS.cart.push({
                    id: product.id,
                    name: product.name,
                    barcode: product.barcode || '',
                    price: roundedPrice,
                    buy_price: getConvertedBuyPrice(productData),
                    wholesale_price: parseFloat(productData.wholesale_price) || 0,
                    special_price: parseFloat(productData.special_price) || 0,
                    quantity: quantity,
                    total: roundPriceByCurrency(quantity * roundedPrice, POS.currentCurrency),
                    stock: parseFloat(productData.stock_quantity),
                    price_type: priceType, // Use actual price type applied
                    selected_price_type: POS.currentPriceType, // Keep track of what user selected
                    unit_id: unitData ? unitData.unit_id : null,
                    unit_name: unitData ? unitData.unit_name : 'دانە',
                    unit_symbol: unitData ? unitData.unit_symbol : '',
                    unit_options: product.units && product.units.length > 1 ? product.units : null,
                    currency: POS.currentCurrency || 'IQD' // Add current currency to cart item
                });
            }
            
            updateCartDisplay();
            saveCartToStorage();
            showNotification(`${product.name} زیادکرا بۆ سەبەتە`, 'success');
        }

        function getRiskLevelText(riskLevel) {
            if (riskLevel === 'high') return 'زۆر';
            if (riskLevel === 'low') return 'کەم';
            return 'ناوەند';
        }

        function getCartProductMeta() {
            const meta = {};
            POS.cart.forEach(item => {
                const id = Number(item.id);
                if (Number.isFinite(id) && id > 0 && !item.isExternal) {
                    meta[id] = {
                        name: item.name || `#${id}`,
                        barcode: item.barcode || ''
                    };
                }
            });
            return meta;
        }

        async function fetchCartInteractionConflicts() {
            if (!POS.drugInteractionWarningEnabled) {
                return [];
            }

            const productIds = Array.from(new Set(
                POS.cart
                    .filter(item => !item.isExternal)
                    .map(item => Number(item.id))
                    .filter(id => Number.isFinite(id) && id > 0)
            ));

            if (productIds.length < 2) {
                return [];
            }

            const response = await fetch(`../products/api/drug_interactions.php?action=find_conflicts&product_ids=${encodeURIComponent(productIds.join(','))}`, {
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!payload.success || !Array.isArray(payload.data)) {
                return [];
            }

            const productMeta = getCartProductMeta();
            return payload.data.map(conflict => {
                const p1 = Number(conflict.product_id_1);
                const p2 = Number(conflict.product_id_2);
                return {
                    ...conflict,
                    product_id_1: p1,
                    product_id_2: p2,
                    product_name_1: productMeta[p1]?.name || `#${p1}`,
                    product_name_2: productMeta[p2]?.name || `#${p2}`,
                    barcode_1: productMeta[p1]?.barcode || '',
                    barcode_2: productMeta[p2]?.barcode || ''
                };
            });
        }

        function refreshRiskAlertIndicator() {
            const btn = document.getElementById('riskAlertBtn');
            const count = document.getElementById('riskAlertCount');
            if (!btn || !count) return;

            const total = POS.currentInteractionConflicts.length;
            count.textContent = total;
            if (total > 0) {
                btn.classList.add('show');
            } else {
                btn.classList.remove('show');
            }
        }

        function triggerCartInteractionScan() {
            if (POS.riskScanTimer) {
                clearTimeout(POS.riskScanTimer);
            }

            POS.riskScanTimer = setTimeout(async () => {
                try {
                    const conflicts = await fetchCartInteractionConflicts();
                    POS.currentInteractionConflicts = conflicts;
                    refreshRiskAlertIndicator();

                    const nextKeys = new Set();
                    conflicts.forEach(conflict => {
                        const key = `${Math.min(conflict.product_id_1, conflict.product_id_2)}-${Math.max(conflict.product_id_1, conflict.product_id_2)}`;
                        nextKeys.add(key);
                        if (!POS.knownConflictKeys.has(key)) {
                            const noteText = conflict.note ? ` | تێبینی: ${conflict.note}` : '';
                            showNotification(`ئاگاداری دەرمانی نەگونجاو (${getRiskLevelText(conflict.risk_level)})${noteText}`, 'warning');
                        }
                    });
                    POS.knownConflictKeys = nextKeys;
                } catch (error) {
                    POS.currentInteractionConflicts = [];
                    POS.knownConflictKeys = new Set();
                    refreshRiskAlertIndicator();
                }
            }, 180);
        }

        function showRiskDetailsModal() {
            const modalBody = document.getElementById('riskDetailsModalBody');
            if (!modalBody) {
                return;
            }

            if (!POS.currentInteractionConflicts.length) {
                modalBody.innerHTML = '<div class="alert alert-success mb-0">هیچ نەگونجاویەک لە سەبەتەدا نییە.</div>';
            } else {
                modalBody.innerHTML = POS.currentInteractionConflicts.map((conflict, idx) => {
                    const note = conflict.note ? escapeHtml(conflict.note) : 'تێبینی نییە';
                    return `
                        <div class="border rounded p-3 mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong>${idx + 1}) ${escapeHtml(conflict.product_name_1)} + ${escapeHtml(conflict.product_name_2)}</strong>
                                <span class="badge bg-danger-subtle text-danger-emphasis">${getRiskLevelText(conflict.risk_level)}</span>
                            </div>
                            <div class="small text-muted mb-1">بارکۆد: ${escapeHtml(conflict.barcode_1 || '-')} / ${escapeHtml(conflict.barcode_2 || '-')}</div>
                            <div>${note}</div>
                        </div>
                    `;
                }).join('');
            }

            const modalEl = document.getElementById('riskDetailsModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
        }

        function showRiskConfirmCard(conflicts) {
            return new Promise((resolve) => {
                const old = document.getElementById('riskConfirmOverlay');
                if (old) {
                    old.remove();
                }

                const list = conflicts.slice(0, 6).map((conflict, idx) => {
                    const note = conflict.note ? escapeHtml(conflict.note) : 'تێبینی نییە';
                    return `
                        <li class="mb-1">
                            ${idx + 1}) ${escapeHtml(conflict.product_name_1)} + ${escapeHtml(conflict.product_name_2)}
                            <span class="badge bg-danger-subtle text-danger-emphasis ms-1">${getRiskLevelText(conflict.risk_level)}</span>
                            <div class="small text-muted mt-1">تێبینی: ${note}</div>
                        </li>
                    `;
                }).join('');

                const extraCount = conflicts.length > 6 ? `<div class="small text-muted mt-2">... ${conflicts.length - 6} نەگونجاوی تر هەیە</div>` : '';

                const html = `
                    <div class="risk-confirm-overlay" id="riskConfirmOverlay">
                        <div class="risk-confirm-card">
                            <div class="p-3 border-bottom">
                                <h5 class="mb-1"><i class="bi bi-exclamation-octagon-fill text-danger"></i> ئاگاداری پێش فرۆشتن</h5>
                                <p class="mb-0 text-muted">لە سەبەتەکەدا کاڵای نەگونجاو هەیە. دەتەوێت فرۆشتنەکە بەردەوام بێت؟</p>
                            </div>
                            <div class="p-3">
                                <ul class="mb-0">${list}</ul>
                                ${extraCount}
                            </div>
                            <div class="p-3 border-top d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-secondary" id="riskCancelBtn">هەڵوەشاندنەوە</button>
                                <button type="button" class="btn btn-danger" id="riskProceedBtn">بەردەوامبوون لە فرۆشتن</button>
                            </div>
                        </div>
                    </div>
                `;

                document.body.insertAdjacentHTML('beforeend', html);
                const overlay = document.getElementById('riskConfirmOverlay');
                const cleanup = (result) => {
                    if (overlay) {
                        overlay.remove();
                    }
                    resolve(result);
                };

                document.getElementById('riskCancelBtn')?.addEventListener('click', () => cleanup(false));
                document.getElementById('riskProceedBtn')?.addEventListener('click', () => cleanup(true));
            });
        }

        function updateCartDisplay() {
            const cartTableBody = document.getElementById('cartTableBody');
            const cartCount = document.getElementById('cartCount');
            const checkoutBtn = document.getElementById('checkoutBtn');
            
            const totalItems = POS.cart.reduce((sum, item) => sum + item.quantity, 0);
            cartCount.textContent = totalItems;
            
            if (POS.cart.length === 0) {
                cartTableBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-cart-x display-4 mb-3"></i>
                            <p>سەبەتە بەتاڵە</p>
                            <p class="small">کاڵاکان زیاد بکە</p>
                        </td>
                    </tr>
                `;
                checkoutBtn.disabled = true;
            } else {
                cartTableBody.innerHTML = POS.cart.map((item, index) => createCartTableRow(item, index)).join('');
                checkoutBtn.disabled = false;
            }
            
            updateTotalDisplay();
            triggerCartInteractionScan();
            
            // Update tabs display to show item counts
            updateTabsDisplay();
        }

        function createCartTableRow(item, index) {
            const priceTypeNames = {
                'retail': 'تاک',
                'wholesale': 'جوملە',
                'special': 'تایبەت'
            };
            
            const priceTypeBadges = {
                'retail': 'bg-primary',
                'wholesale': 'bg-info',
                'special': 'bg-warning'
            };
            
            return `
                <tr class="slide-up" style="background-color: ${((document.documentElement.getAttribute('data-bs-theme') || 'light') === 'dark') ? (index % 2 === 0 ? '#111827' : '#1f2937') : (index % 2 === 0 ? '#ffffff' : '#f5f5f5')};">
                    <td>${index + 1}</td>
                    <td class="text-end">
                        <div>
                            <strong>${item.name}</strong>
                            <br><span class="badge ${priceTypeBadges[item.price_type] || 'bg-secondary'} badge-sm">${priceTypeNames[item.price_type] || 'تاک'}</span>
                        </div>
                    </td>
                    <td class="text-center">
                        <small class="text-muted">${item.barcode || '-'}</small>
                    </td>
                    <td class="text-center">
                        ${item.unit_options && item.unit_options.length > 1 ? 
                            `<select class="form-select form-select-sm unit-dropdown" 
                                     onchange="changeCartItemUnit(${index}, this.value)"
                                     style="min-width: 80px;">
                                ${item.unit_options.map(unit => 
                                    `<option value="${unit.unit_id}" ${unit.unit_id === item.unit_id ? 'selected' : ''}>
                                        ${unit.unit_name} ${unit.unit_symbol ? `(${unit.unit_symbol})` : ''}
                                    </option>`
                                ).join('')}
                            </select>` :
                            `<span class="text-muted">${item.unit_name || 'دانە'}</span>`
                        }
                    </td>
                    <td>
                        ${POS.canEditPrice !== false ? `
                        <input type="number" class="form-control form-control-sm price-input numpad-target"
                               value="${item.price}"
                               min="0" step="0.01"
                               data-index="${index}"
                               onchange="updatePrice(${index}, this.value, this)"
                               onblur="updatePrice(${index}, this.value, this)"
                               onfocus="selectAllInputValue(this)"
                               onclick="selectAllInputValue(this); openNumPad(this, 'گۆڕینی نرخ')"
                               style="min-width: 80px; text-align: center;">
                        ` : `
                        <input type="number" class="form-control form-control-sm price-input"
                               value="${item.price}" readonly tabindex="-1"
                               style="min-width: 80px; text-align: center; background-color: #e9ecef; cursor: not-allowed;">
                        `}
                    </td>
                    <td>
                        <div class="quantity-controls">
                            <button class="quantity-btn" onclick="updateQuantity(${index}, -1)">
                                <i class="bi bi-dash"></i>
                            </button>
                            <input type="number" class="quantity-input numpad-target" value="${item.quantity}" 
                                   min="0.01" step="0.01"
                                   onclick="openNumPad(this, 'گۆڕینی بڕ')"
                                   onchange="updateQuantityInput(${index}, this.value)">
                            <button class="quantity-btn" onclick="updateQuantity(${index}, 1)">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </td>
                    <td>
                        ${POS.canEditTotal !== false ? `
                        <input type="number" class="form-control form-control-sm total-input numpad-target"
                               value="${item.total}"
                               min="0" step="0.01"
                               data-index="${index}"
                               onchange="updateTotal(${index}, this.value, this)"
                               onblur="updateTotal(${index}, this.value, this)"
                               onfocus="selectAllInputValue(this)"
                               onclick="selectAllInputValue(this); openNumPad(this, 'گۆڕینی کۆی گشتی')"
                               style="min-width: 90px; text-align: center; font-weight: bold;"
                               ${POS.canViewProfits ? `title="کلیک بکە بۆ بینینی قازانج"
                               onmouseenter="this.style.cursor='pointer'"
                               ondblclick="showItemProfitCard(${index})"` : ''}>
                        ` : `
                        <input type="number" class="form-control form-control-sm total-input"
                               value="${item.total}" readonly tabindex="-1"
                               style="min-width: 90px; text-align: center; font-weight: bold; background-color: #e9ecef; cursor: not-allowed;"
                               ${POS.canViewProfits ? `title="دووجار کلیک بکە بۆ بینینی قازانج"
                               ondblclick="showItemProfitCard(${index})"` : ''}>
                        `}
                    </td>
                    <td>
                        <i class="bi bi-trash delete-btn" onclick="removeFromCart(${index})"></i>
                    </td>
                </tr>
            `;
        }

        function removeFromCart(index) {
            if (index >= 0 && index < POS.cart.length) {
                POS.cart.splice(index, 1);
                updateCartDisplay();
                saveCartToStorage();
                showNotification('کاڵا لە سەبەتەکە سڕایەوە', 'info');
            }
        }

        function changeCartItemUnit(cartIndex, newUnitId) {
            const cartItem = POS.cart[cartIndex];
            if (!cartItem || !cartItem.unit_options) return;

            const selectedUnit = cartItem.unit_options.find(unit => unit.unit_id == newUnitId);
            if (!selectedUnit) return;

            // Update cart item with new unit data
            cartItem.unit_id = selectedUnit.unit_id;
            cartItem.unit_name = selectedUnit.unit_name;
            cartItem.unit_symbol = selectedUnit.unit_symbol;
            cartItem.stock = selectedUnit.stock_quantity;

            // Find the original product to get its currency
            const product = POS.products.find(p => Number(p.id) === Number(cartItem.id));
            const productCurrency = selectedUnit.currency || (product ? (product.currency || 'IQD') : 'IQD');

            // Update price based on current price type
            let newPrice = parseFloat(selectedUnit.sell_price);
            if (cartItem.selected_price_type === 'wholesale' && selectedUnit.wholesale_price && selectedUnit.wholesale_price > 0) {
                newPrice = parseFloat(selectedUnit.wholesale_price);
            } else if (cartItem.selected_price_type === 'special' && selectedUnit.special_price && selectedUnit.special_price > 0) {
                newPrice = parseFloat(selectedUnit.special_price);
            }

            // Convert price to current currency only if currencies are different
            if (productCurrency !== POS.currentCurrency && POS.dollarPrice && POS.dollarPrice > 0) {
                if (productCurrency === 'USD' && POS.currentCurrency === 'IQD') {
                    newPrice = newPrice * POS.dollarPrice;
                } else if (productCurrency === 'IQD' && POS.currentCurrency === 'USD') {
                    newPrice = newPrice / POS.dollarPrice;
                }
            }

            cartItem.price = roundPriceByCurrency(newPrice, POS.currentCurrency);
            cartItem.total = roundPriceByCurrency(cartItem.quantity * cartItem.price, POS.currentCurrency);
            // گۆڕینی یەکە کردارێکی ئەنقەستە و نرخی نوێی یەکەکە دادەنێت، بۆیە
            // نیشانەی نرخی دەستکاریکراو لادەبەین (بەکارهێنەر دەتوانێت دووبارە بیگۆڕێت).
            cartItem.priceOverridden = false;
            cartItem.buy_price = getConvertedBuyPrice({
                buy_price: selectedUnit.buy_price,
                currency: productCurrency
            });

            updateCartDisplay();
            saveCartToStorage();
            showNotification('یەکەی کاڵا گۆڕدرا', 'success');
        }

        function updateQuantity(index, change) {
            if (index >= 0 && index < POS.cart.length) {
                const item = POS.cart[index];
                const newQuantity = item.quantity + change;
                
                if (newQuantity <= 0) {
                    removeFromCart(index);
                    return;
                }
                
                item.quantity = newQuantity;
                item.total = roundPriceByCurrency(item.quantity * item.price, POS.currentCurrency);
                
                updateCartDisplay();
                saveCartToStorage();
            }
        }

        // ===== PROFIT DISPLAY FUNCTIONS =====
        /** نرخی کڕینی هەر یەکە بۆ ئایتمەکانی سەبەتە (ئاسایی: buy_price، دەرەکی: costPrice) */
        function getCartItemUnitCost(item) {
            if (item.isExternal) {
                const c = item.costPrice;
                if (c != null && c !== '' && Number.isFinite(Number(c))) {
                    return Number(c);
                }
                return 0;
            }
            if (item.unit_id != null && Array.isArray(item.unit_options) && item.unit_options.length > 0) {
                const unit = item.unit_options.find((u) => Number(u.unit_id) === Number(item.unit_id));
                if (unit && unit.buy_price != null && unit.buy_price !== '') {
                    return getConvertedBuyPrice({
                        buy_price: unit.buy_price,
                        currency: unit.currency || item.currency || 'IQD'
                    });
                }
            }
            return item.buy_price || 0;
        }

        function showItemProfitCard(index) {
            if (!POS.canViewProfits) return;
            if (index < 0 || index >= POS.cart.length) return;
            
            const item = POS.cart[index];
            const buyPrice = getCartItemUnitCost(item);
            const sellPrice = item.price;
            const quantity = item.quantity;
            const unitLabel = item.unit_name || item.unit_symbol || 'دانە';
            
            // Calculate profit
            const profitPerUnit = sellPrice - buyPrice;
            const totalCost = buyPrice * quantity;
            const totalProfit = profitPerUnit * quantity;
            
            // Create card HTML
            const cardHTML = `
                <div class="profit-card-overlay" id="profitCardOverlay" onclick="closeProfitCard()">
                    <div class="profit-card" onclick="event.stopPropagation()">
                        <div class="profit-card-header">
                            <i class="bi bi-graph-up-arrow"></i>
                            <h3>${item.name}</h3>
                        </div>
                        <div class="profit-card-body">
                            <div class="profit-info-row buy-price">
                                <div class="profit-info-label">
                                    <i class="bi bi-bag-check"></i>
                                    <span>نرخی کڕین (هەر ${unitLabel})</span>
                                </div>
                                <div class="profit-info-value">${formatMoney(buyPrice)}</div>
                            </div>
                            <div class="profit-info-row sell-price">
                                <div class="profit-info-label">
                                    <i class="bi bi-cash-coin"></i>
                                    <span>نرخی فرۆشتن (هەر ${unitLabel})</span>
                                </div>
                                <div class="profit-info-value">${formatMoney(sellPrice)}</div>
                            </div>
                            <div class="profit-info-row unit-profit">
                                <div class="profit-info-label">
                                    <i class="bi bi-currency-exchange"></i>
                                    <span>قازانجی هەر ${unitLabel}ێک</span>
                                </div>
                                <div class="profit-info-value">${formatMoney(profitPerUnit)}</div>
                            </div>
                            <div class="profit-section-divider"></div>
                            <div class="profit-info-row total-cost">
                                <div class="profit-info-label">
                                    <i class="bi bi-cart-check"></i>
                                    <span>کۆی نرخی کڕین بۆ ${quantity} ${unitLabel}</span>
                                </div>
                                <div class="profit-info-value">${formatMoney(totalCost)}</div>
                            </div>
                            <div class="profit-info-row total-profit">
                                <div class="profit-info-label">
                                    <i class="bi bi-trophy"></i>
                                    <span>کۆی قازانج</span>
                                </div>
                                <div class="profit-info-value highlight">${formatMoney(totalProfit)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Show card
            document.body.insertAdjacentHTML('beforeend', cardHTML);
            
            // Auto-close after 7 seconds
            setTimeout(() => {
                closeProfitCard();
            }, 7000);
        }

        function showReceiptProfitCard() {
            if (!POS.canViewProfits) return;
            if (POS.cart.length === 0) {
                showNotification('سەبەتە بەتاڵە', 'warning');
                return;
            }
            
            // Calculate totals
            let totalCost = 0;
            let totalSales = 0;
            
            POS.cart.forEach(item => {
                const buyPrice = getCartItemUnitCost(item);
                totalCost += buyPrice * item.quantity;
                totalSales += item.total;
            });
            
            const totalProfit = totalSales - totalCost;
            const profitMargin = totalSales > 0 ? ((totalProfit / totalSales) * 100).toFixed(1) : 0;
            
            // Create card HTML
            const cardHTML = `
                <div class="profit-card-overlay" id="profitCardOverlay" onclick="closeProfitCard()">
                    <div class="profit-card" onclick="event.stopPropagation()">
                        <div class="profit-card-header">
                            <i class="bi bi-receipt-cutoff"></i>
                            <h3>قازانجی وەسڵ</h3>
                        </div>
                        <div class="profit-card-body">
                            <div class="profit-info-row info-row">
                                <div class="profit-info-label">
                                    <i class="bi bi-cart"></i>
                                    <span>ژمارەی کاڵاکان</span>
                                </div>
                                <div class="profit-info-value">${POS.cart.length} کاڵا</div>
                            </div>
                            <div class="profit-section-divider"></div>
                            <div class="profit-info-row total-cost">
                                <div class="profit-info-label">
                                    <i class="bi bi-bag-check"></i>
                                    <span>کۆی نرخی کڕین</span>
                                </div>
                                <div class="profit-info-value">${formatMoney(totalCost)}</div>
                            </div>
                            <div class="profit-info-row sell-price">
                                <div class="profit-info-label">
                                    <i class="bi bi-cash-coin"></i>
                                    <span>کۆی نرخی فرۆشتن</span>
                                </div>
                                <div class="profit-info-value">${formatMoney(totalSales)}</div>
                            </div>
                            <div class="profit-section-divider"></div>
                            <div class="profit-info-row percentage-row">
                                <div class="profit-info-label">
                                    <i class="bi bi-percent"></i>
                                    <span>ڕێژەی قازانج</span>
                                </div>
                                <div class="profit-info-value">${profitMargin}%</div>
                            </div>
                            <div class="profit-info-row total-profit">
                                <div class="profit-info-label">
                                    <i class="bi bi-trophy"></i>
                                    <span>کۆی قازانج</span>
                                </div>
                                <div class="profit-info-value highlight">${formatMoney(totalProfit)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Show card
            document.body.insertAdjacentHTML('beforeend', cardHTML);
            
            // Auto-close after 7 seconds
            setTimeout(() => {
                closeProfitCard();
            }, 7000);
        }

        function closeProfitCard() {
            const overlay = document.getElementById('profitCardOverlay');
            if (overlay) {
                overlay.remove();
            }
        }

