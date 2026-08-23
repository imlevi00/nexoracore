<?php
/**
 * سڕینەوەی کاڵا - user/products/delete.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/product_change_logger.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$productsIndexBase = url('user/products/index.php');
$returnUrlInput = $_POST['return_url'] ?? ($_GET['return_url'] ?? $productsIndexBase);
$returnUrl = (strpos($returnUrlInput, $productsIndexBase) === 0) ? $returnUrlInput : $productsIndexBase;

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setMessage('درخواست نامعتبر', 'error');
    redirect($returnUrl);
}

// CSRF token validation
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setMessage('نادروستی ئامنیەتی', 'error');
    redirect($returnUrl);
}

$productId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if (!$productId) {
    setMessage('کاڵا نەدۆزرایەوە', 'error');
    redirect($returnUrl);
}

try {
    // Get product data first
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $productId, $userId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if (!$product) {
        setMessage('کاڵا نەدۆزرایەوە یان دەسەڵاتت نییە', 'error');
        redirect($returnUrl);
    }

    // Check if product has been sold (only check non-NULL product_id to exclude external products)
    $salesStmt = $conn->prepare("SELECT COUNT(*) as count FROM sale_items WHERE product_id = ? AND product_id IS NOT NULL");
    $salesStmt->bind_param("i", $productId);
    $salesStmt->execute();
    $salesCount = $salesStmt->get_result()->fetch_assoc()['count'];

    if ($salesCount > 0) {
        setMessage("ناتوانیت ئەم کاڵایە بسڕیتەوە چونکە {$salesCount} جار فرۆشراوە. تەنها دەتوانیت بەردەست بکەیت بە سفر", 'warning');
        redirect(url('user/products/edit.php?id=' . $productId . '&return_url=' . urlencode($returnUrl)));
    }

    // Start transaction
    $beforeSnapshot = getProductSnapshotForLogs($conn, $userId, $productId);
    $conn->begin_transaction();

    if ($product['image_path']) {
        delete_product_image_from_spaces($product['image_path']);
    }

    // Delete the product
    $deleteStmt = $conn->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
    $deleteStmt->bind_param("ii", $productId, $userId);

    if ($deleteStmt->execute()) {
        // Commit transaction
        $conn->commit();
        logProductChangeEvent(
            'product.delete',
            'product',
            $productId,
            $beforeSnapshot,
            null,
            [
                'user_id' => $userId,
                'current_user' => $currentUser,
                'product_id' => $productId,
                'source_module' => 'user/products/delete.php',
                'source_reference' => 'product_delete'
            ]
        );
        
        // Log activity
        writeLog("Product deleted: {$product['name']} (ID: $productId) by user: {$currentUser['email']}");
        
        setMessage("کاڵای '{$product['name']}' بە سەرکەوتوویی سڕایەوە", 'success');
    } else {
        $conn->rollback();
        setMessage('هەڵەیەک ڕوویدا لە سڕینەوەی کاڵا', 'error');
    }

} catch (Exception $e) {
    $conn->rollback();
    writeLog("Product deletion error: " . $e->getMessage());
    setMessage('هەڵەیەک ڕوویدا لە سڕینەوەی کاڵا', 'error');
}

redirect($returnUrl);
?>