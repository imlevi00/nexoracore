<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once dirname(__DIR__, 3) . '/config/product_images.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('نادروستی ئامنیەتی', 'danger');
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}

$session = cosmeticCenterSession();
$userId = (int)$session['user_id'];
$accountId = (int)$session['center_account_id'];
$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'header') {
    $header = trim((string)($_POST['receipt_header'] ?? ''));
    $upd = $conn_kasher_platform->prepare('UPDATE cosmetic_center_accounts SET receipt_header = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
    $upd->bind_param('sii', $header, $accountId, $userId);
    $ok = $upd->execute();
    $upd->close();
    setMessage($ok ? 'سەرپەڕە نوێکرایەوە' : 'نوێکردنەوە سەرکەوتوو نەبوو', $ok ? 'success' : 'danger');
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}

if ($action !== 'logo') {
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
$maxBytes = 5 * 1024 * 1024;

if (!isset($_FILES['logo_image']) || $_FILES['logo_image']['error'] !== UPLOAD_ERR_OK) {
    setMessage('هیچ وێنەیەکی دروست هەڵنەبژێردراوە', 'danger');
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}
$file = $_FILES['logo_image'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExtensions, true) || $file['size'] > $maxBytes) {
    setMessage('فایلی بانەر نادروستە', 'danger');
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}

$mimeType = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mimeType = (string)finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }
}
if ($mimeType === '' || strncmp($mimeType, 'image/', 6) !== 0) {
    $mimeType = $mimeMap[$ext] ?? 'image/jpeg';
}
if (!function_exists('product_spaces_enabled') || !product_spaces_enabled()) {
    setMessage('ڕێکخستنی Spaces تەواو نییە', 'warning');
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}

$objectKey = 'img/cosmetic_receipt_center/user_' . $userId . '_ctr_' . $accountId . '_' . time() . '.' . $ext;
$payload = spaces_optimized_image_upload_payload($file['tmp_name'], $file['name'] ?? '');
if ($payload['body'] === false) {
    setMessage('نەتوانرا فایل بخوێنرێتەوە', 'danger');
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}
try {
    spaces_put_object($objectKey, $payload['body'], $payload['mime']);
} catch (Throwable $e) {
    setMessage('هەڵە لە بارکردن', 'danger');
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}
$logoUrl = spaces_public_url_for_object_key($objectKey);
if ($logoUrl === null) {
    setMessage('ڕێکخستنی URL ی وێنە نادروستە', 'danger');
    redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
}

$upd = $conn_kasher_platform->prepare('UPDATE cosmetic_center_accounts SET receipt_logo_url = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
$upd->bind_param('sii', $logoUrl, $accountId, $userId);
$ok = $upd->execute();
$upd->close();
setMessage($ok ? 'بانەر پاشەکەوتکرا' : 'پاشەکەوتکردنی داتا سەرکەوتوو نەبوو', $ok ? 'success' : 'danger');
redirect(url('professions/cosmetic-center/center/receipt_branding.php'));
