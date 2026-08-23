<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/product_videos/database.php';
require_once __DIR__ . '/../config/product_images/database.php';
require_once __DIR__ . '/../config/kasher_zanyari/database.php';

global $conn;

// ڕێکخستنی سنوورداری وەرگرتنی ڤیدیۆکان
$videoLimit = 50;

// ئەگەر لە فرۆشگا هاتووە (shop_user_id)، تەنها ڤیدیۆکانی ئەو فرۆشگایە نیشان بدە
$shopUserIdFilter = null;
if (isset($_GET['shop_user_id'])) {
    $tmp = (int) $_GET['shop_user_id'];
    if ($tmp > 0) {
        $shopUserIdFilter = $tmp;
    }
}

// بۆ لیستی گشتی: تەنها ڤیدیۆکانی فرۆشگاکانی show_on_index = 1 لە website_settings
$allowedUserIdsForIndex = null;
if ($shopUserIdFilter === null && $conn instanceof mysqli) {
    $checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_on_index'");
    $hasShowOnIndexColumn = $checkColumnStmt && $checkColumnStmt->num_rows > 0;
    if ($checkColumnStmt) {
        $checkColumnStmt->close();
    }
    $whereClause = $hasShowOnIndexColumn
        ? "WHERE is_active = 1 AND show_on_index = 1"
        : "WHERE is_active = 1";
    $idsResult = $conn->query("SELECT user_id FROM website_settings $whereClause");
    if ($idsResult instanceof mysqli_result) {
        $allowedUserIdsForIndex = [];
        while ($r = $idsResult->fetch_assoc()) {
            if (!empty($r['user_id'])) {
                $allowedUserIdsForIndex[] = (int) $r['user_id'];
            }
        }
        $idsResult->free();
    }
}

// وەرگرتنی لیستی ڤیدیۆکان لە free_videos و product_videos (ئەگەر shop_user_id هەبێت تەنها ڤیدیۆکانی ئەو فرۆشگایە)
$feedItems = [];

if (!empty($conn_videos)) {
    if ($shopUserIdFilter !== null) {
        $sql = "
            SELECT id, user_id, NULL AS product_id, video_description, video_url, created_at, 'free' AS video_type
            FROM free_videos
            WHERE user_id = ?
            UNION ALL
            SELECT id, user_id, product_id, video_description, video_url, created_at, 'product' AS video_type
            FROM product_videos
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ";
    } else {
        // لە گشتی تەنها ڤیدیۆکانی فرۆشگاکانی show_on_index چالاک؛ ئەگەر هیچ فرۆشگایەک نەبێت، ڤیدیۆ نیشان نەدرێت
        if (!empty($allowedUserIdsForIndex)) {
            $idList = implode(',', array_map('intval', $allowedUserIdsForIndex));
            $sql = "
                SELECT id, user_id, NULL AS product_id, video_description, video_url, created_at, 'free' AS video_type
                FROM free_videos
                WHERE user_id IN ($idList)
                UNION ALL
                SELECT id, user_id, product_id, video_description, video_url, created_at, 'product' AS video_type
                FROM product_videos
                WHERE user_id IN ($idList)
                ORDER BY RAND()
                LIMIT ?
            ";
        } else {
            $sql = null; // هیچ فرۆشگایەک show_on_index چالاک نییە، ڤیدیۆ نیشان نەدرێت
        }
    }

    try {
        if ($sql === null) {
            $feedItems = [];
        } else {
            $stmt = $conn_videos->prepare($sql);
        if ($stmt) {
            if ($shopUserIdFilter !== null) {
                $stmt->bind_param('iii', $shopUserIdFilter, $shopUserIdFilter, $videoLimit);
            } else {
                $stmt->bind_param('i', $videoLimit);
            }
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    if (!empty($row['video_url'])) {
                        $feedItems[] = $row;
                    }
                }
            }
            $stmt->close();
        }
        }
    } catch (Throwable $e) {
        if (function_exists('writeLog')) {
            writeLog('Video feed query failed: ' . $e->getMessage(), 'ERROR');
        }
        $feedItems = [];
    }
}

// وەرگرتنی ناو، لۆگۆ و slug ـی فرۆشگا بەپێی user_id ـە جیاوازەکان
$logosByUserId = [];
$shopUrlsByUserId = [];
$storeNamesByUserId = [];
if (!empty($feedItems)) {
    $userIds = [];
    foreach ($feedItems as $item) {
        if (!empty($item['user_id'])) {
            $userIds[(int)$item['user_id']] = true;
        }
    }

    if (!empty($userIds)) {
        $idList = implode(',', array_map('intval', array_keys($userIds)));

        // لۆگۆکان لە داتابەیسی kasher_media
        if (!empty($conn_images)) {
            $logoSql = "SELECT user_id, logo_url FROM user_logos WHERE user_id IN ($idList)";
            $logoResult = $conn_images->query($logoSql);
            if ($logoResult instanceof mysqli_result) {
                while ($logo = $logoResult->fetch_assoc()) {
                    if (!empty($logo['user_id']) && !empty($logo['logo_url'])) {
                        $logosByUserId[(int)$logo['user_id']] = $logo['logo_url'];
                    }
                }
            }
        }

        // slug ـی فرۆشگا بەپێی هەر user_id لە خشتەی website_settings
        try {
            if ($conn instanceof mysqli) {
                $slugSql = "SELECT user_id, website_slug FROM website_settings WHERE user_id IN ($idList)";
                if ($slugResult = $conn->query($slugSql)) {
                    while ($row = $slugResult->fetch_assoc()) {
                        if (!empty($row['user_id']) && !empty($row['website_slug']) && function_exists('url')) {
                            $uId = (int)$row['user_id'];
                            $slug = $row['website_slug'];
                            $shopUrlsByUserId[$uId] = url('web/shop.php?slug=' . urlencode($slug));
                        }
                    }
                    $slugResult->free();
                }
            }
        } catch (Throwable $e) {
            if (function_exists('writeLog')) {
                writeLog('Website slug fetch failed: ' . $e->getMessage(), 'ERROR');
            }
        }

        // ناوی فرۆشگا لە خشتەی users.business_name
        try {
            if ($conn instanceof mysqli) {
                $nameSql = "SELECT id, business_name FROM users WHERE id IN ($idList)";
                if ($nameResult = $conn->query($nameSql)) {
                    while ($row = $nameResult->fetch_assoc()) {
                        if (!empty($row['id']) && !empty($row['business_name'])) {
                            $storeNamesByUserId[(int)$row['id']] = $row['business_name'];
                        }
                    }
                    $nameResult->free();
                }
            }
        } catch (Throwable $e) {
            if (function_exists('writeLog')) {
                writeLog('Store name fetch failed: ' . $e->getMessage(), 'ERROR');
            }
        }
    }
}

// ڕیزبەندی بەپێی زۆرترین بینەر + بۆنوسی کاڵایی (ڤیدیۆی نوێ ئەگەری زیاتر)
// و کۆکردنەوەی total watch_seconds بۆ ڤیدیۆی کاڵاکان (product) لە ٣٠ ڕۆژی دوایى
$viewCountMap = [];
$watchSecondsMap = [];
$userWatchMap = [];
if (!empty($feedItems) && !empty($conn_zanyari) && $conn_zanyari instanceof mysqli) {
    $freeIds = [];
    $productIds = [];
    foreach ($feedItems as $item) {
        $type = $item['video_type'] ?? '';
        $id = isset($item['id']) ? (int)$item['id'] : 0;
        if ($id <= 0) {
            continue;
        }
        if ($type === 'free') {
            $freeIds[] = $id;
        } elseif ($type === 'product') {
            $productIds[] = $id;
        }
    }
    $freeIds = array_values(array_unique(array_map('intval', $freeIds)));
    $productIds = array_values(array_unique(array_map('intval', $productIds)));

    // ژمارەی بینەرەکان بەپێی video_views (هەموو بەکارهێنەران)
    $conditions = [];
    if (!empty($freeIds)) {
        $conditions[] = "(video_type = 'free' AND video_id IN (" . implode(',', array_map('intval', $freeIds)) . "))";
    }
    if (!empty($productIds)) {
        $conditions[] = "(video_type = 'product' AND video_id IN (" . implode(',', array_map('intval', $productIds)) . "))";
    }
    if (!empty($conditions)) {
        try {
            $viewSql = "SELECT video_type, video_id, COUNT(*) AS view_count FROM video_views WHERE " . implode(' OR ', $conditions) . " GROUP BY video_type, video_id";
            $viewResult = $conn_zanyari->query($viewSql);
            if ($viewResult instanceof mysqli_result) {
                while ($row = $viewResult->fetch_assoc()) {
                    $t = $row['video_type'] ?? '';
                    $vid = isset($row['video_id']) ? (int)$row['video_id'] : 0;
                    if ($t !== '' && $vid > 0) {
                        $viewCountMap[$t . '_' . $vid] = (int)($row['view_count'] ?? 0);
                    }
                }
                $viewResult->free();
            }
        } catch (Throwable $e) {
            if (function_exists('writeLog')) {
                writeLog('Video view count batch failed: ' . $e->getMessage(), 'ERROR');
            }
        }
    }

    // کۆکردنەوەی watch_seconds بۆ ڤیدیۆی کاڵاکان لە ٣٠ ڕۆژی دوایى
    if (!empty($productIds)) {
        $productIdList = implode(',', array_map('intval', $productIds));
        $watchSql = "
            SELECT video_id, SUM(watch_seconds) AS total_watch_seconds
            FROM video_views
            WHERE video_type = 'product'
              AND video_id IN ($productIdList)
              AND viewed_at >= (NOW() - INTERVAL 30 DAY)
            GROUP BY video_id
        ";
        try {
            $watchResult = $conn_zanyari->query($watchSql);
            if ($watchResult instanceof mysqli_result) {
                while ($row = $watchResult->fetch_assoc()) {
                    $vid = isset($row['video_id']) ? (int)$row['video_id'] : 0;
                    if ($vid > 0) {
                        $watchSecondsMap['product_' . $vid] = (int)($row['total_watch_seconds'] ?? 0);
                    }
                }
                $watchResult->free();
            }
        } catch (Throwable $e) {
            if (function_exists('writeLog')) {
                writeLog('Video watch_seconds aggregation failed: ' . $e->getMessage(), 'ERROR');
            }
        }
    }

    // watch-status ـی بەکارهێنەر (Google یان میوان) بەپێی video_views
    $currentGoogleUserId = !empty($_SESSION['google_user']['id']) ? (int)$_SESSION['google_user']['id'] : null;
    $viewerIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($viewerIp, ',') !== false) {
        $viewerIp = trim(explode(',', $viewerIp)[0]);
    }
    $viewerIp = substr($viewerIp, 0, 45);

    $sessionId = session_id();
    $viewerUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if (!empty($sessionId)) {
        $viewerUserAgent .= ' [SID:' . $sessionId . ']';
    }
    $viewerUserAgent = substr($viewerUserAgent, 0, 500);

    if ((!empty($freeIds) || !empty($productIds))) {
        $userConditions = [];
        if (!empty($freeIds)) {
            $userConditions[] = "(video_type = 'free' AND video_id IN (" . implode(',', array_map('intval', $freeIds)) . "))";
        }
        if (!empty($productIds)) {
            $userConditions[] = "(video_type = 'product' AND video_id IN (" . implode(',', array_map('intval', $productIds)) . "))";
        }
        if (!empty($userConditions)) {
            if ($currentGoogleUserId) {
                // بەکارهێنەری Google ـی هاتۆژوور
                $userSql = "
                    SELECT video_type, video_id, MAX(viewed_at) AS last_watched_at
                    FROM video_views
                    WHERE google_user_id = ?
                      AND (" . implode(' OR ', $userConditions) . ")
                    GROUP BY video_type, video_id
                ";
                try {
                    if ($stmt = $conn_zanyari->prepare($userSql)) {
                        $stmt->bind_param('i', $currentGoogleUserId);
                        if ($stmt->execute() && ($res = $stmt->get_result())) {
                            while ($row = $res->fetch_assoc()) {
                                $t = $row['video_type'] ?? '';
                                $vid = isset($row['video_id']) ? (int)$row['video_id'] : 0;
                                $ts = 0;
                                if (!empty($row['last_watched_at'])) {
                                    $parsed = strtotime($row['last_watched_at']);
                                    if ($parsed !== false) {
                                        $ts = $parsed;
                                    }
                                }
                                if ($t !== '' && $vid > 0 && $ts > 0) {
                                    $userWatchMap[$t . '_' . $vid] = $ts;
                                }
                            }
                        }
                        $stmt->close();
                    }
                } catch (Throwable $e) {
                    if (function_exists('writeLog')) {
                        writeLog('Video watch-status per google user failed: ' . $e->getMessage(), 'ERROR');
                    }
                }
            } else {
                // میوان: track بەپێی IP + User-Agent هەمان لۆژیکی video_view.php
                $userSql = "
                    SELECT video_type, video_id, MAX(viewed_at) AS last_watched_at
                    FROM video_views
                    WHERE google_user_id IS NULL
                      AND ip_address = ?
                      AND user_agent = ?
                      AND (" . implode(' OR ', $userConditions) . ")
                    GROUP BY video_type, video_id
                ";
                try {
                    if ($stmt = $conn_zanyari->prepare($userSql)) {
                        $stmt->bind_param('ss', $viewerIp, $viewerUserAgent);
                        if ($stmt->execute() && ($res = $stmt->get_result())) {
                            while ($row = $res->fetch_assoc()) {
                                $t = $row['video_type'] ?? '';
                                $vid = isset($row['video_id']) ? (int)$row['video_id'] : 0;
                                $ts = 0;
                                if (!empty($row['last_watched_at'])) {
                                    $parsed = strtotime($row['last_watched_at']);
                                    if ($parsed !== false) {
                                        $ts = $parsed;
                                    }
                                }
                                if ($t !== '' && $vid > 0 && $ts > 0) {
                                    $userWatchMap[$t . '_' . $vid] = $ts;
                                }
                            }
                        }
                        $stmt->close();
                    }
                } catch (Throwable $e) {
                    if (function_exists('writeLog')) {
                        writeLog('Video watch-status per guest failed: ' . $e->getMessage(), 'ERROR');
                    }
                }
            }
        }
    }

    $now = time();
    $recencyBoost7 = 50;
    $recencyBoost30 = 25;
    foreach ($feedItems as &$item) {
        $key = ($item['video_type'] ?? '') . '_' . (isset($item['id']) ? (int)$item['id'] : 0);
        $item['_view_count'] = $viewCountMap[$key] ?? 0;
        if (($item['video_type'] ?? '') === 'product') {
            $item['_watch_seconds'] = $watchSecondsMap[$key] ?? 0;
        } else {
            $item['_watch_seconds'] = 0;
        }
        $createdAt = $item['created_at'] ?? '';
        $createdTs = $createdAt !== '' ? strtotime($createdAt) : $now;
        $daysAgo = ($now - $createdTs) / 86400;
        $item['_recency_boost'] = $daysAgo <= 7 ? $recencyBoost7 : ($daysAgo <= 30 ? $recencyBoost30 : 0);

        // watch-status بۆ ئەم بەکارهێنەرە
        if (isset($userWatchMap[$key])) {
            $item['_watched'] = true;
            $item['_last_watched_ts'] = $userWatchMap[$key];
        } else {
            $item['_watched'] = false;
            $item['_last_watched_ts'] = 0;
        }
    }
    unset($item);

    usort($feedItems, static function ($a, $b) {
        $typeA = $a['video_type'] ?? '';
        $typeB = $b['video_type'] ?? '';

        // یەکەم: ڤیدیۆی نەبینراو (watched = false) پێش ڤیدیۆی بینراو بێت
        $watchedA = $a['_watched'] ?? false;
        $watchedB = $b['_watched'] ?? false;
        if ($watchedA !== $watchedB) {
            return $watchedA ? 1 : -1; // false (نەبینراو) پێش true (بینراو)
        }

        // ئەگەر هەردووکیان بینراون، بە بنەمای last_watched_ts ڕیزبەندی بکە
        if ($watchedA && $watchedB) {
            $tsA = $a['_last_watched_ts'] ?? 0;
            $tsB = $b['_last_watched_ts'] ?? 0;
            if ($tsA !== $tsB) {
                // ڤیدیۆی تازەترین بینراو لە دواوە بێت
                return $tsA <=> $tsB;
            }
        }

        // دوای watch-status، هەمان بیرۆکەی scoring ی پێشووتر
        // مەرج: ڤیدیۆی کاڵا هەمیشە لە سەرەوەی فیددا بێت
        if ($typeA !== $typeB) {
            return $typeA === 'product' ? -1 : 1;
        }

        // ئەگەر هەردووکیان product بن، بەپێی total_watch_seconds ڕیزبەندی بکە
        if ($typeA === 'product' && $typeB === 'product') {
            $watchA = $a['_watch_seconds'] ?? 0;
            $watchB = $b['_watch_seconds'] ?? 0;
            if ($watchB !== $watchA) {
                return $watchB <=> $watchA;
            }
        }

        $scoreA = ($a['_view_count'] ?? 0) + ($a['_recency_boost'] ?? 0);
        $scoreB = ($b['_view_count'] ?? 0) + ($b['_recency_boost'] ?? 0);
        if ($scoreB !== $scoreA) {
            return $scoreB <=> $scoreA;
        }
        $tsCreatedA = strtotime($a['created_at'] ?? '0');
        $tsCreatedB = strtotime($b['created_at'] ?? '0');
        return $tsCreatedB <=> $tsCreatedA;
    });
}

