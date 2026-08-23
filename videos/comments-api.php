<?php
// Simple JSON API for loading and creating video comments

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DB connection for kasher_z (same as google-login.php)
require_once __DIR__ . '/../config/kasher_zanyari/database.php';
/** @var mysqli $conn_zanyari */

/** @return never */
function json_response(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Ensure DB connection exists
if (empty($conn_zanyari) || !($conn_zanyari instanceof mysqli)) {
    json_response(500, [
        'ok'    => false,
        'error' => 'database_unavailable',
        'msg'   => 'پەیوەندی بە داتابەیس نەدۆزرایەوە.',
    ]);
}
assert($conn_zanyari instanceof mysqli);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? 'load';

// Basic input sanitation
function sanitize_video_type(?string $type): ?string
{
    if ($type === null) {
        return null;
    }
    $type = trim($type);
    if ($type === '') {
        return null;
    }
    // Limit length and allowed chars (letters, numbers, underscore)
    $type = substr($type, 0, 50);
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $type)) {
        return null;
    }
    return $type;
}

function sanitize_int_id($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $id = (int)$value;
    return $id > 0 ? $id : null;
}

// --- ACTION: LOAD COMMENTS ---
if ($action === 'load') {
    $videoType = sanitize_video_type($_GET['video_type'] ?? $_POST['video_type'] ?? null);
    $videoId   = sanitize_int_id($_GET['video_id'] ?? $_POST['video_id'] ?? null);

    if ($videoType === null || $videoId === null) {
        json_response(400, [
            'ok'    => false,
            'error' => 'invalid_params',
            'msg'   => 'زانیاری ڤیدیۆ دروست نییە.',
        ]);
    }

    $comments = [];

    $sql = "
        SELECT
            c.id,
            c.parent_id,
            c.comment_text,
            c.created_at,
            gu.name AS user_name,
            gu.picture AS user_picture
        FROM video_comments c
        INNER JOIN google_users gu ON gu.id = c.google_user_id
        WHERE
            c.video_type = ?
            AND c.video_id = ?
            AND c.is_deleted = 0
        ORDER BY c.created_at ASC, c.id ASC
    ";

    $stmt = $conn_zanyari->prepare($sql);
    if ($stmt === false) {
        json_response(500, [
            'ok'    => false,
            'error' => 'query_prepare_failed',
            'msg'   => 'نەتوانرا داتاکان بخوێندرێنەوە.',
        ]);
        return;
    }

    $stmt->bind_param('si', $videoType, $videoId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $comments[] = [
            'id'           => (int)$row['id'],
            'parent_id'    => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            'text'         => $row['comment_text'],
            'created_at'   => $row['created_at'],
            'user_name'    => $row['user_name'],
            'user_picture' => $row['user_picture'],
        ];
    }

    $stmt->close();

    json_response(200, [
        'ok'       => true,
        'comments' => $comments,
    ]);
}

// --- ACTION: CREATE COMMENT ---
if ($action === 'create') {
    if ($method !== 'POST') {
        json_response(405, [
            'ok'    => false,
            'error' => 'method_not_allowed',
            'msg'   => 'ڕێگە بە ئەم ڕێگایە نەدراوە.',
        ]);
    }

    $sessionUserId = !empty($_SESSION['google_user']['id'])
        ? (int)$_SESSION['google_user']['id']
        : null;

    if ($sessionUserId === null) {
        json_response(401, [
            'ok'    => false,
            'error' => 'not_authenticated',
            'msg'   => 'بەخێربێیت بۆ گووگڵ پێش نووسینی کۆمێنت.',
        ]);
    }

    $videoType  = sanitize_video_type($_POST['video_type'] ?? null);
    $videoId    = sanitize_int_id($_POST['video_id'] ?? null);
    $parentId   = sanitize_int_id($_POST['parent_id'] ?? null);
    $rawComment = $_POST['comment_text'] ?? '';
    $comment    = trim((string)$rawComment);

    if ($videoType === null || $videoId === null) {
        json_response(400, [
            'ok'    => false,
            'error' => 'invalid_params',
            'msg'   => 'زانیاری ڤیدیۆ دروست نییە.',
        ]);
    }

    if ($comment === '') {
        json_response(400, [
            'ok'    => false,
            'error' => 'empty_comment',
            'msg'   => 'ناتوانیت کۆمێنتی بەتاڵ بنێری.',
        ]);
    }

    // Limit length (e.g. 2000 characters)
    if (mb_strlen($comment, 'UTF-8') > 2000) {
        $comment = mb_substr($comment, 0, 2000, 'UTF-8');
    }

    // Optional: basic rate-limit per user could be added here

    $sql = "
        INSERT INTO video_comments (
            video_type,
            video_id,
            google_user_id,
            parent_id,
            comment_text
        ) VALUES (?, ?, ?, ?, ?)
    ";

    $stmt = $conn_zanyari->prepare($sql);
    if ($stmt === false) {
        json_response(500, [
            'ok'    => false,
            'error' => 'query_prepare_failed',
            'msg'   => 'نەتوانرا کۆمێنت تۆمار بکرێت.',
        ]);
        return;
    }

    // parent_id can be null
    if ($parentId === null) {
        $null = null;
        $stmt->bind_param('siibs', $videoType, $videoId, $sessionUserId, $null, $comment);
    } else {
        $stmt->bind_param('siibs', $videoType, $videoId, $sessionUserId, $parentId, $comment);
    }

    $ok = $stmt->execute();
    if (!$ok) {
        $stmt->close();
        json_response(500, [
            'ok'    => false,
            'error' => 'insert_failed',
            'msg'   => 'هەڵەیەک ڕوویدا لە کاتی ناردنی کۆمێنت.',
        ]);
    }

    $newId = (int)$stmt->insert_id;
    $stmt->close();

    // Fetch created_at and user display info
    $sql2 = "
        SELECT
            c.id,
            c.parent_id,
            c.comment_text,
            c.created_at,
            gu.name AS user_name,
            gu.picture AS user_picture
        FROM video_comments c
        INNER JOIN google_users gu ON gu.id = c.google_user_id
        WHERE c.id = ?
        LIMIT 1
    ";

    $stmt2 = $conn_zanyari->prepare($sql2);
    if ($stmt2 !== false) {
        $stmt2->bind_param('i', $newId);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $row2 = $res2 ? $res2->fetch_assoc() : null;
        $stmt2->close();
    } else {
        $row2 = null;
    }

    if ($row2) {
        $commentPayload = [
            'id'           => (int)$row2['id'],
            'parent_id'    => $row2['parent_id'] !== null ? (int)$row2['parent_id'] : null,
            'text'         => $row2['comment_text'],
            'created_at'   => $row2['created_at'],
            'user_name'    => $row2['user_name'],
            'user_picture' => $row2['user_picture'],
        ];
    } else {
        $commentPayload = [
            'id'           => $newId,
            'parent_id'    => $parentId,
            'text'         => $comment,
            'created_at'   => date('Y-m-d H:i:s'),
            'user_name'    => $_SESSION['google_user']['name'] ?? null,
            'user_picture' => $_SESSION['google_user']['picture'] ?? null,
        ];
    }

    json_response(201, [
        'ok'      => true,
        'comment' => $commentPayload,
    ]);
}

// Unknown action
json_response(400, [
    'ok'    => false,
    'error' => 'unknown_action',
    'msg'   => 'کردار نا شناوە.',
]);

