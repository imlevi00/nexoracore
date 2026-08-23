<?php
/**
 * تۆمارکردنی بەکارهێنەر - user/auth/register.php
 */

// دەستپێکردنی output buffering بۆ پاراستنی headers
ob_start();

// Helper function to parse size strings like "20M" to bytes
function parseSize($size) {
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\.]/', '', $size);
    if ($unit) {
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    } else {
        return round($size);
    }
}

// تاقیکردنی POST size error پێش session_start
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && $_SERVER['CONTENT_LENGTH'] > 0) {
    // POST data زۆر گەورەیە
    $maxPostSize = ini_get('post_max_size');
    $maxPostSizeBytes = parseSize($maxPostSize);
    $currentSize = $_SERVER['CONTENT_LENGTH'];
    
    if ($currentSize > $maxPostSizeBytes) {
        // پاککردنەوەی output buffer
        ob_clean();
        
        $error_message = "قەبارەی فایلەکان زۆر گەورەیە. سنوور: $maxPostSize";
        // ڕیدایرێکت بۆ پەڕەی هەڵە
        header("Location: " . $_SERVER['PHP_SELF'] . "?error=size_limit&message=" . urlencode($error_message));
        exit();
    }
}

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/unit_functions.php';

/**
 * بارکردنی وێنەی شوێنی کار بۆ DigitalOcean Spaces (spaces_put_object + پەردەختکردن)
 */
function uploadBusinessImageToSpaces(array $file): array {
    if (!function_exists('product_spaces_enabled') || !product_spaces_enabled()) {
        return ['success' => false, 'error' => 'ڕێکخستنی Spaces تەواو نییە'];
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        return ['success' => false, 'error' => 'جۆری فایل پەسەند نییە'];
    }

    $payload = spaces_optimized_image_upload_payload($file['tmp_name'], $file['name'] ?? '');
    if ($payload['body'] === false) {
        return ['success' => false, 'error' => 'نەتوانرا فایل بخوێنرێتەوە'];
    }

    $objectKey = 'img/business/temp_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    try {
        spaces_put_object($objectKey, $payload['body'], $payload['mime']);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strlen($msg) > 200) {
            $msg = substr($msg, 0, 200) . '…';
        }
        return ['success' => false, 'error' => 'هەڵە لە بارکردنی وێنە بۆ DigitalOcean: ' . $msg];
    }

    $cdnUrl = spaces_public_url_for_object_key($objectKey);
    if ($cdnUrl === null) {
        return ['success' => false, 'error' => 'ڕێکخستنی URL ی Spaces تەواو نییە'];
    }
    return ['success' => true, 'url' => $cdnUrl];
}

// Keep referral code stable for this browser (session + cookie).
$refCookieName = 'ref_agent_code';
$refCookieTtl = 60 * 60 * 24 * 30; // 30 days
$isSecureRequest = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

