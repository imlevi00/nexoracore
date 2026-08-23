<?php
/**
 * Receipt block: returned items for a sale.
 * Expects: $saleReceiptReturns (from getSaleReceiptReturnsData), $saleCurrency
 * A4 also expects: $selectedFields, shouldShowField() in scope
 */
if (empty($saleReceiptReturns['rows'])) {
    return;
}

$rows = $saleReceiptReturns['rows'];
$currency = $saleCurrency ?? ($rows[0]['currency'] ?? 'IQD');
$decimals = $currency === 'USD' ? 2 : 0;
$isA4 = !empty($saleReceiptReturnsIsA4);
$receiptSelectedFields = $selectedFields ?? [];
?>

<?php if ($isA4): ?>
<section class="sale-returns-block items-section" style="margin-top: 16px;">
    <div class="section-title">
        <i class="bi bi-arrow-return-left"></i> کاڵا گەڕاوەکان
    </div>
    <table class="items-table">
        <thead>
            <tr>
                <th class="col-row-num">#</th>
                <?php if (shouldShowField('product_image', $receiptSelectedFields)): ?>
                <th style="width: 80px;">وێنە</th>
                <?php endif; ?>
                <?php if (shouldShowField('product_name', $receiptSelectedFields)): ?>
                <th class="col-product-name">ناوی کاڵا</th>
                <?php endif; ?>
                <?php if (shouldShowField('barcode', $receiptSelectedFields)): ?>
                <th style="width: 12%;">بارکۆد</th>
                <?php endif; ?>
                <?php if (shouldShowField('quantity', $receiptSelectedFields)): ?>
                <th style="width: 10%;">بڕ</th>
                <?php endif; ?>
                <?php if (shouldShowField('unit', $receiptSelectedFields)): ?>
                <th style="width: 13%;">یەکە</th>
                <?php endif; ?>
                <?php if (shouldShowField('unit_price', $receiptSelectedFields)): ?>
                <th style="width: 13%;">نرخی یەکە</th>
                <?php endif; ?>
                <?php if (shouldShowField('total', $receiptSelectedFields)): ?>
                <th style="width: 17%;">کۆ</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $returnRowNum = 1;
            foreach ($rows as $row):
                $qty = (float)$row['quantity'];
                if (is_numeric((string)$qty)) {
                    $normalizedQty = number_format($qty, 6, '.', '');
                    $compactQty = rtrim(rtrim($normalizedQty, '0'), '.');
                } else {
                    $compactQty = (string)$row['quantity'];
                }
                $lineTotal = (float)$row['total_price'];
                $rowCurrency = $row['currency'] ?? $currency;
                $rowDecimals = $rowCurrency === 'USD' ? 2 : 0;
            ?>
                <tr>
                    <td class="row-number"><?php echo $returnRowNum++; ?></td>
                    <?php if (shouldShowField('product_image', $receiptSelectedFields)): ?>
                    <td class="product-image-cell">
                        <?php if (!empty($row['product_image_path']) && product_image_url($row['product_image_path'])): ?>
                            <img src="<?php echo htmlspecialchars(product_image_url($row['product_image_path']) ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                 class="product-image-thumbnail">
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if (shouldShowField('product_name', $receiptSelectedFields)): ?>
                    <td class="col-product-name">
                        <strong><?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <small class="return-item-tag d-block">(گەڕاوە)</small>
                        <?php if (!empty($row['return_number'])): ?>
                            <small class="text-muted d-block" style="font-size: calc(var(--receipt-a4-item-fs, 12px) * 0.85);">
                                <?php echo htmlspecialchars($row['return_number'], ENT_QUOTES, 'UTF-8'); ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if (shouldShowField('barcode', $receiptSelectedFields)): ?>
                    <td><span class="text-muted">-</span></td>
                    <?php endif; ?>
                    <?php if (shouldShowField('quantity', $receiptSelectedFields)): ?>
                    <td>
                        <strong><?php echo $compactQty; ?></strong>
                        <?php if (!empty($row['unit_symbol'])): ?>
                            <?php echo htmlspecialchars($row['unit_symbol'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if (shouldShowField('unit', $receiptSelectedFields)): ?>
                    <td>
                        <?php echo !empty($row['unit_name']) ? htmlspecialchars($row['unit_name'], ENT_QUOTES, 'UTF-8') : '-'; ?>
                    </td>
                    <?php endif; ?>
                    <?php if (shouldShowField('unit_price', $receiptSelectedFields)): ?>
                    <td>
                        <?php
                        echo number_format((float)$row['unit_price'], $rowDecimals);
                        echo $rowCurrency === 'USD' ? '$' : ' د';
                        ?>
                    </td>
                    <?php endif; ?>
                    <?php if (shouldShowField('total', $receiptSelectedFields)): ?>
                    <td>
                        <strong><?php
                        echo number_format($lineTotal, $rowDecimals);
                        echo $rowCurrency === 'USD' ? '$' : ' د';
                        ?></strong>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php else: ?>
<div class="sale-returns-block items-section" style="margin-top: 10px;">
    <div class="section-title" style="font-size: 11px; margin-bottom: 6px; border-bottom: 1px solid #6366f1; padding-bottom: 4px; font-weight: bold; color: #1f2937;">
        کاڵا گەڕاوەکان
    </div>
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
            <?php foreach ($rows as $row): ?>
                <?php
                $qty = (float)$row['quantity'];
                if (is_numeric((string)$qty)) {
                    $normalizedQty = number_format($qty, 6, '.', '');
                    $compactQty = rtrim(rtrim($normalizedQty, '0'), '.');
                } else {
                    $compactQty = (string)$row['quantity'];
                }
                $lineTotal = (float)$row['total_price'];
                $rowCurrency = $row['currency'] ?? $currency;
                $rowDecimals = $rowCurrency === 'USD' ? 2 : 0;
                ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>
                        <small class="return-item-tag" style="font-size: 8px; color: #6b7280;"> (گەڕاوە)</small>
                        <?php if (!empty($row['return_number'])): ?>
                            <br><small style="color: #000; font-size: 8px; font-weight: bold;">کۆد: <?php echo htmlspecialchars($row['return_number'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $compactQty; ?>
                        <?php if (!empty($row['unit_symbol'])): ?>
                            <?php echo htmlspecialchars($row['unit_symbol'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php elseif (!empty($row['unit_name'])): ?>
                            <?php echo htmlspecialchars($row['unit_name'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        echo number_format((float)$row['unit_price'], $rowDecimals);
                        echo $rowCurrency === 'USD' ? '$' : ' د';
                        ?>
                    </td>
                    <td>
                        <strong>
                            <?php
                            echo number_format($lineTotal, $rowDecimals);
                            echo $rowCurrency === 'USD' ? '$' : ' د';
                            ?>
                        </strong>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
