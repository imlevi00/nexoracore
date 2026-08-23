<?php
/**
 * Doctor layout start — expects $pageTitle, optional $activeNav, $bodyClass, $extraHead, $doctorUiLocale.
 *
 * @var string $pageTitle
 * @var string $activeNav dashboard|patients|create|history|view
 * @var string $bodyClass
 * @var string $extraHead
 * @var string $doctorUiLocale ku|en
 */

$doctorUiLocale = $doctorUiLocale ?? 'ku';
$isEnglishUi = $doctorUiLocale === 'en';

$pageTitle = $pageTitle ?? ($isEnglishUi ? 'Doctor Dashboard' : 'داشبۆردی دکتۆر');
$activeNav = $activeNav ?? 'dashboard';
$bodyClass = $bodyClass ?? 'doctor-queue-page';
$extraHead = $extraHead ?? '';

// Live count of patients referred to this doctor, shown as a badge on the
// Referrals nav item. Computed defensively so the layout still renders if the
// referral service or connection is unavailable.
$referralNavCount = 0;
require_once __DIR__ . '/referral_service.php';
if (
    function_exists('medicalDoctorCountIncomingReferrals')
    && isset($conn_kasher_platform) && $conn_kasher_platform instanceof mysqli
) {
    $navSession = medicalDoctorSession();
    if (is_array($navSession)) {
        $referralNavCount = medicalDoctorCountIncomingReferrals(
            $conn_kasher_platform,
            (int)$navSession['user_id'],
            (int)$navSession['doctor_id']
        );
    }
}

$navItems = $isEnglishUi ? [
    'dashboard' => [
        'label' => 'Dashboard',
        'url' => url('professions/medical-center/doctor/dashboard/index.php'),
        'icon' => 'bi-speedometer2',
    ],
    'patients' => [
        'label' => 'Patients',
        'url' => url('professions/medical-center/doctor/patients/index.php'),
        'icon' => 'bi-people',
    ],
    'referrals' => [
        'label' => 'Referrals',
        'url' => url('professions/medical-center/doctor/referrals/index.php'),
        'icon' => 'bi-arrow-left-right',
        'badge' => $referralNavCount,
    ],
    'create' => [
        'label' => 'New Prescription',
        'url' => url('professions/medical-center/doctor/prescriptions/create.php'),
        'icon' => 'bi-file-earmark-medical',
    ],
    'history' => [
        'label' => 'History',
        'url' => url('professions/medical-center/doctor/prescriptions/index.php'),
        'icon' => 'bi-clock-history',
    ],
    'lab_orders' => [
        'label' => 'Lab Orders',
        'url' => url('professions/medical-center/doctor/lab-orders/index.php'),
        'icon' => 'bi-clipboard2-pulse',
    ],
] : [
    'dashboard' => [
        'label' => 'داشبۆرد',
        'url' => url('professions/medical-center/doctor/dashboard/index.php'),
        'icon' => 'bi-speedometer2',
    ],
    'patients' => [
        'label' => 'نەخۆشەکان',
        'url' => url('professions/medical-center/doctor/patients/index.php'),
        'icon' => 'bi-people',
    ],
    'referrals' => [
        'label' => 'ناردنەکان',
        'url' => url('professions/medical-center/doctor/referrals/index.php'),
        'icon' => 'bi-arrow-left-right',
        'badge' => $referralNavCount,
    ],
    'create' => [
        'label' => 'ڕەچەتەی نوێ',
        'url' => url('professions/medical-center/doctor/prescriptions/create.php'),
        'icon' => 'bi-file-earmark-medical',
    ],
    'history' => [
        'label' => 'مێژوو',
        'url' => url('professions/medical-center/doctor/prescriptions/index.php'),
        'icon' => 'bi-clock-history',
    ],
    'lab_orders' => [
        'label' => 'داواکاری تاقیگە',
        'url' => url('professions/medical-center/doctor/lab-orders/index.php'),
        'icon' => 'bi-clipboard2-pulse',
    ],
];

$brandLabel = $isEnglishUi ? 'Shahana Medical Building' : 'مێدیکەل سێنتەر - دکتۆر';
$logoutLabel = $isEnglishUi ? 'Log out' : 'چوونەدەرەوە';
$htmlLang = $isEnglishUi ? 'en' : 'ku';
$htmlDir = $isEnglishUi ? 'ltr' : 'rtl';

$flashMessage = getMessage();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo htmlspecialchars($htmlDir, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_center/secretary.css'); ?>" rel="stylesheet">
    <?php echo $extraHead; ?>
</head>
<body class="bg-body-secondary <?php echo htmlspecialchars(trim($bodyClass . ($isEnglishUi ? ' doctor-ui-ltr' : '')), ENT_QUOTES, 'UTF-8'); ?>">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('professions/medical-center/doctor/dashboard/index.php'); ?>">
            <i class="bi bi-heart-pulse"></i> <?php echo htmlspecialchars($brandLabel, ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#doctorNavbar"
                aria-controls="doctorNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="doctorNavbar">
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                <?php foreach ($navItems as $key => $item): ?>
                    <a class="nav-link<?php echo $activeNav === $key ? ' active fw-semibold' : ''; ?>"
                       href="<?php echo $item['url']; ?>">
                        <i class="bi <?php echo $item['icon']; ?>"></i>
                        <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                            <span class="badge rounded-pill bg-danger ms-1"><?php echo (int)$item['badge']; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <a class="nav-link" href="<?php echo url('professions/medical-center/doctor/auth/logout.php'); ?>">
                    <i class="bi bi-box-arrow-right"></i> <?php echo htmlspecialchars($logoutLabel, ENT_QUOTES, 'UTF-8'); ?>
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-4">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
