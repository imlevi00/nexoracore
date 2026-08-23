<?php
/**
 * بەڕێوەبردنی قەرزەکانی خەرجیەکان - user/expenses/credits/index.php
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
    'route' => '/user/expenses/credits/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireExpensesModuleAccess();
$userId = (int)$currentUser['id'];

// نوێکردنەوەی status بۆ قەرزە درەنگکراوەکان
$upd_stmt = $conn->prepare("
    UPDATE expense_credits 
    SET status = 'overdue' 
    WHERE status IN ('active', 'pending')
    AND due_date IS NOT NULL 
    AND due_date < CURDATE() 
    AND remaining_amount > 0
    AND user_id = ?
");
if ($upd_stmt) {
    $upd_stmt->bind_param("i", $userId);
    $upd_stmt->execute();
    $upd_stmt->close();
}

// فیلتەرکردن
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// دروستکردنی Query
$where_conditions = ["ec.user_id = ?"];
$params = [$userId];
$types = 'i';

if (!empty($search)) {
    $where_conditions[] = "(ec.creditor_name LIKE ? OR ec.creditor_phone LIKE ? OR e.expense_name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= 'sss';
}

if (!empty($status)) {
    $where_conditions[] = "ec.status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(ec.due_date) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(ec.due_date) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// کۆی گشتی قەرزەکان
$totals = [
    'total_count' => 0,
    'total_iqd' => 0,
    'total_usd' => 0,
    'paid_iqd' => 0,
    'paid_usd' => 0,
    'active_iqd' => 0,
    'active_usd' => 0,
    'overdue_iqd' => 0,
    'overdue_usd' => 0
];
$total_query = "
    SELECT
        COUNT(*) as total_count,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.total_amount ELSE 0 END) as total_iqd,
        SUM(CASE WHEN ec.currency = 'USD' THEN ec.total_amount ELSE 0 END) as total_usd,
        SUM(CASE WHEN ec.currency = 'IQD' THEN ec.paid_amount ELSE 0 END) as paid_iqd,
        SUM(CASE WHEN ec.currency = 'USD' THEN ec.paid_amount ELSE 0 END) as paid_usd,
        SUM(CASE WHEN ec.currency = 'IQD' AND ec.status IN ('active', 'pending') THEN ec.remaining_amount ELSE 0 END) as active_iqd,
        SUM(CASE WHEN ec.currency = 'USD' AND ec.status IN ('active', 'pending') THEN ec.remaining_amount ELSE 0 END) as active_usd,
        SUM(CASE WHEN ec.currency = 'IQD' AND ec.status = 'overdue' THEN ec.remaining_amount ELSE 0 END) as overdue_iqd,
        SUM(CASE WHEN ec.currency = 'USD' AND ec.status = 'overdue' THEN ec.remaining_amount ELSE 0 END) as overdue_usd
    FROM expense_credits ec
    LEFT JOIN expenses e ON ec.expense_id = e.id
    $where_clause
";

$stmt = $conn->prepare($total_query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $tot_row = $stmt->get_result()->fetch_assoc();
    if ($tot_row) {
        $totals = $tot_row;
    }
    $stmt->close();
}

// قەرزەکان
$credits = [];
$credits_query = "
    SELECT 
        ec.*,
        e.expense_name,
        e.amount as expense_amount,
        (SELECT COUNT(*) FROM expense_credit_payments WHERE expense_credit_id = ec.id) as payment_count
    FROM expense_credits ec
    LEFT JOIN expenses e ON ec.expense_id = e.id
    $where_clause
    ORDER BY 
        CASE WHEN ec.status = 'overdue' THEN 1 
             WHEN ec.status IN ('active', 'pending') THEN 2 
             ELSE 3 END,
        ec.due_date ASC
    LIMIT ? OFFSET ?
";

$cred_stmt = $conn->prepare($credits_query);
if ($cred_stmt) {
    $cred_types = $types . 'ii';
    $cred_params = array_merge($params, [$limit, $offset]);
    $cred_stmt->bind_param($cred_types, ...$cred_params);
    $cred_stmt->execute();
    $credits = $cred_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cred_stmt->close();
}

$total_pages = max(1, (int)ceil(($totals['total_count'] ?? 0) / $limit));
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>بەڕێوەبردنی قەرزەکان - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/expenses/expenses-pages.css'); ?>" rel="stylesheet">
</head>
<body class="expenses-module-page expenses-credits-page">

    <?php include_once '../../../includes/navigation.php'; ?>

    <div class="container py-4 ex-wrap">

        <header class="ex-hero">
            <div>
                <div class="ex-kicker"><i class="bi bi-credit-card-2-back"></i> قەرزی خەرجی</div>
                <h1><i class="bi bi-credit-card"></i> بەڕێوەبردنی قەرزەکان</h1>
                <p class="ex-hero-sub">قەرزی چالاک، دراوەتەوە، ماوەتەوە و درەنگکراو لە یەک شوێن</p>
            </div>
            <div class="ex-actions">
                <a href="../index.php" class="ex-btn ex-btn-ghost">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ خەرجیەکان
                </a>
                <a href="statistics.php" class="ex-btn ex-btn-success">
                    <i class="bi bi-graph-up"></i> ئامار
                </a>
            </div>
        </header>

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
                <div class="ex-stat-icon"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="ex-stat-label">دراوەتەوە</div>
                    <div class="ex-stat-value"><?php echo number_format($totals['paid_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($totals['paid_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($totals['paid_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ex-stat" style="--stat-accent:#f59e0b">
                <div class="ex-stat-icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="ex-stat-label">ماوەتەوە</div>
                    <div class="ex-stat-value"><?php echo number_format($totals['active_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($totals['active_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($totals['active_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ex-stat" style="--stat-accent:#ef4444">
                <div class="ex-stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="ex-stat-label">درەنگکراو</div>
                    <div class="ex-stat-value"><?php echo number_format($totals['overdue_iqd'] ?? 0, 0); ?> دینار</div>
                    <?php if (($totals['overdue_usd'] ?? 0) != 0): ?>
                        <div class="ex-stat-meta"><?php echo formatCurrencyAmount($totals['overdue_usd'], 'USD'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

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
                                   placeholder="گەڕان لە ناو قەرزەکان...">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">بارودۆخ</label>
                            <select class="form-select" name="status">
                                <option value="">هەموو</option>
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>چالاک</option>
                                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>تەواوبوو</option>
                                <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>درەنگکراو</option>
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

                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <a href="?" class="ex-btn ex-btn-ghost w-100">
                                    <i class="bi bi-arrow-clockwise"></i> ڕیسێت
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="ex-panel">
            <div class="ex-panel-head">
                <span><i class="bi bi-list"></i> لیستی قەرزەکان</span>
            </div>
            <div class="ex-panel-body-flush">
                <?php if (empty($credits)): ?>
                    <div class="ex-empty">
                        <div class="ex-empty-icon"><i class="bi bi-inbox"></i></div>
                        <h3>هیچ قەرزێک نەدۆزرایەوە</h3>
                        <p>یەکەمین خەرجی بە قەرز زیاد بکە</p>
                        <a href="../index.php" class="ex-btn ex-btn-primary mt-2">
                            <i class="bi bi-plus-lg"></i> یەکەمین خەرجی بە قەرز زیاد بکە
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 expenses-credits-table">
                            <thead>
                                <tr>
                                    <th>خەرجی / قەرزکار</th>
                                    <th>بڕی قەرز</th>
                                    <th>دراوەتەوە</th>
                                    <th>ماوەتەوە</th>
                                    <th>وادەی دانەوە</th>
                                    <th>بارودۆخ</th>
                                    <th>کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($credits as $credit): ?>
                                <tr class="<?php echo $credit['status'] === 'overdue' ? 'ex-row-overdue' : ($credit['status'] === 'completed' ? 'ex-row-done' : ''); ?>">
                                    <td data-label="خەرجی / قەرزکار">
                                        <div>
                                            <strong><?php echo htmlspecialchars($credit['expense_name']); ?></strong>
                                            <br><small class="text-muted">قەرزکار: <?php echo htmlspecialchars($credit['creditor_name']); ?></small>
                                            <?php if (!empty($credit['creditor_phone'])): ?>
                                                <br><small class="text-muted">
                                                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($credit['creditor_phone']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php $crCur = strtoupper($credit['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD'; ?>
                                    <td data-label="بڕی قەرز">
                                        <strong class="text-primary"><?php echo formatCurrencyAmount($credit['total_amount'], $crCur); ?></strong>
                                    </td>
                                    <td data-label="دراوەتەوە">
                                        <span class="text-success"><?php echo formatCurrencyAmount($credit['paid_amount'], $crCur); ?></span>
                                        <?php if ($credit['payment_count'] > 0): ?>
                                            <small class="badge bg-info d-block mt-1"><?php echo $credit['payment_count']; ?> پارەدانەوە</small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="ماوەتەوە">
                                        <strong class="text-danger"><?php echo formatCurrencyAmount($credit['remaining_amount'], $crCur); ?></strong>
                                    </td>
                                    <td data-label="وادەی دانەوە">
                                        <?php if (!empty($credit['due_date'])): ?>
                                            <small class="text-muted"><?php echo date('Y/m/d', strtotime($credit['due_date'])); ?></small>
                                            <?php
                                            $due_date = new DateTime($credit['due_date']);
                                            $today = new DateTime();
                                            $diff = $today->diff($due_date);
                                            ?>
                                            <br>
                                            <?php if ($credit['status'] === 'overdue'): ?>
                                                <small class="text-danger">درەنگ بە <?php echo $diff->days; ?> ڕۆژ</small>
                                            <?php elseif ($diff->days <= 7 && $due_date > $today): ?>
                                                <small class="text-warning"><?php echo $diff->days; ?> ڕۆژ ماوە</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="بارودۆخ">
                                        <?php if ($credit['status'] === 'active'): ?>
                                            <span class="badge bg-warning">چالاک</span>
                                        <?php elseif ($credit['status'] === 'completed'): ?>
                                            <span class="badge bg-success">تەواوبوو</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">درەنگکراو</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="کردارەکان">
                                        <div class="btn-group btn-group-sm">
                                            <a href="details.php?id=<?php echo $credit['id']; ?>"
                                               class="btn btn-outline-info" title="وردەکاری">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($credit['remaining_amount'] > 0): ?>
                                                <a href="payment.php?id=<?php echo $credit['id']; ?>"
                                                   class="btn btn-outline-success" title="پارەدانەوە">
                                                    <i class="bi bi-cash"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="edit.php?id=<?php echo $credit['id']; ?>"
                                               class="btn btn-outline-primary" title="دەستکاریکردن">
                                                <i class="bi bi-pencil"></i>
                                            </a>
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

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>