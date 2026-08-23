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

$statusCounts = labCountOrdersByStatus($conn_kasher_platform, $userId, $labId);
$recentResult = labFetchOrders($conn_kasher_platform, $userId, $labId, null, null, 5, 0);
$recentOrders = $recentResult['rows'];

$pageTitle = 'داشبۆردی تاقیگە';
$activeNav = 'dashboard';
$bodyClass = 'lab-dashboard-page';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="queue-header card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>
            <h4 class="mb-1">بەخێربێیت، <?php echo htmlspecialchars((string)$session['name'], ENT_QUOTES, 'UTF-8'); ?></h4>
            <p class="text-body-secondary mb-0">پۆرتاڵی تاقیگەی مێدیکەل سێنتەر</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <span class="queue-stat-chip queue-stat-with-doctor">
                <i class="bi bi-hourglass-split"></i>
                <?php echo (int)$statusCounts['pending']; ?> چاوەڕوان
            </span>
            <span class="queue-stat-chip text-bg-info-subtle">
                <i class="bi bi-droplet"></i>
                <?php echo (int)$statusCounts['sample_collected']; ?> نمونە وەرگیراوە
            </span>
            <span class="queue-stat-chip queue-stat-completed">
                <i class="bi bi-check-circle"></i>
                <?php echo (int)$statusCounts['today_completed']; ?> تەواو (ئەمڕۆ)
            </span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-3">
        <a href="<?php echo url('professions/medical-center/lab/orders/create.php'); ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 lab-test-card">
                <div class="card-body text-center py-4">
                    <i class="bi bi-person-plus text-warning lab-dash-icon"></i>
                    <h6 class="mt-3 mb-1">داواکاری نوێ</h6>
                    <p class="text-body-secondary small mb-0">نەخۆشی دەرەکی — بەبێ دکتۆر</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-3">
        <a href="<?php echo url('professions/medical-center/lab/orders/index.php'); ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 lab-test-card">
                <div class="card-body text-center py-4 position-relative">
                    <?php if ((int)$statusCounts['pending'] > 0): ?>
                        <span class="position-absolute top-0 start-0 m-2 badge rounded-pill text-bg-warning"><?php echo (int)$statusCounts['pending']; ?></span>
                    <?php endif; ?>
                    <i class="bi bi-clipboard2-pulse text-primary lab-dash-icon"></i>
                    <h6 class="mt-3 mb-1">داواکاریەکان</h6>
                    <p class="text-body-secondary small mb-0">پڕکردنەوەی ئەنجام و چاپی وەسڵ</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-3">
        <a href="<?php echo url('professions/medical-center/lab/tests/index.php'); ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 lab-test-card">
                <div class="card-body text-center py-4">
                    <i class="bi bi-grid-3x3-gap text-success lab-dash-icon"></i>
                    <h6 class="mt-3 mb-1">فەحسەکان</h6>
                    <p class="text-body-secondary small mb-0">دروستکردن و دیزاینی خشتەی فەحس</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-3">
        <a href="<?php echo url('professions/medical-center/lab/receipt_template/index.php'); ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 lab-test-card">
                <div class="card-body text-center py-4">
                    <i class="bi bi-receipt text-info lab-dash-icon"></i>
                    <h6 class="mt-3 mb-1">ڕێکخستنی وەسڵ</h6>
                    <p class="text-body-secondary small mb-0">سەرپەڕە و زانیاری وەسڵ</p>
                </div>
            </div>
        </a>
    </div>
</div>

<?php if (!empty($recentOrders)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <h5 class="mb-0"><i class="bi bi-clock-history text-primary"></i> دوایین داواکاریەکان</h5>
                <a href="<?php echo url('professions/medical-center/lab/orders/index.php'); ?>" class="btn btn-sm btn-outline-primary">هەموو</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>نەخۆش</th>
                            <th>دۆخ</th>
                            <th>بەروار</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $recent): ?>
                            <tr>
                                <td><?php echo (int)$recent['id']; ?></td>
                                <td><?php echo htmlspecialchars((string)$recent['patient_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="badge text-bg-<?php echo labOrderStatusBadge((string)$recent['status']); ?>">
                                        <?php echo htmlspecialchars(labOrderStatusLabel((string)$recent['status']), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="text-body-secondary small"><?php echo htmlspecialchars((string)$recent['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end">
                                    <a href="<?php echo url('professions/medical-center/lab/orders/fill.php?id=' . (int)$recent['id']); ?>" class="btn btn-sm btn-outline-primary">کردنەوە</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
