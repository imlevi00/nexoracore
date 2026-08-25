<?php
/**
 * ڕێکخستنەکان - user/settings/index.php
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/kasher_zanyari/database.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/permissions.php';

/** @var mysqli $conn */
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = $GLOBALS['conn'] ?? (new Database())->connect();
}

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'settings.view', [
    'route' => '/user/settings/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];
$dbZanyari = $conn_zanyari instanceof mysqli ? $conn_zanyari : null;
$themeMode = 'light';

$success = '';
$error = '';

if (!empty($_SESSION['user_theme_mode'])) {
    $sessionThemeMode = strtolower((string)$_SESSION['user_theme_mode']);
    if (in_array($sessionThemeMode, ['light', 'dark', 'system'], true)) {
        $themeMode = $sessionThemeMode;
    }
}

if ($dbZanyari !== null && $userId > 0) {
    $themeStmt = $dbZanyari->prepare('SELECT theme_mode FROM user_account_settings WHERE user_id = ? LIMIT 1');
    if ($themeStmt) {
        $themeStmt->bind_param('i', $userId);
        if ($themeStmt->execute()) {
            $themeRow = $themeStmt->get_result()->fetch_assoc();
            if ($themeRow && isset($themeRow['theme_mode'])) {
                $dbThemeMode = strtolower((string)$themeRow['theme_mode']);
                if (in_array($dbThemeMode, ['light', 'dark', 'system'], true)) {
                    $themeMode = $dbThemeMode;
                    $_SESSION['user_theme_mode'] = $themeMode;
                    $_SESSION['user_data']['theme_mode'] = $themeMode;
                }
            }
        }
        $themeStmt->close();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    }
    
    elseif ($action === 'update_business_name') {
        $businessName = cleanInput($_POST['business_name'] ?? '');
        
        if (empty($businessName)) {
            $error = 'تکایە ناوی فرۆشگا داخڵ بکە';
        } else {
            $stmt = $conn->prepare("UPDATE users SET business_name = ? WHERE id = ?");
            $stmt->bind_param("si", $businessName, $userId);
            
            if ($stmt->execute()) {
                // Update session data
                $_SESSION['user']['business_name'] = $businessName;
                $currentUser['business_name'] = $businessName;
                
                $success = 'ناوی فرۆشگا بە سەرکەوتوویی نوێکرایەوە';
                writeLog("Business name updated by user {$currentUser['email']}: {$businessName}");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی ناوی فرۆشگا';
            }
            $stmt->close();
        }
    }
    
    elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'تکایە هەموو خانەکان پڕبکەرەوە';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'پاسۆردی نوێ و پشتڕاستکردنەوەی پاسۆرد جیاوازن';
        } elseif (strlen($newPassword) < 6) {
            $error = 'پاسۆردی نوێ دەبێت لانیکەم ٦ پیت بێت';
        } else {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($user = $result->fetch_assoc()) {
                if (Security::verifyPassword($currentPassword, $user['password'])) {
                    // Update password
                    $hashedPassword = Security::hashPassword($newPassword);
                    $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $updateStmt->bind_param("si", $hashedPassword, $userId);
                    
                    if ($updateStmt->execute()) {
                        $success = 'پاسۆرد بە سەرکەوتوویی گۆڕدرا';
                        writeLog("Password changed by user {$currentUser['email']}");
                    } else {
                        $error = 'هەڵەیەک ڕوویدا لە گۆڕینی پاسۆرد';
                    }
                    $updateStmt->close();
                } else {
                    $error = 'پاسۆردی ئێستا هەڵەیە';
                }
            } else {
                $error = 'بەکارهێنەر نەدۆزرایەوە';
            }
            $stmt->close();
        }
    }
    
    elseif ($action === 'update_business_type') {
        $businessTypeId = isset($_POST['business_type_id']) ? (int) $_POST['business_type_id'] : 0;
        
        if ($businessTypeId < 1) {
            $error = 'تکایە جۆری ئیش و کار هەڵبژێرە';
        } else {
            // پشکنینی ئەوەی ID ڕەسەنە لە business_types
            $checkStmt = $conn->prepare("SELECT id FROM business_types WHERE id = ?");
            $checkStmt->bind_param("i", $businessTypeId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $checkStmt->close();
            
            if ($checkResult->num_rows === 0) {
                $error = 'جۆری ئیشوکار نادروستە';
            } else {
                $settingsCheck = $conn->prepare("SELECT id FROM settings WHERE user_id = ? LIMIT 1");
                $settingsCheck->bind_param("i", $userId);
                $settingsCheck->execute();
                $settingsResult = $settingsCheck->get_result();
                
                if ($settingsResult->num_rows > 0) {
                    $updateStmt = $conn->prepare("UPDATE settings SET business_type_id = ? WHERE user_id = ?");
                    $updateStmt->bind_param("ii", $businessTypeId, $userId);
                    $done = $updateStmt->execute();
                    $updateStmt->close();
                } else {
                    $insertStmt = $conn->prepare("INSERT INTO settings (user_id, business_type_id) VALUES (?, ?)");
                    $insertStmt->bind_param("ii", $userId, $businessTypeId);
                    $done = $insertStmt->execute();
                    $insertStmt->close();
                }
                $settingsCheck->close();
                
                if ($done) {
                    $success = 'جۆری ئیش بە سەرکەوتوویی نوێکرایەوە';
                    writeLog("Business type updated by user {$currentUser['email']}: {$businessTypeId}");
                } else {
                    $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی جۆری ئیش';
                }
            }
        }
    }

    elseif ($action === 'update_theme_mode') {
        $postedThemeMode = strtolower((string)($_POST['theme_mode'] ?? 'light'));
        if (!in_array($postedThemeMode, ['light', 'dark', 'system'], true)) {
            $error = 'دۆخی ڕووکار نادروستە';
        } elseif ($dbZanyari === null) {
            $error = 'هەڵەی پەیوەندی داتابەیس. تکایە دواتر هەوڵ بدەرەوە';
        } else {
            $themeStmt = $dbZanyari->prepare(
                'INSERT INTO user_account_settings (user_id, theme_mode) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE theme_mode = VALUES(theme_mode)'
            );

            if ($themeStmt) {
                $themeStmt->bind_param('is', $userId, $postedThemeMode);
                if ($themeStmt->execute()) {
                    $themeMode = $postedThemeMode;
                    $_SESSION['user_theme_mode'] = $themeMode;
                    $_SESSION['user_data']['theme_mode'] = $themeMode;
                    $success = 'دۆخی دیزاین بە سەرکەوتوویی نوێکرایەوە';
                    writeLog("Theme mode updated by user {$currentUser['email']}: {$themeMode}");
                } else {
                    $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی دۆخی دیزاین';
                }
                $themeStmt->close();
            } else {
                $error = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی دۆخی دیزاین';
            }
        }
    }
}

