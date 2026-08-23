<?php
/**
 * چاپکردنی وەسڵی کۆتایی ڕۆژ / ڕاپۆرتی کاشێر - user/reports/print_receipt.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/zanyari_user_settings.php';
require_once '../../includes/profit_stats.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'profits.view', [
    'route' => '/user/reports/print_receipt.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');

$userId = (int)$currentUser['id'];
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';
$today = date('Y-m-d');

$fromDateInput = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : $today;
$toDateInput = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : $today;

$fromDateObj = DateTime::createFromFormat('Y-m-d', $fromDateInput) ?: new DateTime($today);
$toDateObj = DateTime::createFromFormat('Y-m-d', $toDateInput) ?: new DateTime($today);

if ($fromDateObj > $toDateObj) {
    $tmp = $fromDateObj;
    $fromDateObj = $toDateObj;
    $toDateObj = $tmp;
}

$fromDate = $fromDateObj->format('Y-m-d');
$toDate = $toDateObj->format('Y-m-d');

$showMode = isset($_GET['show']) ? (string)$_GET['show'] : 'both';
$allowedShowModes = ['revenue', 'profit', 'both'];
if (!in_array($showMode, $allowedShowModes, true)) {
    $showMode = 'both';
}

$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

$effectiveSubUserId = null;
$selectedSubUserName = '';

if ($isSubUser) {
    if (isset($currentUser['sub_user_id']) && $currentUser['sub_user_id']) {
        $effectiveSubUserId = (int)$currentUser['sub_user_id'];
        if (!empty($currentUser['full_name'])) {
            $selectedSubUserName = $currentUser['full_name'];
        } elseif (!empty($currentUser['username'])) {
            $selectedSubUserName = $currentUser['username'];
        }
    }
} elseif (isset($_GET['sub_user_id']) && $_GET['sub_user_id'] !== '' && (int)$_GET['sub_user_id'] > 0) {
    $selectedSubUserId = (int)$_GET['sub_user_id'];
    $check = $conn->prepare("SELECT id, full_name, username FROM sub_users WHERE id = ? AND main_user_id = ?");
    $check->bind_param('ii', $selectedSubUserId, $userId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if ($row) {
        $effectiveSubUserId = (int)$row['id'];
        $selectedSubUserName = $row['full_name'] ?: $row['username'];
    }
}

$recognizeDebtRevenueAtSale = getRecognizeCustomerDebtRevenueAtSale($userId);
$statsByCurrency = calculateProfitStatsByCurrency(
    $conn,
    $userId,
    $fromDate,
    $toDate,
    $effectiveSubUserId,
    $isSubUser,
    $recognizeDebtRevenueAtSale
);
$mainStats = $statsByCurrency['IQD'];
$mainStatsUsd = $statsByCurrency['USD'];

// ئایا هیچ چالاکیەکی دۆلاری هەیە؟
$hasUsdActivity = (
    (float)($mainStatsUsd['net_revenue'] ?? 0) != 0.0
    || (float)($mainStatsUsd['profit'] ?? 0) != 0.0
);

$settingsStmt = $conn->prepare('SELECT * FROM settings WHERE user_id = ? LIMIT 1');
$settingsStmt->bind_param('i', $userId);
$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

$userStmt = $conn->prepare('SELECT business_name, phone, address FROM users WHERE id = ? LIMIT 1');
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$userRow = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

$businessName = $userRow['business_name'] ?? $currentUser['business_name'] ?? '';
$businessPhone = $userRow['phone'] ?? '';
$businessAddress = $userRow['address'] ?? '';

$fromLabel = date('Y/m/d', strtotime($fromDate));
$toLabel = date('Y/m/d', strtotime($toDate));
$rangeLabel = ($fromDate === $toDate)
    ? $fromLabel
    : $fromLabel . ' — ' . $toLabel;

$printedAt = date('Y/m/d H:i');
$pageTitle = 'وەسڵی کۆتایی ڕۆژ';
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: Tahoma, 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 12px;
            background: #f3f4f6;
            color: #000;
        }

        .no-print {
            text-align: center;
            margin-bottom: 12px;
        }

        .no-print button {
            font-family: inherit;
            font-size: 14px;
            padding: 8px 16px;
            cursor: pointer;
            border: 1px solid #6f42c1;
            background: #6f42c1;
            color: #fff;
            border-radius: 6px;
        }

        .receipt-wrap {
            max-width: 72mm;
            margin: 0 auto;
        }

        .receipt {
            width: 100%;
            max-width: 72mm;
            margin: 0 auto;
            padding: 6px 4px;
            background: #fff;
            border: 2px solid #333;
            font-size: 10px;
            line-height: 1.45;
            overflow: visible;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 8px;
        }

        .receipt-header h1 {
            font-size: 14px;
            margin: 6px 0 4px;
        }

        .receipt-header .business-info {
            font-size: 10px;
            margin-top: 4px;
        }

        .receipt-logo {
            max-width: 100%;
            max-height: 56px;
            display: block;
            margin: 0 auto 6px;
            object-fit: contain;
        }

        .receipt-title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            margin: 8px 0 6px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #333;
        }

        .info-line {
            margin: 5px 0;
            font-size: 10px;
            line-height: 1.5;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .info-label {
            font-weight: bold;
        }

        .info-value {
            unicode-bidi: plaintext;
        }

        .info-meta {
            margin: 4px 0 8px;
            padding: 0 2px;
        }

        .amount-block {
            margin: 10px 0;
            padding: 8px 6px;
            border: 2px solid #000;
            text-align: center;
        }

        .amount-block .amount-label {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .amount-block .amount-value {
            font-size: 14px;
            font-weight: bold;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .amount-block.profit-negative .amount-value {
            color: #b91c1c;
        }

        .signature-section {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            margin-top: 14px;
        }

        .signature-box {
            flex: 1;
            min-width: 0;
            text-align: center;
            font-size: 9px;
        }

        .signature-line {
            border-bottom: 1px solid #333;
            height: 28px;
            margin-bottom: 4px;
        }

        .receipt-footer {
            text-align: center;
            font-size: 9px;
            margin-top: 10px;
            padding-top: 4px;
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
                width: 72mm;
                max-width: 72mm;
                margin: 0;
            }

            .receipt {
                border: none;
                width: 100%;
                max-width: 72mm;
                padding: 2mm 1mm;
                page-break-inside: avoid;
                overflow: visible;
            }

            @page {
                margin: 0;
                size: 72mm auto;
            }

            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .receipt,
            html[data-bs-theme='dark'] .receipt * {
                background: #fff !important;
                color: #000 !important;
                border-color: #000 !important;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">چاپکردن</button>
    </div>

    <div class="receipt-wrap">
        <div class="receipt">
            <div class="receipt-header">
                <?php if (!empty($settings['business_logo'])): ?>
                    <img
                        src="<?php echo htmlspecialchars(url('uploads/' . $settings['business_logo']), ENT_QUOTES, 'UTF-8'); ?>"
                        alt=""
                        class="receipt-logo"
                    >
                <?php endif; ?>

                <h1><?php echo htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'); ?></h1>

                <?php if (!empty($settings['receipt_header'])): ?>
                    <div class="business-info">
                        <?php echo nl2br(htmlspecialchars($settings['receipt_header'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                <?php elseif ($businessPhone !== '' || $businessAddress !== ''): ?>
                    <div class="business-info">
                        <?php if ($businessPhone !== ''): ?>
                            <div><?php echo htmlspecialchars($businessPhone, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if ($businessAddress !== ''): ?>
                            <div><?php echo htmlspecialchars($businessAddress, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="receipt-title">وەسڵی کۆتایی ڕۆژ<br><span style="font-size:10px;font-weight:normal;">ڕاپۆرتی NexoraCore</span></div>

            <div class="info-meta">
                <div class="info-line">
                    <span class="info-label">ماوە: </span>
                    <span class="info-value"><?php echo htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>

                <?php if ($selectedSubUserName !== ''): ?>
                <div class="info-line">
                    <span class="info-label">کارمەند: </span>
                    <span class="info-value"><?php echo htmlspecialchars($selectedSubUserName, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>

                <div class="info-line">
                    <span class="info-label">کاتی چاپ: </span>
                    <span class="info-value"><?php echo htmlspecialchars($printedAt, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>

            <?php if ($showMode === 'revenue' || $showMode === 'both'): ?>
            <div class="amount-block">
                <div class="amount-label">کۆی داهات (دینار)</div>
                <div class="amount-value"><?php echo number_format((float)($mainStats['net_revenue'] ?? 0)); ?> دینار</div>
            </div>
            <?php if ($hasUsdActivity): ?>
            <div class="amount-block">
                <div class="amount-label">کۆی داهات (دۆلار)</div>
                <div class="amount-value"><?php echo htmlspecialchars(formatCurrencyAmount((float)($mainStatsUsd['net_revenue'] ?? 0), 'USD'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($showMode === 'profit' || $showMode === 'both'): ?>
            <div class="amount-block <?php echo ((float)($mainStats['profit'] ?? 0)) < 0 ? 'profit-negative' : ''; ?>">
                <div class="amount-label">قازانجی پاک (دینار)</div>
                <div class="amount-value"><?php echo number_format((float)($mainStats['profit'] ?? 0)); ?> دینار</div>
            </div>
            <?php if ($hasUsdActivity): ?>
            <div class="amount-block <?php echo ((float)($mainStatsUsd['profit'] ?? 0)) < 0 ? 'profit-negative' : ''; ?>">
                <div class="amount-label">قازانجی پاک (دۆلار)</div>
                <div class="amount-value"><?php echo htmlspecialchars(formatCurrencyAmount((float)($mainStatsUsd['profit'] ?? 0), 'USD'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div>کارمەندی کاشێر</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div>خاوەن شوێن</div>
                </div>
            </div>

            <div class="receipt-footer">
                ئەم وەسڵە بۆ پێشکەشکردنی پارەی قاسەیە لە کۆتایی ڕۆژدا
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
