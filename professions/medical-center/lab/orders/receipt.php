<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_catalog_service.php';
require_once dirname(__DIR__) . '/includes/lab_order_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];

$orderId = (int)($_GET['id'] ?? 0);
$order = labFetchOrder($conn_kasher_platform, $userId, $orderId);
if (!$order || (int)$order['lab_id'] !== $labId) {
    setMessage('داواکاری نەدۆزرایەوە', 'danger');
    redirect(url('professions/medical-center/lab/orders/index.php'));
}

if ((string)$order['status'] !== 'completed') {
    setMessage('وەسڵ تەنها دوای تەواوکردنی داواکاری بەردەستە', 'warning');
    redirect(url('professions/medical-center/lab/orders/fill.php?id=' . (int)$orderId));
}

$settings = labFetchReceiptSettings($conn_kasher_platform, $userId, $labId);
$orderTests = labFetchOrderTests($conn_kasher_platform, $orderId);
$infoFields = $settings['info_fields'];

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$receiptGenderLabel = static fn(?string $gender): string => match ($gender) {
    'male' => 'Male',
    'female' => 'Female',
    default => '—',
};

$receiptAgeLabel = static function (int $age, ?int $ageMonths): string {
    $parts = [];
    if ($age > 0) {
        $parts[] = $age . ' yr';
    }
    if ($ageMonths !== null && $ageMonths > 0) {
        $parts[] = $ageMonths . ' mo';
    }
    return $parts === [] ? '0' : implode(', ', $parts);
};

// Build the info items respecting toggles
$infoItems = [];
if (!empty($infoFields['name'])) {
    $infoItems[] = ['Patient Name', (string)$order['patient_name']];
}
if (!empty($infoFields['age'])) {
    $infoItems[] = ['Age', $receiptAgeLabel((int)$order['age'], isset($order['age_months']) ? (int)$order['age_months'] : null)];
}
if (!empty($infoFields['gender'])) {
    $infoItems[] = ['Gender', $receiptGenderLabel($order['gender'] ?? null)];
}
if (!empty($infoFields['mobile'])) {
    $infoItems[] = ['Mobile', (string)$order['patient_mobile']];
}
if (!empty($infoFields['doctor']) && !labOrderIsDirect($order)) {
    $infoItems[] = ['Doctor', labOrderDoctorDisplay($order)];
}
if (!empty($infoFields['order_no'])) {
    $infoItems[] = ['Order No.', '#' . (int)$order['id']];
}
if (!empty($infoFields['date'])) {
    $infoItems[] = ['Date', (string)($order['completed_at'] ?: $order['created_at']), 'is-nowrap'];
}

