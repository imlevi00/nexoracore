<?php
/**
 * Checkout Page - web/checkout.php
 * Handles order completion for online shop
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';
require_once 'auth/session_helper.php';
require_once 'auth/shop_google_access.php';
require_once 'includes/shop_whatsapp.php';

/**
 * Fetch shop owner contact info used for WhatsApp receipt delivery.
 */
function fetchShopWhatsAppMeta(mysqli $conn, string $shopSlug): ?array
{
    if ($shopSlug === '' || $shopSlug === 'unknown' || !preg_match('/^[a-zA-Z0-9_-]+$/', $shopSlug)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT ws.enable_whatsapp_order, u.phone AS shop_phone, u.business_name
        FROM website_settings ws
        INNER JOIN users u ON ws.user_id = u.id
        WHERE ws.website_slug = ? AND ws.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('s', $shopSlug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function shopWhatsAppMetaExists(?array $meta): bool
{
    return is_array($meta);
}

function shopWhatsAppOrderEnabled(?array $meta): bool
{
    return shopWhatsAppMetaExists($meta) && (int) ($meta['enable_whatsapp_order'] ?? 0) === 1;
}

function shopWhatsAppPhone(?array $meta): string
{
    return is_array($meta) && !empty($meta['shop_phone']) ? (string) $meta['shop_phone'] : '';
}

function shopWhatsAppBusinessName(?array $meta, string $fallback): string
{
    if (!is_array($meta)) {
        return $fallback;
    }

    $name = trim((string) ($meta['business_name'] ?? ''));
    return $name !== '' ? $name : $fallback;
}

// Lightweight lookup for checkout form WhatsApp availability hints
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['action'])
    && $_GET['action'] === 'whatsapp_status'
) {
    header('Content-Type: application/json; charset=utf-8');
    $rawSlugs = sanitizeInput($_GET['slugs'] ?? '');
    $slugList = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawSlugs)))));
    $shops = [];

    foreach ($slugList as $slug) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            continue;
        }
        $meta = fetchShopWhatsAppMeta($conn, $slug);
        $exists = shopWhatsAppMetaExists($meta);
        $enabled = shopWhatsAppOrderEnabled($meta);
        $hasPhone = shopWhatsAppPhone($meta) !== '';
        $shopEntry = [
            'exists' => $exists,
            'enabled' => $enabled,
            'available' => $enabled && $hasPhone,
            'business_name' => shopWhatsAppBusinessName($meta, $slug),
        ];
        if ($enabled && $hasPhone) {
            $shopEntry['shop_phone'] = shopWhatsAppPhone($meta);
        }
        $shops[$slug] = $shopEntry;
    }

    echo json_encode(['success' => true, 'shops' => $shops], JSON_UNESCAPED_UNICODE);
    exit;
}

// Start session for both logged in and guest users
CustomerSession::start();

shop_google_ensure_db_schema($conn);
shop_whatsapp_ensure_column($conn);

// Check if user is logged in or guest
$isGuest = CustomerSession::isGuest();
$customerData = null;
$guestData = null;

if ($isGuest) {
    // Guest user - get guest data
    $guestData = CustomerSession::getGuestData();
} else {
    // Logged in customer - get customer data
    $customerData = CustomerSession::getCustomerData();
}

$error = '';
$success = '';
$checkoutOrderNumbers = [];
$isCheckoutSuccess = false;
$shopSlug = sanitizeInput($_GET['shop'] ?? '');
$checkoutShopWhatsAppMeta = $shopSlug !== '' ? fetchShopWhatsAppMeta($conn, $shopSlug) : null;
$checkoutShopWhatsAppEnabled = shopWhatsAppOrderEnabled($checkoutShopWhatsAppMeta);
$checkoutShopWhatsAppAvailable = $checkoutShopWhatsAppEnabled && shopWhatsAppPhone($checkoutShopWhatsAppMeta) !== '';
$checkoutShopWhatsAppMissingPhoneNotice = $shopSlug !== '' && $checkoutShopWhatsAppEnabled && !$checkoutShopWhatsAppAvailable;
$showWhatsAppCheckoutNotice = $checkoutShopWhatsAppMissingPhoneNotice;

if ($shopSlug !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $shopSlug)) {
    $chkWs = $conn->prepare('SELECT * FROM website_settings WHERE website_slug = ? AND is_active = 1');
    $chkWs->bind_param('s', $shopSlug);
    $chkWs->execute();
    $wsCheckout = $chkWs->get_result()->fetch_assoc();
    $chkWs->close();
    if ($wsCheckout) {
        shop_google_access_guard($conn, $shopSlug, $wsCheckout);
        $isGuest = CustomerSession::isGuest();
        if ($isGuest) {
            $guestData = CustomerSession::getGuestData();
        } else {
            $customerData = CustomerSession::getCustomerData();
        }
    }
}

// Handle success redirect (POST/Redirect/GET pattern)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status']) && $_GET['status'] === 'success' && isset($_SESSION['checkout_success'])) {
    $checkoutSuccess = $_SESSION['checkout_success'];
    unset($_SESSION['checkout_success']);

    if (is_array($checkoutSuccess)) {
        $success = $checkoutSuccess['message'] ?? 'داواکارییەکەت بە سەرکەوتوویی نێردرا';
        $checkoutOrderNumbers = is_array($checkoutSuccess['order_numbers'] ?? null)
            ? array_values(array_filter($checkoutSuccess['order_numbers']))
            : [];
    } else {
        $success = 'داواکارییەکەت بە سەرکەوتوویی نێردرا';
    }
}

$isCheckoutSuccess = $success !== '';

