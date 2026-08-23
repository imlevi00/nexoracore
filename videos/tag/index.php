<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/product_videos/database.php';
require_once __DIR__ . '/../../config/product_images/database.php';

global $conn, $conn_videos, $conn_images;

$siteName = defined('SITE_NAME') ? SITE_NAME : 'NexoraCore';

// وەرگرتنی هاشتاگ و پارامەتەرەکانی فلتەرکردن
$rawTag = isset($_GET['tag']) ? (string)$_GET['tag'] : '';
$rawTag = trim($rawTag);
if ($rawTag !== '' && $rawTag[0] === '#') {
    $rawTag = substr($rawTag, 1);
}
$rawTag = mb_substr($rawTag, 0, 64, 'UTF-8');

$filterType = isset($_GET['type']) ? strtolower((string)$_GET['type']) : 'all';
if (!in_array($filterType, ['all', 'free', 'product'], true)) {
    $filterType = 'all';
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if (!in_array($perPage, [12, 20, 40], true)) {
    $perPage = 20;
}

/**
 * وەسفی ڤیدیۆ بە هاشتاگ highlight و clickable دەکات.
 * `$text` لە پێشدا `htmlspecialchars` کرابێت.
 */
function renderVideoDescriptionWithHashtags(?string $text): string
{
    if ($text === null || $text === '') {
        return '';
    }

    $pattern = '/(^|[\s\p{P}])#([\p{L}\p{M}0-9_]+)/u';

    $callback = static function (array $m): string {
        $prefix = $m[1];
        $tagRaw = $m[2];
        $tagForUrl = trim($tagRaw);
        if ($tagForUrl === '') {
            return $m[0];
        }

        $href = 'index.php?tag=' . rawurlencode($tagForUrl);

        return $prefix
            . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="video-hashtag" rel="nofollow">#'
            . htmlspecialchars($tagRaw, ENT_QUOTES, 'UTF-8')
            . '</a>';
    };

    return preg_replace_callback($pattern, $callback, $text) ?? $text;
}

$tag = $rawTag;
$hasValidTag = ($tag !== '');

$videos = [];
$totalCount = 0;
$totalPages = 1;

$logosByUserId = [];
$shopUrlsByUserId = [];
$storeNamesByUserId = [];

if ($hasValidTag && $conn_videos instanceof mysqli) {
    $pattern = '%#' . $tag . '%';

    // ژمارەی ڤیدیۆکان بۆ هەموو جۆرەکان
    $countFree = 0;
    $countProduct = 0;

    if ($filterType === 'all' || $filterType === 'free') {
        if ($stmt = $conn_videos->prepare('SELECT COUNT(*) AS c FROM free_videos WHERE video_description LIKE ?')) {
            $stmt->bind_param('s', $pattern);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $countFree = (int)($row['c'] ?? 0);
                }
            }
            $stmt->close();
        }
    }

    if ($filterType === 'all' || $filterType === 'product') {
        if ($stmt = $conn_videos->prepare('SELECT COUNT(*) AS c FROM product_videos WHERE video_description LIKE ?')) {
            $stmt->bind_param('s', $pattern);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $countProduct = (int)($row['c'] ?? 0);
                }
            }
            $stmt->close();
        }
    }

    if ($filterType === 'free') {
        $totalCount = $countFree;
    } elseif ($filterType === 'product') {
        $totalCount = $countProduct;
    } else {
        $totalCount = $countFree + $countProduct;
    }

    $totalPages = $totalCount > 0 ? max(1, (int)ceil($totalCount / $perPage)) : 1;
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;

    // هێنانەوەی ڤیدیۆکان
    $queryParts = [];
    $params = [];
    $types = '';

    if ($filterType === 'all' || $filterType === 'free') {
        $queryParts[] = "
            SELECT id, user_id, NULL AS product_id,
                   video_description, video_url, created_at,
                   'free' AS video_type
            FROM free_videos
            WHERE video_description LIKE ?
        ";
        $params[] = $pattern;
        $types .= 's';
    }

    if ($filterType === 'all' || $filterType === 'product') {
        $queryParts[] = "
            SELECT id, user_id, product_id,
                   video_description, video_url, created_at,
                   'product' AS video_type
            FROM product_videos
            WHERE video_description LIKE ?
        ";
        $params[] = $pattern;
        $types .= 's';
    }

    if (!empty($queryParts)) {
        $sql = implode(' UNION ALL ', $queryParts) . ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $sql .= ' ';
        $types .= 'ii';
        $params[] = $perPage;
        $params[] = $offset;

        if ($stmt = $conn_videos->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (!empty($row['video_url'])) {
                        $videos[] = $row;
                    }
                }
            }
            $stmt->close();
        }
    }

    // هێنانەوەی لۆگۆ، لینک و ناوی فرۆشگا بەپێی user_id
    if (!empty($videos)) {
        $userIds = [];
        foreach ($videos as $item) {
            if (!empty($item['user_id'])) {
                $userIds[(int)$item['user_id']] = true;
            }
        }

        if (!empty($userIds)) {
            $idList = implode(',', array_map('intval', array_keys($userIds)));

            if (!empty($conn_images)) {
                $logoSql = "SELECT user_id, logo_url FROM user_logos WHERE user_id IN ($idList)";
                if ($logoResult = $conn_images->query($logoSql)) {
                    while ($logo = $logoResult->fetch_assoc()) {
                        if (!empty($logo['user_id']) && !empty($logo['logo_url'])) {
                            $logosByUserId[(int)$logo['user_id']] = $logo['logo_url'];
                        }
                    }
                }
            }

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
                    }
                }
            } catch (Throwable $e) {
                if (function_exists('writeLog')) {
                    writeLog('Tag page slug fetch failed: ' . $e->getMessage(), 'ERROR');
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
                    }
                }
            } catch (Throwable $e) {
                if (function_exists('writeLog')) {
                    writeLog('Tag page store name fetch failed: ' . $e->getMessage(), 'ERROR');
                }
            }
        }
    }
}

