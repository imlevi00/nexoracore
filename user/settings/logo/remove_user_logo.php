<?php
/**
 * Remove User Logo - user/settings/logo/remove_user_logo.php
 * سڕینەوەی لۆگۆی بەکارهێنەر لە DigitalOcean Spaces و داتابەیسی kasher_media
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/security.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../config/product_images/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isUser()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'دەبێت داخڵ بیت']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'ڕێگەی داواکاری نادروست']);
    exit;
}

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];

if ($conn_images === null) {
    echo json_encode(['success' => false, 'message' => 'پەیوەندی بە داتابەیسەکە نییە']);
    exit;
}

$stmt = $conn_images->prepare("SELECT logo_url FROM user_logos WHERE user_id = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'هەڵە لە خوێندنەوەی داتا']);
    exit;
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row || empty($row['logo_url'])) {
    echo json_encode(['success' => true, 'message' => 'لۆگۆ نەدۆزرایەوە']);
    exit;
}

$logoUrl = $row['logo_url'];

spaces_delete_object_from_public_url($logoUrl);

$del = $conn_images->prepare("DELETE FROM user_logos WHERE user_id = ?");
if ($del) {
    $del->bind_param('i', $userId);
    $del->execute();
    $del->close();
}

echo json_encode([
    'success' => true,
    'message' => 'لۆگۆ سڕایەوە'
]);
