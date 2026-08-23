<?php
/**
 * پەڕەی وەسڵ - user/pos/receipt.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
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
 
$hasReceiptView = hasFeaturePermission('pos_receipt_view');

// کارمەندی سنووردار: تەنها بۆ فرۆشتنی خۆی
$ownOnlySubUserId = getSalesOwnOnlySubUserId($currentUser);
$ownFilter = ($ownOnlySubUserId !== null) ? " AND s.sub_user_id = ?" : "";

// وەرگرتنی زانیاری فرۆشتن
$stmt = $conn->prepare("
    SELECT s.*,
           COALESCE(s.currency, 'IQD') as currency,
           DATE_FORMAT(s.sale_date, '%Y-%m-%d %H:%i:%s') as formatted_date,
           DATE_FORMAT(s.sale_date, '%d/%m/%Y') as short_date,
           DATE_FORMAT(s.sale_date, '%H:%i') as time
    FROM sales s
    WHERE s.id = ? AND s.user_id = ?" . $ownFilter . "
");
if ($ownOnlySubUserId !== null) {
    $stmt->bind_param('iii', $saleId, $currentUser['id'], $ownOnlySubUserId);
} else {
    $stmt->bind_param('ii', $saleId, $currentUser['id']);
}
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) {
    setMessage('فرۆشتن نەدۆزرایەوە', 'error');
    redirect(url('user/dashboard.php'));
}

// Get sale currency
$saleCurrency = $sale['currency'] ?? 'IQD';

// وەرگرتنی ئایتمەکانی فرۆشتن
$itemsStmt = $conn->prepare("
    SELECT si.*, 
           COALESCE(si.currency, 'IQD') as currency,
           COALESCE(p.name, si.product_name) as product_name,
           p.barcode as barcode
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

// حیسابی کۆتایی وەسڵ کاتێک گەڕاوە هەیە (قەرزی کۆن + ماوەی کۆتایی)
$isDebtSale = in_array($sale['payment_method'] ?? '', ['debt', 'credit', 'installment'], true);
$oldDebtIQD = 0;
$oldDebtUSD = 0;
$paidAmount = 0;
$totalRemainingIQD = 0;
$totalRemainingUSD = 0;
if ($isDebtSale && $totalReturnedAmount > 0) {
    $paidAmount = getSaleReceiptPaidReceivedAmount($conn, (int)$currentUser['id'], $saleId, $sale);
    $paidAmountIQD = $saleCurrency === 'USD' ? 0 : $paidAmount;
    $paidAmountUSD = $saleCurrency === 'USD' ? $paidAmount : 0;

    if (!empty($sale['customer_id'])) {
        $oldDebtIQDStmt = $conn->prepare("
            SELECT COALESCE(SUM(d.remaining_amount), 0) as old_debt
            FROM debts d
            INNER JOIN sales s ON d.sale_id = s.id
            WHERE d.customer_id = ? AND d.status = 'active' AND d.sale_id != ?
            AND COALESCE(s.currency, 'IQD') = 'IQD'
        ");
        $oldDebtIQDStmt->bind_param('ii', $sale['customer_id'], $saleId);
        $oldDebtIQDStmt->execute();
        $oldDebtIQD = (float)($oldDebtIQDStmt->get_result()->fetch_assoc()['old_debt'] ?? 0);

        $oldDebtUSDStmt = $conn->prepare("
            SELECT COALESCE(SUM(d.remaining_amount), 0) as old_debt
            FROM debts d
            INNER JOIN sales s ON d.sale_id = s.id
            WHERE d.customer_id = ? AND d.status = 'active' AND d.sale_id != ?
            AND COALESCE(s.currency, 'IQD') = 'USD'
        ");
        $oldDebtUSDStmt->bind_param('ii', $sale['customer_id'], $saleId);
        $oldDebtUSDStmt->execute();
        $oldDebtUSD = (float)($oldDebtUSDStmt->get_result()->fetch_assoc()['old_debt'] ?? 0);
    }

    if ($saleCurrency === 'USD') {
        $totalRemainingUSD = $netFinalAmount + $oldDebtUSD - $paidAmountUSD;
        $totalRemainingIQD = $oldDebtIQD;
    } else {
        $totalRemainingIQD = $netFinalAmount + $oldDebtIQD - $paidAmountIQD;
        $totalRemainingUSD = $oldDebtUSD;
    }
}

// وەرگرتنی ڕێکخستنەکان
$settingsStmt = $conn->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
$settingsStmt->bind_param('i', $currentUser['id']);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();

if (!function_exists('resolveReceiptBannerUrl')) {
    function resolveReceiptBannerUrl(?string $bannerPath): string
    {
        $bannerPath = trim((string)$bannerPath);
        if ($bannerPath === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $bannerPath)) {
            return $bannerPath;
        }
        $normalized = ltrim($bannerPath, '/');
        if (strpos($normalized, 'img/receipts/receipt_banner/') === 0) {
            $spaceUrl = spaces_public_url_for_object_key($normalized);
            return is_string($spaceUrl) ? $spaceUrl : '';
        }
        return '';
    }
}

$receiptBannerUrl = ($settings && !empty($settings['receipt_banner']))
    ? resolveReceiptBannerUrl($settings['receipt_banner'])
    : '';

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
    
    <!-- Custom CSS -->
    <link href="<?php echo url('assets/css/style.css'); ?>" rel="stylesheet">
    <?php renderPremiumLockStylesheetLink(); ?>
    
    <style>
        <?php if (!$hasReceiptView): ?>
        body { overflow: hidden; }
        <?php endif; ?>
        @media print {
            body * { visibility: hidden; }
            .receipt-container, .receipt-container * { visibility: visible; }
            .receipt-container { 
                position: absolute; 
                left: 0; 
                top: 0; 
                width: 100%; 
            }
            .no-print { display: none !important; }
            .receipt { 
                border: none !important; 
                box-shadow: none !important;
                page-break-inside: avoid;
            }
            @page {
                margin: 1cm;
            }

            /* چاپ هەمیشە light بێت، تەنانەت لە dark mode */
            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .receipt,
            html[data-bs-theme='dark'] .customer-info,
            html[data-bs-theme='dark'] .receipt-total,
            html[data-bs-theme='dark'] .items-table tbody tr:nth-child(even) {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #000000 !important;
                box-shadow: none !important;
            }

            html[data-bs-theme='dark'] .items-table th,
            html[data-bs-theme='dark'] .items-table thead,
            html[data-bs-theme='dark'] .items-table td {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #000000 !important;
            }

            html[data-bs-theme='dark'] .receipt * {
                color: #000000 !important;
                border-color: #000000 !important;
            }

            .sale-notes {
                background: #fff8e1 !important;
                border-color: #f59e0b !important;
            }

            .sale-notes-title {
                color: #b45309 !important;
            }

            .sale-notes-content {
                color: #78350f !important;
            }
        }
        
        .receipt {
            max-width: 70mm;
            width: 70mm;
            margin: 0 auto;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            border: 2px solid #333;
            padding: 8px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-size: 10px;
        }
        
        /* بەشی سەرەوە - ناوی شوێن */
        .receipt-header {
            text-align: center;
            border-bottom: none;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .receipt-header h1 {
            font-size: 16px;
            font-weight: bold;
            color: #000;
            margin: 0;
            padding: 5px 0;
        }
        
        .receipt-header .business-info {
            font-size: 9px;
            color: #000;
            margin-top: 5px;
        }
        
        /* بەشی دووەم - زانیاری کڕیار */
        .customer-info {
            background: #e9ecef;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 10px;
            border: 3px solid #000;
        }
        
        .customer-info h3 {
            font-size: 12px;
            font-weight: bold;
            color: #000;
            margin: 0 0 8px 0;
            padding-bottom: 5px;
            border-bottom: 2px solid #000;
        }
        
        .customer-info .info-row {
            display: flex;
            padding: 3px 0;
            font-size: 10px;
        }
        
        .customer-info .info-label {
            font-weight: bold;
            color: #000;
            min-width: 60px;
        }
        
        .customer-info .info-value {
            color: #000;
            word-break: break-word;
        }
        
        /* بەشی سێیەم - خشتەی کاڵاکان */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        .items-table thead {
            background: #000;
            color: white;
            border-bottom: 4px solid #000;
        }
        
        .items-table th {
            padding: 5px 2px;
            text-align: center;
            font-size: 10px;
            font-weight: bold;
            border: 3px solid #000;
        }
        
        .items-table tbody tr {
            border-bottom: 2px solid #333;
        }
        
        .items-table tbody tr:nth-child(even) {
            background: #e9ecef;
        }
        
        .items-table td {
            padding: 5px 2px;
            text-align: center;
            font-size: 9px;
            color: #000;
            border: 2px solid #000;
            word-break: break-word;
        }
        
        .items-table td:first-child {
            text-align: right;
            font-weight: 600;
            font-size: 10px;
        }
        
        /* کۆی گشتی */
        .receipt-total {
            background: #e9ecef;
            padding: 8px;
            border-radius: 4px;
            border: 2px solid #000;
        }
        
        .receipt-total .total-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 10px;
            border-bottom: 3px solid #000;
            color: #000;
        }
        
        .receipt-total .total-row:last-child {
            border-bottom: none;
            border-top: 3px solid #000;
            margin-top: 5px;
            padding-top: 8px;
        }
        
        .receipt-total .total-row strong {
            font-weight: bold;
            color: #000;
        }
        
        .receipt-total .final-total {
            font-size: 14px;
            color: #000;
            font-weight: bold;
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 10px;
            padding-top: 0;
            font-size: 9px;
            color: #000;
            border-top: none !important;
        }

        .sale-notes {
            margin-top: 10px;
            padding: 8px;
            background: #fff8e1;
            border-right: 3px solid #f59e0b;
            border-radius: 4px;
            text-align: right;
        }

        .sale-notes-title {
            font-size: 10px;
            font-weight: bold;
            color: #b45309;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding-bottom: 4px;
            border-bottom: 1px dashed #f59e0b;
        }

        .sale-notes-content {
            font-size: 10px;
            color: #78350f;
            line-height: 1.45;
            word-break: break-word;
            white-space: pre-line;
        }
        
        @media print {
            .receipt {
                font-size: 10px;
                border: none;
                box-shadow: none;
            }
            .receipt-footer {
                border-top: none !important;
                padding-top: 0 !important;
            }
            .receipt-footer > div {
                border-top: none !important;
                padding-top: 0 !important;
            }
            .items-table th,
            .items-table td {
                font-size: 8px;
            }
            @page {
                size: 70mm auto;
                margin: 0;
            }
        }

        html[data-bs-theme='dark'] body {
            background: #0b1220 !important;
            color: #e5e7eb;
        }

        html[data-bs-theme='dark'] .receipt {
            background: #111827;
            border-color: #4b5563;
            color: #e5e7eb;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.45);
        }

        html[data-bs-theme='dark'] .customer-info,
        html[data-bs-theme='dark'] .receipt-total,
        html[data-bs-theme='dark'] .items-table tbody tr:nth-child(even) {
            background: #1f2937;
            border-color: #4b5563;
        }

        html[data-bs-theme='dark'] .items-table td,
        html[data-bs-theme='dark'] .items-table th,
        html[data-bs-theme='dark'] .receipt-total .total-row,
        html[data-bs-theme='dark'] .customer-info h3 {
            border-color: #4b5563;
        }

        html[data-bs-theme='dark'] .receipt *[style*="color: #000"],
        html[data-bs-theme='dark'] .receipt *[style*="color:#000"],
        html[data-bs-theme='dark'] .receipt-header h1,
        html[data-bs-theme='dark'] .receipt-header .business-info,
        html[data-bs-theme='dark'] .customer-info .info-label,
        html[data-bs-theme='dark'] .customer-info .info-value,
        html[data-bs-theme='dark'] .items-table td,
        html[data-bs-theme='dark'] .receipt-total .total-row,
        html[data-bs-theme='dark'] .receipt-total .total-row strong,
        html[data-bs-theme='dark'] .receipt-total .final-total,
        html[data-bs-theme='dark'] .receipt-footer {
            color: #e5e7eb !important;
        }

        html[data-bs-theme='dark'] .sale-notes {
            background: #422006;
            border-color: #f59e0b;
        }

        html[data-bs-theme='dark'] .sale-notes-title {
            color: #fcd34d;
            border-color: #b45309;
        }

        html[data-bs-theme='dark'] .sale-notes-content {
            color: #fde68a;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>
    
    <?php if (!$hasReceiptView) {
        renderPremiumLockInlineOverlay('بینین و چاپکردنی وەسڵ', [
            'back_url' => url('user/pos/sales.php'),
            'back_label' => 'گەڕانەوە بۆ فرۆشتنەکان',
        ]);
    } ?>
    
    <div class="container-fluid mt-4 <?php echo premiumLockContentClass($hasReceiptView); ?>">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                    <h2><i class="bi bi-receipt"></i> <?php echo $pageTitle; ?></h2>
                    <div>
                        <button class="btn btn-primary me-2" onclick="window.print()">
                            <i class="bi bi-printer"></i> چاپکردن
                        </button>
                        <a href="<?php echo url('user/pos/sales.php'); ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                        </a>
                    </div>
                </div>
                
                <!-- Receipt Container -->
                <div class="receipt-container">
                    <div class="receipt">
                        <!-- بەشی یەکەم: ناوی شوێن/فرۆشگا -->
                        <div class="receipt-header">
                            <?php if ($receiptBannerUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($receiptBannerUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                     alt="Banner" style="max-width: 100%; height: auto; margin-bottom: 10px;">
                            <?php else: ?>
                                <?php if ($settings && $settings['business_logo']): ?>
                                    <img src="<?php echo url('uploads/' . $settings['business_logo']); ?>"
                                         alt="Logo" style="max-width: 100px; max-height: 100px; margin-bottom: 15px;">
                                <?php endif; ?>

                                <h1><?php echo htmlspecialchars($currentUser['business_name']); ?></h1>
                            <?php endif; ?>

                            <?php if ($settings && $settings['receipt_header']): ?>
                                <div class="business-info">
                                    <?php echo nl2br(htmlspecialchars($settings['receipt_header'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- بەشی دووەم: زانیاری کڕیار و بەروار -->
                        <div class="customer-info">
                            <h3>زانیاری وەسڵ</h3>
                            
                            <div class="info-row">
                                <span class="info-label">ناوی کڕیار:</span>
                                <span class="info-value"><?php echo !empty($sale['customer_name']) ? htmlspecialchars($sale['customer_name']) : 'کڕیاری گشتی'; ?></span>
                            </div>
                            
                            <?php if (!empty($sale['customer_address'])): ?>
                            <div class="info-row">
                                <span class="info-label">ناونیشان:</span>
                                <span class="info-value"><?php echo htmlspecialchars($sale['customer_address']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($sale['customer_phone'])): ?>
                            <div class="info-row">
                                <span class="info-label">ژمارە مۆبایل:</span>
                                <span class="info-value"><?php echo htmlspecialchars($sale['customer_phone']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-row">
                                <span class="info-label">بەرواری فرۆشتن:</span>
                                <span class="info-value"><?php echo $sale['short_date']; ?> - <?php echo $sale['time']; ?></span>
                            </div>
                            
                            <div class="info-row">
                                <span class="info-label">ژمارەی وەسڵ:</span>
                                <span class="info-value">#<?php echo $saleId; ?></span>
                            </div>
                        </div>
                        
                        <!-- بەشی سێیەم: خشتەی کاڵاکان -->
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>ناوی کاڵا</th>
                                    <th>یەکە</th>
                                    <th>نرخ</th>
                                    <th>کۆی گشتی</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php 
                                    // Format quantity
                                    $rawQty = is_string($item['quantity']) ? $item['quantity'] : (string)$item['quantity'];
                                    if (is_numeric($rawQty)) {
                                        $normalizedQty = number_format((float)$rawQty, 6, '.', '');
                                        $compactQty = rtrim(rtrim($normalizedQty, '0'), '.');
                                    } else {
                                        $compactQty = $rawQty;
                                    }
                                    
                                    // Calculate item total
                                    $itemTotal = $item['quantity'] * $item['unit_price'];
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                            <?php if (!empty($item['barcode'])): ?>
                                                <br><small style="color: #000; font-size: 8px; font-weight: bold;">کۆد: <?php echo htmlspecialchars($item['barcode']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $compactQty; ?>
                                            <?php if (!empty($item['unit_symbol'])): ?>
                                                <?php echo htmlspecialchars($item['unit_symbol']); ?>
                                            <?php elseif (!empty($item['unit_name'])): ?>
                                                <?php echo htmlspecialchars($item['unit_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $itemCurrency = $item['currency'] ?? $saleCurrency;
                                            $decimals = $itemCurrency === 'USD' ? 2 : 0;
                                            echo number_format($item['unit_price'], $decimals);
                                            echo $itemCurrency === 'USD' ? '$' : ' د';
                                            ?>
                                        </td>
                                        <td>
                                            <strong>
                                                <?php 
                                                $decimals = $itemCurrency === 'USD' ? 2 : 0;
                                                echo number_format($itemTotal, $decimals);
                                                echo $itemCurrency === 'USD' ? '$' : ' د';
                                                ?>
                                            </strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php
                        $saleReceiptReturnsIsA4 = false;
                        include __DIR__ . '/../../includes/partials/sale_receipt_returns_block.php';
                        ?>
                        
                        <!-- کۆی گشتی -->
                        <div class="receipt-total">
                            <?php if ($totalReturnedAmount > 0): ?>
                            <?php
                            $isA4 = false;
                            $isDebt = $isDebtSale;
                            include __DIR__ . '/../../includes/partials/sale_receipt_return_totals_summary.php';
                            ?>
                            <?php else: ?>
                            <div class="total-row" style="font-size: 12px;">
                                <span style="color: #000; font-weight: bold;">کۆی گشتی:</span>
                                <span style="color: #000; font-weight: bold;">
                                    <?php 
                                    $decimals = $saleCurrency === 'USD' ? 2 : 0;
                                    echo number_format($sale['total_amount'] ?? 0, $decimals);
                                    echo $saleCurrency === 'USD' ? '$' : ' دینار';
                                    ?>
                                </span>
                            </div>
                            
                            <?php if ($sale['discount'] > 0): ?>
                                <div class="total-row" style="color: #d9534f;">
                                    <span>داشکاندن:</span>
                                    <span>
                                        -<?php 
                                        $decimals = $saleCurrency === 'USD' ? 2 : 0;
                                        echo number_format($sale['discount'], $decimals);
                                        echo $saleCurrency === 'USD' ? '$' : ' دینار';
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <?php 
                            $tax = isset($sale['tax']) ? $sale['tax'] : 0;
                            if ($tax > 0): 
                            ?>
                                <div class="total-row">
                                    <span>باج:</span>
                                    <span>
                                        <?php 
                                        $decimals = $saleCurrency === 'USD' ? 2 : 0;
                                        echo number_format($tax, $decimals);
                                        echo $saleCurrency === 'USD' ? '$' : ' دینار';
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="total-row" style="border-bottom: none;">
                                <strong class="final-total" style="color: #000;">کۆی کۆتایی:</strong>
                                <strong class="final-total" style="color: #000;">
                                    <?php 
                                    $decimals = $saleCurrency === 'USD' ? 2 : 0;
                                    echo number_format($netFinalAmount, $decimals);
                                    echo $saleCurrency === 'USD' ? '$' : ' دینار';
                                    ?>
                                </strong>
                            </div>
                            <?php endif; ?>
                            
                            <div class="total-row" style="border-bottom: none;">
                                <span><strong style="color: #000;">شێوازی پارەدان:</strong></span>
                                <span style="color: #000;">
                                    <?php 
                                    switch($sale['payment_method']) {
                                        case 'cash': echo 'پارەی نەقد'; break;
                                        case 'card': echo 'کارتی بانکی'; break;
                                        case 'debt': echo 'قەرز'; break;
                                        default: echo $sale['payment_method']; break;
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <?php if ($sale['payment_method'] == 'cash' && $sale['paid_amount'] > $netFinalAmount): ?>
                                <div class="total-row">
                                    <span>پارەی پێدراو:</span>
                                    <span>
                                        <?php 
                                        $decimals = $saleCurrency === 'USD' ? 2 : 0;
                                        echo number_format($sale['paid_amount'], $decimals);
                                        echo $saleCurrency === 'USD' ? '$' : ' دینار';
                                        ?>
                                    </span>
                                </div>
                                <div class="total-row" style="color: #5cb85c;">
                                    <span>گەڕانەوە:</span>
                                    <span>
                                        <?php 
                                        $decimals = $saleCurrency === 'USD' ? 2 : 0;
                                        echo number_format($sale['paid_amount'] - $netFinalAmount, $decimals);
                                        echo $saleCurrency === 'USD' ? '$' : ' دینار';
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

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
                        
                        <!-- Footer -->
                        <div class="receipt-footer">
                            <?php if ($settings && $settings['receipt_footer']): ?>
                                <div style="margin-bottom: 15px;">
                                    <?php echo nl2br(htmlspecialchars($settings['receipt_footer'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div style="margin-top: 15px; padding-top: 0; border-top: none !important;">
                                <div style="font-size: 18px; font-weight: bold; color: #000; margin-bottom: 8px;">
                                    سیستەمی NexoraCore
                                </div>
                                <div style="font-size: 16px; font-weight: 600; color: #000; letter-spacing: 1px;">
                                    NexoraCore.com
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="text-center mt-4 no-print">
                    <button class="btn btn-success me-2" onclick="window.print()">
                        <i class="bi bi-printer"></i> چاپکردن
                    </button>
                    <button class="btn btn-info me-2" onclick="shareReceipt()">
                        <i class="bi bi-share"></i> هاوبەشکردن
                    </button>
                    <a href="<?php echo url('user/pos/sales.php'); ?>" class="btn btn-secondary">
                        <i class="bi bi-list"></i> لیستی فرۆشتنەکان
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function shareReceipt() {
            <?php if (!$hasReceiptView): ?>
            alert('ئەم خزمەتگوزارییە لە پاکێجی خۆڕاییدا بەردەست نییە');
            return;
            <?php endif; ?>
            
            if (navigator.share) {
                navigator.share({
                    title: 'وەسڵی فرۆشتن #<?php echo $saleId; ?>',
                    text: 'وەسڵی فرۆشتن لە <?php echo htmlspecialchars($currentUser['business_name']); ?>',
                    url: window.location.href
                });
            } else {
                // Copy to clipboard
                navigator.clipboard.writeText(window.location.href).then(function() {
                    alert('بەستەری وەسڵ کۆپی کرا!');
                });
            }
        }
        
        // Auto print if print parameter is present
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === '1' <?php echo !$hasReceiptView ? '&& false' : ''; ?>) {
            setTimeout(() => {
                window.print();
            }, 1000);
        }
    </script>
</body>
</html>