function buildVideoLink(string $type, int $id): string
{
    $query = 'videos/index.php?video_type=' . rawurlencode($type) . '&video_id=' . $id;
    if (function_exists('url')) {
        return url($query);
    }
    return $query;
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php if ($hasValidTag): ?>
            هاشتاگ #<?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
        <?php else: ?>
            هاشتاگی ڤیدیۆکان - <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
        <?php endif; ?>
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background: radial-gradient(circle at top, #020617 0, #020617 40%, #000000 100%);
            color: #e5e7eb;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .tag-page-wrapper {
            padding: 1.25rem 0 2.3rem;
        }

        .tag-hero {
            border-radius: 1.5rem;
            padding: 1.25rem 1.35rem;
            background: radial-gradient(circle at top left, rgba(56,189,248,0.22), transparent 55%),
                        radial-gradient(circle at bottom right, rgba(129,140,248,0.22), transparent 55%),
                        linear-gradient(135deg, rgba(15,23,42,0.98), rgba(15,23,42,0.9));
            border: 1px solid rgba(148,163,184,0.55);
            box-shadow:
                0 24px 60px rgba(15,23,42,0.9),
                0 0 0 1px rgba(15,23,42,0.7);
        }

        .tag-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border-radius: 999px;
            padding: 0.3rem 0.85rem;
            background: rgba(15,23,42,0.9);
            border: 1px solid rgba(148,163,184,0.7);
            color: #e5e7eb;
            font-size: 0.9rem;
        }

        .tag-chip i {
            font-size: 1rem;
        }

        .tag-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.85rem;
            color: #cbd5f5;
        }

        .tag-filter-pills .btn {
            border-radius: 0;
            font-size: 0.85rem;
            padding-inline: 0.9rem;
        }

        /* دووگمەی یەکەم ("هەموو") - لای ڕاست چەمابێتەوە */
        .tag-filter-pills .btn-group .btn:first-child {
            border-top-right-radius: 999px;
            border-bottom-right-radius: 999px;
            border-top-left-radius: 999px;
            border-bottom-left-radius: 999px;
        }

        /* دووگمەی کۆتایی ("کاڵا") - لای چەپ چەمابێتەوە */
        .tag-filter-pills .btn-group .btn:last-child {
            border-top-left-radius: 999px;
            border-bottom-left-radius: 999px;
            border-top-right-radius: 999px;
            border-bottom-right-radius: 999px;
        }

        /* دووگمەکانی ناوەڕاست ("گشتی") - لای ناوەوە چەمابێن */
        .tag-filter-pills .btn-group .btn:not(:first-child):not(:last-child) {
            border-top-left-radius: 999px;
            border-bottom-left-radius: 999px;
            border-top-right-radius: 999px;
            border-bottom-right-radius: 999px;
        }

        /* دووگمەی چالاک */
        .tag-filter-pills .btn-check:checked + .btn {
            background: #38bdf8;
            border-color: #38bdf8;
            color: #0b1120;
        }

        .video-hashtag {
            color: #38bdf8;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            position: relative;
            z-index: 2;
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

        .tag-grid {
            margin-top: 1.25rem;
        }

        /* 5 کارت لەسەر یەک ڕیز لە کۆمپیوتەردا */
        @media (min-width: 992px) {
            .tag-grid .row > [class*="col-lg"] {
                flex: 0 0 20%;
                max-width: 20%;
            }
        }

        .tag-video-card {
            background: radial-gradient(circle at top, #020617 0, #020617 55%, #020617 100%);
            border-radius: 1.3rem;
            border: 1px solid rgba(30,64,175,0.7);
            overflow: hidden;
            box-shadow:
                0 14px 35px rgba(15,23,42,0.9),
                0 0 0 1px rgba(15,23,42,0.8);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .tag-video-link {
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }

        .tag-video-media {
            position: relative;
            background: #000;
        }

        .tag-video-media video {
            width: 100%;
            height: 300px;
            object-fit: cover;
            display: block;
        }

        @media (min-width: 992px) {
            .tag-video-media video {
                height: 350px;
            }
        }

        .tag-play-icon {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }

        .tag-play-icon-inner {
            width: 60px;
            height: 60px;
            border-radius: 999px;
            background: rgba(0,0,0,0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f9fafb;
            box-shadow:
                0 18px 40px rgba(0,0,0,0.9),
                0 0 0 1px rgba(248,250,252,0.35);
        }

        .tag-play-icon-inner i {
            font-size: 2rem;
        }

        .tag-video-body {
            padding: 0.9rem 0.95rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .tag-video-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
        }

        .tag-store-group {
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }

        .tag-store-group a {
            position: relative;
            z-index: 2;
        }

        .tag-logo {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            object-fit: cover;
        }

        .tag-logo-placeholder {
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

        .tag-store-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: #e5e7eb;
        }

        .tag-meta {
            font-size: 0.78rem;
            color: #9ca3af;
        }

        .tag-description {
            font-size: 0.86rem;
            color: #e5e7eb;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .tag-type-badge {
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

        .tag-horizontal-strip {
            margin-top: 2rem;
        }

        .tag-horizontal-strip-inner {
            display: flex;
            gap: 0.9rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            scrollbar-width: thin;
        }

        .tag-horizontal-strip-inner::-webkit-scrollbar {
            height: 5px;
        }

        .tag-horizontal-strip-inner::-webkit-scrollbar-thumb {
            background: rgba(148,163,184,0.7);
            border-radius: 999px;
        }

        .tag-mini-card {
            min-width: 180px;
            max-width: 220px;
            background: rgba(15,23,42,0.96);
            border-radius: 1rem;
            border: 1px solid rgba(30,64,175,0.8);
            overflow: hidden;
        }

        .tag-mini-card video {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .tag-mini-body {
            padding: 0.55rem 0.6rem 0.7rem;
        }

        .tag-mini-title {
            font-size: 0.8rem;
            color: #e5e7eb;
        }

        .tag-empty {
            border-radius: 1.5rem;
            padding: 2.5rem 1.5rem;
            background: radial-gradient(circle at top, rgba(15,23,42,1) 0, rgba(15,23,42,0.96) 55%);
            border: 1px solid rgba(55,65,81,0.9);
            text-align: center;
            max-width: 520px;
            margin: 1.75rem auto 0;
            box-shadow:
                0 24px 70px rgba(15,23,42,1),
                0 0 0 1px rgba(15,23,42,0.9);
        }

        /* شوێن گونجاوتر بۆ حالەتی تەنیا یەک ڤیدیۆ */
        .tag-single-video .tag-page-wrapper {
            padding-top: 0.2rem;
            padding-bottom: 0.5rem;
        }

        .tag-single-video .tag-hero {
            margin-bottom: 0 !important;
            padding: 0.6rem 1rem;
        }

        .tag-single-video .tag-grid {
            margin-top: 0;
            display: flex;
            justify-content: center;
        }

        /* با کارتەکە نەبێتە full-width لە شاشە گەورەکان */
        .tag-single-video .tag-grid .col-12 {
            max-width: 720px;
        }

        /* بەرزکردنەوەی بەرزی ڤیدیۆ بۆ کارتی تەنیا یەک ڤیدیۆ */
        .tag-single-video .tag-video-media video {
            height: 280px;
        }

        @media (min-width: 992px) {
            .tag-single-video .tag-video-media video {
                height: 320px;
            }
        }
    </style>
</head>
<body class="<?php echo ($hasValidTag && $totalCount === 1) ? 'tag-single-video' : ''; ?>">
<div class="container tag-page-wrapper">
    <div class="tag-hero mb-3">
        <?php if ($hasValidTag): ?>
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <div class="mb-2">
                        <span class="tag-chip">
                            <i class="bi bi-hash"></i>
                            <span>#<?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                    </div>
                    <div class="tag-stats">
                        <span>
                            <i class="bi bi-collection-play"></i>
                            <?php echo (int)$totalCount; ?> ڤیدیۆ
                        </span>
                        <?php if ($countFree > 0): ?>
                            <span>
                                <i class="bi bi-play-btn"></i>
                                <?php echo (int)$countFree; ?> گشتی
                            </span>
                        <?php endif; ?>
                        <?php if ($countProduct > 0): ?>
                            <span>
                                <i class="bi bi-bag-check"></i>
                                <?php echo (int)$countProduct; ?> کاڵا
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tag-filter-pills text-md-end">
                    <?php
                    $baseQuery = ['tag' => $tag, 'per_page' => $perPage];
                    ?>
                    <div class="btn-group" role="group" aria-label="Filter by type">
                        <?php foreach (['all' => 'هەموو', 'free' => 'گشتی', 'product' => 'کاڵا'] as $key => $label): ?>
                            <?php
                            $checked = $filterType === $key ? 'checked' : '';
                            $query = http_build_query(array_merge($baseQuery, ['type' => $key]));
                            ?>
                            <input type="radio" class="btn-check" name="typeFilter" id="filter-<?php echo $key; ?>"
                                   autocomplete="off" <?php echo $checked; ?>>
                            <label class="btn btn-outline-light btn-sm" for="filter-<?php echo $key; ?>"
                                   onclick="window.location.href='?<?php echo htmlspecialchars($query, ENT_QUOTES, 'UTF-8'); ?>'">
                                <?php echo $label; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <h1 class="h5 mb-2">
                        هاشتاگی ڤیدیۆکان
                    </h1>
                    <p class="mb-0 small text-light">
                        هاشتاگێک وەک نمونه `#kasher` لە لای ژێر ڤیدیۆکان کلیک بکە بۆ بینینی هەموو ڤیدیۆکان بەو هاشتاگە.
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$hasValidTag): ?>
        <div class="tag-empty">
            <div class="mb-3">
                <i class="bi bi-hash fs-1 text-info"></i>
            </div>
            <h2 class="h5 mb-2">هاشتاگ دیاری نەکراوە</h2>
            <p class="mb-0 small text-muted">
                تکایە یک هاشتاگ لەسەر یەکێک لە ڤیدیۆکان کلیک بکە بۆ ئەوەی لێرە هەموو ئەو ڤیدیۆیانە ببینیت.
            </p>
        </div>
    <?php else: ?>
        <?php if (empty($videos)): ?>
            <div class="tag-empty">
                <div class="mb-3">
                    <i class="bi bi-emoji-neutral fs-1 text-secondary"></i>
                </div>
                <h2 class="h5 mb-2">هیچ ڤیدیۆیەک بە ئەم هاشتاگە نییە</h2>
                <p class="mb-0 small text-muted">
                    هێشتا هیچ ڤیدیۆیەک بە هاشتاگی
                    <span class="text-info">#<?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?></span>
                    بارنەکراوە.
                </p>
            </div>
        <?php else: ?>
            <div class="tag-grid">
                <div class="row g-3 g-md-4">
                    <?php foreach ($videos as $item): ?>
                        <?php
                        $videoId = isset($item['id']) ? (int)$item['id'] : 0;
                        $userId = !empty($item['user_id']) ? (int)$item['user_id'] : null;
                        $type = ($item['video_type'] === 'product') ? 'product' : 'free';
                        $description = htmlspecialchars($item['video_description'] ?? '', ENT_QUOTES, 'UTF-8');
                        $videoUrl = htmlspecialchars($item['video_url'] ?? '', ENT_QUOTES, 'UTF-8');

                        $logoUrl = $userId && isset($logosByUserId[$userId])
                            ? htmlspecialchars($logosByUserId[$userId], ENT_QUOTES, 'UTF-8')
                            : null;
                        $shopUrl = $userId && isset($shopUrlsByUserId[$userId])
                            ? $shopUrlsByUserId[$userId]
                            : null;

                        // ناوی فرۆشگا بەپێی user_id، fallback بۆ ناوی سیستەم
                        $storeShort = $siteName;
                        if ($userId && isset($storeNamesByUserId[$userId]) && $storeNamesByUserId[$userId] !== '') {
                            $storeShort = $storeNamesByUserId[$userId];
                        }
                        $storeInitials = mb_strtoupper(mb_substr($storeShort, 0, 2, 'UTF-8'), 'UTF-8');

                        $videoLink = $videoId > 0 ? buildVideoLink($type, $videoId) : '#';
                        ?>
                        <div class="col-6 col-md-6 col-lg">
                            <article class="tag-video-card position-relative">
                                <a href="<?php echo htmlspecialchars($videoLink, ENT_QUOTES, 'UTF-8'); ?>" class="stretched-link"></a>
                                <div class="tag-video-media">
                                    <video src="<?php echo $videoUrl; ?>" muted playsinline preload="metadata" loop></video>
                                    <div class="tag-play-icon">
                                        <div class="tag-play-icon-inner">
                                            <i class="bi bi-play-fill"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="tag-video-body">
                                    <div class="tag-video-header">
                                        <div class="tag-store-group">
                                            <?php if ($shopUrl && $logoUrl): ?>
                                                <a href="<?php echo htmlspecialchars($shopUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                   class="d-inline-flex">
                                                    <img src="<?php echo $logoUrl; ?>" alt="Logo" class="tag-logo">
                                                </a>
                                            <?php elseif ($shopUrl): ?>
                                                <a href="<?php echo htmlspecialchars($shopUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                   class="d-inline-flex">
                                                    <div class="tag-logo-placeholder">
                                                        <span><?php echo htmlspecialchars($storeInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                </a>
                                            <?php else: ?>
                                                <div class="tag-logo-placeholder">
                                                    <span><?php echo htmlspecialchars($storeInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="d-flex flex-column">
                                                <span class="tag-store-name">
                                                    <?php echo htmlspecialchars($storeShort, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="tag-type-badge">
                                                <i class="bi <?php echo $type === 'product' ? 'bi-bag-check-fill' : 'bi-play-btn-fill'; ?>"></i>
                                                <span><?php echo $type === 'product' ? 'کاڵا' : 'گشتی'; ?></span>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($description): ?>
                                        <p class="tag-description mb-0">
                                            <?php echo renderVideoDescriptionWithHashtags($description); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="mt-3 d-flex justify-content-center">
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $baseQuery = ['tag' => $tag, 'type' => $filterType, 'per_page' => $perPage];
                            $base = http_build_query($baseQuery);
                            ?>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link"
                                   href="<?php echo $page <= 1 ? '#' : '?' . htmlspecialchars($base . '&page=' . ($page - 1), ENT_QUOTES, 'UTF-8'); ?>">
                                    پێشوو
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i == 1 || $i == $totalPages || abs($i - $page) <= 2): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link"
                                           href="?<?php echo htmlspecialchars($base . '&page=' . $i, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php elseif (abs($i - $page) === 3): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link"
                                   href="<?php echo $page >= $totalPages ? '#' : '?' . htmlspecialchars($base . '&page=' . ($page + 1), ENT_QUOTES, 'UTF-8'); ?>">
                                    دواتر
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>

