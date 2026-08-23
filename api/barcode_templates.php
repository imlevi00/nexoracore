<?php
/**
 * API تێمپلێتەکانی بارکۆد - api/barcode_templates.php
 * هەر بەکارهێنەرێک دەتوانێت تا 10 تێمپلێت خەزن بکات
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/security.php';
require_once '../includes/permissions.php';

// Maximum templates per user
define('MAX_TEMPLATES_PER_USER', 10);

// Function to send JSON response
function sendResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Check if user is authenticated
if (!isUser()) {
    sendResponse(false, null, 'غیر مجاز - داخڵبوون پێویستە', 401);
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'] ?? null;

if (!$userId) {
    sendResponse(false, null, 'بەکارهێنەر نەدۆزرایەوە', 401);
}

// Get action from request
$action = $_GET['action'] ?? '';
$actionPermissionMap = [
    'list' => 'products.view',
    'get' => 'products.view',
    'get_default' => 'products.view',
    'save' => 'products.update',
    'update' => 'products.update',
    'set_default' => 'products.update',
    'delete' => 'products.delete'
];

enforceAuthorizationOrDeny($currentUser, $actionPermissionMap[$action] ?? 'products.view', [
    'route' => '/api/barcode_templates.php?action=' . $action,
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'json');

try {
    switch ($action) {
        case 'list':
            handleListTemplates();
            break;
            
        case 'get':
            handleGetTemplate();
            break;
            
        case 'save':
            handleSaveTemplate();
            break;
            
        case 'update':
            handleUpdateTemplate();
            break;
            
        case 'delete':
            handleDeleteTemplate();
            break;
            
        case 'set_default':
            handleSetDefaultTemplate();
            break;
            
        case 'get_default':
            handleGetDefaultTemplate();
            break;
            
        default:
            sendResponse(false, null, 'کردارێکی نادروست', 400);
    }
} catch (Exception $e) {
    error_log('Barcode Templates API Error: ' . $e->getMessage());
    sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
}

/**
 * Get count of user's templates
 */
function getUserTemplateCount() {
    global $conn, $userId;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM barcode_templates WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return (int)$row['count'];
}

/**
 * List all templates for current user
 */
