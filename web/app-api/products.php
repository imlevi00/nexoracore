<?php
/**
 * ====================================================================
 * App API - Products  (web/app-api/products.php)
 * ====================================================================
 * کاڵاکانی فرۆشگایەک، یان زانیاری تەواوی یەک کاڵا.
 *
 * GET products.php?slug=SHOP            → لیستی کاڵاکانی فرۆشگا
 * GET products.php?slug=SHOP&id=123     → زانیاری تەواوی یەک کاڵا
 *
 * پارامیتەری زیادە بۆ لیست:
 *   category → id ی کەتەگۆری (فلتەر)
 *   search   → گەڕان بەپێی ناو یان بارکۆد
 *   sort     → newest | oldest | price_asc | price_desc | name | random
 *   page     → ژمارەی لاپەڕە (بنەڕەت 1)
 *   per_page → ژمارە لە هەر لاپەڕەیەک (بنەڕەت 20، زۆرترین 100)
 * ====================================================================
 */

require_once __DIR__ . '/_bootstrap.php';

app_api_require_method('GET');

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if ($slug === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    app_api_error('ناسنامەی فرۆشگا (slug) پێویستە و دەبێت دروست بێت.', 400, 'invalid_slug');
}

$shop = app_api_get_shop_by_slug($conn, $slug);
if (!$shop) {
    app_api_error('فرۆشگاکە نەدۆزرایەوە یان ناچالاکە.', 404, 'shop_not_found');
}

$userId = (int)$shop['user_id'];

