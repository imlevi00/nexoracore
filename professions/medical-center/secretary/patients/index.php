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

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;



$assignedDoctors = getSecretaryAssignedDoctors($conn_kasher_platform, $userId, $secretaryId);

$hasAssignedDoctors = $assignedDoctors !== [];



$todayDate = date('Y-m-d');

$form = [

    'id' => 0,

    'doctor_id' => 0,

    'name' => '',

    'mobile' => '',

    'age' => '',

    'age_months' => '',

    'gender' => '',

    'profession' => '',

    'blood_type' => '',

    'address' => '',

    'appointment_date' => $todayDate,

    'appointment_time' => '',

    'appointment_duration' => medicalCenterDefaultAppointmentDuration()

];



if ($editId > 0) {

    $editStmt = $conn_kasher_platform->prepare("

        SELECT id, doctor_id, name, mobile, age, age_months, gender, profession, blood_type, address, appointment_date, appointment_time, appointment_end_time

        FROM medical_center_patients

        WHERE id = ? AND user_id = ? AND created_by_secretary_id = ?

        LIMIT 1

    ");

    if ($editStmt) {

        $editStmt->bind_param('iii', $editId, $userId, $secretaryId);

        $editStmt->execute();

        $row = $editStmt->get_result()->fetch_assoc();

        $editStmt->close();

        if ($row) {

            $form['id'] = (int)$row['id'];

            $form['doctor_id'] = (int)$row['doctor_id'];

            $form['name'] = (string)$row['name'];

            $form['mobile'] = (string)$row['mobile'];

            $form['age'] = (string)$row['age'];

            $form['age_months'] = $row['age_months'] !== null ? (string)$row['age_months'] : '';

            $form['gender'] = (string)($row['gender'] ?? '');

            $form['profession'] = (string)($row['profession'] ?? '');

            $form['blood_type'] = (string)($row['blood_type'] ?? '');

            $form['address'] = (string)($row['address'] ?? '');

            $form['appointment_date'] = medicalCenterNormalizeAppointmentDate((string)($row['appointment_date'] ?? '')) ?? $todayDate;

            $form['appointment_time'] = medicalCenterFormatTimeLabel($row['appointment_time'] ?? null);

            $form['appointment_duration'] = medicalCenterAppointmentDurationFromRange(

                $row['appointment_time'] ?? null,

                $row['appointment_end_time'] ?? null

            );

        }

    }

} elseif ($hasAssignedDoctors) {

    $form['doctor_id'] = (int)$assignedDoctors[0]['id'];

}



// Scheduling hints for the form: the next free slot per doctor for today, plus
// a rounded "now" fallback when a doctor has no appointments booked yet.
$assignedDoctorIds = array_map(static fn(array $d): int => (int)$d['id'], $assignedDoctors);

$doctorNextSlots = getSecretaryDoctorsNextSlot($conn_kasher_platform, $userId, $assignedDoctorIds, $todayDate);

$appointmentFallbackTime = medicalCenterRoundedNowTime();

$appointmentDurations = medicalCenterAppointmentDurations();



$patients = [];

$stmt = $conn_kasher_platform->prepare("

    SELECT p.id, p.name, p.mobile, p.age, p.age_months, p.gender, p.visit_status, p.created_at,

           p.appointment_date, p.appointment_time, p.appointment_end_time, d.name AS doctor_name

    FROM medical_center_patients p

    INNER JOIN doctors d ON d.id = p.doctor_id AND d.user_id = p.user_id

    WHERE p.user_id = ? AND p.created_by_secretary_id = ?

    ORDER BY p.id DESC

");

if ($stmt) {

    $stmt->bind_param('ii', $userId, $secretaryId);

    $stmt->execute();

    $patients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

}

?>

<!DOCTYPE html>

<html lang="ku" dir="rtl">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php echo kasher_get_theme_bootstrap_markup(); ?>

    <title>بەڕێوەبردنی نەخۆشەکان - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">

    <link href="<?php echo asset('css/medical_center/secretary.css'); ?>" rel="stylesheet">

    <script src="<?php echo asset('js/medical_center/secretary-appointment.js'); ?>" defer></script>

</head>

<body class="bg-body-secondary">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">

    <div class="container-fluid">

        <a class="navbar-brand" href="<?php echo url('professions/medical-center/secretary/dashboard/index.php'); ?>">

            <i class="bi bi-arrow-right-circle"></i> گەڕانەوە بۆ داشبۆرد

        </a>

        <div class="navbar-nav ms-auto">

            <a class="nav-link" href="<?php echo url('professions/medical-center/secretary/auth/logout.php'); ?>">چوونەدەرەوە</a>

        </div>

    </div>

</nav>



<div class="container py-4">

    <?php if ($flashMessage): ?>

        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>">

            <?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?>

        </div>

    <?php endif; ?>



    <?php if (!$hasAssignedDoctors): ?>

        <div class="alert alert-warning">

            هیچ دکتۆرێکت دیاری نەکراوە بۆ ناردنی نەخۆش. تکایە پەیوەندی بە بەڕێوەبەرەکەت بکە.

        </div>

    <?php else: ?>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <h5 class="mb-3"><?php echo $form['id'] > 0 ? 'دەستکاری نەخۆش' : 'زیادکردنی نەخۆش'; ?></h5>

            <form method="post" action="<?php echo url('professions/medical-center/secretary/patients/save.php'); ?>" class="row g-3"
                  data-appt-scope
                  data-appt-today="<?php echo htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8'); ?>"
                  data-appt-fallback="<?php echo htmlspecialchars($appointmentFallbackTime, ENT_QUOTES, 'UTF-8'); ?>"
                  data-appt-nextslots="<?php echo htmlspecialchars(json_encode($doctorNextSlots, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <input type="hidden" name="patient_id" value="<?php echo (int)$form['id']; ?>">

                <div class="col-md-3">

                    <label class="form-label">ناوی نەخۆش</label>

                    <input type="text" name="name" class="form-control" required maxlength="150"

                           value="<?php echo htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8'); ?>">

                </div>

                <div class="col-md-2">

                    <label class="form-label">مۆبایل</label>

                    <input type="text" name="mobile" class="form-control" required maxlength="30"

                           value="<?php echo htmlspecialchars($form['mobile'], ENT_QUOTES, 'UTF-8'); ?>">

                </div>

                <div class="col-md-1">

                    <label class="form-label">ساڵ</label>

                    <input type="number" name="age" class="form-control" required min="0" max="130"

                           value="<?php echo htmlspecialchars($form['age'], ENT_QUOTES, 'UTF-8'); ?>">

                </div>

                <div class="col-md-1">

                    <label class="form-label">مانگ</label>

                    <input type="number" name="age_months" class="form-control" min="0" max="11"

                           placeholder="0-11"

                           value="<?php echo htmlspecialchars($form['age_months'], ENT_QUOTES, 'UTF-8'); ?>">

                </div>

                <div class="col-md-2">

                    <label class="form-label">ڕەگەز</label>

                    <select name="gender" class="form-select" required>

                        <option value="">هەڵبژێرە...</option>

                        <option value="male" <?php echo $form['gender'] === 'male' ? 'selected' : ''; ?>>نێر</option>

                        <option value="female" <?php echo $form['gender'] === 'female' ? 'selected' : ''; ?>>مێ</option>

                    </select>

                </div>

                <div class="col-md-2">

                    <label class="form-label">پیشە <span class="text-body-secondary">(ئیختیاری)</span></label>

                    <input type="text" name="profession" class="form-control" maxlength="100"

                           value="<?php echo htmlspecialchars($form['profession'], ENT_QUOTES, 'UTF-8'); ?>">

                </div>

                <div class="col-md-1">

                    <label class="form-label">جۆری خوێن</label>

                    <select name="blood_type" class="form-select">

                        <option value="">—</option>

                        <?php foreach (medicalCenterBloodTypes() as $bloodTypeOption): ?>

                            <option value="<?php echo htmlspecialchars($bloodTypeOption, ENT_QUOTES, 'UTF-8'); ?>"

                                <?php echo $form['blood_type'] === $bloodTypeOption ? 'selected' : ''; ?>>

                                <?php echo htmlspecialchars($bloodTypeOption, ENT_QUOTES, 'UTF-8'); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-3">

                    <label class="form-label">ناونیشان <span class="text-body-secondary">(ئیختیاری)</span></label>

                    <input type="text" name="address" class="form-control" maxlength="255"

                           value="<?php echo htmlspecialchars($form['address'], ENT_QUOTES, 'UTF-8'); ?>">

                </div>

                <div class="col-md-2">

                    <label class="form-label">ناردن بۆ دکتۆر</label>

                    <select name="doctor_id" class="form-select" required data-appt-doctor>

                        <option value="">هەڵبژێرە...</option>

                        <?php foreach ($assignedDoctors as $doctor): ?>

                            <option value="<?php echo (int)$doctor['id']; ?>"

                                <?php echo ((int)$form['doctor_id'] === (int)$doctor['id']) ? 'selected' : ''; ?>>

                                <?php echo htmlspecialchars($doctor['name'], ENT_QUOTES, 'UTF-8'); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12">

                    <div class="appointment-fields">

                        <span class="appointment-fields-label"><i class="bi bi-calendar-event"></i> کاتی سەردان</span>

                        <div class="appointment-fields-grid">

                            <div class="appointment-field">

                                <label class="form-label">بەروار</label>

                                <input type="date" name="appointment_date" class="form-control" data-appt-date

                                       value="<?php echo htmlspecialchars($form['appointment_date'], ENT_QUOTES, 'UTF-8'); ?>">

                            </div>

                            <div class="appointment-field">

                                <label class="form-label">کاتی دەستپێک</label>

                                <input type="time" name="appointment_time" class="form-control" data-appt-time

                                       value="<?php echo htmlspecialchars($form['appointment_time'], ENT_QUOTES, 'UTF-8'); ?>">

                            </div>

                            <div class="appointment-field">

                                <label class="form-label">ماوە (خولەک)</label>

                                <select name="appointment_duration" class="form-select" data-appt-duration>

                                    <?php foreach ($appointmentDurations as $duration): ?>

                                        <option value="<?php echo (int)$duration; ?>"

                                            <?php echo ((int)$form['appointment_duration'] === (int)$duration) ? 'selected' : ''; ?>>

                                            <?php echo (int)$duration; ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="appointment-field appointment-field-end">

                                <span class="form-label">کۆتایی</span>

                                <span class="appointment-end-value" data-appt-end>—</span>

                            </div>

                        </div>

                        <small class="text-body-secondary d-block mt-2">

                            <i class="bi bi-info-circle"></i> بەروار بە شێوەی خۆکار بۆ ئەمڕۆیە و کاتی دەستپێک بۆ یەکەم کاتی بەتاڵی دکتۆر پێشنیار دەکرێت.

                        </small>

                    </div>

                </div>

                <div class="col-12">

                    <button class="btn btn-primary" type="submit">

                        <i class="bi bi-check2-circle"></i> <?php echo $form['id'] > 0 ? 'نوێکردنەوە' : 'زیادکردن'; ?>

                    </button>

                    <?php if ($form['id'] > 0): ?>

                        <a href="<?php echo url('professions/medical-center/secretary/patients/index.php'); ?>" class="btn btn-outline-secondary">

                            هەڵوەشاندنەوە

                        </a>

                    <?php endif; ?>

                </div>

            </form>

        </div>

    </div>

    <?php endif; ?>



    <div class="card border-0 shadow-sm">

        <div class="card-body">

            <h5 class="mb-3">لیستی نەخۆشەکان</h5>

            <div class="table-responsive">

                <table class="table align-middle">

                    <thead>

                    <tr>

                        <th>ناو</th>

                        <th>مۆبایل</th>

                        <th>تەمەن</th>

                        <th>ڕەگەز</th>

                        <th>دکتۆر</th>

                        <th>کاتی سەردان</th>

                        <th>دۆخ</th>

                        <th>بەرواری تۆمار</th>

                        <th class="text-center">کردار</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($patients)): ?>

                        <tr>

                            <td colspan="9" class="text-center text-body-secondary py-4">هێشتا نەخۆش تۆمار نەکراوە</td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($patients as $patient): ?>

                            <tr>

                                <td><?php echo htmlspecialchars((string)$patient['name'], ENT_QUOTES, 'UTF-8'); ?></td>

                                <td><?php echo htmlspecialchars((string)$patient['mobile'], ENT_QUOTES, 'UTF-8'); ?></td>

                                <td><?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$patient['age'], isset($patient['age_months']) ? (int)$patient['age_months'] : null), ENT_QUOTES, 'UTF-8'); ?></td>

                                <td><?php echo htmlspecialchars(medicalCenterPatientGenderLabel($patient['gender'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>

                                <td><?php echo htmlspecialchars((string)$patient['doctor_name'], ENT_QUOTES, 'UTF-8'); ?></td>

                                <td>
                                    <?php
                                    $apptRange = medicalCenterFormatAppointmentRangeLabel($patient['appointment_time'] ?? null, $patient['appointment_end_time'] ?? null);
                                    $apptDate = medicalCenterFormatAppointmentDateLabel($patient['appointment_date'] ?? null);
                                    ?>
                                    <?php if ($apptRange !== ''): ?>
                                        <span class="appointment-cell">
                                            <span class="appointment-cell-time"><i class="bi bi-clock"></i> <?php echo htmlspecialchars($apptRange, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if ($apptDate !== ''): ?>
                                                <small class="text-body-secondary"><?php echo htmlspecialchars($apptDate, ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-body-secondary">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge <?php echo medicalCenterPatientVisitStatusBadgeClass((string)($patient['visit_status'] ?? 'waiting')); ?>">
                                        <?php echo htmlspecialchars(medicalCenterPatientVisitStatusLabel((string)($patient['visit_status'] ?? 'waiting')), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>

                                <td><?php echo htmlspecialchars((string)$patient['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>

                                <td class="text-center">

                                    <a href="<?php echo url('professions/medical-center/secretary/patients/index.php?edit=' . (int)$patient['id']); ?>"

                                       class="btn btn-sm btn-outline-primary">

                                        دەستکاری

                                    </a>

                                    <form method="post" action="<?php echo url('professions/medical-center/secretary/patients/delete.php'); ?>"

                                          class="d-inline"

                                          onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم نەخۆشە؟');">

                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                                        <input type="hidden" name="patient_id" value="<?php echo (int)$patient['id']; ?>">

                                        <button type="submit" class="btn btn-sm btn-outline-danger">سڕینەوە</button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

</body>

</html>


