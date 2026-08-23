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

$patients = [];
$sql = "
    SELECT id, name, mobile, age, age_months, gender, created_at
    FROM medical_center_patients
    WHERE user_id = ? AND doctor_id = ?
";
$types = 'ii';
$params = [$userId, $doctorId];

if ($nameQ !== '') {
    $sql .= " AND name LIKE ?";
    $types .= 's';
    $params[] = '%' . $nameQ . '%';
}
if ($mobileQ !== '') {
    $sql .= " AND mobile LIKE ?";
    $types .= 's';
    $params[] = '%' . $mobileQ . '%';
}

$sql .= " ORDER BY id DESC";
$stmt = $conn_kasher_platform->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $patients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$totalPatientsCount = 0;
$totalStmt = $conn_kasher_platform->prepare("
    SELECT COUNT(*) AS total FROM medical_center_patients WHERE user_id = ? AND doctor_id = ?
");
if ($totalStmt) {
    $totalStmt->bind_param('ii', $userId, $doctorId);
    $totalStmt->execute();
    $totalRow = $totalStmt->get_result()->fetch_assoc();
    $totalStmt->close();
    $totalPatientsCount = (int)($totalRow['total'] ?? 0);
}

$filteredCount = count($patients);

$doctorUiLocale = 'en';
$pageTitle = 'Doctor Patients';
$activeNav = 'patients';
$bodyClass = 'doctor-smart-clinic-page';
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="sc-page">
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title">Patients</h1>
            <p class="sc-page-subtitle">Search and manage your patient list</p>
        </div>
    </div>

    <div class="sc-stat-bar">
        <div class="sc-stat-card sc-stat-total">
            <span class="sc-stat-value"><?php echo $totalPatientsCount; ?></span>
            <span class="sc-stat-label">Total</span>
        </div>
        <div class="sc-stat-card sc-stat-active">
            <span class="sc-stat-value"><?php echo $filteredCount; ?></span>
            <span class="sc-stat-label">Showing</span>
        </div>
    </div>

    <form method="get" class="sc-filter-bar">
        <div class="sc-filter-row">
            <div class="sc-filter-field">
                <label for="filter-name">Name</label>
                <input type="text" class="form-control form-control-sm" id="filter-name" name="name"
                       placeholder="First or full name"
                       value="<?php echo htmlspecialchars($nameQ, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="sc-filter-field">
                <label for="filter-mobile">Phone</label>
                <input type="text" class="form-control form-control-sm" id="filter-mobile" name="mobile"
                       placeholder="Phone number"
                       value="<?php echo htmlspecialchars($mobileQ, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="sc-filter-actions">
                <button type="submit" class="btn sc-btn-primary btn-sm"><i class="bi bi-search"></i> Search</button>
                <?php if ($nameQ !== '' || $mobileQ !== ''): ?>
                    <a href="<?php echo url('professions/medical-center/doctor/patients/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="sc-content-card">
        <div class="sc-content-body">
            <?php if (empty($patients)): ?>
                <div class="sc-empty">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">No patients found</p>
                </div>
            <?php else: ?>
                <div class="sc-table-wrap sc-table-desktop">
                    <table class="sc-table">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($patients as $patient): ?>
                            <tr class="sc-row-clickable" data-history-url="<?php echo htmlspecialchars(url('professions/medical-center/doctor/patients/history.php?patient_id=' . (int)$patient['id']), ENT_QUOTES, 'UTF-8'); ?>" title="Double-click to open visit history">
                                <td>#<?php echo (int)$patient['id']; ?></td>
                                <td><?php echo htmlspecialchars((string)$patient['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$patient['mobile'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$patient['age'], isset($patient['age_months']) ? (int)$patient['age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(medicalCenterPatientGenderLabel($patient['gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-body-secondary small"><?php echo htmlspecialchars((string)$patient['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <div class="sc-action-group">
                                        <a class="sc-action-btn sc-action-primary"
                                           href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php?patient_id=' . (int)$patient['id']); ?>"
                                           title="Write prescription">
                                            <i class="bi bi-file-earmark-medical"></i> Pharmacy
                                        </a>
                                        <a class="sc-action-btn"
                                           href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php?patient_id=' . (int)$patient['id']); ?>"
                                           title="Lab order">
                                            <i class="bi bi-clipboard2-pulse"></i> Lab
                                        </a>
                                        <a class="sc-action-btn"
                                           href="<?php echo url('professions/medical-center/doctor/patients/history.php?patient_id=' . (int)$patient['id']); ?>"
                                           title="Visit history">
                                            <i class="bi bi-diagram-3"></i> History
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sc-mobile-cards">
                    <?php foreach ($patients as $patient): ?>
                        <div class="sc-mobile-card sc-row-clickable" data-history-url="<?php echo htmlspecialchars(url('professions/medical-center/doctor/patients/history.php?patient_id=' . (int)$patient['id']), ENT_QUOTES, 'UTF-8'); ?>" title="Double-click to open visit history">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <strong><?php echo htmlspecialchars((string)$patient['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small class="text-body-secondary">#<?php echo (int)$patient['id']; ?></small>
                            </div>
                            <div class="sc-mobile-card-meta">
                                <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$patient['mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>
                                    <i class="bi bi-person"></i>
                                    <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$patient['age'], isset($patient['age_months']) ? (int)$patient['age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                    · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($patient['gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span><i class="bi bi-calendar"></i> <?php echo htmlspecialchars((string)$patient['created_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="sc-action-group mt-3">
                                <a class="sc-action-btn sc-action-primary w-100 justify-content-center"
                                   href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php?patient_id=' . (int)$patient['id']); ?>">
                                    <i class="bi bi-file-earmark-medical"></i> Write prescription
                                </a>
                                <a class="sc-action-btn w-100 justify-content-center"
                                   href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php?patient_id=' . (int)$patient['id']); ?>">
                                    <i class="bi bi-clipboard2-pulse"></i> Lab order
                                </a>
                                <a class="sc-action-btn w-100 justify-content-center"
                                   href="<?php echo url('professions/medical-center/doctor/patients/history.php?patient_id=' . (int)$patient['id']); ?>">
                                    <i class="bi bi-diagram-3"></i> Visit history
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .sc-row-clickable { cursor: pointer; }
</style>
<script>
    (function () {
        document.querySelectorAll('.sc-row-clickable').forEach(function (el) {
            el.addEventListener('dblclick', function (event) {
                // Ignore double-clicks on action buttons/links inside the row.
                if (event.target.closest('a, button')) {
                    return;
                }
                var url = el.getAttribute('data-history-url');
                if (url) {
                    window.getSelection().removeAllRanges();
                    window.location.href = url;
                }
            });
        });
    })();
</script>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
