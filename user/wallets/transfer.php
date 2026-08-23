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
enforceAuthorizationOrDeny($currentUser, 'wallets.transfer', [
    'route' => '/user/wallets/transfer.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
], 'redirect');
requireWalletsModuleAccess();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'CSRF هەڵەیە';
    } else {
        $res = walletTransfer(
            $conn,
            $userId,
            (int)($_POST['from_wallet_id'] ?? 0),
            (int)($_POST['to_wallet_id'] ?? 0),
            (string)($_POST['currency'] ?? 'IQD'),
            (float)($_POST['amount'] ?? 0),
            (string)($_POST['notes'] ?? ''),
            (int)$userId
        );
        $message = $res['success'] ? 'گواستنەوە سەرکەوتوو بوو' : ($res['message'] ?? 'هەڵە');
    }
}

$wallets = walletGetUserWallets($conn, $userId, true);
$csrf = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>گواستنەوەی قاسە - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/wallets/wallets-pages.css'); ?>" rel="stylesheet">
</head>
<body class="wallets-module-page wallets-form-page wallets-transfer-page">
<?php include_once '../../includes/navigation.php'; ?>
<div class="container py-4 hub-page-content wa-wrap-narrow">

    <header class="wa-hero">
        <div>
            <div class="wa-kicker"><i class="bi bi-arrow-left-right"></i> گواستنەوە</div>
            <h1><i class="bi bi-arrow-left-right"></i> گواستنەوەی پارە نێوان قاسەکان</h1>
            <p class="wa-hero-sub">پارە لە قاسەیەکەوە بگوازەوە بۆ قاسەیەکی دیکە</p>
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
            <span><i class="bi bi-send"></i> وردەکاری گواستنەوە</span>
        </div>
        <div class="wa-panel-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <div class="wa-flow mb-3">
                    <div>
                        <label class="form-label" for="from_wallet_id">لە قاسە</label>
                        <select class="form-select" name="from_wallet_id" id="from_wallet_id">
                            <?php foreach ($wallets as $w): ?>
                                <option value="<?php echo (int)$w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wa-flow-arrow" aria-hidden="true"><i class="bi bi-arrow-left"></i></div>
                    <div>
                        <label class="form-label" for="to_wallet_id">بۆ قاسە</label>
                        <select class="form-select" name="to_wallet_id" id="to_wallet_id">
                            <?php foreach ($wallets as $w): ?>
                                <option value="<?php echo (int)$w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="currency">دراو</label>
                        <select class="form-select" name="currency" id="currency">
                            <option value="IQD">IQD</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="amount">بڕ</label>
                        <input class="form-control" type="number" step="0.001" min="0.001" name="amount" id="amount" required placeholder="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">تێبینی</label>
                        <input class="form-control" name="notes" id="notes" placeholder="تێبینی ئیختیاری...">
                    </div>
                </div>
                <div class="wa-sticky-actions mt-3">
                    <button type="submit" class="wa-btn wa-btn-warn"><i class="bi bi-check-lg"></i> جێبەجێکردن</button>
                </div>
            </form>
        </div>
    </section>
</div>
</body>
</html>

