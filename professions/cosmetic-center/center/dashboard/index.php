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

$q = trim((string)($_GET['q'] ?? ''));



$statCases = 0;

$statSessionsMonth = 0;

$statRevenue = 0.0;

$s1 = $conn_kasher_platform->prepare(
    'SELECT COUNT(*) AS c FROM cosmetic_client_cases WHERE user_id = ? AND created_by_role = ? AND created_by_account_id = ?'
);

if ($s1) {

    $s1->bind_param('isi', $userId, $creatorRole, $creatorAccountId);

    $s1->execute();

    $row = $s1->get_result()->fetch_assoc();

    $statCases = (int)($row['c'] ?? 0);

    $s1->close();

}

$s2 = $conn_kasher_platform->prepare("

    SELECT COUNT(*) AS c

    FROM cosmetic_client_sessions s

    INNER JOIN cosmetic_client_cases c ON c.id = s.case_id

    WHERE c.user_id = ?

      AND c.created_by_role = ?

      AND c.created_by_account_id = ?

      AND YEAR(s.session_date) = YEAR(CURDATE())

      AND MONTH(s.session_date) = MONTH(CURDATE())

");

if ($s2) {

    $s2->bind_param('isi', $userId, $creatorRole, $creatorAccountId);

    $s2->execute();

    $row = $s2->get_result()->fetch_assoc();

    $statSessionsMonth = (int)($row['c'] ?? 0);

    $s2->close();

}

$s3 = $conn_kasher_platform->prepare(
    'SELECT COALESCE(SUM(s.price - s.discount), 0) AS t
     FROM cosmetic_client_sessions s
     INNER JOIN cosmetic_client_cases c ON c.id = s.case_id
     WHERE c.user_id = ? AND c.created_by_role = ? AND c.created_by_account_id = ?'
);

if ($s3) {

    $s3->bind_param('isi', $userId, $creatorRole, $creatorAccountId);

    $s3->execute();

    $row = $s3->get_result()->fetch_assoc();

    $statRevenue = (float)($row['t'] ?? 0);

    $s3->close();

}



$records = [];

$sql = "

    SELECT c.id, c.client_name, c.mobile, c.age,

           c.sessions_planned,

           (SELECT COUNT(*) FROM cosmetic_client_sessions s WHERE s.case_id = c.id) AS sessions_done,

           (SELECT COALESCE(SUM(s2.price - s2.discount), 0) FROM cosmetic_client_sessions s2 WHERE s2.case_id = c.id) AS sessions_total,

           c.work_type, c.created_at

    FROM cosmetic_client_cases c

    WHERE c.user_id = ?

      AND c.created_by_role = ?

      AND c.created_by_account_id = ?

";

$types = 'isi';

$params = [$userId, $creatorRole, $creatorAccountId];

if ($q !== '') {

    $sql .= " AND (c.client_name LIKE ? OR c.mobile LIKE ?)";

    $types .= 'ss';

    $like = '%' . $q . '%';

    $params[] = $like;

    $params[] = $like;

}

$sql .= " ORDER BY c.id DESC LIMIT 25";



$stmt = $conn_kasher_platform->prepare($sql);

if ($stmt) {

    $stmt->bind_param($types, ...$params);

    $stmt->execute();

    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

}

?>

<!DOCTYPE html>

<html lang="ku" dir="rtl">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php require_once dirname(__DIR__, 4) . '/includes/theme_bootstrap.php'; echo kasher_get_theme_bootstrap_markup(); ?>

    <title>داشبۆردی سەنتەری جوانکاری - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">

    <link href="<?php echo asset('css/medical_center/secretary.css'); ?>" rel="stylesheet">

</head>

<body class="bg-body-secondary">

<nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #db2777, #be185d);">

    <div class="container-fluid">

        <span class="navbar-brand"><i class="bi bi-flower1"></i> سەنتەری جوانکاری</span>

        <div class="navbar-nav ms-auto">

            <a class="nav-link text-white" href="<?php echo url('professions/cosmetic-center/center/auth/logout.php'); ?>"><i class="bi bi-box-arrow-right"></i> چوونەدەرەوە</a>

        </div>

    </div>

</nav>



<div class="container py-4">

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <h4 class="mb-1">بەخێربێیت، <?php echo htmlspecialchars((string)$session['name'], ENT_QUOTES, 'UTF-8'); ?></h4>

            <p class="text-body-secondary mb-0">تۆماری کڕیار و جەلسەکان — هاوبەش لەگەڵ ئەکاونتی دکتۆر</p>

        </div>

    </div>



    <div class="row g-3 mb-4">

        <div class="col-6 col-md-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body py-3">

                    <div class="text-body-secondary small">کۆی کەیس</div>

                    <div class="fs-4 fw-semibold"><?php echo (int)$statCases; ?></div>

                </div>

            </div>

        </div>

        <div class="col-6 col-md-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body py-3">

                    <div class="text-body-secondary small">جەلسەی ئەم مانگە</div>

                    <div class="fs-4 fw-semibold"><?php echo (int)$statSessionsMonth; ?></div>

                </div>

            </div>

        </div>

        <div class="col-12 col-md-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body py-3">

                    <div class="text-body-secondary small">کۆی نرخی کەیسەکان (دوای داشکاندن)</div>

                    <div class="fs-4 fw-semibold"><?php echo htmlspecialchars(number_format($statRevenue, 0), ENT_QUOTES, 'UTF-8'); ?></div>

                </div>

            </div>

        </div>

    </div>



    <div class="row g-3 mb-4">

        <div class="col-12 col-md-6">

            <a href="<?php echo url('professions/cosmetic-center/center/visits/index.php'); ?>" class="text-decoration-none">

                <div class="card border-0 shadow-sm secretary-panel h-100">

                    <div class="card-body p-4">

                        <div class="fs-2 mb-3 text-danger"><i class="bi bi-clipboard2-pulse"></i></div>

                        <h5 class="mb-1">تۆمارەکان</h5>

                        <p class="text-body-secondary mb-0">لیست، جەلسە، وەسڵ</p>

                    </div>

                </div>

            </a>

        </div>

        <div class="col-12 col-md-6">
            <a href="<?php echo url('professions/cosmetic-center/center/receipt_branding.php'); ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="fs-2 mb-3 text-danger"><i class="bi bi-image"></i></div>
                        <h5 class="mb-1">ڕێکخستنی بانەری وەسڵ</h5>
                        <p class="text-body-secondary mb-0">سەرپەڕە، بانەر، پێشبینینی چاپ</p>
                    </div>
                </div>
            </a>
        </div>

    </div>



    <div class="card border-0 shadow-sm">

        <div class="card-body">

            <h5 class="mb-3">دوایین کەیسەکان</h5>

            <form method="get" class="row g-2 mb-3">

                <div class="col-md-8">

                    <input type="text" name="q" class="form-control" placeholder="گەڕان بە ناو یان مۆبایل..." value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">

                </div>

                <div class="col-md-4">

                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i> گەڕان</button>

                </div>

            </form>

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

                    </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($records)): ?>

                        <tr><td colspan="6" class="text-center text-body-secondary py-4">هێشتا تۆمار نییە</td></tr>

                    <?php else: foreach ($records as $r): ?>

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

                            <td>

                                <a href="<?php echo url('professions/cosmetic-center/center/visits/case.php?id=' . $sid); ?>" class="text-decoration-none"><?php echo htmlspecialchars((string)$r['client_name'], ENT_QUOTES, 'UTF-8'); ?></a>

                            </td>

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

