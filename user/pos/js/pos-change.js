        // ===== CHANGE CALCULATOR FUNCTIONS =====
        // Cache for exchange rate
        let cachedExchangeRate = null;
        let exchangeRateFetchPromise = null;
        // Track currency click counts: { amount: count }
        let currencyClickCounts = {};

        /**
         * وەرگرتنی ڕێژەی ئاڵوگۆڕکردنی دراوە لە API
         */
        async function getCurrentExchangeRate() {
            // ئەگەر پێشتر وەرگیرابێت، بەکاربهێنە
            if (cachedExchangeRate !== null) {
                return cachedExchangeRate;
            }

            // ئەگەر داواکاریێک لە کاردایە، چاوەڕێی بکە
            if (exchangeRateFetchPromise) {
                return await exchangeRateFetchPromise;
            }

            // دروستکردنی داواکاری نوێ
            exchangeRateFetchPromise = posFetch('ajax/get_exchange_rate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    csrf_token: POS.csrfToken
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.exchange_rate) {
                    cachedExchangeRate = parseFloat(data.exchange_rate);
                    return cachedExchangeRate;
                } else {
                    // لە کاتی هەڵەدا، بەکارهێنانی default
                    cachedExchangeRate = 1400.0;
                    return cachedExchangeRate;
                }
            })
            .catch(error => {
                console.error('هەڵە لە وەرگرتنی ڕێژەی ئاڵوگۆڕکردن:', error);
                cachedExchangeRate = 1400.0; // default rate
                return cachedExchangeRate;
            })
            .finally(() => {
                exchangeRateFetchPromise = null;
            });

            return await exchangeRateFetchPromise;
        }

        function showChangeCard() {
            // Check if cart is empty
            if (POS.cart.length === 0) {
                showNotification('سەبەتە بەتاڵە', 'warning');
                return;
            }

            // Reset currency click counts when opening the card
            currencyClickCounts = {};

            // Calculate current total
            const totalAmount = POS.cart.reduce((sum, item) => sum + item.total, 0);
            const discountAmount = parseFloat(document.getElementById('discountAmount').value) || 0;
            const finalAmount = roundPriceByCurrency(Math.max(0, totalAmount - discountAmount), POS.currentCurrency);

            // Create card HTML
            const cardHTML = `
                <div class="change-card-overlay" id="changeCardOverlay" onclick="closeChangeCard()">
                    <div class="change-card" data-final-amount="${finalAmount}" onclick="event.stopPropagation()">
                        <div class="change-card-header">
                            <h3>
                                <i class="bi bi-cash-coin"></i>
                                باقی دانەوە
                            </h3>
                            <button class="change-card-close" onclick="closeChangeCard()" type="button">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div class="change-card-body">
                            <div class="change-info-section">
                                <div class="change-info-row">
                                    <span class="change-info-label">کۆی گشتی:</span>
                                    <span class="change-info-value">${formatMoney(finalAmount)}</span>
                                </div>
                                <div class="change-info-row">
                                    <span class="change-info-label">بڕی پارە:</span>
                                    <span class="change-info-value" id="paidAmountDisplay">0 دینار</span>
                                </div>
                            </div>
                            <div class="change-currency-section">
                                <h4>هەڵبژاردنی پارە</h4>
                                <div class="change-currency-buttons">
                                    <button class="change-currency-btn" data-is-dollar="true" data-dollar-amount="100" id="dollar100Btn">
                                    <img src="../../user/images/baqy/100$.jpg" alt="100$">
                                    </button>
                                     <button class="change-currency-btn" data-amount="50000">
                                        <img src="../../user/images/baqy/50,000.png" alt="50,000 دینار">
                                    </button>
                                    <button class="change-currency-btn" data-amount="25000">
                                        <img src="../../user/images/baqy/25,000.png" alt="25,000 دینار">
                                    </button>
                                    <button class="change-currency-btn" data-amount="10000">
                                        <img src="../../user/images/baqy/10,000.png" alt="10,000 دینار">
                                    </button>
                                    <button class="change-currency-btn" data-amount="5000">
                                        <img src="../../user/images/baqy/5,000.png" alt="5,000 دینار">
                                    </button>
                                   
                                </div>
                                <button class="change-reset-btn" onclick="event.stopPropagation(); resetChangeCard();" type="button">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                    سفرکردنەوە
                                </button>
                            </div>
                            <div class="change-amount-display" id="changeAmountDisplay" style="display: none;">
                                <div class="change-amount-label">باقی دانەوە</div>
                                <div class="change-amount-value" id="changeAmountValue">0 دینار</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Show card
            document.body.insertAdjacentHTML('beforeend', cardHTML);
            
            // Add event listeners to currency buttons after card is created
            setTimeout(() => {
                const currencyButtons = document.querySelectorAll('.change-currency-btn');
                currencyButtons.forEach(btn => {
                    btn.addEventListener('click', async function(e) {
                        e.stopPropagation();
                        
                        // چیکردنی ئەگەر دوگمەی دۆلارە
                        if (this.dataset.isDollar === 'true') {
                            const dollarBtn = this;
                            const dollarAmount = parseFloat(this.dataset.dollarAmount) || 100;
                            
                            // نیشاندانی loading state
                            dollarBtn.classList.add('loading');
                            dollarBtn.disabled = true;
                            
                            try {
                                // وەرگرتنی ڕێژەی ئاڵوگۆڕکردن
                                const exchangeRate = await getCurrentExchangeRate();
                                
                                // حسابکردنی بڕی دینار: 100 * exchange_rate
                                let iqdAmount = Math.round(100 * exchangeRate);
                                
                                // سڕینەوەی دوو 0 سفری کۆتایی (142000 → 1420)
                                iqdAmount = Math.floor(iqdAmount / 100);
                                
                                // Store the calculated amount in button data for tracking
                                dollarBtn.dataset.calculatedAmount = iqdAmount.toString();
                                
                                // بەکارهێنانی handleChangeCurrencyClick
                                handleChangeCurrencyClick(iqdAmount, dollarBtn);
                            } catch (error) {
                                console.error('هەڵە لە حسابکردنی دۆلار:', error);
                                showNotification('هەڵەیەک ڕوویدا لە حسابکردنی دۆلار', 'error');
                            } finally {
                                // لابردنی loading state
                                dollarBtn.classList.remove('loading');
                                dollarBtn.disabled = false;
                            }
                        } else {
                            // بۆ دوگمەکانی تر، وەک پێشتر
                            const amount = parseInt(this.dataset.amount);
                            handleChangeCurrencyClick(amount, this);
                        }
                    });
                });
            }, 100);
        }

        function closeChangeCard() {
            const overlay = document.getElementById('changeCardOverlay');
            if (overlay) {
                overlay.remove();
            }
            // Reset currency counts when closing
            currencyClickCounts = {};
        }

        function resetChangeCard() {
            // Reset currency click counts
            currencyClickCounts = {};
            
            // Reset paid amount display
            const paidAmountDisplay = document.getElementById('paidAmountDisplay');
            if (paidAmountDisplay) {
                paidAmountDisplay.textContent = '0 دینار';
            }
            
            // Hide change amount display
            const changeAmountDisplay = document.getElementById('changeAmountDisplay');
            if (changeAmountDisplay) {
                changeAmountDisplay.style.display = 'none';
            }
            
            // Remove active states and count badges from all buttons
            document.querySelectorAll('.change-currency-btn').forEach(btn => {
                btn.classList.remove('active');
                const existingBadge = btn.querySelector('.currency-count-badge');
                if (existingBadge) {
                    existingBadge.remove();
                }
                // Clear calculated amount for dollar button
                if (btn.dataset.isDollar === 'true' && btn.dataset.calculatedAmount) {
                    delete btn.dataset.calculatedAmount;
                }
            });
        }

        function handleChangeCurrencyClick(amount, buttonElement) {
            // نۆرمالکردنی بڕی گۆڕانکاری:
            // ئەگەر بڕەکە وەک 5 یان -5 دابنرێت، وەک 500 / -500 کاربکە ( * 100 )
            let normalizedAmount = amount;
            if (Math.abs(normalizedAmount) > 0 && Math.abs(normalizedAmount) < 100) {
                normalizedAmount = normalizedAmount * 100;
            }
            // Get final amount from card data attribute
            const card = document.querySelector('.change-card');
            if (!card) {
                console.error('Change card not found');
                return;
            }

            const finalAmount = parseFloat(card.dataset.finalAmount) || 0;
            
            // Increment click count for this currency amount
            const amountKey = normalizedAmount.toString();
            if (!currencyClickCounts[amountKey]) {
                currencyClickCounts[amountKey] = 0;
            }
            currencyClickCounts[amountKey]++;

            // Calculate total paid amount from all currencies
            let totalPaid = Object.entries(currencyClickCounts).reduce((sum, [amt, count]) => {
                return sum + (parseFloat(amt) * count);
            }, 0);

            // بەکاربردنی manual_adjustment ئەگەر هەیە (ئێستا already *100 لە PHP کراوە بۆ حاڵەتەکانی 5 / -5)
            let manualAdj = typeof POS.manualChangeAdjustment === 'number'
                ? POS.manualChangeAdjustment
                : parseFloat(POS.manualChangeAdjustment || 0);
            if (!isNaN(manualAdj) && Math.abs(manualAdj) > 0) {
                totalPaid += manualAdj;
            }

            // Calculate change
            const change = calculateChange(totalPaid, finalAmount);

            // Update paid amount display
            const paidAmountDisplay = document.getElementById('paidAmountDisplay');
            if (paidAmountDisplay) {
                paidAmountDisplay.textContent = formatMoney(totalPaid);
            }

            // Update button states and show count badges
            document.querySelectorAll('.change-currency-btn').forEach(btn => {
                btn.classList.remove('active');
                // Remove existing count badge
                const existingBadge = btn.querySelector('.currency-count-badge');
                if (existingBadge) {
                    existingBadge.remove();
                }
                
                // Get amount for this button
                let btnAmount = null;
                if (btn.dataset.isDollar === 'true') {
                    // For dollar button, use calculated amount if available
                    if (btn.dataset.calculatedAmount) {
                        btnAmount = parseFloat(btn.dataset.calculatedAmount);
                    }
                } else {
                    btnAmount = parseInt(btn.dataset.amount);

                    // نۆرمالکردنی خانەکانی وەکو 5 / -5 بۆ * 100
                    if (Math.abs(btnAmount) > 0 && Math.abs(btnAmount) < 100) {
                        btnAmount = btnAmount * 100;
                    }
                }
                
                // Show count badge if this button has clicks
                if (btnAmount !== null && currencyClickCounts[btnAmount.toString()] > 0) {
                    const count = currencyClickCounts[btnAmount.toString()];
                    btn.classList.add('active');
                    const badge = document.createElement('span');
                    badge.className = 'currency-count-badge';
                    badge.textContent = `×${count}`;
                    btn.appendChild(badge);
                }
            });

            // Show change amount
            const changeAmountDisplay = document.getElementById('changeAmountDisplay');
            const changeAmountValue = document.getElementById('changeAmountValue');
            
            if (changeAmountDisplay && changeAmountValue) {
                if (change >= 0) {
                    changeAmountDisplay.style.display = 'block';
                    changeAmountValue.textContent = formatMoney(change);
                } else {
                    changeAmountDisplay.style.display = 'none';
                    showNotification('بڕی پارە کەمترە لە کۆی گشتی', 'warning');
                }
            }
        }

        function calculateChange(paidAmount, finalAmount) {
            return roundPriceByCurrency(Math.max(0, paidAmount - finalAmount), POS.currentCurrency);
        }

        function updateQuantityInput(index, newQuantity, element) {
            if (index >= 0 && index < POS.cart.length) {
                const item = POS.cart[index];
                const qtyStr = String(newQuantity ?? '').trim();
                if (qtyStr === '' || qtyStr === '.') return;

                const quantity = parseFloat(qtyStr);
                if (isNaN(quantity) || quantity <= 0) {
                    if (!element) {
                        removeFromCart(index);
                    }
                    return;
                }
                
                item.quantity = quantity;
                item.total = roundPriceByCurrency(item.quantity * item.price, POS.currentCurrency);
                
                const factor = parseFloat(item.conversion_factor) || 1;
                const totalMeters = Number((quantity * factor).toFixed(3));

                if (element) {
                    const row = element.closest('tr');
                    if (row) {
                        const meterInput = row.querySelector('.fabric-meter-input');
                        if (meterInput && document.activeElement !== meterInput) {
                            meterInput.value = totalMeters;
                        }
                        const totalInput = row.querySelector('.total-input');
                        if (totalInput && document.activeElement !== totalInput) {
                            totalInput.value = item.total;
                        }
                    }
                    updateTotalDisplay();
                    saveCartToStorage();
                } else {
                    updateCartDisplay();
                    saveCartToStorage();
                }
            }
        }

        function updateCartFabricMeters(index, newMeters, element) {
            if (index >= 0 && index < POS.cart.length) {
                const item = POS.cart[index];
                const metersStr = String(newMeters ?? '').trim();
                if (metersStr === '' || metersStr === '.') return;

                const meters = parseFloat(metersStr);
                if (isNaN(meters) || meters <= 0) return;

                const factor = parseFloat(item.conversion_factor) || 1;
                const unitName = (item.unit_name || '').trim();
                
                if (unitName.includes('تۆپ') || (factor > 1 && !unitName.includes('مەتر') && !unitName.includes('سم'))) {
                    item.quantity = Math.round((meters / factor) * 1000) / 1000;
                } else {
                    item.quantity = meters;
                }
                
                item.total = roundPriceByCurrency(item.quantity * item.price, POS.currentCurrency);

                if (element) {
                    const row = element.closest('tr');
                    if (row) {
                        const qtyInput = row.querySelector('.quantity-input');
                        if (qtyInput && document.activeElement !== qtyInput) {
                            qtyInput.value = item.quantity;
                        }
                        const totalInput = row.querySelector('.total-input');
                        if (totalInput && document.activeElement !== totalInput) {
                            totalInput.value = item.total;
                        }
                    }
                    updateTotalDisplay();
                    saveCartToStorage();
                } else {
                    updateCartDisplay();
                    saveCartToStorage();
                }
            }
        }

        function finishCartFabricMeters(index, newMeters, element) {
            if (index >= 0 && index < POS.cart.length) {
                const item = POS.cart[index];
                const meters = parseFloat(newMeters);
                if (isNaN(meters) || meters <= 0) {
                    updateCartDisplay();
                    return;
                }
                updateCartFabricMeters(index, newMeters, element);
                updateCartDisplay();
            }
        }

        function updatePrice(index, newPrice, element) {
            if (POS.canEditPrice === false) {
                if (element && POS.cart[index]) { element.value = POS.cart[index].price; }
                return;
            }
            if (index >= 0 && index < POS.cart.length) {
                const item = POS.cart[index];
                const price = parseFloat(newPrice);
                
                if (isNaN(price) || price < 0) {
                    // Reset to current price if invalid
                    if (element) {
                        element.value = item.price;
                    }
                    return;
                }
                
                item.price = roundPriceByCurrency(price, POS.currentCurrency);
                item.total = roundPriceByCurrency(item.quantity * item.price, POS.currentCurrency);
                // نیشانەکردنی ئەم ئایتمە وەک نرخی دەستکاریکراو بۆ ئەوەی نوێکردنەوەی
                // ئۆتۆماتیکی (بۆ نموونە کاتی گەڕانەوە بۆ تاب) نرخەکەی نەگۆڕێتەوە.
                item.priceOverridden = true;

                updateCartDisplay();
                saveCartToStorage();
            }
        }

        function updateTotal(index, newTotal, element) {
            if (POS.canEditTotal === false) {
                if (element && POS.cart[index]) { element.value = POS.cart[index].total; }
                return;
            }
            if (index >= 0 && index < POS.cart.length) {
                const item = POS.cart[index];
                const total = parseFloat(newTotal);
                
                if (isNaN(total) || total < 0) {
                    // Reset to current total if invalid
                    if (element) {
                        element.value = item.total;
                    }
                    return;
                }
                
                if (item.quantity <= 0) {
                    showNotification('بڕی کاڵا نابێت بچووکتر بێت یان یەکسان بێت بە سفر', 'warning');
                    if (element) {
                        element.value = item.total;
                    }
                    return;
                }
                
                item.total = roundPriceByCurrency(total, POS.currentCurrency);
                item.price = roundPriceByCurrency(item.total / item.quantity, POS.currentCurrency);
                // نیشانەکردنی ئەم ئایتمە وەک نرخی دەستکاریکراو (لە ڕێگەی کۆی گشتییەوە).
                item.priceOverridden = true;

                updateCartDisplay();
                saveCartToStorage();
            }
        }

        function updateTotalDisplay() {
            const totalAmount = POS.cart.reduce((sum, item) => sum + item.total, 0);
            const discountAmount = parseFloat(document.getElementById('discountAmount').value) || 0;
            const finalAmount = roundPriceByCurrency(Math.max(0, totalAmount - discountAmount), POS.currentCurrency);
            
            document.getElementById('totalAmount').textContent = formatMoney(finalAmount);
        }

        function toggleDiscountSection() {
            const container = document.getElementById('discountContainer');
            const icon = document.getElementById('discountToggleIcon');
            const isVisible = container.style.display !== 'none';
            
            if (isVisible) {
                container.style.display = 'none';
                icon.classList.remove('bi-chevron-up');
                icon.classList.add('bi-chevron-down');
            } else {
                container.style.display = 'block';
                icon.classList.remove('bi-chevron-down');
                icon.classList.add('bi-chevron-up');
            }
        }

        function clearDiscount() {
            document.getElementById('discountAmount').value = '';
            document.querySelectorAll('.discount-btn').forEach(btn => btn.classList.remove('active'));
            updateTotalDisplay();
        }

        function applyPercentageDiscount(percentage, buttonElement) {
            const totalAmount = POS.cart.reduce((sum, item) => sum + item.total, 0);
            const discountAmount = roundPriceByCurrency((totalAmount * percentage) / 100, POS.currentCurrency);
            const decimals = POS.currentCurrency === 'USD' ? 2 : 0;
            
            document.getElementById('discountAmount').value = discountAmount.toFixed(decimals);
            
            // Update button states
            document.querySelectorAll('.discount-btn').forEach(btn => btn.classList.remove('active'));
            buttonElement.classList.add('active');
            
            updateTotalDisplay();
        }

        function clearCart() {
            if (POS.cart.length === 0) return;
            
            if (confirm('ئایا دڵنیایت لە پاککردنەوەی سەبەتەکە؟')) {
                POS.cart = [];
                POS.currentInteractionConflicts = [];
                POS.knownConflictKeys = new Set();
                updateCartDisplay();
                saveCartToStorage();
                
                resetCurrentPriceTypeToDefault();
                
                // Reset payment method to account default
                document.getElementById('paymentMethod').value = POS.defaultPaymentMethod;
                document.getElementById('walletId').value = POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '';
                syncPaymentMethodUI();
                document.getElementById('paidAmountWrapper').style.display = 'none';
                document.getElementById('customerContainer').style.display = 'flex';
                document.getElementById('paidAmount').value = '';
                
                // Clear customer selection
                clearSelectedCustomer();
                
                showNotification('سەبەتە پاک کرایەوە', 'info');
            }
        }

