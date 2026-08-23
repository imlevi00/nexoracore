        // ===== UTILITY FUNCTIONS =====

        const POS_DATA_VERSION = 2;

        function sortProductUnitsPrimaryFirst(units) {
            if (!Array.isArray(units) || units.length < 2) {
                return units || [];
            }
            return [...units].sort((left, right) => {
                const leftPrimary = Number(left.is_primary || 0);
                const rightPrimary = Number(right.is_primary || 0);
                if (leftPrimary !== rightPrimary) {
                    return rightPrimary - leftPrimary;
                }
                return Number(left.id) - Number(right.id);
            });
        }

        function normalizeProductUnits(product) {
            if (!product || !Array.isArray(product.units) || product.units.length === 0) {
                return product;
            }
            const sortedUnits = sortProductUnitsPrimaryFirst(product.units);
            const firstUnit = sortedUnits[0];
            return {
                ...product,
                units: sortedUnits,
                buy_price: firstUnit.buy_price,
                sell_price: firstUnit.sell_price,
                wholesale_price: firstUnit.wholesale_price,
                special_price: firstUnit.special_price,
                stock_quantity: firstUnit.stock_quantity,
                min_stock: firstUnit.min_stock,
                currency: firstUnit.currency || product.currency || 'IQD',
                default_unit_index: 0,
                default_unit_id: firstUnit.id
            };
        }

        function getPrimaryUnitIndex(product) {
            if (!product) return 0;
            if (Number.isInteger(product.default_unit_index) && product.default_unit_index >= 0) {
                return product.default_unit_index;
            }
            if (Array.isArray(product.units) && product.units.length > 0) {
                const primaryIdx = product.units.findIndex((unit) => Number(unit.is_primary) === 1);
                return primaryIdx >= 0 ? primaryIdx : 0;
            }
            return 0;
        }

        // Product is out of stock only when NO unit has available stock.
        // Mirrors the has_stock logic used in api/products.php and pos-search.js.
        function productHasAnyUnitStock(product) {
            if (!product) return false;
            if (typeof product.has_stock !== 'undefined') {
                return !!product.has_stock;
            }
            if (Array.isArray(product.units) && product.units.length > 0) {
                for (const unit of product.units) {
                    if (Number(unit.stock_quantity) > 0) {
                        return true;
                    }
                }
                return false;
            }
            return Number(product.stock_quantity) > 0;
        }

        function getUnitIndexById(product, unitId) {
            if (!product || !Array.isArray(product.units) || unitId == null) {
                return getPrimaryUnitIndex(product);
            }
            const idx = product.units.findIndex((unit) => Number(unit.id) === Number(unitId));
            return idx >= 0 ? idx : getPrimaryUnitIndex(product);
        }

        function getSelectedUnitIndexFromCard(productId) {
            const card = document.querySelector(`.product-card[data-product-id="${Number(productId)}"]`);
            if (!card) return null;
            const idx = parseInt(card.getAttribute('data-selected-unit-index'), 10);
            return Number.isFinite(idx) ? idx : null;
        }

        function isPosDataVersionStale() {
            const savedVersion = parseInt(localStorage.getItem('pos_data_version') || '0', 10);
            return savedVersion !== POS_DATA_VERSION;
        }

        function clearPosLocalStorageCache() {
            localStorage.removeItem('pos_tabs');
            localStorage.removeItem('pos_active_tab');
            localStorage.removeItem('pos_cart');
            localStorage.removeItem('pos_saved_at');
            localStorage.setItem('pos_data_version', String(POS_DATA_VERSION));
        }

        /** fetch بە no-store و cache-buster بۆ داتای تازەی POS */
        function posFetch(url, options = {}) {
            const hasTs = /[?&]_t=/.test(url);
            const sep = url.includes('?') ? '&' : '?';
            const bustUrl = hasTs ? url : `${url}${sep}_t=${Date.now()}`;
            const headers = { ...(options.headers || {}), 'Cache-Control': 'no-cache' };
            return fetch(bustUrl, { ...options, cache: 'no-store', headers });
        }

        const POS_FETCH_RETRY_DELAYS = [300, 800];
        const POS_REFRESH_MAX_CONCURRENT = 2;

        let posSearchAbortController = null;

        function cancelPosSearchRequests() {
            if (posSearchAbortController) {
                posSearchAbortController.abort();
                posSearchAbortController = null;
            }
        }

        function createPosSearchAbortController() {
            cancelPosSearchRequests();
            posSearchAbortController = new AbortController();
            return posSearchAbortController;
        }

        function isPosSearchAbortError(err) {
            return err && (err.name === 'AbortError' || err.code === 20);
        }

        async function runPosTasksWithConcurrency(tasks, maxConcurrent = POS_REFRESH_MAX_CONCURRENT) {
            if (!Array.isArray(tasks) || tasks.length === 0) {
                return [];
            }
            const results = new Array(tasks.length);
            let nextIndex = 0;

            async function worker() {
                while (nextIndex < tasks.length) {
                    const currentIndex = nextIndex;
                    nextIndex += 1;
                    try {
                        results[currentIndex] = await tasks[currentIndex]();
                    } catch (err) {
                        results[currentIndex] = null;
                    }
                }
            }

            const workerCount = Math.min(maxConcurrent, tasks.length);
            await Promise.all(Array.from({ length: workerCount }, () => worker()));
            return results;
        }

        function posFetchDelay(ms) {
            return new Promise((resolve) => setTimeout(resolve, ms));
        }

        function logPosFetchDiagnostic(url, info) {
            console.error('[POS fetch]', url, info);
        }

        function isRetryablePosFetchStatus(status) {
            return status === 0 || status >= 500;
        }

        function isRetryablePosFetchError(err) {
            if (!err) {
                return false;
            }
            if (err.name === 'TypeError') {
                return true;
            }
            if (err.kind === 'parse') {
                return isRetryablePosFetchStatus(err.status || 0);
            }
            return isRetryablePosFetchStatus(err.status || 0);
        }

        function getPosApiErrorMessage(err, statusOverride) {
            const code = Number(statusOverride ?? err?.status) || 0;
            if (code === 401) {
                return 'سێشن بەسەرچووە — تکایە پەڕە نوێ بکەرەوە';
            }
            if (code === 403) {
                return 'دەسەڵاتت نییە بۆ بینینی کاڵا';
            }
            if (code >= 500 || err?.kind === 'parse' || err?.name === 'TypeError') {
                return 'کێشەی کاتی سێرڤەر — دووبارە هەوڵ بدە';
            }
            return 'هەڵەیەک ڕوویدا لە گەڕان';
        }

        async function parsePosFetchResponse(response, url) {
            const contentType = (response.headers.get('content-type') || '').toLowerCase();
            const text = await response.text();
            if (!text || !text.trim()) {
                logPosFetchDiagnostic(url, { status: response.status, contentType, bodyPreview: '(empty)' });
                const err = new Error('empty_response');
                err.status = response.status;
                err.kind = 'parse';
                throw err;
            }
            try {
                return JSON.parse(text);
            } catch (parseError) {
                logPosFetchDiagnostic(url, {
                    status: response.status,
                    contentType,
                    bodyPreview: text.slice(0, 200)
                });
                const err = new Error('invalid_json');
                err.status = response.status;
                err.kind = 'parse';
                throw err;
            }
        }

        /**
         * fetch + JSON parse + retry بۆ گەڕانی کاڵا (GET idempotent).
         * @returns {{ response: Response, data: object, status: number, ok: boolean }}
         */
        async function posFetchJson(url, options = {}) {
            const maxRetries = typeof options.retries === 'number' ? options.retries : 2;
            const retryDelays = options.retryDelays || POS_FETCH_RETRY_DELAYS;
            const fetchOptions = { ...options };
            delete fetchOptions.retries;
            delete fetchOptions.retryDelays;
            let lastError = null;

            for (let attempt = 0; attempt <= maxRetries; attempt++) {
                if (fetchOptions.signal && fetchOptions.signal.aborted) {
                    const abortErr = new Error('aborted');
                    abortErr.name = 'AbortError';
                    throw abortErr;
                }
                try {
                    const response = await posFetch(url, fetchOptions);
                    let data;
                    try {
                        data = await parsePosFetchResponse(response, url);
                    } catch (parseErr) {
                        lastError = parseErr;
                        if (attempt < maxRetries && isRetryablePosFetchError(parseErr)) {
                            await posFetchDelay(retryDelays[attempt] ?? 500);
                            continue;
                        }
                        throw parseErr;
                    }

                    if (attempt < maxRetries && isRetryablePosFetchStatus(response.status)) {
                        await posFetchDelay(retryDelays[attempt] ?? 500);
                        continue;
                    }

                    return {
                        response,
                        data,
                        status: response.status,
                        ok: response.ok
                    };
                } catch (err) {
                    lastError = err;
                    if (isPosSearchAbortError(err)) {
                        throw err;
                    }
                    if (attempt < maxRetries && isRetryablePosFetchError(err)) {
                        await posFetchDelay(retryDelays[attempt] ?? 500);
                        continue;
                    }
                    throw err;
                }
            }

            throw lastError || new Error('pos_fetch_failed');
        }

        function upsertProduct(product) {
            if (!product || product.id == null || typeof POS === 'undefined') return;
            if (!Array.isArray(POS.products)) {
                POS.products = [];
            }
            const normalized = normalizeProductUnits({ ...product });
            const id = Number(normalized.id);
            const idx = POS.products.findIndex((p) => Number(p.id) === id);
            if (idx >= 0) {
                POS.products[idx] = normalized;
            } else {
                POS.products.push(normalized);
            }
        }

        function upsertService(service) {
            if (!service || service.id == null || typeof POS === 'undefined') return;
            if (!Array.isArray(POS.services)) {
                POS.services = [];
            }
            const id = Number(service.id);
            const idx = POS.services.findIndex((s) => Number(s.id) === id);
            if (idx >= 0) {
                POS.services[idx] = service;
            } else {
                POS.services.push(service);
            }
        }

        function mergeProductsIntoCache(products) {
            if (!Array.isArray(products)) return;
            products.forEach((p) => upsertProduct(p));
        }

        /** map service row from API to POS catalog item */
        function mapServiceRow(service) {
            return {
                id: Number(service.id),
                name: service.name,
                sell_price: parseFloat(service.sell_price || 0),
                wholesale_price: parseFloat(service.sell_price || 0),
                special_price: parseFloat(service.sell_price || 0),
                cost_price: parseFloat(service.cost_price || 0),
                stock_quantity: 999999,
                barcode: '',
                is_service: true,
                currency: POS.currentCurrency || 'IQD'
            };
        }

        function mergeCatalogItems(products, services) {
            const productItems = Array.isArray(products) ? products.filter((item) => !item.is_service) : [];
            const serviceItems = Array.isArray(services) ? services : [];
            return [...productItems, ...serviceItems].sort((left, right) =>
                String(left.name || '').localeCompare(String(right.name || ''), 'ku')
            );
        }

        function cacheServicesInPos(services) {
            if (!Array.isArray(services)) {
                return [];
            }
            const mapped = services.map(mapServiceRow);
            mapped.forEach((service) => upsertService(service));
            return mapped;
        }

        function showServicesFeatureLockNotification() {
            const lock = (typeof POS !== 'undefined' && POS.servicesLock) ? POS.servicesLock : null;
            const title = lock && lock.title
                ? lock.title
                : 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نیە';
            const desc = lock && lock.description ? lock.description : '';
            const message = desc ? `${title} — ${desc}` : title;
            showNotification(message, 'warning');
        }

        function handleServicesApiError(data, response) {
            const denied = response && response.status === 403
                || (data && data.success === false && response && response.status === 403);
            if (denied) {
                showServicesFeatureLockNotification();
                return true;
            }
            if (data && data.success === false && data.message) {
                showNotification(data.message, 'warning');
                return true;
            }
            return false;
        }

        async function fetchPosServices(options = {}) {
            const search = options.search != null ? String(options.search) : '';
            const offset = Math.max(0, Number(options.offset) || 0);
            const limit = Math.max(1, Number(options.limit) || POS.servicesPagination.limit || 150);

            if (typeof POS !== 'undefined' && !POS.hasServicesAccess) {
                return {
                    ok: false,
                    denied: true,
                    services: [],
                    total: 0,
                    hasMore: false
                };
            }

            try {
                let url = `../../api/services.php?action=list&limit=${limit}&offset=${offset}`;
                if (search !== '') {
                    url += `&search=${encodeURIComponent(search)}`;
                }
                const fetchOpts = options.signal ? { signal: options.signal } : undefined;
                const response = await posFetch(url, fetchOpts);
                const data = await response.json();

                if (!(data.success && data.data && Array.isArray(data.data.services))) {
                    const denied = handleServicesApiError(data, response);
                    return {
                        ok: false,
                        denied,
                        services: [],
                        total: 0,
                        hasMore: false
                    };
                }

                const services = cacheServicesInPos(data.data.services);
                return {
                    ok: true,
                    denied: false,
                    services,
                    total: Number(data.data.total || services.length),
                    hasMore: !!data.data.hasMore
                };
            } catch (error) {
                if (isPosSearchAbortError(error)) {
                    throw error;
                }
                console.error('fetchPosServices error:', error);
                return {
                    ok: false,
                    denied: false,
                    services: [],
                    total: 0,
                    hasMore: false
                };
            }
        }

        function getCatalogDisplayList() {
            const includeServices = POS.currentSource === 'services'
                || (POS.currentSource === 'products' && POS.currentCategory === '' && POS.hasServicesAccess);
            if (includeServices && POS.currentSource === 'services') {
                return Array.isArray(POS.services) ? POS.services : [];
            }
            if (includeServices && POS.currentCategory === '') {
                return mergeCatalogItems(POS.products, POS.services);
            }
            return Array.isArray(POS.products) ? POS.products : [];
        }

        function getCatalogPaginationMeta() {
            if (POS.currentSource === 'services') {
                return {
                    total: POS.servicesPagination.total > 0 ? POS.servicesPagination.total : (POS.services || []).length,
                    hasMore: !!POS.servicesPagination.hasMore
                };
            }
            if (POS.currentSource === 'products' && POS.currentCategory === '' && POS.hasServicesAccess) {
                const serviceCount = (POS.services || []).length;
                const productTotal = POS.productsPagination.total > 0 ? POS.productsPagination.total : (POS.products || []).length;
                return {
                    total: productTotal + serviceCount,
                    hasMore: !!POS.productsPagination.hasMore
                };
            }
            return {
                total: POS.productsPagination.total > 0 ? POS.productsPagination.total : (POS.products || []).length,
                hasMore: !!POS.productsPagination.hasMore
            };
        }

        /** داتای کاڵا بۆ ئایتمێکی سەبەتە لەگەڵ نرخی ئێستا */
        function getProductDataForCartItem(product, cartItem) {
            let productData = product;
            let unitData = null;
            const selectedUnitIndex = cartItem.unit_id != null && product.units
                ? product.units.findIndex((u) => Number(u.unit_id) === Number(cartItem.unit_id))
                : getPrimaryUnitIndex(product);
            const unitIdx = selectedUnitIndex >= 0 ? selectedUnitIndex : getPrimaryUnitIndex(product);

            if (product.units && product.units.length > 0) {
                unitData = product.units[unitIdx] || product.units[getPrimaryUnitIndex(product)];
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
            return { productData, unitData };
        }

        /** نوێکردنەوەی نرخ/بڕی ئایتم لە سەبەتە لە داتای تازەی کاڵا */
        function syncCartItemFromProduct(cartItem, product) {
            if (!cartItem || !product || cartItem.isExternal) return false;

            const priceType = cartItem.selected_price_type || cartItem.price_type || POS.currentPriceType;
            const { productData, unitData } = getProductDataForCartItem(product, cartItem);

            // ئەگەر بەکارهێنەر بەدەست نرخەکەی گۆڕیبێت، نابێت بە ئۆتۆماتیکی
            // بگەڕێتەوە بۆ نرخی بنەڕەتیی کاڵا. تەنها زانیاری وەک بڕی کۆگا و
            // نرخی کڕین نوێ دەکەینەوە بۆ حیسابی دروستی قازانج، بەڵام نرخی
            // فرۆشتن و کۆی گشتی وەک خۆی دەهێڵینەوە.
            if (cartItem.priceOverridden) {
                cartItem.buy_price = getConvertedBuyPrice(productData);
                cartItem.wholesale_price = parseFloat(productData.wholesale_price) || 0;
                cartItem.special_price = parseFloat(productData.special_price) || 0;
                cartItem.stock = parseFloat(productData.stock_quantity);
                if (product.units && product.units.length > 1) {
                    cartItem.unit_options = product.units;
                }
                return false;
            }

            let price = getConvertedPrice(productData, priceType);
            let appliedPriceType = 'retail';
            if (priceType === 'wholesale' && productData.wholesale_price && productData.wholesale_price > 0) {
                appliedPriceType = 'wholesale';
            } else if (priceType === 'special' && productData.special_price && productData.special_price > 0) {
                appliedPriceType = 'special';
            }

            const oldPrice = cartItem.price;
            const roundedPrice = roundPriceByCurrency(price, POS.currentCurrency);

            cartItem.price = roundedPrice;
            cartItem.buy_price = getConvertedBuyPrice(productData);
            cartItem.wholesale_price = parseFloat(productData.wholesale_price) || 0;
            cartItem.special_price = parseFloat(productData.special_price) || 0;
            cartItem.stock = parseFloat(productData.stock_quantity);
            cartItem.price_type = appliedPriceType;
            cartItem.total = roundPriceByCurrency(cartItem.quantity * roundedPrice, POS.currentCurrency);
            if (unitData) {
                cartItem.unit_id = unitData.unit_id;
                cartItem.unit_name = unitData.unit_name;
                cartItem.unit_symbol = unitData.unit_symbol || '';
            }
            if (product.units && product.units.length > 1) {
                cartItem.unit_options = product.units;
            }

            return Math.abs((oldPrice || 0) - roundedPrice) > (POS.currentCurrency === 'USD' ? 0.01 : 1);
        }

        async function fetchProductById(productId) {
            const id = Number(productId);
            if (!Number.isFinite(id) || id <= 0) return null;
            try {
                const response = await posFetch(`../../api/products.php?action=get&id=${id}&user_id=${POS.userId}`);
                const data = await response.json();
                if (data.success && data.data && data.data.product) {
                    return data.data.product;
                }
            } catch (e) {
                console.error('fetchProductById error:', e);
            }
            return null;
        }

        async function fetchCustomerById(customerId) {
            const id = Number(customerId);
            if (!Number.isFinite(id) || id <= 0) return null;
            try {
                const response = await posFetch(`ajax/search_customers.php?q=&id=${id}`);
                const data = await response.json();
                if (data.customers && data.customers.length > 0) {
                    return data.customers[0];
                }
            } catch (e) {
                console.error('fetchCustomerById error:', e);
            }
            return null;
        }

        let refreshStalePosDataPromise = null;

        /** نوێکردنەوەی نرخ/بڕی سەبەتە و قەرزی کڕیار لە DB */
        async function refreshStalePosData(options = {}) {
            const silent = !!options.silent;
            if (refreshStalePosDataPromise) {
                return refreshStalePosDataPromise;
            }

            refreshStalePosDataPromise = (async () => {
                if (POS.activeTabId && typeof saveCurrentTabData === 'function') {
                    saveCurrentTabData();
                }

                const productIds = new Set();
                const customerIds = new Set();

                (POS.tabs || []).forEach((tab) => {
                    (tab.cart || []).forEach((item) => {
                        const pid = Number(item.id);
                        if (!item.isExternal && Number.isFinite(pid) && pid > 0) {
                            productIds.add(pid);
                        }
                    });
                    if (tab.selectedCustomer && tab.selectedCustomer.id) {
                        customerIds.add(Number(tab.selectedCustomer.id));
                    }
                });

                if (POS.selectedCustomer && POS.selectedCustomer.id) {
                    customerIds.add(Number(POS.selectedCustomer.id));
                }

                let priceChanges = 0;

                const productTasks = [...productIds].map((productId) => async () => {
                    const product = await fetchProductById(productId);
                    if (!product) return;
                    upsertProduct(product);

                    (POS.tabs || []).forEach((tab) => {
                        (tab.cart || []).forEach((item) => {
                            if (Number(item.id) === productId && syncCartItemFromProduct(item, product)) {
                                priceChanges += 1;
                            }
                        });
                    });
                });
                await runPosTasksWithConcurrency(productTasks);

                const customerTasks = [...customerIds].map((customerId) => async () => {
                    const customer = await fetchCustomerById(customerId);
                    if (!customer) return;

                    (POS.tabs || []).forEach((tab) => {
                        if (tab.selectedCustomer && Number(tab.selectedCustomer.id) === customerId) {
                            tab.selectedCustomer = {
                                id: customer.id,
                                name: customer.name,
                                phone: customer.phone || null,
                                current_debt_iqd: customer.current_debt_iqd || 0,
                                current_debt_usd: customer.current_debt_usd || 0
                            };
                        }
                    });

                    if (POS.selectedCustomer && Number(POS.selectedCustomer.id) === customerId) {
                        POS.selectedCustomer = {
                            id: customer.id,
                            name: customer.name,
                            phone: customer.phone || null,
                            current_debt_iqd: customer.current_debt_iqd || 0,
                            current_debt_usd: customer.current_debt_usd || 0
                        };
                        if (typeof updateCustomerDisplay === 'function') {
                            updateCustomerDisplay();
                        }
                    }
                });
                await runPosTasksWithConcurrency(customerTasks);

                if (POS.activeTabId) {
                    const activeTab = POS.tabs.find((t) => t.id === POS.activeTabId);
                    if (activeTab) {
                        POS.cart = [...activeTab.cart];
                        POS.selectedCustomer = activeTab.selectedCustomer;
                    }
                }

                if (typeof updateCartDisplay === 'function') {
                    updateCartDisplay();
                }
                if (typeof saveCartToStorage === 'function') {
                    saveCartToStorage();
                }

                if (!silent && priceChanges > 0) {
                    showNotification(`نرخی ${priceChanges} کاڵا نوێکرایەوە`, 'info');
                }

                if (POS.currentSource === 'products' && typeof refreshCurrentProductList === 'function') {
                    await refreshCurrentProductList();
                }

                return { priceChanges };
            })().finally(() => {
                refreshStalePosDataPromise = null;
            });

            return refreshStalePosDataPromise;
        }
        
        // Round price based on currency (USD: 2 decimals, IQD: no decimals)
        function roundPriceByCurrency(price, currency) {
            if (!price || isNaN(price) || !isFinite(price)) return 0;
            if (currency === 'USD') {
                return Math.round(price * 100) / 100; // Round to 2 decimal places
            }
            return Math.round(price); // Round to whole number for IQD
        }
        
        // Convert price between currencies
        function convertPrice(price, fromCurrency, toCurrency) {
            if (!price || price <= 0) return 0;
            if (fromCurrency === toCurrency) return roundPriceByCurrency(price, toCurrency); // ئەگەر هەردووکیان یەکسانن، نرخەکە وەکو خۆی بگەڕێنەوە
            if (!POS.dollarPrice || POS.dollarPrice <= 0) return roundPriceByCurrency(price, toCurrency); // ئەگەر نرخی دۆلار نەبێت، نرخەکە وەکو خۆی بگەڕێنەوە
            
            let convertedPrice = 0;
            // گۆڕینی دۆلار بۆ دینار
            if (fromCurrency === 'USD' && toCurrency === 'IQD') {
                convertedPrice = price * POS.dollarPrice;
            }
            // گۆڕینی دینار بۆ دۆلار
            else if (fromCurrency === 'IQD' && toCurrency === 'USD') {
                convertedPrice = price / POS.dollarPrice;
            } else {
                convertedPrice = price;
            }
            
            return roundPriceByCurrency(convertedPrice, toCurrency);
        }
        
        /** نرخی کڕین بەپێی دراوی هەڵبژێردراو (هەر یەکەیەک) */
        function getConvertedBuyPrice(productData) {
            if (!productData) return 0;
            const buyPrice = parseFloat(productData.buy_price);
            if (!buyPrice || isNaN(buyPrice) || buyPrice < 0) return 0;

            const productCurrency = productData.currency || 'IQD';
            let price = buyPrice;

            if (productCurrency === 'USD' && POS.currentCurrency === 'IQD') {
                if (POS.dollarPrice && POS.dollarPrice > 0) {
                    price = buyPrice * POS.dollarPrice;
                }
            } else if (productCurrency === 'IQD' && POS.currentCurrency === 'USD') {
                if (POS.dollarPrice && POS.dollarPrice > 0) {
                    price = buyPrice / POS.dollarPrice;
                } else {
                    return 0;
                }
            }

            if (isNaN(price) || !isFinite(price)) return 0;
            return roundPriceByCurrency(price, POS.currentCurrency);
        }

        // Get converted price for a product
        function getConvertedPrice(product, priceType = null) {
            const type = priceType || POS.currentPriceType;
            let price = 0;
            const productCurrency = product.currency || 'IQD';
            
            // Get base price based on type
            if (type === 'wholesale' && product.wholesale_price && product.wholesale_price > 0) {
                price = parseFloat(product.wholesale_price);
            } else if (type === 'special' && product.special_price && product.special_price > 0) {
                price = parseFloat(product.special_price);
            } else if (product.sell_price) {
                price = parseFloat(product.sell_price) || 0;
            }
            
            // If price is invalid (0, NaN, or negative), return 0
            if (!price || isNaN(price) || price <= 0) {
                return 0;
            }
            
            // تەنها کاتێک دراوەکان جیاوازن گۆڕانکاری بکە
            // ئەگەر کاڵاکە بە دۆلار دانراوە و دۆلار هەڵدەبژێریت → هیچ گۆڕانکارییەک ناکرێت
            // ئەگەر کاڵاکە بە دینار دانراوە و دینار هەڵدەبژێریت → هیچ گۆڕانکارییەک ناکرێت
            // تەنها کاتێک دراوەکان جیاوازن گۆڕانکاری دەکرێت
            
            // Convert based on product currency and selected currency
            // کاتێک کاڵاکە بە دۆلار دانراوە و دینار هەڵدەبژێرین
            if (productCurrency === 'USD' && POS.currentCurrency === 'IQD') {
                if (POS.dollarPrice && POS.dollarPrice > 0) {
                    price = price * POS.dollarPrice; // دۆلار بگۆڕە بۆ دینار
                }
            }
            // کاتێک کاڵاکە بە دینار دانراوە و دۆلار هەڵدەبژێرین
            else if (productCurrency === 'IQD' && POS.currentCurrency === 'USD') {
                if (POS.dollarPrice && POS.dollarPrice > 0) {
                    price = price / POS.dollarPrice; // دینار بگۆڕە بۆ دۆلار
                } else {
                    // If dollarPrice is not available, we can't convert, so return 0
                    // This prevents showing IQD prices when USD is selected
                    return 0;
                }
            }
            // کاتێک هەردووکیان یەکسانن (دۆلار بۆ دۆلار یان دینار بۆ دینار)، نرخەکە وەکو خۆی بمێنێتەوە
            // هیچ گۆڕانکارییەک ناکرێت
            
            // Ensure the result is a valid number
            if (isNaN(price) || !isFinite(price)) {
                return 0;
            }
            
            // Round price based on current currency
            return roundPriceByCurrency(price, POS.currentCurrency);
        }
        
      function formatMoney(amount) {
          const currencyLabel = POS.currentCurrency === 'USD' ? 'دۆلار' : 'دینار';
          // For USD, show 2 decimal places; for IQD, show no decimals
          const options = POS.currentCurrency === 'USD' ? {
              style: 'decimal',
              minimumFractionDigits: 2,
              maximumFractionDigits: 2
          } : {
              style: 'decimal',
              minimumFractionDigits: 0,
              maximumFractionDigits: 0
          };
          return new Intl.NumberFormat('en-US', options).format(amount) + ' ' + currencyLabel;
      }

      function formatMoneyByCurrency(amount, currency) {
          const n = parseFloat(amount);
          const value = isFinite(n) ? n : 0;
          const currencyLabel = currency === 'USD' ? 'دۆلار' : 'دینار';
          // For USD, show 2 decimal places; for IQD, show no decimals
          const options = currency === 'USD' ? {
              style: 'decimal',
              minimumFractionDigits: 2,
              maximumFractionDigits: 2
          } : {
              style: 'decimal',
              minimumFractionDigits: 0,
              maximumFractionDigits: 0
          };
          return new Intl.NumberFormat('en-US', options).format(value) + ' ' + currencyLabel;
      }

      function formatCompactQty(qty) {
        const n = Number(qty);
        if (!isFinite(n)) return String(qty ?? '');
        // Keep up to 6 decimals, then trim trailing zeros and dot
        let s = n.toFixed(6);
        s = s.replace(/\.0+$/, '').replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.$/, '');
        return s;
      }

      function escapeHtml(value) {
          return String(value ?? '')
              .replaceAll('&', '&amp;')
              .replaceAll('<', '&lt;')
              .replaceAll('>', '&gt;')
              .replaceAll('"', '&quot;')
              .replaceAll("'", '&#39;');
      }

        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        function showPackageFeatureLockNotification() {
            const lock = (typeof POS !== 'undefined' && POS.packageLock) ? POS.packageLock : null;
            const title = lock && lock.title
                ? lock.title
                : 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نیە';
            const desc = lock && lock.description ? lock.description : '';
            const message = desc ? `${title} — ${desc}` : title;
            showNotification(message, 'warning');
        }

        function resetBarcodeInputTiming() {
            POS.barcodeInputTiming = { firstKey: 0, lastKey: 0, charCount: 0 };
        }

        function trackBarcodeInputKeydown(event) {
            if (!event || event.key === 'Enter' || event.key === 'Escape') {
                return;
            }
            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }
            const now = Date.now();
            if (!POS.barcodeInputTiming.charCount) {
                POS.barcodeInputTiming.firstKey = now;
            }
            POS.barcodeInputTiming.lastKey = now;
            POS.barcodeInputTiming.charCount += 1;
        }

        function isLikelyHardwareScanner(inputLength) {
            const timing = POS.barcodeInputTiming || {};
            const charCount = timing.charCount || 0;
            if (inputLength < 4 || charCount < 4) {
                return false;
            }
            const duration = (timing.lastKey || 0) - (timing.firstKey || 0);
            return duration >= 0 && duration < 300;
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} alert-dismissible fade show notification bounce-in`;
            notification.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="bi bi-${getNotificationIcon(type)} me-2"></i>
                    <span>${message}</span>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                const alert = new bootstrap.Alert(notification);
                alert.close();
            }, 5000);
        }

        function getNotificationIcon(type) {
            const icons = {
                'success': 'check-circle-fill',
                'danger': 'exclamation-triangle-fill',
                'warning': 'exclamation-circle-fill',
                'info': 'info-circle-fill'
            };
            return icons[type] || 'info-circle-fill';
        }

        function showError(message) {
            showNotification(message, 'danger');
        }

        function saveCartToStorage() {
            // Save current tab data
            saveCurrentTabData();
            // Save all tabs data
            localStorage.setItem('pos_tabs', JSON.stringify(POS.tabs));
            localStorage.setItem('pos_active_tab', POS.activeTabId);
            localStorage.setItem('pos_data_version', String(POS_DATA_VERSION));
            localStorage.setItem('pos_saved_at', String(Date.now()));
        }

        function clearCartSilent() {
            POS.cart = [];
            POS.currentInteractionConflicts = [];
            POS.knownConflictKeys = new Set();
            updateCartDisplay();
            // Clear old cart storage
            localStorage.removeItem('pos_cart');
            // Save current tab data
            saveCurrentTabData();
        }

        // Function to reset tabs to a single empty tab
        function resetTabsToDefault() {
            // Save current tab data before resetting
            if (POS.activeTabId) {
                saveCurrentTabData();
            }
            
            // Reset tabs to a single empty tab
            POS.tabCounter = 1;
            const defaultTabId = `tab_${POS.tabCounter}`;
            const defaultTabName = `فرۆشتن ${POS.tabCounter}`;
            
            POS.tabs = [{
                id: defaultTabId,
                name: defaultTabName,
                cart: [],
                selectedCustomer: null,
                paymentMethod: POS.defaultPaymentMethod,
                walletId: POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '',
                discountAmount: '',
                saleNotes: ''
            }];
            
            POS.activeTabId = defaultTabId;
            POS.cart = [];
            POS.selectedCustomer = null;
            
            // Reset form fields
            document.getElementById('paymentMethod').value = POS.defaultPaymentMethod;
            document.getElementById('walletId').value = POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '';
            document.getElementById('discountAmount').value = '';
            document.getElementById('saleNotes').value = '';
            clearSelectedCustomer();
            
            // Update display
            updateTabsDisplay();
            updateCartDisplay();
            updateCustomerDisplay();
            syncPaymentMethodUI();
            
            // Clear localStorage
            localStorage.removeItem('pos_tabs');
            localStorage.removeItem('pos_active_tab');
        }

        // Function to close only the current tab after a successful sale
        function closeCurrentTabAfterSale() {
            if (POS.tabs.length === 1) {
                // If only one tab, just clear it
                POS.cart = [];
                POS.selectedCustomer = null;
                document.getElementById('paymentMethod').value = POS.defaultPaymentMethod;
                document.getElementById('walletId').value = POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '';
                document.getElementById('discountAmount').value = '';
                document.getElementById('saleNotes').value = '';
                clearSelectedCustomer();
                
                // Update the current tab
                const currentTab = POS.tabs.find(t => t.id === POS.activeTabId);
                if (currentTab) {
                    currentTab.cart = [];
                    currentTab.selectedCustomer = null;
                    currentTab.paymentMethod = POS.defaultPaymentMethod;
                    currentTab.walletId = POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '';
                    currentTab.discountAmount = '';
                    currentTab.saleNotes = '';
                }
                
                updateTabsDisplay();
                updateCartDisplay();
                updateCustomerDisplay();
                syncPaymentMethodUI();
                saveCartToStorage();
            } else {
                // If multiple tabs, close the current one
                const currentTabId = POS.activeTabId;
                closeTab(currentTabId);
            }
        }

        function updateProductCount(count) {
            document.getElementById('productCount').textContent = count;
        }

        function isModalOpen() {
            return document.querySelector('.modal.show') !== null;
        }

