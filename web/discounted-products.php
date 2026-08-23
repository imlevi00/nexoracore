<?php
/**
 * کاتالۆگی کاڵا داشکاندراوەکان - web/discounted-products.php
 * پیشاندانی هەموو کاڵایانەی کە داشکاندنیان بۆ کراوە
 */

require_once '../config/config.php';
require_once 'auth/session_helper.php';

// وەرگرتنی هەموو کاڵا داشکاندراوەکان لە فرۆشگا جیاوازەکان
$discountedProductsQuery = "
    SELECT p.*, u.business_name as shop_name, ws.website_slug, ws.show_stock_quantity, COALESCE(ws.show_by_category, 0) as show_by_category,
           c.name as category_name,
           COALESCE(pu.sell_price, 0) as retail_price,
           COALESCE(pu.stock_quantity, 0) as stock_quantity,
           COALESCE(pu.id, '') as unit_id,
           COALESCE(u2.name, 'دانە') as unit_name,
           pd.discount_price,
           pd.description,
           pd.main_image,
           ((COALESCE(pu.sell_price, 0) - pd.discount_price) / COALESCE(pu.sell_price, 0) * 100) as discount_percentage
    FROM products p
    INNER JOIN users u ON p.user_id = u.id
    INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1
    INNER JOIN product_details pd ON p.id = pd.product_id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_units pu ON p.id = pu.product_id 
        AND (pu.is_primary = 1 
             OR (pu.is_primary = 0 AND NOT EXISTS (
                 SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
             ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
    LEFT JOIN units u2 ON pu.unit_id = u2.id
    LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = p.user_id
    WHERE pd.discount_price IS NOT NULL 
          AND pd.discount_price > 0
         AND pd.discount_price < COALESCE(pu.sell_price, 0)
          AND (p.image_path IS NOT NULL AND p.image_path != '')
          AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
          AND COALESCE(c.is_visible_on_website, 1) = 1
         AND pu.stock_quantity > 0
    ORDER BY discount_percentage DESC, p.created_at DESC
";

$discountedProductsResult = $conn->query($discountedProductsQuery);
$discountedProducts = $discountedProductsResult ? $discountedProductsResult->fetch_all(MYSQLI_ASSOC) : [];

// وەرگرتنی کۆی گشتی کاڵا داشکاندراوەکان
$totalDiscountedCount = count($discountedProducts);

// Helper function to get product image
function getProductImageDiscount($product) {
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

// Helper function to format price by currency (IQD = دینار, USD = دۆلار)
function formatPriceDiscount($price, $currency = 'IQD') {
    if ($price === null || $price === '') {
        return ($currency === 'USD') ? '0 دۆلار' : '0 دینار';
    }
    $curr = $currency === 'USD' ? 'USD' : 'IQD';
    $decimals = ($curr === 'USD') ? 2 : 0;
    $formatted = number_format((float)$price, $decimals, '.', ',');
    return $formatted . ($curr === 'USD' ? ' دۆلار' : ' دینار');
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="کاتالۆگی کاڵا داشکاندراوەکان - داشکاندنی تایبەت لەسەر کاڵاکان">
    <meta name="theme-color" content="#dc2626">
    <title>کاڵا داشکاندراوەکان - <?php echo SITE_NAME; ?></title>
    
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="template/assets/css/shop.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/cart.css?v=<?php echo time(); ?>" rel="stylesheet">
    
    <style>
        /* Special Discount Page Styles */
        .discount-header {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 30px 30px;
            box-shadow: 0 10px 40px rgba(220, 38, 38, 0.3);
        }
        
        .discount-header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .discount-header .subtitle {
            font-size: 1.1rem;
            opacity: 0.95;
        }
        
        .discount-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1rem;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.4);
            z-index: 10;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        .discount-badge i {
            animation: rotate 2s linear infinite;
        }
        
        @keyframes rotate {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
        
        .price-comparison {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1rem 0;
        }
        
        .old-price {
            font-size: 1rem;
            color: #6b7280;
            text-decoration: line-through;
            font-weight: 500;
        }
        
        .new-price {
            font-size: 1.4rem;
            color: #dc2626;
            font-weight: 800;
        }
        
        .savings-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        
        .shop-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #f3f4f6;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: #374151;
            margin-top: 0.5rem;
        }
        
        .shop-tag i {
            color: #0d6efd;
        }
        
        .stats-container {
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .stats-container .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: #dc2626;
            display: block;
        }
        
        .stats-container .stat-label {
            font-size: 1.1rem;
            color: #6b7280;
            margin-top: 0.5rem;
        }
        
        .back-button {
            position: fixed;
            bottom: 30px;
            left: 30px;
            z-index: 1000;
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            border: none;
            padding: 15px 25px;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 8px 25px rgba(13, 110, 253, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .back-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(13, 110, 253, 0.5);
            color: white;
        }
        
        .cart-button {
            position: fixed;
            top: 30px;
            right: 30px;
            z-index: 1000;
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: white;
            border: none;
            padding: 15px 25px;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        
        .cart-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(220, 38, 38, 0.5);
            color: white;
        }
        
        .cart-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #10b981;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }
        
        .empty-state {
            text-align: center;
            padding: 5rem 2rem;
        }
        
        .empty-state i {
            font-size: 5rem;
            color: #d1d5db;
            margin-bottom: 1.5rem;
        }
        
        .empty-state h3 {
            color: #374151;
            margin-bottom: 1rem;
        }
        
        .empty-state p {
            color: #6b7280;
            font-size: 1.1rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .discount-header h1 {
                font-size: 1.8rem;
            }
            
            .discount-header .subtitle {
                font-size: 0.95rem;
            }
            
            .back-button {
                bottom: 20px;
                left: 20px;
                padding: 12px 20px;
                font-size: 0.9rem;
            }
            
            .cart-button {
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                font-size: 0.9rem;
            }
            
            .stats-container .stat-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body class="bg-light">

    <!-- Discount Header -->
    <div class="discount-header">
        <div class="container text-center">
            <h1>
                <i class="bi bi-tags-fill"></i>
                کاڵا داشکاندراوەکان
            </h1>
            <p class="subtitle">
                <i class="bi bi-fire"></i>
                باشترین داشکاندنەکان لە فرۆشگا جیاوازەکان
            </p>
        </div>
    </div>

    <div class="container py-4">
        
        <!-- Stats -->
        <?php if ($totalDiscountedCount > 0): ?>
        <div class="stats-container">
            <span class="stat-number">
                <i class="bi bi-percent"></i>
                <?php echo $totalDiscountedCount; ?>
            </span>
            <span class="stat-label">کاڵای داشکاندراو بەردەستە</span>
        </div>
        <?php endif; ?>

        <!-- Products Grid -->
        <div class="row products-container grid-view" id="productsContainer">
            <?php if (empty($discountedProducts)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <h3>هیچ کاڵای داشکاندراوێک نییە</h3>
                        <p>لە ئێستادا هیچ داشکاندنێک لەسەر کاڵاکان نییە</p>
                        <a href="<?php echo SITE_URL; ?>web/" class="btn btn-primary btn-lg mt-3">
                            <i class="bi bi-arrow-right"></i>
                            گەڕانەوە بۆ فرۆشگاکان
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($discountedProducts as $product): ?>
                <div class="col-lg-4 col-md-6 mb-4 product-item">
                    <div class="card product-card h-100">
                        <!-- Discount Badge -->
                        <div class="discount-badge">
                            <i class="bi bi-fire"></i>
                            -<?php echo round($product['discount_percentage']); ?>%
                        </div>
                        
                        <!-- Product Image -->
                        <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $product['website_slug']; ?>&id=<?php echo $product['id']; ?>" class="text-decoration-none product-image-link">
                            <div class="position-relative product-image-wrapper">
                                <img src="<?php echo getProductImageDiscount($product); ?>" 
                                     class="card-img-top" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                     loading="lazy"
                                     decoding="async"
                                     onerror="if(!this.hasAttribute('data-error-handled')) { this.setAttribute('data-error-handled', 'true'); this.src='<?php echo SITE_URL; ?>web/template/assets/images/no-image.svg'; }">
                                <div class="product-overlay">
                                    <button class="btn btn-sm btn-light quick-view-btn" 
                                            data-product-id="<?php echo $product['id']; ?>" 
                                            data-slug="<?php echo $product['website_slug']; ?>">
                                        <i class="bi bi-eye"></i> بینینی خێرا
                                    </button>
                                </div>
                            </div>
                        </a>
                        
                        <div class="card-body d-flex flex-column">
                            <!-- Product Name -->
                            <h5 class="card-title">
                                <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $product['website_slug']; ?>&id=<?php echo $product['id']; ?>" 
                                   class="text-decoration-none text-dark">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </a>
                            </h5>
                            
                            <!-- Shop Tag -->
                            <div class="shop-tag">
                                <i class="bi bi-shop"></i>
                                <a href="<?php echo SITE_URL; ?>web/<?php echo $product['website_slug']; ?>/" 
                                   class="text-decoration-none text-dark">
                                    <?php echo htmlspecialchars($product['shop_name']); ?>
                                </a>
                            </div>
                            
                            <?php if (!empty($product['category_name']) && isset($product['show_by_category']) && $product['show_by_category'] == 1): ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-tag"></i>
                                    <?php echo htmlspecialchars($product['category_name']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Price Comparison -->
                            <div class="price-comparison mt-3">
                                <?php $productCurrency = $product['currency'] ?? 'IQD'; ?>
                                <span class="old-price"><?php echo formatPriceDiscount($product['retail_price'], $productCurrency); ?></span>
                                <span class="new-price"><?php echo formatPriceDiscount($product['discount_price'], $productCurrency); ?></span>
                                <span class="savings-badge">
                                    پاشەکەوت: <?php echo formatPriceDiscount($product['retail_price'] - $product['discount_price'], $productCurrency); ?>
                                </span>
                            </div>
                            
                            <?php if ($product['show_stock_quantity']): ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-box"></i>
                                    بەردەستی: <span class="fw-bold"><?php echo (int)$product['stock_quantity']; ?></span> <?php echo htmlspecialchars($product['unit_name']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Actions -->
                            <div class="mt-auto pt-3">
                                <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $product['website_slug']; ?>&id=<?php echo $product['id']; ?>" 
                                   class="btn btn-outline-primary w-100 mb-2">
                                    <i class="bi bi-eye"></i>
                                    بینینی وردەکاری
                                </a>
                                
                                <button class="btn btn-danger w-100 add-to-cart-btn"
                                        data-product-id="<?php echo $product['id']; ?>"
                                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                        data-product-price="<?php echo $product['discount_price']; ?>"
                                        data-product-image="<?php echo getProductImageDiscount($product); ?>"
                                        data-product-unit-id="<?php echo $product['unit_id']; ?>"
                                        data-product-unit="<?php echo htmlspecialchars($product['unit_name']); ?>"
                                        data-product-currency="<?php echo htmlspecialchars($productCurrency); ?>"
                                        data-shop-slug="<?php echo htmlspecialchars($product['website_slug']); ?>"
                                        data-stock="<?php echo (int)$product['stock_quantity']; ?>">
                                    <i class="bi bi-cart-plus"></i>
                                    زیادکردن بۆ سەبەتە
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cart Sidebar -->
    <div class="cart-sidebar-overlay"></div>
    <div class="cart-sidebar">
        <div class="cart-header">
            <h3 class="cart-title">
                <i class="bi bi-cart3"></i>
                سەبەتەی کڕین
            </h3>
            <button class="cart-close">
                <i class="bi bi-x"></i>
            </button>
        </div>
        
        <div class="cart-body">
            <div class="cart-items">
                <!-- Cart items will be populated by JavaScript -->
            </div>
            
            <div class="cart-summary" style="display: none;">
                <div class="cart-total">
                    <span>کۆی گشتی:</span>
                    <span class="cart-total-amount">0 دینار</span>
                </div>
            </div>
            
            <div class="cart-actions">
                <button class="btn-checkout" id="checkoutBtn">
                    <i class="bi bi-credit-card"></i>
                    تەواوکردنی داواکاری
                </button>
                <a href="#" class="btn-continue-shopping" onclick="window.shoppingCart.closeCart(); return false;">
                    <i class="bi bi-arrow-left"></i>
                    بەردەوامبوون لە کڕین
                </a>
            </div>
        </div>
    </div>

    <!-- Cart Button -->
    <button class="cart-button" title="سەبەتەی کڕین">
        <i class="bi bi-cart3"></i>
        <span class="cart-badge" style="display: none;">0</span>
    </button>

    <!-- Back Button -->
    <a href="<?php echo SITE_URL; ?>web/" class="back-button">
        <i class="bi bi-arrow-right"></i>
        گەڕانەوە بۆ سەرەکی
    </a>

    <!-- Quick View Modal -->
    <div class="modal fade" id="quickViewModal" tabindex="-1" aria-labelledby="quickViewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickViewModalLabel">بینینی خێرا</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="quickViewContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">چاوەڕوانی...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/shop.js?v=<?php echo time(); ?>"></script>
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/cart.js?v=<?php echo time(); ?>"></script>
    
    <script>
        // Handle checkout button for multiple shops
        document.addEventListener('DOMContentLoaded', function() {
            const checkoutBtn = document.getElementById('checkoutBtn');
            
            if (checkoutBtn) {
                checkoutBtn.addEventListener('click', function() {
                    // Get cart items
                    const cart = window.shoppingCart.getCart();
                    
                    if (cart.length === 0) {
                        alert('سەبەتەکەت بەتاڵە!');
                        return;
                    }
                    
                    // Check if all items are from the same shop
                    const shops = [...new Set(cart.map(item => item.shopSlug))];
                    
                    if (shops.length === 1) {
                        // All items from same shop, redirect to checkout
                        window.location.href = '<?php echo SITE_URL; ?>web/checkout.php?shop=' + shops[0];
                    } else {
                        // Items from multiple shops
                        alert('تکایە تەنها کاڵاکانی یەک فرۆشگا هەڵبژێرە بۆ تەواوکردنی داواکاری');
                    }
                });
            }
        });
    </script>

</body>
</html>