function handleListTemplates() {
    global $conn, $userId;
    
    $stmt = $conn->prepare("
        SELECT id, name, is_default, created_at, updated_at 
        FROM barcode_templates 
        WHERE user_id = ? 
        ORDER BY is_default DESC, created_at DESC
    ");
    
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $templates = [];
    while ($row = $result->fetch_assoc()) {
        $templates[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'is_default' => (bool)$row['is_default'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
    $stmt->close();
    
    sendResponse(true, [
        'templates' => $templates,
        'count' => count($templates),
        'max_allowed' => MAX_TEMPLATES_PER_USER,
        'can_add_more' => count($templates) < MAX_TEMPLATES_PER_USER
    ]);
}

/**
 * Get a single template with settings
 */
function handleGetTemplate() {
    global $conn, $userId;
    
    $templateId = (int)($_GET['id'] ?? 0);
    
    if (!$templateId) {
        sendResponse(false, null, 'ID ی تێمپلێت پێویستە', 400);
    }
    
    $stmt = $conn->prepare("
        SELECT id, name, settings, is_default, created_at, updated_at 
        FROM barcode_templates 
        WHERE id = ? AND user_id = ?
    ");
    
    $stmt->bind_param('ii', $templateId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $template = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'settings' => json_decode($row['settings'], true),
            'is_default' => (bool)$row['is_default'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
        $stmt->close();
        sendResponse(true, ['template' => $template]);
    } else {
        $stmt->close();
        sendResponse(false, null, 'تێمپلێت نەدۆزرایەوە', 404);
    }
}

/**
 * Save a new template
 */
function handleSaveTemplate() {
    global $conn, $userId;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, null, 'تەنها POST ڕێگەپێدراوە', 405);
    }
    
    // Check if user has reached max templates
    $currentCount = getUserTemplateCount();
    if ($currentCount >= MAX_TEMPLATES_PER_USER) {
        sendResponse(false, null, 'تەنها ' . MAX_TEMPLATES_PER_USER . ' تێمپلێت دەتوانیت خەزن بکەیت. تکایە یەکێک بسڕەوە.', 400);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendResponse(false, null, 'داتای نادروست', 400);
    }
    
    // Validate required fields
    if (empty($input['name'])) {
        sendResponse(false, null, 'ناوی تێمپلێت پێویستە', 400);
    }
    
    if (empty($input['settings'])) {
        sendResponse(false, null, 'ڕێکخستنەکان پێویستە', 400);
    }
    
    $name = cleanInput($input['name']);
    $settings = json_encode($input['settings'], JSON_UNESCAPED_UNICODE);
    
    // Check if template name already exists for this user
    $checkStmt = $conn->prepare("SELECT id FROM barcode_templates WHERE user_id = ? AND name = ?");
    $checkStmt->bind_param('is', $userId, $name);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        $checkStmt->close();
        sendResponse(false, null, 'تێمپلێتێک بەم ناوە پێشتر هەیە', 400);
    }
    $checkStmt->close();
    
    // Check if this should be set as default
    $isDefault = isset($input['is_default']) && $input['is_default'] ? 1 : 0;
    
    // If setting as default, unset other defaults for this user
    if ($isDefault) {
        $unsetStmt = $conn->prepare("UPDATE barcode_templates SET is_default = 0 WHERE user_id = ?");
        $unsetStmt->bind_param('i', $userId);
        $unsetStmt->execute();
        $unsetStmt->close();
    }
    
    // Insert template
    $stmt = $conn->prepare("
        INSERT INTO barcode_templates (user_id, name, settings, is_default) 
        VALUES (?, ?, ?, ?)
    ");
    
    $stmt->bind_param('issi', $userId, $name, $settings, $isDefault);
    
    if ($stmt->execute()) {
        $templateId = $conn->insert_id;
        $stmt->close();
        sendResponse(true, ['template_id' => $templateId], 'تێمپلێت بە سەرکەوتوویی خەزنکرا');
    } else {
        $stmt->close();
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە خەزنکردنی تێمپلێت');
    }
}

/**
 * Update an existing template
 */
function handleUpdateTemplate() {
    global $conn, $userId;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, null, 'تەنها PUT یان POST ڕێگەپێدراوە', 405);
    }
    
    $templateId = (int)($_GET['id'] ?? 0);
    
    if (!$templateId) {
        sendResponse(false, null, 'ID ی تێمپلێت پێویستە', 400);
    }
    
    // Check if template belongs to user
    $checkStmt = $conn->prepare("SELECT id FROM barcode_templates WHERE id = ? AND user_id = ?");
    $checkStmt->bind_param('ii', $templateId, $userId);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        $checkStmt->close();
        sendResponse(false, null, 'تێمپلێت نەدۆزرایەوە یان دەسەڵاتت نییە', 404);
    }
    $checkStmt->close();
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendResponse(false, null, 'داتای نادروست', 400);
    }
    
    $updateFields = [];
    $params = [];
    $types = '';
    
    if (!empty($input['name'])) {
        $name = cleanInput($input['name']);
        
        // Check if new name conflicts with another template
        $nameCheckStmt = $conn->prepare("SELECT id FROM barcode_templates WHERE user_id = ? AND name = ? AND id != ?");
        $nameCheckStmt->bind_param('isi', $userId, $name, $templateId);
        $nameCheckStmt->execute();
        if ($nameCheckStmt->get_result()->num_rows > 0) {
            $nameCheckStmt->close();
            sendResponse(false, null, 'تێمپلێتێک بەم ناوە پێشتر هەیە', 400);
        }
        $nameCheckStmt->close();
        
        $updateFields[] = 'name = ?';
        $params[] = $name;
        $types .= 's';
    }
    
    if (!empty($input['settings'])) {
        $updateFields[] = 'settings = ?';
        $params[] = json_encode($input['settings'], JSON_UNESCAPED_UNICODE);
        $types .= 's';
    }
    
    if (isset($input['is_default'])) {
        $isDefault = $input['is_default'] ? 1 : 0;
        
        // If setting as default, unset other defaults for this user
        if ($isDefault) {
            $unsetStmt = $conn->prepare("UPDATE barcode_templates SET is_default = 0 WHERE user_id = ? AND id != ?");
            $unsetStmt->bind_param('ii', $userId, $templateId);
            $unsetStmt->execute();
            $unsetStmt->close();
        }
        
        $updateFields[] = 'is_default = ?';
        $params[] = $isDefault;
        $types .= 'i';
    }
    
    if (empty($updateFields)) {
        sendResponse(false, null, 'هیچ خانەیەک بۆ نوێکردنەوە دیارینەکراوە', 400);
    }
    
    $params[] = $templateId;
    $params[] = $userId;
    $types .= 'ii';
    
    $sql = "UPDATE barcode_templates SET " . implode(', ', $updateFields) . " WHERE id = ? AND user_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        $stmt->close();
        sendResponse(true, null, 'تێمپلێت بە سەرکەوتوویی نوێکرایەوە');
    } else {
        $stmt->close();
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە نوێکردنەوەی تێمپلێت');
    }
}

