<?php
/**
 * Get User Logo - user/settings/logo/get_user_logo.php
 * وەرگرتنی لینکی لۆگۆی بەکارهێنەر لە داتابەیسی kasher_media
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/security.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/product_images/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isUser()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'دەبێت داخڵ بیت'
    ]);
    exit;
}

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];

if ($conn_images === null) {
    echo json_encode([
        'success' => true,
        'data' => ['logo_url' => null]
    ]);
    exit;
}

$stmt = $conn_images->prepare("SELECT logo_url FROM user_logos WHERE user_id = ? LIMIT 1");
if (!$stmt) {
    echo json_encode([
        'success' => true,
        'data' => ['logo_url' => null]
    ]);
    exit;
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

$logoUrl = null;
if ($row && !empty($row['logo_url'])) {
    $logoUrl = $row['logo_url'];
    // ئەگەر ڕێگا ڕێژەییە (img/logos/...) URLی تەواو دروست بکە
    if (strpos($logoUrl, 'http') !== 0) {
        $logoUrl = rtrim(SITE_URL, '/') . '/' . ltrim($logoUrl, '/');
    }
}

echo json_encode([
    'success' => true,
    'data' => ['logo_url' => $logoUrl]
]);
