<?php
/** @var array $headerLines */
/** @var string $logoUrl */
/** @var string $docName */
/** @var array<string,mixed> $caseRow */
/** @var array<string,mixed> $sessionRow */
/** @var float $price */
/** @var float $discount */
/** @var float $total */
/** @var int $sessionsPlanned */
/** @var int $sessionNumber */
/** @var string $receiptDateLabel */
/** @var string $receiptAccent */
$sessionLabel = $sessionNumber > 0 && $sessionsPlanned > 0
    ? 'جەلسەی ' . $sessionNumber . ' لە ' . $sessionsPlanned
    : (string)$sessionsPlanned;
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>وەسڵی A4 - <?php echo htmlspecialchars($docName, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Tahoma, Arial, sans-serif; margin: 0; padding: 16px; background: #f3f4f6; color: #111; }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 18mm 16mm;
            box-shadow: 0 4px 24px rgba(0,0,0,0.12);
        }
        .header-logo { max-height: 96px; width: 100%; max-width: 100%; display: block; margin: 0 auto 12px; object-fit: contain; }
        .header-lines { text-align: center; margin-bottom: 16px; line-height: 1.6; font-size: 13px; }
        .header-lines strong { font-size: 15px; display: block; margin-bottom: 4px; }
        h1 {
            text-align: center;
            font-size: 18px;
            margin: 12px 0 20px;
            border-bottom: 2px solid <?php echo htmlspecialchars($receiptAccent, ENT_QUOTES, 'UTF-8'); ?>;
            padding-bottom: 8px;
        }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 13px; }
        table.meta td { padding: 8px 10px; border: 1px solid #e5e7eb; }
        table.meta td:first-child { width: 32%; background: #f9fafb; font-weight: 600; }
        .totals { margin-top: 20px; font-size: 14px; }
        .totals table { width: 100%; max-width: 320px; margin-right: 0; margin-left: auto; border-collapse: collapse; }
        .totals td { padding: 8px 12px; border: 1px solid #e5e7eb; }
        .totals .sum { font-weight: bold; font-size: 16px; background: #ede9fe; }
        .no-print { text-align: center; margin-bottom: 16px; }
        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .sheet { box-shadow: none; margin: 0; width: 100%; min-height: auto; }
        }
    </style>
</head>
<body>
<div class="no-print">
    <button type="button" onclick="window.print()" style="padding:10px 20px;cursor:pointer">چاپ</button>
</div>
<div class="sheet">
    <?php if ($logoUrl !== ''): ?>
        <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="header-logo">
    <?php endif; ?>
    <div class="header-lines">
        <?php if (!empty($headerLines)): ?>
            <?php foreach ($headerLines as $i => $line): ?>
                <?php if ($i === 0): ?>
                    <strong><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php else: ?>
                    <div><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <strong><?php echo htmlspecialchars($docName, ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php endif; ?>
    </div>
    <h1>وەسڵی خزمەتگوزاری</h1>

    <table class="meta">
        <tr><td>ناو</td><td><?php echo htmlspecialchars((string)$caseRow['client_name'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>تەمەن</td><td><?php echo (int)$caseRow['age']; ?></td></tr>
        <tr><td>جەلسە</td><td><?php echo htmlspecialchars($sessionLabel, ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>جۆری ئیش</td><td><?php echo htmlspecialchars((string)$caseRow['work_type'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>مۆبایل</td><td><?php echo htmlspecialchars((string)$caseRow['mobile'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
        <tr><td>بەرواری جەلسە</td><td><?php echo htmlspecialchars($receiptDateLabel, ENT_QUOTES, 'UTF-8'); ?></td></tr>
    </table>

    <div class="totals">
        <table>
            <tr><td>نرخ</td><td><?php echo htmlspecialchars(number_format($price, 2), ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td>داشکاندن</td><td><?php echo htmlspecialchars(number_format($discount, 2), ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr class="sum"><td>کۆی گشتی</td><td><?php echo htmlspecialchars(number_format($total, 2), ENT_QUOTES, 'UTF-8'); ?></td></tr>
        </table>
    </div>
</div>
</body>
</html>
