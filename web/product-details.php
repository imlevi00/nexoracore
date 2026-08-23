<?php
/**
 * Product Details Page - web/product-details.php
 * Shows full product details with images gallery and description
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once 'auth/session_helper.php';
require_once 'auth/shop_google_access.php';
require_once 'includes/shop_whatsapp.php';

shop_google_ensure_db_schema($conn);
shop_whatsapp_ensure_column($conn);

// Get parameters
$slug = $_GET['slug'] ?? '';
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Validate slug format
if (empty($slug) || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    http_response_code(404);
    include '404.php';
    exit;
}

if ($productId <= 0) {
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

// Get product details
$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name, c.id as category_id,
           pd.description, pd.main_image, pd.sub_images, pd.discount_price,
           wpv.is_visible
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_details pd ON p.id = pd.product_id
    LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = ?
    WHERE p.id = ? AND p.user_id = ? AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
");
$stmt->bind_param("iii", $userId, $productId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();
$stmt->close();

if (!$product) {
    http_response_code(404);
    include '404.php';
    exit;
}

// Get all units for this product
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

// Determine display prices and stock
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

$displayRetail = $displayUnit ? $displayUnit['sell_price'] : $product['sell_price'];
$displayWholesale = $displayUnit ? $displayUnit['wholesale_price'] : $product['wholesale_price'];
$displaySpecial = $displayUnit ? $displayUnit['special_price'] : $product['special_price'];
$displayStock = $displayUnit ? $displayUnit['stock_quantity'] : $product['stock_quantity'];

// Prepare images array
$images = [];
if (!empty($product['main_image'])) {
    $images[] = $product['main_image'];
} elseif (!empty($product['image_path'])) {
    $images[] = $product['image_path'];
}

if (!empty($product['sub_images'])) {
    $subImages = json_decode($product['sub_images'], true);
    if (is_array($subImages)) {
        $images = array_merge($images, $subImages);
    }
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

// Helper function to get image URL
function getImageUrl($imagePath) {
    if (empty($imagePath)) {
        return SITE_URL . 'web/template/assets/images/no-image.svg';
    }
    $u = product_image_url($imagePath);
    return $u ?: SITE_URL . 'web/template/assets/images/no-image.svg';
}

// Helper function to get product image (for similar products)
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

// Get similar products
$similarProducts = [];
$productNameWords = explode(' ', trim($product['name']));
$productNameWords = array_filter($productNameWords, function($word) {
    return mb_strlen(trim($word)) > 2; // Only words longer than 2 characters
});
$productNameWords = array_values(array_slice($productNameWords, 0, 3)); // Take first 3 meaningful words

$categoryId = $product['category_id'] ?? null;
$namePattern = '';
if (count($productNameWords) > 0) {
    $namePattern = '%' . implode('%', $productNameWords) . '%';
}

// Build query for similar products with priority:
// 1. Same category + similar name (highest priority)
// 2. Same category
// 3. Similar name
// 4. Any product from same store

$similarQuery = "
    SELECT DISTINCT p.*, c.name as category_name, c.id as category_id,
           COALESCE(pu.stock_quantity, 0) as stock_quantity,
           COALESCE(pu.sell_price, 0) as retail_price,
           COALESCE(pu.wholesale_price, 0) as wholesale_price,
           COALESCE(pu.special_price, 0) as special_price,
           pd.description, pd.main_image, pd.discount_price,
           CASE 
               WHEN p.category_id = ? AND p.name LIKE ? THEN 1
               WHEN p.category_id = ? THEN 2
               WHEN p.name LIKE ? THEN 3
               ELSE 4
           END as similarity_score
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = ?
    LEFT JOIN product_details pd ON p.id = pd.product_id
    LEFT JOIN product_units pu ON p.id = pu.product_id 
        AND (pu.is_primary = 1 
             OR (pu.is_primary = 0 AND NOT EXISTS (
                 SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
             ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
    WHERE p.user_id = ? 
        AND p.id != ?
        AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
        AND COALESCE(c.is_visible_on_website, 1) = 1
        AND pu.stock_quantity > 0
        AND ((p.image_path IS NOT NULL AND p.image_path != '') OR (pd.main_image IS NOT NULL AND pd.main_image != ''))
    ORDER BY similarity_score ASC, p.name ASC
    LIMIT 10
";

$similarStmt = $conn->prepare($similarQuery);
if ($similarStmt) {
    $categoryIdParam = $categoryId ?? 0;
    $namePatternParam = $namePattern ?: '';
    
    $similarStmt->bind_param("iissiii", 
        $categoryIdParam,           // for CASE WHEN p.category_id = ? (first)
        $namePatternParam,           // for CASE WHEN p.name LIKE ? (first)
        $categoryIdParam,            // for CASE WHEN p.category_id = ? (second)
        $namePatternParam,           // for CASE WHEN p.name LIKE ? (second)
        $userId,                     // for wpv.user_id
        $userId,                     // for p.user_id
        $productId                   // for p.id != ?
    );
    
    $similarStmt->execute();
    $similarResult = $similarStmt->get_result();
    $similarProducts = $similarResult->fetch_all(MYSQLI_ASSOC);
    $similarStmt->close();
}

// Get units for similar products
function getProductUnitsForSimilar($conn, $productId) {
    $stmt = $conn->prepare("
        SELECT pu.*, u.name as unit_name, u.symbol as unit_symbol
        FROM product_units pu
        INNER JOIN units u ON pu.unit_id = u.id
        WHERE pu.product_id = ?
        ORDER BY pu.is_primary DESC, pu.id ASC
    ");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $units = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $units;
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title><?php echo htmlspecialchars($product['name']); ?> - <?php echo htmlspecialchars($businessName); ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/shop.css" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/cart.css?v=<?php echo time(); ?>" rel="stylesheet">
    
    <!-- Meta tags for SEO -->
    <meta name="description" content="<?php echo htmlspecialchars(mb_substr(strip_tags($product['description'] ?? ''), 0, 160)); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($product['name']); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars(mb_substr(strip_tags($product['description'] ?? ''), 0, 160)); ?>">
    <?php if (!empty($images)): ?>
    <meta property="og:image" content="<?php echo getImageUrl($images[0]); ?>">
    <?php endif; ?>
    
    <style>
        :root {
            --pd-surface: #ffffff;
            --pd-surface-alt: #f8f9fa;
            --pd-surface-soft: #e9ecef;
            --pd-text: #1e293b;
            --pd-text-muted: #64748b;
            --pd-border: #e9ecef;
        }

        /* Fixed Header Styles */
        .navbar {
            position: fixed !important;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            width: 100%;
            background: linear-gradient(135deg, #0d6efd 0%, #6f42c1 100%) !important;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15) !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 0.75rem 0;
            border-bottom: 2px solid rgba(255,255,255,0.1);
        }
        
        .navbar.scrolled {
            box-shadow: 0 8px 30px rgba(0,0,0,0.25) !important;
            padding: 0.5rem 0;
            background: linear-gradient(135deg, rgba(13, 110, 253, 0.98) 0%, rgba(111, 66, 193, 0.98) 100%) !important;
        }
        
        /* Smooth scroll behavior */
        html {
            scroll-behavior: smooth;
        }
        
        /* Add padding to body to account for fixed navbar */
        body {
            padding-top: 70px;
            scroll-padding-top: 70px;
        }
        
        @media (max-width: 768px) {
            body {
                padding-top: 65px;
                scroll-padding-top: 65px;
            }
            
            .navbar {
                padding: 0.6rem 0;
            }
        }
        
        @media (max-width: 576px) {
            body {
                padding-top: 60px;
                scroll-padding-top: 60px;
            }
            
            .navbar {
                padding: 0.5rem 0;
            }
        }
        
        /* Navbar Brand Enhancement */
        .navbar-brand {
            font-size: 1.1rem !important;
            font-weight: 600 !important;
            transition: all 0.3s ease !important;
            padding: 8px 12px !important;
            border-radius: 12px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
        
        .navbar-brand:hover {
            background: rgba(255,255,255,0.1) !important;
            transform: translateX(-5px) !important;
        }
        
        /* Navbar Items Enhancement */
        .navbar-nav {
            gap: 10px !important;
            flex-wrap: nowrap !important;
        }
        
        .navbar .container {
            overflow-x: visible;
        }
        
        .cart-button {
            position: relative !important;
            transition: all 0.3s ease !important;
            background: rgba(255,255,255,0.15) !important;
            border: 2px solid rgba(255,255,255,0.3) !important;
            border-radius: 12px !important;
            padding: 10px 15px !important;
            color: white !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: visible !important;
            white-space: nowrap !important;
        }
        
        .cart-button:hover {
            background: rgba(255,255,255,0.25) !important;
            border-color: rgba(255,255,255,0.5) !important;
            transform: scale(1.05) translateY(-2px) !important;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2) !important;
        }
        
        .cart-button:active {
            transform: scale(0.98) !important;
        }
        
        .cart-button i {
            font-size: 1.3rem !important;
            transition: transform 0.3s ease !important;
        }
        
        .cart-button:hover i {
            transform: scale(1.1) rotate(-5deg) !important;
        }
        
        .cart-badge {
            position: absolute;
            top: -8px;
            left: -8px;
            background: #dc3545;
            color: white;
            border-radius: 12px;
            min-width: 24px;
            height: 24px;
            padding: 0 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            border: 2px solid #0d6efd;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            z-index: 10;
            white-space: nowrap;
            line-height: 1;
        }
        
        /* Dropdown Enhancement */
        .dropdown-toggle {
            transition: all 0.3s ease !important;
        }
        
        .dropdown-toggle:hover {
            background: rgba(255,255,255,0.2) !important;
            transform: translateY(-2px) !important;
        }
        
        .dropdown-menu {
            border-radius: 15px !important;
            border: none !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important;
            margin-top: 10px !important;
            padding: 8px !important;
        }
        
        .dropdown-item {
            border-radius: 10px !important;
            padding: 10px 16px !important;
            margin: 4px 0 !important;
            transition: all 0.3s ease !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }
        
        .dropdown-item:hover {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%) !important;
            transform: translateX(-5px) !important;
            padding-right: 20px !important;
        }
        
        .dropdown-item i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }
        
        /* Navbar Text Enhancement */
        .navbar-text {
            background: rgba(255,255,255,0.2) !important;
            padding: 8px 16px !important;
            border-radius: 12px !important;
            font-weight: 600 !important;
            backdrop-filter: blur(10px) !important;
            -webkit-backdrop-filter: blur(10px) !important;
            transition: all 0.3s ease !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
        
        .navbar-text:hover {
            background: rgba(255,255,255,0.3) !important;
            transform: translateY(-2px) !important;
        }
        
        /* Mobile Menu Toggle */
        .navbar-toggler {
            border: 2px solid rgba(255,255,255,0.3) !important;
            border-radius: 10px !important;
            padding: 8px 12px !important;
            transition: all 0.3s ease !important;
        }
        
        .navbar-toggler:hover {
            background: rgba(255,255,255,0.1) !important;
            border-color: rgba(255,255,255,0.5) !important;
        }
        
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 1%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e") !important;
        }
        
        /* Cart button - always visible in header */
        .navbar .cart-button {
            margin-left: 10px;
            flex-shrink: 0;
            order: 2;
        }
        
        @media (max-width: 991px) {
            .navbar .container {
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: nowrap;
            }
            
            .navbar-brand {
                flex: 0 0 auto;
                margin-right: auto;
                order: 1;
            }
            
            .navbar .cart-button {
                order: 2;
                margin-left: auto;
                margin-right: 10px;
            }
            
            .navbar-toggler {
                order: 3;
                margin-left: 0;
            }
        }
        
        @media (min-width: 992px) {
            .navbar .cart-button {
                order: 2;
                margin-left: auto;
                margin-right: 10px;
            }
        }
        
        /* Responsive Navbar */
        @media (max-width: 991px) {
            .navbar-collapse {
                background: rgba(13, 110, 253, 0.98);
                border-radius: 15px;
                margin-top: 15px;
                padding: 15px;
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
            }
            
            .navbar-nav {
                gap: 8px !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
            }
            
            .navbar-nav .gap-3 {
                gap: 8px !important;
            }
            
            .navbar-text {
                width: 100%;
                justify-content: center;
                margin-top: 10px;
            }
            
            .cart-button {
                width: auto !important;
                min-width: 50px;
                flex-shrink: 0;
                justify-content: center;
            }
            
            .dropdown {
                width: auto !important;
                flex-shrink: 0;
            }
            
            .dropdown-toggle {
                width: auto !important;
                min-width: 100px;
                justify-content: center;
                white-space: nowrap;
            }
        }
        
        @media (max-width: 768px) {
            .navbar-brand {
                font-size: 0.95rem !important;
            }
            
            .navbar-brand i {
                font-size: 1.2rem !important;
            }
            
            .cart-button {
                padding: 8px 12px !important;
            }
            
            .cart-button i {
                font-size: 1.2rem !important;
            }
            
            .dropdown-toggle {
                font-size: 0.9rem !important;
                padding: 8px 12px !important;
            }
            
            .dropdown-toggle i {
                font-size: 1.1rem !important;
            }
        }
        
        @media (max-width: 576px) {
            .navbar-brand {
                font-size: 0.85rem !important;
                padding: 6px 10px !important;
            }
            
            .navbar-brand i {
                font-size: 1.1rem !important;
            }
            
            .navbar-text {
                font-size: 0.85rem !important;
                padding: 6px 12px !important;
            }
            
            .navbar-text i {
                font-size: 1rem !important;
            }
            
            .cart-button {
                padding: 8px 10px !important;
                min-width: 45px;
            }
            
            .cart-button i {
                font-size: 1.1rem !important;
            }
            
            .dropdown-toggle {
                font-size: 0.85rem !important;
                padding: 7px 10px !important;
                min-width: 90px;
            }
            
            .dropdown-toggle i {
                font-size: 1rem !important;
            }
            
            .cart-badge {
                min-width: 20px;
                height: 20px;
                font-size: 0.7rem;
                top: -6px;
                left: -6px;
                padding: 0 5px;
                border-radius: 10px;
            }
            
            .navbar-nav {
                flex-wrap: nowrap !important;
                overflow-x: auto;
                overflow-y: hidden;
            }
            
            .navbar-nav .gap-3 {
                gap: 6px !important;
            }
        }
        
        @media (max-width: 400px) {
            .navbar-brand {
                font-size: 0.8rem !important;
            }
            
            .cart-button {
                padding: 7px 8px !important;
            }
        }
        
        /* Product Gallery Styles */
        .product-gallery {
            position: sticky;
            top: 90px;
            animation: fadeInUp 0.6s ease-out;
        }
        
        @media (max-width: 992px) {
            .product-gallery {
                position: static;
                margin-bottom: 30px;
            }
        }
        
        .main-image-container {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            cursor: zoom-in;
            transition: all 0.4s ease, transform 0.1s ease-out;
            will-change: transform;
        }
        
        .main-image-container:hover {
            box-shadow: 0 15px 50px rgba(0,0,0,0.25);
            transform: translateY(-5px);
        }
        
        .main-image-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(13,110,253,0.1) 0%, rgba(111,66,193,0.1) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        
        .main-image-container:hover::before {
            opacity: 1;
        }
        
        .main-image {
            width: 100%;
            height: auto;
            max-height: 500px;
            object-fit: contain;
            display: block;
            transition: all 0.4s ease;
        }
        
        .main-image-container:hover .main-image {
            transform: scale(1.05);
        }
        
        /* No Image Placeholder */
        .main-image[src*="no-image.svg"] {
            opacity: 0.5;
            filter: grayscale(30%);
        }
        
        .thumbnail-image[src*="no-image.svg"] {
            opacity: 0.5;
            filter: grayscale(30%);
        }
        
        .thumbnail-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
            gap: 12px;
            margin-top: 20px;
            animation: fadeInUp 0.6s ease-out 0.3s both;
        }
        
        .thumbnail-item {
            border: 3px solid #e9ecef;
            border-radius: 15px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            aspect-ratio: 1;
            position: relative;
            background: white;
        }
        
        .thumbnail-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(13,110,253,0.2) 0%, rgba(111,66,193,0.2) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .thumbnail-item:hover {
            border-color: #0d6efd;
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 8px 20px rgba(13,110,253,0.3);
        }
        
        .thumbnail-item:hover::before {
            opacity: 1;
        }
        
        .thumbnail-item.active {
            border-color: #0d6efd;
            box-shadow: 0 8px 25px rgba(13,110,253,0.4);
            transform: scale(1.08);
        }
        
        .thumbnail-item.active::before {
            opacity: 1;
        }
        
        .thumbnail-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .thumbnail-item:hover .thumbnail-image {
            transform: scale(1.1);
        }
        
        /* Product Info Styles */
        .product-info {
            background: white;
            border-radius: 20px;
            padding: 35px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
            animation: fadeInUp 0.6s ease-out 0.2s both;
            position: relative;
            overflow: hidden;
        }
        
        .product-info::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #0d6efd 0%, #6f42c1 50%, #0d6efd 100%);
            background-size: 200% 100%;
            animation: gradientMove 3s ease infinite;
        }
        
        @keyframes gradientMove {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .product-title {
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #1e293b 0%, #475569 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 15px;
            line-height: 1.3;
        }
        
        .product-category {
            color: #64748b;
            font-size: 1.1rem;
            margin-bottom: 25px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            border-radius: 25px;
            transition: all 0.3s ease;
        }
        
        .product-category:hover {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
            transform: translateX(-5px);
        }
        
        .product-category i {
            color: #0d6efd;
        }
        
        /* Price Box Styles */
        .price-box {
            background: transparent;
            color: #1e293b;
            padding: 25px;
            border-radius: 18px;
            margin-bottom: 25px;
            box-shadow: none;
            position: relative;
            overflow: hidden;
        }
        
        .price-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            position: relative;
            z-index: 1;
        }
        
        .price-item:last-child {
            border-bottom: none;
        }
        
        /* Individual price item backgrounds */
        .price-item-retail {
            background: transparent;
            padding: 12px 0;
            margin-bottom: 0;
            border: none;
            transition: all 0.3s ease;
        }
        
        .price-item-retail:hover {
            background: transparent;
            transform: translateX(-5px);
        }
        
        .price-item-wholesale {
            background: transparent;
            padding: 12px 0;
            margin-bottom: 0;
            border: none;
            transition: all 0.3s ease;
        }
        
        .price-item-wholesale:hover {
            background: transparent;
            transform: translateX(-5px);
        }
        
        .price-item-special {
            background: transparent;
            padding: 12px 0;
            margin-bottom: 0;
            border: none;
            transition: all 0.3s ease;
        }
        
        .price-item-special:hover {
            background: transparent;
            transform: translateX(-5px);
        }
        
        .price-label {
            font-size: 1.05rem;
            color: #1e293b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .price-label i {
            font-size: 1.2rem;
            color: #0d6efd;
        }
        
        .price-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1e293b;
            transition: transform 0.2s ease;
        }
        
        .price-box {
            transition: all 0.3s ease;
        }
        
        /* Description Box */
        .description-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-radius: 18px;
            margin-bottom: 25px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .description-box:hover {
            border-color: #cbd5e1;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .description-box h5 {
            color: #1e293b;
            margin-bottom: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .description-box h5 i {
            color: #0d6efd;
            font-size: 1.3rem;
        }
        
        .description-text {
            color: #475569;
            line-height: 1.9;
            white-space: pre-wrap;
            font-size: 1.05rem;
        }
        
        /* Stock Badge */
        .stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 30px;
            font-weight: 700;
            margin-bottom: 25px;
            font-size: 1.05rem;
            transition: all 0.3s ease, transform 0.2s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .stock-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        .stock-badge.in-stock {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            border: 2px solid #6ee7b7;
        }
        
        .stock-badge.out-of-stock {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border: 2px solid #fca5a5;
        }
        
        .stock-badge i {
            font-size: 1.2rem;
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            border: none;
            border-radius: 15px;
            padding: 15px 30px;
            font-size: 1.1rem;
            font-weight: 700;
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(13,110,253,0.3);
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn-primary:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(13,110,253,0.4);
        }
        
        .btn-primary i {
            margin-left: 8px;
            transition: transform 0.3s ease;
        }
        
        .btn-primary:hover i {
            transform: translateX(5px);
        }
        
        /* Unit Selector */
        .form-select-lg {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 12px 20px;
            font-size: 1.05rem;
            transition: all 0.3s ease;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .form-select-lg:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .form-select-lg:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13,110,253,0.15), 0 4px 15px rgba(13,110,253,0.2);
            outline: none;
        }
        
        .unit-selector option {
            padding: 12px;
            font-weight: 600;
        }
        
        .form-label {
            font-size: 1.1rem;
            color: #1e293b;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-label i {
            color: #0d6efd;
            font-size: 1.2rem;
        }
        
        /* Image Zoom Modal */
        .image-zoom-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 9999;
            cursor: zoom-out;
            backdrop-filter: blur(10px);
        }
        
        .image-zoom-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .image-zoom-modal img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            animation: zoomIn 0.3s ease;
        }
        
        @keyframes zoomIn {
            from {
                transform: scale(0.8);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .zoom-close {
            position: absolute;
            top: 30px;
            right: 30px;
            background: white;
            color: #000;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            font-size: 1.8rem;
            cursor: pointer;
            z-index: 10000;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .zoom-close:hover {
            background: #f8f9fa;
            transform: rotate(90deg) scale(1.1);
            box-shadow: 0 6px 25px rgba(0,0,0,0.4);
        }
        
        .zoom-close:active {
            transform: rotate(90deg) scale(0.95);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Additional Hover Effects */
        .navbar-brand:hover {
            transform: translateX(-5px);
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .navbar-brand:hover i {
            transform: translateX(-3px);
        }
        
        .navbar-brand i {
            transition: transform 0.3s ease;
        }
        
        .cart-button:hover {
            background: rgba(255,255,255,0.25) !important;
            transform: scale(1.05);
        }
        
        .dropdown-item:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%) !important;
            transform: translateX(-5px);
        }
        
        .btn-checkout:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(16,185,129,0.4) !important;
        }
        
        .btn-continue-shopping:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108,117,125,0.4) !important;
        }
        
        .cart-close:hover {
            background: rgba(255,255,255,0.3) !important;
            transform: rotate(90deg);
        }
        
        /* Smooth Scrollbar */
        html {
            scroll-behavior: smooth;
        }
        
        /* Page Loading Animation */
        body {
            animation: pageLoad 0.5s ease-out;
            opacity: 0;
        }
        
        body.loaded {
            opacity: 1;
        }
        
        @keyframes pageLoad {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Loading Overlay */
        .page-loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            opacity: 1;
            transition: opacity 0.5s ease;
        }
        
        .page-loading.hidden {
            opacity: 0;
            pointer-events: none;
        }
        
        .loading-spinner-large {
            width: 60px;
            height: 60px;
            border: 6px solid #e9ecef;
            border-top: 6px solid #0d6efd;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Breadcrumb Hover */
        .breadcrumb-item a:hover {
            color: #0056b3 !important;
            text-decoration: underline !important;
        }
        
        /* Footer Links Hover */
        footer .btn {
            transition: all 0.3s ease;
        }
        
        footer .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.1);
        }
        
        footer .btn i {
            transition: transform 0.3s ease;
        }
        
        footer .btn:hover i {
            transform: scale(1.2);
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .product-gallery {
                position: static;
                margin-bottom: 30px;
            }
        }
        
        @media (max-width: 768px) {
            .product-title {
                font-size: 1.8rem;
            }
            
            .product-info {
                padding: 25px;
            }
            
            .product-info::before {
                height: 4px;
            }
            
            .price-box {
                padding: 20px;
            }
            
            .price-value {
                font-size: 1.2rem;
            }
            
            .price-label {
                font-size: 0.95rem;
            }
            
            .thumbnail-gallery {
                grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
                gap: 10px;
            }
            
            .main-image {
                max-height: 350px;
            }
            
            .main-image-container {
                border-radius: 15px;
            }
            
            .thumbnail-item {
                border-radius: 12px;
            }
            
            .stock-badge {
                font-size: 0.95rem;
                padding: 8px 16px;
            }
            
            .btn-primary {
                font-size: 1rem;
                padding: 12px 25px;
            }
            
            .description-box {
                padding: 20px;
            }
            
            .description-text {
                font-size: 1rem;
            }
            
            .zoom-close {
                width: 45px;
                height: 45px;
                font-size: 1.6rem;
                top: 20px;
                right: 20px;
            }
            
            .navbar-brand {
                font-size: 0.95rem !important;
            }
            
            .navbar-text {
                font-size: 0.9rem !important;
                padding: 6px 12px !important;
            }
            
            .breadcrumb {
                padding: 12px 15px;
                font-size: 0.9rem;
            }
            
            footer {
                padding: 2rem 0 !important;
            }
            
            footer h5 {
                font-size: 1.1rem;
            }
        }
        
        @media (max-width: 576px) {
            .product-title {
                font-size: 1.5rem;
            }
            
            .product-info {
                padding: 20px;
                border-radius: 15px;
            }
            
            .price-box {
                padding: 18px;
                border-radius: 15px;
            }
            
            .price-item {
                padding: 10px 0;
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .price-value {
                font-size: 1.3rem;
            }
            
            .thumbnail-gallery {
                grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
                gap: 8px;
            }
            
            .main-image {
                max-height: 300px;
            }
            
            .btn-primary {
                font-size: 0.95rem;
                padding: 12px 20px;
            }
            
            .stock-badge {
                font-size: 0.9rem;
                padding: 7px 14px;
            }
            
            .form-select-lg {
                font-size: 0.95rem;
                padding: 10px 15px;
            }
            
            .description-box {
                padding: 18px;
            }
            
            .description-box h5 {
                font-size: 1.1rem;
            }
            
            .description-text {
                font-size: 0.95rem;
            }
            
            .navbar-brand {
                font-size: 0.85rem !important;
            }
            
            .navbar-brand i {
                font-size: 1.1rem !important;
            }
            
            .navbar-text {
                display: none !important;
            }
            
            .cart-button {
                padding: 8px 12px !important;
            }
            
            .cart-button i {
                font-size: 1.2rem !important;
            }
            
            .dropdown .btn {
                font-size: 0.85rem !important;
                padding: 6px 12px !important;
            }
            
            .breadcrumb {
                padding: 10px 12px;
                font-size: 0.85rem;
            }
            
            .breadcrumb-item {
                max-width: 150px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            
            footer h5 {
                font-size: 1rem;
            }
            
            footer p {
                font-size: 0.85rem;
            }
            
            footer .btn {
                font-size: 0.85rem;
                padding: 6px 15px;
            }
            
            /* Similar Products Mobile */
            .products-container .col-lg-4 {
                margin-bottom: 20px;
            }
            
            .products-container .product-card {
                margin-bottom: 0;
            }
            
            .products-container .product-card .card-img-top {
                height: 200px;
            }
        }
        
        @media (max-width: 400px) {
            .product-title {
                font-size: 1.3rem;
            }
            
            .product-category {
                font-size: 0.9rem;
            }
            
            .price-label {
                font-size: 0.85rem;
            }
            
            .price-value {
                font-size: 1.1rem;
            }
        }
        
        /* Print Button */
        button[onclick="window.print()"] {
            transition: all 0.3s ease;
        }
        
        button[onclick="window.print()"]:hover {
            background: rgba(255,255,255,0.1) !important;
        }
        
        /* Similar Products Styles - Matching shop.php */
        .products-container .product-card {
            transition: all 0.3s ease;
            border: none !important;
        }
        
        .products-container .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
        }
        
        .products-container .product-image-wrapper {
            position: relative;
            overflow: hidden;
            background-color: #ffffff;
            border-bottom: 1px solid #e9ecef;
        }
        
        .products-container .product-card .card-img-top {
            height: 250px;
            object-fit: contain;
            border-radius: 0;
            background-color: #ffffff;
            padding: 10px;
            transition: all 0.3s ease;
        }
        
        .products-container .product-image-wrapper:hover .product-overlay {
            opacity: 1 !important;
        }
        
        .products-container .product-image-wrapper:hover img {
            transform: scale(1.1);
        }
        
        .products-container .product-card.out-of-stock {
            opacity: 0.7;
        }
        
        .products-container .product-card.out-of-stock:hover {
            opacity: 0.85;
        }
        
        /* Print Styles */
        @media print {
            nav, footer, .cart-sidebar, .cart-sidebar-overlay, .breadcrumb, .zoom-close {
                display: none !important;
            }
            
            body {
                background: white !important;
            }
            
            .container {
                max-width: 100% !important;
            }
            
            .product-gallery {
                position: static !important;
            }
            
            .main-image-container {
                box-shadow: none !important;
                border: 1px solid #ddd;
            }
            
            .product-info {
                box-shadow: none !important;
                border: 1px solid #ddd;
            }
            
            .product-info::before {
                display: none !important;
            }
            
            .price-box {
                background: white !important;
                color: #000 !important;
                border: 2px solid #0d6efd;
            }
            
            .price-label, .price-value {
                color: #000 !important;
            }
            
            .btn-primary {
                display: none !important;
            }
            
            .thumbnail-gallery {
                display: none !important;
            }
            
            .description-box {
                background: white !important;
                border: 1px solid #ddd;
            }
        }

        html[data-bs-theme='dark'] {
            --pd-surface: #111827;
            --pd-surface-alt: #1f2937;
            --pd-surface-soft: #374151;
            --pd-text: #f3f4f6;
            --pd-text-muted: #9ca3af;
            --pd-border: #4b5563;
        }

        html[data-bs-theme='dark'] body,
        html[data-bs-theme='dark'] .page-loading {
            background: linear-gradient(135deg, #0b1220 0%, #111827 100%) !important;
            color: var(--pd-text) !important;
        }

        html[data-bs-theme='dark'] .product-info,
        html[data-bs-theme='dark'] .description-box,
        html[data-bs-theme='dark'] .main-image-container,
        html[data-bs-theme='dark'] .cart-summary,
        html[data-bs-theme='dark'] .breadcrumb,
        html[data-bs-theme='dark'] .products-container .product-card,
        html[data-bs-theme='dark'] .products-container .product-image-wrapper,
        html[data-bs-theme='dark'] .form-select-lg,
        html[data-bs-theme='dark'] .thumbnail-item,
        html[data-bs-theme='dark'] .cart-sidebar {
            background: var(--pd-surface) !important;
            color: var(--pd-text) !important;
            border-color: var(--pd-border) !important;
        }

        html[data-bs-theme='dark'] .price-box,
        html[data-bs-theme='dark'] .cart-actions a,
        html[data-bs-theme='dark'] .products-container .price-item,
        html[data-bs-theme='dark'] .products-container .card-body {
            background: var(--pd-surface-alt) !important;
            color: var(--pd-text) !important;
            border-color: var(--pd-border) !important;
        }

        html[data-bs-theme='dark'] .product-title,
        html[data-bs-theme='dark'] .price-value,
        html[data-bs-theme='dark'] .form-label,
        html[data-bs-theme='dark'] .products-container .card-title a,
        html[data-bs-theme='dark'] .breadcrumb-item.active {
            color: var(--pd-text) !important;
            -webkit-text-fill-color: var(--pd-text) !important;
            background: none !important;
        }

        html[data-bs-theme='dark'] .product-category,
        html[data-bs-theme='dark'] .description-text,
        html[data-bs-theme='dark'] .price-label,
        html[data-bs-theme='dark'] .text-muted,
        html[data-bs-theme='dark'] .products-container .card-text,
        html[data-bs-theme='dark'] .products-container .price-label {
            color: var(--pd-text-muted) !important;
        }

        html[data-bs-theme='dark'] .main-image-container,
        html[data-bs-theme='dark'] .thumbnail-item,
        html[data-bs-theme='dark'] .products-container .card-img-top {
            background-color: var(--pd-surface) !important;
        }

        html[data-bs-theme='dark'] .products-container .card-img-top {
            border-color: var(--pd-border) !important;
        }

        html[data-bs-theme='dark'] .products-container .card-title a.text-dark {
            color: var(--pd-text) !important;
        }

        html[data-bs-theme='dark'] .page-loading .loading-spinner-large {
            border-color: #374151;
            border-top-color: #60a5fa;
        }

        html[data-bs-theme='dark'] footer.bg-dark {
            background: linear-gradient(135deg, #020617 0%, #0b1220 100%) !important;
        }
    </style>
</head>
<body class="product-details-page">

    <!-- Loading Overlay -->
    <div class="page-loading">
        <div class="loading-spinner-large"></div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary" id="mainNavbar">
        <div class="container">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>web/shop.php?slug=<?php echo $slug; ?>">
                <i class="bi bi-arrow-right-circle-fill"></i>
                گەڕانەوە بۆ فرۆشگا
            </a>
            
            <!-- Cart Button - Always visible in header -->
            <button class="cart-button btn btn-outline-light" title="سەبەتەی کڕین" type="button" style="margin-left: 10px;">
                <i class="bi bi-cart3"></i>
                <span class="cart-badge" style="display: none;">0</span>
            </button>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <div class="navbar-nav ms-auto d-flex align-items-center gap-3 flex-nowrap">
                    <!-- Customer Menu -->
                    <?php if (CustomerSession::isLoggedIn()): ?>
                        <?php $customerData = CustomerSession::getCustomerData(); ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle"></i>
                                <span class="d-none d-md-inline"><?php echo htmlspecialchars($customerData['name']); ?></span>
                                <span class="d-md-none">هەژمار</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>web/my-orders.php">
                                        <i class="bi bi-list-ul"></i> داواکارییەکانم
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>web/auth/logout.php?slug=<?php echo urlencode($slug); ?>">
                                        <i class="bi bi-box-arrow-right"></i> دەرچوون
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person"></i> هەژمار
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>web/auth/login.php?redirect=product-details.php?slug=<?php echo urlencode($slug); ?>&id=<?php echo $productId; ?>">
                                        <i class="bi bi-box-arrow-in-right"></i> چوونەژوورەوە
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>web/auth/register.php">
                                        <i class="bi bi-person-plus"></i> تۆمارکردن
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <span class="navbar-text d-none d-lg-inline-flex">
                        <i class="bi bi-shop"></i>
                        <?php echo htmlspecialchars($businessName); ?>
                    </span>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-5" style="max-width: 1400px; margin-top: 20px;">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4" style="animation: fadeInUp 0.6s ease-out;">
            <ol class="breadcrumb" style="background: white; padding: 15px 20px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.08);">
                <li class="breadcrumb-item">
                    <a href="<?php echo SITE_URL; ?>web/shop.php?slug=<?php echo $slug; ?>" style="color: #0d6efd; text-decoration: none; font-weight: 600;">
                        <i class="bi bi-house-fill"></i> سەرەکی
                    </a>
                </li>
                <?php if ($product['category_name']): ?>
                <li class="breadcrumb-item">
                    <a href="<?php echo SITE_URL; ?>web/shop.php?slug=<?php echo $slug; ?>&category=<?php echo $product['category_id']; ?>" style="color: #0d6efd; text-decoration: none; font-weight: 600;">
                        <?php echo htmlspecialchars($product['category_name']); ?>
                    </a>
                </li>
                <?php endif; ?>
                <li class="breadcrumb-item active" aria-current="page" style="color: #64748b; font-weight: 600;">
                    <?php echo htmlspecialchars($product['name']); ?>
                </li>
            </ol>
        </nav>
        
        <div class="row">
            <!-- Image Gallery -->
            <div class="col-lg-6 mb-4">
                <div class="product-gallery">
                    <div class="main-image-container" id="mainImageContainer">
                        <img src="<?php echo getImageUrl($images[0] ?? ''); ?>" 
                             class="main-image" 
                             id="mainImage"
                             alt="<?php echo htmlspecialchars($product['name']); ?>">
                    </div>
                    
                    <?php if (count($images) > 1): ?>
                    <div class="thumbnail-gallery">
                        <?php foreach ($images as $index => $image): ?>
                        <div class="thumbnail-item <?php echo $index === 0 ? 'active' : ''; ?>" 
                             data-image="<?php echo getImageUrl($image); ?>">
                            <img src="<?php echo getImageUrl($image); ?>" 
                                 class="thumbnail-image" 
                                 alt="Thumbnail <?php echo $index + 1; ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Product Info -->
            <div class="col-lg-6">
                <div class="product-info">
                    <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>
                    
                    <?php if ($product['category_name'] && isset($websiteSettings['show_by_category']) && $websiteSettings['show_by_category'] == 1): ?>
                    <p class="product-category">
                        <i class="bi bi-tag"></i>
                        <?php echo htmlspecialchars($product['category_name']); ?>
                    </p>
                    <?php endif; ?>
                    
                    <!-- Stock Status -->
                    <?php if ($websiteSettings['show_stock_quantity']): ?>
                        <?php if ($displayStock > 0): ?>
                            <span class="stock-badge in-stock">
                                <i class="bi bi-check-circle"></i>
                                بەردەستە (<?php echo (int)$displayStock; ?> دانە)
                            </span>
                        <?php else: ?>
                            <span class="stock-badge out-of-stock">
                                <i class="bi bi-x-circle"></i>
                                تەواو بووە
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- Prices -->
                    <?php if ($websiteSettings['show_prices']): ?>
                    <div class="price-box">
                        <?php 
                        // Check if product has discount price
                        $hasDiscount = !empty($product['discount_price']) && $product['discount_price'] > 0;
                        $productCurrency = $product['currency'] ?? 'IQD';
                        ?>

                        <?php if ($websiteSettings['show_retail_price'] && !empty($displayRetail)): ?>
                        <div class="price-item price-item-retail">
                            <span class="price-label">
                                <i class="bi bi-tag-fill"></i>
                                نرخ :
                            </span>
                            <?php if ($hasDiscount): ?>
                                <span class="price-value text-decoration-line-through text-muted"><?php echo formatPrice($displayRetail, $productCurrency); ?></span>
                            <?php else: ?>
                                <span class="price-value"><?php echo formatPrice($displayRetail, $productCurrency); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($hasDiscount): ?>
                        <div class="price-item price-item-discount">
                            <span class="price-label">
                                <i class="bi bi-percent"></i>
                                نرخی داشکاندن:
                            </span>
                            <span class="price-value text-danger fw-bold"><?php echo formatPrice($product['discount_price'], $productCurrency); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($websiteSettings['show_wholesale_price'] && !empty($displayWholesale)): ?>
                        <div class="price-item price-item-wholesale">
                            <span class="price-label">
                                <i class="bi bi-boxes"></i>
                                نرخی جوملە:
                            </span>
                            <span class="price-value"><?php echo formatPrice($displayWholesale, $productCurrency); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($websiteSettings['show_special_price'] && !empty($displaySpecial)): ?>
                        <div class="price-item price-item-special">
                            <span class="price-label">
                                <i class="bi bi-star-fill"></i>
                                نرخی تایبەت:
                            </span>
                            <span class="price-value"><?php echo formatPrice($displaySpecial, $productCurrency); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Units Selector -->
                    <?php if ($hasMultipleUnits): ?>
                    <div class="mb-4">
                        <label class="form-label fw-bold" style="font-size: 1.1rem; color: #1e293b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="bi bi-box-seam-fill" style="color: #0d6efd; font-size: 1.3rem;"></i>
                            یەکە:
                        </label>
                        <div style="position: relative;">
                            <select class="form-select form-select-lg unit-selector" data-product-id="<?php echo $product['id']; ?>" style="border: 2px solid #e9ecef; border-radius: 15px; padding: 14px 20px; font-size: 1.05rem; font-weight: 600; transition: all 0.3s ease; background: white; cursor: pointer; appearance: none; padding-right: 45px;">
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
                            <i class="bi bi-chevron-down" style="position: absolute; left: 20px; top: 50%; transform: translateY(-50%); color: #0d6efd; font-size: 1.2rem; pointer-events: none;"></i>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Add to Cart -->
                    <?php if ($displayStock > 0): ?>
                    <div class="d-grid gap-2 mb-4">
                        <?php
                        // Use discount price if available, otherwise use retail price
                        $finalPrice = (!empty($product['discount_price']) && $product['discount_price'] > 0) 
                            ? $product['discount_price'] 
                            : ($displayRetail ?? 0);
                        ?>
                        <button class="btn btn-primary btn-lg add-to-cart-btn"
                                data-product-id="<?php echo $product['id']; ?>"
                                data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                data-product-price="<?php echo $finalPrice; ?>"
                                data-product-image="<?php echo getImageUrl($images[0] ?? ''); ?>"
                                data-product-unit-id="<?php echo $displayUnit ? $displayUnit['id'] : ''; ?>"
                                data-product-unit="<?php echo $displayUnit ? $displayUnit['unit_name'] : 'دانە'; ?>"
                                data-product-currency="<?php echo htmlspecialchars($product['currency'] ?? 'IQD'); ?>"
                                data-shop-slug="<?php echo htmlspecialchars($slug); ?>"
                                data-stock="<?php echo (int)$displayStock; ?>">
                            <i class="bi bi-cart-plus-fill"></i>
                            زیادکردن بۆ سەبەتە
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="d-grid gap-2 mb-4">
                        <button class="btn btn-secondary btn-lg" disabled style="border-radius: 15px; padding: 15px 30px; font-size: 1.1rem; font-weight: 700;">
                            <i class="bi bi-x-circle-fill"></i>
                            تەواو بووە
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Description -->
                    <?php if (!empty($product['description'])): ?>
                    <div class="description-box">
                        <h5>
                            <i class="bi bi-card-text"></i>
                            وەسفی کاڵا
                        </h5>
                        <div class="description-text">
                            <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Sidebar -->
    <div class="cart-sidebar-overlay"></div>
    <div class="cart-sidebar" style="border-radius: 20px 0 0 20px; box-shadow: -8px 0 30px rgba(0,0,0,0.2);">
        <div class="cart-header">
            <h3 class="cart-title" style="font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="bi bi-cart3" style="font-size: 1.8rem;"></i>
                سەبەتەی کڕین
            </h3>
            <button class="cart-close" style="background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.3); border-radius: 12px; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease;">
                <i class="bi bi-x" style="font-size: 1.8rem;"></i>
            </button>
        </div>
        
        <div class="cart-body">
            <div class="cart-items">
                <!-- Cart items will be populated by JavaScript -->
            </div>
            
            <div class="cart-summary" style="display: none; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 20px; border-radius: 15px; margin: 15px; border: 2px solid #e9ecef;">
                <div class="cart-total" style="display: flex; justify-content: space-between; align-items: center; font-size: 1.3rem; font-weight: 700;">
                    <span style="color: #1e293b;">کۆی گشتی:</span>
                    <span class="cart-total-amount" style="color: #0d6efd;">0 دینار</span>
                </div>
            </div>
            
            <div class="cart-actions" style="padding: 20px; gap: 12px;">
                <div class="cart-actions-primary">
                    <a href="<?php echo SITE_URL; ?>web/checkout.php?shop=<?php echo htmlspecialchars($slug); ?>" class="btn-checkout" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 15px; padding: 15px; font-size: 1.1rem; font-weight: 700; box-shadow: 0 6px 20px rgba(16,185,129,0.3); transition: all 0.3s ease;">
                        <i class="bi bi-credit-card-fill"></i>
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
                <small class="text-warning text-center d-block">
                    <i class="bi bi-exclamation-triangle"></i>
                    واتسئاپ چالاکە بەڵام ژمارەی تەلەفۆنی فرۆشگا دیاری نەکردووە
                </small>
                <?php endif; ?>
                <a href="#" class="btn-continue-shopping" onclick="window.shoppingCart.closeCart(); return false;" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%); border-radius: 15px; padding: 12px; font-size: 1rem; font-weight: 600; box-shadow: 0 4px 15px rgba(108,117,125,0.3); transition: all 0.3s ease;">
                    <i class="bi bi-arrow-left-circle-fill"></i>
                    بەردەوامبوون لە کڕین
                </a>
            </div>
        </div>
    </div>

    <!-- Image Zoom Modal -->
    <div class="image-zoom-modal" id="imageZoomModal">
        <button class="zoom-close" onclick="closeZoom()">&times;</button>
        <img src="" alt="Zoomed Image" id="zoomedImage">
    </div>

    <!-- Similar Products Section -->
    <?php if (!empty($similarProducts)): ?>
    <div class="container py-5" style="max-width: 1400px;">
        <div class="row mb-4">
            <div class="col-12">
                <h3 class="text-center mb-4" style="font-weight: 700; color: #1e293b; display: flex; align-items: center; justify-content: center; gap: 12px;">
                    <i class="bi bi-grid-3x3-gap-fill" style="color: #0d6efd; font-size: 2rem;"></i>
                    کاڵا هاوشێوەکان
                </h3>
            </div>
        </div>
        
        <div class="row products-container">
            <?php foreach ($similarProducts as $similarProduct): 
                $similarProductUnits = getProductUnitsForSimilar($conn, $similarProduct['id']);
                $hasMultipleUnits = count($similarProductUnits) > 1;
                
                // Get display unit
                $displayUnitSimilar = null;
                if (!empty($similarProductUnits)) {
                    foreach ($similarProductUnits as $unit) {
                        if ($unit['is_primary']) {
                            $displayUnitSimilar = $unit;
                            break;
                        }
                    }
                    if ($displayUnitSimilar === null && count($similarProductUnits) > 0) {
                        $displayUnitSimilar = $similarProductUnits[0];
                    }
                }
                
                $displayRetailSimilar = $displayUnitSimilar ? $displayUnitSimilar['sell_price'] : $similarProduct['retail_price'];
                $displayWholesaleSimilar = $displayUnitSimilar ? $displayUnitSimilar['wholesale_price'] : $similarProduct['wholesale_price'];
                $displaySpecialSimilar = $displayUnitSimilar ? $displayUnitSimilar['special_price'] : $similarProduct['special_price'];
                $displayStockSimilar = $displayUnitSimilar ? $displayUnitSimilar['stock_quantity'] : $similarProduct['stock_quantity'];
            ?>
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card product-card h-100 <?php echo $displayStockSimilar <= 0 ? 'out-of-stock' : ''; ?>" style="border-radius: 20px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); transition: all 0.3s ease;">
                    <?php if ($websiteSettings['show_product_images']): ?>
                    <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $similarProduct['id']; ?>" class="text-decoration-none product-image-link">
                        <div class="position-relative product-image-wrapper">
                            <img src="<?php echo getProductImage($similarProduct); ?>" 
                                 class="card-img-top" 
                                 alt="<?php echo htmlspecialchars($similarProduct['name']); ?>"
                                 loading="lazy"
                                 decoding="async"
                                 onerror="if(!this.hasAttribute('data-error-handled')) { this.setAttribute('data-error-handled', 'true'); this.src='<?php echo SITE_URL; ?>web/template/assets/images/no-image.svg'; }">
                            <?php if ($displayStockSimilar <= 0): ?>
                            <div class="position-absolute top-0 end-0 m-2">
                                <span class="badge bg-danger">تەواو بووە</span>
                            </div>
                            <?php endif; ?>
                            <div class="product-overlay">
                                <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $similarProduct['id']; ?>" class="btn btn-light btn-sm">
                                    <i class="bi bi-eye"></i> بینینی وردەکاری
                                </a>
                            </div>
                        </div>
                    </a>
                    <?php endif; ?>
                    
                    <div class="card-body d-flex flex-column" style="padding: 20px;">
                        <h5 class="card-title" style="font-weight: 700; margin-bottom: 12px;">
                            <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $similarProduct['id']; ?>" class="text-decoration-none text-dark">
                                <?php echo htmlspecialchars($similarProduct['name']); ?>
                            </a>
                        </h5>
                        
                        <?php if (!empty($similarProduct['description'])): ?>
                        <p class="card-text text-muted small" style="margin-bottom: 15px;">
                            <?php 
                            $description = strip_tags($similarProduct['description']);
                            echo htmlspecialchars(mb_substr($description, 0, 80)) . (mb_strlen($description) > 80 ? '...' : ''); 
                            ?>
                        </p>
                        <?php endif; ?>
                        
                        <?php if ($hasMultipleUnits): ?>
                        <div class="mb-3">
                            <label class="form-label small text-muted">
                                <i class="bi bi-box-seam"></i> یەکە:
                            </label>
                            <select class="form-select form-select-sm unit-selector-similar" data-product-id="<?php echo $similarProduct['id']; ?>" style="border-radius: 10px;">
                                <?php foreach ($similarProductUnits as $unit): ?>
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
                        
                        <?php if ($websiteSettings['show_prices']): ?>
                        <div class="prices mt-auto" data-prices-container-similar>
                            <?php 
                            // Check if similar product has discount price
                            $hasDiscountSimilar = !empty($similarProduct['discount_price']) && $similarProduct['discount_price'] > 0;
                            ?>
                            
                            <?php $similarCurrency = $similarProduct['currency'] ?? 'IQD'; ?>
                            <?php if ($websiteSettings['show_retail_price'] && !empty($displayRetailSimilar)): ?>
                            <div class="price-item" data-price-type="retail" style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e9ecef;">
                                <span class="price-label" style="font-weight: 600; color: #64748b;">نرخ:</span>
                                <?php if ($hasDiscountSimilar): ?>
                                    <span class="price-value text-decoration-line-through text-muted" style="font-weight: 700;"><?php echo formatPrice($displayRetailSimilar, $similarCurrency); ?></span>
                                <?php else: ?>
                                    <span class="price-value" style="font-weight: 700; color: #1e293b;"><?php echo formatPrice($displayRetailSimilar, $similarCurrency); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($hasDiscountSimilar): ?>
                            <div class="price-item" data-price-type="discount" style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e9ecef;">
                                <span class="price-label" style="font-weight: 600; color: #64748b;">نرخی داشکاندن:</span>
                                <span class="price-value text-danger fw-bold" style="font-weight: 700;"><?php echo formatPrice($similarProduct['discount_price'], $similarCurrency); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($websiteSettings['show_wholesale_price'] && !empty($displayWholesaleSimilar)): ?>
                            <div class="price-item" data-price-type="wholesale" style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e9ecef;">
                                <span class="price-label" style="font-weight: 600; color: #64748b;">نرخی جوملە:</span>
                                <span class="price-value" style="font-weight: 700; color: #1e293b;"><?php echo formatPrice($displayWholesaleSimilar, $similarCurrency); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($websiteSettings['show_special_price'] && !empty($displaySpecialSimilar)): ?>
                            <div class="price-item special" data-price-type="special" style="display: flex; justify-content: space-between; padding: 8px 0;">
                                <span class="price-label" style="font-weight: 600; color: #64748b;">نرخی تایبەت:</span>
                                <span class="price-value" style="font-weight: 700; color: #dc3545;"><?php echo formatPrice($displaySpecialSimilar, $similarCurrency); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($websiteSettings['show_stock_quantity']): ?>
                        <div class="mt-2" data-stock-container-similar>
                            <small class="text-muted">
                                <i class="bi bi-box"></i> بەردەستی: <span class="stock-quantity"><?php echo (int)$displayStockSimilar; ?></span> دانە
                            </small>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($displayStockSimilar > 0): ?>
                        <div class="mt-auto pt-3">
                            <?php
                            // Use discount price if available, otherwise use retail price
                            $basePrice = $displayRetailSimilar ?? $similarProduct['retail_price'] ?? 0;
                            $priceForCartSimilar = (!empty($similarProduct['discount_price']) && $similarProduct['discount_price'] > 0) 
                                ? $similarProduct['discount_price'] 
                                : $basePrice;
                            $unitIdForCartSimilar = $displayUnitSimilar ? $displayUnitSimilar['id'] : '';
                            $unitNameForCartSimilar = $displayUnitSimilar ? $displayUnitSimilar['unit_name'] : 'دانە';
                            ?>
                            <button class="btn btn-primary w-100 add-to-cart-btn"
                                    data-product-id="<?php echo $similarProduct['id']; ?>"
                                    data-product-name="<?php echo htmlspecialchars($similarProduct['name']); ?>"
                                    data-product-price="<?php echo $priceForCartSimilar; ?>"
                                    data-product-image="<?php echo getProductImage($similarProduct); ?>"
                                    data-product-unit-id="<?php echo $unitIdForCartSimilar; ?>"
                                    data-product-unit="<?php echo $unitNameForCartSimilar; ?>"
                                    data-product-currency="<?php echo htmlspecialchars($similarCurrency); ?>"
                                    data-shop-slug="<?php echo htmlspecialchars($slug); ?>"
                                    data-stock="<?php echo (int)$displayStockSimilar; ?>"
                                    style="border-radius: 12px; padding: 10px; font-weight: 600;">
                                <i class="bi bi-cart-plus"></i>
                                زیادکردن بۆ سەبەتە
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="mt-auto pt-3">
                            <button class="btn btn-secondary w-100" disabled style="border-radius: 12px; padding: 10px;">
                                <i class="bi bi-x-circle"></i> تەواو بووە
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="bg-dark text-light py-5 mt-5" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%) !important; box-shadow: 0 -8px 30px rgba(0,0,0,0.2);">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
                    <h5 class="mb-2" style="font-weight: 700; color: #fff;">
                        <i class="bi bi-shop-window" style="color: #0d6efd; font-size: 1.5rem;"></i>
                        <?php echo htmlspecialchars($businessName); ?>
                    </h5>
                    <p class="mb-0" style="color: #94a3b8; font-size: 0.95rem;">
                        <i class="bi bi-shield-check-fill" style="color: #10b981;"></i>
                        پاڵپشتی لەلایەن <?php echo SITE_NAME; ?>
                    </p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <div class="d-flex justify-content-center justify-content-md-end gap-3 flex-wrap">
                        <a href="<?php echo SITE_URL; ?>web/shop.php?slug=<?php echo $slug; ?>" class="btn btn-outline-light btn-sm" style="border-radius: 10px; border-width: 2px; padding: 8px 20px; font-weight: 600;">
                            <i class="bi bi-house-fill"></i>
                            سەرەکی
                        </a>
                        <button onclick="window.print()" class="btn btn-outline-light btn-sm" style="border-radius: 10px; border-width: 2px; padding: 8px 20px; font-weight: 600;">
                            <i class="bi bi-printer-fill"></i>
                            چاپکردن
                        </button>
                        <a href="#" class="btn btn-outline-light btn-sm" style="border-radius: 10px; border-width: 2px; padding: 8px 20px; font-weight: 600;" onclick="window.scrollTo({top: 0, behavior: 'smooth'}); return false;">
                            <i class="bi bi-arrow-up-circle-fill"></i>
                            گەڕانەوە بۆ سەرەوە
                        </a>
                    </div>
                </div>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1); margin: 1.5rem 0;">
            <div class="text-center">
                <p class="mb-0" style="color: #64748b; font-size: 0.9rem;">
                    <i class="bi bi-c-circle"></i>
                    <?php echo date('Y'); ?> - هەموو مافێک پارێزراوە
                </p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/shop.js?v=<?php echo time(); ?>"></script>
    <script>
        window.shopCartWhatsApp = <?php echo json_encode($shopWhatsAppCartConfig, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/cart.js?v=<?php echo time(); ?>"></script>
    
    <script>
        const mainProductCurrency = '<?php echo addslashes($product['currency'] ?? 'IQD'); ?>';
        // Navbar scroll effect
        const navbar = document.getElementById('mainNavbar');
        let lastScroll = 0;
        
        window.addEventListener('scroll', function() {
            const currentScroll = window.pageYOffset;
            
            if (currentScroll > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
            
            lastScroll = currentScroll;
        });
        
        // Hide loading overlay when page is loaded
        window.addEventListener('load', function() {
            const loadingOverlay = document.querySelector('.page-loading');
            if (loadingOverlay) {
                setTimeout(() => {
                    loadingOverlay.classList.add('hidden');
                    document.body.classList.add('loaded');
                }, 300);
            }
        });
        
        // Thumbnail Gallery
        document.querySelectorAll('.thumbnail-item').forEach((item, index) => {
            // Add staggered animation
            item.style.animation = `fadeInUp 0.4s ease-out ${0.1 * index}s both`;
            
            item.addEventListener('click', function() {
                const imageUrl = this.dataset.image;
                const mainImage = document.getElementById('mainImage');
                
                // Add fade effect to main image
                mainImage.style.opacity = '0.5';
                mainImage.style.transform = 'scale(0.95)';
                
                setTimeout(() => {
                    // Update main image
                    mainImage.src = imageUrl;
                    mainImage.style.opacity = '1';
                    mainImage.style.transform = 'scale(1)';
                }, 200);
                
                // Update active state
                document.querySelectorAll('.thumbnail-item').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
            });
        });
        
        // Image Zoom
        const mainImageContainer = document.getElementById('mainImageContainer');
        const imageZoomModal = document.getElementById('imageZoomModal');
        const zoomedImage = document.getElementById('zoomedImage');
        
        mainImageContainer.addEventListener('click', function() {
            const mainImage = document.getElementById('mainImage');
            zoomedImage.src = mainImage.src;
            imageZoomModal.classList.add('active');
        });
        
        imageZoomModal.addEventListener('click', function() {
            closeZoom();
        });
        
        function closeZoom() {
            imageZoomModal.classList.remove('active');
        }
        
        // Unit Selector (if multiple units exist)
        const unitSelector = document.querySelector('.unit-selector');
        if (unitSelector) {
            unitSelector.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const retailPrice = selectedOption.dataset.retailPrice;
                const wholesalePrice = selectedOption.dataset.wholesalePrice;
                const specialPrice = selectedOption.dataset.specialPrice;
                const stock = selectedOption.dataset.stock;
                
                // Add loading animation
                const priceBox = document.querySelector('.price-box');
                if (priceBox) {
                    priceBox.style.opacity = '0.6';
                    priceBox.style.transform = 'scale(0.98)';
                }
                
                setTimeout(() => {
                    // Update prices in the page
                    const priceItems = document.querySelectorAll('.price-item');
                    priceItems.forEach(item => {
                        const label = item.querySelector('.price-label').textContent;
                        const valueSpan = item.querySelector('.price-value');
                        
                        if (label.includes('تاک') && retailPrice) {
                            valueSpan.style.transform = 'scale(1.1)';
                            valueSpan.textContent = formatPrice(retailPrice, mainProductCurrency);
                            setTimeout(() => valueSpan.style.transform = 'scale(1)', 200);
                        } else if (label.includes('جوملە') && wholesalePrice) {
                            valueSpan.style.transform = 'scale(1.1)';
                            valueSpan.textContent = formatPrice(wholesalePrice, mainProductCurrency);
                            setTimeout(() => valueSpan.style.transform = 'scale(1)', 200);
                        } else if (label.includes('تایبەت') && specialPrice) {
                            valueSpan.style.transform = 'scale(1.1)';
                            valueSpan.textContent = formatPrice(specialPrice, mainProductCurrency);
                            setTimeout(() => valueSpan.style.transform = 'scale(1)', 200);
                        }
                    });
                    
                    // Update stock badge
                    const stockBadge = document.querySelector('.stock-badge');
                    if (stockBadge) {
                        stockBadge.style.transform = 'scale(0.9)';
                        setTimeout(() => {
                            if (parseInt(stock) > 0) {
                                stockBadge.className = 'stock-badge in-stock';
                                stockBadge.innerHTML = '<i class="bi bi-check-circle"></i> بەردەستە (' + stock + ' دانە)';
                            } else {
                                stockBadge.className = 'stock-badge out-of-stock';
                                stockBadge.innerHTML = '<i class="bi bi-x-circle"></i> تەواو بووە';
                            }
                            stockBadge.style.transform = 'scale(1)';
                        }, 150);
                    }
                    
                    // Update add to cart button
                    const addToCartBtn = document.querySelector('.add-to-cart-btn');
                    if (addToCartBtn) {
                        addToCartBtn.dataset.productPrice = retailPrice;
                        addToCartBtn.dataset.productUnitId = this.value;
                        addToCartBtn.dataset.productUnit = selectedOption.textContent.trim();
                        addToCartBtn.dataset.stock = stock;
                    }
                    
                    // Remove loading animation
                    if (priceBox) {
                        priceBox.style.opacity = '1';
                        priceBox.style.transform = 'scale(1)';
                    }
                }, 200);
            });
        }
        
        function formatPrice(price, currency) {
            currency = currency || 'IQD';
            const decimals = currency === 'USD' ? 2 : 0;
            const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(parseFloat(price) || 0);
            return formatted + (currency === 'USD' ? ' دۆلار' : ' دینار');
        }
        
        // Add to cart button animation
        const addToCartBtn = document.querySelector('.add-to-cart-btn');
        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', function() {
                // Add success animation
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 200);
            });
        }
        
        // Smooth scroll for breadcrumb links
        document.querySelectorAll('.breadcrumb a').forEach(link => {
            link.addEventListener('click', function(e) {
                if (this.getAttribute('href').startsWith('#')) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth' });
                    }
                }
            });
        });
        
        // Intersection Observer for scroll animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        // Observe elements with delay
        setTimeout(() => {
            document.querySelectorAll('.breadcrumb').forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'all 0.6s ease-out';
                setTimeout(() => observer.observe(el), index * 100);
            });
        }, 400);
        
        // Add parallax effect to main image
        window.addEventListener('scroll', function() {
            const mainImage = document.querySelector('.main-image-container');
            if (mainImage) {
                const scrolled = window.pageYOffset;
                const rate = scrolled * 0.3;
                mainImage.style.transform = `translateY(${rate}px)`;
            }
        });
        
        // Unit Selector for Similar Products
        document.querySelectorAll('.unit-selector-similar').forEach(selector => {
            selector.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const retailPrice = selectedOption.dataset.retailPrice;
                const wholesalePrice = selectedOption.dataset.wholesalePrice;
                const specialPrice = selectedOption.dataset.specialPrice;
                const stock = selectedOption.dataset.stock;
                const productCard = this.closest('.product-card');
                const pricesContainer = productCard.querySelector('[data-prices-container-similar]');
                const stockContainer = productCard.querySelector('[data-stock-container-similar]');
                const addToCartBtn = productCard.querySelector('.add-to-cart-btn');
                
                // Update prices
                if (pricesContainer) {
                    const similarCurrency = addToCartBtn && addToCartBtn.dataset.productCurrency ? addToCartBtn.dataset.productCurrency : 'IQD';
                    pricesContainer.style.opacity = '0.6';
                    setTimeout(() => {
                        const priceItems = pricesContainer.querySelectorAll('.price-item');
                        priceItems.forEach(item => {
                            const label = item.querySelector('.price-label').textContent;
                            const valueSpan = item.querySelector('.price-value');
                            
                            if (label.includes('نرخ') && retailPrice) {
                                valueSpan.textContent = formatPrice(retailPrice, similarCurrency);
                            } else if (label.includes('جوملە') && wholesalePrice) {
                                valueSpan.textContent = formatPrice(wholesalePrice, similarCurrency);
                            } else if (label.includes('تایبەت') && specialPrice) {
                                valueSpan.textContent = formatPrice(specialPrice, similarCurrency);
                            }
                        });
                        pricesContainer.style.opacity = '1';
                    }, 200);
                }
                
                // Update stock
                if (stockContainer) {
                    const stockQuantity = stockContainer.querySelector('.stock-quantity');
                    if (stockQuantity) {
                        stockQuantity.textContent = parseInt(stock) || 0;
                    }
                }
                
                // Update add to cart button
                if (addToCartBtn) {
                    addToCartBtn.dataset.productPrice = retailPrice;
                    addToCartBtn.dataset.productUnitId = this.value;
                    addToCartBtn.dataset.productUnit = selectedOption.textContent.trim();
                    addToCartBtn.dataset.stock = stock;
                    
                    // Enable/disable button based on stock
                    if (parseInt(stock) > 0) {
                        addToCartBtn.disabled = false;
                        addToCartBtn.className = 'btn btn-primary w-100 add-to-cart-btn';
                    } else {
                        addToCartBtn.disabled = true;
                        addToCartBtn.className = 'btn btn-secondary w-100';
                        addToCartBtn.innerHTML = '<i class="bi bi-x-circle"></i> تەواو بووە';
                    }
                }
            });
        });
    </script>

</body>
</html>

