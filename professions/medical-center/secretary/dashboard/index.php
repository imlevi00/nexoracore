<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/secretary_service.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalSecretarySession();
$userId = (int)$session['user_id'];
$secretaryId = (int)$session['secretary_id'];
$csrfToken = Security::generateCSRFToken();
$flashMessage = getMessage();

$assignedDoctors = getSecretaryAssignedDoctors($conn_kasher_platform, $userId, $secretaryId);
$hasAssignedDoctors = $assignedDoctors !== [];
$defaultDoctorId = $hasAssignedDoctors ? (int)$assignedDoctors[0]['id'] : 0;

// Scheduling hints for the quick-add form (see secretary-appointment.js).
$todayDate = date('Y-m-d');
$assignedDoctorIds = array_map(static fn(array $d): int => (int)$d['id'], $assignedDoctors);
$doctorNextSlots = getSecretaryDoctorsNextSlot($conn_kasher_platform, $userId, $assignedDoctorIds, $todayDate);
$appointmentFallbackTime = medicalCenterRoundedNowTime();
$appointmentDurations = medicalCenterAppointmentDurations();
$defaultAppointmentDuration = medicalCenterDefaultAppointmentDuration();

$waitingPatients = [];
$withDoctorPatients = [];
$completedTodayPatients = [];

