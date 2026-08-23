<?php
/**
 * Dynamic Shop Handler - web/shop.php
 * Handles individual shop pages based on website_slug
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once 'auth/session_helper.php';
require_once 'auth/shop_google_access.php';
require_once 'includes/shop_whatsapp.php';
require_once 'includes/shop_announcement.php';

// HTML-ی داینامیکی فرۆشگا هەرگیز کاش نەکرێت، بۆ ئەوەی ئاگادارکردنەوە و
// هەر گۆڕانکاریەک یەکسەر دەربکەون بەبێ پێویستی بە Ctrl+Shift+R.
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

shop_google_ensure_db_schema($conn);

$checkExitCol = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_shop_exit_button'");
if ($checkExitCol && $checkExitCol->num_rows === 0) {
    $conn->query("ALTER TABLE website_settings ADD COLUMN show_shop_exit_button TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=show دەرچوون in shop nav' AFTER shop_banner");
}
if ($checkExitCol) {
    $checkExitCol->close();
}

shop_whatsapp_ensure_column($conn);
shop_announcement_ensure_columns($conn);

// Get slug from URL parameter
$slug = $_GET['slug'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$priceFilter = $_GET['price'] ?? ''; // all, low, medium, high
$sortBy = $_GET['sort'] ?? ''; // name, price_asc, price_desc, category, random (empty = random)
$viewMode = $_GET['view'] ?? 'grid'; // grid, list

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
$shopId = $websiteSettings['id']; // ID ی فرۆشگا بۆ تۆمارکردنی سەردانیکەران

$showShopExitButton = !isset($websiteSettings['show_shop_exit_button']) || (int) $websiteSettings['show_shop_exit_button'] === 1;
$shopWhatsAppCartConfig = shop_whatsapp_cart_config($websiteSettings, $slug);

shop_google_access_guard($conn, $slug, $websiteSettings);

// ========================================
// سیستەمی ژمارەی سەردانیکەران بۆ فرۆشگا
// ========================================

// دروستکردنی خشتەی سەردانیکەرانی فرۆشگا ئەگەر نەبوو
$conn->query("CREATE TABLE IF NOT EXISTS shop_visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NOT NULL,
    visit_count INT DEFAULT 1,
    first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_visit DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_shop_visitor (shop_id, ip_address, user_agent),
    INDEX idx_shop_id (shop_id)
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

// تۆمارکردنی سەردانیکەر بۆ ئەم فرۆشگایە - INSERT ON DUPLICATE KEY UPDATE
$stmtVisitor = $conn->prepare("INSERT INTO shop_visitors (shop_id, ip_address, user_agent, visit_count, first_visit, last_visit) 
                               VALUES (?, ?, ?, 1, NOW(), NOW()) 
                               ON DUPLICATE KEY UPDATE last_visit = NOW(), visit_count = visit_count + 1");
$stmtVisitor->bind_param("iss", $shopId, $visitorIP, $userAgent);
$stmtVisitor->execute();
$stmtVisitor->close();

// وەرگرتنی کۆی گشتی سەردانیکەران بۆ ئەم فرۆشگایە (یەکتایی)
$shopVisitorsResult = $conn->prepare("SELECT COUNT(*) as total FROM shop_visitors WHERE shop_id = ?");
$shopVisitorsResult->bind_param("i", $shopId);
$shopVisitorsResult->execute();
$shopVisitorsData = $shopVisitorsResult->get_result()->fetch_assoc();
$shopVisitors = $shopVisitorsData['total'] ?? 0;
$shopVisitorsResult->close();

// ========================================
// کۆتایی سیستەمی سەردانیکەران
// ========================================

// وەرگرتنی ڤیدیۆکانی فرۆشگا (free_videos + product_videos) لە داتابەیسی ڤیدیۆ
$shopVideos = [];
if (file_exists(__DIR__ . '/../config/product_videos/database.php')) {
    require_once __DIR__ . '/../config/product_videos/database.php';
    global $conn_videos;
    if (!empty($conn_videos) && $conn_videos instanceof mysqli) {
        $uid = (int) $userId;
        $sql = "(SELECT id, user_id, NULL AS product_id, video_description, video_url, created_at, 'free' AS video_type FROM free_videos WHERE user_id = ?)
                UNION ALL
                (SELECT id, user_id, product_id, video_description, video_url, created_at, 'product' AS video_type FROM product_videos WHERE user_id = ?)
                ORDER BY created_at DESC LIMIT 20";
        $stmtV = $conn_videos->prepare($sql);
        if ($stmtV) {
            $stmtV->bind_param("ii", $uid, $uid);
            $stmtV->execute();
            $resV = $stmtV->get_result();
            while ($row = $resV->fetch_assoc()) {
                if (!empty($row['video_url'])) {
                    $shopVideos[] = $row;
                }
            }
            $stmtV->close();
        }
    }
}

function buildShopVideoLink($type, $id, $shopUserId = null) {
    $path = 'videos/index.php?video_type=' . rawurlencode($type) . '&video_id=' . (int) $id;
    if ($shopUserId !== null && (int) $shopUserId > 0) {
        $path .= '&shop_user_id=' . (int) $shopUserId;
    }
    return function_exists('url') ? url($path) : (rtrim(SITE_URL, '/') . '/' . $path);
}

// Get categories for filtering
$categories = [];
if ($websiteSettings['show_by_category']) {
    $catStmt = $conn->prepare("
        SELECT DISTINCT c.id, c.name, COUNT(DISTINCT p.id) as product_count
        FROM categories c
        INNER JOIN products p ON c.id = p.category_id
        LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = ?
        LEFT JOIN product_details pd ON p.id = pd.product_id
        LEFT JOIN product_units pu ON p.id = pu.product_id 
            AND (pu.is_primary = 1 
                 OR (pu.is_primary = 0 AND NOT EXISTS (
                     SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                 ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
        WHERE p.user_id = ? AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL) 
            AND COALESCE(c.is_visible_on_website, 1) = 1
            AND pu.stock_quantity > 0
            AND (p.image_path IS NOT NULL AND p.image_path != '' OR pd.main_image IS NOT NULL AND pd.main_image != '')
        GROUP BY c.id, c.name
        ORDER BY c.name
    ");
    $catStmt->bind_param("ii", $userId, $userId);
    $catStmt->execute();
    $catResult = $catStmt->get_result();
    $categories = $catResult->fetch_all(MYSQLI_ASSOC);
    $catStmt->close();
}

// Get products with their primary unit data
$whereClause = "p.user_id = ? AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)";
// هەمیشە تەنها کاڵاکانی نیشان بدە کە وێنەیان هەیە
$whereClause .= " AND (p.image_path IS NOT NULL AND p.image_path != '' OR pd.main_image IS NOT NULL AND pd.main_image != '')";
$params = [$userId, $userId];
$paramTypes = "ii";

if (!empty($categoryFilter)) {
    $whereClause .= " AND c.id = ?";
    $params[] = (int)$categoryFilter;
    $paramTypes .= "i";
}

if (!empty($searchTerm)) {
    $whereClause .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $searchPattern = "%{$searchTerm}%";
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $paramTypes .= "ss";
}

// Build ORDER BY clause based on sort parameter
// ئەگەر sort parameter نەدرابێت، بەکارهێنانی product_display_order لە website_settings
if (empty($sortBy)) {
    $sortBy = $websiteSettings['product_display_order'] ?? 'random';
}

$orderBy = "RAND()"; // Default: Random order like index.php
switch ($sortBy) {
    case 'price_asc':
        $orderBy = "COALESCE(pu.sell_price, 0) ASC, p.name";
        break;
    case 'price_desc':
        $orderBy = "COALESCE(pu.sell_price, 0) DESC, p.name";
        break;
    case 'name':
        $orderBy = "p.name ASC";
        break;
    case 'category':
        $orderBy = "c.name, p.name";
        break;
    case 'newest':
        $orderBy = "COALESCE(p.updated_at, p.created_at) DESC";
        break;
    case 'oldest':
        $orderBy = "p.id ASC";
        break;
    case 'random':
        $orderBy = "RAND()";
        break;
    default:
        // ئەگەر بەهاکە نەناسرێت، بەکارهێنانی product_display_order یان random
        $defaultOrder = $websiteSettings['product_display_order'] ?? 'random';
        if ($defaultOrder === 'newest') {
            $orderBy = "COALESCE(p.updated_at, p.created_at) DESC";
        } elseif ($defaultOrder === 'oldest') {
            $orderBy = "p.id ASC";
        } else {
            $orderBy = "RAND()";
        }
}

$productQuery = "
    SELECT p.*, c.name as category_name, c.id as category_id,
           COALESCE(pu.stock_quantity, 0) as stock_quantity,
           COALESCE(pu.sell_price, 0) as retail_price,
           COALESCE(pu.wholesale_price, 0) as wholesale_price,
           COALESCE(pu.special_price, 0) as special_price,
           pd.description, pd.main_image, pd.discount_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = ?
    LEFT JOIN product_details pd ON p.id = pd.product_id
    LEFT JOIN product_units pu ON p.id = pu.product_id 
        AND (pu.is_primary = 1 
             OR (pu.is_primary = 0 AND NOT EXISTS (
                 SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
             ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
    WHERE $whereClause
        AND COALESCE(c.is_visible_on_website, 1) = 1
        AND pu.stock_quantity > 0
    ORDER BY $orderBy
";

$stmt = $conn->prepare($productQuery);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$products = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Shuffle products randomly (similar to index.php) when random order is selected or default
if ($sortBy == 'random' || empty($sortBy)) {
    shuffle($products);
}

// Get all units for each product
function getProductUnits($conn, $productId) {
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

// Get category name for display if filtering by category
$categoryName = '';
if (!empty($categoryFilter)) {
    $catNameStmt = $conn->prepare("SELECT name FROM categories WHERE id = ? AND user_id = ?");
    $catNameStmt->bind_param("ii", $categoryFilter, $userId);
    $catNameStmt->execute();
    $catNameResult = $catNameStmt->get_result();
    if ($catNameRow = $catNameResult->fetch_assoc()) {
        $categoryName = $catNameRow['name'];
    }
    $catNameStmt->close();
}

// Group products by category if enabled
$groupedProducts = [];
if ($websiteSettings['show_by_category']) {
    foreach ($products as $product) {
        $categoryName = $product['category_name'] ?: 'بێ کەتەلۆگ';
        $groupedProducts[$categoryName][] = $product;
    }
} else {
    $groupedProducts['هەموو کاڵاکان'] = $products;
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

// Helper function to get product image
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

// Generate checkout request token for direct cart submission (logged-in users only)
$isCustomerLoggedIn = CustomerSession::isLoggedIn();
$loggedCustomerData = $isCustomerLoggedIn ? CustomerSession::getCustomerData() : null;
$checkoutRequestToken = '';
if ($isCustomerLoggedIn) {
    if (empty($_SESSION['checkout_request_token'])) {
        try {
            $_SESSION['checkout_request_token'] = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $_SESSION['checkout_request_token'] = uniqid('chk_', true);
        }
    }
    $checkoutRequestToken = $_SESSION['checkout_request_token'];
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title><?php echo htmlspecialchars($businessName); ?> - فرۆشگای ئۆنلاین</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/shop.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/cart.css?v=<?php echo time(); ?>" rel="stylesheet">
    
    <style>
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

        /* Navbar Back Button */
        .navbar-back-btn {
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        /* Responsive for mobile */
        @media (max-width: 768px) {
            /* Reduce navbar height and padding */
            #mainNavbar {
                padding: 0.4rem 0 !important;
            }
            
            #mainNavbar .container {
                padding: 0 0.75rem !important;
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
            }
            
            /* Center section - shop name */
            .navbar-text {
                font-size: 0.85rem !important;
                margin: 0 !important;
            }
            
            .navbar-text i {
                font-size: 0.9rem !important;
            }
            
            /* Reduce gap between elements */
            #mainNavbar .d-flex {
                gap: 0.35rem !important;
            }
            
            #mainNavbar .navbar-nav {
                gap: 0.4rem !important;
            }
            
            /* Make copy link button smaller */
            .btn-copy-link {
                padding: 4px 8px !important;
            }
            
            .btn-copy-text {
                display: none !important;
            }
            
            .btn-copy-link i {
                font-size: 1.1rem !important;
            }
            
            /* Make cart button smaller */
            .cart-button {
                padding: 0.35rem 0.6rem !important;
                font-size: 1.2rem !important;
            }
            
            .cart-badge {
                font-size: 0.65rem !important;
                padding: 0.15rem 0.35rem !important;
            }
            
            /* Make dropdown button smaller */
            .dropdown .btn {
                padding: 0.35rem 0.6rem !important;
                font-size: 0.8rem !important;
            }
            
            .dropdown .btn i {
                font-size: 1rem !important;
            }
            
            /* Back button */
            .navbar-back-btn {
                padding: 0.35rem 0.6rem !important;
                font-size: 0.8rem !important;
            }
            
            .navbar-back-btn i {
                font-size: 1rem !important;
            }
        }
        
        /* Small mobile devices */
        @media (max-width: 576px) {
            #mainNavbar {
                padding: 0.3rem 0 !important;
            }
            
            #mainNavbar .container {
                padding: 0 0.5rem !important;
            }
            
            /* Hide text, show only icons */
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
            
            /* Make buttons more compact */
            .cart-button,
            .dropdown .btn,
            .navbar-back-btn {
                padding: 0.3rem 0.5rem !important;
                font-size: 1.1rem !important;
            }
            
            .btn-copy-link {
                padding: 3px 6px !important;
            }
            
            .btn-copy-link i {
                font-size: 1rem !important;
            }
            
            #mainNavbar .d-flex {
                gap: 0.25rem !important;
            }
            
            #mainNavbar .navbar-nav {
                gap: 0.3rem !important;
            }
        }

        /* ڤیدیۆکانی فرۆشگا - هەمان قەبارە و دیزاینی بەشی گەڕان */
        .shop-videos-section {
            margin-bottom: 2rem;
        }
        .shop-videos-section .tag-grid .row {
            margin-top: 0.5rem;
        }
        @media (min-width: 992px) {
            .shop-videos-section .tag-grid .row > [class*="col-lg"] {
                flex: 0 0 20%;
                max-width: 20%;
            }
        }
        .shop-videos-section .tag-video-card {
            background: radial-gradient(circle at top, #020617 0, #020617 55%, #020617 100%);
            border-radius: 1.3rem;
            border: 1px solid rgba(30,64,175,0.7);
            overflow: hidden;
            box-shadow: 0 14px 35px rgba(15,23,42,0.9), 0 0 0 1px rgba(15,23,42,0.8);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .shop-videos-section .tag-video-media {
            position: relative;
            background: #000;
        }
        .shop-videos-section .tag-video-media video {
            width: 100%;
            height: 300px;
            object-fit: cover;
            display: block;
        }
        @media (min-width: 992px) {
            .shop-videos-section .tag-video-media video {
                height: 350px;
            }
        }
        .shop-videos-section .tag-play-icon {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        .shop-videos-section .tag-play-icon-inner {
            width: 60px;
            height: 60px;
            border-radius: 999px;
            background: rgba(0,0,0,0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f9fafb;
            box-shadow: 0 18px 40px rgba(0,0,0,0.9), 0 0 0 1px rgba(248,250,252,0.35);
        }
        .shop-videos-section .tag-play-icon-inner i {
            font-size: 2rem;
        }
        .shop-videos-section .tag-video-body {
            padding: 0.9rem 0.95rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }
        .shop-videos-section .tag-video-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
        }
        .shop-videos-section .tag-store-group {
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }
        .shop-videos-section .tag-logo {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            object-fit: cover;
        }
        .shop-videos-section .tag-logo-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            background: radial-gradient(circle at 30% 0, #ffffff 0, #4b5563 40%, #020617 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f9fafb;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .shop-videos-section .tag-store-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: #e5e7eb;
        }
        .shop-videos-section .tag-description {
            font-size: 0.86rem;
            color: #e5e7eb;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .shop-videos-section .tag-type-badge {
            border-radius: 999px;
            padding: 0.15rem 0.6rem;
            font-size: 0.78rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: rgba(15,23,42,0.9);
            border: 1px solid rgba(148,163,184,0.65);
            color: #e5e7eb;
        }
        .shop-videos-section .shop-video-hidden {
            display: none;
        }
        .shop-videos-section .shop-video-reveal {
            animation: fadeInUp 0.35s ease forwards;
        }
        .shop-videos-section .shop-video-toggle-wrap {
            margin-top: 1.1rem;
            display: flex;
            justify-content: center;
        }
        .shop-videos-section .shop-video-toggle-btn {
            border: 1px solid rgba(59,130,246,0.45);
            background:
                linear-gradient(135deg, rgba(2,6,23,0.92) 0%, rgba(15,23,42,0.92) 100%),
                radial-gradient(circle at top right, rgba(96,165,250,0.18) 0%, transparent 60%);
            color: #e2e8f0;
            border-radius: 1.1rem;
            padding: 0.8rem 1.1rem;
            min-width: min(100%, 380px);
            width: 100%;
            max-width: 520px;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            box-shadow: 0 14px 34px rgba(2,6,23,0.5), inset 0 1px 0 rgba(255,255,255,0.08);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            transition: all 0.25s ease;
        }
        .shop-videos-section .shop-video-toggle-btn:hover {
            color: #f8fafc;
            border-color: rgba(59,130,246,0.85);
            transform: translateY(-2px);
            box-shadow: 0 18px 36px rgba(2,6,23,0.6), inset 0 1px 0 rgba(255,255,255,0.12);
        }
        .shop-videos-section .shop-video-toggle-btn:focus-visible {
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(59,130,246,0.35), 0 18px 36px rgba(2,6,23,0.6);
        }
        .shop-videos-section .shop-video-toggle-btn-main {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.96rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .shop-videos-section .shop-video-toggle-btn-hint {
            font-size: 0.82rem;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }
        .shop-videos-section .shop-video-toggle-btn .badge {
            background: rgba(59,130,246,0.25) !important;
            border: 1px solid rgba(96,165,250,0.5);
            color: #dbeafe;
            font-size: 0.75rem;
        }
        .shop-videos-section .shop-video-toggle-btn-preview {
            width: 1.45rem;
            height: 1.1rem;
            border-radius: 0.45rem;
            background: linear-gradient(140deg, rgba(56,189,248,0.35), rgba(59,130,246,0.1));
            border: 1px solid rgba(125,211,252,0.45);
            position: relative;
            flex-shrink: 0;
        }
        .shop-videos-section .shop-video-toggle-btn-preview::before,
        .shop-videos-section .shop-video-toggle-btn-preview::after {
            content: "";
            position: absolute;
            border-radius: 0.4rem;
            border: 1px solid rgba(125,211,252,0.35);
            inset: 0;
        }
        .shop-videos-section .shop-video-toggle-btn-preview::before {
            transform: translate(-4px, -3px);
            opacity: 0.55;
        }
        .shop-videos-section .shop-video-toggle-btn-preview::after {
            transform: translate(-2px, -1px);
            opacity: 0.8;
        }
        @media (max-width: 768px) {
            .shop-videos-section .shop-video-toggle-wrap {
                margin-top: 1.4rem;
                padding: 0 0.25rem;
            }
            .shop-videos-section .shop-video-toggle-btn {
                max-width: 100%;
                border-radius: 1rem;
                padding: 0.9rem 1rem;
                box-shadow: 0 16px 34px rgba(2,6,23,0.55), inset 0 1px 0 rgba(255,255,255,0.08);
            }
            .shop-videos-section .shop-video-toggle-btn-main {
                font-size: 1rem;
            }
            .shop-videos-section .shop-video-toggle-btn-hint {
                font-size: 0.8rem;
            }
        }
        @media (min-width: 992px) {
            .shop-videos-section .shop-video-toggle-wrap {
                margin-top: 1.5rem;
            }
            .shop-videos-section .shop-video-toggle-btn {
                max-width: 620px;
                border-radius: 1.25rem;
                padding: 1rem 1.25rem;
                border-color: rgba(96,165,250,0.55);
                box-shadow: 0 20px 44px rgba(2,6,23,0.58), inset 0 1px 0 rgba(255,255,255,0.1);
            }
            .shop-videos-section .shop-video-toggle-btn-main {
                font-size: 1.05rem;
                gap: 0.6rem;
            }
            .shop-videos-section .shop-video-toggle-btn-hint {
                font-size: 0.9rem;
            }
            .shop-videos-section .shop-video-toggle-btn .badge {
                font-size: 0.82rem;
                padding: 0.28rem 0.55rem;
            }
        }
        
        /* Extra small devices */
        @media (max-width: 380px) {
            .navbar-text {
                font-size: 0.7rem !important;
                max-width: 90px !important;
            }
            
            .navbar-text i {
                display: none !important;
            }
            
            .cart-button,
            .dropdown .btn,
            .navbar-back-btn {
                padding: 0.25rem 0.4rem !important;
            }
        }

        html[data-bs-theme='dark'] .copy-toast {
            background: linear-gradient(135deg, #020617 0%, #0f172a 100%) !important;
        }

        html[data-bs-theme='dark'] .shop-videos-section .shop-video-toggle-btn-hint {
            color: #cbd5e1;
        }

        html[data-bs-theme='dark'] .shop-videos-section .tag-video-card {
            border-color: rgba(96, 165, 250, 0.55);
        }

        /* Inline quantity widget — flex row: minus, input, plus (RTL dir=rtl swaps visually) */
        .shop-qty-widget {
            display: flex;
            flex-direction: row;
            align-items: stretch;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            box-sizing: border-box;
        }

        .shop-qty-widget.shop-qty-widget--disabled {
            opacity: 0.65;
            pointer-events: none;
        }

        .shop-qty-widget .shop-qty-dec {
            margin-inline-end: auto;
        }

        .shop-qty-widget .shop-qty-inc {
            margin-inline-start: auto;
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
            flex: 0 1 4rem;
            min-width: 0;
            max-width: 4rem;
            margin-inline: 0;
            text-align: center;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            background-color: var(--bs-body-bg);
            color: var(--bs-body-color);
            border-color: var(--bs-border-color);
            min-height: calc(1.5em + 0.75rem + 2px);
            padding-inline: 0.25rem;
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

        /* بەردەستی / یەکە — جیاکردنەوەی کاڵی لە نێوان لیبڵ، ژمارە، ناو */
        .shop-stock-line {
            font-size: 0.8125rem;
            line-height: 1.45;
        }

        .shop-stock-line .shop-stock-label {
            color: var(--bs-secondary-color);
            font-weight: 400;
        }

        .shop-stock-line .shop-stock-qty {
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: var(--bs-emphasis-color);
        }

        /* ناوی یەکە لە تەنیشت بڕی داواکاری (shop-qty-widget) */
        .shop-qty-widget .shop-stock-unit {
            align-self: center;
            flex-shrink: 0;
            margin-inline-start: -0.15rem;
            margin-inline-end: 0.15rem;
            font-size: 0.8125rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: var(--bs-secondary-color);
            color: color-mix(in srgb, var(--bs-secondary-color) 52%, var(--bs-emphasis-color) 48%);
        }

        html[data-bs-theme='dark'] .shop-qty-widget .shop-stock-unit {
            color: color-mix(in srgb, var(--bs-secondary-color) 45%, var(--bs-emphasis-color) 55%);
        }

        .shop-unit-field .shop-unit-label-text {
            font-size: 0.8rem;
            color: var(--bs-secondary-color);
            font-weight: 400;
        }

        .shop-unit-field .unit-selector {
            font-weight: 500;
            color: var(--bs-emphasis-color);
            border-color: var(--bs-border-color);
        }

        /* ============================================
           ئاگادارکردنەوەی سەرەوەی فرۆشگا (Announcement)
           ============================================ */
        .shop-announcement {
            position: relative;
            background:
                linear-gradient(135deg, rgba(var(--bs-primary-rgb, 13, 110, 253), 0.14) 0%, rgba(var(--bs-primary-rgb, 13, 110, 253), 0.05) 100%),
                var(--bs-body-bg);
            border-bottom: 1px solid rgba(var(--bs-primary-rgb, 13, 110, 253), 0.22);
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .shop-announcement::before {
            content: "";
            position: absolute;
            inset-inline-start: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, var(--bs-primary) 0%, rgba(var(--bs-primary-rgb, 13, 110, 253), 0.4) 100%);
        }

        .shop-announcement-inner {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.75rem 0.25rem;
        }

        .shop-announcement-icon {
            flex: 0 0 auto;
            width: 2.35rem;
            height: 2.35rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--bs-primary) 0%, rgba(var(--bs-primary-rgb, 13, 110, 253), 0.75) 100%);
            color: #fff;
            font-size: 1.05rem;
            box-shadow: 0 4px 12px rgba(var(--bs-primary-rgb, 13, 110, 253), 0.35);
        }

        .shop-announcement-body {
            flex: 1 1 auto;
            min-width: 0;
            color: var(--bs-emphasis-color);
            font-size: 0.95rem;
            line-height: 1.6;
            word-break: break-word;
        }

        .shop-announcement-body strong {
            font-weight: 700;
            color: var(--bs-emphasis-color);
        }

        .shop-announcement-body em {
            font-style: italic;
        }

        .shop-announcement-body a {
            color: var(--bs-primary);
            text-decoration: underline;
            font-weight: 600;
        }

        .shop-announcement-body code {
            background: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.1);
            padding: 0.05rem 0.35rem;
            border-radius: 0.35rem;
            font-size: 0.88em;
        }

        .shop-announcement-close {
            flex: 0 0 auto;
            border: 0;
            background: transparent;
            color: var(--bs-secondary-color);
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        .shop-announcement-close:hover {
            background: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.12);
            color: var(--bs-emphasis-color);
        }

        html[data-bs-theme='dark'] .shop-announcement {
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
        }

        @media (max-width: 576px) {
            .shop-announcement-inner {
                gap: 0.6rem;
                padding: 0.65rem 0.15rem;
            }
            .shop-announcement-icon {
                width: 2rem;
                height: 2rem;
                font-size: 0.95rem;
            }
            .shop-announcement-body {
                font-size: 0.875rem;
            }
        }
    </style>
    
    <!-- Meta tags for SEO -->
    <meta name="description" content="فرۆشگای ئۆنلاینی <?php echo htmlspecialchars($businessName); ?> - کاڵا و بەرهەمە جیاوازەکان">
    <meta name="keywords" content="فرۆشگا, ئۆنلاین, کاڵا, <?php echo htmlspecialchars($businessName); ?>">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo htmlspecialchars($businessName); ?> - فرۆشگای ئۆنلاین">
    <meta property="og:description" content="فرۆشگای ئۆنلاینی <?php echo htmlspecialchars($businessName); ?> - کاڵا و بەرهەمە جیاوازەکان">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo SITE_URL; ?>web/<?php echo $slug; ?>/">
</head>
<body class="web-shop-page">

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top" id="mainNavbar">
        <div class="container">
            <!-- Left Side: Cart Button -->
            <div class="d-flex align-items-center">
                <button class="cart-button" title="سەبەتەی کڕین">
                    <i class="bi bi-cart3"></i>
                    <span class="cart-badge" style="display: none;">0</span>
                </button>
            </div>
            
            <!-- Center: Shop Info & Actions -->
            <div class="d-flex align-items-center gap-2 flex-grow-1 justify-content-center">
                <span class="navbar-text text-center">
                    <i class="bi bi-shop"></i>
                    <?php echo htmlspecialchars($businessName); ?>
                </span>
                
                <!-- Copy Shop Link Button -->
                <button class="btn-copy-link" id="copyShopLinkBtn" title="کۆپی کردنی لینکی فرۆشگا">
                    <i class="bi bi-link-45deg"></i>
                    <span class="btn-copy-text">کۆپی لینک</span>
                </button>
                
                <!-- QR Code Button -->
                <button class="btn-copy-link" id="qrCodeBtn" title="پیشاندانی QR Code" data-bs-toggle="modal" data-bs-target="#qrCodeModal">
                    <i class="bi bi-qr-code"></i>
                    <span class="btn-copy-text">QR Code</span>
                </button>
            </div>
            
            <!-- Right Side: User Menu & Back Button -->
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
                            <?php if ($showShopExitButton): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>web/auth/logout.php?slug=<?php echo urlencode($slug); ?>">
                                <i class="bi bi-box-arrow-right"></i> دەرچوون
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person"></i>
                            <span class="account-text">هەژمار</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>web/auth/login.php?redirect=shop.php?slug=<?php echo urlencode($slug); ?>">
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
                
                <!-- My Account Button -->
                <a class="btn btn-outline-light btn-sm" href="<?php echo SITE_URL; ?>my_account/index.php" title="ئەکاوەنت">
                    <i class="bi bi-person-circle"></i>
                    <span class="account-text">ئەکاوەنت</span>
                </a>
                
                <?php if ($showShopExitButton): ?>
                <!-- Back Button -->
                <a class="btn btn-outline-light btn-sm navbar-back-btn" href="<?php echo SITE_URL; ?>web/" title="گەڕانەوە بۆ فرۆشگاکان">
                    <i class="bi bi-arrow-right"></i>
                    <span class="back-text">دەرچوون</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <?php if (shop_announcement_is_active($websiteSettings)): ?>
    <!-- ئاگادارکردنەوەی فرۆشگا -->
    <div class="shop-announcement" role="region" aria-label="ئاگادارکردنەوە">
        <div class="container">
            <div class="shop-announcement-inner">
                <span class="shop-announcement-icon" aria-hidden="true">
                    <i class="bi bi-megaphone-fill"></i>
                </span>
                <div class="shop-announcement-body">
                    <?php echo shop_announcement_render_html($websiteSettings['shop_announcement']); ?>
                </div>
                <button type="button" class="shop-announcement-close" aria-label="داخستن" title="داخستن">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="container py-4">
        
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <?php if (!empty($websiteSettings['shop_banner'])): ?>
                    <!-- بانەری فرۆشگا -->
                    <div class="shop-banner-container mb-4">
                        <div class="position-relative rounded overflow-hidden shadow-lg" style="height: 250px;">
                            <img src="<?php echo htmlspecialchars(resolveShopBannerUrl($websiteSettings['shop_banner'])); ?>" 
                                 class="w-100 h-100" 
                                 style="object-fit: cover; object-position: center;"
                                 alt="<?php echo htmlspecialchars($businessName); ?>">
                        </div>
                    </div>
                <?php else: ?>
                    <!-- دیزاینی کلاسیکی -->
                    <div class="text-center">
                        <h1 class="display-4 mb-3"><?php echo htmlspecialchars($businessName); ?></h1>
                        <p class="lead text-muted">فرۆشگای ئۆنلاین</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Breadcrumbs -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>web/"><i class="bi bi-house"></i> سەرەکی</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($businessName); ?></li>
                <?php if (!empty($categoryFilter) && !empty($categoryName)): ?>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($categoryName); ?></li>
                <?php endif; ?>
            </ol>
        </nav>

        <!-- Search and Filter Section -->
        <div class="search-filter-section mb-4">
            <div class="row g-3 align-items-center">
                <!-- Search Box -->
                <div class="col-lg-7 col-md-6 col-12">
                    <div class="search-box">
                        <form method="get" action="" class="d-flex">
                            <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug); ?>">
                            <?php if (!empty($categoryFilter)): ?>
                            <input type="hidden" name="category" value="<?php echo htmlspecialchars($categoryFilter); ?>">
                            <?php endif; ?>
                            <?php if (!empty($sortBy)): ?>
                            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                            <?php endif; ?>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-0">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="search" 
                                       name="search" 
                                       class="form-control border-0" 
                                       placeholder="گەڕان بە ناوی کاڵا یان بارکۆد..." 
                                       value="<?php echo htmlspecialchars($searchTerm); ?>"
                                       autocomplete="off"
                                       aria-label="گەڕان لە کاڵاکان">
                                <button type="submit" class="btn btn-primary border-0">
                                    <i class="bi bi-search d-md-none"></i>
                                    <span class="d-none d-md-inline">گەڕان</span>
                                </button>
                                <?php if (!empty($searchTerm)): ?>
                                <a href="?slug=<?php echo $slug; ?><?php echo !empty($categoryFilter) ? '&category=' . $categoryFilter : ''; ?>" 
                                   class="btn btn-outline-secondary border-0" 
                                   title="سڕینەوەی گەڕان">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Stats -->
                <div class="col-lg-5 col-md-6 col-12">
                    <div class="shop-stats d-flex justify-content-center justify-content-lg-end align-items-center h-100 gap-2 gap-md-3">
                        <div class="stat-item stat-item-visitors" title="ژمارەی سەردانیکەران">
                            <div class="stat-icon-wrapper">
                                <i class="bi bi-people-fill" aria-hidden="true"></i>
                            </div>
                            <div class="stat-content">
                                <span class="stat-number fw-bold" data-count="<?php echo $shopVisitors; ?>">0</span>
                                <span class="stat-label text-muted d-block">سەردانیکەر</span>
                            </div>
                        </div>
                        <div class="stat-item stat-item-products" title="کۆی گشتی کاڵاکان">
                            <div class="stat-icon-wrapper">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <div class="stat-content">
                                <span class="stat-number fw-bold"><?php echo count($products); ?></span>
                                <span class="stat-label text-muted d-block">کاڵا</span>
                            </div>
                        </div>
                        <?php if ($websiteSettings['show_by_category']): ?>
                        <div class="stat-item stat-item-catalogs" title="ژمارەی کەتەلۆگەکان">
                            <div class="stat-icon-wrapper">
                                <i class="bi bi-tag"></i>
                            </div>
                            <div class="stat-content">
                                <span class="stat-number fw-bold"><?php echo count($categories); ?></span>
                                <span class="stat-label text-muted d-block">کەتەلۆگ</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Discount Products Button -->
        <?php
        // وەرگرتنی ژمارەی کاڵا داشکاندراوەکانی ئەم فرۆشگایە
        $shopDiscountCountQuery = "
            SELECT COUNT(DISTINCT p.id) as total
            FROM products p
            INNER JOIN product_details pd ON p.id = pd.product_id
            LEFT JOIN product_units pu ON p.id = pu.product_id 
                AND (pu.is_primary = 1 
                     OR (pu.is_primary = 0 AND NOT EXISTS (
                         SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                     ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
            WHERE p.user_id = ?
                  AND pd.discount_price IS NOT NULL 
                  AND pd.discount_price > 0
                 AND pd.discount_price < COALESCE(pu.sell_price, 0)
                 AND pu.stock_quantity > 0
        ";
        $shopDiscountStmt = $conn->prepare($shopDiscountCountQuery);
        $shopDiscountStmt->bind_param("i", $userId);
        $shopDiscountStmt->execute();
        $shopDiscountResult = $shopDiscountStmt->get_result();
        $shopDiscountCount = $shopDiscountResult->fetch_assoc()['total'];
        $shopDiscountStmt->close();
        ?>
        
        <?php if ($shopDiscountCount > 0): ?>
        <div class="mb-4">
            <a href="<?php echo SITE_URL; ?>web/shop-discounts.php?slug=<?php echo $slug; ?>" 
               class="btn btn-danger btn-lg w-100 discount-promo-btn">
                <i class="bi bi-fire"></i>
                <span class="fw-bold">کاڵا داشکاندراوەکان</span>
                <span class="badge bg-light text-danger ms-2"><?php echo $shopDiscountCount; ?></span>
            </a>
        </div>
        
        <style>
            .discount-promo-btn {
                background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%) !important;
                border: none !important;
                padding: 1rem 1.5rem !important;
                font-size: 1.1rem !important;
                box-shadow: 0 6px 20px rgba(220, 38, 38, 0.3) !important;
                transition: all 0.3s ease !important;
                animation: btnPulse 2s infinite !important;
            }
            
            .discount-promo-btn:hover {
                transform: translateY(-3px) !important;
                box-shadow: 0 10px 30px rgba(220, 38, 38, 0.4) !important;
            }
            
            .discount-promo-btn i {
                font-size: 1.3rem !important;
                animation: iconWiggle 1s ease-in-out infinite !important;
            }
            
            @keyframes btnPulse {
                0%, 100% {
                    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.3);
                }
                50% {
                    box-shadow: 0 6px 25px rgba(220, 38, 38, 0.5);
                }
            }
            
            @keyframes iconWiggle {
                0%, 100% { transform: rotate(0deg); }
                25% { transform: rotate(-15deg); }
                75% { transform: rotate(15deg); }
            }
        </style>
        <?php endif; ?>

        <!-- Category Filter -->
        <?php if ($websiteSettings['show_by_category'] && count($categories) > 1): ?>
        <div class="category-filter">
            <h6 class="mb-3">
                <i class="bi bi-funnel"></i>
                فلتەر بە پێی کەتەلۆگ:
            </h6>
            <div class="d-flex flex-wrap">
                <a href="<?php echo SITE_URL; ?>web/<?php echo $slug; ?>/" 
                   class="btn btn-outline-primary <?php echo empty($categoryFilter) ? 'active' : ''; ?>">
                    <i class="bi bi-grid"></i>
                    هەموو کاڵاکان
                </a>
                <?php foreach ($categories as $category): ?>
                <a href="<?php echo SITE_URL; ?>web/<?php echo $slug; ?>/category/<?php echo $category['id']; ?>/" 
                   class="btn btn-outline-primary <?php echo $categoryFilter == $category['id'] ? 'active' : ''; ?>">
                    <i class="bi bi-tag"></i>
                    <?php echo htmlspecialchars($category['name']); ?>
                    <span class="badge bg-secondary ms-1"><?php echo $category['product_count']; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ڤیدیۆکانی فرۆشگا (هەمان قەبارە و دیزاینی بەشی گەڕان، کلیک دەچێت بۆ بەشی ڤیدیۆکان) -->
        <?php if (!empty($shopVideos)): ?>
        <?php
        $shopPageUrl = (function_exists('url') ? url('web/shop.php') : (rtrim(SITE_URL, '/') . '/web/shop.php')) . '?slug=' . rawurlencode($slug);
        $storeInitials = mb_strtoupper(mb_substr($businessName, 0, 2, 'UTF-8'), 'UTF-8');
        $initialVisibleVideosMobile = 2;
        $initialVisibleVideosDesktop = 5;
        $hiddenVideosCount = max(0, count($shopVideos) - $initialVisibleVideosMobile);
        $shopLogoUrl = null;
        if (file_exists(__DIR__ . '/../config/product_images/database.php')) {
            require_once __DIR__ . '/../config/product_images/database.php';
            global $conn_images;
            if (!empty($conn_images) && $conn_images instanceof mysqli) {
                $logoStmt = $conn_images->prepare('SELECT logo_url FROM user_logos WHERE user_id = ? LIMIT 1');
                if ($logoStmt) {
                    $logoStmt->bind_param('i', $userId);
                    $logoStmt->execute();
                    $logoRow = $logoStmt->get_result()->fetch_assoc();
                    $logoStmt->close();
                    if (!empty($logoRow['logo_url'])) {
                        $shopLogoUrl = $logoRow['logo_url'];
                    }
                }
            }
        }
        ?>
        <div class="shop-videos-section mb-4">
            <h4 class="mb-3">
                <i class="bi bi-collection-play"></i>
                ڤیدیۆکانی فرۆشگا
                <span class="badge bg-primary"><?php echo count($shopVideos); ?></span>
            </h4>
            <div class="tag-grid" data-shop-videos-container>
                <div class="row g-3 g-md-4">
                    <?php foreach ($shopVideos as $videoIndex => $item): ?>
                        <?php
                        $videoId = isset($item['id']) ? (int)$item['id'] : 0;
                        $type = (isset($item['video_type']) && $item['video_type'] === 'product') ? 'product' : 'free';
                        $description = isset($item['video_description']) ? $item['video_description'] : '';
                        $videoUrl = isset($item['video_url']) ? $item['video_url'] : '';
                        if ($videoId <= 0 || $videoUrl === '') continue;
                        $videoLink = buildShopVideoLink($type, $videoId, $userId);
                        $isHiddenVideo = $videoIndex >= $initialVisibleVideosDesktop;
                        ?>
                        <div class="col-6 col-md-6 col-lg <?php echo $isHiddenVideo ? 'shop-video-hidden' : ''; ?>" data-shop-video-item data-shop-video-index="<?php echo (int)$videoIndex; ?>">
                            <article class="tag-video-card position-relative">
                                <a href="<?php echo htmlspecialchars($videoLink, ENT_QUOTES, 'UTF-8'); ?>" class="stretched-link"></a>
                                <div class="tag-video-media">
                                    <video src="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'); ?>" muted playsinline preload="metadata" loop></video>
                                    <div class="tag-play-icon">
                                        <div class="tag-play-icon-inner">
                                            <i class="bi bi-play-fill"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="tag-video-body">
                                    <div class="tag-video-header">
                                        <div class="tag-store-group">
                                            <a href="<?php echo htmlspecialchars($shopPageUrl, ENT_QUOTES, 'UTF-8'); ?>" class="d-inline-flex">
                                                <?php if (!empty($shopLogoUrl)): ?>
                                                    <img src="<?php echo htmlspecialchars($shopLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'); ?>" class="tag-logo">
                                                <?php else: ?>
                                                    <div class="tag-logo-placeholder">
                                                        <span><?php echo htmlspecialchars($storeInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </a>
                                            <div class="d-flex flex-column">
                                                <span class="tag-store-name"><?php echo htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="tag-type-badge">
                                                <i class="bi <?php echo $type === 'product' ? 'bi-bag-check-fill' : 'bi-play-btn-fill'; ?>"></i>
                                                <span><?php echo $type === 'product' ? 'کاڵا' : 'گشتی'; ?></span>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($description !== ''): ?>
                                        <p class="tag-description mb-0"><?php echo htmlspecialchars(mb_substr($description, 0, 150, 'UTF-8') . (mb_strlen($description) > 150 ? '...' : ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($hiddenVideosCount > 0): ?>
            <div class="shop-video-toggle-wrap">
                <button type="button"
                        class="btn shop-video-toggle-btn"
                        data-shop-video-toggle
                        data-expand-text="بینینی ڤیدیۆکانی تر"
                        data-collapse-text="شاردنەوەی ڤیدیۆکان"
                        data-hidden-hint="ڤیدیۆی تر شاراوەیە"
                        data-expanded-hint="هەموو ڤیدیۆکان دەرکەوتن"
                        data-mobile-visible="<?php echo (int)$initialVisibleVideosMobile; ?>"
                        data-desktop-visible="<?php echo (int)$initialVisibleVideosDesktop; ?>"
                        aria-expanded="false">
                    <span class="shop-video-toggle-btn-main">
                        <span class="shop-video-toggle-btn-preview" aria-hidden="true"></span>
                        <i class="bi bi-chevron-down" data-shop-video-toggle-icon></i>
                        <span data-shop-video-toggle-text>بینینی ڤیدیۆکانی تر</span>
                    </span>
                    <span class="shop-video-toggle-btn-hint">
                        <span class="badge" data-shop-video-toggle-badge>+<?php echo $hiddenVideosCount; ?></span>
                        <span data-shop-video-toggle-hint><?php echo $hiddenVideosCount; ?> ڤیدیۆی تر شاراوەیە</span>
                    </span>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Products -->
        <div class="row products-container <?php echo $viewMode == 'list' ? 'list-view' : 'grid-view'; ?>" id="productsContainer">
            <?php if (empty($products)): ?>
                <div class="col-12">
                    <div class="empty-state-card text-center py-5">
                        <div class="empty-icon mb-4">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                        </div>
                        <h4 class="mb-3">هیچ کاڵایەک نەدۆزرایەوە</h4>
                        <p class="text-muted mb-4">
                            <?php if (!empty($searchTerm)): ?>
                                هیچ ئەنجامێک بۆ "<?php echo htmlspecialchars($searchTerm); ?>" نەدۆزرایەوە
                            <?php elseif (!empty($categoryFilter)): ?>
                                لە کەتەلۆگی "<?php echo htmlspecialchars($categoryName); ?>" هیچ کاڵایەک نەدۆزرایەوە
                            <?php else: ?>
                                ئەم فرۆشگایە هیچ کاڵایەکی بەردەست نییە
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($searchTerm) || !empty($categoryFilter)): ?>
                        <a href="<?php echo SITE_URL; ?>web/<?php echo $slug; ?>/" class="btn btn-primary">
                            <i class="bi bi-arrow-clockwise"></i>
                            گەڕانەوە بۆ هەموو کاڵاکان
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($groupedProducts as $categoryName => $categoryProducts): ?>
                    <?php if ($websiteSettings['show_by_category'] && count($groupedProducts) > 1): ?>
                    <div class="col-12">
                        <h4 class="mb-3 mt-4">
                            <i class="bi bi-tag"></i>
                            <?php echo htmlspecialchars($categoryName); ?>
                            <span class="badge bg-primary"><?php echo count($categoryProducts); ?></span>
                        </h4>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $productIndex = 0;
                    foreach ($categoryProducts as $product): 
                        $productIndex++;
                        $productCurrency = $product['currency'] ?? 'IQD';
                        // Get all units for this product
                        $productUnits = getProductUnits($conn, $product['id']);
                        $hasMultipleUnits = count($productUnits) > 1;
                    ?>
                    <div class="col-lg-4 col-md-6 mb-4 product-item <?php echo $productIndex > 75 ? 'hidden-product' : ''; ?>" data-category="<?php echo htmlspecialchars($product['category_name'] ?? ''); ?>" data-product-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>" data-product-price="<?php echo $displayRetail ?? $product['retail_price'] ?? 0; ?>">
                        <div class="card product-card h-100 <?php echo $product['stock_quantity'] <= 0 ? 'out-of-stock' : ''; ?>" data-product-id="<?php echo $product['id']; ?>">
                            <?php if ($websiteSettings['show_product_images']): ?>
                            <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" class="text-decoration-none product-image-link">
                                <div class="position-relative product-image-wrapper">
                                    <img src="<?php echo getProductImage($product); ?>" 
                                         class="card-img-top" 
                                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                                         loading="lazy"
                                         decoding="async"
                                         onerror="if(!this.hasAttribute('data-error-handled')) { this.setAttribute('data-error-handled', 'true'); this.src='<?php echo SITE_URL; ?>web/template/assets/images/no-image.svg'; }">
                                    <?php if ($product['stock_quantity'] <= 0): ?>
                                    <div class="position-absolute top-0 end-0 m-2">
                                        <span class="badge bg-danger">تەواو بووە</span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="product-overlay">
                                        <button type="button" class="btn btn-sm btn-light quick-view-btn" data-product-id="<?php echo $product['id']; ?>" data-slug="<?php echo $slug; ?>">
                                            <i class="bi bi-eye"></i> بینینی خێرا
                                        </button>
                                    </div>
                                </div>
                            </a>
                            <?php else: ?>
                            <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" class="text-decoration-none">
                                <div class="product-placeholder">
                                    <i class="bi bi-box display-1 text-muted"></i>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title">
                                    <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" class="text-decoration-none text-dark">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </a>
                                </h5>
                                
                                <?php if (!empty($product['description'])): ?>
                                <p class="card-text text-muted small">
                                    <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" class="text-decoration-none text-muted">
                                        <?php 
                                        $description = strip_tags($product['description']);
                                        echo htmlspecialchars(mb_substr($description, 0, 100)) . (mb_strlen($description) > 100 ? '...' : ''); 
                                        ?>
                                    </a>
                                </p>
                                <?php endif; ?>
                                
                                <?php 
                                    // Initialize display unit as null (needed for prices, stock, and cart)
                                    $displayUnit = null;
                                    
                                    // Find primary unit or use first available
                                    if (!empty($productUnits)) {
                                        // First try to find the primary unit
                                        foreach ($productUnits as $unit) {
                                            if ($unit['is_primary']) {
                                                $displayUnit = $unit;
                                                break;
                                            }
                                        }
                                        // If no primary unit found, use first one
                                        if ($displayUnit === null && count($productUnits) > 0) {
                                            $displayUnit = $productUnits[0];
                                        }
                                    }
                                    $displayRetail = $displayUnit ? $displayUnit['sell_price'] : $product['retail_price'];
                                    $displayWholesale = $displayUnit ? $displayUnit['wholesale_price'] : $product['wholesale_price'];
                                    $displaySpecial = $displayUnit ? $displayUnit['special_price'] : $product['special_price'];
                                    // Calculate displayStock - always needed for cart validation
                                    $displayStock = (isset($displayUnit) && is_array($displayUnit) && isset($displayUnit['stock_quantity']))
                                        ? $displayUnit['stock_quantity']
                                        : $product['stock_quantity'];
                                ?>
                                
                                <?php if ($hasMultipleUnits): ?>
                                <!-- Unit Selector -->
                                <div class="mb-3 shop-unit-field">
                                    <label class="form-label mb-1">
                                        <span class="shop-unit-label-text">
                                            <i class="bi bi-box-seam"></i>
                                            یەکە:
                                        </span>
                                    </label>
                                    <select class="form-select form-select-sm unit-selector" data-product-id="<?php echo $product['id']; ?>">
                                        <?php foreach ($productUnits as $unit): ?>
                                        <option value="<?php echo $unit['id']; ?>" 
                                                data-retail-price="<?php echo $unit['sell_price'] ?? '0'; ?>"
                                                data-wholesale-price="<?php echo $unit['wholesale_price'] ?? '0'; ?>"
                                                data-special-price="<?php echo $unit['special_price'] ?? '0'; ?>"
                                                data-stock="<?php echo $unit['stock_quantity'] ?? '0'; ?>"
                                                data-unit-name="<?php echo htmlspecialchars($unit['unit_name'] ?? 'دانە'); ?>"
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
                                <div class="prices mt-auto" data-prices-container>
                                    <?php 
                                    // Check if product has discount price
                                    $hasDiscount = !empty($product['discount_price']) && $product['discount_price'] > 0;
                                    ?>
                                    <?php if ($websiteSettings['show_retail_price'] && !empty($displayRetail)): ?>
                                    <div class="price-item" data-price-type="retail">
                                        <span class="price-label">نرخ:</span>
                                        <?php if ($hasDiscount): ?>
                                            <span class="price-value text-decoration-line-through text-muted"><?php echo formatPrice($displayRetail, $productCurrency); ?></span>
                                        <?php else: ?>
                                            <span class="price-value"><?php echo formatPrice($displayRetail, $productCurrency); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($hasDiscount): ?>
                                    <div class="price-item special" data-price-type="discount">
                                        <span class="price-label">نرخی داشکاندن:</span>
                                        <span class="price-value text-danger fw-bold"><?php echo formatPrice($product['discount_price'], $productCurrency); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($websiteSettings['show_wholesale_price'] && !empty($displayWholesale)): ?>
                                    <div class="price-item" data-price-type="wholesale">
                                        <span class="price-label">نرخی جوملە:</span>
                                        <span class="price-value"><?php echo formatPrice($displayWholesale, $productCurrency); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($websiteSettings['show_special_price'] && !empty($displaySpecial)): ?>
                                    <div class="price-item special" data-price-type="special">
                                        <span class="price-label">نرخی تایبەت:</span>
                                        <span class="price-value"><?php echo formatPrice($displaySpecial, $productCurrency); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($websiteSettings['show_stock_quantity']): ?>
                                <div class="mt-2" data-stock-container>
                                    <?php
                                        // Use stock from the same displayUnit we used for prices
                                        $displayStock = (isset($displayUnit) && is_array($displayUnit) && isset($displayUnit['stock_quantity']))
                                            ? $displayUnit['stock_quantity']
                                            : $product['stock_quantity'];
                                    ?>
                                    <small class="shop-stock-line d-inline-flex align-items-baseline flex-wrap gap-1">
                                        <i class="bi bi-box shop-stock-label"></i>
                                        <span class="shop-stock-label">بەردەستی:</span>
                                        <span class="stock-quantity shop-stock-qty"><?php echo isset($displayStock) ? (int)$displayStock : 0; ?></span>
                                        <?php if ($displayUnit && !empty($displayUnit['unit_name'])): ?>
                                        <span class="shop-stock-label"><?php echo htmlspecialchars($displayUnit['unit_name']); ?></span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <!-- View Details and Add to Cart -->
                                <?php if ($product['stock_quantity'] > 0): ?>
                                <div class="mt-auto pt-3">
                                    <?php
                                        // دیاریکردنی نرخی بەکارهێنراو بۆ سەبەتە بەپێی نرخی پیشاندان
                                        // پێشەنگی: نرخی داشکاندن > نرخی تایبەت > نرخی جوملە > نرخی تاک
                                        $priceForCart = 0;
                                        
                                        // 1. نرخی داشکاندن (ئەگەر هەبێت)
                                        if (!empty($product['discount_price']) && $product['discount_price'] > 0) {
                                            $priceForCart = $product['discount_price'];
                                        }
                                        // 2. نرخی تایبەت (ئەگەر چالاک بێت و هەبێت)
                                        elseif ($websiteSettings['show_special_price'] && !empty($displaySpecial) && $displaySpecial > 0) {
                                            $priceForCart = $displaySpecial;
                                        }
                                        // 3. نرخی جوملە (ئەگەر چالاک بێت و هەبێت)
                                        elseif ($websiteSettings['show_wholesale_price'] && !empty($displayWholesale) && $displayWholesale > 0) {
                                            $priceForCart = $displayWholesale;
                                        }
                                        // 4. نرخی تاک (fallback)
                                        else {
                                            $priceForCart = $displayRetail ?? $product['retail_price'] ?? 0;
                                        }
                                        
                                        $unitIdForCart = $displayUnit ? $displayUnit['id'] : '';
                                        $unitNameForCart = $displayUnit ? $displayUnit['unit_name'] : 'دانە';
                                    ?>
                                    
                                    <?php if (!empty($product['description'])): ?>
                                    <a href="<?php echo SITE_URL; ?>web/product-details.php?slug=<?php echo $slug; ?>&id=<?php echo $product['id']; ?>" 
                                       class="btn btn-outline-primary w-100 mb-2">
                                        <i class="bi bi-eye"></i>
                                        بینینی وردەکاری
                                    </a>
                                    <?php endif; ?>
                                    
                                    <div class="shop-qty-widget w-100"
                                         data-shop-qty-widget
                                         data-product-id="<?php echo $product['id']; ?>"
                                         data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                         data-product-price="<?php echo $priceForCart; ?>"
                                         data-product-image="<?php echo getProductImage($product); ?>"
                                         data-product-unit-id="<?php echo htmlspecialchars((string) $unitIdForCart); ?>"
                                         data-product-unit="<?php echo htmlspecialchars($unitNameForCart); ?>"
                                         data-product-currency="<?php echo htmlspecialchars($productCurrency); ?>"
                                         data-shop-slug="<?php echo htmlspecialchars($slug); ?>"
                                         data-stock="<?php echo (int)$displayStock; ?>"
                                         data-retail-price="<?php echo $displayRetail ?? 0; ?>"
                                         data-wholesale-price="<?php echo $displayWholesale ?? 0; ?>"
                                         data-special-price="<?php echo $displaySpecial ?? 0; ?>"
                                         data-discount-price="<?php echo $product['discount_price'] ?? 0; ?>"
                                         data-show-retail="<?php echo $websiteSettings['show_retail_price'] ? '1' : '0'; ?>"
                                         data-show-wholesale="<?php echo $websiteSettings['show_wholesale_price'] ? '1' : '0'; ?>"
                                         data-show-special="<?php echo $websiteSettings['show_special_price'] ? '1' : '0'; ?>">
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
                                        <span class="shop-stock-unit" data-shop-qty-unit-label><?php echo htmlspecialchars($unitNameForCart); ?></span>
                                        <button type="button" class="btn btn-outline-secondary shop-qty-inc" aria-label="زیادکردنی بڕ">
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="mt-auto pt-3">
                                    <button class="btn btn-secondary w-100" disabled>
                                        <i class="bi bi-x-circle"></i>
                                        تەواو بووە
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Load More Button -->
        <?php if (count($products) > 75): ?>
        <div class="text-center mt-4 mb-5" id="loadMoreContainer">
            <button class="btn btn-primary btn-lg load-more-btn" id="loadMoreBtn">
                <i class="bi bi-arrow-down-circle"></i>
                <span class="btn-text">بینینی زیاتر</span>
                <span class="badge bg-light text-primary ms-2"><?php echo count($products) - 75; ?>+</span>
            </button>
            <p class="text-muted mt-2 small">
                <i class="bi bi-info-circle"></i>
                <?php echo count($products) - 75; ?> کاڵای تر هەیە
            </p>
        </div>
        <?php endif; ?>
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
            
            <?php if ($isCustomerLoggedIn): ?>
            <div class="cart-notes-section px-3 pb-2">
                <label for="cartNotes" class="form-label small text-muted mb-1">
                    <i class="bi bi-pencil"></i> تێبینی
                </label>
                <textarea id="cartNotes" class="form-control form-control-sm" rows="2"
                          placeholder="تێبینی تایبەت بۆ داواکارییەکە..."></textarea>
            </div>
            <?php endif; ?>

            <div class="cart-actions">
                <div class="cart-actions-primary">
                    <?php if ($isCustomerLoggedIn): ?>
                    <button type="button" class="btn-checkout-direct" onclick="submitDirectCheckout()">
                        <i class="bi bi-check-circle"></i>
                          ناردن
                    </button>
                    <?php else: ?>
                    <a href="<?php echo SITE_URL; ?>web/checkout.php?shop=<?php echo htmlspecialchars($slug); ?>" class="btn-checkout">
                        <i class="bi bi-credit-card"></i>
                        تەواوکردنی داواکاری
                    </a>
                    <?php endif; ?>
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
                    واتسئاپ چالاکە بەڵام ژمارەی تەلەفۆنت لە پرۆفایل دیاری نەکردووە
                </small>
                <?php endif; ?>
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

    <?php if ($isCustomerLoggedIn && $loggedCustomerData): ?>
    <form id="directCheckoutForm" method="POST"
          action="<?php echo SITE_URL; ?>web/checkout.php?shop=<?php echo htmlspecialchars($slug); ?>"
          style="display:none;">
        <input type="hidden" name="customer_name"    value="<?php echo htmlspecialchars($loggedCustomerData['name']); ?>">
        <input type="hidden" name="customer_phone"   value="<?php echo htmlspecialchars($loggedCustomerData['phone']); ?>">
        <input type="hidden" name="customer_address" value="<?php echo htmlspecialchars($loggedCustomerData['address']); ?>">
        <input type="hidden" name="notes"            id="directCheckoutNotes" value="">
        <input type="hidden" name="cart_data"        id="directCheckoutCartData" value="">
        <input type="hidden" name="website_slug"     value="<?php echo htmlspecialchars($slug); ?>">
        <input type="hidden" name="request_token"    value="<?php echo htmlspecialchars($checkoutRequestToken); ?>">
    </form>
    <script>
    function submitDirectCheckout() {
        const cart = window.shoppingCart;
        if (!cart || cart.cart.length === 0) {
            alert('سەبەتەکەت بەتاڵە');
            return;
        }
        const notesEl = document.getElementById('cartNotes');
        document.getElementById('directCheckoutNotes').value = notesEl ? notesEl.value : '';
        document.getElementById('directCheckoutCartData').value = JSON.stringify(cart.cart);
        document.getElementById('directCheckoutForm').submit();
    }
    </script>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="bg-dark text-light py-5 mt-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <h5 class="mb-3">
                        <i class="bi bi-shop"></i>
                        <?php echo htmlspecialchars($businessName); ?>
                    </h5>
                    <p class="text-white small">
                        فرۆشگای ئۆنلاینی پرۆفیشناڵ بۆ کڕینی کاڵا و بەرهەمە جیاوازەکان
                    </p>
                    <?php if (!empty($websiteSettings['phone'])): ?>
                    <p class="mb-2">
                        <i class="bi bi-telephone"></i>
                        <a href="tel:<?php echo htmlspecialchars($websiteSettings['phone']); ?>" class="text-light text-decoration-none">
                            <?php echo htmlspecialchars($websiteSettings['phone']); ?>
                        </a>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($websiteSettings['email'])): ?>
                    <p class="mb-2">
                        <i class="bi bi-envelope"></i>
                        <a href="mailto:<?php echo htmlspecialchars($websiteSettings['email']); ?>" class="text-light text-decoration-none">
                            <?php echo htmlspecialchars($websiteSettings['email']); ?>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <h5 class="mb-3">بەستەرەکان</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <a href="<?php echo SITE_URL; ?>web/" class="text-light text-decoration-none">
                                <i class="bi bi-arrow-left"></i>
                                گەڕانەوە بۆ فرۆشگاکان
                            </a>
                        </li>
                        <?php if (CustomerSession::isLoggedIn()): ?>
                        <li class="mb-2">
                            <a href="<?php echo SITE_URL; ?>web/my-orders.php" class="text-light text-decoration-none">
                                <i class="bi bi-list-ul"></i>
                                داواکارییەکانم
                            </a>
                        </li>
                        <?php else: ?>
                        <li class="mb-2">
                            <a href="<?php echo SITE_URL; ?>web/my-orders-guest.php" class="text-light text-decoration-none">
                                <i class="bi bi-list-ul"></i>
                                داواکارییەکانی میوان
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div class="col-lg-4 col-md-12">
                    <h5 class="mb-3">پاڵپشتی</h5>
                    <p class="text-white small mb-3">
                        <i class="bi bi-shield-check"></i>
                        پاڵپشتی لەلایەن <?php echo SITE_NAME; ?>
                    </p>
                    <div class="d-flex gap-2">
                        <span class="badge bg-primary">
                            <i class="bi bi-check-circle"></i>
                            ئۆنلاین
                        </span>
                        <span class="badge bg-success">
                            <i class="bi bi-lightning-charge"></i>
                            چالاک
                        </span>
                    </div>
                </div>
            </div>
            
            <hr class="my-4 bg-secondary">
            
         
        </div>
    </footer>

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

    <!-- QR Code Modal -->
    <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="qrCodeModalLabel">
                        <i class="bi bi-qr-code"></i>
                        QR Code ی فرۆشگا
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <!-- Shop Name -->
                    <h4 class="mb-3 fw-bold text-primary">
                        <i class="bi bi-shop"></i>
                        <?php echo htmlspecialchars($businessName); ?>
                    </h4>
                    
                    <p class="text-muted mb-4">ئەم QR Code ە سکان بکە بۆ گەیشتن بە فرۆشگا</p>
                    
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
        <span>لینکی فرۆشگا کۆپی کرا!</span>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        // Shop configuration
        window.SHOP_CONFIG = {
            slug: '<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>',
            siteUrl: '<?php echo SITE_URL; ?>',
            sortBy: '<?php echo htmlspecialchars($sortBy); ?>',
            viewMode: '<?php echo htmlspecialchars($viewMode); ?>',
            categoryFilter: '<?php echo htmlspecialchars($categoryFilter); ?>'
        };
        
        // Dismiss announcement banner (per browser session)
        document.addEventListener('DOMContentLoaded', function() {
            const announcement = document.querySelector('.shop-announcement');
            if (!announcement) return;
            const closeBtn = announcement.querySelector('.shop-announcement-close');
            if (!closeBtn) return;
            closeBtn.addEventListener('click', function() {
                announcement.style.display = 'none';
            });
        });

        // Copy Shop Link Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const copyBtn = document.getElementById('copyShopLinkBtn');
            const copyToast = document.getElementById('copyToast');
            const shopUrl = '<?php echo SITE_URL; ?>web/<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>/';
            
            if (copyBtn) {
                copyBtn.addEventListener('click', async function() {
                    try {
                        await navigator.clipboard.writeText(shopUrl);
                        
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
                        textArea.value = shopUrl;
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
                            alert('لینک: ' + shopUrl);
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
                                text: shopUrl,
                                width: 300,
                                height: 300,
                                colorDark: "#000000",
                                colorLight: "#ffffff",
                                correctLevel: QRCode.CorrectLevel.H
                            });
                            
                            qrCodeGenerated = true;
                        } catch (error) {
                            console.error('QR Code generation error:', error);
                            container.innerHTML = '<div class="alert alert-danger">هەڵەیەک ڕوویدا لە درووستکردنی QR Code</div>';
                        }
                    }
                });
                
                // Reset when modal is closed
                qrModal.addEventListener('hidden.bs.modal', function() {
                    // Don't reset, keep the QR code for reuse
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
                                    link.download = 'qrcode-<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>.png';
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
                                    link.download = 'qrcode-<?php echo htmlspecialchars($slug, ENT_QUOTES); ?>.png';
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
        
        // Enhanced Search Box Interaction
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            const searchForm = document.querySelector('.search-box form');
            const inputGroup = document.querySelector('.search-box .input-group');
            
            if (searchInput && inputGroup) {
                // Add focus animation
                searchInput.addEventListener('focus', function() {
                    inputGroup.style.transform = 'translateY(-2px)';
                });
                
                searchInput.addEventListener('blur', function() {
                    inputGroup.style.transform = 'translateY(0)';
                });
                
                // Add typing animation
                let typingTimer;
                searchInput.addEventListener('input', function() {
                    clearTimeout(typingTimer);
                    if (this.value.length > 0) {
                        inputGroup.style.borderColor = 'var(--primary-color)';
                    }
                    typingTimer = setTimeout(function() {
                        // Could add auto-search here if needed
                    }, 500);
                });
                
                // Prevent empty search
                searchForm.addEventListener('submit', function(e) {
                    if (searchInput.value.trim() === '') {
                        e.preventDefault();
                        searchInput.focus();
                        inputGroup.style.animation = 'shake 0.5s';
                        setTimeout(function() {
                            inputGroup.style.animation = '';
                        }, 500);
                    }
                });
            }
        });
    </script>
    
    <style>
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        /* Image Optimization for Performance */
        .product-image-wrapper img {
            will-change: transform;
            transform: translateZ(0);
            backface-visibility: hidden;
        }
        
        /* Load More Button Styles */
        .hidden-product {
            display: none !important;
        }
        
        .load-more-btn {
            position: relative;
            padding: 14px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            border: none;
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.3);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .load-more-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(13, 110, 253, 0.4);
            background: linear-gradient(135deg, #0a58ca 0%, #084298 100%);
        }
        
        .load-more-btn:active {
            transform: translateY(-1px);
        }
        
        .load-more-btn i {
            font-size: 1.3rem;
            animation: bounce 2s infinite;
        }
        
        .load-more-btn .badge {
            font-size: 0.85rem;
            padding: 0.35rem 0.65rem;
            border-radius: 20px;
        }
        
        .load-more-btn.loading .btn-text {
            display: none;
        }
        
        .load-more-btn.loading::after {
            content: "چاوەڕوانبە...";
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-8px);
            }
            60% {
                transform: translateY(-4px);
            }
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
        
        .product-item.show-animation {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        #loadMoreContainer {
            animation: fadeInUp 0.6s ease;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .load-more-btn {
                padding: 12px 30px;
                font-size: 1rem;
            }
            
            .load-more-btn i {
                font-size: 1.1rem;
            }
        }
    </style>
    
    <script>
        // Load More Products Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const loadMoreBtn = document.getElementById('loadMoreBtn');
            const loadMoreContainer = document.getElementById('loadMoreContainer');
            const itemsPerLoad = 75; // Load 75 items at a time
            const shopVideosContainer = document.querySelector('[data-shop-videos-container]');
            const shopVideoToggleBtn = document.querySelector('[data-shop-video-toggle]');
            
            if (shopVideosContainer && shopVideoToggleBtn) {
                const allVideoItems = Array.from(shopVideosContainer.querySelectorAll('[data-shop-video-item]'));
                const toggleText = shopVideoToggleBtn.querySelector('[data-shop-video-toggle-text]');
                const toggleIcon = shopVideoToggleBtn.querySelector('[data-shop-video-toggle-icon]');
                const toggleBadge = shopVideoToggleBtn.querySelector('[data-shop-video-toggle-badge]');
                const toggleHint = shopVideoToggleBtn.querySelector('[data-shop-video-toggle-hint]');
                const expandText = shopVideoToggleBtn.getAttribute('data-expand-text') || 'بینینی ڤیدیۆکانی تر';
                const collapseText = shopVideoToggleBtn.getAttribute('data-collapse-text') || 'شاردنەوەی ڤیدیۆکان';
                const hiddenHintSuffix = shopVideoToggleBtn.getAttribute('data-hidden-hint') || 'ڤیدیۆی تر شاراوەیە';
                const expandedHintText = shopVideoToggleBtn.getAttribute('data-expanded-hint') || 'هەموو ڤیدیۆکان دەرکەوتن';
                const mobileVisibleCount = parseInt(shopVideoToggleBtn.getAttribute('data-mobile-visible') || '2', 10);
                const desktopVisibleCount = parseInt(shopVideoToggleBtn.getAttribute('data-desktop-visible') || '5', 10);
                const desktopMedia = window.matchMedia('(min-width: 992px)');
                let isExpanded = false;

                function applyShopVideoState() {
                    const visibleCount = desktopMedia.matches ? desktopVisibleCount : mobileVisibleCount;
                    let hiddenCount = 0;

                    allVideoItems.forEach(function(item, index) {
                        const shouldHide = !isExpanded && index >= visibleCount;
                        item.classList.toggle('shop-video-hidden', shouldHide);

                        if (shouldHide) {
                            hiddenCount++;
                            item.classList.remove('shop-video-reveal');
                        } else if (isExpanded) {
                            item.classList.add('shop-video-reveal');
                        } else {
                            item.classList.remove('shop-video-reveal');
                        }
                    });

                    shopVideoToggleBtn.style.display = hiddenCount === 0 && !isExpanded ? 'none' : '';
                    shopVideoToggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');

                    if (toggleText) {
                        toggleText.textContent = isExpanded ? collapseText : expandText;
                    }
                    if (toggleIcon) {
                        toggleIcon.className = isExpanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
                    }
                    if (toggleBadge) {
                        toggleBadge.style.display = isExpanded ? 'none' : 'inline-flex';
                        toggleBadge.textContent = '+' + hiddenCount;
                    }
                    if (toggleHint) {
                        toggleHint.textContent = isExpanded ? expandedHintText : (hiddenCount + ' ' + hiddenHintSuffix);
                    }
                }

                applyShopVideoState();

                shopVideoToggleBtn.addEventListener('click', function() {
                    isExpanded = !isExpanded;
                    applyShopVideoState();
                });

                desktopMedia.addEventListener('change', function() {
                    if (!isExpanded) {
                        applyShopVideoState();
                    }
                });
            }
            
            if (loadMoreBtn) {
                loadMoreBtn.addEventListener('click', function() {
                    // Add loading state
                    loadMoreBtn.classList.add('loading');
                    loadMoreBtn.disabled = true;
                    
                    // Get all hidden products
                    const hiddenProducts = document.querySelectorAll('.product-item.hidden-product');
                    
                    // Calculate how many to show
                    const itemsToShow = Math.min(itemsPerLoad, hiddenProducts.length);
                    const remainingItems = hiddenProducts.length - itemsToShow;
                    
                    // Show products with animation
                    setTimeout(() => {
                        for (let i = 0; i < itemsToShow; i++) {
                            setTimeout(() => {
                                hiddenProducts[i].classList.remove('hidden-product');
                                hiddenProducts[i].classList.add('show-animation');
                            }, i * 30); // Stagger animation (faster)
                        }
                        
                        // Update button or hide it
                        setTimeout(() => {
                            loadMoreBtn.classList.remove('loading');
                            loadMoreBtn.disabled = false;
                            
                            if (remainingItems > 0) {
                                // Update badge with remaining count
                                const badge = loadMoreBtn.querySelector('.badge');
                                if (badge) {
                                    badge.textContent = remainingItems + '+';
                                }
                                
                                // Update info text
                                const infoText = loadMoreContainer.querySelector('.text-muted');
                                if (infoText) {
                                    infoText.innerHTML = '<i class="bi bi-info-circle"></i> ' + remainingItems + ' کاڵای تر هەیە';
                                }
                                
                                // Smooth scroll to first newly shown item
                                setTimeout(() => {
                                    hiddenProducts[0].scrollIntoView({ 
                                        behavior: 'smooth', 
                                        block: 'center' 
                                    });
                                }, 100);
                            } else {
                                // No more items, hide button
                                loadMoreContainer.style.animation = 'fadeOut 0.5s ease';
                                setTimeout(() => {
                                    loadMoreContainer.style.display = 'none';
                                }, 500);
                            }
                        }, itemsToShow * 30 + 300);
                        
                    }, 300);
                });
            }
            
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
        });
    </script>
    
    <style>
        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(-20px);
            }
        }
    </style>
    
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/shop.js?v=<?php echo time(); ?>"></script>
    <script>
        window.shopCartWhatsApp = <?php echo json_encode($shopWhatsAppCartConfig, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo SITE_URL; ?>web/template/assets/js/cart.js?v=<?php echo time(); ?>"></script>

</body>
</html>
