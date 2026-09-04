<?php
/**
 * ڕێکخستنە گشتیەکانی سیستەم
 * config/config.php
 */

// Session دەستپێدەکرێت لە config/security.php (SessionManager::startSecureSession)
// بۆ ئەوەی cookie/ini ی ئامن جێبەجێ ببێت پێش session_start

// دیاریکردنی ژینگە (لۆکاڵ/سێرڤەر) — SITE_URL و کرێدێنشاڵەکان لێرەوە دێن
require_once __DIR__ . '/env.php';

// ڕێکخستنەکانی سایت
define('SITE_NAME', 'NexoraCore');
if (!defined('SITE_URL')) define('SITE_URL', 'http://169.58.215.11/Ka.sheryAi/');
define('SITE_VERSION', '1.0.8'); // بۆ cache busting

// ڕێگاکانی فایلەکان
define('ROOT_PATH', dirname(dirname(__FILE__)));
define('ASSETS_PATH', rtrim(SITE_URL, '/') . '/assets');
define('UPLOADS_PATH', ROOT_PATH . '/assets/uploads');
define('UPLOADS_URL', rtrim(SITE_URL, '/') . '/assets/uploads');
define('CACHE_PATH', ROOT_PATH . '/cache');

// ڕێکخستنەکانی ئەپلۆد
define('MAX_FILE_SIZE', 8 * 1024 * 1024); // 8MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('MAX_BUSINESS_IMAGES', 8);
define('MIN_BUSINESS_IMAGES', 3);

// ڕێکخستنەکانی ئامنیەت
define('PASSWORD_MIN_LENGTH', 8);
// ماوەی کوکی سێشن (چرکە): دەتوانێت درێژتر بێت بۆ continuity
define('SESSION_LIFETIME', 7 * 24 * 60 * 60);
// idle timeout ـی سێشن: 24 کاتژمێر بێچالاکی
define('SESSION_TIMEOUT', 24 * 60 * 60);
// کووکی نهێنی: تەنها دوای چوونەژوورەوە بە پاسۆرد نوێ دەکرێتەوە — Remember Me ڕۆژێک جار ڕێگە دەدات ئەگەر ئەمڕۆ پاسۆرد دانابێت
define('AUTH_DAY_COOKIE_NAME', 'kasher_pw_auth_day');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 خولەک بە چرکە

// ڕێکخستنەکانی تەلەگرام
define('TELEGRAM_BOT_USERNAME', 'Amir_Kurdish_1');
define('TELEGRAM_VERIFICATION_URL', 'https://t.me/itz_levi0');

// ڕێکخستنەکانی کار
define('DEFAULT_CURRENCY', 'دینار');
define('DEFAULT_LANGUAGE', 'ku');
define('DEFAULT_TIMEZONE', 'Asia/Baghdad');

// ڕێکخستنەکانی وەسڵ
define('RECEIPT_PAPER_WIDTH', '80mm');
define('RECEIPT_MARGIN', '5mm');

// دانانی timezone
date_default_timezone_set(DEFAULT_TIMEZONE);

// دڵنیابوونەوە لە timezone ی دروست
if (date_default_timezone_get() !== DEFAULT_TIMEZONE) {
    ini_set('date.timezone', DEFAULT_TIMEZONE);
    date_default_timezone_set(DEFAULT_TIMEZONE);
}

// کلاسی ڕێکخستنەکان
class Config {
    
    private static $settings = [
        'app' => [
            'name' => SITE_NAME,
            'url' => SITE_URL,
            'version' => SITE_VERSION,
            'debug' => false
        ],
        'database' => [
            'host' => DB_HOST,
            'name' => DB_NAME,
            'charset' => 'utf8mb4'
        ],
        'paths' => [
            'root' => ROOT_PATH,
            'assets' => ASSETS_PATH,
            'uploads' => UPLOADS_PATH
        ],
        'security' => [
            'password_min_length' => PASSWORD_MIN_LENGTH,
            'session_timeout' => SESSION_TIMEOUT,
            'max_login_attempts' => MAX_LOGIN_ATTEMPTS
        ],
        'upload' => [
            'max_file_size' => MAX_FILE_SIZE,
            'allowed_types' => ALLOWED_IMAGE_TYPES,
            'max_business_images' => MAX_BUSINESS_IMAGES
        ]
    ];
    
