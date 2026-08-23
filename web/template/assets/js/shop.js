// Shop JavaScript - Enhanced Modern Version
// Template for all websites

(function() {
    'use strict';
    
    // Configuration
    const CONFIG = {
        searchDelay: 300,
        animationDelay: 100,
        scrollOffset: 100
    };
    
    // State management
    const state = {
        isSearching: false,
        currentCategory: 'all',
        searchTerm: '',
        currentUnit: 'kg' // Added new state property for unit
    };
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log('🛍️ Shop loaded successfully');
        
        // Initialize all features
        initializeSmoothScrolling();
        initializeCategoryFiltering();
        initializeLoadingStates();
        initializeAnimations();
        initializeLiveSearch();
        initializeKeyboardNavigation();
        initializeBackToTop();
        initializeProductCards();
        initializeLazyLoading();
        initializeImageErrorHandling();
        initializeUnitSelectors();
        initializeSortSelector();
        initializeViewToggle();
        initializeQuickView();
        initializeStickyNavbar();
        
        // Performance monitoring
        logPerformance();
    });
    
    /**
     * Live search functionality
     */
    function initializeLiveSearch() {
        const searchInput = document.querySelector('input[name="search"]');
        if (!searchInput) return;
        
        let searchTimeout;
        
        searchInput.addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            const term = e.target.value.toLowerCase().trim();
            state.searchTerm = term;
            
            searchTimeout = setTimeout(() => {
                filterProductsLive(term);
            }, CONFIG.searchDelay);
        });
        
        // Clear search on Escape key
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                this.value = '';
                filterProductsLive('');
                this.blur();
            }
        });
    }
    
    /**
     * Filter products in real-time
     */
    function filterProductsLive(term) {
        const products = document.querySelectorAll('.product-card');
        let visibleCount = 0;
        
        products.forEach((card, index) => {
            const productName = card.querySelector('.card-title')?.textContent.toLowerCase() || '';
            const productBarcode = card.querySelector('.bi-upc')?.parentElement?.textContent.toLowerCase() || '';
            const parent = card.closest('.col-lg-4');
            
            if (!term || productName.includes(term) || productBarcode.includes(term)) {
                parent.style.display = 'block';
                setTimeout(() => {
                    card.classList.add('fade-in');
                }, index * 30);
                visibleCount++;
            } else {
                parent.style.display = 'none';
                card.classList.remove('fade-in');
            }
        });
        
        updateResultsCount(visibleCount, products.length);
    }
    
    /**
     * Update search results count
     */
    function updateResultsCount(visible, total) {
        let countElement = document.querySelector('.results-count');
        
        if (!countElement && visible < total) {
            countElement = document.createElement('div');
            countElement.className = 'results-count alert alert-info text-center';
            countElement.style.marginBottom = '1rem';
            
            const container = document.querySelector('.row.g-3');
            if (container) {
                container.parentNode.insertBefore(countElement, container.nextSibling);
            }
        }
        
        if (countElement) {
            if (visible < total) {
                countElement.innerHTML = `<i class="bi bi-info-circle"></i> ${visible} لە ${total} کاڵا دەردەکەون`;
                countElement.style.display = 'block';
            } else {
                countElement.style.display = 'none';
            }
        }
    }
    
    /**
     * Keyboard navigation support
     */
    function initializeKeyboardNavigation() {
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
        });
    }
    
    /**
     * Back to top button
     */
    function initializeBackToTop() {
        const backToTopBtn = createBackToTopButton();
        
        window.addEventListener('scroll', () => {
            if (window.pageYOffset > 300) {
                backToTopBtn.classList.add('visible');
            } else {
                backToTopBtn.classList.remove('visible');
            }
        });
        
        backToTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
    
    /**
     * Create back to top button
     */
    function createBackToTopButton() {
        let btn = document.querySelector('.back-to-top');
        
        if (!btn) {
            btn = document.createElement('button');
            btn.className = 'back-to-top';
            btn.innerHTML = '<i class="bi bi-arrow-up"></i>';
            btn.setAttribute('aria-label', 'گەڕانەوە بۆ سەرەوە');
            
            // Styles
            Object.assign(btn.style, {
                position: 'fixed',
                bottom: '20px',
                left: '20px',
                width: '50px',
                height: '50px',
                borderRadius: '50%',
                background: 'linear-gradient(135deg, #0d6efd, #0056b3)',
                color: 'white',
                border: 'none',
                cursor: 'pointer',
                opacity: '0',
                visibility: 'hidden',
                transition: 'all 0.3s ease',
                boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)',
                zIndex: '1000',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: '1.2rem'
            });
            
            document.body.appendChild(btn);
        }
        
        // Add CSS for visible state
        const style = document.createElement('style');
        style.textContent = `
            .back-to-top.visible {
                opacity: 1 !important;
                visibility: visible !important;
            }
            .back-to-top:hover {
                transform: translateY(-5px);
                box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
            }
        `;
        document.head.appendChild(style);
        
        return btn;
    }
    
    /**
     * Enhanced product cards interactions
     */
    function initializeProductCards() {
        const cards = document.querySelectorAll('.product-card');
        
        cards.forEach(card => {
            // Add click event for mobile
            card.addEventListener('click', function(e) {
                if (window.innerWidth <= 768 && !e.target.closest('a, button')) {
                    this.classList.toggle('active');
                }
            });
            
            // Intersection Observer for scroll animations
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('fade-in');
                            observer.unobserve(entry.target);
                        }
                    });
                }, {
                    threshold: 0.1,
                    rootMargin: '50px'
                });
                
                observer.observe(card);
            }
        });
    }
    
    /**
     * Log performance metrics
     */
    function logPerformance() {
        if (window.performance && window.performance.timing) {
            window.addEventListener('load', () => {
                setTimeout(() => {
                    const perfData = window.performance.timing;
                    const pageLoadTime = perfData.loadEventEnd - perfData.navigationStart;
                    console.log(`⚡ Page load time: ${pageLoadTime}ms`);
                }, 0);
            });
        }
    }

