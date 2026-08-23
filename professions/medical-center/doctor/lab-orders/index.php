<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/lab/includes/lab_order_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];

$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$orderIdFromBarcode = $search !== '' ? labParseOrderBarcode($search) : null;
if ($orderIdFromBarcode !== null) {
    $hit = labFetchOrder($conn_kasher_platform, $userId, $orderIdFromBarcode, $doctorId);
    if ($hit) {
        redirect(url('professions/medical-center/doctor/lab-orders/view.php?id=' . $orderIdFromBarcode));
    }
}

$result = labFetchDoctorOrders(
    $conn_kasher_platform,
    $userId,
    $doctorId,
    null,
    $search !== '' ? $search : null,
    $perPage,
    $offset
);
$orders = $result['rows'];
$totalOrders = $result['total'];
$totalPages = max(1, (int)ceil($totalOrders / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$baseQuery = [];
if ($search !== '') {
    $baseQuery['q'] = $search;
}

$statusCounts = ['total' => 0, 'pending' => 0, 'completed' => 0];
$countStmt = $conn_kasher_platform->prepare("
    SELECT status, COUNT(*) AS cnt FROM lab_orders
    WHERE user_id = ? AND doctor_id = ?
    GROUP BY status
");
if ($countStmt) {
    $countStmt->bind_param('ii', $userId, $doctorId);
    $countStmt->execute();
    $countRows = $countStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $countStmt->close();
    foreach ($countRows as $cr) {
        $cnt = (int)$cr['cnt'];
        $statusCounts['total'] += $cnt;
        $st = (string)$cr['status'];
        if ($st === 'pending') {
            $statusCounts['pending'] += $cnt;
        } elseif (in_array($st, ['completed', 'done', 'ready'], true)) {
            $statusCounts['completed'] += $cnt;
        }
    }
}

$doctorUiLocale = 'en';
$pageTitle = 'Lab Orders';
$activeNav = 'lab_orders';
$bodyClass = 'doctor-smart-clinic-page';
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="sc-page">
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title">Lab Orders</h1>
            <p class="sc-page-subtitle">Manage laboratory test orders</p>
        </div>
        <a href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php'); ?>" class="btn sc-btn-primary btn-sm">
            <i class="bi bi-plus-circle"></i> New order
        </a>
    </div>

    <div class="sc-stat-bar">
        <div class="sc-stat-card sc-stat-total">
            <span class="sc-stat-value"><?php echo $statusCounts['total']; ?></span>
            <span class="sc-stat-label">Total</span>
        </div>
        <div class="sc-stat-card sc-stat-pending">
            <span class="sc-stat-value"><?php echo $statusCounts['pending']; ?></span>
            <span class="sc-stat-label">Pending</span>
        </div>
        <div class="sc-stat-card sc-stat-completed">
            <span class="sc-stat-value"><?php echo $statusCounts['completed']; ?></span>
            <span class="sc-stat-label">Completed</span>
        </div>
    </div>

    <form method="get" class="sc-filter-bar">
        <div class="sc-filter-row">
            <div class="sc-filter-field flex-grow-1">
                <label for="filter-search">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                    <input type="search" name="q" id="filter-search" class="form-control"
                           placeholder="Name, mobile, or receipt barcode..."
                           value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" autofocus>
                </div>
            </div>
            <div class="sc-filter-actions">
                <button type="submit" class="btn sc-btn-primary btn-sm"><i class="bi bi-search"></i> Search</button>
                <?php if ($search !== ''): ?>
                    <a href="<?php echo url('professions/medical-center/doctor/lab-orders/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if (empty($orders)): ?>
        <div class="sc-content-card">
            <div class="sc-empty">
                <i class="bi bi-clipboard2-pulse"></i>
                <h6 class="mt-2 mb-1">No orders yet</h6>
                <p class="text-body-secondary mb-3">Create your first lab order</p>
                <a href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php'); ?>" class="btn sc-btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> New order
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="sc-content-card">
            <div class="sc-content-body">
                <div class="sc-table-wrap">
                    <table class="sc-table">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Patient</th>
                            <th>Tests</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo (int)$order['id']; ?></td>
                                <td>
                                    <?php echo htmlspecialchars((string)$order['patient_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($order['patient_mobile'])): ?>
                                        <div class="text-body-secondary small"><?php echo htmlspecialchars((string)$order['patient_mobile'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$order['test_count']; ?></td>
                                <td>
                                    <span class="badge text-bg-<?php echo labOrderStatusBadge((string)$order['status']); ?>">
                                        <?php echo htmlspecialchars(labOrderStatusLabel((string)$order['status'], 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="text-body-secondary small"><?php echo htmlspecialchars((string)$order['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <a href="<?php echo url('professions/medical-center/doctor/lab-orders/view.php?id=' . (int)$order['id']); ?>" class="sc-action-btn sc-action-primary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="sc-pagination">
                    <span class="text-body-secondary">
                        <?php echo (int)$totalOrders; ?> order<?php echo $totalOrders === 1 ? '' : 's'; ?> — Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?>
                    </span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $pageQuery = $baseQuery;
                            if ($page > 1):
                                $pageQuery['page'] = $page - 1;
                            ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo url('professions/medical-center/doctor/lab-orders/index.php?' . http_build_query($pageQuery)); ?>">Previous</a>
                                </li>
                            <?php endif; ?>
                            <?php if ($page < $totalPages):
                                $pageQuery['page'] = $page + 1;
                            ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo url('professions/medical-center/doctor/lab-orders/index.php?' . http_build_query($pageQuery)); ?>">Next</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
