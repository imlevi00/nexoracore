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
$csrfToken = Security::generateCSRFToken();

$activePatients = [];
$activeStmt = $conn_kasher_platform->prepare("
    SELECT id, name, mobile, age, age_months, gender, visit_status_updated_at,
           appointment_date, appointment_time, appointment_end_time
    FROM medical_center_patients
    WHERE user_id = ? AND doctor_id = ? AND visit_status = 'with_doctor'
    ORDER BY visit_status_updated_at ASC, id ASC
");
if ($activeStmt) {
    $activeStmt->bind_param('ii', $userId, $doctorId);
    $activeStmt->execute();
    $activePatients = $activeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $activeStmt->close();
}

// Today's appointment schedule for this doctor: every patient booked for today
// that has not been seen yet (waiting or currently with the doctor), ordered by
// their appointment time so the doctor sees who is coming and when.
$todaySchedule = [];
$scheduleStmt = $conn_kasher_platform->prepare("
    SELECT id, name, mobile, age, age_months, gender, visit_status,
           appointment_date, appointment_time, appointment_end_time
    FROM medical_center_patients
    WHERE user_id = ? AND doctor_id = ?
      AND appointment_date = CURDATE()
      AND appointment_time IS NOT NULL
      AND visit_status IN ('waiting', 'with_doctor')
    ORDER BY appointment_time ASC, id ASC
");
if ($scheduleStmt) {
    $scheduleStmt->bind_param('ii', $userId, $doctorId);
    $scheduleStmt->execute();
    $todaySchedule = $scheduleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $scheduleStmt->close();
}
$scheduledCount = count($todaySchedule);

$completedTodayCount = 0;
$completedStmt = $conn_kasher_platform->prepare("
    SELECT COUNT(*) AS total
    FROM medical_center_patients
    WHERE user_id = ? AND doctor_id = ?
      AND visit_status = 'completed'
      AND DATE(visit_status_updated_at) = CURDATE()
");
if ($completedStmt) {
    $completedStmt->bind_param('ii', $userId, $doctorId);
    $completedStmt->execute();
    $completedRow = $completedStmt->get_result()->fetch_assoc();
    $completedStmt->close();
    $completedTodayCount = (int)($completedRow['total'] ?? 0);
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

$pendingRxCount = 0;
$rxStmt = $conn_kasher_platform->prepare("
    SELECT COUNT(*) AS total FROM medical_prescriptions
    WHERE user_id = ? AND doctor_id = ? AND status = 'pending'
");
if ($rxStmt) {
    $rxStmt->bind_param('ii', $userId, $doctorId);
    $rxStmt->execute();
    $rxRow = $rxStmt->get_result()->fetch_assoc();
    $rxStmt->close();
    $pendingRxCount = (int)($rxRow['total'] ?? 0);
}

$pendingLabCount = 0;
$labStmt = $conn_kasher_platform->prepare("
    SELECT COUNT(*) AS total FROM lab_orders
    WHERE user_id = ? AND doctor_id = ? AND status = 'pending'
");
if ($labStmt) {
    $labStmt->bind_param('ii', $userId, $doctorId);
    $labStmt->execute();
    $labRow = $labStmt->get_result()->fetch_assoc();
    $labStmt->close();
    $pendingLabCount = (int)($labRow['total'] ?? 0);
}

$activeCount = count($activePatients);

$doctorUiLocale = 'en';
$pageTitle = 'Doctor Dashboard';
$activeNav = 'dashboard';
$bodyClass = 'doctor-smart-clinic-page';
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">'
    . '<script src="' . asset('js/medical_center/doctor-ui.js') . '" defer></script>';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="sc-page">
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title">Appointments</h1>
            <p class="sc-page-subtitle">Welcome, <?php echo htmlspecialchars((string)$session['name'], ENT_QUOTES, 'UTF-8'); ?> — current patients with doctor</p>
        </div>
        <a class="btn sc-btn-primary btn-sm" href="<?php echo url('professions/medical-center/doctor/patients/index.php'); ?>">
            <i class="bi bi-people"></i> All patients
        </a>
    </div>

    <div class="sc-stat-bar">
        <div class="sc-stat-card sc-stat-total">
            <span class="sc-stat-value"><?php echo $totalPatientsCount; ?></span>
            <span class="sc-stat-label">Total</span>
        </div>
        <div class="sc-stat-card sc-stat-scheduled">
            <span class="sc-stat-value"><?php echo $scheduledCount; ?></span>
            <span class="sc-stat-label">Scheduled today</span>
        </div>
        <div class="sc-stat-card sc-stat-active">
            <span class="sc-stat-value"><?php echo $activeCount; ?></span>
            <span class="sc-stat-label">With doctor</span>
        </div>
        <div class="sc-stat-card sc-stat-completed">
            <span class="sc-stat-value"><?php echo $completedTodayCount; ?></span>
            <span class="sc-stat-label">Seen today</span>
        </div>
        <div class="sc-stat-card sc-stat-rx">
            <span class="sc-stat-value"><?php echo $pendingRxCount; ?></span>
            <span class="sc-stat-label">Pharmacy</span>
        </div>
        <div class="sc-stat-card sc-stat-lab">
            <span class="sc-stat-value"><?php echo $pendingLabCount; ?></span>
            <span class="sc-stat-label">Lab</span>
        </div>
    </div>

    <div class="sc-content-card sc-schedule-card">
        <div class="sc-schedule-head">
            <div>
                <h2 class="sc-schedule-title"><i class="bi bi-calendar2-week"></i> Today's schedule</h2>
                <p class="sc-schedule-sub">Booked appointments for today, in order of visit time</p>
            </div>
            <span class="sc-schedule-count"><?php echo $scheduledCount; ?> patient<?php echo $scheduledCount === 1 ? '' : 's'; ?></span>
        </div>
        <?php if ($todaySchedule === []): ?>
            <div class="sc-schedule-empty">
                <i class="bi bi-calendar2-x"></i>
                <span>No appointments scheduled for today.</span>
            </div>
        <?php else: ?>
            <ol class="sc-schedule-list">
                <?php foreach ($todaySchedule as $index => $appt): ?>
                    <?php
                    $apptStatus = (string)($appt['visit_status'] ?? 'waiting');
                    $apptRange = medicalCenterFormatAppointmentRangeLabel($appt['appointment_time'] ?? null, $appt['appointment_end_time'] ?? null);
                    ?>
                    <li class="sc-schedule-item sc-schedule-item-<?php echo htmlspecialchars($apptStatus, ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="sc-schedule-slot">
                            <span class="sc-schedule-order"><?php echo (int)$index + 1; ?></span>
                            <span class="sc-schedule-time"><i class="bi bi-clock"></i> <?php echo htmlspecialchars($apptRange, ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                        <span class="sc-schedule-info">
                            <span class="sc-schedule-name"><?php echo htmlspecialchars((string)$appt['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="sc-schedule-meta">
                                <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$appt['age'], isset($appt['age_months']) ? (int)$appt['age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($appt['gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                · <i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$appt['mobile'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </span>
                        <span class="sc-schedule-status">
                            <span class="badge <?php echo medicalCenterPatientVisitStatusBadgeClass($apptStatus); ?>">
                                <?php echo htmlspecialchars(medicalCenterPatientVisitStatusLabel($apptStatus, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>

    <div class="sc-filter-bar">
        <div class="sc-filter-row">
            <div class="sc-filter-field">
                <label for="sc-filter-name">Name</label>
                <input type="text" class="form-control form-control-sm" id="sc-filter-name" placeholder="Filter by name">
            </div>
            <div class="sc-filter-field">
                <label for="sc-filter-mobile">Phone</label>
                <input type="text" class="form-control form-control-sm" id="sc-filter-mobile" placeholder="Filter by mobile">
            </div>
        </div>
    </div>

    <div class="sc-content-card">
        <div class="sc-content-body">
            <?php if ($activePatients === []): ?>
                <div class="sc-empty">
                    <i class="bi bi-door-closed"></i>
                    <p class="mb-2">No patients in the room</p>
                    <a class="btn sc-btn-primary btn-sm" href="<?php echo url('professions/medical-center/doctor/patients/index.php'); ?>">
                        <i class="bi bi-people"></i> View patient list
                    </a>
                </div>
            <?php else: ?>
                <div class="sc-table-wrap sc-table-desktop">
                    <table class="sc-table" id="sc-filterable-table">
                        <thead>
                        <tr>
                            <th>Status</th>
                            <th>ID</th>
                            <th>Full name</th>
                            <th>Mobile</th>
                            <th>Age / Gender</th>
                            <th>Appointment</th>
                            <th>Entered</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activePatients as $patient): ?>
                            <?php
                            $patientName = (string)$patient['name'];
                            $patientMobile = (string)$patient['mobile'];
                            ?>
                            <tr data-sc-row="1"
                                class="sc-row-clickable"
                                data-history-url="<?php echo htmlspecialchars(url('professions/medical-center/doctor/patients/history.php?patient_id=' . (int)$patient['id']), ENT_QUOTES, 'UTF-8'); ?>"
                                title="Double-click to open visit history"
                                data-sc-name="<?php echo htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8'); ?>"
                                data-sc-mobile="<?php echo htmlspecialchars($patientMobile, ENT_QUOTES, 'UTF-8'); ?>">
                                <td>
                                    <span class="sc-status-active">
                                        <?php echo htmlspecialchars(medicalCenterPatientVisitStatusLabel('with_doctor', 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td>#<?php echo (int)$patient['id']; ?></td>
                                <td class="sc-name-cell"><?php echo htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($patientMobile, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$patient['age'], isset($patient['age_months']) ? (int)$patient['age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                    · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($patient['gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td>
                                    <?php
                                    $rowApptRange = medicalCenterFormatAppointmentRangeLabel($patient['appointment_time'] ?? null, $patient['appointment_end_time'] ?? null);
                                    ?>
                                    <?php if ($rowApptRange !== ''): ?>
                                        <span class="sc-appt-badge"><i class="bi bi-clock"></i> <?php echo htmlspecialchars($rowApptRange, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php else: ?>
                                        <span class="text-body-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-body-secondary small">
                                    <?php echo htmlspecialchars((string)($patient['visit_status_updated_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td class="sc-actions-cell">
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
                                        <form method="post"
                                              action="<?php echo url('professions/medical-center/doctor/patients/update_status.php'); ?>"
                                              class="sc-action-btn-form">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="patient_id" value="<?php echo (int)$patient['id']; ?>">
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit" class="sc-action-btn sc-action-success" title="Mark complete">
                                                <i class="bi bi-check-circle"></i> Done
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sc-mobile-cards">
                    <?php foreach ($activePatients as $patient): ?>
                        <div class="sc-mobile-card sc-row-clickable"
                             data-history-url="<?php echo htmlspecialchars(url('professions/medical-center/doctor/patients/history.php?patient_id=' . (int)$patient['id']), ENT_QUOTES, 'UTF-8'); ?>"
                             title="Double-click to open visit history">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <strong><?php echo htmlspecialchars((string)$patient['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span class="sc-status-active small">
                                    <?php echo htmlspecialchars(medicalCenterPatientVisitStatusLabel('with_doctor', 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div class="sc-mobile-card-meta">
                                <span><i class="bi bi-hash"></i> #<?php echo (int)$patient['id']; ?></span>
                                <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$patient['mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php $mobileApptRange = medicalCenterFormatAppointmentRangeLabel($patient['appointment_time'] ?? null, $patient['appointment_end_time'] ?? null); ?>
                                <?php if ($mobileApptRange !== ''): ?>
                                    <span class="sc-appt-badge"><i class="bi bi-clock"></i> <?php echo htmlspecialchars($mobileApptRange, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php echo renderDoctorQueuePatientMeta($patient, 'en'); ?>
                            <div class="sc-action-group mt-3">
                                <a class="sc-action-btn sc-action-primary"
                                   href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php?patient_id=' . (int)$patient['id']); ?>">
                                    <i class="bi bi-file-earmark-medical"></i> Pharmacy
                                </a>
                                <a class="sc-action-btn"
                                   href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php?patient_id=' . (int)$patient['id']); ?>">
                                    <i class="bi bi-clipboard2-pulse"></i> Lab
                                </a>
                                <a class="sc-action-btn"
                                   href="<?php echo url('professions/medical-center/doctor/patients/history.php?patient_id=' . (int)$patient['id']); ?>">
                                    <i class="bi bi-diagram-3"></i> History
                                </a>
                                <form method="post"
                                      action="<?php echo url('professions/medical-center/doctor/patients/update_status.php'); ?>"
                                      class="sc-action-btn-form">
                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="patient_id" value="<?php echo (int)$patient['id']; ?>">
                                    <input type="hidden" name="status" value="completed">
                                    <button type="submit" class="sc-action-btn sc-action-success">
                                        <i class="bi bi-check-circle"></i> Done
                                    </button>
                                </form>
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
                // Ignore double-clicks on action buttons/links/forms inside the row.
                if (event.target.closest('a, button, form')) {
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
