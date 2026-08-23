/**
 * JavaScript سەرەکی - assets/js/main.js
 * NexoraCore
 */

// Load timezone utilities first
if (typeof timezoneManager === 'undefined') {
    // Load timezone.js if not already loaded
    const timezoneScript = document.createElement('script');
    timezoneScript.src = 'assets/js/timezone.js';
    document.head.appendChild(timezoneScript);
}

// Global fetch interceptor to handle 401 unauthorized responses
const originalFetch = window.fetch;
window.fetch = async function (...args) {
    try {
        const response = await originalFetch.apply(window, args);
        if (response.status === 401) {
            try {
                const data = await response.clone().json();
                if (data && data.redirect) {
                    window.location.href = data.redirect;
                    return new Promise(() => { }); // Make promise hang so caller doesn't proceed
                }
            } catch (e) {
                // Not json, just reload
                window.location.reload();
            }
        }
        return response;
    } catch (error) {
        throw error;
    }
};

// Global session keepalive for authenticated pages
function initSessionKeepAlive() {
    if (window.__kasherSessionKeepAliveInitialized) {
        return;
    }

    const hasUser =
        typeof window.POS_CONFIG !== 'undefined' &&
        window.POS_CONFIG &&
        window.POS_CONFIG.currentUser;
    if (!hasUser) {
        return;
    }

    window.__kasherSessionKeepAliveInitialized = true;
    const keepAliveUrl = `${window.POS_CONFIG.baseUrl}api/session_keepalive.php`;
    const intervalMs = 5 * 60 * 1000; // 5 minutes
    let inFlight = false;

    const ping = async () => {
        if (inFlight) return;
        inFlight = true;

        try {
            const response = await fetch(keepAliveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ refresh: true })
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            if (data && data.success && data.data && data.data.csrf_token) {
                window.POS_CONFIG.csrf_token = data.data.csrf_token;

                const csrfInput = document.querySelector('input[name="csrf_token"]');
                if (csrfInput) {
                    csrfInput.value = data.data.csrf_token;
                }
            }
        } catch (e) {
            // silent on temporary network/server issues
        } finally {
            inFlight = false;
        }
    };

    ping();
    setInterval(ping, intervalMs);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) ping();
    });
    window.addEventListener('focus', ping);
}

// Global variables
let cart = [];
let products = [];
let currentUser = null;

// Document Ready
document.addEventListener('DOMContentLoaded', function () {
    initSessionKeepAlive();
    initializeApp();
    setupEventListeners();
    loadUserData();
});

/**
 * دەستپێکردنی ئەپلیکەیشن
 */
function initializeApp() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Auto-hide alerts
    setTimeout(function () {
        const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
        alerts.forEach(function (alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // لابردنی console.log لە production بۆ پاککردنەوەی console
    // console.log('POS System Initialized');
}

/**
 * ڕێکخستنی Event Listeners
 */
function setupEventListeners() {
    // Search functionality
    const searchInputs = document.querySelectorAll('.search-input');
    searchInputs.forEach(function (input) {
        input.addEventListener('input', handleSearch);
        input.addEventListener('keydown', handleSearchKeydown);
    });

    // Barcode scanner
    const barcodeInput = document.getElementById('barcode-input');
    if (barcodeInput) {
        barcodeInput.addEventListener('keydown', handleBarcodeInput);
    }

    // File upload
    const fileUploadAreas = document.querySelectorAll('.file-upload-area');
    fileUploadAreas.forEach(function (area) {
        setupFileUpload(area);
    });

    // Cart functionality
    setupCartEvents();

    // Form validation
    setupFormValidation();

    // Print functionality
    const printBtns = document.querySelectorAll('.print-btn');
    printBtns.forEach(function (btn) {
        btn.addEventListener('click', handlePrint);
    });
}

/**
 * بارکردنی داتای بەکارهێنەر
 */
function loadUserData() {
    // Check if user is logged in
    fetch('../../api/user.php?action=current')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentUser = data.user;
                updateUserInterface();
            }
        })
        .catch(error => {

        });
}

/**
 * گەڕان لە کاڵاکان
 */
