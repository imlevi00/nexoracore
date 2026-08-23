<?php
/**
 * Save User Logo - user/settings/logo/save_user_logo.php
 * پاشەکەوتکردنی وێنەی لۆگۆ لەسەر DigitalOcean Spaces لە فۆڵدەری img/logos + داتابەیسی kasher_media
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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'پەیوەندی بە داتابەیسەکە نییە']);
    exit;
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$mimeMap = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp'
];
define('LOGO_MAX_SIZE_BYTES', 5 * 1024 * 1024); // 5MB

if (!isset($_FILES['logo_image']) || $_FILES['logo_image']['error'] !== UPLOAD_ERR_OK) {
    if (isset($_FILES['logo_image']) && $_FILES['logo_image']['error'] === UPLOAD_ERR_INI_SIZE) {
        echo json_encode(['success' => false, 'message' => 'قەبارەی فایل لە سنووری سێرڤەر زیاترە']);
    } else {
        echo json_encode(['success' => false, 'message' => 'هیچ وێنەیەک هەڵنەبژێردراوە یان هەڵەی بارکردن']);
    }
    exit;
}

$file = $_FILES['logo_image'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExtensions, true)) {
    echo json_encode(['success' => false, 'message' => 'جۆری فایل پەسەند نییە. تەنها JPG, PNG, GIF, WEBP']);
    exit;
}

if ($file['size'] > LOGO_MAX_SIZE_BYTES) {
    echo json_encode(['success' => false, 'message' => 'قەبارەی فایل گەورەیە. تکایە کەمتر لە ٥ مێگابایت']);
    exit;
}

$mimeType = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
}
if (empty($mimeType)) {
    $mimeType = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'image/jpeg';
}
if (strpos($mimeType, 'image/') !== 0) {
    echo json_encode(['success' => false, 'message' => 'تکایە تەنها وێنە هەڵبژێرە']);
    exit;
}

if (!function_exists('product_spaces_enabled') || !product_spaces_enabled()) {
    echo json_encode(['success' => false, 'message' => 'ڕێکخستنی Spaces تەواو نییە']);
    exit;
}

$objectKey = 'img/logos/user_' . $userId . '_' . time() . '.' . $ext;
$payload = spaces_optimized_image_upload_payload($file['tmp_name'], $file['name'] ?? '');
if ($payload['body'] === false) {
    echo json_encode(['success' => false, 'message' => 'نەتوانرا فایل بخوێنرێتەوە']);
    exit;
}

try {
    spaces_put_object($objectKey, $payload['body'], $payload['mime']);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'هەڵە لە بارکردنی وێنە بۆ DigitalOcean: ' . (strlen($e->getMessage()) > 200 ? substr($e->getMessage(), 0, 200) . '…' : $e->getMessage())
    ]);
    exit;
}

$logoUrl = spaces_public_url_for_object_key($objectKey);
if ($logoUrl === null) {
    echo json_encode(['success' => false, 'message' => 'ڕێکخستنی URL ی Spaces تەواو نییە']);
    exit;
}

$stmt = $conn_images->prepare("
    INSERT INTO user_logos (user_id, logo_url) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE logo_url = VALUES(logo_url), updated_at = NOW()
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'هەڵە لە پاشەکەوتکردنی داتا']);
    exit;
}
$stmt->bind_param('is', $userId, $logoUrl);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'هەڵە لە نووسینی داتابەیس']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'لۆگۆ بە سەرکەوتوویی پاشەکەوت کرا',
    'logo_url' => $logoUrl
]);
