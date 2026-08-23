<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/security.php';
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
    $isMedicalCenterMode = (
        $businessTypeId === 3 ||
        $businessTypeCode === 'pharmacy_and_medical_center'
    );
}

if (!$isMedicalCenterMode) {
    setMessage('ئەم بەشە تەنها بۆ دەرمانخانە و سەنتەری پزیشکییە', 'warning');
    redirect(url('user/dashboard/index.php'));
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>بەڕێوەبردنی جوانکاری - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_staff/medical-staff-dark.css'); ?>" rel="stylesheet">
    <style>
        .dashboard-card { border: none; border-radius: 14px; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12); transition: all 0.25s ease; min-height: 185px; }
        .dashboard-card:hover { transform: translateY(-5px); box-shadow: 0 14px 28px rgba(15, 23, 42, 0.16); }
        .section-cosmetic-center { background: linear-gradient(135deg, #db2777, #be185d); }
        .section-cosmetic-doctor { background: linear-gradient(135deg, #c026d3, #7c3aed); }
        .section-cosmetic-login-c { background: linear-gradient(135deg, #f472b6, #db2777); }
        .section-cosmetic-login-d { background: linear-gradient(135deg, #a855f7, #6366f1); }
        .card-icon { width: 64px; height: 64px; border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 30px; color: #fff; background: rgba(255,255,255,0.2); }
        .dashboard-card-wrapper { padding: 0.65rem; }
        @media (min-width: 992px) { .dashboard-card-wrapper { flex: 0 0 50%; max-width: 50%; } }
        @media (max-width: 991px) { .dashboard-card-wrapper { flex: 0 0 100%; max-width: 100%; } }
    </style>
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('user/dashboard/index.php'); ?>">
            <i class="bi bi-flower1"></i> <?php echo htmlspecialchars($currentUser['business_name'], ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="<?php echo url('user/dashboard/index.php'); ?>"><i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد</a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-1">بەڕێوەبردنی سەنتەری جوانکاری</h2>
            <p class="text-muted mb-0">ئەکاونتی سەنتەر، دکتۆری جوانکاری و چوونەژوورەوەی تایبەت</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="dashboard-card-wrapper p-2">
            <a href="<?php echo url('user/cosmetic_staff/center_accounts.php'); ?>" class="text-decoration-none">
                <div class="dashboard-card section-cosmetic-center p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon"><i class="bi bi-building"></i></div>
                        <h5 class="mb-1">ئەکاونتی سەنتەری جوانکاری</h5>
                        <p class="mb-0 small">زیادکردن، دەستکاری و سڕینەوە</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="dashboard-card-wrapper p-2">
            <a href="<?php echo url('user/cosmetic_staff/doctor_accounts.php'); ?>" class="text-decoration-none">
                <div class="dashboard-card section-cosmetic-doctor p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon"><i class="bi bi-person-badge"></i></div>
                        <h5 class="mb-1">ئەکاونتی دکتۆری جوانکاری</h5>
                        <p class="mb-0 small">سەرپەڕەی وەسڵ، لۆگۆ و بەڕێوەبردن</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="dashboard-card-wrapper p-2">
            <a href="<?php echo url('professions/cosmetic-center/center/auth/login.php'); ?>" class="text-decoration-none" target="_blank" rel="noopener noreferrer">
                <div class="dashboard-card section-cosmetic-login-c p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon"><i class="bi bi-box-arrow-in-right"></i></div>
                        <h5 class="mb-1">چوونەژوورەوە وەک سەنتەر</h5>
                        <p class="mb-0 small">پەڕەی تایبەتی ئەکاونتی سەنتەر</p>
                    </div>
                </div>
            </a>
        </div>

        <div class="dashboard-card-wrapper p-2">
            <a href="<?php echo url('professions/cosmetic-center/doctor/auth/login.php'); ?>" class="text-decoration-none" target="_blank" rel="noopener noreferrer">
                <div class="dashboard-card section-cosmetic-login-d p-4 h-100">
                    <div class="text-center text-white">
                        <div class="card-icon"><i class="bi bi-heart-pulse"></i></div>
                        <h5 class="mb-1">چوونەژوورەوە وەک دکتۆری جوانکاری</h5>
                        <p class="mb-0 small">تۆمارەکان و چاپی وەسڵ</p>
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
