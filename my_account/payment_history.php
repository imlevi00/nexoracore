<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/kasher_zanyari/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$googleUser = $_SESSION['google_user'] ?? null;
$error = '';
$accessDenied = false;
$payments = [];
$linkInfo = null;

if (empty($googleUser['email'])) {
    redirect(url('my_account/index.php'));
}

$gmail = strtolower(trim($googleUser['email']));
$userId = (int)($_GET['user_id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);

if ($userId <= 0 || $customerId <= 0) {
    $accessDenied = true;
    $error = 'داواکارییەکە نادروستە.';
}

if (!$accessDenied && $conn instanceof mysqli) {
    $linkSql = "
        SELECT cgl.user_id, cgl.customer_id, c.name AS customer_name, u.business_name
        FROM customer_gmail_links cgl
        INNER JOIN customers c ON c.id = cgl.customer_id AND c.user_id = cgl.user_id
        INNER JOIN users u ON u.id = cgl.user_id
        WHERE cgl.user_id = ? AND cgl.customer_id = ? AND LOWER(cgl.gmail) = ?
        LIMIT 1
    ";

    $linkStmt = $conn->prepare($linkSql);
    if ($linkStmt) {
        $linkStmt->bind_param('iis', $userId, $customerId, $gmail);
        $linkStmt->execute();
        $linkInfo = $linkStmt->get_result()->fetch_assoc();
        $linkStmt->close();
    }

    if (empty($linkInfo)) {
        $accessDenied = true;
        $error = 'تۆ مۆڵەتی بینینی ئەم زانیاریانە نییە.';
    }
}

if (!$accessDenied && $conn instanceof mysqli) {
    $paymentsSql = "
        SELECT
            dp.id,
            dp.payment_amount,
            dp.payment_date,
            dp.notes,
            DATE_FORMAT(dp.payment_date, '%Y/%m/%d') AS payment_day,
            DATE_FORMAT(dp.payment_date, '%h:%i %p') AS payment_time,
            dr.receipt_number,
            dr.payment_method,
            dr.notes AS receipt_notes,
            COALESCE(dr.currency, COALESCE(s.currency, 'IQD')) AS currency,
            COALESCE(s.invoice_number, d.sale_id) AS invoice_number
        FROM debt_payments dp
        INNER JOIN debts d ON d.id = dp.debt_id
        LEFT JOIN debt_receipts dr ON dr.debt_payment_id = dp.id
        LEFT JOIN sales s ON s.id = d.sale_id
        WHERE d.user_id = ? AND d.customer_id = ?
        ORDER BY dp.payment_date DESC, dp.id DESC
    ";

    $paymentsStmt = $conn->prepare($paymentsSql);
    if ($paymentsStmt) {
        $paymentsStmt->bind_param('ii', $userId, $customerId);
        $paymentsStmt->execute();
        $payments = $paymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $paymentsStmt->close();
    } else {
        $error = 'هەڵە لە وەرگرتنی مێژووی پارەدانەوەکان.';
    }
}

function formatAmountHistory($amount, $currency = 'IQD')
{
    if (($currency ?? 'IQD') === 'USD') {
        return number_format((float)$amount, 2) . ' USD';
    }
    return number_format((float)$amount, 0) . ' IQD';
}

function formatPaymentMethodHistory($method)
{
    $methodText = trim((string)$method);
    if ($methodText === '') {
        return '-';
    }

    if (strtolower($methodText) === 'cash') {
        return 'کاش';
    }

    return $methodText;
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title>مێژووی پارەدانەوەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="container py-4 py-lg-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-clock-history"></i> مێژووی پارەدانەوەکان</h4>
            <?php if (!empty($linkInfo)): ?>
                <div class="text-muted">
                    <?php echo htmlspecialchars($linkInfo['business_name'] ?? '-'); ?> /
                    <?php echo htmlspecialchars($linkInfo['customer_name'] ?? '-'); ?>
                </div>
            <?php endif; ?>
        </div>
        <a class="btn btn-outline-secondary" href="<?php echo url('my_account/index.php'); ?>">
            <i class="bi bi-arrow-right"></i> گەڕانەوە
        </a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!$accessDenied): ?>
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <?php if (empty($payments)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-5 text-muted"></i>
                        <p class="text-muted mt-3 mb-0">هیچ پارەدانەوەیەک نەدۆزرایەوە.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="bg-body-tertiary">
                                <tr>
                                    <th>#</th>
                                    <th>بەروار/کات</th>
                                    <th>بڕی پارەدانەوە</th>
                                    <th>فاتورە</th>
                                    <th>ژمارەی وەسڵ</th>
                                    <th>شێوازی پارەدان</th>
                                    <th>تێبینی</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($payments as $index => $payment): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($payment['payment_day'] ?? '-'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($payment['payment_time'] ?? '-'); ?></small>
                                    </td>
                                    <td class="fw-bold text-success">
                                        <?php echo formatAmountHistory($payment['payment_amount'] ?? 0, $payment['currency'] ?? 'IQD'); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($payment['invoice_number'] ?? '-')); ?></td>
                                    <td>
                                        <?php if (!empty($payment['receipt_number'])): ?>
                                            <code><?php echo htmlspecialchars($payment['receipt_number']); ?></code>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(formatPaymentMethodHistory($payment['payment_method'] ?? '')); ?></td>
                                    <td>
                                        <?php
                                            $note = $payment['receipt_notes'] ?: ($payment['notes'] ?? '');
                                            echo !empty($note) ? htmlspecialchars($note) : '-';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
