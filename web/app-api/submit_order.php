<?php
/**
 * ====================================================================
 * App API - Submit Order  (web/app-api/submit_order.php)
 * ====================================================================
 * ناردنی داواکاری لە بەرنامەکەوە بۆ خاوەن فرۆشگا.
 * داتاکە لە هەمان خشتەی web_orders خەزن دەکرێت کە سایتەکە بەکاری دەهێنێت،
 * بۆیە داواکارییەکانی بەرنامە لەگەڵ داواکارییەکانی سایت لە یەک شوێن دەردەکەون.
 *
 * POST submit_order.php   (Content-Type: application/json)
 * Body:
 * {
 *   "website_slug":   "SHOP_SLUG",           (پێویست)
 *   "customer_name":  "ناوی کڕیار",           (پێویست)
 *   "customer_phone": "07xxxxxxxxx",          (پێویست)
 *   "customer_address":"ناونیشان",            (ئارەزوومەندانە)
 *   "notes":          "تێبینی",               (ئارەزوومەندانە)
 *   "request_token":  "UUID یەکجارەیی",       (ئارەزوومەندانە — بۆ نەکردنی دووبارە)
 *   "total_amount":   12000,                  (ئارەزوومەندانە)
 *   "items": [
 *      { "product_id":1, "name":"...", "unit":"دانە", "unit_id":5,
 *        "price":1500, "quantity":2 }
 *   ]                                         (پێویست، لانیکەم یەک کاڵا)
 * }
 * ====================================================================
 */

require_once __DIR__ . '/_bootstrap.php';

app_api_require_method('POST');

$data = app_api_json_body();

// ------------------------------------------------------------------
// پشکنینی خانە پێویستەکان
// ------------------------------------------------------------------
foreach (['website_slug', 'customer_name', 'customer_phone', 'items'] as $field) {
    if (empty($data[$field])) {
        app_api_error("خانەی پێویست بەتاڵە: $field", 400, 'missing_field');
    }
}

$websiteSlug     = cleanInput((string)$data['website_slug']);
$customerName    = cleanInput((string)$data['customer_name']);
$customerPhone   = cleanInput((string)$data['customer_phone']);
$customerAddress = cleanInput((string)($data['customer_address'] ?? ''));
$notes           = cleanInput((string)($data['notes'] ?? ''));
$items           = $data['items'];
$totalAmount     = (float)($data['total_amount'] ?? 0);

// request_token بۆ نەهێشتنی داواکاری دووبارە — ئەگەر نەنێردرا، خۆکارانە دروستی دەکەین
$requestToken = isset($data['request_token']) ? trim((string)$data['request_token']) : '';
if ($requestToken === '') {
    $requestToken = 'app_' . bin2hex(random_bytes(12));
}

// ------------------------------------------------------------------
// پشکنینی کاڵاکان
// ------------------------------------------------------------------
if (!is_array($items) || count($items) === 0) {
    app_api_error('هیچ کاڵایەک دیاری نەکراوە.', 400, 'empty_items');
}

// پاککردنەوە + پشکنینی سادەی هەر کاڵایەک
$cleanItems = [];
$computedTotal = 0.0;
foreach ($items as $it) {
    if (!is_array($it) || empty($it['product_id']) || !isset($it['quantity'])) {
        app_api_error('پێکهاتەی کاڵاکان دروست نییە (product_id و quantity پێویستن).', 400, 'invalid_item');
    }
    $qty   = (float)$it['quantity'];
    $price = (float)($it['price'] ?? 0);
    if ($qty <= 0) {
        continue;
    }
    $cleanItems[] = [
        'product_id' => (int)$it['product_id'],
        'name'       => isset($it['name']) ? (string)$it['name'] : '',
        'unit'       => isset($it['unit']) ? (string)$it['unit'] : 'دانە',
        'unit_id'    => isset($it['unit_id']) ? (string)$it['unit_id'] : '',
        'price'      => $price,
        'quantity'   => $qty,
    ];
    $computedTotal += $price * $qty;
}
if (count($cleanItems) === 0) {
    app_api_error('هیچ کاڵایەکی دروست نییە لە داواکاریدا.', 400, 'empty_items');
}
// ئەگەر total_amount نەنێردرابێت، لە کاڵاکانەوە حیسابی دەکەین
if ($totalAmount <= 0) {
    $totalAmount = $computedTotal;
}

