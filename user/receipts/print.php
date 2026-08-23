<?php
/**
 * چاپکردنی وەسڵی پارەدانەوەی قەرز (68mm) - user/receipts/print.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];

$receiptId = (int)($_GET['id'] ?? 0);
$receiptNumber = trim((string)($_GET['receipt_number'] ?? ''));
$fromCreditSales = isset($_GET['from']) && $_GET['from'] === 'credit_sales';
$autoPrint = !isset($_GET['print']) || (string)$_GET['print'] === '1';

if ($receiptId <= 0 && $receiptNumber === '') {
    setMessage('ناسنامەی وەسڵ پێویستە', 'error');
    redirect(url($fromCreditSales ? 'user/customers/credit_sales.php' : 'user/customers/index.php'));
}

$sql = "
    SELECT dr.*,
           c.name AS customer_name,
           c.phone AS customer_phone,
           c.address AS customer_address,
           dp.notes AS payment_notes,
           dp.payment_date,
           d.total_debt,
           d.remaining_amount AS debt_remaining,
           d.id AS debt_id,
           u.business_name,
           u.phone AS business_phone,
           u.address AS business_address,
           COALESCE(NULLIF(dr.currency, ''), s.currency, 'IQD') AS currency
    FROM debt_receipts dr
    JOIN debt_payments dp ON dr.debt_payment_id = dp.id
    JOIN debts d ON dp.debt_id = d.id
    LEFT JOIN customers c ON dr.customer_id = c.id
    LEFT JOIN sales s ON d.sale_id = s.id
    JOIN users u ON dr.user_id = u.id
    WHERE dr.user_id = ?
";

if ($receiptId > 0) {
    $sql .= ' AND dr.id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $userId, $receiptId);
} else {
    $sql .= ' AND dr.receipt_number = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $userId, $receiptNumber);
}

$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$receipt) {
    setMessage('وەسڵ نەدۆزرایەوە', 'error');
    redirect(url($fromCreditSales ? 'user/customers/credit_sales.php' : 'user/customers/index.php'));
}

$receiptId = (int)$receipt['id'];
$currency = strtoupper(trim((string)($receipt['currency'] ?? 'IQD'))) === 'USD' ? 'USD' : 'IQD';
$decimals = $currency === 'USD' ? 2 : 0;
$paymentAmount = (float)($receipt['payment_amount'] ?? 0);
$debtRemaining = (float)($receipt['debt_remaining'] ?? 0);
$debtRemainingBefore = $debtRemaining + $paymentAmount;
$isMultiple = str_contains((string)($receipt['receipt_number'] ?? ''), 'RC-MULTI-')
    || str_contains((string)($receipt['notes'] ?? ''), 'کۆمەڵە');

$customerTotalRemaining = 0.0;
$customerId = (int)($receipt['customer_id'] ?? 0);
if ($customerId > 0) {
    $totalStmt = $conn->prepare("
        SELECT COALESCE(SUM(d.remaining_amount), 0) AS total_remaining
        FROM debts d
        LEFT JOIN sales s ON d.sale_id = s.id
        WHERE d.customer_id = ?
          AND d.user_id = ?
          AND d.status = 'active'
          AND COALESCE(NULLIF(s.currency, ''), 'IQD') = ?
    ");
    $totalStmt->bind_param('iis', $customerId, $userId, $currency);
    $totalStmt->execute();
    $customerTotalRemaining = (float)($totalStmt->get_result()->fetch_assoc()['total_remaining'] ?? 0);
    $totalStmt->close();
}

$updateStmt = $conn->prepare('UPDATE debt_receipts SET printed = 1 WHERE id = ? AND user_id = ?');
$updateStmt->bind_param('ii', $receiptId, $userId);
$updateStmt->execute();
$updateStmt->close();

$paymentMethods = [
    'cash' => 'نەقد',
    'credit' => 'قەرز',
    'bank_transfer' => 'گواستنەوەی بانکی',
    'check' => 'چەک',
];
$paymentMethodLabel = $paymentMethods[(string)($receipt['payment_method'] ?? 'cash')] ?? (string)($receipt['payment_method'] ?? 'نەقد');

$createdAt = strtotime((string)($receipt['payment_date'] ?? $receipt['receipt_date'] ?? 'now'));
$shortDate = date('d/m/Y', $createdAt);
$time = date('H:i', $createdAt);
$businessName = trim((string)($receipt['business_name'] ?? ''));
if ($businessName === '') {
    $businessName = (string)($currentUser['business_name'] ?? SITE_NAME);
}

$userNote = trim((string)($receipt['notes'] ?? ''));
$paymentNote = trim((string)($receipt['payment_notes'] ?? ''));
if ($paymentNote !== '' && str_contains($paymentNote, 'پارەدانی کۆمەڵە')) {
    $paymentNote = '';
}

$backUrl = url($fromCreditSales ? 'user/customers/credit_sales.php' : 'user/customers/index.php');
$pageTitle = 'وەسڵی پارەدانەوەی قەرز #' . (string)$receipt['receipt_number'];
$paperWidth = '68mm';
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        @page {
            size: <?php echo $paperWidth; ?> auto;
            margin: 2mm;
        }

        body {
            font-family: Tahoma, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            background: #e5e7eb;
            padding: 12px 0;
        }

        .no-print {
            width: <?php echo $paperWidth; ?>;
            max-width: 100%;
            margin: 0 auto 10px;
            text-align: center;
        }

        .no-print button,
        .no-print a {
            display: inline-block;
            margin: 3px;
            padding: 7px 12px;
            font-size: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            cursor: pointer;
        }

        .no-print button.primary {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .receipt {
            width: <?php echo $paperWidth; ?>;
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            padding: 8px 6px 10px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.12);
        }

        .center { text-align: center; }

        .receipt-title {
            font-size: 14px;
            font-weight: 700;
            margin: 4px 0 2px;
        }

        .receipt-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 10px;
            border: 1px solid #16a34a;
            border-radius: 999px;
            color: #16a34a;
            margin-bottom: 4px;
        }

        .business-name {
            font-size: 12px;
            font-weight: 700;
            margin-top: 2px;
        }

        .business-meta {
            font-size: 10px;
            color: #333;
            margin-top: 2px;
        }

        .divider {
            border-bottom: 1px dashed #333;
            margin: 7px 0;
        }

        .divider-thick {
            border-bottom: 2px solid #000;
            margin: 8px 0;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            margin: 3px 0;
            font-size: 11px;
        }

        .row .label {
            font-weight: 700;
            flex-shrink: 0;
        }

        .row .value {
            text-align: left;
            word-break: break-word;
        }

        .amount-box {
            text-align: center;
            border: 2px solid #000;
            border-radius: 6px;
            padding: 8px 4px;
            margin: 8px 0;
        }

        .amount-box .caption {
            font-size: 10px;
            margin-bottom: 3px;
        }

        .amount-box .value {
            font-size: 18px;
            font-weight: 800;
            line-height: 1.2;
            color: #16a34a;
        }

        .notes-box {
            border: 1px dashed #666;
            padding: 6px;
            margin: 6px 0;
            font-size: 10px;
        }

        .signature-row {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .signature-box {
            flex: 1;
            text-align: center;
            font-size: 9px;
        }

        .signature-line {
            border-bottom: 1px solid #333;
            height: 28px;
            margin-bottom: 4px;
        }

        .footer-brand {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #333;
            text-align: center;
            font-size: 10px;
        }

        .footer-brand .title {
            font-weight: 700;
            font-size: 11px;
        }

        .footer-brand .url {
            direction: ltr;
            display: inline-block;
            margin-top: 2px;
            color: #444;
        }

        @media print {
            body {
                background: #fff !important;
                padding: 0;
            }

            .no-print { display: none !important; }

            .receipt {
                width: <?php echo $paperWidth; ?>;
                max-width: <?php echo $paperWidth; ?>;
                margin: 0;
                padding: 2mm 1.5mm;
                box-shadow: none;
            }

            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .receipt,
            html[data-bs-theme='dark'] .receipt * {
                background: #fff !important;
                color: #000 !important;
                border-color: #000 !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body>
<div class="no-print">
    <button type="button" class="primary" onclick="window.print()">چاپکردن</button>
    <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">گەڕانەوە</a>
</div>

<div class="receipt">
    <div class="center">
        <span class="receipt-badge">پارەدانەوە</span>
        <div class="receipt-title">وەسڵی پارەدانەوەی قەرز</div>
        <div class="business-name"><?php echo htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if (!empty($receipt['business_phone'])): ?>
            <div class="business-meta">تەلەفۆن: <?php echo htmlspecialchars((string)$receipt['business_phone'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (!empty($receipt['business_address'])): ?>
            <div class="business-meta"><?php echo htmlspecialchars((string)$receipt['business_address'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </div>

    <div class="divider-thick"></div>

    <div class="row">
        <span class="label">ژمارەی وەسڵ:</span>
        <span class="value"><?php echo htmlspecialchars((string)$receipt['receipt_number'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="row">
        <span class="label">بەروار:</span>
        <span class="value"><?php echo htmlspecialchars($shortDate, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="row">
        <span class="label">کات:</span>
        <span class="value"><?php echo htmlspecialchars($time, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>

    <div class="divider"></div>

    <div class="row">
        <span class="label">کڕیار:</span>
        <span class="value"><?php echo htmlspecialchars((string)($receipt['customer_name'] ?: 'کڕیاری گشتی'), ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <?php if (!empty($receipt['customer_phone'])): ?>
        <div class="row">
            <span class="label">تەلەفۆن:</span>
            <span class="value"><?php echo htmlspecialchars((string)$receipt['customer_phone'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>
    <div class="row">
        <span class="label">شێوازی پارەدان:</span>
        <span class="value"><?php echo htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="row">
        <span class="label">دراو:</span>
        <span class="value"><?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <?php if (!$isMultiple): ?>
        <div class="row">
            <span class="label">قەرزی ئەم وەسڵە (پێش):</span>
            <span class="value"><?php echo number_format($debtRemainingBefore, $decimals) . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="row">
            <span class="label">قەرزی ئەم وەسڵە (دوای):</span>
            <span class="value"><?php echo number_format(max(0, $debtRemaining), $decimals) . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>
    <div class="row">
        <span class="label">کۆی قەرزی ماوەی کڕیار:</span>
        <span class="value"><?php echo number_format(max(0, $customerTotalRemaining), $decimals) . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>

    <div class="amount-box">
        <div class="caption">بڕی پارەی وەرگیراو</div>
        <div class="value">
            +<?php echo number_format($paymentAmount, $decimals) . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>

    <?php if ($userNote !== ''): ?>
        <div class="notes-box">
            <strong>تێبینی:</strong>
            <?php echo nl2br(htmlspecialchars($userNote, ENT_QUOTES, 'UTF-8')); ?>
        </div>
    <?php endif; ?>
    <?php if ($paymentNote !== '' && $paymentNote !== $userNote): ?>
        <div class="notes-box">
            <strong>تێبینی:</strong>
            <?php echo nl2br(htmlspecialchars($paymentNote, ENT_QUOTES, 'UTF-8')); ?>
        </div>
    <?php endif; ?>

    <div class="signature-row">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div>واژووی کڕیار</div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div>واژووی بەرپرس</div>
        </div>
    </div>

    <div class="footer-brand">
        <div class="title">NexoraCore</div>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>
    window.addEventListener('load', function () {
        setTimeout(function () {
            window.print();
        }, 350);
    });
</script>
<?php endif; ?>
</body>
</html>
