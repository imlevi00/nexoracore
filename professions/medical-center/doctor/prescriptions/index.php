<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];
$nameQ = trim((string)($_GET['name'] ?? ''));
$mobileQ = trim((string)($_GET['mobile'] ?? ''));
$legacyQ = trim((string)($_GET['q'] ?? ''));
if ($legacyQ !== '' && $nameQ === '' && $mobileQ === '') {
    $nameQ = $legacyQ;
}

$prescriptions = [];
$sql = "
    SELECT mp.id, mp.diagnosis, mp.status, mp.created_at,
           p.name AS patient_name, p.mobile AS patient_mobile
    FROM medical_prescriptions mp
    INNER JOIN medical_center_patients p ON p.id = mp.patient_id
    WHERE mp.user_id = ? AND mp.doctor_id = ? AND mp.status <> 'draft'
";
$types = 'ii';
$params = [$userId, $doctorId];
if ($nameQ !== '') {
    $sql .= " AND p.name LIKE ?";
    $types .= 's';
    $params[] = '%' . $nameQ . '%';
}
if ($mobileQ !== '') {
    $sql .= " AND p.mobile LIKE ?";
    $types .= 's';
    $params[] = '%' . $mobileQ . '%';
}
$sql .= " ORDER BY mp.id DESC";
$stmt = $conn_kasher_platform->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$itemsByPrescription = [];
if (!empty($prescriptions)) {
    $prescriptionIds = array_map(static fn($row) => (int)$row['id'], $prescriptions);
    $placeholders = implode(',', array_fill(0, count($prescriptionIds), '?'));
    $typesItems = str_repeat('i', count($prescriptionIds));
    $itemStmt = $conn_kasher_platform->prepare("
        SELECT prescription_id, product_name_snapshot
        FROM medical_prescription_items
        WHERE prescription_id IN ($placeholders)
        ORDER BY id ASC
    ");
    if ($itemStmt) {
        $itemStmt->bind_param($typesItems, ...$prescriptionIds);
        $itemStmt->execute();
        $itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemStmt->close();
        foreach ($itemRows as $itemRow) {
            $pid = (int)$itemRow['prescription_id'];
            if (!isset($itemsByPrescription[$pid])) {
                $itemsByPrescription[$pid] = [];
            }
            $itemsByPrescription[$pid][] = (string)$itemRow['product_name_snapshot'];
        }
    }
}

$statusCounts = ['total' => 0, 'pending' => 0, 'other' => 0];
$countStmt = $conn_kasher_platform->prepare("
    SELECT status, COUNT(*) AS cnt FROM medical_prescriptions
    WHERE user_id = ? AND doctor_id = ? AND status <> 'draft'
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
        if ((string)$cr['status'] === 'pending') {
            $statusCounts['pending'] += $cnt;
        } else {
            $statusCounts['other'] += $cnt;
        }
    }
}

$doctorUiLocale = 'en';
$pageTitle = 'Prescription History';
$activeNav = 'history';
$bodyClass = 'doctor-smart-clinic-page';
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="sc-page">
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title">Prescription History</h1>
            <p class="sc-page-subtitle">My past prescriptions</p>
        </div>
        <a class="btn sc-btn-primary btn-sm" href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php'); ?>">
            <i class="bi bi-plus-circle"></i> New prescription
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
            <span class="sc-stat-value"><?php echo $statusCounts['other']; ?></span>
            <span class="sc-stat-label">Processed</span>
        </div>
    </div>

    <form method="get" class="sc-filter-bar">
        <div class="sc-filter-row">
            <div class="sc-filter-field">
                <label for="filter-name">Patient name</label>
                <input type="text" class="form-control form-control-sm" id="filter-name" name="name"
                       placeholder="Search by name"
                       value="<?php echo htmlspecialchars($nameQ, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="sc-filter-field">
                <label for="filter-mobile">Mobile</label>
                <input type="text" class="form-control form-control-sm" id="filter-mobile" name="mobile"
                       placeholder="Search by mobile"
                       value="<?php echo htmlspecialchars($mobileQ, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="sc-filter-actions">
                <button type="submit" class="btn sc-btn-primary btn-sm"><i class="bi bi-search"></i> Search</button>
                <?php if ($nameQ !== '' || $mobileQ !== ''): ?>
                    <a href="<?php echo url('professions/medical-center/doctor/prescriptions/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="sc-content-card">
        <div class="sc-content-body">
            <?php if (empty($prescriptions)): ?>
                <div class="sc-empty">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">No prescriptions found</p>
                </div>
            <?php else: ?>
                <div class="sc-table-wrap">
                    <table class="sc-table">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Patient</th>
                            <th>Diagnosis</th>
                            <th>Medications</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($prescriptions as $prescription): ?>
                            <?php
                            $pid = (int)$prescription['id'];
                            $drugNames = $itemsByPrescription[$pid] ?? [];
                            $drugCount = count($drugNames);
                            $diagnosisText = (string)$prescription['diagnosis'];
                            $diagnosisShort = mb_strlen($diagnosisText) > 40
                                ? mb_substr($diagnosisText, 0, 40) . '…'
                                : $diagnosisText;
                            $status = (string)$prescription['status'];
                            ?>
                            <tr>
                                <td>#<?php echo $pid; ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars((string)$prescription['patient_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <small class="text-body-secondary"><?php echo htmlspecialchars((string)$prescription['patient_mobile'], ENT_QUOTES, 'UTF-8'); ?></small>
                                </td>
                                <td>
                                    <span class="prescription-list-diagnosis d-inline-block text-truncate"
                                          title="<?php echo htmlspecialchars($diagnosisText, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($diagnosisShort, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($drugCount === 0): ?>
                                        <span class="text-body-secondary">-</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary"><?php echo $drugCount; ?> med<?php echo $drugCount === 1 ? '' : 's'; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo medicalCenterPrescriptionStatusBadgeClass($status); ?>">
                                        <?php echo htmlspecialchars(medicalCenterPrescriptionStatusLabel($status, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="text-body-secondary small"><?php echo htmlspecialchars((string)$prescription['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <a class="sc-action-btn sc-action-primary"
                                       href="<?php echo url('professions/medical-center/doctor/prescriptions/view.php?id=' . $pid); ?>">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
