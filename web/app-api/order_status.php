<?php
/**
 * ====================================================================
 * App API - Order Status  (web/app-api/order_status.php)
 * ====================================================================
 * پشکنینی دۆخی داواکارییەک بۆ کڕیار (میوان) بەپێی ژمارەی داواکاری + مۆبایل.
 *
 * GET order_status.php?order_number=WO-...&phone=07xxxxxxxxx
 * ====================================================================
 */

require_once __DIR__ . '/_bootstrap.php';

app_api_require_method('GET');

$orderNumber = isset($_GET['order_number']) ? trim((string)$_GET['order_number']) : '';
$phone       = isset($_GET['phone']) ? trim((string)$_GET['phone']) : '';

if ($orderNumber === '' || $phone === '') {
    app_api_error('order_number و phone هەردووکیان پێویستن.', 400, 'missing_field');
}

$stmt = $conn->prepare("
    SELECT wo.order_number, wo.status, wo.total_amount, wo.items,
           wo.customer_name, wo.customer_phone, wo.customer_address,
           wo.notes, wo.website_slug, wo.created_at,
           u.business_name
    FROM web_orders wo
    INNER JOIN users u ON wo.user_id = u.id
    WHERE wo.order_number = ? AND wo.customer_phone = ?
    LIMIT 1
");
$stmt->bind_param('ss', $orderNumber, $phone);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    app_api_error('داواکارییەکە نەدۆزرایەوە. ژمارە یان مۆبایل هەڵەیە.', 404, 'order_not_found');
}

$items = json_decode($order['items'] ?? '[]', true);
if (!is_array($items)) {
    $items = [];
}

app_api_success([
    'order_number'     => $order['order_number'],
    'status'           => $order['status'],
    'total_amount'     => (float)$order['total_amount'],
    'customer_name'    => $order['customer_name'],
    'customer_phone'   => $order['customer_phone'],
    'customer_address' => $order['customer_address'],
    'notes'            => $order['notes'],
    'shop'             => [
        'slug'          => $order['website_slug'],
        'business_name' => $order['business_name'],
    ],
    'items'            => $items,
    'created_at'       => $order['created_at'],
    'pdf_url'          => rtrim(SITE_URL, '/') . '/web/api/generate_order_pdf.php?order_number=' . rawurlencode($order['order_number']),
]);
