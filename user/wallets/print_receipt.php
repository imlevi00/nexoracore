<?php
/**
 * چاپکردنی وەسڵی جوڵەی قاسە (68mm)
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/wallet_service.php';
require_once '../../includes/wallet_history_labels.php';
/** @var mysqli $conn */

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
enforceAuthorizationOrDeny($currentUser, 'wallets.view', [
    'route' => '/user/wallets/print_receipt.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
], 'redirect');
requireWalletsModuleAccess();

$transactionId = (int)($_GET['id'] ?? 0);
$fromHistory = isset($_GET['from']) && $_GET['from'] === 'history';
$autoPrint = !isset($_GET['print']) || (string)$_GET['print'] === '1';

if ($transactionId <= 0) {
    setMessage('ناسنامەی جوڵە پێویستە', 'error');
    redirect(url($fromHistory ? 'user/wallets/history.php' : 'user/wallets/main.php'));
}

$tx = walletGetTransactionById($conn, $userId, $transactionId);
if (!$tx) {
    setMessage('وەسڵ نەدۆزرایەوە', 'error');
    redirect(url($fromHistory ? 'user/wallets/history.php' : 'user/wallets/main.php'));
}

$isIn = (string)$tx['direction'] === 'in';
$currency = (string)$tx['currency'] === 'USD' ? 'USD' : 'IQD';
$decimals = $currency === 'USD' ? 2 : 0;
$amount = (float)$tx['amount'];
$txType = (string)($tx['tx_type'] ?? '');
$referenceType = (string)($tx['reference_type'] ?? '');
$referenceId = (int)($tx['reference_id'] ?? 0);
$prefix = walletReceiptNumberPrefix($referenceType, $txType);
$receiptNumber = $prefix . '-' . date('Ymd', strtotime((string)$tx['created_at'])) . '-' . str_pad((string)$transactionId, 6, '0', STR_PAD_LEFT);
$createdAt = strtotime((string)$tx['created_at']);
$shortDate = date('d/m/Y', $createdAt);
$time = date('H:i', $createdAt);
$directionLabel = $isIn ? 'هاتن' : 'دەرچوون';
$amountPrefix = $isIn ? '+' : '-';
$typeLabel = walletHistoryTypeLabel($txType);
$userNote = walletHistoryUserNote($tx);
$noteLabel = walletHistoryNoteLabel($tx);
$receiptTitle = walletReceiptTitle($referenceType, $txType);
$businessName = trim((string)($tx['business_name'] ?? ''));
if ($businessName === '') {
    $businessName = (string)($currentUser['business_name'] ?? SITE_NAME);
}
$balanceAfter = isset($tx['balance_after_tx']) ? (float)$tx['balance_after_tx'] : null;
$relatedWalletName = trim((string)($tx['related_wallet_name'] ?? ''));
$backUrl = url($fromHistory ? 'user/wallets/history.php' : 'user/wallets/main.php');
$pageTitle = $receiptTitle . ' #' . $receiptNumber;
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
            border: 1px solid #000;
            border-radius: 999px;
            margin-bottom: 4px;
        }

        .receipt-badge.in { border-color: #16a34a; color: #16a34a; }
        .receipt-badge.out { border-color: #dc2626; color: #dc2626; }

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

        .amount-box .value.in { color: #16a34a; }
        .amount-box .value.out { color: #dc2626; }

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
        <span class="receipt-badge <?php echo $isIn ? 'in' : 'out'; ?>">
            <?php echo htmlspecialchars($directionLabel, ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <div class="receipt-title"><?php echo htmlspecialchars($receiptTitle, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="business-name"><?php echo htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if (!empty($tx['business_phone'])): ?>
            <div class="business-meta">تەلەفۆن: <?php echo htmlspecialchars((string)$tx['business_phone'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (!empty($tx['business_address'])): ?>
            <div class="business-meta"><?php echo htmlspecialchars((string)$tx['business_address'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </div>

    <div class="divider-thick"></div>

    <div class="row">
        <span class="label">ژمارەی وەسڵ:</span>
        <span class="value"><?php echo htmlspecialchars($receiptNumber, ENT_QUOTES, 'UTF-8'); ?></span>
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
        <span class="label">قاسە:</span>
        <span class="value"><?php echo htmlspecialchars((string)$tx['wallet_name'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <?php if ($relatedWalletName !== ''): ?>
        <div class="row">
            <span class="label">قاسەی پەیوەندیدار:</span>
            <span class="value"><?php echo htmlspecialchars($relatedWalletName, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>
    <div class="row">
        <span class="label">جۆری جوڵە:</span>
        <span class="value"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <?php if ($referenceType !== ''): ?>
        <div class="row">
            <span class="label">سەرچاوە:</span>
            <span class="value"><?php echo htmlspecialchars(walletHistoryReferenceLabel($referenceType), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>
    <?php if ($referenceId > 0): ?>
        <div class="row">
            <span class="label">ژمارەی مامەڵە:</span>
            <span class="value">#<?php echo $referenceId; ?></span>
        </div>
    <?php endif; ?>
    <div class="row">
        <span class="label">دراو:</span>
        <span class="value"><?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>

    <div class="amount-box">
        <div class="caption">بڕی <?php echo htmlspecialchars($directionLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="value <?php echo $isIn ? 'in' : 'out'; ?>">
            <?php echo $amountPrefix . number_format($amount, $decimals) . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>

    <?php if ($balanceAfter !== null): ?>
        <div class="row">
            <span class="label">باڵانس دوای جوڵە:</span>
            <span class="value"><?php echo number_format($balanceAfter, $decimals) . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($userNote !== ''): ?>
        <div class="notes-box">
            <strong>تێبینی:</strong>
            <?php echo nl2br(htmlspecialchars($userNote, ENT_QUOTES, 'UTF-8')); ?>
        </div>
    <?php endif; ?>
    <?php if ($noteLabel !== '' && $noteLabel !== $userNote): ?>
        <div class="notes-box">
            <strong>تێبینی:</strong>
            <?php echo nl2br(htmlspecialchars($noteLabel, ENT_QUOTES, 'UTF-8')); ?>
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
        <div class="url">nexoracore.com</div>
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
