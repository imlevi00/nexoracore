<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/kasher_zanyari/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$linkedCustomers = [];
$latestReceiptsMap = [];
$googleUser = $_SESSION['google_user'] ?? null;

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    unset($_SESSION['google_user'], $_SESSION['oauth2_state']);
    redirect(url('my_account/index.php'));
}

if (isset($_GET['login']) && $_GET['login'] === 'google') {
    redirect(url('videos/google-login.php?return_to=my_account/index.php'));
}

$googleUser = $_SESSION['google_user'] ?? null;

if (!empty($googleUser['email']) && $conn instanceof mysqli) {
    $gmail = strtolower(trim($googleUser['email']));
    $sql = "
        SELECT
            cgl.user_id,
            u.business_name,
            c.id AS customer_id,
            c.name AS customer_name,
            cgl.gmail,
            COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'IQD' THEN d.total_debt ELSE 0 END), 0) AS total_debt_iqd,
            COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'USD' THEN d.total_debt ELSE 0 END), 0) AS total_debt_usd,
            COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'IQD' THEN d.paid_amount ELSE 0 END), 0) AS paid_iqd,
            COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'USD' THEN d.paid_amount ELSE 0 END), 0) AS paid_usd,
            COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) AS debt_iqd,
            COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) AS debt_usd
        FROM customer_gmail_links cgl
        INNER JOIN customers c ON c.id = cgl.customer_id AND c.user_id = cgl.user_id
        INNER JOIN users u ON u.id = cgl.user_id
        LEFT JOIN debts d ON d.customer_id = c.id AND d.user_id = c.user_id AND d.status = 'active'
        LEFT JOIN sales s ON s.id = d.sale_id
        WHERE LOWER(cgl.gmail) = ?
        GROUP BY cgl.user_id, u.business_name, c.id, c.name, cgl.gmail
        ORDER BY u.business_name ASC, c.name ASC
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $gmail);
        $stmt->execute();
        $linkedCustomers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $error = 'هەڵە لە وەرگرتنی زانیاری فرۆشگاکان.';
    }

    if (empty($error) && !empty($linkedCustomers)) {
        $receiptSql = "
            SELECT
                dr.id,
                dr.user_id,
                dr.customer_id,
                dr.receipt_number,
                dr.payment_amount,
                COALESCE(dr.currency, COALESCE(s.currency, 'IQD')) AS currency,
                dr.payment_method,
                dr.notes,
                d.total_debt,
                d.paid_amount,
                d.remaining_amount,
                DATE_FORMAT(dr.receipt_date, '%Y/%m/%d') AS receipt_day,
                DATE_FORMAT(dr.receipt_date, '%H:%i') AS receipt_time
            FROM debt_receipts dr
            LEFT JOIN debt_payments dp ON dp.id = dr.debt_payment_id
            LEFT JOIN debts d ON d.id = dp.debt_id
            LEFT JOIN sales s ON s.id = d.sale_id
            INNER JOIN customer_gmail_links cgl ON cgl.user_id = dr.user_id AND cgl.customer_id = dr.customer_id
            WHERE LOWER(cgl.gmail) = ?
            ORDER BY dr.receipt_date DESC, dr.id DESC
        ";

        $receiptStmt = $conn->prepare($receiptSql);
        if ($receiptStmt) {
            $receiptStmt->bind_param('s', $gmail);
            $receiptStmt->execute();
            $allReceipts = $receiptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $receiptStmt->close();

            foreach ($allReceipts as $receipt) {
                $key = (int)$receipt['user_id'] . '_' . (int)$receipt['customer_id'];
                if (!isset($latestReceiptsMap[$key])) {
                    $latestReceiptsMap[$key] = [];
                }
                if (count($latestReceiptsMap[$key]) < 3) {
                    $latestReceiptsMap[$key][] = $receipt;
                }
            }
        }
    }
}

function formatMoneyLite($amount, $currency = 'IQD')
{
    if (($currency ?? 'IQD') === 'USD') {
        return number_format((float)$amount, 2) . ' USD';
    }
    return number_format((float)$amount, 0) . ' IQD';
}

