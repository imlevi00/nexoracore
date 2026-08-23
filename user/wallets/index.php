<?php
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/wallet_service.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_deactivate.php';
/** @var mysqli $conn */

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
enforceAuthorizationOrDeny($currentUser, 'wallets.view', [
    'route' => '/user/wallets/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
], 'redirect');
requireWalletsModuleAccess();

$message = '';
$messageType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    if (isset($_POST['default_wallet_id'])) {
        $ok = walletSetDefault($conn, $userId, (int)$_POST['default_wallet_id']);
        $message = $ok ? 'قاسەی بنەڕەتی نوێکرایەوە' : 'نەتوانرا قاسەی بنەڕەتی بگۆڕدرێت';
        $messageType = $ok ? 'success' : 'danger';
    } elseif (isset($_POST['hide_wallet_id'])) {
        $result = walletDeactivate($conn, $userId, (int)$_POST['hide_wallet_id']);
        $message = (string)($result['message'] ?? ($result['success'] ? 'قاسەکە شاردرایەوە' : 'نەتوانرا قاسە بشاردرێتەوە'));
        $messageType = !empty($result['success']) ? 'success' : 'danger';
    }
}

$wallets = walletGetUserWallets($conn, $userId, true);
$csrf = Security::generateCSRFToken();
$walletCount = count($wallets);
$totalIqd = 0.0;
$totalUsd = 0.0;
foreach ($wallets as $walletRow) {
    $totalIqd += (float)($walletRow['balance_iqd'] ?? 0);
    $totalUsd += (float)($walletRow['balance_usd'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>لیستی قاسەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/wallets/wallets-pages.css'); ?>" rel="stylesheet">
</head>
<body class="wallets-module-page wallets-list-page">
<?php include_once '../../includes/navigation.php'; ?>
<div class="container-fluid py-4 hub-page-content wa-wrap">

    <header class="wa-hero">
        <div>
            <div class="wa-kicker"><i class="bi bi-wallet2"></i> بەشی قاسەکان</div>
            <h1><i class="bi bi-safe"></i> لیستی قاسەکان</h1>
            <p class="wa-hero-sub">بینینی باڵانس، دیاریکردنی قاسەی بنەڕەتی، و شاردنەوەی قاسە</p>
            <div class="wa-hero-pills">
                <span class="wa-pill"><i class="bi bi-collection"></i> <?php echo number_format($walletCount); ?> قاسە</span>
            </div>
        </div>
        <div class="wa-actions">
            <a class="wa-btn wa-btn-ghost" href="<?php echo url('user/wallets/main.php'); ?>">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
            <a class="wa-btn wa-btn-ghost" href="<?php echo url('user/wallets/history.php'); ?>">
                <i class="bi bi-clock-history"></i> مێژوو
            </a>
            <a class="wa-btn wa-btn-primary" href="<?php echo url('user/wallets/add.php'); ?>">
                <i class="bi bi-plus-lg"></i> قاسەی نوێ
            </a>
        </div>
    </header>

    <?php if ($message): ?>
        <?php
        $alertClass = match ($messageType) {
            'success' => 'alert-success',
            'danger' => 'alert-danger',
            default => 'alert-info',
        };
        $alertIcon = match ($messageType) {
            'success' => 'bi-check-circle',
            'danger' => 'bi-exclamation-triangle',
            default => 'bi-info-circle',
        };
        ?>
        <div class="alert <?php echo $alertClass; ?> d-flex align-items-center gap-2">
            <i class="bi <?php echo $alertIcon; ?>"></i>
            <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <div class="wa-stats wa-stats-3">
        <div class="wa-stat" style="--stat-accent:#0ea5e9">
            <div class="wa-stat-icon"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="wa-stat-label">ژمارەی قاسە</div>
                <div class="wa-stat-value"><?php echo number_format($walletCount); ?></div>
            </div>
        </div>
        <div class="wa-stat" style="--stat-accent:#10b981">
            <div class="wa-stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="wa-stat-label">کۆی دینار</div>
                <div class="wa-stat-value"><?php echo number_format($totalIqd, 0); ?></div>
            </div>
        </div>
        <div class="wa-stat" style="--stat-accent:#6366f1">
            <div class="wa-stat-icon"><i class="bi bi-currency-dollar"></i></div>
            <div>
                <div class="wa-stat-label">کۆی دۆلار</div>
                <div class="wa-stat-value"><?php echo number_format($totalUsd, 2); ?></div>
            </div>
        </div>
    </div>

    <section class="wa-panel">
        <div class="wa-panel-head">
            <span><i class="bi bi-list-ul"></i> قاسە چالاکەکان</span>
        </div>
        <div class="wa-panel-body-flush">
            <?php if (!$wallets): ?>
                <div class="wa-empty">
                    <div class="wa-empty-icon"><i class="bi bi-inbox"></i></div>
                    <h3>هیچ قاسەیەک نەدۆزرایەوە</h3>
                    <p>یەکەمین قاسە زیاد بکە بۆ دەستپێکردن</p>
                    <a class="wa-btn wa-btn-primary mt-2" href="<?php echo url('user/wallets/add.php'); ?>">
                        <i class="bi bi-plus-lg"></i> قاسەی نوێ
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 wallets-list-table">
                        <thead>
                            <tr>
                                <th>ناو</th>
                                <th>IQD</th>
                                <th>USD</th>
                                <th>بنەڕەتی</th>
                                <th>کردارەکان</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($wallets as $w): ?>
                            <?php
                            $hasBalance = (float)$w['balance_iqd'] != 0.0 || (float)$w['balance_usd'] != 0.0;
                            $hideConfirm = $hasBalance
                                ? 'ئایا دڵنیایت لە شاردنەوەی ئەم قاسەیە؟ باڵانسەکە دەمێنێتەوە و مێژووەکە دەبینرێت.'
                                : 'ئایا دڵنیایت لە شاردنەوەی ئەم قاسەیە؟ داتاکانی مێژوو دەمێننەوە.';
                            ?>
                            <tr>
                                <td data-label="ناو">
                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)$w['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if (!empty($w['notes'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars((string)$w['notes'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="IQD"><span class="wa-balance"><?php echo number_format((float)$w['balance_iqd'], 0); ?></span></td>
                                <td data-label="USD"><span class="wa-balance"><?php echo number_format((float)$w['balance_usd'], 2); ?></span></td>
                                <td data-label="بنەڕەتی">
                                    <?php if ((int)$w['is_default'] === 1): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis">بنەڕەتی</span>
                                    <?php else: ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="default_wallet_id" value="<?php echo (int)$w['id']; ?>">
                                            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-circle"></i> دیاریبکە</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td data-label="کردارەکان">
                                    <form method="post" class="d-inline" onsubmit="return confirm('<?php echo htmlspecialchars($hideConfirm, ENT_QUOTES, 'UTF-8'); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="hide_wallet_id" value="<?php echo (int)$w['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="شاردنەوە">
                                            <i class="bi bi-eye-slash"></i> شاردنەوە
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>