// Build receipt test list (respect show_on_receipt) and group by test group_name
$receiptTests = [];
foreach ($orderTests as $orderTest) {
    $testId = (int)$orderTest['test_id'];
    $testMeta = labFetchTest($conn_kasher_platform, $userId, $labId, $testId);
    if ($testMeta && !(int)$testMeta['show_on_receipt']) {
        continue;
    }
    $columns = labFetchColumns($conn_kasher_platform, $testId);
    $rows = labFetchRows($conn_kasher_platform, $testId);
    $rows = labFilterRowsBySelection($rows, labFetchOrderTestRowIds($conn_kasher_platform, (int)$orderTest['id']));
    if (empty($columns) || empty($rows)) {
        continue;
    }
    $groupKey = trim((string)($testMeta['group_name'] ?? ''));
    if (!isset($receiptTests[$groupKey])) {
        $receiptTests[$groupKey] = [];
    }
    $receiptTests[$groupKey][] = [
        'order_test' => $orderTest,
        'test_id' => $testId,
        'columns' => $columns,
        'rows' => $rows,
        'cells' => labFetchCells($conn_kasher_platform, $testId),
        'results' => labFetchOrderResults($conn_kasher_platform, (int)$orderTest['id']),
    ];
}
$barcodeValue = labOrderBarcode((int)$order['id']);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>Lab Receipt #<?php echo (int)$order['id']; ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_center/lab.css'); ?>" rel="stylesheet">
</head>
<body class="lab-receipt-screen">
<div class="no-print text-center py-3">
    <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <a href="<?php echo url('professions/medical-center/lab/orders/fill.php?id=' . (int)$order['id']); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="lab-receipt shadow-sm" dir="ltr">
    <?php if (!empty($settings['banner_url'])): ?>
        <div class="lab-receipt-banner-wrap">
            <img src="<?php echo $h($settings['banner_url']); ?>" class="lab-receipt-banner" alt="banner">
        </div>
    <?php elseif (!empty($settings['header_text'])): ?>
        <div class="lab-receipt-title" style="white-space: pre-line;"><?php echo $h($settings['header_text']); ?></div>
    <?php endif; ?>

    <div class="lab-receipt-meta">
        <?php if (!empty($infoItems)): ?>
            <div class="lab-receipt-info">
                <?php foreach ($infoItems as $infoItem): ?>
                    <?php [$label, $value] = $infoItem; $valueClass = $infoItem[2] ?? ''; ?>
                    <div class="item"><span class="lbl"><?php echo $h($label); ?></span><span class="val<?php echo $valueClass !== '' ? ' ' . $h($valueClass) : ''; ?>"><?php echo $h($value); ?></span></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="lab-receipt-barcode-wrap">
            <svg id="labReceiptBarcode"></svg>
        </div>
    </div>

    <?php foreach ($receiptTests as $groupLabel => $groupTests): ?>
        <div class="lab-receipt-section">
            <?php if ($groupLabel !== ''): ?>
                <div class="lab-receipt-section-bar"><?php echo $h($groupLabel); ?></div>
            <?php endif; ?>
            <?php foreach ($groupTests as $entry): ?>
                <div class="lab-receipt-test">
                    <h6 class="lab-receipt-test-title"><?php echo $h($entry['order_test']['test_name_snapshot']); ?></h6>
                    <?php echo labRenderResultTable($entry['columns'], $entry['rows'], $entry['cells'], $entry['results'], true, $order['gender'] ?? null); ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="lab-receipt-tail">
        <div class="lab-receipt-appbrand">
            <span class="lab-receipt-appbrand-name"><svg class="lab-receipt-appbrand-a" viewBox="12 4 72 84" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="A"><defs><linearGradient id="amirLogoGrad" x1="0" y1="0" x2="0" y2="96" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#5cccff"/><stop offset="0.4" stop-color="#1e8bff"/><stop offset="0.72" stop-color="#0a5bff"/><stop offset="1" stop-color="#4aa8ff"/></linearGradient></defs><g fill="url(#amirLogoGrad)"><rect x="42" y="6" width="12" height="8" rx="4" opacity="0.3"/><rect x="39" y="18" width="18" height="8" rx="4"/><rect x="31" y="30" width="14" height="8" rx="4"/><rect x="51" y="30" width="14" height="8" rx="4"/><rect x="26" y="42" width="14" height="8" rx="4"/><rect x="56" y="42" width="14" height="8" rx="4"/><rect x="24" y="54" width="48" height="8" rx="4"/><rect x="19" y="66" width="15" height="8" rx="4" opacity="0.85"/><rect x="62" y="66" width="15" height="8" rx="4" opacity="0.85"/><rect x="14" y="78" width="16" height="8" rx="4" opacity="0.55"/><rect x="66" y="78" width="16" height="8" rx="4" opacity="0.55"/></g></svg>mir <span class="lab-receipt-appbrand-t">T</span>echnology</span>
        </div>

        <?php if (!empty($settings['stamp_url'])): ?>
            <div class="lab-receipt-footer-banner-wrap">
                <img src="<?php echo $h($settings['stamp_url']); ?>" class="lab-receipt-footer-banner" alt="footer banner">
            </div>
        <?php endif; ?>

        <?php if (!empty($settings['footer_text'])): ?>
            <div class="lab-receipt-footer"><?php echo $h($settings['footer_text']); ?></div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
    (function () {
        var barcodeValue = <?php echo json_encode($barcodeValue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        if (typeof JsBarcode !== 'undefined') {
            JsBarcode('#labReceiptBarcode', barcodeValue, {
                format: 'CODE128',
                width: 1.1,
                height: 28,
                displayValue: true,
                fontSize: 9,
                margin: 2
            });
        }
    })();
    if (new URLSearchParams(window.location.search).get('print') === '1') {
        window.addEventListener('load', function () { window.print(); });
    }
</script>
</body>
</html>
