<?php
$counter = 1;
$mdash = "\u{2014}";
foreach ($transactionsWithRunningDebt as $transaction):
    $currency = $transaction['currency'] ?? 'IQD';
    $transactionDateTime = date('Y/m/d H:i', strtotime($transaction['transaction_date']));
    $typeMeta = getTransactionTypeMeta($transaction['type'] ?? '');
    $reducesDebt = in_array(($transaction['type'] ?? ''), ['payment', 'return_adjustment'], true);
    $amountClass = $reducesDebt ? 'text-success' : 'text-warning';
    $amountPrefix = $reducesDebt ? '-' : '';
    $totalCellHtml = '<span class="' . htmlspecialchars($amountClass, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($amountPrefix . formatAmount($transaction['amount'], $currency), ENT_QUOTES, 'UTF-8')
        . '</span>';
    $runningDebtVal = (float)($transaction['running_debt'] ?? 0);
    $runningClass = $runningDebtVal > 0 ? 'text-danger' : 'text-success';
    $runningCellHtml = '<span class="fw-bold ' . htmlspecialchars($runningClass, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars(formatAmount($runningDebtVal, $currency), ENT_QUOTES, 'UTF-8')
        . '</span>';

    $saleIdRow = isset($transaction['sale_id']) ? (int)$transaction['sale_id'] : 0;
    $txType = $transaction['type'] ?? '';

    $showCreditLines = ($statementShowCreditItemDetails ?? false)
        && $txType === 'credit'
        && $saleIdRow > 0
        && !empty($itemsBySaleId[$saleIdRow]);

    $returnNum = $transaction['return_number'] ?? '';
    $returnItems = ($returnNum !== '' && !empty($itemsByReturnNumber[$returnNum])) ? $itemsByReturnNumber[$returnNum] : [];
    $showReturnLines = ($statementShowReturnItemDetails ?? false)
        && $txType === 'return_adjustment'
        && !empty($returnItems);

    if ($showCreditLines):
        foreach ($itemsBySaleId[$saleIdRow] as $item):
            $itemCurrency = $item['currency'] ?? 'IQD';
            $qty = (float)($item['quantity'] ?? 0);
            $lineTotal = (float)($item['total_price'] ?? 0);
            $unit = (float)($item['unit_price'] ?? 0);
            if ($unit <= 0 && $qty > 0) {
                $unit = $lineTotal / $qty;
            }
            $unitCell = ($unit > 0) ? formatAmount($unit, $itemCurrency) : $mdash;
            $productLabel = htmlspecialchars($item['product_name_display'] ?? $item['product_name'] ?? '-', ENT_QUOTES, 'UTF-8');
?>
    <tr>
        <td class="text-muted"><?php echo $counter++; ?></td>
        <td>
            <span class="statement-type-pill <?php echo htmlspecialchars($typeMeta['class'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($typeMeta['text'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </td>
        <td class="text-nowrap"><?php echo htmlspecialchars($transactionDateTime, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="statement-col-product"><?php echo $productLabel; ?></td>
        <td class="text-end"><?php echo formatStatementQty($qty); ?></td>
        <td class="text-end"><?php echo htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="text-end fw-semibold"><?php echo formatAmount($lineTotal, $itemCurrency); ?></td>
        <td class="text-end"><?php echo $runningCellHtml; ?></td>
    </tr>
<?php
        endforeach;
    elseif ($showReturnLines):
        foreach ($returnItems as $rItem):
            $qty = (float)($rItem['quantity'] ?? 0);
            $lineTotal = (float)($rItem['total_price'] ?? 0);
            $unit = (float)($rItem['unit_price'] ?? 0);
            if ($unit <= 0 && $qty > 0) {
                $unit = $lineTotal / $qty;
            }
            $unitCell = ($unit > 0) ? formatAmount($unit, $currency) : $mdash;
            $productLabel = htmlspecialchars($rItem['product_name'] ?? '-', ENT_QUOTES, 'UTF-8');
?>
    <tr>
        <td class="text-muted"><?php echo $counter++; ?></td>
        <td>
            <span class="statement-type-pill <?php echo htmlspecialchars($typeMeta['class'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($typeMeta['text'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </td>
        <td class="text-nowrap"><?php echo htmlspecialchars($transactionDateTime, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="statement-col-product"><?php echo $productLabel; ?></td>
        <td class="text-end"><?php echo formatStatementQty($qty); ?></td>
        <td class="text-end"><?php echo htmlspecialchars($unitCell, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="text-end fw-semibold"><?php echo formatAmount($lineTotal, $currency); ?></td>
        <td class="text-end"><?php echo $runningCellHtml; ?></td>
    </tr>
<?php
        endforeach;
    else:
?>
    <tr>
        <td class="text-muted"><?php echo $counter++; ?></td>
        <td>
            <span class="statement-type-pill <?php echo htmlspecialchars($typeMeta['class'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($typeMeta['text'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </td>
        <td class="text-nowrap"><?php echo htmlspecialchars($transactionDateTime, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="statement-col-product text-muted"><?php echo htmlspecialchars($mdash, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="text-end text-muted"><?php echo htmlspecialchars($mdash, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="text-end text-muted"><?php echo htmlspecialchars($mdash, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="text-end fw-semibold"><?php echo $totalCellHtml; ?></td>
        <td class="text-end"><?php echo $runningCellHtml; ?></td>
    </tr>
<?php
    endif;
endforeach;
