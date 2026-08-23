<?php
/**
 * وەسڵی A4 - user/pos/receipt_a4.php
 * وەسڵێکی دیزاینی مۆدێرن بە فۆرماتی A4
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/zanyari_user_settings.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/sale_return_service.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

$currentUser = getCurrentUser();
$saleId = (int)($_GET['id'] ?? 0);

if (!$saleId) {
    setMessage('ID ی فرۆشتن پێویستە', 'error');
    redirect(url('user/dashboard.php'));
}

$hasA4ReceiptView = hasFeaturePermission('pos_receipt_a4_view');

// کارمەندی سنووردار: تەنها بۆ فرۆشتنی خۆی
$ownOnlySubUserId = getSalesOwnOnlySubUserId($currentUser);
$ownFilter = ($ownOnlySubUserId !== null) ? " AND s.sub_user_id = ?" : "";

// وەرگرتنی زانیاری فرۆشتن
$stmt = $conn->prepare("
    SELECT s.*,
           COALESCE(s.currency, 'IQD') as currency,
           DATE_FORMAT(s.sale_date, '%Y-%m-%d %H:%i:%s') as formatted_date,
           DATE_FORMAT(s.sale_date, '%d/%m/%Y') as short_date,
           DATE_FORMAT(s.sale_date, '%H:%i') as time,
           c.name as customer_name,
           c.phone as customer_phone,
           c.address as customer_address,
           c.id as customer_id,
           (SELECT wo.notes
            FROM web_orders wo
            WHERE wo.user_id = s.user_id
              AND wo.status = 'completed'
              AND ABS(wo.total_amount - s.total_amount) < 0.01
              AND ABS(TIMESTAMPDIFF(HOUR, wo.completed_at, s.sale_date)) <= 1
            ORDER BY ABS(TIMESTAMPDIFF(MINUTE, wo.completed_at, s.sale_date)) ASC
            LIMIT 1
           ) as customer_note
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.id = ? AND s.user_id = ?" . $ownFilter . "
");
if ($ownOnlySubUserId !== null) {
    $stmt->bind_param('iii', $saleId, $currentUser['id'], $ownOnlySubUserId);
} else {
    $stmt->bind_param('ii', $saleId, $currentUser['id']);
}
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

// Get sale currency
$saleCurrency = $sale['currency'] ?? 'IQD';

if (!$sale) {
    setMessage('فرۆشتن نەدۆزرایەوە', 'error');
    redirect(url('user/dashboard.php'));
}

// وەرگرتنی ئایتمەکانی فرۆشتن
$itemsStmt = $conn->prepare("
    SELECT si.*, 
           COALESCE(si.currency, 'IQD') as currency,
           COALESCE(p.name, si.product_name) as product_name,
           p.barcode as barcode,
           p.image_path as product_image_path
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
    ORDER BY si.id
");
$itemsStmt->bind_param('i', $saleId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$saleReceiptReturns = getSaleReceiptReturnsData($conn, (int)$currentUser['id'], $saleId);
$totalReturnedAmount = (float)($saleReceiptReturns['total_returned'] ?? 0);
$netFinalAmount = getSaleReceiptNetFinalAmount($sale, $saleReceiptReturns);
$paidReceivedAmount = getSaleReceiptPaidReceivedAmount($conn, (int)$currentUser['id'], $saleId, $sale);

// وەرگرتنی ڕێکخستنەکانی فیڵدەکان لە خشتەی user_a4_field_settings ئەگەر fields لە URL نەهاتبێت
$autoSelectedFields = [];
if (empty($_GET['fields'] ?? '')) {
    try {
        // پشکنینی بوونی خشتەکە بۆ ئەوەی هەڵەی SQL دروست نەبێت
        $checkTable = $conn->query("SHOW TABLES LIKE 'user_a4_field_settings'");
        if ($checkTable && $checkTable->num_rows > 0) {
            $stmtFields = $conn->prepare("
                SELECT fields 
                FROM user_a4_field_settings 
                WHERE user_id = ? 
                LIMIT 1
            ");
            if ($stmtFields) {
                $stmtFields->bind_param('i', $currentUser['id']);
                $stmtFields->execute();
                $resFields = $stmtFields->get_result();
                if ($rowFields = $resFields->fetch_assoc()) {
                    if (!empty($rowFields['fields'])) {
                        $tmp = array_map('trim', explode(',', $rowFields['fields']));
                        $autoSelectedFields = array_filter($tmp, function($v) {
                            return $v !== '';
                        });
                    }
                }
                $stmtFields->close();
            }
        }
        if ($checkTable instanceof mysqli_result) {
            $checkTable->close();
        }
    } catch (Exception $e) {
        // هەڵەکە تەنیا لۆگ بکە، بۆ بەکارهێنەر هەموو فیلدەکان نیشان بدرێن
        error_log('A4 receipt auto field settings error: ' . $e->getMessage());
    }
}

// وەرگرتنی پارامیتەرەکانی فیلدەکان لە URL یان لە ڕێکخستنەکان
$fieldsParam = $_GET['fields'] ?? '';
$selectedFields = [];
if (!empty($fieldsParam)) {
    $selectedFields = explode(',', $fieldsParam);
    $selectedFields = array_map('trim', $selectedFields);
} elseif (!empty($autoSelectedFields)) {
    $selectedFields = $autoSelectedFields;
}

// فەنکشنی بۆ پشکنینی نیشاندان/شاردنەوەی فیلد
function shouldShowField($fieldName, $selectedFields) {
    // ئەگەر هیچ فیلدێک هەڵنەبژێردرابێت، هەموو فیلدەکان نیشان بدرێن
    if (empty($selectedFields)) {
        return true;
    }
    return in_array($fieldName, $selectedFields);
}

// وەرگرتنی ڕێکخستنەکان
$settingsStmt = $conn->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
$settingsStmt->bind_param('i', $currentUser['id']);
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

// کۆی بڕەکان بەپێی یەکە: ئەگەر fields ڕاستەوخۆ لە GET هاتبێت، تەنها کاتێک نیشان بدرێت کە unit_totals_summary لە لیستدا بێت
$showUnitTotalsSummary = shouldShowField('unit_totals_summary', $selectedFields);
if (array_key_exists('fields', $_GET)) {
    $fieldsGet = isset($_GET['fields']) ? (string) $_GET['fields'] : '';
    if ($fieldsGet === '') {
        $showUnitTotalsSummary = false;
    } else {
        $fieldsListGet = array_map('trim', explode(',', $fieldsGet));
        $fieldsListGet = array_values(array_filter($fieldsListGet, static function ($v) {
            return $v !== '';
        }));
        $showUnitTotalsSummary = in_array('unit_totals_summary', $fieldsListGet, true);
    }
}

$receiptA4ItemsFontSize = getReceiptA4ItemsFontSize((int)($currentUser['id'] ?? 0));

$pageTitle = "وەسڵی فرۆشتن #" . $saleId;
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderPremiumLockStylesheetLink(); ?>
    
    <style>
        :root {
            --receipt-a4-item-fs: <?php echo (int) $receiptA4ItemsFontSize; ?>px;
        }
        <?php if (!$hasA4ReceiptView): ?>
        body { overflow: hidden; }
        <?php endif; ?>

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

        /* Hide source elements */
        #source-header, #source-items, #source-returns, #source-totals, #source-footer, #source-notes {
            display: none !important;
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


        /* زانیاریەکانی وەسڵ لە خوار بانەرەکە بە شێوەی ئاسۆیی */
        .banner-info-horizontal {
            position: relative;
            width: 100%;
            background: white;
            padding: 8px 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 20px;
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

        /* زانیاریەکانی کڕیار - ڕیزێکی جیا */
        .customer-info-horizontal {
            position: relative;
            width: 100%;
            background: #f9fafb;
            padding: 8px 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-bottom: 2px solid #10b981;
        }

        .customer-info-horizontal .banner-info-label {
            font-size: 16px;
            font-weight: bold;
        }

        .customer-info-horizontal .banner-info-label i {
            color: #10b981;
            font-size: 16px;
        }

        .customer-info-horizontal .banner-info-value {
            font-size: 17px;
            font-weight: bold;
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
            /* Height will be managed dynamically */
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
            font-size: calc(var(--receipt-a4-item-fs) * 14 / 16);
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
            font-size: calc(var(--receipt-a4-item-fs) * 18 / 16);
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
            font-size: var(--receipt-a4-item-fs);
            border: 3px solid #e5e7eb;
        }

        .items-table .item-barcode-code {
            font-size: calc(var(--receipt-a4-item-fs) * 12 / 16);
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
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

        /* ستونی # بچووک دەمێنێتەوە، بەشەکانی تر (وەک ناوی کاڵا) فراوان دەبن */
        .items-table thead th.col-row-num,
        .items-table tbody td.row-number {
            width: 50px;
            max-width: 50px;
            min-width: 50px;
        }
        .items-table thead th.col-product-name,
        .items-table tbody td.col-product-name {
            width: auto;
            min-width: 25%;
        }

        /* Product Image Styles */
        .product-image-cell {
            text-align: center;
            vertical-align: middle;
        }

        .product-image-thumbnail {
            max-width: 60px;
            max-height: 60px;
            width: auto;
            height: auto;
            object-fit: contain;
            border-radius: 4px;
            border: 2px solid #e5e7eb;
            padding: 2px;
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .unit-info {
            font-size: calc(var(--receipt-a4-item-fs) * 9 / 16);
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
            page-break-inside: avoid;
            break-inside: avoid;
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

        .total-label-strong {
            font-weight: 700;
            color: #111827;
        }

        .total-value-strong {
            font-weight: 800;
            color: #000000;
        }

        .highlight .total-label,
        .highlight .total-value {
            color: white;
        }

        /* Print overrides: keep highlighted grand-total text white to match the on-screen design */
        @media print {
            .highlight .total-label,
            .highlight .total-value {
                color: #ffffff !important;
            }
        }

        .page-total-section .total-row.page-total-with-units {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px 12px;
        }

        .page-total-section .total-units {
            flex: 1 1 200px;
            text-align: center;
            font-weight: 600;
            color: #374151;
            font-size: 15px;
            min-width: 0;
            line-height: 1.4;
        }

        .receipt-units-grand-total .total-units-grand {
            font-weight: 700;
            color: #1f2937;
            text-align: left;
            max-width: 58%;
            line-height: 1.45;
        }

        /* Payment Method Badge */
        .payment-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            color: white;
        }
        /* نەقد - سەوز */
        .payment-badge:not(.payment-badge-debt) {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        /* قەرز - زەرد */
        .payment-badge-debt {
            background: linear-gradient(135deg, #eab308 0%, #ca8a04 100%);
            color: #1f2937;
        }

        .return-item-tag {
            font-size: calc(var(--receipt-a4-item-fs, 12px) * 0.85);
            color: #6b7280;
            font-weight: normal;
        }

        .banner-info-value .payment-badge {
            font-size: 12px;
            padding: 5px 14px;
        }

        /* Footer */
        .receipt-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            text-align: center;
            padding: 12px 0 15px;
            background: white;
            border-top: 1px solid #f3f4f6;
            z-index: 10;
        }

        .footer-text {
            font-size: 10px;
            color: #6b7280;
            line-height: 1.5;
            white-space: pre-line;
            margin-bottom: 8px;
            padding: 0 20px;
        }

        .brand-footer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 600;
            color: #4b5563;
            font-size: 12px;
            padding: 4px 12px;
            background: #f9fafb;
            border-radius: 20px;
            margin: 0 auto;
            width: fit-content;
            border: 1px solid #e5e7eb;
        }

        .brand-footer i {
            color: #6366f1;
            font-size: 14px;
        }

        /* Sale Notes Section (from POS) */
        .sale-notes {
            margin-top: 10px;
            padding: 10px;
            background: #ecfdf5;
            border-right: 3px solid #10b981;
            border-radius: 6px;
            text-align: right;
        }

        .sale-notes-title {
            font-size: 12px;
            font-weight: bold;
            color: #047857;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .sale-notes-content {
            font-size: 14px;
            color: #064e3b;
            line-height: 1.45;
            white-space: pre-line;
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
            font-size: 14px;
            color: #5d4037;
            line-height: 1.4;
            white-space: pre-line;
        }

        /* Customer Notes Section */
        .customer-notes {
            margin-top: 10px;
            padding: 10px;
            background: #e3f2fd;
            border-right: 3px solid #2196f3;
            border-radius: 6px;
            text-align: right;
        }

        .customer-notes-title {
            font-size: 11px;
            font-weight: bold;
            color: #1565c0;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .customer-notes-content {
            font-size: 11px;
            color: #0d47a1;
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
        /* Page Break Styles */
        .page-break {
            page-break-after: always;
            break-after: page;
        }
        
        /* Specific page class for JS generated pages */
        .page {
            position: relative;
            background: white;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            /* No page-break-after here, managed by print styles */
        }

        .page-content {
            flex: 1;
            padding: 10px 20px;
        }

        .page-number {
            text-align: center;
            font-size: 10px;
            color: #9ca3af;
            position: absolute;
            bottom: 60px; /* Moved up above footer */
            left: 0;
            right: 0;
            background: transparent;
            z-index: 5;
        }

        @media print {
            body {
                background: white;
                padding: 0;
                color: #000000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .page {
                box-shadow: none;
                margin: 0;
                page-break-after: always;
                min-height: 297mm;
                height: 297mm; /* Force fixed height for print */
                overflow: hidden;
            }
            
            /* Last page shouldn't force a page break if not needed, but safe to keep always */
            .page:last-child {
                page-break-after: auto;
            }

            .action-buttons {
                display: none !important;
            }

            .items-table tbody tr:hover {
                background: transparent;
            }

            /* چاپی A4 هەمیشە light بێت، dark mode کاریگەری نەکات */
            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .page,
            html[data-bs-theme='dark'] .a4-container,
            html[data-bs-theme='dark'] .content-wrapper,
            html[data-bs-theme='dark'] .receipt-footer,
            html[data-bs-theme='dark'] .receipt-info-card,
            html[data-bs-theme='dark'] .items-table,
            html[data-bs-theme='dark'] .totals-section,
            html[data-bs-theme='dark'] .banner-info-horizontal,
            html[data-bs-theme='dark'] .customer-info-horizontal,
            html[data-bs-theme='dark'] .brand-footer {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #d1d5db !important;
                box-shadow: none !important;
            }

            html[data-bs-theme='dark'] .items-table thead,
            html[data-bs-theme='dark'] .items-table thead th,
            html[data-bs-theme='dark'] .items-table tbody td,
            html[data-bs-theme='dark'] .total-row,
            html[data-bs-theme='dark'] .total-label,
            html[data-bs-theme='dark'] .total-value,
            html[data-bs-theme='dark'] .business-name,
            html[data-bs-theme='dark'] .footer-text,
            html[data-bs-theme='dark'] .page-number {
                color: #000000 !important;
                border-color: #d1d5db !important;
            }

            html[data-bs-theme='dark'] .items-table thead {
                background: #ffffff !important;
            }

            /* Grand-total highlight row keeps its colored background + white text even in dark theme */
            html[data-bs-theme='dark'] .highlight .total-label,
            html[data-bs-theme='dark'] .highlight .total-value {
                color: #ffffff !important;
            }

            .sale-notes {
                background: #ecfdf5 !important;
                border-color: #10b981 !important;
            }

            .sale-notes-title {
                color: #047857 !important;
            }

            .sale-notes-content {
                color: #064e3b !important;
            }
        }

        @page {
            size: A4;
            margin: 0;
        }

        html[data-bs-theme='dark'] body {
            background: #0b1220;
            color: #e5e7eb;
        }

        html[data-bs-theme='dark'] .page,
        html[data-bs-theme='dark'] .a4-container,
        html[data-bs-theme='dark'] .content-wrapper,
        html[data-bs-theme='dark'] .receipt-footer,
        html[data-bs-theme='dark'] .receipt-info-card,
        html[data-bs-theme='dark'] .items-table,
        html[data-bs-theme='dark'] .totals-section {
            background: #111827;
            color: #e5e7eb;
            border-color: #374151;
        }

        html[data-bs-theme='dark'] .items-table tbody tr:hover {
            background: #1f2937;
        }

        html[data-bs-theme='dark'] .total-label,
        html[data-bs-theme='dark'] .total-value,
        html[data-bs-theme='dark'] .banner-info-value,
        html[data-bs-theme='dark'] .business-name,
        html[data-bs-theme='dark'] .footer-text,
        html[data-bs-theme='dark'] .brand-footer {
            color: #e5e7eb;
        }
    </style>
    </head>
<body>
    <?php if (!$hasA4ReceiptView) {
        renderPremiumLockInlineOverlay('بینینی و چاپکردنی وەسڵی A4', [
            'back_url' => url('user/pos/sales.php'),
            'back_label' => 'گەڕانەوە بۆ فرۆشتنەکان',
        ]);
    } ?>

    <div class="<?php echo premiumLockContentClass($hasA4ReceiptView); ?>">
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

        <!-- Hidden Source Elements -->
    
    <!-- Source Header (Page 1 Only) -->
    <div id="source-header">
        <?php if ($settings && $settings['a4_receipt_banner']): ?>
            <!-- Custom Banner -->
            <div class="banner-wrapper">
                <img src="<?php echo htmlspecialchars(resolveA4BannerUrl($settings['a4_receipt_banner']), ENT_QUOTES, 'UTF-8'); ?>" alt="Banner" class="custom-banner">
            </div>
            
            <!-- زانیاریەکانی وەسڵ بە شێوەی ئاسۆیی لە خوار بانەرەکە -->
            <?php if (shouldShowField('receipt_number', $selectedFields) || shouldShowField('date', $selectedFields) || shouldShowField('time', $selectedFields) || shouldShowField('payment_method', $selectedFields)): ?>
            <div class="banner-info-horizontal">
                <?php if (shouldShowField('receipt_number', $selectedFields)): ?>
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-hash"></i>
                    </span>
                    <span class="banner-info-value">#<?php echo $saleId; ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (shouldShowField('date', $selectedFields)): ?>
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-calendar-event"></i>
                    </span>
                    <span class="banner-info-value"><?php echo $sale['short_date']; ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (shouldShowField('time', $selectedFields)): ?>
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-clock"></i>
                    </span>
                    <span class="banner-info-value"><?php echo $sale['time']; ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (shouldShowField('payment_method', $selectedFields)): ?>
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-credit-card"></i>
                    </span>
                    <span class="banner-info-value">
                        <span class="payment-badge<?php echo in_array($sale['payment_method'] ?? '', ['debt', 'credit']) ? ' payment-badge-debt' : ''; ?>">
                            <?php 
                            switch($sale['payment_method']) {
                                case 'cash': echo 'پارەی نەقد'; break;
                                case 'card': echo 'کارتی بانکی'; break;
                                case 'debt':
                                case 'credit':
                                    echo 'قەرز';
                                    break;
                                default: echo $sale['payment_method']; break;
                            }
                            ?>
                        </span>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- زانیاریەکانی کڕیار - ڕیزێکی جیا -->
            <?php if (shouldShowField('customer_info', $selectedFields) && (!empty($sale['customer_name']) || !empty($sale['customer_phone']) || !empty($sale['customer_id']) || !empty($sale['customer_address']))): ?>
            <div class="customer-info-horizontal">
                <?php if (!empty($sale['customer_name'])): ?>
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-person"></i>
                    </span>
                    <span class="banner-info-value"><?php echo htmlspecialchars($sale['customer_name']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($sale['customer_phone'])): ?>
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-phone"></i>
                    </span>
                    <span class="banner-info-value"><?php echo htmlspecialchars($sale['customer_phone']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($sale['customer_id'])): ?>
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-upc"></i>
                    </span>
                    <span class="banner-info-value"><?php echo htmlspecialchars($sale['customer_id']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($sale['customer_address'])): ?>
                <div class="banner-info-item">
                    <span class="banner-info-label">
                        <i class="bi bi-geo-alt"></i>
                    </span>
                    <span class="banner-info-value"><?php echo htmlspecialchars($sale['customer_address']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="content-wrapper <?php echo ($settings && $settings['a4_receipt_banner']) ? 'with-banner' : ''; ?>" style="padding-bottom: 0;">
            <!-- Receipt Info Card - Only show if no banner -->
            <?php if (!($settings && $settings['a4_receipt_banner']) && (shouldShowField('receipt_number', $selectedFields) || shouldShowField('date', $selectedFields) || shouldShowField('time', $selectedFields) || shouldShowField('payment_method', $selectedFields) || (shouldShowField('customer_info', $selectedFields) && !empty($sale['customer_name'])))): ?>
            <div class="receipt-info-card">
                <div class="receipt-title">
                    <i class="bi bi-receipt"></i>
                    وەسڵی فرۆشتن
                </div>
                
                <table class="info-table">
                    <?php if (shouldShowField('receipt_number', $selectedFields)): ?>
                    <tr>
                        <td class="info-label"><i class="bi bi-hash"></i></td>
                        <td class="info-value">#<?php echo $saleId; ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (shouldShowField('customer_info', $selectedFields) && !empty($sale['customer_name'])): ?>
                    <tr>
                        <td class="info-label"><i class="bi bi-person"></i> ناوی کڕیار</td>
                        <td class="info-value"><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (shouldShowField('date', $selectedFields)): ?>
                    <tr>
                        <td class="info-label"><i class="bi bi-calendar-event"></i></td>
                        <td class="info-value"><?php echo $sale['short_date']; ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (shouldShowField('time', $selectedFields)): ?>
                    <tr>
                        <td class="info-label"><i class="bi bi-clock"></i></td>
                        <td class="info-value"><?php echo $sale['time']; ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (shouldShowField('payment_method', $selectedFields)): ?>
                    <tr>
                        <td class="info-label"><i class="bi bi-credit-card"></i></td>
                        <td class="info-value">
                            <span class="payment-badge<?php echo in_array($sale['payment_method'] ?? '', ['debt', 'credit']) ? ' payment-badge-debt' : ''; ?>">
                                <?php 
                                switch($sale['payment_method']) {
                                    case 'cash': echo 'پارەی نەقد'; break;
                                    case 'card': echo 'کارتی بانکی'; break;
                                    case 'debt':
                                    case 'credit':
                                        echo 'قەرز';
                                        break;
                                    default: echo $sale['payment_method']; break;
                                }
                                ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>
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
                        <?php echo htmlspecialchars($currentUser['business_name']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($settings && $settings['receipt_header']): ?>
                    <div class="business-info">
                        <?php echo nl2br(htmlspecialchars($settings['receipt_header'])); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Source Items Table -->
    <div id="source-items">
        <table class="items-table">
            <thead>
                <tr>
                    <th class="col-row-num">#</th>
                    <?php if (shouldShowField('product_image', $selectedFields)): ?>
                    <th style="width: 80px;">وێنە</th>
                    <?php endif; ?>
                    <?php if (shouldShowField('product_name', $selectedFields)): ?>
                    <th class="col-product-name">ناوی کاڵا</th>
                    <?php endif; ?>
                    <?php if (shouldShowField('barcode', $selectedFields)): ?>
                    <th style="width: 12%;">بارکۆد</th>
                    <?php endif; ?>
                    <?php if (shouldShowField('quantity', $selectedFields)): ?>
                    <th style="width: 10%;">بڕ</th>
                    <?php endif; ?>
                    <?php if (shouldShowField('unit', $selectedFields)): ?>
                    <th style="width: 13%;">یەکە</th>
                    <?php endif; ?>
                    <?php if (shouldShowField('unit_price', $selectedFields)): ?>
                    <th style="width: 13%;">نرخی یەکە</th>
                    <?php endif; ?>
                    <?php if (shouldShowField('total', $selectedFields)): ?>
                    <th style="width: 17%;">کۆ</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rowNum = 1;
                foreach ($items as $item): 
                    // Format quantity
                    $rawQty = is_string($item['quantity']) ? $item['quantity'] : (string)$item['quantity'];
                    if (is_numeric($rawQty)) {
                        $normalizedQty = number_format((float)$rawQty, 6, '.', '');
                        $compactQty = rtrim(rtrim($normalizedQty, '0'), '.');
                    } else {
                        $compactQty = $rawQty;
                    }
                    
                    $itemTotal = $item['quantity'] * $item['unit_price'];
                    $qtyForData = null;
                    if (is_numeric($rawQty)) {
                        $qf = (float) $rawQty;
                        if (is_finite($qf)) {
                            $qtyForData = $qf;
                        }
                    }
                    $dataQtyAttr = $qtyForData !== null ? (string) $qtyForData : '';
                    $unitLabelForData = trim((string) ($item['unit_name'] ?? ''));
                    if ($unitLabelForData === '') {
                        $unitLabelForData = 'دانە';
                    }
                ?>
                    <tr data-total="<?php echo $itemTotal; ?>"
                        data-qty="<?php echo htmlspecialchars($dataQtyAttr, ENT_QUOTES, 'UTF-8'); ?>"
                        data-unit-name="<?php echo htmlspecialchars($unitLabelForData, ENT_QUOTES, 'UTF-8'); ?>">
                        <td class="row-number"><?php echo $rowNum++; ?></td>
                        <?php if (shouldShowField('product_image', $selectedFields)): ?>
                        <td class="product-image-cell">
                            <?php if (!empty($item['product_image_path']) && product_image_url($item['product_image_path'])): ?>
                                <img src="<?php echo htmlspecialchars(product_image_url($item['product_image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                     class="product-image-thumbnail">
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if (shouldShowField('product_name', $selectedFields)): ?>
                        <td class="col-product-name">
                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                        </td>
                        <?php endif; ?>
                        <?php if (shouldShowField('barcode', $selectedFields)): ?>
                        <td>
                            <?php if (!empty($item['barcode'])): ?>
                                <code class="item-barcode-code"><?php echo htmlspecialchars($item['barcode']); ?></code>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if (shouldShowField('quantity', $selectedFields)): ?>
                        <td>
                            <strong><?php echo $compactQty; ?></strong>
                            <?php if (!empty($item['unit_symbol'])): ?>
                                <?php echo htmlspecialchars($item['unit_symbol']); ?>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if (shouldShowField('unit', $selectedFields)): ?>
                        <td>
                            <?php echo !empty($item['unit_name']) ? htmlspecialchars($item['unit_name']) : '-'; ?>
                        </td>
                        <?php endif; ?>
                        <?php if (shouldShowField('unit_price', $selectedFields)): ?>
                        <td>
                            <?php 
                            $itemCurrency = $item['currency'] ?? $saleCurrency;
                            $decimals = $itemCurrency === 'USD' ? 2 : 0;
                            echo number_format($item['unit_price'], $decimals);
                            echo $itemCurrency === 'USD' ? '$' : ' د';
                            ?>
                        </td>
                        <?php endif; ?>
                        <?php if (shouldShowField('total', $selectedFields)): ?>
                        <td>
                            <strong><?php 
                            $itemCurrency = $item['currency'] ?? $saleCurrency;
                            $decimals = $itemCurrency === 'USD' ? 2 : 0;
                            echo number_format($itemTotal, $decimals);
                            echo $itemCurrency === 'USD' ? '$' : ' د';
                            ?></strong>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Source Returns Section (above totals) -->
    <div id="source-returns">
    <?php
    $saleReceiptReturnsIsA4 = true;
    include __DIR__ . '/../../includes/partials/sale_receipt_returns_block.php';
    ?>
    </div>

    <!-- Source Totals Section (Last Page) -->
    <div id="source-totals">
        <?php if (shouldShowField('totals', $selectedFields) && !($sale['payment_method'] == 'debt' || $sale['payment_method'] == 'credit')): ?>
        <?php if ($totalReturnedAmount > 0): ?>
            <?php
            $isA4 = true;
            $isDebt = false;
            include __DIR__ . '/../../includes/partials/sale_receipt_return_totals_summary.php';
            ?>
        <?php else: ?>
        <div class="totals-section" style="margin-top: 20px;">
            <?php 
            $decimals = $saleCurrency === 'USD' ? 2 : 0;
            $grandTotal = $netFinalAmount;
            if (!empty($sale['discount']) && $sale['discount'] > 0): 
            ?>
            <div class="total-row">
                <span class="total-label">کۆی گشتی:</span>
                <span class="total-value">
                    <?php echo number_format($sale['total_amount'] ?? 0, $decimals); ?>
                    <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?>
                </span>
            </div>
            <?php endif; ?>
            
            <?php if (shouldShowField('discount', $selectedFields) && $sale['discount'] > 0): ?>
                <div class="total-row">
                    <span class="total-label">داشکاندن:</span>
                    <span class="total-value" style="color: #ef4444;">
                        -<?php echo number_format($sale['discount'], $decimals); ?>
                        <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?>
                    </span>
                </div>
            <?php endif; ?>
            
            <?php 
            $tax = isset($sale['tax']) ? $sale['tax'] : 0;
            if (shouldShowField('tax', $selectedFields) && $tax > 0): 
            ?>
                <div class="total-row">
                    <span class="total-label">باج:</span>
                    <span class="total-value"><?php echo number_format($tax, $decimals); ?> <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($totalReturnedAmount > 0): ?>
            <div class="total-row">
                <span class="total-label">بڕی پارەی گەڕاوە:</span>
                <span class="total-value" style="color: #ef4444;">
                    -<?php echo number_format($totalReturnedAmount, $decimals); ?>
                    <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?>
                </span>
            </div>
            <?php endif; ?>
            
            <?php 
            // نیشاندانی "کۆی کۆتایی هەموو لاپەڕەکان:" تەنها ئەگەر "کۆی پارەی وەسڵەکە:" نیشان نەدرێت
            // چونکە لە حاڵەتی قەرز/کریتدا هەمان شتن
            if (!($sale['payment_method'] == 'debt' || $sale['payment_method'] == 'credit')): 
            ?>
            <div class="total-row highlight">
                <span class="total-label">کۆی کۆتایی هەموو لاپەڕەکان:</span>
                <span class="total-value">
                    <?php echo number_format($grandTotal, $decimals); ?>
                    <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (shouldShowField('totals', $selectedFields) && $sale['payment_method'] == 'cash' && $sale['paid_amount'] > $netFinalAmount): ?>
            <div class="totals-section" style="margin-top: 20px;">
                <div class="total-row">
                    <span class="total-label">پارەی پێدراو:</span>
                    <span class="total-value">
                        <?php 
                        $decimals = $saleCurrency === 'USD' ? 2 : 0;
                        echo number_format($sale['paid_amount'], $decimals); 
                        ?>
                        <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?>
                    </span>
                </div>
                <div class="total-row">
                    <span class="total-label">گەڕانەوە:</span>
                    <span class="total-value" style="color: #10b981;">
                        <?php 
                        $decimals = $saleCurrency === 'USD' ? 2 : 0;
                        $change = $sale['paid_amount'] - $netFinalAmount;
                        echo number_format($change, $decimals);
                        echo $saleCurrency === 'USD' ? '$' : ' دینار';
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <?php 
        // زانیاریەکانی پارەدان بۆ قەرز
        if (shouldShowField('totals', $selectedFields) && ($sale['payment_method'] == 'debt' || $sale['payment_method'] == 'credit')) {
            // ... (Same logic for debt display)
            // ...
             // پێناسەکردنی $grandTotal بۆ بەکارهێنانی لە بەشی قەرز
             $decimals = $saleCurrency === 'USD' ? 2 : 0;
             $grandTotal = $netFinalAmount;
             
             // وەرگرتنی قەرزی کۆنی کڕیار بە جیا بۆ هەر دراوێک
             $oldDebtIQD = 0;
             $oldDebtUSD = 0;
             $paidAmountIQD = 0;
             $paidAmountUSD = 0;
             $paidAmount = $paidReceivedAmount;
             if ($saleCurrency === 'USD') {
                 $paidAmountUSD = $paidAmount;
             } else {
                 $paidAmountIQD = $paidAmount;
             }
             
             if ($sale['customer_id']) {
                 // قەرزی کۆن بۆ دینار = کۆی قەرزەکان پێش ئەم فرۆشتنە بە دینار
                 $oldDebtIQDStmt = $conn->prepare("
                     SELECT COALESCE(SUM(d.remaining_amount), 0) as old_debt
                     FROM debts d
                     INNER JOIN sales s ON d.sale_id = s.id
                     WHERE d.customer_id = ? AND d.status = 'active' AND d.sale_id != ? 
                     AND COALESCE(s.currency, 'IQD') = 'IQD'
                 ");
                 $oldDebtIQDStmt->bind_param('ii', $sale['customer_id'], $saleId);
                 $oldDebtIQDStmt->execute();
                 $oldDebtIQDResult = $oldDebtIQDStmt->get_result()->fetch_assoc();
                 $oldDebtIQD = $oldDebtIQDResult['old_debt'] ?? 0;
                 
                 // قەرزی کۆن بۆ دۆلار = کۆی قەرزەکان پێش ئەم فرۆشتنە بە دۆلار
                 $oldDebtUSDStmt = $conn->prepare("
                     SELECT COALESCE(SUM(d.remaining_amount), 0) as old_debt
                     FROM debts d
                     INNER JOIN sales s ON d.sale_id = s.id
                     WHERE d.customer_id = ? AND d.status = 'active' AND d.sale_id != ? 
                     AND COALESCE(s.currency, 'IQD') = 'USD'
                 ");
                 $oldDebtUSDStmt->bind_param('ii', $sale['customer_id'], $saleId);
                 $oldDebtUSDStmt->execute();
                 $oldDebtUSDResult = $oldDebtUSDStmt->get_result()->fetch_assoc();
                 $oldDebtUSD = $oldDebtUSDResult['old_debt'] ?? 0;
             }
             
             // حیسابکردنی کۆتایی بڕی ماوە بە جیا بۆ هەر دراوێک (پاش کەمکردنەوەی گەڕاوە)
             $afterDiscount = (float)($sale['final_amount'] ?? 0);
             $invoiceNet = $netFinalAmount;
             
             if ($saleCurrency === 'USD') {
                 $totalRemainingUSD = $invoiceNet + $oldDebtUSD - $paidAmountUSD;
                 $totalRemainingIQD = $oldDebtIQD;
             } else {
                 $totalRemainingIQD = $invoiceNet + $oldDebtIQD - $paidAmountIQD;
                 $totalRemainingUSD = $oldDebtUSD;
             }

             if ($totalReturnedAmount > 0) {
                 $isA4 = true;
                 $isDebt = true;
                 include __DIR__ . '/../../includes/partials/sale_receipt_return_totals_summary.php';
             } else {
        ?>
               <div class="totals-section" style="margin-top: 20px; background: linear-gradient(135deg, #fff8e1 0%, #fffbf0 100%); border: 2px solid #f59e0b;">
               <div class="total-row">
                   <span class="total-label total-label-strong">کۆی پارەی وەسڵەکە:</span>
                   <span class="total-value total-value-strong"><?php echo number_format($sale['total_amount'] ?? 0, $decimals); ?> <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?></span>
               </div>
                
                <?php if ($sale['discount'] > 0): ?>
                <div class="total-row">
                    <span class="total-label">داشکاندن:</span>
                    <span class="total-value" style="color: #ef4444;">-<?php echo number_format($sale['discount'], $decimals); ?> <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?></span>
                </div>
                
                <div class="total-row">
                    <span class="total-label">پاش داشکاندن:</span>
                    <span class="total-value"><?php echo number_format($afterDiscount, $decimals); ?> <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?></span>
                </div>
                <?php endif; ?>
                
                <!-- قەرزی کۆن بە جیا بۆ هەر دراوێک -->
                <?php if ($oldDebtIQD > 0 || $oldDebtUSD > 0): ?>
             
               <?php if ($oldDebtIQD > 0): ?>
               <div class="total-row">
                   <span class="total-label total-label-strong" style="padding-right: 20px;">&nbsp;&nbsp;قەرزی کۆن (دینار):</span>
                   <span class="total-value total-value-strong" style="color: #f59e0b;"><?php echo number_format($oldDebtIQD, 0); ?> دینار</span>
               </div>
               <?php endif; ?>
                <?php if ($oldDebtUSD > 0): ?>
                <div class="total-row">
                    <span class="total-label" style="padding-right: 20px;">&nbsp;&nbsp;قەرزی کۆن (دۆلار):</span>
                    <span class="total-value" style="color: #f59e0b;"><?php echo number_format($oldDebtUSD, 2); ?> $</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($totalReturnedAmount > 0): ?>
                <div class="total-row">
                    <span class="total-label total-label-strong">بڕی پارەی گەڕاوە:</span>
                    <span class="total-value total-value-strong" style="color: #ef4444;">-<?php echo number_format($totalReturnedAmount, $decimals); ?> <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?></span>
                </div>
                <?php endif; ?>
                
               <?php if ($paidAmount > 0): ?>
               <div class="total-row">
                   <span class="total-label total-label-strong">بڕی پارەی واسڵکراو:</span>
                   <span class="total-value total-value-strong" style="color: #10b981;">-<?php echo number_format($paidAmount, $decimals); ?> <?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?></span>
               </div>
               <?php endif; ?>
               
               
               <?php if ($totalRemainingIQD > 0): ?>
               <div class="total-row highlight" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                   <span class="total-label total-label-strong" style="padding-right: 20px;">&nbsp;&nbsp;کۆتایی بڕی پارەی ماوە (دینار):</span>
                   <span class="total-value total-value-strong"><?php echo number_format($totalRemainingIQD, 0); ?> دینار</span>
               </div>
               <?php endif; ?>
               <?php if ($totalRemainingUSD > 0): ?>
               <div class="total-row highlight" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                   <span class="total-label total-label-strong" style="padding-right: 20px;">&nbsp;&nbsp;کۆتایی بڕی پارەی ماوە (دۆلار):</span>
                   <span class="total-value total-value-strong"><?php echo number_format($totalRemainingUSD, 2); ?> $</span>
               </div>
               <?php endif; ?>
               <?php if ($totalRemainingIQD == 0 && $totalRemainingUSD == 0): ?>
               <div class="total-row highlight" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                   <span class="total-label total-label-strong" style="padding-right: 20px;">&nbsp;&nbsp;کۆتایی بڕی پارەی ماوە:</span>
                   <span class="total-value total-value-strong">0</span>
               </div>
               <?php endif; ?>
            </div>
        <?php } ?>
        <?php } ?>
    </div>

    <!-- Source Notes (Last Page) -->
    <div id="source-notes">
        <!-- Customer Notes Section -->
        <?php if (shouldShowField('customer_note', $selectedFields) && !empty($sale['customer_note'])): ?>
            <div class="customer-notes">
                <div class="customer-notes-title">
                    <i class="bi bi-person-check"></i>
                    تێبینی کڕیار
                </div>
                <div class="customer-notes-content">
                    <?php echo nl2br(htmlspecialchars($sale['customer_note'])); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Sale Notes Section -->
        <?php if (!empty(trim((string)($sale['notes'] ?? '')))): ?>
            <div class="sale-notes">
                <div class="sale-notes-title">
                    <i class="bi bi-journal-text"></i>
                    تێبینی فرۆشتن
                </div>
                <div class="sale-notes-content">
                    <?php echo nl2br(htmlspecialchars($sale['notes'], ENT_QUOTES, 'UTF-8')); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Custom Notes Section -->
        <?php if ($settings && !empty($settings['a4_receipt_notes'])): ?>
            <div class="custom-notes">
                <div class="custom-notes-title">
                    <i class="bi bi-chat-left-dots"></i>
                    تێبینی
                </div>
                <div class="custom-notes-content">
                    <?php echo nl2br(htmlspecialchars($settings['a4_receipt_notes'])); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Source Footer (All Pages) -->
    <div id="source-footer">
        <div class="receipt-footer">
            <?php if ($settings && $settings['receipt_footer']): ?>
                <div class="footer-text">
                    <?php echo nl2br(htmlspecialchars($settings['receipt_footer'])); ?>
                </div>
            <?php endif; ?>
            
            <div class="brand-footer">
                <i class="bi bi-stars"></i> سیستەمی NexoraCore | NexoraCore.com
            </div>
        </div>
    </div>

    <!-- Container for Generated Pages -->
    <div id="print-container"></div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        window.onload = function() {
            const printContainer = document.getElementById('print-container');
            const sourceHeader = document.getElementById('source-header');
            const sourceItems = document.querySelector('#source-items table');
            const sourceReturns = document.getElementById('source-returns');
            const sourceTotals = document.getElementById('source-totals');
            const sourceNotes = document.getElementById('source-notes');
            const sourceFooter = document.getElementById('source-footer');
            
            // Configuration
            const A4_HEIGHT_MM = 297;
            const PADDING_MM = 20; // top + bottom padding
            // Convert mm to pixels (approx 3.78 px per mm)
            const PIXELS_PER_MM = 3.78;
            const MAX_HEIGHT_PX = (A4_HEIGHT_MM * PIXELS_PER_MM) - 20; // A bit more buffer
            
            // Get currency info for subtotal
            const saleCurrency = "<?php echo $saleCurrency === 'USD' ? '$' : ' دینار'; ?>";
            const isUSD = "<?php echo $saleCurrency === 'USD' ? 'true' : 'false'; ?>" === 'true';
            const showUnitTotalsSummary = <?php echo $showUnitTotalsSummary ? 'true' : 'false'; ?>;

            function aggregateUnitsFromTbody(tbody) {
                const map = new Map();
                const order = [];
                if (!tbody) return { map, order };
                const trs = tbody.querySelectorAll('tr');
                for (let i = 0; i < trs.length; i++) {
                    const tr = trs[i];
                    const raw = tr.dataset.qty;
                    if (raw === undefined || raw === '') continue;
                    const qty = parseFloat(raw);
                    if (!isFinite(qty)) continue;
                    let unitName = (tr.dataset.unitName || '').trim();
                    if (!unitName) unitName = 'دانە';
                    if (!map.has(unitName)) {
                        order.push(unitName);
                        map.set(unitName, 0);
                    }
                    map.set(unitName, map.get(unitName) + qty);
                }
                return { map, order };
            }

            function formatQtyUnitPart(qty, unitDisplayName) {
                const u = unitDisplayName || 'دانە';
                const rounded = Math.round(qty * 1e6) / 1e6;
                const isWhole = Math.abs(rounded - Math.round(rounded)) < 1e-9 && rounded >= 0;
                const formatted = isWhole
                    ? new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Math.round(rounded))
                    : new Intl.NumberFormat('en-US', {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 6
                    }).format(rounded);
                return formatted + ' ' + u;
            }

            function formatUnitPartsSummary(map, order) {
                const parts = [];
                for (let i = 0; i < order.length; i++) {
                    const key = order[i];
                    const sum = map.get(key);
                    if (sum == null || !isFinite(sum)) continue;
                    if (Math.abs(sum) < 1e-12) continue;
                    parts.push(formatQtyUnitPart(sum, key));
                }
                return parts.join(' و ');
            }

            function injectGrandUnitsSummary(totalsNode, sourceTbody, fallbackParent) {
                if (!showUnitTotalsSummary || !sourceTbody) return;
                const agg = aggregateUnitsFromTbody(sourceTbody);
                const text = formatUnitPartsSummary(agg.map, agg.order);
                if (!text) return;

                const row = document.createElement('div');
                row.className = 'total-row receipt-units-grand-total';
                const lab = document.createElement('span');
                lab.className = 'total-label';
                lab.textContent = 'کۆی بڕەکان (هەموو لاپەڕەکان):';
                const val = document.createElement('span');
                val.className = 'total-value total-units-grand';
                val.textContent = text;
                row.appendChild(lab);
                row.appendChild(val);

                if (totalsNode) {
                    const rowsAll = totalsNode.querySelectorAll('.total-row');
                    let cashGrand = null;
                    for (let j = 0; j < rowsAll.length; j++) {
                        const r = rowsAll[j];
                        const l = r.querySelector('.total-label');
                        if (l && l.textContent.indexOf('کۆی کۆتایی هەموو لاپەڕەکان') !== -1) {
                            cashGrand = r;
                            break;
                        }
                    }
                    if (cashGrand && cashGrand.parentNode) {
                        cashGrand.parentNode.insertBefore(row, cashGrand);
                        return;
                    }
                    const sections = totalsNode.querySelectorAll('.totals-section');
                    let debtSection = null;
                    for (let s = 0; s < sections.length; s++) {
                        const sec = sections[s];
                        if (sec.textContent.indexOf('کۆی پارەی وەسڵ') !== -1 &&
                            sec.textContent.indexOf('کۆی کۆتایی هەموو لاپەڕەکان') === -1) {
                            debtSection = sec;
                            break;
                        }
                    }
                    if (debtSection) {
                        debtSection.appendChild(row);
                        return;
                    }
                    const firstSec = totalsNode.querySelector('.totals-section');
                    if (firstSec) {
                        firstSec.appendChild(row);
                        return;
                    }
                }
                if (fallbackParent) {
                    const wrap = document.createElement('div');
                    wrap.className = 'totals-section';
                    wrap.style.marginTop = '20px';
                    wrap.appendChild(row);
                    fallbackParent.appendChild(wrap);
                }
            }

            // Helpers
            function createPage(pageNum) {
                const page = document.createElement('div');
                page.className = 'page';
                
                const contentWrapper = document.createElement('div');
                contentWrapper.className = 'content-wrapper';
                // Copy header styles if any specific
                contentWrapper.style.padding = '10px 20px';
                
                page.appendChild(contentWrapper);
                printContainer.appendChild(page);
                return { page, content: contentWrapper };
            }

            function createTable() {
                const table = document.createElement('table');
                table.className = 'items-table';
                // Clone header
                const thead = sourceItems.querySelector('thead').cloneNode(true);
                table.appendChild(thead);
                const tbody = document.createElement('tbody');
                table.appendChild(tbody);
                return { table, tbody };
            }

            function createSubtotalRow(total, tbody) {
                const wrap = document.createElement('div');
                wrap.className = 'totals-section page-total-section';
                wrap.style.marginTop = '15px';

                const decimals = isUSD ? 2 : 0;
                const formattedTotal = new Intl.NumberFormat('en-US', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                }).format(total);

                let unitsText = '';
                if (showUnitTotalsSummary) {
                    const agg = aggregateUnitsFromTbody(tbody);
                    unitsText = formatUnitPartsSummary(agg.map, agg.order);
                }

                const inner = document.createElement('div');
                inner.className = 'total-row' + (unitsText ? ' page-total-with-units' : '');

                const label = document.createElement('span');
                label.className = 'total-label';
                label.textContent = 'کۆی گشتی ئەم لاپەڕەیە:';
                inner.appendChild(label);

                if (unitsText) {
                    const unitsSpan = document.createElement('span');
                    unitsSpan.className = 'total-units';
                    unitsSpan.textContent = unitsText;
                    inner.appendChild(unitsSpan);
                }

                const val = document.createElement('span');
                val.className = 'total-value';
                val.textContent = formattedTotal + ' ' + saleCurrency;
                inner.appendChild(val);

                wrap.appendChild(inner);
                return wrap;
            }

            function createPageNumber(pageNum, totalPages) {
                const div = document.createElement('div');
                div.className = 'page-number';
                div.textContent = `لاپەڕەی ${pageNum} لە ${totalPages}`;
                return div;
            }
            
            function createFooter() {
                 const footer = sourceFooter.firstElementChild.cloneNode(true);
                 // Need to remove ID if it exists (it shouldn't but safe to check)
                 return footer;
            }
            
            function cloneElement(el) {
                if (!el) return null;
                const clone = el.cloneNode(true);
                clone.removeAttribute('id'); // Remove ID so CSS display:none doesn't apply
                return clone;
            }

            // Initialization
            let currentPageNum = 1;
            let currentStruct = createPage(currentPageNum);
            let currentPage = currentStruct.page;
            let currentContent = currentStruct.content;
            
            // Add Header to first page
            const headerClone = cloneElement(sourceHeader);
            if (headerClone) {
                currentContent.appendChild(headerClone);
            }

            // Add Table
            let currentTableStruct = createTable();
            let currentTable = currentTableStruct.table;
            let currentTbody = currentTableStruct.tbody;
            
            const itemsSection = document.createElement('div');
            itemsSection.className = 'items-section';
            itemsSection.appendChild(currentTable);
            currentContent.appendChild(itemsSection);

            // Add Footer placeholder
            let currentFooter = createFooter();
            currentPage.appendChild(currentFooter);

            // Process Items
            const rows = Array.from(sourceItems.querySelectorAll('tbody tr'));
            let pageTotal = 0;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i].cloneNode(true);
                currentTbody.appendChild(row);
                
                // Calculate height
                // Since we just appended, browser might not have laid it out yet.
                // Reading offsetHeight forces layout.
                const contentHeight = currentContent.offsetHeight;
                const footerHeight = currentFooter.offsetHeight;
                
                // Use actual page height if available (browser rendering), otherwise fallback
                const containerHeight = currentPage.offsetHeight || MAX_HEIGHT_PX;
                
                // We want to ensure content + footer doesn't overflow page height.
                const currentUsedHeight = contentHeight + footerHeight + 100; // increased buffer to prevent clipping
                
                if (currentUsedHeight > containerHeight) {
                    // Row doesn't fit
                    currentTbody.removeChild(row);
                    
                    // Add subtotal to current page
                    const subtotalDiv = createSubtotalRow(pageTotal, currentTbody);
                    currentContent.appendChild(subtotalDiv);
                    
                    // Start new page
                    currentPageNum++;
                    currentStruct = createPage(currentPageNum);
                    currentPage = currentStruct.page;
                    currentContent = currentStruct.content;
                    
                    // Add new table
                    currentTableStruct = createTable();
                    currentTable = currentTableStruct.table;
                    currentTbody = currentTableStruct.tbody;
                    
                    const newItemsSection = document.createElement('div');
                    newItemsSection.className = 'items-section';
                    newItemsSection.appendChild(currentTable);
                    currentContent.appendChild(newItemsSection);
                    
                    // Add row to new page
                    currentTbody.appendChild(row);
                    pageTotal = 0;
                    
                    // Add footer to new page
                    currentFooter = createFooter();
                    currentPage.appendChild(currentFooter);
                }
                
                // Add to total
                const rowTotal = parseFloat(row.dataset.total) || 0;
                pageTotal += rowTotal;
            }

            // Try adding Returns, Totals and Notes FIRST (without page subtotal)
            const returnsNode = cloneElement(sourceReturns);
            const totalsNode = cloneElement(sourceTotals);
            const notesNode = cloneElement(sourceNotes);

            function appendReturnsTotalsNotes(content) {
                if (returnsNode) content.appendChild(returnsNode);
                if (totalsNode) content.appendChild(totalsNode);
                if (notesNode) content.appendChild(notesNode);
            }

            function removeReturnsTotalsNotes(content) {
                if (returnsNode && returnsNode.parentNode === content) content.removeChild(returnsNode);
                if (totalsNode && totalsNode.parentNode === content) content.removeChild(totalsNode);
                if (notesNode && notesNode.parentNode === content) content.removeChild(notesNode);
            }

            appendReturnsTotalsNotes(currentContent);

            // Check if overflow after adding returns/totals/notes
            const contentHeight = currentContent.offsetHeight;
            const footerHeight = currentFooter.offsetHeight;
            const containerHeight = currentPage.offsetHeight || MAX_HEIGHT_PX;
            const totalHeight = contentHeight + footerHeight + 50;

            if (totalHeight > containerHeight) {
                 removeReturnsTotalsNotes(currentContent);
                 
                 const subtotalDiv = createSubtotalRow(pageTotal, currentTbody);
                 currentContent.appendChild(subtotalDiv);
                 
                 currentPageNum++;
                 currentStruct = createPage(currentPageNum);
                 currentPage = currentStruct.page;
                 currentContent = currentStruct.content;
                 
                 appendReturnsTotalsNotes(currentContent);
                 
                 currentFooter = createFooter();
                 currentPage.appendChild(currentFooter);
            } else {
                 if (currentPageNum > 1 && pageTotal > 0) {
                     removeReturnsTotalsNotes(currentContent);
                     
                     const subtotalDiv = createSubtotalRow(pageTotal, currentTbody);
                     currentContent.appendChild(subtotalDiv);
                     
                     appendReturnsTotalsNotes(currentContent);
                 }
            }

            const sourceTbodyForUnits = sourceItems ? sourceItems.querySelector('tbody') : null;
            injectGrandUnitsSummary(totalsNode, sourceTbodyForUnits, currentContent);

            // Add page numbers to all pages
            const pages = document.querySelectorAll('#print-container .page');
            pages.forEach((p, index) => {
                if (pages.length > 1) {
                    p.appendChild(createPageNumber(index + 1, pages.length));
                }
            });
        };
    </script>
</body>
</html>
