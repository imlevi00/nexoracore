<?php
/**
 * Get A4 Receipt Settings - user/settings/get_a4_settings.php
 * وەرگرتنی ڕێکخستنەکانی وەسڵی A4
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

$currentUser = getCurrentUser();

function normalizeA4BannerUrl(?string $bannerPath): ?string {
    $bannerPath = trim((string)$bannerPath);
    if ($bannerPath === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $bannerPath)) {
        return $bannerPath;
    }

    $normalized = ltrim($bannerPath, '/');
    if (preg_match('/^a4_banner_[^\/]+\.(jpg|jpeg|png|gif|webp)$/i', $normalized)) {
        $normalized = 'img/receipts/a4_receipt_banner/' . $normalized;
    }
    if (strpos($normalized, 'img/receipts/a4_receipt_banner/') === 0) {
        return spaces_public_url_for_object_key($normalized);
    }
    return null;
}

try {
    // وەرگرتنی ڕێکخستنەکان لە بنکەی دراوە
    $stmt = $conn->prepare("
        SELECT a4_receipt_banner, a4_receipt_notes 
        FROM settings 
        WHERE user_id = ? 
        LIMIT 1
    ");
    $stmt->bind_param('i', $currentUser['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $settings = $result->fetch_assoc();
        $settings['a4_receipt_banner'] = normalizeA4BannerUrl($settings['a4_receipt_banner'] ?? null);
    } else {
        // ئەگەر ڕێکخستن نەبێت، دروستی بکە
        $insertStmt = $conn->prepare("
            INSERT INTO settings (user_id, a4_receipt_banner, a4_receipt_notes) 
            VALUES (?, NULL, NULL)
        ");
        $insertStmt->bind_param('i', $currentUser['id']);
        $insertStmt->execute();
        
        $settings = [
            'a4_receipt_banner' => null,
            'a4_receipt_notes' => null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $settings
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()
    ]);
}
?>

