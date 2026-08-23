/**
 * Delegate sales fullscreen product picker for POS.
 */
(function (global) {
    'use strict';

    const PAGE_SIZE = 40;
    const SEARCH_DEBOUNCE_MS = 320;
    const BARCODE_MIN_LEN = 6;

    const DelegateSalesPicker = {
        config: null,
        modal: null,
        observer: null,
        searchTimer: null,
        cardState: new Map(),

        state: {
            category: '',
            viewMode: 'grid',
            searchQuery: '',
            isSearchMode: false,
            items: [],
            catalogProducts: [],
            cachedServices: [],
            pagination: {
                offset: 0,
                limit: PAGE_SIZE,
                hasMore: false,
                loading: false,
                total: 0
            }
        },

        els: {},

        init(config) {
            this.config = config || {};
            this.cacheElements();
            this.bindEvents();
            this.renderCatalogPills();
        },

        cacheElements() {
            this.els = {
                modal: document.getElementById('delegateSalesPickerModal'),
                catalogStrip: document.getElementById('delegateCatalogStrip'),
                searchInput: document.getElementById('delegateSalesSearch'),
                searchClear: document.getElementById('delegateSalesSearchClear'),
                products: document.getElementById('delegateSalesProducts'),
                productsScroll: document.getElementById('delegateSalesProductsScroll'),
                loading: document.getElementById('delegateSalesLoading'),
                empty: document.getElementById('delegateSalesEmpty'),
                sentinel: document.getElementById('delegateSalesSentinel'),
                loadMore: document.getElementById('delegateSalesLoadMore'),
                lightbox: document.getElementById('delegateSalesImageLightbox'),
                lightboxImg: document.getElementById('delegateSalesLightboxImg'),
                lightboxClose: document.getElementById('delegateSalesLightboxClose'),
                lightboxBackdrop: document.getElementById('delegateSalesLightboxBackdrop'),
                viewToggle: document.querySelector('.delegate-sales-view-toggle')
            };

            if (this.els.modal) {
                this.modal = bootstrap.Modal.getOrCreateInstance(this.els.modal);
            }
        },

        bindEvents() {
            const btn = document.getElementById('delegateSalesBtn');
            if (btn) {
                btn.addEventListener('click', () => this.open());
            }

            if (this.els.modal) {
                this.els.modal.addEventListener('shown.bs.modal', () => {
                    this.refreshPricesOnCards();
                    this.setupInfiniteScroll();
                });
                this.els.modal.addEventListener('hidden.bs.modal', () => {
                    this.teardownInfiniteScroll();
                    this.closeLightbox();
                });
            }

            if (this.els.searchInput) {
                this.els.searchInput.addEventListener('input', () => this.onSearchInput());
                this.els.searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.runSearch(true);
                    }
                });
            }

            if (this.els.searchClear) {
                this.els.searchClear.addEventListener('click', () => {
                    this.els.searchInput.value = '';
                    this.onSearchInput();
                    this.els.searchInput.focus();
                });
            }

            if (this.els.viewToggle) {
                this.els.viewToggle.addEventListener('click', (e) => {
                    const btn = e.target.closest('[data-view]');
                    if (!btn) return;
                    this.setViewMode(btn.getAttribute('data-view'));
                });
            }

            if (this.els.products) {
                this.els.products.addEventListener('click', (e) => this.onProductsClick(e));
                this.els.products.addEventListener('change', (e) => this.onProductsChange(e));
                this.els.products.addEventListener('input', (e) => this.onProductsInput(e));
            }

            if (this.els.lightboxClose) {
                this.els.lightboxClose.addEventListener('click', () => this.closeLightbox());
            }
            if (this.els.lightboxBackdrop) {
                this.els.lightboxBackdrop.addEventListener('click', () => this.closeLightbox());
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isLightboxOpen()) {
                    this.closeLightbox();
                }
            });

            document.addEventListener('pos:priceTypeChanged', () => {
                if (this.isOpen()) {
                    this.refreshPricesOnCards();
                }
            });
            document.addEventListener('pos:currencyChanged', () => {
                if (this.isOpen()) {
                    this.refreshPricesOnCards();
                }
            });
        },

        renderCatalogPills() {
            if (!this.els.catalogStrip) return;

            const categories = Array.isArray(this.config.categories) ? this.config.categories : [];
            const pills = [
                { id: '', name: 'هەموو', icon: 'bi-grid' }
            ];

            if (this.config.hasServicesAccess) {
                pills.push({ id: '__services__', name: 'خزمەتگوزاری', icon: 'bi-briefcase' });
            }

            categories.forEach((cat) => {
                pills.push({
                    id: String(cat.id),
                    name: cat.name,
                    icon: 'bi-folder2'
                });
            });

            this.els.catalogStrip.innerHTML = pills.map((pill) => {
                const active = pill.id === this.state.category ? ' is-active' : '';
                const selected = pill.id === this.state.category ? 'true' : 'false';
                return `<button type="button" class="delegate-sales-catalog__pill${active}" data-category="${this.escapeHtml(pill.id)}" role="tab" aria-selected="${selected}">
                    <i class="bi ${pill.icon}"></i> ${this.escapeHtml(pill.name)}
                </button>`;
            }).join('');

            this.els.catalogStrip.addEventListener('click', (e) => {
                const pill = e.target.closest('[data-category]');
                if (!pill) return;
                const category = pill.getAttribute('data-category') || '';
                if (category === this.state.category && !this.state.isSearchMode) return;
                this.selectCategory(category);
            });
        },

        selectCategory(category) {
            this.state.category = category;
            this.state.catalogProducts = [];
            this.state.cachedServices = [];
            this.state.isSearchMode = false;
            this.teardownInfiniteScroll();
            this.state.searchQuery = '';
            if (this.els.searchInput) {
                this.els.searchInput.value = '';
            }
            this.updateSearchClearVisibility();
            this.highlightCatalogPill(category);
            this.resetAndLoad();
            if (this.isOpen()) {
                this.setupInfiniteScroll();
            }
        },

        highlightCatalogPill(category) {
            if (!this.els.catalogStrip) return;
            this.els.catalogStrip.querySelectorAll('[data-category]').forEach((el) => {
                const isActive = (el.getAttribute('data-category') || '') === category;
                el.classList.toggle('is-active', isActive);
                el.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        },

        open() {
            if (!this.modal) return;
            this.cardState.clear();
            this.state.viewMode = 'grid';
            this.setViewMode('grid', false);
            this.highlightCatalogPill(this.state.category);
            this.modal.show();
            this.resetAndLoad();
        },

        isOpen() {
            return this.els.modal && this.els.modal.classList.contains('show');
        },

        onSearchInput() {
            const q = (this.els.searchInput?.value || '').trim();
            this.updateSearchClearVisibility();
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => {
                if (q.length === 0) {
                    this.state.isSearchMode = false;
                    this.state.searchQuery = '';
                    this.resetAndLoad();
                    if (this.isOpen()) {
                        this.setupInfiniteScroll();
                    }
                    return;
                }
                if (q.length < 2) return;
                this.state.searchQuery = q;
                this.state.isSearchMode = true;
                this.teardownInfiniteScroll();
                this.runSearch(true);
            }, SEARCH_DEBOUNCE_MS);
        },

        updateSearchClearVisibility() {
            if (!this.els.searchClear) return;
            const hasValue = (this.els.searchInput?.value || '').trim().length > 0;
            this.els.searchClear.hidden = !hasValue;
        },

        async runSearch(reset) {
            const query = (this.state.searchQuery || '').trim();
            if (query.length < 2) return;

            if (reset) {
                this.state.items = [];
                this.state.pagination.offset = 0;
                this.state.pagination.hasMore = false;
                this.cardState.clear();
                this.showLoading(true);
                this.els.products.innerHTML = '';
            }

            if (this.state.pagination.loading) return;
            this.state.pagination.loading = true;

            try {
                const pos = this.getPosState();
                const items = await this.fetchSearchResults(query, pos);
                if (reset) {
                    this.state.items = items;
                } else {
                    this.state.items = [...this.state.items, ...items];
                }
                this.state.pagination.hasMore = false;
                this.renderProducts(reset);
            } catch (err) {
                console.error('DelegateSalesPicker search error:', err);
                if (reset) this.showEmpty(true);
            } finally {
                this.state.pagination.loading = false;
                this.showLoading(false);
            }
        },

        async fetchSearchResults(query, pos) {
            const zeroStock = this.config.showZeroStock ? '&include_zero_stock=1' : '';
            const items = [];

            if (POS.barcodeScanEnabled && this.looksLikeBarcode(query)) {
                if (typeof isLikelyScaleBarcodeForPos === 'function' && isLikelyScaleBarcodeForPos(query)) {
                    const scaleUrl = `../../api/scale_products.php?action=barcode&code=${encodeURIComponent(query)}&user_id=${pos.userId}`;
                    const scaleRes = await posFetch(scaleUrl);
                    const scaleData = await scaleRes.json();
                    if (scaleData.success && scaleData.data?.product) {
                        const scaleProduct = { ...scaleData.data.product };
                        scaleProduct.is_scale_product = true;
                        scaleProduct._scale_weight_kg = parseFloat(scaleData.data.scale?.weight_kg || 0);
                        items.push(scaleProduct);
                        return items;
                    }
                }

                const barcodeUrl = `../../api/products.php?action=barcode&code=${encodeURIComponent(query)}&user_id=${pos.userId}`;
                const barcodeRes = await posFetch(barcodeUrl);
                const barcodeData = await barcodeRes.json();
                if (barcodeData.success && barcodeData.data?.product) {
                    items.push(barcodeData.data.product);
                    return items;
                }
            }

            const productUrl = `../../api/products.php?action=search&q=${encodeURIComponent(query)}&user_id=${pos.userId}&limit=50${zeroStock}`;
            const productPromise = posFetch(productUrl).then((res) => res.json());
            const servicesPromise = this.config.hasServicesAccess && typeof fetchPosServices === 'function'
                ? fetchPosServices({ search: query, limit: 30 })
                : Promise.resolve({ ok: false, services: [] });

            const [productData, servicesResult] = await Promise.all([productPromise, servicesPromise]);

            if (productData.success && Array.isArray(productData.data?.products)) {
                items.push(...productData.data.products);
            }

            if (servicesResult.ok && Array.isArray(servicesResult.services)) {
                items.push(...servicesResult.services);
            }

            if (typeof mergeCatalogItems === 'function') {
                const products = items.filter((item) => !item.is_service);
                const services = items.filter((item) => item.is_service);
                return mergeCatalogItems(products, services);
            }

            return items;
        },

        looksLikeBarcode(query) {
            if (query.length < BARCODE_MIN_LEN) return false;
            return /^[0-9A-Za-z\-]+$/.test(query);
        },

        resetAndLoad() {
            this.state.items = [];
            this.state.catalogProducts = [];
            this.state.cachedServices = [];
            this.state.pagination.offset = 0;
            this.state.pagination.hasMore = true;
            this.cardState.clear();
            this.els.products.innerHTML = '';
            this.loadMore(true);
        },

        async loadMore(reset) {
            if (this.state.isSearchMode) return;
            if (this.state.pagination.loading) return;
            if (!reset && !this.state.pagination.hasMore) return;

            this.state.pagination.loading = true;
            if (reset) {
                this.showLoading(true);
                this.showEmpty(false);
            } else {
                this.els.loadMore?.classList.remove('d-none');
            }

            try {
                const pos = this.getPosState();
                const result = await this.fetchPage(pos, reset);
                const newItems = result.items || [];
                const productsOnly = result.productsOnly || newItems;

                let itemsToRender;

                if (reset) {
                    if (this.state.category === '' && this.config.hasServicesAccess) {
                        this.state.catalogProducts = productsOnly;
                        this.state.items = newItems;
                    } else {
                        this.state.catalogProducts = [];
                        this.state.items = newItems;
                    }
                    itemsToRender = newItems;
                } else if (this.state.category === '' && this.config.hasServicesAccess) {
                    this.state.catalogProducts = [...this.state.catalogProducts, ...productsOnly];
                    this.state.items = typeof mergeCatalogItems === 'function'
                        ? mergeCatalogItems(this.state.catalogProducts, this.state.cachedServices)
                        : [...this.state.catalogProducts, ...(this.state.cachedServices || [])];
                    itemsToRender = productsOnly;
                } else {
                    this.state.items = [...this.state.items, ...newItems];
                    itemsToRender = newItems;
                }

                this.state.pagination.hasMore = !!result.hasMore;
                this.state.pagination.total = result.total || this.state.items.length;
                this.state.pagination.offset += productsOnly.length;

                this.renderProducts(reset, itemsToRender);

                if (this.state.items.length === 0) {
                    this.showEmpty(true);
                }
            } catch (err) {
                console.error('DelegateSalesPicker load error:', err);
                if (reset) this.showEmpty(true);
            } finally {
                this.state.pagination.loading = false;
                this.showLoading(false);
                this.els.loadMore?.classList.add('d-none');
            }

            // If sentinel is still within the scroll container after rendering,
            // keep loading pages until the viewport is full or there's nothing left.
            if (!this.state.isSearchMode && this.isOpen() && this.state.pagination.hasMore) {
                const sentinel = this.els.sentinel;
                const scrollEl = this.els.productsScroll;
                if (sentinel && scrollEl) {
                    const sr = sentinel.getBoundingClientRect();
                    const cr = scrollEl.getBoundingClientRect();
                    if (sr.top < cr.bottom + 200) {
                        this.loadMore(false);
                    }
                }
            }
        },

        async fetchPage(pos, reset) {
            const offset = reset ? 0 : this.state.pagination.offset;
            const limit = this.state.pagination.limit;
            const category = this.state.category;

            if (category === '__services__') {
                if (!this.config.hasServicesAccess) {
                    return { items: [], productsOnly: [], hasMore: false, total: 0 };
                }
                if (typeof fetchPosServices === 'function') {
                    const result = await fetchPosServices({ offset: reset ? 0 : offset, limit });
                    if (!result.ok) {
                        return { items: [], productsOnly: [], hasMore: false, total: 0 };
                    }
                    return {
                        items: result.services,
                        productsOnly: [],
                        hasMore: result.hasMore,
                        total: result.total
                    };
                }
                const url = `../../api/services.php?action=list&limit=${limit}&offset=${offset}`;
                const res = await posFetch(url);
                const data = await res.json();
                if (!data.success) {
                    if (res.status === 403 && typeof showServicesFeatureLockNotification === 'function') {
                        showServicesFeatureLockNotification();
                    }
                    return { items: [], productsOnly: [], hasMore: false, total: 0 };
                }
                const services = (data.data?.services || []).map((s) => this.mapService(s, pos));
                return {
                    items: services,
                    productsOnly: [],
                    hasMore: !!data.data?.hasMore,
                    total: Number(data.data?.total || 0)
                };
            }

            if (category === '' && this.config.hasServicesAccess && reset) {
                if (typeof fetchPosServices === 'function') {
                    const servicesResult = await fetchPosServices({ offset: 0, limit: 150 });
                    this.state.cachedServices = servicesResult.ok ? servicesResult.services : [];
                } else {
                    this.state.cachedServices = [];
                }
            } else if (category !== '') {
                this.state.cachedServices = [];
            }

            let url = `../../api/products.php?action=list&user_id=${pos.userId}&limit=${limit}&offset=${offset}`;
            if (this.config.showZeroStock) {
                url += '&include_zero_stock=1';
            }
            if (category) {
                url += `&category=${encodeURIComponent(category)}`;
            }

            const res = await posFetch(url);
            const data = await res.json();
            if (!data.success) {
                return { items: [], productsOnly: [], hasMore: false, total: 0 };
            }

            const products = data.data?.products || [];
            if (typeof mergeProductsIntoCache === 'function') {
                mergeProductsIntoCache(products);
            }

            const serviceCount = category === '' ? (this.state.cachedServices || []).length : 0;
            const mergedItems = category === '' && this.config.hasServicesAccess
                ? (typeof mergeCatalogItems === 'function'
                    ? mergeCatalogItems(products, this.state.cachedServices)
                    : [...products, ...(this.state.cachedServices || [])])
                : products;

            return {
                items: mergedItems,
                productsOnly: products,
                hasMore: !!data.data?.hasMore,
                total: Number(data.data?.total || 0) + serviceCount
            };
        },

        mapService(service, pos) {
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
                currency: pos.currency || 'IQD'
            };
        },

        renderProducts(reset, itemsToRender) {
            if (!this.els.products) return;

            const items = reset ? this.state.items : (itemsToRender || this.state.items);
            const html = items.map((item) => this.buildCardHtml(item)).join('');

            if (reset) {
                this.els.products.innerHTML = html;
            } else {
                this.els.products.insertAdjacentHTML('beforeend', html);
            }

            this.showEmpty(this.state.items.length === 0);
        },

        buildCardHtml(item) {
            const isService = !!item.is_service;
            const key = this.itemKey(item);
            const stored = this.cardState.get(key) || { qty: 1, unitIndex: 0 };
            this.cardState.set(key, stored);

            const unitIndex = stored.unitIndex;
            const qty = stored.qty;
            const unitPrice = this.getItemUnitPrice(item, unitIndex);
            const lineTotal = this.getLineTotal(unitPrice, qty);
            const unitLabel = this.getUnitLabel(item, unitIndex);
            const isOutOfStock = !isService && Number(item.stock_quantity) <= 0;
            const outClass = isOutOfStock ? ' is-out-of-stock' : '';

            const imageBlock = isService
                ? '<div class="delegate-sales-card__media" data-action="noop"><i class="bi bi-briefcase"></i></div>'
                : this.buildImageBlock(item);

            const unitSelectHtml = (!isService && item.units && item.units.length > 1)
                ? `<select class="form-select form-select-sm delegate-sales-card__unit-select" data-action="unit" data-key="${key}" aria-label="یەکە">${item.units.map((u, idx) => {
                    const label = u.unit_name || u.unit_symbol || 'دانە';
                    const selected = idx === unitIndex ? ' selected' : '';
                    return `<option value="${idx}"${selected}>${this.escapeHtml(label)}</option>`;
                }).join('')}</select>`
                : `<span class="delegate-sales-card__unit">${this.escapeHtml(unitLabel)}</span>`;

            const stepperHidden = isService ? ' d-none' : '';
            const addDisabled = isOutOfStock && !this.config.showZeroStock ? ' disabled' : '';

            return `
                <article class="delegate-sales-card${outClass}" role="listitem" data-key="${key}" data-id="${item.id}" data-service="${isService ? '1' : '0'}">
                    ${imageBlock}
                    <h6 class="delegate-sales-card__name">${this.escapeHtml(item.name)}</h6>
                    <div class="delegate-sales-card__meta">${unitSelectHtml}</div>
                    <div class="delegate-sales-card__unit-price">نرخی یەکە: <strong data-role="unit-price">${this.formatPrice(unitPrice)}</strong></div>
                    <div class="delegate-sales-stepper${stepperHidden}">
                        <button type="button" class="delegate-sales-stepper__btn" data-action="inc" data-key="${key}" aria-label="زیادکردن">+</button>
                        <input type="number" class="form-control delegate-sales-stepper__input" data-action="qty" data-key="${key}"
                               value="${qty}" min="1" step="1" inputmode="numeric" aria-label="بڕ">
                        <button type="button" class="delegate-sales-stepper__btn" data-action="dec" data-key="${key}" aria-label="کەمکردنەوە" ${qty <= 1 ? 'disabled' : ''}>-</button>
                    </div>
                    <div class="delegate-sales-card__line-total" data-role="line-total">${this.formatPrice(lineTotal)}</div>
                    <button type="button" class="btn btn-primary delegate-sales-card__add" data-action="add" data-key="${key}"${addDisabled}>
                        <i class="bi bi-cart-plus"></i> زیادکردن بۆ سەبەتە
                    </button>
                </article>
            `;
        },

        buildImageBlock(item) {
            const name = this.escapeHtml(item.name);
            if (item.image_url) {
                return `<div class="delegate-sales-card__media" data-action="zoom" data-src="${this.escapeHtml(item.image_url)}" data-alt="${name}">
                    <img src="${this.escapeHtml(item.image_url)}" alt="${name}" loading="lazy"
                         onerror="this.style.display='none';this.parentElement.querySelector('.bi')?.classList.remove('d-none');">
                    <i class="bi bi-box-seam d-none"></i>
                </div>`;
            }
            return `<div class="delegate-sales-card__media" data-action="noop"><i class="bi bi-box-seam"></i></div>`;
        },

        onProductsClick(e) {
            const target = e.target.closest('[data-action]');
            if (!target) return;

            const action = target.getAttribute('data-action');
            const key = target.getAttribute('data-key');
            const item = this.findItemByKey(key);
            if (!item && action !== 'zoom' && action !== 'noop') return;

            if (action === 'zoom') {
                const media = target.closest('[data-action="zoom"]') || target;
                const src = media.getAttribute('data-src');
                const alt = media.getAttribute('data-alt') || '';
                if (src) this.openLightbox(src, alt);
                return;
            }

            if (action === 'inc' || action === 'dec') {
                this.adjustQty(key, action === 'inc' ? 1 : -1);
                return;
            }

            if (action === 'add') {
                this.addItemToCart(key);
            }
        },

        onProductsChange(e) {
            const target = e.target.closest('[data-action="unit"]');
            if (!target) return;
            const key = target.getAttribute('data-key');
            const unitIndex = parseInt(target.value, 10) || 0;
            const state = this.cardState.get(key) || { qty: 1, unitIndex: 0 };
            state.unitIndex = unitIndex;
            this.cardState.set(key, state);
            this.updateCardPricing(key);
        },

        onProductsInput(e) {
            const target = e.target.closest('[data-action="qty"]');
            if (!target) return;
            const key = target.getAttribute('data-key');
            let qty = parseInt(target.value, 10);
            if (!Number.isFinite(qty) || qty < 1) qty = 1;
            const state = this.cardState.get(key) || { qty: 1, unitIndex: 0 };
            state.qty = qty;
            this.cardState.set(key, state);
            this.updateCardPricing(key);
        },

        adjustQty(key, delta) {
            const state = this.cardState.get(key) || { qty: 1, unitIndex: 0 };
            state.qty = Math.max(1, state.qty + delta);
            this.cardState.set(key, state);

            const card = this.els.products?.querySelector(`[data-key="${key}"]`);
            if (card) {
                const input = card.querySelector('[data-action="qty"]');
                const decBtn = card.querySelector('[data-action="dec"]');
                if (input) input.value = String(state.qty);
                if (decBtn) decBtn.disabled = state.qty <= 1;
            }
            this.updateCardPricing(key);
        },

        updateCardPricing(key) {
            const item = this.findItemByKey(key);
            const card = this.els.products?.querySelector(`[data-key="${key}"]`);
            if (!item || !card) return;

            const state = this.cardState.get(key) || { qty: 1, unitIndex: 0 };
            const unitPrice = this.getItemUnitPrice(item, state.unitIndex);
            const lineTotal = this.getLineTotal(unitPrice, state.qty);

            const unitEl = card.querySelector('[data-role="unit-price"]');
            const lineEl = card.querySelector('[data-role="line-total"]');
            if (unitEl) unitEl.textContent = this.formatPrice(unitPrice);
            if (lineEl) lineEl.textContent = this.formatPrice(lineTotal);
        },

        refreshPricesOnCards() {
            if (!this.els.products) return;
            this.els.products.querySelectorAll('.delegate-sales-card[data-key]').forEach((card) => {
                this.updateCardPricing(card.getAttribute('data-key'));
            });
        },

        addItemToCart(key) {
            const item = this.findItemByKey(key);
            if (!item) return;

            const state = this.cardState.get(key) || { qty: 1, unitIndex: 0 };

            if (item.is_service) {
                this.ensureServiceInPos(item);
                this.config.addServiceToCart(item.id);
                return;
            }

            if (this.config.isProductExpired && this.config.isProductExpired(item)) {
                this.config.showNotification('کاڵاکە بەسەرچووە', 'warning');
                return;
            }

            const productForCart = this.buildProductForCart(item, state.unitIndex);
            this.ensureProductInPos(productForCart);
            const cartQty = item._scale_weight_kg && parseFloat(item._scale_weight_kg) > 0
                ? parseFloat(item._scale_weight_kg)
                : state.qty;
            this.config.addProductToCart(productForCart, cartQty, state.unitIndex);

            state.qty = 1;
            state.unitIndex = state.unitIndex;
            this.cardState.set(key, state);
            const card = this.els.products?.querySelector(`[data-key="${key}"]`);
            if (card) {
                const input = card.querySelector('[data-action="qty"]');
                if (input) input.value = '1';
                this.updateCardPricing(key);
            }
        },

        buildProductForCart(item, unitIndex) {
            if (!item.units || item.units.length <= 1) {
                return item;
            }
            const unit = item.units[unitIndex] || item.units[0];
            if (!unit) return item;

            return {
                ...item,
                sell_price: parseFloat(unit.sell_price ?? item.sell_price ?? 0),
                wholesale_price: parseFloat(unit.wholesale_price ?? item.wholesale_price ?? 0),
                special_price: parseFloat(unit.special_price ?? item.special_price ?? 0),
                stock_quantity: parseFloat(unit.stock_quantity ?? item.stock_quantity ?? 0),
                currency: unit.currency || item.currency || 'IQD'
            };
        },

        ensureProductInPos(product) {
            if (typeof upsertProduct === 'function') {
                upsertProduct(product);
            }
        },

        ensureServiceInPos(service) {
            if (typeof upsertService === 'function') {
                upsertService(service);
            }
        },

        getItemUnitPrice(item, unitIndex) {
            const product = this.buildProductForCart(item, unitIndex);
            return this.config.getConvertedPrice(product);
        },

        getLineTotal(unitPrice, qty) {
            const pos = this.getPosState();
            const raw = unitPrice * qty;
            return this.config.roundPriceByCurrency(raw, pos.currency);
        },

        findItemByKey(key) {
            return this.state.items.find((item) => this.itemKey(item) === key);
        },

        itemKey(item) {
            return (item.is_service ? 's' : 'p') + '_' + item.id;
        },

        getUnitLabel(item, unitIndex) {
            if (item.is_service) return 'خزمەت';
            if (item.units && item.units[unitIndex]) {
                const u = item.units[unitIndex];
                return u.unit_name || u.unit_symbol || 'دانە';
            }
            return item.unit_name || item.unit_symbol || 'دانە';
        },

        formatPrice(amount) {
            if (this.config.formatMoney) {
                return this.config.formatMoney(amount);
            }
            return String(amount);
        },

        getPosState() {
            if (typeof this.config.getPosState === 'function') {
                return this.config.getPosState();
            }
            return { userId: 0, priceType: 'retail', currency: 'IQD' };
        },

        setViewMode(mode, rerender) {
            if (!['grid', 'list', 'horizontal'].includes(mode)) return;
            this.state.viewMode = mode;

            if (this.els.viewToggle) {
                this.els.viewToggle.querySelectorAll('[data-view]').forEach((btn) => {
                    btn.classList.toggle('active', btn.getAttribute('data-view') === mode);
                });
            }

            if (this.els.products) {
                this.els.products.classList.remove(
                    'delegate-sales-view-grid',
                    'delegate-sales-view-list',
                    'delegate-sales-view-horizontal'
                );
                this.els.products.classList.add('delegate-sales-view-' + mode);
            }

            if (rerender !== false && this.state.items.length) {
                const items = [...this.state.items];
                this.cardState.clear();
                this.state.items = items;
                this.renderProducts(true);
            }
        },

        setupInfiniteScroll() {
            this.teardownInfiniteScroll();
            if (!this.els.sentinel || this.state.isSearchMode) return;

            this.observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting && !this.state.isSearchMode) {
                        this.loadMore(false);
                    }
                });
            }, {
                root: this.els.productsScroll,
                rootMargin: '120px',
                threshold: 0
            });

            this.observer.observe(this.els.sentinel);
        },

        teardownInfiniteScroll() {
            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }
        },

        showLoading(show) {
            this.els.loading?.classList.toggle('d-none', !show);
        },

        showEmpty(show) {
            this.els.empty?.classList.toggle('d-none', !show);
            if (show) {
                this.els.products?.classList.add('d-none');
            } else {
                this.els.products?.classList.remove('d-none');
            }
        },

        openLightbox(src, alt) {
            if (!this.els.lightbox || !this.els.lightboxImg) return;
            this.els.lightboxImg.src = src;
            this.els.lightboxImg.alt = alt || '';
            this.els.lightbox.hidden = false;
            document.body.style.overflow = 'hidden';
        },

        closeLightbox() {
            if (!this.els.lightbox) return;
            this.els.lightbox.hidden = true;
            if (this.els.lightboxImg) {
                this.els.lightboxImg.src = '';
            }
            if (!this.isOpen()) {
                document.body.style.overflow = '';
            }
        },

        isLightboxOpen() {
            return this.els.lightbox && !this.els.lightbox.hidden;
        },

        escapeHtml(str) {
            if (str == null) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
    };

    global.DelegateSalesPicker = DelegateSalesPicker;
})(window);
