<?php
/**
 * Shelf Price Tag Templates API - user/products/shelf_price_tags/api/templates.php
 */

require_once '../../../../config/config.php';
require_once '../../../../config/security.php';
require_once '../../../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if (!hasShelfPriceTagsAccess()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نیە'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_template':
            getTemplate($conn, $userId);
            break;
            
        case 'add_item':
            addItem($conn, $userId);
            break;
            
        case 'update_item':
            updateItem($conn, $userId);
            break;
            
        case 'delete_item':
            deleteItem($conn, $userId);
            break;
            
        case 'reorder_items':
            reorderItems($conn, $userId);
            break;
            
        case 'get_items':
            getItems($conn, $userId);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getTemplate($conn, $userId) {
    $templateId = (int)($_GET['template_id'] ?? 0);
    
    if (!$templateId) {
        throw new Exception('Template ID is required');
    }
    
    $stmt = $conn->prepare("
        SELECT * FROM shelf_price_tags 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $templateId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Template not found');
    }
    
    $template = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'data' => $template
    ]);
}

function getItems($conn, $userId) {
    $templateId = (int)($_GET['template_id'] ?? 0);
    
    if (!$templateId) {
        throw new Exception('Template ID is required');
    }
    
    // Verify template belongs to user
    $checkStmt = $conn->prepare("SELECT id FROM shelf_price_tags WHERE id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $templateId, $userId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows === 0) {
        throw new Exception('Template not found');
    }
    $checkStmt->close();
    
    $stmt = $conn->prepare("
        SELECT * FROM shelf_price_tag_items 
        WHERE template_id = ?
        ORDER BY display_order ASC, id ASC
    ");
    $stmt->bind_param("i", $templateId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $items
    ]);
}

function addItem($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $templateId = (int)($input['template_id'] ?? 0);
    $textContent = trim($input['text_content'] ?? '');
    $textType = $input['text_type'] ?? 'text';
    $fontSize = (int)($input['font_size'] ?? 12);
    $fontWeight = $input['font_weight'] ?? 'normal';
    $textAlign = $input['text_align'] ?? 'center';
    
    if (!$templateId) {
        throw new Exception('Template ID is required');
    }
    
    if (empty($textContent)) {
        throw new Exception('Text content is required');
    }
    
    // Verify template belongs to user
    $checkStmt = $conn->prepare("SELECT id FROM shelf_price_tags WHERE id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $templateId, $userId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows === 0) {
        throw new Exception('Template not found');
    }
    $checkStmt->close();
    
    // Get max display_order
    $orderStmt = $conn->prepare("SELECT MAX(display_order) as max_order FROM shelf_price_tag_items WHERE template_id = ?");
    $orderStmt->bind_param("i", $templateId);
    $orderStmt->execute();
    $orderResult = $orderStmt->get_result()->fetch_assoc();
    $newOrder = ($orderResult['max_order'] ?? 0) + 1;
    $orderStmt->close();
    
    $stmt = $conn->prepare("
        INSERT INTO shelf_price_tag_items 
        (template_id, text_content, text_type, font_size, font_weight, text_align, display_order) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ississi", $templateId, $textContent, $textType, $fontSize, $fontWeight, $textAlign, $newOrder);
    
    if ($stmt->execute()) {
        $itemId = $stmt->insert_id;
        echo json_encode([
            'success' => true,
            'message' => 'Item added successfully',
            'data' => [
                'id' => $itemId,
                'template_id' => $templateId,
                'text_content' => $textContent,
                'text_type' => $textType,
                'font_size' => $fontSize,
                'font_weight' => $fontWeight,
                'text_align' => $textAlign,
                'display_order' => $newOrder
            ]
        ]);
    } else {
        throw new Exception('Failed to add item');
    }
}

function updateItem($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $itemId = (int)($input['item_id'] ?? 0);
    $textContent = trim($input['text_content'] ?? '');
    $textType = $input['text_type'] ?? 'text';
    $fontSize = (int)($input['font_size'] ?? 12);
    $fontWeight = $input['font_weight'] ?? 'normal';
    $textAlign = $input['text_align'] ?? 'center';
    
    if (!$itemId) {
        throw new Exception('Item ID is required');
    }
    
    if (empty($textContent)) {
        throw new Exception('Text content is required');
    }
    
    // Verify item belongs to user's template
    $checkStmt = $conn->prepare("
        SELECT spti.id 
        FROM shelf_price_tag_items spti
        JOIN shelf_price_tags spt ON spti.template_id = spt.id
        WHERE spti.id = ? AND spt.user_id = ?
    ");
    $checkStmt->bind_param("ii", $itemId, $userId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows === 0) {
        throw new Exception('Item not found');
    }
    $checkStmt->close();
    
    $stmt = $conn->prepare("
        UPDATE shelf_price_tag_items 
        SET text_content = ?, text_type = ?, font_size = ?, font_weight = ?, text_align = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssissi", $textContent, $textType, $fontSize, $fontWeight, $textAlign, $itemId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Item updated successfully'
        ]);
    } else {
        throw new Exception('Failed to update item');
    }
}

function deleteItem($conn, $userId) {
    $itemId = (int)($_GET['item_id'] ?? 0);
    
    if (!$itemId) {
        throw new Exception('Item ID is required');
    }
    
    // Verify item belongs to user's template
    $checkStmt = $conn->prepare("
        SELECT spti.id 
        FROM shelf_price_tag_items spti
        JOIN shelf_price_tags spt ON spti.template_id = spt.id
        WHERE spti.id = ? AND spt.user_id = ?
    ");
    $checkStmt->bind_param("ii", $itemId, $userId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows === 0) {
        throw new Exception('Item not found');
    }
    $checkStmt->close();
    
    $stmt = $conn->prepare("DELETE FROM shelf_price_tag_items WHERE id = ?");
    $stmt->bind_param("i", $itemId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Item deleted successfully'
        ]);
    } else {
        throw new Exception('Failed to delete item');
    }
}

function reorderItems($conn, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $templateId = (int)($input['template_id'] ?? 0);
    $itemOrders = $input['item_orders'] ?? [];
    
    if (!$templateId) {
        throw new Exception('Template ID is required');
    }
    
    if (empty($itemOrders) || !is_array($itemOrders)) {
        throw new Exception('Item orders array is required');
    }
    
    // Verify template belongs to user
    $checkStmt = $conn->prepare("SELECT id FROM shelf_price_tags WHERE id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $templateId, $userId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows === 0) {
        throw new Exception('Template not found');
    }
    $checkStmt->close();
    
    // Update display_order for each item
    $stmt = $conn->prepare("UPDATE shelf_price_tag_items SET display_order = ? WHERE id = ? AND template_id = ?");
    
    foreach ($itemOrders as $order => $itemId) {
        $itemId = (int)$itemId;
        $displayOrder = (int)$order;
        
        $stmt->bind_param("iii", $displayOrder, $itemId, $templateId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to reorder items');
        }
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Items reordered successfully'
    ]);
}
