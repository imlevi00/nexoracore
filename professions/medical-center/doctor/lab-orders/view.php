<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
require_once dirname(dirname(__DIR__)) . '/lab/includes/lab_catalog_service.php';
require_once dirname(dirname(__DIR__)) . '/lab/includes/lab_order_service.php';
require_once dirname(__DIR__) . '/includes/referral_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];

$orderId = (int)($_GET['id'] ?? 0);
$order = labFetchOrder($conn_kasher_platform, $userId, $orderId, $doctorId);
if (!$order) {
    // The doctor-scoped fetch above misses orders this doctor doesn't own —
    // both lab-initiated (direct, no doctor) orders and orders another doctor
    // placed for a patient later referred to this one. Allow viewing (read-only)
    // whenever the order is for a clinic patient this doctor can access (owns or
    // was referred), so the lab's results and the referring doctor's requests
    // show up from the patient's history.
    $tenantOrder = labFetchOrder($conn_kasher_platform, $userId, $orderId, 0);
    if (
        $tenantOrder !== null
        && (int)($tenantOrder['patient_id'] ?? 0) > 0
        && medicalDoctorFetchAccessiblePatient($conn_kasher_platform, $userId, $doctorId, (int)$tenantOrder['patient_id']) !== null
    ) {
        $order = $tenantOrder;
    }
}
if (!$order) {
    setMessage('Order not found.', 'danger');
    redirect(url('professions/medical-center/doctor/lab-orders/index.php'));
}

$patientId = (int)$order['patient_id'];
$orderTests = labFetchOrderTests($conn_kasher_platform, $orderId);

$doctorUiLocale = 'en';
$pageTitle = 'View Lab Order';
$activeNav = 'lab_orders';
$bodyClass = 'doctor-smart-clinic-page';
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="sc-page">
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title">Lab Order #<?php echo (int)$order['id']; ?></h1>
            <p class="sc-page-subtitle">
                <i class="bi bi-calendar3"></i>
                Created <?php echo htmlspecialchars((string)$order['created_at'], ENT_QUOTES, 'UTF-8'); ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge text-bg-<?php echo labOrderStatusBadge((string)$order['status']); ?> fs-6">
                <?php echo htmlspecialchars(labOrderStatusLabel((string)$order['status'], 'en'), ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?php echo url('professions/medical-center/doctor/lab-orders/index.php'); ?>">
                <i class="bi bi-arrow-left"></i> Back to orders
            </a>
        </div>
    </div>

    <div class="sc-workspace">
        <aside class="sc-sidebar">
            <div class="sc-patient-card">
                <div class="sc-patient-name"><?php echo htmlspecialchars((string)$order['patient_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="sc-patient-detail">
                    <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$order['patient_mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span>
                        <i class="bi bi-person"></i>
                        <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$order['age'], isset($order['age_months']) ? (int)$order['age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                        · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($order['gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <?php if (!empty($order['notes'])): ?>
                    <div class="sc-patient-detail mt-2">
                        <span><i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars((string)$order['notes'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="sc-send-to">
                <div class="sc-send-to-title">Send To</div>
                <div class="sc-send-to-grid">
                    <a class="sc-send-to-btn sc-send-lab"
                       href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php?patient_id=' . $patientId); ?>">
                        <i class="bi bi-clipboard2-pulse"></i> Lab
                    </a>
                    <a class="sc-send-to-btn sc-send-rx"
                       href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php?patient_id=' . $patientId); ?>">
                        <i class="bi bi-file-earmark-medical"></i> Pharmacy
                    </a>
                    <a class="sc-send-to-btn sc-send-history"
                       href="<?php echo url('professions/medical-center/doctor/patients/history.php?patient_id=' . $patientId); ?>">
                        <i class="bi bi-diagram-3"></i> History
                    </a>
                    <a class="sc-send-to-btn sc-send-change"
                       href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php'); ?>">
                        <i class="bi bi-arrow-repeat"></i> Change
                    </a>
                </div>
            </div>
        </aside>

        <div class="sc-main-panels">
            <?php if (empty($orderTests)): ?>
                <div class="sc-panel">
                    <div class="sc-panel-body">
                        <p class="text-body-secondary mb-0">No tests in this order</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($orderTests as $orderTest): ?>
                    <?php
                    $testId = (int)$orderTest['test_id'];
                    $columns = labFetchColumns($conn_kasher_platform, $testId);
                    $rows = labFetchRows($conn_kasher_platform, $testId);
                    $rows = labFilterRowsBySelection($rows, labFetchOrderTestRowIds($conn_kasher_platform, (int)$orderTest['id']));
                    $cells = labFetchCells($conn_kasher_platform, $testId);
                    $results = labFetchOrderResults($conn_kasher_platform, (int)$orderTest['id']);
                    ?>
                    <div class="sc-panel mb-3">
                        <div class="sc-panel-header">
                            <i class="bi bi-clipboard-data"></i> <?php echo htmlspecialchars((string)$orderTest['test_name_snapshot'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="sc-panel-body">
                            <?php if (empty($columns) || empty($rows)): ?>
                                <p class="text-body-secondary mb-0">This test has no result table.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <?php echo labRenderResultTable($columns, $rows, $cells, $results, false, $order['gender'] ?? null); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
