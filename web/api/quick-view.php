<?php
/**
 * Quick View API - web/api/quick-view.php
 * Returns product details in HTML format for quick view modal
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../auth/shop_google_access.php';

shop_google_ensure_db_schema($conn);

// Get parameters
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$slug = $_GET['slug'] ?? '';

if ($productId <= 0 || empty($slug)) {
    http_response_code(400);
    echo '<div class="alert alert-danger">پارامیتەرەکان نادروستن</div>';
    exit;
}

// Get website settings
$stmt = $conn->prepare("
    SELECT ws.*, u.business_name
    FROM website_settings ws
    INNER JOIN users u ON ws.user_id = u.id
    WHERE ws.website_slug = ? AND ws.is_active = 1
");
$stmt->bind_param("s", $slug);
$stmt->execute();
$result = $stmt->get_result();
$websiteSettings = $result->fetch_assoc();
$stmt->close();

if (!$websiteSettings) {
    http_response_code(404);
    echo '<div class="alert alert-danger">فرۆشگا نەدۆزرایەوە</div>';
    exit;
}

$userId = $websiteSettings['user_id'];

$apiErr = shop_google_access_api_error($conn, $userId);
if ($apiErr) {
    http_response_code($apiErr['http']);
    echo '<div class="alert alert-danger">' . htmlspecialchars($apiErr['message'], ENT_QUOTES, 'UTF-8') . '</div>';
    exit;
}

// Get product details
$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name,
           pd.description, pd.main_image, pd.discount_price,
           COALESCE(pu.stock_quantity, 0) as stock_quantity,
           COALESCE(pu.sell_price, 0) as retail_price,
           COALESCE(pu.wholesale_price, 0) as wholesale_price,
           COALESCE(pu.special_price, 0) as special_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_details pd ON p.id = pd.product_id
    LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = ?
    LEFT JOIN product_units pu ON p.id = pu.product_id 
        AND (pu.is_primary = 1 
             OR (pu.is_primary = 0 AND NOT EXISTS (
                 SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
             ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
    WHERE p.id = ? AND p.user_id = ? AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
");
$stmt->bind_param("iii", $userId, $productId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();
$stmt->close();

if (!$product) {
    http_response_code(404);
    echo '<div class="alert alert-danger">کاڵا نەدۆزرایەوە</div>';
    exit;
}

// Get all units
$unitsStmt = $conn->prepare("
    SELECT pu.*, u.name as unit_name, u.symbol as unit_symbol
    FROM product_units pu
    INNER JOIN units u ON pu.unit_id = u.id
    WHERE pu.product_id = ?
    ORDER BY pu.is_primary DESC, pu.id ASC
");
$unitsStmt->bind_param("i", $productId);
$unitsStmt->execute();
$unitsResult = $unitsStmt->get_result();
$units = $unitsResult->fetch_all(MYSQLI_ASSOC);
$unitsStmt->close();

// Helper functions
function formatPrice($price, $currency = 'IQD') {
    if ($price === null || $price === '') {
        return ($currency === 'USD') ? '0 دۆلار' : '0 دینار';
    }
    $curr = $currency === 'USD' ? 'USD' : 'IQD';
    $decimals = ($curr === 'USD') ? 2 : 0;
    $formatted = number_format((float)$price, $decimals, '.', ',');
    return $formatted . ($curr === 'USD' ? ' دۆلار' : ' دینار');
}

function getProductImage($product) {
    if (!empty($product['main_image'])) {
        $u = product_image_url($product['main_image']);
        if ($u) {
            return $u;
        }
    }
    if (!empty($product['image_path'])) {
        $u = product_image_url($product['image_path']);
        if ($u) {
            return $u;
        }
    }
    return SITE_URL . 'web/template/assets/images/no-image.svg';
}

$hasMultipleUnits = count($units) > 1;
$displayUnit = null;
foreach ($units as $unit) {
    if ($unit['is_primary']) {
        $displayUnit = $unit;
        break;
    }
}
if (!$displayUnit && count($units) > 0) {
    $displayUnit = $units[0];
}

$displayRetail = $displayUnit ? $displayUnit['sell_price'] : $product['retail_price'];
$displayWholesale = $displayUnit ? $displayUnit['wholesale_price'] : $product['wholesale_price'];
$displaySpecial = $displayUnit ? $displayUnit['special_price'] : $product['special_price'];
$displayStock = $displayUnit ? $displayUnit['stock_quantity'] : $product['stock_quantity'];
$unitIdForCart = $displayUnit ? $displayUnit['id'] : '';
$unitNameForCart = $displayUnit ? $displayUnit['unit_name'] : 'دانە';
?>

<div class="row g-4">
    <!-- Product Image -->
    <div class="col-md-5">
        <div class="quick-view-image">
            <img src="<?php echo getProductImage($product); ?>" 
                 class="img-fluid rounded" 
                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                 style="max-height: 400px; width: 100%; object-fit: contain;">
        </div>
    </div>
    
    <!-- Product Details -->
    <div class="col-md-7">
        <h3 class="mb-3"><?php echo htmlspecialchars($product['name']); ?></h3>
        
        <?php if (!empty($product['category_name']) && isset($websiteSettings['show_by_category']) && $websiteSettings['show_by_category'] == 1): ?>
        <div class="mb-3">
            <span class="badge bg-secondary">
                <i class="bi bi-tag"></i>
                <?php echo htmlspecialchars($product['category_name']); ?>
            </span>
        </div>
        <?php endif; ?>
        
        <?php if ($hasMultipleUnits): ?>
        <div class="mb-3">
            <label class="form-label">
                <i class="bi bi-box-seam"></i>
                یەکە:
            </label>
            <select class="form-select unit-selector-quickview" data-product-id="<?php echo $product['id']; ?>">
                <?php foreach ($units as $unit): ?>
                <option value="<?php echo $unit['id']; ?>" 
                        data-retail-price="<?php echo $unit['sell_price'] ?? '0'; ?>"
                        data-wholesale-price="<?php echo $unit['wholesale_price'] ?? '0'; ?>"
                        data-special-price="<?php echo $unit['special_price'] ?? '0'; ?>"
                        data-stock="<?php echo $unit['stock_quantity'] ?? '0'; ?>"
                        <?php echo $unit['is_primary'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($unit['unit_name']); ?>
                    <?php if (!empty($unit['unit_symbol'])): ?>
                        (<?php echo htmlspecialchars($unit['unit_symbol']); ?>)
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <!-- Prices -->
        <div class="prices-quickview mb-4" data-prices-container>
            <?php 
            $productCurrency = $product['currency'] ?? 'IQD';
            // Check if product has discount price
            $hasDiscount = !empty($product['discount_price']) && $product['discount_price'] > 0;
            ?>
            
            <?php if ($websiteSettings['show_retail_price'] && !empty($displayRetail)): ?>
            <div class="price-item mb-2" data-price-type="retail">
                <span class="price-label">نرخی تاک:</span>
                <?php if ($hasDiscount): ?>
                    <span class="price-value fw-bold text-decoration-line-through text-muted"><?php echo formatPrice($displayRetail, $productCurrency); ?></span>
                <?php else: ?>
                    <span class="price-value fw-bold text-success"><?php echo formatPrice($displayRetail, $productCurrency); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($hasDiscount): ?>
            <div class="price-item mb-2" data-price-type="discount">
                <span class="price-label">نرخی داشکاندن:</span>
                <span class="price-value fw-bold text-danger"><?php echo formatPrice($product['discount_price'], $productCurrency); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($websiteSettings['show_wholesale_price'] && !empty($displayWholesale)): ?>
            <div class="price-item mb-2" data-price-type="wholesale">
                <span class="price-label">نرخی جوملە:</span>
                <span class="price-value fw-bold"><?php echo formatPrice($displayWholesale, $productCurrency); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($websiteSettings['show_special_price'] && !empty($displaySpecial)): ?>
            <div class="price-item special mb-2" data-price-type="special">
                <span class="price-label">نرخی تایبەت:</span>
                <span class="price-value fw-bold text-warning"><?php echo formatPrice($displaySpecial, $productCurrency); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Stock -->
        <?php if ($websiteSettings['show_stock_quantity']): ?>
        <div class="mb-4" data-stock-container>
            <p class="mb-0">
                <i class="bi bi-box"></i>
                <strong>بەردەستی:</strong>
                <span class="stock-quantity text-primary"><?php echo isset($displayStock) ? (int)$displayStock : 0; ?></span>
                <span>دانە</span>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Description -->
        <?php if (!empty($product['description'])): ?>
        <div class="mb-4">
            <h6>وردەکاری:</h6>
            <div class="text-muted">
                <?php echo nl2br(htmlspecialchars(mb_substr(strip_tags($product['description']), 0, 300))); ?>
                <?php if (mb_strlen(strip_tags($product['description'])) > 300): ?>
                ...
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Actions -->
        <div class="d-flex gap-2">
            <?php if ($product['stock_quantity'] > 0): ?>
            <?php
            // Use discount price if available, otherwise use retail price
            $finalPrice = (!empty($product['discount_price']) && $product['discount_price'] > 0) 
                ? $product['discount_price'] 
                : ($displayRetail ?? $product['retail_price'] ?? 0);
            ?>
            <button class="btn btn-primary flex-fill add-to-cart-btn-quickview"
                    data-product-id="<?php echo $product['id']; ?>"
                    data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                    data-product-price="<?php echo $finalPrice; ?>"
                    data-product-image="<?php echo getProductImage($product); ?>"
                    data-product-unit-id="<?php echo $unitIdForCart; ?>"
                    data-product-unit="<?php echo $unitNameForCart; ?>"
                    data-product-currency="<?php echo htmlspecialchars($productCurrency); ?>"
                    data-shop-slug="<?php echo htmlspecialchars($slug); ?>"
                    data-stock="<?php echo isset($displayStock) ? (int)$displayStock : 0; ?>">
                <i class="bi bi-cart-plus"></i>
                زیادکردن بۆ سەبەتە
            </button>
            <?php else: ?>
            <button class="btn btn-secondary flex-fill" disabled>
                <i class="bi bi-x-circle"></i>
                تەواو بووە
            </button>
            <?php endif; ?>
            
            <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" 
               class="btn btn-outline-primary">
                <i class="bi bi-eye"></i>
                بینینی تەواو
            </a>
        </div>
    </div>
</div>

<script>
// Handle unit selector change in quick view
document.querySelectorAll('.unit-selector-quickview').forEach(selector => {
    selector.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const container = document.querySelector('.prices-quickview');
        
        if (!container) return;
        
        const retailPrice = parseFloat(selectedOption.getAttribute('data-retail-price')) || 0;
        const wholesalePrice = parseFloat(selectedOption.getAttribute('data-wholesale-price')) || 0;
        const specialPrice = parseFloat(selectedOption.getAttribute('data-special-price')) || 0;
        const stock = parseFloat(selectedOption.getAttribute('data-stock')) || 0;
        
        // Update prices
        const retailItem = container.querySelector('[data-price-type="retail"]');
        if (retailItem && retailPrice > 0) {
            retailItem.querySelector('.price-value').textContent = formatCurrency(retailPrice, quickViewCurrency);
        }
        
        const wholesaleItem = container.querySelector('[data-price-type="wholesale"]');
        if (wholesaleItem && wholesalePrice > 0) {
            wholesaleItem.querySelector('.price-value').textContent = formatCurrency(wholesalePrice, quickViewCurrency);
        }
        
        const specialItem = container.querySelector('[data-price-type="special"]');
        if (specialItem && specialPrice > 0) {
            specialItem.querySelector('.price-value').textContent = formatCurrency(specialPrice, quickViewCurrency);
        }
        
        // Update stock
        const stockElement = document.querySelector('.stock-quantity');
        if (stockElement) {
            stockElement.textContent = Math.floor(stock);
        }
        
        // Update add to cart button
        const addToCartBtn = document.querySelector('.add-to-cart-btn-quickview');
        if (addToCartBtn) {
            addToCartBtn.setAttribute('data-product-price', retailPrice);
            addToCartBtn.setAttribute('data-product-unit', selectedOption.textContent.trim());
            addToCartBtn.setAttribute('data-product-unit-id', selectedOption.value);
            addToCartBtn.setAttribute('data-stock', stock);
            // Keep shop slug if it exists
            if (!addToCartBtn.hasAttribute('data-shop-slug')) {
                const shopSlug = '<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>';
                if (shopSlug) {
                    addToCartBtn.setAttribute('data-shop-slug', shopSlug);
                }
            }
        }
    });
});

// Handle add to cart in quick view
document.querySelectorAll('.add-to-cart-btn-quickview').forEach(btn => {
    btn.addEventListener('click', function() {
        if (window.shoppingCart) {
            const productId = this.dataset.productId;
            const productName = this.dataset.productName;
            const productPrice = parseFloat(this.dataset.productPrice) || 0;
            const productImage = this.dataset.productImage;
            const productUnit = this.dataset.productUnit || 'دانە';
            const productUnitId = this.dataset.productUnitId || '';
            const websiteSlug = this.dataset.shopSlug || '';
            const productCurrency = this.dataset.productCurrency || 'IQD';
            
            window.shoppingCart.addToCart(productId, productName, productPrice, productImage, productUnit, productUnitId, websiteSlug, null, 0, productCurrency);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('quickViewModal'));
            if (modal) {
                modal.hide();
            }
        }
    });
});

const quickViewCurrency = '<?php echo addslashes($productCurrency ?? 'IQD'); ?>';
function formatCurrency(amount, currency) {
    currency = currency || quickViewCurrency;
    const isUsd = currency === 'USD';
    const decimals = isUsd ? 2 : 0;
    const num = parseFloat(amount) || 0;
    const formatted = isUsd ? num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : Math.floor(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return formatted + (isUsd ? ' دۆلار' : ' دینار');
}
</script>

