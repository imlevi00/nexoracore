<?php
/**
 * پارەدانەوەی وەسڵی قەرز - user/purchases/pay_installment.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/company_computed_debt.php';
require_once '../../includes/wallet_service.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
requireCompaniesModuleAccess();
$userId = $currentUser['id'];

$receiptId = (int)($_GET['id'] ?? 0);
$errors = [];
$wallets = walletGetUserWallets($conn, (int)$userId, true);

// وەرگرتنی زانیاری وەسڵەکە
$receiptStmt = $conn->prepare("
    SELECT pr.*, c.name as company_name
    FROM purchase_receipts pr
    LEFT JOIN companies c ON pr.company_id = c.id
    WHERE pr.id = ? AND pr.user_id = ? AND pr.payment_type = 'debt' AND pr.status = 'active'
");
$receiptStmt->bind_param("ii", $receiptId, $userId);
$receiptStmt->execute();
$receipt = $receiptStmt->get_result()->fetch_assoc();

if (!$receipt) {
    setMessage('وەسڵەکە نەدۆزرایەوە یان دەسەڵاتت نییە', 'error');
    redirect(url('user/purchases/index.php'));
}

if (!$receipt['company_id']) {
    setMessage('وەسڵەکە بە کۆمپانیایەکەوە بەستراونەتەوە', 'error');
    redirect(url('user/purchases/index.php'));
}

$companyId = $receipt['company_id'];
$receiptAmount = $receipt['final_amount'];
$receiptCurrency = (($receipt['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
$receiptDebtColumn = $receiptCurrency === 'USD' ? 'debt_amount_usd' : 'debt_amount';

// بڕی پێشتر پارەدراو بۆ ئەم وەسڵە تایبەتە
$paidAmountForReceipt = 0;
$remainingAmount = $receiptAmount;

$paidQuery = "
    SELECT COALESCE(SUM(amount), 0) as total_paid
    FROM company_debts
    WHERE company_id = ?
      AND user_id = ?
      AND type = 'payment'
      AND purchase_receipt_id = ?
";

if ($paidStmt = $conn->prepare($paidQuery)) {
    $paidStmt->bind_param("iii", $companyId, $userId, $receiptId);
    if ($paidStmt->execute()) {
        $paidResult = $paidStmt->get_result()->fetch_assoc();
        $paidAmountForReceipt = (float)($paidResult['total_paid'] ?? 0);
        if ($paidAmountForReceipt < 0) {
            $paidAmountForReceipt = 0;
        }
        $remainingAmount = max(0, $receiptAmount - $paidAmountForReceipt);
    }
}

// پرۆسێسی پارەدانەوە
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $description = cleanInput($_POST['description'] ?? '');
        $date = $_POST['date'] ?? date('Y-m-d');
        $walletId = (int)($_POST['wallet_id'] ?? 0);
        
        // پشتڕاستکردنەوە
        if ($remainingAmount <= 0) {
            $errors[] = 'ئەم وەسڵە قەرزی مانەوە نییە، ناتوانیت دووبارە پارەدانەوەی بۆ بکەیت';
        } elseif ($amount <= 0) {
            $errors[] = 'بڕەکە دەبێت گەورەتر لە سفر بێت';
        } elseif ($walletId <= 0) {
            $errors[] = 'تکایە قاسەی پارەدانەوە هەڵبژێرە';
        } elseif ($amount > $remainingAmount) {
            $errors[] = 'بڕەکە ناتوانێت گەورەتر بێت لە بڕی مانەوەی وەسڵەکە';
        }
        
        if (empty($description)) {
            $receiptNum = $receipt['receipt_number'] ? $receipt['receipt_number'] : $receiptId;
            $description = "پارەدانەوە لە وەسڵ #{$receiptNum}";
        }
        
        if (empty($errors)) {
            $conn->begin_transaction();
            
            try {
                // تۆمارکردنی مامەڵەی دانەوە لە company_debts (بە دراوی وەسڵەکە)
                $stmt = $conn->prepare("
                    INSERT INTO company_debts (user_id, company_id, purchase_receipt_id, amount, currency, description, type, date)
                    VALUES (?, ?, ?, ?, ?, ?, 'payment', ?)
                ");
                $stmt->bind_param("iiidsss", $userId, $companyId, $receiptId, $amount, $receiptCurrency, $description, $date);

                if (!$stmt->execute()) {
                    throw new Exception('هەڵە لە تۆمارکردنی مامەڵە');
                }

                // نوێکردنەوەی بڕی قەرزی کۆمپانیا (ئەتۆمیک) — باڵانسی هەمان دراو
                $updateCompanyStmt = $conn->prepare("
                    UPDATE companies
                    SET {$receiptDebtColumn} = GREATEST({$receiptDebtColumn} - ?, 0), updated_at = NOW()
                    WHERE id = ?
                ");
                $updateCompanyStmt->bind_param("di", $amount, $companyId);
                $updateCompanyStmt->execute();
                
                // ئەگەر قەرزی ئەم وەسڵەیە تەواو دانەوە کرابێت، status ی بگۆڕە بۆ completed
                $newPaidTotal = $paidAmountForReceipt + $amount;
                if ($newPaidTotal >= $receiptAmount) {
                    $updateReceiptStmt = $conn->prepare("UPDATE purchase_receipts SET status = 'completed', updated_at = NOW() WHERE id = ?");
                    $updateReceiptStmt->bind_param("i", $receiptId);
                    $updateReceiptStmt->execute();
                }

                if (!walletPostEntry(
                    $conn,
                    (int)$userId,
                    $walletId,
                    'purchase_installment_out',
                    'out',
                    $receiptCurrency,
                    $amount,
                    'purchase_installment',
                    (int)$receiptId,
                    'Purchase installment payment',
                    (int)$userId
                )) {
                    throw new Exception('هەڵە لە تۆمارکردنی جوڵەی قاسە');
                }
                
                $conn->commit();
                
                $successMessage = 'پارە بەسەرکەوتوویی وەرگیرا';
                if ($amount >= $remainingAmount) {
                    $successMessage .= ' و وەسڵەکە تەواو دانەوە کرا';
                }
                
                setMessage($successMessage, 'success');
                redirect(url('user/purchases/index.php?company_id=' . $companyId));
                
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'هەڵە لە تۆمارکردن: ' . $e->getMessage();
            }
        }
    }
}

// وەرگرتنی کۆتا ٥ مامەڵەی ئەم کۆمپانیایە
$recentQuery = "SELECT * FROM company_debts 
                WHERE company_id = ? AND user_id = ? 
                ORDER BY date DESC, created_at DESC 
                LIMIT 5";

$recentStmt = $conn->prepare($recentQuery);
$recentStmt->bind_param("ii", $companyId, $userId);
$recentStmt->execute();
$recentTransactions = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// نوێکردنەوەی زانیاری کۆمپانیا
$companyStmt = $conn->prepare("SELECT * FROM companies WHERE id = ? AND user_id = ?");
$companyStmt->bind_param("ii", $companyId, $userId);
$companyStmt->execute();
$company = $companyStmt->get_result()->fetch_assoc();
if ($company) {
    $company['computed_remaining_debt'] = fetch_company_computed_remaining_debt($conn, (int)$companyId, $userId, $receiptCurrency);
}

$csrf_token = Security::generateCSRFToken();
$pageTitle = 'پارەدانەوەی وەسڵی قەرز';
include '../../includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-xl-10">
            
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h2 class="mb-1">
                                        <i class="bi bi-cash-coin"></i>
                                        پارەدانەوەی وەسڵی قەرز
                                    </h2>
                                    <h4 class="mb-0"><?php echo htmlspecialchars($receipt['company_name']); ?></h4>
                                </div>
                                <div class="text-end">
                                    <div class="h3 mb-1"><?php echo htmlspecialchars(formatCurrencyAmount($receiptAmount, $receiptCurrency)); ?></div>
                                    <small class="opacity-75 d-block">کۆی وەسڵەکە</small>
                                    <small class="opacity-75">مانەوە: <?php echo htmlspecialchars(formatCurrencyAmount($remainingAmount, $receiptCurrency)); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>هەڵەکان:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Payment Form -->
                <div class="col-lg-8">
                    <div class="card shadow">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-credit-card text-primary"></i>
                                زانیاری پارەدانەوە
                            </h5>
                        </div>
                        <div class="card-body">
                            
                            <!-- Receipt Info -->
                            <div class="alert alert-info mb-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>ژمارەی وەسڵ:</strong> 
                                        <?php echo $receipt['receipt_number'] ? htmlspecialchars($receipt['receipt_number']) : '#' . $receiptId; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>بەروار:</strong> 
                                        <?php echo date('Y-m-d', strtotime($receipt['receipt_date'])); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <form method="POST" class="needs-validation" novalidate id="paymentForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="row g-3">
                                    <!-- Amount -->
                                    <div class="col-md-6">
                                        <label for="amount" class="form-label required">بڕی پارەدانەوە</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="amount" name="amount"
                                                   min="0.01" max="<?php echo $remainingAmount; ?>" step="0.01" required
                                                   placeholder="تا <?php echo htmlspecialchars(formatCurrencyAmount($remainingAmount, $receiptCurrency)); ?>">
                                            <span class="input-group-text"><?php echo $receiptCurrency === 'USD' ? 'دۆلار' : 'دینار'; ?></span>
                                        </div>
                                        <div class="invalid-feedback">تکایە بڕەکە دیاری بکە (تا <?php echo htmlspecialchars(formatCurrencyAmount($remainingAmount, $receiptCurrency)); ?>)</div>
                                        <div id="amount_help" class="form-text">ئەتوانیت تا <?php echo htmlspecialchars(formatCurrencyAmount($remainingAmount, $receiptCurrency)); ?> بنووسیت</div>
                                    </div>
                                    
                                    <!-- Date -->
                                    <div class="col-md-6">
                                        <label for="date" class="form-label required">بەروار</label>
                                        <input type="date" class="form-control" id="date" name="date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                        <div class="invalid-feedback">تکایە بەروار دیاری بکە</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="wallet_id" class="form-label required">قاسە</label>
                                        <select class="form-select" id="wallet_id" name="wallet_id" required>
                                            <option value="">قاسە هەڵبژێرە</option>
                                            <?php foreach ($wallets as $wallet): ?>
                                                <option value="<?php echo (int)$wallet['id']; ?>">
                                                    <?php echo htmlspecialchars((string)$wallet['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">تکایە قاسە هەڵبژێرە</div>
                                    </div>
                                    
                                    <!-- Description (ئیجباری نییە) -->
                                    <div class="col-12">
                                        <label for="description" class="form-label">تێبینی <span class="text-muted small">(ئیجباری نییە)</span></label>
                                        <textarea class="form-control" id="description" name="description" rows="2" 
                                                  placeholder="وردەکاری مامەڵەکە ئەگەر دەتەوێت..."></textarea>
                                    </div>
                                </div>
                                
                                <!-- Form Actions -->
                                <div class="row mt-4">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between">
                                            <a href="<?php echo url('user/purchases/index.php?company_id=' . $companyId); ?>" class="btn btn-secondary">
                                                <i class="bi bi-x-circle"></i> پاشگەز
                                            </a>
                                            <button type="submit" class="btn btn-success px-4" id="submitBtn">
                                                <i class="bi bi-check-circle"></i> پارەدانەوە
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Company Info & Recent Transactions -->
                <div class="col-lg-4">
                    
                    <!-- Company Debt Info -->
                    <div class="card mb-4">
                        <div class="card-body bg-warning bg-opacity-10 text-center">
                            <h6 class="mb-3">زانیاری قەرزی کۆمپانیا</h6>
                            <div class="row">
                                <div class="col-12 mb-2">
                                    <div class="h4 mb-1 text-danger"><?php echo htmlspecialchars(formatCurrencyAmount((float)($company['computed_remaining_debt'] ?? 0), $receiptCurrency)); ?></div>
                                    <small class="text-muted">قەرزی مانەوە (<?php echo $receiptCurrency === 'USD' ? 'دۆلار' : 'دینار'; ?>)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Transactions -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title mb-0">
                                <i class="bi bi-clock-history text-primary"></i>
                                دوایین مامەڵەکان
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($recentTransactions)): ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-inbox text-muted" style="font-size: 2rem;"></i>
                                    <p class="text-muted mt-2 mb-0">هیچ مامەڵەیەک نییە</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recentTransactions as $trans): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center mb-1">
                                                        <i class="bi bi-<?php echo $trans['type'] === 'debt' ? 'arrow-up-circle text-danger' : 'arrow-down-circle text-success'; ?> me-2"></i>
                                                        <h6 class="mb-0 small">
                                                            <?php echo $trans['type'] === 'debt' ? 'قەرز' : 'دانەوە'; ?>
                                                        </h6>
                                                    </div>
                                                    <p class="mb-0 text-muted small"><?php echo htmlspecialchars($trans['description']); ?></p>
                                                </div>
                                                <div class="text-end">
                                                    <div class="fw-bold small <?php echo $trans['type'] === 'debt' ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo htmlspecialchars(formatCurrencyAmount($trans['amount'], $trans['currency'] ?? 'IQD')); ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo date('m/d', strtotime($trans['date'])); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .required::after {
        content: ' *';
        color: red;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const receiptAmount = <?php echo $receiptAmount; ?>;
    const remainingAmount = <?php echo $remainingAmount; ?>;
    const receiptCurrencyLabel = '<?php echo $receiptCurrency === 'USD' ? 'دۆلار' : 'دینار'; ?>';

    function selectPaymentType(type) {
        // Remove selected class from all options
        document.querySelectorAll('.payment-option').forEach(option => {
            option.classList.remove('selected');
        });
        
        // Add selected class to clicked option
        event.target.closest('.payment-option').classList.add('selected');
        
        // Set hidden input value
        document.getElementById('selected_payment_type').value = type;
        
        // Update amount field
        const amountField = document.getElementById('amount');
        const amountHelp = document.getElementById('amount_help');
        const submitBtn = document.getElementById('submitBtn');
        
        if (type === 'full') {
            amountField.value = remainingAmount;
            amountField.readOnly = true;
            amountHelp.textContent = 'تەواوی بڕی مانەوەی وەسڵەکە دەدرێتەوە';
            amountHelp.className = 'form-text text-success';
        } else {
            amountField.value = '';
            amountField.readOnly = false;
            amountField.max = remainingAmount;
            amountHelp.textContent = `ئەتوانیت تا ${remainingAmount.toLocaleString()} ${receiptCurrencyLabel} بدەیتەوە`;
            amountHelp.className = 'form-text text-info';
        }
        
        // Enable submit button
        submitBtn.disabled = false;
        
        // Update description
        const descField = document.getElementById('description');
        const receiptNum = '<?php echo $receipt['receipt_number'] ? htmlspecialchars($receipt['receipt_number']) : $receiptId; ?>';
        if (type === 'full') {
            descField.value = 'پارەدانەوەی تەواوی وەسڵ #' + receiptNum;
        } else {
            descField.value = 'پارەدانەوەی بەشی لە وەسڵ #' + receiptNum;
        }
    }
    
    // Amount validation
    document.getElementById('amount').addEventListener('input', function() {
        const amount = parseFloat(this.value) || 0;
        const amountHelp = document.getElementById('amount_help');
        const paymentType = document.getElementById('selected_payment_type').value;
        
        if (paymentType === 'partial') {
            if (amount > remainingAmount) {
                this.setCustomValidity('بڕەکە گەورەتر لە بڕی مانەوەی وەسڵەکەیە');
                amountHelp.textContent = 'بڕەکە زۆرە!';
                amountHelp.className = 'form-text text-danger';
            } else if (amount <= 0) {
                this.setCustomValidity('بڕەکە دەبێت گەورەتر لە سفر بێت');
                amountHelp.textContent = 'بڕەکە ناتوانێت سفر یان کەمتر بێت';
                amountHelp.className = 'form-text text-danger';
            } else {
                this.setCustomValidity('');
                const remaining = remainingAmount - amount;
                amountHelp.textContent = `دوای ئەم دانەوەیە ${remaining.toLocaleString()} ${receiptCurrencyLabel} دەمێنێتەوە`;
                amountHelp.className = 'form-text text-success';
            }
        }
    });
    
    (function() {
        'use strict';
        window.addEventListener('load', function() {
            var forms = document.getElementsByClassName('needs-validation');
            Array.prototype.filter.call(forms, function(form) {
                form.addEventListener('submit', function(event) {
                    if (form.checkValidity() === false) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    form.classList.add('was-validated');
                }, false);
            });
        }, false);
    })();
</script>

<?php include '../../includes/footer.php'; ?>

