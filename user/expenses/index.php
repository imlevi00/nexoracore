<?php
/**
 * بەڕێوەبردنی خەرجیەکانی فرۆشگا (نوێکراوەتەوە) - user/expenses/index.php
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
    'route' => '/user/expenses/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

// فیلتەرکردن
$search = trim($_GET['search'] ?? '');
$payment_method = trim($_GET['payment_method'] ?? '');
$is_recurring = $_GET['is_recurring'] ?? '';
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// دروستکردنی Query
$where_conditions = ["e.user_id = ?"];
$params = [$userId];
$types = 'i';

if (!empty($search)) {
    $where_conditions[] = "(e.expense_name LIKE ? OR e.description LIKE ? OR et.name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= 'sss';
}

if (!empty($payment_method) && in_array($payment_method, ['cash', 'credit'], true)) {
    $where_conditions[] = "e.payment_method = ?";
    $params[] = $payment_method;
    $types .= 's';
}

if ($is_recurring !== '' && ($is_recurring === '0' || $is_recurring === '1')) {
    $where_conditions[] = "e.is_recurring = ?";
    $params[] = (int)$is_recurring;
    $types .= 'i';
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(e.expense_date) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(e.expense_date) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// کۆی گشتی خەرجیەکان
$totals = [
    'total_count' => 0,
    'cash_iqd' => 0,
    'cash_usd' => 0,
    'credit_iqd' => 0,
    'credit_usd' => 0,
    'total_iqd' => 0,
    'total_usd' => 0
];
$total_query = "
    SELECT
        COUNT(*) as total_count,
        SUM(CASE WHEN e.currency = 'IQD' AND e.payment_method = 'cash'   THEN e.amount ELSE 0 END) as cash_iqd,
        SUM(CASE WHEN e.currency = 'USD' AND e.payment_method = 'cash'   THEN e.amount ELSE 0 END) as cash_usd,
        SUM(CASE WHEN e.currency = 'IQD' AND e.payment_method = 'credit' THEN e.amount ELSE 0 END) as credit_iqd,
        SUM(CASE WHEN e.currency = 'USD' AND e.payment_method = 'credit' THEN e.amount ELSE 0 END) as credit_usd,
        SUM(CASE WHEN e.currency = 'IQD' THEN e.amount ELSE 0 END) as total_iqd,
        SUM(CASE WHEN e.currency = 'USD' THEN e.amount ELSE 0 END) as total_usd
    FROM expenses e
    LEFT JOIN expense_types et ON e.expense_type_id = et.id
    $where_clause
";

$stmt = $conn->prepare($total_query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totals_res = $stmt->get_result()->fetch_assoc();
    if ($totals_res) {
        $totals = $totals_res;
    }
    $stmt->close();
}

// ئاماری قەرزەکان
$credit_stats = [
    'total_credits' => 0,
    'total_credit_iqd' => 0,
    'total_credit_usd' => 0,
    'total_remaining_iqd' => 0,
    'total_remaining_usd' => 0,
    'overdue_count' => 0
];
$credit_stats_query = "
    SELECT
        COUNT(ec.id) as total_credits,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.total_amount ELSE 0 END) as total_credit_iqd,
        SUM(CASE WHEN ec.currency = 'USD' THEN ec.total_amount ELSE 0 END) as total_credit_usd,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.remaining_amount ELSE 0 END) as total_remaining_iqd,
        SUM(CASE WHEN ec.currency = 'USD' THEN ec.remaining_amount ELSE 0 END) as total_remaining_usd,
        COUNT(CASE WHEN ec.status = 'overdue' THEN 1 END) as overdue_count
    FROM expenses e
    INNER JOIN expense_credits ec ON e.id = ec.expense_id
    WHERE e.user_id = ? AND e.payment_method = 'credit'
";

$credit_stmt = $conn->prepare($credit_stats_query);
if ($credit_stmt) {
    $credit_stmt->bind_param("i", $userId);
    $credit_stmt->execute();
    $credit_row = $credit_stmt->get_result()->fetch_assoc();
    if ($credit_row) {
        $credit_stats = $credit_row;
    }
    $credit_stmt->close();
}

// خەرجیەکان
$expenses = [];
$expenses_query = "
    SELECT 
        e.*,
        et.name as expense_type_name,
        ec.id as credit_id,
        ec.creditor_name,
        ec.remaining_amount as credit_remaining,
        ec.status as credit_status
    FROM expenses e
    LEFT JOIN expense_types et ON e.expense_type_id = et.id
    LEFT JOIN expense_credits ec ON e.id = ec.expense_id
    $where_clause
    ORDER BY e.expense_date DESC
    LIMIT ? OFFSET ?
";

$exp_stmt = $conn->prepare($expenses_query);
if ($exp_stmt) {
    $exp_types = $types . 'ii';
    $exp_params = array_merge($params, [$limit, $offset]);
    $exp_stmt->bind_param($exp_types, ...$exp_params);
    $exp_stmt->execute();
    $expenses = $exp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $exp_stmt->close();
}

$total_pages = max(1, (int)ceil(($totals['total_count'] ?? 0) / $limit));

// پەیامی سەرکەوتن یان هەڵە
$message = null;
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
    <title>بەڕێوەبردنی خەرجیەکان - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/expenses/expenses-pages.css'); ?>" rel="stylesheet">
</head>
<body class="expenses-module-page expenses-list-page">

    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container py-4 hub-page-content ex-wrap">

        <header class="ex-hero">
            <div>
                <div class="ex-kicker"><i class="bi bi-wallet2"></i> بەشی خەرجیەکان</div>
                <h1><i class="bi bi-receipt"></i> بەڕێوەبردنی خەرجیەکان</h1>
                <p class="ex-hero-sub">لیست، فیلتەر، قەرز و ئاماری خەرجیەکانی فرۆشگا</p>
                <div class="ex-hero-pills">
                    <span class="ex-pill"><i class="bi bi-list-ol"></i> <?php echo number_format($totals['total_count'] ?? 0); ?> خەرجی</span>
                </div>
            </div>
            <div class="ex-actions">
                <a href="statistics.php" class="ex-btn ex-btn-ghost">
                    <i class="bi bi-graph-up"></i> ئامار
                </a>
                <a href="credits/index.php" class="ex-btn ex-btn-warn">
                    <i class="bi bi-credit-card"></i> قەرزەکان
                </a>
                <a href="add.php" class="ex-btn ex-btn-primary">
                    <i class="bi bi-plus-lg"></i> خەرجی نوێ
                </a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
                <?php echo $message['text']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="ex-stats ex-stats-4">
            <div class="ex-stat" style="--stat-accent:#6366f1">
                <div class="ex-stat-icon"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="ex-stat-label">کۆی گشتی</div>
                    <div class="ex-stat-value"><?php echo number_format($totals['total_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($totals['total_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($totals['total_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ex-stat" style="--stat-accent:#10b981">
                <div class="ex-stat-icon"><i class="bi bi-cash"></i></div>
                <div>
                    <div class="ex-stat-label">نەقد</div>
                    <div class="ex-stat-value"><?php echo number_format($totals['cash_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($totals['cash_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($totals['cash_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ex-stat" style="--stat-accent:#f59e0b">
                <div class="ex-stat-icon"><i class="bi bi-credit-card"></i></div>
                <div>
                    <div class="ex-stat-label">قەرز</div>
                    <div class="ex-stat-value"><?php echo number_format($totals['credit_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($totals['credit_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($totals['credit_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ex-stat" style="--stat-accent:#0ea5e9">
                <div class="ex-stat-icon"><i class="bi bi-list-ol"></i></div>
                <div>
                    <div class="ex-stat-label">ژمارەی خەرجیەکان</div>
                    <div class="ex-stat-value"><?php echo number_format($totals['total_count']); ?></div>
                    <div class="ex-stat-meta">خەرجی</div>
                </div>
            </div>
        </div>

        <?php if ($credit_stats['total_credits'] > 0): ?>
        <div class="ex-credit-banner">
            <div>
                <strong><i class="bi bi-exclamation-triangle"></i> کورتەی قەرزەکان</strong>
                <div class="ex-credit-metrics mt-2">
                    <div>
                        <b><?php echo $credit_stats['total_credits']; ?></b>
                        <span>قەرز</span>
                    </div>
                    <div>
                        <b><?php echo formatDualCurrency($credit_stats['total_credit_iqd'] ?? 0, $credit_stats['total_credit_usd'] ?? 0, '<br>'); ?></b>
                        <span>کۆی گشتی</span>
                    </div>
                    <div>
                        <b><?php echo formatDualCurrency($credit_stats['total_remaining_iqd'] ?? 0, $credit_stats['total_remaining_usd'] ?? 0, '<br>'); ?></b>
                        <span>ماوەتەوە</span>
                    </div>
                    <div>
                        <b class="text-danger"><?php echo $credit_stats['overdue_count']; ?></b>
                        <span>درەنگکراو</span>
                    </div>
                </div>
            </div>
            <a href="credits/index.php" class="ex-btn ex-btn-warn">
                <i class="bi bi-credit-card"></i> بەڕێوەبردنی قەرزەکان
            </a>
        </div>
        <?php endif; ?>

        <section class="ex-panel">
            <div class="ex-panel-head">
                <span><i class="bi bi-funnel"></i> فیلتەرکردن</span>
            </div>
            <div class="ex-panel-body">
                <form method="GET" action="">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">گەڕان</label>
                            <input type="text" class="form-control" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="گەڕان لە ناو خەرجیەکان...">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">شێوازی پارەدان</label>
                            <select class="form-select" name="payment_method">
                                <option value="">هەموو</option>
                                <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>نەقد</option>
                                <option value="credit" <?php echo $payment_method === 'credit' ? 'selected' : ''; ?>>قەرز</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">جۆری خەرجی</label>
                            <select class="form-select" name="is_recurring">
                                <option value="">هەموو</option>
                                <option value="1" <?php echo $is_recurring === '1' ? 'selected' : ''; ?>>دووبارە</option>
                                <option value="0" <?php echo $is_recurring === '0' ? 'selected' : ''; ?>>یەک جار</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">لە بەرواری</label>
                            <input type="date" class="form-control" name="date_from"
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">تا بەرواری</label>
                            <input type="date" class="form-control" name="date_to"
                                   value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>

                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="ex-btn ex-btn-primary w-100">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="ex-panel">
            <div class="ex-panel-head">
                <span><i class="bi bi-list"></i> لیستی خەرجیەکان</span>
            </div>
            <div class="ex-panel-body-flush">
                <?php if (empty($expenses)): ?>
                    <div class="ex-empty">
                        <div class="ex-empty-icon"><i class="bi bi-inbox"></i></div>
                        <h3>هیچ خەرجیەک نەدۆزرایەوە</h3>
                        <p>یەکەمین خەرجی زیاد بکە بۆ دەستپێکردن</p>
                        <a href="add.php" class="ex-btn ex-btn-primary mt-2">
                            <i class="bi bi-plus-lg"></i> یەکەمین خەرجی زیاد بکە
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 expenses-list-table">
                            <thead>
                                <tr>
                                    <th>ناوی خەرجی</th>
                                    <th>جۆری خەرجی</th>
                                    <th>بڕی پارە</th>
                                    <th>شێوازی پارەدان</th>
                                    <th>جۆر</th>
                                    <th>بەروار</th>
                                    <th>کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses as $expense): ?>
                                <tr>
                                    <td data-label="ناوی خەرجی">
                                        <strong><?php echo htmlspecialchars($expense['expense_name']); ?></strong>
                                        <?php if (!empty($expense['description'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($expense['description'], 0, 50)); ?><?php echo strlen($expense['description']) > 50 ? '...' : ''; ?></small>
                                        <?php endif; ?>

                                        <?php if ($expense['payment_method'] === 'credit' && !empty($expense['creditor_name'])): ?>
                                            <br><small class="text-warning">
                                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($expense['creditor_name']); ?>
                                                <?php if ($expense['credit_remaining'] > 0): ?>
                                                    - ماوە: <?php echo formatCurrencyAmount($expense['credit_remaining'], $expense['currency'] ?? 'IQD'); ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="جۆری خەرجی">
                                        <?php if ($expense['expense_type_name']): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($expense['expense_type_name']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">یەک جار</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="بڕی پارە">
                                        <?php $exCur = strtoupper($expense['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD'; ?>
                                        <strong class="text-danger"><?php echo number_format($expense['amount'], $exCur === 'USD' ? 2 : 0); ?></strong>
                                        <small class="text-muted d-block"><?php echo $exCur === 'USD' ? 'دۆلار' : 'دینار'; ?></small>
                                    </td>
                                    <td data-label="شێوازی پارەدان">
                                        <?php if ($expense['payment_method'] === 'cash'): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-cash"></i> نەقد
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">
                                                <i class="bi bi-credit-card"></i> قەرز
                                            </span>
                                            <?php if (!empty($expense['credit_status'])): ?>
                                                <br>
                                                <?php if ($expense['credit_status'] === 'completed'): ?>
                                                    <span class="badge bg-success">تەواوبوو</span>
                                                <?php elseif ($expense['credit_status'] === 'overdue'): ?>
                                                    <span class="badge bg-danger">درەنگکراو</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">چالاک</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="جۆر">
                                        <?php if ($expense['is_recurring']): ?>
                                            <span class="badge bg-primary">
                                                <i class="bi bi-arrow-repeat"></i> دووبارە
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <i class="bi bi-check"></i> یەک جار
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="بەروار">
                                        <small class="text-muted"><?php echo date('Y/m/d', strtotime($expense['expense_date'])); ?></small>
                                        <br><small class="text-muted"><?php echo date('H:i', strtotime($expense['expense_date'])); ?></small>
                                    </td>
                                    <td data-label="کردارەکان">
                                        <div class="btn-group btn-group-sm">
                                            <a href="details.php?id=<?php echo $expense['id']; ?>"
                                               class="btn btn-outline-info" title="وردەکاری">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-secondary"
                                                    onclick="printExpenseReceipt(<?php echo $expense['id']; ?>)" title="چاپکردن">
                                                <i class="bi bi-printer"></i>
                                            </button>
                                            <?php if ($expense['payment_method'] === 'credit' && !empty($expense['credit_id'])): ?>
                                                <a href="credits/details.php?id=<?php echo $expense['credit_id']; ?>"
                                                   class="btn btn-outline-warning" title="قەرز">
                                                    <i class="bi bi-credit-card"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="edit.php?id=<?php echo $expense['id']; ?>"
                                               class="btn btn-outline-primary" title="دەستکاریکردن">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger"
                                                    onclick="deleteExpense(<?php echo $expense['id']; ?>)" title="سڕینەوە">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="ex-panel-foot">
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
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
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>ئاگاداری:</strong> ئەگەر ئەم خەرجیە قەرزی لەگەڵدایە، قەرزەکەش دەسڕێتەوە!
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                    <button type="button" class="btn btn-danger" id="confirmDelete">سڕینەوە</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let expenseToDelete = null;

        function printExpenseReceipt(expenseId) {
            window.open('print_receipt.php?id=' + expenseId + '&print=1', '_blank');
        }
        
        function deleteExpense(expenseId) {
            expenseToDelete = expenseId;
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }
        
        document.getElementById('confirmDelete').addEventListener('click', function() {
            if (expenseToDelete) {
                window.location.href = `delete.php?id=${expenseToDelete}`;
            }
        });
    </script>
</body>
</html>