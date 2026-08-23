/**
 * Shopping Cart JavaScript
 * Handles cart functionality using localStorage
 */

/**
 * itemKey هاوشێوەی addToCart: یەکەتی بەرهەم + یەکەی پێوانە (ئەگەر هەبێت).
 */
function buildShopCartItemKey(productId, unitId) {
    const uid = unitId != null ? String(unitId).trim() : '';
    return uid ? `${productId}_${uid}` : String(productId);
}

/**
 * یەکسانکردنی نیشاندانی بڕ لە هەموو [data-shop-qty-widget] لەگەڵ ناوەڕۆکی سەبەتە.
 */
function syncShopQtyInputsFromCart(cartInstance) {
    document.querySelectorAll('[data-shop-qty-widget]').forEach(widget => {
        const input = widget.querySelector('.shop-qty-input');
        const dec = widget.querySelector('.shop-qty-dec');
        const inc = widget.querySelector('.shop-qty-inc');
        if (!input) return;

        const stock = parseInt(widget.dataset.stock, 10) || 0;
        if (stock <= 0) {
            widget.classList.add('shop-qty-widget--disabled');
            input.value = '0';
            input.disabled = true;
            if (dec) dec.disabled = true;
            if (inc) inc.disabled = true;
            return;
        }

        widget.classList.remove('shop-qty-widget--disabled');

        const itemKey = buildShopCartItemKey(widget.dataset.productId, widget.dataset.productUnitId || '');
        const slug = widget.dataset.shopSlug || cartInstance.getShopSlugFromUrl();
        const item = cartInstance.cart.find(i =>
            i.itemKey === itemKey &&
            (i.website_slug === slug || (!i.website_slug && !slug))
        );

        if (document.activeElement !== input) {
            input.value = item ? String(item.quantity) : '0';
        }

        const qty = item ? item.quantity : (parseInt(String(input.value).trim(), 10) || 0);
        input.disabled = false;
        if (dec) dec.disabled = qty <= 0;
        if (inc) inc.disabled = stock > 0 && qty >= stock;
    });
}

class ShoppingCart {
    constructor() {
        this.cartKey = 'shopping_cart';
        this.cart = this.loadCart();
        this.addingToCart = false; // Flag to prevent multiple simultaneous adds
        this.init();
    }

    init() {
        this.bindEvents();
        this.updateCartDisplay();
    }

