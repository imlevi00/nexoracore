<?php
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/wallet_service.php';
/** @var mysqli $conn */

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
enforceAuthorizationOrDeny($currentUser, 'wallets.create', [
    'route' => '/user/wallets/add.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
], 'redirect');
requireWalletsModuleAccess();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'CSRF هەڵەیە';
    } else {
        $result = walletCreate($conn, $userId, (string)($_POST['name'] ?? ''), (string)($_POST['notes'] ?? ''), !empty($_POST['is_default']));
        $message = $result['success'] ? 'قاسە زیادکرا' : ($result['message'] ?? 'هەڵە');
    }
}
$csrf = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>قاسەی نوێ - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/wallets/wallets-pages.css'); ?>" rel="stylesheet">
</head>
<body class="wallets-module-page wallets-form-page wallets-add-page">
<?php include_once '../../includes/navigation.php'; ?>
<div class="container py-4 hub-page-content wa-wrap-narrow">

    <header class="wa-hero">
        <div>
            <div class="wa-kicker"><i class="bi bi-wallet2"></i> بەشی قاسەکان</div>
            <h1><i class="bi bi-plus-circle"></i> زیادکردنی قاسەی نوێ</h1>
            <p class="wa-hero-sub">قاسەیەکی نوێ دروست بکە بۆ جیاکردنەوەی پارە و جوڵەکان</p>
        </div>
        <div class="wa-actions">
            <a class="wa-btn wa-btn-ghost" href="<?php echo url('user/wallets/main.php'); ?>">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="alert alert-info d-flex align-items-center gap-2">
            <i class="bi bi-info-circle"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <section class="wa-panel">
        <div class="wa-panel-head">
            <span><i class="bi bi-safe"></i> زانیاری قاسە</span>
        </div>
        <div class="wa-panel-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <div class="mb-3">
                    <label class="form-label" for="wallet_name">ناوی قاسە</label>
                    <input id="wallet_name" name="name" required class="form-control" placeholder="بۆ نموونە: قاسەی سەرەکی">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="wallet_notes">تێبینی</label>
                    <textarea id="wallet_notes" name="notes" class="form-control" rows="3" placeholder="تێبینی ئیختیاری..."></textarea>
                </div>
                <div class="form-check wa-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_default" id="is_default">
                    <label class="form-check-label" for="is_default">وەک بنەڕەتی دابنرێت</label>
                </div>
                <div class="wa-sticky-actions">
                    <a class="wa-btn wa-btn-ghost" href="<?php echo url('user/wallets/index.php'); ?>">پاشگەزبوونەوە</a>
                    <button type="submit" class="wa-btn wa-btn-success"><i class="bi bi-check-lg"></i> پاشەکەوت</button>
                </div>
            </form>
        </div>
    </section>
</div>
</body>
</html>