    /**
     * وەرگرتنی ڕێکخستن
     */
    public static function get($key, $default = null) {
        $keys = explode('.', $key);
        $value = self::$settings;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }
    
    /**
     * دانانی ڕێکخستن
     */
    public static function set($key, $value) {
        $keys = explode('.', $key);
        $setting = &self::$settings;
        
        foreach ($keys as $k) {
            $setting = &$setting[$k];
        }
        
        $setting = $value;
    }
}

/**
 * وەرگرتنی ID ی بەکارهێنەری ئێستا
 */
function getCurrentUserId() {
    if (isset($_SESSION['user_data']['id'])) {
        return $_SESSION['user_data']['id'];
    }
    // Fallback بۆ کۆدی کۆن
    return $_SESSION['user_id'] ?? null;
}

// فانکشنە یارمەتیدەرەکان

/**
 * گەڕاندنەوەی URL ی تەواو
 */
function url($path = '') {
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * گەڕاندنەوەی ڕێگای فایل
 */
function path($path = '') {
    return rtrim(ROOT_PATH, '/') . '/' . ltrim($path, '/');
}

/**
 * گەڕاندنەوەی version خۆکار بۆ فایلێکی static (cache busting)
 * کاتی دوایین گۆڕانکاری فایلەکە (filemtime) بەکاردێت — بۆیە هەر جارێک
 * فایلەکە بگۆڕیت، version خۆکارانە دەگۆڕێت و پێویست ناکات بە دەستی هیچ بگۆڕیت.
 * $absFile: ڕێگای تەواوی فایلەکە لەسەر دیسک
 */
function file_version($absFile) {
    return is_file($absFile) ? filemtime($absFile) : SITE_VERSION;
}

/**
 * گەڕاندنەوەی URL ی assets بە versioning خۆکار بۆ cache busting
 * $path: نسبت بە بوخچەی /assets، وەک 'css/style.css'
 */
function asset($path = '') {
    $path = ltrim($path, '/');
    $url  = rtrim(ASSETS_PATH, '/') . '/' . $path;
    // version خۆکار لە filemtime ـی فایلە ڕاستەقینەکەوە
    $ver  = file_version(ROOT_PATH . '/assets/' . $path);
    $separator = strpos($url, '?') !== false ? '&' : '?';
    return $url . $separator . 'v=' . $ver;
}

/**
 * URL ـی فایلێکی static (CSS/JS) لە هەر شوێنێکی پڕۆژە بە version خۆکار
 * $path: نسبت بە ڕەگی پڕۆژە، وەک 'user/pos/css/pos-design.css'
 */
function asset_url($path = '') {
    $path = ltrim($path, '/');
    $ver  = file_version(ROOT_PATH . '/' . $path);
    return rtrim(SITE_URL, '/') . '/' . $path . '?v=' . $ver;
}

/**
 * تاقیکردنەوەی URL ـی دەرەکی
 */
function is_url($string) {
    return filter_var($string, FILTER_VALIDATE_URL) !== false;
}

/**
 * گەڕاندنەوەی URL ی uploads
 */
function upload($path = '') {
    return rtrim(UPLOADS_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * تاقیکردنی داخڵبوون
 */
function isLoggedIn($type = 'user') {
    return isset($_SESSION[$type . '_logged_in']) && $_SESSION[$type . '_logged_in'] === true;
}

/**
 * تاقیکردنی ڕۆڵی بەکارهێنەر
 */
function isAdmin() {
    return isLoggedIn('admin');
}

function isUser() {
    return isLoggedIn('user');
}

/**
 * وەرگرتنی زانیاری بەکارهێنەری داخڵبوو
 */
function getCurrentUser() {
    if (isUser()) {
        return $_SESSION['user_data'] ?? null;
    }
    return null;
}

function getCurrentAdmin() {
    if (isAdmin()) {
        return $_SESSION['admin_data'] ?? null;
    }
    return null;
}

/**
 * ڕیدایڕێکت کردن
 */
function redirect($url) {
    if (headers_sent()) {
        echo "<script>window.location.href='$url';</script>";
    } else {
        header("Location: $url");
    }
    exit();
}

/**
 * پیشاندانی پەیام
 */
function setMessage($message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function getMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

/**
 * فۆرماتکردنی پارە
 */
function formatMoney($amount, $currency = DEFAULT_CURRENCY) {
    // Handle null or empty values
    if ($amount === null || $amount === '') {
        $amount = 0;
    }
    
    // Format USD with $ symbol
    if ($currency === 'USD') {
        return '$' . number_format((float)$amount, 0);
    }
    
    // Default to IQD with دینار
    return number_format((float)$amount, 0) . ' دینار';
}

/**
 * فۆرماتکردنی بەروار
 */
function formatDate($date, $format = 'Y-m-d H:i:s') {
    if (is_string($date)) {
        $date = new DateTime($date, new DateTimeZone(DEFAULT_TIMEZONE));
    }
    return $date->format($format);
}

/**
 * وەرگرتنی کاتی ئێستا بە timezone ی دروست
 */
function getCurrentDateTime($format = 'Y-m-d H:i:s') {
    $now = new DateTime('now', new DateTimeZone(DEFAULT_TIMEZONE));
    return $now->format($format);
}

/**
 * دروستکردنی کۆدی بارکۆد
 */
function generateBarcode($prefix = 'POS') {
    return $prefix . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * دروستکردنی ژمارەی پسوولە
 */
function generateInvoiceNumber() {
    // Add microseconds and increase randomness range to reduce collision probability
    $microseconds = substr(str_replace('.', '', microtime(true)), -6); // Last 6 digits of microseconds
    $random = mt_rand(1000, 9999); // Increased range from 999 to 9999
    return 'INV-' . date('Ymd') . '-' . $random . '-' . $microseconds;
}

/**
 * پاککردنەوەی HTML
 */
function clean($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * تاقیکردنی ئیمەیڵ
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * تاقیکردنی ژمارەی مۆبایل
 */
function isValidPhone($phone) {
    return preg_match('/^[0-9+\-\s()]{10,20}$/', $phone);
}

/**
 * دروستکردنی log
 */
function writeLog($message, $level = 'INFO') {
    $logFile = ROOT_PATH . '/logs/' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// دانانی HTTP Headers بۆ ئامنیەت و cache control
function setCacheHeaders() {
    // Security Headers
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    
    // Cache Control Headers - لە بنەڕەتدا cache ناکات بۆ HTML
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

// جێبەجێکردنی cache headers بۆ PHP pages
if (!defined('NO_CACHE_HEADERS')) {
    setCacheHeaders();
}

// بەکارهێنان
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/timezone.php';

// دەستپێکردنی session ـی ئامن و Remember Me (یەک شوێن تەنها) — product_images لە کۆتایی security.php بار دەکرێت
require_once __DIR__ . '/security.php';

// لایەری «بزنسی چالاک» (چەندین بزنس) — دوای دەستپێکردنی session و Remember Me.
// بۆ بەکارهێنەری تاک/کارمەند بێ-زیانە (no-op)؛ تەنها بۆ خاوەنی ڕێکخراو re-scope دەکات.
require_once __DIR__ . '/../includes/business_context.php';
if (function_exists('resolveBusinessContext')) {
    resolveBusinessContext();
}

require_once ROOT_PATH . '/includes/theme_bootstrap.php';
if (!function_exists('kasher_theme_output_injector')) {
    function kasher_theme_output_injector($buffer) {
        if (!kasher_should_apply_theme_bootstrap()) {
            return $buffer;
        }

        if (!is_string($buffer) || $buffer === '') {
            return $buffer;
        }

        if (stripos($buffer, '<html') === false || stripos($buffer, '<head') === false) {
            return $buffer;
        }

        if (strpos($buffer, 'id="kasher-theme-bootstrap"') !== false) {
            return $buffer;
        }

        $markup = kasher_get_theme_bootstrap_markup();
        if ($markup === '') {
            return $buffer;
        }

        return preg_replace('/<head([^>]*)>/i', '<head$1>' . $markup, $buffer, 1);
    }
}

if (
    PHP_SAPI !== 'cli'
    && !headers_sent()
    && !defined('KASHER_THEME_OB_STARTED')
    && kasher_should_apply_theme_bootstrap()
) {
    define('KASHER_THEME_OB_STARTED', true);
    ob_start('kasher_theme_output_injector');
}

?>