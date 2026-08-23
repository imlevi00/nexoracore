<?php
/**
 * ====================================================================
 * App API - Shared Bootstrap  (web/app-api/_bootstrap.php)
 * ====================================================================
 * بناغەی هاوبەشی API ی تایبەت بە بەرنامەی مۆبایل/دێسکتۆپ (Flutter).
 *
 * ئەم پەڕگەیە:
 *   - Header ـەکانی JSON و CORS دادەنێت
 *   - داواکاری OPTIONS (preflight) بەرپەرچ دەداتەوە
 *   - config/database ی پڕۆژە بار دەکات ($conn, product_image_url, ...)
 *   - چەند فانکشنێکی یارمەتیدەر بۆ وەڵامدانەوە و فۆرماتکردنی داتا
 *
 * هەموو endpoint ـەکان تەنها ئەم پەڕگەیە require دەکەن.
 * هیچ گۆڕانکارییەک لە پەڕگە کۆنەکانی سایتەکە ناکات.
 * ====================================================================
 */

// نیشانەیەک بۆ ئەوەی endpoint ـەکان دڵنیابن لە ڕێگەی bootstrap ـەوە بانگ کراون
define('APP_API', true);

// ------------------------------------------------------------------
// 1) Header ـەکان: JSON + CORS
// ------------------------------------------------------------------
// API یەکی گشتییە بۆ خوێندنەوە (فرۆشگا/کاڵاکان)، بۆیە هەموو Origin ڕێگەپێدراوە.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// بەرپەرچدانەوەی preflight ی مرۆرگەرەکان پێش بارکردنی هیچ شتێکی قورس
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------------
// 2) بارکردنی بناغەی پڕۆژە
// ------------------------------------------------------------------
// config.php خۆی database.php, functions.php, security.php (session),
// product_images.php و spaces_s3_http.php بار دەکات.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../auth/shop_google_access.php';

// ------------------------------------------------------------------
// 3) فانکشنە یارمەتیدەرەکانی وەڵامدانەوە
// ------------------------------------------------------------------

/**
 * وەڵامی سەرکەوتوو بە JSON و کۆتاییهێنان بە پرۆسەکە.
 *
 * @param mixed      $data داتای سەرەکی (data)
 * @param array|null $meta زانیاری زیادە (pagination, ...)
 * @param int        $code HTTP status code
 */
function app_api_success($data, ?array $meta = null, int $code = 200): void
{
    http_response_code($code);
    $payload = ['success' => true, 'data' => $data];
    if ($meta !== null) {
        $payload['meta'] = $meta;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * وەڵامی هەڵە بە JSON.
 *
 * @param string $message پەیامی مرۆیی (کوردی)
 * @param int    $code    HTTP status code
 * @param string $errCode کۆدی ماشینی بۆ بەرنامەکە (وەک not_found)
 */
function app_api_error(string $message, int $code = 400, string $errCode = 'error'): void
{
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => ['code' => $errCode, 'message' => $message],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * تەنها ڕێگە بە شێوازێکی HTTP دیاریکراو دەدات.
 */
function app_api_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        app_api_error('شێوازی داواکاری نادروستە. تەنها ' . $method . ' ڕێگەپێدراوە.', 405, 'method_not_allowed');
    }
}

/**
 * خوێندنەوەی body ی JSON بۆ داواکارییەکانی POST.
 *
 * @return array<string,mixed>
 */
function app_api_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        app_api_error('داتای نێردراو دروست نییە (JSON پێویستە).', 400, 'invalid_json');
    }
    return $data;
}

// ------------------------------------------------------------------
// 4) ئاسایشی: IP، کلیلی API، و سنووردارکردنی داواکاری (Rate Limit)
// ------------------------------------------------------------------

/**
 * وەرگرتنی IP ی ڕاستەقینەی کڕیار (لەگەڵ ڕەچاوکردنی proxy/CDN).
 */
