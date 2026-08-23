<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once dirname(__DIR__, 3) . '/config/product_images.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}

$session = cosmeticDoctorSession();
$userId = (int)$session['user_id'];
$accountId = (int)$session['doctor_account_id'];
$csrfToken = Security::generateCSRFToken();
$flashMessage = getMessage();

$st = $conn_kasher_platform->prepare(
    'SELECT id, name, receipt_header, receipt_logo_url FROM cosmetic_doctor_accounts WHERE id = ? AND user_id = ? LIMIT 1'
);
$st->bind_param('ii', $accountId, $userId);
$st->execute();
$account = $st->get_result()->fetch_assoc();
$st->close();

if (!$account) {
    setMessage('ئەکاونت نەدۆزرایەوە', 'danger');
    redirect(url('professions/cosmetic-center/doctor/dashboard/index.php'));
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once dirname(__DIR__, 4) . '/includes/theme_bootstrap.php'; echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ڕێکخستنی وەسڵ - دکتۆر</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark cosmetic-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('professions/cosmetic-center/doctor/dashboard/index.php'); ?>">گەڕانەوە بۆ داشبۆرد</a>
    </div>
</nav>
<div class="container py-4">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="mb-3">سەرپەڕەی وەسڵ</h5>
            <form method="post" action="<?php echo url('professions/cosmetic-center/doctor/receipt_branding_save.php'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="header">
                <textarea name="receipt_header" class="form-control mb-3" rows="4" placeholder="ناو، ناونیشان، تەلەفۆن..."><?php echo htmlspecialchars((string)($account['receipt_header'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                <button class="btn btn-primary" type="submit">پاشەکەوتی سەرپەڕە</button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="mb-3">بانەری سەر وەسڵ</h5>
            <?php if (!empty($account['receipt_logo_url'])): ?>
                <p><img src="<?php echo htmlspecialchars((string)$account['receipt_logo_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="banner" class="img-thumbnail" style="max-height:120px;max-width:360px;width:100%;object-fit:contain"></p>
            <?php endif; ?>
            <form method="post" action="<?php echo url('professions/cosmetic-center/doctor/receipt_branding_save.php'); ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="logo">
                <div class="col-md-8"><input type="file" name="logo_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" required></div>
                <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="submit">بارکردنی بانەر</button></div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h6 class="mb-2">پێشبینینی وەسڵ</h6>
            <a class="btn btn-sm btn-outline-secondary me-2" target="_blank" rel="noopener" href="<?php echo url('professions/cosmetic-center/doctor/receipt_a4.php?preview=1'); ?>">A4</a>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?php echo url('professions/cosmetic-center/doctor/receipt_thermal.php?preview=1'); ?>">٨٠مم</a>
        </div>
    </div>
</div>
</body>
</html>