// ناوی سیستەم و پارامەتەرەکانی deep-linking
$siteName = defined('SITE_NAME') ? SITE_NAME : 'NexoraCore';

/**
 * وەسفی ڤیدیۆ بە هاشتاگ highlight و clickable دەکات.
 * `$text` لە پێشدا `htmlspecialchars` کرابێت.
 */
function renderVideoDescriptionWithHashtags(?string $text): string
{
    if ($text === null || $text === '') {
        return '';
    }

    // spaces/punctuation + #tag (Unicode-aware)
    $pattern = '/(^|[\s\p{P}])#([\p{L}\p{M}0-9_]+)/u';

    $callback = static function (array $m): string {
        $prefix = $m[1];
        $tagRaw = $m[2];
        $tagForUrl = trim($tagRaw);
        if ($tagForUrl === '') {
            return $m[0];
        }

        $href = 'tag/index.php?tag=' . rawurlencode($tagForUrl);

        return $prefix
            . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="video-hashtag" rel="nofollow">#'
            . htmlspecialchars($tagRaw, ENT_QUOTES, 'UTF-8')
            . '</a>';
    };

    return preg_replace_callback($pattern, $callback, $text) ?? $text;
}

/**
 * وێنەی کاڵا بۆ ڤیدیۆی کاڵاکان دێرێت، بەهۆی وێنەی سەرەکی یان main_image.
 */
function getVideoProductImage(array $productRow): string
{
    if (!empty($productRow['main_image'])) {
        $u = product_image_url($productRow['main_image']);
        if ($u) {
            return $u;
        }
    }
    if (!empty($productRow['image_path'])) {
        $u = product_image_url($productRow['image_path']);
        if ($u) {
            return $u;
        }
    }
    return SITE_URL . 'web/template/assets/images/no-image.svg';
}

/**
 * وەرگرتنی وردەکاری کاڵاکان بۆ ڤیدیۆکانی product_videos بەپێی product_id.
 *
 * داتای گەڕاوە:
 *  - id, name, retail_price, stock_quantity, unit_id, unit_name, discount_price,
 *    currency, website_slug, image_url
 */
function buildVideoProductsMap(mysqli $conn, array $feedItems): array
{
    $productIds = [];

    foreach ($feedItems as $item) {
        if (
            isset($item['video_type'], $item['product_id'])
            && $item['video_type'] === 'product'
        ) {
            $pid = (int)$item['product_id'];
            if ($pid > 0) {
                $productIds[$pid] = true;
            }
        }
    }

    if (empty($productIds)) {
        return [];
    }

    $idList = implode(',', array_map('intval', array_keys($productIds)));

    $sql = "
        SELECT
            p.id,
            p.name,
            p.image_path,
            pd.main_image,
            COALESCE(pu.sell_price, 0)      AS retail_price,
            COALESCE(pu.stock_quantity, 0) AS stock_quantity,
            COALESCE(pu.id, '')                        AS unit_id,
            COALESCE(u2.name, 'دانە')                  AS unit_name,
            pd.discount_price,
            COALESCE(p.currency, 'IQD')                AS currency,
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
                    AND NOT EXISTS (
                        SELECT 1 FROM product_units WHERE product_id = p.id AND is_primary = 1
                    )
                    AND pu.id = (
                        SELECT MIN(id) FROM product_units WHERE product_id = p.id
                    )
                )
            )
        LEFT JOIN units u2 ON pu.unit_id = u2.id
        LEFT JOIN website_product_visibility wpv ON p.id = wpv.product_id AND wpv.user_id = p.user_id
        WHERE p.id IN ($idList)
          AND (wpv.is_visible = 1 OR wpv.is_visible IS NULL)
    ";

    $map = [];

    try {
        $result = $conn->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $productId = (int)$row['id'];
                if ($productId <= 0) {
                    continue;
                }

                $currency = $row['currency'] ?? 'IQD';
                $retailPrice = isset($row['retail_price']) ? (float)$row['retail_price'] : 0.0;
                $discountPrice = isset($row['discount_price']) ? (float)$row['discount_price'] : 0.0;
                $finalPrice = $discountPrice > 0 ? $discountPrice : $retailPrice;

                $imageUrl = getVideoProductImage($row);

                $map[$productId] = [
                    'id'            => $productId,
                    'name'          => $row['name'] ?? '',
                    'retail_price'  => $retailPrice,
                    'discount_price'=> $discountPrice,
                    'final_price'   => $finalPrice,
                    'stock_quantity'=> isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0,
                    'unit_id'       => $row['unit_id'] ?? '',
                    'unit_name'     => $row['unit_name'] ?? 'دانە',
                    'currency'      => $currency,
                    'website_slug'  => $row['website_slug'] ?? '',
                    'image_url'     => $imageUrl,
                ];
            }
            $result->free();
        }
    } catch (Throwable $e) {
        if (function_exists('writeLog')) {
            writeLog('Video product map query failed: ' . $e->getMessage(), 'ERROR');
        }
        return [];
    }

    return $map;
}

$targetVideoType = null;
$targetVideoId = null;
if (!empty($_GET['video_type']) && in_array($_GET['video_type'], ['free', 'product'], true)) {
    $targetVideoType = $_GET['video_type'];
}
if (isset($_GET['video_id'])) {
    $tmpId = (int)$_GET['video_id'];
    if ($tmpId > 0) {
        $targetVideoId = $tmpId;
    }
}

// ئەگەر deep-link بۆ ڤیدیۆی دیاری‌کراو هەبوو، ئەو ڤیدیۆیە بۆ سەرەوەی فید ببەرە
if ($targetVideoType !== null && $targetVideoId !== null && !empty($feedItems)) {
    foreach ($feedItems as $idx => $item) {
        $itemId = isset($item['id']) ? (int)$item['id'] : null;
        $itemType = isset($item['video_type']) ? $item['video_type'] : null;

        if ($itemId === $targetVideoId && $itemType === $targetVideoType) {
            $targetItem = $item;
            unset($feedItems[$idx]);
            array_unshift($feedItems, $targetItem);
            $feedItems = array_values($feedItems);
            break;
        }
    }
}

// وەرگرتنی وردەکاری کاڵاکان بۆ ڤیدیۆکانی product_videos
$videoProductsMap = [];
if (!empty($feedItems) && $conn instanceof mysqli) {
    $videoProductsMap = buildVideoProductsMap($conn, $feedItems);
}

// زانیاری سەردانیکەر و ستیتەکانی لایک/بینەر بۆ ڤیدیۆکان
$videoLikesVisitorIp = null;
$videoLikesUserAgent = null;
$videoLikesCountStmt = null;
$videoLikesUserLikedStmtGuest = null;
$videoLikesUserLikedStmtGoogle = null;
$videoViewsCountStmt = null;
$googleSessionUserId = !empty($_SESSION['google_user']['id']) ? (int)$_SESSION['google_user']['id'] : null;