/**
 * Delete a template
 */
function handleDeleteTemplate() {
    global $conn, $userId;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, null, 'تەنها DELETE یان POST ڕێگەپێدراوە', 405);
    }
    
    $templateId = (int)($_GET['id'] ?? 0);
    
    if (!$templateId) {
        sendResponse(false, null, 'ID ی تێمپلێت پێویستە', 400);
    }
    
    // Check if template belongs to user
    $checkStmt = $conn->prepare("SELECT name FROM barcode_templates WHERE id = ? AND user_id = ?");
    $checkStmt->bind_param('ii', $templateId, $userId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $templateName = $row['name'];
        $checkStmt->close();
        
        $deleteStmt = $conn->prepare("DELETE FROM barcode_templates WHERE id = ? AND user_id = ?");
        $deleteStmt->bind_param('ii', $templateId, $userId);
        
        if ($deleteStmt->execute()) {
            $deleteStmt->close();
            sendResponse(true, null, 'تێمپلێت "' . $templateName . '" بە سەرکەوتوویی سڕایەوە');
        } else {
            $deleteStmt->close();
            sendResponse(false, null, 'هەڵەیەک ڕوویدا لە سڕینەوەی تێمپلێت');
        }
    } else {
        $checkStmt->close();
        sendResponse(false, null, 'تێمپلێت نەدۆزرایەوە یان دەسەڵاتت نییە', 404);
    }
}

/**
 * Set a template as default
 */
function handleSetDefaultTemplate() {
    global $conn, $userId;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
        sendResponse(false, null, 'تەنها POST یان PUT ڕێگەپێدراوە', 405);
    }
    
    $templateId = (int)($_GET['id'] ?? 0);
    
    if (!$templateId) {
        sendResponse(false, null, 'ID ی تێمپلێت پێویستە', 400);
    }
    
    // Check if template belongs to user
    $checkStmt = $conn->prepare("SELECT name FROM barcode_templates WHERE id = ? AND user_id = ?");
    $checkStmt->bind_param('ii', $templateId, $userId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        $checkStmt->close();
        sendResponse(false, null, 'تێمپلێت نەدۆزرایەوە یان دەسەڵاتت نییە', 404);
    }
    $checkStmt->close();
    
    // Unset all other defaults for this user
    $unsetStmt = $conn->prepare("UPDATE barcode_templates SET is_default = 0 WHERE user_id = ?");
    $unsetStmt->bind_param('i', $userId);
    $unsetStmt->execute();
    $unsetStmt->close();
    
    // Set this template as default
    $setStmt = $conn->prepare("UPDATE barcode_templates SET is_default = 1 WHERE id = ? AND user_id = ?");
    $setStmt->bind_param('ii', $templateId, $userId);
    
    if ($setStmt->execute()) {
        $setStmt->close();
        sendResponse(true, null, 'تێمپلێت بە سەرکەوتوویی وەک دیفۆڵت دیاریکرا');
    } else {
        $setStmt->close();
        sendResponse(false, null, 'هەڵەیەک ڕوویدا لە دیاریکردنی تێمپلێتی دیفۆڵت');
    }
}

/**
 * Get default template for current user
 */
function handleGetDefaultTemplate() {
    global $conn, $userId;
    
    $stmt = $conn->prepare("
        SELECT id, name, settings, is_default, created_at, updated_at 
        FROM barcode_templates 
        WHERE user_id = ? AND is_default = 1
        LIMIT 1
    ");
    
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $template = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'settings' => json_decode($row['settings'], true),
            'is_default' => (bool)$row['is_default'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
        $stmt->close();
        sendResponse(true, ['template' => $template]);
    } else {
        $stmt->close();
        sendResponse(false, null, 'هیچ تێمپلێتێکی دیفۆڵت نەدۆزرایەوە', 404);
    }
}

?>
