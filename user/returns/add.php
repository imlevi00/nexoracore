<?php
/**
 * Add Return - user/returns/add.php
 * Interface for adding new product returns
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/wallet_service.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی کڕیارەکان
$customersQuery = "SELECT id, name FROM customers WHERE user_id = $userId ORDER BY name";
$customers = $conn->query($customersQuery)->fetch_all(MYSQLI_ASSOC);
$wallets = walletGetUserWallets($conn, (int)$userId, true);

$pageTitle = 'گەڕاندنەوەی نوێ';
$bodyClass = 'returns-module-page returns-add-page bg-light';
$additionalCSS = ['returns-responsive.css'];

include '../../includes/header.php';
?>

<div class="container-fluid py-4 returns-page-content">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4 returns-page-header">
                <div>
                    <h2 class="h3 mb-0">گەڕاندنەوەی نوێ</h2>
                    <p class="text-muted mb-0">زیادکردنی گەڕاندنەوەی کاڵا</p>
                </div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right me-2"></i>گەڕانەوە
                </a>
            </div>

            <div class="row">
                <!-- بەشی کاڵاکان -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-box-seam me-2"></i>کاڵاکان
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- گەڕان -->
                            <div class="mb-3">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="productSearch" 
                                           placeholder="گەڕانی کاڵا بە ناو، بارکۆد یان کۆد">
                                    <button class="btn btn-outline-primary" type="button" id="searchBtn">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- نەخشەی کاڵاکان -->
                            <div id="productsGrid" class="row g-3">
                                <!-- کاڵاکان لێرە دەردەکەون -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- بەشی سەبەتە -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-cart me-2"></i>سەبەتەی گەڕاندنەوە
                                <span class="badge bg-primary ms-2" id="cartCount">0</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm returns-cart-table">
                                    <thead>
                                        <tr>
                                            <th>کاڵا</th>
                                            <th>بڕ</th>
                                            <th>بڕی</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="cartTableBody">
                                        <tr>
                                            <td colspan="4" class="text-center py-3 text-muted">
                                                سەبەتە بەتاڵە
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- کۆی گشتی -->
                            <div class="border-top pt-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>کۆی گشتی:</span>
                                    <span id="totalAmount">0 دینار</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>داشکاندن:</span>
                                    <span id="discountAmount">0 دینار</span>
                                </div>
                                <div class="d-flex justify-content-between fw-bold">
                                    <span>کۆی کۆتایی:</span>
                                    <span id="finalAmount">0 دینار</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- فۆڕمی گەڕاندنەوە -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="card-title mb-0">زانیاری گەڕاندنەوە</h5>
                        </div>
                        <div class="card-body">
                            <form id="returnForm">
                                <div class="mb-3">
                                    <label for="customerSelect" class="form-label">کڕیار</label>
                                    <select class="form-select" id="customerSelect">
                                        <option value="">کڕیاری گشتی</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo $customer['id']; ?>">
                                                <?php echo htmlspecialchars($customer['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="returnReason" class="form-label">هۆکاری گەڕاندنەوە</label>
                                    <textarea class="form-control" id="returnReason" rows="3" 
                                              placeholder="هۆکاری گەڕاندنەوەی کاڵا"></textarea>
                                </div>

                                <div class="mb-3">
                                    <label for="discountInput" class="form-label">داشکاندن (دینار)</label>
                                    <input type="number" class="form-control" id="discountInput" 
                                           value="0" min="0" step="1">
                                </div>

                                <div class="mb-3">
                                    <label for="paymentMethod" class="form-label">ڕێگەی پارەدان</label>
                                    <select class="form-select" id="paymentMethod">
                                        <option value="cash">نەقد</option>
                                        <option value="debt">قەرز</option>
                                    </select>
                                </div>

                                <div class="mb-3" id="walletField">
                                    <label for="walletId" class="form-label">قاسە</label>
                                    <select class="form-select" id="walletId">
                                        <option value="">قاسە هەڵبژێرە</option>
                                        <?php foreach ($wallets as $wallet): ?>
                                            <option value="<?php echo (int)$wallet['id']; ?>">
                                                <?php echo htmlspecialchars((string)$wallet['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-warning w-100" id="processReturnBtn" disabled>
                                    <i class="bi bi-arrow-return-left me-2"></i>گەڕاندنەوە
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Return Management JavaScript
const ReturnManager = {
    cart: [],
    csrfToken: '<?php echo $_SESSION['csrf_token']; ?>',
    
    init() {
        this.bindEvents();
        this.updateCartDisplay();
    },
    
    bindEvents() {
        // گەڕانی کاڵا
        document.getElementById('searchBtn').addEventListener('click', () => this.searchProducts());
        document.getElementById('productSearch').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.searchProducts();
        });
        
        // فۆڕمی گەڕاندنەوە
        document.getElementById('returnForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.processReturn();
        });
        
        // داشکاندن
        document.getElementById('discountInput').addEventListener('input', () => {
            this.updateTotalDisplay();
        });
        document.getElementById('paymentMethod').addEventListener('change', () => {
            this.toggleWalletField();
        });
    },
    
    toggleWalletField() {
        const paymentMethod = document.getElementById('paymentMethod').value;
        const walletField = document.getElementById('walletField');
        const walletSelect = document.getElementById('walletId');
        if (paymentMethod === 'cash') {
            walletField.style.display = '';
            walletSelect.required = true;
        } else {
            walletField.style.display = 'none';
            walletSelect.required = false;
            walletSelect.value = '';
        }
    },
    
    async searchProducts() {
        const query = document.getElementById('productSearch').value.trim();
        if (!query) return;
        
        try {
            const response = await fetch(`../../user/api/search_products.php?q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (data.success) {
                this.displayProducts(data.data.products || []);
            } else {
                this.showNotification('هەڵەیەک ڕووی دا لە گەڕان', 'danger');
            }
        } catch (error) {
        
        }
    },
    
    displayProducts(products) {
        const grid = document.getElementById('productsGrid');
        
        if (products.length === 0) {
            grid.innerHTML = '<div class="col-12 text-center py-4 text-muted">هیچ کاڵایەک نەدۆزرایەوە</div>';
            return;
        }
        
        grid.innerHTML = products.map(product => `
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">${product.name}</h6>
                        <p class="card-text text-muted small">بارکۆد: ${product.barcode || 'بێ بارکۆد'}</p>
                        <p class="card-text">
                            <strong>نرخ: ${this.formatMoney(product.sell_price)}</strong>
                        </p>
                        <p class="card-text small">
                            کۆگا: <span class="badge bg-info">${product.stock_quantity}</span>
                        </p>
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control" id="qty_${product.id}" 
                                   value="1" min="1" max="${product.stock_quantity}">
                            <button class="btn btn-outline-primary" type="button" 
                                    onclick="ReturnManager.addToCart(${product.id}, '${product.name}', ${product.sell_price}, '${product.barcode || ''}')">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
    },
    
    addToCart(productId, productName, price, barcode) {
        const quantityInput = document.getElementById(`qty_${productId}`);
        const quantity = parseInt(quantityInput.value) || 1;
        
        // چەککردنی کاڵای هەبوو
        const existingIndex = this.cart.findIndex(item => item.id === productId);
        
        if (existingIndex >= 0) {
            this.cart[existingIndex].quantity += quantity;
            this.cart[existingIndex].total = this.cart[existingIndex].quantity * this.cart[existingIndex].price;
        } else {
            this.cart.push({
                id: productId,
                name: productName,
                barcode: barcode,
                price: price,
                quantity: quantity,
                total: price * quantity
            });
        }
        
        this.updateCartDisplay();
        this.showNotification('کاڵا زیادکرا بۆ سەبەتە', 'success');
    },
    
    removeFromCart(index) {
        this.cart.splice(index, 1);
        this.updateCartDisplay();
        this.showNotification('کاڵا لە سەبەتەکە سڕایەوە', 'info');
    },
    
    updateQuantity(index, change) {
        const newQuantity = this.cart[index].quantity + change;
        
        if (newQuantity <= 0) {
            this.removeFromCart(index);
            return;
        }
        
        this.cart[index].quantity = newQuantity;
        this.cart[index].total = this.cart[index].quantity * this.cart[index].price;
        this.updateCartDisplay();
    },
    
    updateCartDisplay() {
        const cartTableBody = document.getElementById('cartTableBody');
        const cartCount = document.getElementById('cartCount');
        const processBtn = document.getElementById('processReturnBtn');
        
        cartCount.textContent = this.cart.length;
        
        if (this.cart.length === 0) {
            cartTableBody.innerHTML = `
                <tr>
                    <td colspan="4" class="text-center py-3 text-muted">
                        سەبەتە بەتاڵە
                    </td>
                </tr>
            `;
            processBtn.disabled = true;
        } else {
            cartTableBody.innerHTML = this.cart.map((item, index) => `
                <tr>
                    <td data-label="کاڵا">
                        <div>
                            <div class="fw-bold">${item.name}</div>
                            <small class="text-muted">${this.formatMoney(item.price)}</small>
                        </div>
                    </td>
                    <td data-label="بڕ">
                        <div class="input-group input-group-sm">
                            <button class="btn btn-outline-secondary btn-sm" 
                                    onclick="ReturnManager.updateQuantity(${index}, -1)">-</button>
                            <input type="number" class="form-control text-center" 
                                   value="${item.quantity}" style="width: 60px;" readonly>
                            <button class="btn btn-outline-secondary btn-sm" 
                                    onclick="ReturnManager.updateQuantity(${index}, 1)">+</button>
                        </div>
                    </td>
                    <td data-label="بڕی">${this.formatMoney(item.total)}</td>
                    <td data-label="">
                        <button class="btn btn-outline-danger btn-sm" 
                                onclick="ReturnManager.removeFromCart(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
            processBtn.disabled = false;
        }
        
        this.updateTotalDisplay();
    },
    
    updateTotalDisplay() {
        const totalAmount = this.cart.reduce((sum, item) => sum + item.total, 0);
        const discount = parseFloat(document.getElementById('discountInput').value) || 0;
        const finalAmount = Math.max(0, totalAmount - discount);
        
        document.getElementById('totalAmount').textContent = this.formatMoney(totalAmount);
        document.getElementById('discountAmount').textContent = this.formatMoney(discount);
        document.getElementById('finalAmount').textContent = this.formatMoney(finalAmount);
    },
    
    async processReturn() {
        if (this.cart.length === 0) {
            this.showNotification('سەبەتە بەتاڵە', 'warning');
            return;
        }
        
        const customerId = document.getElementById('customerSelect').value;
        const customerName = document.getElementById('customerSelect').selectedOptions[0]?.text || '';
        const returnReason = document.getElementById('returnReason').value.trim();
        const discount = parseFloat(document.getElementById('discountInput').value) || 0;
        const paymentMethod = document.getElementById('paymentMethod').value;
        const walletId = document.getElementById('walletId').value;

        if (paymentMethod === 'cash' && !walletId) {
            this.showNotification('تکایە قاسە هەڵبژێرە', 'warning');
            return;
        }
        
        const totalAmount = this.cart.reduce((sum, item) => sum + item.total, 0);
        const finalAmount = Math.max(0, totalAmount - discount);
        
        const returnData = {
            customer_id: customerId ? parseInt(customerId) : null,
            customer_name: customerName,
            return_reason: returnReason,
            total_amount: totalAmount,
            discount: discount,
            final_amount: finalAmount,
            payment_method: paymentMethod,
            wallet_id: walletId ? parseInt(walletId) : null,
            items: this.cart.map(item => ({
                product_id: item.id,
                product_name: item.name,
                quantity: item.quantity,
                unit_price: item.price,
                total_price: item.total,
                price_type: 'retail',
                unit_id: null,
                unit_name: 'دانە',
                unit_symbol: ''
            })),
            csrf_token: this.csrfToken
        };
        
        try {
            const response = await fetch('../../api/returns.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(returnData)
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('گەڕاندنەوە بە سەرکەوتووییی تەواو بوو', 'success');
                this.cart = [];
                this.updateCartDisplay();
                document.getElementById('returnForm').reset();
                
                // نیشاندانی پسوولە
                if (data.data && data.data.return) {
                    this.showReturnReceipt(data.data.return);
                }
            } else {
                this.showNotification(data.message || 'هەڵەیەک ڕووی دا', 'danger');
            }
        } catch (error) {
       
        }
    },
    
    showReturnReceipt(returnData) {
        // نیشاندانی پسوولەی گەڕاندنەوە
        const receiptContent = `
            <div class="text-center">
                <h4>پسوولەی گەڕاندنەوە</h4>
                <hr>
                <p><strong>ژمارەی گەڕاندنەوە:</strong> ${returnData.return_number}</p>
                <p><strong>بەروار:</strong> ${new Date(returnData.return_date).toLocaleDateString('ku-IQ')}</p>
                <p><strong>کڕیار:</strong> ${returnData.customer_name || 'کڕیاری گشتی'}</p>
                <hr>
                <p><strong>کۆی کۆتایی:</strong> ${this.formatMoney(returnData.final_amount)}</p>
                <hr>
                <p class="text-success">سپاس بۆ گەڕاندنەوە</p>
            </div>
        `;
        
        // دروستکردنی modal بۆ نیشاندانی پسوولە
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">پسوولەی گەڕاندنەوە</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        ${receiptContent}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                        <button type="button" class="btn btn-primary" onclick="window.print()">چاپکردن</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        
        modal.addEventListener('hidden.bs.modal', () => {
            document.body.removeChild(modal);
        });
    },
    
    formatMoney(amount) {
        return new Intl.NumberFormat('en-US').format(amount) + ' دینار';
    },
    
    showNotification(message, type) {
        // نیشاندانی notification
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alert);
        
        setTimeout(() => {
            if (alert.parentNode) {
                alert.parentNode.removeChild(alert);
            }
        }, 5000);
    }
};

// دەستپێکردنی بەڕێوەبردنی گەڕاندنەوە
document.addEventListener('DOMContentLoaded', () => {
    ReturnManager.init();
    ReturnManager.toggleWalletField();
});
</script>

<?php include '../../includes/footer.php'; ?>
