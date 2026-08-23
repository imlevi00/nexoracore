<?php
/**
 * پارەدانەوەی قەرزی خەرجی - user/expenses/credits/payment.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/theme_bootstrap.php';
require_once '../../../includes/wallet_service.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expense_credits.edit', [
    'route' => '/user/expenses/credits/payment.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

$credit_id = intval($_GET['id'] ?? 0);
$message = null;
$wallets = walletGetUserWallets($conn, (int)$userId, true);
if (empty($wallets) && function_exists('walletEnsureDefaultForUser')) {
    walletEnsureDefaultForUser($conn, (int)$userId);
    $wallets = walletGetUserWallets($conn, (int)$userId, true);
}

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
$credit_query->close();
$creditCurrency = strtoupper($credit['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';

// تاقیکردنی ئەوەی قەرزەکە تەواو نەبووبێت
if ($credit['remaining_amount'] <= 0) {
    redirect(url('user/expenses/credits/details.php?id=' . $credit_id . '&error=' . urlencode('ئەم قەرزە تەواو بووە!')));
}

// پرۆسێسکردنی پارەدانەوە
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = ['type' => 'danger', 'text' => 'تێکچوونی ئامنیەت! دووبارە تاقی بکەرەوە.'];
    } else {
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $wallet_id = (int)($_POST['wallet_id'] ?? 0);
        $receipt_number = trim($_POST['receipt_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d H:i:s');
        
        if ($payment_method === 'cash' && $wallet_id <= 0) {
            $defaultWid = walletGetDefaultWalletId($conn, $userId);
            if ($defaultWid) {
                $wallet_id = (int)$defaultWid;
            }
        }
        
        // تاقیکردنی داتاکان
        if ($payment_amount <= 0) {
            $message = ['type' => 'danger', 'text' => 'بڕی پارەدانەوە دەبێت لە سفرەوە زیاتر بێت!'];
        } elseif ($payment_amount > $credit['remaining_amount']) {
            $message = ['type' => 'danger', 'text' => 'بڕی پارەدانەوە ناتوانێت لە بڕی ماوە زیاتر بێت!'];
        } elseif ($payment_method === 'cash' && $wallet_id <= 0) {
            $message = ['type' => 'danger', 'text' => 'تکایە قاسە هەڵبژێرە'];
        } elseif (!in_array($payment_method, ['cash', 'bank_transfer', 'check', 'other'], true)) {
            $message = ['type' => 'danger', 'text' => 'شێوازی پارەدان هەڵەیە!'];
        } else {
            $conn->begin_transaction();
            
            try {
                $wallet_id_val = ($payment_method === 'cash' && $wallet_id > 0) ? $wallet_id : null;
                // زیادکردنی پارەدانەوە
                $insert_payment = $conn->prepare("
                    INSERT INTO expense_credit_payments (
                        user_id, expense_credit_id, payment_amount, currency,
                        payment_method, wallet_id, payment_date, receipt_number, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $insert_payment->bind_param(
                    "iidssisss",
                    $userId, $credit_id, $payment_amount, $creditCurrency,
                    $payment_method, $wallet_id_val, $payment_date, $receipt_number, $notes
                );
                
                if (!$insert_payment->execute()) {
                    throw new Exception('نەتوانرا پارەدانەوە تۆمار بکرێت!');
                }
                
                // نوێکردنەوەی قەرز
                $new_paid_amount = $credit['paid_amount'] + $payment_amount;
                $new_remaining_amount = $credit['total_amount'] - $new_paid_amount;
                $new_status = ($new_remaining_amount <= 0) ? 'completed' : $credit['status'];
                
                $update_credit = $conn->prepare("
                    UPDATE expense_credits 
                    SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW()
                    WHERE id = ? AND user_id = ?
                ");
                
                $update_credit->bind_param(
                    "ddsii", 
                    $new_paid_amount, $new_remaining_amount, $new_status,
                    $credit_id, $userId
                );
                
                if (!$update_credit->execute()) {
                    throw new Exception('نەتوانرا قەرز نوێ بکرێتەوە!');
                }

                if ($payment_method === 'cash' && $wallet_id > 0) {
                    if (!walletPostEntry(
                        $conn,
                        (int)$userId,
                        (int)$wallet_id,
                        'expense_credit_payment_out',
                        'out',
                        $creditCurrency,
                        (float)$payment_amount,
                        'expense_credit_payment',
                        (int)$credit_id,
                        'Expense credit payment out',
                        (int)$userId
                    )) {
                        throw new Exception('نەتوانرا جوڵەی قاسەی پارەدانی قەرز تۆمار بکرێت');
                    }
                }
                
                $conn->commit();
                
                $message = ['type' => 'success', 'text' => 'پارەدانەوە بە سەرکەوتووی تۆمار کرا!'];
                
                // نوێکردنەوەی داتاکان
                $credit['paid_amount'] = $new_paid_amount;
                $credit['remaining_amount'] = $new_remaining_amount;
                $credit['status'] = $new_status;
                
            } catch (Exception $e) {
                $conn->rollback();
                $message = ['type' => 'danger', 'text' => $e->getMessage()];
            }
        }
    }
}

// وەرگرتنی مێژووی پارەدانەوەکان
$recent_payments = [];
$payments_query = $conn->prepare("
    SELECT * FROM expense_credit_payments 
    WHERE expense_credit_id = ? AND user_id = ?
    ORDER BY payment_date DESC 
    LIMIT 5
");
if ($payments_query) {
    $payments_query->bind_param("ii", $credit_id, $userId);
    $payments_query->execute();
    $recent_payments = $payments_query->get_result()->fetch_all(MYSQLI_ASSOC);
    $payments_query->close();
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>پارەدانەوەی قەرز - <?php echo SITE_NAME; ?></title>
    
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
                        <i class="bi bi-cash-coin text-success"></i>
                        پارەدانەوەی قەرز
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

                <!-- Credit Info -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-info-circle"></i> زانیاری قەرز
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="bi bi-shop"></i> خەرجی:</h6>
                                <p class="text-muted"><?php echo htmlspecialchars($credit['expense_name']); ?></p>
                                
                                <h6><i class="bi bi-person"></i> قەرزکار:</h6>
                                <p class="text-muted">
                                    <?php echo htmlspecialchars($credit['creditor_name']); ?>
                                    <?php if (!empty($credit['creditor_phone'])): ?>
                                        <br><small><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($credit['creditor_phone']); ?></small>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="bi bi-cash-stack"></i> بڕی گشتی:</h6>
                                <p class="text-primary h5"><?php echo formatCurrencyAmount($credit['total_amount'], $creditCurrency); ?></p>

                                <h6><i class="bi bi-check-circle"></i> دراوەتەوە:</h6>
                                <p class="text-success h6"><?php echo formatCurrencyAmount($credit['paid_amount'], $creditCurrency); ?></p>

                                <h6><i class="bi bi-exclamation-circle"></i> ماوەتەوە:</h6>
                                <p class="text-danger h5"><?php echo formatCurrencyAmount($credit['remaining_amount'], $creditCurrency); ?></p>
                            </div>
                        </div>
                        
                        <?php if (!empty($credit['due_date'])): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-calendar-event"></i>
                                <strong>وادەی دانەوە:</strong> <?php echo date('Y/m/d', strtotime($credit['due_date'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Form -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-plus-circle"></i> پارەدانەوەی نوێ
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="row g-3">
                                <!-- Payment Amount -->
                                <div class="col-md-6">
                                    <label class="form-label" for="payment_amount">بڕی پارەدانەوە *</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="payment_amount" name="payment_amount"
                                               min="0" max="<?php echo $credit['remaining_amount']; ?>"
                                               step="0.001" placeholder="0" required>
                                        <span class="input-group-text"><?php echo $creditCurrency === 'USD' ? 'دۆلار $' : 'دینار'; ?></span>
                                    </div>
                                    <small class="text-muted">زۆرترین بڕ: <?php echo formatCurrencyAmount($credit['remaining_amount'], $creditCurrency); ?></small>
                                </div>
                                
                                <!-- Payment Method -->
                                <div class="col-md-6">
                                    <label class="form-label">شێوازی پارەدان *</label>
                                    <select class="form-select" name="payment_method" id="payment_method" required>
                                        <option value="cash">نەقد</option>
                                        <option value="bank_transfer">گواستنەوەی بانکی</option>
                                        <option value="check">چێک</option>
                                        <option value="other">جۆری تر</option>
                                    </select>
                                </div>
                                <div class="col-md-6" id="wallet_field">
                                    <label class="form-label">قاسە *</label>
                                    <select class="form-select" name="wallet_id" id="wallet_id">
                                        <option value="">قاسە هەڵبژێرە</option>
                                        <?php foreach ($wallets as $wallet): ?>
                                            <option value="<?php echo (int)$wallet['id']; ?>">
                                                <?php echo htmlspecialchars((string)$wallet['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Receipt Number -->
                                <div class="col-md-6">
                                    <label class="form-label" for="receipt_number">ژمارەی پسوڵە</label>
                                    <input type="text" class="form-control" id="receipt_number" name="receipt_number" 
                                           placeholder="ژمارەی پسوڵە (ئیختیاری)">
                                </div>
                                
                                <!-- Payment Date -->
                                <div class="col-md-6">
                                    <label class="form-label" for="payment_date">بەرواری پارەدانەوە</label>
                                    <input type="datetime-local" class="form-control" id="payment_date" name="payment_date" 
                                           value="<?php echo date('Y-m-d\TH:i'); ?>">
                                </div>
                                
                                <!-- Notes -->
                                <div class="col-12">
                                    <label class="form-label" for="notes">تێبینی</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" 
                                              placeholder="تێبینی دەربارەی پارەدانەوەکە..."></textarea>
                                </div>
                            </div>

                            <!-- Quick Amount Buttons -->
                            <div class="mt-3">
                                <label class="form-label">بڕی خێرا:</label>
                                <div class="btn-group" role="group">
                                    <?php
                                    $remaining = $credit['remaining_amount'];
                                    $quarter = $remaining / 4;
                                    $half = $remaining / 2;
                                    $three_quarter = $remaining * 0.75;
                                    ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" 
                                            onclick="setAmount(<?php echo $quarter; ?>)">
                                        ١/٤ (<?php echo number_format($quarter, 0); ?>)
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" 
                                            onclick="setAmount(<?php echo $half; ?>)">
                                        ١/٢ (<?php echo number_format($half, 0); ?>)
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" 
                                            onclick="setAmount(<?php echo $three_quarter; ?>)">
                                        ٣/٤ (<?php echo number_format($three_quarter, 0); ?>)
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                            onclick="setAmount(<?php echo $remaining; ?>)">
                                        هەموو (<?php echo number_format($remaining, 0); ?>)
                                    </button>
                                </div>
                            </div>

                            <!-- Submit Buttons -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <a href="details.php?id=<?php echo $credit_id; ?>" class="btn btn-secondary">
                                            <i class="bi bi-x-lg"></i> پاشگەزبوونەوە
                                        </a>
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-check-lg"></i> تۆمارکردنی پارەدانەوە
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Recent Payments -->
                <?php if (!empty($recent_payments)): ?>
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-clock-history"></i> دوایین پارەدانەوەکان
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>بڕ</th>
                                        <th>شێواز</th>
                                        <th>بەروار</th>
                                        <th>ژمارەی پسوڵە</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_payments as $payment): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-success"><?php echo formatCurrencyAmount($payment['payment_amount'], $payment['currency'] ?? $creditCurrency); ?></strong>
                                        </td>
                                        <td>
                                            <?php
                                            $methods = [
                                                'cash' => 'نەقد',
                                                'bank_transfer' => 'بانکی',
                                                'check' => 'چێک',
                                                'other' => 'تر'
                                            ];
                                            echo $methods[$payment['payment_method']] ?? $payment['payment_method'];
                                            ?>
                                        </td>
                                        <td>
                                            <small><?php echo date('Y/m/d H:i', strtotime($payment['payment_date'])); ?></small>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($payment['receipt_number'] ?: '-'); ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setAmount(amount) {
            document.getElementById('payment_amount').value = amount.toFixed(3);
        }

        function toggleWalletField() {
            const method = document.getElementById('payment_method').value;
            const walletField = document.getElementById('wallet_field');
            const walletInput = document.getElementById('wallet_id');
            if (method === 'cash') {
                walletField.style.display = '';
                walletInput.required = true;
            } else {
                walletField.style.display = 'none';
                walletInput.required = false;
                walletInput.value = '';
            }
        }

        // Auto-focus on amount field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('payment_amount').focus();
            document.getElementById('payment_method').addEventListener('change', toggleWalletField);
            toggleWalletField();
        });

        // Validate amount on input
        document.getElementById('payment_amount').addEventListener('input', function() {
            const maxAmount = <?php echo $credit['remaining_amount']; ?>;
            const currentAmount = parseFloat(this.value);
            
            if (currentAmount > maxAmount) {
                this.value = maxAmount;
                alert('بڕی پارەدانەوە ناتوانێت لە بڕی ماوە زیاتر بێت!');
            }
        });
    </script>
</body>
</html>