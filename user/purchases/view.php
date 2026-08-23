<?php
/**
 * بینینی وەسڵی کڕدراو - user/purchases/view.php
 */

require_once '../../includes/functions.php';
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنەوەی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
requireCompaniesModuleAccess();
$userId = $currentUser['id'];

// دیاریکردنی مۆدی دەرمانخانە بەپێی ڕێکخستنەکان
$isPharmacyMode = false;
$settingsStmt = $conn->prepare("
    SELECT s.business_type_id, bt.code AS business_type_code
    FROM settings s
    LEFT JOIN business_types bt ON bt.id = s.business_type_id
    WHERE s.user_id = ?
    LIMIT 1
");
$settingsStmt->bind_param("i", $userId);
$settingsStmt->execute();
$settingsRow = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();
if ($settingsRow) {
    $businessTypeId = (int)($settingsRow['business_type_id'] ?? 0);
    $businessTypeCode = trim((string)($settingsRow['business_type_code'] ?? ''));
    if (
        in_array($businessTypeId, [1, 3], true) ||
        in_array($businessTypeCode, ['pharmacy', 'pharmacy_and_medical_center'], true)
    ) {
        $isPharmacyMode = true;
    }
}

// وەرگرتنی ID ی وەسڵەکە
$receiptId = (int)($_GET['id'] ?? 0);
if (!$receiptId) {
    setMessage('وەسڵ نەدۆزرایەوە', 'error');
    redirect(url('user/purchases/index.php'));
}

// وەرگرتنی زانیاری وەسڵەکە
$stmt = $conn->prepare("
    SELECT pr.*, c.name as company_name, c.address as company_address, c.phone as company_phone
    FROM purchase_receipts pr 
    LEFT JOIN companies c ON pr.company_id = c.id 
    WHERE pr.id = ? AND pr.user_id = ?
");
$stmt->bind_param("ii", $receiptId, $userId);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();

if (!$receipt) {
    setMessage('وەسڵ نەدۆزرایەوە یان دەسەڵاتت نیە', 'error');
    redirect(url('user/purchases/index.php'));
}

// دراوی وەسڵ (هەموو نرخەکان بەم دراوەن)
$viewCurrency = (($receipt['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
$viewCurLabel = $viewCurrency === 'USD' ? 'دۆلار' : 'دینار';

// وەرگرتنی کاڵاکانی وەسڵەکە لەگەڵ یەکەکانیان
$stmt = $conn->prepare("
    SELECT pri.*, p.barcode, COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as current_stock,
           u.name as unit_name, u.symbol as unit_symbol
    FROM purchase_receipt_items pri
    LEFT JOIN products p ON pri.product_id = p.id
    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
    LEFT JOIN product_units pu_any ON pu_any.id = (
        SELECT pu2.id FROM product_units pu2
        WHERE pu2.product_id = p.id
        ORDER BY pu2.is_primary DESC, pu2.id ASC
        LIMIT 1
    )
    LEFT JOIN units u ON pri.unit_id = u.id
    WHERE pri.purchase_receipt_id = ?
    ORDER BY pri.id
");
$stmt->bind_param("i", $receiptId);
$stmt->execute();
$receiptItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'بینینی وەسڵی کڕدراو';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header bg-primary text-white d-print-none">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-receipt-cutoff"></i>
                            وەسڵی کڕدراو - <?php echo htmlspecialchars($receipt['company_name']); ?>
                        </h5>
                        <div>
                            <button onclick="window.print()" class="btn btn-light btn-sm me-2">
                                <i class="bi bi-printer"></i> چاپکردن
                            </button>
                            <a href="<?php echo url('user/purchases/index.php'); ?>" class="btn btn-secondary btn-sm">
                                <i class="bi bi-arrow-left"></i> گەڕانەوە
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <!-- سەرنووسەی وەسڵەکە -->
                    <div class="text-center mb-4 d-print-block">
                        <h2 class="mb-1"><?php echo htmlspecialchars($currentUser['business_name']); ?></h2>
                        <h4 class="text-primary mb-3">وەسڵی کڕدراو</h4>
                    </div>

                    <div class="row mb-4">
                        <!-- زانیاری کۆمپانیاکە -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="bi bi-building"></i> زانیاری کۆمپانیا</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-2">
                                        <strong>ناو:</strong> <?php echo htmlspecialchars($receipt['company_name']); ?>
                                    </div>
                                    <?php if ($receipt['company_address']): ?>
                                    <div class="mb-2">
                                        <strong>ناونیشان:</strong> <?php echo htmlspecialchars($receipt['company_address']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($receipt['company_phone']): ?>
                                    <div class="mb-2">
                                        <strong>تەلەفۆن:</strong> <?php echo htmlspecialchars($receipt['company_phone']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- زانیاری وەسڵەکە -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="bi bi-receipt"></i> زانیاری وەسڵەکە</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-2">
                                        <strong>بەروار:</strong> <?php echo date('Y-m-d', strtotime($receipt['receipt_date'])); ?>
                                    </div>
                                    <?php if ($receipt['receipt_number']): ?>
                                    <div class="mb-2">
                                        <strong>ژمارەی وەسڵ:</strong> <?php echo htmlspecialchars($receipt['receipt_number']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="mb-2">
                                        <strong>جۆری پارەدان:</strong>
                                        <span class="badge bg-<?php echo $receipt['payment_type'] == 'cash' ? 'success' : 'warning'; ?>">
                                            <?php echo $receipt['payment_type'] == 'cash' ? 'نەقد' : 'قەرز'; ?>
                                        </span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>دراو:</strong>
                                        <span class="badge <?php echo $viewCurrency === 'USD' ? 'bg-success' : 'bg-info'; ?>"><?php echo $viewCurLabel; ?> (<?php echo $viewCurrency; ?>)</span>
                                    </div>
                                    <div class="mb-2">
                                        <strong>دۆخ:</strong> 
                                        <span class="badge bg-<?php 
                                            echo $receipt['status'] == 'active' ? 'primary' : 
                                                ($receipt['status'] == 'completed' ? 'success' : 'danger'); 
                                        ?>">
                                            <?php 
                                                echo $receipt['status'] == 'active' ? 'چالاک' : 
                                                    ($receipt['status'] == 'completed' ? 'تەواو' : 'هەڵوەشێندراوە'); 
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- گەڕان لە ناو کاڵاکانی وەسڵ -->
                    <?php if (!empty($receiptItems)): ?>
                    <div class="row mb-3 d-print-none">
                        <div class="col-md-6 col-lg-5">
                            <label class="form-label" for="receiptItemSearch">
                                <i class="bi bi-search"></i> گەڕان بە ناوی کاڵا یان بارکۆد
                            </label>
                            <div class="input-group">
                                <input type="text"
                                       id="receiptItemSearch"
                                       class="form-control"
                                       placeholder="ناوی کاڵا یان بارکۆد..."
                                       autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary" id="receiptItemSearchClear" title="پاککردنەوە">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <small class="text-muted" id="receiptItemSearchStatus"></small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- خشتەی کاڵاکان -->
                    <div class="table-responsive mb-4">
                        <?php if ($isPharmacyMode): ?>
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="table-light">
                                <tr class="text-center">
                                    <th>#</th>
                                    <th>ناوی کاڵا</th>
                                    <th>بارکۆد</th>
                                    <th>بڕی پاکەت</th>
                                    <th>بۆنسی پاکەت</th>
                                    <th>کۆی پاکەت</th>
                                    <th>شیت بۆ هەر پاکەت</th>
                                    <th>کۆی شیت</th>
                                    <th>نرخی کڕینی پاکەت</th>
                                    <th>داشکاندن (%)</th>
                                    <th>بڕی داشکاندن</th>
                                    <th>نرخی تێکڕای پاکەت</th>
                                    <th>نرخی تێکڕای شیت</th>
                                    <th>کۆی نرخی ڕیز</th>
                                    <th>بەسەرچوون</th>
                                    <th class="d-print-none">مەخزەنی ئێستا</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($receiptItems)): ?>
                                    <tr>
                                        <td colspan="16" class="text-center py-4">
                                            <div class="text-muted">هیچ کاڵایەک نەدۆزرایەوە</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($receiptItems as $index => $item): ?>
                                        <?php
                                            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
                                            $packetBonus = isset($item['packet_bonus']) ? (float)$item['packet_bonus'] : 0.0;
                                            $totalPackets = $quantity + $packetBonus;
                                            $sheetsPerPacket = isset($item['sheets_per_packet']) ? (int)$item['sheets_per_packet'] : 0;
                                            $totalSheets = ($totalPackets > 0 && $sheetsPerPacket > 0) ? $totalPackets * $sheetsPerPacket : 0;
                                            $lineNetTotal = isset($item['total_cost']) ? (float)$item['total_cost'] : ($quantity * (float)($item['buy_price'] ?? 0));
                                            $lineDiscountAmount = isset($item['discount_amount']) ? (float)$item['discount_amount'] : 0.0;
                                            $effectivePacketPrice = $totalPackets > 0 ? $lineNetTotal / $totalPackets : 0.0;
                                            $effectiveSheetPrice = $totalSheets > 0 ? $lineNetTotal / $totalSheets : 0.0;

                                            // فۆرماتکردنی بڕەکان و نرخی تێکڕا بەبێ .000
                                            $quantityDisplay = $quantity > 0
                                                ? rtrim(rtrim(number_format($quantity, 3), '0'), '.')
                                                : '-';
                                            $packetBonusDisplay = $packetBonus > 0
                                                ? rtrim(rtrim(number_format($packetBonus, 3), '0'), '.')
                                                : '-';
                                            $totalPacketsDisplay = $totalPackets > 0
                                                ? rtrim(rtrim(number_format($totalPackets, 3), '0'), '.')
                                                : '-';
                                            $effectivePacketPriceDisplay = $totalPackets > 0
                                                ? formatCurrencyAmount($effectivePacketPrice, $viewCurrency)
                                                : '-';
                                            $effectiveSheetPriceDisplay = $totalSheets > 0
                                                ? formatCurrencyAmount($effectiveSheetPrice, $viewCurrency)
                                                : '-';
                                            $receiptDiscountPercent = isset($receipt['discount_percent']) ? (float)$receipt['discount_percent'] : 0.0;
                                        ?>
                                        <tr class="receipt-item-row"
                                            data-product-name="<?php echo htmlspecialchars(mb_strtolower((string)($item['product_name'] ?? ''), 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-barcode="<?php echo htmlspecialchars(mb_strtolower((string)($item['barcode'] ?? ''), 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <td class="text-center receipt-item-index"><?php echo $index + 1; ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                            </td>
                                            <td class="text-center">
                                                <small class="text-muted"><?php echo $item['barcode'] ?: '-'; ?></small>
                                            </td>
                                            <td class="text-end"><?php echo $quantityDisplay; ?></td>
                                            <td class="text-end"><?php echo $packetBonusDisplay; ?></td>
                                            <td class="text-end"><?php echo $totalPacketsDisplay; ?></td>
                                            <td class="text-end"><?php echo $sheetsPerPacket > 0 ? number_format($sheetsPerPacket, 0) : '-'; ?></td>
                                            <td class="text-end"><?php echo $totalSheets > 0 ? number_format($totalSheets, 0) : '-'; ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars(formatCurrencyAmount($item['buy_price'], $viewCurrency)); ?></td>
                                            <td class="text-center">
                                                <?php if ($receiptDiscountPercent > 0): ?>
                                                    <span class="badge bg-info text-dark">
                                                        <?php echo rtrim(rtrim(number_format($receiptDiscountPercent, 3, '.', ''), '0'), '.'); ?>%
                                                    </span>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end text-danger">
                                                <?php echo $lineDiscountAmount > 0 ? '-' . htmlspecialchars(formatCurrencyAmount($lineDiscountAmount, $viewCurrency)) : '<span class="text-muted">-</span>'; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo $effectivePacketPriceDisplay; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo $effectiveSheetPriceDisplay; ?>
                                            </td>
                                            <td class="text-end fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount($lineNetTotal, $viewCurrency)); ?></td>
                                            <td class="text-center">
                                                <small><?php echo $item['expiry_date'] ? date('Y-m-d', strtotime($item['expiry_date'])) : '-'; ?></small>
                                            </td>
                                            <td class="text-center d-print-none">
                                                <span class="badge bg-info"><?php echo $item['current_stock']; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="receipt-items-no-match d-none">
                                        <td colspan="16" class="text-center py-4">
                                            <div class="text-muted">هیچ کاڵایەک بەم گەڕانە نەدۆزرایەوە</div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <!-- خشتەی مۆدی نۆرمال -->
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr class="text-center">
                                    <th>#</th>
                                    <th>ناوی کاڵا</th>
                                    <th>بارکۆد</th>
                                    <th>بڕ</th>
                                    <th>یەکە</th>
                                    <th>نرخی کڕین</th>
                                    <th>نرخی فرۆشتن</th>
                                    <th>نرخی جوملە</th>
                                    <th>نرخی تایبەتی</th>
                                    <th>بەسەرچوون</th>
                                    <th>کۆی نرخ</th>
                                    <th class="d-print-none">مەخزەنی ئێستا</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($receiptItems)): ?>
                                    <tr>
                                        <td colspan="12" class="text-center py-4">
                                            <div class="text-muted">هیچ کاڵایەک نەدۆزرایەوە</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($receiptItems as $index => $item): ?>
                                        <tr class="receipt-item-row"
                                            data-product-name="<?php echo htmlspecialchars(mb_strtolower((string)($item['product_name'] ?? ''), 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-barcode="<?php echo htmlspecialchars(mb_strtolower((string)($item['barcode'] ?? ''), 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <td class="text-center receipt-item-index"><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                            <td class="text-center">
                                                <small class="text-muted"><?php echo $item['barcode'] ?: '-'; ?></small>
                                            </td>
                                            <td class="text-center"><?php echo number_format($item['quantity'], 3); ?></td>
                                            <td class="text-center">
                                                <?php if ($item['unit_name']): ?>
                                                    <span class="badge bg-secondary">
                                                        <?php echo htmlspecialchars($item['unit_name']); ?>
                                                        <?php if ($item['unit_symbol']): ?>
                                                            (<?php echo htmlspecialchars($item['unit_symbol']); ?>)
                                                        <?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?php echo htmlspecialchars(formatCurrencyAmount($item['buy_price'], $viewCurrency)); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars(formatCurrencyAmount($item['sell_price'], $viewCurrency)); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars(formatCurrencyAmount($item['wholesale_price'], $viewCurrency)); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars(formatCurrencyAmount($item['special_price'], $viewCurrency)); ?></td>
                                            <td class="text-center">
                                                <small><?php echo $item['expiry_date'] ? date('Y-m-d', strtotime($item['expiry_date'])) : '-'; ?></small>
                                            </td>
                                            <td class="text-end fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount($item['total_cost'], $viewCurrency)); ?></td>
                                            <td class="text-center d-print-none">
                                                <span class="badge bg-info"><?php echo $item['current_stock']; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="receipt-items-no-match d-none">
                                        <td colspan="12" class="text-center py-4">
                                            <div class="text-muted">هیچ کاڵایەک بەم گەڕانە نەدۆزرایەوە</div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <!-- کۆی گشتی -->
                    <div class="row">
                        <div class="col-md-8">
                            <?php if ($receipt['notes']): ?>
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0"><i class="bi bi-sticky"></i> تێبینی</h6>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($receipt['notes'])); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="bi bi-calculator"></i> کۆی حیساب</h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>کۆی کاڵاکان:</span>
                                        <span class="fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount($receipt['total_amount'], $viewCurrency)); ?></span>
                                    </div>
                                    
                                    <?php if ($isPharmacyMode && isset($receipt['discount_percent']) && (float)$receipt['discount_percent'] > 0): ?>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>ڕێژەی داشکاندن:</span>
                                        <span class="fw-semibold text-primary">
                                            <?php echo rtrim(rtrim(number_format((float)$receipt['discount_percent'], 3, '.', ''), '0'), '.'); ?>%
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($receipt['discount_amount'] > 0): ?>
                                    <div class="d-flex justify-content-between mb-2 text-danger">
                                        <span>داشکاندن:</span>
                                        <span>-<?php echo htmlspecialchars(formatCurrencyAmount($receipt['discount_amount'], $viewCurrency)); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($receipt['additional_charges'] > 0): ?>
                                    <div class="d-flex justify-content-between mb-2 text-info">
                                        <span>کرێی زیادە:</span>
                                        <span>+<?php echo htmlspecialchars(formatCurrencyAmount($receipt['additional_charges'], $viewCurrency)); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <strong>کۆی گشتی:</strong>
                                        <strong class="text-primary fs-5"><?php echo htmlspecialchars(formatCurrencyAmount($receipt['final_amount'], $viewCurrency)); ?></strong>
                                    </div>
                                    
                                    <?php if ($isPharmacyMode): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">
                                            نرخی پاکەت و شیت لەم وەسڵەدا دوای بۆنس و داشکاندنی سەدی حیساب کراون.
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- وێنەی وەسڵ -->
                    <?php if ($receipt['receipt_image']): ?>
                    <div class="row mt-4 d-print-none">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="bi bi-image"></i> وێنەی وەسڵ</h6>
                                </div>
                                <div class="card-body text-center">
                                    <img src="<?php echo htmlspecialchars($receipt['receipt_image']); ?>" alt="وێنەی وەسڵ" 
                                         class="img-fluid rounded shadow" style="max-height: 400px;" 
                                         onclick="showImageModal(this.src)">
                                    <div class="mt-2">
                                        <small class="text-muted">کلیک بکە بۆ گەورەکردنەوە</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- فووتەری چاپکردن -->
                    <div class="row mt-4 d-print-block d-none">
                        <div class="col-12 text-center">
                            <hr>
                            <small class="text-muted">
                                چاپکراوە لە: <?php echo date('Y-m-d H:i:s'); ?> | 
                                سیستەمی NexoraCore - AmirTechOne.com
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal بۆ نیشاندانی وێنە -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">وێنەی وەسڵ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" alt="وێنەی وەسڵ" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<script>
function showImageModal(src) {
    document.getElementById('modalImage').src = src;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}

(function initReceiptItemSearch() {
    const searchInput = document.getElementById('receiptItemSearch');
    const clearBtn = document.getElementById('receiptItemSearchClear');
    const statusEl = document.getElementById('receiptItemSearchStatus');
    const table = document.querySelector('.table-responsive table');

    if (!searchInput || !table) {
        return;
    }

    const rows = Array.from(table.querySelectorAll('tbody tr.receipt-item-row'));
    const noMatchRow = table.querySelector('tbody tr.receipt-items-no-match');

    function filterReceiptItems() {
        const query = searchInput.value.trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach(function(row, index) {
            const indexCell = row.querySelector('.receipt-item-index');
            let isMatch = true;

            if (query !== '') {
                const productName = row.dataset.productName || '';
                const barcode = row.dataset.barcode || '';
                isMatch = productName.includes(query) || (barcode !== '' && barcode.includes(query));
            }

            row.style.display = isMatch ? '' : 'none';

            if (isMatch) {
                visibleCount++;
                if (indexCell) {
                    indexCell.textContent = String(visibleCount);
                }
            } else if (indexCell) {
                indexCell.textContent = String(index + 1);
            }
        });

        if (noMatchRow) {
            noMatchRow.classList.toggle('d-none', query === '' || visibleCount > 0);
        }

        if (statusEl) {
            statusEl.textContent = query !== '' ? (visibleCount + ' لە ' + rows.length + ' کاڵا') : '';
        }
    }

    searchInput.addEventListener('input', filterReceiptItems);
    searchInput.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            searchInput.value = '';
            filterReceiptItems();
        }
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            filterReceiptItems();
            searchInput.focus();
        });
    }
})();

// Print styles
const printStyles = `
<style media="print">
    @page { margin: 1cm; }
    .d-print-none { display: none !important; }
    .d-print-block { display: block !important; }
    .card { border: 1px solid #ddd; margin-bottom: 1rem; }
    .table { border-collapse: collapse; }
    .table th, .table td { border: 1px solid #ddd; padding: 8px; }
    .badge { background-color: #e9ecef !important; color: #000 !important; }
</style>
`;
document.head.insertAdjacentHTML('beforeend', printStyles);
</script>

<?php include '../../includes/footer.php'; ?>