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

if ($videoId <= 0 || !in_array($videoType, ['free', 'product'], true)) {
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
        // Logged-in via Google: هەر بەکارهێنەرێک IP/UA ـی جیاواز بۆ هەبێت تا uniqueness لەسەر میوانان کێشە دروست نەکات
        $googleIp = 'google_user_' . $googleUserId;
        $googleUa = 'google_user_' . $googleUserId;

        // Logged-in via Google: track by google_user_id
        $insertSql = "
            INSERT INTO video_views (video_type, video_id, ip_address, user_agent, google_user_id, viewed_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                viewed_at = VALUES(viewed_at),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent)
        ";
        if ($stmt = $conn_zanyari->prepare($insertSql)) {
            $stmt->bind_param('sissi', $videoType, $videoId, $googleIp, $googleUa, $googleUserId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        // Guest: track by IP + User-Agent
        $insertSql = "
            INSERT INTO video_views (video_type, video_id, ip_address, user_agent, viewed_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE viewed_at = VALUES(viewed_at)
        ";
        if ($stmt = $conn_zanyari->prepare($insertSql)) {
            $stmt->bind_param('siss', $videoType, $videoId, $visitorIP, $userAgent);
            $stmt->execute();
            $stmt->close();
        }
    }

    $viewCount = 0;
    $countSql = "SELECT COUNT(*) AS total FROM video_views WHERE video_type = ? AND video_id = ?";
    if ($cntStmt = $conn_zanyari->prepare($countSql)) {
        $cntStmt->bind_param('si', $videoType, $videoId);
        $cntStmt->execute();
        $result = $cntStmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $viewCount = (int)($row['total'] ?? 0);
        }
        $cntStmt->close();
    }

    echo json_encode([
        'success'    => true,
        'view_count' => $viewCount,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'server_error',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