// ------------------------------------------------------------------
// دۆزینەوەی فرۆشگا + پشکنینی دەستڕاگەیشتن
// ------------------------------------------------------------------
$shop = app_api_get_shop_by_slug($conn, $websiteSlug);
if (!$shop) {
    app_api_error('فرۆشگاکە نەدۆزرایەوە یان ناچالاکە.', 404, 'shop_not_found');
}
$userId       = (int)$shop['user_id'];
$businessName = $shop['business_name'];

app_api_guard_shop_access($conn, $userId);

// سنووری توندتر بۆ ناردنی داواکاری — ڕێگری لە داواکاری درۆیین/سپام:
//   - لە هەر IP ـێک: زۆرترین ١٥ داواکاری لە کاتژمێرێکدا
//   - لە هەر مۆبایلێک: زۆرترین ٨ داواکاری لە کاتژمێرێکدا
app_api_rate_limit($conn, 'order_ip', 15, 3600);
$phoneDigits = preg_replace('/\D/', '', $customerPhone);
if ($phoneDigits !== '') {
    app_api_rate_limit($conn, 'order_phone', 8, 3600, $phoneDigits);
}

// ------------------------------------------------------------------
// نەهێشتنی داواکاری دووبارە (idempotency) بەپێی request_token
// ------------------------------------------------------------------
$dedupe = $conn->prepare("
    SELECT id, order_number
    FROM web_orders
    WHERE user_id = ? AND website_slug = ? AND customer_phone = ? AND request_token = ?
    LIMIT 1
");
$dedupe->bind_param('isss', $userId, $websiteSlug, $customerPhone, $requestToken);
$dedupe->execute();
$existing = $dedupe->get_result()->fetch_assoc();
$dedupe->close();

if ($existing) {
    $existingId = (int)$existing['id'];
    app_api_success([
        'order_number' => $existing['order_number'],
        'order_id'     => $existingId,
        'pdf_url'      => rtrim(SITE_URL, '/') . '/web/api/generate_order_pdf.php?order_id=' . $existingId,
        'duplicate'    => true,
    ], null, 200);
}

// ------------------------------------------------------------------
// دروستکردنی ژمارەی داواکاری یەکتا
// ------------------------------------------------------------------
$orderNumber = 'WO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
$check = $conn->prepare("SELECT id FROM web_orders WHERE order_number = ?");
$check->bind_param('s', $orderNumber);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $orderNumber .= '-' . rand(10, 99);
}
$check->close();

// ------------------------------------------------------------------
// خەزنکردنی داواکاری
// ------------------------------------------------------------------
$itemsJson = json_encode($cleanItems, JSON_UNESCAPED_UNICODE);

// guest fields — بەرنامەکە وەک میوان داواکاری دەنێرێت
$isGuest         = 1;
$guestSessionId  = 'app';
$guestIpAddress  = $_SERVER['REMOTE_ADDR'] ?? null;
$customerId      = null;

$ins = $conn->prepare("
    INSERT INTO web_orders
    (user_id, customer_id, guest_session_id, guest_ip_address, website_slug, order_number, request_token, customer_name, customer_phone, customer_address, items, total_amount, notes, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");
$ins->bind_param(
    'iisssssssssds',
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

if (!$ins->execute()) {
    app_api_error('نەتوانرا داواکارییەکە خەزن بکرێت. تکایە دووبارە هەوڵ بدەرەوە.', 500, 'db_error');
}
$orderId = (int)$conn->insert_id;
$ins->close();

// ------------------------------------------------------------------
// ئاگادارکردنەوەی تەلەگرام (ئەگەر ڕێکخرابێت)
// ------------------------------------------------------------------
$telegramHelper = __DIR__ . '/../../user/telegram/telegram_helper.php';
if (is_file($telegramHelper)) {
    try {
        require_once $telegramHelper;
        if (class_exists('TelegramHelper')) {
            $msg = TelegramHelper::buildWebOrderMessage(
                $orderNumber,
                $customerName,
                $customerPhone,
                $customerAddress,
                $cleanItems
            );
            TelegramHelper::notifyUser($userId, 'web_order', $msg);
        }
    } catch (Throwable $e) {
        error_log('App API telegram notify failed: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------------
// وەڵامی سەرکەوتوو
// ------------------------------------------------------------------
app_api_success([
    'order_number' => $orderNumber,
    'order_id'     => $orderId,
    'pdf_url'      => rtrim(SITE_URL, '/') . '/web/api/generate_order_pdf.php?order_id=' . $orderId,
    'duplicate'    => false,
], null, 201);
