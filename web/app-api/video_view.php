<?php
/**
 * ====================================================================
 * App API - Record Video View  (web/app-api/video_view.php)
 * ====================================================================
 * تۆمارکردنی بینینی ڤیدیۆ بۆ بەرنامەی مۆبایل (وەک /videos/video_view.php ی سایتەکە).
 * بینین بەپێی IP + User-Agent یەکجار تۆمار دەکرێت (میوان).
 *
 * POST video_view.php
 *   Body (JSON یان form): { "video_id": 12, "video_type": "free"|"product" }
 *
 * وەڵام: { "success": true, "data": { "view_count": 34 } }
 *
 * هەمان داتابەیس (kasher_z.video_views) و لۆژیکی سایتەکە بەکاردەهێنێت.
 * ====================================================================
 */

require_once __DIR__ . '/_bootstrap.php';

require_once __DIR__ . '/../../config/product_videos/database.php';   // $conn_videos (پشکنینی بوونی ڤیدیۆ)
require_once __DIR__ . '/../../config/kasher_zanyari/database.php';   // $conn_zanyari (video_views)

app_api_require_method('POST');

/** @var mysqli|null $conn_videos */
/** @var mysqli|null $conn_zanyari */
$conn_videos  = $GLOBALS['conn_videos']  ?? null;
$conn_zanyari = $GLOBALS['conn_zanyari'] ?? null;

if (!($conn_zanyari instanceof mysqli)) {
    app_api_error('داتابەیسی ئامارەکان بەردەست نییە.', 503, 'stats_db_unavailable');
}

// خوێندنەوەی داتا: پشتگیری لە JSON و form-encoded (application/x-www-form-urlencoded)
$body = $_POST;
if (empty($body['video_id']) && empty($body['video_type'])) {
    $raw = file_get_contents('php://input') ?: '';
    if (trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $body = $json;                 // JSON body
        } else {
            parse_str($raw, $parsed);      // form-encoded raw body
            if (is_array($parsed)) {
                $body = $parsed;
            }
        }
    }
}

$videoId   = isset($body['video_id']) ? (int)$body['video_id'] : 0;
$videoType = isset($body['video_type']) ? trim((string)$body['video_type']) : '';

if ($videoId <= 0 || !in_array($videoType, ['free', 'product'], true)) {
    app_api_error('داتای ناردراو دروست نییە (video_id و video_type پێویستن).', 400, 'invalid_input');
}

// پشکنین: ئایا ڤیدیۆکە بوونی هەیە؟ (ڕێگری لە تۆماری بوختانی)
if ($conn_videos instanceof mysqli) {
    $table = $videoType === 'free' ? 'free_videos' : 'product_videos';
    if ($chk = $conn_videos->prepare("SELECT 1 FROM $table WHERE id = ? LIMIT 1")) {
        $chk->bind_param('i', $videoId);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();
        if (!$exists) {
            app_api_error('ڤیدیۆکە نەدۆزرایەوە.', 404, 'video_not_found');
        }
    }
}

$visitorIp = app_api_client_ip();
$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'KasheryApp', 0, 500);

try {
    // میوان: تۆمار بەپێی IP + User-Agent (وەک سایتەکە)
    $insertSql = "
        INSERT INTO video_views (video_type, video_id, ip_address, user_agent, viewed_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE viewed_at = VALUES(viewed_at)
    ";
    if ($stmt = $conn_zanyari->prepare($insertSql)) {
        $stmt->bind_param('siss', $videoType, $videoId, $visitorIp, $userAgent);
        $stmt->execute();
        $stmt->close();
    }

    $viewCount = 0;
    if ($cnt = $conn_zanyari->prepare("SELECT COUNT(*) AS total FROM video_views WHERE video_type = ? AND video_id = ?")) {
        $cnt->bind_param('si', $videoType, $videoId);
        $cnt->execute();
        $viewCount = (int)($cnt->get_result()->fetch_assoc()['total'] ?? 0);
        $cnt->close();
    }

    app_api_success(['view_count' => $viewCount]);
} catch (Throwable $e) {
    app_api_error('کێشەیەک ڕوویدا لە تۆمارکردنی بینین.', 500, 'server_error');
}
