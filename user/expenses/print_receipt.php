<?php
/**
 * چاپکردنی وەسڵی خەرجی (72mm) - user/expenses/print_receipt.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
/** @var mysqli $conn */

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
enforceAuthorizationOrDeny($currentUser, 'expenses.view', [
    'route' => '/user/expenses/print_receipt.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
], 'redirect');
requireExpensesModuleAccess();

if (function_exists('ensureExpensesSchemaTables')) {
    ensureExpensesSchemaTables($conn);
}

$expenseId = (int)($_GET['id'] ?? 0);
if ($expenseId <= 0) {
    setMessage('ناسنامەی خەرجی پێویستە', 'error');
    redirect(url('user/expenses/index.php'));
}

$stmt = $conn->prepare("
    SELECT e.*,
           et.name AS expense_type_name,
           w.name AS wallet_name,
           ec.creditor_name,
           ec.creditor_phone,
           ec.due_date,
           ec.total_amount AS credit_total,
           ec.remaining_amount AS credit_remaining,
           u.business_name,
           u.phone AS business_phone,
           u.address AS business_address
    FROM expenses e
    LEFT JOIN expense_types et ON e.expense_type_id = et.id
    LEFT JOIN wallets w ON e.wallet_id = w.id AND w.user_id = e.user_id
    LEFT JOIN expense_credits ec ON ec.expense_id = e.id AND ec.user_id = e.user_id
    LEFT JOIN users u ON u.id = e.user_id
    WHERE e.id = ? AND e.user_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $expenseId, $userId);
$stmt->execute();
$expense = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$expense) {
    setMessage('وەسڵی خەرجی نەدۆزرایەوە', 'error');
    redirect(url('user/expenses/index.php'));
}

$settingsStmt = $conn->prepare('SELECT business_logo, receipt_header FROM settings WHERE user_id = ? LIMIT 1');
$settingsStmt->bind_param('i', $userId);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc() ?: [];
$settingsStmt->close();

$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
$paperWidth = '72mm';

