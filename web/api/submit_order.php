<?php
/**
 * Submit Order API - web/api/submit_order.php
 * Handles order submission from the online shop
 */

header('Content-Type: application/json');

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../user/telegram/telegram_helper.php';
require_once '../auth/shop_google_access.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'شێوازی داواکاری نادروستە']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'داتای نادروست']);
    exit;
}

// Validate required fields
$required = ['website_slug', 'customer_name', 'customer_phone', 'items'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "خانەی پێویست بەتاڵە: $field"]);
        exit;
    }
}

$websiteSlug = cleanInput($data['website_slug']);
$customerName = cleanInput($data['customer_name']);
$customerPhone = cleanInput($data['customer_phone']);
$customerAddress = cleanInput($data['customer_address'] ?? '');
$items = $data['items'];
$totalAmount = floatval($data['total_amount'] ?? 0);
$notes = cleanInput($data['notes'] ?? '');
$customerId = isset($data['customer_id']) ? intval($data['customer_id']) : null;

// Request token for idempotency / duplicate protection
$requestToken = isset($data['request_token']) ? trim($data['request_token']) : '';
if ($requestToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'داتای نادروست: request_token بەتاڵە']);
    exit;
}

// Guest checkout data
$isGuest = isset($data['is_guest']) ? (bool)$data['is_guest'] : false;
$guestSessionId = $isGuest && isset($data['guest_session_id']) ? cleanInput($data['guest_session_id']) : null;
$guestIpAddress = $isGuest && isset($data['guest_ip_address']) ? cleanInput($data['guest_ip_address']) : null;

// Validate items
if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'هیچ کاڵایەک دیاری نەکراوە']);
    exit;
}

// Get website settings and user info
$stmt = $conn->prepare("
    SELECT ws.user_id, u.business_name, u.telegram_id, u.phone as shop_phone, u.address as shop_address
    FROM website_settings ws
    INNER JOIN users u ON ws.user_id = u.id
    WHERE ws.website_slug = ? AND ws.is_active = 1
");
$stmt->bind_param("s", $websiteSlug);
$stmt->execute();
$result = $stmt->get_result();
$websiteData = $result->fetch_assoc();
$stmt->close();

if (!$websiteData) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'فرۆشگاکە نەدۆزرایەوە یان ناچالاکە']);
    exit;
}

$userId = $websiteData['user_id'];
$businessName = $websiteData['business_name'];

shop_google_ensure_db_schema($conn);
$apiErr = shop_google_access_api_error($conn, $userId);
if ($apiErr) {
    http_response_code($apiErr['http']);
    echo json_encode(['success' => false, 'message' => $apiErr['message']]);
    exit;
}

// If request_token provided, check for existing order to avoid duplicates
$dedupeStmt = $conn->prepare("
    SELECT id, order_number 
    FROM web_orders 
    WHERE user_id = ? AND website_slug = ? AND customer_phone = ? AND request_token = ? 
    LIMIT 1
");
$dedupeStmt->bind_param("isss", $userId, $websiteSlug, $customerPhone, $requestToken);
$dedupeStmt->execute();
$dedupeResult = $dedupeStmt->get_result();
if ($existingOrder = $dedupeResult->fetch_assoc()) {
    $existingOrderId = (int)$existingOrder['id'];
    $existingOrderNumber = $existingOrder['order_number'];
    $dedupeStmt->close();

    $pdfUrl = SITE_URL . "web/api/generate_order_pdf.php?order_id=" . $existingOrderId;

    echo json_encode([
        'success' => true,
        'message' => 'داواکارییەکەت پێشتر تۆمار کراوە، هەمان وەسڵ دەگێڕدرێتەوە',
        'order_number' => $existingOrderNumber,
        'order_id' => $existingOrderId,
        'pdf_url' => $pdfUrl
    ]);
    exit;
}
$dedupeStmt->close();

// Generate unique order number
$orderNumber = 'WO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

// Check if order number exists (very unlikely, but just in case)
$checkStmt = $conn->prepare("SELECT id FROM web_orders WHERE order_number = ?");
$checkStmt->bind_param("s", $orderNumber);
$checkStmt->execute();
if ($checkStmt->get_result()->num_rows > 0) {
    $orderNumber .= '-' . rand(10, 99);
}
$checkStmt->close();

// Insert order into database
$itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
$insertStmt = $conn->prepare("
    INSERT INTO web_orders 
    (user_id, customer_id, guest_session_id, guest_ip_address, website_slug, order_number, request_token, customer_name, customer_phone, customer_address, items, total_amount, notes, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");
$insertStmt->bind_param(
    "iisssssssssds",
    $userId,
    $customerId,
    $guestSessionId,
    $guestIpAddress,
    $websiteSlug,
    $orderNumber,
    $requestToken,
    $customerName,
    $customerPhone,
    $customerAddress,
    $itemsJson,
    $totalAmount,
    $notes
);

if (!$insertStmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'نەتوانرا داواکارییەکە خەزن بکرێت']);
    exit;
}

$orderId = $conn->insert_id;
$insertStmt->close();

// Send Telegram notification if enabled and user has telegram_id
try {
    $webOrderMessage = TelegramHelper::buildWebOrderMessage(
        $orderNumber,
        $customerName,
        $customerPhone,
        $customerAddress,
        $items
    );
    TelegramHelper::notifyUser((int)$websiteData['user_id'], 'web_order', $webOrderMessage);
} catch (Exception $e) {
    error_log('Telegram web order notification failed: ' . $e->getMessage());
}

// Generate PDF receipt URL
$pdfUrl = SITE_URL . "web/api/generate_order_pdf.php?order_id=" . $orderId;

// Return success response
echo json_encode([
    'success' => true,
    'message' => 'داواکارییەکەت بە سەرکەوتوویی نێردرا',
    'order_number' => $orderNumber,
    'order_id' => $orderId,
    'pdf_url' => $pdfUrl
]);
?>

