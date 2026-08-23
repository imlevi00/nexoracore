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
$receipts = [];
$receiptItemsBySale = [];
$linkInfo = null;
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$receiptNumber = trim((string)($_GET['receipt_number'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = 0;
$totalReceipts = 0;
$totalPages = 1;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

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
    $whereConditions = ['dr.user_id = ?', 'dr.customer_id = ?'];
    $params = [$userId, $customerId];
    $types = 'ii';

    if ($dateFrom !== '') {
        $whereConditions[] = 'DATE(dr.receipt_date) >= ?';
        $params[] = $dateFrom;
        $types .= 's';
    }

    if ($dateTo !== '') {
        $whereConditions[] = 'DATE(dr.receipt_date) <= ?';
        $params[] = $dateTo;
        $types .= 's';
    }

    if ($receiptNumber !== '') {
        $whereConditions[] = 'dr.receipt_number LIKE ?';
        $params[] = '%' . $receiptNumber . '%';
        $types .= 's';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

    $countSql = "
        SELECT COUNT(*) AS total
        FROM debt_receipts dr
        LEFT JOIN debt_payments dp ON dp.id = dr.debt_payment_id
        LEFT JOIN debts d ON d.id = dp.debt_id
        LEFT JOIN sales s ON s.id = d.sale_id
        $whereClause
    ";

    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc();
        $totalReceipts = (int)($countRow['total'] ?? 0);
        $countStmt->close();

        if ($totalReceipts > 0) {
            $totalPages = (int)ceil($totalReceipts / $limit);
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $limit;
        } else {
            $totalPages = 1;
            $page = 1;
            $offset = 0;
        }
    } else {
        $error = 'هەڵە لە هەژمارکردنی وەسڵەکان.';
    }

    if ($error === '') {
        $receiptsSql = "
            SELECT
                dr.receipt_number,
                dr.payment_amount,
                COALESCE(dr.currency, COALESCE(s.currency, 'IQD')) AS currency,
                dr.payment_method,
                dr.notes,
                dr.receipt_date,
                DATE_FORMAT(dr.receipt_date, '%Y/%m/%d') AS receipt_day,
                DATE_FORMAT(dr.receipt_date, '%h:%i %p') AS receipt_time,
                COALESCE(s.invoice_number, d.sale_id) AS invoice_number,
                d.sale_id,
                d.total_debt,
                d.paid_amount,
                d.remaining_amount
            FROM debt_receipts dr
            LEFT JOIN debt_payments dp ON dp.id = dr.debt_payment_id
            LEFT JOIN debts d ON d.id = dp.debt_id
            LEFT JOIN sales s ON s.id = d.sale_id
            $whereClause
            ORDER BY dr.receipt_date DESC, dr.id DESC
            LIMIT ? OFFSET ?
        ";

        $receiptsStmt = $conn->prepare($receiptsSql);
        if ($receiptsStmt) {
            $receiptParams = array_merge($params, [$limit, $offset]);
            $receiptTypes = $types . 'ii';
            $receiptsStmt->bind_param($receiptTypes, ...$receiptParams);
            $receiptsStmt->execute();
            $receipts = $receiptsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $receiptsStmt->close();
        } else {
            $error = 'هەڵە لە وەرگرتنی وەسڵەکان.';
        }
    }
}

if (!$accessDenied && !empty($receipts) && $conn instanceof mysqli) {
    $saleIds = [];
    foreach ($receipts as $receipt) {
        $saleId = (int)($receipt['sale_id'] ?? 0);
        if ($saleId > 0) {
            $saleIds[$saleId] = $saleId;
        }
    }

    if (!empty($saleIds)) {
        $saleIds = array_values($saleIds);
        $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
        $types = str_repeat('i', count($saleIds) + 1);
        $params = array_merge([$userId], $saleIds);

        $itemsSql = "
            SELECT
                si.sale_id,
                COALESCE(p.name, si.product_name) AS product_name,
                si.quantity,
                si.unit_price,
                si.total_price,
                si.unit_symbol,
                si.unit_name,
                COALESCE(si.currency, 'IQD') AS currency
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.user_id = ? AND si.sale_id IN ($placeholders)
            ORDER BY si.sale_id ASC, si.id ASC
        ";

        $itemsStmt = $conn->prepare($itemsSql);
        if ($itemsStmt) {
            $itemsStmt->bind_param($types, ...$params);
            $itemsStmt->execute();
            $allItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();

            foreach ($allItems as $item) {
                $sid = (int)($item['sale_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                if (!isset($receiptItemsBySale[$sid])) {
                    $receiptItemsBySale[$sid] = [];
                }
                $receiptItemsBySale[$sid][] = $item;
            }
        }
    }
}

function formatAmountDisplay($amount, $currency = 'IQD')
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
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title>وەسڵی قەرزەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="container py-4 py-lg-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-receipt-cutoff"></i> وەسڵی قەرزەکان</h4>
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
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <form method="GET" action="">
                    <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                    <input type="hidden" name="customer_id" value="<?php echo (int)$customerId; ?>">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">لە بەروار</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">تا بەروار</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">گەڕان بە ژمارەی وەسڵ</label>
                            <input type="text" class="form-control" name="receipt_number" value="<?php echo htmlspecialchars($receiptNumber); ?>" placeholder="نمونە: RCP-00125">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> گەڕان
                            </button>
                            <a class="btn btn-outline-secondary"
                               href="<?php echo url('my_account/debt_receipts.php?user_id=' . (int)$userId . '&customer_id=' . (int)$customerId); ?>">
                                <i class="bi bi-arrow-clockwise"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-body d-flex justify-content-between align-items-center">
                <div class="fw-bold">
                    <i class="bi bi-list-ul"></i>
                    <?php echo number_format($totalReceipts); ?> وەسڵ
                </div>
                <small class="text-muted">پەڕەی <?php echo (int)$page; ?> لە <?php echo (int)$totalPages; ?></small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($receipts)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-5 text-muted"></i>
                        <p class="text-muted mt-3 mb-0">هیچ وەسڵێک نەدۆزرایەوە.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="bg-body-tertiary">
                                <tr>
                                    <th>#</th>
                                    <th>ژمارەی وەسڵ</th>
                                    <th>فاتورە</th>
                                    <th>بەروار/کات</th>
                                    <th>بڕی پارەدان</th>
                                    <th>شێوازی پارەدان</th>
                                    <th>وردەکاری قەرز</th>
                                    <th>تێبینی</th>
                                    <th>کاڵاکان</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($receipts as $index => $receipt): ?>
                                <?php
                                    $saleId = (int)($receipt['sale_id'] ?? 0);
                                    $receiptItems = $saleId > 0 ? ($receiptItemsBySale[$saleId] ?? []) : [];
                                    $collapseId = 'receipt-items-' . $index . '-' . $saleId;
                                ?>
                                <tr>
                                    <td><?php echo $offset + $index + 1; ?></td>
                                    <td><code><?php echo htmlspecialchars($receipt['receipt_number'] ?? '-'); ?></code></td>
                                    <td><?php echo htmlspecialchars((string)($receipt['invoice_number'] ?? '-')); ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($receipt['receipt_day'] ?? '-'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($receipt['receipt_time'] ?? '-'); ?></small>
                                    </td>
                                    <td class="fw-bold text-success">
                                        <?php echo formatAmountDisplay($receipt['payment_amount'] ?? 0, $receipt['currency'] ?? 'IQD'); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(paymentMethodLabel($receipt['payment_method'] ?? '')); ?></td>
                                    <td>
                                        <div><small class="text-muted">کۆی قەرز: </small><?php echo formatAmountDisplay($receipt['total_debt'] ?? 0, $receipt['currency'] ?? 'IQD'); ?></div>
                                        <div><small class="text-muted">پارەدراو: </small><?php echo formatAmountDisplay($receipt['paid_amount'] ?? 0, $receipt['currency'] ?? 'IQD'); ?></div>
                                        <div><small class="text-muted">ماوە: </small><?php echo formatAmountDisplay($receipt['remaining_amount'] ?? 0, $receipt['currency'] ?? 'IQD'); ?></div>
                                    </td>
                                    <td><?php echo !empty($receipt['notes']) ? htmlspecialchars($receipt['notes']) : '-'; ?></td>
                                    <td>
                                        <?php if (!empty($receiptItems)): ?>
                                            <button class="btn btn-sm btn-outline-primary"
                                                    type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#<?php echo htmlspecialchars($collapseId); ?>"
                                                    aria-expanded="false">
                                                <i class="bi bi-box-seam"></i> بینینی کاڵاکان
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($receiptItems)): ?>
                                    <tr class="collapse bg-body-tertiary" id="<?php echo htmlspecialchars($collapseId); ?>">
                                        <td colspan="9">
                                            <div class="p-2 p-md-3 border rounded bg-body">
                                                <h6 class="mb-3">
                                                    <i class="bi bi-list-check"></i>
                                                    کاڵاکانی وەسڵ: <code><?php echo htmlspecialchars($receipt['receipt_number'] ?? '-'); ?></code>
                                                </h6>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-striped table-bordered align-middle mb-0">
                                                        <thead class="bg-body-tertiary">
                                                            <tr>
                                                                <th>#</th>
                                                                <th>ناوی کاڵا</th>
                                                                <th>بڕ</th>
                                                                <th>نرخی یەکە</th>
                                                                <th>کۆی گشتی</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach ($receiptItems as $itemIndex => $item): ?>
                                                            <?php
                                                                $itemCurrency = $item['currency'] ?? ($receipt['currency'] ?? 'IQD');
                                                                $qty = (float)($item['quantity'] ?? 0);
                                                                $qtyText = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
                                                                if ($qtyText === '') {
                                                                    $qtyText = '0';
                                                                }
                                                                $unitLabel = !empty($item['unit_symbol']) ? $item['unit_symbol'] : ($item['unit_name'] ?? '');
                                                            ?>
                                                            <tr>
                                                                <td><?php echo $itemIndex + 1; ?></td>
                                                                <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                                                <td>
                                                                    <?php echo htmlspecialchars($qtyText); ?>
                                                                    <?php if (!empty($unitLabel)): ?>
                                                                        <small class="text-muted"><?php echo htmlspecialchars($unitLabel); ?></small>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?php echo formatAmountDisplay($item['unit_price'] ?? 0, $itemCurrency); ?></td>
                                                                <td class="fw-bold"><?php echo formatAmountDisplay($item['total_price'] ?? 0, $itemCurrency); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php
                $queryString = http_build_query(array_filter([
                    'user_id' => $userId,
                    'customer_id' => $customerId,
                    'date_from' => $dateFrom ?: null,
                    'date_to' => $dateTo ?: null,
                    'receipt_number' => $receiptNumber ?: null
                ]));
                $baseUrl = url('my_account/debt_receipts.php') . ($queryString ? '?' . $queryString . '&' : '?');
            ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>">پێشوو</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>">دواتر</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