function app_api_client_ip(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return substr($ip, 0, 45);
}

/**
 * پشکنینی کلیلی API. بەرنامەکە دەبێت کلیل بنێرێت بە:
 *   Header:  X-API-Key: <key>
 *   یان:     Authorization: Bearer <key>
 *
 * کلیلە دروستەکان لە web/app-api/keys.php وەردەگیرێن.
 */
function app_api_require_key(): void
{
    $keysFile = __DIR__ . '/keys.php';
    if (!is_file($keysFile)) {
        // fail-closed: ئەگەر کلیل ڕێکنەخرابێت لەسەر سێرڤەر، ڕێگە نادرێت
        error_log('App API: keys.php not found — refusing requests.');
        app_api_error('APIـەکە هێشتا لەسەر سێرڤەر ڕێکنەخراوە (کلیل نییە).', 503, 'server_not_configured');
    }

    /** @var array<string,string> $validKeys */
    $validKeys = require $keysFile;
    if (!is_array($validKeys) || count($validKeys) === 0) {
        app_api_error('APIـەکە هێشتا لەسەر سێرڤەر ڕێکنەخراوە.', 503, 'server_not_configured');
    }

    // خوێندنەوەی کلیل لە header ـەکانەوە
    $provided = '';
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        $provided = trim((string)$_SERVER['HTTP_X_API_KEY']);
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.+)/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $provided = trim($m[1]);
        }
    } elseif (function_exists('getallheaders')) {
        // fallback بۆ هەندێک ڕێکخستنی سێرڤەر کە Authorization ناخاتە $_SERVER
        foreach (getallheaders() as $hk => $hv) {
            $lk = strtolower($hk);
            if ($lk === 'x-api-key') {
                $provided = trim((string)$hv);
                break;
            }
            if ($lk === 'authorization' && preg_match('/Bearer\s+(.+)/i', (string)$hv, $m)) {
                $provided = trim($m[1]);
                break;
            }
        }
    }

    if ($provided === '') {
        app_api_error('کلیلی API نەنێردراوە (X-API-Key پێویستە).', 401, 'missing_api_key');
    }

    foreach ($validKeys as $key) {
        if (is_string($key) && $key !== '' && hash_equals($key, $provided)) {
            return; // کلیل دروستە
        }
    }

    app_api_error('کلیلی API دروست نییە.', 401, 'invalid_api_key');
}

/**
 * سنووردارکردنی ژمارەی داواکاری (Rate Limit) — پەنجەرەی جێگیر (fixed window).
 * ئەگەر سنوور تێپەڕێنرا، بە کۆدی 429 کۆتایی دەهێنێت.
 *
 * @param string      $scope         ناوی بەش (وەک 'general' یان 'order')
 * @param int         $limit         زۆرترین داواکاری لە پەنجەرەکەدا
 * @param int         $windowSeconds درێژی پەنجەرە بە چرکە
 * @param string|null $identifier    ناسنامە (بنەڕەت: IP). بۆ مۆبایل دەکرێت بنێردرێت.
 */
