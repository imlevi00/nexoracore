<?php
/**
 * Generate Order PDF - web/api/generate_order_pdf.php
 * Creates a printable PDF receipt for orders
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../auth/session_helper.php';

$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($orderId === 0) {
    http_response_code(400);
    die('ئایدی داواکاری نادروستە');
}

// Get order details
$stmt = $conn->prepare("
    SELECT wo.*, u.business_name, u.phone as shop_phone, u.address as shop_address, u.email as shop_email
    FROM web_orders wo
    INNER JOIN users u ON wo.user_id = u.id
    WHERE wo.id = ?
");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$result = $stmt->get_result();
$order = $result->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    die('داواکاری نەدۆزرایەوە');
}

// پشتڕاستکردنەوەی مۆڵەت - ڕێگە دەدرێت بە: خاوەنی فرۆشگا، کڕیاری داخڵبوو، یان میوانی داواکار
$authorized = false;

// ١) خاوەنی فرۆشگا (بەکارهێنەری داخڵبوو)
if (isLoggedIn('user') && getCurrentUserId() && (int)$order['user_id'] === (int)getCurrentUserId()) {
    $authorized = true;
}

// ٢) کڕیاری داخڵبوو کە خاوەنی داواکارییەکەیە
if (!$authorized && CustomerSession::isLoggedIn() && !empty($order['customer_id'])
    && (int)$order['customer_id'] === (int)CustomerSession::getCustomerId()) {
    $authorized = true;
}

// ٣) میوان کە داواکارییەکەی کردووە (پشتڕاستکردنەوە بە session id + IP)
if (!$authorized && !empty($order['guest_session_id'])) {
    $guestSessionId = CustomerSession::getGuestSessionId();
    $guestIp = CustomerSession::getClientIp();
    if ($guestSessionId && hash_equals((string)$order['guest_session_id'], (string)$guestSessionId)
        && (string)$order['guest_ip_address'] === (string)$guestIp) {
        $authorized = true;
    }
}

if (!$authorized) {
    // ئەگەر هیچ سێشنێک نەبوو، داوای داخڵبوون دەکەین؛ ئەگەرنا مۆڵەت ڕەتدەکرێتەوە
    if (!isLoggedIn('user') && !CustomerSession::isLoggedIn()) {
        http_response_code(401);
        die('تکایە سەرەتا بچۆرە ژوورەوە بۆ بینینی وەسڵ');
    }
    http_response_code(403);
    die('تۆ مۆڵەتت نییە ئەم وەسڵە ببینیت. تەنها خاوەنی داواکاری دەتوانێت وەسڵەکەی ببینێت.');
}

// شوناسی فرۆشگای خاوەنی داواکاری بۆ هێنانی ڕێکخستنەکانی وەسڵ (لۆگۆ، بانەر، ژێرەوە)
$currentUserId = (int)$order['user_id'];

$items = json_decode($order['items'], true);

// Format price by currency (IQD = دینار, USD = دۆلار)
function formatPricePdf($price, $currency = 'IQD') {
    if ($price === null || $price === '') {
        return ($currency === 'USD') ? '0 دۆلار' : '0 دینار';
    }
    $curr = $currency === 'USD' ? 'USD' : 'IQD';
    $decimals = ($curr === 'USD') ? 2 : 0;
    $formatted = number_format((float)$price, $decimals, '.', ',');
    return $formatted . ($curr === 'USD' ? ' دۆلار' : ' دینار');
}

$orderDate = date('Y/m/d H:i', strtotime($order['created_at']));
$shortDate = date('d/m/Y', strtotime($order['created_at']));
$time = date('H:i', strtotime($order['created_at']));

// وەرگرتنی ڕێکخستنەکان
$settingsStmt = $conn->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
$settingsStmt->bind_param('i', $currentUserId);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();

if (!function_exists('resolveA4BannerUrl')) {
    function resolveA4BannerUrl(?string $bannerPath): string
    {
        $bannerPath = trim((string)$bannerPath);
        if ($bannerPath === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $bannerPath)) {
            return $bannerPath;
        }
        $normalized = ltrim($bannerPath, '/');
        if (preg_match('/^a4_banner_[^\/]+\.(jpg|jpeg|png|gif|webp)$/i', $normalized)) {
            $normalized = 'img/receipts/a4_receipt_banner/' . $normalized;
        }
        if (strpos($normalized, 'img/receipts/a4_receipt_banner/') === 0) {
            $spaceUrl = spaces_public_url_for_object_key($normalized);
            return is_string($spaceUrl) ? $spaceUrl : '';
        }
        return url($normalized);
    }
}

$pageTitle = "وەسڵی داواکاری - " . $order['order_number'];
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            color: #1f2937;
        }

        .a4-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: white;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }

        /* Custom Banner Section */
        .banner-wrapper {
            position: relative;
            width: 100%;
            height: 250px;
            overflow: visible;
        }

        .custom-banner {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* زانیاری کڕیار لەسەر بانەر - لای چەپ */
        .customer-info-overlay {
            position: absolute;
            top: 15px;
            left: 1px;
            width: 30%;
            background: transparent;
            padding: 20px;
            z-index: 10;
            color: #1f2937;
            font-size: 16px;
            line-height: 2.2;
        }

        .customer-info-overlay .customer-info-line {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .customer-info-overlay .customer-info-label {
            font-weight: bold;
            color: #1f2937;
            min-width: fit-content;
            font-size: 17px;
        }

        .customer-info-overlay .customer-info-value {
            color: #374151;
            word-break: break-word;
            font-size: 16px;
        }

        /* زانیاریەکانی وەسڵ لە خوار بانەرەکە بە شێوەی ئاسۆیی */
        .banner-info-horizontal {
            position: relative;
            width: 100%;
            background: white;
            padding: 8px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 35px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-bottom: 2px solid #6366f1;
        }

        .banner-info-title {
            font-size: 16px;
            font-weight: bold;
            color: #6366f1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding-left: 15px;
            border-left: 2px solid #e5e7eb;
        }

        .banner-info-item {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 5px;
            padding: 0;
            background: transparent;
            border-radius: 0;
            min-width: auto;
        }

        .banner-info-label {
            font-size: 14px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .banner-info-label i {
            color: #6366f1;
            font-size: 14px;
        }

        .banner-info-value {
            font-size: 15px;
            font-weight: bold;
            color: #1f2937;
        }

        /* Background Pattern - Only show if no banner */
        .a4-container.no-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 180px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            clip-path: polygon(0 0, 100% 0, 100% 70%, 0 100%);
            z-index: 0;
        }

        .content-wrapper {
            position: relative;
            z-index: 1;
            padding: 10px 20px;
        }

        .content-wrapper.with-banner {
            padding-top: 5px;
        }

        /* Header Section */
        .receipt-header {
            text-align: center;
            padding: 8px 0 10px;
            position: relative;
        }

        .receipt-header.with-banner {
            padding-top: 5px;
        }

        .business-logo {
            width: 50px;
            height: 50px;
            margin: 0 auto 6px;
            background: white;
            border-radius: 50%;
            padding: 6px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .business-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .business-name {
            font-size: 16px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 4px;
            text-shadow: none;
            background: white;
            padding: 6px 12px;
            border-radius: 6px;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .receipt-header:not(.with-banner) .business-name {
            color: white;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
            background: transparent;
            box-shadow: none;
        }

        .business-info {
            color: rgba(255, 255, 255, 0.95);
            font-size: 9px;
            line-height: 1.3;
        }

        .receipt-header.with-banner .business-info {
            color: #6b7280;
        }

        /* Receipt Info Card */
        .receipt-info-card {
            background: white;
            border-radius: 6px;
            padding: 5px 8px;
            margin: 5px 0 2px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
        }

        .receipt-title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            color: #6366f1;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table tr {
            display: inline-block;
            width: 50%;
            vertical-align: top;
        }

        .info-table tr:nth-child(odd) {
            padding-left: 3px;
        }

        .info-table tr:nth-child(even) {
            padding-right: 3px;
        }

        .info-table td {
            display: inline-block;
            padding: 2px 4px;
            font-size: 11px;
            vertical-align: middle;
        }

        .info-table .info-label {
            color: #6b7280;
            min-width: 80px;
            text-align: right;
        }

        .info-table .info-value {
            font-weight: bold;
            color: #1f2937;
            text-align: right;
        }
        
        .info-table .info-label i {
            font-size: 10px;
            margin-left: 2px;
        }

        /* Items Table */
        .items-section {
            margin: 2px 0;
        }

        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding-bottom: 6px;
            border-bottom: 2px solid #6366f1;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            border: 3px solid #6366f1;
        }

        .items-table thead {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
        }

        .items-table thead th {
            padding: 8px 6px;
            text-align: center;
            font-weight: 600;
            font-size: 18px;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }

        .items-table tbody tr {
            transition: background 0.2s;
        }

        .items-table tbody tr:hover {
            background: #f9fafb;
        }

        .items-table tbody td {
            padding: 6px 5px;
            text-align: center;
            font-size: 16px;
            border: 3px solid #e5e7eb;
        }

        .items-table tbody td:nth-child(2) {
            text-align: right;
            font-weight: 500;
        }

        .row-number {
            background: #f3f4f6;
            font-weight: bold;
            color: #6366f1;
        }

        .unit-info {
            font-size: 9px;
            color: #6b7280;
            display: block;
            margin-top: 1px;
        }

        /* Totals Section */
        .totals-section {
            margin: 10px 0;
            background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
            border-radius: 8px;
            padding: 10px;
            border: 1px solid #e5e7eb;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 16px;
            border-bottom: 1px dashed #d1d5db;
        }

        .total-row:last-child {
            border-bottom: none;
        }

        .total-row.highlight {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            padding: 10px 12px;
            margin: 8px -10px -10px;
            border-radius: 0 0 7px 7px;
            font-size: 18px;
            font-weight: bold;
        }

        .total-label {
            font-weight: 600;
            color: #4b5563;
        }

        .total-value {
            font-weight: bold;
            color: #1f2937;
        }

        .highlight .total-label,
        .highlight .total-value {
            color: white;
        }

        /* Payment Method Badge */
        .payment-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .banner-info-value .payment-badge {
            font-size: 12px;
            padding: 5px 14px;
        }

        /* Footer */
        .receipt-footer {
            position: absolute;
            bottom: 3px;
            left: 0;
            right: 0;
            text-align: center;
            padding: 0;
            margin: 0;
        }

        .footer-text {
            font-size: 10px;
            color: #6b7280;
            line-height: 1.5;
            white-space: pre-line;
            margin-bottom: 5px;
        }

        .brand-footer {
            font-weight: 700;
            color: #1f2937;
            font-size: 12px;
            line-height: 1.2;
        }

        .brand-footer i {
            color: #6366f1;
            font-size: 11px;
        }

        /* Custom Notes Section */
        .custom-notes {
            margin-top: 10px;
            padding: 10px;
            background: #fff8e1;
            border-right: 3px solid #ffc107;
            border-radius: 6px;
            text-align: right;
        }

        .custom-notes-title {
            font-size: 11px;
            font-weight: bold;
            color: #f57c00;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .custom-notes-content {
            font-size: 10px;
            color: #5d4037;
            line-height: 1.4;
            white-space: pre-line;
        }

        /* Action Buttons */
        .action-buttons {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.3s;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .btn-print {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
        }

        .btn-back {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .a4-container {
                width: 100%;
                height: 100%;
                box-shadow: none;
                margin: 0;
                page-break-after: always;
            }

            .action-buttons {
                display: none !important;
            }

            .items-table tbody tr:hover {
                background: transparent;
            }

            .banner-wrapper {
                page-break-inside: avoid;
            }

            .banner-overlay {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }

        @page {
            size: A4;
            margin: 0;
        }
    </style>
</head>
<body>
    <!-- Action Buttons -->
    <div class="action-buttons">
        <button class="action-btn btn-print" onclick="window.print()">
            <i class="bi bi-printer"></i>
            چاپکردن
        </button>
        <button class="action-btn btn-back" onclick="window.close()">
            <i class="bi bi-x-circle"></i>
            داخستن
        </button>
    </div>

    <!-- A4 Container -->
    <div class="a4-container <?php echo ($settings && $settings['a4_receipt_banner']) ? '' : 'no-banner'; ?>">
        <?php if ($settings && $settings['a4_receipt_banner']): ?>
            <!-- Custom Banner -->
            <div class="banner-wrapper">
                <img src="<?php echo htmlspecialchars(resolveA4BannerUrl($settings['a4_receipt_banner']), ENT_QUOTES, 'UTF-8'); ?>" alt="Banner" class="custom-banner">
                
                <!-- زانیاری کڕیار لەسەر بانەر -->
                <?php if (!empty($order['customer_name'])): ?>
                <div class="customer-info-overlay">
                    <div class="customer-info-line">
                        <span class="customer-info-label">کڕیار :</span>
                        <span class="customer-info-value"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                    </div>
                    
                    <?php if (!empty($order['customer_phone'])): ?>
                    <div class="customer-info-line">
                        <span class="customer-info-label">ژ. مۆبایل :</span>
                        <span class="customer-info-value"><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($order['customer_address'])): ?>
                    <div class="customer-info-line">
                        <span class="customer-info-label">ناونیشان :</span>
                        <span class="customer-info-value"><?php echo htmlspecialchars($order['customer_address']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- زانیاریەکانی وەسڵ بە شێوەی ئاسۆیی لە خوار بانەرەکە -->
            <div class="banner-info-horizontal">
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-hash"></i>
                        ژمارەی وەسڵ
                    </span>
                    <span class="banner-info-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                </div>
                
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-calendar-event"></i>
                        بەروار
                    </span>
                    <span class="banner-info-value"><?php echo $shortDate; ?></span>
                </div>
                
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-clock"></i>
                        کاتژمێر
                    </span>
                    <span class="banner-info-value"><?php echo $time; ?></span>
                </div>
                
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-truck"></i>
                        داواکاری وێب
                    </span>
                    <span class="banner-info-value">
                        <span class="payment-badge">
                            <?php 
                            $status_text = '';
                            switch($order['status']) {
                                case 'pending': $status_text = 'چاوەڕوانی'; break;
                                case 'processing': $status_text = 'لە پرۆسەدایە'; break;
                                case 'completed': $status_text = 'تەواوبووە'; break;
                                case 'cancelled': $status_text = 'هەڵوەشاوەتەوە'; break;
                                default: $status_text = $order['status']; break;
                            }
                            echo $status_text;
                            ?>
                        </span>
                    </span>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="content-wrapper <?php echo ($settings && $settings['a4_receipt_banner']) ? 'with-banner' : ''; ?>">
            <!-- Receipt Info Card - Only show if no banner -->
            <?php if (!($settings && $settings['a4_receipt_banner'])): ?>
            <div class="receipt-info-card">
                <div class="receipt-title">
                    <i class="bi bi-receipt"></i>
                    وەسڵی داواکاری
                </div>
                
                <table class="info-table">
                    <tr>
                        <td class="info-label"><i class="bi bi-hash"></i> ژمارەی وەسڵ</td>
                        <td class="info-value"><?php echo htmlspecialchars($order['order_number']); ?></td>
                    </tr>
                    
                    <?php if (!empty($order['customer_name'])): ?>
                    <tr>
                        <td class="info-label"><i class="bi bi-person"></i> ناوی کڕیار</td>
                        <td class="info-value"><?php echo htmlspecialchars($order['customer_name']); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <td class="info-label"><i class="bi bi-calendar-event"></i> بەروار</td>
                        <td class="info-value"><?php echo $shortDate; ?></td>
                    </tr>
                    
                    <tr>
                        <td class="info-label"><i class="bi bi-clock"></i> کاتژمێر</td>
                        <td class="info-value"><?php echo $time; ?></td>
                    </tr>
                    
                    <tr>
                        <td class="info-label"><i class="bi bi-truck"></i> دۆخی داواکاری</td>
                        <td class="info-value">
                            <span class="payment-badge">
                                <?php 
                                $status_text = '';
                                switch($order['status']) {
                                    case 'pending': $status_text = 'چاوەڕوانی'; break;
                                    case 'processing': $status_text = 'لە پرۆسەدایە'; break;
                                    case 'completed': $status_text = 'تەواوبووە'; break;
                                    case 'cancelled': $status_text = 'هەڵوەشاوەتەوە'; break;
                                    default: $status_text = $order['status']; break;
                                }
                                echo $status_text;
                                ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="receipt-header <?php echo ($settings && $settings['a4_receipt_banner']) ? 'with-banner' : ''; ?>">
                <?php if (!($settings && $settings['a4_receipt_banner'])): ?>
                    <?php if ($settings && $settings['business_logo']): ?>
                        <div class="business-logo">
                            <img src="<?php echo url('uploads/' . $settings['business_logo']); ?>" alt="Logo">
                        </div>
                    <?php endif; ?>
                    
                    <div class="business-name">
                        <?php echo htmlspecialchars($order['business_name']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($settings && $settings['receipt_header']): ?>
                    <div class="business-info">
                        <?php echo nl2br(htmlspecialchars($settings['receipt_header'])); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Items Section -->
            <div class="items-section">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="width: 30%;">ناوی کاڵا</th>
                            <th style="width: 12%;">بڕ</th>
                            <th style="width: 13%;">یەکە</th>
                            <th style="width: 20%;">نرخی یەکە</th>
                            <th style="width: 20%;">کۆ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $index = 1;
                        foreach ($items as $item): 
                            $itemCurrency = $item['currency'] ?? 'IQD';
                            // Calculate subtotal if not present
                            $subtotal = isset($item['subtotal']) ? $item['subtotal'] : ($item['price'] * $item['quantity']);
                            
                            // Format quantity
                            $rawQty = is_string($item['quantity']) ? $item['quantity'] : (string)$item['quantity'];
                            if (is_numeric($rawQty)) {
                                $normalizedQty = number_format((float)$rawQty, 6, '.', '');
                                $compactQty = rtrim(rtrim($normalizedQty, '0'), '.');
                            } else {
                                $compactQty = $rawQty;
                            }
                        ?>
                            <tr>
                                <td class="row-number"><?php echo $index++; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo $compactQty; ?></strong>
                                </td>
                                <td>
                                    <?php echo !empty($item['unit']) ? htmlspecialchars($item['unit']) : 'دانە'; ?>
                                </td>
                                <td><?php echo formatPricePdf($item['price'], $itemCurrency); ?></td>
                                <td><strong><?php echo formatPricePdf($subtotal, $itemCurrency); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Totals Section -->
            <?php 
            $totalIQD = 0;
            $totalUSD = 0;
            if (is_array($items)) {
                foreach ($items as $item) {
                    $amt = isset($item['subtotal']) ? $item['subtotal'] : ($item['price'] * $item['quantity']);
                    $curr = $item['currency'] ?? 'IQD';
                    if ($curr === 'USD') $totalUSD += $amt;
                    else $totalIQD += $amt;
                }
            }
            $orderHasBoth = $totalIQD > 0 && $totalUSD > 0;
            ?>
            <div class="totals-section" style="margin-top: 20px;">
                <?php if ($orderHasBoth): ?>
                <div class="total-row highlight">
                    <span class="total-label">کۆی دینار:</span>
                    <span class="total-value"><?php echo formatPricePdf($totalIQD, 'IQD'); ?></span>
                </div>
                <div class="total-row highlight">
                    <span class="total-label">کۆی دۆلار:</span>
                    <span class="total-value"><?php echo formatPricePdf($totalUSD, 'USD'); ?></span>
                </div>
                <?php elseif ($totalIQD > 0): ?>
                <div class="total-row highlight">
                    <span class="total-label">کۆی گشتی:</span>
                    <span class="total-value"><?php echo formatPricePdf($totalIQD, 'IQD'); ?></span>
                </div>
                <?php else: ?>
                <div class="total-row highlight">
                    <span class="total-label">کۆی گشتی:</span>
                    <span class="total-value"><?php echo formatPricePdf($totalUSD, 'USD'); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($order['notes'])): ?>
            <!-- Custom Notes Section -->
            <div class="custom-notes">
                <div class="custom-notes-title">
                    <i class="bi bi-chat-left-dots"></i>
                    تێبینی
                </div>
                <div class="custom-notes-content">
                    <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="receipt-footer">
                <?php if ($settings && $settings['receipt_footer']): ?>
                    <div class="footer-text">
                        <?php echo nl2br(htmlspecialchars($settings['receipt_footer'])); ?>
                    </div>
                <?php endif; ?>
                
                <div class="brand-footer">
                    <i class="bi bi-stars"></i> سیستەمی NexoraCore | nexoracore.com
                </div>
            </div>

            <!-- Custom Notes Section from settings -->
            <?php if ($settings && !empty($settings['a4_receipt_notes'])): ?>
                <div class="custom-notes" style="margin-top: 15px;">
                    <div class="custom-notes-title">
                        <i class="bi bi-info-circle"></i>
                        زانیاری زیاتر
                    </div>
                    <div class="custom-notes-content">
                        <?php echo nl2br(htmlspecialchars($settings['a4_receipt_notes'])); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

