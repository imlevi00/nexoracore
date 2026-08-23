<?php
/**
 * لیستی وەسڵە کڕدراوەکان - user/purchases/index.php
 */

require_once '../../includes/functions.php';
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنەوەی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'purchases.view', [
    'route' => '/user/purchases/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
requireCompaniesModuleAccess();
$userId = $currentUser['id'];

// گەڕان و فلتەرکردن
$search = cleanInput($_GET['search'] ?? '');
$company_filter = (int)($_GET['company_id'] ?? 0);
$payment_type_filter = $_GET['payment_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// دروستکردنی query بۆ گەڕان
$whereConditions = ['pr.user_id = ?'];
$params = [$userId];
$types = 'i';

if (!empty($search)) {
    $whereConditions[] = '(c.name LIKE ? OR pr.receipt_number LIKE ? OR pr.notes LIKE ?)';
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    $types .= 'sss';
}

if ($company_filter) {
    $whereConditions[] = 'pr.company_id = ?';
    $params[] = $company_filter;
    $types .= 'i';
}

if (!empty($payment_type_filter)) {
    $whereConditions[] = 'pr.payment_type = ?';
    $params[] = $payment_type_filter;
    $types .= 's';
}

if (!empty($date_from)) {
    $whereConditions[] = 'pr.receipt_date >= ?';
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $whereConditions[] = 'pr.receipt_date <= ?';
    $params[] = $date_to;
    $types .= 's';
}

if (!empty($status_filter)) {
    $whereConditions[] = 'pr.status = ?';
    $params[] = $status_filter;
    $types .= 's';
}

$whereClause = implode(' AND ', $whereConditions);

// وەرگرتنی کۆی گشتی تۆمارەکان
$countStmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM purchase_receipts pr
    LEFT JOIN companies c ON pr.company_id = c.id
    WHERE $whereClause
");
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $perPage);

// وەرگرتنی وەسڵەکان
$stmt = $conn->prepare("
    SELECT pr.*, c.name as company_name,
           (SELECT COUNT(*) FROM purchase_receipt_items WHERE purchase_receipt_id = pr.id) as items_count,
           CASE 
               WHEN pr.payment_type = 'debt' THEN COALESCE((
                   SELECT SUM(cd.amount) 
                   FROM company_debts cd 
                   WHERE cd.company_id = pr.company_id 
                     AND cd.user_id = pr.user_id 
                     AND cd.type = 'payment' 
                     AND cd.purchase_receipt_id = pr.id
               ), 0)
               ELSE 0 
           END as paid_amount
    FROM purchase_receipts pr
    LEFT JOIN companies c ON pr.company_id = c.id
    WHERE $whereClause
    ORDER BY pr.receipt_date DESC, pr.created_at DESC
    LIMIT ? OFFSET ?
");

// زیادکردنی پیرامیتەرەکانی pagination
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی لیستی کۆمپانیاکان بۆ فلتەر
$companiesStmt = $conn->prepare("SELECT * FROM companies WHERE user_id = ? AND status = 'active' ORDER BY name");
$companiesStmt->bind_param("i", $userId);
$companiesStmt->execute();
$companies = $companiesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ئاماری گشتی (بە بەکارهێنانی هەمان فلتەرەکان)
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) as total_receipts,
        COALESCE(SUM(CASE WHEN pr.currency = 'IQD' THEN final_amount ELSE 0 END), 0) as total_amount_iqd,
        COALESCE(SUM(CASE WHEN pr.currency = 'USD' THEN final_amount ELSE 0 END), 0) as total_amount_usd,
        COALESCE(AVG(CASE WHEN pr.currency = 'IQD' THEN final_amount END), 0) as avg_amount,
        COUNT(DISTINCT company_id) as unique_companies
    FROM purchase_receipts pr
    LEFT JOIN companies c ON pr.company_id = c.id
    WHERE $whereClause
");
// بەکارهێنانی هەمان پارامێتەرەکانی فلتەر
$statsParams = $params;
$statsTypes = $types;
// لابردنی پارامێتەرەکانی pagination لە ئامار
$statsParams = array_slice($statsParams, 0, -2);
$statsTypes = substr($statsTypes, 0, -2);
$statsStmt->bind_param($statsTypes, ...$statsParams);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$pageTitle = 'وەسڵە کڕدراوەکان';
$bodyClass = 'purchases-module-page purchases-list-page';
$additionalCSS = ['purchases-responsive.css', 'purchases/purchases-pages.css'];
include '../../includes/header.php';
?>

<div class="container-fluid py-4 hub-page-content pu-wrap">

    <header class="pu-hero">
        <div>
            <div class="pu-kicker"><i class="bi bi-bag-check"></i> بەشی کڕین</div>
            <h1><i class="bi bi-receipt-cutoff"></i> وەسڵە کڕدراوەکان</h1>
            <p class="pu-hero-sub">لیست، گەڕان و بەڕێوەبردنی وەسڵەکانی کڕین لە کۆمپانیاکان</p>
            <div class="pu-hero-pills">
                <span class="pu-pill"><i class="bi bi-receipt"></i> <?php echo number_format($stats['total_receipts'], 0); ?> وەسڵ</span>
            </div>
        </div>
        <div class="pu-actions">
            <a href="<?php echo url('user/purchases/reports.php'); ?>" class="pu-btn pu-btn-ghost">
                <i class="bi bi-bar-chart-line"></i> ڕاپۆرت
            </a>
            <a href="<?php echo url('user/purchases/product_tracking.php'); ?>" class="pu-btn pu-btn-ghost">
                <i class="bi bi-search"></i> بەدواداچوون
            </a>
            <a href="<?php echo url('user/purchases/add.php'); ?>" class="pu-btn pu-btn-primary">
                <i class="bi bi-plus-lg"></i> وەسڵی نوێ
            </a>
        </div>
    </header>

    <div class="pu-stats pu-stats-4">
        <div class="pu-stat" style="--stat-accent:#0ea5e9">
            <div class="pu-stat-icon"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="pu-stat-label">کۆی وەسڵەکان</div>
                <div class="pu-stat-value"><?php echo number_format($stats['total_receipts'], 0); ?></div>
            </div>
        </div>
        <div class="pu-stat" style="--stat-accent:#10b981">
            <div class="pu-stat-icon"><i class="bi bi-currency-exchange"></i></div>
            <div>
                <div class="pu-stat-label">کۆی گشتی</div>
                <div class="pu-stat-value"><?php echo htmlspecialchars(formatCurrencyAmount((float)($stats['total_amount_iqd'] ?? 0), 'IQD')); ?></div>
                <?php if ((float)($stats['total_amount_usd'] ?? 0) > 0): ?>
                    <div class="pu-stat-meta"><?php echo htmlspecialchars(formatCurrencyAmount((float)$stats['total_amount_usd'], 'USD')); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="pu-stat" style="--stat-accent:#f59e0b">
            <div class="pu-stat-icon"><i class="bi bi-graph-up"></i></div>
            <div>
                <div class="pu-stat-label">ناوەندی وەسڵ</div>
                <div class="pu-stat-value"><?php echo number_format($stats['avg_amount'], 0); ?></div>
            </div>
        </div>
        <div class="pu-stat" style="--stat-accent:#6366f1">
            <div class="pu-stat-icon"><i class="bi bi-building"></i></div>
            <div>
                <div class="pu-stat-label">کۆمپانیاکان</div>
                <div class="pu-stat-value"><?php echo (int)$stats['unique_companies']; ?></div>
            </div>
        </div>
    </div>

    <section class="pu-panel">
        <div class="pu-panel-head">
            <span><i class="bi bi-funnel"></i> گەڕان و فیلتەر</span>
        </div>
        <div class="pu-panel-body">
            <form method="GET" class="purchases-filter-form">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="search" class="form-label">گەڕان</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="ناوی کۆمپانیا، ژمارەی وەسڵ...">
                    </div>
                    <div class="col-md-2">
                        <label for="company_id" class="form-label">کۆمپانیا</label>
                        <select class="form-select" id="company_id" name="company_id">
                            <option value="">هەموو کۆمپانیاکان</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>"
                                        <?php echo ($company_filter == $company['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="payment_type" class="form-label">جۆری پارەدان</label>
                        <select class="form-select" id="payment_type" name="payment_type">
                            <option value="">هەموویان</option>
                            <option value="cash" <?php echo ($payment_type_filter == 'cash') ? 'selected' : ''; ?>>نەقد</option>
                            <option value="debt" <?php echo ($payment_type_filter == 'debt') ? 'selected' : ''; ?>>قەرز</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_from" class="form-label">لە بەروار</label>
                        <input type="date" class="form-control" id="date_from" name="date_from"
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="date_to" class="form-label">تا بەروار</label>
                        <input type="date" class="form-control" id="date_to" name="date_to"
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid gap-2">
                            <button type="submit" class="pu-btn pu-btn-primary">
                                <i class="bi bi-search"></i>
                            </button>
                            <a href="<?php echo url('user/purchases/index.php'); ?>" class="pu-btn pu-btn-ghost">
                                <i class="bi bi-x"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <section class="pu-panel purchases-main-card">
        <div class="pu-panel-head">
            <span><i class="bi bi-list-ul"></i> لیستی وەسڵەکان</span>
        </div>
        <div class="pu-panel-body-flush">
            <?php if (empty($receipts)): ?>
                <div class="pu-empty">
                    <div class="pu-empty-icon"><i class="bi bi-receipt"></i></div>
                    <h3>هیچ وەسڵێک نەدۆزرایەوە</h3>
                    <p>وەسڵێکی نوێ زیاد بکە یان فلتەرەکان بگۆڕە</p>
                    <a href="<?php echo url('user/purchases/add.php'); ?>" class="pu-btn pu-btn-primary mt-2">
                        <i class="bi bi-plus-lg"></i> وەسڵی نوێ
                    </a>
                </div>
            <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover purchases-list-table mb-0">
                            <thead>
                                <tr>
                                    <th>بەروار</th>
                                    <th>کۆمپانیا</th>
                                    <th>ژمارەی وەسڵ</th>
                                    <th>کاڵاکان</th>
                                    <th>جۆری پارەدان</th>
                                    <th>کۆی گشتی</th>
                                    <th>پارەی دراو</th>
                                    <th>ماوەی قەرز</th>
                                    <th>دۆخ</th>
                                    <th>کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                    <?php foreach ($receipts as $receipt):
                                        $paidAmount = (float)($receipt['paid_amount'] ?? 0);
                                        $debtAmount = ($receipt['payment_type'] == 'debt') ? (float)$receipt['final_amount'] : 0;
                                        $remainingAmount = $debtAmount - $paidAmount;
                                        $rowCurrency = (($receipt['currency'] ?? 'IQD') === 'USD') ? 'USD' : 'IQD';
                                    ?>
                                        <tr>
                                            <td data-label="بەروار">
                                                <div class="fw-semibold"><?php echo date('Y-m-d', strtotime($receipt['receipt_date'])); ?></div>
                                                <small class="text-muted"><?php echo date('H:i', strtotime($receipt['created_at'])); ?></small>
                                            </td>
                                            <td data-label="کۆمپانیا">
                                                <div class="fw-semibold"><?php echo htmlspecialchars($receipt['company_name']); ?></div>
                                            </td>
                                            <td data-label="ژمارەی وەسڵ">
                                                <?php if ($receipt['receipt_number']): ?>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($receipt['receipt_number']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="کاڵاکان">
                                                <span class="badge bg-info"><?php echo $receipt['items_count']; ?> کاڵا</span>
                                            </td>
                                            <td data-label="جۆری پارەدان">
                                                <span class="badge bg-<?php echo $receipt['payment_type'] == 'cash' ? 'success' : 'warning'; ?>">
                                                    <?php echo $receipt['payment_type'] == 'cash' ? 'نەقد' : 'قەرز'; ?>
                                                </span>
                                            </td>
                                            <td data-label="کۆی گشتی">
                                                <div class="fw-semibold"><?php echo htmlspecialchars(formatCurrencyAmount($receipt['final_amount'], $rowCurrency)); ?></div>
                                                <small class="badge <?php echo $rowCurrency === 'USD' ? 'text-bg-success' : 'text-bg-light border'; ?>"><?php echo $rowCurrency === 'USD' ? 'دۆلار' : 'دینار'; ?></small>
                                            </td>
                                            <td data-label="پارەی دراو">
                                                <?php if ($receipt['payment_type'] == 'debt'): ?>
                                                    <div class="fw-semibold text-success"><?php echo htmlspecialchars(formatCurrencyAmount($paidAmount, $rowCurrency)); ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="ماوەی قەرز">
                                                <?php if ($receipt['payment_type'] == 'debt'): ?>
                                                    <?php if ($remainingAmount > 0): ?>
                                                        <div class="fw-semibold text-danger"><?php echo htmlspecialchars(formatCurrencyAmount($remainingAmount, $rowCurrency)); ?></div>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">تەواو</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="دۆخ">
                                                <span class="badge bg-<?php 
                                                    echo $receipt['status'] == 'active' ? 'primary' : 
                                                        ($receipt['status'] == 'completed' ? 'success' : 'danger'); 
                                                ?>">
                                                    <?php 
                                                        echo $receipt['status'] == 'active' ? 'چالاک' : 
                                                            ($receipt['status'] == 'completed' ? 'تەواو' : 'هەڵوەشێندراوە'); 
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="purchases-actions-cell" data-label="کردارەکان">
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-info" 
                                                            onclick="viewReceipt(<?php echo $receipt['id']; ?>)" title="بینین">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($receipt['status'] == 'active'): ?>
                                                        <a href="<?php echo url("user/purchases/edit.php?id={$receipt['id']}"); ?>" 
                                                           class="btn btn-sm btn-outline-warning" title="دەستکاری">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($receipt['payment_type'] == 'debt' && $receipt['status'] == 'active' && $receipt['company_id']): ?>
                                                        <a href="<?php echo url("user/purchases/pay_installment.php?id={$receipt['id']}"); ?>" 
                                                           class="btn btn-sm btn-outline-success" title="پارەدانەوەی وەسڵ">
                                                            <i class="bi bi-cash-coin"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($receipt['status'] == 'active'): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteReceipt(<?php echo $receipt['id']; ?>)" title="سڕینەوە">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pu-panel-foot">
                            <nav aria-label="Page navigation">
                                <ul class="pagination justify-content-center flex-wrap mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo url('user/purchases/index.php?' . http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>">
                                            پێشوو
                                        </a>
                                    </li>
                                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo url('user/purchases/index.php?' . http_build_query(array_merge($_GET, ['page' => $i]))); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo url('user/purchases/index.php?' . http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>">
                                            داهاتوو
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>


<script>
function viewReceipt(receiptId) {
    // دەتوانین modal یەک بۆ بینینی وردەکاریەکان زیاد بکەین
    window.open(`<?php echo url('user/purchases/view.php?id='); ?>${receiptId}`, '_blank');
}

function deleteReceipt(receiptId) {
    if (confirm('ئایا دڵنیای لە سڕینەوەی ئەم وەسڵە؟')) {
        fetch('<?php echo url('user/api/delete_receipt.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({receipt_id: receiptId})
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('هەڵە: ' + data.message);
            }
        });
    }
}
</script>

<?php include '../../includes/footer.php'; ?>