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

requireCosmeticStaffModuleAccess();

if (!($conn_kasher_platform instanceof mysqli)) {
    setMessage('داتابەیسی kasher_platform بەردەست نییە', 'danger');
    redirect(url('user/cosmetic_staff/main.php'));
}

$errors = [];
$csrfToken = Security::generateCSRFToken();
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$form = ['name' => '', 'email' => '', 'mobile' => ''];

if ($editId > 0) {
    $editStmt = $conn_kasher_platform->prepare("
        SELECT id, name, email, mobile
        FROM cosmetic_center_accounts
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    if ($editStmt) {
        $editStmt->bind_param('ii', $editId, $userId);
        $editStmt->execute();
        $editRow = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
        if ($editRow) {
            $form['name'] = (string)$editRow['name'];
            $form['email'] = (string)$editRow['email'];
            $form['mobile'] = (string)$editRow['mobile'];
        } else {
            $editId = 0;
            $errors[] = 'ئەکاونتەکە نەدۆزرایەوە';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'add' || $action === 'update') {
            $accountId = (int)($_POST['account_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $mobile = trim((string)($_POST['mobile'] ?? ''));

            if ($name === '' || $email === '' || $mobile === '') {
                $errors[] = 'تکایە هەموو خانە پێویستەکان پڕبکەرەوە';
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

            if (empty($errors)) {
                if ($action === 'add') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $insertStmt = $conn_kasher_platform->prepare("
                        INSERT INTO cosmetic_center_accounts (user_id, name, email, password_hash, mobile, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    if ($insertStmt) {
                        $insertStmt->bind_param('issss', $userId, $name, $email, $passwordHash, $mobile);
                        if ($insertStmt->execute()) {
                            setMessage('ئەکاونتی سەنتەر بە سەرکەوتوویی دروستکرا', 'success');
                            $insertStmt->close();
                            redirect(url('user/cosmetic_staff/center_accounts.php'));
                        }
                        if ((int)$conn_kasher_platform->errno === 1062) {
                            $errors[] = 'ئەم ئیمەیڵە پێشتر بۆ ئەم کارگەیە تۆمارکراوە';
                        } else {
                            $errors[] = 'هەڵەیەک لە دروستکردن ڕوویدا';
                        }
                        $insertStmt->close();
                    } else {
                        $errors[] = 'هەڵە لە ئامادەکردنی داواکاری';
                    }
                } else {
                    if ($accountId <= 0) {
                        $errors[] = 'ناسنامە نادروستە';
                    } else {
                        if ($password !== '') {
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            $updateStmt = $conn_kasher_platform->prepare("
                                UPDATE cosmetic_center_accounts
                                SET name = ?, email = ?, mobile = ?, password_hash = ?, updated_at = NOW()
                                WHERE id = ? AND user_id = ?
                            ");
                            $updateStmt->bind_param('ssssii', $name, $email, $mobile, $passwordHash, $accountId, $userId);
                        } else {
                            $updateStmt = $conn_kasher_platform->prepare("
                                UPDATE cosmetic_center_accounts
                                SET name = ?, email = ?, mobile = ?, updated_at = NOW()
                                WHERE id = ? AND user_id = ?
                            ");
                            $updateStmt->bind_param('sssii', $name, $email, $mobile, $accountId, $userId);
                        }
                        if ($updateStmt && $updateStmt->execute()) {
                            setMessage('ئەکاونت نوێکرایەوە', 'success');
                            $updateStmt->close();
                            redirect(url('user/cosmetic_staff/center_accounts.php'));
                        }
                        if ($updateStmt) {
                            if ((int)$conn_kasher_platform->errno === 1062) {
                                $errors[] = 'ئەم ئیمەیڵە پێشتر تۆمارکراوە';
                            } else {
                                $errors[] = 'هەڵە لە نوێکردنەوە';
                            }
                            $updateStmt->close();
                        } else {
                            $errors[] = 'هەڵە لە ئامادەکردنی نوێکردنەوە';
                        }
                    }
                }
            }
        } elseif ($action === 'delete') {
            $accountId = (int)($_POST['account_id'] ?? 0);
            if ($accountId <= 0) {
                $errors[] = 'ناسنامە نادروستە';
            } else {
                $deleteStmt = $conn_kasher_platform->prepare("
                    DELETE FROM cosmetic_center_accounts
                    WHERE id = ? AND user_id = ?
                ");
                $deleteStmt->bind_param('ii', $accountId, $userId);
                if ($deleteStmt->execute()) {
                    setMessage('ئەکاونت سڕایەوە', 'success');
                    $deleteStmt->close();
                    redirect(url('user/cosmetic_staff/center_accounts.php'));
                }
                $errors[] = 'سڕینەوە سەرکەوتوو نەبوو';
                $deleteStmt->close();
            }
        }
    }
}

$rows = [];
$listStmt = $conn_kasher_platform->prepare("
    SELECT id, name, email, mobile, created_at
    FROM cosmetic_center_accounts
    WHERE user_id = ?
    ORDER BY id DESC
");
if ($listStmt) {
    $listStmt->bind_param('i', $userId);
    $listStmt->execute();
    $rows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $listStmt->close();
}

$flashMessage = getMessage();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ئەکاونتی سەنتەری جوانکاری - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_staff/medical-staff-dark.css'); ?>" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('user/cosmetic_staff/main.php'); ?>"><i class="bi bi-building"></i> سەنتەری جوانکاری</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="<?php echo url('user/cosmetic_staff/main.php'); ?>"><i class="bi bi-arrow-right"></i> گەڕانەوە</a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3"><?php echo $editId > 0 ? 'دەستکاری ئەکاونت' : 'ئەکاونتی نوێ'; ?></h5>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="<?php echo $editId > 0 ? 'update' : 'add'; ?>">
                <input type="hidden" name="account_id" value="<?php echo (int)$editId; ?>">
                <div class="col-md-3">
                    <label class="form-label">ناو</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">ئیمەیڵ</label>
                    <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">پاسۆرد <?php if ($editId > 0): ?><small class="text-muted">(بەتاڵ = بێ گۆڕان)</small><?php endif; ?></label>
                    <input type="password" name="password" class="form-control" minlength="6" <?php echo $editId > 0 ? '' : 'required'; ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">مۆبایل</label>
                    <input type="text" name="mobile" class="form-control" required value="<?php echo htmlspecialchars($form['mobile'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> <?php echo $editId > 0 ? 'نوێکردنەوە' : 'دروستکردن'; ?></button>
                    <?php if ($editId > 0): ?><a href="<?php echo url('user/cosmetic_staff/center_accounts.php'); ?>" class="btn btn-outline-secondary">هەڵوەشاندنەوە</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">لیست</h5>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>ناو</th><th>ئیمەیڵ</th><th>مۆبایل</th><th>بەروار</th><th class="text-center">کردار</th></tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">هێشتا ئەکاونت نییە</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['mobile'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$r['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-center">
                                <a href="<?php echo url('user/cosmetic_staff/center_accounts.php?edit=' . (int)$r['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('دڵنیایت؟');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="account_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
