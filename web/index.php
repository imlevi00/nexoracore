<?php
/**
 * لاپەڕەی سەرەکی وێب سایت - web/index.php
 */

require_once '../config/config.php';
require_once 'auth/session_helper.php';

function resolveShopBannerUrl(?string $bannerValue): string
{
    $bannerValue = trim((string)$bannerValue);
    if ($bannerValue === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $bannerValue)) {
        return $bannerValue;
    }

    $normalized = ltrim($bannerValue, '/');
    if (strpos($normalized, 'img/shop_banner/') !== 0) {
        if (strpos($normalized, 'shop_banner/') === 0) {
            $normalized = 'img/' . $normalized;
        } elseif (strpos($normalized, '/') === false) {
            $normalized = 'img/shop_banner/' . $normalized;
        } else {
            return '';
        }
    }

    $url = spaces_public_url_for_object_key($normalized);
    return $url ?? '';
}

// ========================================
// سیستەمی ژمارەی سەردانیکەران
// ========================================

// دروستکردنی خشتەی سەردانیکەران ئەگەر نەبوو
$conn->query("CREATE TABLE IF NOT EXISTS web_visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NOT NULL,
    visit_count INT DEFAULT 1,
    first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_visit DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_visitor (ip_address, user_agent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// وەرگرتنی IP ی سەردانیکەر
$visitorIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// ئەگەر چەند IP هەبوو، یەکەمیان وەربگرە
if (strpos($visitorIP, ',') !== false) {
    $visitorIP = trim(explode(',', $visitorIP)[0]);
}

// وەرگرتنی User Agent
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$userAgent = substr($userAgent, 0, 500); // سنووردارکردن بۆ 500 پیت

// تۆمارکردنی سەردانیکەر - INSERT ON DUPLICATE KEY UPDATE
$stmtVisitor = $conn->prepare("INSERT INTO web_visitors (ip_address, user_agent, visit_count, first_visit, last_visit) 
                               VALUES (?, ?, 1, NOW(), NOW()) 
                               ON DUPLICATE KEY UPDATE last_visit = NOW(), visit_count = visit_count + 1");
$stmtVisitor->bind_param("ss", $visitorIP, $userAgent);
$stmtVisitor->execute();
$stmtVisitor->close();

// وەرگرتنی کۆی گشتی سەردانیکەران (یەکتایی)
$totalVisitorsResult = $conn->query("SELECT COUNT(*) as total FROM web_visitors");
$totalVisitors = $totalVisitorsResult->fetch_assoc()['total'];
$totalVisitorsResult->close();

// ========================================
// کۆتایی سیستەمی سەردانیکەران
// ========================================

// وەرگرتنی لیستی هەموو فرۆشگاکانی چالاک
// تاقیکردنی ئەوەی ستونی show_on_index هەیە یان نا
$checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_on_index'");
$hasShowOnIndexColumn = $checkColumnStmt->num_rows > 0;
$checkColumnStmt->close();

if ($hasShowOnIndexColumn) {
    // ئەگەر ستونی show_on_index هەبێت، تەنها ئەوانەی show_on_index = 1 نیشان بدرێن
    $query = "SELECT ws.id, ws.website_slug, u.business_name, ws.created_at, ws.shop_banner
              FROM website_settings ws
              INNER JOIN users u ON ws.user_id = u.id
              WHERE ws.is_active = 1 AND ws.show_on_index = 1
              ORDER BY CASE WHEN ws.id = 4 THEN 0 ELSE 1 END, ws.created_at DESC";
} else {
    // ئەگەر ستونی show_on_index نەبێت، هەموو ئەوانەی is_active = 1 نیشان بدرێن (دەستپێک)
    $query = "SELECT ws.id, ws.website_slug, u.business_name, ws.created_at, ws.shop_banner
              FROM website_settings ws
              INNER JOIN users u ON ws.user_id = u.id
              WHERE ws.is_active = 1
              ORDER BY CASE WHEN ws.id = 4 THEN 0 ELSE 1 END, ws.created_at DESC";
}

$result = $conn->query($query);
$shops = $result->fetch_all(MYSQLI_ASSOC);

// ========================================
// وەرگرتنی 75 کاڵای هەڕەمەکی لە فرۆشگا جیاوازەکان
// ========================================

// وەرگرتنی 75 کاڵای هەڕەمەکی (تەنها ئەوانەی وێنەیان هەیە)
if ($hasShowOnIndexColumn) {
    // ئەگەر ستونی show_on_index هەبێت، تەنها کاڵاکانی فرۆشگاکانی show_on_index = 1 نیشان بدرێن
    $randomProductsQuery = "
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
        ORDER BY RAND()
        LIMIT 75
    ";
} else {
    // ئەگەر ستونی show_on_index نەبێت، هەموو کاڵاکانی فرۆشگاکانی is_active = 1 نیشان بدرێن (دەستپێک)
    $randomProductsQuery = "
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
        ORDER BY RAND()
        LIMIT 75
    ";
}

$randomProductsResult = $conn->query($randomProductsQuery);
$randomProducts = $randomProductsResult ? $randomProductsResult->fetch_all(MYSQLI_ASSOC) : [];

// وەرگرتنی کۆی گشتی کاڵاکان (بە هەمان شەرتەکان)
if ($hasShowOnIndexColumn) {
    // ئەگەر ستونی show_on_index هەبێت، تەنها کاڵاکانی فرۆشگاکانی show_on_index = 1 نیشان بدرێن
    $totalProductsCountQuery = "
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
    ";
} else {
    // ئەگەر ستونی show_on_index نەبێت، هەموو کاڵاکانی فرۆشگاکانی is_active = 1 نیشان بدرێن (دەستپێک)
    $totalProductsCountQuery = "
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
    ";
}

$totalProductsCountResult = $conn->query($totalProductsCountQuery);
$totalProductsCount = $totalProductsCountResult ? $totalProductsCountResult->fetch_assoc()['total'] : 0;
if ($totalProductsCountResult) {
    $totalProductsCountResult->close();
}

// Helper function to get product image
function getProductImageIndex($product) {
    if (!empty($product['image_path'])) {
        $u = product_image_url($product['image_path']);
        if ($u) {
            return $u;
        }
    }
    return SITE_URL . 'web/template/assets/images/no-image.svg';
}

// Helper function to format price by currency (IQD = دینار, USD = دۆلار)
function formatPriceIndex($price, $currency = 'IQD') {
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
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <meta name="description" content="فرۆشگاکانی چالاکی ئۆنلاین لە سیستەمەکەماندا">
    <meta name="theme-color" content="#1e40af">
    <title>فرۆشگا ئۆنلاینەکان - <?php echo SITE_NAME; ?></title>
    
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
        /* View Mode Toggle Styles */
        .view-mode-toggle {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 2rem;
        }

        .toggle-container {
            display: inline-flex;
            background: #f8f9fa;
            border-radius: 50px;
            padding: 6px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            gap: 4px;
        }

        .toggle-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            border: none;
            background: transparent;
            color: #6c757d;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .toggle-btn i {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .toggle-btn:hover {
            color: #495057;
            transform: translateY(-2px);
        }

        .toggle-btn.active {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }

        .toggle-btn.active i {
            transform: scale(1.1);
        }

        /* Product Card Styles for Index */
        .product-item-card {
            animation: fadeInUp 0.6s ease;
            animation-fill-mode: both;
        }

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

        .product-card {
            border: none;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }

        .product-image-wrapper {
            height: 280px;
            overflow: hidden;
            background: #f8f9fa;
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .product-card:hover .product-image {
            transform: scale(1.1);
        }

        .product-overlay-index {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, transparent 100%);
            padding: 15px;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }

        .product-card:hover .product-overlay-index {
            transform: translateY(0);
        }

        .shop-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: rgba(255, 255, 255, 0.95);
            color: #0d6efd;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .product-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            min-height: 2.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .price-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .price-item:last-child {
            border-bottom: none;
        }

        .price-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .price-value {
            color: #0d6efd;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .hidden-product {
            display: none !important;
        }

        /* Load More Products Button */
        .load-more-products-btn {
            position: relative;
            padding: 14px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            border: none;
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.3);
            transition: all 0.3s ease;
        }

        .load-more-products-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(13, 110, 253, 0.4);
            background: linear-gradient(135deg, #0a58ca 0%, #084298 100%);
        }

        .load-more-products-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .load-more-products-btn i {
            font-size: 1.3rem;
            margin-right: 8px;
        }

        #loadMoreProductsContainer {
            animation: fadeInUp 0.6s ease;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .toggle-btn {
                padding: 10px 20px;
                font-size: 0.9rem;
            }

            .toggle-btn span {
                display: none;
            }

            .toggle-btn i {
                font-size: 1.3rem;
            }

            .product-image-wrapper {
                height: 220px;
            }
        }

        @media (max-width: 576px) {
            .toggle-container {
                padding: 4px;
            }

            .toggle-btn {
                padding: 8px 16px;
            }
        }

        /* Copy Link Button - Inline Styles */
        .btn-copy-link {
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            padding: 8px 14px !important;
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.1) 100%) !important;
            border: 1px solid rgba(255,255,255,0.3) !important;
            border-radius: 50px !important;
            color: white !important;
            font-size: 0.85rem !important;
            font-weight: 500 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            backdrop-filter: blur(10px) !important;
            -webkit-backdrop-filter: blur(10px) !important;
            text-decoration: none !important;
            white-space: nowrap !important;
        }

        .btn-copy-link:hover {
            background: linear-gradient(135deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.2) 100%) !important;
            border-color: rgba(255,255,255,0.5) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2) !important;
            color: white !important;
        }

        .btn-copy-link:active {
            transform: translateY(0) !important;
        }

        .btn-copy-link i {
            font-size: 1.1rem !important;
            transition: transform 0.15s ease !important;
        }

        .btn-copy-link:hover i {
            transform: rotate(15deg) !important;
        }

        .btn-copy-link.copied {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%) !important;
            border-color: #059669 !important;
            animation: copyPulse 0.5s ease !important;
        }

        .btn-copy-link.copied i::before {
            content: "\F26B" !important; /* bi-check-lg */
        }

        @keyframes copyPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        /* Discount Button Special Style */
        .btn-discount {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%) !important;
            border-color: rgba(220, 38, 38, 0.5) !important;
            animation: discountPulse 2s infinite !important;
        }
        
        .btn-discount:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%) !important;
            border-color: rgba(220, 38, 38, 0.8) !important;
            box-shadow: 0 4px 20px rgba(220, 38, 38, 0.4) !important;
        }
        
        .btn-discount i {
            animation: wiggle 1s ease-in-out infinite !important;
        }
        
        @keyframes discountPulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7);
            }
            50% {
                box-shadow: 0 0 0 8px rgba(220, 38, 38, 0);
            }
        }
        
        @keyframes wiggle {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(-10deg); }
            75% { transform: rotate(10deg); }
        }

        /* Copy Toast Notification */
        .copy-toast {
            position: fixed !important;
            bottom: 30px !important;
            left: 50% !important;
            transform: translateX(-50%) translateY(100px) !important;
            padding: 12px 24px !important;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%) !important;
            color: white !important;
            border-radius: 10px !important;
            font-size: 0.9rem !important;
            font-weight: 500 !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
            z-index: 9999 !important;
            opacity: 0 !important;
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55) !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }

        .copy-toast.show {
            opacity: 1 !important;
            transform: translateX(-50%) translateY(0) !important;
        }

        .copy-toast i {
            color: #10b981 !important;
            font-size: 1.2rem !important;
        }

        /* Cart Button Styles */
        .cart-button {
            position: relative;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 50px;
            font-size: 1.3rem;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .cart-button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 0.2rem 0.4rem;
            font-size: 0.7rem;
            font-weight: bold;
            min-width: 20px;
            text-align: center;
        }

        /* Responsive for mobile */
        @media (max-width: 768px) {
            #mainNavbar {
                padding: 0.4rem 0 !important;
            }
            
            #mainNavbar .container {
                padding: 0 0.75rem !important;
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
            }
            
            .navbar-text {
                font-size: 0.85rem !important;
                margin: 0 !important;
            }
            
            .btn-copy-link {
                padding: 4px 8px !important;
            }
            
            .btn-copy-text {
                display: none !important;
            }
            
            .cart-button {
                padding: 0.35rem 0.6rem !important;
                font-size: 1.2rem !important;
            }
            
            .cart-badge {
                font-size: 0.65rem !important;
                padding: 0.15rem 0.35rem !important;
            }
            
            .dropdown .btn {
                padding: 0.35rem 0.6rem !important;
                font-size: 0.8rem !important;
            }
        }
        
        @media (max-width: 576px) {
            .back-text,
            .account-text,
            .user-name-text {
                display: none !important;
            }
            
            .navbar-text {
                font-size: 0.75rem !important;
                max-width: 120px !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                white-space: nowrap !important;
            }
        }

        /* نافیگیشنی خوارەوە وەک بەرنامەی مۆبایل */
        .video-bottom-nav {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1100;
            display: none;
            align-items: center;
            justify-content: space-between;
            padding: 0.35rem 1.5rem calc(env(safe-area-inset-bottom, 0px) + 0.45rem);
            background: linear-gradient(to top, rgba(0, 0, 0, 0.96), rgba(0, 0, 0, 0.88));
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        .video-bottom-nav-btn {
            flex: 1 1 0;
            border: none;
            background: transparent;
            color: #e5e7eb;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.15rem;
            font-size: 0.78rem;
            text-decoration: none;
            cursor: pointer;
            padding: 0.1rem 0.25rem;
        }
        .video-bottom-nav-btn i {
            font-size: 1.4rem;
            line-height: 1;
            text-shadow: 0 4px 10px rgba(0, 0, 0, 0.85);
            transition: transform 0.12s ease, color 0.15s ease;
        }
        .video-bottom-nav-btn span {
            font-weight: 500;
            letter-spacing: 0.01em;
        }
        .video-bottom-nav-btn.is-active {
            color: #ffffff;
        }
        .video-bottom-nav-btn.is-active i {
            color: #38bdf8;
        }
        .video-bottom-nav-btn:active i,
        .video-bottom-nav-btn:active span {
            transform: scale(0.96);
        }
        .video-bottom-nav .cart-button:hover {
            background: rgba(229, 231, 235, 0.12);
            border-radius: 0.5rem;
        }
        .video-bottom-nav .cart-button:active {
            background: rgba(229, 231, 235, 0.28);
            border-radius: 0.5rem;
        }
        .video-bottom-nav .cart-button .video-bottom-nav-cart-icon-wrap {
            position: relative;
            display: inline-flex;
        }
        @media (max-width: 767.98px) {
            .video-bottom-nav {
                display: flex;
            }
        }

        html[data-bs-theme='dark'] .toggle-container {
            background: #1f2937;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
        }

        html[data-bs-theme='dark'] .toggle-btn {
            color: #d1d5db;
        }

        html[data-bs-theme='dark'] .toggle-btn:hover {
            color: #f3f4f6;
        }

        html[data-bs-theme='dark'] .product-image-wrapper {
            background: #111827;
        }

        html[data-bs-theme='dark'] .price-item {
            border-bottom-color: #374151;
        }

        html[data-bs-theme='dark'] .price-label {
            color: #9ca3af;
        }

        /* Inline quantity widget (هاوشێوەی shop.php) */
        .shop-qty-widget {
            display: flex;
            flex-direction: row;
            align-items: stretch;
            gap: 0.35rem;
            width: 100%;
            box-sizing: border-box;
        }

        .shop-qty-widget.shop-qty-widget--disabled {
            opacity: 0.65;
            pointer-events: none;
        }

        .shop-qty-widget .shop-qty-dec,
        .shop-qty-widget .shop-qty-inc {
            flex: 0 0 auto;
            min-width: 2.35rem;
            padding-inline: 0.4rem;
            color: var(--bs-secondary-color);
            border-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.14);
            background-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.065);
            transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .shop-qty-widget .shop-qty-dec:hover:not(:disabled),
        .shop-qty-widget .shop-qty-inc:hover:not(:disabled) {
            background-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.12);
            border-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.22);
            color: var(--bs-emphasis-color);
        }

        html[data-bs-theme='dark'] .shop-qty-widget .shop-qty-dec,
        html[data-bs-theme='dark'] .shop-qty-widget .shop-qty-inc {
            background-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.1);
            border-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.22);
        }

        html[data-bs-theme='dark'] .shop-qty-widget .shop-qty-dec:hover:not(:disabled),
        html[data-bs-theme='dark'] .shop-qty-widget .shop-qty-inc:hover:not(:disabled) {
            background-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.16);
            border-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.3);
        }

        .shop-qty-widget .shop-qty-dec:focus-visible,
        .shop-qty-widget .shop-qty-inc:focus-visible {
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(var(--bs-primary-rgb, 13, 110, 253), 0.25);
        }

        .shop-qty-widget .shop-qty-input {
            flex: 1 1 auto;
            min-width: 0;
            max-width: 5.5rem;
            margin-inline: auto;
            text-align: center;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            background-color: var(--bs-body-bg);
            color: var(--bs-body-color);
            border-color: var(--bs-border-color);
            min-height: calc(1.5em + 0.75rem + 2px);
        }

        .shop-qty-widget .shop-qty-input:focus {
            border-color: var(--bs-primary);
            box-shadow: 0 0 0 0.2rem rgba(var(--bs-primary-rgb, 13, 110, 253), 0.25);
            background-color: var(--bs-body-bg);
            color: var(--bs-body-color);
        }

        .shop-qty-widget .shop-qty-dec:disabled,
        .shop-qty-widget .shop-qty-inc:disabled,
        .shop-qty-widget .shop-qty-input:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
    </style>
