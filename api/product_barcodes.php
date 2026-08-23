<?php
/**
 * API بۆ بەڕێوەبردنی بارکۆدە زیادەکان
 * Multi-barcode management API
 */

require_once '../config/config.php';
require_once '../config/security.php';
require_once '../includes/functions.php';
require_once '../includes/permissions.php';

// تاقیکردنی دەسەڵات
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json; charset=utf-8');

$actionPermissionMap = [
    'list' => 'products.view',
    'check' => 'products.view',
    'add' => 'products.update',
    'delete' => 'products.delete'
];

enforceAuthorizationOrDeny($currentUser, $actionPermissionMap[$action] ?? 'products.view', [
    'route' => '/api/product_barcodes.php?action=' . $action,
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'json');

switch ($action) {
    case 'list':
        handleListBarcodes();
        break;
    
    case 'add':
        handleAddBarcode();
        break;
    
    case 'delete':
        handleDeleteBarcode();
        break;
    
    case 'check':
        handleCheckBarcode();
        break;
    
    default:
        sendJsonResponse(false, null, 'Action نادروستە', 400);
        break;
}

/**
 * لیستکردنی هەموو بارکۆدەکانی کاڵایەک
 */
function handleListBarcodes() {
    global $conn, $userId;
    
    $productId = (int)($_GET['product_id'] ?? 0);
    
    if (!$productId) {
        sendJsonResponse(false, null, 'Product ID پێویستە', 400);
    }
    
    // چێککردن کە کاڵاکە بە کەسی بەکارهێنەرەکەوە بێت
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $productId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        sendJsonResponse(false, null, 'کاڵا نەدۆزرایەوە', 404);
    }
    $stmt->close();
    
    // چێککردن کە کاڵاکە یەکەی هەبێت
    $stmt = $conn->prepare("SELECT COUNT(*) as unit_count FROM product_units WHERE product_id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $unitData = $result->fetch_assoc();
    $stmt->close();
    
    if ($unitData['unit_count'] == 0) {
        sendJsonResponse(false, null, 'بارکۆدی فرەکان تەنها بۆ کاڵای بەیەکە کار دەکات', 400);
    }
    
    // وەرگرتنی بارکۆدەکان
    $stmt = $conn->prepare("SELECT id, barcode, created_at FROM product_barcodes WHERE product_id = ? ORDER BY id");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $barcodes = [];
    while ($row = $result->fetch_assoc()) {
        $barcodes[] = [
            'id' => (int)$row['id'],
            'barcode' => $row['barcode'],
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    sendJsonResponse(true, ['barcodes' => $barcodes]);
}

/**
 * زیادکردنی بارکۆدی نوێ
 */
function handleAddBarcode() {
    global $conn, $userId;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, null, 'تەنها POST ڕێگەپێدراوە', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $productId = (int)($input['product_id'] ?? 0);
    $barcode = trim($input['barcode'] ?? '');
    
    if (!$productId) {
        sendJsonResponse(false, null, 'Product ID پێویستە', 400);
    }
    
    if (empty($barcode)) {
        sendJsonResponse(false, null, 'بارکۆد پێویستە', 400);
    }
    
    // چێککردن کە کاڵاکە بە کەسی بەکارهێنەرەکەوە بێت
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $productId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        sendJsonResponse(false, null, 'کاڵا نەدۆزرایەوە', 404);
    }
    $stmt->close();
    
    // چێککردن کە کاڵاکە یەکەی هەبێت
    $stmt = $conn->prepare("SELECT COUNT(*) as unit_count FROM product_units WHERE product_id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $unitData = $result->fetch_assoc();
    $stmt->close();
    
    if ($unitData['unit_count'] == 0) {
        sendJsonResponse(false, null, 'بارکۆدی فرەکان تەنها بۆ کاڵای بەیەکە کار دەکات', 400);
    }
    
    // چێککردنی duplicate - لە products table
    $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND user_id = ?");
    $stmt->bind_param("si", $barcode, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        sendJsonResponse(false, null, 'ئەم بارکۆدە پێشتر بەکارهاتووە', 400);
    }
    $stmt->close();
    
    // چێککردنی duplicate - لە product_barcodes table
    $stmt = $conn->prepare("SELECT pb.id FROM product_barcodes pb 
                            INNER JOIN products p ON pb.product_id = p.id 
                            WHERE pb.barcode = ? AND p.user_id = ?");
    $stmt->bind_param("si", $barcode, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        sendJsonResponse(false, null, 'ئەم بارکۆدە پێشتر بەکارهاتووە', 400);
    }
    $stmt->close();
    
    // زیادکردنی بارکۆد
    $stmt = $conn->prepare("INSERT INTO product_barcodes (product_id, barcode) VALUES (?, ?)");
    $stmt->bind_param("is", $productId, $barcode);
    
    if ($stmt->execute()) {
        $barcodeId = $conn->insert_id;
        $stmt->close();
        
        sendJsonResponse(true, [
            'id' => $barcodeId,
            'barcode' => $barcode,
            'message' => 'بارکۆد بە سەرکەوتوویی زیادکرا'
        ]);
    } else {
        $stmt->close();
        sendJsonResponse(false, null, 'هەڵەیەک ڕوویدا لە زیادکردنی بارکۆد', 500);
    }
}

/**
 * سڕینەوەی بارکۆد
 */
function handleDeleteBarcode() {
    global $conn, $userId;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, null, 'تەنها POST ڕێگەپێدراوە', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $barcodeId = (int)($input['barcode_id'] ?? 0);
    
    if (!$barcodeId) {
        sendJsonResponse(false, null, 'Barcode ID پێویستە', 400);
    }
    
    // چێککردن کە بارکۆدەکە بە کەسی بەکارهێنەرەکەوە بێت
    $stmt = $conn->prepare("SELECT pb.id FROM product_barcodes pb 
                            INNER JOIN products p ON pb.product_id = p.id 
                            WHERE pb.id = ? AND p.user_id = ?");
    $stmt->bind_param("ii", $barcodeId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        sendJsonResponse(false, null, 'بارکۆد نەدۆزرایەوە', 404);
    }
    $stmt->close();
    
    // سڕینەوەی بارکۆد
    $stmt = $conn->prepare("DELETE FROM product_barcodes WHERE id = ?");
    $stmt->bind_param("i", $barcodeId);
    
    if ($stmt->execute()) {
        $stmt->close();
        sendJsonResponse(true, ['message' => 'بارکۆد بە سەرکەوتوویی سڕایەوە']);
    } else {
        $stmt->close();
        sendJsonResponse(false, null, 'هەڵەیەک ڕوویدا لە سڕینەوەی بارکۆد', 500);
    }
}

/**
 * چێککردنی بارکۆد (بۆ duplicate check)
 */
function handleCheckBarcode() {
    global $conn, $userId;
    
    $barcode = trim($_GET['barcode'] ?? '');
    $productId = (int)($_GET['product_id'] ?? 0);
    
    if (empty($barcode)) {
        sendJsonResponse(false, null, 'بارکۆد پێویستە', 400);
    }
    
    // چێککردن لە products table
    $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND user_id = ?");
    $stmt->bind_param("si", $barcode, $userId);
    $stmt->execute();
    $existsInProducts = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    
    // چێککردن لە product_barcodes table
    $stmt = $conn->prepare("SELECT pb.id FROM product_barcodes pb 
                            INNER JOIN products p ON pb.product_id = p.id 
                            WHERE pb.barcode = ? AND p.user_id = ?");
    $stmt->bind_param("si", $barcode, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existsInBarcodes = false;
    $existingProductId = null;
    
    if ($row = $result->fetch_assoc()) {
        $existsInBarcodes = true;
        // Get product_id for this barcode
        $stmt2 = $conn->prepare("SELECT product_id FROM product_barcodes WHERE id = ?");
        $stmt2->bind_param("i", $row['id']);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        if ($row2 = $result2->fetch_assoc()) {
            $existingProductId = (int)$row2['product_id'];
        }
        $stmt2->close();
    }
    $stmt->close();
    
    $exists = $existsInProducts || $existsInBarcodes;
    $isSameProduct = $existsInBarcodes && $existingProductId == $productId;
    
    sendJsonResponse(true, [
        'exists' => $exists,
        'is_duplicate' => $exists && !$isSameProduct,
        'is_same_product' => $isSameProduct
    ]);
}

/**
 * ناردنی وەڵامی JSON
 */
function sendJsonResponse($success, $data = null, $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    if ($data !== null) {
        if (is_array($data)) {
            $response = array_merge($response, $data);
        } else {
            $response['data'] = $data;
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
