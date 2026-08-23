<?php
/**
 * Receipt block: clarified end-of-receipt totals when a sale has returns.
 *
 * Renders the breakdown requested for sales with returned items:
 *   کۆی پارەی وەسڵ → کۆی پارەی کاڵای گەڕاوە → چەند ماوەتەوە ( وەسڵ - گەڕاوە )
 *   → قەرزی کۆن (debt only) → کۆتای بڕی پارەی ماوە
 *
 * Expects: $sale, $saleCurrency, $totalReturnedAmount, $netFinalAmount
 * Optional: $isDebt, $oldDebtIQD, $oldDebtUSD, $paidAmount,
 *           $totalRemainingIQD, $totalRemainingUSD, $isA4
 */
if ((float)($totalReturnedAmount ?? 0) <= 0) {
    return;
}

$summaryIsA4       = !empty($isA4);
$summaryIsDebt     = !empty($isDebt);
$summaryCurrency   = $saleCurrency ?? 'IQD';
$summaryDecimals   = $summaryCurrency === 'USD' ? 2 : 0;
$summaryUnit       = $summaryCurrency === 'USD' ? '$' : ' دینار';
$summaryInvoice    = (float)($sale['final_amount'] ?? 0);
$summaryReturned   = (float)($totalReturnedAmount ?? 0);
$summaryRemaining  = (float)($netFinalAmount ?? max(0, $summaryInvoice - $summaryReturned));
$summaryOldDebtIQD = (float)($oldDebtIQD ?? 0);
$summaryOldDebtUSD = (float)($oldDebtUSD ?? 0);
$summaryPaid       = (float)($paidAmount ?? 0);
$summaryRemIQD     = (float)($totalRemainingIQD ?? 0);
$summaryRemUSD     = (float)($totalRemainingUSD ?? 0);
?>
<?php if ($summaryIsA4): ?>
<div class="totals-section" style="margin-top: 20px; background: linear-gradient(135deg, #fff8e1 0%, #fffbf0 100%); border: 2px solid #f59e0b;">
    <div class="total-row">
        <span class="total-label total-label-strong">کۆی پارەی وەسڵ:</span>
        <span class="total-value total-value-strong"><?php echo number_format($summaryInvoice, $summaryDecimals); ?> <?php echo $summaryUnit; ?></span>
    </div>
    <div class="total-row">
        <span class="total-label">کۆی پارەی کاڵای گەڕاوە:</span>
        <span class="total-value" style="color: #ef4444;">-<?php echo number_format($summaryReturned, $summaryDecimals); ?> <?php echo $summaryUnit; ?></span>
    </div>
    <div class="total-row">
        <span class="total-label total-label-strong">چەند ماوەتەوە ( وەسڵ - گەڕاوە ):</span>
        <span class="total-value total-value-strong"><?php echo number_format($summaryRemaining, $summaryDecimals); ?> <?php echo $summaryUnit; ?></span>
    </div>

    <?php if ($summaryIsDebt): ?>
        <?php if ($summaryOldDebtIQD > 0): ?>
        <div class="total-row">
            <span class="total-label total-label-strong" style="padding-right: 20px;">&nbsp;&nbsp;قەرزی کۆن (دینار):</span>
            <span class="total-value total-value-strong" style="color: #f59e0b;"><?php echo number_format($summaryOldDebtIQD, 0); ?> دینار</span>
        </div>
        <?php endif; ?>
        <?php if ($summaryOldDebtUSD > 0): ?>
        <div class="total-row">
            <span class="total-label" style="padding-right: 20px;">&nbsp;&nbsp;قەرزی کۆن (دۆلار):</span>
            <span class="total-value" style="color: #f59e0b;"><?php echo number_format($summaryOldDebtUSD, 2); ?> $</span>
        </div>
        <?php endif; ?>

        <?php if ($summaryPaid > 0): ?>
        <div class="total-row">
            <span class="total-label total-label-strong">بڕی پارەی واسڵکراو:</span>
            <span class="total-value total-value-strong" style="color: #10b981;">-<?php echo number_format($summaryPaid, $summaryDecimals); ?> <?php echo $summaryUnit; ?></span>
        </div>
        <?php endif; ?>

        <?php if ($summaryRemIQD > 0): ?>
        <div class="total-row highlight" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
            <span class="total-label total-label-strong" style="padding-right: 20px;">&nbsp;&nbsp;کۆتای بڕی پارەی ماوە (دینار):</span>
            <span class="total-value total-value-strong"><?php echo number_format($summaryRemIQD, 0); ?> دینار</span>
        </div>
        <?php endif; ?>
        <?php if ($summaryRemUSD > 0): ?>
        <div class="total-row highlight" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
            <span class="total-label total-label-strong" style="padding-right: 20px;">&nbsp;&nbsp;کۆتای بڕی پارەی ماوە (دۆلار):</span>
            <span class="total-value total-value-strong"><?php echo number_format($summaryRemUSD, 2); ?> $</span>
        </div>
        <?php endif; ?>
        <?php if ($summaryRemIQD == 0 && $summaryRemUSD == 0): ?>
        <div class="total-row highlight" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
            <span class="total-label total-label-strong" style="padding-right: 20px;">&nbsp;&nbsp;کۆتای بڕی پارەی ماوە:</span>
            <span class="total-value total-value-strong">0</span>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="total-row highlight">
            <span class="total-label total-label-strong">کۆی کۆتایی:</span>
            <span class="total-value total-value-strong"><?php echo number_format($summaryRemaining, $summaryDecimals); ?> <?php echo $summaryUnit; ?></span>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="total-row" style="font-size: 12px;">
    <span style="color: #000; font-weight: bold;">کۆی پارەی وەسڵ:</span>
    <span style="color: #000; font-weight: bold;"><?php echo number_format($summaryInvoice, $summaryDecimals); ?><?php echo $summaryUnit; ?></span>