// Generate or ensure request token for checkout form (for duplicate protection)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (empty($_SESSION['checkout_request_token'])) {
        try {
            $_SESSION['checkout_request_token'] = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $_SESSION['checkout_request_token'] = uniqid('chk_', true);
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate request token to prevent invalid/duplicate submissions
    $requestToken = $_POST['request_token'] ?? '';
    $sessionToken = $_SESSION['checkout_request_token'] ?? '';

    if (empty($requestToken) || empty($sessionToken) || !hash_equals($sessionToken, $requestToken)) {
        $error = 'داواکاری نادروستە، تکایە لاپەڕەکە دووبارە بکەرەوە و هەوڵبدەوە.';
    } else {
        $customerName = sanitizeInput($_POST['customer_name'] ?? '');
        $customerPhone = sanitizeInput($_POST['customer_phone'] ?? '');
        $customerAddress = sanitizeInput($_POST['customer_address'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        // Validate required fields
        if (empty($customerName)) {
            $error = 'ناوی کڕیار پێویستە';
        } elseif (empty($customerPhone)) {
            $error = 'ژمارەی تەلەفۆن پێویستە';
        } elseif (!preg_match('/^[0-9]{11}$/', $customerPhone)) {
            $error = 'ژمارەی تەلەفۆن دەبێت ١١ ژمارە بێت بە ئینگلیزی';
        } else {
            // Get cart data from session or localStorage (we'll use a simple approach)
            $cartData = $_POST['cart_data'] ?? '';
            
            if (empty($cartData)) {
                $error = 'سەبەتەکەت بەتاڵە';
            } else {
                $items = json_decode($cartData, true);
                
                if (!$items || count($items) === 0) {
                    $error = 'سەبەتەکەت بەتاڵە';
                } else {
                    // Group items by website_slug
                    $itemsByShop = [];
                    foreach ($items as $item) {
                        $shopSlug = $item['website_slug'] ?? '';
                        // If no website_slug, try to get from product's user_id
                        if (empty($shopSlug) && !empty($item['id'])) {
                            // Try to get shop slug from product
                            $productStmt = $conn->prepare("
                            SELECT ws.website_slug 
                            FROM products p
                            INNER JOIN website_settings ws ON p.user_id = ws.user_id
                            WHERE p.id = ? AND ws.is_active = 1
                            LIMIT 1
                        ");
                            $productStmt->bind_param("i", $item['id']);
                            $productStmt->execute();
                            $productResult = $productStmt->get_result();
                            if ($productRow = $productResult->fetch_assoc()) {
                                $shopSlug = $productRow['website_slug'];
                            }
                            $productStmt->close();
                        }
                        
                        // Fallback to shop parameter from URL if still empty
                        if (empty($shopSlug)) {
                            $shopSlug = sanitizeInput($_GET['shop'] ?? '');
                        }
                        
                        if (empty($shopSlug)) {
                            $shopSlug = 'unknown';
                        }
                        
                        if (!isset($itemsByShop[$shopSlug])) {
                            $itemsByShop[$shopSlug] = [];
                        }
                        $itemsByShop[$shopSlug][] = $item;
                    }

                    if (empty($itemsByShop)) {
                        $error = 'هیچ کاڵایەک دیاری نەکراوە';
                    } else {
                        // Prepare guest session data if guest
                        $guestSessionId = null;
                        $guestIpAddress = null;
                        if ($isGuest) {
                            $guestSessionId = CustomerSession::getOrCreateGuestSessionId();
                            $guestIpAddress = CustomerSession::getClientIp();
                        }

                        // Submit orders for each shop
                        $orderResults = [];
                        $allSuccess = true;
                        $orderNumbers = [];

                        // Same PHP session ID in curl would block: submit_order.php waits for this
                        // request's session lock. Release lock before internal HTTP call, then reopen.
                        $sessionCookieHeader = '';
                        if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
                            $sessionCookieHeader = session_name() . '=' . session_id();
                        }
                        session_write_close();

                        foreach ($itemsByShop as $shopSlug => $shopItems) {
                            // Calculate total for this shop
                            $shopTotal = 0;
                            foreach ($shopItems as $item) {
                                $shopTotal += $item['price'] * $item['quantity'];
                            }

                            // Prepare order data
                            $orderData = [
                                'website_slug' => $shopSlug,
                                'customer_name' => $customerName,
                                'customer_phone' => $customerPhone,
                                'customer_address' => $customerAddress,
                                'items' => $shopItems,
                                'total_amount' => $shopTotal,
                                'notes' => $notes,
                                'customer_id' => $isGuest ? null : $customerData['id'],
                                'is_guest' => $isGuest,
                                'request_token' => $requestToken,
                            ];
                            
                            // Add guest session data if guest
                            if ($isGuest) {
                                $orderData['guest_session_id'] = $guestSessionId;
                                $orderData['guest_ip_address'] = $guestIpAddress;
                            }
                            
                            // Send to API
                            $payload = json_encode($orderData);
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, SITE_URL . 'web/api/submit_order.php');
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                            // Forward session cookie so submit_order.php sees google_user (shop_google_restrict).
                            $apiHeaders = [
                                'Content-Type: application/json',
                                'Content-Length: ' . strlen($payload),
                            ];
                            if ($sessionCookieHeader !== '') {
                                $apiHeaders[] = 'Cookie: ' . $sessionCookieHeader;
                            }
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $apiHeaders);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            
                            $response = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($httpCode === 200) {
                                $result = json_decode($response, true);
                                if ($result && $result['success']) {
                                    $orderResults[$shopSlug] = [
                                        'success' => true,
                                        'order_number' => $result['order_number'] ?? '',
                                        'message' => $result['message'] ?? ''
                                    ];
                                    if (!empty($result['order_number'])) {
                                        $orderNumbers[] = $result['order_number'];
                                    }
                                } else {
                                    $orderResults[$shopSlug] = [
                                        'success' => false,
                                        'message' => $result['message'] ?? 'هەڵەیەک ڕوویدا'
                                    ];
                                    $allSuccess = false;
                                }
                            } else {
                                $orderResults[$shopSlug] = [
                                    'success' => false,
                                    'message' => 'هەڵەیەک ڕوویدا لە ناردنی داواکاری'
                                ];
                                $allSuccess = false;
                            }
                        }

                        CustomerSession::start();

                        // Handle results
                        if ($allSuccess) {
                            $shopCount = count($itemsByShop);
                            if ($shopCount > 1) {
                                $message = 'داواکارییەکانت بە سەرکەوتوویی نێردران (' . $shopCount . ' وەسڵ)';
                            } else {
                                $message = 'داواکارییەکەت بە سەرکەوتوویی نێردرا';
                            }
                            
                            // Store order numbers for guest users
                            if ($isGuest && !empty($orderNumbers)) {
                                $_SESSION['last_order_numbers'] = $orderNumbers;
                                $_SESSION['last_order_number'] = $orderNumbers[0]; // For backward compatibility
                            }
                            
                            // Fetch shop owner phones for WhatsApp receipt
                            $shopInfo = [];
                            $shopsMissingPhone = [];
                            $shopsWhatsAppDisabled = [];
                            $shopsWhatsAppNotFound = [];
                            foreach (array_keys($itemsByShop) as $orderShopSlug) {
                                if ($orderShopSlug === 'unknown' || $orderShopSlug === '') {
                                    $shopsMissingPhone[] = 'فرۆشگای نادیار';
                                    continue;
                                }

                                $shopRow = fetchShopWhatsAppMeta($conn, $orderShopSlug);
                                $shopLabel = shopWhatsAppBusinessName($shopRow, $orderShopSlug);

                                if (!shopWhatsAppMetaExists($shopRow)) {
                                    $shopsWhatsAppNotFound[] = $shopLabel;
                                    continue;
                                }

                                if (!shopWhatsAppOrderEnabled($shopRow)) {
                                    continue;
                                }

                                $shopPhone = shopWhatsAppPhone($shopRow);
                                if ($shopPhone !== '') {
                                    $shopInfo[$orderShopSlug] = [
                                        'shop_phone' => $shopPhone,
                                        'business_name' => shopWhatsAppBusinessName($shopRow, ''),
                                    ];
                                } else {
                                    $shopsMissingPhone[] = $shopLabel;
                                }
                            }

                            // Store order details for WhatsApp receipt
                            $_SESSION['whatsapp_receipt_data'] = [
                                'customer_name' => $customerName,
                                'customer_phone' => $customerPhone,
                                'customer_address' => $customerAddress,
                                'order_numbers' => $orderNumbers,
                                'order_results' => $orderResults,
                                'items_by_shop' => $itemsByShop,
                                'shop_info' => $shopInfo,
                                'shops_missing_phone' => $shopsMissingPhone,
                                'shops_whatsapp_disabled' => $shopsWhatsAppDisabled,
                                'shops_whatsapp_not_found' => $shopsWhatsAppNotFound,
                                'whatsapp_available' => !empty($shopInfo),
                                'notes' => $notes
                            ];

                            // Persist checkout success data for redirect
                            $_SESSION['checkout_success'] = [
                                'message' => $message,
                                'order_numbers' => $orderNumbers,
                                'order_results' => $orderResults,
                                'items_by_shop' => $itemsByShop,
                                'is_guest' => $isGuest,
                                'shop_slug' => $shopSlug,
                            ];

                            // Invalidate the request token after successful submission
                            unset($_SESSION['checkout_request_token']);

                            // Redirect to GET to prevent duplicate form submissions (PRG pattern)
                            $redirectUrl = SITE_URL . 'web/checkout.php';
                            $redirectParams = [];
                            if (!empty($shopSlug)) {
                                $redirectParams[] = 'shop=' . urlencode($shopSlug);
                            }
                            $redirectParams[] = 'status=success';
                            if (!empty($redirectParams)) {
                                $redirectUrl .= '?' . implode('&', $redirectParams);
                            }
                            header('Location: ' . $redirectUrl);
                            exit;
                        } else {
                            // Some orders failed
                            $errorMessages = [];
                            foreach ($orderResults as $shopSlug => $result) {
                                if (!$result['success']) {
                                    $errorMessages[] = 'فرۆشگای ' . $shopSlug . ': ' . $result['message'];
                                }
                            }
                            $error = 'هەندێک داواکاری سەرکەوتوو نەبوو: ' . implode('; ', $errorMessages);
                        }
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تەواوکردنی داواکاری - فرۆشگای ئۆنلاین</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="template/assets/css/shop.css" rel="stylesheet">
    <link href="template/assets/css/cart.css?v=<?php echo time(); ?>" rel="stylesheet">
    
    <style>
        .checkout-container {
            min-height: 100vh;
            background: #f8f9fa;
        }
        
        .checkout-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .checkout-header {
            background: var(--brand-header-gradient, linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%));
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .order-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .order-total {
            background: #28a745;
            color: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            font-size: 1.2rem;
            font-weight: bold;
            margin-top: 1rem;
        }
        
        .shop-group {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .shop-group-header {
            background: var(--brand-header-gradient, linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%));
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: bold;
        }
        
        .shop-group-total {
            background: #f8f9fa;
            padding: 0.75rem;
            border-radius: 6px;
            margin-top: 0.75rem;
            text-align: left;
            font-weight: bold;
            border-top: 2px solid #dee2e6;
        }
        
        .grand-total {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 1.25rem;
            border-radius: 10px;
            text-align: center;
            font-size: 1.3rem;
            font-weight: bold;
            margin-top: 1.5rem;
        }
        
        #sendWhatsAppBtn {
            background: #25D366;
            border-color: #25D366;
            color: white;
        }
        
        #sendWhatsAppBtn:hover {
            background: #128C7E;
            border-color: #128C7E;
        }

        /* Checkout success state */
        .checkout-card--success {
            border-radius: var(--border-radius-2xl, 24px);
            box-shadow: 0 12px 40px rgba(5, 150, 105, 0.12);
        }

        .checkout-header--success {
            background: linear-gradient(135deg, #059669 0%, #047857 50%, #065f46 100%);
            padding: 2.25rem 2rem;
        }

        .checkout-header--success h2 {
            font-size: clamp(1.35rem, 3.5vw, 1.75rem);
            font-weight: 700;
        }

        .checkout-header--success p {
            opacity: 0.92;
            font-size: 1rem;
        }

        .checkout-success-state {
            text-align: center;
        }

        .checkout-success-hero {
            background: linear-gradient(160deg, #ecfdf5 0%, #d1fae5 55%, #a7f3d0 100%);
            border-radius: var(--border-radius-xl, 18px);
            padding: 2.5rem 1.5rem 2rem;
            margin-bottom: 1.75rem;
        }

        .success-icon-ring {
            width: 112px;
            height: 112px;
            margin: 0 auto 1.5rem;
            border-radius: 50%;
            background: var(--white, #ffffff);
            box-shadow: 0 8px 32px rgba(5, 150, 105, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            animation: successScaleIn 0.55s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        .success-icon-ring svg {
            width: 64px;
            height: 64px;
        }

        .success-check-circle {
            fill: none;
            stroke: #059669;
            stroke-width: 3;
            stroke-linecap: round;
            opacity: 0.25;
        }

        .success-check-mark {
            fill: none;
            stroke: #059669;
            stroke-width: 3.5;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-dasharray: 48;
            stroke-dashoffset: 48;
            animation: successCheckDraw 0.55s ease-out 0.35s forwards;
        }

        @keyframes successScaleIn {
            0% {
                opacity: 0;
                transform: scale(0.4);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes successCheckDraw {
            to {
                stroke-dashoffset: 0;
            }
        }

        .success-title {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 700;
            color: #065f46;
            margin-bottom: 0.75rem;
        }

        .success-message {
            font-size: 1.05rem;
            color: #047857;
            margin-bottom: 0;
            line-height: 1.6;
        }

        .success-order-numbers {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.25rem;
        }

        .success-order-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(255, 255, 255, 0.85);
            color: #065f46;
            border: 1px solid rgba(5, 150, 105, 0.25);
            border-radius: var(--border-radius-full, 9999px);
            padding: 0.45rem 1rem;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.08);
        }

        .success-order-badge i {
            color: #059669;
        }

        .checkout-success-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .checkout-success-actions .btn {
            border-radius: var(--border-radius-full, 9999px);
            padding: 0.6rem 1.25rem;
            font-weight: 600;
        }

        .checkout-success-warnings {
            text-align: right;
            max-width: 100%;
        }

        html[data-bs-theme='dark'] .checkout-container {
            background: var(--light-bg, #0f172a);
        }

        html[data-bs-theme='dark'] .checkout-card {
            background: var(--gray-100, #1f2937);
        }

        html[data-bs-theme='dark'] .checkout-success-hero {
            background: linear-gradient(160deg, #064e3b 0%, #065f46 50%, #047857 100%);
        }

        html[data-bs-theme='dark'] .success-icon-ring {
            background: var(--gray-100, #1f2937);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
        }

        html[data-bs-theme='dark'] .success-title {
            color: #a7f3d0;
        }

        html[data-bs-theme='dark'] .success-message {
            color: #d1fae5;
        }

        html[data-bs-theme='dark'] .success-order-badge {
            background: rgba(17, 24, 39, 0.7);
            color: #a7f3d0;
            border-color: rgba(167, 243, 208, 0.25);
        }

        @media (max-width: 768px) {
            .checkout-success-hero {
                padding: 2rem 1rem 1.5rem;
            }

            .success-icon-ring {
                width: 96px;
                height: 96px;
            }

            .success-icon-ring svg {
                width: 54px;
                height: 54px;
            }

            .checkout-success-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .checkout-success-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body class="checkout-container">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>web/<?php echo $shopSlug ? htmlspecialchars($shopSlug) . '/' : ''; ?>">
                <i class="bi bi-arrow-right"></i>
                گەڕانەوە بۆ فرۆشگا
            </a>

            <div class="navbar-nav ms-auto">
                <?php if ($isGuest): ?>
                    <span class="navbar-text">
                        <i class="bi bi-person"></i>
                        کڕینی میوان
                    </span>
                <?php else: ?>
                    <span class="navbar-text">
                        <i class="bi bi-person-circle"></i>
                        <?php echo htmlspecialchars($customerData['name']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="checkout-card<?php echo $isCheckoutSuccess ? ' checkout-card--success' : ''; ?>">
                    <div class="checkout-header<?php echo $isCheckoutSuccess ? ' checkout-header--success' : ''; ?>">
                        <?php if ($isCheckoutSuccess): ?>
                            <h2 class="mb-0">
                                <i class="bi bi-check2-circle"></i>
                                داواکارییەکەت سەرکەوتوو بوو!
                            </h2>
                            <p class="mb-0 mt-2">سوپاس بۆ داواکارییەکەت — ئێمە بە زوویی پەیوەندیت پێوە دەکەین</p>
                        <?php else: ?>
                            <h2 class="mb-0">
                                <i class="bi bi-credit-card"></i>
                                تەواوکردنی داواکاری
                            </h2>
                            <p class="mb-0 mt-2">زانیاری کڕیار و پشتڕاستکردنەوەی داواکاری</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i>
                                <?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <?php
                            $whatsappData = $_SESSION['whatsapp_receipt_data'] ?? null;
                            $waDisabledShops = [];
                            $waMissingPhoneShops = [];
                            $waNotFoundShops = [];
                            $hasWhatsAppReceiptButton = false;
                            $hasWhatsAppReceiptWarnings = false;
                            if ($whatsappData) {
                                $waDisabledShops = $whatsappData['shops_whatsapp_disabled'] ?? [];
                                $waMissingPhoneShops = $whatsappData['shops_missing_phone'] ?? [];
                                $waNotFoundShops = $whatsappData['shops_whatsapp_not_found'] ?? [];
                                $hasWhatsAppReceiptButton = !empty($whatsappData['shop_info']);
                                $hasWhatsAppReceiptWarnings = !empty($waDisabledShops)
                                    || !empty($waMissingPhoneShops)
                                    || !empty($waNotFoundShops);
                            }
                            ?>
                            <div class="checkout-success-state">
                                <div class="checkout-success-hero">
                                    <div class="success-icon-ring" aria-hidden="true">
                                        <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="سەرکەوتوو">
                                            <circle class="success-check-circle" cx="32" cy="32" r="28"/>
                                            <path class="success-check-mark" d="M18 33 L28 43 L48 21"/>
                                        </svg>
                                    </div>
                                    <h2 class="success-title">داواکارییەکەت سەرکەوتوو بوو!</h2>
                                    <p class="success-message"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php if (!empty($checkoutOrderNumbers)): ?>
                                        <div class="success-order-numbers">
                                            <?php foreach ($checkoutOrderNumbers as $orderNum): ?>
                                                <span class="success-order-badge">
                                                    <i class="bi bi-receipt"></i>
                                                    <?php echo htmlspecialchars((string) $orderNum, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="checkout-success-actions">
                                    <?php if ($hasWhatsAppReceiptButton): ?>
                                        <button type="button" class="btn btn-success" id="sendWhatsAppBtn" onclick="sendReceiptViaWhatsApp()">
                                            <i class="bi bi-whatsapp"></i> ناردنی وەسڵ بە واتسئاپ
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($isGuest): ?>
                                        <a href="<?php echo SITE_URL; ?>web/my-orders-guest.php<?php echo $shopSlug ? '?shop=' . urlencode($shopSlug) : ''; ?>" class="btn btn-success">
                                            <i class="bi bi-list-ul"></i> بینینی داواکارییەکانم
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo SITE_URL; ?>web/my-orders.php<?php echo $shopSlug ? '?shop=' . urlencode($shopSlug) : ''; ?>" class="btn btn-success">
                                            <i class="bi bi-list-ul"></i> بینینی داواکارییەکانم
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?php echo SITE_URL; ?>web/<?php echo $shopSlug ? htmlspecialchars($shopSlug) . '/' : ''; ?>" class="btn btn-outline-success">
                                        <i class="bi bi-arrow-left"></i> گەڕانەوە بۆ فرۆشگا
                                    </a>
                                </div>

                                <?php if ($hasWhatsAppReceiptWarnings): ?>
                                    <div class="checkout-success-warnings">
                                        <div class="alert alert-warning mb-0 py-2">
                                            <i class="bi bi-info-circle"></i>
                                            <?php if (!empty($waNotFoundShops)): ?>
                                                <div class="mb-2">
                                                    ناردنی وەسڵ بە واتسئاپ بەردەست نییە چونکە ئەم فرۆشگایانە نەدۆزرانەوە:
                                                    <strong><?php echo htmlspecialchars(implode('، ', $waNotFoundShops)); ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($waDisabledShops)): ?>
                                                <div<?php echo !empty($waMissingPhoneShops) ? ' class="mb-2"' : ''; ?>>
                                                    ناردنی وەسڵ بە واتسئاپ بەردەست نییە چونکە ئەم فرۆشگایانە تایبەتمەندی واتسئاپیان چالاک نەکردووە:
                                                    <strong><?php echo htmlspecialchars(implode('، ', $waDisabledShops)); ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($waMissingPhoneShops)): ?>
                                                <div>
                                                    ناردنی وەسڵ بە واتسئاپ بەردەست نییە چونکە ئەم فرۆشگایانە ژمارەی واتسئاپیان دیاری نەکردووە:
                                                    <strong><?php echo htmlspecialchars(implode('، ', $waMissingPhoneShops)); ?></strong>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <script>
                                // Clear cart on successful checkout (after redirect)
                                try {
                                    localStorage.removeItem("shopping_cart");
                                } catch (e) {
                                    console.error('Unable to clear shopping cart from localStorage:', e);
                                }
                            </script>
                        <?php else: ?>
                            <form method="POST" action="" id="checkoutForm">
                                <div class="row">
                                    <!-- Customer Information -->
                                    <div class="col-md-6">
                                        <h5 class="mb-3">
                                            <i class="bi bi-person"></i>
                                            زانیاری کڕیار
                                        </h5>
                                        
                                        <div class="mb-3">
                                            <label for="customer_name" class="form-label">ناو *</label>
                                            <input type="text" class="form-control" id="customer_name" name="customer_name" 
                                                   value="<?php echo $isGuest ? '' : htmlspecialchars($customerData['name']); ?>" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="customer_phone" class="form-label">ژمارەی تەلەفۆن * (١١ ژمارە)</label>
                                            <input type="tel" class="form-control" id="customer_phone" name="customer_phone" 
                                                   value="<?php echo $isGuest ? '' : htmlspecialchars($customerData['phone']); ?>" 
                                                   pattern="[0-9]{11}" 
                                                   maxlength="11" 
                                                   minlength="11"
                                                   title="تکایە ١١ ژمارە بە ئینگلیزی بنوسە"
                                                   required>
                                            <small class="text-muted">تکایە ١١ ژمارە بە ئینگلیزی بنوسە (بۆ نموونە: 07501234567)</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="customer_address" class="form-label">ناونیشان</label>
                                            <textarea class="form-control" id="customer_address" name="customer_address" rows="3"><?php echo $isGuest ? '' : htmlspecialchars($customerData['address']); ?></textarea>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="notes" class="form-label">تێبینی</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="2" 
                                                      placeholder="تێبینی تایبەت بۆ داواکارییەکە..."></textarea>
                                        </div>
                                    </div>
                                    
                                    <!-- Order Summary -->
                                    <div class="col-md-6">
                                        <h5 class="mb-3">
                                            <i class="bi bi-list-ul"></i>
                                            کورتەی داواکاری
                                        </h5>
                                        
                                        <div class="order-summary" id="orderSummary">
                                            <div class="text-center">
                                                <div class="spinner-border text-primary" role="status">
                                                    <span class="visually-hidden">بارکردن...</span>
                                                </div>
                                                <p class="mt-2">بارکردنی کاڵاکان...</p>
                                            </div>
                                        </div>
                                        
                                        <input type="hidden" name="cart_data" id="cartData">
                                        <input type="hidden" name="website_slug" value="<?php echo htmlspecialchars($_GET['shop'] ?? ''); ?>">
                                        <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($_SESSION['checkout_request_token'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div id="whatsappCheckoutNotice" class="alert alert-info mt-4 mb-0<?php echo $showWhatsAppCheckoutNotice ? '' : ' d-none'; ?>">
                                    <i class="bi bi-whatsapp"></i>
                                    <span id="whatsappCheckoutNoticeText">
                                        <?php if ($checkoutShopWhatsAppMissingPhoneNotice): ?>
                                            دوای ناردنی داواکاری، ناردنی وەسڵ بە واتسئاپ بەردەست نابێت چونکە
                                            <strong><?php echo htmlspecialchars(shopWhatsAppBusinessName($checkoutShopWhatsAppMeta, $shopSlug)); ?></strong>
                                            ژمارەی واتسئاپی دیاری نەکردووە.
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                    <a href="<?php echo SITE_URL; ?>web/<?php echo $shopSlug ? htmlspecialchars($shopSlug) . '/' : ''; ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> گەڕانەوە
                                    </a>
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-check-circle"></i> پشتڕاستکردنەوە و ناردن
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="template/assets/js/cart.js"></script>
    
    <script>
        // Phone number validation - only English numbers and exactly 11 digits
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInput = document.getElementById('customer_phone');
            
            if (phoneInput) {
                // Prevent non-numeric input
                phoneInput.addEventListener('input', function(e) {
                    // Remove any non-digit characters
                    let value = this.value.replace(/[^0-9]/g, '');
                    
                    // Limit to 11 digits
                    if (value.length > 11) {
                        value = value.substring(0, 11);
                    }
                    
                    this.value = value;
                });
                
                // Prevent paste of non-numeric content
                phoneInput.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                    const numericOnly = pastedText.replace(/[^0-9]/g, '').substring(0, 11);
                    this.value = numericOnly;
                });
                
                // Validate on form submit
                const form = document.getElementById('checkoutForm');
                if (form) {
                    form.addEventListener('submit', function(e) {
                        const phoneValue = phoneInput.value;
                        if (phoneValue.length !== 11) {
                            e.preventDefault();
                            alert('تکایە پێویستە ژمارەی تەلەفۆن ١١ ژمارە بێت بە ئینگلیزی');
                            phoneInput.focus();
                            return false;
                        }
                        if (!/^[0-9]{11}$/.test(phoneValue)) {
                            e.preventDefault();
                            alert('تکایە تەنها ژمارە بە ئینگلیزی بنوسە');
                            phoneInput.focus();
                            return false;
                        }
                    });
                }
            }
        });
        
        // Load cart data and display order summary
        document.addEventListener('DOMContentLoaded', function() {
            const orderSummaryEl = document.getElementById('orderSummary');
            if (!orderSummaryEl) return;
            
            // Function to load cart from localStorage as fallback
            function loadCartFromStorage() {
                try {
                    const cartData = localStorage.getItem('shopping_cart');
                    return cartData ? JSON.parse(cartData) : [];
                } catch (e) {
                    console.error('Error loading cart from storage:', e);
                    return [];
                }
            }
            
            // Function to render order summary
            function renderOrderSummary(cartItems) {
                if (!cartItems || cartItems.length === 0) {
                    orderSummaryEl.innerHTML = `
                        <div class="text-center text-muted">
                            <i class="bi bi-cart-x display-4"></i>
                            <p class="mt-2">سەبەتەکەت بەتاڵە</p>
                            <a href="<?php echo SITE_URL; ?>web/<?php echo $shopSlug ? htmlspecialchars($shopSlug) . '/' : ''; ?>" class="btn btn-primary">گەڕان بۆ کاڵاکان</a>
                        </div>
                    `;
                    const form = document.querySelector('form');
                    if (form) form.style.display = 'none';
                    return;
                }
                
                // Set cart data in hidden input
                const cartDataInput = document.getElementById('cartData');
                if (cartDataInput) {
                    cartDataInput.value = JSON.stringify(cartItems);
                }
                
                // Group items by shop and compute totals per currency
                const itemsByShop = {};
                let grandTotalIQD = 0, grandTotalUSD = 0;
                cartItems.forEach(item => {
                    const shopSlug = item.website_slug || 'unknown';
                    if (!itemsByShop[shopSlug]) {
                        itemsByShop[shopSlug] = {
                            items: [],
                            totalIQD: 0,
                            totalUSD: 0,
                            shopSlug: shopSlug
                        };
                    }
                    itemsByShop[shopSlug].items.push(item);
                    const amount = item.price * item.quantity;
                    const curr = item.currency || 'IQD';
                    if (curr === 'USD') {
                        itemsByShop[shopSlug].totalUSD += amount;
                        grandTotalUSD += amount;
                    } else {
                        itemsByShop[shopSlug].totalIQD += amount;
                        grandTotalIQD += amount;
                    }
                });
                
                // Display order summary grouped by shop
                let summaryHTML = '';
                const shopKeys = Object.keys(itemsByShop);
                
                shopKeys.forEach((shopSlug, index) => {
                    const shopGroup = itemsByShop[shopSlug];
                    const shopIqd = shopGroup.totalIQD || 0;
                    const shopUsd = shopGroup.totalUSD || 0;
                    const shopHasBoth = shopIqd > 0 && shopUsd > 0;
                    
                    summaryHTML += `
                        <div class="shop-group">
                            <div class="shop-group-header">
                                <i class="bi bi-shop"></i>
                                فرۆشگا ${index + 1}${shopSlug !== 'unknown' ? ' (' + shopSlug + ')' : ''}
                            </div>
                    `;
                    
                    shopGroup.items.forEach(item => {
                        const unitName = item.unit || 'دانە';
                        const curr = item.currency || 'IQD';
                        summaryHTML += `
                            <div class="order-item">
                                <div>
                                    <strong>${item.name}</strong><br>
                                    <small class="text-muted">${item.quantity} ${unitName} × ${formatPrice(item.price, curr)}</small>
                                </div>
                                <div class="text-end">
                                    <strong>${formatPrice(item.price * item.quantity, curr)}</strong>
                                </div>
                            </div>
                        `;
                    });
                    if (shopHasBoth) {
                        summaryHTML += `
                            <div class="shop-group-total">
                                کۆی دینار: ${formatPrice(shopIqd, 'IQD')}<br>
                                کۆی دۆلار: ${formatPrice(shopUsd, 'USD')}
                            </div>
                        </div>
                    `;
                    } else if (shopIqd > 0) {
                        summaryHTML += `
                            <div class="shop-group-total">
                                کۆی ئەم فرۆشگایە: ${formatPrice(shopIqd, 'IQD')}
                            </div>
                        </div>
                    `;
                    } else {
                        summaryHTML += `
                            <div class="shop-group-total">
                                کۆی ئەم فرۆشگایە: ${formatPrice(shopUsd, 'USD')}
                            </div>
                        </div>
                    `;
                    }
                });
                
                const grandHasBoth = grandTotalIQD > 0 && grandTotalUSD > 0;
                if (grandHasBoth) {
                    summaryHTML += `
                        <div class="grand-total">
                            <i class="bi bi-calculator"></i>
                            کۆی دینار: ${formatPrice(grandTotalIQD, 'IQD')}<br>
                            کۆی دۆلار: ${formatPrice(grandTotalUSD, 'USD')}
                        </div>
                    `;
                } else if (grandTotalIQD > 0) {
                    summaryHTML += `
                        <div class="order-total">
                            کۆی گشتی: ${formatPrice(grandTotalIQD, 'IQD')}
                        </div>
                    `;
                } else {
                    summaryHTML += `
                        <div class="order-total">
                            کۆی گشتی: ${formatPrice(grandTotalUSD, 'USD')}
                        </div>
                    `;
                }
                
                orderSummaryEl.innerHTML = summaryHTML;
                updateWhatsAppCheckoutNotice(shopKeys);
            }

            function updateWhatsAppCheckoutNotice(shopSlugs) {
                const noticeEl = document.getElementById('whatsappCheckoutNotice');
                const noticeTextEl = document.getElementById('whatsappCheckoutNoticeText');
                if (!noticeEl || !noticeTextEl) {
                    return;
                }

                const validSlugs = (shopSlugs || []).filter(slug => slug && slug !== 'unknown');
                if (validSlugs.length === 0) {
                    noticeEl.classList.add('d-none');
                    return;
                }

                const statusUrl = <?php echo json_encode(
                    SITE_URL . 'web/checkout.php?action=whatsapp_status&slugs=',
                    JSON_UNESCAPED_UNICODE
                ); ?> + encodeURIComponent(validSlugs.join(','));

                fetch(statusUrl)
                    .then(response => response.json())
                    .then(data => {
                        if (!data || !data.success || !data.shops) {
                            noticeEl.classList.add('d-none');
                            return;
                        }

                        const notFoundShops = Object.keys(data.shops)
                            .filter(slug => data.shops[slug].exists === false)
                            .map(slug => data.shops[slug].business_name || slug);
                        const missingPhoneShops = Object.keys(data.shops)
                            .filter(slug => data.shops[slug].enabled && !data.shops[slug].available)
                            .map(slug => data.shops[slug].business_name || slug);

                        if (notFoundShops.length === 0 && missingPhoneShops.length === 0) {
                            noticeEl.classList.add('d-none');
                            return;
                        }

                        noticeTextEl.textContent = '';

                        if (notFoundShops.length > 0) {
                            const notFoundLine = document.createElement('div');
                            notFoundLine.className = missingPhoneShops.length > 0 ? 'mb-2' : '';
                            notFoundLine.append(
                                'دوای ناردنی داواکاری، ناردنی وەسڵ بە واتسئاپ بۆ ئەم فرۆشگایانە بەردەست نابێت چونکە نەدۆزرانەوە: '
                            );
                            const notFoundStrong = document.createElement('strong');
                            notFoundStrong.textContent = notFoundShops.join('، ');
                            notFoundLine.appendChild(notFoundStrong);
                            notFoundLine.append('.');
                            noticeTextEl.appendChild(notFoundLine);
                        }

                        if (missingPhoneShops.length > 0) {
                            const missingLine = document.createElement('div');
                            missingLine.append(
                                'دوای ناردنی داواکاری، ناردنی وەسڵ بە واتسئاپ بۆ ئەم فرۆشگایانە بەردەست نابێت چونکە ژمارەی واتسئاپیان دیاری نەکردووە: '
                            );
                            const missingStrong = document.createElement('strong');
                            missingStrong.textContent = missingPhoneShops.join('، ');
                            missingLine.appendChild(missingStrong);
                            missingLine.append('.');
                            noticeTextEl.appendChild(missingLine);
                        }

                        noticeEl.classList.remove('d-none');
                    })
                    .catch(() => {
                        noticeEl.classList.add('d-none');
                    });
            }
            
            // Try to get cart data - wait for shoppingCart with timeout
            let attempts = 0;
            const maxAttempts = 50; // 5 seconds max wait (50 * 100ms)
            
            function tryLoadCart() {
                attempts++;
                
                if (window.shoppingCart && typeof window.shoppingCart.getCartData === 'function') {
                    // Cart object is available, use it
                    try {
                        const cartData = window.shoppingCart.getCartData();
                        renderOrderSummary(cartData.items || []);
                        return;
                    } catch (e) {
                        console.error('Error getting cart data from shoppingCart:', e);
                        // Fall through to localStorage fallback
                    }
                }
                
                // If cart object not ready yet and we haven't exceeded max attempts, try again
                if (attempts < maxAttempts) {
                    setTimeout(tryLoadCart, 100);
                    return;
                }
                
                // Timeout reached or error - fallback to localStorage
                console.warn('shoppingCart not available, using localStorage fallback');
                const cartItems = loadCartFromStorage();
                renderOrderSummary(cartItems);
            }
            
            // Start trying to load cart
            tryLoadCart();
        });
        
        function formatPrice(price, currency) {
            currency = currency || 'IQD';
            const isUsd = currency === 'USD';
            const decimals = isUsd ? 2 : 0;
            const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(parseFloat(price) || 0);
            return formatted + (isUsd ? ' دۆلار' : ' دینار');
        }
        
        function formatWhatsAppPhone(phone) {
            let formatted = String(phone).replace(/[\s\-\(\)]/g, '');
            if (!formatted.startsWith('964') && !formatted.startsWith('+964')) {
                if (formatted.startsWith('0')) {
                    formatted = '964' + formatted.substring(1);
                } else {
                    formatted = '964' + formatted;
                }
            }
            return formatted.replace(/^\+/, '');
        }

        function buildReceiptMessage(receiptData, shopSlugFilter) {
            let message = '📋 *وەسڵی داواکاری*\n\n';
            message += '👤 *کڕیار:* ' + receiptData.customer_name + '\n';
            message += '📱 *تەلەفۆن:* ' + receiptData.customer_phone + '\n';

            if (receiptData.customer_address) {
                message += '📍 *ناونیشان:* ' + receiptData.customer_address + '\n';
            }

            message += '\n━━━━━━━━━━━━━━━━\n\n';

            let grandTotalIQD = 0, grandTotalUSD = 0;
            const shopKeys = Object.keys(receiptData.items_by_shop || {}).filter(slug => {
                return !shopSlugFilter || slug === shopSlugFilter;
            });

            shopKeys.forEach((shopSlug, index) => {
                const shopGroup = receiptData.items_by_shop[shopSlug];
                const orderResult = receiptData.order_results[shopSlug];
                const shopMeta = (receiptData.shop_info || {})[shopSlug];

                if (shopMeta && shopMeta.business_name) {
                    message += `🏪 *${shopMeta.business_name}*\n`;
                } else {
                    message += `🏪 *فرۆشگا ${index + 1}*\n`;
                }

                if (orderResult && orderResult.order_number) {
                    message += `📋 *ژمارەی وەسڵ:* ${orderResult.order_number}\n\n`;
                }

                shopGroup.forEach(item => {
                    const itemTotal = item.price * item.quantity;
                    const curr = item.currency || 'IQD';
                    if (curr === 'USD') grandTotalUSD += itemTotal;
                    else grandTotalIQD += itemTotal;
                    const unitName = item.unit || 'دانە';
                    message += `• ${item.name}\n`;
                    message += `  ${item.quantity} ${unitName} × ${formatPrice(item.price, curr)} = ${formatPrice(itemTotal, curr)}\n\n`;
                });

                message += '━━━━━━━━━━━━━━━━\n\n';
            });

            const grandHasBoth = grandTotalIQD > 0 && grandTotalUSD > 0;
            if (grandHasBoth) {
                message += `💰 *کۆی دینار:* ${formatPrice(grandTotalIQD, 'IQD')}\n`;
                message += `💰 *کۆی دۆلار:* ${formatPrice(grandTotalUSD, 'USD')}\n\n`;
            } else if (grandTotalIQD > 0) {
                message += `💰 *کۆی گشتی:* ${formatPrice(grandTotalIQD, 'IQD')}\n\n`;
            } else {
                message += `💰 *کۆی گشتی:* ${formatPrice(grandTotalUSD, 'USD')}\n\n`;
            }

            if (receiptData.notes) {
                message += `📝 *تێبینی:* ${receiptData.notes}\n\n`;
            }

            message += '✅ *سپاس بۆ کڕینەکەت!*';
            return message;
        }

        // Function to send receipt via WhatsApp to shop owner(s)
        function sendReceiptViaWhatsApp() {
            <?php if (isset($_SESSION['whatsapp_receipt_data'])): ?>
            const receiptData = <?php echo json_encode($_SESSION['whatsapp_receipt_data'], JSON_UNESCAPED_UNICODE); ?>;
            const shopInfo = receiptData.shop_info || {};
            const shopSlugs = Object.keys(shopInfo).filter(slug => shopInfo[slug].shop_phone);

            if (!receiptData || shopSlugs.length === 0) {
                alert('ژمارەی تەلەفۆنی فرۆشگا بەردەست نییە');
                return;
            }

            shopSlugs.forEach((shopSlug, index) => {
                const phone = formatWhatsAppPhone(shopInfo[shopSlug].shop_phone);
                const message = buildReceiptMessage(receiptData, shopSlug);
                const whatsappUrl = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
                if (index === 0) {
                    window.open(whatsappUrl, '_blank');
                } else {
                    setTimeout(() => window.open(whatsappUrl, '_blank'), index * 500);
                }
            });

            <?php else: ?>
            alert('زانیاری وەسڵ بەردەست نییە');
            <?php endif; ?>
        }
    </script>
</body>
</html>