function paymentMethodLabel($method)
{
    if ($method === 'cash') {
        return 'کاش';
    }
    if ($method === 'bank_transfer') {
        return 'گواستنەوەی بانکی';
    }
    if ($method === 'check') {
        return 'چەک';
    }
    if ($method === 'credit') {
        return 'قەرز';
    }
    return '-';
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>هەژماری من - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; }
        .profile-img { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <h4 class="mb-3"><i class="bi bi-person-circle"></i> هەژماری من</h4>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <?php if (empty($googleUser)): ?>
                        <p class="text-muted mb-4">بۆ بینی مامەڵەکانت لەم وێب سایتە ، لەڕێگەی جیمێڵ چوونەژوورەوە بکە.</p>
                        <a class="btn btn-danger" href="<?php echo url('my_account/index.php?login=google'); ?>">
                            <i class="bi bi-google"></i> چوونەژوورەوە بە Google
                        </a>
                    <?php else: ?>
                        <div class="d-flex align-items-center gap-3 mb-4">
                            <?php if (!empty($googleUser['picture'])): ?>
                                <img src="<?php echo htmlspecialchars($googleUser['picture']); ?>" alt="Google Avatar" class="profile-img">
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($googleUser['name'] ?? ''); ?></div>
                                <div class="text-muted"><?php echo htmlspecialchars($googleUser['email'] ?? ''); ?></div>
                            </div>
                            <div class="ms-auto">
                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('my_account/index.php?logout=1'); ?>">
                                    <i class="bi bi-box-arrow-left"></i> دەرچوون
                                </a>
                            </div>
                        </div>

                        <h5 class="mb-3">فرۆشگا ۆنڵاینەکان</h5>
                        <?php if (empty($linkedCustomers)): ?>
                            <div class="alert alert-info mb-0">
                                هیچ تۆمارێک بۆ ئەم Gmail ـە نەدۆزرایەوە.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead class="bg-body-tertiary">
                                        <tr>
                                            <th>ناوی فرۆشگا</th>
                                            <th>ناوی کڕیار</th>
                                            <th>Gmail</th>
                                            <th>قەرزی دینار</th>
                                            <th>قەرزی دۆلار</th>
                                            <th>کردارەکان</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($linkedCustomers as $item): ?>
                                            <?php
                                                $receiptKey = (int)($item['user_id'] ?? 0) . '_' . (int)($item['customer_id'] ?? 0);
                                                $latestReceipts = $latestReceiptsMap[$receiptKey] ?? [];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['business_name'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($item['customer_name'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($item['gmail'] ?? '-'); ?></td>
                                                <td><?php echo number_format((float)($item['debt_iqd'] ?? 0), 0); ?> IQD</td>
                                                <td><?php echo number_format((float)($item['debt_usd'] ?? 0), 2); ?> USD</td>
                                                <td>
                                                    <div class="d-flex gap-2 flex-wrap">
                                                        <a class="btn btn-sm btn-outline-primary"
                                                           href="<?php echo url('my_account/debt_receipts.php?user_id=' . (int)($item['user_id'] ?? 0) . '&customer_id=' . (int)($item['customer_id'] ?? 0)); ?>">
                                                            <i class="bi bi-receipt-cutoff"></i> وەسڵی قەرزەکان
                                                        </a>
                                                        <a class="btn btn-sm btn-outline-info"
                                                           href="<?php echo url('my_account/payment_history.php?user_id=' . (int)($item['user_id'] ?? 0) . '&customer_id=' . (int)($item['customer_id'] ?? 0)); ?>">
                                                            <i class="bi bi-clock-history"></i> مێژووی پارەدانەوە
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr class="bg-body-tertiary">
                                                <td colspan="6">
                                                    <div class="mb-2">
                                                        <strong class="text-secondary">
                                                            <i class="bi bi-receipt-cutoff"></i> ٣ کۆتا وەسڵ
                                                        </strong>
                                                    </div>
                                                    <?php if (empty($latestReceipts)): ?>
                                                        <div class="text-muted small">هیچ وەسڵێک بۆ ئەم کڕیارە نەدۆزرایەوە.</div>
                                                    <?php else: ?>
                                                        <div class="table-responsive">
                                                            <table class="table table-sm table-bordered align-middle mb-0 bg-body">
                                                                <thead class="bg-body-tertiary">
                                                                    <tr>
                                                                        <th>ژمارەی وەسڵ</th>
                                                                        <th>بەروار/کات</th>
                                                                        <th>بڕی پارەدان</th>
                                                                        <th>وردەکاریی قەرز</th>
                                                                        <th>شێوازی پارەدان</th>
                                                                        <th>تێبینی</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($latestReceipts as $receipt): ?>
                                                                        <tr>
                                                                            <td><code><?php echo htmlspecialchars($receipt['receipt_number'] ?? '-'); ?></code></td>
                                                                            <td>
                                                                                <div><?php echo htmlspecialchars($receipt['receipt_day'] ?? '-'); ?></div>
                                                                                <small class="text-muted"><?php echo htmlspecialchars($receipt['receipt_time'] ?? '-'); ?></small>
                                                                            </td>
                                                                            <td class="fw-bold text-success"><?php echo formatMoneyLite($receipt['payment_amount'] ?? 0, $receipt['currency'] ?? 'IQD'); ?></td>
                                                                            <td class="small">
                                                                                <div><small class="text-muted">کۆی قەرز: </small><?php echo formatMoneyLite($receipt['total_debt'] ?? 0, $receipt['currency'] ?? 'IQD'); ?></div>
                                                                                <div><small class="text-muted">پارەدراو: </small><?php echo formatMoneyLite($receipt['paid_amount'] ?? 0, $receipt['currency'] ?? 'IQD'); ?></div>
                                                                                <div><small class="text-muted">ماوە: </small><?php echo formatMoneyLite($receipt['remaining_amount'] ?? 0, $receipt['currency'] ?? 'IQD'); ?></div>
                                                                            </td>
                                                                            <td><?php echo htmlspecialchars(paymentMethodLabel($receipt['payment_method'] ?? '')); ?></td>
                                                                            <td><?php echo !empty($receipt['notes']) ? htmlspecialchars($receipt['notes']) : '-'; ?></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
