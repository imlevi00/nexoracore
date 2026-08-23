<?php
/**
 * ئاگادارکەرەوەکان + باک ئەپی تیلیگرام - user/telegram/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once 'telegram_helper.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'telegram.view', [
    'route' => '/user/telegram/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

$success = '';
$error = '';

$telegramSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('telegram_bot_link', 'telegram_enabled')");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $telegramSettings[$row['setting_key']] = $row['setting_value'];
    }
}

$telegramEnabled = isset($telegramSettings['telegram_enabled']) && $telegramSettings['telegram_enabled'] == '1';
$botLink = $telegramSettings['telegram_bot_link'] ?? '';

$stmt = $conn->prepare("SELECT telegram_id, telegram_last_sent FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$stmt->close();

$telegramId = $userData['telegram_id'] ?? '';
$lastSent = $userData['telegram_last_sent'] ?? null;
$notificationCatalog = TelegramHelper::getNotificationCatalog();
$recentLogs = TelegramHelper::getRecentUserLogs($userId, 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    }

    elseif ($action === 'update_telegram_id') {
        $newTelegramId = trim($_POST['telegram_id'] ?? '');

        $stmt = $conn->prepare("UPDATE users SET telegram_id = ? WHERE id = ?");
        $stmt->bind_param("si", $newTelegramId, $userId);

        if ($stmt->execute()) {
            $telegramId = $newTelegramId;
            $success = 'ئایدی تیلیگرام بە سەرکەوتوویی نوێکرایەوە';
            writeLog("Telegram ID updated by user {$currentUser['email']}");
        } else {
            $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی ئایدی تیلیگرام';
        }
        $stmt->close();
    }

    elseif ($action === 'send_test_message') {
        if (empty($telegramId)) {
            $error = 'تکایە سەرەتا ئایدی تیلیگرامت دابنێ';
        } elseif (!$telegramEnabled) {
            $error = 'سیستەمی تیلیگرام ناچالاکە';
        } else {
            $telegram = new TelegramHelper();
            $message = "🎉 پیرۆزە!\n\n";
            $message .= "سیستەمی ئاگادارکەرەوەکان و باک ئەپی تیلیگرامت بە سەرکەوتوویی کارپێکرا.\n\n";
            $message .= "📊 فرۆشگا: " . TelegramHelper::escapeHtml($currentUser['business_name']) . "\n";
            $message .= "📧 ئیمەیڵ: " . TelegramHelper::escapeHtml($currentUser['email']) . "\n\n";
            $message .= "لە ئێستاوە ئاگادارکەرەوەکانی ڕاستەوخۆ و باک ئەپەکانی ڕۆژانە بۆ دەنێردرێت.";

            $result = $telegram->sendMessage($telegramId, $message);

            if ($result['success']) {
                $success = 'پەیامی تاقیکردنەوە بە سەرکەوتوویی نێردرا!';
                TelegramHelper::logTelegramSend($userId, 'test_message', $telegramId, 'success');
                $recentLogs = TelegramHelper::getRecentUserLogs($userId, 10);
            } else {
                $error = 'نەتوانرا پەیام بنێردرێت. تکایە ئایدی تیلیگرامەکەت بپشکنەرەوە';
                TelegramHelper::logTelegramSend($userId, 'test_message', $telegramId, 'failed', $result['error'] ?? 'Unknown error');
            }
        }
    }

    elseif ($action === 'send_customers_report' || $action === 'send_companies_report') {
        if (empty($telegramId)) {
            $error = 'تکایە سەرەتا ئایدی تیلیگرامت دابنێ';
        } elseif (!$telegramEnabled) {
            $error = 'سیستەمی تیلیگرام ناچالاکە';
        } else {
            if ($action === 'send_companies_report') {
                $reportData = TelegramHelper::generateCompaniesPrintHTML($userId);
                $caption = "📋 لیستی قەرزی کۆمپانیاکان\n📅 " . date('Y/m/d - H:i');
                $logType = 'companies_report';
                $okMsg = 'لیستی کۆمپانیاکان بە سەرکەوتوویی نێردرا!';
                $failPrefix = 'نەتوانرا لیستی کۆمپانیاکان بنێردرێت';
                $genFail = 'نەتوانرا لیستی کۆمپانیاکان دروست بکرێت';
            } else {
                $reportData = TelegramHelper::generateCustomersPrintHTML($userId);
                $caption = "📋 لیستی کڕیاران\n📅 " . date('Y/m/d - H:i');
                $logType = 'customers_report';
                $okMsg = 'لیستی کڕیاران بە سەرکەوتوویی نێردرا!';
                $failPrefix = 'نەتوانرا لیستی کڕیاران بنێردرێت';
                $genFail = 'نەتوانرا لیستی کڕیاران دروست بکرێت';
            }

            if ($reportData) {
                $telegram = new TelegramHelper();
                $result = $telegram->sendDocument($telegramId, $reportData['file_path'], $caption);

                if ($result['success']) {
                    $success = $okMsg;
                    TelegramHelper::logTelegramSend($userId, $logType, $telegramId, 'success');
                    $recentLogs = TelegramHelper::getRecentUserLogs($userId, 10);
                    @unlink($reportData['file_path']);
                } else {
                    $error = $failPrefix . ': ' . ($result['error'] ?? 'Unknown error');
                    TelegramHelper::logTelegramSend($userId, $logType, $telegramId, 'failed', $result['error'] ?? 'Unknown error');
                    @unlink($reportData['file_path']);
                }
            } else {
                $error = $genFail;
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();
$botUsername = $botLink ? '@' . str_replace('https://t.me/', '', $botLink) : 'بۆتەکە';
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ئاگادارکەرەوەکان + باک ئەپی تیلیگرام - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <style>
        .tg-page .tg-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 8px 24px rgba(15, 23, 42, 0.06);
        }
        .tg-page .tg-hero-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
        }
        .tg-page .tg-notify-item {
            border: 1px solid var(--bs-border-color);
            border-radius: 12px;
            padding: 0.9rem 1rem;
            background: var(--bs-body-bg);
        }
        .tg-page .tg-notify-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .tg-page .tg-section-title {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
    </style>
</head>
<body class="bg-light tg-page">

    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container py-4" style="max-width: 820px;">

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <nav class="small text-muted mb-1" aria-label="breadcrumb">
                    <a href="<?php echo url('user/settings/main.php'); ?>" class="text-decoration-none text-muted">ڕێکخستنەکان</a>
                    <span class="mx-1">/</span>
                    <span>ئاگادارکەرەوەکان</span>
                </nav>
                <h1 class="h3 mb-1 fw-semibold d-flex align-items-center gap-2 flex-wrap">
                    <span class="tg-hero-icon" aria-hidden="true"><i class="bi bi-telegram"></i></span>
                    ئاگادارکەرەوەکان + باک ئەپی تیلیگرام
                </h1>
                <p class="text-muted small mb-0">ئاگاداری ڕاستەوخۆ و باک ئەپی ڕۆژانە بۆ بەڕێوبەری ئەکاوەنت لە تیلیگرام</p>
            </div>
            <div class="d-flex flex-column align-items-end gap-1">
                <span class="badge rounded-pill <?php echo $telegramEnabled ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                    <?php echo $telegramEnabled ? 'چالاک' : 'ناچالاک'; ?>
                </span>
                <?php if ($botLink): ?>
                <a href="<?php echo htmlspecialchars($botLink); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-box-arrow-up-left"></i> کردنەوەی بۆت
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-1"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="داخستن"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="داخستن"></button>
            </div>
        <?php endif; ?>

        <?php if (!$telegramEnabled): ?>
            <div class="alert alert-warning mb-3">
                <i class="bi bi-exclamation-triangle"></i>
                سیستەمی تیلیگرام لەلایەن بەڕێوەبەرەوە ناچالاکە. تکایە پەیوەندی بە بەڕێوەبەرەوە بکە.
            </div>
        <?php endif; ?>

        <!-- پەیوەندی تیلیگرام -->
        <div class="card tg-card mb-3">
            <div class="card-body p-4">
                <div class="tg-section-title"><i class="bi bi-link-45deg text-primary"></i> پەیوەندی تیلیگرام</div>

                <?php if (empty($telegramId)): ?>
                <div class="bg-body-secondary border rounded-3 p-3 mb-3">
                    <div class="fw-semibold mb-2"><i class="bi bi-lightbulb text-warning"></i> چۆنیەتی ڕێکخستن</div>
                    <ol class="small mb-0 ps-3">
                        <li class="mb-2">لە تیلیگرام بگەڕێ بۆ: <code><?php echo htmlspecialchars($botUsername); ?></code></li>
                        <li class="mb-2">دووگمەی <strong>Start</strong> داگرە</li>
                        <li class="mb-2">فەرمانی <code>/id</code> بنێرە بۆ وەرگرتنی ئایدی خۆت</li>
                        <li>ئایدیەکەت لە خوارەوە دابنێ</li>
                    </ol>
                </div>
                <?php endif; ?>

                <form method="POST" class="mb-0">
                    <input type="hidden" name="action" value="update_telegram_id">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="mb-3">
                        <label for="telegram_id" class="form-label fw-medium">
                            <i class="bi bi-telegram text-primary"></i> ئایدی تیلیگرام
                        </label>
                        <input type="text" class="form-control" id="telegram_id"
                               name="telegram_id" value="<?php echo htmlspecialchars($telegramId); ?>"
                               placeholder="123456789"
                               inputmode="numeric"
                               autocomplete="off"
                               <?php echo !$telegramEnabled ? 'disabled' : ''; ?>>
                        <div class="form-text">نموونە: 123456789 (تەنها ژمارە) — ئەم ئایدییە وەردەگیرێت بۆ هەموو ئاگادارکەرەوەکان و باک ئەپەکان</div>
                    </div>

                    <button type="submit" class="btn btn-primary" <?php echo !$telegramEnabled ? 'disabled' : ''; ?>>
                        <i class="bi bi-save"></i> پاشەکەوتکردن
                    </button>
                </form>
            </div>
        </div>

        <!-- ئاگادارکەرەوەکانی ڕاستەوخۆ -->
        <div class="card tg-card mb-3">
            <div class="card-body p-4">
                <div class="tg-section-title"><i class="bi bi-bell text-danger"></i> ئاگادارکەرەوەکانی ڕاستەوخۆ</div>
                <p class="text-muted small mb-3">کاتێک ئەم ڕووداوانە ڕوودەدەن، پەیامێکی فوری بۆ تیلیگرامەکەت دەنێردرێت.</p>

                <div class="d-flex flex-column gap-2">
                    <?php foreach ($notificationCatalog as $notification): ?>
                    <?php
                    $notificationFeatureKey = $notification['feature_key'] ?? null;
                    $notificationEnabledForPackage = $notificationFeatureKey === null
                        || hasFeaturePermission($notificationFeatureKey);
                    ?>
                    <div class="tg-notify-item d-flex align-items-start gap-3<?php echo $notificationEnabledForPackage ? '' : ' opacity-75'; ?>">
                        <span class="tg-notify-icon" aria-hidden="true">
                            <i class="bi <?php echo htmlspecialchars($notification['icon']); ?>"></i>
                        </span>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <strong class="small"><?php echo htmlspecialchars($notification['title']); ?></strong>
                                <?php if ($notificationEnabledForPackage): ?>
                                <span class="badge text-bg-primary">خۆکار</span>
                                <?php else: ?>
                                <span class="badge text-bg-secondary">ناچالاک بۆ پاکێجەکەت</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-muted small mb-0 mt-1"><?php echo htmlspecialchars($notification['description']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($telegramId) && $telegramEnabled): ?>
                <div class="mt-3">
                    <form method="POST">
                        <input type="hidden" name="action" value="send_test_message">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-send"></i> پەیامی تاقیکردنەوە
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- باک ئەپەکانی ڕۆژانە -->
        <div class="card tg-card mb-3">
            <div class="card-body p-4">
                <div class="tg-section-title"><i class="bi bi-archive text-success"></i> باک ئەپەکانی ڕۆژانە</div>
                <p class="text-muted small mb-3">لیستی کڕیاران و لیستی قەرزی کۆمپانیاکان بە شێوەی HTML ڕۆژانە یەک جار دەنێردرێن کاتێک بۆ یەکەم جار داخڵ دەبیتەوە.</p>

                <ul class="small text-muted mb-3">
                    <li>لیستی کڕیاران — قەرز و زانیاری کڕیار</li>
                    <li>لیستی کۆمپانیاکان — قەرزی کۆمپانیاکان</li>
                </ul>

                <?php if (!empty($telegramId) && $telegramEnabled): ?>
                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <form method="POST">
                            <input type="hidden" name="action" value="send_customers_report">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-people"></i> ناردنی لیستی کڕیاران
                            </button>
                        </form>
                    </div>
                    <div class="col-12 col-md-6">
                        <form method="POST">
                            <input type="hidden" name="action" value="send_companies_report">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-building"></i> ناردنی لیستی کۆمپانیاکان
                            </button>
                        </form>
                    </div>
                </div>

                <?php if ($lastSent): ?>
                <p class="text-center text-muted small mt-3 mb-0">
                    <i class="bi bi-clock-history"></i>
                    دوایین باک ئەپی ڕۆژانە: <?php echo date('Y/m/d - H:i', strtotime($lastSent)); ?>
                </p>
                <?php endif; ?>
                <?php else: ?>
                <div class="alert alert-light border mb-0 small">
                    <i class="bi bi-info-circle text-primary"></i>
                    بۆ وەرگرتنی باک ئەپەکان، سەرەتا ئایدی تیلیگرامت دابنێ.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- دوایین چالاکییەکان -->
        <?php if (!empty($recentLogs)): ?>
        <div class="card tg-card">
            <div class="card-body p-4">
                <div class="tg-section-title"><i class="bi bi-clock-history text-secondary"></i> دوایین چالاکییەکان</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>جۆر</th>
                                <th>دۆخ</th>
                                <th>کات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td class="small"><?php echo htmlspecialchars(TelegramHelper::getMessageTypeLabel($log['message_type'])); ?></td>
                                <td>
                                    <?php if ($log['status'] === 'success'): ?>
                                        <span class="badge text-bg-success">سەرکەوتوو</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger">شکستخوارد</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?php echo date('Y/m/d H:i', strtotime($log['sent_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
