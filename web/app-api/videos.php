<?php
/**
 * ====================================================================
 * App API - Videos  (web/app-api/videos.php)
 * ====================================================================
 * فیدی ڤیدیۆکان بۆ بەرنامەی مۆبایل (وەک بەشی ڤیدیۆی سایتەکە /videos/).
 *
 * GET videos.php                          → فیدی گشتی (فرۆشگاکانی show_on_index)
 * GET videos.php?slug=SHOP                → تەنها ڤیدیۆکانی ئەو فرۆشگایە
 * GET videos.php?video_type=free&video_id=12  → یەک ڤیدیۆی دیاریکراو
 *
 * پارامیتەری زیادە:
 *   page     → ژمارەی لاپەڕە (بنەڕەت 1)
 *   per_page → ژمارە لە هەر لاپەڕەیەک (بنەڕەت 20، زۆرترین 50)
 *
 * تێبینی: ئەم endpoint ـە تەنها خوێندنەوەیە. تۆمارکردنی بینین لە
 * `video_view.php` ـدایە. هەمان داتابەیس و لۆژیکی بەشی ڤیدیۆی سایتەکە
 * بەکاردەهێنێت — هیچ گۆڕانکارییەک لە کۆدی سایتەکە ناکات.
 * ====================================================================
 */

require_once __DIR__ . '/_bootstrap.php';

// پەیوەندییە زیادەکانی ڤیدیۆ (bootstrap تەنها داتابەیسە سەرەکییەکە بار دەکات)
require_once __DIR__ . '/../../config/product_videos/database.php';   // $conn_videos (kasher_media)
require_once __DIR__ . '/../../config/product_images/database.php';   // $conn_images (kasher_media: user_logos)
require_once __DIR__ . '/../../config/kasher_zanyari/database.php';   // $conn_zanyari (kasher_z: views/likes)

app_api_require_method('GET');

/** @var mysqli|null $conn_videos */
/** @var mysqli|null $conn_images */
/** @var mysqli|null $conn_zanyari */
$conn_videos  = $GLOBALS['conn_videos']  ?? null;
$conn_images  = $GLOBALS['conn_images']  ?? null;
$conn_zanyari = $GLOBALS['conn_zanyari'] ?? null;

if (!($conn_videos instanceof mysqli)) {
    app_api_error('داتابەیسی ڤیدیۆ بەردەست نییە.', 503, 'videos_db_unavailable');
}

// ------------------------------------------------------------------
// فانکشنە یارمەتیدەرەکان
// ------------------------------------------------------------------

/**
 * زانیاری فرۆشگا (ناو، slug، لۆگۆ) بۆ کۆمەڵێک user_id.
 * @param int[] $userIds
 * @return array<int,array<string,mixed>>  [user_id => [...]]
 */
function app_api_videos_shop_meta(mysqli $conn, ?mysqli $conn_images, array $userIds): array
{
    $userIds = array_values(array_unique(array_map('intval', array_filter($userIds))));
    if (empty($userIds)) {
        return [];
    }
    $idList = implode(',', $userIds);
    $meta = [];

    // ناو + slug لە داتابەیسی سەرەکی
    $sql = "
        SELECT u.id AS user_id, u.business_name, ws.website_slug
        FROM users u
        INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1
        WHERE u.id IN ($idList)
    ";
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row['user_id'];
            $slug = $row['website_slug'] ?? '';
            $meta[$uid] = [
                'user_id'       => $uid,
                'business_name' => $row['business_name'] ?? '',
                'slug'          => $slug,
                'shop_url'      => ($slug !== '' && function_exists('url'))
                    ? url('web/shop.php?slug=' . urlencode($slug))
                    : null,
                'logo_url'      => null,
            ];
        }
        $res->free();
    }

    // لۆگۆکان لە kasher_media.user_logos
    if ($conn_images instanceof mysqli && !empty($meta)) {
        if ($logoRes = $conn_images->query("SELECT user_id, logo_url FROM user_logos WHERE user_id IN ($idList)")) {
            while ($row = $logoRes->fetch_assoc()) {
                $uid = (int)$row['user_id'];
                if (isset($meta[$uid]) && !empty($row['logo_url'])) {
                    $meta[$uid]['logo_url'] = $row['logo_url'];
                }
            }
            $logoRes->free();
        }
    }

    return $meta;
}