function handleSearch(event) {
    const query = event.target.value;
    const resultsContainer = event.target.parentNode.querySelector('.search-results');

    if (query.length < 2) {
        if (resultsContainer) {
            resultsContainer.style.display = 'none';
        }
        return;
    }

    // Debounce search
    clearTimeout(this.searchTimeout);
    this.searchTimeout = setTimeout(() => {
        searchProducts(query, resultsContainer);
    }, 300);
}

/**
 * گەڕان لە کاڵاکان لە API
 */
function searchProducts(query, container) {
    fetch(`../../api/products.php?action=search&q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySearchResults(data.products, container);
            }
        })
        .catch(error => {

        });
}

/**
 * پیشاندانی ئەنجامەکانی گەڕان
 */
function displaySearchResults(products, container) {
    if (!container) return;

    container.innerHTML = '';

    if (products.length === 0) {
        container.innerHTML = '<div class="search-result-item text-muted">هیچ کاڵایەک نەدۆزرایەوە</div>';
    } else {
        products.forEach(product => {
            const item = document.createElement('div');
            item.className = 'search-result-item';
            item.innerHTML = `
                <div class="d-flex justify-content-between">
                    <div>
                        <strong>${product.name}</strong>
                        <br>
                        <small class="text-muted">${product.barcode || 'بێ بارکۆد'}</small>
                    </div>
                    <div class="text-end">
                        <span class="text-success">${formatMoney(product.sell_price)}</span>
                        <br>
                        <small class="text-muted">بەردەست: ${product.stock_quantity}</small>
                    </div>
                </div>
            `;

            item.addEventListener('click', () => {
                selectProduct(product);
                container.style.display = 'none';
            });

            container.appendChild(item);
        });
    }

    container.style.display = 'block';
}

/**
 * بەکارهێنانی keyboard لە گەڕان
 */
function handleSearchKeydown(event) {
    const resultsContainer = event.target.parentNode.querySelector('.search-results');
    if (!resultsContainer) return;

    const items = resultsContainer.querySelectorAll('.search-result-item');
    let selected = resultsContainer.querySelector('.search-result-item.selected');

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            if (selected) {
                selected.classList.remove('selected');
                const next = selected.nextElementSibling || items[0];
                next.classList.add('selected');
            } else if (items.length > 0) {
                items[0].classList.add('selected');
            }
            break;

        case 'ArrowUp':
            event.preventDefault();
            if (selected) {
                selected.classList.remove('selected');
                const prev = selected.previousElementSibling || items[items.length - 1];
                prev.classList.add('selected');
            }
            break;

        case 'Enter':
            event.preventDefault();
            if (selected) {
                selected.click();
            }
            break;

        case 'Escape':
            resultsContainer.style.display = 'none';
            break;
    }
}

/**
 * بەکارهێنانی بارکۆد سکانەر
 */
function handleBarcodeInput(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        const barcode = event.target.value.trim();

        if (barcode) {
            searchProductByBarcode(barcode);
            event.target.value = '';
        }
    }
}

/**
 * گەڕان بە بارکۆد
 */
function searchProductByBarcode(barcode) {
    showLoading();

    fetch(`../../api/products.php?action=barcode&code=${encodeURIComponent(barcode)}`)
        .then(response => response.json())
        .then(data => {
            hideLoading();

            if (data.success && data.product) {
                addToCart(data.product);
                showNotification('کاڵا زیادکرا بۆ سەبەتە', 'success');
            } else {
                showNotification('کاڵا بەم بارکۆدە نەدۆزرایەوە', 'warning');
            }
        })
        .catch(error => {
            hideLoading();

            showNotification('هەڵەیەک ڕوویدا لە گەڕان', 'error');
        });
}

/**
 * ڕێکخستنی سەبەتە
 */
function setupCartEvents() {
    // Add to cart buttons
    document.addEventListener('click', function (event) {
        if (event.target.classList.contains('add-to-cart-btn')) {
            const productId = event.target.dataset.productId;
            const productData = JSON.parse(event.target.dataset.product);
            addToCart(productData);
        }

        // Remove from cart
        if (event.target.classList.contains('remove-cart-item')) {
            const index = parseInt(event.target.dataset.index);
            removeFromCart(index);
        }

        // Update cart quantity
        if (event.target.classList.contains('cart-qty-btn')) {
            const index = parseInt(event.target.dataset.index);
            const action = event.target.dataset.action;
            updateCartQuantity(index, action);
        }
    });
}

/**
 * زیادکردن بۆ سەبەتە
 */
function addToCart(product, quantity = 1) {
    const existingIndex = cart.findIndex(item => item.id === product.id);

    if (existingIndex >= 0) {
        cart[existingIndex].quantity += quantity;
        cart[existingIndex].total = cart[existingIndex].quantity * cart[existingIndex].price;
    } else {
        cart.push({
            id: product.id,
            name: product.name,
            price: parseFloat(product.sell_price),
            quantity: quantity,
            total: quantity * parseFloat(product.sell_price),
            barcode: product.barcode || '',
            stock: parseInt(product.stock_quantity)
        });
    }

    updateCartDisplay();
    updateCartSummary();
}

/**
 * سڕینەوە لە سەبەتە
 */
function removeFromCart(index) {
    if (index >= 0 && index < cart.length) {
        cart.splice(index, 1);
        updateCartDisplay();
        updateCartSummary();
    }
}

/**
 * نوێکردنەوەی بڕی سەبەتە
 */
function updateCartQuantity(index, action) {
    if (index >= 0 && index < cart.length) {
        const item = cart[index];

        if (action === 'increase') {
            if (item.quantity < item.stock) {
                item.quantity++;
            } else {
                showNotification('بڕی بەردەست تەواو بووە', 'warning');
                return;
            }
        } else if (action === 'decrease') {
            if (item.quantity > 1) {
                item.quantity--;
            } else {
                removeFromCart(index);
                return;
            }
        }

        item.total = item.quantity * item.price;
        updateCartDisplay();
        updateCartSummary();
    }
}

/**
 * نوێکردنەوەی پیشاندانی سەبەتە
 */
function updateCartDisplay() {
    const cartContainer = document.getElementById('cart-items');
    if (!cartContainer) return;

    if (cart.length === 0) {
        cartContainer.innerHTML = '<div class="text-center text-muted p-3">سەبەتە بەتاڵە</div>';
        return;
    }

    cartContainer.innerHTML = cart.map((item, index) => `
        <div class="cart-item">
            <div class="d-flex justify-content-between align-items-center">
                <div class="flex-grow-1">
                    <h6 class="mb-1">${item.name}</h6>
                    <small class="text-muted">${item.barcode}</small>
                </div>
                <div class="d-flex align-items-center">
                    <button class="btn btn-sm btn-outline-secondary cart-qty-btn" 
                            data-index="${index}" data-action="decrease">
                        <i class="bi bi-dash"></i>
                    </button>
                    <span class="mx-2">${item.quantity}</span>
                    <button class="btn btn-sm btn-outline-secondary cart-qty-btn" 
                            data-index="${index}" data-action="increase">
                        <i class="bi bi-plus"></i>
                    </button>
                </div>
                <div class="text-end ms-3">
                    <div class="fw-bold">${formatMoney(item.total)}</div>
                    <button class="btn btn-sm btn-outline-danger remove-cart-item" 
                            data-index="${index}">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

/**
 * نوێکردنەوەی کورتەی سەبەتە
 */
function updateCartSummary() {
    const totalAmount = cart.reduce((sum, item) => sum + item.total, 0);
    const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);

    // Update total amount display
    const totalElements = document.querySelectorAll('.cart-total');
    totalElements.forEach(el => {
        el.textContent = formatMoney(totalAmount);
    });

    // Update items count
    const countElements = document.querySelectorAll('.cart-count');
    countElements.forEach(el => {
        el.textContent = totalItems;
    });

    // Enable/disable checkout button
    const checkoutBtn = document.getElementById('checkout-btn');
    if (checkoutBtn) {
        checkoutBtn.disabled = cart.length === 0;
    }
}

/**
 * ڕێکخستنی فۆرمە validation
 */
function setupFormValidation() {
    const forms = document.querySelectorAll('.needs-validation');

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }

            form.classList.add('was-validated');
        }, false);
    });
}

