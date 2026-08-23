<?php
/**
 * فانکشنە یارمەتیدەرەکان - includes/functions.php
 */

/**
 * Resolve business image path to a valid DigitalOcean Spaces URL.
 */
function resolveBusinessImageUrl($imagePath)
{
    $imagePath = trim((string)$imagePath);
    if ($imagePath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $imagePath)) {
        return $imagePath;
    }

    $normalizedPath = ltrim($imagePath, '/');
    if (strpos($normalizedPath, 'business/') === 0) {
        $normalizedPath = 'img/' . $normalizedPath;
    }

    if (function_exists('spaces_public_url_for_object_key')) {
        $url = spaces_public_url_for_object_key($normalizedPath);
        return $url ?? '';
    }

    if (defined('SITE_URL')) {
        return rtrim(SITE_URL, '/') . '/assets/uploads/' . $normalizedPath;
    }

    return '';
}

/**
 * فانکشنەکانی ئامنیەت
 */

/**
 * پاککردنەوەی HTML و تاگەکان
 */

function sanitizeHtml($content) {
    $allowedTags = '<p><br><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6>';
    return strip_tags((string)($content ?? ''), $allowedTags);
}



/**
 * چیکردنی ئۆتۆریزەیشن - پێویستە لە سەرەتای هەر پەڕەیەک بەکار بهێنرێت
 */
function requireLogin() {
    if (!isLoggedIn()) {
        setMessage('پێویستە بچیتە ژوورەوە', 'warning');
        redirect(url('auth/login.php'));
    }
}








/**
 * پاککردنەوەی داتا بۆ ئاسایشی
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    $data = trim((string)($data ?? ''));
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * User Expiration Management Functions
 */

/**
 * Check if user account has expired
 */
function isUserExpired($userId, $isSubUser = false) {
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        return false;
    }
    
    try {
        if ($isSubUser) {
            $stmt = $conn->prepare("
                SELECT su.expiration_date, u.expiration_date as main_expiration_date 
                FROM sub_users su 
                JOIN users u ON su.main_user_id = u.id 
                WHERE su.id = ?
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result) {
                // Check sub-user expiration first, then main user expiration
                if ($result['expiration_date'] && strtotime($result['expiration_date']) < time()) {
                    return true;
                }
                if ($result['main_expiration_date'] && strtotime($result['main_expiration_date']) < time()) {
                    return true;
                }
            }
        } else {
            $stmt = $conn->prepare("SELECT expiration_date FROM users WHERE id = ?");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result && $result['expiration_date'] && strtotime($result['expiration_date']) < time()) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    
    return false;
}

/**
 * Extend user expiration by specified months
 */
