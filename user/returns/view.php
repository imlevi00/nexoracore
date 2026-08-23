<?php
/**
 * View Return - user/returns/view.php
 * View detailed information about a specific return
 */

require_once '../../config/config.php';
require_once '../../config/security.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی ID ی گەڕاندنەوە
$returnId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$returnId) {
    header('Location: index.php');
    exit();
}

// وەرگرتنی زانیاری گەڕاندنەوە
$returnQuery = "
    SELECT r.*, c.name as customer_name
    FROM returns r
    LEFT JOIN customers c ON r.customer_id = c.id
    WHERE r.id = ? AND r.user_id = ?
";

$stmt = $conn->prepare($returnQuery);
$stmt->bind_param('ii', $returnId, $userId);
$stmt->execute();
$return = $stmt->get_result()->fetch_assoc();

if (!$return) {
    header('Location: index.php');
    exit();
}

// وەرگرتنی کاڵاکانی گەڕاندنەوە
$itemsQuery = "
    SELECT ri.*
    FROM return_items ri
    WHERE ri.return_id = ?
    ORDER BY ri.id
";

$stmt = $conn->prepare($itemsQuery);
$stmt->bind_param('i', $returnId);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'زانیاری گەڕاندنەوە';
$bodyClass = 'returns-module-page returns-view-page bg-light';
$additionalCSS = ['returns-responsive.css'];

include '../../includes/header.php';
?>

<div class="container-fluid py-4 returns-page-content">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4 returns-page-header">
                <div>
                    <h2 class="h3 mb-0">زانیاری گەڕاندنەوە</h2>
                    <p class="text-muted mb-0">ژمارەی گەڕاندنەوە: <?php echo htmlspecialchars($return['return_number']); ?></p>
                </div>
                <div class="returns-page-header-actions">
                    <a href="print.php?id=<?php echo $return['id']; ?>" class="btn btn-success" target="_blank">
                        <i class="bi bi-printer me-2"></i>چاپکردن
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right me-2"></i>گەڕانەوە
                    </a>
                </div>
            </div>

            <div class="row">
                <!-- زانیاری سەرەکی -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle me-2"></i>زانیاری گەڕاندنەوە
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td class="fw-bold">ژمارەی گەڕاندنەوە:</td>
                                            <td><?php echo htmlspecialchars($return['return_number']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">کڕیار:</td>
                                            <td><?php echo htmlspecialchars($return['customer_name'] ?: 'کڕیاری گشتی'); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">بەروار:</td>
                                            <td><?php echo date('Y/m/d H:i', strtotime($return['return_date'])); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">ڕێگەی پارەدان:</td>
                                            <td>
                                                <?php
                                                $paymentMethods = [
                                                    'cash' => 'نەقد',
                                                    'debt' => 'قەرز',
                                                    'installment' => 'قسە'
                                                ];
                                                echo $paymentMethods[$return['payment_method']] ?? $return['payment_method'];
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td class="fw-bold">کۆی گشتی:</td>
                                            <td><?php echo number_format($return['total_amount']); ?> دینار</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">داشکاندن:</td>
                                            <td><?php echo number_format($return['discount']); ?> دینار</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">کۆی کۆتایی:</td>
                                            <td class="text-success fw-bold"><?php echo number_format($return['final_amount']); ?> دینار</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">کۆی کاڵاکان:</td>
                                            <td><?php echo count($items); ?> کاڵا</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <?php if (!empty($return['return_reason'])): ?>
                                <div class="mt-3">
                                    <h6>هۆکاری گەڕاندنەوە:</h6>
                                    <div class="alert alert-info">
                                        <?php echo nl2br(htmlspecialchars($return['return_reason'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- کاڵاکانی گەڕاندنەوە -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-box-seam me-2"></i>کاڵاکانی گەڕاندنەوە
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover returns-items-table">
                                    <thead>
                                        <tr>
                                            <th>کاڵا</th>
                                            <th>بڕ</th>
                                            <th>نرخ</th>
                                            <th>کۆی</th>
                                            <th>جۆری نرخ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): ?>
                                            <tr>
                                                <td data-label="کاڵا">
                                                    <div>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                        <?php if ($item['unit_name']): ?>
                                                            <small class="text-muted"><?php echo htmlspecialchars($item['unit_name']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td data-label="بڕ">
                                                    <span class="badge bg-primary">
                                                        <?php echo number_format($item['quantity']); ?>
                                                        <?php echo htmlspecialchars($item['unit_symbol'] ?: ''); ?>
                                                    </span>
                                                </td>
                                                <td data-label="نرخ"><?php echo number_format($item['unit_price']); ?> دینار</td>
                                                <td data-label="کۆی" class="fw-bold"><?php echo number_format($item['total_price']); ?> دینار</td>
                                                <td data-label="جۆری نرخ">
                                                    <?php
                                                    $priceTypes = [
                                                        'retail' => 'خرده فروشی',
                                                        'wholesale' => 'کۆڵە',
                                                        'special' => 'تایبەت'
                                                    ];
                                                    echo $priceTypes[$item['price_type']] ?? $item['price_type'];
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-light">
                                            <th colspan="3">کۆی گشتی:</th>
                                            <th class="text-success"><?php echo number_format($return['total_amount']); ?> دینار</th>
                                            <th></th>
                                        </tr>
                                        <?php if ($return['discount'] > 0): ?>
                                            <tr class="table-light">
                                                <th colspan="3">داشکاندن:</th>
                                                <th class="text-danger">-<?php echo number_format($return['discount']); ?> دینار</th>
                                                <th></th>
                                            </tr>
                                        <?php endif; ?>
                                        <tr class="table-success">
                                            <th colspan="3">کۆی کۆتایی:</th>
                                            <th class="text-success"><?php echo number_format($return['final_amount']); ?> دینار</th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- بەشی لایەنی -->
                <div class="col-md-4">
                    <!-- ئامارەکان -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-graph-up me-2"></i>ئامارەکان
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3">
                                        <h4 class="text-primary mb-1"><?php echo count($items); ?></h4>
                                        <small class="text-muted">کۆی کاڵاکان</small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3">
                                        <h4 class="text-success mb-1"><?php echo number_format($return['final_amount']); ?></h4>
                                        <small class="text-muted">کۆی بڕ (دینار)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- کردارەکان -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-gear me-2"></i>کردارەکان
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="print.php?id=<?php echo $return['id']; ?>" 
                                   class="btn btn-success" target="_blank">
                                    <i class="bi bi-printer me-2"></i>چاپکردنی پسوولە
                                </a>
                              
                                <button class="btn btn-outline-danger" 
                                        onclick="confirmDelete(<?php echo $return['id']; ?>)">
                                    <i class="bi bi-trash me-2"></i>سڕینەوە
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- زانیاری زیاتر -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle me-2"></i>زانیاری زیاتر
                            </h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-bold">دروستکراوە:</td>
                                    <td><?php echo date('Y/m/d H:i', strtotime($return['created_at'])); ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">نوێکراوە:</td>
                                    <td><?php echo date('Y/m/d H:i', strtotime($return['return_date'])); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(returnId) {
    if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم گەڕاندنەوەیە؟')) {
        // لێرە دەتوانیت کۆدەکانی سڕینەوە زیاد بکەیت
        alert('کرداری سڕینەوە لە داهاتوودا زیاد دەکرێت');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