$stmt = $conn_kasher_platform->prepare("
    SELECT p.id, p.name, p.mobile, p.age, p.age_months, p.gender,
           p.visit_status, p.visit_status_updated_at, p.created_at,
           p.appointment_date, p.appointment_time, p.appointment_end_time,
           d.name AS doctor_name
    FROM medical_center_patients p
    INNER JOIN doctors d ON d.id = p.doctor_id AND d.user_id = p.user_id
    WHERE p.user_id = ? AND p.created_by_secretary_id = ?
      AND (
        p.visit_status IN ('waiting', 'with_doctor')
        OR (p.visit_status = 'completed' AND DATE(p.visit_status_updated_at) = CURDATE())
      )
");
if ($stmt) {
    $stmt->bind_param('ii', $userId, $secretaryId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $patient) {
        $status = (string)$patient['visit_status'];
        if ($status === 'waiting') {
            $waitingPatients[] = $patient;
        } elseif ($status === 'with_doctor') {
            $withDoctorPatients[] = $patient;
        } elseif ($status === 'completed') {
            $completedTodayPatients[] = $patient;
        }
    }
}

// Order the waiting queue by appointment time when scheduled, so the secretary
// sees patients in the order they are expected. Unscheduled patients fall back
// to their registration order.
$waitingSortKey = static function (array $p): string {
    $date = trim((string)($p['appointment_date'] ?? ''));
    $time = trim((string)($p['appointment_time'] ?? ''));
    if ($date !== '' && $date !== '0000-00-00' && $time !== '') {
        return $date . ' ' . $time;
    }
    return (string)$p['created_at'];
};
usort(
    $waitingPatients,
    static fn(array $a, array $b): int => strcmp($waitingSortKey($a), $waitingSortKey($b))
);
usort(
    $withDoctorPatients,
    static fn(array $a, array $b): int => strcmp(
        (string)($a['visit_status_updated_at'] ?? $a['created_at']),
        (string)($b['visit_status_updated_at'] ?? $b['created_at'])
    )
);
usort(
    $completedTodayPatients,
    static fn(array $a, array $b): int => strcmp(
        (string)($b['visit_status_updated_at'] ?? $b['created_at']),
        (string)($a['visit_status_updated_at'] ?? $a['created_at'])
    )
);

$waitingCount = count($waitingPatients);
$withDoctorCount = count($withDoctorPatients);
$completedCount = count($completedTodayPatients);

// --- Returning-patient search ---------------------------------------------
// Look up a previously registered patient by name or mobile so the secretary can
// re-send the SAME record (and its full history) back to a doctor. Rows are
// grouped per person (name + mobile), keeping only the most recent record — that
// is the one carrying the accumulated history the doctor should see.
$searchQuery = trim((string)($_GET['q'] ?? ''));
$searchPerformed = $searchQuery !== '';
$searchTooShort = $searchPerformed && mb_strlen($searchQuery) < 2;
$searchResults = [];
if ($searchPerformed && !$searchTooShort) {
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchQuery);
    $like = '%' . $escaped . '%';
    $searchStmt = $conn_kasher_platform->prepare("
        SELECT p.id, p.name, p.mobile, p.age, p.age_months, p.gender, p.doctor_id,
               p.visit_status, p.visit_status_updated_at, p.created_at,
               d.name AS doctor_name,
               (SELECT COUNT(*) FROM medical_prescriptions mp WHERE mp.patient_id = p.id AND mp.status <> 'draft') AS rx_count,
               (SELECT COUNT(*) FROM lab_orders lo WHERE lo.patient_id = p.id) AS lab_count
        FROM medical_center_patients p
        INNER JOIN doctors d ON d.id = p.doctor_id AND d.user_id = p.user_id
        INNER JOIN (
            SELECT MAX(id) AS max_id
            FROM medical_center_patients
            WHERE user_id = ? AND created_by_secretary_id = ?
              AND (name LIKE ? OR mobile LIKE ?)
            GROUP BY name, mobile
        ) latest ON latest.max_id = p.id
        ORDER BY p.visit_status_updated_at DESC, p.id DESC
        LIMIT 30
    ");
    if ($searchStmt) {
        $searchStmt->bind_param('iiss', $userId, $secretaryId, $like, $like);
        $searchStmt->execute();
        $searchResults = $searchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $searchStmt->close();
    }
}
$dashboardSelfUrl = url('professions/medical-center/secretary/dashboard/index.php');

/**
 * @param array<string, mixed> $patient
 */
function renderSecretaryQueuePatientMeta(array $patient): string
{
    $age = htmlspecialchars(
        medicalCenterPatientAgeLabel(
            (int)$patient['age'],
            isset($patient['age_months']) ? (int)$patient['age_months'] : null
        ),
        ENT_QUOTES,
        'UTF-8'
    );
    $gender = htmlspecialchars(
        medicalCenterPatientGenderLabel($patient['gender'] ?? null),
        ENT_QUOTES,
        'UTF-8'
    );
    $mobile = htmlspecialchars((string)$patient['mobile'], ENT_QUOTES, 'UTF-8');
    $doctorName = htmlspecialchars((string)$patient['doctor_name'], ENT_QUOTES, 'UTF-8');

    $apptRange = medicalCenterFormatAppointmentRangeLabel(
        $patient['appointment_time'] ?? null,
        $patient['appointment_end_time'] ?? null
    );
    $apptRow = '';
    if ($apptRange !== '') {
        $apptDate = medicalCenterFormatAppointmentDateLabel($patient['appointment_date'] ?? null);
        $apptText = htmlspecialchars(
            $apptDate !== '' ? ($apptDate . ' · ' . $apptRange) : $apptRange,
            ENT_QUOTES,
            'UTF-8'
        );
        $apptRow = '<span class="queue-appt-time"><i class="bi bi-clock"></i> ' . $apptText . '</span>';
    }

    return <<<HTML
        <div class="queue-patient-meta">
            <span><i class="bi bi-telephone"></i> {$mobile}</span>
            <span><i class="bi bi-person"></i> {$age} · {$gender}</span>
            <span><i class="bi bi-heart-pulse"></i> {$doctorName}</span>
            {$apptRow}
        </div>
    HTML;
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>داشبۆردی سکرتێر - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_center/secretary.css'); ?>" rel="stylesheet">
    <script src="<?php echo asset('js/medical_center/secretary-appointment.js'); ?>" defer></script>
</head>
<body class="bg-body-secondary secretary-queue-page">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-heart-pulse"></i> مێدیکەل سێنتەر - سکرتێر</span>
        <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
            <a class="nav-link" href="<?php echo url('professions/medical-center/secretary/patients/index.php'); ?>">
                <i class="bi bi-people"></i> بەڕێوەبردنی نەخۆشەکان
            </a>
            <a class="nav-link" href="<?php echo url('professions/medical-center/secretary/auth/logout.php'); ?>">
                <i class="bi bi-box-arrow-right"></i> چوونەدەرەوە
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="queue-header card border-0 shadow-sm mb-4">
        <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div>
                <h4 class="mb-1">بەخێربێیت، <?php echo htmlspecialchars((string)$session['name'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <p class="text-body-secondary mb-0">بەڕێوەبردنی queue ـی نەخۆشەکانی ئەمڕۆ</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="queue-stat-chip queue-stat-waiting">
                    <i class="bi bi-hourglass-split"></i>
                    <?php echo $waitingCount; ?> چاوەڕوان
                </span>
                <span class="queue-stat-chip queue-stat-with-doctor">
                    <i class="bi bi-door-open"></i>
                    <?php echo $withDoctorCount; ?> لای دکتۆر
                </span>
                <span class="queue-stat-chip queue-stat-completed">
                    <i class="bi bi-check-circle"></i>
                    <?php echo $completedCount; ?> تەواو (ئەمڕۆ)
                </span>
            </div>
        </div>
    </div>

    <?php if (!$hasAssignedDoctors): ?>
        <div class="alert alert-warning">
            هیچ دکتۆرێکت دیاری نەکراوە بۆ ناردنی نەخۆش. تکایە پەیوەندی بە بەڕێوەبەرەکەت بکە.
        </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm mb-4 queue-quick-add">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <h5 class="mb-0"><i class="bi bi-person-plus text-success"></i> زیادکردنی نەخۆشی نوێ</h5>
            </div>
            <form method="post" action="<?php echo url('professions/medical-center/secretary/patients/save.php'); ?>" class="row g-2 align-items-end"
                  data-appt-scope
                  data-appt-today="<?php echo htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8'); ?>"
                  data-appt-fallback="<?php echo htmlspecialchars($appointmentFallbackTime, ENT_QUOTES, 'UTF-8'); ?>"
                  data-appt-nextslots="<?php echo htmlspecialchars(json_encode($doctorNextSlots, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="patient_id" value="0">
                <input type="hidden" name="redirect_to" value="dashboard">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">ناو</label>
                    <input type="text" name="name" class="form-control form-control-sm" required maxlength="150">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">مۆبایل</label>
                    <input type="text" name="mobile" class="form-control form-control-sm" required maxlength="30">
                </div>
                <div class="col-4 col-md-1">
                    <label class="form-label small mb-1">ساڵ</label>
                    <input type="number" name="age" class="form-control form-control-sm" required min="0" max="130">
                </div>
                <div class="col-4 col-md-1">
                    <label class="form-label small mb-1">مانگ</label>
                    <input type="number" name="age_months" class="form-control form-control-sm" min="0" max="11" placeholder="0-11">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label small mb-1">ڕەگەز</label>
                    <select name="gender" class="form-select form-select-sm" required>
                        <option value="">هەڵبژێرە...</option>
                        <option value="male">نێر</option>
                        <option value="female">مێ</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">پیشە <span class="text-body-secondary">(ئیختیاری)</span></label>
                    <input type="text" name="profession" class="form-control form-control-sm" maxlength="100">
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label small mb-1">جۆری خوێن</label>
                    <select name="blood_type" class="form-select form-select-sm">
                        <option value="">—</option>
                        <?php foreach (medicalCenterBloodTypes() as $bloodTypeOption): ?>
                            <option value="<?php echo htmlspecialchars($bloodTypeOption, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($bloodTypeOption, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">ناونیشان <span class="text-body-secondary">(ئیختیاری)</span></label>
                    <input type="text" name="address" class="form-control form-control-sm" maxlength="255">
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label small mb-1">دکتۆر</label>
                    <select name="doctor_id" class="form-select form-select-sm" required data-appt-doctor>
                        <?php foreach ($assignedDoctors as $doctor): ?>
                            <option value="<?php echo (int)$doctor['id']; ?>"
                                <?php echo ((int)$doctor['id'] === $defaultDoctorId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($doctor['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1"><i class="bi bi-calendar-event"></i> بەروار</label>
                    <input type="date" name="appointment_date" class="form-control form-control-sm" data-appt-date
                           value="<?php echo htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1"><i class="bi bi-clock"></i> کات</label>
                    <input type="time" name="appointment_time" class="form-control form-control-sm" data-appt-time>
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label small mb-1">ماوە</label>
                    <select name="appointment_duration" class="form-select form-select-sm" data-appt-duration>
                        <?php foreach ($appointmentDurations as $duration): ?>
                            <option value="<?php echo (int)$duration; ?>"
                                <?php echo ((int)$duration === $defaultAppointmentDuration) ? 'selected' : ''; ?>>
                                <?php echo (int)$duration; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label small mb-1">کۆتایی</label>
                    <div class="appointment-end-inline" data-appt-end>—</div>
                </div>
                <div class="col-12 col-md-2">
                    <button class="btn btn-success btn-sm w-100" type="submit">
                        <i class="bi bi-plus-circle"></i> زیادکردن
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4 queue-search-card">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <h5 class="mb-0"><i class="bi bi-search text-primary"></i> گەڕان بۆ نەخۆشی پێشوو</h5>
                <small class="text-body-secondary">ناردنەوەی نەخۆش بۆ دکتۆر لەسەر هەمان داتا و مێژووی پێشووی</small>
            </div>
            <form method="get" action="<?php echo htmlspecialchars($dashboardSelfUrl, ENT_QUOTES, 'UTF-8'); ?>" class="row g-2 align-items-end">
                <div class="col-12 col-md-8">
                    <label class="form-label small mb-1">ناو یان ژمارەی مۆبایل</label>
                    <input type="text" name="q" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="بەلایەنی کەم ٢ پیت بنووسە..." maxlength="150" autocomplete="off">
                </div>
                <div class="<?php echo $searchPerformed ? 'col-8 col-md-2' : 'col-12 col-md-4'; ?>">
                    <button class="btn btn-primary btn-sm w-100" type="submit">
                        <i class="bi bi-search"></i> گەڕان
                    </button>
                </div>
                <?php if ($searchPerformed): ?>
                    <div class="col-4 col-md-2">
                        <a class="btn btn-outline-secondary btn-sm w-100" href="<?php echo htmlspecialchars($dashboardSelfUrl, ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="bi bi-x-lg"></i> پاککردنەوە
                        </a>
                    </div>
                <?php endif; ?>
            </form>

            <?php if ($searchPerformed): ?>
                <?php if ($searchTooShort): ?>
                    <div class="alert alert-info mt-3 mb-0 py-2">تکایە بەلایەنی کەم ٢ پیت بنووسە بۆ گەڕان.</div>
                <?php elseif ($searchResults === []): ?>
                    <div class="alert alert-warning mt-3 mb-0 py-2">هیچ نەخۆشێکی پێشوو نەدۆزرایەوە بەم ناو یان مۆبایلە.</div>
                <?php else: ?>
                    <div class="queue-search-results mt-3">
                        <?php foreach ($searchResults as $result): ?>
                            <?php
                            $resStatus = (string)($result['visit_status'] ?? 'waiting');
                            $resIsActive = in_array($resStatus, ['waiting', 'with_doctor'], true);
                            $resRxCount = (int)$result['rx_count'];
                            $resLabCount = (int)$result['lab_count'];
                            $resDoctorId = (int)$result['doctor_id'];
                            ?>
                            <div class="queue-search-result">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="queue-patient-name"><?php echo htmlspecialchars((string)$result['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="queue-patient-meta mt-1">
                                            <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$result['mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span><i class="bi bi-person"></i>
                                                <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$result['age'], isset($result['age_months']) ? (int)$result['age_months'] : null), ENT_QUOTES, 'UTF-8'); ?>
                                                · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($result['gender'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <span><i class="bi bi-heart-pulse"></i> <?php echo htmlspecialchars((string)$result['doctor_name'], ENT_QUOTES, 'UTF-8'); ?> <small class="text-body-secondary">(دکتۆری پێشوو)</small></span>
                                        </div>
                                    </div>
                                    <div class="text-md-end">
                                        <span class="badge <?php echo medicalCenterPatientVisitStatusBadgeClass($resStatus); ?>">
                                            <?php echo htmlspecialchars(medicalCenterPatientVisitStatusLabel($resStatus), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                        <div class="queue-search-history mt-2">
                                            <?php if ($resRxCount > 0 || $resLabCount > 0): ?>
                                                <span class="badge text-bg-light border"><i class="bi bi-capsule"></i> <?php echo $resRxCount; ?> ڕەچەتە</span>
                                                <span class="badge text-bg-light border"><i class="bi bi-clipboard2-pulse"></i> <?php echo $resLabCount; ?> پشکنین</span>
                                            <?php else: ?>
                                                <span class="text-body-secondary small">مێژووی تۆمارکراو نییە</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($result['visit_status_updated_at'])): ?>
                                            <small class="text-body-secondary d-block mt-1">
                                                <i class="bi bi-clock-history"></i> دوایین سەردان: <?php echo htmlspecialchars(substr((string)$result['visit_status_updated_at'], 0, 16), ENT_QUOTES, 'UTF-8'); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($resIsActive): ?>
                                    <div class="alert alert-info mt-3 mb-0 py-2 small">
                                        <i class="bi bi-info-circle"></i> ئەم نەخۆشە ئێستا لە ڕیزەکەدایە، پێویست ناکات دووبارە بنێردرێت.
                                    </div>
                                <?php elseif (!$hasAssignedDoctors): ?>
                                    <div class="alert alert-warning mt-3 mb-0 py-2 small">
                                        هیچ دکتۆرێکت دیاری نەکراوە بۆ ناردنی نەخۆش.
                                    </div>
                                <?php else: ?>
                                    <form method="post" action="<?php echo url('professions/medical-center/secretary/patients/resend.php'); ?>" class="row g-2 align-items-end mt-1"
                                          data-appt-scope
                                          data-appt-today="<?php echo htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8'); ?>"
                                          data-appt-fallback="<?php echo htmlspecialchars($appointmentFallbackTime, ENT_QUOTES, 'UTF-8'); ?>"
                                          data-appt-nextslots="<?php echo htmlspecialchars(json_encode($doctorNextSlots, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="patient_id" value="<?php echo (int)$result['id']; ?>">
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small mb-1">دکتۆر</label>
                                            <select name="doctor_id" class="form-select form-select-sm" required data-appt-doctor>
                                                <?php foreach ($assignedDoctors as $doctor): ?>
                                                    <option value="<?php echo (int)$doctor['id']; ?>"
                                                        <?php echo ((int)$doctor['id'] === $resDoctorId) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($doctor['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <label class="form-label small mb-1"><i class="bi bi-calendar-event"></i> بەروار</label>
                                            <input type="date" name="appointment_date" class="form-control form-control-sm" data-appt-date
                                                   value="<?php echo htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <label class="form-label small mb-1"><i class="bi bi-clock"></i> کات</label>
                                            <input type="time" name="appointment_time" class="form-control form-control-sm" data-appt-time>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <label class="form-label small mb-1">ماوە</label>
                                            <select name="appointment_duration" class="form-select form-select-sm" data-appt-duration>
                                                <?php foreach ($appointmentDurations as $duration): ?>
                                                    <option value="<?php echo (int)$duration; ?>"
                                                        <?php echo ((int)$duration === $defaultAppointmentDuration) ? 'selected' : ''; ?>>
                                                        <?php echo (int)$duration; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <button class="btn btn-success btn-sm w-100" type="submit">
                                                <i class="bi bi-arrow-repeat"></i> ناردنەوە بۆ دکتۆر
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="queue-board">
        <div class="queue-column queue-column-waiting">
            <div class="queue-column-header">
                <span class="queue-column-icon"><i class="bi bi-hourglass-split"></i></span>
                <div>
                    <h5 class="mb-0">چاوەڕوان</h5>
                    <small class="text-body-secondary">هێشتا نەچوونەتە لای دکتۆر</small>
                </div>
                <span class="badge rounded-pill <?php echo medicalCenterPatientVisitStatusBadgeClass('waiting'); ?>"><?php echo $waitingCount; ?></span>
            </div>
            <div class="queue-column-body">
                <?php if ($waitingPatients === []): ?>
                    <div class="queue-empty">
                        <i class="bi bi-inbox"></i>
                        <p class="mb-0">هیچ نەخۆشێک لە چاوەڕوانیدا نییە</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($waitingPatients as $patient): ?>
                        <div class="queue-patient-card">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <h6 class="mb-0 queue-patient-name"><?php echo htmlspecialchars((string)$patient['name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                <small class="text-body-secondary text-nowrap">
                                    <?php echo htmlspecialchars((string)$patient['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                            </div>
                            <?php echo renderSecretaryQueuePatientMeta($patient); ?>
                            <div class="queue-patient-actions mt-3">
                                <a href="<?php echo url('professions/medical-center/secretary/patients/index.php?edit=' . (int)$patient['id']); ?>"
                                   class="btn btn-outline-secondary btn-sm queue-edit-btn" title="دەستکاری زانیاری نەخۆش">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <form method="post" action="<?php echo url('professions/medical-center/secretary/patients/update_status.php'); ?>" class="flex-grow-1 m-0">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="patient_id" value="<?php echo (int)$patient['id']; ?>">
                                    <input type="hidden" name="status" value="with_doctor">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-arrow-left-circle"></i> ناردن بۆ دکتۆر
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="queue-column queue-column-with-doctor">
            <div class="queue-column-header">
                <span class="queue-column-icon"><i class="bi bi-door-open"></i></span>
                <div>
                    <h5 class="mb-0">لای دکتۆر</h5>
                    <small class="text-body-secondary">لە ژوورەوەی دکتۆرن</small>
                </div>
                <span class="badge rounded-pill <?php echo medicalCenterPatientVisitStatusBadgeClass('with_doctor'); ?>"><?php echo $withDoctorCount; ?></span>
            </div>
            <div class="queue-column-body">
                <?php if ($withDoctorPatients === []): ?>
                    <div class="queue-empty">
                        <i class="bi bi-door-closed"></i>
                        <p class="mb-0">هیچ نەخۆشێک لە ژوورەوە نییە</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($withDoctorPatients as $patient): ?>
                        <div class="queue-patient-card">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <h6 class="mb-0 queue-patient-name"><?php echo htmlspecialchars((string)$patient['name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                <span class="badge <?php echo medicalCenterPatientVisitStatusBadgeClass('with_doctor'); ?>">لای دکتۆر</span>
                            </div>
                            <?php echo renderSecretaryQueuePatientMeta($patient); ?>
                            <?php if (!empty($patient['visit_status_updated_at'])): ?>
                                <small class="text-body-secondary d-block mt-2">
                                    <i class="bi bi-clock"></i>
                                    چووە ژوورەوە: <?php echo htmlspecialchars((string)$patient['visit_status_updated_at'], ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                            <?php endif; ?>
                            <div class="queue-patient-actions mt-3">
                                <a href="<?php echo url('professions/medical-center/secretary/patients/index.php?edit=' . (int)$patient['id']); ?>"
                                   class="btn btn-outline-secondary btn-sm w-100">
                                    <i class="bi bi-pencil-square"></i> دەستکاری زانیاری
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="queue-column queue-column-completed">
            <div class="queue-column-header">
                <span class="queue-column-icon"><i class="bi bi-check-circle"></i></span>
                <div>
                    <h5 class="mb-0">تەواو (ئەمڕۆ)</h5>
                    <small class="text-body-secondary">سەردانیان تەواو بووە</small>
                </div>
                <span class="badge rounded-pill <?php echo medicalCenterPatientVisitStatusBadgeClass('completed'); ?>"><?php echo $completedCount; ?></span>
            </div>
            <div class="queue-column-body">
                <?php if ($completedTodayPatients === []): ?>
                    <div class="queue-empty">
                        <i class="bi bi-clipboard-check"></i>
                        <p class="mb-0">هێشتا هیچ سەردانێک تەواو نەبووە</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($completedTodayPatients as $patient): ?>
                        <div class="queue-patient-card queue-patient-card-completed">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <h6 class="mb-0 queue-patient-name"><?php echo htmlspecialchars((string)$patient['name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                <span class="badge <?php echo medicalCenterPatientVisitStatusBadgeClass('completed'); ?>">تەواو</span>
                            </div>
                            <?php echo renderSecretaryQueuePatientMeta($patient); ?>
                            <?php if (!empty($patient['visit_status_updated_at'])): ?>
                                <small class="text-body-secondary d-block mt-2">
                                    <i class="bi bi-clock-history"></i>
                                    <?php echo htmlspecialchars((string)$patient['visit_status_updated_at'], ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                            <?php endif; ?>
                            <div class="queue-patient-actions mt-3">
                                <a href="<?php echo url('professions/medical-center/secretary/patients/index.php?edit=' . (int)$patient['id']); ?>"
                                   class="btn btn-outline-secondary btn-sm w-100">
                                    <i class="bi bi-pencil-square"></i> دەستکاری زانیاری
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