/**
 * زانیاری کاڵا بۆ ڤیدیۆی جۆری product (هەمان لۆژیکی buildVideoProductsMap ی سایتەکە).
 * @param int[] $productIds
 * @return array<int,array<string,mixed>>  [product_id => [...]]
 */
function app_api_videos_products(mysqli $conn, array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', array_filter($productIds))));
    if (empty($productIds)) {
        return [];
    }
    $idList = implode(',', $productIds);

    $sql = "
        SELECT
            p.id, p.name, p.image_path, pd.main_image,
            COALESCE(pu.sell_price, 0)      AS retail_price,
            COALESCE(pu.stock_quantity, 0)  AS stock_quantity,
            COALESCE(pu.id, '')             AS unit_id,
            COALESCE(u2.name, 'دانە')       AS unit_name,
            pd.discount_price,
            COALESCE(p.currency, 'IQD')     AS currency,
            ws.website_slug
        FROM products p
        INNER JOIN users u ON p.user_id = u.id
        INNER JOIN website_settings ws ON u.id = ws.user_id AND ws.is_active = 1
        LEFT JOIN product_details pd ON p.id = pd.product_id
        LEFT JOIN product_units pu ON p.id = pu.product_id
            AND (
                pu.is_primary = 1
                OR (
                    pu.is_primary = 0
                    AND NOT EXISTS (SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1)
                    AND pu.id = (SELECT MIN(id) FROM product_units WHERE product_id = p.id)
                )
            )
        LEFT JOIN units u2 ON pu.unit_id = u2.id
        LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = p.user_id
        WHERE p.id IN ($idList)
          AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
    ";

    $map = [];
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $pid = (int)$row['id'];
            if ($pid <= 0) {
                continue;
            }
            $currency  = $row['currency'] ?? 'IQD';
            $retail    = isset($row['retail_price']) ? (float)$row['retail_price'] : 0.0;
            $discount  = isset($row['discount_price']) ? (float)$row['discount_price'] : 0.0;
            $final     = $discount > 0 ? $discount : $retail;

            $imgSource = !empty($row['main_image']) ? $row['main_image'] : ($row['image_path'] ?? '');

            $map[$pid] = [
                'id'                     => $pid,
                'name'                   => $row['name'] ?? '',
                'image'                  => app_api_product_image_url($imgSource),
                'currency'               => $currency,
                'retail_price'           => $retail,
                'discount_price'         => $discount > 0 ? $discount : null,
                'final_price'            => $final,
                'retail_price_formatted' => app_api_format_price($retail, $currency),
                'final_price_formatted'  => app_api_format_price($final, $currency),
                'has_discount'           => $discount > 0,
                'in_stock'               => (isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0) > 0,
                'unit_id'                => ($row['unit_id'] !== '' ? (int)$row['unit_id'] : null),
                'unit_name'              => $row['unit_name'] ?? 'دانە',
                'website_slug'           => $row['website_slug'] ?? '',
            ];
        }
        $res->free();
    }

    return $map;
}

/**
 * ژمارەی بینین و لایک بۆ کۆمەڵێک ڤیدیۆ لە یەک جار (kasher_z).
 * @param array<int,array{0:string,1:int}> $pairs  [ [type, id], ... ]
 * @return array{views:array<string,int>,likes:array<string,int>}
 */
