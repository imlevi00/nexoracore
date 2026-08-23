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
$sessionLabel = $sessionNumber > 0 && $sessionsPlanned > 0
    ? $sessionNumber . '/' . $sessionsPlanned
    : (string)$sessionsPlanned;
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>وەسڵی کاشێر - <?php echo htmlspecialchars($docName, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Tahoma, Arial, sans-serif;
            margin: 0;
            padding: 8px;
            width: 80mm;
            max-width: 80mm;
            font-size: 11px;
            line-height: 1.35;
            color: #000;
            background: #fff;
        }
        .logo { max-width: 100%; max-height: 56px; width: 100%; display: block; margin: 0 auto 6px; object-fit: contain; }
        .center { text-align: center; }
        .line { border-bottom: 1px dashed #333; margin: 6px 0; }
        .row { margin: 3px 0; }
        .label { font-weight: bold; }
        h1 { font-size: 13px; margin: 8px 0; text-align: center; }
        .no-print { text-align: center; margin-bottom: 8px; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 4px; }
        }
    </style>
</head>
<body>
<div class="no-print">
    <button type="button" onclick="window.print()">چاپ</button>
</div>
<?php if ($logoUrl !== ''): ?>
    <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="logo">
<?php endif; ?>
<div class="center">
    <?php foreach ($headerLines as $i => $line): ?>
        <div style="font-weight: <?php echo $i === 0 ? 'bold' : 'normal'; ?>"><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endforeach; ?>
    <?php if (empty($headerLines)): ?>
        <div style="font-weight:bold"><?php echo htmlspecialchars($docName, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
</div>
<div class="line"></div>
<h1>وەسڵ</h1>
<div class="row"><span class="label">ناو:</span> <?php echo htmlspecialchars((string)$caseRow['client_name'], ENT_QUOTES, 'UTF-8'); ?></div>
<div class="row"><span class="label">تەمەن:</span> <?php echo (int)$caseRow['age']; ?> — <span class="label">جەلسە:</span> <?php echo htmlspecialchars($sessionLabel, ENT_QUOTES, 'UTF-8'); ?></div>
<div class="row"><span class="label">جۆری ئیش:</span> <?php echo htmlspecialchars((string)$caseRow['work_type'], ENT_QUOTES, 'UTF-8'); ?></div>
<div class="row"><span class="label">مۆبایل:</span> <?php echo htmlspecialchars((string)$caseRow['mobile'], ENT_QUOTES, 'UTF-8'); ?></div>
<div class="row"><span class="label">بەروار:</span> <?php echo htmlspecialchars($receiptDateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
<div class="line"></div>
<div class="row"><span class="label">نرخ:</span> <?php echo htmlspecialchars(number_format($price, 2), ENT_QUOTES, 'UTF-8'); ?></div>
<div class="row"><span class="label">داشکاندن:</span> <?php echo htmlspecialchars(number_format($discount, 2), ENT_QUOTES, 'UTF-8'); ?></div>
<div class="row" style="font-weight:bold;font-size:12px;margin-top:6px"><span class="label">کۆ:</span> <?php echo htmlspecialchars(number_format($total, 2), ENT_QUOTES, 'UTF-8'); ?></div>
<div class="line"></div>
<div class="center" style="font-size:10px;margin-top:8px">سپاس بە هەڵبژاردن</div>
</body>
</html>