/**
 * Initialize smooth scrolling for anchor links
 */
function initializeSmoothScrolling() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href === '#' || href === '#!') return;
            
            e.preventDefault();
            const target = document.querySelector(href);
            
            if (target) {
                const offsetTop = target.getBoundingClientRect().top + window.pageYOffset - CONFIG.scrollOffset;
                
                window.scrollTo({
                    top: offsetTop,
                    behavior: 'smooth'
                });
                
                // Update URL without triggering scroll
                if (history.pushState) {
                    history.pushState(null, null, href);
                }
            }
        });
    });
}

/**
 * Initialize category filtering functionality
 */
function initializeCategoryFiltering() {
    const categoryButtons = document.querySelectorAll('.category-filter .btn');
    
    categoryButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            // Don't prevent default - let the page navigate to the new URL
            // This will refresh the page with the filtered products
            
            // Add loading state for better UX
            addLoadingState(this);
            
            // The page will reload with the new category filter
            // No need to prevent default or handle filtering client-side
        });
    });
}

/**
 * Filter products based on category
 */
function filterProducts(category) {
    const products = document.querySelectorAll('.product-card');
    
    products.forEach(product => {
        const productCategory = product.getAttribute('data-category') || '';
        
        if (category === 'all' || productCategory === category) {
            product.style.display = 'block';
            product.classList.add('fade-in');
        } else {
            product.style.display = 'none';
        }
    });
}

/**
 * Initialize loading states for buttons and forms
 */
function initializeLoadingStates() {
    // Add loading state to buttons on click
    document.querySelectorAll('button[type="submit"]').forEach(button => {
        button.addEventListener('click', function() {
            if (this.form && this.form.checkValidity()) {
                addLoadingState(this);
            }
        });
    });
    
    // Add loading state to navigation links (not category filters)
    document.querySelectorAll('a[href]').forEach(link => {
        // Skip category filter buttons and anchor links
        if (link.closest('.category-filter') || link.href.includes('#')) {
            return;
        }
        
        link.addEventListener('click', function(e) {
            if (this.href && !this.target) {
                addLoadingState(this);
            }
        });
    });
}

/**
 * Add loading state to element
 */
