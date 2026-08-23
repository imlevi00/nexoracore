<?php
/**
 * Lab layout start — expects $pageTitle, optional $activeNav, $bodyClass, $extraHead.
 *
 * @var string $pageTitle
 * @var string $activeNav
 * @var string $bodyClass
 * @var string $extraHead
 */

$pageTitle = $pageTitle ?? 'داشبۆردی تاقیگە';
$activeNav = $activeNav ?? 'dashboard';
$bodyClass = $bodyClass ?? 'lab-dashboard-page';
$extraHead = $extraHead ?? '';

$navItems = [
    'dashboard' => [
        'label' => 'داشبۆرد',
        'url' => url('professions/medical-center/lab/dashboard/index.php'),
        'icon' => 'bi-speedometer2',
    ],
    'orders' => [
        'label' => 'داواکاریەکان',
        'url' => url('professions/medical-center/lab/orders/index.php'),
        'icon' => 'bi-clipboard2-pulse',
    ],
    'orders_create' => [
        'label' => 'داواکاری نوێ',
        'url' => url('professions/medical-center/lab/orders/create.php'),
        'icon' => 'bi-plus-circle',
    ],
    'tests' => [
        'label' => 'فەحسەکان',
        'url' => url('professions/medical-center/lab/tests/index.php'),
        'icon' => 'bi-grid-3x3-gap',
    ],
    'receipt_template' => [
        'label' => 'وەسڵ',
        'url' => url('professions/medical-center/lab/receipt_template/index.php'),
        'icon' => 'bi-receipt',
    ],
];

$flashMessage = getMessage();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_center/secretary.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_center/lab.css'); ?>" rel="stylesheet">
    <?php echo $extraHead; ?>
</head>
<body class="bg-body-secondary <?php echo htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8'); ?>">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('professions/medical-center/lab/dashboard/index.php'); ?>">
            <i class="bi bi-flask"></i> مێدیکەل سێنتەر - تاقیگە
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#labNavbar"
                aria-controls="labNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="labNavbar">
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                <?php foreach ($navItems as $key => $item): ?>
                    <a class="nav-link<?php echo $activeNav === $key ? ' active fw-semibold' : ''; ?>"
                       href="<?php echo $item['url']; ?>">
                        <i class="bi <?php echo $item['icon']; ?>"></i>
                        <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
                <a class="nav-link" href="<?php echo url('professions/medical-center/lab/auth/logout.php'); ?>">
                    <i class="bi bi-box-arrow-right"></i> چوونەدەرەوە
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
