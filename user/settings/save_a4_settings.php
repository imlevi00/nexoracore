<?php
/**
 * Save A4 Receipt Settings - user/settings/save_a4_settings.php
 * پاشەکەوتکردنی ڕێکخستنەکانی وەسڵی A4
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// تاقیکردنی داخڵبوون
if (!isUser()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'دەبێت داخڵ بیت'
    ]);
    exit;
}

// تاقیکردنی داواکاری POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'ڕێگەی داواکاری نادروست'
    ]);
    exit;
}

$currentUser = getCurrentUser();

try {
    $bannerUrl = null;
    
    // پرۆسێسکردنی وێنەی بانەر
    if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
        $fileExtension = strtolower(pathinfo($_FILES['banner_image']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            throw new Exception('جۆری فایل پەسەند نییە. تەنها JPG, PNG, GIF, WEBP پەسەند دەکرێت');
        }
        
        // پشکنینی قەبارەی فایل (5MB max)
        if ($_FILES['banner_image']['size'] > 5 * 1024 * 1024) {
            throw new Exception('قەبارەی فایل گەورەیە. تکایە فایلێکی بچووکتر هەڵبژێرە (کەمتر لە 5MB)');
        }
        
        // سڕینەوەی بانەری کۆن ئەگەر هەبوو (لە DO Spaces)
        $oldBannerStmt = $conn->prepare("SELECT a4_receipt_banner FROM settings WHERE user_id = ?");
        $oldBannerStmt->bind_param('i', $currentUser['id']);
        $oldBannerStmt->execute();
        $oldBannerResult = $oldBannerStmt->get_result();
        if ($oldBannerResult->num_rows > 0) {
            $oldBanner = $oldBannerResult->fetch_assoc()['a4_receipt_banner'];
            if (!empty($oldBanner)) {
                spaces_delete_object_from_public_url($oldBanner);
            }
        }
        $oldBannerStmt->close();

        if (!function_exists('product_spaces_enabled') || !product_spaces_enabled()) {
            throw new Exception('ڕێکخستنی Spaces تەواو نییە');
        }

        $mimeType = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['banner_image']['tmp_name']);
            finfo_close($finfo);
        }
        if ($mimeType === '' || strpos($mimeType, 'image/') !== 0) {
            $mimeType = $mimeMap[$fileExtension] ?? 'image/jpeg';
        }

        $payload = spaces_optimized_image_upload_payload($_FILES['banner_image']['tmp_name'], $_FILES['banner_image']['name'] ?? '');
        if ($payload['body'] === false) {
            throw new Exception('نەتوانرا فایل بخوێنرێتەوە');
        }

        $objectKey = 'img/receipts/a4_receipt_banner/a4_banner_' . $currentUser['id'] . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExtension;
        try {
            spaces_put_object($objectKey, $payload['body'], $payload['mime']);
        } catch (Throwable $e) {
            throw new Exception('هەڵە لە بارکردنی وێنە بۆ DigitalOcean: ' . $e->getMessage());
        }

        $bannerUrl = spaces_public_url_for_object_key($objectKey);
        if ($bannerUrl === null) {
            throw new Exception('ڕێکخستنی URL ی Spaces تەواو نییە');
        }
    }
    
    // وەرگرتنی تێبینەکان
    $notes = $_POST['notes'] ?? '';
    
    // پاشەکەوتکردن لە بنکەی دراوە
    // یەکەم پشکنین ئەگەر ڕێکخستن هەیە
    $checkStmt = $conn->prepare("SELECT id FROM settings WHERE user_id = ?");
    $checkStmt->bind_param('i', $currentUser['id']);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->num_rows > 0;
    
    if ($exists) {
        // نوێکردنەوە
        if ($bannerUrl) {
            $updateStmt = $conn->prepare("
                UPDATE settings 
                SET a4_receipt_banner = ?, a4_receipt_notes = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $updateStmt->bind_param('ssi', $bannerUrl, $notes, $currentUser['id']);
        } else {
            // تەنها تێبینەکان نوێ بکەرەوە
            $updateStmt = $conn->prepare("
                UPDATE settings 
                SET a4_receipt_notes = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $updateStmt->bind_param('si', $notes, $currentUser['id']);
        }
        $updateStmt->execute();
    } else {
        // دانان
        $insertStmt = $conn->prepare("
            INSERT INTO settings (user_id, a4_receipt_banner, a4_receipt_notes) 
            VALUES (?, ?, ?)
        ");
        $insertStmt->bind_param('iss', $currentUser['id'], $bannerUrl, $notes);
        $insertStmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'ڕێکخستنەکان بە سەرکەوتوویی پاشەکەوت کران'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

