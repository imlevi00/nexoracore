<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/kasher_platform/database.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once dirname(__DIR__, 2) . '/professions/medical-center/secretary/includes/secretary_service.php';

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
    $db = new Database();
    $GLOBALS['conn'] = $db->connect();
}
$conn = $GLOBALS['conn'];

$isMedicalCenterMode = false;
$settingsStmt = $conn->prepare("
    SELECT s.business_type_id, bt.code AS business_type_code
    FROM settings s
    LEFT JOIN business_types bt ON bt.id = s.business_type_id
    WHERE s.user_id = ?
    LIMIT 1
");
if ($settingsStmt) {
    $settingsStmt->bind_param('i', $userId);
    $settingsStmt->execute();
    $settingsRow = $settingsStmt->get_result()->fetch_assoc();
    $settingsStmt->close();
    $businessTypeId = (int)($settingsRow['business_type_id'] ?? 0);
    $businessTypeCode = trim((string)($settingsRow['business_type_code'] ?? ''));
    $isMedicalCenterMode = ($businessTypeId === 3 || $businessTypeCode === 'pharmacy_and_medical_center');
}
if (!$isMedicalCenterMode) {
    setMessage('ئەم بەشە تەنها بۆ دەرمانخانە و سەنتەری پزیشکییە', 'warning');
    redirect(url('user/dashboard/index.php'));
}

requireMedicalStaffModuleAccess();

if (!($conn_kasher_platform instanceof mysqli)) {
    setMessage('داتابەیسی kasher_platform بەردەست نییە', 'danger');
    redirect(url('user/medical_staff/main.php'));
}

$errors = [];
$csrfToken = Security::generateCSRFToken();
$editSecretaryId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$secretaryForm = [
    'doctor_id' => 0,
    'assigned_doctor_ids' => [],
    'name' => '',
    'mobile' => '',
    'email' => ''
];

if ($editSecretaryId > 0) {
    $editStmt = $conn_kasher_platform->prepare("
        SELECT id, doctor_id, name, mobile, email
        FROM doctor_secretaries
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    if ($editStmt) {
        $editStmt->bind_param('ii', $editSecretaryId, $userId);
        $editStmt->execute();
        $editRow = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
        if ($editRow) {
            $secretaryForm['doctor_id'] = (int)$editRow['doctor_id'];
            $secretaryForm['name'] = (string)$editRow['name'];
            $secretaryForm['mobile'] = (string)$editRow['mobile'];
            $secretaryForm['email'] = (string)$editRow['email'];
            $secretaryForm['assigned_doctor_ids'] = getSecretaryAssignedDoctorIds(
                $conn_kasher_platform,
                $userId,
                $editSecretaryId
            );
            if ($secretaryForm['assigned_doctor_ids'] === []) {
                $secretaryForm['assigned_doctor_ids'] = [$secretaryForm['doctor_id']];
            }
        } else {
            $editSecretaryId = 0;
            $errors[] = 'سکرتێری داواکراو نەدۆزرایەوە';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'add' || $action === 'update') {
            $secretaryId = (int)($_POST['secretary_id'] ?? 0);
            $doctorId = (int)($_POST['doctor_id'] ?? 0);
            $assignedDoctorIds = array_map('intval', (array)($_POST['assigned_doctor_ids'] ?? []));
            $assignedDoctorIds = array_values(array_unique(array_filter($assignedDoctorIds, static fn(int $id): bool => $id > 0)));
            $name = trim((string)($_POST['name'] ?? ''));
            $mobile = trim((string)($_POST['mobile'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($doctorId <= 0 || $name === '' || $mobile === '' || $email === '') {
                $errors[] = 'تکایە هەموو خانەکان پڕبکەرەوە';
            }
            if ($assignedDoctorIds === []) {
                $errors[] = 'تکایە لانیکەم یەک دکتۆر بۆ سکرتێر دیاری بکە';
            }
            if ($doctorId > 0 && !in_array($doctorId, $assignedDoctorIds, true)) {
                $errors[] = 'دکتۆری سەرەکی دەبێت لە ناو دکتۆرە دیاریکراوەکان بێت';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'ئیمەیڵ نادروستە';
            }
            if ($action === 'add' && $password === '') {
                $errors[] = 'پاسۆرد پێویستە';
            }
            if ($password !== '' && mb_strlen($password) < 6) {
                $errors[] = 'پاسۆرد دەبێت لانیکەم 6 پیت بێت';
            }

            $doctorCheckStmt = $conn_kasher_platform->prepare("SELECT id FROM doctors WHERE id = ? AND user_id = ? LIMIT 1");
            $doctorCheckStmt->bind_param('ii', $doctorId, $userId);
            $doctorCheckStmt->execute();
            $doctorExists = $doctorCheckStmt->get_result()->num_rows > 0;
            $doctorCheckStmt->close();
            if (!$doctorExists) {
                $errors[] = 'پزیشکی سەرەکی دیاریکراو نادروستە';
            }

            if (empty($errors)) {
                if ($action === 'add') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $insertStmt = $conn_kasher_platform->prepare("
                        INSERT INTO doctor_secretaries (user_id, doctor_id, name, email, password_hash, mobile, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    if ($insertStmt) {
                        $insertStmt->bind_param('iissss', $userId, $doctorId, $name, $email, $passwordHash, $mobile);
                        if ($insertStmt->execute()) {
                            $newSecretaryId = (int)$insertStmt->insert_id;
                            $insertStmt->close();
                            if (syncSecretaryAssignedDoctors($conn_kasher_platform, $userId, $newSecretaryId, $assignedDoctorIds)) {
                                setMessage('سکرتێر بە سەرکەوتوویی زیادکرا', 'success');
                                redirect(url('user/medical_staff/secretaries.php'));
                            }
                            $errors[] = 'سکرتێر زیادکرا بەڵام هەڵەیەک لە دیاریکردنی دکتۆرەکان ڕوویدا';
                        } else {
                            if ((int)$conn_kasher_platform->errno === 1062) {
                                $errors[] = 'ئەم ئیمەیڵە پێشتر تۆمارکراوە';
                            } else {
                                $errors[] = 'هەڵەیەک لە زیادکردنی سکرتێر ڕوویدا';
                            }
                            $insertStmt->close();
                        }
                    } else {
                        $errors[] = 'هەڵەیەک ڕوویدا لە ئامادەکردنی داتا';
                    }
                } else {
                    if ($secretaryId <= 0) {
                        $errors[] = 'ناسنامەی سکرتێر نادروستە';
                    } else {
                        if ($password !== '') {
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            $updateStmt = $conn_kasher_platform->prepare("
                                UPDATE doctor_secretaries
                                SET doctor_id = ?, name = ?, mobile = ?, email = ?, password_hash = ?, updated_at = NOW()
                                WHERE id = ? AND user_id = ?
                            ");
                            $updateStmt->bind_param('issssii', $doctorId, $name, $mobile, $email, $passwordHash, $secretaryId, $userId);
                        } else {
                            $updateStmt = $conn_kasher_platform->prepare("
                                UPDATE doctor_secretaries
                                SET doctor_id = ?, name = ?, mobile = ?, email = ?, updated_at = NOW()
                                WHERE id = ? AND user_id = ?
                            ");
                            $updateStmt->bind_param('isssii', $doctorId, $name, $mobile, $email, $secretaryId, $userId);
                        }

                        if ($updateStmt && $updateStmt->execute()) {
                            $updateStmt->close();
                            if (syncSecretaryAssignedDoctors($conn_kasher_platform, $userId, $secretaryId, $assignedDoctorIds)) {
                                setMessage('زانیاری سکرتێر نوێکرایەوە', 'success');
                                redirect(url('user/medical_staff/secretaries.php'));
                            }
                            $errors[] = 'زانیاری سکرتێر نوێکرایەوە بەڵام هەڵەیەک لە دیاریکردنی دکتۆرەکان ڕوویدا';
                        } elseif ($updateStmt) {
                            if ((int)$conn_kasher_platform->errno === 1062) {
                                $errors[] = 'ئەم ئیمەیڵە پێشتر تۆمارکراوە';
                            } else {
                                $errors[] = 'هەڵەیەک لە نوێکردنەوەی سکرتێر ڕوویدا';
                            }
                            $updateStmt->close();
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە ئامادەکردنی نوێکردنەوە';
                        }
                    }
                }
            }
        } elseif ($action === 'delete') {
            $secretaryId = (int)($_POST['secretary_id'] ?? 0);
            if ($secretaryId <= 0) {
                $errors[] = 'ناسنامەی سکرتێر نادروستە';
            } else {
                $deleteStmt = $conn_kasher_platform->prepare("
                    DELETE FROM doctor_secretaries
                    WHERE id = ? AND user_id = ?
                ");
                $deleteStmt->bind_param('ii', $secretaryId, $userId);
                if ($deleteStmt->execute()) {
                    setMessage('سکرتێر بە سەرکەوتوویی سڕایەوە', 'success');
                    $deleteStmt->close();
                    redirect(url('user/medical_staff/secretaries.php'));
                }
                $errors[] = 'هەڵەیەک لە سڕینەوە ڕوویدا';
                $deleteStmt->close();
            }
        }
    }
}

$doctors = [];
$doctorsStmt = $conn_kasher_platform->prepare("
    SELECT id, name
    FROM doctors
    WHERE user_id = ?
    ORDER BY name ASC
");
if ($doctorsStmt) {
    $doctorsStmt->bind_param('i', $userId);
    $doctorsStmt->execute();
    $doctors = $doctorsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $doctorsStmt->close();
}

$secretaries = [];
$secretariesStmt = $conn_kasher_platform->prepare("
    SELECT s.id, s.name, s.email, s.mobile, s.created_at, d.name AS doctor_name
    FROM doctor_secretaries s
    INNER JOIN doctors d ON d.id = s.doctor_id AND d.user_id = s.user_id
    WHERE s.user_id = ?
    ORDER BY s.id DESC
");
if ($secretariesStmt) {
    $secretariesStmt->bind_param('i', $userId);
    $secretariesStmt->execute();
    $secretaries = $secretariesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $secretariesStmt->close();
}

$assignedDoctorsBySecretary = [];
if (!empty($secretaries)) {
    $secretaryIds = array_map(static fn(array $row): int => (int)$row['id'], $secretaries);
    $placeholders = implode(',', array_fill(0, count($secretaryIds), '?'));
    $types = 'i' . str_repeat('i', count($secretaryIds));
    $params = array_merge([$userId], $secretaryIds);
    $assignedSql = "
        SELECT sd.secretary_id, d.name
        FROM doctor_secretary_doctors sd
        INNER JOIN doctors d ON d.id = sd.doctor_id AND d.user_id = sd.user_id
        WHERE sd.user_id = ? AND sd.secretary_id IN ($placeholders)
        ORDER BY d.name ASC
    ";
    $assignedStmt = $conn_kasher_platform->prepare($assignedSql);
    if ($assignedStmt) {
        $assignedStmt->bind_param($types, ...$params);
        $assignedStmt->execute();
        $assignedRows = $assignedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $assignedStmt->close();
        foreach ($assignedRows as $assignedRow) {
            $sid = (int)$assignedRow['secretary_id'];
            if (!isset($assignedDoctorsBySecretary[$sid])) {
                $assignedDoctorsBySecretary[$sid] = [];
            }
            $assignedDoctorsBySecretary[$sid][] = (string)$assignedRow['name'];
        }
    }
}

$flashMessage = getMessage();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>سکرتێرەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_staff/medical-staff-dark.css'); ?>" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('user/medical_staff/main.php'); ?>"><i class="bi bi-person-workspace"></i> سکرتێرەکان</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="<?php echo url('user/medical_staff/main.php'); ?>"><i class="bi bi-arrow-right"></i> گەڕانەوە</a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3"><?php echo $editSecretaryId > 0 ? 'دەستکاری سکرتێر' : 'زیادکردنی سکرتێر'; ?></h5>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="<?php echo $editSecretaryId > 0 ? 'update' : 'add'; ?>">
                <input type="hidden" name="secretary_id" value="<?php echo (int)$editSecretaryId; ?>">
                <div class="col-md-3">
                    <label class="form-label">دکتۆری سەرەکی</label>
                    <select class="form-select" name="doctor_id" required>
                        <option value="">هەڵبژێرە...</option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?php echo (int)$doctor['id']; ?>" <?php echo ((int)$secretaryForm['doctor_id'] === (int)$doctor['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($doctor['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ناوی سکرتێر</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($secretaryForm['name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">مۆبایل</label>
                    <input type="text" name="mobile" class="form-control" required value="<?php echo htmlspecialchars($secretaryForm['mobile'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">ئیمەیڵ</label>
                    <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($secretaryForm['email'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">پاسۆرد <?php if ($editSecretaryId > 0): ?><small class="text-muted">(ئەگەر خالی بێت، ناگۆڕدرێت)</small><?php endif; ?></label>
                    <input type="password" name="password" class="form-control" minlength="6" <?php echo $editSecretaryId > 0 ? '' : 'required'; ?>>
                </div>
                <div class="col-12">
                    <label class="form-label">دکتۆرە دیاریکراوەکان بۆ ناردنی نەخۆش</label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($doctors as $doctor): ?>
                            <?php $doctorId = (int)$doctor['id']; ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="assigned_doctor_ids[]"
                                       value="<?php echo $doctorId; ?>" id="doctor_<?php echo $doctorId; ?>"
                                    <?php echo in_array($doctorId, $secretaryForm['assigned_doctor_ids'], true) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="doctor_<?php echo $doctorId; ?>">
                                    <?php echo htmlspecialchars($doctor['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-body-secondary">سکرتێر دەتوانێت نەخۆش تەنها بۆ ئەم دکتۆرانە بنێرێت.</small>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi <?php echo $editSecretaryId > 0 ? 'bi-check2-circle' : 'bi-plus-circle'; ?>"></i>
                        <?php echo $editSecretaryId > 0 ? 'نوێکردنەوە' : 'زیادکردن'; ?>
                    </button>
                    <?php if ($editSecretaryId > 0): ?>
                        <a href="<?php echo url('user/medical_staff/secretaries.php'); ?>" class="btn btn-outline-secondary">هەڵوەشاندنەوە</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">لیستی سکرتێرەکان</h5>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ناو</th>
                            <th>دکتۆری سەرەکی</th>
                            <th>دکتۆرە دیاریکراوەکان</th>
                            <th>ئیمەیڵ</th>
                            <th>مۆبایل</th>
                            <th>بەروار</th>
                            <th class="text-center">کردار</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($secretaries)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">هێشتا هیچ سکرتێرێک تۆمار نەکراوە</td></tr>
                    <?php else: ?>
                        <?php foreach ($secretaries as $secretary): ?>
                            <?php
                            $sid = (int)$secretary['id'];
                            $assignedNames = $assignedDoctorsBySecretary[$sid] ?? [(string)$secretary['doctor_name']];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($secretary['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($secretary['doctor_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(implode('، ', $assignedNames), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($secretary['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($secretary['mobile'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$secretary['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center">
                                    <div class="d-flex gap-2 justify-content-center">
                                        <a href="<?php echo url('user/medical_staff/secretaries.php?edit=' . $sid); ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil-square"></i> دەستکاری
                                        </a>
                                        <form method="post" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم سکرتێرە؟');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="secretary_id" value="<?php echo $sid; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> سڕینەوە</button>
                                        </form>
                                    </div>
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