    bindEvents() {
        // Cart toggle
        document.addEventListener('click', (e) => {
            if (e.target.closest('.cart-button')) {
                e.preventDefault();
                this.toggleCart();
            }
            
            if (e.target.closest('.cart-close') || e.target.closest('.cart-sidebar-overlay')) {
                this.closeCart();
            }
        });

        // Add to cart buttons
        document.addEventListener('click', (e) => {
            if (e.target.closest('.add-to-cart-btn')) {
                e.preventDefault();
                const button = e.target.closest('.add-to-cart-btn');
                
                // Prevent multiple rapid clicks
                if (this.addingToCart || button.disabled) {
                    return;
                }
                
                const productId = button.dataset.productId;
                const productName = button.dataset.productName;
                const productPrice = parseFloat(button.dataset.productPrice) || 0;
                const productImage = button.dataset.productImage;
                const productUnit = button.dataset.productUnit || 'دانە';
                const productUnitId = button.dataset.productUnitId || '';
                const websiteSlug = button.dataset.shopSlug || this.getShopSlugFromUrl();
                const productStock = parseInt(button.dataset.stock) || 0;
                const productCurrency = button.dataset.productCurrency || 'IQD';

                this.addToCart(productId, productName, productPrice, productImage, productUnit, productUnitId, websiteSlug, button, productStock, productCurrency);
            }
        });

        // Cart item controls
        document.addEventListener('click', (e) => {
            if (e.target.closest('.quantity-btn')) {
                const button = e.target.closest('.quantity-btn');
                const itemId = button.dataset.itemId;
                const action = button.dataset.action;
                
                if (action === 'increase') {
                    this.increaseQuantity(itemId);
                } else if (action === 'decrease') {
                    this.decreaseQuantity(itemId);
                }
            }
            
            if (e.target.closest('.remove-item')) {
                const button = e.target.closest('.remove-item');
                const itemId = button.dataset.itemId;
                this.removeItem(itemId);
            }
        });

        // Quantity input changes
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('quantity-input')) {
                const input = e.target;
                const itemId = input.dataset.itemId;
                const quantity = parseInt(input.value);
                
                if (quantity > 0) {
                    this.updateQuantity(itemId, quantity);
                } else {
                    this.removeItem(itemId);
                }
            }
        });

        // Checkout button
        document.addEventListener('click', (e) => {
            if (e.target.closest('.btn-checkout')) {
                e.preventDefault();
                this.proceedToCheckout();
            }

            if (e.target.closest('.btn-whatsapp-order')) {
                e.preventDefault();
                this.sendOrderViaWhatsApp();
            }
        });

        // Shop page inline quantity widget (+ / − / blur + Enter to commit)
        document.addEventListener('click', (e) => {
            const inc = e.target.closest('.shop-qty-inc');
            const dec = e.target.closest('.shop-qty-dec');
            if (!inc && !dec) return;
            const widget = e.target.closest('[data-shop-qty-widget]');
            if (!widget) return;
            const btn = inc || dec;
            if (btn.disabled) return;
            e.preventDefault();
            const input = widget.querySelector('.shop-qty-input');
            const cur = Math.max(0, parseInt(input && input.value, 10) || 0);
            if (inc) {
                this.setCartQuantityFromShopWidget(widget, cur + 1);
            } else {
                this.setCartQuantityFromShopWidget(widget, cur - 1);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (!e.target.classList.contains('shop-qty-input')) return;
            if (e.key !== 'Enter') return;
            const widget = e.target.closest('[data-shop-qty-widget]');
            if (!widget) return;
            e.preventDefault();
            e.target.blur();
        });

        document.addEventListener('focusout', (e) => {
            if (!e.target.classList.contains('shop-qty-input')) return;
            const widget = e.target.closest('[data-shop-qty-widget]');
            if (!widget) return;
            if (e.target.disabled) return;
            const raw = e.target.value.trim();
            let n = parseInt(raw, 10);
            if (raw === '' || Number.isNaN(n) || n < 0) {
                n = 0;
            }
            this.setCartQuantityFromShopWidget(widget, n);
        }, true);
    }

    readShopWidgetMeta(widget) {
        const websiteSlug = widget.dataset.shopSlug || this.getShopSlugFromUrl();
        return {
            productId: widget.dataset.productId,
            productName: widget.dataset.productName,
            productPrice: parseFloat(widget.dataset.productPrice) || 0,
            productImage: widget.dataset.productImage || '',
            productUnit: widget.dataset.productUnit || 'دانە',
            productUnitId: widget.dataset.productUnitId || '',
            websiteSlug,
            stock: parseInt(widget.dataset.stock, 10) || 0,
            currency: widget.dataset.productCurrency || 'IQD'
        };
    }

    findCartItemForMeta(meta) {
        const itemKey = buildShopCartItemKey(meta.productId, meta.productUnitId);
        return this.cart.find(item =>
            item.itemKey === itemKey &&
            (item.website_slug === meta.websiteSlug || (!item.website_slug && !meta.websiteSlug))
        );
    }

    /**
     * Set absolute quantity for the cart line matching this shop card widget (add/update/remove).
     */
    setCartQuantityFromShopWidget(widget, newQty) {
        const meta = this.readShopWidgetMeta(widget);

        let qty = parseInt(newQty, 10);
        if (Number.isNaN(qty) || qty < 0) {
            qty = 0;
        }

        const stock = meta.stock;
        if (stock > 0 && qty > stock) {
            this.showStockWarning(meta.productName, stock, meta.productUnit);
            qty = stock;
        }

        const existing = this.findCartItemForMeta(meta);
        const prevQty = existing ? existing.quantity : 0;

        if (qty <= 0) {
            if (existing) {
                this.removeItem(buildShopCartItemKey(meta.productId, meta.productUnitId));
            } else {
                syncShopQtyInputsFromCart(this);
            }
            return;
        }

        if (existing) {
            existing.quantity = qty;
            existing.price = meta.productPrice;
            existing.name = meta.productName;
            existing.image = meta.productImage;
            existing.unit = meta.productUnit;
            existing.unitId = meta.productUnitId;
            existing.currency = meta.currency || 'IQD';
            if (stock > 0) {
                existing.stock = stock;
            }
        } else {
            const itemKey = buildShopCartItemKey(meta.productId, meta.productUnitId);
            this.cart.push({
                itemKey,
                id: meta.productId,
                name: meta.productName,
                price: meta.productPrice,
                image: meta.productImage,
                unit: meta.productUnit,
                unitId: meta.productUnitId,
                website_slug: meta.websiteSlug,
                quantity: qty,
                stock: stock || 0,
                currency: meta.currency || 'IQD'
            });
        }

        this.saveCart();
        this.updateCartDisplay();

        if (qty > prevQty) {
            this.showAddToCartAnimation();
        }
    }

    /** یەکسانکردنی widget ـەکانی فرۆشگا لە دەرەوەی cart.js (وەک گۆڕینی یەکەی کاڵا). */
    syncShopQtyWidgetsFromCart() {
        syncShopQtyInputsFromCart(this);
    }

    loadCart() {
        try {
            const cartData = localStorage.getItem(this.cartKey);
            const cart = cartData ? JSON.parse(cartData) : [];
            // Migrate old cart items that don't have website_slug
            // Try to infer from URL if possible, otherwise leave empty
            return cart.map(item => {
                if (!item.website_slug) {
                    // Try to get from current URL as fallback
                    item.website_slug = this.getShopSlugFromUrl() || '';
                }
                return item;
            });
        } catch (e) {
            console.error('Error loading cart:', e);
            return [];
        }
    }

    saveCart() {
        try {
            localStorage.setItem(this.cartKey, JSON.stringify(this.cart));
        } catch (e) {
            console.error('Error saving cart:', e);
        }
    }

    addToCart(productId, name, price, image, unit, unitId = '', websiteSlug = '', button = null, stock = 0, currency = 'IQD') {
        // Prevent multiple simultaneous adds
        if (this.addingToCart) {
            return;
        }
        
        this.addingToCart = true;
        
        // Disable button to prevent multiple clicks
        if (button) {
            const originalText = button.innerHTML;
            button.disabled = true;
            button.style.opacity = '0.6';
            button.style.cursor = 'not-allowed';
        }
        
        // Get shop slug if not provided
        if (!websiteSlug) {
            websiteSlug = this.getShopSlugFromUrl();
        }

        // Create unique key for item based on product + unit combination
        const itemKey = buildShopCartItemKey(productId, unitId);
        // Also check website_slug to ensure items from different shops are separate
        const existingItem = this.cart.find(item => 
            item.itemKey === itemKey && 
            (item.website_slug === websiteSlug || (!item.website_slug && !websiteSlug))
        );

        if (existingItem) {
            // Check stock before increasing quantity
            const newQuantity = existingItem.quantity + 1;
            if (stock > 0 && newQuantity > stock) {
                this.showStockWarning(name, stock, unit);
                this.addingToCart = false;
                if (button) {
                    button.disabled = false;
                    button.style.opacity = '';
                    button.style.cursor = '';
                }
                return;
            }
            existingItem.quantity = newQuantity;
            // Update stock if provided
            if (stock > 0) {
                existingItem.stock = stock;
            }
        } else {
            // Check stock before adding new item
            if (stock > 0 && 1 > stock) {
                this.showStockWarning(name, stock, unit);
                this.addingToCart = false;
                if (button) {
                    button.disabled = false;
                    button.style.opacity = '';
                    button.style.cursor = '';
                }
                return;
            }
            this.cart.push({
                itemKey: itemKey,
                id: productId,
                name: name,
                price: price,
                image: image,
                unit: unit,
                unitId: unitId,
                website_slug: websiteSlug,
                quantity: 1,
                stock: stock || 0,
                currency: currency || 'IQD'
            });
        }

        this.saveCart();
        this.updateCartDisplay();
        this.showAddToCartAnimation();
        
        // Re-enable button after a short delay
        setTimeout(() => {
            this.addingToCart = false;
            if (button) {
                button.disabled = false;
                button.style.opacity = '';
                button.style.cursor = '';
            }
        }, 500); // 500ms delay to prevent rapid clicks
    }

    getShopSlugFromUrl() {
        // Try to get shop slug from URL
        const pathParts = window.location.pathname.split('/');
        // Look for shop slug in URL path (usually before the last part)
        for (let i = pathParts.length - 1; i >= 0; i--) {
            const part = pathParts[i];
            // Skip empty parts and common file names
            if (part && part !== 'web' && !part.includes('.php') && !part.includes('.html')) {
                return part;
            }
        }
        // Fallback: try to get from query parameter
        const urlParams = new URLSearchParams(window.location.search);
        const slug = urlParams.get('slug') || urlParams.get('shop');
        return slug || '';
    }

    increaseQuantity(itemKey) {
        const item = this.cart.find(item => item.itemKey === itemKey);
        if (item) {
            const newQuantity = item.quantity + 1;
            
            // Check stock if available
            if (item.stock && item.stock > 0) {
                if (newQuantity > item.stock) {
                    this.showStockWarning(item.name, item.stock, item.unit || 'دانە');
                    return;
                }
            }
            
            item.quantity = newQuantity;
            this.saveCart();
            this.updateCartDisplay();
        }
    }

    decreaseQuantity(itemKey) {
        const item = this.cart.find(item => item.itemKey === itemKey);
        if (item) {
            if (item.quantity > 1) {
                item.quantity -= 1;
            } else {
                this.removeItem(itemKey);
                return;
            }
            this.saveCart();
            this.updateCartDisplay();
        }
    }

    updateQuantity(itemKey, quantity) {
        const item = this.cart.find(item => item.itemKey === itemKey);
        if (item) {
            // Check stock if available
            if (item.stock && item.stock > 0) {
                if (quantity > item.stock) {
                    this.showStockWarning(item.name, item.stock, item.unit || 'دانە');
                    // Set quantity to max available stock
                    quantity = item.stock;
                }
            }
            
            if (quantity < 1) {
                this.removeItem(itemKey);
                return;
            }
            
            item.quantity = quantity;
            this.saveCart();
            this.updateCartDisplay();
        }
    }

    removeItem(itemKey) {
        this.cart = this.cart.filter(item => item.itemKey !== itemKey);
        this.saveCart();
        this.updateCartDisplay();
    }

    clearCart() {
        this.cart = [];
        this.saveCart();
        this.updateCartDisplay();
    }

    getTotalItems() {
        return this.cart.reduce((total, item) => total + item.quantity, 0);
    }

    getTotalPrice() {
        return this.cart.reduce((total, item) => total + (item.price * item.quantity), 0);
    }

    getTotalByCurrency() {
        const totals = { iqd: 0, usd: 0 };
        this.cart.forEach(item => {
            const curr = item.currency || 'IQD';
            const amount = item.price * item.quantity;
            if (curr === 'USD') totals.usd += amount;
            else totals.iqd += amount;
        });
        return totals;
    }

    updateCartDisplay() {
        this.updateCartBadge();
        this.updateCartSidebar();
        syncShopQtyInputsFromCart(this);
    }

    updateCartBadge() {
        const badges = document.querySelectorAll('.cart-badge');
        const totalItems = this.getTotalItems();
        
        badges.forEach(badge => {
            if (totalItems > 0) {
                badge.textContent = totalItems;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        });
    }

    updateCartSidebar() {
        const cartItems = document.querySelector('.cart-items');
        const cartTotalContainer = document.querySelector('.cart-total');
        const cartEmpty = document.querySelector('.cart-empty');
        const cartSummary = document.querySelector('.cart-summary');
        
        if (!cartItems) return;

        if (this.cart.length === 0) {
            cartItems.innerHTML = `
                <div class="cart-empty">
                    <div class="cart-empty-icon">
                        <i class="bi bi-cart-x"></i>
                    </div>
                    <div class="cart-empty-text">سەبەتەکەت بەتاڵە</div>
                    <p class="text-muted">کاڵایەک زیاد بکە بۆ دەستپێکردن</p>
                </div>
            `;
            
            if (cartSummary) cartSummary.style.display = 'none';
            if (cartEmpty) cartEmpty.style.display = 'block';
        } else {
            cartItems.innerHTML = this.cart.map(item => {
                const itemKey = item.itemKey || item.id; // Support old items without itemKey
                const unitName = item.unit || 'دانە';
                const unitDisplay = ` - ${unitName}`;
                const itemTotal = item.price * item.quantity;
                const maxQuantity = item.stock && item.stock > 0 ? item.stock : 999999;
                const isMaxReached = item.stock && item.stock > 0 && item.quantity >= item.stock;
                return `
                <div class="cart-item" data-item-key="${itemKey}">
                    <img src="${item.image}" alt="${item.name}" class="cart-item-image" onerror="this.src='${window.location.origin}/web/template/assets/images/no-image.svg'">
                    <div class="cart-item-details">
                        <div class="cart-item-name">${item.name}${unitDisplay}</div>
                        <div class="cart-item-price">
                            ${this.formatPrice(item.price, item.currency)} × ${item.quantity} ${unitName} = ${this.formatPrice(itemTotal, item.currency)}
                        </div>
                        ${item.stock && item.stock > 0 ? `<div class="cart-item-stock" style="font-size: 0.85rem; color: #6c757d; margin-top: 4px;">
                            <i class="bi bi-box"></i> بەردەستی: ${item.stock} ${unitName}
                        </div>` : ''}
                        <div class="cart-item-controls">
                            <button class="quantity-btn" data-item-id="${itemKey}" data-action="decrease" ${item.quantity <= 1 ? 'disabled' : ''}>
                                <i class="bi bi-dash"></i>
                            </button>
                            <input type="number" class="quantity-input" data-item-id="${itemKey}" value="${item.quantity}" min="1" max="${maxQuantity}">
                            <button class="quantity-btn" data-item-id="${itemKey}" data-action="increase" ${isMaxReached ? 'disabled' : ''} title="${isMaxReached ? 'بەردەستی تەواو بووە' : ''}">
                                <i class="bi bi-plus"></i>
                            </button>
                            <button class="remove-item" data-item-id="${itemKey}" title="لابردن">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                `;
            }).join('');

            if (cartTotalContainer) {
                const totals = this.getTotalByCurrency();
                const hasIqd = totals.iqd > 0;
                const hasUsd = totals.usd > 0;
                if (hasIqd && hasUsd) {
                    cartTotalContainer.innerHTML = '<div>کۆی دینار: ' + this.formatPrice(totals.iqd, 'IQD') + '</div><div>کۆی دۆلار: ' + this.formatPrice(totals.usd, 'USD') + '</div>';
                } else if (hasIqd) {
                    cartTotalContainer.innerHTML = '<span>کۆی گشتی:</span><span class="cart-total-amount">' + this.formatPrice(totals.iqd, 'IQD') + '</span>';
                } else {
                    cartTotalContainer.innerHTML = '<span>کۆی گشتی:</span><span class="cart-total-amount">' + this.formatPrice(totals.usd, 'USD') + '</span>';
                }
            }

            if (cartSummary) cartSummary.style.display = 'block';
            if (cartEmpty) cartEmpty.style.display = 'none';
        }

        this.updateWhatsAppOrderButton();
    }

    getWhatsAppShopConfig() {
        return window.shopCartWhatsApp && typeof window.shopCartWhatsApp === 'object'
            ? window.shopCartWhatsApp
            : null;
    }

    updateWhatsAppOrderButton() {
        const btn = document.getElementById('sendWhatsAppOrderBtn');
        if (!btn) {
            return;
        }

        const config = this.getWhatsAppShopConfig();
        const hasItemsForShop = this.getCartItemsForWhatsAppShop(config).length > 0;
        const shouldShow = !!(config && config.available && hasItemsForShop);

        btn.classList.toggle('d-none', !shouldShow);
        btn.disabled = !shouldShow;
    }

    getCartItemsForWhatsAppShop(config) {
        if (!config || !config.slug) {
            return [];
        }

        return this.cart.filter(item =>
            (item.website_slug === config.slug || (!item.website_slug && !config.slug))
        );
    }

    formatWhatsAppPhone(phone) {
        let formatted = String(phone).replace(/[\s\-\(\)]/g, '');
        if (!formatted.startsWith('964') && !formatted.startsWith('+964')) {
            if (formatted.startsWith('0')) {
                formatted = '964' + formatted.substring(1);
            } else {
                formatted = '964' + formatted;
            }
        }
        return formatted.replace(/^\+/, '');
    }

    buildCartOrderMessage(items, config) {
        let message = '📋 *داواکاری نوێ*\n\n';
        message += `🏪 *${config.business_name || config.slug}*\n\n`;
        message += '━━━━━━━━━━━━━━━━\n\n';

        let totalIqd = 0;
        let totalUsd = 0;

        items.forEach(item => {
            const unitName = item.unit || 'دانە';
            const itemTotal = (parseFloat(item.price) || 0) * (parseInt(item.quantity, 10) || 0);
            const currency = item.currency || 'IQD';

            if (currency === 'USD') {
                totalUsd += itemTotal;
            } else {
                totalIqd += itemTotal;
            }

            message += `• ${item.name}\n`;
            message += `  ${item.quantity} ${unitName} × ${this.formatPrice(item.price, currency)} = ${this.formatPrice(itemTotal, currency)}\n\n`;
        });

        message += '━━━━━━━━━━━━━━━━\n\n';

        const hasBoth = totalIqd > 0 && totalUsd > 0;
        if (hasBoth) {
            message += `💰 *کۆی دینار:* ${this.formatPrice(totalIqd, 'IQD')}\n`;
            message += `💰 *کۆی دۆلار:* ${this.formatPrice(totalUsd, 'USD')}\n\n`;
        } else if (totalIqd > 0) {
            message += `💰 *کۆی گشتی:* ${this.formatPrice(totalIqd, 'IQD')}\n\n`;
        } else {
            message += `💰 *کۆی گشتی:* ${this.formatPrice(totalUsd, 'USD')}\n\n`;
        }

        message += ' NexoraCore \n nexoracore.com';
        return message;
    }

    sendOrderViaWhatsApp() {
        const config = this.getWhatsAppShopConfig();
        if (!config || !config.available || !config.phone) {
            alert('ناردنی لە واتس ئەپ بەردەست نییە');
            return;
        }

        const items = this.getCartItemsForWhatsAppShop(config);
        if (items.length === 0) {
            alert('سەبەتەکەت بەتاڵە');
            return;
        }

        const phone = this.formatWhatsAppPhone(config.phone);
        const message = this.buildCartOrderMessage(items, config);
        const whatsappUrl = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;

        window.open(whatsappUrl, '_blank');
    }

    toggleCart() {
        const sidebar = document.querySelector('.cart-sidebar');
        const overlay = document.querySelector('.cart-sidebar-overlay');
        
        if (sidebar && overlay) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
            
            // Prevent body scroll when cart is open
            if (sidebar.classList.contains('open')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }
    }

    closeCart() {
        const sidebar = document.querySelector('.cart-sidebar');
        const overlay = document.querySelector('.cart-sidebar-overlay');
        
        if (sidebar && overlay) {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    showAddToCartAnimation() {
        // Simple animation feedback
        const cartButton = document.querySelector('.cart-button');
        if (cartButton) {
            cartButton.style.transform = 'scale(1.1)';
            setTimeout(() => {
                cartButton.style.transform = '';
            }, 200);
        }
    }

    showStockWarning(productName, availableStock, unitName = 'دانە') {
        // Create or get notification container
        let notificationContainer = document.getElementById('stock-notification-container');
        if (!notificationContainer) {
            notificationContainer = document.createElement('div');
            notificationContainer.id = 'stock-notification-container';
            notificationContainer.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 10000;
                max-width: 500px;
                width: 90%;
            `;
            document.body.appendChild(notificationContainer);
        }

        // Create notification element
        const notification = document.createElement('div');
        notification.className = 'stock-warning-notification';
        notification.style.cssText = `
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 15px;
            padding: 18px 24px;
            margin-bottom: 12px;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
            animation: slideDown 0.4s ease-out;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
            font-weight: 600;
            font-size: 0.95rem;
        `;
        
        notification.innerHTML = `
            <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.5rem; color: #f59e0b;"></i>
            <div style="flex: 1;">
                <div style="font-weight: 700; margin-bottom: 4px;">ئاگاداری بەردەستی</div>
                <div style="font-size: 0.9rem; font-weight: 500;">
                    بڕی داواکراو بۆ ( ${productName} ) زیاتر لە بڕی بەردەست (بەردەستی: ${availableStock} ${unitName})
                </div>
            </div>
            <button class="close-notification" style="background: none; border: none; color: #92400e; font-size: 1.5rem; cursor: pointer; padding: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">&times;</button>
        `;

        // Add close button functionality
        const closeBtn = notification.querySelector('.close-notification');
        closeBtn.addEventListener('click', () => {
            notification.style.animation = 'slideUp 0.3s ease-out';
            setTimeout(() => {
                notification.remove();
            }, 300);
        });

        // Add CSS animation if not already added
        if (!document.getElementById('stock-notification-styles')) {
            const style = document.createElement('style');
            style.id = 'stock-notification-styles';
            style.textContent = `
                @keyframes slideDown {
                    from {
                        opacity: 0;
                        transform: translateY(-20px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                @keyframes slideUp {
                    from {
                        opacity: 1;
                        transform: translateY(0);
                    }
                    to {
                        opacity: 0;
                        transform: translateY(-20px);
                    }
                }
            `;
            document.head.appendChild(style);
        }

        notificationContainer.appendChild(notification);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideUp 0.3s ease-out';
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }
        }, 5000);
    }

    showAddToCartToast(message) {
        const text = message || 'ئەم کاڵایە زیاد کرا بۆ سەبەتە';

        let toast = document.getElementById('cart-add-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'cart-add-toast';
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%) translateY(-20px);
                background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
                color: #ecfdf5;
                padding: 10px 18px;
                border-radius: 999px;
                font-size: 0.9rem;
                font-weight: 600;
                box-shadow: 0 15px 30px rgba(22, 163, 74, 0.45);
                z-index: 11000;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.2s ease, transform 0.2s ease;
                display: flex;
                align-items: center;
                gap: 8px;
            `;
            toast.innerHTML = `
                <i class="bi bi-check-circle-fill" style="font-size: 1.1rem;"></i>
                <span class="cart-add-toast-text"></span>
            `;
            document.body.appendChild(toast);
        }

        const textSpan = toast.querySelector('.cart-add-toast-text');
        if (textSpan) {
            textSpan.textContent = text;
        }

        // Show
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
        });

        // Hide after short delay
        clearTimeout(this._cartToastTimer);
        this._cartToastTimer = setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(-20px)';
        }, 2000);
    }

    formatPrice(price, currency) {
        currency = currency || 'IQD';
        const numPrice = parseFloat(price) || 0;
        const isUsd = currency === 'USD';
        const decimals = isUsd ? 2 : 0;
        const formatted = isUsd ? numPrice.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : Math.floor(numPrice).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return `${formatted} ${isUsd ? 'دۆلار' : 'دینار'}`;
    }

    proceedToCheckout() {
        if (this.cart.length === 0) {
            alert('سەبەتەکەت بەتاڵە');
            return;
        }

        // Try to detect shop slug(s) from cart items first
        const slugs = new Set();
        this.cart.forEach(item => {
            if (item.website_slug) {
                slugs.add(item.website_slug);
            }
        });

        let checkoutUrl = window.location.origin + '/web/checkout.php';

        if (slugs.size === 1) {
            const slug = Array.from(slugs)[0];
            if (slug) {
                checkoutUrl += '?shop=' + encodeURIComponent(slug);
            }
        } else if (slugs.size === 0) {
            // فۆڵباک بۆ شێوازی کۆن کە slug لە URL وەردەگرێت
            const pathParts = window.location.pathname.split('/');
            const candidate = pathParts[pathParts.length - 2] || pathParts[pathParts.length - 1];
            if (candidate && candidate !== 'web' && !candidate.includes('.php')) {
                checkoutUrl += '?shop=' + encodeURIComponent(candidate);
            }
        }

        window.location.href = checkoutUrl;
    }

    // Public methods for external use
    getCartData() {
        return {
            items: this.cart,
            totalItems: this.getTotalItems(),
            totalPrice: this.getTotalPrice()
        };
    }
}

// Initialize cart when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.shoppingCart = new ShoppingCart();
});

// Export for global access
window.ShoppingCart = ShoppingCart;
