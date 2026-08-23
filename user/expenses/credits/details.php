<?php
/**
 * وردەکاری قەرزی خەرجی - user/expenses/credits/details.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expense_credits.view', [
    'route' => '/user/expenses/credits/details.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

$credit_id = intval($_GET['id'] ?? 0);
$message = null;

if ($credit_id <= 0) {
    redirect(url('user/expenses/credits/index.php?error=' . urlencode('IDی قەرز نادروستە!')));
}

// وەرگرتنی زانیاری قەرز
$credit_query = $conn->prepare("
    SELECT ec.*, e.expense_name, e.amount as expense_amount, e.expense_date
    FROM expense_credits ec
    LEFT JOIN expenses e ON ec.expense_id = e.id
    WHERE ec.id = ? AND ec.user_id = ?
");
$credit_query->bind_param("ii", $credit_id, $userId);
$credit_query->execute();
$credit_result = $credit_query->get_result();

if ($credit_result->num_rows === 0) {
    redirect(url('user/expenses/credits/index.php?error=' . urlencode('قەرز نەدۆزرایەوە!')));
}

$credit = $credit_result->fetch_assoc();
$credit_query->close();
$crCur = strtoupper($credit['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';

// وەرگرتنی مێژووی پارەدانەوەکان
$payments = [];
$payments_query = $conn->prepare("
    SELECT * FROM expense_credit_payments 
    WHERE expense_credit_id = ? AND user_id = ?
    ORDER BY payment_date DESC
");
if ($payments_query) {
    $payments_query->bind_param("ii", $credit_id, $userId);
    $payments_query->execute();
    $payments = $payments_query->get_result()->fetch_all(MYSQLI_ASSOC);
    $payments_query->close();
}

// ئاماری پارەدانەوەکان
$payment_stats = [
    'total_payments' => count($payments),
    'total_paid' => $credit['paid_amount'],
    'average_payment' => count($payments) > 0 ? $credit['paid_amount'] / count($payments) : 0
];

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
    <title>وردەکاری قەرز - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="expenses-module-page expenses-credits-page bg-light">

    <!-- Navigation -->
    <?php include_once '../../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container py-4">
        
        <!-- Page Header -->
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-file-text text-info"></i>
                        وردەکاری قەرز
                    </h1>
                    <div>
                        <?php if ($credit['remaining_amount'] > 0): ?>
                            <a href="payment.php?id=<?php echo $credit_id; ?>" class="btn btn-success">
                                <i class="bi bi-cash"></i> پارەدانەوە
                            </a>
                        <?php endif; ?>
                        <a href="edit.php?id=<?php echo $credit_id; ?>" class="btn btn-outline-primary">
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
            <!-- Credit Information -->
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
                                <h6><i class="bi bi-shop text-primary"></i> خەرجی پەیوەست:</h6>
                                <p class="text-muted mb-3"><?php echo htmlspecialchars($credit['expense_name']); ?></p>
                                
                                <h6><i class="bi bi-cash-stack text-success"></i> بڕی خەرجی:</h6>
                                <p class="text-muted mb-3"><?php echo formatCurrencyAmount($credit['expense_amount'], $crCur); ?></p>
                                
                                <h6><i class="bi bi-person text-info"></i> قەرزکار:</h6>
                                <p class="text-muted mb-1"><?php echo htmlspecialchars($credit['creditor_name']); ?></p>
                                <?php if (!empty($credit['creditor_phone'])): ?>
                                    <p class="text-muted small"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($credit['creditor_phone']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="bi bi-calendar-event text-warning"></i> بەرواری خەرجی:</h6>
                                <p class="text-muted mb-3"><?php echo date('Y/m/d', strtotime($credit['expense_date'])); ?></p>
                                
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
                                <p class="text-muted"><?php echo htmlspecialchars($credit['notes']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history"></i> مێژووی پارەدانەوەکان
                            <?php if (count($payments) > 0): ?>
                                <span class="badge bg-primary ms-2"><?php echo count($payments); ?></span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-inbox display-4 text-muted"></i>
                                <h6 class="text-muted mt-2">هیچ پارەدانەوەیەک نەکراوە</h6>
                                <?php if ($credit['remaining_amount'] > 0): ?>
                                    <a href="payment.php?id=<?php echo $credit_id; ?>" class="btn btn-success mt-2">
                                        <i class="bi bi-cash"></i> یەکەمین پارەدانەوە
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 expenses-credits-table">
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
                                                <strong class="text-success"><?php echo formatCurrencyAmount($payment['payment_amount'], $payment['currency'] ?? $crCur); ?></strong>
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
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Summary Sidebar -->
            <div class="col-lg-4">
                <!-- Financial Summary -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">
                            <i class="bi bi-calculator"></i> کورتەی دارایی
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <h6 class="text-muted">بڕی گشتی:</h6>
                            <h4 class="text-primary"><?php echo formatCurrencyAmount($credit['total_amount'], $crCur); ?></h4>
                        </div>

                        <div class="mb-3">
                            <h6 class="text-muted">دراوەتەوە:</h6>
                            <h4 class="text-success"><?php echo formatCurrencyAmount($credit['paid_amount'], $crCur); ?></h4>
                            <?php if ($credit['total_amount'] > 0): ?>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: <?php echo ($credit['paid_amount'] / $credit['total_amount']) * 100; ?>%"></div>
                                </div>
                                <small class="text-muted">
                                    <?php echo number_format(($credit['paid_amount'] / $credit['total_amount']) * 100, 1); ?>% تەواوبوو
                                </small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted">ماوەتەوە:</h6>
                            <h4 class="text-danger"><?php echo formatCurrencyAmount($credit['remaining_amount'], $crCur); ?></h4>
                        </div>

                        <?php if ($payment_stats['total_payments'] > 0): ?>
                            <div class="border-top pt-3">
                                <h6 class="text-muted">ناوەندی پارەدانەوە:</h6>
                                <p class="h6"><?php echo formatCurrencyAmount($payment_stats['average_payment'], $crCur); ?></p>
                                
                                <h6 class="text-muted">ژمارەی پارەدانەوەکان:</h6>
                                <p class="h6"><?php echo $payment_stats['total_payments']; ?> جار</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-lightning"></i> کردارە خێراکان
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($credit['remaining_amount'] > 0): ?>
                                <a href="payment.php?id=<?php echo $credit_id; ?>" class="btn btn-success">
                                    <i class="bi bi-cash"></i> پارەدانەوە
                                </a>
                            <?php endif; ?>
                            
                            <a href="edit.php?id=<?php echo $credit_id; ?>" class="btn btn-outline-primary">
                                <i class="bi bi-pencil"></i> دەستکاریکردن
                            </a>
                            
                            <a href="../details.php?id=<?php echo $credit['expense_id']; ?>" class="btn btn-outline-info">
                                <i class="bi bi-eye"></i> بینینی خەرجی
                            </a>
                            
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                <i class="bi bi-printer"></i> چاپکردن
                            </button>
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