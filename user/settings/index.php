<?php
/**
 * ڕێکخستنەکان - user/settings/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../config/kasher_zanyari/database.php';
require_once '../../includes/permissions.php';

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
    <title>ڕێکخستنەکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    <link href="settings.css" rel="stylesheet">
</head>
<body class="settings-module-page settings-detail-page settings-page">
    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

<div class="container py-4 hub-page-content">
        
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-gear text-primary"></i>
                            ڕێکخستنەکان
                        </h2>
                        <p class="text-muted mb-0">بەڕێوەبردنی زانیاریەکانی ئەکاونتەکەت</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            
            <!-- زانیاری ئەکاونت -->
            <div class="col-12">
                <div class="settings-card card p-4">
                    <div class="text-center mb-4">
                        <div class="settings-icon section-info mx-auto">
                            <i class="bi bi-info-circle"></i>
                        </div>
                        <h4 class="mb-3">زانیاری ئەکاونت</h4>
                        <p class="text-muted">زانیاری گشتی ئەکاونتەکەت</p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-envelope"></i> ئیمەیڵ
                                </div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($userData['email']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-building"></i> ناوی فرۆشگا
                                </div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($userData['business_name']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-calendar-plus"></i> بەرواری دروستکردن
                                </div>
                                <div class="info-value">
                                    <?php echo date('Y/m/d - H:i', strtotime($userData['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-shield-check"></i> دۆخی ئەکاونت
                                </div>
                                <div class="info-value">
                                    <span class="badge bg-success">چالاک</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- زانیاری فرۆشگا -->
            <div class="col-lg-6">
                <div class="settings-card card p-4">
                    <div class="text-center mb-4">
                        <div class="settings-icon section-business mx-auto">
                            <i class="bi bi-shop"></i>
                        </div>
                        <h4 class="mb-3">زانیاری فرۆشگا</h4>
                        <p class="text-muted">ناوی فرۆشگاکەت بگۆڕە</p>
                    </div>
                    
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="update_business_name">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label for="business_name" class="form-label">
                                <i class="bi bi-building"></i> ناوی فرۆشگا
                            </label>
                            <input type="text" class="form-control form-control-lg" id="business_name" 
                                   name="business_name" value="<?php echo htmlspecialchars($userData['business_name']); ?>" 
                                   required>
                            <div class="invalid-feedback">
                                تکایە ناوی فرۆشگا داخڵ بکە
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button type="submit" class="btn btn-save">
                                <i class="bi bi-check-lg"></i> نوێکردنەوە
                            </button>
                        </div>
                        
                    
                    </form>
                </div>
            </div>

            <!-- گۆڕینی پاسۆرد -->
            <div class="col-lg-6">
                <div class="settings-card card p-4">
                    <div class="text-center mb-4">
                        <div class="settings-icon section-security mx-auto">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <h4 class="mb-3">گۆڕینی پاسۆرد</h4>
                        <p class="text-muted">پاسۆردی ئەکاونتەکەت بگۆڕە</p>
                    </div>
                    
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label for="current_password" class="form-label">
                                <i class="bi bi-lock"></i> پاسۆردی ئێستا
                            </label>
                            <input type="password" class="form-control" id="current_password" 
                                   name="current_password" required>
                            <div class="invalid-feedback">
                                تکایە پاسۆردی ئێستا داخڵ بکە
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">
                                <i class="bi bi-key"></i> پاسۆردی نوێ
                            </label>
                            <input type="password" class="form-control" id="new_password" 
                                   name="new_password" minlength="6" required>
                            <div class="invalid-feedback">
                                پاسۆردی نوێ دەبێت لانیکەم ٦ پیت بێت
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">
                                <i class="bi bi-key-fill"></i> پشتڕاستکردنەوەی پاسۆرد
                            </label>
                            <input type="password" class="form-control" id="confirm_password" 
                                   name="confirm_password" minlength="6" required>
                            <div class="invalid-feedback">
                                تکایە پاسۆرد پشتڕاست بکەرەوە
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button type="submit" class="btn btn-danger-custom">
                                <i class="bi bi-arrow-repeat"></i> گۆڕینی پاسۆرد
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- جۆری ئیش و کار -->
            <div class="col-lg-6">
                <div class="settings-card card p-4">
                    <div class="text-center mb-4">
                        <div class="settings-icon section-business mx-auto">
                            <i class="bi bi-briefcase"></i>
                        </div>
                        <h4 class="mb-3">جۆری ئیش و کار</h4>
                        <p class="text-muted">جۆری ئیشوکاری فرۆشگاکەت دیاری بکە</p>
                    </div>
                    
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="update_business_type">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-briefcase"></i> جۆری ئیش و کار
                            </label>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($businessTypes as $bt): ?>
                                <?php $isCurtainShopType = (trim((string)($bt['code'] ?? '')) === 'curtain_shop'); ?>
                                <div class="form-check border rounded p-3<?php echo $isCurtainShopType ? ' border-primary' : ''; ?>">
                                    <input class="form-check-input" type="radio" name="business_type_id" 
                                           id="business_type_<?php echo (int)$bt['id']; ?>" 
                                           value="<?php echo (int)$bt['id']; ?>"
                                           <?php echo ($currentBusinessTypeId === (int)$bt['id']) ? 'checked' : ''; ?> required>
                                    <label class="form-check-label w-100 d-flex align-items-center justify-content-between gap-2" for="business_type_<?php echo (int)$bt['id']; ?>">
                                        <span>
                                            <?php if ($isCurtainShopType): ?>
                                            <i class="bi bi-window me-1"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($bt['name_ku']); ?>
                                        </span>
                                        <?php if ($isCurtainShopType): ?>
                                        <span class="badge bg-primary">تایبەت</span>
                                        <?php endif; ?>
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
                        <div class="text-center">
                            <button type="submit" class="btn btn-save">
                                <i class="bi bi-check-lg"></i> نوێکردنەوە
                            </button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- دۆخی دیزاین -->
            <div class="col-lg-6">
                <div class="settings-card card p-4">
                    <div class="text-center mb-4">
                        <div class="settings-icon section-info mx-auto">
                            <i class="bi bi-circle-half"></i>
                        </div>
                        <h4 class="mb-3">دۆخی دیزاین</h4>
                        <p class="text-muted">ڕووناک، تاریک یان سیستەم هەڵبژێرە</p>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_theme_mode">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold d-block">هەڵبژاردنی دۆخ</label>
                            <div class="d-flex flex-column gap-2">
                                <label class="form-check border rounded p-3 theme-choice">
                                    <input class="form-check-input" type="radio" name="theme_mode" value="light" <?php echo $themeMode === 'light' ? 'checked' : ''; ?>>
                                    <span class="form-check-label ms-2">
                                        <i class="bi bi-sun"></i> دۆخی ڕووناک
                                    </span>
                                </label>
                                <label class="form-check border rounded p-3 theme-choice">
                                    <input class="form-check-input" type="radio" name="theme_mode" value="dark" <?php echo $themeMode === 'dark' ? 'checked' : ''; ?>>
                                    <span class="form-check-label ms-2">
                                        <i class="bi bi-moon-stars"></i> دۆخی تاریک
                                    </span>
                                </label>
                                <label class="form-check border rounded p-3 theme-choice">
                                    <input class="form-check-input" type="radio" name="theme_mode" value="system" <?php echo $themeMode === 'system' ? 'checked' : ''; ?>>
                                    <span class="form-check-label ms-2">
                                        <i class="bi bi-laptop"></i> دۆخی سیستەم (ئۆتۆماتیک)
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="text-center">
                            <button type="submit" class="btn btn-save">
                                <i class="bi bi-check-lg"></i> نوێکردنەوە
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- لۆگۆی فرۆشگا -->
            <div class="col-lg-6">
                <div class="settings-card card p-4">
                    <div class="text-center mb-4">
                        <div class="settings-icon section-business mx-auto">
                            <i class="bi bi-image-fill"></i>
                        </div>
                        <h4 class="mb-3">لۆگۆی فرۆشگا</h4>
                        <p class="text-muted">وێنەی لۆگۆی فرۆشگاکەت دابنێ</p>
                    </div>
                    
                    <form id="userLogoForm" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="form-label fw-bold">
                                <i class="bi bi-image"></i> وێنەی لۆگۆ
                            </label>
                            <div class="border rounded p-3 surface-soft">
                                <input type="file" class="form-control mb-2" id="logoImage" name="logo_image" accept="image/*">
                                <small class="text-muted d-block mb-2">
                                    <i class="bi bi-info-circle"></i> تەنها JPG, PNG, GIF, WEBP (کەمتر لە ٥ مێگابایت)
                                </small>
                                <div id="currentLogoPreview" class="mt-3" style="display: none;">
                                    <label class="form-label">لۆگۆی ئێستا:</label>
                                    <div class="position-relative d-inline-block">
                                        <img id="currentLogoImg" src="" alt="Logo" style="max-width: 100%; max-height: 120px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                        <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-2" id="removeLogoBtn" style="opacity: 0.9;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="newLogoPreview" class="mt-3" style="display: none;">
                                    <label class="form-label">پێشبینینی لۆگۆی نوێ:</label>
                                    <img id="newLogoImg" src="" alt="Preview" style="max-width: 100%; max-height: 120px; border-radius: 8px;">
                                </div>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="button" class="btn btn-save" id="saveLogoBtn">
                                <i class="bi bi-check-circle"></i> پاشەکەوتکردن
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ڕێکخستنی وەسڵی کاشێر -->
            <div class="col-12">
                <div class="settings-card card p-4">
                    <div class="text-center mb-4">
                        <div class="settings-icon section-business mx-auto">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <h4 class="mb-3">ڕێکخستنی وەسڵی کاشێر</h4>
                        <p class="text-muted">ڕێکخستنی وێنەی بانەر بۆ وەسڵی کاشێر</p>
                    </div>
                    
                    <form id="receiptBannerForm" enctype="multipart/form-data">
                        <!-- Banner Image Section -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">
                                <i class="bi bi-image"></i> وێنەی بانەر
                            </label>
                            <div class="border rounded p-3 surface-soft">
                                <input type="file" class="form-control mb-2" id="receiptBannerImage" accept="image/*">
                                <small class="text-muted d-block mb-2">
                                    <i class="bi bi-info-circle"></i> وێنەی بانەر دەبێت <strong>٤٠٠ پێکسڵ پانی</strong> و <strong>١٠٠ پێکسڵ بەرزی</strong> بێت
                                </small>
                                <small class="text-muted d-block">
                                    <i class="bi bi-info-circle"></i> ئەگەر وێنەی بانەر دانرابێت، لە جێگای ناوی فرۆشگا لە وەسڵەکاندا دەردەکەوێت
                                </small>
                                <div id="currentReceiptBannerPreview" class="mt-3" style="display: none;">
                                    <label class="form-label">وێنەی ئێستا:</label>
                                    <div class="position-relative d-inline-block">
                                        <img id="currentReceiptBannerImg" src="" alt="Banner" style="max-width: 100%; max-height: 150px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                        <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-2" id="removeReceiptBannerBtn" style="opacity: 0.9;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="newReceiptBannerPreview" class="mt-3" style="display: none;">
                                    <label class="form-label">پێشبینینی وێنەی نوێ:</label>
                                    <img id="newReceiptBannerImg" src="" alt="Preview" style="max-width: 100%; max-height: 150px; border-radius: 8px;">
                                </div>
                            </div>
                        </div>

                        <div class="text-center">
                            <button type="button" class="btn btn-save" id="saveReceiptBannerBtn">
                                <i class="bi bi-check-circle"></i> پاشەکەوتکردن
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ڕێکخستنی وەسڵی A4 -->
            <div class="col-12">
                <div class="settings-card card p-4">
                    <div class="text-center mb-4">
                        <div class="settings-icon section-business mx-auto">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                        <h4 class="mb-3">ڕێکخستنی وەسڵی A4</h4>
                        <p class="text-muted">ڕێکخستنی وێنەی بانەر و تێبینی بۆ وەسڵەکانی A4</p>
                    </div>
                    
                    <form id="a4SettingsForm" enctype="multipart/form-data">
                        <!-- Banner Image Section -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">
                                <i class="bi bi-image"></i> وێنەی بانەری سەرەوە
                            </label>
                            <div class="border rounded p-3 surface-soft">
                                <input type="file" class="form-control mb-2" id="a4BannerImage" accept="image/*">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i> وێنەیەکی پان بەرز بهێڵە بۆ باشترین دیمەن
                                </small>
                                <div id="currentBannerPreview" class="mt-3" style="display: none;">
                                    <label class="form-label">وێنەی ئێستا:</label>
                                    <div class="position-relative d-inline-block">
                                        <img id="currentBannerImg" src="" alt="Banner" style="max-width: 100%; max-height: 200px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                        <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-2" id="removeBannerBtn" style="opacity: 0.9;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="newBannerPreview" class="mt-3" style="display: none;">
                                    <label class="form-label">پێشبینینی وێنەی نوێ:</label>
                                    <img id="newBannerImg" src="" alt="Preview" style="max-width: 100%; max-height: 200px; border-radius: 8px;">
                                </div>
                            </div>
                        </div>

                        <!-- Notes Section -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">
                                <i class="bi bi-chat-left-text"></i> تێبینی خوارەوەی وەسڵ
                            </label>
                            <textarea class="form-control" id="a4ReceiptNotes" rows="4" placeholder="تێبینی خۆت بنووسە کە لە خوارەوەی هەموو وەسڵەکانی A4 دەردەکەوێت..."></textarea>
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i> ئەم تێبینیە لە خوارەوەی هەموو وەسڵێکی A4 دەردەکەوێت
                            </small>
                        </div>

                        <div class="text-center">
                            <button type="button" class="btn btn-save" id="saveA4SettingsBtn">
                                <i class="bi bi-check-circle"></i> پاشەکەوتکردن
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
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
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('پاسۆردی نوێ و پشتڕاستکردنەوەی پاسۆرد جیاوازن');
            } else {
                this.setCustomValidity('');
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Receipt Banner Functions
        // Load receipt banner settings on page load
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
            
            // Handle receipt banner image preview
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

            // Handle receipt banner removal
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

            // Save Receipt Banner
            if (saveReceiptBannerBtn) {
                saveReceiptBannerBtn.addEventListener('click', function() {
                    const formData = new FormData();
                    
                    // Add banner image if selected
                    const bannerFile = receiptBannerImage ? receiptBannerImage.files[0] : null;
                    if (bannerFile) {
                        formData.append('banner_image', bannerFile);
                    }
                    
                    // Save settings
                    fetch('<?php echo url("user/settings/save_receipt_banner.php"); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('وێنەی بانەر پاشەکەوت کرا', 'success');
                            // Reset preview
                            const newReceiptBannerPreview = document.getElementById('newReceiptBannerPreview');
                            if (newReceiptBannerPreview) newReceiptBannerPreview.style.display = 'none';
                            if (receiptBannerImage) receiptBannerImage.value = '';
                            // Reload settings to show updated banner
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
            
            // Handle banner image preview
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

            // Handle banner removal
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

            // Save A4 Settings
            if (saveA4SettingsBtn) {
                saveA4SettingsBtn.addEventListener('click', function() {
                    const formData = new FormData();
                    
                    // Add banner image if selected
                    const bannerFile = a4BannerImage ? a4BannerImage.files[0] : null;
                    if (bannerFile) {
                        formData.append('banner_image', bannerFile);
                    }
                    
                    // Add notes
                    const a4ReceiptNotes = document.getElementById('a4ReceiptNotes');
                    if (a4ReceiptNotes) {
                        formData.append('notes', a4ReceiptNotes.value);
                    }
                    
                    // Save settings
                    fetch('<?php echo url("user/settings/save_a4_settings.php"); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('ڕێکخستنەکان پاشەکەوت کرا', 'success');
                            // Reset preview
                            const newBannerPreview = document.getElementById('newBannerPreview');
                            if (newBannerPreview) newBannerPreview.style.display = 'none';
                            if (a4BannerImage) a4BannerImage.value = '';
                            // Reload settings to show updated banner
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
                        // Load banner image if exists
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
                        // Load banner image if exists
                        const currentBannerPreview = document.getElementById('currentBannerPreview');
                        const currentBannerImg = document.getElementById('currentBannerImg');
                        const a4ReceiptNotes = document.getElementById('a4ReceiptNotes');
                        
                        if (data.data.a4_receipt_banner && currentBannerPreview && currentBannerImg) {
                            currentBannerPreview.style.display = 'block';
                            currentBannerImg.src = data.data.a4_receipt_banner;
                        } else if (currentBannerPreview) {
                            currentBannerPreview.style.display = 'none';
                        }
                        
                        // Load notes
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

        // Notification function
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
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alertDiv);
                bsAlert.close();
            }, 5000);
        }
    </script>

</body>
</html>
