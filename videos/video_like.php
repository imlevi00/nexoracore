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

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$userAgent = substr($userAgent, 0, 500);

$googleUserId = !empty($_SESSION['google_user']['id']) ? (int)$_SESSION['google_user']['id'] : null;
$liked = false;

try {
    if ($googleUserId === null) {
        echo json_encode([
            'success' => false,
            'error'   => 'login_required',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // لە حالەتی گوگڵ، IP/UA ـی جیاواز بۆ هەر بەکارهێنەر دروست بکە بۆئەوەی uniqueness لەسەر میوانان نەکەوێت
    $googleIp = 'google_user_' . $googleUserId;
    $googleUa = 'google_user_' . $googleUserId;

    // Toggle like based on Google user ID
    $selectSql = "SELECT id FROM video_likes WHERE video_type = ? AND video_id = ? AND google_user_id = ? LIMIT 1";
    if ($stmt = $conn_zanyari->prepare($selectSql)) {
        $stmt->bind_param('sii', $videoType, $videoId, $googleUserId);
        $stmt->execute();
        $stmt->bind_result($existingId);
        if ($stmt->fetch()) {
            $stmt->close();

            $deleteSql = "DELETE FROM video_likes WHERE id = ?";
            if ($delStmt = $conn_zanyari->prepare($deleteSql)) {
                $delStmt->bind_param('i', $existingId);
                $delStmt->execute();
                $delStmt->close();
            }
            $liked = false;
        } else {
            $stmt->close();

            $insertSql = "
                INSERT INTO video_likes (video_type, video_id, ip_address, user_agent, google_user_id, liked_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ";
            if ($insStmt = $conn_zanyari->prepare($insertSql)) {
                $insStmt->bind_param('sissi', $videoType, $videoId, $googleIp, $googleUa, $googleUserId);
                $insStmt->execute();
                $insStmt->close();
            }
            $liked = true;
        }
    }

    $likeCount = 0;
    $countSql  = "SELECT COUNT(*) AS total FROM video_likes WHERE video_type = ? AND video_id = ?";
    if ($cntStmt = $conn_zanyari->prepare($countSql)) {
        $cntStmt->bind_param('si', $videoType, $videoId);
        $cntStmt->execute();
        $result = $cntStmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $likeCount = (int)($row['total'] ?? 0);
        }
        $cntStmt->close();
    }

    echo json_encode([
        'success'    => true,
        'liked'      => $liked,
        'like_count' => $likeCount,
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
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/product_videos/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!$conn_videos instanceof mysqli) {
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

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$userAgent = substr($userAgent, 0, 500);

try {
    $conn_videos->query("
        CREATE TABLE IF NOT EXISTS video_likes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            video_type ENUM('free','product') NOT NULL,
            video_id INT NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500) NOT NULL,
            liked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_video_like (video_type, video_id, ip_address, user_agent),
            INDEX idx_video (video_type, video_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'migration_failed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$liked = false;

try {
    $selectSql = "SELECT id FROM video_likes WHERE video_type = ? AND video_id = ? AND ip_address = ? AND user_agent = ? LIMIT 1";
    if ($stmt = $conn_videos->prepare($selectSql)) {
        $stmt->bind_param('siss', $videoType, $videoId, $visitorIP, $userAgent);
        $stmt->execute();
        $stmt->bind_result($existingId);
        if ($stmt->fetch()) {
            $stmt->close();

            $deleteSql = "DELETE FROM video_likes WHERE id = ?";
            if ($delStmt = $conn_videos->prepare($deleteSql)) {
                $delStmt->bind_param('i', $existingId);
                $delStmt->execute();
                $delStmt->close();
            }
            $liked = false;
        } else {
            $stmt->close();

            $insertSql = "INSERT INTO video_likes (video_type, video_id, ip_address, user_agent, liked_at) VALUES (?, ?, ?, ?, NOW())";
            if ($insStmt = $conn_videos->prepare($insertSql)) {
                $insStmt->bind_param('siss', $videoType, $videoId, $visitorIP, $userAgent);
                $insStmt->execute();
                $insStmt->close();
            }
            $liked = true;
        }
    }

    $likeCount = 0;
    $countSql  = "SELECT COUNT(*) AS total FROM video_likes WHERE video_type = ? AND video_id = ?";
    if ($cntStmt = $conn_videos->prepare($countSql)) {
        $cntStmt->bind_param('si', $videoType, $videoId);
        $cntStmt->execute();
        $result = $cntStmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $likeCount = (int)($row['total'] ?? 0);
        }
        $cntStmt->close();
    }

    echo json_encode([
        'success'    => true,
        'liked'      => $liked,
        'like_count' => $likeCount,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'server_error',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