function addLoadingState(element) {
    element.classList.add('loading');
    element.style.pointerEvents = 'none';
    
    if (element.tagName === 'BUTTON' || element.tagName === 'A') {
        const originalText = element.innerHTML;
        element.setAttribute('data-original-text', originalText);
        element.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>چاوەڕوانی...';
        
        // For links, we'll let the page navigate after a brief delay
        if (element.tagName === 'A') {
            setTimeout(() => {
                window.location.href = element.href;
            }, 200);
        }
    }
}

/**
 * Initialize animations for elements
 */
function initializeAnimations() {
    // Stagger animations for cards
    const cards = document.querySelectorAll('.product-card, .shop-card');
    cards.forEach((card, index) => {
        setTimeout(() => {
            card.style.animationDelay = `${index * CONFIG.animationDelay}ms`;
            card.classList.add('fade-in');
        }, index * 50);
    });
    
    // Navigation animation
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        navbar.classList.add('slide-in');
    }
    
    // Stats animation
    const stats = document.querySelectorAll('.stat-item');
    if (stats.length > 0) {
        setTimeout(() => {
            stats.forEach((stat, index) => {
                setTimeout(() => {
                    stat.classList.add('bounce-in');
                }, index * 100);
            });
        }, 300);
    }
}

/**
 * Utility function to show loading overlay
 */
function showLoadingOverlay() {
    const overlay = document.createElement('div');
    overlay.id = 'loading-overlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    `;
    
    const spinner = document.createElement('div');
    spinner.style.cssText = `
        width: 40px;
        height: 40px;
        border: 4px solid #f3f3f3;
        border-top: 4px solid #0d6efd;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    `;
    
    overlay.appendChild(spinner);
    document.body.appendChild(overlay);
}

/**
 * Utility function to hide loading overlay
 */
function hideLoadingOverlay() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

/**
 * Utility function to show notification with animations
 */
function showNotification(message, type = 'info', duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade position-fixed`;
    notification.style.cssText = `
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        animation: slideIn 0.3s ease-out;
    `;
    
    notification.innerHTML = `
        <i class="bi bi-${getNotificationIcon(type)}"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Trigger show animation
    setTimeout(() => notification.classList.add('show'), 10);
    
    // Auto remove
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }, duration);
}

/**
 * Get icon for notification type
 */