if (!empty($conn_zanyari) && !empty($feedItems)) {
    $videoLikesVisitorIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($videoLikesVisitorIp, ',') !== false) {
        $videoLikesVisitorIp = trim(explode(',', $videoLikesVisitorIp)[0]);
    }
    $videoLikesVisitorIp = substr($videoLikesVisitorIp, 0, 45);

    $videoLikesUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $videoLikesUserAgent = substr($videoLikesUserAgent, 0, 500);

    try {
        $videoLikesCountStmt = $conn_zanyari->prepare(
            "SELECT COUNT(*) AS total FROM video_likes WHERE video_type = ? AND video_id = ?"
        );
        $videoLikesUserLikedStmtGuest = $conn_zanyari->prepare(
            "SELECT 1 FROM video_likes WHERE video_type = ? AND video_id = ? AND ip_address = ? AND user_agent = ? LIMIT 1"
        );
        if ($googleSessionUserId !== null) {
            $videoLikesUserLikedStmtGoogle = $conn_zanyari->prepare(
                "SELECT 1 FROM video_likes WHERE video_type = ? AND video_id = ? AND google_user_id = ? LIMIT 1"
            );
        }
        $videoViewsCountStmt = $conn_zanyari->prepare(
            "SELECT COUNT(*) AS total FROM video_views WHERE video_type = ? AND video_id = ?"
        );
    } catch (Throwable $e) {
        if (function_exists('writeLog')) {
            writeLog('Video likes/views prepare failed: ' . $e->getMessage(), 'ERROR');
        }
        $videoLikesCountStmt = null;
        $videoLikesUserLikedStmtGuest = null;
        $videoLikesUserLikedStmtGoogle = null;
        $videoViewsCountStmt = null;
    }
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ڤیدیۆکان - <?php echo defined('SITE_NAME') ? SITE_NAME : 'NexoraCore'; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <?php if (function_exists('asset')): ?>
        <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <?php endif; ?>
    <link href="<?php echo SITE_URL; ?>web/template/assets/css/cart.css" rel="stylesheet">

    <style>
        :root {
            --video-bg: #000000;
            --video-card-radius: 18px;
            --video-overlay-gradient: linear-gradient(to top, rgba(0,0,0,0.9), transparent);
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            background-color: #000000;
            color: #f8f9fa;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            overflow: hidden;
            overscroll-behavior-y: none;
        }

        .video-feed-wrapper {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            height: 100%;
            width: 100%;
            overflow-y: scroll;
            scroll-snap-type: y mandatory;
            background: radial-gradient(circle at top, #141414 0, #000000 55%);
        }

        .video-feed-inner {
            min-height: 100%;
        }

        .video-card {
            position: relative;
            height: 100vh; /* fallback */
            height: 100%;
            box-sizing: border-box;
            scroll-snap-align: start;
            /* scroll-snap-stop removed */
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 0.75rem 2.5rem;
        }

        @media (min-width: 768px) {
            .video-card {
                padding-inline: 3rem;
            }
        }

        /* لابردنی بۆشایی سەرەوەی ڤیدیۆکە لەسەر ئامێر بچووك */
        @media (max-width: 767.98px) {
            .video-card {
                min-height: 100vh;
                min-height: 100dvh;
                height: 100vh;
                height: 100dvh;
                align-items: center; /* ڤیدیۆ ناوەڕاست لە بەشی پەڕاندندا، دەرکەوتن باشتر */
                padding-top: 0;
                padding-bottom: 0;
                scroll-snap-stop: always;
            }
            .video-frame {
                height: 100%;
                max-width: 100%;
                border-radius: 0;
            }
        }

        .video-frame {
            position: relative;
            width: 100%;
            max-width: 480px;
            height: 88vh;
            background: #050505;
            border-radius: var(--video-card-radius);
            overflow: hidden;
            box-shadow:
                0 24px 60px rgba(0, 0, 0, 0.8),
                0 0 0 1px rgba(255,255,255,0.06);
        }

        @media (min-width: 992px) {
            .video-frame {
                max-width: 430px;
            }
        }

        .video-player {
            width: 100%;
            height: 100%;
            object-fit: contain; /* ڤیدیۆکە بە دیزاینی خۆی نیشان بدە، بەبێ برین */
            background-color: #000;
            display: block;
            transition: transform 260ms ease;
            will-change: transform;
            transform-origin: center center;
        }

        /* Sync background video with the comments bottom-sheet */
        .video-card.comments-open .video-player {
            /* When comments are open, push the background video upward more.
               Keep it clearly visible above the sheet (TikTok/Reels feel). */
            transform: translateY(-42px) scale(0.84);
        }

        .video-card.comments-dragging .video-player {
            transition: none !important;
        }

        /* شێوازی ڤیدیۆکە لە دۆخی Fullscreen ـدا */
        .video-frame:fullscreen,
        .video-frame:-webkit-full-screen {
            max-width: 100vw;
            height: 100vh;
            border-radius: 0;
        }

        .video-frame:fullscreen .video-player,
        .video-frame:-webkit-full-screen .video-player {
            object-fit: contain;
        }

        .video-overlay-top,
        .video-overlay-bottom {
            position: absolute;
            left: 0;
            right: 0;
            padding-inline: 1rem;
            z-index: 2;
            pointer-events: none;
        }

        .video-overlay-top {
            top: 0.85rem;
        }

        .video-overlay-bottom {
            bottom: 0;
            background: var(--video-overlay-gradient);
            padding-top: 1.5rem; /* نزیکتر بێت بە قەدێ خوارەوە */
        }

        .video-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .video-logo-group {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .video-logo {
            width: 44px;
            height: 44px;
            border-radius: 999px;
            object-fit: cover;
            background-color: transparent;
            cursor: pointer;
        }

        .video-logo-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 999px;
            background: radial-gradient(circle at 30% 0, #ffffff 0, #555555 40%, #111111 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f8f9fa;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
        }

        .video-type-badge {
            pointer-events: auto;
            font-size: 0.7rem;
            border-radius: 999px;
            padding: 0.25rem 0.65rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(15,23,42,0.9);
            color: #e5e7eb;
            border: 1px solid rgba(148,163,184,0.55);
            backdrop-filter: blur(10px);
        }

        .video-type-badge span {
            font-weight: 600;
        }

        .video-search-btn {
            pointer-events: auto;
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #f9fafb;
            cursor: pointer;
            text-decoration: none;
        }
        
        .video-search-btn i {
            font-size: 1.8rem;
            line-height: 1;
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.71);
            transition: transform 0.12s ease, color 0.15s ease;
        }
        
        .video-search-btn:hover i {
            transform: scale(1.05);
        }

        .video-description-box {
            max-width: 100%;
            pointer-events: auto;
        }

        .video-description {
            font-size: 0.92rem;
            line-height: 1.6;
            font-weight: 500;
            color: #f9fafb;
            text-shadow: 0 2px 12px rgba(0,0,0,0.8);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 0;
            transition: opacity 0.3s ease;
        }

        .video-description.expanded {
            -webkit-line-clamp: unset;
            max-height: 35vh;
            overflow-y: auto;
            margin-bottom: 0.2rem;
            padding-left: 2px;
            overscroll-behavior: contain;
            white-space: pre-line;
            overflow-wrap: break-word;
        }

        .video-description.expanded::-webkit-scrollbar {
            width: 3px;
        }
        .video-description.expanded::-webkit-scrollbar-track {
            background: transparent;
        }
        .video-description.expanded::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.5);
            border-radius: 4px;
        }

        .video-hashtag {
            color: #38bdf8;
            font-weight: 600;
            text-decoration: none;
            white-space: normal;
        }

        .video-hashtag:hover {
            color: #0ea5e9;
            text-decoration: underline;
            text-shadow: 0 0 8px rgba(56, 189, 248, 0.7);
        }

        .video-hashtag:focus-visible {
            outline: 2px solid #0ea5e9;
            outline-offset: 2px;
        }

        .video-read-more-btn {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            font-weight: 700;
            padding: 0;
            margin-top: 0.2rem;
            cursor: pointer;
            text-shadow: 0 2px 8px rgba(0,0,0,0.8);
            transition: color 0.15s ease, transform 0.15s ease;
            display: inline-block;
        }

        .video-read-more-btn:hover {
            color: #ffffff;
            transform: scale(1.02);
            text-decoration: underline;
        }

        .video-meta {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin-top: 0.55rem;
            font-size: 0.78rem;
            color: #e5e7eb;
            opacity: 0.85;
        }

        .video-meta-dot {
            width: 4px;
            height: 4px;
            border-radius: 999px;
            background: rgba(248,250,252,0.7);
        }

        .video-empty-state {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem 1.5rem;
            background: radial-gradient(circle at top, #1f2937 0, #020617 60%);
        }

        .video-empty-card {
            max-width: 460px;
            border-radius: 1.5rem;
            background: linear-gradient(145deg, rgba(15,23,42,0.95), rgba(17,24,39,0.98));
            border: 1px solid rgba(148,163,184,0.5);
            box-shadow:
                0 30px 80px rgba(15,23,42,0.95),
                0 0 0 1px rgba(15,23,42,0.6);
            padding: 1.75rem 1.5rem 2rem;
            color: #e5e7eb;
        }

        .video-empty-card h1 {
            font-size: 1.4rem;
            margin-bottom: 0.6rem;
        }

        .video-empty-card p {
            font-size: 0.9rem;
            margin-bottom: 0;
            color: #d1d5db;
        }

        /* Overlay بۆ چالاککردنی دەنگ */
        .video-start-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: opacity 0.3s ease;
        }

        .video-start-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .video-start-box {
            text-align: center;
            color: #fff;
            padding: 2rem;
        }

        .video-start-box i {
            font-size: 4rem;
            margin-bottom: 1rem;
            display: block;
            animation: pulse 1.5s infinite;
        }

        .video-start-box p {
            font-size: 1.1rem;
            margin: 0;
            opacity: 0.9;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        /* ئایکۆنی ناوەڕاست بۆ play / pause */
        .video-center-icon {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.25s ease;
            z-index: 3;
        }

        .video-center-icon.visible {
            opacity: 1;
        }

        .video-center-icon-inner {
            width: 80px;
            height: 80px;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f9fafb;
            box-shadow:
                0 18px 40px rgba(0, 0, 0, 0.9),
                0 0 0 1px rgba(255,255,255,0.25);
        }

        .video-center-icon-inner i {
            font-size: 2.6rem;
        }

        /* کاتی ڤیدیۆ و پڕۆگرەسی ڕۆیشتن */
        .video-time-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #ffffff;
            margin-top: 0.75rem;
            margin-bottom: 0.25rem;
            opacity: 0.9;
        }

        .video-time-current,
        .video-time-total {
            font-variant-numeric: tabular-nums;
        }

        .video-progress-container {
            position: relative;
            width: 100%;
            /* سنووری کلیکی پڕۆگرەس فراوانتر بێت بۆ ئاسانی */
            height: 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.18);
            overflow: hidden;
            cursor: pointer;
        }

        .video-progress-bar {
            position: absolute;
            inset: 0;
            background: #ffffff;
            transform-origin: left center;
            transform: scaleX(0);
            transition: transform 0.08s linear;
        }

        .video-progress-handle {
            position: absolute;
            top: 50%;
            left: 0;
            /* گەورەکردنی دوگمەکە بۆ سنووری قۆناغی زیاتر */
            width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow:
                0 0 0 1px rgba(0,0,0,0.4),
                0 2px 6px rgba(0,0,0,0.6);
            transform: translate(-50%, -50%);
            touch-action: none;
            pointer-events: auto;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }

        .video-progress-container.dragging .video-progress-handle {
            transform: translate(-50%, -50%) scale(1.4);
            box-shadow:
                0 0 0 1px rgba(0,0,0,0.6),
                0 3px 10px rgba(0, 0, 0, 0.66);
        }

        .video-like-btn.liked i {
            color: #ef4444;
        }

        .video-comments-section {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 20;
            padding: 0.75rem 0.9rem 0.9rem;
            border-radius: 16px 16px 0 0;
            background: radial-gradient(circle at top left, rgba(56,189,248,0.22), transparent 55%),
                        radial-gradient(circle at bottom right, rgba(244,114,182,0.22), transparent 55%),
                        rgba(15,23,42,0.95);
            border: 1px solid rgba(148,163,184,0.35);
            border-bottom: none;
            box-shadow:
                0 -8px 30px rgba(15,23,42,0.85),
                inset 0 0 0 1px rgba(15,23,42,0.9);
            color: #e5e7eb;
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.22s ease, max-height 0.3s ease;
        }

        .video-comments-section::before {
            content: '';
            position: absolute;
            top: 0.45rem;
            left: 50%;
            width: 44px;
            height: 4px;
            transform: translateX(-50%);
            border-radius: 999px;
            background: rgba(148,163,184,0.6);
            box-shadow: 0 2px 10px rgba(0,0,0,0.35);
            opacity: 0.9;
            pointer-events: none;
        }

        .video-comments-section.is-open {
            opacity: 1;
            max-height: 65%;
            pointer-events: auto;
        }

        .video-comments-section.is-dragging {
            transition: none !important;
        }

        .video-comments-inner {
            height: 100%;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            overflow-y: auto;
        }

        .video-comments-scroll {
            flex: 1 1 auto;
            overflow-y: auto;
            padding-right: 0.15rem;
            margin-top: 0.35rem;
            scrollbar-width: thin;
        }

        .video-comments-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .video-comments-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .video-comments-scroll::-webkit-scrollbar-thumb {
            background: rgba(148,163,184,0.7);
            border-radius: 999px;
        }

        .video-comments-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .video-comments-header-left {
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }

        .video-comments-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 0 0 2px rgba(15,23,42,1), 0 0 0 4px rgba(148,163,184,0.55);
        }

        .video-comments-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .video-comments-user-fallback {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #e5e7eb;
            background: linear-gradient(135deg, #64748b, #0f172a);
        }

        .video-comments-user-meta {
            display: flex;
            flex-direction: column;
            gap: 0.1rem;
        }

        .video-comments-user-name {
            font-size: 0.85rem;
            font-weight: 600;
        }

        .video-comments-user-note {
            font-size: 0.74rem;
            color: #9ca3af;
        }

        .video-comments-login-prompt {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .video-comments-login-text {
            font-size: 0.8rem;
            color: #cbd5f5;
        }

        .video-comments-login-btn {
            flex-shrink: 0;
            border: none;
            border-radius: 999px;
            padding: 0.35rem 0.9rem;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            cursor: pointer;
            background: radial-gradient(circle at top left, #22c55e, #16a34a);
            color: #022c22;
            box-shadow: 0 10px 30px rgba(22,163,74,0.7);
        }

        .video-comments-login-btn i {
            font-size: 1rem;
        }

        .video-comments-form {
            margin-top: 0.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .video-comments-textarea {
            width: 100%;
            min-height: 2.4rem;
            max-height: 6rem;
            resize: none;
            border-radius: 12px;
            border: 1px solid rgba(148,163,184,0.55);
            background: rgba(15,23,42,0.9);
            color: #e5e7eb;
            font-size: 0.82rem;
            padding: 0.45rem 0.55rem;
            outline: none;
        }

        .video-comments-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .video-comments-send-btn {
            border-radius: 999px;
            border: none;
            padding: 0.3rem 0.9rem;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: pointer;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            color: #0b1020;
            box-shadow: 0 10px 30px rgba(37,99,235,0.65);
        }

        .video-comments-send-btn:disabled {
            opacity: 0.55;
            cursor: default;
            box-shadow: none;
        }

        .video-comments-send-btn i {
            font-size: 0.9rem;
        }

        .video-comments-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .video-comment-item {
            display: flex;
            align-items: flex-start;
            gap: 0.45rem;
        }

        .video-comment-avatar {
            width: 26px;
            height: 26px;
            border-radius: 999px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .video-comment-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .video-comment-body {
            flex: 1 1 auto;
        }

        .video-comment-header {
            display: flex;
            align-items: baseline;
            gap: 0.3rem;
            margin-bottom: 0.15rem;
        }

        .video-comment-author {
            font-size: 0.8rem;
            font-weight: 600;
        }

        .video-comment-meta {
            font-size: 0.7rem;
            color: #9ca3af;
        }

        .video-comment-text {
            font-size: 0.8rem;
            line-height: 1.45;
            color: #e5e7eb;
        }

        .video-comment-actions {
            margin-top: 0.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .video-comment-reply-btn {
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            font-size: 0.75rem;
            color: #93c5fd;
            cursor: pointer;
        }

        .video-comment-children {
            margin-top: 0.3rem;
            margin-inline-start: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .video-comments-empty {
            font-size: 0.78rem;
            color: #9ca3af;
        }

        .video-comments-loading {
            font-size: 0.8rem;
            color: #cbd5f5;
        }

        /* Transition for side actions */
        .video-side-actions {
            position: absolute;
            top: 68%;
            right: 0.9rem;
            transform: translateY(-50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.85rem;
            z-index: 3;
            pointer-events: none;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            opacity: 1;
            visibility: visible;
        }
        
        .video-side-actions.hidden-by-description {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .video-side-actions-item {
            pointer-events: auto;
        }

        .video-copy-btn {
            pointer-events: auto;
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .video-add-to-cart-btn {
            pointer-events: auto;
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .video-copy-btn i {
            font-size: 2.2rem;
            line-height: 1;
            color: #f9fafb;
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.9);
            transition: transform 0.12s ease, color 0.15s ease;
        }

        .video-add-to-cart-btn i {
            font-size: 2.2rem;
            line-height: 1;
            color: #f9fafb;
            text-shadow:
            0 4px 10px rgba(0, 0, 0, 0.9);
            transition: transform 0.12s ease, color 0.15s ease;
        }

        .video-comment-btn {
            pointer-events: auto;
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            cursor: pointer;
        }

        .video-comment-btn i {
            font-size: 2.1rem;
            line-height: 1;
            color: #e5e7eb;
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.75);
            transition: transform 0.12s ease, color 0.15s ease;
        }

        .video-comment-btn:hover i {
            transform: scale(1.05);
            color: #f97316;
        }

        .video-comment-btn:active i {
            transform: scale(0.96);
        }

        .video-comment-btn.is-active i {
            color: #f97316;
        }

        .video-copy-btn:hover i {
            transform: scale(1.05);
        }

        .video-copy-btn:active i {
            transform: scale(0.95);
        }
        
        .video-like-btn {
            pointer-events: auto;
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            cursor: pointer;
        }

        .video-like-btn i {
            font-size: 2.2rem;
            line-height: 1;
            color: #f9fafb;
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.41);
            transition: transform 0.12s ease, color 0.15s ease;
        }

        .video-like-btn .video-like-count {
            font-size: 0.82rem;
            font-variant-numeric: tabular-nums;
            opacity: 0.95;
            display: block;
            line-height: 1.1;
            text-align: center;
            color: #f9fafb;
            text-shadow:
                0 3px 8px rgba(0, 0, 0, 0.9);
        }

        .video-like-btn:hover i {
            transform: scale(1.05);
        }

        .video-like-btn:active i {
            transform: scale(0.96);
        }

        .video-like-btn.liked i {
            color: #ef4444;
        }

        .video-views {
            pointer-events: auto;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: #f9fafb;
            font-size: 0.78rem;
        }
        
        .video-views i {
            font-size: 1.4rem;
            line-height: 1;
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.7);
            transition: transform 0.12s ease, color 0.15s ease;
        }
        
        .video-views:hover i {
            transform: scale(1.05);
        }

        .video-views-count {
            font-variant-numeric: tabular-nums;
            min-width: 1.5rem;
            text-align: center;
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.9);
        }

        .video-header .cart-button {
            pointer-events: auto;
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .video-header .cart-button i {
            color: #ffffff;
            font-size: 1.8rem;
            line-height: 1;
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.71);
            transition: transform 0.12s ease, color 0.15s ease;
        }

        .video-header .cart-button:hover i {
            transform: scale(1.05);
        }

        .video-header .cart-badge {
            background-color: #ffffff;
            color: #000000;
        }

        /* نافیگیشنەکانی خوارەوە وەک بەرنامەی مۆبایل */
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
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.85);
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

        /* باگراوەندی دوگمەی سەبەتە کاتێک کلیک دەکرێت ـ ڕەنگ لەگەڵ دوگمەکە */
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

        /* دوگمەی فۆڵسکرین */
        .video-fullscreen-btn {
            pointer-events: auto;
            border: none;
            background: transparent;
            padding: 0;
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .video-fullscreen-btn i {
            color: #ffffff;
            font-size: 1.6rem;
            line-height: 1;
            text-shadow:
                0 4px 10px rgba(0, 0, 0, 0.71);
            transition: transform 0.12s ease, color 0.15s ease;
        }

        .video-fullscreen-btn:hover i {
            transform: scale(1.05);
        }

        /* دۆخی تایبەت بۆ کاتێک ڤیدیۆکە فۆڵسکرینە */
        .video-frame-fullscreen .video-header .video-search-btn,
        .video-frame-fullscreen .video-header .cart-button,
        .video-frame-fullscreen .video-header .video-views {
            display: none;
        }

        .video-frame-fullscreen .video-side-actions,
        .video-frame-fullscreen .video-description-box,
        .video-frame-fullscreen .video-meta,
        .video-frame-fullscreen .video-comments-section {
            display: none;
        }

        /* تەنیا دوگمەی فۆڵسکرین لەسەرەوە دەرکەوێت */
        .video-frame-fullscreen .video-fullscreen-btn {
            display: inline-flex;
        }

        /* بەشی خوارەوە (کات و پڕۆگرەس) تەنیا لە فۆڵسکرین و کاتێک کلیک بکرێت دەرکەوێت */
        .video-frame-fullscreen .video-time-row,
        .video-frame-fullscreen .video-progress-container {
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .video-frame-fullscreen.video-show-controls .video-time-row,
        .video-frame-fullscreen.video-show-controls .video-progress-container {
            opacity: 1;
            pointer-events: auto;
        }

        /* دۆخی مۆبایل: ڤیدیۆکە نزیک بە فۆرمەتی ٩x١٦ وەک تیکتۆک،
           بۆشایی سەرەوە زۆر کەمە و زۆرتر لە خوارەوە دەبینرێت. */
        @media (max-width: 767.98px) {
            .video-feed-wrapper {
                height: 100%; /* overrides any vh/dvh */
                padding: 0;
            }

            /* لای ڕاست و چەپ هیچ بۆشایی مەبێت */
            .video-card {
                padding-left: 0;
                padding-right: 0;
            }

            .video-frame {
                width: 100%;
                max-width: none;
                height: calc(100% - 2.25rem); /* ڤیدیۆکە بەرزتر، بۆشایی زیاتر لە خوارەوە */
                margin: 0 0 2.25rem;           /* هیچ بۆشایی لە سەرەوە، زیاتر لە خوارەوە */
                border-radius: 0;
                box-shadow: none;
                transform: translateY(-0.5rem); /* یەک تۆز بەرەوسەرەوە، دەرکەوتن لە playback باشتر */
            }

            .video-player {
                object-fit: contain; /* letterbox: ڤیدیۆ ناوەڕاست، ڕەش لە سەر و خوار، تەنها یەک ڤیدیۆ لە هەر شاشە */
            }

            .video-overlay-bottom {
                padding-inline: 0.85rem;
                padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 0.75rem);
            }

            /* نافیگیشنی خوارەوە تەنیا لە مۆبایل */
            .video-bottom-nav {
                display: flex;
            }

            /* دوگمەی سەبەتە لە سەرەوە لاببە لە مۆبایل، چونکە بۆ خوارەوە گواستراوە */
            .video-header .cart-button {
                display: none;
            }
        }
    </style>
<script>
    !function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister opt_out_capturing has_opted_out_capturing opt_in_capturing reset isFeatureEnabled getFeatureFlag getFeatureFlagPayload reloadFeatureFlags group identify setPersonProperties setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags resetGroups onFeatureFlags addFeatureFlagsHandler onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
    posthog.init('phc_t5YOqtCn3YxG9MnywIhAnW6w4OzWEWd9oxjsJ2Boi5q', {
        api_host: 'https://us.i.posthog.com',
        defaults: '2026-01-30'
    })
</script>

</head>
<body>

<?php if (!empty($feedItems)): ?>
    <!-- Overlay بۆ چالاککردنی دەنگ -->
    <div class="video-start-overlay" id="videoStartOverlay">
        <div class="video-start-box">
            <i class="bi bi-play-circle-fill"></i>
            <p>کلیک بکە بۆ دەستپێکردن</p>
        </div>
    </div>

    <div class="video-feed-wrapper">
        <div class="video-feed-inner">
            <?php foreach ($feedItems as $index => $item): ?>
                <?php
                $videoUrl = htmlspecialchars($item['video_url'] ?? '', ENT_QUOTES, 'UTF-8');
                $description = htmlspecialchars($item['video_description'] ?? '', ENT_QUOTES, 'UTF-8');
                $type = $item['video_type'] === 'product' ? 'product' : 'free';
                $typeLabel = '';
                $typeIcon = $type === 'product' ? 'bi-bag-check-fill' : 'bi-play-btn-fill';
                $userId = !empty($item['user_id']) ? (int)$item['user_id'] : null;
                $logoUrl = $userId && isset($logosByUserId[$userId]) ? htmlspecialchars($logosByUserId[$userId], ENT_QUOTES, 'UTF-8') : null;
                $shopUrl = $userId && isset($shopUrlsByUserId[$userId]) ? $shopUrlsByUserId[$userId] : null;

                // ناوی فرۆشگا بەپێی user_id، fallback بۆ ناوی سیستەم
                $storeShort = $siteName;
                if ($userId && isset($storeNamesByUserId[$userId]) && $storeNamesByUserId[$userId] !== '') {
                    $storeShort = $storeNamesByUserId[$userId];
                }
                $storeInitials = mb_strtoupper(mb_substr($storeShort, 0, 2, 'UTF-8'), 'UTF-8');

                // ناسێنەری بێهاوڵە بۆ ڤیدیۆکە (id + type) بۆ deep-linking و کۆپیکردنی لینک
                $videoId = isset($item['id']) ? (int)$item['id'] : null;
                $videoKey = ($type ?? '') . '_' . ($videoId ?? 0);

                // زانیاری کاڵا بۆ ڤیدیۆی product
                $productInfo = null;
                if ($type === 'product' && !empty($item['product_id'])) {
                    $pid = (int)$item['product_id'];
                    if ($pid > 0 && isset($videoProductsMap[$pid])) {
                        $productInfo = $videoProductsMap[$pid];
                    }
                }

                // ژمارەی لایک و دۆخی لایککراوی ئەم ڤیدیۆیە بۆ سەردانیکەری هەنووکە
                $initialLikeCount = 0;
                $initialLiked = false;

                // ژمارەی بینەرە سەرهێڵییەکان بۆ ئەم ڤیدیۆیە
                $initialViewCount = 0;

                if ($videoId && $videoLikesCountStmt instanceof mysqli_stmt) {
                    if ($videoLikesCountStmt->bind_param('si', $type, $videoId) && $videoLikesCountStmt->execute()) {
                        if ($likesResult = $videoLikesCountStmt->get_result()) {
                            if ($likesRow = $likesResult->fetch_assoc()) {
                                $initialLikeCount = (int)($likesRow['total'] ?? 0);
                            }
                            $likesResult->free();
                        }
                    }
                }

                if ($videoId && $videoViewsCountStmt instanceof mysqli_stmt) {
                    if ($videoViewsCountStmt->bind_param('si', $type, $videoId) && $videoViewsCountStmt->execute()) {
                        if ($viewsResult = $videoViewsCountStmt->get_result()) {
                            if ($viewsRow = $viewsResult->fetch_assoc()) {
                                $initialViewCount = (int)($viewsRow['total'] ?? 0);
                            }
                            $viewsResult->free();
                        }
                    }
                }

                if ($videoId) {
                    if (
                        $googleSessionUserId !== null &&
                        $videoLikesUserLikedStmtGoogle instanceof mysqli_stmt
                    ) {
                        if ($videoLikesUserLikedStmtGoogle->bind_param('sii', $type, $videoId, $googleSessionUserId)
                            && $videoLikesUserLikedStmtGoogle->execute()
                        ) {
                            $videoLikesUserLikedStmtGoogle->store_result();
                            if ($videoLikesUserLikedStmtGoogle->num_rows > 0) {
                                $initialLiked = true;
                            }
                            $videoLikesUserLikedStmtGoogle->free_result();
                        }
                    } elseif (
                        $videoLikesUserLikedStmtGuest instanceof mysqli_stmt &&
                        $videoLikesVisitorIp !== null &&
                        $videoLikesUserAgent !== null
                    ) {
                        if ($videoLikesUserLikedStmtGuest->bind_param('siss', $type, $videoId, $videoLikesVisitorIp, $videoLikesUserAgent)
                            && $videoLikesUserLikedStmtGuest->execute()
                        ) {
                            $videoLikesUserLikedStmtGuest->store_result();
                            if ($videoLikesUserLikedStmtGuest->num_rows > 0) {
                                $initialLiked = true;
                            }
                            $videoLikesUserLikedStmtGuest->free_result();
                        }
                    }
                }

                $likeBtnClasses = 'video-like-btn';
                $likeIconClasses = 'bi bi-heart';
                if ($initialLiked) {
                    $likeBtnClasses .= ' liked';
                    $likeIconClasses = 'bi bi-heart-fill';
                }
                ?>

                <article
                    class="video-card<?php echo !empty($item['_watched']) ? ' video-card-watched' : ''; ?>"
                    data-watched="<?php echo !empty($item['_watched']) ? '1' : '0'; ?>"
                    <?php if ($videoId): ?>
                        data-video-id="<?php echo $videoId; ?>"
                        data-video-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php endif; ?>
                    <?php if (!empty($shopUrl)): ?>
                        data-shop-url="<?php echo htmlspecialchars($shopUrl, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php endif; ?>
                >
                    <div class="video-frame">
                        <video
                            class="video-player"
                            src="<?php echo $videoUrl; ?>"
                            <?php if ((int)$index === 0): ?>autoplay<?php endif; ?>
                            playsinline
                            loop
                            preload="metadata"
                            data-index="<?php echo (int)$index; ?>"
                            controlslist="nodownload noplaybackrate noremoteplayback"
                            oncontextmenu="return false;"
                        ></video>

                        <div class="video-center-icon">
                            <div class="video-center-icon-inner">
                                <i class="bi bi-play-fill"></i>
                            </div>
                        </div>

                        <div class="video-overlay-top">
                            <div class="video-header">

                                <button class="video-fullscreen-btn" type="button" aria-label="پڕکردنەوەی شاشە (Full screen)">
                                    <i class="bi bi-arrows-fullscreen"></i>
                                </button>

                                <a href="<?php echo function_exists('url') ? url('videos/search/index.php') : 'search/index.php'; ?>" class="video-search-btn" aria-label="گەڕان">
                                    <i class="bi bi-search"></i>
                                </a>
                                
                                <button class="cart-button" title="سەبەتەی کڕین">
                                    <i class="bi bi-cart3"></i>
                                    <span class="cart-badge" style="display: none;">0</span>
                                </button>
                                <?php if ($videoId): ?>
                                    <div class="video-views">
                                        <i class="bi bi-eye-fill"></i>
                                        <span class="video-views-count"><?php echo (int)$initialViewCount; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="video-overlay-bottom">

                            <div class="video-description-box">
                                <?php if ($description): ?>
                                    <div class="video-description-wrapper position-relative">
                                        <p class="video-description"><?php echo renderVideoDescriptionWithHashtags($description); ?></p>
                                        <button class="video-read-more-btn d-none" type="button">زیاتر...</button>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="video-time-row">
                                <span class="video-time-current">00:00</span>
                                <span class="video-time-total">--:--</span>
                            </div>
                            <div class="video-progress-container">
                                <div class="video-progress-bar"></div>
                                <div class="video-progress-handle" role="slider" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                            </div>

                        </div>

                        <div class="video-comments-section" data-comments-loaded="0">
                            <div class="video-comments-inner">
                                <!-- JS will render login prompt / form / list here -->
                            </div>
                        </div>

                        <?php if ($videoId): ?>
                            <div class="video-side-actions">
                                <div class="video-side-actions-item">
                                    <div class="video-logo-group">
                                        <?php if ($logoUrl): ?>
                                            <img src="<?php echo $logoUrl; ?>" alt="Logo" class="video-logo">
                                        <?php else: ?>
                                            <div class="video-logo-placeholder">
                                                <span><?php echo htmlspecialchars($storeInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="video-side-actions-item">
                                    <button
                                        type="button"
                                        class="<?php echo htmlspecialchars($likeBtnClasses, ENT_QUOTES, 'UTF-8'); ?>"
                                        aria-label="ਲایککردنی ڤیدیۆ"
                                        data-video-id="<?php echo $videoId; ?>"
                                        data-video-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <i class="<?php echo htmlspecialchars($likeIconClasses, ENT_QUOTES, 'UTF-8'); ?>"></i>
                                        <span class="video-like-count"><?php echo (int)$initialLikeCount; ?></span>
                                    </button>
                                </div>
                                <div class="video-side-actions-item">
                                    <button
                                        type="button"
                                        class="video-comment-btn"
                                        aria-label="کۆمێنتکردن لەسەر ڤیدیۆ"
                                        data-video-id="<?php echo $videoId; ?>"
                                        data-video-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <i class="bi bi-chat-dots"></i>
                                    </button>
                                </div>
                                <?php if ($productInfo !== null): ?>
                                    <div class="video-side-actions-item">
                                        <button
                                            type="button"
                                            class="video-add-to-cart-btn add-to-cart-btn"
                                            aria-label="زیادکردنی کاڵا بۆ سەبەتە"
                                            data-product-id="<?php echo (int)$productInfo['id']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($productInfo['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-product-price="<?php echo htmlspecialchars((string)$productInfo['final_price'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-product-image="<?php echo htmlspecialchars($productInfo['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-product-unit-id="<?php echo htmlspecialchars((string)$productInfo['unit_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-product-unit="<?php echo htmlspecialchars($productInfo['unit_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-product-currency="<?php echo htmlspecialchars($productInfo['currency'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-shop-slug="<?php echo htmlspecialchars($productInfo['website_slug'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-stock="<?php echo (int)$productInfo['stock_quantity']; ?>"
                                        >
                                            <i class="bi bi-cart-plus"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <div class="video-side-actions-item">
                                    <button
                                        type="button"
                                        class="video-copy-btn"
                                        aria-label="کۆپیکردنی لینکی ڤیدیۆ"
                                        data-video-id="<?php echo $videoId; ?>"
                                        data-video-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <i class="bi bi-link-45deg"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div class="video-empty-state">
        <div class="video-empty-card">
            <div class="mb-3">
                <i class="bi bi-collection-play-fill fs-3 text-info"></i>
            </div>
            <h1>هیچ ڤیدیۆیەک ئێستا بەردەست نییە</h1>
            <p>
                تا ئێستا هیچ ڤیدیۆیەك بۆ ئەم بەشە زیاد نەکراوە. دەتوانیت لە بەشی بەڕێوەبردنی کاڵاکان،
                ڤیدیۆ زیاد بکەیت و دووبارە سەردانی ئەم لاپەڕە بکەیت.
            </p>
        </div>
    </div>
<?php endif; ?>

    <!-- نافیگیشنی خوارەوە وەک بەرنامەی مۆبایل -->
    <nav class="video-bottom-nav d-md-none">
        <a href="<?php echo SITE_URL; ?>videos/index.php"
           class="video-bottom-nav-btn is-active"
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
           class="video-bottom-nav-btn"
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
                <a href="#" class="btn-checkout">
                    <i class="bi bi-credit-card"></i>
                    تەواوکردنی داواکاری
                </a>
                <div class="text-center mt-2">
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i>
                        دەتوانیت بەبێ چوونەژوورەوە کڕین بکەیت
                    </small>
                </div>
                <a href="#" class="btn-continue-shopping" onclick="window.shoppingCart && window.shoppingCart.closeCart(); return false;">
                    <i class="bi bi-arrow-left"></i>
                    بەردەوامبوون لە کڕین
                </a>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo SITE_URL; ?>web/template/assets/js/cart.js"></script>
<script>
    const targetVideoType = <?php echo $targetVideoType ? json_encode($targetVideoType, JSON_UNESCAPED_UNICODE) : 'null'; ?>;
    const targetVideoId = <?php echo $targetVideoId !== null ? (int)$targetVideoId : 'null'; ?>;

    (function () {
        const cards = Array.prototype.slice.call(document.querySelectorAll('.video-card'));
        const videos = document.querySelectorAll('.video-player');

        // ====== Comments (Google-based) ======
        const COMMENT_API_URL = 'comments-api.php';
        const hasGoogleUser = <?php echo !empty($_SESSION['google_user']) ? 'true' : 'false'; ?>;
        const googleUserName = <?php echo json_encode($_SESSION['google_user']['name'] ?? null, JSON_UNESCAPED_UNICODE); ?>;
        const googleUserPicture = <?php echo json_encode($_SESSION['google_user']['picture'] ?? null, JSON_UNESCAPED_UNICODE); ?>;

        // created_at لە PHP/Vivid DB زیاتر بە شێوەی 'YYYY-MM-DD HH:mm:ss' دەبێت،
        // لە JavaScript بۆ پارسکردن باشترە بە ISO بگۆڕین.
        function parseMySqlDate(value) {
            if (!value || typeof value !== 'string') return null;
            const s = value.trim();
            if (!s) return null;

            const iso = s.includes('T') ? s : s.replace(' ', 'T');
            const d = new Date(iso);
            return Number.isNaN(d.getTime()) ? null : d;
        }

        // x د = x دەقە، x ڕ = x ڕۆژ، x ح = x حەفتە، x م = x مانگ
        function formatKurdishElapsed(createdAt) {
            const createdDate = parseMySqlDate(createdAt);
            if (!createdDate) return createdAt || '';

            const diffMs = Date.now() - createdDate.getTime();
            if (diffMs < 0) return 'ئێستا';

            const totalMinutes = Math.floor(diffMs / 60000);
            if (totalMinutes < 1) return 'ئێستا';

            // < 60 دێقە -> دەقە
            if (totalMinutes < 60) {
                return `${totalMinutes} د`;
            }

            // < 24 سەعات -> سەعات
            const totalHours = Math.floor(diffMs / 3600000);
            if (totalHours < 24) {
                return `${totalHours} ک`;
            }

            const days = Math.floor(diffMs / 86400000);
            if (days < 7) return `${days} ڕ`;

            // < 30 ڕۆژ -> حەفتە
            if (days < 30) {
                const weeks = Math.floor(days / 7);
                return `${weeks} ح`;
            }

            // >= 30 ڕۆژ -> مانگ (لەبەر ڕێژەی دیاریکراو بە 30 ڕۆژ)
            const months = Math.floor(days / 30);
            return `${months} م`;
        }

        function createCommentElement(comment) {
            const li = document.createElement('li');
            li.className = 'video-comment-item';
            li.dataset.commentId = String(comment.id);
            li.dataset.parentId = comment.parent_id != null ? String(comment.parent_id) : '';

            const avatar = document.createElement('div');
            avatar.className = 'video-comment-avatar';
            if (comment.user_picture) {
                const img = document.createElement('img');
                img.src = comment.user_picture;
                img.alt = comment.user_name || '';
                avatar.appendChild(img);
            } else {
                const span = document.createElement('span');
                span.className = 'video-comments-user-fallback';
                span.textContent = (comment.user_name || '?').slice(0, 2).toUpperCase();
                avatar.appendChild(span);
            }

            const body = document.createElement('div');
            body.className = 'video-comment-body';

            const header = document.createElement('div');
            header.className = 'video-comment-header';

            const author = document.createElement('span');
            author.className = 'video-comment-author';
            author.textContent = comment.user_name || 'بەکارهێنەر';

            const meta = document.createElement('span');
            meta.className = 'video-comment-meta';
            meta.textContent = formatKurdishElapsed(comment.created_at);

            header.appendChild(author);
            header.appendChild(meta);

            const textEl = document.createElement('div');
            textEl.className = 'video-comment-text';
            textEl.textContent = comment.text || '';

            const actions = document.createElement('div');
            actions.className = 'video-comment-actions';
            const replyBtn = document.createElement('button');
            replyBtn.type = 'button';
            replyBtn.className = 'video-comment-reply-btn';
            replyBtn.textContent = 'وەڵامدانەوە';
            replyBtn.addEventListener('click', function () {
                openReplyFormForComment(li);
            });
            actions.appendChild(replyBtn);

            const childrenContainer = document.createElement('div');
            childrenContainer.className = 'video-comment-children';

            body.appendChild(header);
            body.appendChild(textEl);
            body.appendChild(actions);
            body.appendChild(childrenContainer);

            li.appendChild(avatar);
            li.appendChild(body);

            return li;
        }

        function buildCommentsTree(comments) {
            const byId = {};
            const roots = [];

            comments.forEach(function (c) {
                const clone = Object.assign({}, c, { children: [] });
                byId[clone.id] = clone;
            });

            Object.keys(byId).forEach(function (idStr) {
                const c = byId[idStr];
                if (c.parent_id && byId[c.parent_id]) {
                    byId[c.parent_id].children.push(c);
                } else {
                    roots.push(c);
                }
            });

            return roots;
        }

        function renderCommentsInto(container, comments) {
            container.innerHTML = '';

            const header = document.createElement('div');
            header.className = 'video-comments-header';

            const headerLeft = document.createElement('div');
            headerLeft.className = 'video-comments-header-left';

            if (hasGoogleUser && (googleUserName || googleUserPicture)) {
                const avatar = document.createElement('div');
                avatar.className = 'video-comments-user-avatar';
                if (googleUserPicture) {
                    const img = document.createElement('img');
                    img.src = googleUserPicture;
                    img.alt = googleUserName || '';
                    avatar.appendChild(img);
                } else {
                    const fallback = document.createElement('div');
                    fallback.className = 'video-comments-user-fallback';
                    fallback.textContent = (googleUserName || '?').slice(0, 2).toUpperCase();
                    avatar.appendChild(fallback);
                }
                const meta = document.createElement('div');
                meta.className = 'video-comments-user-meta';
                const nameEl = document.createElement('div');
                nameEl.className = 'video-comments-user-name';
                nameEl.textContent = googleUserName || '';
                const noteEl = document.createElement('div');
                noteEl.className = 'video-comments-user-note';
                noteEl.textContent = 'کۆمێنتەکانت بە ناوی ئەم هەژمارەوە نیشان دەدرێن';
                meta.appendChild(nameEl);
                meta.appendChild(noteEl);
                headerLeft.appendChild(avatar);
                headerLeft.appendChild(meta);
            } else {
                const loginPrompt = document.createElement('div');
                loginPrompt.className = 'video-comments-login-prompt';
                const textEl = document.createElement('div');
                textEl.className = 'video-comments-login-text';
                textEl.textContent = 'بۆ نووسینی کۆمێنت پێویستە بە ئەکاونتی Google بچیتە ژوورەوە.';
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'video-comments-login-btn';
                btn.innerHTML = '<i class="bi bi-google"></i><span>چوونەژوورەوە بە گووگڵ</span>';
                btn.addEventListener('click', function () {
                    window.location.href = '<?php echo htmlspecialchars(SITE_URL . "videos/google-login.php", ENT_QUOTES, "UTF-8"); ?>';
                });
                loginPrompt.appendChild(textEl);
                loginPrompt.appendChild(btn);
                headerLeft.appendChild(loginPrompt);
            }

            header.appendChild(headerLeft);
            container.appendChild(header);

            const scroll = document.createElement('div');
            scroll.className = 'video-comments-scroll';

            const list = document.createElement('ul');
            list.className = 'video-comments-list';

            const treeRoots = buildCommentsTree(comments || []);
            if (treeRoots.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'video-comments-empty';
                empty.textContent = 'هێشتا هیچ کۆمێنتێک نییە. یەکەم کەس بە!';
                scroll.appendChild(empty);
            } else {
                function appendComments(nodes, parentEl) {
                    nodes.forEach(function (node) {
                        const li = createCommentElement(node);
                        parentEl.appendChild(li);
                        if (node.children && node.children.length) {
                            const childrenContainer = li.querySelector('.video-comment-children');
                            appendComments(node.children, childrenContainer);
                        }
                    });
                }
                appendComments(treeRoots, list);
                scroll.appendChild(list);
            }

            container.appendChild(scroll);

            if (hasGoogleUser && (googleUserName || googleUserPicture)) {
                const form = document.createElement('form');
                form.className = 'video-comments-form';
                form.setAttribute('data-parent-id', '');

                const textarea = document.createElement('textarea');
                textarea.className = 'video-comments-textarea';
                textarea.rows = 1;
                textarea.placeholder = 'کۆمێنتێکی کورت بنووسە...';

                textarea.addEventListener('input', function () {
                    textarea.style.height = 'auto';
                    textarea.style.height = Math.min(96, textarea.scrollHeight) + 'px';
                });

                const actions = document.createElement('div');
                actions.className = 'video-comments-actions';

                const sendBtn = document.createElement('button');
                sendBtn.type = 'submit';
                sendBtn.className = 'video-comments-send-btn';
                sendBtn.disabled = true;
                sendBtn.innerHTML = '<span>ناردن</span><i class="bi bi-send"></i>';

                textarea.addEventListener('input', function () {
                    sendBtn.disabled = textarea.value.trim().length === 0;
                });

                actions.appendChild(sendBtn);
                form.appendChild(textarea);
                form.appendChild(actions);

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const card = form.closest('.video-card');
                    if (!card) return;
                    const videoIdAttr = card.getAttribute('data-video-id') || '0';
                    const videoId = parseInt(videoIdAttr, 10);
                    const videoType = card.getAttribute('data-video-type') || '';
                    const parentIdStr = form.getAttribute('data-parent-id') || '';
                    const parentId = parentIdStr ? parseInt(parentIdStr, 10) : null;
                    const text = textarea.value.trim();
                    if (!videoId || !videoType || !text) return;
                    sendCommentRequest({
                        card: card,
                        videoId: videoId,
                        videoType: videoType,
                        parentId: parentId,
                        text: text,
                        onDone: function () {
                            textarea.value = '';
                            textarea.style.height = 'auto';
                            form.setAttribute('data-parent-id', '');
                            sendBtn.disabled = true;
                        }
                    });
                });

                container.appendChild(form);
            }
        }

        function openReplyFormForComment(commentItem) {
            const card = commentItem.closest('.video-card');
            if (!card) return;
            const commentsSection = card.querySelector('.video-comments-section');
            const form = commentsSection ? commentsSection.querySelector('.video-comments-form') : null;
            const textarea = form ? form.querySelector('.video-comments-textarea') : null;
            if (!form || !textarea) return;
            const commentId = commentItem.dataset.commentId || '';
            form.setAttribute('data-parent-id', commentId);
            textarea.focus();
        }

        function loadCommentsForCard(card) {
            const videoIdAttr = card.getAttribute('data-video-id') || '0';
            const videoId = parseInt(videoIdAttr, 10);
            const videoType = card.getAttribute('data-video-type') || '';
            const section = card.querySelector('.video-comments-section');
            const inner = section ? section.querySelector('.video-comments-inner') : null;
            if (!section || !inner || !videoId || !videoType) return;

            inner.innerHTML = '<div class="video-comments-loading">کۆمێنتەکان بار دەبن...</div>';

            const formData = new FormData();
            formData.append('action', 'load');
            formData.append('video_type', videoType);
            formData.append('video_id', String(videoId));

            fetch(COMMENT_API_URL, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }).then(function (res) {
                if (!res.ok) {
                    throw new Error('Failed to load comments');
                }
                return res.json();
            }).then(function (data) {
                if (!data || !data.ok) {
                    throw new Error('Invalid response');
                }
                renderCommentsInto(inner, data.comments || []);
                section.setAttribute('data-comments-loaded', '1');
            }).catch(function () {
                inner.innerHTML = '<div class="video-comments-loading">هەڵەیەک ڕوویدا لە کاتی بارکردنی کۆمێنتەکان.</div>';
            });
        }

        function prependCommentToCard(card, comment) {
            const section = card.querySelector('.video-comments-section');
            const inner = section ? section.querySelector('.video-comments-inner') : null;
            if (!section || !inner) return;
            const list = inner.querySelector('.video-comments-list');
            const scroll = inner.querySelector('.video-comments-scroll');
            const emptyState = inner.querySelector('.video-comments-empty');
            if (emptyState && emptyState.parentElement) {
                emptyState.parentElement.removeChild(emptyState);
            }
            const li = createCommentElement(comment);
            if (comment.parent_id) {
                const parentLi = inner.querySelector('.video-comment-item[data-comment-id="' + comment.parent_id + '"]');
                if (parentLi) {
                    const children = parentLi.querySelector('.video-comment-children');
                    if (children) {
                        children.appendChild(li);
                    }
                } else if (list) {
                    list.insertBefore(li, list.firstChild);
                }
            } else if (list) {
                list.insertBefore(li, list.firstChild);
            }
            if (scroll) {
                scroll.scrollTop = 0;
            }
        }

        function sendCommentRequest(options) {
            const card = options.card;
            const videoId = options.videoId;
            const videoType = options.videoType;
            const parentId = options.parentId;
            const text = options.text;
            const onDone = typeof options.onDone === 'function' ? options.onDone : function () {};

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('video_type', videoType);
            formData.append('video_id', String(videoId));
            if (parentId) {
                formData.append('parent_id', String(parentId));
            }
            formData.append('comment_text', text);

            fetch(COMMENT_API_URL + '?action=create', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }).then(function (res) {
                if (res.status === 401) {
                    alert('بۆ نووسینی کۆمێنت پێویستە بە گووگڵ بچیتە ژوورەوە.');
                    window.location.href = '<?php echo htmlspecialchars(SITE_URL . "videos/google-login.php", ENT_QUOTES, "UTF-8"); ?>';
                    return null;
                }
                if (!res.ok) {
                    throw new Error('Failed to send comment');
                }
                return res.json();
            }).then(function (data) {
                if (!data) return;
                if (!data.ok || !data.comment) {
                    throw new Error('Invalid response');
                }
                prependCommentToCard(card, data.comment);
            }).catch(function () {
                // هەڵەکان بە هێمن پشتگوێ بخە
            }).finally(function () {
                onDone();
            });
        }

        function handleCommentButtonClick(event) {
            const btn = event.target.closest('.video-comment-btn');
            if (!btn) return;
            event.preventDefault();

            const card = btn.closest('.video-card');
            if (!card) return;

            const section = card.querySelector('.video-comments-section');
            const inner = section ? section.querySelector('.video-comments-inner') : null;
            if (!section || !inner) return;

            const alreadyOpen = section.classList.contains('is-open');

            var allBtns = document.querySelectorAll('.video-comment-btn.is-active');
            var allSections = document.querySelectorAll('.video-comments-section.is-open');
            allSections.forEach(function (sec) {
                if (sec !== section) {
                    sec.classList.remove('is-open');
                    const otherCard = sec.closest('.video-card');
                    if (otherCard) otherCard.classList.remove('comments-open');
                }
            });
            allBtns.forEach(function (b) {
                if (b !== btn) {
                    b.classList.remove('is-active');
                }
            });

            if (alreadyOpen) {
                const player = card.querySelector('.video-player');
                const targetMaxHeightPx = getTargetMaxHeightPx(section);

                // Close sheet, but keep `comments-open` until sync finishes
                // so the video can smoothly return to full size.
                section.classList.remove('is-open');
                btn.classList.remove('is-active');
                card.classList.remove('comments-dragging');
                section.classList.remove('is-dragging');
                section.style.maxHeight = '';
                section.style.opacity = '';

                syncVideoToSheetHeight(card, section, player, targetMaxHeightPx, function () {
                    card.classList.remove('comments-open');
                    if (player) player.style.transform = '';
                });
                return;
            }

            section.classList.add('is-open');
            btn.classList.add('is-active');
            card.classList.add('comments-open');

            // Clear any inline drag styles (in case the user previously dragged).
            card.classList.remove('comments-dragging');
            section.classList.remove('is-dragging');
            section.style.maxHeight = '';
            section.style.opacity = '';
            const player = card.querySelector('.video-player');
            if (player) player.style.transform = '';

            // Sync video transform with the sheet expansion during click-open.
            // This ensures "bigger comment card => more video push/zoom".
            const targetMaxHeightPx = getTargetMaxHeightPx(section);
            syncVideoToSheetHeight(card, section, player, targetMaxHeightPx, function () {
                if (player) player.style.transform = '';
            });

            const loaded = section.getAttribute('data-comments-loaded') === '1';
            if (!loaded) {
                loadCommentsForCard(card);
            }
        }

        function clamp01(n) {
            if (n <= 0) return 0;
            if (n >= 1) return 1;
            return n;
        }

        function getClientY(ev) {
            if (ev && ev.touches && ev.touches.length) return ev.touches[0].clientY;
            return ev && typeof ev.clientY === 'number' ? ev.clientY : 0;
        }

        let commentsDragState = null;

        function canStartCommentsDrag(ev) {
            const clientY = getClientY(ev);
            const target = ev && ev.target ? ev.target : null;
            if (!target) return false;

            const section = target.closest('.video-comments-section.is-open');
            if (!section) return false;

            const rect = section.getBoundingClientRect();
            const localY = clientY - rect.top;
            const topAllowPx = Math.min(90, rect.height * 0.18);
            if (localY > topAllowPx) return false;

            const scroll = section.querySelector('.video-comments-scroll');
            if (scroll && scroll.contains(target)) {
                // If user is scrolling content, don't hijack it with drag-dismiss.
                if (scroll.scrollTop > 0) return false;
            }

            return true;
        }

        function startCommentsDrag(ev) {
            if (commentsDragState) return;
            if (!canStartCommentsDrag(ev)) return;

            stopCommentsSync();

            const section = ev.target.closest('.video-comments-section.is-open');
            if (!section) return;

            const card = section.closest('.video-card');
            if (!card) return;

            const btn = card.querySelector('.video-comment-btn.is-active') || card.querySelector('.video-comment-btn');
            const player = card.querySelector('.video-player');

            const maxHeightStr = String(getComputedStyle(section).maxHeight || '');
            const computedMax = parseFloat(maxHeightStr);
            // If the browser returns `65%`, use the actual opened height instead of treating 65 as pixels.
            const openMaxHeightPx = maxHeightStr.includes('%')
                ? section.getBoundingClientRect().height
                : (computedMax > 0 ? computedMax : section.getBoundingClientRect().height);

            commentsDragState = {
                card: card,
                section: section,
                btn: btn,
                player: player,
                startY: getClientY(ev),
                openMaxHeightPx: Math.max(40, openMaxHeightPx),
                ratio: 1
            };

            section.classList.add('is-dragging');
            card.classList.add('comments-dragging');
        }

        function updateCommentsDrag(ev) {
            if (!commentsDragState) return;
            const { section, player, openMaxHeightPx, card } = commentsDragState;

            const clientY = getClientY(ev);
            const dy = Math.max(0, clientY - commentsDragState.startY);
            const ratio = clamp01(1 - dy / openMaxHeightPx);
            commentsDragState.ratio = ratio;

            const newMaxHeightPx = openMaxHeightPx * ratio;
            section.style.maxHeight = newMaxHeightPx + 'px';
            section.style.opacity = ratio;
            section.style.pointerEvents = ratio <= 0.02 ? 'none' : 'auto';

            if (player) {
                // Match the open-state transform and keep it synced while dragging.
                const translateY = -42 * ratio;
                const scale = 1 - (1 - 0.84) * ratio; // 0.84 at ratio=1
                player.style.transform =
                    'translateY(' + translateY.toFixed(2) + 'px) scale(' + scale.toFixed(3) + ')';
            }

            // Disable transitions via classes while dragging.
            section.classList.add('is-dragging');
            card.classList.add('comments-dragging');
        }

        function endCommentsDrag() {
            if (!commentsDragState) return;

            const { section, card, btn, player, ratio } = commentsDragState;
            const shouldClose = ratio < 0.45;

            section.style.pointerEvents = '';
            section.classList.remove('is-dragging');
            card.classList.remove('comments-dragging');

            if (shouldClose) {
                // Collapse/close.
                section.classList.remove('is-open');
                card.classList.remove('comments-open');
                btn && btn.classList.remove('is-active');

                section.style.maxHeight = '';
                section.style.opacity = '';
                if (player) player.style.transform = '';
            } else {
                // Snap open.
                section.classList.add('is-open');
                card.classList.add('comments-open');
                btn && btn.classList.add('is-active');

                section.style.maxHeight = '';
                section.style.opacity = '';
                if (player) player.style.transform = '';
            }

            commentsDragState = null;
        }

        // Keep video transform synced with the actual current sheet height
        // during click-open / click-close (in addition to drag).
        let commentsSyncRafId = null;
        function stopCommentsSync() {
            if (commentsSyncRafId) {
                cancelAnimationFrame(commentsSyncRafId);
                commentsSyncRafId = null;
            }
        }

        function getTargetMaxHeightPx(section) {
            const maxHeightStr = String(getComputedStyle(section).maxHeight || '');
            const computedMax = parseFloat(maxHeightStr);
            if (maxHeightStr.includes('%')) {
                const parent = section.parentElement;
                const base = parent ? parent.getBoundingClientRect().height : section.getBoundingClientRect().height;
                return base * computedMax / 100;
            }
            return computedMax > 0 ? computedMax : section.getBoundingClientRect().height;
        }

        function applyVideoTransformForRatio(player, ratio) {
            if (!player) return;
            const safeRatio = clamp01(ratio);
            const translateY = -42 * safeRatio;
            const scale = 1 - (1 - 0.84) * safeRatio; // 0.84 at ratio=1
            player.style.transform =
                'translateY(' + translateY.toFixed(2) + 'px) scale(' + scale.toFixed(3) + ')';
        }

        function syncVideoToSheetHeight(card, section, player, targetMaxHeightPx, onDone) {
            if (!card || !section || !player || !targetMaxHeightPx) return;
            stopCommentsSync();

            const step = function () {
                // If user started dragging, drag logic takes over.
                if (commentsDragState) return;

                const currentHeight = section.getBoundingClientRect().height;
                const ratio = currentHeight / targetMaxHeightPx;
                applyVideoTransformForRatio(player, ratio);

                // Stop once we are sufficiently close to target ends.
                if (ratio >= 0.985 || ratio <= 0.02) {
                    stopCommentsSync();
                    if (typeof onDone === 'function') onDone();
                    return;
                }

                commentsSyncRafId = requestAnimationFrame(step);
            };

            commentsSyncRafId = requestAnimationFrame(step);
        }

        // Bottom-sheet drag-to-dismiss (mobile-first).
        document.addEventListener(
            'touchstart',
            function (ev) {
                startCommentsDrag(ev);
            },
            { passive: false }
        );
        document.addEventListener(
            'touchmove',
            function (ev) {
                if (!commentsDragState) return;
                ev.preventDefault(); // keep the drag gesture stable
                updateCommentsDrag(ev);
            },
            { passive: false }
        );
        document.addEventListener('touchend', function () {
            endCommentsDrag();
        });
        document.addEventListener('touchcancel', function () {
            endCommentsDrag();
        });

        document.addEventListener('mousedown', function (ev) {
            // Only left click.
            if (ev && ev.button !== 0) return;
            startCommentsDrag(ev);
        });
        document.addEventListener('mousemove', function (ev) {
            if (!commentsDragState) return;
            updateCommentsDrag(ev);
        });
        document.addEventListener('mouseup', function () {
            endCommentsDrag();
        });

        // ڕێگری لە مێنیووی براوسەر (Right-click) لەسەر ناوچەی ڤیدیۆ
        document.addEventListener('contextmenu', function (event) {
            if (event.target && event.target.closest('.video-frame')) {
                event.preventDefault();
            }
        }, { capture: true });

        let hasUserStarted = false;

        // --- Watch time tracking (per viewer & video) ---
        const watchStates = new WeakMap();

        function getOrCreateWatchState(video) {
            let state = watchStates.get(video);
            if (!state) {
                state = {
                    accumulatedSeconds: 0, // کۆی کاتی بینین بۆ ئەم سەشنە
                    sentSeconds: 0,        // ئەو چرکەیانەی پێشتر نێردراون بۆ سرڤەر
                    lastStartedAt: null,   // کاتژمێری دەستپێکردنی play
                    lastSentAt: null       // کاتی دوا ناردن
                };
                watchStates.set(video, state);
            }
            return state;
        }

        function getCardIdentity(card) {
            if (!card) return null;
            const videoId = parseInt(card.getAttribute('data-video-id') || '0', 10);
            const videoType = card.getAttribute('data-video-type') || '';
            if (!videoId || !videoType) return null;
            return { videoId, videoType };
        }

        function sendWatchTimeDelta(card, deltaSeconds) {
            const identity = getCardIdentity(card);
            if (!identity) return;

            const safeDelta = Math.max(0, Math.floor(deltaSeconds || 0));
            if (safeDelta <= 0) {
                return;
            }

            const body =
                'video_id=' + encodeURIComponent(String(identity.videoId)) +
                '&video_type=' + encodeURIComponent(identity.videoType) +
                '&watch_seconds=' + encodeURIComponent(String(safeDelta));

            fetch('video_watch_time.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body,
                credentials: 'same-origin'
            }).catch(function () {
                // هەڵەکان بە هێمن پشتگوێ بخە
            });
        }

        function flushWatchStateForVideo(video, options) {
            if (!video) return;
            const state = watchStates.get(video);
            if (!state) return;

            const nowSec = Date.now() / 1000;

            if (state.lastStartedAt !== null) {
                const delta = Math.max(0, nowSec - state.lastStartedAt);
                state.accumulatedSeconds += delta;
                state.lastStartedAt = null;
            }

            const unsent = Math.max(0, Math.floor(state.accumulatedSeconds - state.sentSeconds));
            const force = options && options.force;

            // سنووری کەمترین چرکە بۆ ناردن بۆ ئەوەی request زۆر نەبێت
            const threshold = 3;
            if (!force && unsent < threshold) {
                return;
            }

            const card = video.closest('.video-card');
            if (!card) return;

            sendWatchTimeDelta(card, unsent);
            state.sentSeconds += unsent;
            state.lastSentAt = nowSec;
        }

        function formatTime(seconds) {
            if (!isFinite(seconds) || seconds < 0) return '00:00';
            const total = Math.floor(seconds);
            const mins = Math.floor(total / 60);
            const secs = total % 60;
            return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
        }

        function pauseAllExcept(current) {
            videos.forEach(function (video) {
                if (video !== current) {
                    video.pause();
                }
            });
        }

        function updatePlayStateIcon(video, isPlaying) {
            if (!hasUserStarted) return;
            if (!video) return;
            const frame = video.closest('.video-frame');
            if (!frame) return;

            const wrapper = frame.querySelector('.video-center-icon');
            const icon = wrapper ? wrapper.querySelector('i') : null;
            if (!wrapper || !icon) return;

            if (isPlaying) {
                icon.classList.remove('bi-play-fill');
                icon.classList.add('bi-pause-fill');
                wrapper.classList.add('visible');
                setTimeout(function () {
                    wrapper.classList.remove('visible');
                }, 400);
            } else {
                icon.classList.remove('bi-pause-fill');
                icon.classList.add('bi-play-fill');
                wrapper.classList.add('visible');
            }
        }

        function tryPlay(video, { withSound } = {}) {
            if (!video) return;

            if (withSound) {
                video.muted = false;
            }

            const playPromise = video.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {
                    // بەهێمن هەڵەکانی autoplay پشتگوێ بخە
                });
            }
        }

        function updateLikeUI(card, liked, likeCount) {
            if (!card) return;
            var btn = card.querySelector('.video-like-btn');
            if (!btn) return;

            var icon = btn.querySelector('i');
            var countEl = btn.querySelector('.video-like-count');

            if (liked) {
                btn.classList.add('liked');
                if (icon) {
                    icon.classList.remove('bi-heart');
                    icon.classList.add('bi-heart-fill');
                }
            } else {
                btn.classList.remove('liked');
                if (icon) {
                    icon.classList.remove('bi-heart-fill');
                    icon.classList.add('bi-heart');
                }
            }

            if (countEl) {
                var safeCount = 0;
                if (typeof likeCount === 'number' && isFinite(likeCount) && likeCount >= 0) {
                    safeCount = likeCount;
                }
                countEl.textContent = String(safeCount);
            }
        }

        function updateViewUI(card, viewCount) {
            if (!card) return;
            var countEl = card.querySelector('.video-views-count');
            if (!countEl) return;

            var safeCount = 0;
            if (typeof viewCount === 'number' && isFinite(viewCount) && viewCount >= 0) {
                safeCount = viewCount;
            }
            countEl.textContent = String(safeCount);
        }

        function ensureGoogleLoginCard() {
            var existing = document.getElementById('googleLoginRequiredOverlay');
            if (existing) return existing;

            var overlay = document.createElement('div');
            overlay.id = 'googleLoginRequiredOverlay';
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.background = 'rgba(15,23,42,0.80)';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.zIndex = '9999';

            var card = document.createElement('div');
            card.style.background = '#020617';
            card.style.borderRadius = '16px';
            card.style.border = '1px solid rgba(148,163,184,0.5)';
            card.style.boxShadow = '0 24px 60px rgba(15,23,42,0.95)';
            card.style.padding = '24px 24px 20px';
            card.style.maxWidth = '380px';
            card.style.width = '90%';
            card.style.color = '#e5e7eb';
            card.style.fontFamily = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            card.style.textAlign = 'center';

            var title = document.createElement('h2');
            title.textContent = 'پێویستە بچیتە ژوورەوە';
            title.style.margin = '0 0 8px';
            title.style.fontSize = '18px';

            var text = document.createElement('p');
            text.textContent = 'بۆ ئەوەی بتوانیت ڤیدیۆکان لایک بکەیت، دەبێت سەرەتا لە ڕێگەی Google بچیتە ژوورەوە.';
            text.style.margin = '0 0 18px';
            text.style.fontSize = '14px';
            text.style.color = '#cbd5f5';
            text.style.lineHeight = '1.7';

            var actions = document.createElement('div');
            actions.style.display = 'flex';
            actions.style.gap = '10px';
            actions.style.justifyContent = 'center';

            var loginBtn = document.createElement('button');
            loginBtn.type = 'button';
            loginBtn.style.padding = '8px 18px';
            loginBtn.style.borderRadius = '999px';
            loginBtn.style.border = 'none';
            loginBtn.style.cursor = 'pointer';
            loginBtn.style.background = '#22c55e';
            loginBtn.style.color = '#022c22';
            loginBtn.style.fontWeight = '600';
            loginBtn.style.fontSize = '14px';
            loginBtn.style.display = 'inline-flex';
            loginBtn.style.alignItems = 'center';
            loginBtn.style.gap = '8px';

            var googleIcon = document.createElement('i');
            googleIcon.className = 'bi bi-google';
            googleIcon.style.fontSize = '16px';

            var loginText = document.createElement('span');
            loginText.textContent = 'چوونەژوورەوە بە Google';

            loginBtn.appendChild(googleIcon);
            loginBtn.appendChild(loginText);
            loginBtn.onclick = function () {
                window.location.href = 'google-login.php';
            };

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.textContent = 'داخستن';
            closeBtn.style.padding = '8px 16px';
            closeBtn.style.borderRadius = '999px';
            closeBtn.style.border = '1px solid rgba(148,163,184,0.7)';
            closeBtn.style.background = 'transparent';
            closeBtn.style.color = '#e5e7eb';
            closeBtn.style.cursor = 'pointer';
            closeBtn.style.fontSize = '13px';
            closeBtn.onclick = function () {
                overlay.style.display = 'none';
            };

            actions.appendChild(loginBtn);
            actions.appendChild(closeBtn);

            card.appendChild(title);
            card.appendChild(text);
            card.appendChild(actions);

            overlay.appendChild(card);

            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) {
                    overlay.style.display = 'none';
                }
            });

            document.body.appendChild(overlay);
            return overlay;
        }

        function showGoogleLoginRequired() {
            var overlay = ensureGoogleLoginCard();
            overlay.style.display = 'flex';
        }

        function sendLikeRequest(card) {
            if (!card) return;
            var videoId = parseInt(card.getAttribute('data-video-id') || '0', 10);
            var videoType = card.getAttribute('data-video-type') || '';

            if (!videoId || !videoType) {
                return;
            }

            var body = 'video_id=' + encodeURIComponent(String(videoId)) +
                '&video_type=' + encodeURIComponent(videoType);

            return fetch('video_like.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            }).then(function (data) {
                if (!data) {
                    return;
                }

                if (data.success) {
                    updateLikeUI(card, !!data.liked, typeof data.like_count === 'number' ? data.like_count : 0);
                    return;
                }

                if (data.error === 'login_required') {
                    showGoogleLoginRequired();
                    return;
                }
            }).catch(function () {
                // هەڵەکان بە هێمن پشتگوێ بخە، UI مەگۆڕە
            });
        }

        function sendViewRequest(card) {
            if (!card) return;
            var videoId = parseInt(card.getAttribute('data-video-id') || '0', 10);
            var videoType = card.getAttribute('data-video-type') || '';

            if (!videoId || !videoType) {
                return;
            }

            var body = 'video_id=' + encodeURIComponent(String(videoId)) +
                '&video_type=' + encodeURIComponent(videoType);

            return fetch('video_view.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            }).then(function (data) {
                if (!data || !data.success) {
                    return;
                }
                updateViewUI(card, typeof data.view_count === 'number' ? data.view_count : 0);
                // ڤیدیۆکە وەک بینراو نیشان بکە بۆ ئەم بەکارهێنەرە
                card.dataset.watched = '1';
                card.classList.add('video-card-watched');
            }).catch(function () {
                // هەڵەکان بە هێمن پشتگوێ بخە، UI مەگۆڕە
            });
        }

        // کلیکی یەکجار لەسەر هەر ڤیدیۆیەک بۆ play/pause
        videos.forEach(function (video) {
            // بۆ هەموو play/pause ـەکان (نەک تەنها کلیک)
            video.addEventListener('play', function () {
                updatePlayStateIcon(video, true);
                // دەستپێکردنی هەژمارکردنی watch time
                const state = getOrCreateWatchState(video);
                if (state.lastStartedAt === null) {
                    state.lastStartedAt = Date.now() / 1000;
                }
            });
            video.addEventListener('pause', function () {
                updatePlayStateIcon(video, false);
                // کاتێک ڤیدیۆ وەستێت، کۆی watch time ی ئەم قۆناغە هەژمار بکە و نێری
                flushWatchStateForVideo(video);
            });

            video.addEventListener('click', function () {
                hasUserStarted = true;
                if (video.paused) {
                    pauseAllExcept(video);
                    tryPlay(video);
                } else {
                    video.pause();
                }
            });

            video.addEventListener('dblclick', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var card = video.closest('.video-card');
                if (!card) return;
                sendLikeRequest(card);
            });

            // نوێکردنەوەی کات و پڕۆگرەس
            const card = video.closest('.video-card');
            if (!card) return;
            const currentEl = card.querySelector('.video-time-current');
            const totalEl = card.querySelector('.video-time-total');
            const progressContainer = card.querySelector('.video-progress-container');
            const progressBar = card.querySelector('.video-progress-bar');
            const progressHandle = card.querySelector('.video-progress-handle');

            const frame = video.closest('.video-frame');
            let controlsHideTimeout = null;

            let isDragging = false;
            let wasPlayingBeforeDrag = false;

            function updateProgressUIFromRatio(ratio, currentTimeSeconds) {
                const safeRatio = Math.min(Math.max(ratio || 0, 0), 1);
                if (currentEl && typeof currentTimeSeconds === 'number') {
                    currentEl.textContent = formatTime(currentTimeSeconds);
                }
                if (progressBar) {
                    progressBar.style.transform = 'scaleX(' + safeRatio + ')';
                }
                if (progressHandle) {
                    progressHandle.style.left = (safeRatio * 100) + '%';
                    const percent = Math.round(safeRatio * 100);
                    progressHandle.setAttribute('aria-valuenow', String(percent));
                }
            }

            function seekToClientX(clientX) {
                if (!progressContainer || !isFinite(video.duration) || video.duration <= 0) {
                    return;
                }
                const rect = progressContainer.getBoundingClientRect();
                const x = clientX - rect.left;
                const width = rect.width || 1;
                const ratio = Math.min(Math.max(x / width, 0), 1);
                const targetTime = ratio * video.duration;
                video.currentTime = targetTime;
                updateProgressUIFromRatio(ratio, targetTime);
            }

            if (totalEl) {
                video.addEventListener('loadedmetadata', function () {
                    totalEl.textContent = formatTime(video.duration || 0);
                });
            }

            if (currentEl || progressBar || progressHandle) {
                video.addEventListener('timeupdate', function () {
                    const ct = video.currentTime || 0;
                    const dur = video.duration || 0;
                    const ratio = dur > 0 ? Math.min(ct / dur, 1) : 0;
                    updateProgressUIFromRatio(ratio, ct);

                    if (!card.dataset.viewSent && ct >= 3) {
                        card.dataset.viewSent = '1';
                        sendViewRequest(card);
                    }
                });
            }

            // گواستەوەی شوێنی کات بە کلیک/ڕاکێشان لەسەر پڕۆگرەس
            if (progressContainer) {
                let didDrag = false;

                function onMouseMove(e) {
                    if (!isDragging) return;
                    e.preventDefault();
                    seekToClientX(e.clientX);
                    didDrag = true;
                }

                function onMouseUp(e) {
                    if (!isDragging) return;
                    e.preventDefault();
                    isDragging = false;
                    progressContainer.classList.remove('dragging');
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    if (wasPlayingBeforeDrag) {
                        tryPlay(video);
                    }
                    wasPlayingBeforeDrag = false;
                    // ئەگەر تەنها کلیک بوو و نەکرا کشان، هەوڵ بدە بڕۆیت بۆ ئەو شوێنە و play بکات
                    if (!didDrag && e.clientX != null) {
                        seekToClientX(e.clientX);
                        if (video.paused) {
                            tryPlay(video);
                        }
                    }
                    didDrag = false;
                }

                progressContainer.addEventListener('mousedown', function (event) {
                    if (event.button !== 0) return;
                    event.preventDefault();
                    event.stopPropagation();
                    if (!isFinite(video.duration) || video.duration <= 0) {
                        return;
                    }
                    isDragging = true;
                    didDrag = false;
                    wasPlayingBeforeDrag = !video.paused;
                    video.pause();
                    progressContainer.classList.add('dragging');
                    seekToClientX(event.clientX);
                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                });

                // Touch events بۆ مۆبایل
                function onTouchMove(e) {
                    if (!isDragging) return;
                    const touch = e.touches[0];
                    if (!touch) return;
                    e.preventDefault();
                    seekToClientX(touch.clientX);
                    didDrag = true;
                }

                function onTouchEnd(e) {
                    if (!isDragging) return;
                    const touch = (e.changedTouches && e.changedTouches[0]) || null;
                    e.preventDefault();
                    isDragging = false;
                    progressContainer.classList.remove('dragging');
                    document.removeEventListener('touchmove', onTouchMove);
                    document.removeEventListener('touchend', onTouchEnd);
                    document.removeEventListener('touchcancel', onTouchEnd);
                    if (wasPlayingBeforeDrag) {
                        tryPlay(video);
                    }
                    wasPlayingBeforeDrag = false;
                    if (!didDrag && touch) {
                        seekToClientX(touch.clientX);
                        if (video.paused) {
                            tryPlay(video);
                        }
                    }
                    didDrag = false;
                }

                progressContainer.addEventListener('touchstart', function (event) {
                    const touch = event.touches[0];
                    if (!touch) return;
                    event.preventDefault();
                    event.stopPropagation();
                    if (!isFinite(video.duration) || video.duration <= 0) {
                        return;
                    }
                    isDragging = true;
                    didDrag = false;
                    wasPlayingBeforeDrag = !video.paused;
                    video.pause();
                     progressContainer.classList.add('dragging');
                    seekToClientX(touch.clientX);
                    document.addEventListener('touchmove', onTouchMove, { passive: false });
                    document.addEventListener('touchend', onTouchEnd);
                    document.addEventListener('touchcancel', onTouchEnd);
                }, { passive: false });
            }

            // نیشاندانی کۆنترۆڵەکانی خوارەوە تەنیا لە فۆڵسکرین و کاتێک کلیک بکرێت
            video.addEventListener('click', function () {
                if (!frame || !frame.classList.contains('video-frame-fullscreen')) {
                    return;
                }
                frame.classList.add('video-show-controls');
                if (controlsHideTimeout) {
                    clearTimeout(controlsHideTimeout);
                }
                controlsHideTimeout = setTimeout(function () {
                    frame.classList.remove('video-show-controls');
                }, 3000);
            });
        });

        // --- TikTok style scroll snapping ---
        const feedWrapper = document.querySelector('.video-feed-wrapper');

        function scrollToCard(card) {
            if (!card || !feedWrapper) return;
            const top = card.offsetTop;
            feedWrapper.scrollTo({
                top: top,
                behavior: 'smooth'
            });
        }

        function findFirstUnwatchedCard() {
            if (!cards.length) return null;
            for (let i = 0; i < cards.length; i++) {
                const card = cards[i];
                if (card && card.dataset.watched !== '1') {
                    return card;
                }
            }
            return null;
        }

        function getClosestCard() {
            if (!feedWrapper || !cards.length) return null;
            const wrapperRect = feedWrapper.getBoundingClientRect();
            const centerY = wrapperRect.top + wrapperRect.height / 2;
            let best = null;
            let bestDist = Infinity;

            cards.forEach(function (card) {
                const rect = card.getBoundingClientRect();
                const cardCenter = rect.top + rect.height / 2;
                const dist = Math.abs(cardCenter - centerY);
                if (dist < bestDist) {
                    bestDist = dist;
                    best = card;
                }
            });

            return best;
        }

        if (feedWrapper) {
            // وەکو تیکتۆک: بە wheel یەک کارت بگۆڕە
            feedWrapper.addEventListener('wheel', function (event) {
                if (!cards.length) return;
                event.preventDefault();

                const current = getClosestCard();
                if (!current) return;

                const currentIndex = cards.indexOf(current);
                if (currentIndex === -1) return;

                let targetIndex = currentIndex;
                if (event.deltaY > 0 && currentIndex < cards.length - 1) {
                    targetIndex = currentIndex + 1;
                } else if (event.deltaY < 0 && currentIndex > 0) {
                    targetIndex = currentIndex - 1;
                }

                const targetCard = cards[targetIndex];
                scrollToCard(targetCard);
            }, { passive: false });
        }

        if ('IntersectionObserver' in window) {
            let activeVideo = null;

            const observer = new IntersectionObserver(function (entries) {
                let bestEntry = null;

                entries.forEach(function (entry) {
                    if (!bestEntry || entry.intersectionRatio > bestEntry.intersectionRatio) {
                        bestEntry = entry;
                    }
                });

                if (bestEntry && bestEntry.isIntersecting) {
                    const video = bestEntry.target.querySelector('.video-player');
                    if (video && video !== activeVideo) {
                        // flush watch time بۆ ڤیدیۆی پێشوو پێش گواستنەوە
                        if (activeVideo) {
                            flushWatchStateForVideo(activeVideo);
                        }
                        activeVideo = video;
                        pauseAllExcept(video);
                        tryPlay(video, { withSound: true });
                    }
                }
            }, {
                threshold: [0.4, 0.6, 0.8]
            });

            cards.forEach(function (card) {
                observer.observe(card);
            });
        } else {
            // fallback بۆ براوسەرە کۆنترەکان
            function getMostVisibleVideo() {
                let bestVideo = null;
                let bestScore = 0;

                videos.forEach(function (video) {
                    const rect = video.getBoundingClientRect();
                    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
                    const visible = Math.max(0, Math.min(rect.bottom, viewportHeight) - Math.max(rect.top, 0));
                    const score = visible / viewportHeight;
                    if (score > bestScore) {
                        bestScore = score;
                        bestVideo = video;
                    }
                });

                return bestVideo;
            }

            let fallbackCurrent = null;
            function onScrollFallback() {
                const v = getMostVisibleVideo();
                if (v && v !== fallbackCurrent) {
                    fallbackCurrent = v;
                    pauseAllExcept(v);
                    tryPlay(v, { withSound: true });
                }
            }

            window.addEventListener('scroll', onScrollFallback, {passive: true});
            window.addEventListener('resize', onScrollFallback);
            onScrollFallback();
        }

        function handleLikeButtonClick(event) {
            const btn = event.target.closest('.video-like-btn');
            if (!btn) return;
            event.preventDefault();
            const card = btn.closest('.video-card');
            if (!card) return;
            sendLikeRequest(card);
        }

        // دوگمەی کۆپیکردنی لینکی ڤیدیۆ
        function buildVideoLink(videoType, videoId) {
            if (!videoType || !videoId) return null;
            const url = new URL(window.location.href);
            url.searchParams.set('video_type', videoType);
            url.searchParams.set('video_id', String(videoId));
            return url.toString();
        }

        function handleCopyButtonClick(event) {
            const btn = event.target.closest('.video-copy-btn');
            if (!btn) return;

            const videoId = parseInt(btn.getAttribute('data-video-id') || '0', 10);
            const videoType = btn.getAttribute('data-video-type') || '';
            const link = buildVideoLink(videoType, videoId);
            if (!link) return;

            const icon = btn.querySelector('i');
            const originalIconClasses = icon ? icon.className : '';

            function showCopiedState() {
                if (!icon) return;
                icon.className = 'bi bi-check2';
                setTimeout(function () {
                    icon.className = originalIconClasses;
                }, 1600);
            }

            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(link).then(showCopiedState).catch(function () {
                    // فۆڵباکی کۆنتر
                    const textarea = document.createElement('textarea');
                    textarea.value = link;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    try {
                        document.execCommand('copy');
                        showCopiedState();
                    } catch (e) {
                        // پشتگوێ کردنەوە
                    }
                    document.body.removeChild(textarea);
                });
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = link;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    showCopiedState();
                } catch (e) {
                    // پشتگوێ کردنەوە
                }
                document.body.removeChild(textarea);
            }
        }

        // Fullscreen بۆ هەر ڤیدیۆیەک
        function setFullscreenStateForFrame(frame, isActive) {
            if (!frame) return;
            const icon = frame.querySelector('.video-fullscreen-btn i');
            if (isActive) {
                frame.classList.add('video-frame-fullscreen');
                frame.classList.remove('video-show-controls');
                if (icon) {
                    icon.className = 'bi bi-fullscreen-exit';
                }
            } else {
                frame.classList.remove('video-frame-fullscreen');
                frame.classList.remove('video-show-controls');
                if (icon) {
                    icon.className = 'bi bi-arrows-fullscreen';
                }
            }
        }

        function toggleFullscreenForFrame(frame) {
            if (!frame) return;
            const doc = document;
            const isFullscreen = doc.fullscreenElement || doc.webkitFullscreenElement || doc.msFullscreenElement;

            if (!isFullscreen) {
                const request = frame.requestFullscreen || frame.webkitRequestFullscreen || frame.msRequestFullscreen;
                if (request) {
                    request.call(frame);
                    setFullscreenStateForFrame(frame, true);
                }
            } else {
                const exit = doc.exitFullscreen || doc.webkitExitFullscreen || doc.msExitFullscreen;
                if (exit) {
                    exit.call(doc);
                }
            }
        }

        document.addEventListener('fullscreenchange', function () {
            const doc = document;
            const fsElement = doc.fullscreenElement;
            document.querySelectorAll('.video-frame').forEach(function (frame) {
                setFullscreenStateForFrame(frame, frame === fsElement);
            });
        });

        document.querySelectorAll('.video-fullscreen-btn').forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation(); // ڕێگری لە play/pause بوونی ڤیدیۆ لە کلیکی دوگمەکە
                const frame = btn.closest('.video-frame');
                toggleFullscreenForFrame(frame);
            });
        });

        document.addEventListener('click', handleCopyButtonClick);
        document.addEventListener('click', handleLikeButtonClick);

        // Overlay بۆ چالاککردنی دەنگ و play
        const overlay = document.getElementById('videoStartOverlay');
        if (overlay) {
            overlay.addEventListener('click', function () {
                hasUserStarted = true;
                overlay.classList.add('hidden');
                const first = document.querySelector('.video-card .video-player');
                if (first) {
                    first.muted = false;
                    tryPlay(first, { withSound: true });
                }
            });
        }

        // کلیک لەسەر لۆگۆ بۆ گواستنەوە بۆ فرۆشگا
        document.querySelectorAll('.video-card').forEach(function (card) {
            var shopUrl = card.getAttribute('data-shop-url');
            if (!shopUrl) {
                return;
            }

            var logo = card.querySelector('.video-logo, .video-logo-placeholder');
            if (!logo) {
                return;
            }

            logo.addEventListener('click', function (event) {
                // ڕێگری لە play/pause بوونی ڤیدیۆ لە کلیکی لۆگۆ
                event.stopPropagation();
                window.location.href = shopUrl;
            });
        });

        // Read More functionality
        const descWrappers = document.querySelectorAll('.video-description-wrapper');
        descWrappers.forEach(function(wrapper) {
            const desc = wrapper.querySelector('.video-description');
            const btn = wrapper.querySelector('.video-read-more-btn');
            const card = wrapper.closest('.video-card');
            const sideActions = card ? card.querySelector('.video-side-actions') : null;
            
            if (desc && btn) {
                // کەمێک دواخستن بۆ ئەوەی دڵنیا بین کە render بووە
                setTimeout(function() {
                    // ئەگەر دەقەکە لە خۆیدا زۆرتر بێت لە بەشە بینراوەکەی
                    if (desc.scrollHeight > desc.clientHeight) {
                        btn.classList.remove('d-none');
                    }
                }, 150);

                btn.addEventListener('click', function(e) {
                    e.stopPropagation(); // بۆ ئەوەی کلیک نەچێتە سەر ڤیدیۆکە
                    if (desc.classList.contains('expanded')) {
                        desc.classList.remove('expanded');
                        btn.textContent = 'زیاتر...';
                        if (sideActions) sideActions.classList.remove('hidden-by-description');
                    } else {
                        desc.classList.add('expanded');
                        btn.textContent = 'کەمتر';
                        if (sideActions) sideActions.classList.add('hidden-by-description');
                    }
                });
                
                // ئەگەر دەقەکە کرا بووبێتەوە و لەسەری کلیک کرا، با داخرێتەوە
                desc.addEventListener('click', function(e) {
                    if (desc.classList.contains('expanded')) {
                        e.stopPropagation();
                        desc.classList.remove('expanded');
                        btn.textContent = 'زیاتر...';
                        if (sideActions) sideActions.classList.remove('hidden-by-description');
                    }
                });
            }
        });

        // Deep-linking بۆ ڤیدیۆی دیاری‌کراو لەسەر بنەمای video_type و video_id
        if (targetVideoType && targetVideoId && cards.length) {
            const selector = '.video-card[data-video-type="' + targetVideoType + '"][data-video-id="' + targetVideoId + '"]';
            const targetCard = document.querySelector(selector);
            if (targetCard) {
                const video = targetCard.querySelector('.video-player');
                if (video) {
                    // هەوڵ بدە ڤیدیۆکە play بکرێت بە دەنگ، ئەگەر براوسەر رێگەدا، بەبێ سکڕۆڵی خۆکارانە
                    tryPlay(video, { withSound: true });
                    const state = getOrCreateWatchState(video);
                    if (state.lastStartedAt === null) {
                        state.lastStartedAt = Date.now() / 1000;
                    }
                }
            }
        } else if (cards.length) {
            // ئەگەر deep-link نییە، یەکەم ڤیدیۆی نەبینراو بدۆزەوە و بۆ دەستپێکی فید ببە
            const firstUnwatched = findFirstUnwatchedCard();
            if (firstUnwatched && firstUnwatched !== cards[0]) {
                scrollToCard(firstUnwatched);
            }
        }

        // کاتێک پەڕەکە دەبێتە پشتی، هەوڵ بدە watch time ی هەموو ڤیدیۆی کارا flush بکرێت
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                videos.forEach(function (video) {
                    if (!video.paused) {
                        flushWatchStateForVideo(video, { force: true });
                    }
                });
            }
        });

        document.addEventListener('click', function (event) {
            handleLikeButtonClick(event);
            handleCopyButtonClick(event);
            handleCommentButtonClick(event);
        });
    })();
</script>
</body>
</html>

