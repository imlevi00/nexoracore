        // ===== NEW FEATURE FUNCTIONS =====
        
        // Price type handling
        function handlePriceTypeChange(event) {
            event.preventDefault();
            const dropdownItem = event.currentTarget;
            const newPriceType = normalizePriceType(dropdownItem.dataset.priceType);
            setPriceTypeDropdownState(newPriceType);
            
            // Close dropdown
            const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('priceTypeDropdown'));
            if (dropdown) {
                dropdown.hide();
            }
            
            POS.currentPriceType = newPriceType;
            document.dispatchEvent(new CustomEvent('pos:priceTypeChanged', { detail: { priceType: newPriceType } }));
            
            // Update all cart items with new price type
            if (POS.cart && POS.cart.length > 0) {
                const itemsWithoutPriceType = [];
                
                POS.cart.forEach((cartItem, index) => {
                    // Find the original product
                    const product = POS.products.find(p => Number(p.id) === Number(cartItem.id));
                    if (!product) return;
                    
                    // Determine which data to use - units or main product
                    let productData = product;
                    let unitData = null;
                    
                    if (product.units && product.units.length > 0 && cartItem.unit_id) {
                        // Find the unit that matches the cart item's unit
                        unitData = product.units.find(u => u.unit_id === cartItem.unit_id);
                        if (unitData) {
                            productData = {
                                ...product,
                                sell_price: unitData.sell_price,
                                wholesale_price: unitData.wholesale_price,
                                special_price: unitData.special_price,
                                currency: product.currency || 'IQD'
                            };
                        }
                    }
                    
                    // Determine actual price type to use (with fallback to retail)
                    let actualPriceType = 'retail';
                    if (newPriceType === 'wholesale' && productData.wholesale_price && productData.wholesale_price > 0) {
                        actualPriceType = 'wholesale';
                    } else if (newPriceType === 'special' && productData.special_price && productData.special_price > 0) {
                        actualPriceType = 'special';
                    } else if (newPriceType !== 'retail') {
                        // Collect items that don't have the requested price type
                        itemsWithoutPriceType.push(cartItem.name);
                    }
                    
                    // Calculate new price using getConvertedPrice
                    const newPrice = getConvertedPrice(productData, newPriceType);
                    
                    // Update cart item
                    if (newPrice > 0) {
                        cartItem.price = roundPriceByCurrency(newPrice, POS.currentCurrency);
                        cartItem.total = roundPriceByCurrency(cartItem.quantity * cartItem.price, POS.currentCurrency);
                        cartItem.price_type = actualPriceType;
                        cartItem.selected_price_type = newPriceType;
                        // گۆڕینی جۆری نرخ کردارێکی ئەنقەستەیە کە نرخی نوێ دادەنێت،
                        // بۆیە نیشانەی نرخی دەستکاریکراو لادەبەین.
                        cartItem.priceOverridden = false;
                    }
                });
                
                // Show single notification if some items don't have the requested price type
                if (itemsWithoutPriceType.length > 0) {
                    const priceTypeName = newPriceType === 'wholesale' ? 'جوملە' : 'تایبەت';
                    if (itemsWithoutPriceType.length === 1) {
                        showNotification(`نرخی ${priceTypeName} بۆ ${itemsWithoutPriceType[0]} بەردەست نییە، نرخی تاک بەکارهێنراوە`, 'info');
                    } else {
                        showNotification(`نرخی ${priceTypeName} بۆ ${itemsWithoutPriceType.length} کاڵا بەردەست نییە، نرخی تاک بەکارهێنراوە`, 'info');
                    }
                }
                
                // Save updated cart
                saveCartToStorage();
            }
            
            // Update cart display to reflect new price type
            updateCartDisplay();
        }

        // Currency handling
        function handleCurrencyChange(event) {
            event.preventDefault();
            const dropdownItem = event.currentTarget;
            const newCurrency = dropdownItem.dataset.currency;
            
            // Check if USD is selected but dollarPrice is not available
            if (newCurrency === 'USD' && (!POS.dollarPrice || POS.dollarPrice <= 0)) {
                showError('نرخی دۆلار دانەمەزراوە. تکایە لە ڕێکخستنەکان نرخی دۆلار بنوێنەوە.');
                // Don't change currency - keep current selection active
                document.querySelectorAll('.currency-dropdown-item').forEach(item => {
                    item.classList.remove('active');
                    if (item.dataset.currency === POS.currentCurrency) {
                        item.classList.add('active');
                    }
                });
                return;
            }
            
            // Update active state
            document.querySelectorAll('.currency-dropdown-item').forEach(item => item.classList.remove('active'));
            dropdownItem.classList.add('active');
            
            // Update dropdown button text and icon
            const currencyText = document.getElementById('currencyText');
            const currencyIcon = document.getElementById('currencyIcon');
            if (currencyText && currencyIcon) {
                currencyText.textContent = newCurrency === 'IQD' ? 'دینار' : 'دۆلار';
                currencyIcon.className = 'bi ' + dropdownItem.dataset.icon;
            }
            
            // Close dropdown
            const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('currencyDropdown'));
            if (dropdown) {
                dropdown.hide();
            }
            
            const oldCurrency = POS.currentCurrency;
            POS.currentCurrency = newCurrency;
            document.dispatchEvent(new CustomEvent('pos:currencyChanged', { detail: { currency: newCurrency } }));
            
            // Update prices in cart when currency changes
            if (oldCurrency !== newCurrency && POS.cart.length > 0) {
                POS.cart.forEach(item => {
                    // ئایتمە دەستکاریکراوەکان: تەنها نرخە دەستکاریکراوەکە دەگۆڕین بۆ
                    // دراوی نوێ، بۆ ئەوەی نرخی بەکارهێنەر نەگەڕێتەوە بۆ نرخی کاڵا.
                    if (item.priceOverridden) {
                        let convertedPrice = item.price;
                        if (POS.dollarPrice && POS.dollarPrice > 0) {
                            if (oldCurrency === 'USD' && newCurrency === 'IQD') {
                                convertedPrice = item.price * POS.dollarPrice;
                            } else if (oldCurrency === 'IQD' && newCurrency === 'USD') {
                                convertedPrice = item.price / POS.dollarPrice;
                            }
                        }
                        item.price = roundPriceByCurrency(convertedPrice, newCurrency);
                        item.total = roundPriceByCurrency(item.quantity * item.price, newCurrency);
                        return;
                    }

                    // Find the original product to get its currency
                    const product = POS.products.find(p => Number(p.id) === Number(item.id));
                    if (product) {
                        const productCurrency = product.currency || 'IQD';

                        // Recalculate price based on new currency
                        let newPrice = 0;
                        
                        // Get the base price in product's original currency
                        if (item.price_type === 'wholesale' && product.wholesale_price > 0) {
                            newPrice = parseFloat(product.wholesale_price);
                        } else if (item.price_type === 'special' && product.special_price > 0) {
                            newPrice = parseFloat(product.special_price);
                        } else {
                            newPrice = parseFloat(product.sell_price);
                        }
                        
                        // Convert to new currency only if currencies are different
                        if (productCurrency !== newCurrency && POS.dollarPrice && POS.dollarPrice > 0) {
                            if (productCurrency === 'USD' && newCurrency === 'IQD') {
                                // کاڵاکە بە دۆلار دانراوە، دەیگۆڕین بۆ دینار
                                newPrice = newPrice * POS.dollarPrice;
                            } else if (productCurrency === 'IQD' && newCurrency === 'USD') {
                                // کاڵاکە بە دینار دانراوە، دەیگۆڕین بۆ دۆلار
                                newPrice = newPrice / POS.dollarPrice;
                            }
                        }
                        // ئەگەر هەردووکیان یەکسانن، نرخەکە وەکو خۆی دەمێنێتەوە
                        
                        // Update item price and total
                        item.price = roundPriceByCurrency(newPrice, newCurrency);
                        item.total = roundPriceByCurrency(item.quantity * item.price, newCurrency);
                    }
                });
            }
            
            // Update all product displays
            if (POS.products && POS.products.length > 0) {
                displayProducts(POS.products);
            }
            
            // Update cart display
            updateCartDisplay();
            
            // Update total display
            updateTotalDisplay();
        }

        /** دۆخی UI ـی پارەدان لەگەڵ کڕیار و قەرز (بێ گۆڕینی دەستی لەسەر select) */
        function syncPaymentMethodUI() {
            const method = document.getElementById('paymentMethod').value;
            const customerContainer = document.getElementById('customerContainer');
            const paidAmountWrapper = document.getElementById('paidAmountWrapper');
            const walletSelectWrapper = document.getElementById('walletSelectWrapper');
            const walletSelect = document.getElementById('walletId');
            const customerId = document.getElementById('selectedCustomerId').value;

            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.classList.remove('active');
            });

            customerContainer.style.display = 'flex';
            if (walletSelectWrapper && walletSelect) {
                const isCash = method === 'cash';
                walletSelectWrapper.style.display = isCash ? 'block' : 'none';
                walletSelect.required = isCash;
                if (!isCash) {
                    walletSelect.value = '';
                } else if (!walletSelect.value && POS.defaultWalletId > 0) {
                    walletSelect.value = String(POS.defaultWalletId);
                }
            }
            if (method === 'credit') {
                paidAmountWrapper.style.display = customerId ? 'block' : 'none';
            } else {
                paidAmountWrapper.style.display = 'none';
                document.getElementById('paidAmount').value = '';
            }
        }

        // Payment method handling (ئاگاداری کڕیار بۆ قەرز تەنها لە processCheckout / processReturn دەردەکەوێت)
        function handlePaymentMethodChange(event) {
            syncPaymentMethodUI();
            if (document.getElementById('paymentMethod').value === 'cash') {
                updateCustomerDisplay();
            }
        }


        // External product functions
        function showExternalProductModal() {
            new bootstrap.Modal(document.getElementById('externalProductModal')).show();
        }

        function addExternalProduct() {
            const name = document.getElementById('externalProductName').value.trim();
            const quantity = parseFloat(document.getElementById('externalProductQuantity').value);
            const costPrice = parseFloat(document.getElementById('externalProductCostPrice').value);
            const sellPrice = parseFloat(document.getElementById('externalProductSellPrice').value);

            if (!name || !Number.isFinite(quantity) || quantity <= 0) {
                showNotification('تکایە ناو و بڕی دروست بنووسە', 'warning');
                return;
            }
            if (!Number.isFinite(costPrice) || costPrice < 0) {
                showNotification('تکایە نرخی کڕین بنووسە (دەتوانیت سفر بێت)', 'warning');
                return;
            }
            if (!Number.isFinite(sellPrice) || sellPrice <= 0) {
                showNotification('تکایە نرخی فرۆشتنی دروست بنووسە', 'warning');
                return;
            }

            const roundedSellPrice = roundPriceByCurrency(sellPrice, POS.currentCurrency);
            const externalProduct = {
                id: 'external_' + Date.now(),
                name: name,
                barcode: '',
                price: roundedSellPrice,
                quantity: quantity,
                total: roundPriceByCurrency(quantity * roundedSellPrice, POS.currentCurrency),
                stock: 999999, // Unlimited stock for external products
                price_type: 'retail',
                isExternal: true,
                costPrice: costPrice
            };

            POS.cart.push(externalProduct);
            updateCartDisplay();
            saveCartToStorage();
            showNotification(`${name} زیادکرا بۆ سەبەتە`, 'success');
            
            // Clear form and close modal
            document.getElementById('externalProductForm').reset();
            bootstrap.Modal.getInstance(document.getElementById('externalProductModal')).hide();
        }

        function addServiceToCart(serviceId) {
            const service = POS.services.find(item => Number(item.id) === Number(serviceId));
            if (!service) {
                showNotification('خزمەتگوزاری نەدۆزرایەوە', 'warning');
                return;
            }

            const existingIndex = POS.cart.findIndex(item => item.isExternal && item.serviceId === service.id);
            const roundedPrice = roundPriceByCurrency(parseFloat(service.sell_price || 0), POS.currentCurrency);

            if (existingIndex !== -1) {
                POS.cart[existingIndex].quantity += 1;
                POS.cart[existingIndex].total = roundPriceByCurrency(
                    POS.cart[existingIndex].quantity * POS.cart[existingIndex].price,
                    POS.currentCurrency
                );
            } else {
                POS.cart.push({
                    id: service.id,
                    serviceId: service.id,
                    name: service.name,
                    barcode: '',
                    price: roundedPrice,
                    quantity: 1,
                    total: roundedPrice,
                    stock: 999999,
                    price_type: 'retail',
                    isExternal: true,
                    isService: true,
                    costPrice: parseFloat(service.cost_price || 0),
                    currency: POS.currentCurrency || 'IQD'
                });
            }

            updateCartDisplay();
            saveCartToStorage();
            showNotification(`${service.name} زیادکرا بۆ سەبەتە`, 'success');
        }


        // Customer search functions
        function searchCustomers(query) {
            posFetch(`ajax/search_customers.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.customers && data.customers.length > 0) {
                        displayCustomerSearchResults(data.customers);
                    } else {
                        hideCustomerSearchResults();
                    }
                })
                .catch(error => {
                    hideCustomerSearchResults();
                });
        }

        function displayCustomerSearchResults(customers) {
            const resultsContainer = document.getElementById('customerSearchResults');
            let html = '';
            
            customers.forEach(customer => {
                const debtIqd = customer.current_debt_iqd || 0;
                const debtUsd = customer.current_debt_usd || 0;
                let debtText = '';

                if (debtIqd > 0 || debtUsd > 0) {
                    const parts = [];
                    if (debtIqd > 0) parts.push(formatMoneyByCurrency(debtIqd, 'IQD'));
                    if (debtUsd > 0) parts.push(formatMoneyByCurrency(debtUsd, 'USD'));
                    debtText = `<span class="text-danger">قەرز: ${parts.join(' | ')}</span>`;
                } else {
                    debtText = '<span class="text-success">بەبێ قەرز</span>';
                }
                
                html += `
                    <div class="search-result-item customer-item" data-customer-id="${customer.id}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">${customer.name}</div>
                                <small class="text-muted">
                                    ${customer.phone ? customer.phone : 'بێ تەلەفۆن'} | 
                                    ${debtText}
                                </small>
                            </div>
                            <i class="bi bi-arrow-left text-muted"></i>
                        </div>
                    </div>
                `;
            });
            
            resultsContainer.innerHTML = html;
            resultsContainer.style.display = 'block';
            
            // Add click handlers
            resultsContainer.querySelectorAll('.customer-item').forEach(item => {
                item.addEventListener('click', function() {
                    const customerId = this.dataset.customerId;
                    const customer = customers.find(c => c.id == customerId);
                    selectCustomer(customer);
                });
            });
        }

        function hideCustomerSearchResults() {
            document.getElementById('customerSearchResults').style.display = 'none';
        }

        function selectCustomer(customer) {
            document.getElementById('selectedCustomerId').value = customer.id;
            document.getElementById('customerName').value = customer.name;
            document.getElementById('customerPhone').value = customer.phone || '';
            document.getElementById('customerSearch').value = '';
            hideCustomerSearchResults();
            
            // نیشاندانی badge ی کڕیار و شاردنەوەی خانەی گەڕان
            const customerSearchState = document.getElementById('customerSearchState');
            const customerSelectedState = document.getElementById('customerSelectedState');
            const customerNameDisplay = document.getElementById('customerNameDisplay');
            const customerPhoneDisplay = document.getElementById('customerPhoneDisplay');
            const customerDebtDisplay = document.getElementById('customerDebtDisplay');
            
            // Set customer name
            customerNameDisplay.textContent = customer.name;
            
            // Set customer phone
            if (customer.phone && customer.phone.trim() !== '') {
                customerPhoneDisplay.textContent = customer.phone;
                customerPhoneDisplay.style.display = 'block';
            } else {
                customerPhoneDisplay.textContent = '';
                customerPhoneDisplay.style.display = 'none';
            }
            
            // Set customer debt
            const debtIqd = customer.current_debt_iqd || 0;
            const debtUsd = customer.current_debt_usd || 0;
            if (debtIqd > 0 || debtUsd > 0) {
                customerDebtDisplay.className = 'customer-badge-debt has-debt';
                customerDebtDisplay.style.display = 'flex';
                customerDebtDisplay.style.flexDirection = 'column';
                customerDebtDisplay.style.gap = '2px';

                const lines = [];
                if (debtIqd > 0) lines.push(`<div>قەرز: ${formatMoneyByCurrency(debtIqd, 'IQD')}</div>`);
                if (debtUsd > 0) lines.push(`<div>قەرز: ${formatMoneyByCurrency(debtUsd, 'USD')}</div>`);
                customerDebtDisplay.innerHTML = lines.join('');
            } else {
                customerDebtDisplay.textContent = 'بەبێ قەرز';
                customerDebtDisplay.className = 'customer-badge-debt no-debt';
                customerDebtDisplay.style.display = 'block';
                customerDebtDisplay.style.flexDirection = '';
                customerDebtDisplay.style.gap = '';
            }
            
            // Switch states
            customerSearchState.style.display = 'none';
            customerSelectedState.style.display = 'block';
            
            // پاشەکەوتکردنی زانیاری کڕیار لەگەڵ تەلەفۆن
            POS.selectedCustomer = {
                id: customer.id,
                name: customer.name,
                phone: customer.phone || null,
                current_debt_iqd: customer.current_debt_iqd || 0,
                current_debt_usd: customer.current_debt_usd || 0
            };
            
            // Show customer debt info if exists
            if (debtIqd > 0 || debtUsd > 0) {
                const parts = [];
                if (debtIqd > 0) parts.push(formatMoneyByCurrency(debtIqd, 'IQD'));
                if (debtUsd > 0) parts.push(formatMoneyByCurrency(debtUsd, 'USD'));
                showNotification(`کڕیار: ${customer.name} - قەرز: ${parts.join(' | ')}`, 'info');
            }
            syncPaymentMethodUI();

            fetchCustomerById(customer.id).then((fresh) => {
                if (!fresh) return;
                if (!POS.selectedCustomer || Number(POS.selectedCustomer.id) !== Number(fresh.id)) return;
                POS.selectedCustomer.current_debt_iqd = fresh.current_debt_iqd || 0;
                POS.selectedCustomer.current_debt_usd = fresh.current_debt_usd || 0;
                if (POS.activeTabId) {
                    const tab = POS.tabs.find((t) => t.id === POS.activeTabId);
                    if (tab && tab.selectedCustomer && Number(tab.selectedCustomer.id) === Number(fresh.id)) {
                        tab.selectedCustomer.current_debt_iqd = fresh.current_debt_iqd || 0;
                        tab.selectedCustomer.current_debt_usd = fresh.current_debt_usd || 0;
                    }
                }
                updateCustomerDisplay();
                saveCartToStorage();
            });
        }

        function clearSelectedCustomer() {
            document.getElementById('selectedCustomerId').value = '';
            document.getElementById('customerName').value = '';
            document.getElementById('customerPhone').value = '';
            document.getElementById('customerSearch').value = '';
            hideCustomerSearchResults();
            
            // شاردنەوەی badge ی کڕیار و نیشاندانی خانەی گەڕان
            const customerSearchState = document.getElementById('customerSearchState');
            const customerSelectedState = document.getElementById('customerSelectedState');
            
            customerSearchState.style.display = 'block';
            customerSelectedState.style.display = 'none';
            
            // Focus on search input
            setTimeout(() => {
                document.getElementById('customerSearch').focus();
            }, 100);
            
            // پاککردنەوەی زانیاری کڕیار
            POS.selectedCustomer = null;
            syncPaymentMethodUI();
        }

        function updateCustomerDisplay() {
            // Update customer display based on current POS state
            const customerSearchState = document.getElementById('customerSearchState');
            const customerSelectedState = document.getElementById('customerSelectedState');
            
            if (POS.selectedCustomer) {
                document.getElementById('selectedCustomerId').value = POS.selectedCustomer.id;
                document.getElementById('customerName').value = POS.selectedCustomer.name;
                document.getElementById('customerPhone').value = POS.selectedCustomer.phone || '';
                
                const customerNameDisplay = document.getElementById('customerNameDisplay');
                const customerPhoneDisplay = document.getElementById('customerPhoneDisplay');
                const customerDebtDisplay = document.getElementById('customerDebtDisplay');
                
                // Set customer name
                customerNameDisplay.textContent = POS.selectedCustomer.name;
                
                // Set customer phone
                if (POS.selectedCustomer.phone && POS.selectedCustomer.phone.trim() !== '') {
                    customerPhoneDisplay.textContent = POS.selectedCustomer.phone;
                    customerPhoneDisplay.style.display = 'block';
                } else {
                    customerPhoneDisplay.textContent = '';
                    customerPhoneDisplay.style.display = 'none';
                }
                
                // Set customer debt
                const debtIqd = POS.selectedCustomer.current_debt_iqd || 0;
                const debtUsd = POS.selectedCustomer.current_debt_usd || 0;
                if (debtIqd > 0 || debtUsd > 0) {
                    customerDebtDisplay.className = 'customer-badge-debt has-debt';
                    customerDebtDisplay.style.display = 'flex';
                    customerDebtDisplay.style.flexDirection = 'column';
                    customerDebtDisplay.style.gap = '2px';

                    const lines = [];
                    if (debtIqd > 0) lines.push(`<div>قەرز: ${formatMoneyByCurrency(debtIqd, 'IQD')}</div>`);
                    if (debtUsd > 0) lines.push(`<div>قەرز: ${formatMoneyByCurrency(debtUsd, 'USD')}</div>`);
                    customerDebtDisplay.innerHTML = lines.join('');
                } else {
                    customerDebtDisplay.textContent = 'بەبێ قەرز';
                    customerDebtDisplay.className = 'customer-badge-debt no-debt';
                    customerDebtDisplay.style.display = 'block';
                    customerDebtDisplay.style.flexDirection = '';
                    customerDebtDisplay.style.gap = '';
                }
                
                // Show selected state
                customerSearchState.style.display = 'none';
                customerSelectedState.style.display = 'block';
            } else {
                document.getElementById('selectedCustomerId').value = '';
                document.getElementById('customerName').value = '';
                document.getElementById('customerPhone').value = '';
                
                // Show search state
                customerSearchState.style.display = 'block';
                customerSelectedState.style.display = 'none';
            }
        }

        async function loadCustomerById(customerId) {
            const customer = await fetchCustomerById(customerId);
            if (customer) {
                selectCustomer(customer);
            }
        }


        // Resize handle setup
        function setupResizeHandle() {
            const resizeHandle = document.getElementById('resizeHandle');
            const rightResizeHandle = document.getElementById('rightResizeHandle');
            const leftSection = document.getElementById('leftSection');
            const rightSection = document.getElementById('rightSection');
            
            // Left section (cart) resize
            resizeHandle.addEventListener('mousedown', (e) => {
                POS.isResizing = 'left';
                document.addEventListener('mousemove', handleResize);
                document.addEventListener('mouseup', stopResize);
                e.preventDefault();
            });
            
            // Right section (controls) resize
            rightResizeHandle.addEventListener('mousedown', (e) => {
                POS.isResizing = 'right';
                document.addEventListener('mousemove', handleResize);
                document.addEventListener('mouseup', stopResize);
                e.preventDefault();
            });
        }

        function handleResize(e) {
            if (!POS.isResizing) return;
            
            const container = document.querySelector('.main-layout');
            const containerRect = container.getBoundingClientRect();
            
            if (POS.isResizing === 'left') {
                const leftSection = document.getElementById('leftSection');
                const newWidth = ((e.clientX - containerRect.left) / containerRect.width) * 100;
                
                if (newWidth >= 20 && newWidth <= 60) {
                    leftSection.style.width = newWidth + '%';
                }
            } else if (POS.isResizing === 'right') {
                const rightSection = document.getElementById('rightSection');
                const newWidth = ((containerRect.right - e.clientX) / containerRect.width) * 100;
                
                if (newWidth >= 20 && newWidth <= 60) {
                    rightSection.style.width = newWidth + '%';
                }
            }
        }

        function stopResize() {
            POS.isResizing = null;
            document.removeEventListener('mousemove', handleResize);
            document.removeEventListener('mouseup', stopResize);
        }

        // Product double-click info popup
        function showProductInfo(product) {
            const popup = document.getElementById('productInfoPopup');
            const content = document.getElementById('productInfoContent');
            
            const profitPerUnit = product.sell_price - (product.cost_price || 0);
            const totalProfit = profitPerUnit * product.stock_quantity;
            
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>زانیاری کاڵا</h6>
                        <p><strong>ناو:</strong> ${product.name}</p>
                        <p><strong>بارکۆد:</strong> ${product.barcode || 'نییە'}</p>
                        <p><strong>بەردەست:</strong> ${product.stock_quantity}</p>
                        <p><strong>نرخی کڕین:</strong> ${formatMoney(product.cost_price || 0)}</p>
                        <p><strong>نرخی فرۆشتن:</strong> ${formatMoney(product.sell_price)}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>زانیاری قازانج</h6>
                        <p><strong>قازانجی هەر دانەیەک:</strong> ${formatMoney(profitPerUnit)}</p>
                        <p><strong>قازانجی کۆی بەردەست:</strong> ${formatMoney(totalProfit)}</p>
                        <p><strong>بەرواری بەسەرچوون:</strong> ${product.expiry_date || 'نەناسراو'}</p>
                        <p><strong>بڕی کەمترین بەردەست:</strong> ${product.min_stock || 0}</p>
                    </div>
                </div>
            `;
            
            popup.style.display = 'block';
            
            // Auto-hide after 10 seconds
            setTimeout(() => {
                popup.style.display = 'none';
            }, 10000);
        }

        function hideProductInfo() {
            document.getElementById('productInfoPopup').style.display = 'none';
        }

        // Out of stock popup
        function showOutOfStockPopup(productName) {
            const popup = document.getElementById('outOfStockPopup');
            const message = document.getElementById('outOfStockMessage');
            
            // Always show a generic not-available message (do not include product name)
            message.textContent = 'ئەم کاڵایە بەردەست نییە';
            popup.style.display = 'block';
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                popup.style.display = 'none';
            }, 5000);
        }

        // Update product card to handle double-click
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
                     onclick="addToCart(${normalizedProduct.id})"
                     ondblclick="showProductInfo(${normalizedProduct.id})">

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

        // ===== FINAL INITIALIZATION =====
        // console.log('POS System Script Loaded');
