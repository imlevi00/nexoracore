<?php
/**
 * دەستکاریکردنی خەرجی - user/expenses/edit.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/wallet_service.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'expenses.edit', [
    'route' => '/user/expenses/edit.php',
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

// وەرگرتنی خەرجی
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
$wallets = walletGetUserWallets($conn, (int)$userId, true);
if (empty($wallets) && function_exists('walletEnsureDefaultForUser')) {
    walletEnsureDefaultForUser($conn, (int)$userId);
    $wallets = walletGetUserWallets($conn, (int)$userId, true);
}

// پرۆسێسکردنی فۆرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = ['type' => 'danger', 'text' => 'تێکچوونی ئامنیەت! دووبارە تاقی بکەرەوە.'];
    } else {
        $expense_name = trim($_POST['expense_name'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $currency = strtoupper($_POST['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $wallet_id = (int)($_POST['wallet_id'] ?? 0);
        $is_recurring = intval($_POST['is_recurring'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $receipt_number = trim($_POST['receipt_number'] ?? '');
        $expense_date = $_POST['expense_date'] ?? date('Y-m-d H:i:s');
        
        if ($payment_method === 'cash' && $wallet_id <= 0) {
            $defaultWid = walletGetDefaultWalletId($conn, $userId);
            if ($defaultWid) {
                $wallet_id = (int)$defaultWid;
            }
        }
        
        // تاقیکردنی داتاکان
        if (empty($expense_name)) {
            $message = ['type' => 'danger', 'text' => 'ناوی خەرجی پێویستە!'];
        } elseif ($amount <= 0) {
            $message = ['type' => 'danger', 'text' => 'بڕی پارە دەبێت لە سفرەوە زیاتر بێت!'];
        } elseif (!in_array($payment_method, ['cash', 'credit'], true)) {
            $message = ['type' => 'danger', 'text' => 'شێوازی پارەدان هەڵەیە!'];
        } elseif ($payment_method === 'cash' && $wallet_id <= 0) {
            $message = ['type' => 'danger', 'text' => 'تکایە قاسە هەڵبژێرە'];
        } else {
            $oldPaymentMethod = (string)$expense['payment_method'];
            $oldWalletId = (int)($expense['wallet_id'] ?? 0);
            $oldAmount = (float)$expense['amount'];
            $oldCurrency = strtoupper($expense['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';
            $has_credit = ($payment_method === 'credit') ? 1 : 0;

            $conn->begin_transaction();
            
            try {
                $expense_type_id = null;
                
                // ئەگەر خەرجی دووبارە بێت، جۆری خەرجی خەزن بکە
                if ($is_recurring) {
                    // بگەڕێ بەدوای جۆری خەرجی هاوشێوە
                    $check_type = $conn->prepare("SELECT id FROM expense_types WHERE user_id = ? AND name = ? AND is_recurring = 1");
                    $check_type->bind_param("is", $userId, $expense_name);
                    $check_type->execute();
                    $type_result = $check_type->get_result();
                    
                    if ($type_result->num_rows > 0) {
                        // جۆری خەرجی هەیە، ئەویش بەکاربێنە
                        $expense_type_id = $type_result->fetch_assoc()['id'];
                    } else {
                        // جۆری خەرجی نوێ دروست بکە
                        $insert_type = $conn->prepare("INSERT INTO expense_types (user_id, name, description, is_recurring) VALUES (?, ?, ?, 1)");
                        $insert_type->bind_param("iss", $userId, $expense_name, $description);
                        
                        if (!$insert_type->execute()) {
                            throw new Exception('نەتوانرا جۆری خەرجی زیاد بکرێت!');
                        }
                        
                        $expense_type_id = $conn->insert_id;
                    }
                }
                
                // نوێکردنەوەی خەرجی
                $update_expense = $conn->prepare("
                    UPDATE expenses SET
                        expense_type_id = ?, expense_name = ?, amount = ?, currency = ?,
                        payment_method = ?, wallet_id = ?, is_recurring = ?, has_credit = ?, description = ?,
                        receipt_number = ?, expense_date = ?
                    WHERE id = ? AND user_id = ?
                ");

                $update_expense->bind_param(
                    "isdssiiisssii",
                    $expense_type_id, $expense_name, $amount, $currency,
                    $payment_method, $wallet_id, $is_recurring, $has_credit, $description,
                    $receipt_number, $expense_date, $expense_id, $userId
                );

                if (!$update_expense->execute()) {
                    throw new Exception('نەتوانرا خەرجی نوێ بکرێتەوە!');
                }

                // ئەگەر خەرجییەکە قەرزی پەیوەستی هەبێت، دراوی قەرزیش هاوتا بکە
                $sync_credit_currency = $conn->prepare("UPDATE expense_credits SET currency = ? WHERE expense_id = ? AND user_id = ?");
                $sync_credit_currency->bind_param("sii", $currency, $expense_id, $userId);
                $sync_credit_currency->execute();

                if (!walletSyncExpenseOnEdit(
                    $conn,
                    (int)$userId,
                    (int)$expense_id,
                    $oldPaymentMethod,
                    $oldWalletId,
                    $oldAmount,
                    $payment_method,
                    $wallet_id,
                    $amount,
                    $oldCurrency,
                    $currency,
                    (int)$userId
                )) {
                    throw new Exception('نەتوانرا جوڵەی قاسەی خەرجی نوێ بکرێتەوە');
                }
                
                $conn->commit();
                $message = ['type' => 'success', 'text' => 'خەرجی بە سەرکەوتووی نوێکرایەوە!'];
                
                // نوێکردنەوەی داتای خەرجی لە memory
                $expense['expense_name'] = $expense_name;
                $expense['amount'] = $amount;
                $expense['currency'] = $currency;
                $expense['payment_method'] = $payment_method;
                $expense['wallet_id'] = $wallet_id;
                $expense['is_recurring'] = $is_recurring;
                $expense['description'] = $description;
                $expense['receipt_number'] = $receipt_number;
                $expense['expense_date'] = $expense_date;
                
            } catch (Exception $e) {
                $conn->rollback();
                $message = ['type' => 'danger', 'text' => $e->getMessage()];
            }
        }
    }
}

// وەرگرتنی جۆرەکانی خەرجی دووبارە لەگەڵ بڕی کۆتا جار
$recurring_types = [];
$types_stmt = $conn->prepare("
    SELECT et.id, et.name, et.description,
           (SELECT e.amount FROM expenses e
            WHERE e.user_id = ? AND e.expense_type_id = et.id
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT 1) AS last_amount
    FROM expense_types et
    WHERE et.user_id = ? AND et.is_recurring = 1
    ORDER BY et.name
");
$types_stmt->bind_param("ii", $userId, $userId);
$types_stmt->execute();
$types_result = $types_stmt->get_result();
if ($types_result) {
    $recurring_types = $types_result->fetch_all(MYSQLI_ASSOC);
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>دەستکاریکردنی خەرجی - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="expenses-module-page expenses-form-page bg-light">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-xl-8 col-lg-10">
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-pencil-square text-primary"></i>
                        دەستکاریکردنی خەرجی
                    </h1>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="window.open('print_receipt.php?id=<?php echo (int)$expense_id; ?>&print=1', '_blank')">
                            <i class="bi bi-printer"></i> چاپکردنی وەسڵ
                        </button>
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
                        <?php if ($message['type'] === 'success'): ?>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-dark"
                                        onclick="window.open('print_receipt.php?id=<?php echo (int)$expense_id; ?>&print=1', '_blank')">
                                    <i class="bi bi-printer"></i> چاپکردنی وەسڵ
                                </button>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Edit Expense Form -->
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-form"></i> دەستکاریکردنی زانیاری خەرجی
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <!-- Current Info Display -->
                            <div class="alert alert-info mb-4">
                                <h6><i class="bi bi-info-circle"></i> زانیاری ئێستا:</h6>
                                <ul class="mb-0">
                                    <li><strong>ناو:</strong> <?php echo htmlspecialchars($expense['expense_name']); ?></li>
                                    <li><strong>بڕ:</strong> <?php echo formatCurrencyAmount($expense['amount'], $expense['currency'] ?? 'IQD'); ?></li>
                                    <li><strong>شێواز:</strong> <?php echo $expense['payment_method'] === 'cash' ? 'نەقد' : 'قەرز'; ?></li>
                                    <li><strong>جۆر:</strong> <?php echo $expense['is_recurring'] ? 'دووبارە' : 'یەک جار'; ?></li>
                                </ul>
                            </div>
                            
                            <!-- Expense Type Selection -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">جۆری خەرجی</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="expense_type_selection" 
                                               id="new_expense" value="new" <?php echo !$expense['is_recurring'] ? 'checked' : ''; ?> onchange="toggleExpenseType()">
                                        <label class="form-check-label" for="new_expense">
                                            خەرجی نوێ / یەک جار
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="expense_type_selection" 
                                               id="recurring_expense" value="recurring" <?php echo $expense['is_recurring'] ? 'checked' : ''; ?> onchange="toggleExpenseType()">
                                        <label class="form-check-label" for="recurring_expense">
                                            خەرجی دووبارە (لە لیستی هەیە)
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">شێوازی پارەدان *</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="payment_method" 
                                               id="cash" value="cash" <?php echo $expense['payment_method'] === 'cash' ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-success" for="cash">
                                            <i class="bi bi-cash"></i> نەقد
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="payment_method" 
                                               id="credit" value="credit" <?php echo $expense['payment_method'] === 'credit' ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-warning" for="credit">
                                            <i class="bi bi-credit-card"></i> قەرز
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="wallet_id">قاسە</label>
                                    <select class="form-select" id="wallet_id" name="wallet_id">
                                        <option value="">قاسە هەڵبژێرە</option>
                                        <?php foreach ($wallets as $wallet): ?>
                                            <option value="<?php echo (int)$wallet['id']; ?>" <?php echo ((int)($expense['wallet_id'] ?? 0) === (int)$wallet['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string)$wallet['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Recurring Expense Selection -->
                            <div id="recurring_selection" class="mb-3" style="<?php echo $expense['is_recurring'] ? '' : 'display: none;'; ?>">
                                <label class="form-label">هەڵبژاردنی خەرجی دووبارە</label>
                                <select class="form-select" id="recurring_type_select" onchange="fillRecurringData()">
                                    <option value="">هەڵبژاردنی خەرجی دووبارە...</option>
                                    <?php foreach ($recurring_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($type['name']); ?>"
                                                data-description="<?php echo htmlspecialchars($type['description']); ?>"
                                                data-amount="<?php echo htmlspecialchars($type['last_amount'] ?? ''); ?>"
                                                <?php echo $expense['expense_type_id'] == $type['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-3">
                                <!-- Expense Name -->
                                <div class="col-md-6">
                                    <label class="form-label" for="expense_name">ناوی خەرجی *</label>
                                    <input type="text" class="form-control" id="expense_name" name="expense_name" 
                                           value="<?php echo htmlspecialchars($expense['expense_name']); ?>" 
                                           placeholder="ناوی خەرجی بنووسە..." required>
                                </div>
                                
                                <!-- Amount -->
                                <div class="col-md-6">
                                    <label class="form-label" for="amount">بڕی پارە *</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="amount" name="amount"
                                               value="<?php echo $expense['amount']; ?>"
                                               min="0" step="0.001" placeholder="0" required>
                                        <?php $selCur = strtoupper($expense['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD'; ?>
                                        <select class="form-select flex-grow-0 w-auto" id="currency" name="currency">
                                            <option value="IQD" <?php echo $selCur === 'IQD' ? 'selected' : ''; ?>>دینار</option>
                                            <option value="USD" <?php echo $selCur === 'USD' ? 'selected' : ''; ?>>دۆلار $</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Receipt Number -->
                                <div class="col-md-6">
                                    <label class="form-label" for="receipt_number">ژمارەی پسوڵە</label>
                                    <input type="text" class="form-control" id="receipt_number" name="receipt_number" 
                                           value="<?php echo htmlspecialchars($expense['receipt_number']); ?>" 
                                           placeholder="ژمارەی پسوڵە (ئیختیاری)">
                                </div>
                                
                                <!-- Expense Date -->
                                <div class="col-md-6">
                                    <label class="form-label" for="expense_date">بەرواری خەرجی</label>
                                    <input type="datetime-local" class="form-control" id="expense_date" name="expense_date" 
                                           value="<?php echo date('Y-m-d\TH:i', strtotime($expense['expense_date'])); ?>">
                                </div>
                                
                                <!-- Description -->
                                <div class="col-12">
                                    <label class="form-label" for="description">تێبینی</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" 
                                              placeholder="تێبینی یان وردەکاری زیاتر..."><?php echo htmlspecialchars($expense['description']); ?></textarea>
                                </div>
                            </div>

                            <!-- Is Recurring Hidden Field -->
                            <input type="hidden" id="is_recurring" name="is_recurring" value="<?php echo $expense['is_recurring']; ?>">

                            <!-- Submit Buttons -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <a href="index.php" class="btn btn-secondary">
                                            <i class="bi bi-x-lg"></i> پاشگەزبوونەوە
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-lg"></i> نوێکردنەوەی خەرجی
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
        function toggleExpenseType() {
            const isRecurring = document.getElementById('recurring_expense').checked;
            const recurringSelection = document.getElementById('recurring_selection');
            const isRecurringField = document.getElementById('is_recurring');
            
            if (isRecurring) {
                recurringSelection.style.display = 'block';
                isRecurringField.value = '1';
            } else {
                recurringSelection.style.display = 'none';
                isRecurringField.value = '0';
                document.getElementById('recurring_type_select').value = '';
            }
        }

        function fillRecurringData() {
            const select = document.getElementById('recurring_type_select');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                document.getElementById('expense_name').value = selectedOption.dataset.name || '';
                document.getElementById('description').value = selectedOption.dataset.description || '';
                document.getElementById('amount').value = selectedOption.dataset.amount || '';
            }
        }
    </script>
</body>
</html>