function extendUserExpiration($userId, $months = 1, $isSubUser = false) {
    global $conn;
    $userId = (int)$userId;
    $months = (int)$months;
    if ($userId <= 0 || !$conn) {
        return false;
    }
    
    try {
        if ($isSubUser) {
            $stmt = $conn->prepare("
                UPDATE sub_users 
                SET expiration_date = DATE_ADD(COALESCE(expiration_date, NOW()), INTERVAL ? MONTH) 
                WHERE id = ?
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("ii", $months, $userId);
            $res = $stmt->execute();
            $stmt->close();
            return $res;
        } else {
            $stmt = $conn->prepare("
                UPDATE users 
                SET expiration_date = DATE_ADD(COALESCE(expiration_date, NOW()), INTERVAL ? MONTH) 
                WHERE id = ?
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("ii", $months, $userId);
            $res = $stmt->execute();
            $stmt->close();
            
            // Also update all sub-users of this main user
            if ($res) {
                $subStmt = $conn->prepare("
                    UPDATE sub_users 
                    SET expiration_date = DATE_ADD(COALESCE(expiration_date, NOW()), INTERVAL ? MONTH) 
                    WHERE main_user_id = ?
                ");
                if ($subStmt) {
                    $subStmt->bind_param("ii", $months, $userId);
                    $subStmt->execute();
                    $subStmt->close();
                }
            }
            return $res;
        }
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get user expiration date
 */
function getUserExpirationDate($userId, $isSubUser = false) {
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        return null;
    }
    
    try {
        if ($isSubUser) {
            $stmt = $conn->prepare("
                SELECT su.expiration_date, u.expiration_date as main_expiration_date 
                FROM sub_users su 
                JOIN users u ON su.main_user_id = u.id 
                WHERE su.id = ?
            ");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result) {
                // Return the earliest expiration date
                if ($result['expiration_date'] && $result['main_expiration_date']) {
                    return strtotime($result['expiration_date']) < strtotime($result['main_expiration_date']) 
                        ? $result['expiration_date'] 
                        : $result['main_expiration_date'];
                }
                return $result['expiration_date'] ?: $result['main_expiration_date'];
            }
        } else {
            $stmt = $conn->prepare("SELECT expiration_date FROM users WHERE id = ?");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            return $result ? $result['expiration_date'] : null;
        }
    } catch (Throwable $e) {
        return null;
    }
    
    return null;
}




/**
 * دروستکردنی token ی csrf بۆ ئاسایشی
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * چیکردنی token ی csrf
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * فۆرماتکردنی پارە
 */
function formatCurrency($amount, $currency = 'IQD') {
    return number_format((float)$amount, 0) . ' ' . $currency;
}

/**
 * چیکردنی ڕێگەپێدان - moved to includes/permissions.php
 * This function is now defined in includes/permissions.php with full functionality
 */

/**
 * لۆگکردنی چالاکی
 */
function logActivity($action, $description = null) {
    if (!isLoggedIn()) {
        return false;
    }
    
    global $conn;
    if (!$conn) {
        $db = new Database();
        $conn = $db->getConnection();
    }
    if (!$conn) {
        return false;
    }
    
    try {
        $userId = getCurrentUserId();
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->bind_param("issss", $userId, $action, $description, $ip_address, $user_agent);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Throwable $e) {
        return false;
    }
}



/**
 * بەراوردکردنی وەرژن
 */
function compareVersions($version1, $version2) {
    return version_compare($version1, $version2);
}

/**
 * کۆنتڕۆڵکردنی ڕێژەی داواکاری
 */
function checkRateLimit($key, $limit = 60, $window = 3600) {
    $file = sys_get_temp_dir() . '/rate_limit_' . md5($key);
    $now = time();
    
    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }
    
    // پاککردنەوەی داواکاری کۆنەکان
    $data = array_filter($data, function($time) use ($now, $window) {
        return ($now - $time) < $window;
    });
    
    if (count($data) >= $limit) {
        return false;
    }
    
    $data[] = $now;
    file_put_contents($file, json_encode($data));
    
    return true;
}

/**
 * تاقیکردنی IP ی بلۆککراو
 */
function isBlockedIP($ip) {
    // لیستی IP ی بلۆککراوەکان
    $blockedIPs = [
        // '192.168.1.100',
        // '10.0.0.50'
    ];
    
    return in_array($ip, $blockedIPs);
}

/**
 * وەرگرتنی IP ی بەکارهێنەر
 */
function getUserIP() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);
            
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * فانکشنەکانی فایل
 */

/**
 * دروستکردنی directory بە ئامنیەت
 */
function createSecureDirectory($path, $permissions = 0755) {
    if (!is_dir($path)) {
        if (!mkdir($path, $permissions, true)) {
            return false;
        }
        
        // دروستکردنی .htaccess فایل بۆ ئامنیەت
        $htaccessContent = "Order deny,allow\nDeny from all\n";
        if (strpos($path, 'uploads') !== false) {
            $htaccessContent = "Options -Indexes\n<Files ~ \"\\.php$\">\nOrder deny,allow\nDeny from all\n</Files>\n";
        }
        
        file_put_contents($path . '/.htaccess', $htaccessContent);
        
        // دروستکردنی index.php فایل
        file_put_contents($path . '/index.php', '<?php http_response_code(403); exit("Access denied"); ?>');
    }
    
    return true;
}

/**
 * سڕینەوەی فایل بە ئامنیەت
 */
function deleteFileSecurely($filePath) {
    if (!file_exists($filePath) || !is_file($filePath)) {
        return false;
    }
    
    // تاقیکردنی ئەوەی فایل لە ناو uploads directory دایە
    if (strpos(realpath($filePath), realpath(UPLOADS_PATH)) !== 0) {
        return false;
    }
    
    return unlink($filePath);
}

/**
 * وەرگرتنی قەبارەی فایل بە شێوەی خوێندراو
 */
function getReadableFileSize($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * فانکشنەکانی بەروار
 */

/**
 * فۆرماتکردنی بەروار بۆ کوردی
 */
function formatKurdishDate($date, $includeTime = false) {
    if (empty($date)) {
        return '';
    }
    try {
        if (is_string($date)) {
            $date = new DateTime($date, new DateTimeZone(DEFAULT_TIMEZONE));
        }
        if (!($date instanceof DateTimeInterface)) {
            return '';
        }
    } catch (Throwable $e) {
        return (string)$date;
    }
    
    $months = [
        1 => 'کانونی دووەم',
        2 => 'شوبات', 
        3 => 'ئازار',
        4 => 'نیسان',
        5 => 'ئایار',
        6 => 'حوزەیران',
        7 => 'تەمووز',
        8 => 'ئاب',
        9 => 'ئەیلوول',
        10 => 'تشرینی یەکەم',
        11 => 'تشرینی دووەم',
        12 => 'کانونی یەکەم'
    ];
    
    $day = $date->format('d');
    $month = $months[(int)$date->format('n')] ?? '';
    $year = $date->format('Y');
    
    $formattedDate = "$day $month $year";
    
    if ($includeTime) {
        $time = $date->format('H:i');
        $formattedDate .= " - کاتژمێر $time";
    }
    
    return $formattedDate;
}

/**
 * حیسابکردنی تەمەن
 */
function calculateAge($birthdate) {
    if (empty($birthdate)) {
        return 0;
    }
    try {
        $birth = new DateTime($birthdate, new DateTimeZone(DEFAULT_TIMEZONE));
        $today = new DateTime('now', new DateTimeZone(DEFAULT_TIMEZONE));
        return (int)$birth->diff($today)->y;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * فانکشنەکانی بازرگانی
 */

/**
 * حیسابکردنی قازانج
 */
function calculateProfit($sellPrice, $buyPrice) {
    $sellPrice = (float)$sellPrice;
    $buyPrice = (float)$buyPrice;
    if ($buyPrice <= 0) {
        return 0;
    }
    
    return $sellPrice - $buyPrice;
}

/**
 * حیسابکردنی ڕێژەی قازانج
 */
function calculateProfitPercentage($sellPrice, $buyPrice) {
    $sellPrice = (float)$sellPrice;
    $buyPrice = (float)$buyPrice;
    if ($buyPrice <= 0) {
        return 0;
    }
    
    return (($sellPrice - $buyPrice) / $buyPrice) * 100;
}

/**
 * حیسابکردنی داشکاندن
 */
function calculateDiscount($originalPrice, $discountedPrice) {
    $originalPrice = (float)$originalPrice;
    $discountedPrice = (float)$discountedPrice;
    if ($originalPrice <= 0) {
        return 0;
    }
    
    return (($originalPrice - $discountedPrice) / $originalPrice) * 100;
}

/**
 * حیسابکردنی باج
 */
function calculateTax($amount, $taxRate) {
    return ((float)$amount * (float)$taxRate) / 100;
}

/**
 * فانکشنەکانی ئامار
 */

/**
 * حیسابکردنی ناوەند
 */
function calculateAverage($numbers) {
    if (!is_array($numbers) || empty($numbers)) {
        return 0;
    }
    $filtered = array_filter($numbers, 'is_numeric');
    if (empty($filtered)) {
        return 0;
    }
    
    return array_sum($filtered) / count($filtered);
}

/**
 * دۆزینەوەی بەهای ناوەندی
 */
function findMedian($numbers) {
    if (!is_array($numbers) || empty($numbers)) {
        return 0;
    }
    $filtered = array_values(array_filter($numbers, 'is_numeric'));
    if (empty($filtered)) {
        return 0;
    }
    
    sort($filtered);
    $count = count($filtered);
    $middle = floor($count / 2);
    
    if ($count % 2) {
        return $filtered[$middle];
    } else {
        return ($filtered[$middle - 1] + $filtered[$middle]) / 2;
    }
}

/**
 * فانکشنەکانی کاڵا
 */

/**
 * تاقیکردنی ئەوەی کاڵا بەسەرچووە
 */
function isProductExpired($expiryDate) {
    if (!$expiryDate) {
        return false;
    }
    
    return strtotime($expiryDate) <= time();
}

/**
 * تاقیکردنی ئەوەی کاڵا نزیکە لە بەسەرچوون
 */
function isProductNearExpiry($expiryDate, $days = 30) {
    if (!$expiryDate) {
        return false;
    }
    
    $warningDate = strtotime("+$days days");
    return strtotime($expiryDate) <= $warningDate && strtotime($expiryDate) > time();
}

/**
 * وەرگرتنی دۆخی کاڵا
 */
function getProductStatus($stockQuantity, $minStock, $expiryDate = null) {
    // چەک کردنی بەسەرچوون
    if ($expiryDate && isProductExpired($expiryDate)) {
        return ['status' => 'expired', 'class' => 'danger', 'text' => 'بەسەرچووە'];
    }
    
    // چەک کردنی نزیک لە بەسەرچوون
    if ($expiryDate && isProductNearExpiry($expiryDate)) {
        return ['status' => 'near_expiry', 'class' => 'warning', 'text' => 'نزیکە لە بەسەرچوون'];
    }
    
    // چەک کردنی بەردەست
    if ($stockQuantity <= 0) {
        return ['status' => 'out_of_stock', 'class' => 'danger', 'text' => 'تەواو بووە'];
    }
    
    if ($stockQuantity <= $minStock) {
        return ['status' => 'low_stock', 'class' => 'warning', 'text' => 'کەمە'];
    }
    
    return ['status' => 'in_stock', 'class' => 'success', 'text' => 'بەردەستە'];
}

/**
 * فانکشنەکانی ڕاپۆرت
 */

/**
 * دروستکردنی ڕاپۆرتی CSV
 */
function generateCSVReport($data, $filename, $headers = []) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // زیادکردنی BOM بۆ UTF-8
    fputs($output, "\xEF\xBB\xBF");
    
    // نووسینی سەرەوەکان
    if (!empty($headers)) {
        fputcsv($output, $headers);
    }
    
    // نووسینی داتاکان
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

/**
 * دروستکردنی ڕاپۆرتی PDF (پێویستی بە کتێبخانەی PDF هەیە)
 */
function generatePDFReport($html, $filename) {
    // ئەم فانکشنە پێویستی بە کتێبخانەیەکی PDF هەیە وەک TCPDF یان mPDF
    // لێرە نموونەیەکی سادە دانراوە
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // ئێرە دەبێت کتێبخانەی PDF بەکار بهێنریت
    echo "PDF generation requires a PDF library like TCPDF or mPDF";
    exit();
}

/**
 * فانکشنەکانی ئیمەیڵ
 */

/**
 * ناردنی ئیمەیڵی سادە
 */
function sendSimpleEmail($to, $subject, $message, $from = null) {
    if (!$from) {
        $from = 'no-reply@' . $_SERVER['HTTP_HOST'];
    }
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: ' . $from,
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . phpversion()
    ];
    
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

/**
 * ناردنی ئیمەیڵی تەمپلەت
 */
function sendTemplateEmail($to, $template, $variables = [], $subject = '') {
    $templatePath = ROOT_PATH . '/templates/email/' . $template . '.php';
    
    if (!file_exists($templatePath)) {
        return false;
    }
    
    // Extract variables for template
    extract($variables);
    
    // Start output buffering
    ob_start();
    include $templatePath;
    $message = ob_get_clean();
    
    return sendSimpleEmail($to, $subject, $message);
}

/**
 * فانکشنەکانی سیستەم
 */

/**
 * وەرگرتنی زانیاری سیستەم
 */
function getSystemInfo() {
    return [
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'mysql_version' => 'Unknown', // دەبێت لە database.php وە بوەستێنرێت
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'timezone' => date_default_timezone_get(),
        'disk_free_space' => getReadableFileSize(disk_free_space('.')),
        'disk_total_space' => getReadableFileSize(disk_total_space('.'))
    ];
}

/**
 * پاککردنەوەی کاش
 */
function clearCache($cacheDir = null) {
    if (!$cacheDir) {
        $cacheDir = ROOT_PATH . '/cache';
    }
    
    if (!is_dir($cacheDir)) {
        return false;
    }
    
    $files = glob($cacheDir . '/*');
    $deletedCount = 0;
    
    foreach ($files as $file) {
        if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === 'cache') {
            if (unlink($file)) {
                $deletedCount++;
            }
        }
    }
    
    return $deletedCount;
}

/**
 * فانکشنەکانی backup
 */

/**
 * دروستکردنی backup ی داتابەیس
 */
function createDatabaseBackup($outputFile = null) {
    if (!$outputFile) {
        $outputFile = ROOT_PATH . '/backups/db_backup_' . date('Y-m-d_H-i-s') . '.sql';
    }
    
    $backupDir = dirname($outputFile);
    if (!is_dir($backupDir)) {
        createSecureDirectory($backupDir);
    }
    
    // ئەم فانکشنە پێویستی بە mysqldump هەیە یان دەبێت بە PHP نووسرێت
    $command = "mysqldump --user=" . DB_USERNAME . " --password=" . DB_PASSWORD . " --host=" . DB_HOST . " " . DB_NAME . " > " . $outputFile;
    
    $result = shell_exec($command);
    
    return file_exists($outputFile) ? $outputFile : false;
}

/**
 * فانکشنەکانی تاقیکردنەوە
 */

/**
 * تاقیکردنەوەی کێشە گشتیەکان
 */
function runSystemHealthCheck() {
    $issues = [];
    
    // تاقیکردنی پەیوەندی داتابەیس
    if (!testConnection()) {
        $issues[] = 'پەیوەندی داتابەیس کێشەی هەیە';
    }
    
    // تاقیکردنی directory ی uploads
    if (!is_writable(UPLOADS_PATH)) {
        $issues[] = 'Directory ی uploads نانووسرێت';
    }
    
    // تاقیکردنی PHP extensions
    $requiredExtensions = ['mysqli', 'json', 'mbstring', 'gd'];
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $issues[] = "PHP extension '$ext' دانەمەزراوە";
        }
    }
    
    // تاقیکردنی فایلە مهمەکان
    $requiredFiles = ['config/config.php', 'config/database.php', 'config/security.php'];
    foreach ($requiredFiles as $file) {
        if (!file_exists(ROOT_PATH . '/' . $file)) {
            $issues[] = "فایلی '$file' بوونی نییە";
        }
    }
    
    return [
        'status' => empty($issues) ? 'healthy' : 'issues_found',
        'issues' => $issues,
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * فانکشنەکانی کاش
 */

/**
 * کاشکردنی داتا
 */
function cacheData($key, $data, $expiration = 3600) {
    $cacheDir = ROOT_PATH . '/cache';
    if (!is_dir($cacheDir)) {
        createSecureDirectory($cacheDir);
    }
    
    $cacheFile = $cacheDir . '/' . md5($key) . '.cache';
    
    $cacheData = [
        'data' => $data,
        'expires' => time() + $expiration,
        'created' => time()
    ];
    
    return file_put_contents($cacheFile, serialize($cacheData)) !== false;
}

/**
 * وەرگرتنی داتا لە کاش
 */
function getCachedData($key) {
    $cacheDir = ROOT_PATH . '/cache';
    $cacheFile = $cacheDir . '/' . md5($key) . '.cache';
    
    if (!file_exists($cacheFile)) {
        return false;
    }
    
    $cacheData = unserialize(file_get_contents($cacheFile));
    
    // تاقیکردنی expiration
    if (time() > $cacheData['expires']) {
        unlink($cacheFile);
        return false;
    }
    
    return $cacheData['data'];
}

/**
 * سڕینەوەی کاشی تایبەت
 */
function deleteCachedData($key) {
    $cacheDir = ROOT_PATH . '/cache';
    $cacheFile = $cacheDir . '/' . md5($key) . '.cache';
    
    if (file_exists($cacheFile)) {
        return unlink($cacheFile);
    }
    
    return true;
}

/**
 * Currency Exchange Functions
 */

/**
 * وەرگرتنی ڕێژەی ئاڵوگۆڕکردنی دراوە
 * 
 * @param int $userId ID ی بەکارهێنەر
 * @param string $fromCurrency دراوەی سەرەکی (USD, IQD)
 * @param string $toCurrency دراوەی ئامانج (USD, IQD)
 * @return float|false ڕێژەی ئاڵوگۆڕکردن یان false ئەگەر نەدۆزرایەوە
 */
function getExchangeRate($userId, $fromCurrency = 'USD', $toCurrency = 'IQD') {
    global $conn;
    
    // ئەگەر هەمان دراوە بن، ڕێژە 1.0 دەگەڕێتەوە
    if ($fromCurrency === $toCurrency) {
        return 1.0;
    }
    
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        if ($fromCurrency === 'USD' && $toCurrency === 'IQD') {
            return 1400.0;
        }
        if ($fromCurrency === 'IQD' && $toCurrency === 'USD') {
            return 1.0 / 1400.0;
        }
        return false;
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT exchange_rate 
            FROM currency_exchange_rates 
            WHERE user_id = ? AND from_currency = ? AND to_currency = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("iss", $userId, $fromCurrency, $toCurrency);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return (float)$row['exchange_rate'];
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Fallback below
    }
    
    // ئەگەر ڕێژە نەدۆزرایەوە، بە default 1400 دەگەڕێتەوە (بۆ USD -> IQD)
    if ($fromCurrency === 'USD' && $toCurrency === 'IQD') {
        return 1400.0;
    }
    
    // بۆ گەڕاندنەوە (IQD -> USD)، بەکارهێنانی بەپێچەوانە
    if ($fromCurrency === 'IQD' && $toCurrency === 'USD') {
        $reverseRate = getExchangeRate($userId, 'USD', 'IQD');
        return ($reverseRate && $reverseRate > 0) ? (1.0 / $reverseRate) : (1.0 / 1400.0);
    }
    
    return false;
}

/**
 * گۆڕینی نرخ لە دراوەیەک بۆ دراوەیەکی تر
 * 
 * @param float $amount بڕی نرخ
 * @param string $fromCurrency دراوەی سەرەکی
 * @param string $toCurrency دراوەی ئامانج
 * @param int $userId ID ی بەکارهێنەر
 * @return float|false نرخی گۆڕدراو یان false ئەگەر نەتوانرا گۆڕدرێت
 */
function convertCurrency($amount, $fromCurrency, $toCurrency, $userId) {
    if ($fromCurrency === $toCurrency) {
        return (float)$amount;
    }
    
    $exchangeRate = getExchangeRate($userId, $fromCurrency, $toCurrency);
    
    if ($exchangeRate === false) {
        return false;
    }
    
    return (float)($amount * $exchangeRate);
}

/**
 * فۆرماتکردنی نرخ بە دراوەکەی (بۆ currency exchange)
 * 
 * @param float $amount بڕی نرخ
 * @param string $currency دراوە (USD, IQD)
 * @return string نرخی فۆرماتکراو
 */
function formatCurrencyAmount($amount, $currency = 'IQD') {
    $amount = (float)$amount;
    
    if ($currency === 'USD') {
        // بۆ دۆلار: تا ٢ دەقەم، بەڵام دەقەمی سفر لە کۆتایەوە بسڕەوە
        // 3.00 → 3  ، 3.40 → 3.4  ، 3.72 → 3.72
        $formattedAmount = number_format($amount, 2, '.', ',');
        $formattedAmount = rtrim(rtrim($formattedAmount, '0'), '.');
        return $formattedAmount . '$';
    }
    
    // بۆ دینار بە ڕێگەی خولەک (بێ پۆینت)
    $formattedAmount = number_format($amount, 0, '.', ',');
    return $formattedAmount . ' دینار';
}

/**
 * فۆرماتکردنی هەردوو دراو پێکەوە (دینار + دۆلار) بۆ کۆکراوەکان.
 * ئەگەر بڕی دۆلار سفر بێت تەنها دینار پیشان دەدرێت، و بەپێچەوانەوە.
 * ئەگەر هەردووکیان سفر بن، تەنها «0 دینار» دەگەڕێنێتەوە.
 *
 * @param float $iqd بڕی دینار
 * @param float $usd بڕی دۆلار
 * @param string $sep جیاکەرەوە لە نێوان هەردووکیان (بنەڕەت: « + »)
 * @return string
 */
function formatDualCurrency($iqd, $usd, $sep = ' + ') {
    $iqd = (float)$iqd;
    $usd = (float)$usd;

    $parts = [];
    if ($iqd != 0.0 || $usd == 0.0) {
        $parts[] = formatCurrencyAmount($iqd, 'IQD');
    }
    if ($usd != 0.0) {
        $parts[] = formatCurrencyAmount($usd, 'USD');
    }

    return implode($sep, $parts);
}

/**
 * دانانی ڕێژەی ئاڵوگۆڕکردنی دراوە
 * 
 * @param int $userId ID ی بەکارهێنەر
 * @param string $fromCurrency دراوەی سەرەکی
 * @param string $toCurrency دراوەی ئامانج
 * @param float $exchangeRate ڕێژەی ئاڵوگۆڕکردن
 * @param float|null $manualAdjustment زیادکراوەی دەستی (ئەگەر null بێت، حساب دەکرێت)
 * @return bool سەرکەوتوو یان نە
 */
function setExchangeRate($userId, $fromCurrency, $toCurrency, $exchangeRate, $manualAdjustment = null) {
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        return false;
    }
    
    // ئەگەر manualAdjustment نەدرابێت، حساب بکرێت لە جیاوازی لە نێوان exchange_rate و base_rate
    if ($manualAdjustment === null && $fromCurrency === 'USD' && $toCurrency === 'IQD') {
        $baseRate = getBaseExchangeRateFromDollarPrices();
        $manualAdjustment = (float)$exchangeRate - (float)$baseRate;
    } else if ($manualAdjustment === null) {
        $manualAdjustment = 0.0;
    }
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO currency_exchange_rates (user_id, from_currency, to_currency, exchange_rate, manual_adjustment)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                exchange_rate = VALUES(exchange_rate),
                manual_adjustment = VALUES(manual_adjustment),
                updated_at = CURRENT_TIMESTAMP
        ");
        if (!$stmt) {
            return false;
        }
        $exchangeRate = (float)$exchangeRate;
        $manualAdjustment = (float)$manualAdjustment;
        $stmt->bind_param("issdd", $userId, $fromCurrency, $toCurrency, $exchangeRate, $manualAdjustment);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * دانانی زیادکراوەی دەستی بەکارهێنەر
 * 
 * @param int $userId ID ی بەکارهێنەر
 * @param float $manualAdjustment زیادکراوەی دەستی
 * @return bool سەرکەوتوو یان نە
 */
function setManualAdjustment($userId, $manualAdjustment) {
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        return false;
    }
    
    // وەرگرتنی base_rate لە dollar_prices
    $baseRate = getBaseExchangeRateFromDollarPrices();
    $manualAdjustment = (float)$manualAdjustment;
    
    // حسابکردنی exchange_rate = base_rate + manual_adjustment
    $exchangeRate = $baseRate + $manualAdjustment;
    
    try {
        // نوێکردنەوەی currency_exchange_rates
        $stmt = $conn->prepare("
            INSERT INTO currency_exchange_rates (user_id, from_currency, to_currency, exchange_rate, manual_adjustment)
            VALUES (?, 'USD', 'IQD', ?, ?)
            ON DUPLICATE KEY UPDATE 
                exchange_rate = VALUES(exchange_rate),
                manual_adjustment = VALUES(manual_adjustment),
                updated_at = CURRENT_TIMESTAMP
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("idd", $userId, $exchangeRate, $manualAdjustment);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * وەرگرتنی نرخی بنەڕەتی لە dollar_prices (4 ژمارەی سەرەتای)
 * 
 * @return float نرخی بنەڕەتی (142000 → 1420)
 */
function getBaseExchangeRateFromDollarPrices() {
    global $conn;
    if (!$conn) {
        return 1400.0;
    }
    
    try {
        $result = $conn->query("SELECT offer_price FROM dollar_prices ORDER BY last_updated DESC LIMIT 1");
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $offerPrice = floatval($row['offer_price'] ?? 0);
            
            if ($offerPrice > 0) {
                // وەرگرتنی تەنها 4 ژمارەی سەرەتای
                $extracted = extractFirstFourDigits($offerPrice);
                if ($extracted > 0) {
                    return $extracted;
                }
            }
        }
    } catch (Throwable $e) {
        // هەڵە بێدەنگ بکە
    }
    
    return 1400.0; // default value
}

/**
 * وەرگرتنی تەنها 4 ژمارەی سەرەتای ژمارەکە
 * بۆ نمونە: 142000 → 1420
 */
function extractFirstFourDigits($number) {
    $numberStr = (string)intval($number);
    if (strlen($numberStr) <= 4) {
        return floatval($numberStr);
    }
    $firstFour = substr($numberStr, 0, 4);
    return floatval($firstFour);
}

/**
 * وەرگرتنی زیادکراوەی دەستی بەکارهێنەر
 * 
 * @param int $userId ID ی بەکارهێنەر
 * @return float زیادکراوەی دەستی
 */
function getManualAdjustment($userId) {
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        return 0.0;
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT manual_adjustment 
            FROM currency_exchange_rates 
            WHERE user_id = ? AND from_currency = 'USD' AND to_currency = 'IQD'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return floatval($row['manual_adjustment'] ?? 0);
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        return 0.0;
    }
    return 0.0;
}

/**
 * وەرگرتنی هەموو ڕێژەکانی ئاڵوگۆڕکردنی بەکارهێنەر
 * 
 * @param int $userId ID ی بەکارهێنەر
 * @return array لیستی ڕێژەکان
 */
function getUserExchangeRates($userId) {
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        return [];
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT from_currency, to_currency, exchange_rate, manual_adjustment, updated_at
            FROM currency_exchange_rates
            WHERE user_id = ?
            ORDER BY updated_at DESC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $rates = [];
        while ($row = $result->fetch_assoc()) {
            $rates[] = $row;
        }
        
        $stmt->close();
        return $rates;
    } catch (Throwable $e) {
        return [];
    }
}

?>