<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
require_once dirname(dirname(__DIR__)) . '/lab/includes/lab_catalog_service.php';
require_once dirname(dirname(__DIR__)) . '/lab/includes/lab_order_service.php';
require_once dirname(__DIR__) . '/includes/visit_history.php';
require_once dirname(__DIR__) . '/includes/referral_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];
$csrfToken = Security::generateCSRFToken();
$errors = [];

$labId = labGetTenantLabId($conn_kasher_platform, $userId);

$selectedPatientId = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));
$selectedTestIds = array_map('intval', (array)($_POST['test_ids'] ?? []));
$selectedTestIds = array_values(array_filter(array_unique($selectedTestIds), static fn($id) => $id > 0));

$rawRowSelections = (array)($_POST['row_ids'] ?? []);
$selectedRowIdsByTest = [];
foreach ($rawRowSelections as $testKey => $rowList) {
    $tid = (int)$testKey;
    if ($tid <= 0) {
        continue;
    }
    $ids = array_values(array_filter(array_unique(array_map('intval', (array)$rowList)), static fn($id) => $id > 0));
    if ($ids !== []) {
        $selectedRowIdsByTest[$tid] = $ids;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security validation failed. Please try again.';
    }
    if ($labId <= 0) {
        $errors[] = 'No lab is configured. Please contact the system administrator.';
    }
    if ($selectedPatientId <= 0) {
        $errors[] = 'Please select a patient.';
    }
    if (empty($selectedTestIds)) {
        $errors[] = 'Please select at least one test.';
    }

    $patientName = '';
    if ($selectedPatientId > 0) {
        // Owned or referred-in patient (helper enforces tenant + access).
        $accessiblePatient = medicalDoctorFetchAccessiblePatient(
            $conn_kasher_platform,
            $userId,
            $doctorId,
            $selectedPatientId
        );
        if (!$accessiblePatient) {
            $errors[] = 'Selected patient is invalid.';
        } else {
            $patientName = (string)$accessiblePatient['name'];
        }
    }

    $testMeta = [];
    if (!empty($selectedTestIds) && $labId > 0) {
        $placeholders = implode(',', array_fill(0, count($selectedTestIds), '?'));
        $types = 'ii' . str_repeat('i', count($selectedTestIds));
        $params = array_merge([$userId, $labId], $selectedTestIds);
        $sql = "SELECT id, name FROM lab_tests WHERE user_id = ? AND lab_id = ? AND is_active = 1 AND id IN ($placeholders)";
        $testsStmt = $conn_kasher_platform->prepare($sql);
        $testsStmt->bind_param($types, ...$params);
        $testsStmt->execute();
        $rows = $testsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $testsStmt->close();
        foreach ($rows as $row) {
            $testMeta[(int)$row['id']] = (string)$row['name'];
        }
        if (count($testMeta) !== count($selectedTestIds)) {
            $errors[] = 'One or more selected tests are invalid.';
        }
    }

    if (empty($errors)) {
        $conn_kasher_platform->begin_transaction();
        try {
            $notesValue = $notes !== '' ? $notes : null;
            $insertOrder = $conn_kasher_platform->prepare("
                INSERT INTO lab_orders (user_id, lab_id, doctor_id, patient_id, status, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())
            ");
            $insertOrder->bind_param('iiiis', $userId, $labId, $doctorId, $selectedPatientId, $notesValue);
            if (!$insertOrder->execute()) {
                throw new RuntimeException('Insert order failed');
            }
            $orderId = (int)$insertOrder->insert_id;
            $insertOrder->close();

            $insertTest = $conn_kasher_platform->prepare("
                INSERT INTO lab_order_tests (order_id, test_id, test_name_snapshot, sort_order, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $sort = 0;
            foreach ($selectedTestIds as $testId) {
                $snapshot = $testMeta[$testId];
                $insertTest->bind_param('iisi', $orderId, $testId, $snapshot, $sort);
                if (!$insertTest->execute()) {
                    throw new RuntimeException('Insert order test failed');
                }
                $orderTestId = (int)$insertTest->insert_id;
                $sort++;

                $chosenRows = $selectedRowIdsByTest[$testId] ?? [];
                if ($chosenRows !== []) {
                    $allRows = labFetchRows($conn_kasher_platform, $testId);
                    $allRowIds = [];
                    $rowNames = [];
                    foreach ($allRows as $r) {
                        $rid = (int)$r['id'];
                        $allRowIds[] = $rid;
                        $rowNames[$rid] = (string)$r['name'];
                    }
                    labSaveOrderTestRows($conn_kasher_platform, $orderTestId, $rowNames, $allRowIds, $chosenRows);
                }
            }
            $insertTest->close();
            $conn_kasher_platform->commit();

            setMessage("Lab order saved for {$patientName}.", 'success');
            redirect(url('professions/medical-center/doctor/lab-orders/view.php?id=' . $orderId));
        } catch (Throwable $e) {
            $conn_kasher_platform->rollback();
            $errors[] = 'Something went wrong while saving the order.';
        }
    }
}

$qPatient = trim((string)($_GET['patient_q'] ?? ''));
$patients = [];
$selectedPatient = null;

if ($selectedPatientId > 0) {
    // Owned or referred-in patient (helper enforces tenant + access).
    $selectedPatient = medicalDoctorFetchAccessiblePatient(
        $conn_kasher_platform,
        $userId,
        $doctorId,
        $selectedPatientId
    );
    if (!$selectedPatient) {
        $selectedPatientId = 0;
    }
}

$showPatientPicker = $selectedPatient === null;
if ($showPatientPicker) {
    $patientSql = "
        SELECT id, name, mobile, age, age_months, gender
        FROM medical_center_patients
        WHERE user_id = ? AND doctor_id = ?
    ";
    $patientTypes = 'ii';
    $patientParams = [$userId, $doctorId];
    if ($qPatient !== '') {
        $patientSql .= " AND (name LIKE ? OR mobile LIKE ?)";
        $patientTypes .= 'ss';
        $likePatient = '%' . $qPatient . '%';
        $patientParams[] = $likePatient;
        $patientParams[] = $likePatient;
    }
    $patientSql .= " ORDER BY id DESC LIMIT 20";
    $patientListStmt = $conn_kasher_platform->prepare($patientSql);
    $patientListStmt->bind_param($patientTypes, ...$patientParams);
    $patientListStmt->execute();
    $patients = $patientListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $patientListStmt->close();
}

$visitHistory = null;
if ($selectedPatient !== null) {
    $visitHistory = medicalDoctorFetchPatientVisitHistory($conn_kasher_platform, $userId, $doctorId, (int)$selectedPatient['id']);
}

$tests = $labId > 0 ? labFetchTests($conn_kasher_platform, $userId, $labId, true) : [];
$groupedTests = [];
foreach ($tests as $test) {
    $key = trim((string)($test['group_name'] ?? ''));
    $groupedTests[$key][] = $test;
}

$testRows = [];
foreach ($tests as $test) {
    $testRows[(int)$test['id']] = labFetchRows($conn_kasher_platform, (int)$test['id']);
}

$doctorUiLocale = 'en';
$pageTitle = 'Lab Order';
$activeNav = 'lab_orders';
$bodyClass = 'doctor-smart-clinic-page';
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">'
    . '<script src="' . asset('js/medical_center/doctor-ui.js') . '" defer></script>';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="sc-page" data-sc-page="lab-create">
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title">Lab Order</h1>
            <p class="sc-page-subtitle">Select tests for a patient</p>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($labId <= 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            No lab is configured for this center. Lab orders cannot be saved.
        </div>
    <?php endif; ?>

    <?php if ($selectedPatient === null): ?>
        <div class="sc-picker-section">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-person-plus"></i> Select patient</h5>
                <form method="get" class="d-flex gap-2">
                    <input type="text" name="patient_q" class="form-control form-control-sm"
                           placeholder="Search by name or mobile"
                           value="<?php echo htmlspecialchars($qPatient, ENT_QUOTES, 'UTF-8'); ?>">
                    <button class="btn sc-btn-primary btn-sm" type="submit"><i class="bi bi-search"></i></button>
                </form>
            </div>

            <?php if (empty($patients)): ?>
                <div class="sc-empty">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">No patients found</p>
                </div>
            <?php else: ?>
                <div class="patient-picker-list" id="patient-picker-list">
                    <?php foreach ($patients as $patient): ?>
                        <button type="button"
                                class="patient-picker-card<?php echo $selectedPatientId === (int)$patient['id'] ? ' selected' : ''; ?>"
                                data-patient-id="<?php echo (int)$patient['id']; ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                <strong><?php echo htmlspecialchars((string)$patient['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <i class="bi bi-check-circle-fill text-primary patient-picker-check<?php echo $selectedPatientId === (int)$patient['id'] ? '' : ' d-none'; ?>"></i>
                            </div>
                            <?php echo renderDoctorQueuePatientMeta($patient, 'en'); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="sc-workspace">
        <?php if ($selectedPatient !== null): ?>
            <aside class="sc-sidebar">
                <div class="sc-patient-card">
                    <div class="sc-patient-name"><?php echo htmlspecialchars((string)$selectedPatient['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="sc-patient-detail">
                        <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$selectedPatient['mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>
                            <i class="bi bi-person"></i>
                            <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$selectedPatient['age'], isset($selectedPatient['age_months']) ? (int)$selectedPatient['age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                            · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($selectedPatient['gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>
                <div class="sc-send-to">
                    <div class="sc-send-to-title">Send To</div>
                    <div class="sc-send-to-grid">
                        <a class="sc-send-to-btn sc-send-rx"
                           href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php?patient_id=' . (int)$selectedPatient['id']); ?>">
                            <i class="bi bi-file-earmark-medical"></i> Pharmacy
                        </a>
                        <a class="sc-send-to-btn sc-send-lab"
                           href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php?patient_id=' . (int)$selectedPatient['id']); ?>">
                            <i class="bi bi-clipboard2-pulse"></i> Lab
                        </a>
                        <a class="sc-send-to-btn sc-send-history"
                           href="<?php echo url('professions/medical-center/doctor/lab-orders/index.php'); ?>">
                            <i class="bi bi-clock-history"></i> History
                        </a>
                        <a class="sc-send-to-btn sc-send-change"
                           href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php'); ?>">
                            <i class="bi bi-arrow-repeat"></i> Change
                        </a>
                    </div>
                </div>
            </aside>
        <?php endif; ?>

        <div class="sc-main-panels">
            <?php if ($selectedPatient !== null): ?>
                <details class="sc-panel sc-vh-panel"<?php echo ($visitHistory !== null && $visitHistory['visits'] !== []) ? ' open' : ''; ?>>
                    <summary class="sc-panel-header sc-vh-panel-header">
                        <i class="bi bi-diagram-3"></i> Visit History
                        <span class="badge bg-light text-dark ms-auto">
                            <?php echo (int)($visitHistory['total_prescriptions'] ?? 0); ?> Pharmacy · <?php echo (int)($visitHistory['total_lab_orders'] ?? 0); ?> Lab
                        </span>
                        <span class="sc-vh-caret sc-vh-panel-caret"><i class="bi bi-chevron-right"></i></span>
                    </summary>
                    <div class="sc-panel-body">
                        <?php echo medicalDoctorRenderVisitHistory($visitHistory, 'en', $doctorId); ?>
                    </div>
                </details>
            <?php endif; ?>

            <form method="post" id="lab-order-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="patient_id" id="patient-id-input"
                       value="<?php echo $selectedPatientId > 0 ? (int)$selectedPatientId : ''; ?>">

                <div class="sc-panel mb-3">
                    <div class="sc-panel-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clipboard2-pulse"></i> Select Tests</span>
                        <span class="badge bg-light text-dark" id="selected-tests-count">0 selected</span>
                    </div>
                    <div class="sc-panel-body">
                <?php if (empty($tests)): ?>
                    <div class="alert alert-info mb-0">No active tests available. The lab must create tests first.</div>
                <?php else: ?>
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="test-search" class="form-control" autocomplete="off"
                                   placeholder="Search by test name or parameter...">
                            <button type="button" id="test-search-clear" class="btn btn-outline-secondary lab-search-clear d-none" aria-label="Clear search">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-info-circle"></i>
                            Search matches test names <em>and</em> the parameters inside each test. To choose specific parameters, open the Parameters panel.
                        </div>
                    </div>

                    <div id="tests-no-results" class="queue-empty py-4 d-none">
                        <i class="bi bi-inbox"></i>
                        <p class="mb-0">No tests found</p>
                    </div>

                    <?php foreach ($groupedTests as $groupName => $groupTests): ?>
                        <div class="mb-3 lab-test-group">
                            <?php if ($groupName !== ''): ?>
                                <div class="lab-group-title mb-2"><i class="bi bi-folder2"></i> <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <?php foreach ($groupTests as $test): ?>
                                <?php
                                $tid = (int)$test['id'];
                                $rowsForTest = $testRows[$tid] ?? [];
                                $testChecked = in_array($tid, $selectedTestIds, true);
                                $chosenRows = $selectedRowIdsByTest[$tid] ?? [];
                                $panelOpen = $chosenRows !== [];
                                $paramNames = [];
                                foreach ($rowsForTest as $pIndex => $pRow) {
                                    $pName = trim((string)$pRow['name']);
                                    if ($pName === '') {
                                        $pName = 'Row ' . ($pIndex + 1);
                                    }
                                    $paramNames[] = $pName;
                                }
                                $paramSearch = mb_strtolower(implode(' | ', $paramNames), 'UTF-8');
                                ?>
                                <div class="lab-test-item border rounded mb-2"
                                     data-test-item
                                     data-test-name="<?php echo htmlspecialchars(mb_strtolower((string)$test['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                                     data-test-params="<?php echo htmlspecialchars($paramSearch, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="d-flex align-items-center gap-2 p-2">
                                        <div class="form-check flex-grow-1 m-0">
                                            <input class="form-check-input lab-test-check" type="checkbox" name="test_ids[]"
                                                   value="<?php echo $tid; ?>"
                                                   id="test_<?php echo $tid; ?>"
                                                   data-test-id="<?php echo $tid; ?>"
                                                   <?php echo $testChecked ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="test_<?php echo $tid; ?>">
                                                <?php echo htmlspecialchars((string)$test['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </label>
                                            <span class="lab-param-hint d-none" data-param-hint>
                                                <i class="bi bi-sliders2"></i>
                                                <span class="lab-param-hint-text"></span>
                                            </span>
                                        </div>
                                        <?php if (!empty($rowsForTest)): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary lab-rows-toggle<?php echo $panelOpen ? ' active' : ''; ?>"
                                                    data-target="rows_<?php echo $tid; ?>"
                                                    aria-expanded="<?php echo $panelOpen ? 'true' : 'false'; ?>">
                                                <i class="bi bi-sliders"></i>
                                                <span>Parameters</span>
                                                <span class="badge text-bg-secondary lab-rows-badge<?php echo $panelOpen ? '' : ' d-none'; ?>"></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($rowsForTest)): ?>
                                        <div class="lab-rows-panel border-top p-2<?php echo $panelOpen ? '' : ' d-none'; ?>" id="rows_<?php echo $tid; ?>">
                                            <div class="text-body-secondary small mb-2">
                                                <i class="bi bi-hand-index"></i>
                                                Tap parameters to select them — leave empty for all parameters
                                            </div>
                                            <div class="lab-row-chips">
                                                <input type="checkbox" class="btn-check lab-rows-all"
                                                       id="rowsall_<?php echo $tid; ?>"
                                                       data-target-panel="rows_<?php echo $tid; ?>"
                                                       autocomplete="off">
                                                <label class="btn btn-outline-primary rounded-pill lab-chip lab-chip-all" for="rowsall_<?php echo $tid; ?>">
                                                    <i class="bi bi-check-all"></i> All
                                                </label>
                                                <?php foreach ($rowsForTest as $rIndex => $rowItem): ?>
                                                    <?php
                                                    $rowId = (int)$rowItem['id'];
                                                    $rowName = trim((string)$rowItem['name']);
                                                    if ($rowName === '') {
                                                        $rowName = 'Row ' . ($rIndex + 1);
                                                    }
                                                    ?>
                                                    <input type="checkbox" class="btn-check lab-row-check"
                                                           name="row_ids[<?php echo $tid; ?>][]"
                                                           value="<?php echo $rowId; ?>"
                                                           id="row_<?php echo $tid; ?>_<?php echo $rowId; ?>"
                                                           data-panel="rows_<?php echo $tid; ?>"
                                                           data-test-id="<?php echo $tid; ?>"
                                                           autocomplete="off"
                                                           <?php echo in_array($rowId, $chosenRows, true) ? 'checked' : ''; ?>>
                                                    <label class="btn btn-outline-success rounded-pill lab-chip" for="row_<?php echo $tid; ?>_<?php echo $rowId; ?>">
                                                        <?php echo htmlspecialchars($rowName, ENT_QUOTES, 'UTF-8'); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                    </div>
                </div>

                <div class="sc-panel">
                    <div class="sc-panel-header">
                        <i class="bi bi-journal-text"></i> Notes
                    </div>
                    <div class="sc-panel-body">
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."><?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <button type="submit" class="btn sc-btn-primary mt-3" <?php echo (empty($tests) || $labId <= 0) ? 'disabled' : ''; ?>>
                            <i class="bi bi-check2-circle"></i> Save order
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.__scLabCreate = {
    selectedLabel: <?php echo json_encode('selected', JSON_UNESCAPED_UNICODE); ?>
};
document.body.dataset.scPage = 'lab-create';
</script>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
