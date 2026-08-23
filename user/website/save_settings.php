<?php
/**
 * API بۆ هەڵگرتنی ڕێکخستنەکان - user/website/save_settings.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// تاقیکردنی ئەوەی یوزەر سەرەکیە (نەک لاوەکی)
if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دەسەڵات نەماوە']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'ڕێگەیەکی نادروست']);
    exit;
}

// CSRF token validation
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'نادروستی ئامنیەتی']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create_website') {
        $websiteSlug = cleanInput($_POST['website_slug'] ?? '');
        
        if (empty($websiteSlug)) {
            throw new Exception('تکایە ناوی وێب سایت داخڵ بکە');
        }
        
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $websiteSlug)) {
            throw new Exception('ناوی وێب سایت تەنها پیت و ژمارە و _ و - دەتوانێت بێت');
        }
        
        // تاقیکردنی یەکتابوونی slug
        $checkStmt = $conn->prepare("SELECT id FROM website_settings WHERE website_slug = ?");
        $checkStmt->bind_param("s", $websiteSlug);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            throw new Exception('ئەم ناوە پێشتر بەکارهاتووە، ناوی تر هەڵبژێرە');
        }
        $checkStmt->close();
        
        // دروستکردنی ڕێکخستنەکان
        $insertStmt = $conn->prepare("INSERT INTO website_settings (user_id, website_slug, is_active, show_product_images, show_prices, show_retail_price, show_wholesale_price, show_special_price, show_by_category) VALUES (?, ?, 0, 1, 1, 1, 1, 0, 1)");
        $insertStmt->bind_param("is", $userId, $websiteSlug);
        
        if ($insertStmt->execute()) {
            // دروستکردنی فۆڵدەری وێب سایت
            $webPath = dirname(__DIR__, 2) . "/web/{$websiteSlug}";
            if (!file_exists($webPath)) {
                mkdir($webPath, 0755, true);
                
                // دروستکردنی فایلی index.php بۆ وێب سایت
                $templateContent = generateWebsiteTemplate($websiteSlug);
                file_put_contents($webPath . '/index.php', $templateContent);
                
                // دروستکردنی فۆڵدەری assets
                mkdir($webPath . '/assets', 0755, true);
                mkdir($webPath . '/assets/css', 0755, true);
                mkdir($webPath . '/assets/js', 0755, true);
                
                // دروستکردنی CSS فایل
                $cssContent = generateWebsiteCSS();
                file_put_contents($webPath . '/assets/css/shop.css', $cssContent);
                
                // دروستکردنی JS فایل
                $jsContent = generateWebsiteJS();
                file_put_contents($webPath . '/assets/js/shop.js', $jsContent);
            }
            
            writeLog("Website created by user {$currentUser['email']}: {$websiteSlug}");
            echo json_encode(['success' => true, 'message' => 'وێب سایت بە سەرکەوتوویی دروستکرا', 'website_url' => "https://169.58.0.215/web/{$websiteSlug}"]);
        } else {
            throw new Exception('هەڵەیەک ڕوویدا لە دروستکردنی وێب سایت');
        }
        $insertStmt->close();
    }
    
    elseif ($action === 'update_settings') {
        $showProductImages = isset($_POST['show_product_images']) ? 1 : 0;
        $showPrices = isset($_POST['show_prices']) ? 1 : 0;
        $showRetailPrice = isset($_POST['show_retail_price']) ? 1 : 0;
        $showWholesalePrice = isset($_POST['show_wholesale_price']) ? 1 : 0;
        $showSpecialPrice = isset($_POST['show_special_price']) ? 1 : 0;
        $showByCategory = isset($_POST['show_by_category']) ? 1 : 0;
        
        $updateStmt = $conn->prepare("UPDATE website_settings SET show_product_images = ?, show_prices = ?, show_retail_price = ?, show_wholesale_price = ?, show_special_price = ?, show_by_category = ? WHERE user_id = ?");
        $updateStmt->bind_param("iiiiiii", $showProductImages, $showPrices, $showRetailPrice, $showWholesalePrice, $showSpecialPrice, $showByCategory, $userId);
        
        if ($updateStmt->execute()) {
            writeLog("Website settings updated by user {$currentUser['email']}");
            echo json_encode(['success' => true, 'message' => 'ڕێکخستنەکان بە سەرکەوتوویی نوێکرانەوە']);
        } else {
            throw new Exception('هەڵەیەک ڕوویدا لە نوێکردنەوەی ڕێکخستنەکان');
        }
        $updateStmt->close();
    }
    
    elseif ($action === 'toggle_active') {
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $toggleStmt = $conn->prepare("UPDATE website_settings SET is_active = ? WHERE user_id = ?");
        $toggleStmt->bind_param("ii", $isActive, $userId);
        
        if ($toggleStmt->execute()) {
            $message = $isActive ? 'وێب سایت چالاککرا' : 'وێب سایت ناچالاککرا';
            writeLog("Website " . ($isActive ? "activated" : "deactivated") . " by user {$currentUser['email']}");
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            throw new Exception('هەڵەیەک ڕوویدا لە گۆڕینی دۆخی وێب سایت');
        }
        $toggleStmt->close();
    }
    
    else {
        throw new Exception('کرداری نادروست');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * دروستکردنی تێمپلەیتی وێب سایت
 */
