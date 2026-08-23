<?php
/**
 * ====================================================================
 * App API - Shops  (web/app-api/shops.php)
 * ====================================================================
 * لیستی فرۆشگا چالاکەکان، یان یەک فرۆشگا بەپێی slug.
 *
 * GET  shops.php                 → لیستی هەموو فرۆشگا چالاکەکان
 * GET  shops.php?slug=SHOP_SLUG  → زانیاری تەواوی یەک فرۆشگا
 *
 * پارامیتەری زیادە بۆ لیست:
 *   search  → گەڕان بەپێی ناوی فرۆشگا (business_name)
 *   page    → ژمارەی لاپەڕە (بنەڕەت 1)
 *   per_page→ ژمارە لە هەر لاپەڕەیەک (بنەڕەت 30، زۆرترین 100)
 * ====================================================================
 */

require_once __DIR__ . '/_bootstrap.php';

app_api_require_method('GET');

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';

// ------------------------------------------------------------------
// حاڵەتی (١): یەک فرۆشگا بەپێی slug
// ------------------------------------------------------------------
if ($slug !== '') {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        app_api_error('ناسنامەی فرۆشگا (slug) دروست نییە.', 400, 'invalid_slug');
    }

    $shop = app_api_get_shop_by_slug($conn, $slug);
    if (!$shop) {
        app_api_error('فرۆشگاکە نەدۆزرایەوە یان ناچالاکە.', 404, 'shop_not_found');
    }

    // ژمارەی کاڵا بەردەستەکان (تەنها ئەوانەی وێنە + بڕیان هەیە — وەک سایتەکە)
    $countStmt = $conn->prepare("
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
        WHERE p.user_id = ?
            AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
            AND COALESCE(c.is_visible_on_website, 1) = 1
            AND pu.stock_quantity > 0
            AND ((p.image_path IS NOT NULL AND p.image_path != '') OR (pd.main_image IS NOT NULL AND pd.main_image != ''))
    ");
    $uid = (int)$shop['user_id'];
    $countStmt->bind_param('ii', $uid, $uid);
    $countStmt->execute();
    $productCount = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    app_api_success([
        'id'             => (int)$shop['id'],
        'user_id'        => (int)$shop['user_id'],
        'slug'           => $shop['website_slug'],
        'business_name'  => $shop['business_name'],
        'phone'          => $shop['shop_phone'] ?? null,
        'address'        => $shop['shop_address'] ?? null,
        'banner_url'     => app_api_shop_banner_url($shop['shop_banner'] ?? ''),
        'product_count'  => $productCount,
        'created_at'     => $shop['created_at'] ?? null,
        // ڕێکخستنەکانی پیشاندان — بۆ ئەوەی بەرنامەکە بزانێت چی پیشان بدات
        'settings'       => [
            'show_retail_price'    => !empty($shop['show_retail_price']),
            'show_wholesale_price' => !empty($shop['show_wholesale_price']),
            'show_special_price'   => !empty($shop['show_special_price']),
            'show_stock_quantity'  => !empty($shop['show_stock_quantity']),
            'show_by_category'     => !empty($shop['show_by_category']),
        ],
        // ئایا ئەم فرۆشگایە پێویستی بە چوونەژوورەوەی گووگڵ هەیە (سنووردارە)؟
        'requires_google_login' => !empty($shop['shop_google_restrict']),
    ]);
}

// ------------------------------------------------------------------
// حاڵەتی (٢): لیستی فرۆشگاکان
// ------------------------------------------------------------------
$search  = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 30);
$perPage = max(1, min(100, $perPage));
$offset  = ($page - 1) * $perPage;

$hasShowOnIndex = app_api_has_show_on_index($conn);

// شەرتەکان — وەک لاپەڕەی سەرەکی سایتەکە
$where = "ws.is_active = 1";
if ($hasShowOnIndex) {
    $where .= " AND ws.show_on_index = 1";
}

$params = [];
$types  = '';
if ($search !== '') {
    $where .= " AND u.business_name LIKE ?";
    $params[] = '%' . $search . '%';
    $types   .= 's';
}

// کۆی گشتی بۆ pagination
$countSql = "
    SELECT COUNT(*) AS total
    FROM website_settings ws
    INNER JOIN users u ON ws.user_id = u.id
    WHERE $where
";
if ($params) {
    $cstmt = $conn->prepare($countSql);
    $cstmt->bind_param($types, ...$params);
    $cstmt->execute();
    $total = (int)($cstmt->get_result()->fetch_assoc()['total'] ?? 0);
    $cstmt->close();
} else {
    $total = (int)($conn->query($countSql)->fetch_assoc()['total'] ?? 0);
}

// لیستەکە خۆی
$listSql = "
    SELECT ws.id, ws.user_id, ws.website_slug, ws.shop_banner, ws.created_at,
           ws.show_retail_price, ws.show_wholesale_price, ws.show_special_price,
           ws.show_stock_quantity, ws.show_by_category, ws.shop_google_restrict,
           u.business_name
    FROM website_settings ws
    INNER JOIN users u ON ws.user_id = u.id
    WHERE $where
    ORDER BY CASE WHEN ws.id = 4 THEN 0 ELSE 1 END, ws.created_at DESC
    LIMIT ? OFFSET ?
";
$listParams = $params;
$listTypes  = $types . 'ii';
$listParams[] = $perPage;
$listParams[] = $offset;

$lstmt = $conn->prepare($listSql);
$lstmt->bind_param($listTypes, ...$listParams);
$lstmt->execute();
$rows = $lstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lstmt->close();

$shops = [];
foreach ($rows as $r) {
    $shops[] = [
        'id'            => (int)$r['id'],
        'user_id'       => (int)$r['user_id'],
        'slug'          => $r['website_slug'],
        'business_name' => $r['business_name'],
        'banner_url'    => app_api_shop_banner_url($r['shop_banner'] ?? ''),
        'created_at'    => $r['created_at'] ?? null,
        'settings'      => [
            'show_retail_price'    => !empty($r['show_retail_price']),
            'show_wholesale_price' => !empty($r['show_wholesale_price']),
            'show_special_price'   => !empty($r['show_special_price']),
            'show_stock_quantity'  => !empty($r['show_stock_quantity']),
            'show_by_category'     => !empty($r['show_by_category']),
        ],
        'requires_google_login' => !empty($r['shop_google_restrict']),
    ];
}

app_api_success($shops, [
    'page'        => $page,
    'per_page'    => $perPage,
    'total'       => $total,
    'total_pages' => (int)ceil($total / $perPage),
]);
