<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_order_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];

$statusFilter = (string)($_GET['status'] ?? '');
$validStatuses = array_keys(labOrderStatuses());
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$orderIdFromBarcode = $search !== '' ? labParseOrderBarcode($search) : null;
if ($orderIdFromBarcode !== null) {
    $hit = labFetchOrder($conn_kasher_platform, $userId, $orderIdFromBarcode);
    if ($hit && (int)$hit['lab_id'] === $labId) {
        redirect(url('professions/medical-center/lab/orders/fill.php?id=' . $orderIdFromBarcode));
    }
}

$result = labFetchOrders(
    $conn_kasher_platform,
    $userId,
    $labId,
    $statusFilter !== '' ? $statusFilter : null,
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
if ($statusFilter !== '') {
    $baseQuery['status'] = $statusFilter;
}
if ($search !== '') {
    $baseQuery['q'] = $search;
}

$pageTitle = 'داواکاریەکانی تاقیگە';
$activeNav = 'orders';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h4 class="mb-0">داواکاریەکان</h4>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <a href="<?php echo url('professions/medical-center/lab/orders/create.php'); ?>" class="btn btn-success btn-sm">
            <i class="bi bi-plus-circle"></i> داواکاری نوێ
        </a>
        <div class="btn-group btn-group-sm" role="group">
        <a href="<?php echo url('professions/medical-center/lab/orders/index.php' . ($search !== '' ? '?q=' . urlencode($search) : '')); ?>" class="btn btn-outline-secondary<?php echo $statusFilter === '' ? ' active' : ''; ?>">هەموو</a>
        <?php foreach (labOrderStatuses() as $key => $meta): ?>
            <?php
            $filterQuery = $search !== '' ? ['q' => $search, 'status' => $key] : ['status' => $key];
            ?>
            <a href="<?php echo url('professions/medical-center/lab/orders/index.php?' . http_build_query($filterQuery)); ?>"
               class="btn btn-outline-secondary<?php echo $statusFilter === $key ? ' active' : ''; ?>">
                <?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<form method="get" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-center">
            <?php if ($statusFilter !== ''): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <div class="col-12 col-md-8">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                    <input type="search" name="q" class="form-control" placeholder="گەڕان بە ناو، مۆبایل، یان بارکۆدی وەسڵ..."
                           value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" autofocus>
                </div>
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> گەڕان</button>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="<?php echo url('professions/medical-center/lab/orders/index.php'); ?>" class="btn btn-outline-secondary">پاککردنەوە</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php if (empty($orders)): ?>
    <div class="lab-empty-hint">
        <i class="bi bi-inbox text-primary lab-dash-icon"></i>
        <h6 class="mt-2 mb-1">هیچ داواکارییەک نییە</h6>
        <p class="mb-3">داواکاری لە لایەن دکتۆرەوە دێت، یان دەتوانیت نەخۆشی دەرەکی تۆمار بکەیت</p>
        <a href="<?php echo url('professions/medical-center/lab/orders/create.php'); ?>" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> داواکاری نوێ
        </a>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>فەحس</th>
                        <th>دۆخ</th>
                        <th>بەروار</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?php echo (int)$order['id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars((string)$order['patient_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (($order['order_source'] ?? '') === 'direct'): ?>
                                    <span class="badge text-bg-secondary ms-1">دەرەکی</span>
                                <?php endif; ?>
                                <div class="text-body-secondary small"><?php echo htmlspecialchars((string)$order['patient_mobile'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars(labOrderDoctorDisplay($order), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int)$order['test_count']; ?></td>
                            <td><span class="badge text-bg-<?php echo labOrderStatusBadge((string)$order['status']); ?>"><?php echo htmlspecialchars(labOrderStatusLabel((string)$order['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="text-body-secondary small"><?php echo htmlspecialchars((string)$order['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-end">
                                <a href="<?php echo url('professions/medical-center/lab/orders/fill.php?id=' . (int)$order['id']); ?>" class="btn btn-sm btn-primary">
                                    <i class="bi bi-pencil-square"></i> پڕکردنەوە
                                </a>
                                <?php if ($order['status'] === 'completed'): ?>
                                    <a href="<?php echo url('professions/medical-center/lab/orders/receipt.php?id=' . (int)$order['id']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-printer"></i> وەسڵ
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-transparent d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <span class="text-body-secondary small">
                    <?php echo (int)$totalOrders; ?> داواکاری — پەڕەی <?php echo (int)$page; ?> لە <?php echo (int)$totalPages; ?>
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $pageQuery = $baseQuery;
                        if ($page > 1):
                            $pageQuery['page'] = $page - 1;
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo url('professions/medical-center/lab/orders/index.php?' . http_build_query($pageQuery)); ?>">پێشوو</a>
                            </li>
                        <?php endif; ?>
                        <?php if ($page < $totalPages):
                            $pageQuery['page'] = $page + 1;
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo url('professions/medical-center/lab/orders/index.php?' . http_build_query($pageQuery)); ?>">دواتر</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
