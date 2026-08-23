<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

if (isMedicalLabLoggedIn()) {
    redirect(url('professions/medical-center/lab/dashboard/index.php'));
}

$error = '';
$needsTenant = false;
$csrfToken = Security::generateCSRFToken();

$tenantUserId = (int)($_GET['u'] ?? $_POST['tenant_user_id'] ?? 0);
$businessHint = trim((string)($_GET['biz'] ?? $_POST['business_name'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $attemptKey = mb_strtolower($identifier, 'UTF-8');
    $tenantUserId = (int)($_POST['tenant_user_id'] ?? $tenantUserId);
    $businessHint = trim((string)($_POST['business_name'] ?? $businessHint));

    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } elseif (Security::isBlocked($attemptKey)) {
        $minutes = (int)ceil(Security::getBlockedTime($attemptKey) / 60);
        $error = "چوونەژوورەوە بلۆک کراوە بۆ {$minutes} خولەک";
    } elseif ($identifier === '' || $password === '') {
        $error = 'تکایە ناسێنەر و پاسۆرد پڕبکەرەوە';
    } else {
        $resolved = labResolveLogin(
            $conn_kasher_platform,
            $identifier,
            $password,
            $tenantUserId,
            $businessHint,
            $conn instanceof mysqli ? $conn : null
        );
        $needsTenant = $resolved['needs_tenant'];

        if ($resolved['row'] !== null) {
            $row = $resolved['row'];
            Security::trackLoginAttempt($attemptKey, true);
            setMedicalLabSession([
                'lab_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'name' => (string)$row['name'],
                'email' => (string)$row['email'],
                'mobile' => (string)$row['mobile'],
            ]);
            redirect(url('professions/medical-center/lab/dashboard/index.php'));
        }

        Security::trackLoginAttempt($attemptKey, false);
        $error = $resolved['error'] !== '' ? $resolved['error'] : 'ناسێنەر یان پاسۆرد هەڵەیە';
    }
}

$showBusinessField = $needsTenant || ($businessHint !== '' && $tenantUserId === 0);
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>چوونەژوورەوەی تاقیگە - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_center/secretary.css'); ?>" rel="stylesheet">
</head>
<body class="secretary-auth-bg">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-7 col-lg-5">
            <div class="card shadow-lg border-0 secretary-card">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="secretary-icon mb-3"><i class="bi bi-flask"></i></div>
                        <h4 class="mb-1">چوونەژوورەوەی تاقیگە</h4>
                        <p class="text-body-secondary mb-0">بەشی مێدیکەل سێنتەر</p>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($tenantUserId > 0): ?>
                            <input type="hidden" name="tenant_user_id" value="<?php echo (int)$tenantUserId; ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">ناسێنەر (ئیمەیڵ یان مۆبایل)</label>
                            <input type="text" name="identifier" class="form-control form-control-lg" required
                                   value="<?php echo htmlspecialchars((string)($_POST['identifier'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">پاسۆرد</label>
                            <input type="password" name="password" class="form-control form-control-lg" required>
                        </div>
                        <div class="mb-4<?php echo $showBusinessField ? '' : ' d-none'; ?>" id="business-field">
                            <label class="form-label">ناوی دامەزراوە</label>
                            <input type="text" name="business_name" class="form-control form-control-lg"
                                   placeholder="تەنها کاتێک چەند تاقیگەیەک هەیە"
                                   value="<?php echo htmlspecialchars($businessHint, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-text">ئەگەر لینکی تایبەتیت هەیە، پێویست بە ئەم خانەیە نییە.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            <i class="bi bi-box-arrow-in-right"></i> چوونەژوورەوە
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