</head>
<body class="web-index-page">

    <!-- Skip to main content for accessibility -->
    <a href="#main-content" class="skip-to-main">بچۆ بۆ ناوەڕۆک</a>

    <!-- Navigation - Enhanced -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top" id="mainNavbar" role="navigation" aria-label="ناوچەی گەشت" style="background: rgba(30, 64, 175, 0.85) !important;">
        <div class="container">
            <!-- Left Side: Cart Button -->
            <div class="d-flex align-items-center">
                <button class="cart-button" title="سەبەتەی کڕین">
                    <i class="bi bi-cart3"></i>
                    <span class="cart-badge" style="display: none;">0</span>
                </button>
            </div>
            
            <!-- Center: Site Info & Actions -->
            <div class="d-flex align-items-center gap-2 flex-grow-1 justify-content-center">
                <span class="navbar-text text-center">
                    <i class="bi bi-shop"></i>
                    فرۆشگا ئۆنلاینەکان
                </span>
                
                <!-- Discounted Products Button -->
                <a href="<?php echo SITE_URL; ?>web/discounted-products.php" class="btn-copy-link btn-discount" title="کاڵا داشکاندراوەکان">
                    <i class="bi bi-tags-fill"></i>
                    <span class="btn-copy-text">داشکاندن</span>
                </a>
                
                <!-- Copy Link Button -->
                <button class="btn-copy-link" id="copyShopLinkBtn" title="کۆپی کردنی لینکی لاپەڕە">
                    <i class="bi bi-link-45deg"></i>
                    <span class="btn-copy-text">کۆپی لینک</span>
                </button>
                
                <!-- QR Code Button -->
                <button class="btn-copy-link" id="qrCodeBtn" title="پیشاندانی QR Code" data-bs-toggle="modal" data-bs-target="#qrCodeModal">
                    <i class="bi bi-qr-code"></i>
                    <span class="btn-copy-text">QR Code</span>
                </button>
            </div>
            
            <!-- Right Side: User Menu -->
            <div class="navbar-nav d-flex align-items-center gap-2">
                <!-- Customer Menu -->
                <?php if (CustomerSession::isLoggedIn()): ?>
                    <?php $customerData = CustomerSession::getCustomerData(); ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i>
                            <span class="user-name-text"><?php echo htmlspecialchars($customerData['name']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>web/my-orders.php">
                                <i class="bi bi-list-ul"></i> داواکارییەکانم
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>web/auth/logout.php">
                                <i class="bi bi-box-arrow-right"></i> دەرچوون
                            </a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person"></i>
                            <span class="account-text">هەژمار</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>web/auth/login.php?redirect=index.php">
                                <i class="bi bi-box-arrow-in-right"></i> چوونەژوورەوە
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>web/auth/register.php">
                                <i class="bi bi-person-plus"></i> تۆمارکردن
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>web/my-orders-guest.php">
                                <i class="bi bi-list-ul"></i> داواکارییەکانی میوان
                            </a></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <main id="main-content">
    <div class="container py-3">
        
        <!-- Breadcrumb Navigation -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>">سەرەکی</a></li>
                <li class="breadcrumb-item active" aria-current="page" id="breadcrumbText">کاڵاکان</li>
            </ol>
        </nav>

        <!-- Discount Banner -->
        <?php
        // وەرگرتنی ژمارەی کاڵا داشکاندراوەکان
        if ($hasShowOnIndexColumn) {
            // ئەگەر ستونی show_on_index هەبێت، تەنها کاڵاکانی فرۆشگاکانی show_on_index = 1 نیشان بدرێن
            $discountCountQuery = "
                SELECT COUNT(DISTINCT p.id) as total
                FROM products p
                INNER JOIN users u ON p.user_id = u.id
                INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1 AND ws.show_on_index = 1
                INNER JOIN product_details pd ON p.id = pd.product_id
                LEFT JOIN product_units pu ON p.id = pu.product_id 
                    AND (pu.is_primary = 1 
                         OR (pu.is_primary = 0 AND NOT EXISTS (
                             SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                         ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
                WHERE pd.discount_price IS NOT NULL 
                      AND pd.discount_price > 0
                     AND pd.discount_price < COALESCE(pu.sell_price, 0)
                     AND pu.stock_quantity > 0
            ";
        } else {
            // ئەگەر ستونی show_on_index نەبێت، هەموو کاڵاکانی فرۆشگاکانی is_active = 1 نیشان بدرێن (دەستپێک)
            $discountCountQuery = "
                SELECT COUNT(DISTINCT p.id) as total
                FROM products p
                INNER JOIN users u ON p.user_id = u.id
                INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1
                INNER JOIN product_details pd ON p.id = pd.product_id
                LEFT JOIN product_units pu ON p.id = pu.product_id 
                    AND (pu.is_primary = 1 
                         OR (pu.is_primary = 0 AND NOT EXISTS (
                             SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                         ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
                WHERE pd.discount_price IS NOT NULL 
                      AND pd.discount_price > 0
                     AND pd.discount_price < COALESCE(pu.sell_price, 0)
                     AND pu.stock_quantity > 0
            ";
        }
        $discountCountResult = $conn->query($discountCountQuery);
        $discountCount = $discountCountResult ? $discountCountResult->fetch_assoc()['total'] : 0;
        ?>
        
        <?php if ($discountCount > 0): ?>
        <div class="discount-promo-banner mb-4">
            <a href="<?php echo SITE_URL; ?>web/discounted-products.php" class="text-decoration-none">
                <div class="promo-content">
                    <div class="promo-icon">
                        <i class="bi bi-fire"></i>
                    </div>
                    <div class="promo-text">
                        <h3>داشکاندنی تایبەت!</h3>
                        <p><?php echo $discountCount; ?> کاڵای داشکاندراو بەردەستە - کلیک بکە بۆ بینین</p>
                    </div>
                    <div class="promo-arrow">
                        <i class="bi bi-arrow-left"></i>
                    </div>
                </div>
            </a>
        </div>
        
        <style>
            .discount-promo-banner {
                background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
                border-radius: 20px;
                padding: 1.5rem;
                box-shadow: 0 8px 30px rgba(220, 38, 38, 0.3);
                overflow: hidden;
                position: relative;
                animation: bannerSlideIn 0.6s ease;
            }
            
            .discount-promo-banner::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -10%;
                width: 200px;
                height: 200px;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 50%;
                animation: float 6s ease-in-out infinite;
            }
            
            .discount-promo-banner::after {
                content: '';
                position: absolute;
                bottom: -30%;
                left: -5%;
                width: 150px;
                height: 150px;
                background: rgba(255, 255, 255, 0.08);
                border-radius: 50%;
                animation: float 8s ease-in-out infinite reverse;
            }
            
            @keyframes float {
                0%, 100% { transform: translateY(0) translateX(0); }
                50% { transform: translateY(-20px) translateX(10px); }
            }
            
            @keyframes bannerSlideIn {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .promo-content {
                display: flex;
                align-items: center;
                gap: 1.5rem;
                position: relative;
                z-index: 1;
            }
            
            .promo-icon {
                font-size: 3rem;
                color: white;
                animation: iconPulse 2s ease-in-out infinite;
            }
            
            @keyframes iconPulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.1); }
            }
            
            .promo-text {
                flex: 1;
                color: white;
            }
            
            .promo-text h3 {
                font-size: 1.5rem;
                font-weight: 800;
                margin: 0 0 0.25rem 0;
                text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
            }
            
            .promo-text p {
                margin: 0;
                font-size: 1rem;
                opacity: 0.95;
            }
            
            .promo-arrow {
                font-size: 2rem;
                color: white;
                animation: arrowBounce 1s ease-in-out infinite;
            }
            
            @keyframes arrowBounce {
                0%, 100% { transform: translateX(0); }
                50% { transform: translateX(-10px); }
            }
            
            .discount-promo-banner:hover {
                transform: translateY(-5px);
                box-shadow: 0 12px 40px rgba(220, 38, 38, 0.4);
                transition: all 0.3s ease;
            }
            
            @media (max-width: 768px) {
                .discount-promo-banner {
                    padding: 1rem;
                }
                
                .promo-content {
                    gap: 1rem;
                }
                
                .promo-icon {
                    font-size: 2rem;
                }
                
                .promo-text h3 {
                    font-size: 1.2rem;
                }
                
                .promo-text p {
                    font-size: 0.9rem;
                }
                
                .promo-arrow {
                    font-size: 1.5rem;
                }
            }
        </style>
        <?php endif; ?>

        <!-- View Mode Toggle -->
        <div class="view-mode-toggle mb-4">
            <div class="toggle-container">
                <button class="toggle-btn active" data-mode="products" id="productsToggle">
                    <i class="bi bi-box-seam"></i>
                    <span>کاڵاکان</span>
                </button>
                <button class="toggle-btn" data-mode="shops" id="shopsToggle">
                    <i class="bi bi-shop"></i>
                    <span>فرۆشگاکان</span>
                </button>
            </div>
        </div>

        <!-- Search Section - Ultra Professional Design -->
        <div class="search-filter-section" role="search">
            <div class="row align-items-center g-4">
                <div class="col-lg-8 col-md-12">
                    <div class="search-box">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-search" aria-hidden="true"></i>
                            </span>
                            <input type="search" 
                                   class="form-control" 
                                   id="shopSearch" 
                                   placeholder="گەڕان بەدوای فرۆشگادا... (Ctrl+K)"
                                   aria-label="گەڕان بەدوای فرۆشگا"
                                   autocomplete="off">
                            <button class="btn btn-outline-secondary search-clear-btn" 
                                    type="button" 
                                    id="clearSearch"
                                    aria-label="پاککردنەوەی گەڕان"
                                    title="پاککردنەوە">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <button class="btn btn-primary" 
                                    type="button" 
                                    aria-label="گەڕان"
                                    title="گەڕان">
                                <i class="bi bi-search me-1"></i>
                                <span class="d-none d-sm-inline">گەڕان</span>
                            </button>
                        </div>
                        <!-- Search Suggestions Dropdown -->
                        <div class="search-suggestions" id="searchSuggestions" role="listbox" aria-label="پێشنیارەکانی گەڕان"></div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-12">
                    <div class="shop-stats d-flex justify-content-lg-end justify-content-center flex-wrap gap-3">
                        <div class="stat-item" title="ژمارەی سەردانیکەران">
                            <i class="bi bi-people-fill text-info" aria-hidden="true"></i>
                            <div>
                                <span class="fw-bold" data-count="<?php echo $totalVisitors; ?>">0</span>
                                <span class="text-muted d-block">سەردانیکەر</span>
                            </div>
                        </div>
                        <div class="stat-item" id="mainStatItem" title="کۆی گشتی">
                            <i class="bi bi-box-seam text-primary stat-icon" aria-hidden="true"></i>
                            <div>
                                <span class="fw-bold stat-count" data-count="<?php echo $totalProductsCount; ?>" data-shops-count="<?php echo count($shops); ?>" data-products-count="<?php echo $totalProductsCount; ?>">0</span>
                                <span class="text-muted d-block stat-label">کاڵا</span>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <!-- Search Results Counter -->
            <div class="mt-3 text-center">
                <span class="search-results-counter" id="searchResultsCounter" style="display: none;" role="status" aria-live="polite"></span>
            </div>
        </div>

        <!-- Products Grid (Default View) -->
        <div id="productsSection" class="products-view-section">
            <div class="row g-4" id="productsGrid">
                <?php if (empty($randomProducts)): ?>
                    <div class="col-12">
                        <div class="empty-state-card text-center">
                            <div class="empty-icon mb-4">
                                <i class="bi bi-box-seam" aria-hidden="true"></i>
                            </div>
                            <h4 class="mb-3">هیچ کاڵایەک نەدۆزرایەوە</h4>
                            <p class="text-muted mb-4">کاڵاکان بە زوویی زیاد دەکرێن</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php 
                    $productIndex = 0;
                    foreach ($randomProducts as $product): 
                        $productIndex++;
                    ?>
                    <div class="col-lg-4 col-md-6 product-item-card <?php echo $productIndex > 75 ? 'hidden-product' : ''; ?>" 
                         data-product-id="<?php echo $product['id']; ?>"
                         data-product-name="<?php echo strtolower($product['name']); ?>"
                         style="animation-delay: <?php echo ($productIndex % 6) * 0.1; ?>s;">
                        <article class="card product-card h-100 hover-lift">
                            <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $product['website_slug']; ?>&id=<?php echo $product['id']; ?>" class="text-decoration-none product-image-link">
                                <div class="position-relative product-image-wrapper">
                                    <img src="<?php echo getProductImageIndex($product); ?>" 
                                         class="card-img-top product-image" 
                                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                                         loading="lazy"
                                         decoding="async"
                                         onerror="if(!this.hasAttribute('data-error-handled')) { this.setAttribute('data-error-handled', 'true'); this.src='<?php echo SITE_URL; ?>web/template/assets/images/no-image.svg'; }">
                                    <?php if ($product['stock_quantity'] <= 0): ?>
                                    <div class="position-absolute top-0 end-0 m-2">
                                        <span class="badge bg-danger">تەواو بووە</span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="product-overlay-index">
                                        <span class="shop-badge">
                                            <i class="bi bi-shop"></i>
                                            <?php echo htmlspecialchars($product['shop_name']); ?>
                                        </span>
                                    </div>
                                </div>
                            </a>
                            
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title product-title">
                                    <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $product['website_slug']; ?>&id=<?php echo $product['id']; ?>" class="text-decoration-none text-dark">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </a>
                                </h5>
                                
                                <?php if (!empty($product['category_name']) && isset($product['show_by_category']) && $product['show_by_category'] == 1): ?>
                                <p class="card-text text-muted small mb-2">
                                    <i class="bi bi-tag"></i>
                                    <?php echo htmlspecialchars($product['category_name']); ?>
                                </p>
                                <?php endif; ?>
                                
                                <div class="prices mt-auto">
                                    <?php 
                                    // Check if product has discount price
                                    $hasDiscount = !empty($product['discount_price']) && $product['discount_price'] > 0;
                                    ?>
                                    
                                    <div class="price-item">
                                        <span class="price-label">نرخ:</span>
                                        <?php if ($hasDiscount): ?>
                                            <span class="price-value text-decoration-line-through text-muted"><?php echo formatPriceIndex($product['retail_price'], $product['currency'] ?? 'IQD'); ?></span>
                                        <?php else: ?>
                                            <span class="price-value"><?php echo formatPriceIndex($product['retail_price'], $product['currency'] ?? 'IQD'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($hasDiscount): ?>
                                    <div class="price-item special">
                                        <span class="price-label">نرخی داشکاندن:</span>
                                        <span class="price-value text-danger fw-bold"><?php echo formatPriceIndex($product['discount_price'], $product['currency'] ?? 'IQD'); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($product['show_stock_quantity']) && $product['stock_quantity'] > 0): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="bi bi-box"></i>
                                        بەردەستی: <span class="stock-quantity"><?php echo (int)$product['stock_quantity']; ?></span> دانە
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Quantity widget (هاوشێوەی shop.php) -->
                                <?php if ($product['stock_quantity'] > 0): ?>
                                <div class="mt-3">
                                    <?php
                                    // Use discount price if available, otherwise use retail price
                                    $finalPrice = (!empty($product['discount_price']) && $product['discount_price'] > 0)
                                        ? $product['discount_price']
                                        : $product['retail_price'];
                                    $unitIdIndex = isset($product['unit_id']) ? (string) $product['unit_id'] : '';
                                    $unitNameIndex = $product['unit_name'] ?? 'دانە';
                                    ?>
                                    <div class="shop-qty-widget w-100"
                                         data-shop-qty-widget
                                         data-product-id="<?php echo (int) $product['id']; ?>"
                                         data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                         data-product-price="<?php echo htmlspecialchars((string) $finalPrice); ?>"
                                         data-product-image="<?php echo htmlspecialchars(getProductImageIndex($product)); ?>"
                                         data-product-unit-id="<?php echo htmlspecialchars($unitIdIndex); ?>"
                                         data-product-unit="<?php echo htmlspecialchars($unitNameIndex); ?>"
                                         data-product-currency="<?php echo htmlspecialchars($product['currency'] ?? 'IQD'); ?>"
                                         data-shop-slug="<?php echo htmlspecialchars($product['website_slug']); ?>"
                                         data-stock="<?php echo (int) $product['stock_quantity']; ?>"
                                         data-retail-price="<?php echo htmlspecialchars((string) ($product['retail_price'] ?? 0)); ?>"
                                         data-wholesale-price="0"
                                         data-special-price="0"
                                         data-discount-price="<?php echo htmlspecialchars((string) ($product['discount_price'] ?? 0)); ?>"
                                         data-show-retail="1"
                                         data-show-wholesale="0"
                                         data-show-special="0">
                                        <button type="button" class="btn btn-outline-secondary shop-qty-dec" aria-label="کەمکردنەوەی بڕ">
                                            <i class="bi bi-dash-lg" aria-hidden="true"></i>
                                        </button>
                                        <input type="number"
                                               class="form-control shop-qty-input"
                                               value="0"
                                               min="0"
                                               step="1"
                                               inputmode="numeric"
                                               autocomplete="off"
                                               aria-label="بڕی سەبەتە">
                                        <button type="button" class="btn btn-outline-secondary shop-qty-inc" aria-label="زیادکردنی بڕ">
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div> 
            
            <!-- Load More Button for Products -->
            <div class="text-center mt-4 mb-5" id="loadMoreProductsContainer">
                <button class="btn btn-primary btn-lg load-more-products-btn" id="loadMoreProductsBtn">
                    <i class="bi bi-arrow-down-circle"></i>
                    <span class="btn-text">بینینی کاڵای زیاتر</span>
                </button>
             
            </div>
        </div>

        <!-- Shops Grid -->
        <div id="shopsSection" class="shops-view-section" style="display: none;">
        <div class="row g-4" id="shopsGrid">
            <?php if (empty($shops)): ?>
                <div class="col-12">
                    <div class="empty-state-card text-center">
                        <div class="empty-icon mb-4">
                            <i class="bi bi-shop" aria-hidden="true"></i>
                        </div>
                        <h4 class="mb-3">هیچ فرۆشگایەک نەدۆزرایەوە</h4>
                        <p class="text-muted mb-4">فرۆشگاکان بە زوویی زیاد دەکرێن</p>
                        <a href="<?php echo SITE_URL; ?>" class="btn btn-primary">
                            <i class="bi bi-arrow-right" aria-hidden="true"></i> گەڕانەوە بۆ سەرەکی
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($shops as $index => $shop): ?>
                    <div class="col-lg-4 col-md-6 shop-item" 
                         data-shop-name="<?php echo strtolower($shop['business_name']); ?>"
                         style="animation-delay: <?php echo ($index % 6) * 0.1; ?>s;">
                        <article class="card shop-card h-100 hover-lift">
                            <?php if (!empty($shop['shop_banner'])): ?>
                                <!-- بانەری فرۆشگا - Enhanced -->
                                <div class="shop-banner-wrapper position-relative">
                                    <span class="shop-status-badge">
                                        <i class="bi bi-check-circle" aria-hidden="true"></i> چالاک
                                    </span>
                                    <img src="<?php echo htmlspecialchars(resolveShopBannerUrl($shop['shop_banner'])); ?>" 
                                         class="w-100 h-100" 
                                         style="object-fit: cover; object-position: center;"
                                         alt="<?php echo htmlspecialchars($shop['business_name']); ?>"
                                         loading="lazy"
                                         onerror="if(!this.hasAttribute('data-error-handled')) { this.setAttribute('data-error-handled', 'true'); this.style.display='none'; this.parentElement.innerHTML='<div class=\'d-flex align-items-center justify-content-center h-100 bg-light\'><i class=\'bi bi-shop display-1 text-muted\'></i></div>'; }">
                                    <div class="position-absolute bottom-0 start-0 w-100 p-3">
                                        <h5 class="card-title text-white mb-0 fw-bold">
                                            <?php echo htmlspecialchars($shop['business_name']); ?>
                                        </h5>
                                    </div>
                                </div>
                                <div class="card-body text-center">
                                    <a href="<?php echo SITE_URL; ?>web/<?php echo $shop['website_slug']; ?>/" 
                                       class="btn btn-primary btn-lg w-100"
                                       aria-label="چوونە ناو فرۆشگای <?php echo htmlspecialchars($shop['business_name']); ?>">
                                        <i class="bi bi-box-arrow-up-left" aria-hidden="true"></i> چوونە ناو فرۆشگا
                                    </a>
                                </div>
                            <?php else: ?>
                                <!-- دیزاینی کلاسیکی بەبێ بانەر - Enhanced -->
                                <div class="card-body text-center">
                                    <div class="shop-icon mb-3">
                                        <i class="bi bi-shop display-1" aria-hidden="true"></i>
                                    </div>
                                    <h5 class="card-title mb-3"><?php echo htmlspecialchars($shop['business_name']); ?></h5>
                                    <a href="<?php echo SITE_URL; ?>web/<?php echo $shop['website_slug']; ?>/" 
                                       class="btn btn-primary btn-lg w-100"
                                       aria-label="چوونە ناو فرۆشگای <?php echo htmlspecialchars($shop['business_name']); ?>">
                                        <i class="bi bi-box-arrow-up-left" aria-hidden="true"></i> چوونە ناو فرۆشگا
                                    </a>
                                </div>
                            <?php endif; ?>
                        </article>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- No Results Message - Enhanced -->
        <div id="noResults" class="col-12 text-center py-5" style="display: none;" role="alert" aria-live="polite">
            <div class="empty-state-card">
                <div class="empty-icon mb-4">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
                <h4 class="mb-3">هیچ ئەنجامێک نەدۆزرایەوە</h4>
                <p class="text-muted mb-4">تکایە بژاردێکی تر تاقی بکەرەوە</p>
                <button type="button" class="btn btn-outline-primary" id="clearSearchBtn">
                    <i class="bi bi-x-circle" aria-hidden="true"></i> پاککردنەوەی گەڕان
                </button>
            </div>
        </div>
        </div>
        <!-- End of Shops Section -->

        <!-- Random Products Section for Shops Mode -->
        <div id="randomProductsSection" style="display: none;">
            <h3 class="mb-4 mt-5 text-center">
                <i class="bi bi-box-seam"></i>
                کاڵا جیاوازەکان لە فرۆشگا جیاوازەکان
            </h3>
            <div class="row g-4" id="randomProductsGrid">
                <!-- Random products will be shown here in shops mode -->
            </div>
            
            <!-- Load More Button for Random Products in Shops Mode -->
            <div class="text-center mt-4 mb-5" id="loadMoreRandomProductsContainer">
                <button class="btn btn-primary btn-lg load-more-products-btn" id="loadMoreRandomProductsBtn">
                    <i class="bi bi-arrow-down-circle"></i>
                    <span class="btn-text">بینینی کاڵای زیاتر</span>
                </button>
         
            </div>
        </div>
    </div>
    </main>

    <!-- QR Code Modal -->
    <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="qrCodeModalLabel">
                        <i class="bi bi-qr-code"></i>
                        QR Code ی لاپەڕە
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <!-- Site Name -->
                    <h4 class="mb-3 fw-bold text-primary">
                        <i class="bi bi-shop"></i>
                        فرۆشگا ئۆنلاینەکان
                    </h4>
                    
                    <p class="text-muted mb-4">ئەم QR Code ە سکان بکە بۆ گەیشتن بە لاپەڕە</p>
                    
                    <!-- QR Code Container -->
                    <div id="qrCodeContainer" class="d-flex justify-content-center align-items-center mx-auto" style="min-height: 300px; max-width: 300px;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">درووستکردنی QR Code...</span>
                        </div>
                    </div>
                    
                    <!-- System Name -->
                    <div class="mt-4 pt-3 border-top">
                        <p class="mb-1 text-muted small">پێشکەشکراو لەلایەن</p>
                        <h6 class="fw-bold text-dark mb-2">
                            <i class="bi bi-calculator"></i>
                            سیستمی NexoraCore
                        </h6>
                    </div>
                </div>
                <div class="modal-footer justify-content-center border-0 pt-0">
                    <button type="button" class="btn btn-primary px-4" id="downloadQrBtn">
                        <i class="bi bi-download"></i>
                        داگرتنی QR Code
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i>
                        داخستن
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast for copy notification -->
    <div class="copy-toast" id="copyToast">
        <i class="bi bi-check-circle-fill"></i>
        <span>لینکی لاپەڕە کۆپی کرا!</span>
    </div>

    <!-- نافیگیشنی خوارەوە وەک بەرنامەی مۆبایل -->
    <nav class="video-bottom-nav d-md-none">
        <a href="<?php echo SITE_URL; ?>videos/index.php"
           class="video-bottom-nav-btn"
           aria-label="ڤیدیۆکان">
            <i class="bi bi-collection-play-fill"></i>
            <span>ڤیدیۆکان</span>
        </a>

        <button type="button"
                class="video-bottom-nav-btn cart-button"
                aria-label="سەبەتەی کڕین">
            <span class="video-bottom-nav-cart-icon-wrap">
                <i class="bi bi-cart3"></i>
                <span class="cart-badge" style="display: none;">0</span>
            </span>
            <span>سەبەتە</span>
        </button>

        <a href="<?php echo SITE_URL; ?>web/index.php"
           class="video-bottom-nav-btn is-active"
           aria-label="فرۆشگا ئۆنلاینەکان">
            <i class="bi bi-shop"></i>
            <span>فرۆشگا ئۆنلاینەکان</span>
        </a>
    </nav>

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
                <a href="<?php echo SITE_URL; ?>web/checkout.php" class="btn-checkout">
                    <i class="bi bi-credit-card"></i>
                    تەواوکردنی داواکاری
                </a>
                <?php if (!CustomerSession::isLoggedIn()): ?>
                <div class="text-center mt-2">
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i>
                        دەتوانیت بەبێ چوونەژوورەوە کڕین بکەیت
                    </small>
                </div>
                <?php endif; ?>
                <a href="#" class="btn-continue-shopping" onclick="window.shoppingCart.closeCart(); return false;">
                    <i class="bi bi-arrow-left"></i>
                    بەردەوامبوون لە کڕین
                </a>
            </div>
        </div>
    </div>

    <!-- Footer - Enhanced -->
    <footer class="bg-dark text-light mt-5" role="contentinfo">
        <div class="container">
            <div class="row">
                <div class="col-md-4 footer-section">
                    <h5>
                        <i class="bi bi-shop" aria-hidden="true"></i>
                        دەربارە
                    </h5>
                    <p>سیستەمی فرۆشگا ئۆنلاینەکان بۆ پێشکەشکردنی خزمەتگوزاری باشتر</p>
                </div>
                <div class="col-md-4 footer-section">
                    <h5>
                        <i class="bi bi-link-45deg" aria-hidden="true"></i>
                        بەستەرەکان
                    </h5>
                    <a href="<?php echo SITE_URL; ?>">سەرەکی</a>
                    <a href="<?php echo SITE_URL; ?>web/shop.php">فرۆشگاکان</a>
                </div>
                <div class="col-md-4 footer-section">
                    <h5>
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        پەیوەندی
                    </h5>
                    <p>پاڵپشتی لەلایەن <?php echo SITE_NAME; ?></p>
                    <div class="social-links">
                        <a href="#" aria-label="فیسبووک"><i class="bi bi-facebook" aria-hidden="true"></i></a>
                        <a href="#" aria-label="تویتەر"><i class="bi bi-twitter" aria-hidden="true"></i></a>
                        <a href="#" aria-label="ئینستاگرام"><i class="bi bi-instagram" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
            <div class="copyright">
                <p class="mb-0">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. هەموو مافێک پارێزراوە.
                </p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/shop.js?v=<?php echo time(); ?>"></script>
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/cart.js?v=<?php echo time(); ?>"></script>
    <script>
        // Copy Link Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const copyBtn = document.getElementById('copyShopLinkBtn');
            const copyToast = document.getElementById('copyToast');
            const pageUrl = window.location.href;
            
            if (copyBtn) {
                copyBtn.addEventListener('click', async function() {
                    try {
                        await navigator.clipboard.writeText(pageUrl);
                        
                        // Visual feedback
                        copyBtn.classList.add('copied');
                        
                        // Show toast
                        copyToast.classList.add('show');
                        
                        // Reset after 2 seconds
                        setTimeout(() => {
                            copyBtn.classList.remove('copied');
                            copyToast.classList.remove('show');
                        }, 2000);
                        
                    } catch (err) {
                        // Fallback for older browsers
                        const textArea = document.createElement('textarea');
                        textArea.value = pageUrl;
                        textArea.style.position = 'fixed';
                        textArea.style.left = '-9999px';
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        
                        try {
                            document.execCommand('copy');
                            copyBtn.classList.add('copied');
                            copyToast.classList.add('show');
                            setTimeout(() => {
                                copyBtn.classList.remove('copied');
                                copyToast.classList.remove('show');
                            }, 2000);
                        } catch (e) {
                            alert('لینک: ' + pageUrl);
                        }
                        
                        document.body.removeChild(textArea);
                    }
                });
            }
            
            // QR Code Functionality
            let qrCodeGenerated = false;
            let qrCodeInstance = null;
            
            const qrModal = document.getElementById('qrCodeModal');
            if (qrModal) {
                qrModal.addEventListener('show.bs.modal', function() {
                    if (!qrCodeGenerated) {
                        const container = document.getElementById('qrCodeContainer');
                        container.innerHTML = ''; // Clear loading spinner
                        
                        try {
                            // Generate QR Code
                            qrCodeInstance = new QRCode(container, {
                                text: pageUrl,
                                width: 300,
                                height: 300,
                                colorDark: "#000000",
                                colorLight: "#ffffff",
                                correctLevel: QRCode.CorrectLevel.H
                            });
                            
                            qrCodeGenerated = true;
                        } catch (error) {
                            container.innerHTML = '<div class="alert alert-danger">هەڵەیەک ڕوویدا لە درووستکردنی QR Code</div>';
                        }
                    }
                });
            }
            
            // Download QR Code
            const downloadBtn = document.getElementById('downloadQrBtn');
            if (downloadBtn) {
                downloadBtn.addEventListener('click', function() {
                    try {
                        const canvas = document.querySelector('#qrCodeContainer canvas');
                        if (canvas) {
                            // Convert canvas to blob for better browser support
                            canvas.toBlob(function(blob) {
                                if (blob) {
                                    const url = URL.createObjectURL(blob);
                                    const link = document.createElement('a');
                                    link.download = 'qrcode-index.png';
                                    link.href = url;
                                    document.body.appendChild(link);
                                    link.click();
                                    document.body.removeChild(link);
                                    
                                    // Clean up
                                    setTimeout(() => URL.revokeObjectURL(url), 100);
                                    
                                    // Show success feedback
                                    const originalText = downloadBtn.innerHTML;
                                    downloadBtn.innerHTML = '<i class="bi bi-check-circle"></i> داگیرا!';
                                    downloadBtn.classList.add('btn-success');
                                    downloadBtn.classList.remove('btn-primary');
                                    
                                    setTimeout(() => {
                                        downloadBtn.innerHTML = originalText;
                                        downloadBtn.classList.add('btn-primary');
                                        downloadBtn.classList.remove('btn-success');
                                    }, 2000);
                                } else {
                                    // Fallback to toDataURL
                                    const link = document.createElement('a');
                                    link.download = 'qrcode-index.png';
                                    link.href = canvas.toDataURL('image/png');
                                    document.body.appendChild(link);
                                    link.click();
                                    document.body.removeChild(link);
                                }
                            }, 'image/png');
                        } else {
                            alert('QR Code هێشتا درووست نەکراوە. تکایە چاوەڕێ بکە.');
                        }
                    } catch (error) {
                        console.error('Download error:', error);
                        alert('هەڵەیەک ڕوویدا لە داگرتنی QR Code');
                    }
                });
            }
        });
    </script>
    <script>
        // Enhanced Shop search functionality with autocomplete
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('shopSearch');
            const clearSearchBtn = document.getElementById('clearSearch');
            const clearSearchBtnFooter = document.getElementById('clearSearchBtn');
            const searchSuggestions = document.getElementById('searchSuggestions');
            const searchResultsCounter = document.getElementById('searchResultsCounter');
            const shopsGrid = document.getElementById('shopsGrid');
            const noResults = document.getElementById('noResults');
            const navbar = document.getElementById('mainNavbar');
            
            // Get all shop names for autocomplete
            const shopItems = document.querySelectorAll('.shop-item');
            const shopNames = Array.from(shopItems).map(item => ({
                name: item.getAttribute('data-shop-name'),
                element: item
            }));
            
            let searchTimeout;
            let currentSuggestions = [];
            
            // Navbar scroll effect
            if (navbar) {
                let lastScrollTop = 0;
                window.addEventListener('scroll', function() {
                    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                    
                    if (scrollTop > 50) {
                        navbar.classList.add('scrolled');
                    } else {
                        navbar.classList.remove('scrolled');
                    }
                    
                    lastScrollTop = scrollTop;
                }, { passive: true });
            }
            
            // Show/hide clear button
            function toggleClearButton() {
                if (clearSearchBtn) {
                    if (searchInput.value.trim().length > 0) {
                        clearSearchBtn.classList.add('show');
                    } else {
                        clearSearchBtn.classList.remove('show');
                    }
                }
            }
            
            // Generate search suggestions
            function generateSuggestions(term) {
                if (!term || term.length < 2) {
                    searchSuggestions.classList.remove('show');
                    return;
                }
                
                const matches = shopNames.filter(shop => 
                    shop.name.includes(term.toLowerCase())
                ).slice(0, 5);
                
                if (matches.length > 0) {
                    currentSuggestions = matches;
                    searchSuggestions.innerHTML = matches.map((shop, index) => {
                        const displayName = shop.element.querySelector('.card-title')?.textContent || shop.name;
                        return `
                            <div class="search-suggestion-item" 
                                 role="option" 
                                 tabindex="0"
                                 data-index="${index}"
                                 aria-label="${displayName}">
                                <i class="bi bi-shop me-2" aria-hidden="true"></i>
                                ${displayName}
                            </div>
                        `;
                    }).join('');
                    
                    // Add click handlers
                    searchSuggestions.querySelectorAll('.search-suggestion-item').forEach(item => {
                        item.addEventListener('click', function() {
                            const index = parseInt(this.getAttribute('data-index'));
                            selectSuggestion(currentSuggestions[index]);
                        });
                        
                        item.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                const index = parseInt(this.getAttribute('data-index'));
                                selectSuggestion(currentSuggestions[index]);
                            }
                        });
                    });
                    
                    searchSuggestions.classList.add('show');
                } else {
                    searchSuggestions.classList.remove('show');
                }
            }
            
            // Select a suggestion
            function selectSuggestion(shop) {
                searchInput.value = shop.element.querySelector('.card-title')?.textContent || shop.name;
                searchSuggestions.classList.remove('show');
                performSearch(searchInput.value);
                searchInput.focus();
            }
            
            // Perform search
            function performSearch(term) {
                const searchTerm = term.toLowerCase().trim();
                let visibleCount = 0;
                
                shopItems.forEach((item, index) => {
                    const shopName = item.getAttribute('data-shop-name');
                    if (!searchTerm || shopName.includes(searchTerm)) {
                        item.style.display = 'block';
                        // Stagger animation
                        setTimeout(() => {
                            item.style.opacity = '1';
                            item.style.transform = 'translateY(0)';
                        }, index * 30);
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                        item.style.opacity = '0';
                        item.style.transform = 'translateY(20px)';
                    }
                });
                
                // Update results counter
                if (searchTerm && searchResultsCounter) {
                    searchResultsCounter.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${visibleCount} لە ${shopItems.length} فرۆشگا دەردەکەون`;
                    searchResultsCounter.style.display = 'inline-flex';
                } else if (searchResultsCounter) {
                    searchResultsCounter.style.display = 'none';
                }
                
                // Show/hide no results
                if (noResults) {
                    if (visibleCount === 0 && searchTerm) {
                        noResults.style.display = 'block';
                        shopsGrid.style.display = 'none';
                    } else {
                        noResults.style.display = 'none';
                        shopsGrid.style.display = 'flex';
                    }
                }
                
                toggleClearButton();
            }
            
            // Search input handler
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const term = this.value;
                    toggleClearButton();
                    
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        generateSuggestions(term);
                        performSearch(term);
                    }, 300);
                });
                
                searchInput.addEventListener('focus', function() {
                    if (this.value.trim().length >= 2) {
                        generateSuggestions(this.value);
                    }
                });
                
                // Close suggestions when clicking outside
                document.addEventListener('click', function(e) {
                    if (!searchInput.contains(e.target) && !searchSuggestions.contains(e.target)) {
                        searchSuggestions.classList.remove('show');
                    }
                });
                
                // Keyboard shortcuts
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        this.value = '';
                        searchSuggestions.classList.remove('show');
                        performSearch('');
                        this.blur();
                    } else if (e.key === 'ArrowDown' && searchSuggestions.classList.contains('show')) {
                        e.preventDefault();
                        const firstSuggestion = searchSuggestions.querySelector('.search-suggestion-item');
                        if (firstSuggestion) firstSuggestion.focus();
                    }
                });
            }
            
            // Clear search button
            function clearSearch() {
                if (searchInput) {
                    searchInput.value = '';
                    searchSuggestions.classList.remove('show');
                    performSearch('');
                    // Trigger input event to reset products view if in products mode
                    searchInput.dispatchEvent(new Event('input', { bubbles: true }));
                    searchInput.focus();
                }
            }
            
            if (clearSearchBtn) {
                clearSearchBtn.addEventListener('click', clearSearch);
            }
            
            if (clearSearchBtnFooter) {
                clearSearchBtnFooter.addEventListener('click', clearSearch);
            }
            
            // Keyboard shortcut: Ctrl+K to focus search
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
            });
            
            // Initialize page load animation
            shopItems.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(30px)';
                item.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            });
            
            // Trigger animation after a short delay
            setTimeout(() => {
                shopItems.forEach((item, index) => {
                    setTimeout(() => {
                        item.style.opacity = '1';
                        item.style.transform = 'translateY(0)';
                    }, index * 50);
                });
            }, 100);
            
            // Animate stat counters
            function animateCounter(element) {
                const target = parseInt(element.getAttribute('data-count'));
                const duration = 1500;
                const increment = target / (duration / 16);
                let current = 0;
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        element.textContent = target;
                        clearInterval(timer);
                    } else {
                        element.textContent = Math.floor(current);
                    }
                }, 16);
            }
            
            // Start counter animation after page load
            setTimeout(() => {
                document.querySelectorAll('.stat-item .fw-bold[data-count]').forEach(animateCounter);
            }, 300);
            
            // Add ripple effect to search button
            const searchBtn = document.querySelector('.search-box .btn-primary');
            if (searchBtn) {
                searchBtn.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    ripple.classList.add('ripple');
                    
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => ripple.remove(), 600);
                });
            }
        });
    </script>

    <script>
        // View Mode Toggle - Switch between Products and Shops
        document.addEventListener('DOMContentLoaded', function() {
            const productsToggle = document.getElementById('productsToggle');
            const shopsToggle = document.getElementById('shopsToggle');
            const productsSection = document.getElementById('productsSection');
            const shopsSection = document.getElementById('shopsSection');
            const randomProductsSection = document.getElementById('randomProductsSection');
            const breadcrumbText = document.getElementById('breadcrumbText');
            const searchInput = document.getElementById('shopSearch');
            const mainStatItem = document.getElementById('mainStatItem');
            const statIcon = mainStatItem.querySelector('.stat-icon');
            const statCount = mainStatItem.querySelector('.stat-count');
            const statLabel = mainStatItem.querySelector('.stat-label');
            
            let currentMode = 'products'; // default mode
            
            // Products count
            const productsCount = parseInt(statCount.getAttribute('data-products-count')) || 0;
            const shopsCount = parseInt(statCount.getAttribute('data-shops-count')) || 0;
            
            // Search-related variables (accessible throughout this scope)
            let originalProductsHTML = null;
            let isInSearchMode = false;
            let currentSearchQuery = '';
            let searchOffset = 0;
            let searchTotal = 0;
            let isSearchingProducts = false;
            
            // Store original products HTML on page load
            const productsGridForSearch = document.getElementById('productsGrid');
            if (productsGridForSearch && !originalProductsHTML) {
                originalProductsHTML = productsGridForSearch.innerHTML;
            }
            
            // Function to reset products view to original (accessible throughout)
            function resetProductsView() {
                const productsGrid = document.getElementById('productsGrid');
                const searchResultsCounter = document.getElementById('searchResultsCounter');
                const loadMoreContainer = document.getElementById('loadMoreProductsContainer');
                const loadMoreBtn = document.getElementById('loadMoreProductsBtn');
                
                if (originalProductsHTML && productsGrid) {
                    productsGrid.innerHTML = originalProductsHTML;
                    isInSearchMode = false;
                    // Reinitialize cart buttons
                    if (window.shoppingCart && typeof window.shoppingCart.initCartButtons === 'function') {
                        window.shoppingCart.initCartButtons();
                    }
                }
                
                if (searchResultsCounter) {
                    searchResultsCounter.style.display = 'none';
                }
                
                // Reset load more button to original state
                if (loadMoreContainer && loadMoreBtn) {
                    loadMoreContainer.style.display = 'block';
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i><span class="btn-text">بینینی کاڵای زیاتر</span>';
                }
                
                currentSearchQuery = '';
                searchOffset = 0;
                searchTotal = 0;
                isSearchingProducts = false;
            }
            
            function switchToProducts() {
                currentMode = 'products';
                
                // Update toggle buttons
                productsToggle.classList.add('active');
                shopsToggle.classList.remove('active');
                
                // Show/Hide sections
                productsSection.style.display = 'block';
                shopsSection.style.display = 'none';
                randomProductsSection.style.display = 'none';
                
                // Update breadcrumb
                breadcrumbText.textContent = 'کاڵاکان';
                
                // Update search placeholder
                searchInput.placeholder = 'گەڕان بەدوای کاڵادا لە گشت فرۆشگاکان...';
                
                // Update stats
                statIcon.className = 'bi bi-box-seam text-primary stat-icon';
                statLabel.textContent = 'کاڵا';
                
                // Animate counter
                animateCounter(statCount, productsCount);
                
                // Clear search and reset view
                searchInput.value = '';
                if (typeof resetProductsView === 'function') {
                    resetProductsView();
                } else {
                    const searchResultsCounter = document.getElementById('searchResultsCounter');
                    if (searchResultsCounter) {
                        searchResultsCounter.style.display = 'none';
                    }
                    // Reset product items visibility
                    document.querySelectorAll('.product-item-card').forEach(item => {
                        item.style.display = 'block';
                    });
                }
            }
            
            function switchToShops() {
                currentMode = 'shops';
                
                // Update toggle buttons
                shopsToggle.classList.add('active');
                productsToggle.classList.remove('active');
                
                // Show/Hide sections
                productsSection.style.display = 'none';
                shopsSection.style.display = 'block';
                randomProductsSection.style.display = 'block';
                
                // Update breadcrumb
                breadcrumbText.textContent = 'فرۆشگاکان';
                
                // Update search placeholder
                searchInput.placeholder = 'گەڕان بەدوای فرۆشگادا...';
                
                // Update stats
                statIcon.className = 'bi bi-shop text-primary stat-icon';
                statLabel.textContent = 'فرۆشگا';
                
                // Animate counter
                animateCounter(statCount, shopsCount);
                
                // Clear search
                searchInput.value = '';
                const searchResultsCounter = document.getElementById('searchResultsCounter');
                if (searchResultsCounter) {
                    searchResultsCounter.style.display = 'none';
                }
                
                // Reset shop items visibility
                document.querySelectorAll('.shop-item').forEach(item => {
                    item.style.display = 'block';
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                });
                
                // Load random products in shops mode
                loadRandomProductsForShopsMode();
            }
            
            function animateCounter(element, target) {
                const duration = 800;
                const increment = target / (duration / 16);
                let current = 0;
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        element.textContent = target;
                        clearInterval(timer);
                    } else {
                        element.textContent = Math.floor(current);
                    }
                }, 16);
            }
            
            function loadRandomProductsForShopsMode() {
                const randomProductsGrid = document.getElementById('randomProductsGrid');
                const productsGrid = document.getElementById('productsGrid');
                
                // Copy products HTML to random products section if not already loaded
                if (productsGrid && randomProductsGrid && randomProductsGrid.children.length === 0) {
                    // Clone the products with animation
                    const products = productsGrid.querySelectorAll('.product-item-card');
                    products.forEach((product, index) => {
                        const clone = product.cloneNode(true);
                        clone.style.animationDelay = (index % 6) * 0.1 + 's';
                        randomProductsGrid.appendChild(clone);
                    });
                }
            }
            
            // Event listeners
            if (productsToggle) {
                productsToggle.addEventListener('click', function() {
                    if (currentMode !== 'products') {
                        switchToProducts();
                    }
                });
            }
            
            if (shopsToggle) {
                shopsToggle.addEventListener('click', function() {
                    if (currentMode !== 'shops') {
                        switchToShops();
                    }
                });
            }
            
            // Initialize with products mode (default)
            switchToProducts();
            
            // Load More Products Functionality
            const loadMoreProductsBtn = document.getElementById('loadMoreProductsBtn');
            let isLoading = false;
            let currentOffset = 0;
            
            if (loadMoreProductsBtn) {
                loadMoreProductsBtn.addEventListener('click', function() {
                    // If in search mode, let the search handler take care of it
                    if (isInSearchMode && currentSearchQuery && currentSearchQuery.length >= 2) {
                        return;
                    }
                    if (isLoading) return;
                    
                    isLoading = true;
                    loadMoreProductsBtn.disabled = true;
                    loadMoreProductsBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>چاوەڕوانبە...';
                    
                    // AJAX request to get more products
                    fetch('<?php echo SITE_URL; ?>web/get-random-products.php?offset=' + currentOffset)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.html) {
                                const productsGrid = document.getElementById('productsGrid');
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = data.html;
                                
                                // Append new products with animation
                                let delay = 0;
                                tempDiv.querySelectorAll('.product-item-card').forEach((item, index) => {
                                    item.style.opacity = '0';
                                    item.style.transform = 'translateY(30px)';
                                    productsGrid.appendChild(item);
                                    
                                    setTimeout(() => {
                                        item.style.transition = 'all 0.6s ease';
                                        item.style.opacity = '1';
                                        item.style.transform = 'translateY(0)';
                                    }, delay);
                                    delay += 50;
                                });
                                
                                currentOffset += data.count;
                                
                                // Reset button
                                setTimeout(() => {
                                    loadMoreProductsBtn.disabled = false;
                                    loadMoreProductsBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i><span class="btn-text">بینینی کاڵای زیاتر</span>';
                                    isLoading = false;
                                }, delay);
                                
                                // Scroll to first new product
                                setTimeout(() => {
                                    const firstNewProduct = tempDiv.querySelector('.product-item-card');
                                    if (firstNewProduct) {
                                        firstNewProduct.scrollIntoView({ 
                                            behavior: 'smooth', 
                                            block: 'center' 
                                        });
                                    }
                                }, delay + 100);
                            } else {
                                loadMoreProductsBtn.innerHTML = '<i class="bi bi-info-circle"></i> کاڵای تر نییە';
                                loadMoreProductsBtn.disabled = true;
                                isLoading = false;
                            }
                        })
                        .catch(error => {
                            loadMoreProductsBtn.innerHTML = '<i class="bi bi-exclamation-circle"></i> هەڵەیەک ڕوویدا';
                            setTimeout(() => {
                                loadMoreProductsBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i><span class="btn-text">بینینی کاڵای زیاتر</span>';
                                loadMoreProductsBtn.disabled = false;
                                isLoading = false;
                            }, 2000);
                        });
                });
            }
            
            // Load More Random Products for Shops Mode
            const loadMoreRandomProductsBtn = document.getElementById('loadMoreRandomProductsBtn');
            let isLoadingRandom = false;
            let currentRandomOffset = 0;
            
            if (loadMoreRandomProductsBtn) {
                loadMoreRandomProductsBtn.addEventListener('click', function() {
                    if (isLoadingRandom) return;
                    
                    isLoadingRandom = true;
                    loadMoreRandomProductsBtn.disabled = true;
                    loadMoreRandomProductsBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>چاوەڕوانبە...';
                    
                    // AJAX request to get more products
                    fetch('<?php echo SITE_URL; ?>web/get-random-products.php?offset=' + currentRandomOffset)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.html) {
                                const randomProductsGrid = document.getElementById('randomProductsGrid');
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = data.html;
                                
                                // Append new products with animation
                                let delay = 0;
                                tempDiv.querySelectorAll('.product-item-card').forEach((item, index) => {
                                    item.style.opacity = '0';
                                    item.style.transform = 'translateY(30px)';
                                    randomProductsGrid.appendChild(item);
                                    
                                    setTimeout(() => {
                                        item.style.transition = 'all 0.6s ease';
                                        item.style.opacity = '1';
                                        item.style.transform = 'translateY(0)';
                                    }, delay);
                                    delay += 50;
                                });
                                
                                currentRandomOffset += data.count;
                                
                                // Reset button
                                setTimeout(() => {
                                    loadMoreRandomProductsBtn.disabled = false;
                                    loadMoreRandomProductsBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i><span class="btn-text">بینینی کاڵای زیاتر</span>';
                                    isLoadingRandom = false;
                                }, delay);
                                
                                // Scroll to first new product
                                setTimeout(() => {
                                    const firstNewProduct = tempDiv.querySelector('.product-item-card');
                                    if (firstNewProduct) {
                                        firstNewProduct.scrollIntoView({ 
                                            behavior: 'smooth', 
                                            block: 'center' 
                                        });
                                    }
                                }, delay + 100);
                            } else {
                                loadMoreRandomProductsBtn.innerHTML = '<i class="bi bi-info-circle"></i> کاڵای تر نییە';
                                loadMoreRandomProductsBtn.disabled = true;
                                isLoadingRandom = false;
                            }
                        })
                        .catch(error => {
                            loadMoreRandomProductsBtn.innerHTML = '<i class="bi bi-exclamation-circle"></i> هەڵەیەک ڕوویدا';
                            setTimeout(() => {
                                loadMoreRandomProductsBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i><span class="btn-text">بینینی کاڵای زیاتر</span>';
                                loadMoreRandomProductsBtn.disabled = false;
                                isLoadingRandom = false;
                            }, 2000);
                        });
                });
            }
            
            // Update search functionality to work with current mode
            if (searchInput) {
                // Store the original shop search handler
                const originalShopSearch = function(searchTerm) {
                    const shopItems = document.querySelectorAll('.shop-item');
                    let visibleCount = 0;
                    
                    shopItems.forEach((item, index) => {
                        const shopName = item.getAttribute('data-shop-name');
                        if (!searchTerm || shopName.includes(searchTerm)) {
                            item.style.display = 'block';
                            setTimeout(() => {
                                item.style.opacity = '1';
                                item.style.transform = 'translateY(0)';
                            }, index * 30);
                            visibleCount++;
                        } else {
                            item.style.display = 'none';
                            item.style.opacity = '0';
                            item.style.transform = 'translateY(20px)';
                        }
                    });
                    
                    // Update results counter
                    const searchResultsCounter = document.getElementById('searchResultsCounter');
                    if (searchTerm && searchResultsCounter) {
                        searchResultsCounter.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${visibleCount} لە ${shopItems.length} فرۆشگا دەردەکەون`;
                        searchResultsCounter.style.display = 'inline-flex';
                    } else if (searchResultsCounter) {
                        searchResultsCounter.style.display = 'none';
                    }
                    
                    // Show/hide no results
                    const noResults = document.getElementById('noResults');
                    const shopsGrid = document.getElementById('shopsGrid');
                    if (noResults && shopsGrid) {
                        if (visibleCount === 0 && searchTerm) {
                            noResults.style.display = 'block';
                            shopsGrid.style.display = 'none';
                        } else {
                            noResults.style.display = 'none';
                            shopsGrid.style.display = 'flex';
                        }
                    }
                };
                
                // New search handler that works for both modes
                let searchTimeout;
                
                // Function to search products via AJAX
                function searchProductsAjax(query, offset = 0, append = false) {
                    if (query.length < 2) {
                        // Reset to original products if query is too short
                        resetProductsView();
                        return;
                    }
                    
                    // Reset search offset if starting a new search (not appending)
                    if (!append) {
                        searchOffset = 0;
                        offset = 0; // Ensure offset is 0 for new searches
                    }
                    
                    // Use the current searchOffset if appending
                    if (append) {
                        offset = searchOffset;
                    }
                    
                    isSearchingProducts = true;
                    const productsGrid = document.getElementById('productsGrid');
                    const searchResultsCounter = document.getElementById('searchResultsCounter');
                    const loadMoreContainer = document.getElementById('loadMoreProductsContainer');
                    const loadMoreBtn = document.getElementById('loadMoreProductsBtn');
                    
                    // Show loading state
                    if (!append) {
                        productsGrid.innerHTML = '<div class="col-12 text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">گەڕان...</span></div></div>';
                        if (loadMoreContainer) loadMoreContainer.style.display = 'none';
                    } else if (loadMoreBtn) {
                        loadMoreBtn.disabled = true;
                        loadMoreBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>چاوەڕوانبە...';
                    }
                    
                    // Fetch search results
                    fetch(`<?php echo SITE_URL; ?>web/search-products.php?q=${encodeURIComponent(query)}&offset=${offset}&limit=100`)
                        .then(response => response.json())
                        .then(data => {
                            isSearchingProducts = false;
                                
                            if (data.success && data.html) {
                                if (append) {
                                    // Append new products
                                    const tempDiv = document.createElement('div');
                                    tempDiv.innerHTML = data.html;
                                    let delay = 0;
                                    tempDiv.querySelectorAll('.product-item-card').forEach((item, index) => {
                                        item.style.opacity = '0';
                                        item.style.transform = 'translateY(30px)';
                                        productsGrid.appendChild(item);
                                        setTimeout(() => {
                                            item.style.transition = 'all 0.6s ease';
                                            item.style.opacity = '1';
                                            item.style.transform = 'translateY(0)';
                                        }, delay);
                                        delay += 50;
                                    });
                                    // Button state will be updated below based on hasMore
                                } else {
                                    // Replace products
                                    productsGrid.innerHTML = data.html;
                                    isInSearchMode = true;
                                    // Reinitialize cart buttons for new products
                                    if (window.shoppingCart && typeof window.shoppingCart.initCartButtons === 'function') {
                                        window.shoppingCart.initCartButtons();
                                    }
                                }
                                
                                // Only update searchTotal on new searches, not when appending
                                // This preserves the original total count from the first search
                                if (!append) {
                                    searchTotal = data.total || 0;
                                }
                                searchOffset = offset + data.count;
                                
                                // Update results counter
                                if (searchResultsCounter) {
                                    const displayedCount = append ? productsGrid.querySelectorAll('.product-item-card').length : data.count;
                                    searchResultsCounter.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${displayedCount} لە ${searchTotal} کاڵا دەردەکەون`;
                                    searchResultsCounter.style.display = 'inline-flex';
                                }
                                
                                // Show/hide load more button
                                if (loadMoreContainer && loadMoreBtn) {
                                    // Calculate displayed count
                                    const displayedCount = append ? productsGrid.querySelectorAll('.product-item-card').length : data.count;
                                    // Use searchTotal (from first search) instead of data.total (which may change)
                                    // Calculate hasMore based on displayed count vs total from initial search
                                    const hasMoreProducts = displayedCount < searchTotal;
                                    
                                    if (hasMoreProducts) {
                                        // There are more products to load
                                        loadMoreContainer.style.display = 'block';
                                        loadMoreBtn.disabled = false;
                                        loadMoreBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i><span class="btn-text">بینینی کاڵای زیاتر</span>';
                                    } else {
                                        // No more products
                                        loadMoreBtn.innerHTML = '<i class="bi bi-info-circle"></i> کاڵای تر نییە';
                                        loadMoreBtn.disabled = true;
                                    }
                                }
                                
                                // Show no results if empty
                                if (data.count === 0 && !append) {
                                    productsGrid.innerHTML = `
                                        <div class="col-12">
                                            <div class="empty-state-card text-center">
                                                <div class="empty-icon mb-4">
                                                    <i class="bi bi-search" aria-hidden="true"></i>
                                                </div>
                                                <h4 class="mb-3">هیچ کاڵایەک نەدۆزرایەوە</h4>
                                                <p class="text-muted mb-4">تکایە بژاردێکی تر تاقی بکەرەوە</p>
                                            </div>
                                        </div>
                                    `;
                                    if (loadMoreContainer) loadMoreContainer.style.display = 'none';
                                }
                            } else {
                                productsGrid.innerHTML = '<div class="col-12 text-center py-5"><p class="text-muted">هەڵەیەک ڕوویدا لە گەڕان</p></div>';
                                if (loadMoreContainer) loadMoreContainer.style.display = 'none';
                            }
                        })
                        .catch(error => {
                            isSearchingProducts = false;
                            const productsGrid = document.getElementById('productsGrid');
                            productsGrid.innerHTML = '<div class="col-12 text-center py-5"><p class="text-danger">هەڵەیەک ڕوویدا لە گەڕان</p></div>';
                            const loadMoreContainer = document.getElementById('loadMoreProductsContainer');
                            if (loadMoreContainer) loadMoreContainer.style.display = 'none';
                        });
                }
                
                // Add search mode handler for load more button
                // Note: The first listener (line 1764) handles random products and returns early in search mode
                // This second listener only handles search mode to avoid conflicts
                if (loadMoreProductsBtn) {
                    loadMoreProductsBtn.addEventListener('click', function(e) {
                        // Only handle search mode - random products are handled by the first listener
                        if (isInSearchMode && currentSearchQuery && currentSearchQuery.length >= 2) {
                            if (isLoading || isSearchingProducts) return;
                            
                            // Load more search results
                            searchProductsAjax(currentSearchQuery, searchOffset, true);
                        }
                    });
                }
                
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.trim();
                    currentSearchQuery = searchTerm;
                    
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        if (currentMode === 'products') {
                            if (searchTerm.length >= 2) {
                                // Search products via AJAX
                                searchProductsAjax(searchTerm, 0, false);
                            } else {
                                // Reset to original view
                                resetProductsView();
                            }
                        } else {
                            // Search shops (client-side)
                            originalShopSearch(searchTerm.toLowerCase());
                        }
                    }, 500); // Increased delay for better UX
                });

                // Auto-trigger product search when coming from video search
                try {
                    const params = new URLSearchParams(window.location.search);
                    const fromVideos = params.get('search_from_videos');
                    const videoQuery = params.get('q');

                    if (fromVideos && videoQuery) {
                        const term = videoQuery.trim();
                        if (term.length >= 2) {
                            // Ensure we are in products mode
                            currentMode = 'products';

                            searchInput.value = term;
                            currentSearchQuery = term;

                            const productsSection = document.getElementById('productsSection');
                            if (productsSection) {
                                productsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }

                            // Use existing AJAX search
                            searchProductsAjax(term, 0, false);
                        }
                    }
                } catch (e) {
                    console.error('Video search redirect handling failed:', e);
                }
            }
        });
    </script>

</body>
</html>