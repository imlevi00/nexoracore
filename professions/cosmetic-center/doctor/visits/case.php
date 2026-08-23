<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__, 2) . '/includes/cosmetic_case_scope.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}

$session = cosmeticDoctorSession();
$userId = (int)$session['user_id'];
$creator = cosmetic_case_creator_from_doctor_session($session);
$creatorRole = $creator['role'];
$creatorAccountId = $creator['account_id'];
$caseId = (int)($_GET['id'] ?? 0);

if ($caseId <= 0) {
    setMessage('ناسنامە نادروستە', 'danger');
    redirect(url('professions/cosmetic-center/doctor/visits/index.php'));
}

$csrfToken = Security::generateCSRFToken();
$flashMessage = getMessage();

$st = $conn_kasher_platform->prepare(
    'SELECT * FROM cosmetic_client_cases WHERE id = ? AND user_id = ? AND created_by_role = ? AND created_by_account_id = ? LIMIT 1'
);
$st->bind_param('iisi', $caseId, $userId, $creatorRole, $creatorAccountId);
$st->execute();
$caseRow = $st->get_result()->fetch_assoc();
$st->close();
if (!$caseRow) {
    setMessage('تۆمار نەدۆزرایەوە', 'danger');
    redirect(url('professions/cosmetic-center/doctor/visits/index.php'));
}

$sessions = [];
$lst = $conn_kasher_platform->prepare(
    'SELECT * FROM cosmetic_client_sessions WHERE case_id = ? ORDER BY session_number ASC, id ASC'
);
$lst->bind_param('i', $caseId);
$lst->execute();
$sessions = $lst->get_result()->fetch_all(MYSQLI_ASSOC);
$lst->close();

$form = [
    'client_name' => (string)$caseRow['client_name'],
    'mobile' => (string)$caseRow['mobile'],
    'age' => (string)$caseRow['age'],
    'sessions_planned' => (string)$caseRow['sessions_planned'],
    'work_type' => (string)$caseRow['work_type'],
];

$planned = (int)$caseRow['sessions_planned'];
$doneCount = count($sessions);
$nextSuggestion = $doneCount > 0 ? (int)$sessions[$doneCount - 1]['session_number'] + 1 : 1;
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once dirname(__DIR__, 4) . '/includes/theme_bootstrap.php'; echo kasher_get_theme_bootstrap_markup(); ?>
    <title>وردەکالی کەیس - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark cosmetic-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('professions/cosmetic-center/doctor/visits/index.php'); ?>"><i class="bi bi-arrow-right"></i> لیست</a>
        <span class="navbar-text text-white small">کەیسی #<?php echo $caseId; ?></span>
    </div>
</nav>

<div class="container py-4">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5 class="mb-3">دەستکاری کەیس</h5>
            <p class="text-body-secondary small mb-3">دروستکراوە: <?php echo htmlspecialchars((string)$caseRow['created_by_role'], ENT_QUOTES, 'UTF-8'); ?> — کۆتا نوێکردنەوە: <?php echo htmlspecialchars((string)$caseRow['updated_by_role'], ENT_QUOTES, 'UTF-8'); ?></p>
            <form method="post" action="<?php echo url('professions/cosmetic-center/doctor/visits/save.php'); ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="record_id" value="<?php echo (int)$caseId; ?>">
                <div class="col-md-4">
                    <label class="form-label">ناو</label>
                    <input type="text" name="client_name" class="form-control" required maxlength="150" value="<?php echo htmlspecialchars($form['client_name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">تەمەن</label>
                    <input type="number" name="age" class="form-control" required min="0" max="130" value="<?php echo htmlspecialchars($form['age'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">پلانی جەلسە</label>
                    <input type="number" name="sessions_planned" class="form-control" required min="1" value="<?php echo htmlspecialchars($form['sessions_planned'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">جۆری ئیش</label>
                    <input type="text" name="work_type" class="form-control" required maxlength="255" value="<?php echo htmlspecialchars($form['work_type'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">مۆبایل</label>
                    <input type="text" name="mobile" class="form-control" required maxlength="30" value="<?php echo htmlspecialchars($form['mobile'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <input type="hidden" name="price" value="0">
                <input type="hidden" name="discount" value="0">
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle"></i> نوێکردنەوە</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-3"><i class="bi bi-calendar-plus"></i> تۆماری جەلسەی نوێ</h6>
                    <?php if ($nextSuggestion > $planned): ?>
                        <p class="text-warning small mb-0">پێشتر ژمارەی جەلسەکان گەیشتووەتە سنووری پلان. پلان بەرز بکەرەوە بۆ زیادکردن.</p>
                    <?php else: ?>
                        <form method="post" action="<?php echo url('professions/cosmetic-center/doctor/visits/session_save.php'); ?>" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="case_id" value="<?php echo (int)$caseId; ?>">
                            <div class="col-12">
                                <label class="form-label small">بەروار</label>
                                <input type="date" name="session_date" class="form-control form-control-sm" required value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">نرخی ئەم جەلسەیە</label>
                                <input type="text" name="price" class="form-control form-control-sm" required inputmode="decimal" value="0">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">داشکاندن</label>
                                <input type="text" name="discount" class="form-control form-control-sm" required inputmode="decimal" value="0">
                            </div>
                            <div class="col-12">
                                <label class="form-label small">تێبینی</label>
                                <input type="text" name="notes" class="form-control form-control-sm" maxlength="500" placeholder="ئارەزوومەندانە">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-success">جەلسەی <?php echo (int)$nextSuggestion; ?> تۆمار بکە</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="mb-3">مێژووی جەلسەکان <span class="badge bg-secondary"><?php echo $doneCount; ?>/<?php echo $planned; ?></span></h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>#</th><th>بەروار</th><th>نرخ</th><th>داشکاندن</th><th>کۆ</th><th></th></tr></thead>
                            <tbody>
                            <?php if (empty($sessions)): ?>
                                <tr><td colspan="6" class="text-body-secondary">بوونی نییە</td></tr>
                            <?php else: foreach ($sessions as $s): ?>
                                <?php
                                $sp = (float)($s['price'] ?? 0);
                                $sd = (float)($s['discount'] ?? 0);
                                $stot = max(0.0, $sp - $sd);
                                ?>
                                <tr>
                                    <td><?php echo (int)$s['session_number']; ?></td>
                                    <td><?php echo htmlspecialchars((string)$s['session_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format($sp, 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format($sd, 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format($stot, 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-end">
                                        <a target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0" href="<?php echo url('professions/cosmetic-center/doctor/receipt_a4.php?case_id=' . $caseId . '&session_id=' . (int)$s['id']); ?>">A4</a>
                                        <a target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0" href="<?php echo url('professions/cosmetic-center/doctor/receipt_thermal.php?case_id=' . $caseId . '&session_id=' . (int)$s['id']); ?>">٨٠مم</a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
