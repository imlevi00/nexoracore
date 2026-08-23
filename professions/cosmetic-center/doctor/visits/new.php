<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}

$sess = cosmeticDoctorSession();
$userId = (int)$sess['user_id'];
$csrfToken = Security::generateCSRFToken();

$form = [
    'client_name' => '',
    'mobile' => '',
    'age' => '',
    'sessions_planned' => '1',
    'work_type' => '',
    'price' => '',
    'discount' => '0',
    'first_session_date' => date('Y-m-d'),
];
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once dirname(__DIR__, 4) . '/includes/theme_bootstrap.php'; echo kasher_get_theme_bootstrap_markup(); ?>
    <title>کەیسی نوێ - دکتۆری جوانکاری - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark cosmetic-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('professions/cosmetic-center/doctor/visits/index.php'); ?>"><i class="bi bi-arrow-right"></i> لیست</a>
    </div>
</nav>
<div class="container py-4">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h5 class="mb-3">تۆماری کەیسی نوێ</h5>
            <form method="post" action="<?php echo url('professions/cosmetic-center/doctor/visits/save.php'); ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="record_id" value="0">
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
                    <label class="form-label">بەرواری جەلسەی یەکەم</label>
                    <input type="date" name="first_session_date" class="form-control" required value="<?php echo htmlspecialchars($form['first_session_date'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">جۆری ئیش</label>
                    <input type="text" name="work_type" class="form-control" required maxlength="255" value="<?php echo htmlspecialchars($form['work_type'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">نرخی جەلسەی یەکەم</label>
                    <input type="text" name="price" class="form-control" required inputmode="decimal" value="<?php echo htmlspecialchars($form['price'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">داشکاندنی جەلسەی یەکەم</label>
                    <input type="text" name="discount" class="form-control" required inputmode="decimal" value="<?php echo htmlspecialchars($form['discount'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">مۆبایل</label>
                    <input type="text" name="mobile" class="form-control" required maxlength="30" value="<?php echo htmlspecialchars($form['mobile'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle"></i> پاشەکەوت</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
