<?php
require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/wallet_service.php';
require_once '../../includes/wallet_history_labels.php';
/** @var mysqli $conn */

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
enforceAuthorizationOrDeny($currentUser, 'wallets.view', [
    'route' => '/user/wallets/history.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
], 'redirect');
requireWalletsModuleAccess();

$selectedWalletId = isset($_GET['wallet_id']) ? (int)$_GET['wallet_id'] : 0;
$walletIdFilter = $selectedWalletId > 0 ? $selectedWalletId : null;

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';

if ($dateFrom !== '' && preg_match($datePattern, $dateFrom) !== 1) {
    $dateFrom = '';
}
if ($dateTo !== '' && preg_match($datePattern, $dateTo) !== 1) {
    $dateTo = '';
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$hasDateFilter = $dateFrom !== '' || $dateTo !== '';
$historyLimit = $hasDateFilter ? 500 : 150;
$historyLimitReached = false;

$wallets = walletGetUserWallets($conn, $userId, false);
$historyRows = walletGetTransactionHistory(
    $conn,
    $userId,
    $walletIdFilter,
    $historyLimit,
    $dateFrom !== '' ? $dateFrom : null,
    $dateTo !== '' ? $dateTo : null
);
if ($hasDateFilter && count($historyRows) >= $historyLimit) {
    $historyLimitReached = true;
}

$printReceiptBaseUrl = url('user/wallets/print_receipt.php');
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>مێژووی قاسەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/hub-modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/wallets/wallets-pages.css'); ?>" rel="stylesheet">
</head>
<body class="wallets-module-page wallets-history-page">
<?php include_once '../../includes/navigation.php'; ?>
<div class="container-fluid py-4 hub-page-content wa-wrap">

    <header class="wa-hero">
        <div>
            <div class="wa-kicker"><i class="bi bi-clock-history"></i> جوڵەکانی قاسە</div>
            <h1><i class="bi bi-journal-text"></i> مێژووی قاسەکان</h1>
            <p class="wa-hero-sub">بینینی هەموو جوڵەکان بەپێی کات و قاسە</p>
            <div class="wa-hero-pills">
                <span class="wa-pill"><i class="bi bi-list-ol"></i> <?php echo number_format(count($historyRows)); ?> جوڵە</span>
            </div>
        </div>
        <div class="wa-actions">
            <a class="wa-btn wa-btn-ghost" href="<?php echo url('user/wallets/main.php'); ?>">
                <i class="bi bi-arrow-right"></i> گەڕانەوە
            </a>
            <a class="wa-btn wa-btn-primary" href="<?php echo url('user/wallets/index.php'); ?>">
                <i class="bi bi-wallet2"></i> لیستی قاسەکان
            </a>
        </div>
    </header>

    <section class="wa-panel">
        <div class="wa-panel-head">
            <span><i class="bi bi-funnel"></i> فیلتەرکردن</span>
        </div>
        <div class="wa-panel-body">
            <form method="get" class="row g-3 align-items-end history-filter-card">
                <div class="col-md-3">
                    <label for="date_from" class="form-label">لە بەروار</label>
                    <input type="date" class="form-control" id="date_from" name="date_from"
                           value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label">تا بەروار</label>
                    <input type="date" class="form-control" id="date_to" name="date_to"
                           value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label for="wallet_id" class="form-label">فلتەر بە قاسە</label>
                    <select class="form-select" name="wallet_id" id="wallet_id">
                        <option value="0">هەموو قاسەکان</option>
                        <?php foreach ($wallets as $wallet): ?>
                            <?php $isHiddenWallet = (int)($wallet['is_active'] ?? 1) !== 1; ?>
                            <option value="<?php echo (int)$wallet['id']; ?>" <?php echo $selectedWalletId === (int)$wallet['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$wallet['name'], ENT_QUOTES, 'UTF-8'); ?><?php echo $isHiddenWallet ? ' (شاردراوە)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex flex-column gap-2">
                    <button class="wa-btn wa-btn-primary w-100" type="submit">
                        <i class="bi bi-funnel"></i> ئەنجامەکان
                    </button>
                    <a class="wa-btn wa-btn-ghost w-100" href="<?php echo url('user/wallets/history.php'); ?>">
                        پاککردنەوە
                    </a>
                </div>
            </form>
        </div>
    </section>

    <section class="wa-panel">
        <div class="wa-panel-head">
            <span><i class="bi bi-list"></i> جوڵەکان</span>
        </div>
        <div class="wa-panel-body-flush">
            <?php if (!$historyRows): ?>
                <div class="wa-empty">
                    <div class="wa-empty-icon"><i class="bi bi-inbox"></i></div>
                    <h3>هیچ جوڵەیەک نەدۆزرایەوە</h3>
                    <p>فلتەرەکان بگۆڕە یان جوڵەیەکی نوێ تۆمار بکە</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 wallets-history-table">
                        <thead>
                            <tr>
                                <th>کات</th>
                                <th>قاسە</th>
                                <th>جۆر</th>
                                <th>ئاڕاستە</th>
                                <th>بڕ</th>
                                <th>تێبینی</th>
                                <th class="text-center">وەسڵ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historyRows as $row): ?>
                                <?php
                                $isIn = (string)$row['direction'] === 'in';
                                $amountClass = $isIn ? 'history-amount-in' : 'history-amount-out';
                                $amountPrefix = $isIn ? '+' : '-';
                                $directionBadgeClass = $isIn ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis';
                                ?>
                                <tr>
                                    <td data-label="کات">
                                        <?php echo htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td data-label="قاسە">
                                        <?php echo htmlspecialchars((string)$row['wallet_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($row['related_wallet_name'])): ?>
                                            <br><small class="text-muted">پەیوەندیدار: <?php echo htmlspecialchars((string)$row['related_wallet_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="جۆر"><?php echo htmlspecialchars(walletHistoryTypeLabel((string)$row['tx_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="ئاڕاستە"><span class="badge <?php echo $directionBadgeClass; ?>"><?php echo $isIn ? 'هاتن' : 'دەرچوون'; ?></span></td>
                                    <td data-label="بڕ" class="<?php echo $amountClass; ?>">
                                        <?php echo $amountPrefix . number_format((float)$row['amount'], (string)$row['currency'] === 'USD' ? 2 : 0); ?>
                                        <?php echo ' ' . htmlspecialchars((string)$row['currency'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td data-label="تێبینی">
                                        <?php
                                        $userNote = walletHistoryUserNote($row);
                                        $systemNote = walletHistoryNoteLabel($row);
                                        if ($userNote !== ''): ?>
                                            <span><?php echo htmlspecialchars($userNote, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if ($systemNote !== '' && $systemNote !== $userNote): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($systemNote, ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($systemNote, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="وەسڵ" class="text-center">
                                        <a
                                            class="btn btn-sm btn-outline-secondary history-print-btn"
                                            href="<?php echo htmlspecialchars($printReceiptBaseUrl . '?id=' . (int)$row['id'] . '&print=1&from=history', ENT_QUOTES, 'UTF-8'); ?>"
                                            target="_blank"
                                            rel="noopener"
                                            title="چاپکردنی وەسڵ"
                                        >
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($historyLimitReached): ?>
                <p class="text-muted small mb-0 p-3">
                    <i class="bi bi-info-circle"></i>
                    زیاتر لە 500 تۆمار نیشان نادرێت — ماوەی بەروار بچووکتر بکە.
                </p>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>

