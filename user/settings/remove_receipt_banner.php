<?php
/**
 * Remove Receipt Banner - user/settings/remove_receipt_banner.php
 * سڕینەوەی وێنەی بانەری وەسڵی کاشێر
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
    // وەرگرتنی ناوی فایلی بانەری ئێستا
    $stmt = $conn->prepare("SELECT receipt_banner FROM settings WHERE user_id = ?");
    $stmt->bind_param('i', $currentUser['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $settings = $result->fetch_assoc();
        $bannerFile = $settings['receipt_banner'];
        
        // سڕینەوەی فایل لە Spaces (یان local بۆ داتای کۆن)
        if ($bannerFile) {
            spaces_delete_object_from_public_url($bannerFile);
            
            // سڕینەوە لە بنکەی دراوە
            $updateStmt = $conn->prepare("
                UPDATE settings 
                SET receipt_banner = NULL, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $updateStmt->bind_param('i', $currentUser['id']);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'وێنەی بانەر بە سەرکەوتوویی سڕایەوە'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'هەڵە لە سڕینەوەی وێنە: ' . $e->getMessage()
    ]);
}
?>

