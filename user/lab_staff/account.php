<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/kasher_platform/database.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

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
    redirect(url('user/lab_staff/main.php'));
}

$errors = [];
$csrfToken = Security::generateCSRFToken();
$labForm = [
    'name' => '',
    'email' => '',
    'mobile' => ''
];
$existingLab = null;

$labStmt = $conn_kasher_platform->prepare("
    SELECT id, name, email, mobile, created_at
    FROM medical_center_labs
    WHERE user_id = ?
    LIMIT 1
");
if ($labStmt) {
    $labStmt->bind_param('i', $userId);
    $labStmt->execute();
    $existingLab = $labStmt->get_result()->fetch_assoc();
    $labStmt->close();
    if ($existingLab) {
        $labForm['name'] = (string)$existingLab['name'];
        $labForm['email'] = (string)$existingLab['email'];
        $labForm['mobile'] = (string)$existingLab['mobile'];
    }
}

$isEditMode = $existingLab !== null;
$labId = $isEditMode ? (int)$existingLab['id'] : 0;

$businessName = '';
$bizStmt = $conn->prepare("SELECT business_name FROM users WHERE id = ? LIMIT 1");
if ($bizStmt) {
    $bizStmt->bind_param('i', $userId);
    $bizStmt->execute();
    $bizRow = $bizStmt->get_result()->fetch_assoc();
    $bizStmt->close();
    $businessName = trim((string)($bizRow['business_name'] ?? ''));
}

$labLoginUrl = url('professions/medical-center/lab/auth/login.php?' . http_build_query(array_filter([
    'u' => $userId,
    'biz' => $businessName !== '' ? $businessName : null,
])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'add' || $action === 'update') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $mobile = trim((string)($_POST['mobile'] ?? ''));

            if ($name === '' || $email === '' || $mobile === '') {
                $errors[] = 'تکایە هەموو خانەکان پڕبکەرەوە';
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

            if ($action === 'add' && $existingLab !== null) {
                $errors[] = 'تەنها یەک ئەکاونتی تاقیگە دەتوانرێت دروست بکرێت';
            }

            if (empty($errors)) {
                if ($action === 'add') {
                    $countStmt = $conn_kasher_platform->prepare("
                        SELECT COUNT(*) AS total
                        FROM medical_center_labs
                        WHERE user_id = ?
                    ");
                    if ($countStmt) {
                        $countStmt->bind_param('i', $userId);
                        $countStmt->execute();
                        $countRow = $countStmt->get_result()->fetch_assoc();
                        $countStmt->close();
                        if ((int)($countRow['total'] ?? 0) > 0) {
                            $errors[] = 'تەنها یەک ئەکاونتی تاقیگە دەتوانرێت دروست بکرێت';
                        }
                    }
                }

                if (empty($errors)) {
                    if ($action === 'add') {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $insertStmt = $conn_kasher_platform->prepare("
                            INSERT INTO medical_center_labs (user_id, name, email, password_hash, mobile, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        if ($insertStmt) {
                            $insertStmt->bind_param('issss', $userId, $name, $email, $passwordHash, $mobile);
                            if ($insertStmt->execute()) {
                                setMessage('ئەکاونتی تاقیگە بە سەرکەوتوویی دروستکرا', 'success');
                                $insertStmt->close();
                                redirect(url('user/lab_staff/main.php'));
                            }
                            if ((int)$conn_kasher_platform->errno === 1062) {
                                $errors[] = 'ئەم ئیمەیڵە پێشتر تۆمارکراوە';
                            } else {
                                $errors[] = 'هەڵەیەک لە دروستکردنی ئەکاونت ڕوویدا';
                            }
                            $insertStmt->close();
                        } else {
                            $errors[] = 'هەڵەیەک ڕوویدا لە ئامادەکردنی داتا';
                        }
                    } else {
                        if ($labId <= 0) {
                            $errors[] = 'ناسنامەی تاقیگە نادروستە';
                        } else {
                            if ($password !== '') {
                                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                                $updateStmt = $conn_kasher_platform->prepare("
                                    UPDATE medical_center_labs
                                    SET name = ?, email = ?, mobile = ?, password_hash = ?, updated_at = NOW()
                                    WHERE id = ? AND user_id = ?
                                ");
                                $updateStmt->bind_param('ssssii', $name, $email, $mobile, $passwordHash, $labId, $userId);
                            } else {
                                $updateStmt = $conn_kasher_platform->prepare("
                                    UPDATE medical_center_labs
                                    SET name = ?, email = ?, mobile = ?, updated_at = NOW()
                                    WHERE id = ? AND user_id = ?
                                ");
                                $updateStmt->bind_param('sssii', $name, $email, $mobile, $labId, $userId);
                            }

                            if ($updateStmt && $updateStmt->execute()) {
                                setMessage('زانیاری ئەکاونت نوێکرایەوە', 'success');
                                $updateStmt->close();
                                redirect(url('user/lab_staff/main.php'));
                            }
                            if ($updateStmt) {
                                if ((int)$conn_kasher_platform->errno === 1062) {
                                    $errors[] = 'ئەم ئیمەیڵە پێشتر تۆمارکراوە';
                                } else {
                                    $errors[] = 'هەڵەیەک لە نوێکردنەوە ڕوویدا';
                                }
                                $updateStmt->close();
                            } else {
                                $errors[] = 'هەڵەیەک ڕوویدا لە ئامادەکردنی نوێکردنەوە';
                            }
                        }
                    }
                }
            }

            if (!empty($errors)) {
                $labForm['name'] = $name;
                $labForm['email'] = $email;
                $labForm['mobile'] = $mobile;
            }
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
    <title><?php echo $isEditMode ? 'دەستکاری ئەکاونت' : 'دروستکردنی ئەکاونت'; ?> - تاقیگە - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_staff/medical-staff-dark.css'); ?>" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('user/lab_staff/main.php'); ?>"><i class="bi bi-flask"></i> ئەکاونتی تاقیگە</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="<?php echo url('user/lab_staff/main.php'); ?>"><i class="bi bi-arrow-right"></i> گەڕانەوە</a>
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

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">
                <?php echo $isEditMode ? 'دەستکاری ئەکاونتی تاقیگە' : 'دروستکردنی ئەکاونتی تاقیگە'; ?>
            </h5>
            <?php if ($isEditMode && !empty($existingLab['created_at'])): ?>
                <p class="text-muted small mb-3">
                    <i class="bi bi-calendar3"></i>
                    دروستکراوە لە: <?php echo htmlspecialchars((string)$existingLab['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>
            <?php if ($isEditMode): ?>
                <div class="alert alert-light border mb-3">
                    <div class="fw-semibold mb-1"><i class="bi bi-box-arrow-in-right"></i> لینکی چوونەژوورەوەی تاقیگە</div>
                    <p class="small text-muted mb-2">ئەم لینکە بە staff ـی تاقیگە بدە بۆ چوونەژوورەوەی ئاسانتر.</p>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" readonly value="<?php echo htmlspecialchars($labLoginUrl, ENT_QUOTES, 'UTF-8'); ?>" id="lab-login-url">
                        <button type="button" class="btn btn-outline-primary" onclick="navigator.clipboard.writeText(document.getElementById('lab-login-url').value)">
                            <i class="bi bi-clipboard"></i> کۆپی
                        </button>
                        <a href="<?php echo htmlspecialchars($labLoginUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                            <i class="bi bi-box-arrow-up-right"></i> کردنەوە
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="<?php echo $isEditMode ? 'update' : 'add'; ?>">
                <div class="col-md-3">
                    <label class="form-label">ناوی تاقیگە</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($labForm['name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">ئیمەیڵ</label>
                    <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($labForm['email'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">پاسۆرد <?php if ($isEditMode): ?><small class="text-muted">(ئەگەر خالی بێت، ناگۆڕدرێت)</small><?php endif; ?></label>
                    <input type="password" name="password" class="form-control" minlength="6" <?php echo $isEditMode ? '' : 'required'; ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ژمارەی مۆبایل</label>
                    <input type="text" name="mobile" class="form-control" required value="<?php echo htmlspecialchars($labForm['mobile'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi <?php echo $isEditMode ? 'bi-check2-circle' : 'bi-plus-circle'; ?>"></i>
                        <?php echo $isEditMode ? 'نوێکردنەوە' : 'دروستکردن'; ?>
                    </button>
                    <a href="<?php echo url('user/lab_staff/main.php'); ?>" class="btn btn-outline-secondary">گەڕانەوە</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