</div>
<div class="total-row" style="color: #d9534f;">
    <span>کۆی پارەی کاڵای گەڕاوە:</span>
    <span>-<?php echo number_format($summaryReturned, $summaryDecimals); ?><?php echo $summaryUnit; ?></span>
</div>
<div class="total-row" style="border-bottom: none;">
    <strong style="color: #000;">چەند ماوەتەوە ( وەسڵ - گەڕاوە ):</strong>
    <strong style="color: #000;"><?php echo number_format($summaryRemaining, $summaryDecimals); ?><?php echo $summaryUnit; ?></strong>
</div>

<?php if ($summaryIsDebt): ?>
    <?php if ($summaryOldDebtIQD > 0): ?>
    <div class="total-row">
        <span style="color: #000;">قەرزی کۆن (دینار):</span>
        <span style="color: #000;"><?php echo number_format($summaryOldDebtIQD, 0); ?> دینار</span>
    </div>
    <?php endif; ?>
    <?php if ($summaryOldDebtUSD > 0): ?>
    <div class="total-row">
        <span style="color: #000;">قەرزی کۆن (دۆلار):</span>
        <span style="color: #000;"><?php echo number_format($summaryOldDebtUSD, 2); ?> $</span>
    </div>
    <?php endif; ?>

    <?php if ($summaryPaid > 0): ?>
    <div class="total-row" style="color: #5cb85c;">
        <span>بڕی پارەی واسڵکراو:</span>
        <span>-<?php echo number_format($summaryPaid, $summaryDecimals); ?><?php echo $summaryUnit; ?></span>
    </div>
    <?php endif; ?>

    <?php if ($summaryRemIQD > 0): ?>
    <div class="total-row" style="border-bottom: none;">
        <strong style="color: #000;">کۆتای بڕی پارەی ماوە (دینار):</strong>
        <strong style="color: #000;"><?php echo number_format($summaryRemIQD, 0); ?> دینار</strong>
    </div>
    <?php endif; ?>
    <?php if ($summaryRemUSD > 0): ?>
    <div class="total-row" style="border-bottom: none;">
        <strong style="color: #000;">کۆتای بڕی پارەی ماوە (دۆلار):</strong>
        <strong style="color: #000;"><?php echo number_format($summaryRemUSD, 2); ?> $</strong>
    </div>
    <?php endif; ?>
    <?php if ($summaryRemIQD == 0 && $summaryRemUSD == 0): ?>
    <div class="total-row" style="border-bottom: none;">
        <strong style="color: #000;">کۆتای بڕی پارەی ماوە:</strong>
        <strong style="color: #000;">0</strong>
    </div>
    <?php endif; ?>
<?php else: ?>
    <div class="total-row" style="border-bottom: none;">
        <strong class="final-total" style="color: #000;">کۆی کۆتایی:</strong>
        <strong class="final-total" style="color: #000;"><?php echo number_format($summaryRemaining, $summaryDecimals); ?><?php echo $summaryUnit; ?></strong>
    </div>
<?php endif; ?>
<?php endif; ?>