function generateWebsiteTemplate($websiteSlug) {
    return '<?php
/**
 * وێب سایتی فرۆشگا - ' . $websiteSlug . '
 */

require_once \'../../config/config.php\';

$websiteSlug = \'' . $websiteSlug . '\';

// وەرگرتنی ڕێکخستنەکانی وێب سایت
$stmt = $conn->prepare("SELECT * FROM website_settings WHERE website_slug = ?");
$stmt->bind_param("s", $websiteSlug);
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();
$stmt->close();

if (!$settings || !$settings[\'is_active\']) {
    http_response_code(404);
    die(\'وێب سایت نەدۆزرایەوە یان ناچالاکە\');
}

// وەرگرتنی کاڵاکان
$whereConditions = ["p.user_id = (SELECT user_id FROM website_settings WHERE website_slug = ?)"];
$params = [$websiteSlug];
$paramTypes = "s";

// فلتەرکردن بە کەتەلۆگ
$categoryFilter = $_GET[\'category\'] ?? \'\';
if (!empty($categoryFilter) && $categoryFilter !== \'all\') {
    $whereConditions[] = "p.category_id = ?";
    $params[] = (int)$categoryFilter;
    $paramTypes .= "i";
}

$whereClause = implode(" AND ", $whereConditions);

$query = "SELECT p.id, p.name, p.barcode,
                 COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
                 COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
                 COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
                 p.image_path,
                 c.name as category_name,
                 COALESCE(wpv.is_visible, 1) as is_visible
          FROM products p
          LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
          LEFT JOIN product_units pu_any ON pu_any.id = (
              SELECT pu2.id FROM product_units pu2
              WHERE pu2.product_id = p.id
              ORDER BY pu2.is_primary DESC, pu2.id ASC
              LIMIT 1
          )
          LEFT JOIN categories c ON p.category_id = c.id
          LEFT JOIN website_product_visibility wpv ON (p.id = wpv.product_id AND wpv.user_id = (SELECT user_id FROM website_settings WHERE website_slug = ?))
          WHERE {$whereClause} AND COALESCE(wpv.is_visible, 1) = 1
          ORDER BY p.name ASC";

$params[] = $websiteSlug;
$paramTypes .= "s";

$stmt = $conn->prepare($query);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// وەرگرتنی کەتەلۆگەکان
$categoriesStmt = $conn->prepare("SELECT c.id, c.name, COUNT(p.id) as product_count 
                                  FROM categories c 
                                  LEFT JOIN products p ON c.id = p.category_id AND p.user_id = (SELECT user_id FROM website_settings WHERE website_slug = ?)
                                  WHERE c.user_id = (SELECT user_id FROM website_settings WHERE website_slug = ?)
                                  GROUP BY c.id, c.name
                                  HAVING product_count > 0
                                  ORDER BY c.name ASC");
$categoriesStmt->bind_param("ss", $websiteSlug, $websiteSlug);
$categoriesStmt->execute();
$categories = $categoriesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$categoriesStmt->close();

// وەرگرتنی زانیاری یوزەر
$userStmt = $conn->prepare("SELECT u.business_name FROM users u 
                           INNER JOIN website_settings ws ON u.id = ws.user_id 
                           WHERE ws.website_slug = ?");
$userStmt->bind_param("s", $websiteSlug);
$userStmt->execute();
$userInfo = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($userInfo[\'business_name\']); ?> - فرۆشگای ئۆنلاین</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/shop.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-shop"></i>
                <?php echo htmlspecialchars($userInfo[\'business_name\']); ?>
            </a>
        </div>
    </nav>

    <div class="container py-4">
        
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12 text-center">
                <h1 class="display-4 mb-3"><?php echo htmlspecialchars($userInfo[\'business_name\']); ?></h1>
                <p class="lead text-muted">فرۆشگای ئۆنلاین</p>
            </div>
        </div>

        <!-- Categories Filter -->
        <?php if (!empty($categories) && $settings[\'show_by_category\']): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">کەتەلۆگەکان</h5>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="?" class="btn btn-outline-primary <?php echo empty($categoryFilter) ? \'active\' : \'\'; ?>">
                                هەموو کاڵاکان
                            </a>
                            <?php foreach ($categories as $category): ?>
                                <a href="?category=<?php echo $category[\'id\']; ?>" 
                                   class="btn btn-outline-primary <?php echo $categoryFilter == $category[\'id\'] ? \'active\' : \'\'; ?>">
                                    <?php echo htmlspecialchars($category[\'name\']); ?>
                                    <span class="badge bg-secondary ms-1"><?php echo $category[\'product_count\']; ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Products Grid -->
        <div class="row">
            <?php if (empty($products)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-box-seam display-1 text-muted"></i>
                    <h4 class="mt-3">هیچ کاڵایەک نەدۆزرایەوە</h4>
                    <p class="text-muted">کاڵاکان بە زوویی زیاد دەکرێن</p>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-4">
                        <div class="card product-card h-100">
                            <?php if ($settings[\'show_product_images\'] && $product[\'image_path\'] && file_exists($product[\'image_path\'])): ?>
                                <img src="../../<?php echo $product[\'image_path\']; ?>" 
                                     class="card-img-top" 
                                     alt="<?php echo htmlspecialchars($product[\'name\']); ?>"
                                     style="height: 200px; object-fit: cover;">
                            <?php else: ?>
                                <div class="card-img-top bg-light d-flex align-items-center justify-content-center" 
                                     style="height: 200px;">
                                    <i class="bi bi-image display-4 text-muted"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title"><?php echo htmlspecialchars($product[\'name\']); ?></h5>
                                
                                <?php if ($product[\'barcode\']): ?>
                                    <p class="card-text">
                                        <small class="text-muted">بارکۆد: <?php echo htmlspecialchars($product[\'barcode\']); ?></small>
                                    </p>
                                <?php endif; ?>
                                
                                <?php if ($settings[\'show_prices\']): ?>
                                    <div class="prices mt-auto">
                                        <?php if ($settings[\'show_retail_price\'] && $product[\'sell_price\'] > 0): ?>
                                            <div class="price-item">
                                                <span class="price-label">نرخی تاک:</span>
                                                <span class="price-value"><?php echo number_format($product[\'sell_price\']); ?> دینار</span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($settings[\'show_wholesale_price\'] && $product[\'wholesale_price\'] > 0): ?>
                                            <div class="price-item">
                                                <span class="price-label">نرخی جوملە:</span>
                                                <span class="price-value"><?php echo number_format($product[\'wholesale_price\']); ?> دینار</span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($settings[\'show_special_price\'] && $product[\'special_price\'] > 0): ?>
                                            <div class="price-item special">
                                                <span class="price-label">نرخی تایبەت:</span>
                                                <span class="price-value"><?php echo number_format($product[\'special_price\']); ?> دینار</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/shop.js"></script>

</body>
</html>';
}

/**
 * دروستکردنی CSS فایل
 */
function generateWebsiteCSS() {
    return '/* Shop CSS */
.product-card {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.2);
}

.prices {
    margin-top: auto;
}

.price-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.25rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.price-item:last-child {
    border-bottom: none;
}

.price-item.special {
    background-color: #fff3cd;
    padding: 0.5rem;
    border-radius: 0.25rem;
    margin-top: 0.5rem;
}

.price-label {
    font-weight: 500;
    color: #666;
}

.price-value {
    font-weight: bold;
    color: #28a745;
}

.navbar-brand {
    font-weight: bold;
}

.card-img-top {
    border-radius: 0.375rem 0.375rem 0 0;
}

.btn-outline-primary.active {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: white;
}

@media (max-width: 768px) {
    .display-4 {
        font-size: 2rem;
    }
    
    .lead {
        font-size: 1rem;
    }
}';
}

/**
 * دروستکردنی JavaScript فایل
 */
function generateWebsiteJS() {
    return '// Shop JavaScript
document.addEventListener(\'DOMContentLoaded\', function() {
    // Add any interactive features here
    console.log(\'Shop loaded successfully\');
    
    // Smooth scrolling for anchor links
    document.querySelectorAll(\'a[href^="#"]\').forEach(anchor => {
        anchor.addEventListener(\'click\', function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute(\'href\')).scrollIntoView({
                behavior: \'smooth\'
            });
        });
    });
});';
}
