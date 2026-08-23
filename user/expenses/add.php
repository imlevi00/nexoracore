<?php
/**
 * زیادکردنی خەرجی نوێ (نوێکراوەتەوە) - user/expenses/add.php
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
enforceAuthorizationOrDeny($currentUser, 'expenses.create', [
    'route' => '/user/expenses/add.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

$message = null;
$saved_expense_id = null;

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
        
        // زانیاری قەرز (ئەگەر payment_method = credit بووبێت)
        $creditor_name = trim($_POST['creditor_name'] ?? '');
        $creditor_phone = trim($_POST['creditor_phone'] ?? '');
        $due_date = $_POST['due_date'] ?? '';
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $credit_notes = trim($_POST['credit_notes'] ?? '');
        
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
        } elseif ($payment_method === 'credit' && empty($creditor_name)) {
            $message = ['type' => 'danger', 'text' => 'ناوی قەرزکار پێویستە بۆ خەرجی قەرز!'];
        } else {
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
                        $expense_type_id = (int)$type_result->fetch_assoc()['id'];
                    } else {
                        // جۆری خەرجی نوێ دروست بکە
                        $insert_type = $conn->prepare("INSERT INTO expense_types (user_id, name, description, is_recurring) VALUES (?, ?, ?, 1)");
                        $insert_type->bind_param("iss", $userId, $expense_name, $description);
                        
                        if (!$insert_type->execute()) {
                            throw new Exception('نەتوانرا جۆری خەرجی زیاد بکرێت!');
                        }
                        
                        $expense_type_id = (int)$conn->insert_id;
                    }
                }
                
                // دیاریکردنی has_credit
                $has_credit = ($payment_method === 'credit') ? 1 : 0;
                $wallet_id_val = ($payment_method === 'cash' && $wallet_id > 0) ? $wallet_id : null;
                
                // خەرجی زیاد بکە
                $insert_expense = $conn->prepare("
                    INSERT INTO expenses (
                        user_id, expense_type_id, expense_name, amount, currency,
                        payment_method, wallet_id, is_recurring, has_credit, description,
                        receipt_number, expense_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $insert_expense->bind_param(
                    "iisdssiissss",
                    $userId, $expense_type_id, $expense_name, $amount, $currency,
                    $payment_method, $wallet_id_val, $is_recurring, $has_credit, $description,
                    $receipt_number, $expense_date
                );
                
                if (!$insert_expense->execute()) {
                    throw new Exception('نەتوانرا خەرجی زیاد بکرێت!');
                }
                
                $expense_id = $conn->insert_id;

                if ($payment_method === 'cash') {
                    if (!walletPostEntry(
                        $conn,
                        $userId,
                        $wallet_id,
                        'expense_out',
                        'out',
                        $currency,
                        $amount,
                        'expense',
                        (int)$expense_id,
                        'Expense payment',
                        (int)$userId
                    )) {
                        throw new Exception('نەتوانرا جوڵەی قاسەی خەرجی تۆمار بکرێت');
                    }
                }
                
                // ئەگەر خەرجی بە قەرز بووبێت، قەرزیش زیاد بکە
                if ($payment_method === 'credit') {
                    $insert_credit = $conn->prepare("
                        INSERT INTO expense_credits (
                            user_id, expense_id, creditor_name, creditor_phone,
                            total_amount, remaining_amount, currency, due_date,
                            payment_terms, notes
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $due_date_value = !empty($due_date) ? $due_date : null;

                   $insert_credit->bind_param(
    "iissddssss",
    $userId, $expense_id, $creditor_name, $creditor_phone,
    $amount, $amount, $currency, $due_date_value,
    $payment_terms, $credit_notes
);
                    
                    if (!$insert_credit->execute()) {
                        throw new Exception('نەتوانرا قەرزی خەرجی زیاد بکرێت!');
                    }
                }
                
                $conn->commit();
                $saved_expense_id = (int)$expense_id;
                $message = ['type' => 'success', 'text' => 'خەرجی بە سەرکەوتووی زیادکرا!' . ($payment_method === 'credit' ? ' قەرزیش تۆمارکرا.' : '')];
                
                // پاکەکردنەوەی فۆرم
                $expense_name = '';
                $amount = '';
                $description = '';
                $receipt_number = '';
                $creditor_name = '';
                $creditor_phone = '';
                $payment_terms = '';
                $credit_notes = '';
                
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
            LIMIT 1) AS last_amount,
           (SELECT e.currency FROM expenses e
            WHERE e.user_id = ? AND e.expense_type_id = et.id
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT 1) AS last_currency
    FROM expense_types et
    WHERE et.user_id = ? AND et.is_recurring = 1
    ORDER BY et.name
");
if ($types_stmt) {
    $types_stmt->bind_param("iii", $userId, $userId, $userId);
    $types_stmt->execute();
    $types_result = $types_stmt->get_result();
    if ($types_result) {
        $recurring_types = $types_result->fetch_all(MYSQLI_ASSOC);
    }
    $types_stmt->close();
}
$wallets = walletGetUserWallets($conn, (int)$userId, true);
if (empty($wallets) && function_exists('walletEnsureDefaultForUser')) {
    walletEnsureDefaultForUser($conn, (int)$userId);
    $wallets = walletGetUserWallets($conn, (int)$userId, true);
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>زیادکردنی خەرجی نوێ - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/expenses/expenses-pages.css'); ?>" rel="stylesheet">
</head>
<body class="expenses-module-page expenses-form-page">

    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container py-4 ex-wrap-narrow">

        <header class="ex-hero">
            <div>
                <div class="ex-kicker"><i class="bi bi-wallet2"></i> بەشی خەرجیەکان</div>
                <h1><i class="bi bi-plus-circle"></i> زیادکردنی خەرجی نوێ</h1>
                <p class="ex-hero-sub">خەرجی نەقد یان قەرز تۆمار بکە، لەگەڵ قاسە و جۆری دووبارە</p>
            </div>
            <div class="ex-actions">
                <a href="index.php" class="ex-btn ex-btn-ghost">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
                <?php echo $message['text']; ?>
                <?php if ($message['type'] === 'success' && !empty($saved_expense_id)): ?>
                    <div class="mt-2">
                        <button type="button" class="ex-btn ex-btn-ghost"
                                onclick="window.open('print_receipt.php?id=<?php echo (int)$saved_expense_id; ?>&print=1', '_blank')">
                            <i class="bi bi-printer"></i> چاپکردنی وەسڵ
                        </button>
                    </div>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <section class="ex-panel">
                <div class="ex-panel-head">
                    <span><i class="bi bi-cash-coin"></i> شێوازی پارەدان</span>
                </div>
                <div class="ex-panel-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">شێوازی پارەدان *</label>
                            <div class="btn-group w-100 ex-pay-toggle" role="group">
                                <input type="radio" class="btn-check" name="payment_method"
                                       id="cash" value="cash" checked onchange="toggleCreditFields()">
                                <label class="btn btn-outline-success" for="cash">
                                    <i class="bi bi-cash"></i> نەقد
                                </label>

                                <input type="radio" class="btn-check" name="payment_method"
                                       id="credit" value="credit" onchange="toggleCreditFields()">
                                <label class="btn btn-outline-warning" for="credit">
                                    <i class="bi bi-credit-card"></i> قەرز
                                </label>
                            </div>
                            <small class="text-muted d-block mt-2">
                                ئەگەر خەرجیەکەت بە قەرز بووبێت، قەرز هەڵبژێرە تا زانیاری قەرزکارەکە زیاد بکەیت
                            </small>
                        </div>
                        <div class="col-12" id="wallet_section">
                            <label class="form-label" for="wallet_id">قاسە *</label>
                            <select class="form-select" id="wallet_id" name="wallet_id">
                                <option value="">قاسە هەڵبژێرە</option>
                                <?php foreach ($wallets as $wallet): ?>
                                    <option value="<?php echo (int)$wallet['id']; ?>">
                                        <?php echo htmlspecialchars((string)$wallet['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ex-panel">
                <div class="ex-panel-head">
                    <span><i class="bi bi-arrow-repeat"></i> جۆری خەرجی</span>
                </div>
                <div class="ex-panel-body">
                    <label class="form-label">جۆری خەرجی</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="expense_type_selection"
                               id="new_expense" value="new" checked onchange="toggleExpenseType()">
                        <label class="form-check-label" for="new_expense">
                            خەرجی نوێ / یەک جار
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="expense_type_selection"
                               id="recurring_expense" value="recurring" onchange="toggleExpenseType()">
                        <label class="form-check-label" for="recurring_expense">
                            خەرجی دووبارە (لە لیستی هەیە)
                        </label>
                    </div>

                    <div id="recurring_selection" class="mt-3" style="display: none;">
                        <label class="form-label">هەڵبژاردنی خەرجی دووبارە</label>
                        <select class="form-select" id="recurring_type_select" onchange="fillRecurringData()">
                            <option value="">هەڵبژاردنی خەرجی دووبارە...</option>
                            <?php foreach ($recurring_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($type['name']); ?>"
                                        data-description="<?php echo htmlspecialchars($type['description']); ?>"
                                        data-amount="<?php echo htmlspecialchars($type['last_amount'] ?? ''); ?>"
                                        data-currency="<?php echo htmlspecialchars($type['last_currency'] ?? 'IQD'); ?>">
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <section class="ex-panel">
                <div class="ex-panel-head">
                    <span><i class="bi bi-file-earmark-text"></i> زانیاری خەرجی</span>
                </div>
                <div class="ex-panel-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="expense_name">ناوی خەرجی *</label>
                            <input type="text" class="form-control" id="expense_name" name="expense_name"
                                   value="<?php echo htmlspecialchars($expense_name ?? ''); ?>"
                                   placeholder="ناوی خەرجی بنووسە..." required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="amount">بڕی پارە *</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="amount" name="amount"
                                       value="<?php echo htmlspecialchars($amount ?? ''); ?>"
                                       min="0" step="0.001" placeholder="0" required>
                                <select class="form-select flex-grow-0 w-auto" id="currency" name="currency">
                                    <?php $selCur = strtoupper($currency ?? 'IQD') === 'USD' ? 'USD' : 'IQD'; ?>
                                    <option value="IQD" <?php echo $selCur === 'IQD' ? 'selected' : ''; ?>>دینار</option>
                                    <option value="USD" <?php echo $selCur === 'USD' ? 'selected' : ''; ?>>دۆلار $</option>
                                </select>
                            </div>
                            <small class="text-muted">دراوی خەرجی و قەرزەکەی هەڵبژێرە</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="receipt_number">ژمارەی پسوڵە</label>
                            <input type="text" class="form-control" id="receipt_number" name="receipt_number"
                                   value="<?php echo htmlspecialchars($receipt_number ?? ''); ?>"
                                   placeholder="ژمارەی پسوڵە (ئیختیاری)">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="expense_date">بەرواری خەرجی</label>
                            <input type="datetime-local" class="form-control" id="expense_date" name="expense_date"
                                   value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="description">تێبینی</label>
                            <textarea class="form-control" id="description" name="description" rows="3"
                                      placeholder="تێبینی یان وردەکاری زیاتر..."><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ex-panel" id="credit_section" style="display: none;">
                <div class="ex-panel-head">
                    <span><i class="bi bi-credit-card"></i> زانیاری قەرز</span>
                </div>
                <div class="ex-panel-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="creditor_name">ناوی قەرزکار *</label>
                            <input type="text" class="form-control" id="creditor_name" name="creditor_name"
                                   value="<?php echo htmlspecialchars($creditor_name ?? ''); ?>"
                                   placeholder="ناوی کەسی قەرزکار...">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="creditor_phone">ژمارەی تەلەفۆن</label>
                            <input type="text" class="form-control" id="creditor_phone" name="creditor_phone"
                                   value="<?php echo htmlspecialchars($creditor_phone ?? ''); ?>"
                                   placeholder="ژمارەی تەلەفۆنی قەرزکار...">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="due_date">وادەی دانەوە</label>
                            <input type="date" class="form-control" id="due_date" name="due_date"
                                   value="<?php echo htmlspecialchars($due_date ?? ''); ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="payment_terms">مەرجەکانی پارەدانەوە</label>
                            <input type="text" class="form-control" id="payment_terms" name="payment_terms"
                                   value="<?php echo htmlspecialchars($payment_terms ?? ''); ?>"
                                   placeholder="مەرجەکانی پارەدانەوە...">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="credit_notes">تێبینی دەربارەی قەرز</label>
                            <textarea class="form-control" id="credit_notes" name="credit_notes" rows="2"
                                      placeholder="تێبینی یان وردەکاری دەربارەی قەرزەکە..."><?php echo htmlspecialchars($credit_notes ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </section>

            <input type="hidden" id="is_recurring" name="is_recurring" value="0">

            <div class="ex-sticky-actions mb-4">
                <a href="index.php" class="ex-btn ex-btn-ghost">
                    <i class="bi bi-x-lg"></i> پاشگەزبوونەوە
                </a>
                <button type="submit" class="ex-btn ex-btn-primary">
                    <i class="bi bi-check-lg"></i> زیادکردنی خەرجی
                </button>
            </div>
        </form>

        <section class="ex-panel">
            <div class="ex-panel-head">
                <span><i class="bi bi-lightning"></i> خەرجیە باوەکان</span>
            </div>
            <div class="ex-panel-body">
                <div class="ex-quick-grid">
                    <button type="button" class="ex-quick" onclick="quickFill('کڕین کۆکاکۆلا', '', 'cash')">
                        <i class="bi bi-cup-straw"></i>
                        کڕین کۆکاکۆلا
                    </button>
                    <button type="button" class="ex-quick" onclick="quickFill('کرێی دوکان', 'کرێی مانگانەی دوکان', 'credit')">
                        <i class="bi bi-building"></i>
                        کرێی دوکان (قەرز)
                    </button>
                    <button type="button" class="ex-quick" onclick="quickFill('کڕینی سووت', '', 'cash')">
                        <i class="bi bi-fuel-pump"></i>
                        کڕینی سووت
                    </button>
                    <button type="button" class="ex-quick" onclick="quickFill('خەرجی کارمەند', 'مووچەی کارمەندان', 'credit')">
                        <i class="bi bi-people"></i>
                        مووچە (قەرز)
                    </button>
                </div>
            </div>
        </section>
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
                clearForm();
            }
        }

        function toggleCreditFields() {
            const isCredit = document.getElementById('credit').checked;
            const creditSection = document.getElementById('credit_section');
            const creditorName = document.getElementById('creditor_name');
            const walletSection = document.getElementById('wallet_section');
            const walletSelect = document.getElementById('wallet_id');
            
            if (isCredit) {
                creditSection.style.display = 'block';
                creditorName.required = true;
                walletSection.style.display = 'none';
                walletSelect.required = false;
            } else {
                creditSection.style.display = 'none';
                creditorName.required = false;
                walletSection.style.display = 'block';
                walletSelect.required = true;
                // Clear credit fields
                document.getElementById('creditor_name').value = '';
                document.getElementById('creditor_phone').value = '';
                document.getElementById('due_date').value = '';
                document.getElementById('payment_terms').value = '';
                document.getElementById('credit_notes').value = '';
            }
        }

        function fillRecurringData() {
            const select = document.getElementById('recurring_type_select');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                document.getElementById('expense_name').value = selectedOption.dataset.name || '';
                document.getElementById('description').value = selectedOption.dataset.description || '';
                document.getElementById('amount').value = selectedOption.dataset.amount || '';
                if (selectedOption.dataset.currency) {
                    document.getElementById('currency').value = selectedOption.dataset.currency;
                }
            } else {
                clearForm();
            }
        }

        function clearForm() {
            document.getElementById('expense_name').value = '';
            document.getElementById('description').value = '';
            document.getElementById('amount').value = '';
        }

        function quickFill(name, description, paymentMethod) {
            document.getElementById('expense_name').value = name;
            document.getElementById('description').value = description;
            
            // Set payment method
            if (paymentMethod === 'credit') {
                document.getElementById('credit').checked = true;
            } else {
                document.getElementById('cash').checked = true;
            }
            
            toggleCreditFields();
            
            document.getElementById('new_expense').checked = true;
            toggleExpenseType();
            
            // Focus on amount field
            document.getElementById('amount').focus();
        }

        // Auto-calculate and format numbers
        document.getElementById('amount').addEventListener('input', function() {
            const value = parseFloat(this.value);
            if (!isNaN(value) && value > 0) {
                // You can add formatting logic here if needed
            }
        });

        toggleCreditFields();
    </script>
</body>
</html>