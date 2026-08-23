<?php
/**
 * وەسڵی سڕینەوەی فرۆشتن - user/pos/receipt_void.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
$token = trim((string)($_GET['token'] ?? ''));
$autoPrint = isset($_GET['print']) && (string)$_GET['print'] === '1';

if ($token === '') {
    setMessage('تۆکنی وەسڵ پێویستە', 'error');
    redirect(url('user/pos/sales.php'));
}

$voidData = $_SESSION['sale_void_receipt'][$token] ?? null;
if (!$voidData || !is_array($voidData)) {
    setMessage('وەسڵی سڕینەوە نەدۆزرایەوە یان بەسەرچووە', 'error');
    redirect(url('user/pos/sales.php'));
}

if ((int)($voidData['user_id'] ?? 0) !== $userId) {
    setMessage('مافی بینینی ئەم وەسڵەت نییە', 'error');
    redirect(url('user/pos/sales.php'));
}

$expiresAt = (int)($voidData['expires_at'] ?? 0);
if ($expiresAt > 0 && $expiresAt < time()) {
    unset($_SESSION['sale_void_receipt'][$token]);
    setMessage('وەسڵی سڕینەوە بەسەرچووە', 'error');
    redirect(url('user/pos/sales.php'));
}

$sale = $voidData['sale'] ?? [];
$items = $voidData['items'] ?? [];
$deletedAt = (string)($voidData['deleted_at'] ?? date('Y-m-d H:i:s'));
$deletedBy = trim((string)($voidData['deleted_by'] ?? ''));
$walletReversedAmount = (float)($voidData['wallet_reversed_amount'] ?? 0);
$walletName = trim((string)($voidData['wallet_name'] ?? ''));

$saleCurrency = strtoupper((string)($sale['currency'] ?? 'IQD')) === 'USD' ? 'USD' : 'IQD';
$decimals = $saleCurrency === 'USD' ? 2 : 0;
$currencySuffix = $saleCurrency === 'USD' ? '$' : ' دینار';

$saleDateRaw = (string)($sale['sale_date'] ?? '');
$saleShortDate = $saleDateRaw !== '' ? date('d/m/Y', strtotime($saleDateRaw)) : '-';
$saleTime = $saleDateRaw !== '' ? date('H:i', strtotime($saleDateRaw)) : '-';
$deletedShortDate = date('d/m/Y', strtotime($deletedAt));
$deletedTime = date('H:i', strtotime($deletedAt));

$invoiceNumber = trim((string)($sale['invoice_number'] ?? ''));
$customerName = trim((string)($sale['customer_name'] ?? ''));
$paymentMethod = (string)($sale['payment_method'] ?? '');
$totalAmount = (float)($sale['total_amount'] ?? 0);
$discount = (float)($sale['discount'] ?? 0);
$finalAmount = (float)($sale['final_amount'] ?? 0);

$paymentMethodLabel = match ($paymentMethod) {
    'cash' => 'پارەی نەقد',
    'card' => 'کارتی بانکی',
    'debt', 'credit' => 'قەرز',
    default => $paymentMethod !== '' ? $paymentMethod : '-',
};

$settingsStmt = $conn->prepare('SELECT * FROM settings WHERE user_id = ? LIMIT 1');
$settingsStmt->bind_param('i', $userId);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

$businessName = trim((string)($currentUser['business_name'] ?? SITE_NAME));
$pageTitle = 'وەسڵی سڕینەوەی فرۆشتن';
$receiptNumber = $invoiceNumber !== '' ? $invoiceNumber : ('#' . (int)($sale['id'] ?? 0));

unset($_SESSION['sale_void_receipt'][$token]);
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title><?php echo htmlspecialchars($pageTitle . ' - ' . SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo url('assets/css/style.css'); ?>" rel="stylesheet">
    <style>
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
                size: 70mm auto;
                margin: 0;
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

        .void-banner {
            text-align: center;
            background: #dc3545;
            color: #fff;
            font-weight: bold;
            font-size: 14px;
            padding: 6px 4px;
            margin-bottom: 8px;
            border: 2px solid #000;
            letter-spacing: 1px;
        }

        .receipt-header {
            text-align: center;
            padding-bottom: 8px;
            margin-bottom: 8px;
            border-bottom: 2px dashed #000;
        }

        .receipt-header h1 {
            font-size: 14px;
            font-weight: bold;
            color: #000;
            margin: 0 0 4px 0;
        }

        .receipt-header .business-info {
            font-size: 9px;
            color: #000;
        }

        .customer-info {
            background: #e9ecef;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 10px;
            border: 2px solid #000;
        }

        .customer-info .info-row {
            display: flex;
            padding: 3px 0;
            font-size: 10px;
        }

        .customer-info .info-label {
            font-weight: bold;
            color: #000;
            min-width: 72px;
        }

        .customer-info .info-value {
            color: #000;
            word-break: break-word;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .items-table thead {
            background: #000;
            color: white;
        }

        .items-table th,
        .items-table td {
            padding: 4px 2px;
            text-align: center;
            font-size: 9px;
            border: 1px solid #000;
            word-break: break-word;
        }

        .items-table td:first-child {
            text-align: right;
            font-weight: 600;
        }

        .receipt-total {
            background: #e9ecef;
            padding: 8px;
            border-radius: 4px;
            border: 2px solid #000;
            margin-bottom: 8px;
        }

        .receipt-total .total-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 10px;
            border-bottom: 1px solid #000;
            color: #000;
        }

        .receipt-total .total-row:last-child {
            border-bottom: none;
            border-top: 2px solid #000;
            margin-top: 4px;
            padding-top: 6px;
            font-weight: bold;
            font-size: 12px;
        }

        .wallet-info {
            background: #fff3cd;
            border: 2px solid #856404;
            padding: 8px;
            margin-bottom: 8px;
            font-size: 10px;
            color: #000;
        }

        .receipt-footer {
            text-align: center;
            margin-top: 8px;
            font-size: 9px;
            color: #000;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="receipt-container">
            <div class="receipt">
                <div class="void-banner">VOID — سڕاوە</div>

                <div class="receipt-header">
                    <h1><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div class="business-info"><?php echo htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>

                <div class="customer-info">
                    <div class="info-row">
                        <span class="info-label">ژمارەی وەسڵ:</span>
                        <span class="info-value"><?php echo htmlspecialchars($receiptNumber, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php if ($customerName !== ''): ?>
                    <div class="info-row">
                        <span class="info-label">کڕیار:</span>
                        <span class="info-value"><?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label">بەرواری فرۆشتن:</span>
                        <span class="info-value"><?php echo htmlspecialchars($saleShortDate . ' - ' . $saleTime, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">بەرواری سڕینەوە:</span>
                        <span class="info-value"><?php echo htmlspecialchars($deletedShortDate . ' - ' . $deletedTime, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php if ($deletedBy !== ''): ?>
                    <div class="info-row">
                        <span class="info-label">سڕدرایەوە لەلایەن:</span>
                        <span class="info-value"><?php echo htmlspecialchars($deletedBy, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($items)): ?>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>ناوی کاڵا</th>
                            <th>بڕ</th>
                            <th>نرخ</th>
                            <th>کۆ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $itemCurrency = strtoupper((string)($item['currency'] ?? $saleCurrency)) === 'USD' ? 'USD' : 'IQD';
                            $itemDecimals = $itemCurrency === 'USD' ? 2 : 0;
                            $itemSuffix = $itemCurrency === 'USD' ? '$' : ' د';
                            $qty = (float)($item['quantity'] ?? 0);
                            $unitPrice = (float)($item['unit_price'] ?? 0);
                            $itemTotal = (float)($item['total_price'] ?? ($qty * $unitPrice));
                            $unitLabel = trim((string)($item['unit_symbol'] ?? $item['unit_name'] ?? ''));
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($item['product_name'] ?? 'کاڵا'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php echo rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'); ?>
                                    <?php if ($unitLabel !== ''): ?><?php echo htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                                </td>
                                <td><?php echo number_format($unitPrice, $itemDecimals) . $itemSuffix; ?></td>
                                <td><strong><?php echo number_format($itemTotal, $itemDecimals) . $itemSuffix; ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <div class="receipt-total">
                    <?php if ($discount > 0): ?>
                    <div class="total-row">
                        <span>کۆی گشتی:</span>
                        <span><?php echo number_format($totalAmount, $decimals) . $currencySuffix; ?></span>
                    </div>
                    <div class="total-row">
                        <span>داشکاندن:</span>
                        <span><?php echo number_format($discount, $decimals) . $currencySuffix; ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="total-row">
                        <span>کۆی کۆتایی:</span>
                        <span><?php echo number_format($finalAmount, $decimals) . $currencySuffix; ?></span>
                    </div>
                    <div class="total-row">
                        <span>شێوازی پارەدان:</span>
                        <span><?php echo htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>

                <?php if ($walletReversedAmount > 0): ?>
                <div class="wallet-info">
                    <strong>گەڕاندنەوەی قاسە:</strong><br>
                    پارە گەڕایەوە بۆ قاسە:
                    <strong><?php echo number_format($walletReversedAmount, $decimals) . $currencySuffix; ?></strong>
                    <?php if ($walletName !== ''): ?>
                        <br>قاسە: <?php echo htmlspecialchars($walletName, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="receipt-footer">
                    <?php if ($settings && !empty($settings['receipt_footer'])): ?>
                        <div style="margin-bottom: 8px;"><?php echo nl2br(htmlspecialchars((string)$settings['receipt_footer'], ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php endif; ?>
                    <div>سیستەمی NexoraCore — NexoraCore.com</div>
                </div>
            </div>
        </div>

        <div class="text-center mt-4 no-print">
            <button class="btn btn-success me-2" onclick="window.print()">
                <i class="bi bi-printer"></i> چاپکردن
            </button>
            <a href="<?php echo url('user/pos/sales.php'); ?>" class="btn btn-secondary">
                <i class="bi bi-list"></i> لیستی فرۆشتنەکان
            </a>
        </div>
    </div>

    <script>
        <?php if ($autoPrint): ?>
        setTimeout(function () {
            window.print();
        }, 800);
        <?php endif; ?>
    </script>
</body>
</html>
