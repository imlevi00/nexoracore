<?php
/**
 * Returns Management - user/returns/index.php
 * Main interface for managing product returns
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'returns.view', [
    'route' => '/user/returns/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

// وەرگرتنی پارامیتەرەکان
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// دروستکردنی WHERE clause
$whereConditions = ["r.user_id = $userId"];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(r.return_number LIKE ? OR r.customer_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(r.return_date) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(r.return_date) <= ?";
    $params[] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// وەرگرتنی گەڕاندنەوەکان
$returnsQuery = "
    SELECT r.*, 
           COUNT(ri.id) as item_count,
           SUM(ri.total_price) as total_items_amount
    FROM returns r
    LEFT JOIN return_items ri ON r.id = ri.return_id
    WHERE $whereClause
    GROUP BY r.id
    ORDER BY r.return_date DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $conn->prepare($returnsQuery);
if (!empty($params)) {
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$returns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی کۆی گەڕاندنەوەکان
$countQuery = "
    SELECT COUNT(DISTINCT r.id) as total
    FROM returns r
    WHERE $whereClause
";

$countStmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $types = str_repeat('s', count($params));
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();
$totalReturns = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalReturns / $limit);

$statsQuery = "
    SELECT 
        COUNT(*) as total_returns,
        IFNULL(SUM(final_amount), 0) as total_amount,
        COUNT(DISTINCT DATE(return_date)) as days_with_returns,
        IFNULL(AVG(final_amount), 0) as average_return,
        IFNULL(SUM(CASE WHEN payment_method = 'cash' THEN final_amount ELSE 0 END), 0) as cash_amount,
        IFNULL(SUM(CASE WHEN payment_method = 'debt' THEN final_amount ELSE 0 END), 0) as debt_amount,
        IFNULL(SUM(CASE WHEN payment_method = 'installment' THEN final_amount ELSE 0 END), 0) as installment_amount
    FROM returns 
    WHERE user_id = $userId
";

$statsResult = $conn->query($statsQuery);
$stats = $statsResult->fetch_assoc();

$statsTotalAmount = (float)($stats['total_amount'] ?? 0);
$statsCash = (float)($stats['cash_amount'] ?? 0);
$statsDebt = (float)($stats['debt_amount'] ?? 0);
$statsInstallment = (float)($stats['installment_amount'] ?? 0);
$cashPct = $statsTotalAmount > 0 ? (int) round(($statsCash / $statsTotalAmount) * 100) : 0;
$debtPct = $statsTotalAmount > 0 ? (int) round(($statsDebt / $statsTotalAmount) * 100) : 0;
$installmentPct = $statsTotalAmount > 0 ? (int) round(($statsInstallment / $statsTotalAmount) * 100) : 0;

if ($dateFrom && $dateTo) {
    $periodLabel = date('d/m/Y', strtotime($dateFrom)) . ' – ' . date('d/m/Y', strtotime($dateTo));
} elseif ($dateFrom) {
    $periodLabel = 'لە ' . date('d/m/Y', strtotime($dateFrom));
} elseif ($dateTo) {
    $periodLabel = 'تا ' . date('d/m/Y', strtotime($dateTo));
} else {
    $periodLabel = 'هەموو ماوەکان';
}

$todayYmd = date('Y-m-d');
$yesterdayYmd = date('Y-m-d', strtotime('-1 day'));
$weekAgoYmd = date('Y-m-d', strtotime('-7 days'));
$monthStartYmd = date('Y-m-01');
$quickRange = '';
if ($dateFrom === $todayYmd && $dateTo === $todayYmd) {
    $quickRange = 'today';
} elseif ($dateFrom === $yesterdayYmd && $dateTo === $yesterdayYmd) {
    $quickRange = 'yesterday';
} elseif ($dateFrom === $weekAgoYmd && $dateTo === $todayYmd) {
    $quickRange = 'week';
} elseif ($dateFrom === $monthStartYmd && $dateTo === $todayYmd) {
    $quickRange = 'month';
}

$filtersActive = $search !== '' || $dateFrom !== '' || $dateTo !== '';

$pageTitle = 'بەڕێوەبردنی گەڕاندنەوەکان';
$bodyClass = 'returns-module-page returns-list-page';
$additionalCSS = ['returns-list.css', 'returns-responsive.css'];

include '../../includes/header.php';
?>

<div class="container-fluid py-4 returns-page-content rl-wrap">
    <header class="rl-hero">
        <div class="rl-hero-main">
            <div class="rl-kicker"><i class="bi bi-arrow-return-left"></i> گەڕاندنەوە</div>
            <h1><i class="bi bi-box-arrow-in-left"></i> <?php echo htmlspecialchars($pageTitle); ?></h1>
            <p class="rl-hero-sub">لیستی گەڕێندراوەکان، گەڕان و ئامار لە یەک شوێن</p>
            <div class="rl-hero-pills">
                <span class="rl-pill"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($periodLabel); ?></span>
                <span class="rl-pill"><i class="bi bi-receipt"></i> <?php echo number_format($totalReturns); ?> گەڕاندنەوە</span>
                <span class="rl-pill"><i class="bi bi-calendar-check"></i> <?php echo number_format((int)($stats['days_with_returns'] ?? 0)); ?> ڕۆژ</span>
            </div>
        </div>
        <div class="rl-hero-actions">
            <a href="<?php echo url('user/pos/main.php'); ?>" class="rl-btn rl-btn-ghost">
                <i class="bi bi-arrow-right"></i> بەشی فرۆشتن
            </a>
            <a href="add.php" class="rl-btn rl-btn-primary">
                <i class="bi bi-plus-lg"></i> گەڕاندنەوەی نوێ
            </a>
        </div>
    </header>

    <section class="rl-stats">
        <div class="rl-stat rl-stat-count">
            <div class="rl-stat-icon"><i class="bi bi-arrow-return-left"></i></div>
            <div class="rl-stat-body">
                <div class="rl-stat-label">کۆی گەڕاندنەوەکان</div>
                <div class="rl-stat-value"><?php echo number_format((int)($stats['total_returns'] ?? 0)); ?></div>
                <div class="rl-stat-meta">هەموو کات</div>
            </div>
        </div>
        <div class="rl-stat rl-stat-amount">
            <div class="rl-stat-icon"><i class="bi bi-currency-exchange"></i></div>
            <div class="rl-stat-body">
                <div class="rl-stat-label">کۆی بڕی گەڕاندنەوە</div>
                <div class="rl-stat-value"><?php echo number_format($statsTotalAmount, 0); ?></div>
                <div class="rl-stat-meta">دینار</div>
            </div>
        </div>
        <div class="rl-stat rl-stat-avg">
            <div class="rl-stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
            <div class="rl-stat-body">
                <div class="rl-stat-label">ناوەندی گەڕاندنەوە</div>
                <div class="rl-stat-value"><?php echo number_format((float)($stats['average_return'] ?? 0), 0); ?></div>
                <div class="rl-stat-meta">دینار بۆ هەر وەسڵێک</div>
            </div>
        </div>
        <div class="rl-stat rl-stat-mix">
            <div class="rl-stat-icon"><i class="bi bi-pie-chart"></i></div>
            <div class="rl-stat-body">
                <div class="rl-stat-label">شێوازی پارەدان</div>
                <div class="rl-stat-value"><?php echo $cashPct; ?>% نەقد</div>
                <div class="rl-mix-bar" aria-hidden="true">
                    <span class="rl-mix-cash" style="width: <?php echo (int) $cashPct; ?>%"></span>
                    <span class="rl-mix-debt" style="width: <?php echo (int) $debtPct; ?>%"></span>
                    <?php if ($installmentPct > 0): ?>
                        <span class="rl-mix-installment" style="width: <?php echo (int) $installmentPct; ?>%"></span>
                    <?php endif; ?>
                </div>
                <div class="rl-mix-legend">
                    <span>قەرز <b><?php echo $debtPct; ?>%</b></span>
                    <?php if ($installmentPct > 0): ?>
                        <span>قسە <b><?php echo $installmentPct; ?>%</b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="card rl-filter-card">
        <div class="card-body">
            <div class="rl-filter-head">
                <h2 class="rl-filter-title"><i class="bi bi-funnel"></i> فلتەر و گەڕان</h2>
                <div class="rl-chips">
                    <button type="button" class="rl-chip<?php echo $quickRange === 'today' ? ' is-active' : ''; ?>" onclick="setReturnDateRange('today')">ئەمڕۆ</button>
                    <button type="button" class="rl-chip<?php echo $quickRange === 'yesterday' ? ' is-active' : ''; ?>" onclick="setReturnDateRange('yesterday')">دوێنێ</button>
                    <button type="button" class="rl-chip<?php echo $quickRange === 'week' ? ' is-active' : ''; ?>" onclick="setReturnDateRange('week')">٧ ڕۆژی ڕابردوو</button>
                    <button type="button" class="rl-chip<?php echo $quickRange === 'month' ? ' is-active' : ''; ?>" onclick="setReturnDateRange('month')">ئەم مانگە</button>
                    <?php if ($filtersActive): ?>
                        <a class="rl-chip rl-chip-reset" href="<?php echo url('user/returns/index.php'); ?>">
                            <i class="bi bi-x-lg"></i> پاککردنەوە
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <form method="GET" class="row g-3 returns-filter-form">
                <div class="col-md-4 col-lg-4">
                    <label for="search" class="form-label">گەڕان</label>
                    <input type="text" class="form-control" id="search" name="search"
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="ژمارەی گەڕاندنەوە یان ناوی کڕیار">
                </div>
                <div class="col-md-3 col-lg-3">
                    <label for="date_from" class="form-label">لە بەروار</label>
                    <input type="date" class="form-control" id="date_from" name="date_from"
                           value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-3 col-lg-3">
                    <label for="date_to" class="form-label">بۆ بەروار</label>
                    <input type="date" class="form-control" id="date_to" name="date_to"
                           value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-2 col-lg-2">
                    <label class="form-label d-none d-md-block">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> گەڕان
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card rl-table-card">
        <div class="rl-table-head">
            <h2><i class="bi bi-list-ul"></i> لیستی گەڕێندراوەکان</h2>
            <span class="rl-count-badge"><?php echo number_format($totalReturns); ?> گەڕاندنەوە</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover returns-list-table mb-0">
                    <thead>
                        <tr>
                            <th>ژمارەی گەڕاندنەوە</th>
                            <th>کڕیار</th>
                            <th>بەروار</th>
                            <th>کۆی کاڵاکان</th>
                            <th>کۆی بڕ</th>
                            <th>ڕێگەی پارەدان</th>
                            <th>کردارەکان</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($returns)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="rl-empty">
                                        <div class="rl-empty-icon"><i class="bi bi-inbox"></i></div>
                                        <h3>هیچ گەڕاندنەوەیەک نەدۆزرایەوە</h3>
                                        <p>فلتەرەکان بگۆڕە یان گەڕاندنەوەیەکی نوێ تۆمار بکە</p>
                                        <a href="add.php" class="rl-btn rl-btn-primary">
                                            <i class="bi bi-plus-lg"></i> گەڕاندنەوەی نوێ
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($returns as $return): ?>
                                <?php
                                $customerName = trim((string)($return['customer_name'] ?? ''));
                                $customerDisplay = $customerName !== '' ? $customerName : 'کڕیاری گشتی';
                                $customerInitial = function_exists('mb_substr')
                                    ? mb_substr($customerDisplay, 0, 1, 'UTF-8')
                                    : substr($customerDisplay, 0, 1);
                                $avatarHue = abs(crc32($customerDisplay)) % 360;
                                $returnTs = strtotime($return['return_date']);
                                $payMethod = (string)($return['payment_method'] ?? '');
                                if ($payMethod === 'cash') {
                                    $paymentBadge = '<span class="rl-pay rl-pay-cash"><i class="bi bi-cash-stack"></i> نەقد</span>';
                                } elseif ($payMethod === 'debt') {
                                    $paymentBadge = '<span class="rl-pay rl-pay-debt"><i class="bi bi-clock-history"></i> قەرز</span>';
                                } elseif ($payMethod === 'installment') {
                                    $paymentBadge = '<span class="rl-pay rl-pay-installment"><i class="bi bi-calendar2-week"></i> قسە</span>';
                                } else {
                                    $paymentBadge = '<span class="rl-pay rl-pay-other">' . htmlspecialchars($payMethod) . '</span>';
                                }
                                ?>
                                <tr>
                                    <td data-label="ژمارەی گەڕاندنەوە">
                                        <span class="rl-invoice"><?php echo htmlspecialchars($return['return_number']); ?></span>
                                    </td>
                                    <td data-label="کڕیار">
                                        <div class="rl-customer">
                                            <span class="rl-avatar" style="--hue: <?php echo (int) $avatarHue; ?>"><?php echo htmlspecialchars($customerInitial); ?></span>
                                            <span class="rl-customer-name"><?php echo htmlspecialchars($customerDisplay); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="بەروار">
                                        <div class="rl-datetime">
                                            <span class="rl-date"><?php echo $returnTs ? date('d/m/Y', $returnTs) : '-'; ?></span>
                                            <span class="rl-time"><?php echo $returnTs ? date('H:i', $returnTs) : ''; ?></span>
                                        </div>
                                    </td>
                                    <td data-label="کۆی کاڵاکان">
                                        <span class="rl-item-count"><?php echo (int) $return['item_count']; ?> کاڵا</span>
                                    </td>
                                    <td data-label="کۆی بڕ">
                                        <span class="rl-amount"><?php echo number_format((float)$return['final_amount']); ?> دینار</span>
                                    </td>
                                    <td data-label="ڕێگەی پارەدان">
                                        <?php echo $paymentBadge; ?>
                                    </td>
                                    <td data-label="کردارەکان">
                                        <div class="returns-row-actions">
                                            <a href="view.php?id=<?php echo (int) $return['id']; ?>"
                                               class="btn btn-outline-primary" title="بینین">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="print.php?id=<?php echo (int) $return['id']; ?>"
                                               class="btn btn-outline-success" title="چاپکردن" target="_blank">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="rl-pagination" aria-label="پەڕەکان">
            <div class="rl-page-info">پەڕەی <?php echo (int) $page; ?> لە <?php echo (int) $totalPages; ?></div>
            <ul class="pagination justify-content-center mb-0">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">
                            پێشوو
                        </a>
                    </li>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">
                            داهاتوو
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<script>
function setReturnDateRange(range) {
    const today = new Date();
    let startDate, endDate = today;

    switch (range) {
        case 'today':
            startDate = today;
            break;
        case 'yesterday':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 1);
            endDate = startDate;
            break;
        case 'week':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 7);
            break;
        case 'month':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            break;
        default:
            return;
    }

    const toYmd = (d) => {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    };

    const fromInput = document.getElementById('date_from');
    const toInput = document.getElementById('date_to');
    if (fromInput) fromInput.value = toYmd(startDate);
    if (toInput) toInput.value = toYmd(endDate);

    const form = document.querySelector('.returns-filter-form');
    if (form) form.submit();
}
</script>

<?php include '../../includes/footer.php'; ?>
