<?php
/**
 * ئاگادارکەرەوەکان + باک ئەپی تیلیگرام - user/telegram/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once 'telegram_helper.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'telegram.view', [
    'route' => '/user/telegram/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = (int)$currentUser['id'];
$isMainUser = !isset($currentUser['user_type']) || $currentUser['user_type'] === 'main';

$success = '';
$error = '';

// دڵنیابوونەوە لە هەبوونی خشتەی system_settings
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    description TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// چالاککردنی سیستەمی تیلیگرام بۆ سەرجەم بەکارهێنەران (ئەگەر لە داتابەیس دانەمەزرابوو یان ناچالاک بوو)
$conn->query("INSERT INTO system_settings (setting_key, setting_value, description) 
              VALUES ('telegram_enabled', '1', 'دۆخی چالاکبوونی سیستەمی تیلیگرام') 
              ON DUPLICATE KEY UPDATE setting_value = '1'");

$telegramSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('telegram_bot_link', 'telegram_enabled', 'telegram_bot_token')");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $telegramSettings[$row['setting_key']] = $row['setting_value'];
    }
}

$telegramEnabled = isset($telegramSettings['telegram_enabled']) && $telegramSettings['telegram_enabled'] == '1';
$botLink = $telegramSettings['telegram_bot_link'] ?? '';
$botToken = $telegramSettings['telegram_bot_token'] ?? '';
if (empty($botToken) && function_exists('kasher_secret')) {
    $botToken = kasher_secret('telegram_bot_token', 'TELEGRAM_BOT_TOKEN') ?: kasher_secret('nrx_bot_token', 'NRX_BOT_TOKEN');
}

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

    elseif ($isMainUser && $action === 'admin_save_settings') {
        $newEnabled = isset($_POST['telegram_enabled']) && $_POST['telegram_enabled'] === '1' ? '1' : '0';
        $newBotToken = trim($_POST['telegram_bot_token'] ?? '');
        $newBotLink = trim($_POST['telegram_bot_link'] ?? '');

        if (!empty($newBotLink) && !preg_match('#^https?://#i', $newBotLink)) {
            $newBotLink = 'https://t.me/' . ltrim($newBotLink, '@');
        }

        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('telegram_enabled', ?, 'دۆخی چالاکبوونی سیستەمی تیلیگرام') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('s', $newEnabled);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('telegram_bot_token', ?, 'تۆکنی بۆتی تیلیگرام') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('s', $newBotToken);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('telegram_bot_link', ?, 'لینکی بۆتی تیلیگرام') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('s', $newBotLink);
        $stmt->execute();
        $stmt->close();

        $telegramEnabled = ($newEnabled === '1');
        $botToken = $newBotToken;
        $botLink = $newBotLink;
        $success = 'ڕێکخستنەکانی بۆتی تیلیگرام بە سەرکەوتوویی پاشەکەوت کران';
    }

    elseif ($isMainUser && $action === 'admin_test_bot') {
        $tokenToTest = trim($_POST['telegram_bot_token'] ?? $botToken);
        if (empty($tokenToTest)) {
            $error = 'تکایە سەرەتا تۆکنی بۆت داخڵ بکە';
        } else {
            $testRes = TelegramHelper::testBotToken($tokenToTest);
            if (!empty($testRes['ok'])) {
                $botInfo = $testRes['result'];
                $botName = $botInfo['first_name'] ?? '';
                $botUser = $botInfo['username'] ?? '';
                $success = "🎉 پەیوەندی سەرکەوتوو بوو! ناوی بۆت: {$botName} (@{$botUser})";

                if (!empty($botUser)) {
                    $autoLink = 'https://t.me/' . $botUser;
                    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('telegram_bot_link', ?, 'لینکی بۆتی تیلیگرام') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->bind_param('s', $autoLink);
                    $stmt->execute();
                    $stmt->close();
                    $botLink = $autoLink;
                }
            } else {
                $error = 'نەتوانرا پەیوەندی بە بۆتەوە بکرێت: ' . ($testRes['error'] ?? 'تۆکنەکە نادروستە');
            }
        }
    }

    elseif ($action === 'find_bot_chats') {
        $telegram = new TelegramHelper();
        $recentSenders = $telegram->getRecentBotSenders();
        if (empty($recentSenders)) {
            $error = 'هیچ نامەیەک نەدۆزرایەوە! تکایە لەناو بەرنامەی تیلیگرام بچۆ ناو بۆتەکەتان و دوگمەی Start داگرە، پاشان دووبارە کلیک لێرە بکەرەوە.';
        } else {
            $success = 'ئایدی(یەکان) دۆزرانەوە! کلیک لەسەر ئایدیەکەت بکە لە خوارەوە بۆ دانانی.';
        }
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
                $error = 'نەتوانرا پەیام بنێردرێت: ' . ($result['error'] ?? 'تکایە ئایدی و تۆکنی بۆت بپشکنەرەوە');
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ئاگادارکەرەوەکان + باک ئەپی تیلیگرام - <?php echo SITE_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/theme-modern.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/settings/settings.css'); ?>" rel="stylesheet">

    <style>
        .tg-step-badge {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--brand) 15%, transparent);
            color: var(--brand);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .tg-notify-row {
            background: var(--surface-2);
            border: 1px solid var(--border-default);
            border-radius: 14px;
            padding: 1rem 1.15rem;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .tg-notify-row:hover {
            transform: translateY(-2px);
            border-color: rgba(79, 70, 229, 0.35);
        }
        .tg-notify-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--brand) 12%, var(--surface-1));
            color: var(--brand);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }
    </style>
</head>
<body class="settings-module-page settings-page">

    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container py-4">

        <!-- Page Header -->
        <div class="settings-header-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <nav class="small text-muted mb-2" aria-label="breadcrumb">
                        <a href="<?php echo url('user/dashboard/index.php'); ?>" class="text-decoration-none text-muted">
                            <i class="bi bi-speedometer2"></i> داشبۆرد
                        </a>
                        <span class="mx-2">/</span>
                        <a href="<?php echo url('user/settings/main.php'); ?>" class="text-decoration-none text-muted">
                            ڕێکخستنەکان
                        </a>
                        <span class="mx-2">/</span>
                        <span class="text-primary fw-medium">تیلیگرام</span>
                    </nav>
                    <div class="d-flex align-items-center gap-3">
                        <div class="settings-icon section-telegram" style="width:52px;height:52px;font-size:24px;">
                            <i class="bi bi-telegram"></i>
                        </div>
                        <div>
                            <h2 class="mb-0">ئاگادارکەرەوەکان + باک ئەپی تیلیگرام</h2>
                            <p class="text-muted small mb-0">ئاگاداری ڕاستەوخۆ و ناردنی باک ئەپی ڕۆژانەی داتا</p>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <span class="badge rounded-pill <?php echo $telegramEnabled ? 'text-bg-success' : 'text-bg-secondary'; ?> px-3 py-2">
                        <i class="bi <?php echo $telegramEnabled ? 'bi-check-circle-fill' : 'bi-x-circle'; ?> me-1"></i>
                        سیستەم: <?php echo $telegramEnabled ? 'چالاک' : 'ناچالاک'; ?>
                    </span>
                    <?php if ($botLink): ?>
                    <a href="<?php echo htmlspecialchars($botLink); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
                        <i class="bi bi-telegram"></i> کردنەوەی بۆت
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right"></i> گەڕانەوە
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="داخستن"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="داخستن"></button>
            </div>
        <?php endif; ?>

        <?php if (!$telegramEnabled): ?>
            <div class="alert alert-warning mb-4">
                <i class="bi bi-exclamation-triangle me-2"></i>
                سیستەمی تیلیگرام لەلایەن بەڕێوەبەرەوە ناچالاکە. تکایە پەیوەندی بە بەڕێوەبەرەوە بکە.
            </div>
        <?php elseif ($isMainUser && empty($botToken)): ?>
            <div class="alert alert-info border-info-subtle mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <i class="bi bi-info-circle-fill me-2 text-info"></i>
                    <strong>سیستەمی تیلیگرام چالاکە!</strong> بۆ ئەوەی بۆتەکە بتوانێت پەیام و باک ئەپ بنێرێت، تکایە تۆکنی بۆتەکەت کە لە <a href="https://t.me/BotFather" target="_blank" class="fw-bold text-decoration-underline text-info-emphasis">@BotFather</a> وەرتگرتووە لە بەشی ڕێکخستنی بەڕێوەبەر دابنێ.
                </div>
                <a href="#admin-telegram-settings" class="btn btn-sm btn-info text-white">
                    <i class="bi bi-arrow-down-circle me-1"></i> دانانی تۆکنی بۆت
                </a>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- ڕێکخستنەکانی بۆتی تیلیگرام - تایبەت بە بەڕێوەبەر -->
            <?php if ($isMainUser): ?>
            <div class="col-12" id="admin-telegram-settings">
                <div class="settings-card card p-4 shadow-sm border-primary-subtle" style="background: var(--surface-1);">
                    <div class="settings-card-header-accent d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="settings-icon section-security" style="width:48px;height:48px;font-size:22px;">
                                <i class="bi bi-robot"></i>
                            </div>
                            <div>
                                <h4 class="mb-1 d-flex align-items-center gap-2">
                                    ڕێکخستنەکانی بۆتی تیلیگرام
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-normal">تایبەت بە بەڕێوەبەر</span>
                                </h4>
                                <p class="text-muted small mb-0">دیاریکردنی تۆکنی بۆت، لینکی فەرمی و چالاککردن بۆ هەموو یوزەرەکان</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill <?php echo !empty($botToken) ? 'text-bg-success' : 'text-bg-warning'; ?> px-3 py-2">
                                <i class="bi <?php echo !empty($botToken) ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'; ?> me-1"></i>
                                <?php echo !empty($botToken) ? 'تۆکنی بۆت دانراوە' : 'تۆکنی بۆت دیاری نەکراوە'; ?>
                            </span>
                        </div>
                    </div>

                    <form method="POST" class="mt-3">
                        <input type="hidden" name="action" value="admin_save_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="telegram_bot_token" class="form-label fw-bold small">
                                    <i class="bi bi-key-fill text-primary me-1"></i> تۆکنی بۆتی تیلیگرام (Bot Token)
                                </label>
                                <input type="text" class="form-control text-start font-monospace" id="telegram_bot_token" 
                                       name="telegram_bot_token" value="<?php echo htmlspecialchars($botToken); ?>" 
                                       placeholder="نموونە: 1234567890:ABCdefGHIjklMNOpqrSTUvwxYZ" dir="ltr" autocomplete="off">
                                <div class="form-text small">تۆکنەکەت لە <a href="https://t.me/BotFather" target="_blank" rel="noopener" class="text-decoration-underline">@BotFather</a> وەربگرە بە دروستکردنی بۆتێک.</div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="telegram_bot_link" class="form-label fw-bold small">
                                    <i class="bi bi-link-45deg text-primary me-1"></i> لینکی بۆت یان یوزەرنەیم (Bot Link / Username)
                                </label>
                                <input type="text" class="form-control text-start" id="telegram_bot_link" 
                                       name="telegram_bot_link" value="<?php echo htmlspecialchars($botLink); ?>" 
                                       placeholder="نموونە: https://t.me/MyStoreBot یان @MyStoreBot" dir="ltr" autocomplete="off">
                                <div class="form-text small">بۆ ئەوەی بەکارهێنەران دەستبەجێ لەناو ڕێنماییەکانەوە بۆتەکە بکەنەوە.</div>
                            </div>

                            <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-3 pt-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="telegram_enabled_switch" 
                                           name="telegram_enabled" value="1" <?php echo $telegramEnabled ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold small" for="telegram_enabled_switch">
                                        چالاککردنی سیستەمی تیلیگرام بۆ سەرجەم بەکارهێنەران
                                    </label>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" name="action" value="admin_test_bot" class="btn btn-outline-info">
                                        <i class="bi bi-plug-fill me-1"></i> پشکنینی پەیوەندی بۆت
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check2-circle me-1"></i> پاشەکەوتکردنی ڕێکخستنی بۆت
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- پەیوەندی تیلیگرام و ڕێنمایی -->
            <div class="col-12 col-lg-6">
                <div class="settings-card card p-4 h-100">
                    <div class="settings-card-header-accent">
                        <div class="settings-icon section-telegram">
                            <i class="bi bi-link-45deg"></i>
                        </div>
                        <div>
                            <h4 class="mb-1">پەیوەندی بە بۆتی تیلیگرام</h4>
                            <p class="text-muted small mb-0">ئایدی ئەکاونتەکەت دابنێ بۆ وەرگرتنی پەیامەکان</p>
                        </div>
                    </div>

                    <div class="p-3 border rounded-3 mb-4" style="background: var(--surface-2);">
                        <div class="fw-bold mb-3 d-flex align-items-center gap-2 text-primary">
                            <i class="bi bi-info-circle-fill"></i> هەنگاوەکانی دەستپێکردن:
                        </div>
                        <div class="d-flex flex-column gap-2 small">
                            <div class="d-flex align-items-center gap-2">
                                <span class="tg-step-badge">١</span>
                                <span>لە تیلیگرام بگەڕێ بۆ: 
                                <?php if (!empty($botLink)): ?>
                                    <a href="<?php echo htmlspecialchars($botLink); ?>" target="_blank" rel="noopener noreferrer" class="fw-bold text-primary text-decoration-none">
                                        <?php echo htmlspecialchars($botUsername); ?> <i class="bi bi-box-arrow-up-right small"></i>
                                    </a>
                                <?php else: ?>
                                    <strong class="text-primary"><?php echo htmlspecialchars($botUsername); ?></strong>
                                <?php endif; ?>
                                </span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="tg-step-badge">٢</span>
                                <span>دوگمەی <strong>Start</strong> داگرە لەناو بۆتەکە.</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="tg-step-badge">٣</span>
                                <span>فەرمانی <code class="px-2 py-1 rounded bg-body-tertiary">/start</code> بنێرە تا بۆتەکە بناسرێتەوە.</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="tg-step-badge">٤</span>
                                <span>ئایدیەکەت بنووسە یان کلیک لەسەر «وەرگرتنی ئایدی لە دوایین پەیامەکان» بکە.</span>
                            </div>
                        </div>

                        <div class="mt-3 p-2 rounded bg-danger-subtle text-danger border border-danger-subtle small">
                            <i class="bi bi-shield-exclamation me-1"></i>
                            <strong>تێبینی زۆر گرنگ:</strong> تیلیگرام ڕێگە نادات بۆت نامە بنێرێت تا ئەو کاتەی بەکارهێنەر لەناو بۆتەکە دوگمەی <strong>Start</strong> دانەگرێت (دەنا هەڵەی 403 دەدات).
                        </div>
                    </div>

                    <?php if (!empty($recentSenders)): ?>
                    <div class="p-3 border rounded-3 bg-body-tertiary mb-3">
                        <div class="small fw-bold text-success mb-2">
                            <i class="bi bi-check-circle-fill me-1"></i> کلیک لەسەر ناوت بکە بۆ دانانی ئایدیەکەت بە ئۆتۆماتیک:
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($recentSenders as $s): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1" 
                                    onclick="document.getElementById('telegram_id').value='<?php echo htmlspecialchars($s['chat_id']); ?>';">
                                <i class="bi bi-person-fill"></i>
                                <span><?php echo htmlspecialchars($s['name']); ?></span>
                                <span class="badge bg-primary text-white font-monospace"><?php echo htmlspecialchars($s['chat_id']); ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" class="mb-0">
                        <input type="hidden" name="action" value="update_telegram_id">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="telegram_id" class="form-label fw-bold mb-0">
                                    <i class="bi bi-person-badge"></i> ئایدی تیلیگرام (Chat ID)
                                </label>
                                <button type="submit" form="find-id-form" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.8rem;">
                                    <i class="bi bi-search me-1"></i> دۆزینەوەی ئایدی لە بۆت
                                </button>
                            </div>
                            <input type="text" class="form-control form-control-lg text-start" id="telegram_id"
                                   name="telegram_id" value="<?php echo htmlspecialchars($telegramId); ?>"
                                   placeholder="نموونە: 123456789"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   dir="ltr"
                                   <?php echo !$telegramEnabled ? 'disabled' : ''; ?>>
                            <div class="form-text">نموونە: 123456789 (تەنها ژمارەی Chat ID)</div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <button type="submit" class="btn btn-save" <?php echo !$telegramEnabled ? 'disabled' : ''; ?>>
                                <i class="bi bi-check-lg"></i> پاشەکەوتکردنی ئایدی
                            </button>

                            <?php if (!empty($telegramId) && $telegramEnabled): ?>
                            <button type="submit" form="test-msg-form" class="btn btn-outline-primary">
                                <i class="bi bi-send"></i> تاقیکردنەوەی پەیوەندی
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <form method="POST" id="find-id-form" class="d-none">
                        <input type="hidden" name="action" value="find_bot_chats">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    </form>

                    <?php if (!empty($telegramId) && $telegramEnabled): ?>
                    <form method="POST" id="test-msg-form" class="d-none">
                        <input type="hidden" name="action" value="send_test_message">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- باک ئەپەکانی ڕۆژانە -->
            <div class="col-12 col-lg-6">
                <div class="settings-card card p-4 h-100">
                    <div class="settings-card-header-accent">
                        <div class="settings-icon section-business">
                            <i class="bi bi-archive"></i>
                        </div>
                        <div>
                            <h4 class="mb-1">باک ئەپەکانی ڕۆژانە</h4>
                            <p class="text-muted small mb-0">ناردنی ڕاپۆرتی کڕیاران و کۆمپانیاکان بۆ تیلیگرام</p>
                        </div>
                    </div>

                    <p class="text-muted small mb-3">
                        لیستی کڕیاران و قەرزی کۆمپانیاکان ڕۆژانە کاتێک داخڵ دەبیت بۆ تیلیگرامەکەت دەنێردرێن. هەروەها دەتوانیت بە دەستی ڕاستەوخۆ بینێریت:
                    </p>

                    <?php if (!empty($telegramId) && $telegramEnabled): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6">
                            <form method="POST">
                                <input type="hidden" name="action" value="send_customers_report">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <button type="submit" class="btn btn-outline-primary w-100 py-2 d-flex align-items-center justify-content-center gap-2">
                                    <i class="bi bi-people-fill"></i> ناردنی لیستی کڕیاران
                                </button>
                            </form>
                        </div>
                        <div class="col-12 col-sm-6">
                            <form method="POST">
                                <input type="hidden" name="action" value="send_companies_report">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <button type="submit" class="btn btn-outline-primary w-100 py-2 d-flex align-items-center justify-content-center gap-2">
                                    <i class="bi bi-building"></i> ناردنی لیستی کۆمپانیاکان
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if ($lastSent): ?>
                    <div class="p-3 border rounded-3 text-center" style="background: var(--surface-2);">
                        <i class="bi bi-clock-history text-primary me-1"></i>
                        <span class="small text-muted">دوایین باک ئەپی ڕۆژانەی نێردراو: </span>
                        <strong class="small"><?php echo date('Y/m/d - H:i', strtotime($lastSent)); ?></strong>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <div class="alert alert-info border mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        بۆ ناردن و وەرگرتنی باک ئەپەکان، سەرەتا ئایدی تیلیگرامت لە بەشی پەیوەندی دابنێ.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ئاگادارکەرەوەکانی ڕاستەوخۆ -->
            <div class="col-12">
                <div class="settings-card card p-4">
                    <div class="settings-card-header-accent">
                        <div class="settings-icon section-security">
                            <i class="bi bi-bell"></i>
                        </div>
                        <div>
                            <h4 class="mb-1">ئاگادارکەرەوەکانی ڕاستەوخۆ (Instant Alerts)</h4>
                            <p class="text-muted small mb-0">کاتێک ئەم ڕووداوانە ئەنجام دەدرێن، دەستبەجێ پەیامێک دەنێردرێت بۆ تیلیگرام</p>
                        </div>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($notificationCatalog as $notification): ?>
                        <?php
                        $notificationFeatureKey = $notification['feature_key'] ?? null;
                        $notificationEnabledForPackage = $notificationFeatureKey === null
                            || hasFeaturePermission($notificationFeatureKey);
                        ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="tg-notify-row h-100 d-flex align-items-start gap-3 <?php echo $notificationEnabledForPackage ? '' : 'opacity-60'; ?>">
                                <span class="tg-notify-icon-box" aria-hidden="true">
                                    <i class="bi <?php echo htmlspecialchars($notification['icon']); ?>"></i>
                                </span>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                                        <strong class="small"><?php echo htmlspecialchars($notification['title']); ?></strong>
                                        <?php if ($notificationEnabledForPackage): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">خۆکار</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">ناچالاک</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted small mb-0"><?php echo htmlspecialchars($notification['description']); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- دوایین چالاکییەکان -->
            <?php if (!empty($recentLogs)): ?>
            <div class="col-12">
                <div class="settings-card card p-4">
                    <div class="settings-card-header-accent">
                        <div class="settings-icon section-info">
                            <i class="bi bi-journal-text"></i>
                        </div>
                        <div>
                            <h4 class="mb-1">دوایین تۆماری پەیامە نێردراوەکان</h4>
                            <p class="text-muted small mb-0">تۆماری پەیام و باک ئەپەکانی ئەم دواییەی بۆ تیلیگرام نێردراون</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr class="text-muted small">
                                    <th>جۆری پەیام</th>
                                    <th>دۆخی ناردن</th>
                                    <th>کاتی ناردن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td class="fw-medium small"><?php echo htmlspecialchars(TelegramHelper::getMessageTypeLabel($log['message_type'])); ?></td>
                                    <td>
                                        <?php if ($log['status'] === 'success'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">سەرکەوتوو</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">شکستخوارد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo date('Y/m/d - H:i', strtotime($log['sent_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
