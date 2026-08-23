<?php
/**
 * کاڵا داشکاندراوەکانی فرۆشگا - web/shop-discounts.php
 * پیشاندانی کاڵا داشکاندراوەکانی فرۆشگایەکی دیاریکراو
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once 'auth/session_helper.php';
require_once 'auth/shop_google_access.php';
require_once 'includes/shop_whatsapp.php';

shop_google_ensure_db_schema($conn);
shop_whatsapp_ensure_column($conn);

// Get slug from URL parameter
$slug = $_GET['slug'] ?? '';

// Validate slug format
if (empty($slug) || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    http_response_code(404);
    include '404.php';
    exit;
}

// Get website settings and user info
$stmt = $conn->prepare("
    SELECT ws.*, u.business_name, u.email, u.phone, u.address
    FROM website_settings ws
    INNER JOIN users u ON ws.user_id = u.id
    WHERE ws.website_slug = ? AND ws.is_active = 1
");
$stmt->bind_param("s", $slug);
$stmt->execute();
$result = $stmt->get_result();
$websiteSettings = $result->fetch_assoc();
$stmt->close();

// Check if shop exists and is active
if (!$websiteSettings) {
    http_response_code(404);
    include '404.php';
    exit;
}

$userId = $websiteSettings['user_id'];
$businessName = $websiteSettings['business_name'];
$shopWhatsAppCartConfig = shop_whatsapp_cart_config($websiteSettings, $slug);

shop_google_access_guard($conn, $slug, $websiteSettings);

// وەرگرتنی کاڵا داشکاندراوەکانی ئەم فرۆشگایە
$discountedProductsQuery = "
    SELECT p.*, c.name as category_name,
           COALESCE(pu.sell_price, 0) as retail_price,
           COALESCE(pu.stock_quantity, 0) as stock_quantity,
           COALESCE(pu.id, '') as unit_id,
           COALESCE(u2.name, 'دانە') as unit_name,
           pd.discount_price,
           pd.description,
           pd.main_image,
           ((COALESCE(pu.sell_price, 0) - pd.discount_price) / COALESCE(pu.sell_price, 0) * 100) as discount_percentage
    FROM products p
    INNER JOIN product_details pd ON p.id = pd.product_id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_units pu ON p.id = pu.product_id 
        AND (pu.is_primary = 1 
             OR (pu.is_primary = 0 AND NOT EXISTS (
                 SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
             ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
    LEFT JOIN units u2 ON pu.unit_id = u2.id
    LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = p.user_id
    WHERE p.user_id = ?
          AND pd.discount_price IS NOT NULL 
          AND pd.discount_price > 0
         AND pd.discount_price < COALESCE(pu.sell_price, 0)
          AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
          AND COALESCE(c.is_visible_on_website, 1) = 1
         AND pu.stock_quantity > 0
    ORDER BY discount_percentage DESC, p.created_at DESC
";

$stmt = $conn->prepare($discountedProductsQuery);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$discountedProducts = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalDiscountedCount = count($discountedProducts);

// Helper function to get product image
function getProductImageShopDiscount($product) {
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
function formatPriceShopDiscount($price, $currency = 'IQD') {
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
    <title>کاڵا داشکاندراوەکان - <?php echo htmlspecialchars($businessName); ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/shop.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/cart.css?v=<?php echo time(); ?>" rel="stylesheet">
    
    <style>
        .discount-header {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 8px 30px rgba(220, 38, 38, 0.3);
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
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
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
        
        .empty-state {
            text-align: center;
            padding: 5rem 2rem;
        }
        
        .empty-state i {
            font-size: 5rem;
            color: #d1d5db;
            margin-bottom: 1.5rem;
        }
        
        /* Cart Button in Header */
        .cart-header-button {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: white;
            border: none;
            padding: 12px 20px;
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
        
        .cart-header-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(220, 38, 38, 0.5);
            color: white;
        }
        
        .cart-badge-header {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 12px;
            min-width: 24px;
            height: 24px;
            padding: 0 6px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            border: 2px solid #dc2626;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        
        @media (max-width: 768px) {
            .cart-header-button {
                top: 15px;
                right: 15px;
                padding: 10px 16px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body class="bg-light">
    
    <!-- Cart Button in Header -->
    <button class="cart-header-button cart-button" title="سەبەتەی کڕین">
        <i class="bi bi-cart3"></i>
        <span class="cart-badge cart-badge-header" style="display: none;">0</span>
        <span class="d-none d-md-inline">سەبەتە</span>
    </button>

    <!-- Discount Header -->
    <div class="discount-header">
        <div class="container text-center">
            <h1 class="mb-2">
                <i class="bi bi-tags-fill"></i>
                کاڵا داشکاندراوەکان
            </h1>
            <h3 class="mb-0">
                <i class="bi bi-shop"></i>
                <?php echo htmlspecialchars($businessName); ?>
            </h3>
            <?php if ($totalDiscountedCount > 0): ?>
            <p class="mt-3 mb-0">
                <span class="badge bg-light text-danger fs-5">
                    <?php echo $totalDiscountedCount; ?> کاڵای داشکاندراو
                </span>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="container py-4">
        
        <!-- Products Grid -->
        <div class="row products-container grid-view">
            <?php if (empty($discountedProducts)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <h3>هیچ کاڵای داشکاندراوێک نییە</h3>
                        <p class="text-muted">لە ئێستادا ئەم فرۆشگایە هیچ داشکاندنێکی نییە</p>
                        <a href="<?php echo SITE_URL; ?>web/<?php echo $slug; ?>/" class="btn btn-primary btn-lg mt-3">
                            <i class="bi bi-arrow-right"></i>
                            گەڕانەوە بۆ فرۆشگا
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
                        <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" class="text-decoration-none product-image-link">
                            <div class="position-relative product-image-wrapper">
                                <img src="<?php echo getProductImageShopDiscount($product); ?>" 
                                     class="card-img-top" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                     loading="lazy"
                                     onerror="if(!this.hasAttribute('data-error-handled')) { this.setAttribute('data-error-handled', 'true'); this.src='<?php echo SITE_URL; ?>web/template/assets/images/no-image.svg'; }">
                                <div class="product-overlay">
                                    <button class="btn btn-sm btn-light quick-view-btn" 
                                            data-product-id="<?php echo $product['id']; ?>" 
                                            data-slug="<?php echo $slug; ?>">
                                        <i class="bi bi-eye"></i> بینینی خێرا
                                    </button>
                                </div>
                            </div>
                        </a>
                        
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">
                                <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" 
                                   class="text-decoration-none text-dark">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </a>
                            </h5>
                            
                            <?php if (!empty($product['category_name'])): ?>
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
                                <span class="old-price"><?php echo formatPriceShopDiscount($product['retail_price'], $productCurrency); ?></span>
                                <span class="new-price"><?php echo formatPriceShopDiscount($product['discount_price'], $productCurrency); ?></span>
                            </div>
                            
                            <div class="savings-badge mb-2">
                                پاشەکەوت: <?php echo formatPriceShopDiscount($product['retail_price'] - $product['discount_price'], $productCurrency); ?>
                            </div>
                            
                            <?php if ($websiteSettings['show_stock_quantity']): ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-box"></i>
                                    بەردەستی: <span class="fw-bold"><?php echo (int)$product['stock_quantity']; ?></span> <?php echo htmlspecialchars($product['unit_name']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Actions -->
                            <div class="mt-auto pt-3">
                                <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" 
                                   class="btn btn-outline-primary w-100 mb-2">
                                    <i class="bi bi-eye"></i>
                                    بینینی وردەکاری
                                </a>
                                
                                <button class="btn btn-danger w-100 add-to-cart-btn"
                                        data-product-id="<?php echo $product['id']; ?>"
                                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                        data-product-price="<?php echo $product['discount_price']; ?>"
                                        data-product-image="<?php echo getProductImageShopDiscount($product); ?>"
                                        data-product-unit-id="<?php echo $product['unit_id']; ?>"
                                        data-product-unit="<?php echo htmlspecialchars($product['unit_name']); ?>"
                                        data-product-currency="<?php echo htmlspecialchars($productCurrency); ?>"
                                        data-shop-slug="<?php echo htmlspecialchars($slug); ?>"
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
            <div class="cart-items"></div>
            
            <div class="cart-summary" style="display: none;">
                <div class="cart-total">
                    <span>کۆی گشتی:</span>
                    <span class="cart-total-amount">0 دینار</span>
                </div>
            </div>
            
            <div class="cart-actions">
                <div class="cart-actions-primary">
                    <a href="<?php echo SITE_URL; ?>web/checkout.php?shop=<?php echo htmlspecialchars($slug); ?>" class="btn-checkout">
                        <i class="bi bi-credit-card"></i>
                        تەواوکردنی داواکاری
                    </a>
                    <button type="button"
                            class="btn-whatsapp-order<?php echo $shopWhatsAppCartConfig['available'] ? '' : ' d-none'; ?>"
                            id="sendWhatsAppOrderBtn"
                            title="ناردنی لە واتس ئەپ">
                        <span class="btn-whatsapp-order__icon" aria-hidden="true">
                            <i class="bi bi-whatsapp"></i>
                        </span>
                        <span class="btn-whatsapp-order__text">ناردنی لە واتس ئەپ</span>
                    </button>
                </div>
                <?php if ($shopWhatsAppCartConfig['enabled'] && !$shopWhatsAppCartConfig['available']): ?>
                <small class="text-warning text-center">
                    <i class="bi bi-exclamation-triangle"></i>
                    واتسئاپ چالاکە بەڵام ژمارەی تەلەفۆنی فرۆشگا دیاری نەکردووە
                </small>
                <?php endif; ?>
                <a href="#" class="btn-continue-shopping" onclick="window.shoppingCart.closeCart(); return false;">
                    <i class="bi bi-arrow-left"></i>
                    بەردەوامبوون لە کڕین
                </a>
            </div>
        </div>
    </div>

    <!-- Back Button -->
    <a href="<?php echo SITE_URL; ?>web/<?php echo $slug; ?>/" class="back-button">
        <i class="bi bi-arrow-right"></i>
        گەڕانەوە بۆ فرۆشگا
    </a>

    <!-- Quick View Modal -->
    <div class="modal fade" id="quickViewModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">بینینی خێرا</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
    <script>
        window.shopCartWhatsApp = <?php echo json_encode($shopWhatsAppCartConfig, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/cart.js?v=<?php echo time(); ?>"></script>

</body>
</html>