function app_api_videos_engagement(?mysqli $conn_zanyari, array $freeIds, array $productIds): array
{
    $views = [];
    $likes = [];
    if (!($conn_zanyari instanceof mysqli)) {
        return ['views' => $views, 'likes' => $likes];
    }

    $conditions = [];
    if (!empty($freeIds)) {
        $conditions[] = "(video_type = 'free' AND video_id IN (" . implode(',', array_map('intval', $freeIds)) . "))";
    }
    if (!empty($productIds)) {
        $conditions[] = "(video_type = 'product' AND video_id IN (" . implode(',', array_map('intval', $productIds)) . "))";
    }
    if (empty($conditions)) {
        return ['views' => $views, 'likes' => $likes];
    }
    $whereClause = implode(' OR ', $conditions);

    foreach ([['video_views', 'views'], ['video_likes', 'likes']] as [$table, $bucket]) {
        try {
            $sql = "SELECT video_type, video_id, COUNT(*) AS c FROM $table WHERE $whereClause GROUP BY video_type, video_id";
            if ($res = $conn_zanyari->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    $t = $row['video_type'] ?? '';
                    $vid = isset($row['video_id']) ? (int)$row['video_id'] : 0;
                    if ($t !== '' && $vid > 0) {
                        ${$bucket}[$t . '_' . $vid] = (int)($row['c'] ?? 0);
                    }
                }
                $res->free();
            }
        } catch (Throwable $e) {
            // fail-soft: ئەگەر خشتەکە نەبوو، ژمارە بە سفر دەمێنێتەوە
        }
    }

    return ['views' => $views, 'likes' => $likes];
}

// ------------------------------------------------------------------
// دیاریکردنی مەودای فیلتەر (فرۆشگای دیاریکراو یان فیدی گشتی)
// ------------------------------------------------------------------
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = max(1, min(50, $perPage));
$offset  = ($page - 1) * $perPage;

// یەک ڤیدیۆی دیاریکراو؟
$singleType = (!empty($_GET['video_type']) && in_array($_GET['video_type'], ['free', 'product'], true))
    ? $_GET['video_type'] : null;
$singleId   = isset($_GET['video_id']) ? (int)$_GET['video_id'] : 0;

// user_id ـەکانی ڕێگەپێدراو
$allowedUserIds = null;   // null = بێ سنوور (تەنها لە حاڵەتی نائاسایی)
$shopMetaSingle = null;

if ($slug !== '') {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        app_api_error('ناسنامەی فرۆشگا (slug) دروست نییە.', 400, 'invalid_slug');
    }
    $shop = app_api_get_shop_by_slug($conn, $slug);
    if (!$shop) {
        app_api_error('فرۆشگاکە نەدۆزرایەوە یان ناچالاکە.', 404, 'shop_not_found');
    }
    // ئاسایشی: فرۆشگای سنووردار بە گووگڵ ڕێگە نادرێت (وەک endpoint ـەکانی تر)
    app_api_guard_shop_access($conn, (int)$shop['user_id']);
    $allowedUserIds = [(int)$shop['user_id']];
} else {
    // فیدی گشتی: تەنها فرۆشگاکانی is_active=1 (و show_on_index=1 ئەگەر هەبوو)
    $whereActive = app_api_has_show_on_index($conn)
        ? "is_active = 1 AND show_on_index = 1"
        : "is_active = 1";
    $allowedUserIds = [];
    if ($res = $conn->query("SELECT user_id FROM website_settings WHERE $whereActive")) {
        while ($r = $res->fetch_assoc()) {
            if (!empty($r['user_id'])) {
                $allowedUserIds[] = (int)$r['user_id'];
            }
        }
        $res->free();
    }
}

// ------------------------------------------------------------------
// وەرگرتنی لیستی ڤیدیۆکان
// ------------------------------------------------------------------
$rows = [];
$total = 0;

if (!empty($allowedUserIds)) {
    $idList = implode(',', array_map('intval', $allowedUserIds));

    // شەرتی جۆر/ئایدی (بۆ یەک ڤیدیۆی دیاریکراو)
    $freeExtra = '';
    $prodExtra = '';
    if ($singleType !== null && $singleId > 0) {
        if ($singleType === 'free') {
            $freeExtra = " AND id = " . $singleId;
            $prodExtra = " AND 0 = 1";
        } else {
            $freeExtra = " AND 0 = 1";
            $prodExtra = " AND id = " . $singleId;
        }
    }

    $unionSql = "
        SELECT id, user_id, NULL AS product_id, video_description, video_url,
               video_duration_seconds, audio_type, created_at, 'free' AS video_type
        FROM free_videos
        WHERE user_id IN ($idList) AND video_url <> ''$freeExtra
        UNION ALL
        SELECT id, user_id, product_id, video_description, video_url,
               video_duration_seconds, audio_type, created_at, 'product' AS video_type
        FROM product_videos
        WHERE user_id IN ($idList) AND video_url <> ''$prodExtra
    ";

    // کۆی گشتی (بۆ pagination)
    if ($cres = $conn_videos->query("SELECT COUNT(*) AS total FROM ($unionSql) AS v")) {
        $total = (int)($cres->fetch_assoc()['total'] ?? 0);
        $cres->free();
    }

    // ڕیزکردن: نوێترین سەرەوە (فۆرماتی جێگیر بۆ pagination)
    $listSql = $unionSql . " ORDER BY created_at DESC, video_type ASC, id DESC LIMIT ? OFFSET ?";
    if ($stmt = $conn_videos->prepare($listSql)) {
        $stmt->bind_param('ii', $perPage, $offset);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
    }
}

