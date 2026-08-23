<?php
/**
 * ڕێکخستنی وێب سایت - user/website/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once __DIR__ . '/../../web/auth/shop_google_access.php';
require_once __DIR__ . '/../../web/includes/shop_announcement.php';

// پەڕەی ڕێکخستن داینامیکە — هەرگیز کاش نەکرێت تاکو گۆڕانکاریەکان یەکسەر دەربکەون.
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function deleteShopBannerFromSpaces(?string $bannerUrl): void
{
    spaces_delete_object_from_public_url($bannerUrl);
}

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

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'settings.view', [
    'route' => '/user/website/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

// تاقیکردنی ئەوەی یوزەر سەرەکیە (نەک لاوەکی)
if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    header('Location: ' . url('user/dashboard/index.php'));
    exit;
}

$success = '';
$error = '';
$websiteSettings = null;

shop_google_ensure_db_schema($conn);

// وەرگرتنی ڕێکخستنەکانی وێب سایت
$stmt = $conn->prepare("SELECT * FROM website_settings WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$websiteSettings = $result->fetch_assoc();
$stmt->close();

// دروستکردنی ستونی product_display_order ئەگەر نەبوو
$checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'product_display_order'");
if ($checkColumnStmt->num_rows == 0) {
    $conn->query("ALTER TABLE website_settings ADD COLUMN product_display_order VARCHAR(20) DEFAULT 'random' AFTER show_stock_quantity");
}
$checkColumnStmt->close();

$checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_shop_exit_button'");
if ($checkColumnStmt->num_rows == 0) {
    $conn->query("ALTER TABLE website_settings ADD COLUMN show_shop_exit_button TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=show دەرچوون in shop nav' AFTER shop_banner");
}
$checkColumnStmt->close();

$checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'enable_whatsapp_order'");
if ($checkColumnStmt->num_rows == 0) {
    $conn->query("ALTER TABLE website_settings ADD COLUMN enable_whatsapp_order TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=دوگمەی واتسئاپ لە سەبەتەی کڕین' AFTER show_shop_exit_button");
}
$checkColumnStmt->close();

shop_announcement_ensure_columns($conn);
// دووبارە وەرگرتنی ڕێکخستنەکان بۆ ئەوەی ستونە نوێیەکان هەبن
if (empty($websiteSettings) || !array_key_exists('shop_announcement', $websiteSettings)) {
    $stmt = $conn->prepare("SELECT * FROM website_settings WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $reloaded = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($reloaded) {
        $websiteSettings = $reloaded;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    }
    
    elseif ($action === 'create_website') {
        $websiteSlug = cleanInput($_POST['website_slug'] ?? '');
        
        if (empty($websiteSlug)) {
            $error = 'تکایە ناوی وێب سایت داخڵ بکە';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $websiteSlug)) {
            $error = 'ناوی وێب سایت تەنها پیت و ژمارە و _ و - دەتوانێت بێت';
        } else {
            // تاقیکردنی یەکتابوونی slug
            $checkStmt = $conn->prepare("SELECT id FROM website_settings WHERE website_slug = ?");
            $checkStmt->bind_param("s", $websiteSlug);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                $error = 'ئەم ناوە پێشتر بەکارهاتووە، ناوی تر هەڵبژێرە';
            } else {
                // تاقیکردنی ئەوەی ستونی show_on_index هەیە یان نا
                $checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_on_index'");
                $hasShowOnIndexColumn = $checkColumnStmt->num_rows > 0;
                $checkColumnStmt->close();
                
                // تاقیکردنی ئەوەی ستونی product_display_order هەیە یان نا
                $checkProductOrderStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'product_display_order'");
                $hasProductDisplayOrderColumn = $checkProductOrderStmt->num_rows > 0;
                $checkProductOrderStmt->close();
                
                $checkExitBtnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_shop_exit_button'");
                $hasShowShopExitButtonColumn = $checkExitBtnStmt->num_rows > 0;
                $checkExitBtnStmt->close();
                
                // دروستکردنی ڕێکخستنەکان
                $columns = ['user_id', 'website_slug', 'is_active', 'show_product_images', 'show_prices', 'show_retail_price', 'show_wholesale_price', 'show_special_price', 'show_by_category', 'show_only_with_images', 'show_stock_quantity'];
                $values = ['?', '?', '0', '1', '1', '1', '1', '0', '1', '0', '1'];
                $bindTypes = "is";
                $bindParams = [$userId, $websiteSlug];
                
                if ($hasShowOnIndexColumn) {
                    $columns[] = 'show_on_index';
                    $values[] = '0';
                }
                
                if ($hasProductDisplayOrderColumn) {
                    $columns[] = 'product_display_order';
                    $values[] = '?';
                    $bindTypes .= 's';
                    $bindParams[] = 'random';
                }
                
                if ($hasShowShopExitButtonColumn) {
                    $columns[] = 'show_shop_exit_button';
                    $values[] = '1';
                }
                
                $columnsStr = implode(', ', $columns);
                $valuesStr = implode(', ', $values);
                $insertStmt = $conn->prepare("INSERT INTO website_settings ($columnsStr) VALUES ($valuesStr)");
                $insertStmt->bind_param($bindTypes, ...$bindParams);
                
                if ($insertStmt->execute()) {
                    $success = 'وێب سایت بە سەرکەوتوویی دروستکرا';
                    // نوێکردنەوەی داتاکان
                    $websiteSettings = [
                        'id' => $conn->insert_id,
                        'user_id' => $userId,
                        'website_slug' => $websiteSlug,
                        'is_active' => 0,
                        'show_product_images' => 1,
                        'show_prices' => 1,
                        'show_retail_price' => 1,
                        'show_wholesale_price' => 1,
                        'show_special_price' => 0,
                        'show_by_category' => 1,
                        'show_only_with_images' => 0,
                        'show_stock_quantity' => 1
                    ];
                    if ($hasShowOnIndexColumn) {
                        $websiteSettings['show_on_index'] = 0;
                    }
                    if ($hasProductDisplayOrderColumn) {
                        $websiteSettings['product_display_order'] = 'random';
                    }
                    if ($hasShowShopExitButtonColumn) {
                        $websiteSettings['show_shop_exit_button'] = 1;
                    }
                    writeLog("Website created by user {$currentUser['email']}: {$websiteSlug}");
                } else {
                    $error = 'هەڵەیەک ڕوویدا لە دروستکردنی وێب سایت';
                }
                $insertStmt->close();
            }
            $checkStmt->close();
        }
    }
    
    elseif ($action === 'update_settings') {
        $showProductImages = isset($_POST['show_product_images']) ? 1 : 0;
        $showPrices = isset($_POST['show_prices']) ? 1 : 0;
        $showRetailPrice = isset($_POST['show_retail_price']) ? 1 : 0;
        $showWholesalePrice = isset($_POST['show_wholesale_price']) ? 1 : 0;
        $showSpecialPrice = isset($_POST['show_special_price']) ? 1 : 0;
        $showByCategory = isset($_POST['show_by_category']) ? 1 : 0;
        $showOnlyWithImages = isset($_POST['show_only_with_images']) ? 1 : 0;
        $showStockQuantity = isset($_POST['show_stock_quantity']) ? 1 : 0;
        $productDisplayOrder = cleanInput($_POST['product_display_order'] ?? 'random');
        
        // تاقیکردنی بەهاکانی product_display_order
        if (!in_array($productDisplayOrder, ['random', 'newest', 'oldest'])) {
            $productDisplayOrder = 'random';
        }
        
        // تاقیکردنی ئەوەی ستونی product_display_order هەیە یان نا
        $checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'product_display_order'");
        $hasProductDisplayOrderColumn = $checkColumnStmt->num_rows > 0;
        $checkColumnStmt->close();
        
        if ($hasProductDisplayOrderColumn) {
            $updateStmt = $conn->prepare("UPDATE website_settings SET show_product_images = ?, show_prices = ?, show_retail_price = ?, show_wholesale_price = ?, show_special_price = ?, show_by_category = ?, show_only_with_images = ?, show_stock_quantity = ?, product_display_order = ? WHERE user_id = ?");
            $updateStmt->bind_param("iiiiiiiiss", $showProductImages, $showPrices, $showRetailPrice, $showWholesalePrice, $showSpecialPrice, $showByCategory, $showOnlyWithImages, $showStockQuantity, $productDisplayOrder, $userId);
        } else {
            $updateStmt = $conn->prepare("UPDATE website_settings SET show_product_images = ?, show_prices = ?, show_retail_price = ?, show_wholesale_price = ?, show_special_price = ?, show_by_category = ?, show_only_with_images = ?, show_stock_quantity = ? WHERE user_id = ?");
            $updateStmt->bind_param("iiiiiiiii", $showProductImages, $showPrices, $showRetailPrice, $showWholesalePrice, $showSpecialPrice, $showByCategory, $showOnlyWithImages, $showStockQuantity, $userId);
        }
        
        if ($updateStmt->execute()) {
            $success = 'ڕێکخستنەکان بە سەرکەوتوویی نوێکرانەوە';
            // نوێکردنەوەی داتاکان
            $websiteSettings['show_product_images'] = $showProductImages;
            $websiteSettings['show_prices'] = $showPrices;
            $websiteSettings['show_retail_price'] = $showRetailPrice;
            $websiteSettings['show_wholesale_price'] = $showWholesalePrice;
            $websiteSettings['show_special_price'] = $showSpecialPrice;
            $websiteSettings['show_by_category'] = $showByCategory;
            $websiteSettings['show_only_with_images'] = $showOnlyWithImages;
            $websiteSettings['show_stock_quantity'] = $showStockQuantity;
            if ($hasProductDisplayOrderColumn) {
                $websiteSettings['product_display_order'] = $productDisplayOrder;
            }
            writeLog("Website settings updated by user {$currentUser['email']}");
        } else {
            $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی ڕێکخستنەکان';
        }
        $updateStmt->close();
    }
    
    elseif ($action === 'toggle_active') {
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $toggleStmt = $conn->prepare("UPDATE website_settings SET is_active = ? WHERE user_id = ?");
        $toggleStmt->bind_param("ii", $isActive, $userId);
        
        if ($toggleStmt->execute()) {
            $success = $isActive ? 'وێب سایت چالاککرا' : 'وێب سایت ناچالاککرا';
            $websiteSettings['is_active'] = $isActive;
            writeLog("Website " . ($isActive ? "activated" : "deactivated") . " by user {$currentUser['email']}");
        } else {
            $error = 'هەڵەیەک ڕوویدا لە گۆڕینی دۆخی وێب سایت';
        }
        $toggleStmt->close();
    }
    
    elseif ($action === 'toggle_show_on_index') {
        $showOnIndex = isset($_POST['show_on_index']) ? 1 : 0;
        
        // تاقیکردنی ئەوەی ستونەکە هەیە یان نا
        $checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_on_index'");
        $columnExists = $checkColumnStmt->num_rows > 0;
        $checkColumnStmt->close();
        
        if ($columnExists) {
            $toggleStmt = $conn->prepare("UPDATE website_settings SET show_on_index = ? WHERE user_id = ?");
            $toggleStmt->bind_param("ii", $showOnIndex, $userId);
            
            if ($toggleStmt->execute()) {
                $success = $showOnIndex ? 'وێب سایت لە لیستی سەرەکیدا نیشان دەدرێت' : 'وێب سایت لە لیستی سەرەکیدا شاردرایەوە';
                $websiteSettings['show_on_index'] = $showOnIndex;
                writeLog("Website show_on_index " . ($showOnIndex ? "enabled" : "disabled") . " by user {$currentUser['email']}");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە گۆڕینی دۆخی نیشاندان';
            }
            $toggleStmt->close();
        } else {
            $error = 'ئەم تایبەتمەندییە هێشتا بەردەست نییە. تکایە پێشتر ستونی show_on_index زیاد بکە بۆ خشتەی website_settings';
        }
    }
    
    elseif ($action === 'toggle_show_shop_exit_button') {
        $showShopExitButton = isset($_POST['show_shop_exit_button']) ? 1 : 0;
        
        $checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_shop_exit_button'");
        $columnExists = $checkColumnStmt->num_rows > 0;
        $checkColumnStmt->close();
        
        if ($columnExists) {
            $toggleStmt = $conn->prepare("UPDATE website_settings SET show_shop_exit_button = ? WHERE user_id = ?");
            $toggleStmt->bind_param("ii", $showShopExitButton, $userId);
            
            if ($toggleStmt->execute()) {
                $success = $showShopExitButton ? 'دوگمەی دەرچوون لە فرۆشگا دەردەکەوێت' : 'دوگمەی دەرچوون لە فرۆشگا شاردرایەوە';
                $websiteSettings['show_shop_exit_button'] = $showShopExitButton;
                writeLog("Website show_shop_exit_button " . ($showShopExitButton ? "enabled" : "disabled") . " by user {$currentUser['email']}");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە گۆڕینی دۆخی دوگمەی دەرچوون';
            }
            $toggleStmt->close();
        } else {
            $error = 'ئەم تایبەتمەندییە هێشتا بەردەست نییە. تکایە پێشتر ستونی show_shop_exit_button زیاد بکە بۆ خشتەی website_settings';
        }
    }

    elseif ($action === 'toggle_whatsapp_order') {
        $enableWhatsAppOrder = isset($_POST['enable_whatsapp_order']) ? 1 : 0;

        $checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'enable_whatsapp_order'");
        $columnExists = $checkColumnStmt->num_rows > 0;
        $checkColumnStmt->close();

        if ($columnExists) {
            $toggleStmt = $conn->prepare("UPDATE website_settings SET enable_whatsapp_order = ? WHERE user_id = ?");
            $toggleStmt->bind_param("ii", $enableWhatsAppOrder, $userId);

            if ($toggleStmt->execute()) {
                $success = $enableWhatsAppOrder
                    ? 'ناردنی داواکاری بە واتسئاپ چالاککرا'
                    : 'ناردنی داواکاری بە واتسئاپ ناچالاککرا';
                $websiteSettings['enable_whatsapp_order'] = $enableWhatsAppOrder;
                writeLog("Website enable_whatsapp_order " . ($enableWhatsAppOrder ? "enabled" : "disabled") . " by user {$currentUser['email']}");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە گۆڕینی دۆخی واتسئاپ';
            }
            $toggleStmt->close();
        } else {
            $error = 'ئەم تایبەتمەندییە هێشتا بەردەست نییە. تکایە پێشتر ستونی enable_whatsapp_order زیاد بکە بۆ خشتەی website_settings';
        }
    }
    
    elseif ($action === 'upload_banner') {
        // تاقیکردنی ئەوەی فایلێک هەڵبژێردراوە
        if (isset($_FILES['shop_banner']) && $_FILES['shop_banner']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['shop_banner'];
            $fileSize = $file['size'];
            
            // وەرگرتنی extension
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($fileExt, $allowed)) {
                $error = 'تەنها فایلی وێنە (jpg, jpeg, png, gif, webp) ڕێگەپێدراوە';
            } elseif ($fileSize > MAX_FILE_SIZE) {
                $error = 'قەبارەی فایل زۆر گەورەیە. زۆرترین قەبارە: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB';
            } else {
                $mimeMap = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp'
                ];

                $mimeType = '';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                }
                if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
                    $mimeType = $mimeMap[$fileExt] ?? 'image/jpeg';
                }

                if (!function_exists('product_spaces_enabled') || !product_spaces_enabled()) {
                    $error = 'ڕێکخستنی Spaces تەواو نییە';
                } else {
                    $payload = spaces_optimized_image_upload_payload($file['tmp_name'], $file['name'] ?? '');
                    if ($payload['body'] === false) {
                        $error = 'هەڵەیەک ڕوویدا لە خوێندنەوەی فایل';
                    } else {
                        $objectKey = 'img/shop_banner/shop_banner_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
                        try {
                            spaces_put_object($objectKey, $payload['body'], $payload['mime']);
                        } catch (Throwable $e) {
                            $error = 'هەڵەیەک ڕوویدا لە ئەپلۆدکردنی فایل بۆ DigitalOcean: ' . $e->getMessage();
                        }
                        if (empty($error)) {
                            $newBannerUrl = spaces_public_url_for_object_key($objectKey);
                            if ($newBannerUrl === null) {
                                $error = 'ڕێکخستنی URL ی Spaces تەواو نییە';
                            }
                        }
                        if (empty($error)) {
                            if (!empty($websiteSettings['shop_banner'])) {
                                deleteShopBannerFromSpaces($websiteSettings['shop_banner']);
                            }

                            $updateStmt = $conn->prepare("UPDATE website_settings SET shop_banner = ? WHERE user_id = ?");
                            $updateStmt->bind_param("si", $newBannerUrl, $userId);

                            if ($updateStmt->execute()) {
                                $success = 'بانەری فرۆشگا بە سەرکەوتوویی ئەپلۆد کرا';
                                $websiteSettings['shop_banner'] = $newBannerUrl;
                                writeLog("Shop banner uploaded by user {$currentUser['email']}");
                            } else {
                                $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی بنکەی زانیاری';
                            }
                            $updateStmt->close();
                        }
                    }
                }
            }
        } else {
            $error = 'تکایە وێنەیەک هەڵبژێرە';
        }
    }
    
    elseif ($action === 'remove_banner') {
        if (!empty($websiteSettings['shop_banner'])) {
            deleteShopBannerFromSpaces($websiteSettings['shop_banner']);
            
            $updateStmt = $conn->prepare("UPDATE website_settings SET shop_banner = NULL WHERE user_id = ?");
            $updateStmt->bind_param("i", $userId);
            
            if ($updateStmt->execute()) {
                $success = 'بانەری فرۆشگا سڕایەوە';
                $websiteSettings['shop_banner'] = null;
                writeLog("Shop banner removed by user {$currentUser['email']}");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە سڕینەوەی بانەر';
            }
            $updateStmt->close();
        }
    }

    elseif ($action === 'save_announcement') {
        $announcementEnabled = isset($_POST['shop_announcement_enabled']) ? 1 : 0;
        $announcementText = trim((string) ($_POST['shop_announcement'] ?? ''));
        // سنووردارکردنی درێژی بۆ پاراستنی داتابەیس
        if (mb_strlen($announcementText) > 2000) {
            $announcementText = mb_substr($announcementText, 0, 2000);
        }
        $announcementValue = $announcementText === '' ? null : $announcementText;

        $updateStmt = $conn->prepare("UPDATE website_settings SET shop_announcement = ?, shop_announcement_enabled = ? WHERE user_id = ?");
        $updateStmt->bind_param("sii", $announcementValue, $announcementEnabled, $userId);

        if ($updateStmt->execute()) {
            $success = 'ئاگادارکردنەوەی فرۆشگا نوێکرایەوە';
            $websiteSettings['shop_announcement'] = $announcementValue;
            $websiteSettings['shop_announcement_enabled'] = $announcementEnabled;
            writeLog("Shop announcement " . ($announcementEnabled ? "enabled/updated" : "saved (disabled)") . " by user {$currentUser['email']}");
        } else {
            $error = 'هەڵەیەک ڕوویدا لە هەڵگرتنی ئاگادارکردنەوە';
        }
        $updateStmt->close();
    }
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ڕێکخستنی وێب سایت - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#0d6efd">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/website/assets/css/website-settings.css'); ?>" rel="stylesheet">
</head>
<body class="website-module-page website-settings-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">
        
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center hub-page-header flex-wrap gap-2 mb-4">
                    <div>
                        <h2 class="mb-1">ڕێکخستنی وێب سایت</h2>
                        <p class="text-muted mb-0">فرۆشگای ئۆنلاینی خۆت دروست بکە</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            
            <?php if (!$websiteSettings): ?>
            <!-- دروستکردنی وێب سایت -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-plus-circle text-primary"></i>
                            دروستکردنی وێب سایت
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_website">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="mb-3">
                                <label for="website_slug" class="form-label">ناوی وێب سایت</label>
                                <div class="input-group">
                                    <span class="input-group-text">https://nexoracore.com/web/</span>
                                    <input type="text" class="form-control" id="website_slug" name="website_slug" 
                                           placeholder="amir" required pattern="[a-zA-Z0-9_-]+" 
                                           title="تەنها پیت و ژمارە و _ و - دەتوانێت بێت">
                                </div>
                                <div class="form-text">نموونە: amir, my-shop, store_123</div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>تێبینی:</strong> دوای دروستکردن، ناوی وێب سایت ناتوانێت بگۆڕێت.
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-globe"></i> دروستکردنی وێب سایت
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <!-- ڕێکخستنەکانی وێب سایت -->
            <div class="col-lg-8">
                <div class="card website-settings-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>
                            <i class="bi bi-gear"></i>
                            ڕێکخستنەکان
                        </h5>
                        <div class="d-flex align-items-center gap-3">
                            <span class="status-badge bg-<?php echo $websiteSettings['is_active'] ? 'success' : 'secondary'; ?>">
                                <i class="bi bi-<?php echo $websiteSettings['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
                                <?php echo $websiteSettings['is_active'] ? 'چالاک' : 'ناچالاک'; ?>
                            </span>
                            <?php if ($websiteSettings['is_active']): ?>
                                <a href="https://nexoracore.com/web/<?php echo $websiteSettings['website_slug']; ?>" 
                                   target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i> بینینی وێب سایت
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo url('user/website/shop_gmail_access.php'); ?>" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-google"></i> دەستڕاگەیشتنی Gmail
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        
                        <!-- چالاککردن/ناچالاککردن -->
                        <div class="settings-section mb-4">
                            <h6>
                                <i class="bi bi-power"></i>
                                دۆخی وێب سایت
                            </h6>
                            <form method="POST" action="" id="toggleActiveForm">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="toggle-switch-wrapper">
                                    <div class="toggle-switch-label">
                                        <span class="label-title">
                                            <i class="bi bi-globe"></i>
                                            چالاککردنی وێب سایت
                                        </span>
                                        <span class="label-description">
                                            کاتێک چالاک بکەیت، وێب سایتەکەت بەردەست دەبێت بۆ بینینی هەموو کەسێک
                                        </span>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="is_active" name="is_active" 
                                               <?php echo $websiteSettings['is_active'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </form>
                            
                            <form method="POST" action="" id="toggleShowOnIndexForm" class="mt-3">
                                <input type="hidden" name="action" value="toggle_show_on_index">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="toggle-switch-wrapper">
                                    <div class="toggle-switch-label">
                                        <span class="label-title">
                                            <i class="bi bi-list-ul"></i>
                                            ینیشاندانی لە لیستی فرۆشگاکان web
                                        </span>
                                        <span class="label-description">
                                            کاتێک چالاک بکەیت، وێب سایتەکەت لە لیستی فرۆشگاکانی لاپەڕەی سەرەکیدا دەرکەوێت
                                        </span>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="show_on_index" name="show_on_index" 
                                               <?php echo (isset($websiteSettings['show_on_index']) && $websiteSettings['show_on_index']) ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </form>
                            
                            <form method="POST" action="" id="toggleShowShopExitForm" class="mt-3">
                                <input type="hidden" name="action" value="toggle_show_shop_exit_button">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="toggle-switch-wrapper">
                                    <div class="toggle-switch-label">
                                        <span class="label-title">
                                            <i class="bi bi-box-arrow-right"></i>
                                            نیشاندانی دوگمەی دەرچوون لە فرۆشگا
                                        </span>
                                        <span class="label-description">
                                            کاتێک چالاک بکەیت، دوگمەی «دەرچوون» (گەڕانەوە بۆ لیستی فرۆشگاکان) و بەستەری دەرچوون لە مێنیوی کڕیار لە نێوبارەکەدا دەردەکەوێت
                                        </span>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="show_shop_exit_button" name="show_shop_exit_button"
                                               <?php echo (!isset($websiteSettings['show_shop_exit_button']) || $websiteSettings['show_shop_exit_button']) ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </form>

                            <form method="POST" action="" id="toggleWhatsAppOrderForm" class="mt-3">
                                <input type="hidden" name="action" value="toggle_whatsapp_order">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                                <div class="toggle-switch-wrapper">
                                    <div class="toggle-switch-label">
                                        <span class="label-title">
                                            <i class="bi bi-whatsapp"></i>
                                            ناردنی داواکاری بە واتسئاپ
                                        </span>
                                        <span class="label-description">
                                            کڕیار لە سەبەتەی کڕین دوگمەی واتسئاپ دەبینێت و دەتوانێت داواکاری ڕاستەوخۆ بنێرێت. پێویستە ژمارەی تەلەفۆنت لە پرۆفایل دیار بێت.
                                        </span>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="enable_whatsapp_order" name="enable_whatsapp_order"
                                               <?php echo !empty($websiteSettings['enable_whatsapp_order']) ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </form>
                        </div>
                        
                        <hr class="section-divider">
                        
                        <!-- ڕێکخستنەکانی پیشاندان -->
                        <form method="POST" action="" id="settingsForm">
                            <input type="hidden" name="action" value="update_settings">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <!-- بەشی پیشاندانی کاڵاکان -->
                            <div class="settings-section">
                                <h6>
                                    <i class="bi bi-eye"></i>
                                    پیشاندانی کاڵاکان
                                </h6>
                                 
                                <!-- هەڵبژاردنی شێوازی پیشاندانی کاڵاکان -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold">
                                        <i class="bi bi-sort-down"></i>
                                        شێوازی پیشاندانی کاڵاکان
                                    </label>
                                    <div class="form-text mb-2">
                                        دیاری بکە کاڵاکان بە چ شێوازێک پیشان بدرێن
                                    </div>
                                    <div class="btn-group w-100" role="group" aria-label="شێوازی پیشاندانی کاڵاکان">
                                        <?php 
                                        $currentOrder = $websiteSettings['product_display_order'] ?? 'random';
                                        $orderOptions = [
                                            'random' => ['label' => 'هەڕەمەکی', 'icon' => 'bi-shuffle'],
                                            'newest' => ['label' => 'نوێترین', 'icon' => 'bi-arrow-up-circle'],
                                            'oldest' => ['label' => 'کۆنترین', 'icon' => 'bi-arrow-down-circle']
                                        ];
                                        foreach ($orderOptions as $value => $option):
                                        ?>
                                            <input type="radio" class="btn-check" name="product_display_order" 
                                                   id="product_order_<?php echo $value; ?>" 
                                                   value="<?php echo $value; ?>"
                                                   <?php echo $currentOrder === $value ? 'checked' : ''; ?>>
                                            <label class="btn btn-outline-primary" for="product_order_<?php echo $value; ?>">
                                                <i class="bi <?php echo $option['icon']; ?>"></i>
                                                <?php echo $option['label']; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="settings-group">
                                    <div class="toggle-switch-wrapper">
                                        <div class="toggle-switch-label">
                                            <span class="label-title">
                                                <i class="bi bi-image"></i>
                                                پیشاندانی وێنەی کاڵاکان
                                            </span>
                                            <span class="label-description">
                                                وێنەکانی کاڵاکان لە وێب سایتەکەتدا پیشان بدرێت
                                            </span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="show_product_images" 
                                                   name="show_product_images" <?php echo $websiteSettings['show_product_images'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>

                                    <div class="toggle-switch-wrapper">
                                        <div class="toggle-switch-label">
                                            <span class="label-title">
                                                <i class="bi bi-funnel"></i>
                                                تەنها کاڵای وێنەدار
                                            </span>
                                            <span class="label-description">
                                                تەنها ئەو کاڵایانە نیشان بدرێن کە وێنەیان هەیە
                                            </span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="show_only_with_images"
                                                   name="show_only_with_images" <?php echo $websiteSettings['show_only_with_images'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>

                                    <div class="toggle-switch-wrapper">
                                        <div class="toggle-switch-label">
                                            <span class="label-title">
                                                <i class="bi bi-layers"></i>
                                                پیشاندان بە کەتەلۆگ
                                            </span>
                                            <span class="label-description">
                                                کاڵاکان بە پێی کەتەلۆگەکانیان دابەش بکرێن
                                            </span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="show_by_category" 
                                                   name="show_by_category" <?php echo $websiteSettings['show_by_category'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
 
                                    <div class="toggle-switch-wrapper">
                                        <div class="toggle-switch-label">
                                            <span class="label-title">
                                                <i class="bi bi-box-seam"></i>
                                                پیشاندانی بڕی بەردەست
                                            </span>
                                            <span class="label-description">
                                                بڕی بەردەستی کاڵاکان لە وێب سایتەکەتدا پیشان بدرێت
                                            </span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="show_stock_quantity"
                                                   name="show_stock_quantity" <?php echo $websiteSettings['show_stock_quantity'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- بەشی نرخەکان -->
                            <div class="settings-section">
                                <h6>
                                    <i class="bi bi-currency-dollar"></i>
                                    ڕێکخستنی نرخەکان
                                </h6>
                                
                                <div class="settings-group">
                                    <div class="toggle-switch-wrapper">
                                        <div class="toggle-switch-label">
                                            <span class="label-title">
                                                <i class="bi bi-tag"></i>
                                                پیشاندانی نرخەکان
                                            </span>
                                            <span class="label-description">
                                                هەموو نرخەکان لە وێب سایتەکەتدا پیشان بدرێن
                                            </span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="show_prices"
                                                   name="show_prices" <?php echo $websiteSettings['show_prices'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>

                                    <div class="toggle-switch-wrapper">
                                        <div class="toggle-switch-label">
                                            <span class="label-title">
                                                <i class="bi bi-cart"></i>
                                                نرخی تاک فرۆشتن
                                            </span>
                                            <span class="label-description">
                                                نرخی تاک فرۆشتن بۆ کاڵاکان پیشان بدرێت
                                            </span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="show_retail_price" 
                                                   name="show_retail_price" <?php echo $websiteSettings['show_retail_price'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="toggle-switch-wrapper">
                                        <div class="toggle-switch-label">
                                            <span class="label-title">
                                                <i class="bi bi-cart-check"></i>
                                                نرخی جوملە
                                            </span>
                                            <span class="label-description">
                                                نرخی جوملە بۆ کاڵاکان پیشان بدرێت
                                            </span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="show_wholesale_price" 
                                                   name="show_wholesale_price" <?php echo $websiteSettings['show_wholesale_price'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="toggle-switch-wrapper">
                                        <div class="toggle-switch-label">
                                            <span class="label-title">
                                                <i class="bi bi-star"></i>
                                                نرخی تایبەت
                                            </span>
                                            <span class="label-description">
                                                نرخی تایبەت (تخفیف) بۆ کاڵاکان پیشان بدرێت
                                            </span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" id="show_special_price" 
                                                   name="show_special_price" <?php echo $websiteSettings['show_special_price'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-end mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-save"></i> هەڵگرتنی ڕێکخستنەکان
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- بەڕێوەبردنی کاڵاکان -->
            <div class="col-lg-4">


        <!-- بانەری فرۆشگا -->
        <div class="card mt-3 website-settings-card">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-image"></i>
                            بانەری فرۆشگا
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($websiteSettings['shop_banner'])): ?>
                            <div class="banner-preview mb-3">
                                <img src="<?php echo htmlspecialchars(resolveShopBannerUrl($websiteSettings['shop_banner'])); ?>" 
                                     alt="بانەری فرۆشگا">
                            </div>
                            <form method="POST" action="" class="d-inline mb-3">
                                <input type="hidden" name="action" value="remove_banner">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('دڵنیایت لە سڕینەوەی بانەر؟');">
                                    <i class="bi bi-trash"></i> سڕینەوەی بانەر
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <div class="banner-upload-section">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_banner">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="mb-3">
                                    <label for="shop_banner" class="form-label">
                                        <i class="bi bi-cloud-upload"></i>
                                        <?php echo !empty($websiteSettings['shop_banner']) ? 'گۆڕینی بانەر' : 'ئەپلۆدکردنی بانەر'; ?>
                                    </label>
                                    <input type="file" class="form-control" id="shop_banner" name="shop_banner" 
                                           accept="image/jpeg,image/png,image/gif,image/webp" <?php echo empty($websiteSettings['shop_banner']) ? 'required' : ''; ?>>
                                    <div class="form-text mt-2">
                                        <i class="bi bi-info-circle"></i>
                                        سایزی پێشنیارکراو: 1200x300 پیکسڵ | زۆرترین قەبارە: 8MB
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload"></i> <?php echo !empty($websiteSettings['shop_banner']) ? 'گۆڕینی بانەر' : 'ئەپلۆدکردن'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>


                <!-- ئاگادارکردنەوەی فرۆشگا -->
                <?php
                    $announcementText = (string) ($websiteSettings['shop_announcement'] ?? '');
                    $announcementEnabled = (int) ($websiteSettings['shop_announcement_enabled'] ?? 0) === 1;
                ?>
                <div class="card mt-3 website-settings-card">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-megaphone"></i>
                            ئاگادارکردنەوەی فرۆشگا
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3" style="font-size: 0.9rem;">
                            <i class="bi bi-info-circle"></i>
                            دەقێک بنووسە کە وەک ڕاگەیاندن لە سەرەوەی فرۆشگاکەت دەردەکەوێت.
                            دەتوانیت <strong>تۆخ</strong>، <em>لار</em> و مارکداون بەکاربهێنیت.
                        </p>

                        <form method="POST" action="" id="announcementForm">
                            <input type="hidden" name="action" value="save_announcement">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                            <!-- تووڵبار بۆ فۆرماتکردن -->
                            <div class="announcement-toolbar btn-group btn-group-sm mb-2" role="group" aria-label="فۆرمات">
                                <button type="button" class="btn btn-outline-secondary" data-md="bold" title="تۆخ (Bold)"><i class="bi bi-type-bold"></i></button>
                                <button type="button" class="btn btn-outline-secondary" data-md="italic" title="لار (Italic)"><i class="bi bi-type-italic"></i></button>
                                <button type="button" class="btn btn-outline-secondary" data-md="strike" title="هێڵ بەسەردا (Strikethrough)"><i class="bi bi-type-strikethrough"></i></button>
                                <button type="button" class="btn btn-outline-secondary" data-md="link" title="بەستەر (Link)"><i class="bi bi-link-45deg"></i></button>
                                <button type="button" class="btn btn-outline-secondary" data-md="code" title="کۆد (Code)"><i class="bi bi-code"></i></button>
                            </div>

                            <div class="mb-3">
                                <textarea class="form-control" id="shop_announcement" name="shop_announcement"
                                          rows="4" maxlength="2000" dir="rtl"
                                          placeholder="بۆ نموونە: **بەخێربێن!** ئەمڕۆ داشکاندنی تایبەت هەیە 🎉"><?php echo htmlspecialchars($announcementText); ?></textarea>
                                <div class="form-text mt-1 d-flex justify-content-between">
                                    <span><i class="bi bi-markdown"></i> پشتگیری مارکداون</span>
                                    <span><span id="announcementCount">0</span>/2000</span>
                                </div>
                            </div>

                            <!-- پێشبینین -->
                            <div class="mb-3">
                                <label class="form-label mb-1" style="font-size: 0.85rem;">
                                    <i class="bi bi-eye"></i> پێشبینین
                                </label>
                                <div id="announcementPreview" class="announcement-preview">
                                    <span class="text-muted">پێشبینینی ئاگادارکردنەوە لێرە دەردەکەوێت...</span>
                                </div>
                            </div>

                            <!-- چالاککردن -->
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <label for="shop_announcement_enabled" class="form-label mb-0">
                                    <i class="bi bi-toggle-on"></i>
                                    پیشاندانی ئاگادارکردنەوە لە فرۆشگا
                                </label>
                                <label class="toggle-switch mb-0">
                                    <input type="checkbox" id="shop_announcement_enabled" name="shop_announcement_enabled"
                                           <?php echo $announcementEnabled ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-save"></i> هەڵگرتنی ئاگادارکردنەوە
                            </button>
                        </form>
                    </div>
                </div>


                  <!-- زانیاری وێب سایت -->
                  <div class="card mt-3 website-settings-card">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-info-circle"></i>
                            زانیاری وێب سایت
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="info-card">
                            <strong>لینکی وێب سایت:</strong>
                            <a href="https://nexoracore.com/web/<?php echo $websiteSettings['website_slug']; ?>" target="_blank" class="text-decoration-none">
                                <code>https://nexoracore.com/web/<?php echo $websiteSettings['website_slug']; ?></code>
                            </a>
                        </div>
                        
                        <div class="info-card">
                            <strong>دروستکراوە لە:</strong>
                            <div class="mt-2">
                                <?php 
                                $createdAt = $websiteSettings['created_at'] ?? null;
                                if ($createdAt) {
                                    echo '<i class="bi bi-calendar-check text-primary"></i> ' . date('Y/m/d H:i', strtotime($createdAt));
                                } else {
                                    echo '<span class="text-muted">نادیار</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <strong>دوایین نوێکردنەوە:</strong>
                            <div class="mt-2">
                                <?php 
                                $updatedAt = $websiteSettings['updated_at'] ?? null;
                                if ($updatedAt) {
                                    echo '<i class="bi bi-clock-history text-success"></i> ' . date('Y/m/d H:i', strtotime($updatedAt));
                                } else {
                                    echo '<span class="text-muted">نادیار</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>


                <div class="card website-settings-card mb-3">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-box-seam"></i>
                            بەڕێوەبردنی کاڵاکان
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle"></i>
                            دیاری بکە چ کاڵایەک لە وێب سایتەکە نیشان بدرێت
                        </p>
                        <a href="<?php echo url('user/website/manage/manage_products.php'); ?>" class="btn btn-outline-primary w-100">
                            <i class="bi bi-list-check"></i> بەڕێوەبردنی کاڵاکان
                        </a>
                    </div>
                </div>
                
                <!-- بەڕێوەبردنی کەتەلۆگەکان -->
                <div class="card website-settings-card mb-3">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-tags"></i>
                            بەڕێوەبردنی کەتەلۆگەکان
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle"></i>
                            دیاری بکە چ کەتەلۆگێک لە وێب سایتەکە نیشان بدرێت
                        </p>
                        <a href="<?php echo url('user/website/manage/manage_categories.php'); ?>" class="btn btn-outline-success w-100">
                            <i class="bi bi-tags"></i> بەڕێوەبردنی کەتەلۆگەکان
                        </a>
                    </div>
                </div>
                
                <!-- بەڕێوەبردنی وردەکاریەکانی کاڵا -->
                <div class="card website-settings-card mb-3">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-card-text"></i>
                            وردەکاریەکانی کاڵا
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle"></i>
                            وەسف و وێنە زیاد بکە بۆ کاڵاکان
                        </p>
                        <a href="<?php echo url('user/website/manage/manage_product_details.php'); ?>" class="btn btn-outline-warning w-100">
                            <i class="bi bi-card-text"></i> دەستکاریکردنی وردەکارییەکان
                        </a>
                    </div>
                </div>
                
                <!-- بەڕێوەبردنی وەسڵەکان -->
                <div class="card website-settings-card mb-3">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-receipt"></i>
                            بەڕێوەبردنی وەسڵەکان
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle"></i>
                            بینینی وەسڵە هاتووەکان و وەرگیراوەکان
                        </p>
                        <a href="<?php echo url('user/website/orders.php'); ?>" class="btn btn-outline-danger w-100">
                            <i class="bi bi-receipt-cutoff"></i> بەڕێوەبردنی وەسڵەکان
                        </a>
                    </div>
                </div>
                
                <!-- ئامارەکانی وەسڵەکان -->
                <div class="card website-settings-card mb-3">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-graph-up-arrow"></i>
                            ئامارەکانی وەسڵەکان
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle"></i>
                            بینینی ئامارەکانی ئەمرۆ، هەفتانە، مانگانە و ساڵانە
                        </p>
                        <a href="<?php echo url('user/website/statistics.php'); ?>" class="btn btn-outline-info w-100">
                            <i class="bi bi-bar-chart-line"></i> بینینی ئامارەکان
                        </a>
                    </div>
                </div>
                
              
                
        
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-submit toggle form for website activation
        const isActiveToggle = document.getElementById('is_active');
        if (isActiveToggle) {
            isActiveToggle.addEventListener('change', function() {
                const form = document.getElementById('toggleActiveForm');
                if (form) {
                    // Add loading state
                    const wrapper = this.closest('.toggle-switch-wrapper');
                    if (wrapper) {
                        wrapper.style.opacity = '0.6';
                        wrapper.style.pointerEvents = 'none';
                    }
                    form.submit();
                }
            });
        }

        // Auto-submit toggle form for show on index
        const showOnIndexToggle = document.getElementById('show_on_index');
        if (showOnIndexToggle) {
            showOnIndexToggle.addEventListener('change', function() {
                const form = document.getElementById('toggleShowOnIndexForm');
                if (form) {
                    // Add loading state
                    const wrapper = this.closest('.toggle-switch-wrapper');
                    if (wrapper) {
                        wrapper.style.opacity = '0.6';
                        wrapper.style.pointerEvents = 'none';
                    }
                    form.submit();
                }
            });
        }

        const showShopExitToggle = document.getElementById('show_shop_exit_button');
        if (showShopExitToggle) {
            showShopExitToggle.addEventListener('change', function() {
                const form = document.getElementById('toggleShowShopExitForm');
                if (form) {
                    const wrapper = this.closest('.toggle-switch-wrapper');
                    if (wrapper) {
                        wrapper.style.opacity = '0.6';
                        wrapper.style.pointerEvents = 'none';
                    }
                    form.submit();
                }
            });
        }

        const whatsAppOrderToggle = document.getElementById('enable_whatsapp_order');
        if (whatsAppOrderToggle) {
            whatsAppOrderToggle.addEventListener('change', function() {
                const form = document.getElementById('toggleWhatsAppOrderForm');
                if (form) {
                    const wrapper = this.closest('.toggle-switch-wrapper');
                    if (wrapper) {
                        wrapper.style.opacity = '0.6';
                        wrapper.style.pointerEvents = 'none';
                    }
                    form.submit();
                }
            });
        }

        // Add smooth transitions for toggle switches
        document.querySelectorAll('.toggle-switch input').forEach(toggle => {
            toggle.addEventListener('change', function() {
                const wrapper = this.closest('.toggle-switch-wrapper');
                if (wrapper) {
                    wrapper.style.transition = 'all 0.3s ease';
                }
            });
        });

        // Form validation feedback
        const settingsForm = document.getElementById('settingsForm');
        if (settingsForm) {
            settingsForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> هەڵگرتن...';
                    submitBtn.disabled = true;
                }
            });
        }

        // Banner image compression before upload
        const shopBannerInput = document.getElementById('shop_banner');
        if (shopBannerInput) {
            shopBannerInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;

                // Check if it's an image
                if (!file.type.match('image.*')) {
                    return;
                }

                // Compress the image
                compressBannerImage(file, function(compressedFile) {
                    // Update the file input with compressed file
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(compressedFile);
                    shopBannerInput.files = dataTransfer.files;
                });
            });
        }

        // Compress banner image function
        function compressBannerImage(file, callback) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    // Calculate new dimensions (max 1920x1080 for banner)
                    let { width, height } = calculateBannerDimensions(img.width, img.height, 1920, 1080);
                    
                    canvas.width = width;
                    canvas.height = height;
                    
                    // Draw resized image
                    ctx.drawImage(img, 0, 0, width, height);
                    
                    // Convert to blob with quality 0.8
                    canvas.toBlob(function(blob) {
                        // Create new file with compressed data
                        const compressedFile = new File([blob], file.name, {
                            type: 'image/jpeg',
                            lastModified: Date.now()
                        });
                        
                        callback(compressedFile);
                    }, 'image/jpeg', 0.8);
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        function calculateBannerDimensions(originalWidth, originalHeight, maxWidth, maxHeight) {
            let width = originalWidth;
            let height = originalHeight;
            
            // Calculate aspect ratio
            const aspectRatio = originalWidth / originalHeight;
            
            // Resize if too large
            if (width > maxWidth) {
                width = maxWidth;
                height = width / aspectRatio;
            }
            
            if (height > maxHeight) {
                height = maxHeight;
                width = height * aspectRatio;
            }
            
            return { width: Math.round(width), height: Math.round(height) };
        }
    </script>

    <!-- ئاگادارکردنەوە: تووڵبار، ژماردن و پێشبینینی زیندوو -->
    <script>
    (function () {
        const textarea = document.getElementById('shop_announcement');
        const preview = document.getElementById('announcementPreview');
        const counter = document.getElementById('announcementCount');
        if (!textarea) return;

        // مارکداونی سادە بۆ پێشبینین — دەبێت لەگەڵ shop_announcement.php بگونجێت
        function escapeHtml(s) {
            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
        function renderMarkdown(src) {
            let t = (src || '').replace(/\r\n|\r/g, '\n');
            t = escapeHtml(t);
            // links [text](url)
            t = t.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, function (m, label, url) {
                const raw = url.replace(/&amp;/g, '&');
                if (!/^(https?:\/\/|mailto:)/i.test(raw)) return label;
                return '<a href="' + url + '" target="_blank" rel="noopener nofollow">' + label + '</a>';
            });
            t = t.replace(/`([^`\n]+)`/g, '<code>$1</code>');
            t = t.replace(/\*\*([^\n]+?)\*\*/g, '<strong>$1</strong>');
            t = t.replace(/__([^\n]+?)__/g, '<strong>$1</strong>');
            t = t.replace(/~~([^\n]+?)~~/g, '<del>$1</del>');
            t = t.replace(/(^|[^\*\w])\*(?!\s)([^\*\n]+?)(?<!\s)\*(?![\*\w])/g, '$1<em>$2</em>');
            t = t.replace(/(^|[^_\w])_(?!\s)([^_\n]+?)(?<!\s)_(?![_\w])/g, '$1<em>$2</em>');
            t = t.replace(/\n/g, '<br>');
            return t;
        }

        function update() {
            const val = textarea.value;
            if (counter) counter.textContent = val.length;
            if (preview) {
                const html = renderMarkdown(val);
                preview.innerHTML = html.trim() !== ''
                    ? html
                    : '<span class="text-muted">پێشبینینی ئاگادارکردنەوە لێرە دەردەکەوێت...</span>';
            }
        }

        // تووڵباری فۆرمات — لفاندنی دەقی هەڵبژێردراو
        function wrapSelection(before, after, placeholder) {
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const val = textarea.value;
            const selected = val.substring(start, end) || placeholder;
            const newText = val.substring(0, start) + before + selected + after + val.substring(end);
            textarea.value = newText;
            const cursorStart = start + before.length;
            textarea.focus();
            textarea.setSelectionRange(cursorStart, cursorStart + selected.length);
            update();
        }

        document.querySelectorAll('.announcement-toolbar [data-md]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switch (btn.dataset.md) {
                    case 'bold':   wrapSelection('**', '**', 'دەقی تۆخ'); break;
                    case 'italic': wrapSelection('*', '*', 'دەقی لار'); break;
                    case 'strike': wrapSelection('~~', '~~', 'دەق'); break;
                    case 'code':   wrapSelection('`', '`', 'کۆد'); break;
                    case 'link':   wrapSelection('[', '](https://)', 'دەقی بەستەر'); break;
                }
            });
        });

        textarea.addEventListener('input', update);
        update();
    })();
    </script>

</body>
</html>
