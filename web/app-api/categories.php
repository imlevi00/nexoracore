<?php
/**
 * ====================================================================
 * App API - Categories  (web/app-api/categories.php)
 * ====================================================================
 * کەتەگۆرییەکانی فرۆشگایەک (تەنها ئەوانەی کاڵای بەردەستیان هەیە).
 *
 * GET categories.php?slug=SHOP
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
app_api_guard_shop_access($conn, $userId);

// ئەگەر فرۆشگا کەتەگۆری پیشان نەدات، لیستێکی بەتاڵ بگەڕێنەوە
if (empty($shop['show_by_category'])) {
    app_api_success([], ['show_by_category' => false]);
}

// وەک shop.php: تەنها کەتەگۆرییەکان کە کاڵای بەردەست (وێنە + بڕ) ی تێدایە
$stmt = $conn->prepare("
    SELECT DISTINCT c.id, c.name, COUNT(DISTINCT p.id) AS product_count
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
        AND ((p.image_path IS NOT NULL AND p.image_path != '') OR (pd.main_image IS NOT NULL AND pd.main_image != ''))
    GROUP BY c.id, c.name
    ORDER BY c.name
");
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$categories = [];
foreach ($rows as $r) {
    $categories[] = [
        'id'            => (int)$r['id'],
        'name'          => $r['name'],
        'product_count' => (int)$r['product_count'],
    ];
}

app_api_success($categories, ['show_by_category' => true]);