$saveRefCookie = static function (string $code) use ($refCookieName, $refCookieTtl, $isSecureRequest): void {
    setcookie($refCookieName, $code, [
        'expires' => time() + $refCookieTtl,
        'path' => '/',
        'secure' => $isSecureRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
};

$clearRefCookie = static function () use ($refCookieName, $isSecureRequest): void {
    setcookie($refCookieName, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isSecureRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
};

$validateAgentRef = static function (mysqli $db, string $rawCode): ?string {
    $normalized = strtoupper(trim($rawCode));
    $normalized = preg_replace('/[^A-Z0-9_-]/', '', $normalized);
    if ($normalized === '') {
        return null;
    }

    $stmt = $db->prepare("SELECT id FROM agents WHERE agent_code = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $normalized);
    $stmt->execute();
    $result = $stmt->get_result();
    $isValid = ($result && $result->num_rows > 0);
    $stmt->close();

    return $isValid ? $normalized : null;
};

if (isset($_GET['ref'])) {
    // Explicit ref from URL has highest priority.
    $validRef = $validateAgentRef($conn, (string)$_GET['ref']);
    if ($validRef !== null) {
        $_SESSION['ref_agent_code'] = $validRef;
        $saveRefCookie($validRef);
    } else {
        unset($_SESSION['ref_agent_code']);
        $clearRefCookie();
    }
} elseif (empty($_SESSION['ref_agent_code']) && !empty($_COOKIE[$refCookieName])) {
    // Restore ref when user leaves page/site and comes back with same browser.
    $cookieRef = $validateAgentRef($conn, (string)$_COOKIE[$refCookieName]);
    if ($cookieRef !== null) {
        $_SESSION['ref_agent_code'] = $cookieRef;
        $saveRefCookie($cookieRef);
    } else {
        unset($_SESSION['ref_agent_code']);
        $clearRefCookie();
    }
}

// ئەگەر user logged in بێت، redirect بکە
if (isUser()) {
    redirect(url('user/dashboard/index.php'));
}

$errors = [];
$success = false;

// تاقیکردنی هەڵەی size limit
if (isset($_GET['error']) && $_GET['error'] === 'size_limit') {
    $errors[] = $_GET['message'] ?? 'قەبارەی فایلەکان زۆر گەورەیە';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        // Get form data
        $business_name = cleanInput($_POST['business_name'] ?? '');
        $email = cleanInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $phone = cleanInput($_POST['phone'] ?? '');
        $address = cleanInput($_POST['address'] ?? '');
        $telegram_sent = isset($_POST['telegram_sent']) ? 1 : 0;
        
        // Validation
        if (empty($business_name)) {
            $errors[] = 'ناوی شوێنی کار پێویستە';
        }
        
        if (empty($email) || !isValidEmail($email)) {
            $errors[] = 'ئیمەیڵێکی دروست داخڵ بکە';
        }
        
        if (empty($password)) {
            $errors[] = 'پاسۆرد پێویستە';
        } else {
            $passwordErrors = Security::validatePasswordStrength($password);
            if (!empty($passwordErrors)) {
                $errors = array_merge($errors, $passwordErrors);
            }
        }
        
        if ($password !== $confirm_password) {
            $errors[] = 'پاسۆردەکان یەک ناگرنەوە';
        }
        
        if (empty($phone) || !isValidPhone($phone)) {
            $errors[] = 'ژمارەی مۆبایلێکی دروست داخڵ بکە';
        }
        
        if (empty($address)) {
            $errors[] = 'ناونیشانی شوێنی کار پێویستە';
        }
        
      /*  if (!$telegram_sent) {
            $errors[] = 'پێویستە پەیام بنێریت بۆ تەلەگرامەکە';
        }
        */
        
        // Check if email already exists
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'ئەم ئیمەیڵە پێشتر بەکارهاتووە';
            }
            $stmt->close();
        }
        
        // Validate business images (without uploading yet)
        if (empty($errors) && isset($_FILES['business_images'])) {
            $imageCount = count($_FILES['business_images']['name']);

            if ($imageCount < MIN_BUSINESS_IMAGES) {
                $errors[] = 'لانی کەم ' . MIN_BUSINESS_IMAGES . ' وێنە پێویستە';
            } elseif ($imageCount > MAX_BUSINESS_IMAGES) {
                $errors[] = 'زیاتر لە ' . MAX_BUSINESS_IMAGES . ' وێنە ڕێگەپێدراو نییە';
            } else {
                // Validate each image without uploading
                for ($i = 0; $i < $imageCount; $i++) {
                    if ($_FILES['business_images']['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $_FILES['business_images']['name'][$i],
                            'type' => $_FILES['business_images']['type'][$i],
                            'tmp_name' => $_FILES['business_images']['tmp_name'][$i],
                            'error' => $_FILES['business_images']['error'][$i],
                            'size' => $_FILES['business_images']['size'][$i]
                        ];

                        // تاقیکردنی جۆری فایل
                        if (!SecureFileUpload::validateFileType($file['name'])) {
                            $errors[] = "جۆری فایل ڕێپێدراو نییە: " . $file['name'];
                        }

                        // تاقیکردنی قەبارەی فایل
                        if (!SecureFileUpload::validateFileSize($file['size'])) {
                            $errors[] = "قەبارەی فایل زۆر گەورەیە: " . $file['name'];
                        }
                    }
                }
            }
        } elseif (empty($errors)) {
            $errors[] = 'وێنەکانی شوێنی کار پێویستە';
        }
        
        // Insert user if no errors
        if (empty($errors)) {
            // Upload images first (now that all validations passed)
            $uploadedImages = [];
            if (isset($_FILES['business_images'])) {
                $imageCount = count($_FILES['business_images']['name']);

                for ($i = 0; $i < $imageCount; $i++) {
                    if ($_FILES['business_images']['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $_FILES['business_images']['name'][$i],
                            'type' => $_FILES['business_images']['type'][$i],
                            'tmp_name' => $_FILES['business_images']['tmp_name'][$i],
                            'error' => $_FILES['business_images']['error'][$i],
                            'size' => $_FILES['business_images']['size'][$i]
                        ];

                        $uploadResult = uploadBusinessImageToSpaces($file);
                        if ($uploadResult['success']) {
                            $uploadedImages[] = $uploadResult['url'];
                        } else {
                            $errors[] = $uploadResult['error'] ?? 'هەڵە لە بارکردنی وێنە';
                            break; // Stop uploading if error occurs
                        }
                    }
                }
            }

            // Only proceed if image upload was successful
            if (empty($errors)) {
                $hashedPassword = Security::hashPassword($password);

                $stmt = $conn->prepare("
                    INSERT INTO users (business_name, email, password, phone, address, telegram_sent, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");

                $stmt->bind_param("sssssi", $business_name, $email, $hashedPassword, $phone, $address, $telegram_sent);

                if ($stmt->execute()) {
                    $userId = $conn->insert_id;

                    // Bind newly registered user with referral agent, if any.
                    if (!empty($_SESSION['ref_agent_code'])) {
                        $sessionRef = $_SESSION['ref_agent_code'];
                        $agentStmt = $conn->prepare("SELECT id, agent_code FROM agents WHERE agent_code = ? AND is_active = 1 LIMIT 1");
                        $agentStmt->bind_param("s", $sessionRef);
                        $agentStmt->execute();
                        $agentResult = $agentStmt->get_result();

                        if ($agentResult && $agentResult->num_rows > 0) {
                            $agentData = $agentResult->fetch_assoc();
                            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

                            $linkStmt = $conn->prepare("
                                INSERT INTO agent_registrations
                                (agent_id, registered_user_id, agent_code_snapshot, source_ip, created_at)
                                VALUES (?, ?, ?, ?, NOW())
                            ");
                            $linkStmt->bind_param(
                                "iiss",
                                $agentData['id'],
                                $userId,
                                $agentData['agent_code'],
                                $ipAddress
                            );

                            if (!$linkStmt->execute()) {
                                writeLog("Agent referral bind failed for user_id={$userId}, ref={$sessionRef}", 'WARNING');
                            }
                            $linkStmt->close();
                        }
                        $agentStmt->close();
                    }

                    // Insert business images
                    if (!empty($uploadedImages)) {
                        $imgStmt = $conn->prepare("INSERT INTO business_images (user_id, image_path) VALUES (?, ?)");
                        foreach ($uploadedImages as $imagePath) {
                            $imgStmt->bind_param("is", $userId, $imagePath);
                            $imgStmt->execute();
                        }
                        $imgStmt->close();
                    }

                    // Create default unit for new user
                    ensureDefaultUnit($userId);

                    // Log registration
                    writeLog("User registered: $email from IP: " . $_SERVER['REMOTE_ADDR']);

                    $success = true;
                } else {
                    $errors[] = 'هەڵەیەک ڕوویدا لە تۆمارکردن. دووبارە هەوڵ بدەرەوە';
                }

                $stmt->close();
            }
        }
    }
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();
?>

<?php require __DIR__ . '/register_view.inc.php';
__halt_compiler();
    
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?php echo url(); ?>">
                <i class="bi bi-shop"></i>
                <?php echo SITE_NAME; ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="<?php echo url('user/auth/login.php'); ?>">
                    <i class="bi bi-box-arrow-in-right"></i> داخڵبوون
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-xl-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white text-center">
                        <h3 class="mb-0">
                            <i class="bi bi-person-plus"></i>
                            دروستکردنی ئەکاونتی نوێ
                        </h3>
                        <p class="mb-0 mt-2">زانیارییەکانی شوێنی کارت بنووسە</p>
                    </div>
                    
                    <div class="card-body p-4">
                        
                        <?php if ($success): ?>
                            <!-- Success Message -->
                            <div class="alert alert-success alert-permanent text-center border-0 shadow-sm">
                                <i class="bi bi-check-circle-fill" style="font-size: 3rem; color: #198754;"></i>
                                <h4 class="mt-3 mb-3 fw-bold">بەسەرکەوتوویی تۆمار بوویت! 🎉</h4>
                                <p class="mb-4 text-dark" style="font-size: 1.1rem;">
                                    داواکارییەکەت بەسەرکەوتوویی نێردراوە  </p>
                                
                                <hr class="my-4">
                                
                                <!-- Quick Contact Info -->
                                <div class="bg-light rounded p-4 my-4">
                                    <h5 class="text-primary mb-3">
                                        <i class="bi bi-lightning-charge-fill"></i>
                                        بۆ وەرگرتنی ئەکاونت پێوستە پەیوەندی بکەیت
                                    </h5>
                                    <p class="mb-3 text-dark">
                                        ئەگەر دەتەوێت ئەکاونتەکەت وەربگیرێت،<br>
                                        پێوستە لە ڕێگەی ئەم شێوازانەوە پەیوەندیمان پێوە بکەیت:
                                    </p>
                                    
                                    <!-- Telegram -->
                                    <div class="mb-3">
                                        <a href="https://t.me/itz_levi0" target="_blank" 
                                           class="btn btn-info btn-lg w-100 d-flex align-items-center justify-content-center gap-2">
                                            <i class="bi bi-telegram" style="font-size: 1.5rem;"></i>
                                            <span>پەیامبنێرە لە تێلێگرام</span>
                                        </a>
                                        <small class="text-muted d-block mt-2">@Amir_Kurdish_1</small>
                                    </div>
                                    
                                    <!-- Phone Numbers -->
                                    <div class="mt-4">
                                        <h6 class="text-dark mb-3">
                                            <i class="bi bi-telephone-fill text-success"></i>
                                            ژمارەی تەلەفۆن بۆ پەیوەندیکردن
                                        </h6>
                                        <div class="d-grid gap-2">
                                            <a href="tel:07705406115" class="btn btn-outline-success btn-lg" dir="ltr">
                                                <i class="bi bi-phone"></i> 0770 540 6115
                                            </a>
                                            <a href="tel:07501837582" class="btn btn-outline-success btn-lg" dir="ltr">
                                                <i class="bi bi-phone"></i> 0750 183 7582
                                            </a>
                                        </div>
                                     
                                    </div>
                                </div>
                                
                                <hr class="my-4">
                                
                                <a href="<?php echo url('user/auth/login.php'); ?>" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right"></i> بڕۆ بۆ پەڕەی داخڵبوون
                                </a>
                            </div>
                            
                        <?php else: ?>
                            
                            <!-- Error Messages -->
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <strong>هەڵەکان:</strong>
                                    <ul class="mb-0 mt-2">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo $error; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    
                                    <?php if (isset($_GET['error']) && $_GET['error'] === 'size_limit'): ?>
                                        <div class="mt-3 p-3 bg-light rounded">
                                            <h6 class="text-primary mb-2">
                                                <i class="bi bi-lightbulb"></i> چارەسەر:
                                            </h6>
                                            <ul class="mb-0 small">
                                                <li>قەبارەی وێنەکان کەم بکەرەوە (کەمتر لە 8MB بۆ هەر وێنەیەک)</li>
                                                <li>ژمارەی وێنەکان کەم بکەرەوە (3-8 وێنە)</li>
                                                <li>وێنەکان بە فۆرماتی JPG یان PNG هەڵبژێرە (کەمتر قەبارە)</li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Registration Form -->
                            <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <!-- Business Name -->
                                <div class="mb-3">
                                    <label for="business_name" class="form-label">
                                        <i class="bi bi-shop"></i> ناوی شوێنی کار *
                                    </label>
                                    <input type="text" class="form-control" id="business_name" name="business_name" 
                                           value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>" 
                                           required>
                                    <div class="invalid-feedback">تکایە ناوی شوێنی کار داخڵ بکە</div>
                                </div>

                                <!-- Email -->
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        <i class="bi bi-envelope"></i> ئیمەیڵ *
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                           required>
                                    <div class="invalid-feedback">تکایە ئیمەیڵێکی دروست داخڵ بکە</div>
                                </div>

                                <!-- Phone -->
                                <div class="mb-3">
                                    <label for="phone" class="form-label">
                                        <i class="bi bi-telephone"></i> ژمارەی مۆبایل *
                                    </label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
                                           placeholder="07XX XXX XXXX" required>
                                    <div class="invalid-feedback">تکایە ژمارەی مۆبایل داخڵ بکە</div>
                                </div>

                                <!-- Address -->
                                <div class="mb-3">
                                    <label for="address" class="form-label">
                                        <i class="bi bi-geo-alt"></i> ناونیشانی شوێنی کار *
                                    </label>
                                    <textarea class="form-control" id="address" name="address" rows="3" 
                                              required><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                    <div class="invalid-feedback">تکایە ناونیشان داخڵ بکە</div>
                                </div>

                                <!-- Password -->
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">
                                            <i class="bi bi-lock"></i> پاسۆرد *
                                        </label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="password" name="password" required>
                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            لانی کەم ۸ پیت
                                        </div>
                                        <div class="invalid-feedback">تکایە پاسۆردێکی بەهێز داخڵ بکە</div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">
                                            <i class="bi bi-lock-fill"></i> دووپاتکردنەوەی پاسۆرد *
                                        </label>
                                        <input type="password" class="form-control" id="confirm_password" 
                                               name="confirm_password" required>
                                        <div class="invalid-feedback">پاسۆردەکان یەک ناگرنەوە</div>
                                    </div>
                                </div>

                                <!-- Business Images - Modern Design -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold d-flex align-items-center gap-2">
                                        <i class="bi bi-images text-primary"></i>
                                        <span>وێنەکانی شوێنی کار</span>
                                        <span class="badge bg-danger">پێویست</span>
                                    </label>

                                    <div class="upload-info-box mb-3">
                                        <div class="d-flex align-items-center gap-2 text-muted small">
                                            <i class="bi bi-info-circle-fill text-info"></i>
                                            <span>لانی کەم <strong><?php echo MIN_BUSINESS_IMAGES; ?></strong> وێنە و کەمتر لە <strong><?php echo MAX_BUSINESS_IMAGES; ?></strong> وێنە</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 text-muted small mt-1">
                                            <i class="bi bi-file-earmark-image text-success"></i>
                                            <span>فۆرماتی پەسەندکراو: JPG, PNG, GIF, WEBP</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 text-muted small mt-1">
                                            <i class="bi bi-hdd text-warning"></i>
                                            <span>قەبارەی هەر وێنەیەک: زیاتر لە 8MB نەبێت</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 text-muted small mt-1">
                                            <i class="bi bi-info-circle text-info"></i>
                                            <span>کۆی گشتی وێنەکان: حەدئکثر 20MB پێشنیار دەکرێت</span>
                                        </div>
                                    </div>

                                    <!-- Upload Area -->
                                    <div class="modern-upload-area" id="fileUploadArea">
                                        <input type="file" name="business_images[]" id="business_images"
                                               multiple accept="image/*" class="d-none" required>

                                        <div class="upload-content" id="uploadContent">
                                            <div class="upload-icon-wrapper">
                                                <i class="bi bi-cloud-arrow-up upload-icon"></i>
                                            </div>
                                            <h5 class="upload-title mt-3 mb-2">وێنەکانی شوێنی کارت هەڵبژێرە</h5>
                                            <p class="upload-subtitle mb-3">کلیک بکە یان وێنەکان ڕابکێشە بۆ ئێرە</p>
                                            <button type="button" class="btn btn-primary btn-browse">
                                                <i class="bi bi-folder2-open"></i>
                                                هەڵبژاردنی وێنەکان
                                            </button>
                                        </div>
                                    </div>

                                    <!-- File Counter & Status -->
                                    <div class="upload-status mt-3" id="uploadStatus" style="display: none;">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="file-count-badge" id="fileCountBadge">
                                                <i class="bi bi-images"></i>
                                                <span id="fileCount">0</span> وێنە هەڵبژێردراوە
                                            </span>
                                            <button type="button" class="btn btn-sm btn-outline-danger" id="clearFiles">
                                                <i class="bi bi-trash"></i> سڕینەوە
                                            </button>
                                        </div>
                                        <div class="mt-2" id="sizeInfo" style="display: none;">
                                            <small class="text-muted">
                                                <i class="bi bi-hdd"></i> کۆی قەبارە: <span id="totalSize">0MB</span> / 20MB
                                            </small>
                                            <div class="progress mt-1" style="height: 6px;">
                                                <div class="progress-bar" id="sizeProgress" role="progressbar" style="width: 0%"></div>
                                            </div>
                                        </div>
                                        <div class="progress mt-2" style="height: 4px; display: none;" id="uploadProgress">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated"
                                                 role="progressbar" style="width: 0%"></div>
                                        </div>
                                    </div>

                                    <!-- Preview Gallery -->
                                    <div class="preview-gallery mt-3" id="filePreview"></div>
                                </div>

                                <!-- Telegram Verification -->
                               <!-- <div class="mb-4">
                                    <div class="alert alert-info">
                                        <h6 class="alert-heading">
                                            <i class="bi bi-telegram"></i> پشتڕاستکردنەوەی تەلەگرام
                                        </h6>
                                        <p class="mb-2">
                                            بۆ دڵنیابوونەوە لە ڕاستگۆیی داواکارییەکەت، تکایە پەیامێک بنێرە بۆ:
                                        </p>
                                        <p class="mb-3">
                                            <a href="<?php echo TELEGRAM_VERIFICATION_URL; ?>" 
                                               target="_blank" class="btn btn-info btn-sm">
                                                <i class="bi bi-telegram"></i>
                                                @<?php echo TELEGRAM_BOT_USERNAME; ?>
                                            </a>
                                        </p>
                                        
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="telegram_sent" 
                                                   name="telegram_sent" <?php echo isset($_POST['telegram_sent']) ? 'checked' : ''; ?> required>
                                            <label class="form-check-label" for="telegram_sent">
                                                پەیامم ناردووە بۆ تەلەگرامەکە
                                            </label>
                                            <div class="invalid-feedback">
                                                پێویستە پەیام بنێریت بۆ تەلەگرامەکە
                                            </div>
                                        </div>
                                    </div>
                                </div>
-->

                 
                                 <!-- Terms and Conditions Link -->
                                         <div class="text-center mb-3">
                                    <a href="https://nexoracore.com/terms_and_conditions.html" target="_blank" class="text-primary text-decoration-none small">
                                        <i class="bi bi-file-text"></i> خوێندنەوەی یاسا و مەرجەکانی سیستەم
                                    </a>
                                </div>

                                <!-- Submit Button -->
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-person-plus"></i> 
                                        داوای دروستکردنی ئەکاونت
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                    </div>
                    
                    <div class="card-footer text-center bg-light">
                        <p class="mb-0">
                            ئەکاونتت هەیە؟ 
                            <a href="<?php echo url('user/auth/login.php'); ?>" class="text-primary">
                                داخڵبوون
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo asset('js/main.js'); ?>"></script>
    
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

        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (password.type === 'password') {
                password.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                password.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('پاسۆردەکان یەک ناگرنەوە');
            } else {
                this.setCustomValidity('');
            }
        });

        // Modern File Upload Handling
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('business_images');
        const filePreview = document.getElementById('filePreview');
        const fileCountElement = document.getElementById('fileCount');
        const uploadStatus = document.getElementById('uploadStatus');
        const uploadContent = document.getElementById('uploadContent');
        const clearFilesBtn = document.getElementById('clearFiles');
        const btnBrowse = document.querySelector('.btn-browse');

        const minImages = <?php echo MIN_BUSINESS_IMAGES; ?>;
        const maxImages = <?php echo MAX_BUSINESS_IMAGES; ?>;
        const maxFileSize = 8 * 1024 * 1024; // 8MB

        let selectedFiles = [];

        // Browse button click
        btnBrowse.addEventListener('click', function(e) {
            e.stopPropagation();
            fileInput.click();
        });

        // Upload area click
        fileUploadArea.addEventListener('click', function(e) {
            if (e.target === this || e.target === uploadContent) {
                fileInput.click();
            }
        });

        // File selection
        fileInput.addEventListener('change', function() {
            handleFiles(Array.from(this.files));
        });

        // Drag and drop events
        fileUploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragover');
        });

        fileUploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        fileUploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');

            const files = Array.from(e.dataTransfer.files).filter(file => file.type.startsWith('image/'));
            if (files.length > 0) {
                handleFiles(files);
            }
        });

        // Clear all files
        clearFilesBtn.addEventListener('click', function() {
            clearAllFiles();
        });

        function handleFiles(files) {
            // Filter only image files
            const imageFiles = files.filter(file => file.type.startsWith('image/'));

            if (imageFiles.length === 0) {
                showNotification('تکایە تەنها فایلی وێنە هەڵبژێرە', 'warning');
                return;
            }

            // Check file count
            if (imageFiles.length > maxImages) {
                showNotification(`زیاتر لە ${maxImages} وێنە ڕێگەپێدراو نییە`, 'danger');
                return;
            }

            // Compress and resize images
            compressImages(imageFiles);
        }

        function compressImages(files) {
            const compressedFiles = [];
            let processedCount = 0;

            files.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const canvas = document.createElement('canvas');
                        const ctx = canvas.getContext('2d');
                        
                        // Calculate new dimensions (max 1920x1080)
                        let { width, height } = calculateDimensions(img.width, img.height, 1920, 1080);
                        
                        canvas.width = width;
                        canvas.height = height;
                        
                        // Draw resized image
                        ctx.drawImage(img, 0, 0, width, height);
                        
                        // Convert to blob with quality 0.8
                        canvas.toBlob(function(blob) {
                            // Create new file with compressed data
                            const compressedFile = new File([blob], file.name, {
                                type: 'image/jpeg',
                                lastModified: Date.now()
                            });
                            
                            compressedFiles.push(compressedFile);
                            processedCount++;
                            
                            if (processedCount === files.length) {
                                // All images processed
                                validateCompressedFiles(compressedFiles, files);
                            }
                        }, 'image/jpeg', 0.8);
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            });
        }

        function calculateDimensions(originalWidth, originalHeight, maxWidth, maxHeight) {
            let width = originalWidth;
            let height = originalHeight;
            
            // Calculate aspect ratio
            const aspectRatio = originalWidth / originalHeight;
            
            // Resize if too large
            if (width > maxWidth) {
                width = maxWidth;
                height = width / aspectRatio;
            }
            
            if (height > maxHeight) {
                height = maxHeight;
                width = height * aspectRatio;
            }
            
            return { width: Math.round(width), height: Math.round(height) };
        }

        function validateCompressedFiles(compressedFiles, originalFiles) {
            // Validate file sizes after compression
            const invalidFiles = compressedFiles.filter(file => file.size > maxFileSize);
            if (invalidFiles.length > 0) {
                showNotification('دوای کەمکردنەوەش هەندێک فایل زۆر گەورەن', 'warning');
            }

            // Check total size
            const totalSize = compressedFiles.reduce((sum, file) => sum + file.size, 0);
            const maxTotalSize = 20 * 1024 * 1024; // 20MB after compression
            
            if (totalSize > maxTotalSize) {
                showNotification('دوای کەمکردنەوەش کۆی قەبارە زۆر گەورەیە', 'warning');
            }

            selectedFiles = compressedFiles;
            updateFileInput();
            displayPreviews();
            
            // Compression completed silently
        }

        function updateFileInput() {
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
        }

        function displayPreviews() {
            if (selectedFiles.length === 0) {
                uploadContent.style.display = 'block';
                uploadStatus.style.display = 'none';
                filePreview.innerHTML = '';
                return;
            }

            // Hide upload content, show status
            uploadContent.style.display = 'none';
            uploadStatus.style.display = 'block';

            // Update count
            fileCountElement.textContent = selectedFiles.length;

            // Update file count badge color
            const badge = document.getElementById('fileCountBadge');
            if (selectedFiles.length < minImages) {
                badge.style.background = 'linear-gradient(135deg, #dc3545 0%, #c82333 100%)';
            } else {
                badge.style.background = 'linear-gradient(135deg, #0d6efd 0%, #6f42c1 100%)';
            }

            // Update size information
            updateSizeInfo();

            // Clear and create previews
            filePreview.innerHTML = '';

            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();

                reader.onload = function(e) {
                    const previewItem = createPreviewItem(file, e.target.result, index);
                    filePreview.appendChild(previewItem);
                };

                reader.readAsDataURL(file);
            });
        }

        function updateSizeInfo() {
            const totalSize = selectedFiles.reduce((sum, file) => sum + file.size, 0);
            const totalSizeMB = (totalSize / 1024 / 1024).toFixed(1);
            const maxSizeMB = 20;
            const percentage = Math.min((totalSize / (maxSizeMB * 1024 * 1024)) * 100, 100);
            
            document.getElementById('totalSize').textContent = totalSizeMB + 'MB';
            document.getElementById('sizeProgress').style.width = percentage + '%';
            
            // Show size info if files are selected
            const sizeInfo = document.getElementById('sizeInfo');
            if (selectedFiles.length > 0) {
                sizeInfo.style.display = 'block';
                
                // Change progress bar color based on size
                const progressBar = document.getElementById('sizeProgress');
                if (percentage > 90) {
                    progressBar.className = 'progress-bar bg-danger';
                } else if (percentage > 70) {
                    progressBar.className = 'progress-bar bg-warning';
                } else {
                    progressBar.className = 'progress-bar bg-success';
                }
            } else {
                sizeInfo.style.display = 'none';
            }
        }

        function createPreviewItem(file, dataUrl, index) {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.dataset.index = index;

            const fileSize = formatFileSize(file.size);
            const fileName = file.name.length > 20 ? file.name.substring(0, 17) + '...' : file.name;

            div.innerHTML = `
                <div class="preview-badge">${index + 1}</div>
                <div class="btn-remove-image" onclick="removeImage(${index})">
                    <i class="bi bi-x-lg"></i>
                </div>
                <div class="preview-image-wrapper">
                    <img src="${dataUrl}" alt="${file.name}" class="preview-image">
                </div>
                <div class="preview-info">
                    <p class="preview-filename" title="${file.name}">${fileName}</p>
                    <p class="preview-size">${fileSize}</p>
                </div>
            `;

            return div;
        }

        window.removeImage = function(index) {
            selectedFiles.splice(index, 1);
            updateFileInput();
            displayPreviews();

            if (selectedFiles.length === 0) {
                showNotification('هەموو وێنەکان سڕدرانەوە', 'info');
            }
        };

        function clearAllFiles() {
            if (selectedFiles.length === 0) return;

            if (confirm('دڵنیایت لە سڕینەوەی هەموو وێنەکان؟')) {
                selectedFiles = [];
                updateFileInput();
                displayPreviews();
                showNotification('هەموو وێنەکان سڕدرانەوە', 'success');
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        function showNotification(message, type) {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            notification.style.cssText = 'top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
            notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            document.body.appendChild(notification);

            // Auto remove after 3 seconds
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Form validation for file count and total size
        document.querySelector('form').addEventListener('submit', function(e) {
            if (selectedFiles.length < minImages) {
                e.preventDefault();
                showNotification(`لانی کەم ${minImages} وێنە پێویستە`, 'danger');
                fileUploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            if (selectedFiles.length > maxImages) {
                e.preventDefault();
                showNotification(`زیاتر لە ${maxImages} وێنە ڕێگەپێدراو نییە`, 'danger');
                fileUploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            // تاقیکردنی کۆی گشتی قەبارەی فایلەکان
            const totalSize = selectedFiles.reduce((sum, file) => sum + file.size, 0);
            const maxTotalSize = 20 * 1024 * 1024; // 20MB
            
            if (totalSize > maxTotalSize) {
                e.preventDefault();
                const totalSizeMB = (totalSize / 1024 / 1024).toFixed(1);
                showNotification(`کۆی قەبارەی وێنەکان زۆر گەورەیە (${totalSizeMB}MB). حەدئکثر 20MB پێشنیار دەکرێت`, 'danger');
                fileUploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
        });
    </script>
</body>
</html>