function app_api_rate_limit(mysqli $conn, string $scope, int $limit, int $windowSeconds, ?string $identifier = null): void
{
    static $ensured = false;
    if (!$ensured) {
        $conn->query("CREATE TABLE IF NOT EXISTS app_api_rate_limit (
            rl_key VARCHAR(190) NOT NULL PRIMARY KEY,
            window_start INT NOT NULL,
            hits INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ensured = true;
        // پاککردنەوەی ڕیزە کۆنەکان بە شانسێکی بچووک (١٪) — بۆ ئەوەی خشتەکە گەورە نەبێت
        if (mt_rand(1, 100) === 1) {
            $old = time() - 86400;
            $conn->query("DELETE FROM app_api_rate_limit WHERE window_start < $old");
        }
    }

    $id = $identifier !== null && $identifier !== '' ? $identifier : app_api_client_ip();
    $window = (int) floor(time() / $windowSeconds);
    $windowStart = $window * $windowSeconds;
    $rlKey = substr($scope . '|' . $id . '|' . $window, 0, 190);

    $up = $conn->prepare("
        INSERT INTO app_api_rate_limit (rl_key, window_start, hits)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE hits = hits + 1
    ");
    if ($up) {
        $up->bind_param('si', $rlKey, $windowStart);
        $up->execute();
        $up->close();
    } else {
        return; // ئەگەر بۆ هەر هۆیەک نەکرا، ڕێگری مەکە (fail-open بۆ لیمیت)
    }

    $sel = $conn->prepare("SELECT hits FROM app_api_rate_limit WHERE rl_key = ?");
    $sel->bind_param('s', $rlKey);
    $sel->execute();
    $hits = (int)($sel->get_result()->fetch_assoc()['hits'] ?? 0);
    $sel->close();

    if ($hits > $limit) {
        $retry = $windowStart + $windowSeconds - time();
        header('Retry-After: ' . max(1, $retry));
        app_api_error('داواکاری زۆر زۆرە. تکایە کەمێک چاوەڕوان بە و دووبارە هەوڵ بدەرەوە.', 429, 'rate_limited');
    }
}

// ------------------------------------------------------------------
// 5) فانکشنە یارمەتیدەرەکانی داتا/فرۆشگا
// ------------------------------------------------------------------

/**
 * دووبارەکردنەوەی لۆژیکی سایتەکە بۆ URL ی بەنەڕی فرۆشگا (shop_banner).
 */
function app_api_shop_banner_url(?string $bannerValue): string
{
    $bannerValue = trim((string)$bannerValue);
    if ($bannerValue === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $bannerValue)) {
        return $bannerValue;
    }
    $normalized = ltrim($bannerValue, '/');
    if (strpos($normalized, 'img/shop_banner/') !== 0) {
        if (strpos($normalized, 'shop_banner/') === 0) {
            $normalized = 'img/' . $normalized;
        } elseif (strpos($normalized, '/') === false) {
            $normalized = 'img/shop_banner/' . $normalized;
        } else {
            return '';
        }
    }
    $url = function_exists('spaces_public_url_for_object_key')
        ? spaces_public_url_for_object_key($normalized)
        : null;
    return $url ?? '';
}

/**
 * URL ی وێنەی کاڵا. ئەگەر وێنە نەبوو، وێنەی بنەڕەتی (no-image) دەگەڕێنێتەوە.
 */
function app_api_product_image_url(?string $dbPath): string
{
    if (!empty($dbPath) && function_exists('product_image_url')) {
        $u = product_image_url($dbPath);
        if ($u) {
            return $u;
        }
    }
    return rtrim(SITE_URL, '/') . '/web/template/assets/images/no-image.svg';
}

/**
 * وەرگرتنی زانیاری فرۆشگایەکی چالاک بەپێی slug.
 * ئەگەر نەدۆزرایەوە یان ناچالاک بوو، NULL دەگەڕێنێتەوە.
 *
 * @return array<string,mixed>|null
 */
function app_api_get_shop_by_slug(mysqli $conn, string $slug): ?array
{
    $stmt = $conn->prepare("
        SELECT ws.*, u.business_name, u.phone AS shop_phone, u.address AS shop_address
        FROM website_settings ws
        INNER JOIN users u ON ws.user_id = u.id
        WHERE ws.website_slug = ? AND ws.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * دیاریکردنی ئەوەی فرۆشگا سنووردارە بە Google (shop_google_restrict).
 * ئەگەر بەرنامەکە session ی گووگڵی نەبێت، ناتوانێت داتای ئەم فرۆشگایانە ببینێت.
 * ئەم فانکشنە بۆ endpoint ـەکانی کاڵا و داواکاری بەکاردێت تا هەمان
 * ئاسایشی سایتەکە بپارێزرێت.
 */
function app_api_guard_shop_access(mysqli $conn, int $merchantUserId): void
{
    if (function_exists('shop_google_access_api_error')) {
        $err = shop_google_access_api_error($conn, $merchantUserId);
        if ($err) {
            app_api_error($err['message'], (int)$err['http'], 'access_restricted');
        }
    }
}

/**
 * فۆرماتکردنی نرخ بۆ پیشاندان (وەک سایتەکە): "1,500 دینار" یان "3.50 دۆلار".
 */
function app_api_format_price(?float $price, string $currency = 'IQD'): string
{
    $price = (float)($price ?? 0);
    $isUsd = ($currency === 'USD');
    $decimals = $isUsd ? 2 : 0;
    $formatted = number_format($price, $decimals, '.', ',');
    return $formatted . ($isUsd ? ' دۆلار' : ' دینار');
}

/**
 * فۆرماتکردنی یەک یەکەی کاڵا (product_unit) بۆ JSON، بەپێی ڕێکخستنی
 * پیشاندانی فرۆشگا (show_retail_price / show_wholesale_price / ...).
 *
 * @param array<string,mixed> $unit    ڕیزی product_units + units
 * @param array<string,mixed> $shop    ڕێکخستنەکانی website_settings
 * @param string              $currency دراوی کاڵا
 * @return array<string,mixed>
 */
function app_api_format_unit(array $unit, array $shop, string $currency): array
{
    $showRetail    = !empty($shop['show_retail_price']);
    $showWholesale = !empty($shop['show_wholesale_price']);
    $showSpecial   = !empty($shop['show_special_price']);
    $showStock     = !empty($shop['show_stock_quantity']);

    $retail    = isset($unit['sell_price']) ? (float)$unit['sell_price'] : 0.0;
    $wholesale = isset($unit['wholesale_price']) ? (float)$unit['wholesale_price'] : 0.0;
    $special   = isset($unit['special_price']) ? (float)$unit['special_price'] : 0.0;
    $stock     = isset($unit['stock_quantity']) ? (float)$unit['stock_quantity'] : 0.0;

    return [
        'unit_id'        => isset($unit['id']) ? (int)$unit['id'] : null,
        'unit_name'      => $unit['unit_name'] ?? 'دانە',
        'unit_symbol'    => $unit['unit_symbol'] ?? null,
        'is_primary'     => !empty($unit['is_primary']),
        // نرخەکان — تەنها ئەوانە پیشان دەدرێن کە فرۆشگا ڕێگەی پێداوە (وەک سایتەکە)
        'sell_price'      => $showRetail ? $retail : null,
        'wholesale_price' => $showWholesale ? $wholesale : null,
        'special_price'   => $showSpecial ? $special : null,
        'sell_price_formatted'      => $showRetail ? app_api_format_price($retail, $currency) : null,
        'wholesale_price_formatted' => $showWholesale ? app_api_format_price($wholesale, $currency) : null,
        'special_price_formatted'   => $showSpecial ? app_api_format_price($special, $currency) : null,
        // بڕی بەردەست
        'stock_quantity' => $showStock ? (float)$stock : null,
        'in_stock'       => $stock > 0,
    ];
}

/**
 * وەرگرتنی هەموو یەکەکانی کاڵایەک.
 *
 * @return array<int,array<string,mixed>>
 */
function app_api_get_product_units(mysqli $conn, int $productId): array
{
    $stmt = $conn->prepare("
        SELECT pu.*, u.name AS unit_name, u.symbol AS unit_symbol
        FROM product_units pu
        INNER JOIN units u ON pu.unit_id = u.id
        WHERE pu.product_id = ?
        ORDER BY pu.is_primary DESC, pu.id ASC
    ");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $units = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $units;
}

/**
 * فۆرماتکردنی کاڵایەک بۆ لیستەکان (زانیاری کورت + یەکەی سەرەکی).
 * داتاکەی لەو query ـانەوە دێت کە یەکەی سەرەکی (primary unit) پێوە لکاندووە.
 *
 * @param array<string,mixed> $p    ڕیزی کاڵا (products + primary unit + product_details)
 * @param array<string,mixed> $shop ڕێکخستنەکانی website_settings
 * @return array<string,mixed>
 */
function app_api_format_product_row(array $p, array $shop): array
{
    $currency = $p['currency'] ?? 'IQD';
    $showRetail    = !empty($shop['show_retail_price']);
    $showWholesale = !empty($shop['show_wholesale_price']);
    $showSpecial   = !empty($shop['show_special_price']);
    $showStock     = !empty($shop['show_stock_quantity']);
    $showCategory  = !empty($shop['show_by_category']);

    $retail    = isset($p['retail_price']) ? (float)$p['retail_price'] : 0.0;
    $wholesale = isset($p['wholesale_price']) ? (float)$p['wholesale_price'] : 0.0;
    $special   = isset($p['special_price']) ? (float)$p['special_price'] : 0.0;
    $discount  = isset($p['discount_price']) ? (float)$p['discount_price'] : 0.0;
    $stock     = isset($p['stock_quantity']) ? (float)$p['stock_quantity'] : 0.0;
    $hasDiscount = $discount > 0;

    // وێنەی سەرەکی: main_image (لە product_details) یان image_path
    $imgSource = !empty($p['main_image']) ? $p['main_image'] : ($p['image_path'] ?? '');

    return [
        'id'            => (int)$p['id'],
        'name'          => $p['name'],
        'barcode'       => $p['barcode'] ?? null,
        'image'         => app_api_product_image_url($imgSource),
        'category_id'   => ($showCategory && !empty($p['category_id'])) ? (int)$p['category_id'] : null,
        'category_name' => ($showCategory && !empty($p['category_name'])) ? $p['category_name'] : null,
        'currency'      => $currency,
        'unit_id'       => isset($p['unit_id']) && $p['unit_id'] !== '' ? (int)$p['unit_id'] : null,
        'unit_name'     => $p['unit_name'] ?? 'دانە',
        // نرخەکان (بەپێی ڕێکخستنی فرۆشگا)
        'sell_price'      => $showRetail ? $retail : null,
        'wholesale_price' => $showWholesale ? $wholesale : null,
        'special_price'   => $showSpecial ? $special : null,
        'discount_price'  => $hasDiscount ? $discount : null,
        'sell_price_formatted'     => $showRetail ? app_api_format_price($retail, $currency) : null,
        'wholesale_price_formatted'=> $showWholesale ? app_api_format_price($wholesale, $currency) : null,
        'discount_price_formatted' => $hasDiscount ? app_api_format_price($discount, $currency) : null,
        'has_discount'  => $hasDiscount,
        // بڕی بەردەست
        'stock_quantity'=> $showStock ? $stock : null,
        'in_stock'      => $stock > 0,
    ];
}

/**
 * ئایا ستوونی show_on_index لە website_settings هەیە؟ (بۆ ڕەچاوکردنی
 * هەمان ڕەفتاری لاپەڕەی سەرەکی).
 */
function app_api_has_show_on_index(mysqli $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $res = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'show_on_index'");
    $cached = ($res && $res->num_rows > 0);
    if ($res) {
        $res->close();
    }
    return $cached;
}

// ------------------------------------------------------------------
// 6) جێبەجێکردنی ئاسایشی بۆ هەموو endpoint ـەکان
// ------------------------------------------------------------------
// (١) پشکنینی کلیلی API — بەبێ کلیلی دروست، هیچ endpointـێک کار ناکات.
app_api_require_key();

// (٢) سنووردارکردنی گشتی بۆ هەر IP — ڕێگری لە بەکارهێنانی زۆر/DoS.
//     ١٨٠ داواکاری لە هەر ٦٠ چرکەیەکدا (بەشی نووسین سنوورێکی توندتری هەیە).
app_api_rate_limit($conn, 'general', 180, 60);