// ------------------------------------------------------------------
// دەوڵەمەندکردنی داتا (فرۆشگا، کاڵا، بینین/لایک)
// ------------------------------------------------------------------
$userIds = [];
$productIds = [];
$freeIds = [];
$prodVideoIds = [];
foreach ($rows as $r) {
    if (!empty($r['user_id'])) {
        $userIds[] = (int)$r['user_id'];
    }
    if (($r['video_type'] ?? '') === 'product' && !empty($r['product_id'])) {
        $productIds[] = (int)$r['product_id'];
    }
    $vid = (int)$r['id'];
    if (($r['video_type'] ?? '') === 'free') {
        $freeIds[] = $vid;
    } else {
        $prodVideoIds[] = $vid;
    }
}

$shopMeta    = app_api_videos_shop_meta($conn, $conn_images, $userIds);
$productsMap = app_api_videos_products($conn, $productIds);
$engagement  = app_api_videos_engagement($conn_zanyari, $freeIds, $prodVideoIds);

// ------------------------------------------------------------------
// دروستکردنی وەڵام
// ------------------------------------------------------------------
$videos = [];
foreach ($rows as $r) {
    $type   = ($r['video_type'] ?? '') === 'product' ? 'product' : 'free';
    $vid    = (int)$r['id'];
    $uid    = !empty($r['user_id']) ? (int)$r['user_id'] : null;
    $key    = $type . '_' . $vid;

    $shopInfo = null;
    if ($uid !== null && isset($shopMeta[$uid])) {
        $shopInfo = $shopMeta[$uid];
    }

    $productInfo = null;
    if ($type === 'product' && !empty($r['product_id'])) {
        $pid = (int)$r['product_id'];
        if (isset($productsMap[$pid])) {
            $productInfo = $productsMap[$pid];
        }
    }

    $videos[] = [
        'id'               => $vid,
        'video_type'       => $type,
        'video_url'        => $r['video_url'] ?? '',
        'description'      => $r['video_description'] !== null && $r['video_description'] !== ''
            ? $r['video_description'] : null,
        'duration_seconds' => isset($r['video_duration_seconds']) && $r['video_duration_seconds'] !== null
            ? (int)$r['video_duration_seconds'] : null,
        'audio_type'       => $r['audio_type'] ?? null,
        'created_at'       => $r['created_at'] ?? null,
        'view_count'       => $engagement['views'][$key] ?? 0,
        'like_count'       => $engagement['likes'][$key] ?? 0,
        'shop'             => $shopInfo,
        'product'          => $productInfo,
    ];
}

// یەک ڤیدیۆی دیاریکراو → تەنها ئەو ئۆبجێکتە بگەڕێنەوە
if ($singleType !== null && $singleId > 0) {
    if (empty($videos)) {
        app_api_error('ڤیدیۆکە نەدۆزرایەوە.', 404, 'video_not_found');
    }
    app_api_success($videos[0]);
}

$meta = [
    'page'        => $page,
    'per_page'    => $perPage,
    'total'       => $total,
    'total_pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 0,
];
if ($slug !== '' && isset($shopMeta)) {
    $meta['scope'] = 'shop';
    $meta['slug']  = $slug;
} else {
    $meta['scope'] = 'index';
}

app_api_success($videos, $meta);