$isCredit = (string)($expense['payment_method'] ?? '') === 'credit';
$paymentLabel = $isCredit ? 'قەرز' : 'نەقد';
$amount = (float)($expense['amount'] ?? 0);
$currency = strtoupper((string)($expense['currency'] ?? 'IQD')) === 'USD' ? 'USD' : 'IQD';
$expenseDateTs = strtotime((string)($expense['expense_date'] ?? 'now'));
$shortDate = date('d/m/Y', $expenseDateTs);
$time = date('H:i', $expenseDateTs);
$systemReceiptNumber = 'EXP-' . date('Ymd', $expenseDateTs) . '-' . str_pad((string)$expenseId, 6, '0', STR_PAD_LEFT);
$externalReceiptNumber = trim((string)($expense['receipt_number'] ?? ''));
$businessName = trim((string)($expense['business_name'] ?? ''));
if ($businessName === '') {
    $businessName = (string)($currentUser['business_name'] ?? SITE_NAME);
}
$businessPhone = trim((string)($expense['business_phone'] ?? ''));
$businessAddress = trim((string)($expense['business_address'] ?? ''));
$description = trim((string)($expense['description'] ?? ''));
$isRecurring = (int)($expense['is_recurring'] ?? 0) === 1;
$expenseTypeLabel = $isRecurring ? 'دووبارە' : 'یەک جار';
$pageTitle = 'وەسڵی خەرجی #' . $systemReceiptNumber;
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Tahoma, 'Segoe UI', Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #000;
            background: #e5e7eb;
            padding: 12px 0;
        }

        .no-print {
            max-width: <?php echo $paperWidth; ?>;
            width: 100%;
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

        .receipt-wrap {
            max-width: <?php echo $paperWidth; ?>;
            margin: 0 auto;
        }

        .receipt {
            width: 100%;
            max-width: <?php echo $paperWidth; ?>;
            margin: 0 auto;
            background: #fff;
            padding: 8px 6px 10px;
            border: 2px solid #333;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.12);
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .center { text-align: center; }

        .receipt-logo {
            max-width: 100%;
            max-height: 56px;
            display: block;
            margin: 0 auto 6px;
            object-fit: contain;
        }

        .receipt-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 10px;
            border: 1px solid #000;
            border-radius: 999px;
            margin-bottom: 4px;
        }

        .receipt-badge.cash { border-color: #16a34a; color: #16a34a; }
        .receipt-badge.credit { border-color: #d97706; color: #d97706; }

        .receipt-title {
            font-size: 14px;
            font-weight: 700;
            margin: 4px 0 2px;
        }

        .receipt-subtitle {
            font-size: 10px;
            color: #444;
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
        }

        .amount-box .value.cash { color: #dc2626; }
        .amount-box .value.credit { color: #d97706; }

        .credit-box {
            border: 1px dashed #666;
            padding: 6px;
            margin: 6px 0;
            font-size: 10px;
        }

        .credit-box .title {
            font-weight: 700;
            margin-bottom: 4px;
            text-align: center;
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
                margin: 0;
                padding: 0;
                background: #fff !important;
            }

            body * {
                visibility: hidden;
            }

            .receipt-wrap,
            .receipt-wrap * {
                visibility: visible;
            }

            .no-print {
                display: none !important;
            }

            .receipt-wrap {
                position: absolute;
                left: 0;
                top: 0;
                width: <?php echo $paperWidth; ?>;
                max-width: <?php echo $paperWidth; ?>;
                margin: 0;
            }

            .receipt {
                border: none;
                width: 100%;
                max-width: <?php echo $paperWidth; ?>;
                padding: 2mm 1.5mm;
                box-shadow: none;
                page-break-inside: avoid;
            }

            @page {
                margin: 0;
                size: <?php echo $paperWidth; ?> auto;
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
    <a href="<?php echo url('user/expenses/details.php?id=' . $expenseId); ?>">وردەکاری</a>
    <a href="<?php echo url('user/expenses/index.php'); ?>">گەڕانەوە</a>
</div>

<div class="receipt-wrap">
    <div class="receipt">
        <div class="center">
            <?php if (!empty($settings['business_logo'])): ?>
                <img
                    src="<?php echo htmlspecialchars(url('uploads/' . $settings['business_logo']), ENT_QUOTES, 'UTF-8'); ?>"
                    alt=""
                    class="receipt-logo"
                >
            <?php endif; ?>

            <span class="receipt-badge <?php echo $isCredit ? 'credit' : 'cash'; ?>">
                <?php echo htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <div class="receipt-title">وەسڵی خەرجی</div>
            <div class="receipt-subtitle">NexoraCore</div>
            <div class="business-name"><?php echo htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'); ?></div>

            <?php if (!empty($settings['receipt_header'])): ?>
                <div class="business-meta">
                    <?php echo nl2br(htmlspecialchars((string)$settings['receipt_header'], ENT_QUOTES, 'UTF-8')); ?>
                </div>
            <?php else: ?>
                <?php if ($businessPhone !== ''): ?>
                    <div class="business-meta">تەلەفۆن: <?php echo htmlspecialchars($businessPhone, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if ($businessAddress !== ''): ?>
                    <div class="business-meta"><?php echo htmlspecialchars($businessAddress, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="divider-thick"></div>

        <div class="row">
            <span class="label">ژمارەی وەسڵ:</span>
            <span class="value"><?php echo htmlspecialchars($systemReceiptNumber, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php if ($externalReceiptNumber !== ''): ?>
        <div class="row">
            <span class="label">پسوڵەی دەرەکی:</span>
            <span class="value"><?php echo htmlspecialchars($externalReceiptNumber, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>
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
            <span class="label">ناوی خەرجی:</span>
            <span class="value"><?php echo htmlspecialchars((string)$expense['expense_name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="row">
            <span class="label">جۆر:</span>
            <span class="value"><?php echo htmlspecialchars($expenseTypeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="row">
            <span class="label">شێوازی پارەدان:</span>
            <span class="value"><?php echo htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php if (!$isCredit && !empty($expense['wallet_name'])): ?>
        <div class="row">
            <span class="label">قاسە:</span>
            <span class="value"><?php echo htmlspecialchars((string)$expense['wallet_name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($expense['expense_type_name'])): ?>
        <div class="row">
            <span class="label">جۆری خەرجی:</span>
            <span class="value"><?php echo htmlspecialchars((string)$expense['expense_type_name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>

        <div class="amount-box">
            <div class="caption">بڕی خەرجی</div>
            <div class="value <?php echo $isCredit ? 'credit' : 'cash'; ?>">
                <?php echo htmlspecialchars(formatCurrencyAmount($amount, $currency), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

        <?php if ($isCredit): ?>
        <div class="credit-box">
            <div class="title">زانیاری قەرز</div>
            <div class="row">
                <span class="label">قەرزکار:</span>
                <span class="value"><?php echo htmlspecialchars((string)($expense['creditor_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php if (!empty($expense['creditor_phone'])): ?>
            <div class="row">
                <span class="label">تەلەفۆن:</span>
                <span class="value"><?php echo htmlspecialchars((string)$expense['creditor_phone'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($expense['due_date'])): ?>
            <div class="row">
                <span class="label">بەرواری کۆتایی:</span>
                <span class="value"><?php echo htmlspecialchars(date('d/m/Y', strtotime((string)$expense['due_date'])), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endif; ?>
            <div class="row">
                <span class="label">کۆی قەرز:</span>
                <span class="value"><?php echo htmlspecialchars(formatCurrencyAmount((float)($expense['credit_total'] ?? 0), $currency), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="row">
                <span class="label">ماوە:</span>
                <span class="value"><?php echo htmlspecialchars(formatCurrencyAmount((float)($expense['credit_remaining'] ?? 0), $currency), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($description !== ''): ?>
        <div class="notes-box">
            <strong>تێبینی:</strong>
            <?php echo nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')); ?>
        </div>
        <?php endif; ?>

        <div class="signature-row">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div>واژووی بەرپرس</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div>واژووی وەرگر</div>
            </div>
        </div>

        <div class="footer-brand">
            <div class="title">NexoraCore</div>
            <div class="url">NexoraCore.com</div>
        </div>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>
    setTimeout(function () {
        window.print();
    }, 500);
</script>
<?php endif; ?>
</body>
</html>
