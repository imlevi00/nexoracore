        // ===== EVENT HANDLERS =====
        function toggleProductsSection() {
            const centerSection = document.getElementById('centerSection');
            const toggleBtn = document.getElementById('productsToggleBtn');
            
            if (centerSection.style.display === 'none' || centerSection.style.display === '') {
                centerSection.style.display = 'flex';
                toggleBtn.innerHTML = '<i class="bi bi-x-lg"></i> داخستنی کاڵاکان';
                toggleBtn.classList.add('btn-danger');
                toggleBtn.classList.remove('btn-primary');
                makeProductsCardDraggable();
            } else {
                centerSection.style.display = 'none';
                toggleBtn.innerHTML = '<i class="bi bi-box-seam"></i> کاڵاکان';
                toggleBtn.classList.add('btn-primary');
                toggleBtn.classList.remove('btn-danger');
            }
        }

        function closeProductsSection() {
            const centerSection = document.getElementById('centerSection');
            const toggleBtn = document.getElementById('productsToggleBtn');
            
            centerSection.style.display = 'none';
            toggleBtn.innerHTML = '<i class="bi bi-box-seam"></i> کاڵاکان';
            toggleBtn.classList.add('btn-primary');
            toggleBtn.classList.remove('btn-danger');
        }

        function toggleViewMode() {
            const viewToggleBtn = document.getElementById('viewToggleBtn');
            
            if (POS.viewMode === 'list') {
                POS.viewMode = 'grid';
                viewToggleBtn.innerHTML = '<i class="bi bi-list-ul"></i>';
                viewToggleBtn.title = 'گۆڕین بۆ لیست';
            } else {
                POS.viewMode = 'list';
                viewToggleBtn.innerHTML = '<i class="bi bi-grid-3x3-gap"></i>';
                viewToggleBtn.title = 'گۆڕین بۆ گرید';
            }
            
            // Reload products with new view mode
            const displayList = getCatalogDisplayList();
            if (displayList.length > 0) {
                displayProductsList(displayList, true, []);
            } else if (POS.currentSource === 'services') {
                loadServicesForList(true);
            } else {
                loadProductsForList(POS.currentCategory, true);
            }
        }

        function makeProductsCardDraggable() {
            const productsCard = document.getElementById('centerSection');
            const productsHeader = document.getElementById('productsHeader');
            let isDragging = false;
            let currentX;
            let currentY;
            let initialX;
            let initialY;
            let xOffset = 0;
            let yOffset = 0;

            productsHeader.addEventListener('mousedown', dragStart);
            document.addEventListener('mousemove', drag);
            document.addEventListener('mouseup', dragEnd);

            function dragStart(e) {
                initialX = e.clientX - xOffset;
                initialY = e.clientY - yOffset;

                if (e.target === productsHeader || productsHeader.contains(e.target)) {
                    isDragging = true;
                }
            }

            function drag(e) {
                if (isDragging) {
                    e.preventDefault();
                    currentX = e.clientX - initialX;
                    currentY = e.clientY - initialY;

                    xOffset = currentX;
                    yOffset = currentY;

                    productsCard.style.transform = `translate(${currentX}px, ${currentY}px)`;
                }
            }

            function dragEnd(e) {
                initialX = currentX;
                initialY = currentY;
                isDragging = false;
            }
        }

        function handleCategoryClick(event) {
            const button = event.target.closest('.category-list-item');
            if (!button) {
                return;
            }
            if (button.dataset.category === '__services__') {
                return;
            }
            // Remove active from all category items
            document.querySelectorAll('.category-list-item').forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            
            POS.currentSource = 'products';
            POS.currentCategory = button.dataset.category;
            
            // Reset pagination
            POS.productsPagination.offset = 0;
            POS.productsPagination.total = 0;
            POS.productsPagination.hasMore = false;
            POS.products = [];
            if (button.dataset.category !== '') {
                POS.services = [];
            }
            
            // Update category name header
            const categoryName = button.textContent.trim();
            document.getElementById('currentCategoryName').innerHTML = `<i class="bi bi-list-ul"></i> ${categoryName}`;
            
            loadProductsForList(POS.currentCategory, true);
        }

        async function handleServicesClick(event) {
            const button = event.target.closest('.category-list-item');
            if (!button) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();

            if (button.dataset.servicesLocked === '1') {
                showServicesFeatureLockNotification();
                return;
            }

            document.querySelectorAll('.category-list-item').forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');

            POS.currentSource = 'services';
            POS.currentCategory = '';
            POS.products = [];
            POS.productsPagination.offset = 0;
            POS.productsPagination.total = 0;
            POS.productsPagination.hasMore = false;
            POS.servicesPagination.offset = 0;
            POS.servicesPagination.total = 0;
            POS.servicesPagination.hasMore = false;
            POS.services = [];

            document.getElementById('currentCategoryName').innerHTML = '<i class="bi bi-briefcase"></i> خزمەتگوزارییەکان';
            await loadServicesForList(true);
        }

        // Load products and display in list format
        async function loadProductsForList(category = '', reset = false) {
            if (POS.productsPagination.loading) return;

            const includeServicesInAll = category === '' && POS.hasServicesAccess && POS.currentSource === 'products';

            try {
                POS.productsPagination.loading = true;
                
                const productsList = document.getElementById('productsList');
                
                if (reset) {
                    productsList.innerHTML = `
                        <div class="text-center py-4">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <p class="mt-2 small">بارکردن...</p>
                        </div>
                    `;
                } else {
                    // Show loading at bottom - button state is handled in loadMoreProducts function
                }
                
                let url = `../../api/products.php?action=list&user_id=${POS.userId}&limit=${POS.productsPagination.limit}&offset=${POS.productsPagination.offset}`;
                if (POS.posShowZeroStockProducts) {
                    url += '&include_zero_stock=1';
                }
                if (category) {
                    url += `&category=${category}`;
                }

                const productPromise = posFetch(url).then((response) => response.json());
                const servicesPromise = includeServicesInAll && reset
                    ? fetchPosServices({ offset: 0, limit: POS.servicesPagination.limit })
                    : Promise.resolve(null);

                const [data, servicesResult] = await Promise.all([productPromise, servicesPromise]);

                if (servicesResult) {
                    if (servicesResult.ok) {
                        POS.services = servicesResult.services;
                        POS.servicesPagination.total = servicesResult.total;
                        POS.servicesPagination.hasMore = servicesResult.hasMore;
                        POS.servicesPagination.offset = servicesResult.services.length;
                    } else if (reset && servicesResult.denied) {
                        POS.services = [];
                    }
                }

                if (data.success && data.data && data.data.products) {
                    const newProducts = data.data.products;

                    if (reset) {
                        POS.products = newProducts;
                    } else {
                        mergeProductsIntoCache(newProducts);
                    }

                    POS.productsPagination.total = data.data.total || 0;
                    POS.productsPagination.hasMore = data.data.hasMore || false;
                    POS.productsPagination.offset += newProducts.length;

                    const displayList = includeServicesInAll
                        ? mergeCatalogItems(POS.products, POS.services)
                        : POS.products;

                    if (reset || includeServicesInAll) {
                        displayProductsList(displayList, true, []);
                    } else {
                        displayProductsList(displayList, false, newProducts);
                    }
                } else if (reset) {
                    if (includeServicesInAll && servicesResult && servicesResult.ok && servicesResult.services.length > 0) {
                        POS.products = [];
                        displayProductsList(servicesResult.services, true, []);
                    } else {
                        displayEmptyProductsList();
                    }
                }
            } catch (error) {
                console.error('Error loading products:', error);
                if (reset) {
                    displayEmptyProductsList();
                }
            } finally {
                POS.productsPagination.loading = false;
            }
        }

        function refreshCurrentProductList() {
            POS.productsPagination.offset = 0;
            POS.productsPagination.total = 0;
            POS.productsPagination.hasMore = false;
            POS.products = [];
            loadProductsForList(POS.currentCategory, true);
        }
        
        // Load more products
        async function loadMoreProducts() {
            if (POS.currentSource === 'services') {
                const loadMoreBtn = document.getElementById('loadMoreProductsBtn');
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = true;
                    loadMoreBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> بارکردن...';
                }
                try {
                    await loadServicesForList(false);
                } finally {
                    const updatedBtn = document.getElementById('loadMoreProductsBtn');
                    const paginationMeta = getCatalogPaginationMeta();
                    if (updatedBtn && paginationMeta.hasMore) {
                        updatedBtn.disabled = false;
                        updatedBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i> بینی کاڵای زیاتر';
                    }
                }
                return;
            }
            const loadMoreBtn = document.getElementById('loadMoreProductsBtn');
            if (loadMoreBtn) {
                loadMoreBtn.disabled = true;
                loadMoreBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> بارکردن...';
            }
            
            try {
                await loadProductsForList(POS.currentCategory, false);
            } finally {
                // Re-enable button if still exists and has more products
                const updatedBtn = document.getElementById('loadMoreProductsBtn');
                const paginationMeta = getCatalogPaginationMeta();
                if (updatedBtn && paginationMeta.hasMore) {
                    updatedBtn.disabled = false;
                    updatedBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i> بینی کاڵای زیاتر';
                }
            }
        }

        function displayProductsList(productList, reset = false, newProductsOnly = []) {
            const productsList = document.getElementById('productsList');
            const countBadge = document.getElementById('productsCountBadge');
            
            if (!productList || productList.length === 0) {
                if (reset) {
                    displayEmptyProductsList();
                }
                return;
            }
            
            // Sort products by name
            productList.sort((a, b) => a.name.localeCompare(b.name, 'ku'));

            const paginationMeta = getCatalogPaginationMeta();
            countBadge.textContent = paginationMeta.total > 0 ? paginationMeta.total : productList.length;
            
            // Helper function to generate product HTML
            function generateProductHtml(product, isGrid) {
                const isService = !!product.is_service;
                // Available if ANY unit has stock, not just the primary/selected unit.
                const isOutOfStock = !isService && !productHasAnyUnitStock(product);
                let price = getConvertedPrice(product, POS.currentPriceType);
                const clickAction = isService ? `addServiceToCart(${product.id})` : `addToCart(${product.id})`;
                const stockBadge = isService ? 'خزمەت' : product.stock_quantity;
                
                if (isGrid) {
                    const imageHtml = product.image_url 
                        ? `<img src="${product.image_url}" alt="${product.name}" onerror="this.parentElement.innerHTML='<i class=\\'bi bi-box-seam\\'></i>'">` 
                        : '<i class="bi bi-box-seam"></i>';
                    return `
                        <div class="product-grid-item ${isOutOfStock ? 'out-of-stock' : ''}" 
                             onclick="${clickAction}">
                            <div class="product-grid-image">${imageHtml}</div>
                            <div class="product-grid-name">${product.name}</div>
                            <div class="product-grid-info">
                                <span class="product-grid-price">${formatMoney(price)}</span>
                                <span class="product-grid-stock">${stockBadge}</span>
                            </div>
                        </div>
                    `;
                } else {
                    return `
                        <div class="product-list-item ${isOutOfStock ? 'out-of-stock' : ''}" 
                             onclick="${clickAction}">
                            <span class="product-list-name">${product.name}</span>
                            <div class="product-list-info">
                                <span class="product-list-price">${formatMoney(price)}</span>
                                <span class="product-list-stock">${stockBadge}</span>
                            </div>
                        </div>
                    `;
                }
            }
            
            if (reset) {
                // Reset: display all products
                let html = '';
                const isGrid = POS.viewMode === 'grid';
                
                if (isGrid) {
                    const productsHtml = productList.map(p => generateProductHtml(p, true)).join('');
                    html = `<div class="products-grid-container">${productsHtml}</div>`;
                } else {
                    html = productList.map(p => generateProductHtml(p, false)).join('');
                }
                
                // Add load more button if there are more products
                if (paginationMeta.hasMore) {
                    html += `
                        <div class="text-center py-3" id="loadMoreContainer">
                            <button class="btn btn-outline-primary btn-sm" id="loadMoreProductsBtn" onclick="loadMoreProducts()">
                                <i class="bi bi-arrow-down-circle"></i> بینی کاڵای زیاتر
                            </button>
                        </div>
                    `;
                }
                
                productsList.innerHTML = html;
            } else {
                // Append: only add new products
                const existingLoadMore = document.getElementById('loadMoreContainer');
                if (existingLoadMore) {
                    existingLoadMore.remove();
                }
                
                const isGrid = POS.viewMode === 'grid';
                const productsToAdd = newProductsOnly.length > 0 ? newProductsOnly : [];
                
                if (productsToAdd.length > 0) {
                    const newProductsHtml = productsToAdd.map(p => generateProductHtml(p, isGrid)).join('');
                    
                    if (isGrid) {
                        const gridContainer = productsList.querySelector('.products-grid-container');
                        if (gridContainer) {
                            gridContainer.insertAdjacentHTML('beforeend', newProductsHtml);
                        }
                    } else {
                        productsList.insertAdjacentHTML('beforeend', newProductsHtml);
                    }
                }
                
                // Add load more button if there are more products
                if (paginationMeta.hasMore) {
                    productsList.insertAdjacentHTML('beforeend', `
                        <div class="text-center py-3" id="loadMoreContainer">
                            <button class="btn btn-outline-primary btn-sm" id="loadMoreProductsBtn" onclick="loadMoreProducts()">
                                <i class="bi bi-arrow-down-circle"></i> بینی کاڵای زیاتر
                            </button>
                        </div>
                    `);
                }
            }
        }

        function displayEmptyProductsList() {
            const productsList = document.getElementById('productsList');
            const countBadge = document.getElementById('productsCountBadge');
            countBadge.textContent = '0';
            
            productsList.innerHTML = `
                <div class="products-empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>${POS.currentSource === 'services' ? 'هیچ خزمەتگوزارییەک نییە' : 'هیچ کاڵایەک نییە لەم کەتەلۆگەدا'}</p>
                </div>
            `;
        }

        async function loadServicesForList(reset = false) {
            if (POS.servicesPagination.loading) return;

            try {
                POS.servicesPagination.loading = true;
                const productsList = document.getElementById('productsList');

                if (reset) {
                    productsList.innerHTML = `
                        <div class="text-center py-4">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <p class="mt-2 small">بارکردنی خزمەتگوزاری...</p>
                        </div>
                    `;
                }

                const result = await fetchPosServices({
                    offset: reset ? 0 : POS.servicesPagination.offset,
                    limit: POS.servicesPagination.limit
                });

                if (result.ok) {
                    const newServices = result.services;

                    if (reset) {
                        POS.services = newServices;
                    } else {
                        POS.services = [...POS.services, ...newServices];
                    }

                    POS.products = POS.services;
                    POS.servicesPagination.total = result.total || POS.services.length;
                    POS.servicesPagination.hasMore = !!result.hasMore;
                    POS.servicesPagination.offset = reset
                        ? newServices.length
                        : POS.servicesPagination.offset + newServices.length;

                    displayProductsList(POS.services, true, []);
                } else if (reset) {
                    POS.services = [];
                    POS.products = [];
                    if (!result.denied) {
                        displayEmptyProductsList();
                    }
                }
            } catch (error) {
                console.error('Error loading services:', error);
                if (reset) {
                    POS.services = [];
                    POS.products = [];
                    displayEmptyProductsList();
                }
            } finally {
                POS.servicesPagination.loading = false;
            }
        }

        function showOutOfStock() {
            showNotification('ببورە، ئەم کاڵایە بەسەرچووە', 'warning');
        }

        function handleKeyboardShortcuts(event) {
            if (event.ctrlKey || event.metaKey) {
                switch (event.key) {
                    case 'n':
                        event.preventDefault();
                        newSale();
                        break;
                    case 'd':
                        event.preventDefault();
                        clearCart();
                        break;
                    case 'Enter':
                        event.preventDefault();
                        if (!document.getElementById('checkoutBtn').disabled) {
                            processCheckout();
                        }
                        break;
                }
            }
        }

        function newSale() {
            // Create a new tab instead of just clearing current cart
            createNewSaleTab();
        }

