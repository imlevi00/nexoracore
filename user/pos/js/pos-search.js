        // ===== SEARCH FUNCTIONALITY =====
        function handleBarcodeInput(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                clearTimeout(POS.searchTimeout);
                cancelPosSearchRequests();
                const barcode = event.target.value.trim();

                if (barcode && !POS.barcodeScanEnabled && isLikelyHardwareScanner(barcode.length)) {
                    showPackageFeatureLockNotification();
                    event.target.value = '';
                    hideSearchResults();
                    resetBarcodeInputTiming();
                    return;
                }

                if (barcode) {
                    searchProductByBarcode(barcode);
                    event.target.value = '';
                    hideSearchResults();
                }
                resetBarcodeInputTiming();
            } else if (event.key === 'Escape') {
                event.target.value = '';
                hideSearchResults();
                resetBarcodeInputTiming();
            } else {
                trackBarcodeInputKeydown(event);
            }
        }

        function handleSearchInput(event) {
            const query = event.target.value.trim();
            
            clearTimeout(POS.searchTimeout);
            cancelPosSearchRequests();
            
            if (query.length < 2) {
                hideSearchResults();
                return;
            }
            
            // If query looks like a barcode (numeric string, typically 4+ digits), search by barcode first
            // Also check if it's all digits and at least 4 characters
            const isBarcode = /^\d{4,}$/.test(query);
            
            POS.searchTimeout = setTimeout(() => {
                if (isBarcode) {
                    // Try barcode search first and show in search results
                    searchProductByBarcode(query, true).then(result => {
                        if (result && result.aborted) {
                            return;
                        }
                        if (!result || !result.success) {
                            // If barcode search fails, fall back to name search
                            searchProducts(query);
                        }
                    }).catch((err) => {
                        if (isPosSearchAbortError(err)) {
                            return;
                        }
                        // If error, fall back to name search
                        searchProducts(query);
                    });
                } else {
                    // Regular name search
                    searchProducts(query);
                }
            }, 300);
        }

        async function searchProducts(query) {
            const searchController = createPosSearchAbortController();
            try {
                const zeroStockParam = POS.posShowZeroStockProducts ? '&include_zero_stock=1' : '';
                const url = `../../api/products.php?action=search&q=${encodeURIComponent(query)}&user_id=${POS.userId}&limit=50${zeroStockParam}`;
                const { data } = await posFetchJson(url, { signal: searchController.signal });

                if (data.success) {
                    const products = data.data.products || [];
                    const services = await searchServicesByName(query, searchController.signal);
                    displaySearchResults([...services, ...products]);
                } else {
                    const resultsContainer = document.getElementById('searchResults');
                    if (resultsContainer) {
                        resultsContainer.innerHTML = '<div class="search-result-item text-muted">هیچ کاڵایەک نەدۆزرایەوە</div>';
                        resultsContainer.style.display = 'block';
                    }
                }
            } catch (error) {
                if (isPosSearchAbortError(error)) {
                    return;
                }
                const resultsContainer = document.getElementById('searchResults');
                const msg = getPosApiErrorMessage(error);
                if (resultsContainer) {
                    resultsContainer.innerHTML = `<div class="search-result-item text-danger">${msg}</div>`;
                    resultsContainer.style.display = 'block';
                }
            }
        }

        async function searchServicesByName(query, signal) {
            try {
                const result = await fetchPosServices({ search: query, limit: 30, signal });
                if (result.denied || !result.ok) {
                    return [];
                }
                return result.services || [];
            } catch (error) {
                if (isPosSearchAbortError(error)) {
                    throw error;
                }
                return [];
            }
        }

        function isProductExpired(product) {
            // Treat empty/zero dates as no-expiry
            const rawExpiry = (product && product.expiry_date ? String(product.expiry_date).trim() : '');
            if (!rawExpiry || rawExpiry === '0000-00-00' || rawExpiry === '0000-00-00 00:00:00') {
                return false;
            }

            // If backend already provides this flag, trust it
            if (typeof product.is_expired !== 'undefined') {
                return !!product.is_expired;
            }

            const expiryDate = new Date(rawExpiry);
            if (Number.isNaN(expiryDate.getTime())) {
                return false;
            }
            const expiryDay = expiryDate.toISOString().slice(0, 10);
            const todayDay = new Date().toISOString().slice(0, 10);
            return expiryDay < todayDay;
        }

        function isLikelyScaleBarcodeForPos(barcode) {
            const settings = POS.scaleSettings || {};
            const enabled = settings.is_enabled === 1 || settings.is_enabled === '1' || settings.is_enabled === true;
            if (!enabled) {
                return false;
            }

            const prefix = String(settings.prefix || '').replace(/\D/g, '');
            const totalDigits = parseInt(settings.total_digits, 10);
            const code = String(barcode || '').trim();

            if (!code || !/^\d+$/.test(code) || !Number.isFinite(totalDigits)) {
                return false;
            }

            if (code.length !== totalDigits) {
                return false;
            }

            return prefix !== '' && code.startsWith(prefix);
        }

        function finalizeScaleBarcodeCartAdd(product, weightKg, showInSearchResults) {
            const hasStockFlag = typeof product.has_stock !== 'undefined' ? !!product.has_stock : null;
            const isAvailableFlag = typeof product.is_available !== 'undefined' ? !!product.is_available : null;

            let hasStock = hasStockFlag;
            if (hasStock === null) {
                hasStock = false;
                if (Array.isArray(product.units) && product.units.length > 0) {
                    for (const unit of product.units) {
                        if (unit.stock_quantity > 0) {
                            hasStock = true;
                            break;
                        }
                    }
                } else if (typeof product.stock_quantity === 'number' && product.stock_quantity > 0) {
                    hasStock = true;
                }
            }

            const isExpired = isProductExpired(product);
            const isAvailable = isAvailableFlag !== null ? isAvailableFlag : (hasStock && !isExpired);

            if (showInSearchResults) {
                const showInDropdown = !isExpired && (POS.posShowZeroStockProducts || isAvailable);
                if (showInDropdown) {
                    displaySearchResults([product]);
                    return { success: true, product: product };
                }
                return { success: false };
            }

            if (isExpired) {
                showNotification('ئەم کاڵایە بەردەست نییە', 'warning');
                return { success: false };
            }

            addProductToCart(product, weightKg, getPrimaryUnitIndex(product));
            showNotification(`${product.name} (${weightKg} کگ) زیادکرا بۆ سەبەتە`, 'success');
            return { success: true, product: product };
        }

        async function searchScaleProductByBarcode(barcode, showInSearchResults = false, searchController = null) {
            if (!isLikelyScaleBarcodeForPos(barcode)) {
                return {
                    success: false,
                    message: 'قەپانی زیرەک چالاک نییە'
                };
            }

            const controller = searchController || createPosSearchAbortController();
            const url = `../../api/scale_products.php?action=barcode&code=${encodeURIComponent(barcode)}&user_id=${POS.userId}`;
            const { data: apiBody } = await posFetchJson(url, { signal: controller.signal });

            if (!apiBody?.success || !apiBody.data?.product) {
                return {
                    success: false,
                    message: apiBody?.message || 'کاڵای قەپان نەدۆزرایەوە'
                };
            }

            const product = normalizeProductUnits({ ...apiBody.data.product });
            const scale = apiBody.data.scale || {};
            const weightKg = parseFloat(scale.weight_kg || 0);

            if (!Number.isFinite(weightKg) || weightKg <= 0) {
                return {
                    success: false,
                    message: 'ناتوانرێت بڕی کیلۆ لە بارکۆد دەرهێنرێت'
                };
            }

            product.is_scale_product = true;
            product._scale_weight_kg = weightKg;
            upsertProduct(product);

            return finalizeScaleBarcodeCartAdd(product, weightKg, showInSearchResults);
        }

        /**
         * دۆزینەوەی کاڵا لە کاشی خۆماڵی (POS.products) بەپێی بارکۆدی سەرەکی.
         * بۆ خێراکردنی سکان — لە جیاتی چاوەڕوانی تۆڕ.
         */
        function findCachedProductByBarcode(barcode) {
            if (!barcode || !Array.isArray(POS.products)) {
                return null;
            }
            const code = String(barcode).trim();
            if (!code) {
                return null;
            }
            return POS.products.find(p => p && String(p.barcode || '').trim() === code) || null;
        }

        async function searchProductByBarcode(barcode, showInSearchResults = false) {
            // ===== خێراکردنی سکان: زیادکردن لە کاشەوە (بێ چاوەڕوانی تۆڕ) =====
            // ئەگەر سکانی ڕاستەوخۆ بێت و کاڵاکە پێشتر لە کاشدا هەبوو (وەک کرتەکردن
            // لەسەر کاڵا لە ڕیزبەندی)، دەستبەجێ زیادی بکە. کاڵای قەپان لێرە جیا دەکرێتەوە
            // چونکە کێش لەناو بارکۆدەکەدایە و پێویستی بە پرۆسێسی سێرڤەرە.
            if (!showInSearchResults && !isLikelyScaleBarcodeForPos(barcode)) {
                const cachedProduct = findCachedProductByBarcode(barcode);
                if (cachedProduct) {
                    if (isProductExpired(cachedProduct)) {
                        showNotification('ئەم کاڵایە بەردەست نییە', 'warning');
                        return { success: false };
                    }
                    addProductToCart(cachedProduct, 1, getPrimaryUnitIndex(cachedProduct));
                    showNotification('کاڵا زیادکرا بۆ سەبەتە', 'success');
                    return { success: true, product: cachedProduct };
                }
            }

            const searchController = showInSearchResults
                ? (posSearchAbortController || createPosSearchAbortController())
                : createPosSearchAbortController();

            // بۆ سکانی ڕاستەوخۆی بارکۆد: ئۆڤەرلەیی «چاوەڕوانی» یەکسەر پیشان مەدە.
            // تەنیا ئەگەر وەڵامی سێرڤەر درەنگ بوو (>250 مل.چ) پیشانی بدە، تا سکانی
            // خێرا هەست بە خاوی/خولانەوە نەکات و کاڵا دەستبەجێ زیاد ببێت.
            let delayedLoadingTimer = null;
            let loadingShown = false;
            const clearDelayedLoading = () => {
                if (delayedLoadingTimer) {
                    clearTimeout(delayedLoadingTimer);
                    delayedLoadingTimer = null;
                }
                if (loadingShown) {
                    hideLoading();
                    loadingShown = false;
                }
            };

            try {
                if (!showInSearchResults) {
                    delayedLoadingTimer = setTimeout(() => {
                        loadingShown = true;
                        showLoading();
                    }, 250);
                }

                if (isLikelyScaleBarcodeForPos(barcode)) {
                    const scaleResult = await searchScaleProductByBarcode(barcode, showInSearchResults, searchController);
                    if (scaleResult.success) {
                        return scaleResult;
                    }
                    if (!showInSearchResults) {
                        showNotification(scaleResult.message || 'کاڵای قەپان نەدۆزرایەوە', 'warning');
                    }
                    return { success: false };
                }
                
                const url = `../../api/products.php?action=barcode&code=${encodeURIComponent(barcode)}&user_id=${POS.userId}`;
                const { data: apiBody } = await posFetchJson(url, { signal: searchController.signal });

                // گەڕانەوەی { success, product? } — نەک شێوازی posFetchJson
                if (apiBody?.success && apiBody.data?.product) {
                    const product = apiBody.data.product;
                    
                    upsertProduct(product);
                    
                    // Determine availability using backend flags if present, otherwise fall back to stock/expiry checks
                    const hasStockFlag = typeof product.has_stock !== 'undefined' ? !!product.has_stock : null;
                    const isAvailableFlag = typeof product.is_available !== 'undefined' ? !!product.is_available : null;
                    
                    let hasStock = hasStockFlag;
                    if (hasStock === null) {
                        // Fallback: check units first, then main stock
                        hasStock = false;
                        if (Array.isArray(product.units) && product.units.length > 0) {
                            for (const unit of product.units) {
                                if (unit.stock_quantity > 0) {
                                    hasStock = true;
                                    break;
                                }
                            }
                        } else if (typeof product.stock_quantity === 'number' && product.stock_quantity > 0) {
                            hasStock = true;
                        }
                    }
                    
                    const isExpired = isProductExpired(product);
                    
                    const isAvailable = isAvailableFlag !== null ? isAvailableFlag : (hasStock && !isExpired);
                    
                    // If called from search input (showInSearchResults = true): لیست بەپێی ڕێکخستن (سفر) و بەسەرچوون
                    if (showInSearchResults) {
                        const showInDropdown = !isExpired && (POS.posShowZeroStockProducts || isAvailable);
                        if (showInDropdown) {
                            displaySearchResults([product]);
                            return { success: true, product: product };
                        }
                        return { success: false };
                    }
                    
                    // For direct barcode scan (no search results UI):
                    // allow zero-stock products so they can be returned later,
                    // but still block expired products.
                    if (isExpired) {
                        showNotification('ئەم کاڵایە بەردەست نییە', 'warning');
                        return { success: false };
                    }
                    
                    // Product is available: add directly to cart
                    addProductToCart(product, 1, getPrimaryUnitIndex(product));
                    showNotification('کاڵا زیادکرا بۆ سەبەتە', 'success');
                    
                    return { success: true, product: product };
                } else {
                    if (!showInSearchResults) {
                        showNotification('کاڵا بەم بارکۆدە نەدۆزرایەوە', 'warning');
                    }
                    return { success: false };
                }
            } catch (error) {
                if (isPosSearchAbortError(error)) {
                    return { success: false, aborted: true };
                }
                if (!showInSearchResults) {
                    const msg = getPosApiErrorMessage(error);
                    const type = ((Number(error?.status) || 0) === 401) ? 'warning' : 'danger';
                    showNotification(msg, type);
                }
                return { success: false, error: true };
            } finally {
                if (!showInSearchResults) {
                    clearDelayedLoading();
                }
            }
        }

        function displaySearchResults(products) {
            const resultsContainer = document.getElementById('searchResults');
            const normalizedProducts = Array.isArray(products)
                ? products.map((product) => normalizeProductUnits({ ...product }))
                : [];
            POS.searchResultProducts = normalizedProducts;
            
            if (!normalizedProducts || normalizedProducts.length === 0) {
                resultsContainer.innerHTML = '<div class="search-result-item text-muted">هیچ کاڵایەک نەدۆزرایەوە</div>';
            } else {
                // ئەگەر ڕێکخستن نیشاندانی کاڵای سفر قەدەغە بکات، تەنیا کاڵای بە ڕێژەی >٠ لە dropdown ـدا بنێرە
                const availableProducts = POS.posShowZeroStockProducts ? normalizedProducts : normalizedProducts.filter(product => {
                    if (product.is_service) {
                        return true;
                    }
                    let hasStock = false;
                    
                    if (Array.isArray(product.units) && product.units.length > 0) {
                        for (const unit of product.units) {
                            if (unit.stock_quantity > 0) {
                                hasStock = true;
                                break;
                            }
                        }
                    } else if (typeof product.stock_quantity === 'number' && product.stock_quantity > 0) {
                        hasStock = true;
                    }
                    
                    return hasStock;
                });
                
                if (availableProducts.length === 0) {
                    resultsContainer.innerHTML = '<div class="search-result-item text-muted">هیچ کاڵایەک نەدۆزرایەوە</div>';
                } else {
                    resultsContainer.innerHTML = availableProducts.map(product => {
                    // Determine price based on current price type and convert currency
                    let price = getConvertedPrice(product, POS.currentPriceType);
                    let priceClass = 'text-success';
                    
                    if (POS.currentPriceType === 'wholesale' && product.wholesale_price && product.wholesale_price > 0) {
                        priceClass = 'text-info';
                    } else if (POS.currentPriceType === 'special' && product.special_price && product.special_price > 0) {
                        priceClass = 'text-warning';
                    } else if (POS.currentPriceType !== 'retail') {
                        priceClass = 'text-muted';
                    }
                    
                    return `
                        <div class="search-result-item" onclick="addToCartFromSearch(${product.id}, ${product.is_service ? 'true' : 'false'})">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>${product.name}</strong>
                                    <br>
                                    <small class="text-muted">${product.is_service ? 'جۆر: خزمەتگوزاری' : `بارکۆد: ${product.barcode || 'نییە'}`}</small>
                                </div>
                                <div class="text-end">
                                    <span class="${priceClass} fw-bold">${formatMoney(price)}</span>
                                    <br>
                                    <small class="text-muted">${product.is_service ? 'بەردەست: خزمەت' : `بەردەست: ${product.stock_quantity}`}</small>
                                </div>
                            </div>
                        </div>
                    `;
                    }).join('');
                }
            }
            
            resultsContainer.style.display = 'block';
        }

        function hideSearchResults() {
            document.getElementById('searchResults').style.display = 'none';
        }

        function addToCartFromSearch(productId, isService = false) {
            if (isService) {
                addServiceToCart(productId);
                hideSearchResults();
                document.getElementById('barcodeInput').value = '';
                return;
            }

            const cachedProduct = (POS.searchResultProducts || []).find((p) => Number(p.id) === Number(productId))
                || POS.products.find((p) => Number(p.id) === Number(productId));

            if (cachedProduct) {
                const qty = cachedProduct._scale_weight_kg && cachedProduct._scale_weight_kg > 0
                    ? parseFloat(cachedProduct._scale_weight_kg)
                    : 1;
                addProductToCart(cachedProduct, qty, getPrimaryUnitIndex(cachedProduct));
            } else {
                fetchProductAndAddToCart(productId);
            }
            hideSearchResults();
            document.getElementById('barcodeInput').value = '';
        }

