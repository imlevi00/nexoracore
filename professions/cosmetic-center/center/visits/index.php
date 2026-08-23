<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__, 2) . '/includes/cosmetic_case_scope.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}

$session = cosmeticCenterSession();
$userId = (int)$session['user_id'];
$creator = cosmetic_case_creator_from_center_session($session);
$creatorRole = $creator['role'];
$creatorAccountId = $creator['account_id'];
$csrfToken = Security::generateCSRFToken();
$flashMessage = getMessage();

$rows = [];
$lst = $conn_kasher_platform->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM cosmetic_client_sessions s WHERE s.case_id = c.id) AS sessions_done,
           (SELECT COALESCE(SUM(s2.price - s2.discount), 0) FROM cosmetic_client_sessions s2 WHERE s2.case_id = c.id) AS sessions_total
    FROM cosmetic_client_cases c
    WHERE c.user_id = ? AND c.created_by_role = ? AND c.created_by_account_id = ?
    ORDER BY c.id DESC
");
if ($lst) {
    $lst->bind_param('isi', $userId, $creatorRole, $creatorAccountId);
    $lst->execute();
    $rows = $lst->get_result()->fetch_all(MYSQLI_ASSOC);
    $lst->close();
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once dirname(__DIR__, 4) . '/includes/theme_bootstrap.php'; echo kasher_get_theme_bootstrap_markup(); ?>
    <title>تۆمارەکان - سەنتەری جوانکاری - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_center/secretary.css'); ?>" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #db2777, #be185d);">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('professions/cosmetic-center/center/dashboard/index.php'); ?>"><i class="bi bi-arrow-right-circle"></i> داشبۆرد</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link text-white" href="<?php echo url('professions/cosmetic-center/center/auth/logout.php'); ?>">چوونەدەرەوە</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="mb-0">کەیسی کڕیار</h5>
        <a href="<?php echo url('professions/cosmetic-center/center/visits/new.php'); ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> کەیسی نوێ</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>ناو</th>
                        <th>جەلسە</th>
                        <th>جۆری ئیش</th>
                        <th>کۆی جەلسەکان</th>
                        <th>مۆبایل</th>
                        <th>وەسڵ</th>
                        <th class="text-end">کردار</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center text-body-secondary py-4">هێشتا تۆمار نییە</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <?php
                        $sid = (int)$r['id'];
                        $sesSt = $conn_kasher_platform->prepare('SELECT id FROM cosmetic_client_sessions WHERE case_id = ? ORDER BY session_number DESC, id DESC LIMIT 1');
                        $lastSessionId = 0;
                        if ($sesSt) {
                            $sesSt->bind_param('i', $sid);
                            $sesSt->execute();
                            $lr = $sesSt->get_result()->fetch_assoc();
                            $sesSt->close();
                            $lastSessionId = $lr ? (int)$lr['id'] : 0;
                        }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$r['client_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge text-bg-secondary"><?php echo (int)$r['sessions_done']; ?>/<?php echo (int)$r['sessions_planned']; ?></span></td>
                            <td><?php echo htmlspecialchars((string)$r['work_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($r['sessions_total'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$r['mobile'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($lastSessionId > 0): ?>
                                    <a target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0" href="<?php echo url('professions/cosmetic-center/center/receipt_a4.php?case_id=' . $sid . '&session_id=' . $lastSessionId); ?>">A4</a>
                                    <a target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0" href="<?php echo url('professions/cosmetic-center/center/receipt_thermal.php?case_id=' . $sid . '&session_id=' . $lastSessionId); ?>">٨٠مم</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?php echo url('professions/cosmetic-center/center/visits/case.php?id=' . $sid); ?>" class="btn btn-sm btn-outline-primary">وردەکاری</a>
                                <form method="post" action="<?php echo url('professions/cosmetic-center/center/visits/delete.php'); ?>" class="d-inline" onsubmit="return confirm('دڵنیایت؟');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="record_id" value="<?php echo $sid; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">سڕینەوە</button>
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