/**
 * ڕێکخستنی فایل ئەپلۆد
 */
function setupFileUpload(area) {
    const input = area.querySelector('input[type="file"]');
    if (!input) return;

    // Click to select
    area.addEventListener('click', function () {
        input.click();
    });

    // Drag and drop
    area.addEventListener('dragover', function (event) {
        event.preventDefault();
        area.classList.add('dragover');
    });

    area.addEventListener('dragleave', function () {
        area.classList.remove('dragover');
    });

    area.addEventListener('drop', function (event) {
        event.preventDefault();
        area.classList.remove('dragover');

        const files = event.dataTransfer.files;
        if (files.length > 0) {
            input.files = files;
            handleFileSelection(input);
        }
    });

    // File selection
    input.addEventListener('change', function () {
        handleFileSelection(this);
    });
}

/**
 * بەکارهێنانی هەڵبژاردنی فایل
 */
function handleFileSelection(input) {
    const files = input.files;
    const previewContainer = input.parentNode.querySelector('.file-preview');

    if (previewContainer) {
        previewContainer.innerHTML = '';

        Array.from(files).forEach(function (file, index) {
            if (file.type.startsWith('image/')) {
                const preview = createImagePreview(file, index);
                previewContainer.appendChild(preview);
            }
        });
    }

    // Update file count display
    const countDisplay = input.parentNode.querySelector('.file-count');
    if (countDisplay) {
        countDisplay.textContent = `${files.length} فایل هەڵبژێردراوە`;
    }
}

