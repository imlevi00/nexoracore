<?php
/**
 * AJAX endpoint for searching products across all active websites
 * Searches ALL products from active websites, not just the displayed ones
 */

require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

// Get search query parameter
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100; // Default 100, can be increased

if (empty($query) || strlen($query) < 2) {
    echo json_encode([
        'success' => true,
        'html' => '',
        'count' => 0,
        'total' => 0,
        'hasMore' => false
    ]);
    exit;
}

$searchTerm = '%' . $query . '%';

// تاقیکردنی ئەوەی ستونی show_on_index هەیە یان نا
$checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_on_index'");
$hasShowOnIndexColumn = $checkColumnStmt->num_rows > 0;
$checkColumnStmt->close();

// Query for searching products across all active websites
if ($hasShowOnIndexColumn) {
    // ئەگەر ستونی show_on_index هەبێت، تەنها کاڵاکانی فرۆشگاکانی show_on_index = 1 نیشان بدرێن
    $searchProductsQuery = "
        SELECT p.*, u.business_name as shop_name, ws.website_slug, ws.show_stock_quantity, COALESCE(ws.show_by_category, 0) as show_by_category,
               c.name as category_name,
               COALESCE(pu.sell_price, 0) as retail_price,
               COALESCE(pu.stock_quantity, 0) as stock_quantity,
               COALESCE(pu.id, '') as unit_id,
               COALESCE(u2.name, 'دانە') as unit_name,
               pd.discount_price
        FROM products p
        INNER JOIN users u ON p.user_id = u.id
        INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1 AND ws.show_on_index = 1
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_details pd ON p.id = pd.product_id
        LEFT JOIN product_units pu ON p.id = pu.product_id 
            AND (pu.is_primary = 1 
                 OR (pu.is_primary = 0 AND NOT EXISTS (
                     SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                 ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
        LEFT JOIN units u2 ON pu.unit_id = u2.id
        LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = p.user_id
        WHERE (p.image_path IS NOT NULL AND p.image_path != '')
              AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
              AND COALESCE(c.is_visible_on_website, 1) = 1
             AND pu.stock_quantity > 0
              AND (p.name LIKE ? OR c.name LIKE ?)
        ORDER BY 
            CASE 
                WHEN p.name LIKE ? THEN 1
                WHEN p.name LIKE ? THEN 2
                ELSE 3
            END,
            p.name ASC
        LIMIT ? OFFSET ?
    ";
    
    $countQuery = "
        SELECT COUNT(DISTINCT p.id) as total
        FROM products p
        INNER JOIN users u ON p.user_id = u.id
        INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1 AND ws.show_on_index = 1
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_units pu ON p.id = pu.product_id 
            AND (pu.is_primary = 1 
                 OR (pu.is_primary = 0 AND NOT EXISTS (
                     SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                 ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
        LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = p.user_id
        WHERE (p.image_path IS NOT NULL AND p.image_path != '')
              AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
              AND COALESCE(c.is_visible_on_website, 1) = 1
             AND pu.stock_quantity > 0
              AND (p.name LIKE ? OR c.name LIKE ?)
    ";
} else {
    // ئەگەر ستونی show_on_index نەبێت، هەموو کاڵاکانی فرۆشگاکانی is_active = 1 نیشان بدرێن (دەستپێک)
    $searchProductsQuery = "
        SELECT p.*, u.business_name as shop_name, ws.website_slug, ws.show_stock_quantity, COALESCE(ws.show_by_category, 0) as show_by_category,
               c.name as category_name,
               COALESCE(pu.sell_price, 0) as retail_price,
               COALESCE(pu.stock_quantity, 0) as stock_quantity,
               COALESCE(pu.id, '') as unit_id,
               COALESCE(u2.name, 'دانە') as unit_name,
               pd.discount_price
        FROM products p
        INNER JOIN users u ON p.user_id = u.id
        INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_details pd ON p.id = pd.product_id
        LEFT JOIN product_units pu ON p.id = pu.product_id 
            AND (pu.is_primary = 1 
                 OR (pu.is_primary = 0 AND NOT EXISTS (
                     SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                 ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
        LEFT JOIN units u2 ON pu.unit_id = u2.id
        LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = p.user_id
        WHERE (p.image_path IS NOT NULL AND p.image_path != '')
              AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
              AND COALESCE(c.is_visible_on_website, 1) = 1
             AND pu.stock_quantity > 0
              AND (p.name LIKE ? OR c.name LIKE ?)
        ORDER BY 
            CASE 
                WHEN p.name LIKE ? THEN 1
                WHEN p.name LIKE ? THEN 2
                ELSE 3
            END,
            p.name ASC
        LIMIT ? OFFSET ?
    ";
    
    $countQuery = "
        SELECT COUNT(DISTINCT p.id) as total
        FROM products p
        INNER JOIN users u ON p.user_id = u.id
        INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_units pu ON p.id = pu.product_id 
            AND (pu.is_primary = 1 
                 OR (pu.is_primary = 0 AND NOT EXISTS (
                     SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                 ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
        LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = p.user_id
        WHERE (p.image_path IS NOT NULL AND p.image_path != '')
              AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
              AND COALESCE(c.is_visible_on_website, 1) = 1
             AND pu.stock_quantity > 0
              AND (p.name LIKE ? OR c.name LIKE ?)
    ";
}

// Get total count
$exactMatch = $query . '%';
$stmtCount = $conn->prepare($countQuery);
$stmtCount->bind_param("ss", $searchTerm, $searchTerm);
$stmtCount->execute();
$countResult = $stmtCount->get_result();
$totalCount = $countResult->fetch_assoc()['total'];
$stmtCount->close();

// Get products
$stmt = $conn->prepare($searchProductsQuery);
$stmt->bind_param("ssssii", $searchTerm, $searchTerm, $exactMatch, $searchTerm, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
$products = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Helper function to get product image
function getProductImage($product) {
    if (!empty($product['image_path'])) {
        $u = product_image_url($product['image_path']);
        if ($u) {
            return $u;
        }
    }
    return SITE_URL . 'web/template/assets/images/no-image.svg';
}

// Helper function to format price by currency (IQD = دینار, USD = دۆلار)
function formatPrice($price, $currency = 'IQD') {
    if ($price === null || $price === '') {
        return ($currency === 'USD') ? '0 دۆلار' : '0 دینار';
    }
    $curr = $currency === 'USD' ? 'USD' : 'IQD';
    $decimals = ($curr === 'USD') ? 2 : 0;
    $formatted = number_format((float)$price, $decimals, '.', ',');
    return $formatted . ($curr === 'USD' ? ' دۆلار' : ' دینار');
}

// Build HTML for products
$html = '';
$productIndex = 0;
foreach ($products as $product) {
    $productIndex++;
    $imageUrl = getProductImage($product);
    $productName = htmlspecialchars($product['name']);
    $shopName = htmlspecialchars($product['shop_name']);
    $categoryName = (isset($product['show_by_category']) && $product['show_by_category'] == 1 && !empty($product['category_name'])) 
        ? htmlspecialchars($product['category_name']) 
        : '';
    
    // Check if product has discount price
    $productCurrency = $product['currency'] ?? 'IQD';
    $hasDiscount = !empty($product['discount_price']) && $product['discount_price'] > 0;
    $price = formatPrice($product['retail_price'], $productCurrency);
    $discountPrice = $hasDiscount ? formatPrice($product['discount_price'], $productCurrency) : '';
    $finalPrice = $hasDiscount ? $product['discount_price'] : $product['retail_price'];
    
    $stockQuantity = (int)$product['stock_quantity'];
    $websiteSlug = $product['website_slug'];
    $isOutOfStock = $stockQuantity <= 0;
    
    $html .= '
    <div class="col-lg-4 col-md-6 product-item-card" 
         data-product-id="' . $product['id'] . '"
         data-product-name="' . strtolower($product['name']) . '"
         style="animation-delay: ' . (($productIndex % 6) * 0.1) . 's;">
        <article class="card product-card h-100 hover-lift">
            <a href="' . SITE_URL . 'web/product-details.php?slug=' . $websiteSlug . '&id=' . $product['id'] . '" class="text-decoration-none product-image-link">
                <div class="position-relative product-image-wrapper">
                    <img src="' . $imageUrl . '" 
                         class="card-img-top product-image" 
                         alt="' . $productName . '"
                         loading="lazy"
                         decoding="async"
                         onerror="if(!this.hasAttribute(\'data-error-handled\')) { this.setAttribute(\'data-error-handled\', \'true\'); this.src=\'' . SITE_URL . 'web/template/assets/images/no-image.svg\'; }">
                    ' . ($isOutOfStock ? '
                    <div class="position-absolute top-0 end-0 m-2">
                        <span class="badge bg-danger">تەواو بووە</span>
                    </div>' : '') . '
                    <div class="product-overlay-index">
                        <span class="shop-badge">
                            <i class="bi bi-shop"></i>
                            ' . $shopName . '
                        </span>
                    </div>
                </div>
            </a>
            
            <div class="card-body d-flex flex-column">
                <h5 class="card-title product-title">
                    <a href="' . SITE_URL . 'web/product-details.php?slug=' . $websiteSlug . '&id=' . $product['id'] . '" class="text-decoration-none text-dark">
                        ' . $productName . '
                    </a>
                </h5>
                
                ' . ($categoryName ? '
                <p class="card-text text-muted small mb-2">
                    <i class="bi bi-tag"></i>
                    ' . $categoryName . '
                </p>' : '') . '
                
                <div class="prices mt-auto">
                    <div class="price-item">
                        <span class="price-label">نرخ:</span>
                        ' . ($hasDiscount ? 
                            '<span class="price-value text-decoration-line-through text-muted">' . $price . '</span>' : 
                            '<span class="price-value">' . $price . '</span>') . '
                    </div>
                    ' . ($hasDiscount ? '
                    <div class="price-item special">
                        <span class="price-label">نرخی داشکاندن:</span>
                        <span class="price-value text-danger fw-bold">' . $discountPrice . '</span>
                    </div>' : '') . '
                </div>

                ' . ((!empty($product['show_stock_quantity']) && $stockQuantity > 0) ? '
                <div class="mt-2">
                    <small class="text-muted">
                        <i class="bi bi-box"></i>
                        بەردەستی: <span class="stock-quantity">' . $stockQuantity . '</span> دانە
                    </small>
                </div>' : '') . '
                
                ' . ($stockQuantity > 0 ? '
                <div class="mt-3">
                    <button class="btn btn-primary w-100 add-to-cart-btn"
                            data-product-id="' . $product['id'] . '"
                            data-product-name="' . $productName . '"
                            data-product-price="' . $finalPrice . '"
                            data-product-image="' . htmlspecialchars(getProductImage($product), ENT_QUOTES, 'UTF-8') . '"
                            data-product-unit="' . htmlspecialchars($product['unit_name'] ?? 'دانە') . '"
                            data-product-unit-id="' . ($product['unit_id'] ?? '') . '"
                            data-product-currency="' . htmlspecialchars($productCurrency) . '"
                            data-shop-slug="' . htmlspecialchars($websiteSlug) . '">
                        <i class="bi bi-cart-plus"></i>
                        زیاد کردن بۆ سەبەتە
                    </button>
                </div>' : '') . '
            </div>
        </article>
    </div>';
}

// Return JSON response
echo json_encode([
    'success' => true,
    'html' => $html,
    'count' => count($products),
    'total' => (int)$totalCount,
    'hasMore' => ($offset + count($products) < $totalCount)
]);
