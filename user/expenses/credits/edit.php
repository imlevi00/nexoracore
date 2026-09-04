<?php
/**
 * دەستکاریکردنی قەرزی خەرجی - user/expenses/credits/edit.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/theme_bootstrap.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expense_credits.edit', [
    'route' => '/user/expenses/credits/edit.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

if (function_exists('ensureExpensesSchemaTables')) {
    ensureExpensesSchemaTables($conn);
}

$credit_id = intval($_GET['id'] ?? 0);
$message = null;

if ($credit_id <= 0) {
    redirect(url('user/expenses/credits/index.php?error=' . urlencode('IDی قەرز نادروستە!')));
}

// وەرگرتنی زانیاری قەرز
$credit_query = $conn->prepare("
    SELECT ec.*, e.expense_name, e.amount as expense_amount 
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
$crCur = strtoupper($credit['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';

// پرۆسێسکردنی فۆرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = ['type' => 'danger', 'text' => 'تێکچوونی ئامنیەت! دووبارە تاقی بکەرەوە.'];
    } else {
        $creditor_name = trim($_POST['creditor_name'] ?? '');
        $creditor_phone = trim($_POST['creditor_phone'] ?? '');
        $total_amount = floatval($_POST['total_amount'] ?? 0);
        $due_date = $_POST['due_date'] ?? null;
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // تاقیکردنی داتاکان
        if (empty($creditor_name)) {
            $message = ['type' => 'danger', 'text' => 'ناوی قەرزکار پێویستە!'];
        } elseif ($total_amount <= 0) {
            $message = ['type' => 'danger', 'text' => 'بڕی قەرز دەبێت لە سفرەوە زیاتر بێت!'];
        } elseif ($total_amount < $credit['paid_amount']) {
            $message = ['type' => 'danger', 'text' => 'بڕی قەرز ناتوانێت لە بڕی پارەدراوە کەمتر بێت!'];
        } elseif (!in_array($status, ['active', 'completed', 'overdue'])) {
            $message = ['type' => 'danger', 'text' => 'بارودۆخی قەرز هەڵە است!'];
        } else {
            $conn->begin_transaction();
            
            try {
                // حیسابکردنی بڕی ماوە
                $new_remaining_amount = $total_amount - $credit['paid_amount'];
                
                // ئەگەر بڕی ماوە سفر بوو، status بکە completed
                if ($new_remaining_amount <= 0) {
                    $status = 'completed';
                    $new_remaining_amount = 0;
                }
                
                // نوێکردنەوەی قەرز
                $update_credit = $conn->prepare("
                    UPDATE expense_credits 
                    SET creditor_name = ?, creditor_phone = ?, total_amount = ?, 
                        remaining_amount = ?, due_date = ?, payment_terms = ?, 
                        notes = ?, status = ?, updated_at = NOW()
                    WHERE id = ? AND user_id = ?
                ");
                
                $due_date_value = !empty($due_date) ? $due_date : null;
                
                $update_credit->bind_param(
                    "ssddssssii", 
                    $creditor_name, $creditor_phone, $total_amount,
                    $new_remaining_amount, $due_date_value, $payment_terms,
                    $notes, $status, $credit_id, $userId
                );
                
                if (!$update_credit->execute()) {
                    throw new Exception('نەتوانرا قەرز نوێ بکرێتەوە!');
                }
                
                $conn->commit();
                $message = ['type' => 'success', 'text' => 'قەرز بە سەرکەوتووی نوێکرایەوە!'];
                
                // نوێکردنەوەی داتای قەرز لە memory
                $credit['creditor_name'] = $creditor_name;
                $credit['creditor_phone'] = $creditor_phone;
                $credit['total_amount'] = $total_amount;
                $credit['remaining_amount'] = $new_remaining_amount;
                $credit['due_date'] = $due_date_value;
                $credit['payment_terms'] = $payment_terms;
                $credit['notes'] = $notes;
                $credit['status'] = $status;
                
            } catch (Exception $e) {
                $conn->rollback();
                $message = ['type' => 'danger', 'text' => $e->getMessage()];
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>دەستکاریکردنی قەرز - <?php echo SITE_NAME; ?></title>
    
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
        <div class="row justify-content-center">
            <div class="col-xl-8 col-lg-10">
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-pencil-square text-primary"></i>
                        دەستکاریکردنی قەرز
                    </h1>
                    <div>
                        <a href="details.php?id=<?php echo $credit_id; ?>" class="btn btn-outline-info">
                            <i class="bi bi-eye"></i> وردەکاری
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                        </a>
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

                <!-- Current Info Display -->
                <div class="alert alert-info mb-4">
                    <h6><i class="bi bi-info-circle"></i> زانیاری ئێستا:</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="mb-0">
                                <li><strong>خەرجی:</strong> <?php echo htmlspecialchars($credit['expense_name']); ?></li>
                                <li><strong>قەرزکار:</strong> <?php echo htmlspecialchars($credit['creditor_name']); ?></li>
                                <li><strong>بڕی گشتی:</strong> <?php echo formatCurrencyAmount($credit['total_amount'], $crCur); ?></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="mb-0">
                                <li><strong>دراوەتەوە:</strong> <?php echo formatCurrencyAmount($credit['paid_amount'], $crCur); ?></li>
                                <li><strong>ماوەتەوە:</strong> <?php echo formatCurrencyAmount($credit['remaining_amount'], $crCur); ?></li>
                                <li><strong>بارودۆخ:</strong> 
                                    <?php if ($credit['status'] === 'active'): ?>
                                        <span class="badge bg-warning">چالاک</span>
                                    <?php elseif ($credit['status'] === 'completed'): ?>
                                        <span class="badge bg-success">تەواوبوو</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">درەنگکراو</span>
                                    <?php endif; ?>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Edit Form -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-form"></i> دەستکاریکردنی زانیاری قەرز
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="row g-3">
                                <!-- Creditor Name -->
                                <div class="col-md-6">
                                    <label class="form-label" for="creditor_name">ناوی قەرزکار *</label>
                                    <input type="text" class="form-control" id="creditor_name" name="creditor_name" 
                                           value="<?php echo htmlspecialchars($credit['creditor_name']); ?>" 
                                           placeholder="ناوی کەسی قەرزکار..." required>
                                </div>
                                
                                <!-- Creditor Phone -->
                                <div class="col-md-6">
                                    <label class="form-label" for="creditor_phone">ژمارەی تەلەفۆن</label>
                                    <input type="text" class="form-control" id="creditor_phone" name="creditor_phone" 
                                           value="<?php echo htmlspecialchars($credit['creditor_phone']); ?>" 
                                           placeholder="ژمارەی تەلەفۆنی قەرزکار...">
                                </div>
                                
                                <!-- Total Amount -->
                                <div class="col-md-6">
                                    <label class="form-label" for="total_amount">بڕی گشتی قەرز *</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="total_amount" name="total_amount"
                                               value="<?php echo $credit['total_amount']; ?>"
                                               min="<?php echo $credit['paid_amount']; ?>" step="0.001" required>
                                        <span class="input-group-text"><?php echo $crCur === 'USD' ? 'دۆلار $' : 'دینار'; ?></span>
                                    </div>
                                    <small class="text-muted">
                                        کەمترین بڕ: <?php echo formatCurrencyAmount($credit['paid_amount'], $crCur); ?> (بڕی پارەدراوە)
                                    </small>
                                </div>
                                
                                <!-- Status -->
                                <div class="col-md-6">
                                    <label class="form-label" for="status">بارودۆخی قەرز</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" <?php echo $credit['status'] === 'active' ? 'selected' : ''; ?>>چالاک</option>
                                        <option value="completed" <?php echo $credit['status'] === 'completed' ? 'selected' : ''; ?>>تەواوبوو</option>
                                        <option value="overdue" <?php echo $credit['status'] === 'overdue' ? 'selected' : ''; ?>>درەنگکراو</option>
                                    </select>
                                </div>
                                
                                <!-- Due Date -->
                                <div class="col-md-6">
                                    <label class="form-label" for="due_date">وادەی دانەوە</label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" 
                                           value="<?php echo $credit['due_date']; ?>">
                                </div>
                                
                                <!-- Payment Terms -->
                                <div class="col-md-6">
                                    <label class="form-label" for="payment_terms">مەرجەکانی پارەدانەوە</label>
                                    <input type="text" class="form-control" id="payment_terms" name="payment_terms" 
                                           value="<?php echo htmlspecialchars($credit['payment_terms']); ?>" 
                                           placeholder="مەرجەکانی پارەدانەوە...">
                                </div>
                                
                                <!-- Notes -->
                                <div class="col-12">
                                    <label class="form-label" for="notes">تێبینی دەربارەی قەرز</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" 
                                              placeholder="تێبینی یان وردەکاری دەربارەی قەرزەکە..."><?php echo htmlspecialchars($credit['notes']); ?></textarea>
                                </div>
                            </div>

                            <!-- Warning for Amount Change -->
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>ئاگاداری:</strong> 
                                ئەگەر بڕی گشتی قەرز بگۆڕیت، بڕی ماوە دووبارە حیساب دەکرێتەوە. 
                                بڕی گشتی ناتوانێت لە بڕی پارەدراوە (<?php echo formatCurrencyAmount($credit['paid_amount'], $crCur); ?>) کەمتر بێت.
                            </div>

                            <!-- Submit Buttons -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <a href="details.php?id=<?php echo $credit_id; ?>" class="btn btn-secondary">
                                            <i class="bi bi-x-lg"></i> پاشگەزبوونەوە
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-lg"></i> نوێکردنەوەی قەرز
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Calculate remaining amount when total amount changes
        document.getElementById('total_amount').addEventListener('input', function() {
            const totalAmount = parseFloat(this.value) || 0;
            const paidAmount = <?php echo $credit['paid_amount']; ?>;
            const remaining = totalAmount - paidAmount;
            
            if (totalAmount < paidAmount) {
                this.value = paidAmount;
                alert('بڕی گشتی ناتوانێت لە بڕی پارەدراوە کەمتر بێت!');
            }
            
            // Auto-set status to completed if remaining is 0 or less
            if (remaining <= 0 && totalAmount >= paidAmount) {
                document.getElementById('status').value = 'completed';
            }
        });

        // Set due date validation
        document.getElementById('due_date').addEventListener('change', function() {
            const selectedDate = new Date(this.value);
            const today = new Date();
            const status = document.getElementById('status');
            
            if (selectedDate < today && status.value === 'active') {
                if (confirm('وادەی دانەوە تێپەڕیوە. ئەتەوێت بارودۆخ بکەیت بە "درەنگکراو"؟')) {
                    status.value = 'overdue';
                }
            }
        });
    </script>
</body>
</html>