function getNotificationIcon(type) {
    const icons = {
        success: 'check-circle',
        danger: 'exclamation-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };
    return icons[type] || 'info-circle';
}

/**
 * Utility function to format numbers (prices)
 */
function formatNumber(number) {
    // Use same format as PHP: number_format with comma separator
    if (isNaN(number) || number === null || number === undefined) {
        return '0';
    }
    const num = Math.floor(parseFloat(number));
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * Utility function to format currency
 */
function formatCurrency(amount, currency = 'دینار') {
    return `${formatNumber(amount)} ${currency}`;
}

/** بۆ نوێکردنەوەی HTML ـی بەردەستی بە شێوەی سەلامەت */
function escapeHtmlShop(text) {
    if (text == null || text === '') return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Initialize unit selectors for products with multiple units
 */
function initializeUnitSelectors() {
    const unitSelectors = document.querySelectorAll('.unit-selector');
    
    unitSelectors.forEach(selector => {
        selector.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const productCard = this.closest('.product-card');
            
            if (!productCard) {
                console.log('❌ Product card not found');
                return;
            }
            
            // Get price data from selected option with better error handling
            const retailPriceAttr = selectedOption.getAttribute('data-retail-price');
            const wholesalePriceAttr = selectedOption.getAttribute('data-wholesale-price');
            const specialPriceAttr = selectedOption.getAttribute('data-special-price');
            const stockAttr = selectedOption.getAttribute('data-stock');
            
            // Parse values with proper fallback
            const retailPrice = retailPriceAttr ? parseFloat(retailPriceAttr) : 0;
            const wholesalePrice = wholesalePriceAttr ? parseFloat(wholesalePriceAttr) : 0;
            const specialPrice = specialPriceAttr ? parseFloat(specialPriceAttr) : 0;
            const stock = stockAttr ? parseFloat(stockAttr) : 0;
            
            console.log('🔄 Unit changed:', {
                retail: retailPrice,
                wholesale: wholesalePrice,
                special: specialPrice,
                stock: stock
            });
            
            // Update prices with smooth animation
            updatePriceWithAnimation(productCard, 'retail', retailPrice);
            updatePriceWithAnimation(productCard, 'wholesale', wholesalePrice);
            updatePriceWithAnimation(productCard, 'special', specialPrice);
            
            // Update stock quantity (ناوی یەکە لە widget ـی بڕ نوێدەکرێتەوە)
            updateStockQuantity(productCard, stock);

            // Update shop qty widget (or legacy add-to-cart button) with new unit data
            updateAddToCartButton(productCard, selectedOption);

            // Visual feedback
            productCard.classList.add('unit-changed');
            setTimeout(() => {
                productCard.classList.remove('unit-changed');
            }, 300);
        });
    });
}

/**
 * Update shop inline qty widget or legacy add-to-cart button when unit/price changes
 */
function updateAddToCartButton(card, selectedOption) {
    const widget = card.querySelector('[data-shop-qty-widget]');
    const addToCartBtn = card.querySelector('.add-to-cart-btn');
    const targetEl = widget || addToCartBtn;

    if (!targetEl) return;

    const unitName = selectedOption.textContent.trim();
    const unitId = selectedOption.value;

    const retailPrice = parseFloat(selectedOption.getAttribute('data-retail-price')) || 0;
    const wholesalePrice = parseFloat(selectedOption.getAttribute('data-wholesale-price')) || 0;
    const specialPrice = parseFloat(selectedOption.getAttribute('data-special-price')) || 0;

    let discountPrice = parseFloat(targetEl.getAttribute('data-discount-price')) || 0;
    if (discountPrice === 0) {
        const discountPriceElement = card.querySelector('[data-price-type="discount"] .price-value');
        if (discountPriceElement) {
            const priceText = discountPriceElement.textContent.replace(/[^0-9.]/g, '');
            discountPrice = parseFloat(priceText) || 0;
        }
    }

    const showWholesale = targetEl.getAttribute('data-show-wholesale') === '1';
    const showSpecial = targetEl.getAttribute('data-show-special') === '1';

    const stock = parseFloat(selectedOption.getAttribute('data-stock')) || 0;

    let priceForCart = 0;

    if (discountPrice > 0) {
        priceForCart = discountPrice;
    } else if (showSpecial && specialPrice > 0) {
        priceForCart = specialPrice;
    } else if (showWholesale && wholesalePrice > 0) {
        priceForCart = wholesalePrice;
    } else {
        priceForCart = retailPrice;
    }

    targetEl.setAttribute('data-product-price', priceForCart);
    targetEl.setAttribute('data-product-unit', unitName);
    targetEl.setAttribute('data-product-unit-id', unitId);
    targetEl.setAttribute('data-stock', stock);

    targetEl.setAttribute('data-retail-price', retailPrice);
    targetEl.setAttribute('data-wholesale-price', wholesalePrice);
    targetEl.setAttribute('data-special-price', specialPrice);
    targetEl.setAttribute('data-discount-price', discountPrice);

    if (widget) {
        const dec = widget.querySelector('.shop-qty-dec');
        const inc = widget.querySelector('.shop-qty-inc');
        const inp = widget.querySelector('.shop-qty-input');
        const unitLabelEl = widget.querySelector('[data-shop-qty-unit-label]');
        if (unitLabelEl) {
            unitLabelEl.textContent = unitName;
        }

        if (stock <= 0) {
            widget.classList.add('shop-qty-widget--disabled');
            if (dec) dec.disabled = true;
            if (inc) inc.disabled = true;
            if (inp) {
                inp.disabled = true;
                inp.value = '0';
            }
        } else {
            widget.classList.remove('shop-qty-widget--disabled');
            if (inp) inp.disabled = false;
        }

        if (window.shoppingCart && typeof window.shoppingCart.syncShopQtyWidgetsFromCart === 'function') {
            window.shoppingCart.syncShopQtyWidgetsFromCart();
        } else {
            const qVal = inp ? (parseInt(inp.value, 10) || 0) : 0;
            if (dec) dec.disabled = stock <= 0 || qVal <= 0;
            if (inc) inc.disabled = stock <= 0 || (stock > 0 && qVal >= stock);
        }
    } else if (addToCartBtn) {
        if (stock > 0) {
            addToCartBtn.disabled = false;
            addToCartBtn.classList.remove('btn-secondary');
            addToCartBtn.classList.add('btn-primary');
        } else {
            addToCartBtn.disabled = true;
            addToCartBtn.classList.remove('btn-primary');
            addToCartBtn.classList.add('btn-secondary');
        }
    }

    console.log('🛒 Updated cart control:', {
        price: priceForCart,
        priceType: discountPrice > 0 ? 'discount' : (showSpecial && specialPrice > 0 ? 'special' : (showWholesale && wholesalePrice > 0 ? 'wholesale' : 'retail')),
        unit: unitName,
        unitId: unitId,
        stock: stock,
        widget: !!widget
    });
}

/**
 * Update price with smooth animation
 */
function updatePriceWithAnimation(card, priceType, newPrice) {
    const priceItem = card.querySelector(`[data-price-type="${priceType}"]`);
    
    if (!priceItem) return;
    
    const priceValueElement = priceItem.querySelector('.price-value');
    
    if (!priceValueElement) return;
    
    // Parse price properly
    const parsedPrice = parseFloat(newPrice);
    const finalPrice = isNaN(parsedPrice) ? 0 : parsedPrice;
    
    // Hide/show based on whether price exists
    if (finalPrice <= 0) {
        priceItem.style.display = 'none';
        return;
    } else {
        priceItem.style.display = 'flex';
    }
    
    // Add animation class
    priceValueElement.classList.add('price-updating');
    
    // Update the price after a brief delay for animation
    setTimeout(() => {
        priceValueElement.textContent = formatCurrency(finalPrice);
        priceValueElement.classList.remove('price-updating');
        priceValueElement.classList.add('price-updated');
        
        setTimeout(() => {
            priceValueElement.classList.remove('price-updated');
        }, 500);
    }, 150);
}

/**
 * Update stock quantity (بەردەستی — بێ ناوی یەکە؛ یەکە لە تەنیشت بڕی داواکاری دەردەکەوێت)
 */
function updateStockQuantity(card, newStock) {
    const stockContainer = card.querySelector('[data-stock-container]');
    const stockElement = card.querySelector('.stock-quantity');
    
    if (!stockContainer || !stockElement) return;
    
    // Parse stock properly
    const parsedStock = parseFloat(newStock);
    const finalStock = isNaN(parsedStock) ? 0 : parsedStock;
    
    // Add animation
    stockElement.classList.add('stock-updating');
    
    setTimeout(() => {
        // Update stock display (تەنها ژمارەی بەردەستی)
        const smallElement = stockContainer.querySelector('small');
        if (smallElement) {
            const icon = smallElement.querySelector('i');
            const iconHtml = icon ? icon.outerHTML + ' ' : '';
            smallElement.innerHTML =
                iconHtml +
                '<span class="shop-stock-label">بەردەستی:</span> ' +
                '<span class="stock-quantity shop-stock-qty">' +
                Math.floor(finalStock) +
                '</span>';
            
            // Re-select the stock element after updating innerHTML
            const updatedStockElement = stockContainer.querySelector('.stock-quantity');
            if (updatedStockElement) {
                updatedStockElement.classList.remove('stock-updating');
                updatedStockElement.classList.add('stock-updated');
                
                setTimeout(() => {
                    updatedStockElement.classList.remove('stock-updated');
                }, 500);
            }
        } else {
            stockElement.textContent = Math.floor(finalStock);
            stockElement.classList.remove('stock-updating');
            stockElement.classList.add('stock-updated');
            
            setTimeout(() => {
                stockElement.classList.remove('stock-updated');
            }, 500);
        }
    }, 150);
}



/**
 * Initialize sort selector
 */
function initializeSortSelector() {
    const sortSelector = document.querySelector('.sort-selector');
    if (!sortSelector) return;
    
    sortSelector.addEventListener('change', function() {
        const sortValue = this.value;
        const url = new URL(window.location.href);
        url.searchParams.set('sort', sortValue);
        
        // Preserve other parameters
        if (window.SHOP_CONFIG && window.SHOP_CONFIG.categoryFilter) {
            url.searchParams.set('category', window.SHOP_CONFIG.categoryFilter);
        }
        if (window.SHOP_CONFIG && window.SHOP_CONFIG.viewMode) {
            url.searchParams.set('view', window.SHOP_CONFIG.viewMode);
        }
        
        window.location.href = url.toString();
    });
}

/**
 * Initialize view toggle (grid/list)
 */
function initializeViewToggle() {
    const viewToggles = document.querySelectorAll('input[name="viewMode"]');
    if (!viewToggles.length) return;
    
    viewToggles.forEach(toggle => {
        toggle.addEventListener('change', function() {
            const viewValue = this.value;
            const url = new URL(window.location.href);
            url.searchParams.set('view', viewValue);
            
            // Preserve other parameters
            if (window.SHOP_CONFIG && window.SHOP_CONFIG.sortBy) {
                url.searchParams.set('sort', window.SHOP_CONFIG.sortBy);
            }
            if (window.SHOP_CONFIG && window.SHOP_CONFIG.categoryFilter) {
                url.searchParams.set('category', window.SHOP_CONFIG.categoryFilter);
            }
            
            window.location.href = url.toString();
        });
    });
}

/**
 * Initialize sticky navbar that shrinks on scroll
 */
function initializeStickyNavbar() {
    const navbar = document.getElementById('mainNavbar');
    if (!navbar) return;
    
    let lastScrollTop = 0;
    const scrollThreshold = 100; // Pixels to scroll before shrinking
    
    window.addEventListener('scroll', function() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        
        if (scrollTop > scrollThreshold) {
            navbar.classList.add('sticky-scrolled');
        } else {
            navbar.classList.remove('sticky-scrolled');
        }
        
        lastScrollTop = scrollTop;
    }, { passive: true });
}

