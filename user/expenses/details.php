<?php
/**
 * وردەکاری خەرجی - user/expenses/details.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expenses.view', [
    'route' => '/user/expenses/details.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

if (function_exists('ensureExpensesSchemaTables')) {
    ensureExpensesSchemaTables($conn);
}

$expense_id = intval($_GET['id'] ?? 0);
$message = null;

if ($expense_id <= 0) {
    redirect(url('user/expenses/index.php?error=' . urlencode('IDی خەرجی نادروستە!')));
}

// وەرگرتنی زانیاری خەرجی
$expense_query = $conn->prepare("
    SELECT e.*, et.name as expense_type_name
    FROM expenses e
    LEFT JOIN expense_types et ON e.expense_type_id = et.id
    WHERE e.id = ? AND e.user_id = ?
");
$expense_query->bind_param("ii", $expense_id, $userId);
$expense_query->execute();
$expense_result = $expense_query->get_result();

if ($expense_result->num_rows === 0) {
    redirect(url('user/expenses/index.php?error=' . urlencode('خەرجی نەدۆزرایەوە!')));
}

$expense = $expense_result->fetch_assoc();
$expense_query->close();

// وەرگرتنی زانیاری قەرز (ئەگەر هەبێت)
$credit = null;
$payments = [];
if (!empty($expense['has_credit']) && $expense['payment_method'] === 'credit') {
    $credit_query = $conn->prepare("
        SELECT ec.*,
               (SELECT COUNT(*) FROM expense_credit_payments WHERE expense_credit_id = ec.id) as payment_count
        FROM expense_credits ec
        WHERE ec.expense_id = ? AND ec.user_id = ?
    ");
    $credit_query->bind_param("ii", $expense_id, $userId);
    $credit_query->execute();
    $credit_result = $credit_query->get_result();
    
    if ($credit_result->num_rows > 0) {
        $credit = $credit_result->fetch_assoc();
        $creditId = (int)$credit['id'];
        
        // وەرگرتنی مێژووی پارەدانەوەکان
        $p_stmt = $conn->prepare("
            SELECT * FROM expense_credit_payments 
            WHERE expense_credit_id = ? AND user_id = ?
            ORDER BY payment_date DESC
        ");
        if ($p_stmt) {
            $p_stmt->bind_param("ii", $creditId, $userId);
            $p_stmt->execute();
            $payments = $p_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $p_stmt->close();
        }
    }
    $credit_query->close();
}

// پەیامی سەرکەوتن یان هەڵە
if (isset($_GET['success'])) {
    $message = ['type' => 'success', 'text' => $_GET['success']];
} elseif (isset($_GET['error'])) {
    $message = ['type' => 'danger', 'text' => $_GET['error']];
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>وردەکاری خەرجی - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="expenses-module-page bg-light">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container py-4">
        
        <!-- Page Header -->
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-file-text text-info"></i>
                        وردەکاری خەرجی
                    </h1>
                    <div>
                        <?php if ($credit && $credit['remaining_amount'] > 0): ?>
                            <a href="credits/payment.php?id=<?php echo $credit['id']; ?>" class="btn btn-success">
                                <i class="bi bi-cash"></i> پارەدانەوە
                            </a>
                        <?php endif; ?>
                        <a href="edit.php?id=<?php echo $expense_id; ?>" class="btn btn-outline-primary">
                            <i class="bi bi-pencil"></i> دەستکاریکردن
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
                <?php echo $message['text']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Main Information -->
            <div class="col-lg-8">
                <!-- Basic Info -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-info-circle"></i> زانیاری گشتی
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="bi bi-tag text-primary"></i> ناوی خەرجی:</h6>
                                <p class="text-muted mb-3"><?php echo htmlspecialchars($expense['expense_name']); ?></p>
                                
                                <h6><i class="bi bi-cash-stack text-success"></i> بڕی پارە:</h6>
                                <p class="h4 text-danger mb-3"><?php echo formatCurrencyAmount($expense['amount'], $expense['currency'] ?? 'IQD'); ?></p>
                                
                                <h6><i class="bi bi-credit-card text-info"></i> شێوازی پارەدان:</h6>
                                <p class="mb-3">
                                    <?php if ($expense['payment_method'] === 'cash'): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-cash"></i> نەقد
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">
                                            <i class="bi bi-credit-card"></i> قەرز
                                        </span>
                                    <?php endif; ?>
                                </p>
                                
                                <?php if (!empty($expense['receipt_number'])): ?>
                                    <h6><i class="bi bi-receipt text-secondary"></i> ژمارەی پسوڵە:</h6>
                                    <p class="text-muted mb-3"><?php echo htmlspecialchars($expense['receipt_number']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="bi bi-calendar-event text-warning"></i> بەرواری خەرجی:</h6>
                                <p class="text-muted mb-3">
                                    <?php echo date('Y/m/d', strtotime($expense['expense_date'])); ?>
                                    <br><small><?php echo date('H:i', strtotime($expense['expense_date'])); ?></small>
                                </p>
                                
                                <h6><i class="bi bi-flag text-secondary"></i> جۆری خەرجی:</h6>
                                <p class="mb-3">
                                    <?php if ($expense['is_recurring']): ?>
                                        <span class="badge bg-primary">
                                            <i class="bi bi-arrow-repeat"></i> دووبارە
                                        </span>
                                        <?php if (!empty($expense['expense_type_name'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($expense['expense_type_name']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-check"></i> یەک جار
                                        </span>
                                    <?php endif; ?>
                                </p>
                                
                                <h6><i class="bi bi-clock text-muted"></i> تۆمارکراوە لە:</h6>
                                <p class="text-muted mb-3">
                                    <?php echo date('Y/m/d H:i', strtotime($expense['created_at'])); ?>
                                </p>
                            </div>
                        </div>
                        
                        <?php if (!empty($expense['description'])): ?>
                            <div class="mt-3">
                                <h6><i class="bi bi-sticky text-secondary"></i> تێبینی:</h6>
                                <p class="text-muted"><?php echo nl2br(htmlspecialchars($expense['description'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Credit Information (if exists) -->
                <?php if ($credit): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="bi bi-credit-card"></i> زانیاری قەرز
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="bi bi-person text-info"></i> قەرزکار:</h6>
                                <p class="text-muted mb-1"><?php echo htmlspecialchars($credit['creditor_name']); ?></p>
                                <?php if (!empty($credit['creditor_phone'])): ?>
                                    <p class="text-muted small"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($credit['creditor_phone']); ?></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($credit['due_date'])): ?>
                                    <h6><i class="bi bi-calendar-check text-danger"></i> وادەی دانەوە:</h6>
                                    <p class="text-muted mb-3">
                                        <?php echo date('Y/m/d', strtotime($credit['due_date'])); ?>
                                        <?php
                                        $due_date = new DateTime($credit['due_date']);
                                        $today = new DateTime();
                                        $diff = $today->diff($due_date);
                                        
                                        if ($credit['status'] === 'overdue') {
                                            echo '<br><small class="text-danger">درەنگ بە ' . $diff->days . ' ڕۆژ</small>';
                                        } elseif ($due_date > $today) {
                                            echo '<br><small class="text-info">' . $diff->days . ' ڕۆژ ماوە</small>';
                                        }
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <?php $crCur = strtoupper($credit['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD'; ?>
                                <h6><i class="bi bi-cash-stack text-primary"></i> بڕی گشتی:</h6>
                                <p class="h5 text-primary mb-2"><?php echo formatCurrencyAmount($credit['total_amount'], $crCur); ?></p>

                                <h6><i class="bi bi-check-circle text-success"></i> دراوەتەوە:</h6>
                                <p class="h6 text-success mb-2"><?php echo formatCurrencyAmount($credit['paid_amount'], $crCur); ?></p>

                                <h6><i class="bi bi-exclamation-circle text-danger"></i> ماوەتەوە:</h6>
                                <p class="h5 text-danger mb-2"><?php echo formatCurrencyAmount($credit['remaining_amount'], $crCur); ?></p>
                                
                                <h6><i class="bi bi-flag text-secondary"></i> بارودۆخ:</h6>
                                <p>
                                    <?php if ($credit['status'] === 'active'): ?>
                                        <span class="badge bg-warning">چالاک</span>
                                    <?php elseif ($credit['status'] === 'completed'): ?>
                                        <span class="badge bg-success">تەواوبوو</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">درەنگکراو</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <?php if (!empty($credit['payment_terms'])): ?>
                            <div class="mt-3">
                                <h6><i class="bi bi-file-text text-secondary"></i> مەرجەکانی پارەدانەوە:</h6>
                                <p class="text-muted"><?php echo htmlspecialchars($credit['payment_terms']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($credit['notes'])): ?>
                            <div class="mt-3">
                                <h6><i class="bi bi-sticky text-secondary"></i> تێبینیەکان:</h6>
                                <p class="text-muted"><?php echo nl2br(htmlspecialchars($credit['notes'])); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Progress Bar -->
                        <?php if ($credit['total_amount'] > 0): ?>
                        <div class="mt-3">
                            <h6><i class="bi bi-graph-up text-info"></i> پێشکەوتنی پارەدانەوە:</h6>
                            <div class="progress" style="height: 12px;">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?php echo ($credit['paid_amount'] / $credit['total_amount']) * 100; ?>%">
                                    <?php echo number_format(($credit['paid_amount'] / $credit['total_amount']) * 100, 1); ?>%
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment History -->
                <?php if (isset($payments) && !empty($payments)): ?>
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history"></i> مێژووی پارەدانەوەکان
                            <span class="badge bg-primary ms-2"><?php echo count($payments); ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>بڕی پارەدانەوە</th>
                                        <th>شێوازی پارەدان</th>
                                        <th>بەرواری پارەدانەوە</th>
                                        <th>ژمارەی پسوڵە</th>
                                        <th>تێبینی</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-success"><?php echo formatCurrencyAmount($payment['payment_amount'], $payment['currency'] ?? ($crCur ?? 'IQD')); ?></strong>
                                        </td>
                                        <td>
                                            <?php
                                            $methods = [
                                                'cash' => ['نەقد', 'success'],
                                                'bank_transfer' => ['بانکی', 'primary'],
                                                'check' => ['چێک', 'warning'],
                                                'other' => ['تر', 'secondary']
                                            ];
                                            $method = $methods[$payment['payment_method']] ?? ['نەناسراو', 'secondary'];
                                            ?>
                                            <span class="badge bg-<?php echo $method[1]; ?>"><?php echo $method[0]; ?></span>
                                        </td>
                                        <td>
                                            <small><?php echo date('Y/m/d', strtotime($payment['payment_date'])); ?></small>
                                            <br><small class="text-muted"><?php echo date('H:i', strtotime($payment['payment_date'])); ?></small>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($payment['receipt_number'] ?: '-'); ?></small>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($payment['notes'] ? (strlen($payment['notes']) > 30 ? substr($payment['notes'], 0, 30) . '...' : $payment['notes']) : '-'); ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Quick Actions -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-lightning"></i> کردارە خێراکان
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="edit.php?id=<?php echo $expense_id; ?>" class="btn btn-outline-primary">
                                <i class="bi bi-pencil"></i> دەستکاریکردن
                            </a>
                            
                            <?php if ($credit): ?>
                                <a href="credits/details.php?id=<?php echo $credit['id']; ?>" class="btn btn-outline-info">
                                    <i class="bi bi-credit-card"></i> وردەکاری قەرز
                                </a>
                                
                                <?php if ($credit['remaining_amount'] > 0): ?>
                                    <a href="credits/payment.php?id=<?php echo $credit['id']; ?>" class="btn btn-success">
                                        <i class="bi bi-cash"></i> پارەدانەوە
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="window.open('print_receipt.php?id=<?php echo (int)$expense_id; ?>&print=1', '_blank')">
                                <i class="bi bi-printer"></i> چاپکردنی وەسڵ
                            </button>
                            
                            <button type="button" class="btn btn-outline-danger" onclick="deleteExpense()">
                                <i class="bi bi-trash"></i> سڕینەوە
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Summary Info -->
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">
                            <i class="bi bi-info-circle"></i> کورتە
                        </h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                <strong>جۆری خەرجی:</strong> 
                                <?php echo $expense['is_recurring'] ? 'دووبارە' : 'یەک جار'; ?>
                            </li>
                            <li class="mb-2">
                                <strong>شێوازی پارەدان:</strong> 
                                <?php echo $expense['payment_method'] === 'cash' ? 'نەقد' : 'قەرز'; ?>
                            </li>
                            <?php if ($credit): ?>
                                <li class="mb-2">
                                    <strong>بارودۆخی قەرز:</strong> 
                                    <?php
                                    if ($credit['status'] === 'active') echo 'چالاک';
                                    elseif ($credit['status'] === 'completed') echo 'تەواوبوو';
                                    else echo 'درەنگکراو';
                                    ?>
                                </li>
                                <li class="mb-2">
                                    <strong>ژمارەی پارەدانەوەکان:</strong> 
                                    <?php echo $credit['payment_count']; ?>
                                </li>
                            <?php endif; ?>
                            <li class="mb-0">
                                <strong>تۆمارکراوە لە:</strong> 
                                <?php echo date('Y/m/d', strtotime($expense['created_at'])); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">سڕینەوەی خەرجی</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>دڵنیایت لە سڕینەوەی ئەم خەرجیە؟ ئەم کردارە ناگەڕێنرێتەوە.</p>
                    <?php if ($credit): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>ئاگاداری:</strong> ئەم خەرجیە قەرزی لەگەڵدایە. ئەگەر بیسڕیتەوە، قەرزەکەش دەسڕێتەوە!
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">سڕینەوە</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteExpense() {
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }
        
        function confirmDelete() {
            window.location.href = 'delete.php?id=<?php echo $expense_id; ?>';
        }
    </script>
</body>
</html>