// ئاسایشی: ئەگەر فرۆشگا سنووردار بێت بە گووگڵ، ڕێگە مەدە
app_api_guard_shop_access($conn, $userId);

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ==================================================================
// حاڵەتی (١): یەک کاڵای تەواو
// ==================================================================
if ($productId > 0) {
    $stmt = $conn->prepare("
        SELECT p.*, c.name AS category_name, c.id AS category_id,
               pd.description, pd.main_image, pd.sub_images, pd.discount_price
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_details pd ON p.id = pd.product_id
        LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = ?
        WHERE p.id = ? AND p.user_id = ? AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
        LIMIT 1
    ");
    $stmt->bind_param('iii', $userId, $productId, $userId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        app_api_error('کاڵاکە نەدۆزرایەوە.', 404, 'product_not_found');
    }

    $currency = $product['currency'] ?? 'IQD';

    // گەلەری وێنەکان: وێنەی سەرەکی + وێنە لاوەکییەکان (sub_images)
    $images = [];
    if (!empty($product['main_image'])) {
        $images[] = app_api_product_image_url($product['main_image']);
    } elseif (!empty($product['image_path'])) {
        $images[] = app_api_product_image_url($product['image_path']);
    }
    if (!empty($product['sub_images'])) {
        $sub = json_decode($product['sub_images'], true);
        if (is_array($sub)) {
            foreach ($sub as $s) {
                $u = app_api_product_image_url($s);
                if ($u) {
                    $images[] = $u;
                }
            }
        }
    }
    if (empty($images)) {
        $images[] = app_api_product_image_url('');
    }

    // هەموو یەکەکان
    $units = app_api_get_product_units($conn, $productId);
    $formattedUnits = [];
    $primaryStock = 0.0;
    foreach ($units as $u) {
        $fu = app_api_format_unit($u, $shop, $currency);
        $formattedUnits[] = $fu;
        if (!empty($u['is_primary']) || $primaryStock === 0.0) {
            $primaryStock = (float)($u['stock_quantity'] ?? 0);
        }
    }

    $showCategory = !empty($shop['show_by_category']);
    $discount = isset($product['discount_price']) ? (float)$product['discount_price'] : 0.0;

    app_api_success([
        'id'            => (int)$product['id'],
        'name'          => $product['name'],
        'barcode'       => $product['barcode'] ?? null,
        'description'   => $product['description'] ?? null,
        'currency'      => $currency,
        'image'         => $images[0],
        'images'        => $images,
        'category_id'   => ($showCategory && !empty($product['category_id'])) ? (int)$product['category_id'] : null,
        'category_name' => ($showCategory && !empty($product['category_name'])) ? $product['category_name'] : null,
        'discount_price'           => $discount > 0 ? $discount : null,
        'discount_price_formatted' => $discount > 0 ? app_api_format_price($discount, $currency) : null,
        'has_discount'  => $discount > 0,
        'in_stock'      => $primaryStock > 0,
        'units'         => $formattedUnits,
        'shop'          => [
            'slug'          => $shop['website_slug'],
            'business_name' => $shop['business_name'],
        ],
    ]);
}

// ==================================================================
// حاڵەتی (٢): لیستی کاڵاکان
// ==================================================================
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$search   = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$sort     = isset($_GET['sort']) ? trim((string)$_GET['sort']) : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = (int)($_GET['per_page'] ?? 20);
$perPage  = max(1, min(100, $perPage));
$offset   = ($page - 1) * $perPage;

// شەرتەکان — وەک shop.php ی سایتەکە
$where = "p.user_id = ? AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)";
$where .= " AND ((p.image_path IS NOT NULL AND p.image_path != '') OR (pd.main_image IS NOT NULL AND pd.main_image != ''))";
$params = [$userId, $userId];
$types  = 'ii';

if ($category > 0) {
    $where .= " AND c.id = ?";
    $params[] = $category;
    $types   .= 'i';
}
if ($search !== '') {
    $where .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types   .= 'ss';
}

// ڕیزکردن
switch ($sort) {
    case 'price_asc':  $orderBy = "COALESCE(pu.sell_price, 0) ASC, p.name"; break;
    case 'price_desc': $orderBy = "COALESCE(pu.sell_price, 0) DESC, p.name"; break;
    case 'name':       $orderBy = "p.name ASC"; break;
    case 'newest':     $orderBy = "COALESCE(p.updated_at, p.created_at) DESC"; break;
    case 'oldest':     $orderBy = "p.id ASC"; break;
    default:           $orderBy = "COALESCE(p.updated_at, p.created_at) DESC"; break;
}

// کۆی گشتی
$countSql = "
    SELECT COUNT(DISTINCT p.id) AS total
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_details pd ON p.id = pd.product_id
    LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = ?
    LEFT JOIN product_units pu ON p.id = pu.product_id
        AND (pu.is_primary = 1
             OR (pu.is_primary = 0 AND NOT EXISTS (
                 SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
             ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
    WHERE $where
        AND COALESCE(c.is_visible_on_website, 1) = 1
        AND pu.stock_quantity > 0
";
$cstmt = $conn->prepare($countSql);
$cstmt->bind_param($types, ...$params);
$cstmt->execute();
$total = (int)($cstmt->get_result()->fetch_assoc()['total'] ?? 0);
$cstmt->close();

// لیستەکە
$listSql = "
    SELECT p.id, p.name, p.barcode, p.image_path, p.currency,
           c.name AS category_name, c.id AS category_id,
           COALESCE(pu.stock_quantity, 0) AS stock_quantity,
           COALESCE(pu.sell_price, 0) AS retail_price,
           COALESCE(pu.wholesale_price, 0) AS wholesale_price,
           COALESCE(pu.special_price, 0) AS special_price,
           COALESCE(pu.id, '') AS unit_id,
           COALESCE(un.name, 'دانە') AS unit_name,
           pd.main_image, pd.discount_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = ?
    LEFT JOIN product_details pd ON p.id = pd.product_id
    LEFT JOIN product_units pu ON p.id = pu.product_id
        AND (pu.is_primary = 1
             OR (pu.is_primary = 0 AND NOT EXISTS (
                 SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
             ) AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)))
    LEFT JOIN units un ON pu.unit_id = un.id
    WHERE $where
        AND COALESCE(c.is_visible_on_website, 1) = 1
        AND pu.stock_quantity > 0
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
";
// listSql هەمان پێکهاتەی countSql ی هەیە (هەمان wpv join + هەمان $where)،
// بۆیە هەمان $params بەکاردێت + LIMIT/OFFSET
$listParams = $params;
$listTypes  = $types . 'ii';
$listParams[] = $perPage;
$listParams[] = $offset;

$lstmt = $conn->prepare($listSql);
$lstmt->bind_param($listTypes, ...$listParams);
$lstmt->execute();
$rows = $lstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lstmt->close();

$products = [];
foreach ($rows as $r) {
    $products[] = app_api_format_product_row($r, $shop);
}

app_api_success($products, [
    'shop'        => ['slug' => $shop['website_slug'], 'business_name' => $shop['business_name']],
    'page'        => $page,
    'per_page'    => $perPage,
    'total'       => $total,
    'total_pages' => (int)ceil($total / $perPage),
]);