/**
 * Initialize quick view modal
 */
function initializeQuickView() {
    const quickViewBtns = document.querySelectorAll('.quick-view-btn');
    const quickViewModal = document.getElementById('quickViewModal');
    const quickViewContent = document.getElementById('quickViewContent');
    
    if (!quickViewModal || !quickViewContent) return;
    
    quickViewBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const productId = this.dataset.productId;
            const slug = this.dataset.slug || (window.SHOP_CONFIG && window.SHOP_CONFIG.slug);
            
            if (!productId || !slug) {
                console.error('Missing product ID or slug');
                return;
            }
            
            // Show modal
            const modal = new bootstrap.Modal(quickViewModal);
            modal.show();
            
            // Load product details
            loadQuickViewContent(productId, slug, quickViewContent);
        });
    });
    
    // Close modal on escape
    quickViewModal.addEventListener('hidden.bs.modal', function() {
        quickViewContent.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">چاوەڕوانی...</span></div></div>';
    });
}

/**
 * Load quick view content
 */
async function loadQuickViewContent(productId, slug, container) {
    try {
        const siteUrl = window.SHOP_CONFIG && window.SHOP_CONFIG.siteUrl || '';
        const url = `${siteUrl}web/api/quick-view.php?product_id=${productId}&slug=${slug}`;
        
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const html = await response.text();
        container.innerHTML = html;
        
        // Initialize any scripts in the loaded content
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
        
    } catch (error) {
        console.error('Error loading quick view:', error);
        container.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i>
                هەڵەیەک ڕوویدا لە بارکردنی زانیارییەکان. تکایە دوبارە هەوڵ بدەوە.
            </div>
        `;
    }
}

// Export functions for external use
window.ShopUtils = {
    showLoadingOverlay,
    hideLoadingOverlay,
    showNotification,
    formatNumber,
    formatCurrency,
    filterProducts,
    filterProductsLive,
    addLoadingState,
    updatePriceWithAnimation,
    updateStockQuantity,
    updateAddToCartButton,
    loadQuickViewContent
};

})(); // End of IIFE