// Get current user data
$stmt = $conn->prepare("SELECT business_name, email, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$stmt->close();

// وەرگرتنی جۆرەکانی ئیش و هەڵبژاردەی ئێستای یوزەر
$businessTypes = [];
$businessTypeResult = $conn->query("SELECT id, code, name_ku FROM business_types ORDER BY sort_order ASC, id ASC");
if ($businessTypeResult) {
    while ($row = $businessTypeResult->fetch_assoc()) {
        $businessTypes[] = $row;
    }
    $businessTypeResult->free();
}
$currentBusinessTypeId = null;
$settingsStmt = $conn->prepare("SELECT business_type_id FROM settings WHERE user_id = ? LIMIT 1");
$settingsStmt->bind_param("i", $userId);
$settingsStmt->execute();
$settingsRow = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();
if ($settingsRow && $settingsRow['business_type_id'] !== null) {
    $currentBusinessTypeId = (int) $settingsRow['business_type_id'];
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ڕێکخستنە سەرەکییەکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/theme-modern.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/settings/settings.css'); ?>" rel="stylesheet">

    <style>
        .settings-nav-tabs {
            border-bottom: 2px solid var(--border-default);
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .settings-nav-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-secondary);
            font-weight: 700;
            padding: 0.75rem 1.25rem;
            border-radius: 0;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }
        .settings-nav-tabs .nav-link:hover {
            color: var(--brand);
            border-bottom-color: rgba(79, 70, 229, 0.4);
        }
        .settings-nav-tabs .nav-link.active {
            color: var(--brand);
            background: transparent;
            border-bottom-color: var(--brand);
        }
        .theme-preview-card {
            border: 2px solid var(--border-default);
            border-radius: 16px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.22s ease;
            background: var(--surface-1);
            position: relative;
        }
        .theme-preview-card:hover {
            border-color: var(--brand);
            transform: translateY(-2px);
        }
        .theme-preview-card.active,
        .theme-preview-card:has(input:checked) {
            border-color: var(--brand);
            background: color-mix(in srgb, var(--brand) 8%, var(--surface-1));
            box-shadow: 0 0 0 1px var(--brand);
        }
        .theme-visual-box {
            height: 70px;
            border-radius: 10px;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-default);
            font-size: 1.75rem;
        }
        .theme-box-light {
            background: #ffffff;
            color: #f59e0b;
        }
        .theme-box-dark {
            background: #0f172a;
            color: #818cf8;
        }
        .theme-box-system {
            background: linear-gradient(135deg, #ffffff 50%, #0f172a 50%);
            color: #06b6d4;
        }
        .copy-btn-mini {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-size: 0.9rem;
            cursor: pointer;
            padding: 0.2rem 0.4rem;
            border-radius: 6px;
            transition: color 0.15s ease, background 0.15s ease;
        }
        .copy-btn-mini:hover {
            color: var(--brand);
            background: var(--surface-2);
        }
        .input-eye-toggle {
            cursor: pointer;
        }
    </style>
</head>
<body class="settings-module-page settings-page">
    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container py-4">
        
        <!-- Header -->
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
                        <span class="text-primary fw-bold">ڕێکخستنە سەرەکییەکان</span>
                    </nav>
                    <h2 class="mb-1 d-flex align-items-center gap-2">
                        <i class="bi bi-sliders text-primary"></i>
                        ڕێکخستنە سەرەکییەکان
                    </h2>
                    <p class="text-muted mb-0">بەڕێوەبردنی زانیاریەکانی ئەکاونت، فرۆشگا، ڕووکار و چاپکردن</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right"></i> گەڕانەوە
                    </a>
                </div>
            </div>
        </div>

        <!-- Messages -->
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

        <!-- Category Tabs -->
        <ul class="nav settings-nav-tabs" id="settingsTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general-pane" type="button" role="tab" aria-selected="true">
                    <i class="bi bi-shop"></i> فرۆشگا و ئەکاونت
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="appearance-tab" data-bs-toggle="tab" data-bs-target="#appearance-pane" type="button" role="tab" aria-selected="false">
                    <i class="bi bi-palette"></i> دۆخی دیزاین
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security-pane" type="button" role="tab" aria-selected="false">
                    <i class="bi bi-shield-lock"></i> ئاسایش و پاسۆرد
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="receipts-tab" data-bs-toggle="tab" data-bs-target="#receipts-pane" type="button" role="tab" aria-selected="false">
                    <i class="bi bi-receipt"></i> وەسڵ و چاپکردن
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="settingsTabContent">
            
            <!-- Tab 1: فرۆشگا و ئەکاونت -->
            <div class="tab-pane fade show active" id="general-pane" role="tabpanel" tabindex="0">
                <div class="row g-4">
                    
                    <!-- زانیاری ئەکاونت -->
                    <div class="col-12">
                        <div class="settings-card card p-4">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-info">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">زانیاری گشتی ئەکاونت</h4>
                                    <p class="text-muted small mb-0">زانیاری و دۆخی تۆمارکراوی هەژمارەکەت لە سیستەم</p>
                                </div>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <div class="info-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="info-label"><i class="bi bi-envelope-at"></i> ئیمەیڵ</span>
                                            <button type="button" class="copy-btn-mini" onclick="copyText('<?php echo htmlspecialchars($userData['email'] ?? ''); ?>')" title="کۆپیکردن">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                        <div class="info-value text-truncate">
                                            <?php echo htmlspecialchars($userData['email'] ?? '—'); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <div class="info-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="info-label"><i class="bi bi-shop"></i> ناوی فرۆشگا</span>
                                            <button type="button" class="copy-btn-mini" onclick="copyText('<?php echo htmlspecialchars($userData['business_name'] ?? ''); ?>')" title="کۆپیکردن">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                        <div class="info-value text-truncate">
                                            <?php echo htmlspecialchars($userData['business_name'] ?? '—'); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <div class="info-item">
                                        <span class="info-label"><i class="bi bi-calendar3"></i> بەرواری دروستکردن</span>
                                        <div class="info-value">
                                            <?php echo !empty($userData['created_at']) ? date('Y/m/d - H:i', strtotime($userData['created_at'])) : '—'; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <div class="info-item">
                                        <span class="info-label"><i class="bi bi-shield-check"></i> دۆخی ئەکاونت</span>
                                        <div class="info-value">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill">
                                                <i class="bi bi-check-circle-fill me-1"></i> چالاک
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ناوی فرۆشگا -->
                    <div class="col-lg-6">
                        <div class="settings-card card p-4 h-100 d-flex flex-column">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-business">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">ناوی فرۆشگا</h4>
                                    <p class="text-muted small mb-0">گۆڕینی ناوی فەرمی بازرگانی فرۆشگاکەت</p>
                                </div>
                            </div>
                            
                            <form method="POST" class="needs-validation flex-grow-1 d-flex flex-column justify-content-between" novalidate>
                                <input type="hidden" name="action" value="update_business_name">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="mb-4">
                                    <label for="business_name" class="form-label fw-semibold">
                                        ناوی نوێی فرۆشگا
                                    </label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text bg-body-tertiary"><i class="bi bi-shop text-muted"></i></span>
                                        <input type="text" class="form-control" id="business_name" 
                                               name="business_name" value="<?php echo htmlspecialchars($userData['business_name'] ?? ''); ?>" 
                                               placeholder="ناوی فرۆشگا بنووسە..." required>
                                    </div>
                                    <div class="invalid-feedback">
                                        تکایە ناوی فرۆشگا داخڵ بکە
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end pt-3 border-top border-light-subtle">
                                    <button type="submit" class="btn btn-save">
                                        <i class="bi bi-check-lg"></i> نوێکردنەوەی ناو
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- لۆگۆی فرۆشگا -->
                    <div class="col-lg-6">
                        <div class="settings-card card p-4 h-100 d-flex flex-column">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-business">
                                    <i class="bi bi-image-fill"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">لۆگۆی فرۆشگا</h4>
                                    <p class="text-muted small mb-0">دانانی وێنەی فەرمی و لۆگۆی تایبەت بە فرۆشگاکەت</p>
                                </div>
                            </div>
                            
                            <form id="userLogoForm" enctype="multipart/form-data" class="flex-grow-1 d-flex flex-column justify-content-between">
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">
                                        هەڵبژاردنی فایلی لۆگۆ
                                    </label>
                                    <div class="surface-soft text-center p-4">
                                        <input type="file" class="form-control mb-2" id="logoImage" name="logo_image" accept="image/*">
                                        <small class="text-muted d-block mt-2">
                                            <i class="bi bi-info-circle me-1"></i> فۆرماتی ڕێگەپێدراو: JPG, PNG, GIF, WEBP (تا ٥ مێگابایت)
                                        </small>
                                        <div id="currentLogoPreview" class="mt-3" style="display: none;">
                                            <label class="form-label d-block text-muted small fw-semibold">لۆگۆی ئێستا:</label>
                                            <div class="position-relative d-inline-block">
                                                <img id="currentLogoImg" src="" alt="Logo" class="rounded-3 shadow-sm" style="max-width: 100%; max-height: 120px; object-fit: contain; background: var(--surface-1); padding: 6px; border: 1px solid var(--border-default);">
                                                <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 rounded-circle shadow" id="removeLogoBtn" title="سڕینەوەی لۆگۆ">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div id="newLogoPreview" class="mt-3" style="display: none;">
                                            <label class="form-label d-block text-muted small fw-semibold">پێشبینینی وێنەی نوێ:</label>
                                            <img id="newLogoImg" src="" alt="Preview" class="rounded-3 shadow-sm border" style="max-width: 100%; max-height: 120px; object-fit: contain; background: var(--surface-1); padding: 6px;">
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end pt-3 border-top border-light-subtle">
                                    <button type="button" class="btn btn-save" id="saveLogoBtn">
                                        <i class="bi bi-check-circle"></i> پاشەکەوتکردنی لۆگۆ
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- جۆری ئیش و کار -->
                    <div class="col-12">
                        <div class="settings-card card p-4">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-business">
                                    <i class="bi bi-briefcase"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">جۆری ئیش و کار</h4>
                                    <p class="text-muted small mb-0">دیاریکردنی کەرتی چالاکی و مۆدێلی فرۆشگاکەت بۆ ڕێکخستنی شاشەی فرۆشتن</p>
                                </div>
                            </div>
                            
                            <form method="POST" class="needs-validation" novalidate>
                                <input type="hidden" name="action" value="update_business_type">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="mb-4">
                                    <label class="form-label fw-semibold mb-3">
                                        لیستی جۆرە بەردەستەکان
                                    </label>
                                    <div class="row g-3">
                                        <?php foreach ($businessTypes as $bt): ?>
                                        <?php $isCurtainShopType = (trim((string)($bt['code'] ?? '')) === 'curtain_shop'); ?>
                                        <div class="col-12 col-md-6 col-lg-4">
                                            <label class="selectable-card-option h-100 <?php echo ($currentBusinessTypeId === (int)$bt['id']) ? 'active' : ''; ?>" for="business_type_<?php echo (int)$bt['id']; ?>">
                                                <input class="form-check-input" type="radio" name="business_type_id" 
                                                       id="business_type_<?php echo (int)$bt['id']; ?>" 
                                                       value="<?php echo (int)$bt['id']; ?>"
                                                       <?php echo ($currentBusinessTypeId === (int)$bt['id']) ? 'checked' : ''; ?> required>
                                                <span class="w-100 d-flex align-items-center justify-content-between gap-2">
                                                    <span class="d-flex align-items-center gap-2 fw-medium">
                                                        <?php if ($isCurtainShopType): ?>
                                                        <i class="bi bi-window text-primary fs-5"></i>
                                                        <?php else: ?>
                                                        <i class="bi bi-shop text-muted fs-5"></i>
                                                        <?php endif; ?>
                                                        <?php echo htmlspecialchars($bt['name_ku']); ?>
                                                    </span>
                                                    <?php if ($isCurtainShopType): ?>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">تایبەت</span>
                                                    <?php endif; ?>
                                                </span>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (empty($businessTypes)): ?>
                                    <p class="text-muted small mb-0">جۆری ئیشوکار بەردەست نییە. تکایە پەیوەندی بە بەڕێوەبەر بکە.</p>
                                    <?php endif; ?>
                                    <div class="invalid-feedback">
                                        تکایە جۆری ئیش و کار هەڵبژێرە
                                    </div>
                                </div>
                                
                                <?php if (!empty($businessTypes)): ?>
                                <div class="d-flex justify-content-end pt-3 border-top border-light-subtle">
                                    <button type="submit" class="btn btn-save">
                                        <i class="bi bi-check-lg"></i> نوێکردنەوەی جۆری ئیش
                                    </button>
                                </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Tab 2: دۆخی دیزاین -->
            <div class="tab-pane fade" id="appearance-pane" role="tabpanel" tabindex="0">
                <div class="row justify-content-center">
                    <div class="col-lg-10 col-xl-9">
                        <div class="settings-card card p-4 p-md-5">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-info">
                                    <i class="bi bi-palette-fill"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">دۆخی دیزاین و ڕووکار</h4>
                                    <p class="text-muted small mb-0">شێوازی بینینی سیستەم دیاری بکە کە گونجاو بێت بۆ چاوەکانت</p>
                                </div>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="action" value="update_theme_mode">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                                <div class="row g-4 mb-4">
                                    <!-- Light Mode -->
                                    <div class="col-md-4">
                                        <label class="theme-preview-card d-block h-100 <?php echo $themeMode === 'light' ? 'active' : ''; ?>">
                                            <input class="form-check-input position-absolute top-0 end-0 m-3" type="radio" name="theme_mode" value="light" <?php echo $themeMode === 'light' ? 'checked' : ''; ?>>
                                            <div class="theme-visual-box theme-box-light">
                                                <i class="bi bi-sun-fill"></i>
                                            </div>
                                            <h6 class="fw-bold mb-1">دۆخی ڕووناک</h6>
                                            <p class="text-muted small mb-2">دیزاینی سپی و ڕووناک بۆ کارکردنی ڕۆژ</p>
                                            <?php if ($themeMode === 'light'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">دۆخی ئێستا</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>

                                    <!-- Dark Mode -->
                                    <div class="col-md-4">
                                        <label class="theme-preview-card d-block h-100 <?php echo $themeMode === 'dark' ? 'active' : ''; ?>">
                                            <input class="form-check-input position-absolute top-0 end-0 m-3" type="radio" name="theme_mode" value="dark" <?php echo $themeMode === 'dark' ? 'checked' : ''; ?>>
                                            <div class="theme-visual-box theme-box-dark">
                                                <i class="bi bi-moon-stars-fill"></i>
                                            </div>
                                            <h6 class="fw-bold mb-1">دۆخی تاریک</h6>
                                            <p class="text-muted small mb-2">ڕەنگی تۆخ و ئارام بۆ کەمکردنەوەی ماندووبوونی چاو</p>
                                            <?php if ($themeMode === 'dark'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">دۆخی ئێستا</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>

                                    <!-- System Mode -->
                                    <div class="col-md-4">
                                        <label class="theme-preview-card d-block h-100 <?php echo $themeMode === 'system' ? 'active' : ''; ?>">
                                            <input class="form-check-input position-absolute top-0 end-0 m-3" type="radio" name="theme_mode" value="system" <?php echo $themeMode === 'system' ? 'checked' : ''; ?>>
                                            <div class="theme-visual-box theme-box-system">
                                                <i class="bi bi-display"></i>
                                            </div>
                                            <h6 class="fw-bold mb-1">دۆخی سیستەم</h6>
                                            <p class="text-muted small mb-2">بەپێی دۆخی ویندۆز/مۆبایلەکەت دەگۆڕێت</p>
                                            <?php if ($themeMode === 'system'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">دۆخی ئێستا</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end pt-3 border-top border-light-subtle">
                                    <button type="submit" class="btn btn-save">
                                        <i class="bi bi-check-lg"></i> پاشەکەوتکردنی دۆخ
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 3: ئاسایش و پاسۆرد -->
            <div class="tab-pane fade" id="security-pane" role="tabpanel" tabindex="0">
                <div class="row justify-content-center">
                    <div class="col-lg-8 col-xl-7">
                        <div class="settings-card card p-4 p-md-5">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-security">
                                    <i class="bi bi-shield-lock-fill"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">گۆڕینی پاسۆردی ئەکاونت</h4>
                                    <p class="text-muted small mb-0">تکایە وشەی نهێنی بەهێز بەکاربهێنە بۆ پاراستنی داتاکانت</p>
                                </div>
                            </div>
                            
                            <form method="POST" class="needs-validation" novalidate>
                                <input type="hidden" name="action" value="change_password">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="mb-3">
                                    <label for="current_password" class="form-label fw-semibold">
                                        پاسۆردی ئێستا
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-body-tertiary"><i class="bi bi-lock text-muted"></i></span>
                                        <input type="password" class="form-control" id="current_password" 
                                               name="current_password" placeholder="پاسۆردی ئێستات بنووسە..." required>
                                        <button class="btn btn-outline-secondary input-eye-toggle" type="button" onclick="togglePassVisibility('current_password', this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback">
                                        تکایە پاسۆردی ئێستا داخڵ بکە
                                    </div>
                                </div>
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label for="new_password" class="form-label fw-semibold">
                                            پاسۆردی نوێ
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-body-tertiary"><i class="bi bi-key text-muted"></i></span>
                                            <input type="password" class="form-control" id="new_password" 
                                                   name="new_password" minlength="6" placeholder="لانیکەم ٦ پیت" required>
                                            <button class="btn btn-outline-secondary input-eye-toggle" type="button" onclick="togglePassVisibility('new_password', this)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="invalid-feedback">
                                            پاسۆردی نوێ دەبێت لانیکەم ٦ پیت بێت
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="confirm_password" class="form-label fw-semibold">
                                            پشتڕاستکردنەوە
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-body-tertiary"><i class="bi bi-key-fill text-muted"></i></span>
                                            <input type="password" class="form-control" id="confirm_password" 
                                                   name="confirm_password" minlength="6" placeholder="دووبارە بنووسەوە" required>
                                            <button class="btn btn-outline-secondary input-eye-toggle" type="button" onclick="togglePassVisibility('confirm_password', this)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="invalid-feedback">
                                            تکایە پاسۆرد پشتڕاست بکەرەوە
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end pt-3 border-top border-light-subtle">
                                    <button type="submit" class="btn btn-danger-custom">
                                        <i class="bi bi-arrow-repeat"></i> نوێکردنەوەی پاسۆرد
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 4: وەسڵ و چاپکردن -->
            <div class="tab-pane fade" id="receipts-pane" role="tabpanel" tabindex="0">
                <div class="row g-4">
                    
                    <!-- ڕێکخستنی وەسڵی کاشێر -->
                    <div class="col-12 col-lg-6">
                        <div class="settings-card card p-4 h-100 d-flex flex-column">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-business">
                                    <i class="bi bi-receipt"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">ڕێکخستنی وەسڵی کاشێر (Thermal)</h4>
                                    <p class="text-muted small mb-0">دانانی وێنەی بانەر و لۆگۆ بۆ سەری وەسڵی کاشێر</p>
                                </div>
                            </div>
                            
                            <form id="receiptBannerForm" enctype="multipart/form-data" class="flex-grow-1 d-flex flex-column justify-content-between">
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">
                                        وێنەی بانەری وەسڵ
                                    </label>
                                    <div class="surface-soft text-center p-4">
                                        <input type="file" class="form-control mb-2" id="receiptBannerImage" accept="image/*">
                                        <div class="d-flex flex-column gap-1 text-muted small mt-2">
                                            <span><i class="bi bi-aspect-ratio me-1"></i> قەبارەی گونجاو: <strong>٤٠٠px پانی</strong> لەگەڵ <strong>١٠٠px بەرزی</strong></span>
                                            <span><i class="bi bi-info-circle me-1"></i> ئەگەر وێنە دانرێت، لە سەرووی وەسڵەکە لە جێگای ناوی فرۆشگا چاپ دەبێت.</span>
                                        </div>
                                        <div id="currentReceiptBannerPreview" class="mt-3" style="display: none;">
                                            <label class="form-label d-block text-muted small fw-semibold">وێنەی ئێستا:</label>
                                            <div class="position-relative d-inline-block">
                                                <img id="currentReceiptBannerImg" src="" alt="Banner" class="rounded-3 shadow-sm" style="max-width: 100%; max-height: 140px; object-fit: contain; background: var(--surface-1); padding: 6px; border: 1px solid var(--border-default);">
                                                <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 rounded-circle shadow" id="removeReceiptBannerBtn" title="سڕینەوەی بانەر">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div id="newReceiptBannerPreview" class="mt-3" style="display: none;">
                                            <label class="form-label d-block text-muted small fw-semibold">پێشبینینی نوێ:</label>
                                            <img id="newReceiptBannerImg" src="" alt="Preview" class="rounded-3 shadow-sm border" style="max-width: 100%; max-height: 140px; object-fit: contain; background: var(--surface-1); padding: 6px;">
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end pt-3 border-top border-light-subtle">
                                    <button type="button" class="btn btn-save" id="saveReceiptBannerBtn">
                                        <i class="bi bi-check-circle"></i> پاشەکەوتکردنی بانەر
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ڕێکخستنی وەسڵی A4 -->
                    <div class="col-12 col-lg-6">
                        <div class="settings-card card p-4 h-100 d-flex flex-column">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-business">
                                    <i class="bi bi-file-earmark-text"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">ڕێکخستنی وەسڵی گەورەی A4</h4>
                                    <p class="text-muted small mb-0">دانانی وێنەی سەردێڕ و تێبینی خوارەوە بۆ وەسڵە فەرمییەکان</p>
                                </div>
                            </div>
                            
                            <form id="a4SettingsForm" enctype="multipart/form-data" class="flex-grow-1 d-flex flex-column justify-content-between">
                                <div class="mb-4">
                                    <!-- Banner Image Section -->
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-image"></i> وێنەی بانەری سەرەوە
                                        </label>
                                        <div class="surface-soft text-center p-3">
                                            <input type="file" class="form-control mb-2" id="a4BannerImage" accept="image/*">
                                            <small class="text-muted d-block">
                                                <i class="bi bi-info-circle me-1"></i> وێنەیەکی پانی ئاسۆیی هەڵبژێرە بۆ سەرووی وەسڵی A4
                                            </small>
                                            <div id="currentBannerPreview" class="mt-3" style="display: none;">
                                                <label class="form-label d-block text-muted small fw-semibold">بانەری ئێستا:</label>
                                                <div class="position-relative d-inline-block">
                                                    <img id="currentBannerImg" src="" alt="Banner" class="rounded-3 shadow-sm" style="max-width: 100%; max-height: 120px; object-fit: contain; background: var(--surface-1); padding: 6px; border: 1px solid var(--border-default);">
                                                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 rounded-circle shadow" id="removeBannerBtn" title="سڕینەوە">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div id="newBannerPreview" class="mt-3" style="display: none;">
                                                <label class="form-label d-block text-muted small fw-semibold">پێشبینینی نوێ:</label>
                                                <img id="newBannerImg" src="" alt="Preview" class="rounded-3 shadow-sm border" style="max-width: 100%; max-height: 120px; object-fit: contain; background: var(--surface-1); padding: 6px;">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Notes Section -->
                                    <div>
                                        <label for="a4ReceiptNotes" class="form-label fw-semibold">
                                            <i class="bi bi-chat-left-text"></i> تێبینی خوارەوەی وەسڵ
                                        </label>
                                        <textarea class="form-control" id="a4ReceiptNotes" rows="3" placeholder="تێبینی، کاتی گەڕاندنەوە، ژمارەی مۆبایل، یان مەرجەکانی فرۆشتن بنووسە..."></textarea>
                                        <small class="text-muted mt-1 d-block">
                                            <i class="bi bi-info-circle me-1"></i> ئەم دەقە لە بنی وەسڵی A4 بە ڕوونی چاپ دەکرێت.
                                        </small>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end pt-3 border-top border-light-subtle">
                                    <button type="button" class="btn btn-save" id="saveA4SettingsBtn">
                                        <i class="bi bi-check-circle"></i> پاشەکەوتکردنی ڕێکخستنی A4
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

        </div>

    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Copy to clipboard helper
        function copyText(text) {
            if (!text || text === '—') return;
            navigator.clipboard.writeText(text).then(() => {
                showNotification('دەقەکە کۆپیکرا', 'success');
            }).catch(() => {});
        }

        // Toggle password visibility
        function togglePassVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) {
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                }
            } else {
                input.type = 'password';
                if (icon) {
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        }

        // Form validation
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();

        // Password confirmation validation
        const confirmPassInput = document.getElementById('confirm_password');
        if (confirmPassInput) {
            confirmPassInput.addEventListener('input', function() {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = this.value;
                
                if (newPassword !== confirmPassword) {
                    this.setCustomValidity('پاسۆردی نوێ و پشتڕاستکردنەوەی پاسۆرد جیاوازن');
                } else {
                    this.setCustomValidity('');
                }
            });
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Receipt Banner & Logo Management
        document.addEventListener('DOMContentLoaded', function() {
            loadReceiptBannerSettings();
            loadA4Settings();
            loadUserLogo();
            
            // Setup User Logo event listeners
            const logoImage = document.getElementById('logoImage');
            const removeLogoBtn = document.getElementById('removeLogoBtn');
            const saveLogoBtn = document.getElementById('saveLogoBtn');
            if (logoImage) {
                logoImage.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(ev) {
                            const newLogoPreview = document.getElementById('newLogoPreview');
                            const newLogoImg = document.getElementById('newLogoImg');
                            if (newLogoPreview && newLogoImg) {
                                newLogoPreview.style.display = 'block';
                                newLogoImg.src = ev.target.result;
                            }
                        };
                        reader.readAsDataURL(file);
                    } else {
                        const newLogoPreview = document.getElementById('newLogoPreview');
                        if (newLogoPreview) newLogoPreview.style.display = 'none';
                    }
                });
            }
            if (removeLogoBtn) {
                removeLogoBtn.addEventListener('click', function() {
                    if (confirm('دڵنیایت لە سڕینەوەی لۆگۆ؟')) {
                        fetch('<?php echo url("user/settings/logo/remove_user_logo.php"); ?>', { method: 'POST' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    const currentLogoPreview = document.getElementById('currentLogoPreview');
                                    const currentLogoImg = document.getElementById('currentLogoImg');
                                    if (currentLogoPreview) currentLogoPreview.style.display = 'none';
                                    if (currentLogoImg) currentLogoImg.src = '';
                                    showNotification('لۆگۆ سڕایەوە', 'success');
                                } else {
                                    showNotification(data.message || 'هەڵەیەک ڕوویدا', 'error');
                                }
                            })
                            .catch(function() { showNotification('هەڵەیەک ڕوویدا', 'error'); });
                    }
                });
            }
            if (saveLogoBtn) {
                saveLogoBtn.addEventListener('click', function() {
                    const logoFile = logoImage ? logoImage.files[0] : null;
                    if (!logoFile) {
                        showNotification('تکایە وێنەی لۆگۆ هەڵبژێرە', 'error');
                        return;
                    }
                    const formData = new FormData();
                    formData.append('logo_image', logoFile);
                    fetch('<?php echo url("user/settings/logo/save_user_logo.php"); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            showNotification('لۆگۆ بە سەرکەوتوویی پاشەکەوت کرا', 'success');
                            var newLogoPreview = document.getElementById('newLogoPreview');
                            if (newLogoPreview) newLogoPreview.style.display = 'none';
                            if (logoImage) logoImage.value = '';
                            loadUserLogo();
                        } else {
                            showNotification(data.message || 'هەڵەیەک ڕوویدا', 'error');
                        }
                    })
                    .catch(function() { showNotification('هەڵەیەک ڕوویدا', 'error'); });
                });
            }
            
            // Setup Receipt Banner event listeners
            const receiptBannerImage = document.getElementById('receiptBannerImage');
            const removeReceiptBannerBtn = document.getElementById('removeReceiptBannerBtn');
            const saveReceiptBannerBtn = document.getElementById('saveReceiptBannerBtn');
            
            if (receiptBannerImage) {
                receiptBannerImage.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const newReceiptBannerPreview = document.getElementById('newReceiptBannerPreview');
                            const newReceiptBannerImg = document.getElementById('newReceiptBannerImg');
                            if (newReceiptBannerPreview && newReceiptBannerImg) {
                                newReceiptBannerPreview.style.display = 'block';
                                newReceiptBannerImg.src = e.target.result;
                            }
                        };
                        reader.readAsDataURL(file);
                    } else {
                        const newReceiptBannerPreview = document.getElementById('newReceiptBannerPreview');
                        if (newReceiptBannerPreview) {
                            newReceiptBannerPreview.style.display = 'none';
                        }
                    }
                });
            }

            if (removeReceiptBannerBtn) {
                removeReceiptBannerBtn.addEventListener('click', function() {
                    if (confirm('دڵنیایت لە سڕینەوەی وێنەی بانەر؟')) {
                        fetch('<?php echo url("user/settings/remove_receipt_banner.php"); ?>', {
                            method: 'POST'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const currentReceiptBannerPreview = document.getElementById('currentReceiptBannerPreview');
                                const currentReceiptBannerImg = document.getElementById('currentReceiptBannerImg');
                                if (currentReceiptBannerPreview) currentReceiptBannerPreview.style.display = 'none';
                                if (currentReceiptBannerImg) currentReceiptBannerImg.src = '';
                                showNotification('وێنەی بانەر سڕایەوە', 'success');
                            } else {
                                showNotification(data.message || 'هەڵەیەک ڕوویدا', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error removing receipt banner:', error);
                            showNotification('هەڵەیەک ڕوویدا', 'error');
                        });
                    }
                });
            }

            if (saveReceiptBannerBtn) {
                saveReceiptBannerBtn.addEventListener('click', function() {
                    const formData = new FormData();
                    const bannerFile = receiptBannerImage ? receiptBannerImage.files[0] : null;
                    if (bannerFile) {
                        formData.append('banner_image', bannerFile);
                    }
                    
                    fetch('<?php echo url("user/settings/save_receipt_banner.php"); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('وێنەی بانەر پاشەکەوت کرا', 'success');
                            const newReceiptBannerPreview = document.getElementById('newReceiptBannerPreview');
                            if (newReceiptBannerPreview) newReceiptBannerPreview.style.display = 'none';
                            if (receiptBannerImage) receiptBannerImage.value = '';
                            loadReceiptBannerSettings();
                        } else {
                            showNotification(data.message || 'هەڵەیەک ڕوویدا', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error saving receipt banner:', error);
                        showNotification('هەڵەیەک ڕوویدا', 'error');
                    });
                });
            }
            
            // Setup A4 Settings event listeners
            const a4BannerImage = document.getElementById('a4BannerImage');
            const removeBannerBtn = document.getElementById('removeBannerBtn');
            const saveA4SettingsBtn = document.getElementById('saveA4SettingsBtn');
            
            if (a4BannerImage) {
                a4BannerImage.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const newBannerPreview = document.getElementById('newBannerPreview');
                            const newBannerImg = document.getElementById('newBannerImg');
                            if (newBannerPreview && newBannerImg) {
                                newBannerPreview.style.display = 'block';
                                newBannerImg.src = e.target.result;
                            }
                        };
                        reader.readAsDataURL(file);
                    } else {
                        const newBannerPreview = document.getElementById('newBannerPreview');
                        if (newBannerPreview) {
                            newBannerPreview.style.display = 'none';
                        }
                    }
                });
            }

            if (removeBannerBtn) {
                removeBannerBtn.addEventListener('click', function() {
                    if (confirm('دڵنیایت لە سڕینەوەی وێنەی بانەر؟')) {
                        fetch('<?php echo url("user/settings/remove_a4_banner.php"); ?>', {
                            method: 'POST'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const currentBannerPreview = document.getElementById('currentBannerPreview');
                                const currentBannerImg = document.getElementById('currentBannerImg');
                                if (currentBannerPreview) currentBannerPreview.style.display = 'none';
                                if (currentBannerImg) currentBannerImg.src = '';
                                showNotification('وێنەی بانەر سڕایەوە', 'success');
                            } else {
                                showNotification(data.message || 'هەڵەیەک ڕوویدا', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error removing banner:', error);
                            showNotification('هەڵەیەک ڕوویدا', 'error');
                        });
                    }
                });
            }

            if (saveA4SettingsBtn) {
                saveA4SettingsBtn.addEventListener('click', function() {
                    const formData = new FormData();
                    const bannerFile = a4BannerImage ? a4BannerImage.files[0] : null;
                    if (bannerFile) {
                        formData.append('banner_image', bannerFile);
                    }
                    
                    const a4ReceiptNotes = document.getElementById('a4ReceiptNotes');
                    if (a4ReceiptNotes) {
                        formData.append('notes', a4ReceiptNotes.value);
                    }
                    
                    fetch('<?php echo url("user/settings/save_a4_settings.php"); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('ڕێکخستنەکان پاشەکەوت کرا', 'success');
                            const newBannerPreview = document.getElementById('newBannerPreview');
                            if (newBannerPreview) newBannerPreview.style.display = 'none';
                            if (a4BannerImage) a4BannerImage.value = '';
                            loadA4Settings();
                        } else {
                            showNotification(data.message || 'هەڵەیەک ڕوویدا', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error saving A4 settings:', error);
                        showNotification('هەڵەیەک ڕوویدا', 'error');
                    });
                });
            }
        });

        function loadReceiptBannerSettings() {
            fetch('<?php echo url("user/settings/get_receipt_banner.php"); ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const currentReceiptBannerPreview = document.getElementById('currentReceiptBannerPreview');
                        const currentReceiptBannerImg = document.getElementById('currentReceiptBannerImg');
                        
                        if (data.data.receipt_banner && currentReceiptBannerPreview && currentReceiptBannerImg) {
                            currentReceiptBannerPreview.style.display = 'block';
                            currentReceiptBannerImg.src = data.data.receipt_banner;
                        } else if (currentReceiptBannerPreview) {
                            currentReceiptBannerPreview.style.display = 'none';
                        }
                    } else {
                        console.error('Error loading receipt banner settings:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading receipt banner settings:', error);
                });
        }

        function loadA4Settings() {
            fetch('<?php echo url("user/settings/get_a4_settings.php"); ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const currentBannerPreview = document.getElementById('currentBannerPreview');
                        const currentBannerImg = document.getElementById('currentBannerImg');
                        const a4ReceiptNotes = document.getElementById('a4ReceiptNotes');
                        
                        if (data.data.a4_receipt_banner && currentBannerPreview && currentBannerImg) {
                            currentBannerPreview.style.display = 'block';
                            currentBannerImg.src = data.data.a4_receipt_banner;
                        } else if (currentBannerPreview) {
                            currentBannerPreview.style.display = 'none';
                        }
                        
                        if (a4ReceiptNotes) {
                            a4ReceiptNotes.value = data.data.a4_receipt_notes || '';
                        }
                    } else {
                        showNotification('هەڵەیەک ڕوویدا لە بارکردنی ڕێکخستنەکان', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error loading A4 settings:', error);
                });
        }

        function loadUserLogo() {
            fetch('<?php echo url("user/settings/logo/get_user_logo.php"); ?>')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        var currentLogoPreview = document.getElementById('currentLogoPreview');
                        var currentLogoImg = document.getElementById('currentLogoImg');
                        if (data.data.logo_url && currentLogoPreview && currentLogoImg) {
                            currentLogoPreview.style.display = 'block';
                            currentLogoImg.src = data.data.logo_url;
                        } else if (currentLogoPreview) {
                            currentLogoPreview.style.display = 'none';
                        }
                    }
                })
                .catch(function() {});
        }

        function showNotification(message, type) {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const icon = type === 'success' ? '<i class="bi bi-check-circle-fill"></i>' : '<i class="bi bi-exclamation-triangle-fill"></i>';
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert ${alertClass} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                ${icon} ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container');
            container.insertBefore(alertDiv, container.firstChild);
            
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alertDiv);
                bsAlert.close();
            }, 5000);
        }
    </script>

</body>
</html>