/**
 * دروستکردنی پێشبینی وێنە
 */
function createImagePreview(file, index) {
    const preview = document.createElement('div');
    preview.className = 'image-preview';

    const img = document.createElement('img');
    img.className = 'img-thumbnail';

    const removeBtn = document.createElement('button');
    removeBtn.className = 'btn btn-sm btn-danger remove-btn';
    removeBtn.innerHTML = '×';
    removeBtn.onclick = function () {
        preview.remove();
    };

    preview.appendChild(img);
    preview.appendChild(removeBtn);

    // Read file as data URL
    const reader = new FileReader();
    reader.onload = function (e) {
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);

    return preview;
}

/**
 * بەکارهێنانی چاپکردن
 */
function handlePrint(event) {
    const target = event.target.dataset.printTarget;
    if (target) {
        const element = document.querySelector(target);
        if (element) {
            printElement(element);
        }
    } else {
        window.print();
    }
}

/**
 * چاپکردنی element ی تایبەت
 */
function printElement(element) {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>چاپکردن</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { font-size: 12px; }
                @media print {
                    .no-print { display: none !important; }
                }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            ${element.innerHTML}
        </body>
        </html>
    `);
    printWindow.document.close();
}

/**
 * Utility Functions
 */

// Format money
function formatMoney(amount) {
    return new Intl.NumberFormat('ar-IQ', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount) + ' دینار';
}

// Show loading
function showLoading() {
    const loader = document.querySelector('.loading-overlay');
    if (loader) {
        loader.style.display = 'flex';
    }
}

// Hide loading
function hideLoading() {
    const loader = document.querySelector('.loading-overlay');
    if (loader) {
        loader.style.display = 'none';
    }
}

// Show notification
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    notification.style.cssText = `
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
    `;

    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    document.body.appendChild(notification);

    // Auto remove after 5 seconds
    setTimeout(() => {
        const alert = new bootstrap.Alert(notification);
        alert.close();
    }, 5000);
}

// AJAX Helper
function makeRequest(url, options = {}) {
    const defaultOptions = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };

    return fetch(url, { ...defaultOptions, ...options })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        });
}

// Debounce function
function debounce(func, wait, immediate) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            timeout = null;
            if (!immediate) func(...args);
        };
        const callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
        if (callNow) func(...args);
    };
}

// Local storage helpers
function saveToStorage(key, data) {
    try {
        localStorage.setItem(key, JSON.stringify(data));
    } catch (error) {

    }
}

function loadFromStorage(key, defaultValue = null) {
    try {
        const data = localStorage.getItem(key);
        return data ? JSON.parse(data) : defaultValue;
    } catch (error) {
        return defaultValue;
    }
}

// Export functions for global access
window.POS = {
    addToCart,
    removeFromCart,
    updateCartQuantity,
    formatMoney,
    showNotification,
    makeRequest,
    showLoading,
    hideLoading
};