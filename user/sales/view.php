<?php
/**
 * وردەکاری فرۆشتن - user/sales/view.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// تاقیکردنی داخڵبوون
if (!isUser()) {
    redirect(url('user/auth/login.php'));
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$saleId = (int)($_GET['id'] ?? 0);

if (!$saleId) {
    setMessage('ID ی فرۆشتن پێویستە', 'error');
    redirect(url('user/pos/main.php'));
}

// وەرگرتنی زانیاری فرۆشتن
$stmt = $conn->prepare("
    SELECT s.*, 
           COALESCE(s.currency, 'IQD') as currency,
           c.name as customer_name_updated,
           c.phone as customer_phone_updated,
           c.address as customer_address,
           DATE_FORMAT(s.sale_date, '%Y/%m/%d %H:%i:%s') as formatted_date,
           DATE_FORMAT(s.sale_date, '%d/%m/%Y') as short_date,
           DATE_FORMAT(s.sale_date, '%H:%i') as time
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.id = ? AND s.user_id = ?
");
$stmt->bind_param('ii', $saleId, $userId);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) {
    setMessage('فرۆشتن نەدۆزرایەوە', 'error');
    redirect(url('user/pos/main.php'));
}

// وەرگرتنی ئایتمەکانی فرۆشتن
$itemsStmt = $conn->prepare("
    SELECT si.*, 
           COALESCE(si.currency, s.currency, 'IQD') as currency,
           p.name as product_name_updated,
           p.barcode,
           COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as current_stock
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
    LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
    LEFT JOIN product_units pu_any ON pu_any.id = (
        SELECT pu2.id
        FROM product_units pu2
        WHERE pu2.product_id = p.id
        ORDER BY pu2.is_primary DESC, pu2.id ASC
        LIMIT 1
    )
    LEFT JOIN sales s ON si.sale_id = s.id
    WHERE si.sale_id = ?
    ORDER BY si.id
");
$itemsStmt->bind_param('i', $saleId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی زانیاری قەرز (ئەگەر هەبوو)
$debt = null;
if (in_array($sale['payment_method'], ['debt', 'installment'])) {
    $debtStmt = $conn->prepare("
        SELECT d.*, 
               COUNT(dp.id) as payment_count,
               COALESCE(SUM(dp.payment_amount), 0) as total_paid
        FROM debts d
        LEFT JOIN debt_payments dp ON d.id = dp.debt_id
        WHERE d.sale_id = ?
        GROUP BY d.id
    ");
    $debtStmt->bind_param('i', $saleId);
    $debtStmt->execute();
    $debt = $debtStmt->get_result()->fetch_assoc();
}

// وەرگرتنی مێژووی پارەدانەکان (ئەگەر قەرز بوو)
$payments = [];
if ($debt) {
    $paymentsStmt = $conn->prepare("
        SELECT dp.*, 
               dr.receipt_number,
               DATE_FORMAT(dp.payment_date, '%d/%m/%Y %H:%i') as formatted_date
        FROM debt_payments dp
        LEFT JOIN debt_receipts dr ON dp.id = dr.debt_payment_id
        WHERE dp.debt_id = ?
        ORDER BY dp.payment_date DESC
    ");
    $paymentsStmt->bind_param('i', $debt['id']);
    $paymentsStmt->execute();
    $payments = $paymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$pageTitle = "وردەکاری فرۆشتن #" . $sale['invoice_number'];
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    
    <style>
        @media print {
            .no-print { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            .container-fluid { padding: 0 !important; }
            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .card,
            html[data-bs-theme='dark'] .card *,
            html[data-bs-theme='dark'] .table,
            html[data-bs-theme='dark'] .table th,
            html[data-bs-theme='dark'] .table td {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #d1d5db !important;
                box-shadow: none !important;
            }
        }
        .receipt-header {
            border-bottom: 2px solid #007bff;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        .amount-highlight {
            font-size: 1.2em;
            font-weight: bold;
        }
    </style>
</head>
<body class="bg-light">

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary no-print">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo url('user/dashboard/index.php'); ?>">
                <i class="bi bi-shop"></i>
                <?php echo htmlspecialchars($currentUser['business_name']); ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="<?php echo url('user/pos/main.php'); ?>">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ فرۆشتنەکان
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid py-4">
        
        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1 class="h3 mb-0">
                <i class="bi bi-receipt text-primary"></i>
                <?php echo $pageTitle; ?>
            </h1>
            <div>
                <button onclick="window.print()" class="btn btn-outline-primary">
                    <i class="bi bi-printer"></i> چاپ
                </button>
                <a href="<?php echo url('user/pos/main.php'); ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> گەڕانەوە
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Sale Details -->
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <!-- Receipt Header -->
                        <div class="receipt-header text-center">
                            <h2 class="text-primary mb-2"><?php echo htmlspecialchars($currentUser['business_name']); ?></h2>
                      <?php if (!empty($currentUser['address'])): ?>
    <p class="mb-0"><?php echo htmlspecialchars($currentUser['address']); ?></p>
<?php endif; ?>
<?php if (!empty($currentUser['phone'])): ?>
    <p class="mb-0">تەلەفۆن: <?php echo htmlspecialchars($currentUser['phone']); ?></p>
<?php endif; ?>
                        </div>

                        <!-- Invoice Info -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>زانیاری پسووڵە</h5>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td><strong>ژمارەی پسووڵە:</strong></td>
                                        <td><code><?php echo $sale['invoice_number']; ?></code></td>
                                    </tr>
                                    <tr>
                                        <td><strong>بەروار:</strong></td>
                                        <td><?php echo $sale['short_date']; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>کات:</strong></td>
                                        <td><?php echo $sale['time']; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>شێوەی پارەدان:</strong></td>
                                        <td>
                                            <span class="badge bg-<?php echo $sale['payment_method'] === 'cash' ? 'success' : ($sale['payment_method'] === 'debt' ? 'warning' : 'info'); ?>">
                                                <?php 
                                                echo $sale['payment_method'] === 'cash' ? 'کاش' : 
                                                     ($sale['payment_method'] === 'debt' ? 'قەرز' : 'قسط'); 
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h5>زانیاری کڕیار</h5>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td><strong>ناو:</strong></td>
                                        <td><?php echo htmlspecialchars($sale['customer_name_updated'] ?: $sale['customer_name'] ?: 'کڕیاری گشتی'); ?></td>
                                    </tr>
                                    <?php if ($sale['customer_phone_updated'] || $sale['customer_phone']): ?>
                                    <tr>
                                        <td><strong>تەلەفۆن:</strong></td>
                                        <td><?php echo htmlspecialchars($sale['customer_phone_updated'] ?: $sale['customer_phone']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($sale['customer_address']): ?>
                                    <tr>
                                        <td><strong>ناونیشان:</strong></td>
                                        <td><?php echo htmlspecialchars($sale['customer_address']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                        <!-- Sale Items -->
                        <div class="mb-4">
                            <h5>ئایتمەکانی فرۆشراو</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>ناوی کاڵا</th>
                                            <th>بارکۆد</th>
                                            <th>دانە</th>
                                            <th>نرخی یەک دانە</th>
                                            <th>جۆری نرخ</th>
                                            <th>کۆی نرخ</th>
                                        </tr>
                                    </thead>
                                    <tbody> 
                                        <?php foreach ($items as $index => $item): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['product_name_updated'] ?: $item['product_name']); ?></strong>
                                                    <?php if (!empty($item['unit_name'])): ?>
                                                        <br><small class="text-info"><i class="bi bi-box"></i> <?php echo htmlspecialchars($item['unit_name']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($item['current_stock'] !== null): ?>
                                                        <br><small class="text-muted">کۆگا ئێستا: <?php echo number_format($item['current_stock']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($item['barcode']): ?>
                                                        <code><?php echo htmlspecialchars($item['barcode']); ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-primary">
                                                        <?php echo number_format($item['quantity']); ?>
                                                        <?php if (!empty($item['unit_symbol'])): ?>
                                                            <?php echo htmlspecialchars($item['unit_symbol']); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatCurrencyAmount($item['unit_price'], $item['currency'] ?? $sale['currency'] ?? 'IQD'); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $item['price_type'] === 'retail' ? 'success' : ($item['price_type'] === 'wholesale' ? 'warning' : 'info'); ?>">
                                                        <?php 
                                                        echo $item['price_type'] === 'retail' ? 'بچووک فرۆشی' : 
                                                             ($item['price_type'] === 'wholesale' ? 'گەورە فرۆشی' : 'تایبەت'); 
                                                        ?>
                                                    </span>
                                                </td>
                                                <td class="amount-highlight"><?php echo formatCurrencyAmount($item['total_price'], $item['currency'] ?? $sale['currency'] ?? 'IQD'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-secondary">
                                        <tr>
                                            <td colspan="6" class="text-end"><strong>کۆی گشتی:</strong></td>
                                            <td class="amount-highlight"><?php echo formatCurrencyAmount($sale['total_amount'], $sale['currency'] ?? 'IQD'); ?></td>
                                        </tr>
                                        <?php if ($sale['discount'] > 0): ?>
                                        <tr>
                                            <td colspan="6" class="text-end"><strong>داشکاندن:</strong></td>
                                            <td class="amount-highlight text-danger">-<?php echo formatCurrencyAmount($sale['discount'], $sale['currency'] ?? 'IQD'); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr class="table-primary">
                                            <td colspan="6" class="text-end"><strong>کۆی کۆتایی:</strong></td>
                                            <td class="amount-highlight fs-5"><?php echo formatCurrencyAmount($sale['final_amount'], $sale['currency'] ?? 'IQD'); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Summary -->
                        <div class="text-center border-top pt-3">
                            <p class="text-muted mb-0">سوپاس بۆ بازرگانیکردنتان لەگەڵمان</p>
                            <small class="text-muted">چاپکراو لە: <?php echo date('Y/m/d H:i'); ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Payment Status -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-credit-card"></i>
                            دۆخی پارەدان
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if ($sale['payment_method'] === 'cash'): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i>
                                پارە بە تەواوی وەرگیراوە (کاش)
                            </div>
                        <?php elseif ($debt): ?>
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h5 class="text-primary"><?php echo formatCurrencyAmount($debt['total_debt'], $sale['currency'] ?? 'IQD'); ?></h5>
                                        <small class="text-muted">کۆی قەرز</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h5 class="text-<?php echo $debt['remaining_amount'] > 0 ? 'danger' : 'success'; ?>">
                                        <?php echo formatCurrencyAmount($debt['remaining_amount'], $sale['currency'] ?? 'IQD'); ?>
                                    </h5>
                                    <small class="text-muted">ماوە</small>
                                </div>
                            </div>
                            
                            <?php if ($debt['remaining_amount'] > 0): ?>
                                <div class="progress mt-3">
                                    <?php $percentage = (($debt['total_debt'] - $debt['remaining_amount']) / $debt['total_debt']) * 100; ?>
                                    <div class="progress-bar" role="progressbar" style="width: <?php echo $percentage; ?>%">
                                        <?php echo round($percentage, 1); ?>%
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    <?php echo formatCurrencyAmount($debt['total_debt'] - $debt['remaining_amount'], $sale['currency'] ?? 'IQD'); ?> پارەی وەرگیراو
                                </small>
                            <?php else: ?>
                                <div class="alert alert-success mt-3">
                                    <i class="bi bi-check-circle"></i>
                                    قەرز بە تەواوی دراوەتەوە
                                </div>
                            <?php endif; ?>

                            <?php if ($debt['debt_type'] === 'installment' && $debt['monthly_amount']): ?>
                                <hr>
                                <h6>زانیاری قسط</h6>
                                <ul class="list-unstyled">
                                    <li><strong>ماوە:</strong> <?php echo $debt['installment_months']; ?> مانگ</li>
                                    <li><strong>مانگانە:</strong> <?php echo formatCurrencyAmount($debt['monthly_amount'], $sale['currency'] ?? 'IQD'); ?></li>
                                    <?php if ($debt['next_payment_date']): ?>
                                        <li><strong>پارەدانی داهاتوو:</strong> <?php echo date('d/m/Y', strtotime($debt['next_payment_date'])); ?></li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment History -->
                <?php if (!empty($payments)): ?>
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-clock-history"></i>
                            مێژووی پارەدانەکان
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($payments as $payment): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 text-success"><?php echo formatCurrencyAmount($payment['payment_amount'], $sale['currency'] ?? 'IQD'); ?></h6>
                                            <small class="text-muted"><?php echo $payment['formatted_date']; ?></small>
                                            <?php if ($payment['notes']): ?>
                                                <p class="mb-0 mt-1"><small><?php echo htmlspecialchars($payment['notes']); ?></small></p>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($payment['receipt_number']): ?>
                                            <a href="<?php echo url('user/receipts/print.php?receipt_number=' . urlencode($payment['receipt_number']) . '&print=1'); ?>" 
                                               class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="card shadow-sm mt-4 no-print">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-gear"></i>
                            کردارەکان
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button onclick="window.print()" class="btn btn-outline-primary">
                                <i class="bi bi-printer"></i> چاپکردن
                            </button>
                            
                            <a href="<?php echo url('user/pos/main.php'); ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-list"></i> گەڕانەوە بۆ لیست
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>