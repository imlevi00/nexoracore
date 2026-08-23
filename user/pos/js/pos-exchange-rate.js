        // ===== گۆڕینی نرخی دۆلار لەناو POS (تایبەت بەم ئەکاونتە و کارمەندەکانی) =====
        (function () {
            function fmtInt(n) {
                return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(n);
            }

            function openDollarEditor() {
                const view = document.getElementById('dollarPriceView');
                const edit = document.getElementById('dollarPriceEdit');
                const input = document.getElementById('dollarRateInput');
                if (!view || !edit || !input) return;
                view.style.display = 'none';
                edit.style.display = 'block';
                input.value = (POS.dollarPrice && POS.dollarPrice > 0) ? String(Math.round(POS.dollarPrice)) : '';
                input.focus();
                input.select();
            }

            function closeDollarEditor() {
                const view = document.getElementById('dollarPriceView');
                const edit = document.getElementById('dollarPriceEdit');
                if (!view || !edit) return;
                edit.style.display = 'none';
                view.style.display = 'block';
            }

            /**
             * دووبارە حیسابکردنی نرخی سەبەتە بۆ نرخی نوێی دۆلار.
             * تەنها کاڵا نادەستکاریکراوەکان کە دراوەیان جیاوازە لە دراوی ئێستا دەگۆڕێن؛
             * نرخە دەستکاریکراوەکان (priceOverridden) وەک خۆیان دەمێننەوە.
             */
            function recomputeCartForNewRate() {
                if (POS.activeTabId && typeof saveCurrentTabData === 'function') {
                    saveCurrentTabData();
                }
                (POS.tabs || []).forEach(function (tab) {
                    (tab.cart || []).forEach(function (item) {
                        if (item.isExternal) return;
                        const product = (POS.products || []).find(function (p) {
                            return Number(p.id) === Number(item.id);
                        });
                        if (product && typeof syncCartItemFromProduct === 'function') {
                            syncCartItemFromProduct(item, product);
                        }
                    });
                });
                if (POS.activeTabId) {
                    const activeTab = (POS.tabs || []).find(function (t) { return t.id === POS.activeTabId; });
                    if (activeTab) {
                        POS.cart = activeTab.cart.slice();
                    }
                }
                if (typeof updateCartDisplay === 'function') updateCartDisplay();
                if (typeof updateTotalDisplay === 'function') updateTotalDisplay();
                if (typeof saveCartToStorage === 'function') saveCartToStorage();
                if ((POS.products || []).length > 0 && typeof displayProducts === 'function') {
                    displayProducts(POS.products);
                }
            }

            async function saveDollarRate() {
                const input = document.getElementById('dollarRateInput');
                const saveBtn = document.getElementById('dollarRateSaveBtn');
                if (!input) return;

                const rate = parseFloat(input.value);
                if (isNaN(rate) || rate <= 100 || rate >= 10000) {
                    showNotification('نرخی دۆلار دەبێت لە نێوان ١٠٠ و ١٠٠٠٠ دیناردا بێت', 'warning');
                    input.focus();
                    return;
                }

                if (saveBtn) saveBtn.disabled = true;
                try {
                    const response = await posFetch('ajax/set_exchange_rate.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: POS.csrfToken, exchange_rate: rate })
                    });
                    const data = await response.json();

                    if (data && data.success) {
                        const newRate = parseFloat(data.exchange_rate) || rate;
                        POS.dollarPrice = newRate;

                        // بەتاڵکردنی cache ـی نرخی کارتی «باقی دانەوە» تاوەکو نرخی نوێ بەکاربهێنێت
                        try { cachedExchangeRate = null; } catch (e) { /* pos-change.js نەخوێندراوەتەوە */ }

                        const valueEl = document.getElementById('dollarPriceValue');
                        if (valueEl) valueEl.textContent = fmtInt(newRate);
                        const updatedEl = document.getElementById('dollarLastUpdated');
                        if (updatedEl) updatedEl.textContent = 'ئێستا';

                        recomputeCartForNewRate();
                        closeDollarEditor();
                        showNotification('نرخی دۆلار نوێکرایەوە: 1$ = ' + fmtInt(newRate) + ' دینار', 'success');
                    } else {
                        showNotification((data && data.message) ? data.message : 'هەڵەیەک ڕوویدا', 'danger');
                    }
                } catch (e) {
                    console.error('saveDollarRate error:', e);
                    showNotification('کێشەی پەیوەندی — دووبارە هەوڵ بدە', 'danger');
                } finally {
                    if (saveBtn) saveBtn.disabled = false;
                }
            }

            function initExchangeRateEditor() {
                const view = document.getElementById('dollarPriceView');
                const saveBtn = document.getElementById('dollarRateSaveBtn');
                const cancelBtn = document.getElementById('dollarRateCancelBtn');
                const input = document.getElementById('dollarRateInput');

                if (view) {
                    view.addEventListener('click', openDollarEditor);
                    view.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            openDollarEditor();
                        }
                    });
                }
                if (saveBtn) saveBtn.addEventListener('click', saveDollarRate);
                if (cancelBtn) cancelBtn.addEventListener('click', closeDollarEditor);
                if (input) {
                    input.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            saveDollarRate();
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            closeDollarEditor();
                        }
                    });
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initExchangeRateEditor);
            } else {
                initExchangeRateEditor();
            }
        })();
