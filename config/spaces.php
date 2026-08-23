<?php
/**
 * DigitalOcean Spaces (S3-compatible) — ڕێکخستن.
 *
 * نرخەکان: getenv() یان config/spaces.local.php (کۆپی لە spaces.example.php)
 */

require_once __DIR__ . '/secrets.php';

// legacy fallback: config/spaces.local.php (ئەگەر هێشتا بەکاردێت — دواتر دەکرێت بسڕدرێتەوە)
$spacesLocal = [];
$localFile = __DIR__ . '/spaces.local.php';
if (is_readable($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) {
        $spacesLocal = $loaded;
    }
}

// تەرتیب: environment variable → فایلی نهێنی یەکگرتوو (secrets.local.php) → spaces.local.php (کۆن) → default
$envOr = static function (string $secretKey, string $envName, $localKey, $default = '') use ($spacesLocal) {
    $v = kasher_secret($secretKey, $envName, '');
    if ($v !== '') {
        return $v;
    }
    if ($localKey !== null && isset($spacesLocal[$localKey]) && $spacesLocal[$localKey] !== '') {
        return $spacesLocal[$localKey];
    }
    return $default;
};

define('SPACES_KEY', (string) $envOr('spaces_key', 'SPACES_KEY', 'key', ''));
define('SPACES_SECRET', (string) $envOr('spaces_secret', 'SPACES_SECRET', 'secret', ''));
define('SPACES_BUCKET', (string) $envOr('spaces_bucket', 'SPACES_BUCKET', 'bucket', ''));
define('SPACES_REGION', (string) $envOr('spaces_region', 'SPACES_REGION', 'region', 'fra1'));
define('SPACES_ENDPOINT', (string) $envOr('spaces_endpoint', 'SPACES_ENDPOINT', 'endpoint', ''));
define('SPACES_PUBLIC_BASE_URL', rtrim((string) $envOr('spaces_public_base_url', 'SPACES_PUBLIC_BASE_URL', 'public_base_url', ''), '/'));

/** پەردەختکردنی وێنە بۆ Spaces — یەکگرتوو لەگەڵ SecureFileUpload::compressImage */
define('SPACES_IMAGE_MAX_WIDTH', 1920);
define('SPACES_IMAGE_MAX_HEIGHT', 1080);
define('SPACES_IMAGE_JPEG_QUALITY', 80);

/** Object key prefix لە bucket (بە / کۆتایی نەهێنرێت) */
define('PRODUCT_SPACES_OBJECT_PREFIX', 'img/products');
