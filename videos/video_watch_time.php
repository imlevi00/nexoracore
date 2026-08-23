<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/product_videos/database.php';
require_once __DIR__ . '/../config/kasher_zanyari/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!$conn_zanyari instanceof mysqli) {
    echo json_encode([
        'success' => false,
        'error'   => 'database_unavailable',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input') ?: '';
parse_str($rawInput, $parsed);

$videoId   = isset($_POST['video_id']) ? (int)$_POST['video_id'] : (int)($parsed['video_id'] ?? 0);
$videoType = isset($_POST['video_type']) ? (string)$_POST['video_type'] : (string)($parsed['video_type'] ?? '');
$videoType = trim($videoType);

$deltaSeconds = isset($_POST['watch_seconds'])
    ? (int)$_POST['watch_seconds']
    : (int)($parsed['watch_seconds'] ?? 0);

if ($videoId <= 0 || !in_array($videoType, ['free', 'product'], true) || $deltaSeconds <= 0) {
    echo json_encode([
        'success' => false,
        'error'   => 'invalid_input',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$visitorIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (strpos($visitorIP, ',') !== false) {
    $visitorIP = trim(explode(',', $visitorIP)[0]);
}
$visitorIP = substr($visitorIP, 0, 45);

$sessionId = session_id();

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
if (!empty($sessionId)) {
    $userAgent .= ' [SID:' . $sessionId . ']';
}
$userAgent = substr($userAgent, 0, 500);

$googleUserId = !empty($_SESSION['google_user']['id']) ? (int)$_SESSION['google_user']['id'] : null;

try {
    if ($googleUserId !== null) {
        // Logged-in via Google: track by google_user_id, with stable pseudo IP/UA so guest uniques do not conflict
        $googleIp = 'google_user_' . $googleUserId;
        $googleUa = 'google_user_' . $googleUserId;

        $sql = "
            INSERT INTO video_views (video_type, video_id, ip_address, user_agent, google_user_id, viewed_at, watch_seconds)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                viewed_at     = VALUES(viewed_at),
                ip_address    = VALUES(ip_address),
                user_agent    = VALUES(user_agent),
                watch_seconds = watch_seconds + VALUES(watch_seconds)
        ";

        if ($stmt = $conn_zanyari->prepare($sql)) {
            $stmt->bind_param('sissii', $videoType, $videoId, $googleIp, $googleUa, $googleUserId, $deltaSeconds);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        // Guest: track by IP + User-Agent
        $sql = "
            INSERT INTO video_views (video_type, video_id, ip_address, user_agent, viewed_at, watch_seconds)
            VALUES (?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                viewed_at     = VALUES(viewed_at),
                watch_seconds = watch_seconds + VALUES(watch_seconds)
        ";

        if ($stmt = $conn_zanyari->prepare($sql)) {
            $stmt->bind_param('sissi', $videoType, $videoId, $visitorIP, $userAgent, $deltaSeconds);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Optional: return the total watch_seconds for this viewer/video row
    $totalWatch = null;
    $countSql = "
        SELECT watch_seconds
        FROM video_views
        WHERE video_type = ? AND video_id = ?
          AND (
            (google_user_id IS NOT NULL AND google_user_id = ?)
            OR (google_user_id IS NULL AND ip_address = ? AND user_agent = ?)
          )
        LIMIT 1
    ";

    if ($stmt = $conn_zanyari->prepare($countSql)) {
        // For guests google_user_id param is null → use 0 as placeholder
        $googleIdParam = $googleUserId !== null ? $googleUserId : 0;
        $ipParam = $googleUserId !== null ? 'google_user_' . $googleUserId : $visitorIP;
        $uaParam = $googleUserId !== null ? 'google_user_' . $googleUserId : $userAgent;

        $stmt->bind_param('siiss', $videoType, $videoId, $googleIdParam, $ipParam, $uaParam);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $totalWatch = (int)($row['watch_seconds'] ?? 0);
        }
        $stmt->close();
    }

    echo json_encode([
        'success'      => true,
        'watch_delta'  => $deltaSeconds,
        'watch_total'  => $totalWatch,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'server_error',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

