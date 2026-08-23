        function initializePOS() {
            // console.log('POS System Initializing...');
            POS.defaultPriceType = normalizePriceType(POS.defaultPriceType);
            POS.currentPriceType = normalizePriceType(POS.currentPriceType || POS.defaultPriceType);
            
            // Initialize dropdown displays
            initializeDropdowns();
            
            // Initialize tabs system
            initializeTabs();

            initPosSessionKeepAlive();

            syncPaymentMethodUI();
            if (POS.posSaleCurrencyFallbackFromUsd) {
                showNotification('دیفۆڵت دۆلار هەڵبژێردراوە بەڵام نرخی دۆلار بەردەست نییە؛ دینار بەکارهێنرا.', 'warning');
            }
            
            // Focus on barcode input by default
            setTimeout(() => {
                const barcodeInput = document.getElementById('barcodeInput');
                if (barcodeInput) {
                    barcodeInput.focus();
                }
            }, 500);
            
            // Clean up empty tabs when page becomes hidden (user navigates away)
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    // Save current tab data before cleanup
                    if (POS.activeTabId) {
                        saveCurrentTabData();
                    }
                    // Remove empty tabs when leaving the page
                    removeEmptyTabs();
                    saveCartToStorage();
                } else {
                    refreshStalePosData({ silent: true });
                }
            });
            
            // Also clean up on beforeunload
            window.addEventListener('beforeunload', function() {
                if (POS.activeTabId) {
                    saveCurrentTabData();
                }
                removeEmptyTabs();
                saveCartToStorage();
            });
            
            // console.log('POS System Initialized Successfully');
        }

        function initPosSessionKeepAlive() {
            if (window.__kasherPosSessionKeepAliveInitialized) {
                return;
            }
            window.__kasherPosSessionKeepAliveInitialized = true;

            const intervalMs = 5 * 60 * 1000;
            let inFlight = false;

            const ping = async () => {
                if (inFlight || document.hidden) {
                    return;
                }
                inFlight = true;
                try {
                    await refreshCSRFToken();
                } finally {
                    inFlight = false;
                }
            };

            ping();
            setInterval(ping, intervalMs);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    ping();
                }
            });
            window.addEventListener('focus', ping);
        }

        // Refresh CSRF token when needed
        async function refreshCSRFToken() {
            try {
                const response = await fetch('../../api/session_keepalive.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ refresh: true })
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.data && data.data.csrf_token) {
                        POS.csrfToken = data.data.csrf_token;
                        return true;
                    }
                }
            } catch (error) {
                console.error('CSRF token refresh error:', error);
            }
            return false;
        }

        function padDateTimePart(value) {
            return String(value).padStart(2, '0');
        }

        function getCurrentLocalSaleDateTime() {
            const now = new Date();
            const date = now.getFullYear() + '-' + padDateTimePart(now.getMonth() + 1) + '-' + padDateTimePart(now.getDate());
            const time = padDateTimePart(now.getHours()) + ':' + padDateTimePart(now.getMinutes());
            const timeWithSeconds = time + ':' + padDateTimePart(now.getSeconds());

            return { date, time, timeWithSeconds };
        }

        function getCheckoutSaleDateTime() {
            const saleDateInput = document.getElementById('saleDate');
            const saleTimeInput = document.getElementById('saleTime');

            // Default POS date/time fields are only initial hints; use realtime local timestamp
            // unless user explicitly edits date/time inputs.
            if (!POS.saleDateTimeManuallyEdited) {
                const nowParts = getCurrentLocalSaleDateTime();
                saleDateInput.value = nowParts.date;
                saleTimeInput.value = nowParts.time;
                saleDateInput.setAttribute('data-datetime', nowParts.date + 'T' + nowParts.timeWithSeconds);
                return nowParts.date + ' ' + nowParts.timeWithSeconds;
            }

            const saleDate = saleDateInput.value;
            const saleTime = saleTimeInput.value;

            if (saleDate && saleTime) {
                const normalizedTime = /^\d{2}:\d{2}:\d{2}$/.test(saleTime) ? saleTime : saleTime + ':00';
                return saleDate + ' ' + normalizedTime;
            }

            if (saleDate) {
                return saleDate + ' 00:00:00';
            }

            return null;
        }

        // ===== EVENT LISTENERS =====
        function setupEventListeners() {
            const barcodeInput = document.getElementById('barcodeInput');
            
            // Products toggle button
            document.getElementById('productsToggleBtn').addEventListener('click', toggleProductsSection);
            
            // Date toggle button - toggles time input visibility
            document.getElementById('dateToggleBtn').addEventListener('click', function() {
                const timeInput = document.getElementById('saleTime');
                if (timeInput.style.display === 'none' || timeInput.style.display === '') {
                    timeInput.style.display = 'block';
                    timeInput.focus();
                    timeInput.showPicker && timeInput.showPicker();
                } else {
                    timeInput.style.display = 'none';
                }
            });
            
            // Update datetime-local value when date or time changes
            const dateInput = document.getElementById('saleDate');
            const timeInput = document.getElementById('saleTime');
            
            function updateDateTimeValue() {
                const date = dateInput.value;
                const time = timeInput.value;
                if (date && time) {
                    // Store combined datetime value in a hidden input or data attribute
                    dateInput.setAttribute('data-datetime', date + 'T' + time);
                }
            }
            
            function markSaleDateTimeAsManual() {
                POS.saleDateTimeManuallyEdited = true;
                updateDateTimeValue();
            }

            dateInput.addEventListener('input', markSaleDateTimeAsManual);
            timeInput.addEventListener('input', markSaleDateTimeAsManual);
            dateInput.addEventListener('change', markSaleDateTimeAsManual);
            timeInput.addEventListener('change', markSaleDateTimeAsManual);
            
            // Products close button
            document.getElementById('closeProductsBtn').addEventListener('click', closeProductsSection);
            
            // View toggle button (list/grid)
            document.getElementById('viewToggleBtn').addEventListener('click', toggleViewMode);
            
            // Discount functionality
            document.getElementById('toggleDiscountBtn').addEventListener('click', toggleDiscountSection);
            document.getElementById('clearDiscountBtn').addEventListener('click', clearDiscount);
            
            // Discount percentage buttons
            document.querySelectorAll('.discount-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    applyPercentageDiscount(parseInt(this.dataset.amount), this);
                });
            });

            // Change calculator functionality
            document.getElementById('showChangeCardBtn').addEventListener('click', showChangeCard);
            
            // Barcode input events
            barcodeInput.addEventListener('keydown', handleBarcodeInput);
            barcodeInput.addEventListener('input', handleSearchInput);

            const scanBarcodeBtn = document.getElementById('scanBarcodeBtn');
            if (scanBarcodeBtn && scanBarcodeBtn.dataset.barcodeScanLocked === '1') {
                scanBarcodeBtn.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    showPackageFeatureLockNotification();
                });
            }
            barcodeInput.addEventListener('focus', function() {
                openNumPad(this, '');
            });
            barcodeInput.addEventListener('click', function() {
                openNumPad(this, '');
            });

            // Category buttons (new vertical layout)
            document.querySelectorAll('.category-list-item').forEach(btn => {
                if (btn.id === 'servicesListBtn') {
                    return;
                }
                btn.addEventListener('click', handleCategoryClick);
            });
            const servicesBtn = document.getElementById('servicesListBtn');
            if (servicesBtn) {
                servicesBtn.addEventListener('click', handleServicesClick);
            }

            // Price type dropdown items
            document.querySelectorAll('.price-type-dropdown-item').forEach(item => {
                item.addEventListener('click', handlePriceTypeChange);
            });

            // Currency dropdown items
            document.querySelectorAll('.currency-dropdown-item').forEach(item => {
                item.addEventListener('click', handleCurrencyChange);
            });

            // Cart buttons
            document.getElementById('checkoutBtn').addEventListener('click', processCheckout);
            document.getElementById('returnBtn').addEventListener('click', processReturn);
            document.getElementById('clearCartBtn').addEventListener('click', clearCart);

            // Form inputs
            document.getElementById('paymentMethod').addEventListener('change', handlePaymentMethodChange);
            document.getElementById('walletId').addEventListener('change', () => {
                saveCurrentTabData();
                saveCartToStorage();
            });
            document.getElementById('discountAmount').addEventListener('input', updateTotalDisplay);

            // External product button
            document.getElementById('addExternalProductBtn').addEventListener('click', showExternalProductModal);
            document.getElementById('addExternalProductConfirmBtn').addEventListener('click', addExternalProduct);

            // New customer button
            document.getElementById('newCustomerBtn').addEventListener('click', () => {
                window.open(POS.urls.customerAdd, '_blank');
            });

            // Customer search functionality
            let customerSearchTimeout;
            document.getElementById('customerSearch').addEventListener('input', function() {
                clearTimeout(customerSearchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    hideCustomerSearchResults();
                    return;
                }
                
                customerSearchTimeout = setTimeout(() => {
                    searchCustomers(query);
                }, 300);
            });

            // Clear customer button
            document.getElementById('clearCustomerBtn').addEventListener('click', clearSelectedCustomer);

            // Hide search results when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#customerSearch') && !e.target.closest('#customerSearchResults')) {
                    hideCustomerSearchResults();
                }
            });

            // Check for customer_id in URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const customerId = urlParams.get('customer_id');
            if (customerId) {
                loadCustomerById(customerId);
            }



            // Resize handle
            setupResizeHandle();

            // Receipt modal buttons
            document.getElementById('printReceiptBtn').addEventListener('click', printReceipt);
            document.getElementById('printA4ReceiptBtn').addEventListener('click', printA4Receipt);
            document.getElementById('sendWhatsAppBtn').addEventListener('click', sendReceiptToWhatsApp);
            document.getElementById('newSaleFromReceiptBtn').addEventListener('click', () => {
                newSale();
                bootstrap.Modal.getInstance(document.getElementById('receiptModal')).hide();
            });

            // Auto-save cart
            setInterval(saveCartToStorage, 10000);

            // Keep barcode input focused - reduced frequency to 10 seconds
            setInterval(() => {
                if (!isModalOpen() && document.activeElement.tagName !== 'INPUT') {
                    barcodeInput.focus();
                }
            }, 10000);

            // Keyboard shortcuts
            document.addEventListener('keydown', handleKeyboardShortcuts);

            // Initialize NumPad
            initializeNumPad();
        }

        // Initialize dropdown displays
        function initializeDropdowns() {
            // Set initial currency dropdown display
            const currencyActiveItem = document.querySelector('.currency-dropdown-item.active');
            if (currencyActiveItem) {
                const currencyText = document.getElementById('currencyText');
                const currencyIcon = document.getElementById('currencyIcon');
                if (currencyText && currencyIcon) {
                    currencyText.textContent = currencyActiveItem.dataset.currency === 'IQD' ? 'دینار' : 'دۆلار';
                    currencyIcon.className = 'bi ' + currencyActiveItem.dataset.icon;
                }
            }
            
            // Set initial price type dropdown display
            const priceTypeActiveItem = document.querySelector('.price-type-dropdown-item.active');
            if (priceTypeActiveItem) {
                const priceTypeText = document.getElementById('priceTypeText');
                const priceTypeIcon = document.getElementById('priceTypeIcon');
                if (priceTypeText && priceTypeIcon) {
                    priceTypeText.textContent = priceTypeActiveItem.dataset.text;
                    priceTypeIcon.className = 'bi ' + priceTypeActiveItem.dataset.icon;
                }
            }
            setPriceTypeDropdownState(POS.currentPriceType);
        }

        function normalizePriceType(priceType) {
            return ['retail', 'wholesale', 'special'].includes(priceType) ? priceType : 'retail';
        }

        function setPriceTypeDropdownState(priceType) {
            const normalizedPriceType = normalizePriceType(priceType);
            let activeItem = null;

            document.querySelectorAll('.price-type-dropdown-item').forEach(item => {
                const isActive = item.dataset.priceType === normalizedPriceType;
                item.classList.toggle('active', isActive);
                if (isActive) {
                    activeItem = item;
                }
            });

            if (!activeItem) {
                activeItem = document.querySelector('.price-type-dropdown-item[data-price-type="retail"]');
            }

            const priceTypeText = document.getElementById('priceTypeText');
            const priceTypeIcon = document.getElementById('priceTypeIcon');
            if (activeItem && priceTypeText && priceTypeIcon) {
                priceTypeText.textContent = activeItem.dataset.text;
                priceTypeIcon.className = 'bi ' + activeItem.dataset.icon;
            }
        }

        function resetCurrentPriceTypeToDefault() {
            POS.currentPriceType = normalizePriceType(POS.defaultPriceType);
            setPriceTypeDropdownState(POS.currentPriceType);
        }

        // ===== NUMPAD FUNCTIONALITY =====
        let activeNumpadInput = null;
        let numpadClearOnNextInput = false;
        const numpadOverlay = document.getElementById('numpadOverlay');

        function initializeNumPad() {
            // NumPad buttons
            document.querySelectorAll('.numpad-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (!activeNumpadInput) return;

                    const value = this.getAttribute('data-value');
                    const action = this.getAttribute('data-action');
                    const isBarcodeField = activeNumpadInput.id === 'barcodeInput';

                    if (value) {
                        if (numpadClearOnNextInput) {
                            activeNumpadInput.value = value;
                            numpadClearOnNextInput = false;
                        } else if (isBarcodeField) {
                            activeNumpadInput.value += value;
                        } else {
                             // Prevent leading zeros unless it's a decimal
                            if (activeNumpadInput.value === '0' && value !== '.') {
                                activeNumpadInput.value = value;
                            } else {
                                activeNumpadInput.value += value;
                            }
                        }
                    } else if (action === 'backspace') {
                        // If we hit backspace immediately after opening, we probably want to just delete the last char of existing text
                        // OR if we clearOnNextInput, backspace might mean "clear everything"? 
                        // Let's stick to standard behavior: clear on next input disabled if backspace used
                        if (numpadClearOnNextInput) {
                            numpadClearOnNextInput = false;
                        }
                        
                        activeNumpadInput.value = activeNumpadInput.value.slice(0, -1);
                        if (!isBarcodeField && activeNumpadInput.value === '') {
                            activeNumpadInput.value = '0';
                        }
                    }

                    // Trigger input event to update calculations
                    activeNumpadInput.dispatchEvent(new Event('input', { bubbles: true }));
                    activeNumpadInput.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

        }

        function selectAllInputValue(inputElement) {
            if (!inputElement) return;

            const applySelection = () => {
                try {
                    inputElement.select();
                } catch (error) {
                    // Some input types may not support select() in all browsers.
                }

                try {
                    const valueLength = String(inputElement.value ?? '').length;
                    inputElement.setSelectionRange(0, valueLength);
                } catch (error) {
                    // setSelectionRange is not supported for every input type.
                }
            };

            applySelection();
            setTimeout(applySelection, 0);
        }

        function openNumPad(inputElement, title = 'داخلکردنی ژمارە') {
            // Remove active class from previous input
            if (activeNumpadInput) {
                activeNumpadInput.classList.remove('numpad-active-input');
            }

            activeNumpadInput = inputElement;
            activeNumpadInput.classList.add('numpad-active-input');
            numpadClearOnNextInput = true; // Enable overwrite mode
            
            // Set title (element optional — guard against missing DOM node)
            const numpadTitleEl = document.getElementById('numpadTitle');
            if (numpadTitleEl) {
                numpadTitleEl.textContent = title;
            }
            
            // Select text in input (visual cue)
            selectAllInputValue(inputElement);
        }

        function closeNumPad() {
            // Remove active input highlighting
            if (activeNumpadInput) {
                activeNumpadInput.classList.remove('numpad-active-input');
                activeNumpadInput = null;
            }
        }

        // ===== PRODUCT LOADING =====
        async function loadProducts(category = '') {
            try {
                showLoading();
                
                let url = `../../api/products.php?action=list&user_id=${POS.userId}`;
                if (category) {
                    url += `&category=${category}`;
                }
                
                // console.log('Loading products from:', url);
                
                const response = await posFetch(url);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const data = await response.json();
                // console.log('API Response:', data);
                
                if (data.success && data.data && data.data.products) {
                    POS.products = data.data.products;
                    displayProducts(data.data.products);
                    updateProductCount(data.data.products.length);
                } else {
                    throw new Error(data.message || 'نەناسراو');
                }
                
            } catch (error) {
                showError('هەڵەیەک ڕوویدا لە بارکردنی کاڵاکان: ' + error.message);
                displayEmptyProducts();
            } finally {
                hideLoading();
            }
        }

        function displayProducts(productList) {
            const productGrid = document.getElementById('productGrid');
            
            // If productGrid doesn't exist (new layout), skip this function
            if (!productGrid) {
                return;
            }
            
            if (!productList || productList.length === 0) {
                displayEmptyProducts();
                return;
            }
            
            const html = productList.map(product => createProductCard(product)).join('');
            productGrid.innerHTML = html;
            
            // Add animation
            setTimeout(() => {
                productGrid.querySelectorAll('.product-card').forEach((card, index) => {
                    setTimeout(() => {
                        card.classList.add('fade-in');
                    }, index * 30);
                });
            }, 100);
        }

        function createProductCard(product) {
            const normalizedProduct = normalizeProductUnits({ ...product });
            const primaryUnitIndex = getPrimaryUnitIndex(normalizedProduct);
            let productData = normalizedProduct;
            let unitData = null;
            // Out-of-stock reflects the whole product: available if ANY unit has stock.
            let isOutOfStock = !productHasAnyUnitStock(normalizedProduct);
            let isLowStock = normalizedProduct.stock_quantity <= normalizedProduct.min_stock && normalizedProduct.stock_quantity > 0;

            if (normalizedProduct.units && normalizedProduct.units.length > 0) {
                unitData = normalizedProduct.units[primaryUnitIndex] || normalizedProduct.units[0];
                productData = {
                    ...normalizedProduct,
                    sell_price: unitData.sell_price,
                    wholesale_price: unitData.wholesale_price,
                    special_price: unitData.special_price,
                    stock_quantity: unitData.stock_quantity,
                    min_stock: unitData.min_stock,
                    currency: unitData.currency || normalizedProduct.currency || 'IQD'
                };
                isLowStock = productData.stock_quantity <= productData.min_stock && productData.stock_quantity > 0;
            }
            
            // Determine price based on current price type and convert currency
            let price = getConvertedPrice(productData, POS.currentPriceType);
            let priceType = 'تاک';
            let priceClass = 'text-success';
            
            if (POS.currentPriceType === 'wholesale' && productData.wholesale_price && productData.wholesale_price > 0) {
                priceType = 'جوملە';
                priceClass = 'text-info';
            } else if (POS.currentPriceType === 'special' && productData.special_price && productData.special_price > 0) {
                priceType = 'تایبەت';
                priceClass = 'text-warning';
            } else if (POS.currentPriceType !== 'retail') {
                priceClass = 'text-muted';
            }
            
            // Generate unit selector if product has multiple units
            let unitSelector = '';
            if (normalizedProduct.units && normalizedProduct.units.length > 1) {
                unitSelector = `
                    <div class="unit-selector mb-2">
                        <select class="form-select form-select-sm" onchange="switchProductUnit(${normalizedProduct.id}, this.value)">
                            ${normalizedProduct.units.map((unit, index) => `
                                <option value="${index}" ${index === primaryUnitIndex ? 'selected' : ''}>
                                    ${unit.unit_name} ${unit.unit_symbol ? `(${unit.unit_symbol})` : ''}
                                </option>
                            `).join('')}
                        </select>
                    </div>
                `;
            } else if (unitData) {
                unitSelector = `
                    <div class="unit-info mb-2">
                        <span class="badge bg-info">${unitData.unit_name} ${unitData.unit_symbol ? `(${unitData.unit_symbol})` : ''}</span>
                    </div>
                `;
            }
            
            return `
                <div class="product-card ${isOutOfStock ? 'out-of-stock' : ''}"
                     data-product-id="${normalizedProduct.id}"
                     data-selected-unit-index="${primaryUnitIndex}"
                     onclick="addToCart(${normalizedProduct.id})">

                    ${normalizedProduct.image_url ?
                        `<img src="${normalizedProduct.image_url}"
                              alt="${normalizedProduct.name}" class="img-fluid rounded mb-2"
                              style="max-height: 80px; object-fit: cover;">` :
                        `<div class="bg-light rounded mb-2 d-flex align-items-center justify-content-center"
                              style="height: 80px;">
                            <i class="bi bi-box-seam text-muted" style="font-size: 2rem;"></i>
                         </div>`
                    }

                    <h6 class="card-title text-truncate">${normalizedProduct.name}</h6>

                    ${unitSelector}

                    <div class="${priceClass} fw-bold mb-1">${formatMoney(price)}</div>
                    <div class="badge badge-sm ${priceClass.replace('text-', 'bg-')} mb-2">${priceType}</div>

                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">${normalizedProduct.barcode || 'بێ بارکۆد'}</small>
                        <span class="badge bg-${isOutOfStock ? 'danger' : isLowStock ? 'warning' : 'success'}">
                            ${productData.stock_quantity}
                        </span>
                    </div>

                    ${isOutOfStock ? '<div class="text-danger small mt-2">تەواو بووە</div>' : ''}
                </div>
            `;
        }

        function displayEmptyProducts() {
            const productGrid = document.getElementById('productGrid');
            
            // If productGrid doesn't exist (new layout), skip this function
            if (!productGrid) {
                return;
            }
            
            productGrid.innerHTML = `
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam display-3 text-muted mb-3"></i>
                        <h5 class="text-muted">هیچ کاڵای بەردەست نییە</h5>
                        <p class="text-muted">تەنها کاڵا بەردەست و بەسەرنەچووەکان نیشان دەدرێن</p>
                        <p class="text-muted small">یەکەم کاڵاکەت زیاد بکە یان کاڵا بەردەستەکان زیاد بکە</p>
                        <a href="../../user/products/add.php" class="btn btn-success-custom btn-custom">
                            <i class="bi bi-plus-circle"></i> زیادکردنی کاڵا
                        </a>
                    </div>
                </div>
            `;
        }

        // Unit switching functionality
        function switchProductUnit(productId, unitIndex) {
            const product = POS.products.find(p => Number(p.id) === Number(productId));
            if (!product || !product.units) return;

            const unitIndexNum = parseInt(unitIndex);
            const selectedUnit = product.units[unitIndexNum];
            if (!selectedUnit) return;

            // Update the product card display
            const productCard = document.querySelector(`.product-card[data-product-id="${Number(productId)}"]`);
            if (productCard) {
                productCard.setAttribute('data-selected-unit-index', String(unitIndexNum));
                // Update price display
                const priceElement = productCard.querySelector('.fw-bold');
                if (priceElement) {
                    const unitProductData = {
                        ...product,
                        sell_price: selectedUnit.sell_price,
                        wholesale_price: selectedUnit.wholesale_price,
                        special_price: selectedUnit.special_price,
                        currency: selectedUnit.currency || product.currency || 'IQD'
                    };
                    let price = getConvertedPrice(unitProductData, POS.currentPriceType);
                    let priceClass = 'text-success';
                    
                    if (POS.currentPriceType === 'wholesale' && selectedUnit.wholesale_price && selectedUnit.wholesale_price > 0) {
                        priceClass = 'text-info';
                    } else if (POS.currentPriceType === 'special' && selectedUnit.special_price && selectedUnit.special_price > 0) {
                        priceClass = 'text-warning';
                    }
                    
                    priceElement.textContent = formatMoney(price);
                    priceElement.className = `${priceClass} fw-bold mb-1`;
                }
                
                // Update stock display
                const stockElement = productCard.querySelector('.badge');
                if (stockElement) {
                    // Overall availability follows any-unit stock; the number still shows the selected unit.
                    const isOutOfStock = !productHasAnyUnitStock(product);
                    const isLowStock = selectedUnit.stock_quantity <= selectedUnit.min_stock && selectedUnit.stock_quantity > 0;

                    stockElement.textContent = selectedUnit.stock_quantity;
                    stockElement.className = `badge bg-${isOutOfStock ? 'danger' : isLowStock ? 'warning' : 'success'}`;
                }
                
                // Update unit info
                const unitInfoElement = productCard.querySelector('.unit-info .badge');
                if (unitInfoElement) {
                    unitInfoElement.textContent = `${selectedUnit.unit_name} ${selectedUnit.unit_symbol ? `(${selectedUnit.unit_symbol})` : ''}`;
                }
            }
        }

        // ===== INITIALIZATION =====
        document.addEventListener('DOMContentLoaded', function() {
            initializePOS();
            setupEventListeners();
            // Products are now loaded when clicking on a category
            // loadProducts();
        });
