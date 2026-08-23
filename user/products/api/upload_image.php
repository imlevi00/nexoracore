<?php
/**
 * بارکردنی وێنەی کاڵا لە لیستی کاڵاکان
 */
require_once '../../../config/config.php';
require_once '../../../config/security.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';

if (!Security::validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە']);
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'داواکاری نادروست']);
    exit;
}

if (!isset($_FILES['product_image']) || $_FILES['product_image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'وێنە هەڵنەبژێردراوە']);
    exit;
}

$stmt = $conn->prepare('SELECT id, image_path FROM products WHERE id = ? AND user_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'هەڵە لە ئامادەکردنی داواکاری']);
    exit;
}

$stmt->bind_param('ii', $productId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$product = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'کاڵا نەدۆزرایەوە']);
    exit;
}

$oldImagePath = $product['image_path'] ?? null;

$uploadResult = upload_product_image_to_spaces($_FILES['product_image']);
if (!$uploadResult['success']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $uploadResult['errors'] ?? ['نەتوانرا وێنە بارکرێت'])
    ]);
    exit;
}

$newImagePath = $uploadResult['db_path'];

$updateStmt = $conn->prepare('UPDATE products SET image_path = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
if (!$updateStmt) {
    delete_product_image_from_spaces($newImagePath);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'هەڵە لە ئامادەکردنی داواکاری']);
    exit;
}

$updateStmt->bind_param('sii', $newImagePath, $productId, $userId);
if (!$updateStmt->execute()) {
    delete_product_image_from_spaces($newImagePath);
    $updateStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'هەڵە لە خەزنکردنی وێنە']);
    exit;
}
$updateStmt->close();

if ($oldImagePath) {
    delete_product_image_from_spaces($oldImagePath);
}

$imageUrl = product_image_url($newImagePath);

echo json_encode([
    'success' => true,
    'message' => 'وێنە نوێکرایەوە',
    'data' => [
        'image_path' => $newImagePath,
        'image_url' => $imageUrl
    ]
], JSON_UNESCAPED_UNICODE);
