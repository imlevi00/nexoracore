<?php
/**
 * config/env.php
 * ---------------------------------------------------------------------------
 * ناوەندێکی یەکگرتوو بۆ دیاریکردنی ژینگە (Environment) و کرێدێنشاڵی داتابەیس.
 *
 * ئامانج: هەمان کۆد بەبێ هیچ گۆڕانکارییەک کار بکات هەم لەسەر XAMPP ـی لۆکاڵی
 * و هەم لەسەر سێرڤەری production.
 *
 * چۆنیەتی کارکردن:
 *   - ئەم فایلە خۆکارانە دەزانێت ئایا لەسەر لۆکاڵ (XAMPP/Windows) ئەکرێت یان
 *     لەسەر سێرڤەر (Linux)، پاشان کرێدێنشاڵی گونجاو define ئەکات.
 *   - هەموو فایلەکانی config/<db>/config.php لە `if (!defined())` کەڵک وەردەگرن،
 *     بۆیە ئەو نرخانەی لێرە define کراون سەرەکین و ئەوانی تر تەنیا fallback ـن.
 *
 * سازکردنی دەستی (ئیختیاری):
 *   ئەگەر ویستت نرخی تایبەت بۆ ئەم کۆمپیوتەرە دابنێیت (بۆ نموونە یوزەر/پاسۆردی
 *   جیاواز لەسەر لۆکاڵ)، فایلێکی بەناوی `config/env.local.php` دروست بکە —
 *   ئەو فایلە پێش هەموو شتێک بار دەبێت و دەتوانێت هەر constant ـێک define بکات.
 *   ئەو فایلە لە git پاشگوێ خراوە (.gitignore) بۆیە کاریگەری لەسەر سێرڤەر نابێت.
 * ---------------------------------------------------------------------------
 */

if (defined('KASHER_ENV_LOADED')) {
    return;
}
define('KASHER_ENV_LOADED', true);

// لۆدەری نهێنی (kasher_secret) — نرخە هەستیارەکان لە config/secrets.local.php دێن
require_once __DIR__ . '/secrets.php';

/* ---------------------------------------------------------------------------
 * ١) سازکردنی دەستی (ئەگەر هەبێت) — بەرەوپێش لە هەموو شتێک
 * ------------------------------------------------------------------------- */
if (is_file(__DIR__ . '/env.local.php')) {
    require_once __DIR__ . '/env.local.php';
}

/* ---------------------------------------------------------------------------
 * ٢) دیاریکردنی ژینگە
 *    سیگناڵی سەرەکی: سیستەمی کارپێکردن (Windows = لۆکاڵ، Linux = سێرڤەر)
 *    یارمەتیدەر: ناوی هۆست (localhost / 127.0.0.1)
 *    دەتوانرێت بە دەستیش زۆرەملێ بکرێت لە env.local.php بەهۆی KASHER_IS_LOCAL
 * ------------------------------------------------------------------------- */
if (!defined('KASHER_IS_LOCAL')) {
    $isLocal = false;

    // (أ) بەپێی سیستەمی کارپێکردن — سێرڤەر Linux ـە، لۆکاڵ Windows/XAMPP
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $isLocal = true;
    }

    // (ب) بەپێی ناوی هۆست (بۆ داواکاری وێب)
    if (!$isLocal) {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $host = strtolower(preg_replace('/:\d+$/', '', (string)$host));
        if (
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            $host === '::1' ||
            substr($host, -6) === '.local' ||
            substr($host, -5) === '.test'
        ) {
            $isLocal = true;
        }
    }

    define('KASHER_IS_LOCAL', $isLocal);
}

/* ---------------------------------------------------------------------------
 * ٣) کرێدێنشاڵی داتابەیسەکان
 *    هەموویان بە `if (!defined())` بۆ ئەوەی env.local.php بتوانێت زۆرەملێ بکات.
 * ------------------------------------------------------------------------- */
if (KASHER_IS_LOCAL) {
    /* ===== ژینگەی لۆکاڵ (XAMPP) =====
     * XAMPP بە بنەڕەت یوزەری root یان itsmelevi بەکاردەهێنێت.
     * ناوی داتابەیس: nexoracore_db
     */

    // ڕێکخستنی XAMPP: دەتوانرێت host/port/password لە env یان secrets.local.php دابنرێت.
    $localDbHost = kasher_secret('local_db_host', 'DB_HOST', '127.0.0.1');
    $localDbPort = kasher_secret('local_db_port', 'DB_PORT', '');
    $localDbUser = kasher_secret('local_db_username', 'DB_USERNAME', 'itsmelevi');
    $localDbPassword = kasher_secret(
        'local_db_password',
        'DB_PASSWORD',
        kasher_secret('db_password', 'DB_PASSWORD', 'levi12345')
    );
    if ($localDbPort !== '' && strpos($localDbHost, ':') === false) {
        $localDbHost .= ':' . $localDbPort;
    }

    // -- داتابەیسی سەرەکی (nexoracore_db) --
    if (!defined('DB_HOST'))     define('DB_HOST', $localDbHost);
    if (!defined('DB_USERNAME')) define('DB_USERNAME', $localDbUser);
    if (!defined('DB_PASSWORD')) define('DB_PASSWORD', $localDbPassword);
    if (!defined('DB_NAME'))     define('DB_NAME', 'nexoracore_db');
    if (!defined('DB_CHARSET'))  define('DB_CHARSET', 'utf8mb4');

    // -- kasher_platform --
    if (!defined('KASHER_PLATFORM_DB_HOST'))     define('KASHER_PLATFORM_DB_HOST', $localDbHost);
    if (!defined('KASHER_PLATFORM_DB_USERNAME')) define('KASHER_PLATFORM_DB_USERNAME', kasher_secret('local_db_username', 'KASHER_PLATFORM_DB_USERNAME', $localDbUser));
    if (!defined('KASHER_PLATFORM_DB_PASSWORD')) define('KASHER_PLATFORM_DB_PASSWORD', kasher_secret('local_db_password', 'KASHER_PLATFORM_DB_PASSWORD', $localDbPassword));
    if (!defined('KASHER_PLATFORM_DB_NAME'))     define('KASHER_PLATFORM_DB_NAME', 'nexoracore_db');
    if (!defined('KASHER_PLATFORM_DB_CHARSET'))  define('KASHER_PLATFORM_DB_CHARSET', 'utf8mb4');

    // -- kasher_zanyari (kasher_z) --
    if (!defined('ZANYARI_DB_HOST'))     define('ZANYARI_DB_HOST', $localDbHost);
    if (!defined('ZANYARI_DB_USERNAME')) define('ZANYARI_DB_USERNAME', kasher_secret('local_db_username', 'ZANYARI_DB_USERNAME', $localDbUser));
    if (!defined('ZANYARI_DB_PASSWORD')) define('ZANYARI_DB_PASSWORD', kasher_secret('local_db_password', 'ZANYARI_DB_PASSWORD', $localDbPassword));
    if (!defined('ZANYARI_DB_NAME'))     define('ZANYARI_DB_NAME', 'nexoracore_db');
    if (!defined('ZANYARI_DB_CHARSET'))  define('ZANYARI_DB_CHARSET', 'utf8mb4');

    // -- kasher_logs --
    if (!defined('KASHER_LOGS_DB_HOST'))     define('KASHER_LOGS_DB_HOST', $localDbHost);
    if (!defined('KASHER_LOGS_DB_USERNAME')) define('KASHER_LOGS_DB_USERNAME', kasher_secret('local_db_username', 'KASHER_LOGS_DB_USERNAME', $localDbUser));
    if (!defined('KASHER_LOGS_DB_PASSWORD')) define('KASHER_LOGS_DB_PASSWORD', kasher_secret('local_db_password', 'KASHER_LOGS_DB_PASSWORD', $localDbPassword));
    if (!defined('KASHER_LOGS_DB_NAME'))     define('KASHER_LOGS_DB_NAME', 'nexoracore_db');
    if (!defined('KASHER_LOGS_DB_CHARSET'))  define('KASHER_LOGS_DB_CHARSET', 'utf8mb4');

    // -- kasher_media (وێنەکان) --
    if (!defined('IMAGES_DB_HOST'))     define('IMAGES_DB_HOST', $localDbHost);
    if (!defined('IMAGES_DB_USERNAME')) define('IMAGES_DB_USERNAME', kasher_secret('local_db_username', 'IMAGES_DB_USERNAME', $localDbUser));
    if (!defined('IMAGES_DB_PASSWORD')) define('IMAGES_DB_PASSWORD', kasher_secret('local_db_password', 'IMAGES_DB_PASSWORD', $localDbPassword));
    if (!defined('IMAGES_DB_NAME'))     define('IMAGES_DB_NAME', 'nexoracore_db');
    if (!defined('IMAGES_DB_CHARSET'))  define('IMAGES_DB_CHARSET', 'utf8mb4');

    // -- kasher_media (ڤیدیۆکان — هەمان داتابەیسی media) --
    if (!defined('VIDEOS_DB_HOST'))     define('VIDEOS_DB_HOST', $localDbHost);
    if (!defined('VIDEOS_DB_USERNAME')) define('VIDEOS_DB_USERNAME', kasher_secret('local_db_username', 'VIDEOS_DB_USERNAME', $localDbUser));
    if (!defined('VIDEOS_DB_PASSWORD')) define('VIDEOS_DB_PASSWORD', kasher_secret('local_db_password', 'VIDEOS_DB_PASSWORD', $localDbPassword));
    if (!defined('VIDEOS_DB_NAME'))     define('VIDEOS_DB_NAME', 'nexoracore_db');
    if (!defined('VIDEOS_DB_CHARSET'))  define('VIDEOS_DB_CHARSET', 'utf8mb4');

} else {
    /* ===== ژینگەی سێرڤەر (Production) =====
     */

    // -- داتابەیسی سەرەکی (nexoracore_db) --
    if (!defined('DB_HOST'))     define('DB_HOST', 'localhost');
    if (!defined('DB_USERNAME')) define('DB_USERNAME', 'itsmelevi');
    if (!defined('DB_PASSWORD')) define('DB_PASSWORD', kasher_secret('db_password', 'DB_PASSWORD', 'levi12345'));
    if (!defined('DB_NAME'))     define('DB_NAME', 'nexoracore_db');
    if (!defined('DB_CHARSET'))  define('DB_CHARSET', 'utf8mb4');

    // -- kasher_platform --
    if (!defined('KASHER_PLATFORM_DB_HOST'))     define('KASHER_PLATFORM_DB_HOST', 'localhost');
    if (!defined('KASHER_PLATFORM_DB_USERNAME')) define('KASHER_PLATFORM_DB_USERNAME', kasher_secret('kasher_platform_db_username', 'KASHER_PLATFORM_DB_USERNAME', 'itsmelevi'));
    if (!defined('KASHER_PLATFORM_DB_PASSWORD')) define('KASHER_PLATFORM_DB_PASSWORD', kasher_secret('kasher_platform_db_password', 'KASHER_PLATFORM_DB_PASSWORD', 'levi12345'));
    if (!defined('KASHER_PLATFORM_DB_NAME'))     define('KASHER_PLATFORM_DB_NAME', 'nexoracore_db');
    if (!defined('KASHER_PLATFORM_DB_CHARSET'))  define('KASHER_PLATFORM_DB_CHARSET', 'utf8mb4');

    // -- kasher_zanyari (kasher_z) --
    if (!defined('ZANYARI_DB_HOST'))     define('ZANYARI_DB_HOST', 'localhost');
    if (!defined('ZANYARI_DB_USERNAME')) define('ZANYARI_DB_USERNAME', kasher_secret('zanyari_db_username', 'ZANYARI_DB_USERNAME', 'itsmelevi'));
    if (!defined('ZANYARI_DB_PASSWORD')) define('ZANYARI_DB_PASSWORD', kasher_secret('zanyari_db_password', 'ZANYARI_DB_PASSWORD', 'levi12345'));
    if (!defined('ZANYARI_DB_NAME'))     define('ZANYARI_DB_NAME', 'nexoracore_db');
    if (!defined('ZANYARI_DB_CHARSET'))  define('ZANYARI_DB_CHARSET', 'utf8mb4');

    // -- kasher_logs --
    if (!defined('KASHER_LOGS_DB_HOST'))     define('KASHER_LOGS_DB_HOST', 'localhost');
    if (!defined('KASHER_LOGS_DB_USERNAME')) define('KASHER_LOGS_DB_USERNAME', kasher_secret('kasher_logs_db_username', 'KASHER_LOGS_DB_USERNAME', 'itsmelevi'));
    if (!defined('KASHER_LOGS_DB_PASSWORD')) define('KASHER_LOGS_DB_PASSWORD', kasher_secret('kasher_logs_db_password', 'KASHER_LOGS_DB_PASSWORD', 'levi12345'));
    if (!defined('KASHER_LOGS_DB_NAME'))     define('KASHER_LOGS_DB_NAME', 'nexoracore_db');
    if (!defined('KASHER_LOGS_DB_CHARSET'))  define('KASHER_LOGS_DB_CHARSET', 'utf8mb4');

    // -- kasher_media (وێنەکان) --
    if (!defined('IMAGES_DB_HOST'))     define('IMAGES_DB_HOST', 'localhost');
    if (!defined('IMAGES_DB_USERNAME')) define('IMAGES_DB_USERNAME', kasher_secret('media_db_username', 'IMAGES_DB_USERNAME', 'itsmelevi'));
    if (!defined('IMAGES_DB_PASSWORD')) define('IMAGES_DB_PASSWORD', kasher_secret('media_db_password', 'IMAGES_DB_PASSWORD', 'levi12345'));
    if (!defined('IMAGES_DB_NAME'))     define('IMAGES_DB_NAME', 'nexoracore_db');
    if (!defined('IMAGES_DB_CHARSET'))  define('IMAGES_DB_CHARSET', 'utf8mb4');

    // -- kasher_media (ڤیدیۆکان — هەمان داتابەیسی media) --
    if (!defined('VIDEOS_DB_HOST'))     define('VIDEOS_DB_HOST', 'localhost');
    if (!defined('VIDEOS_DB_USERNAME')) define('VIDEOS_DB_USERNAME', kasher_secret('media_db_username', 'VIDEOS_DB_USERNAME', 'itsmelevi'));
    if (!defined('VIDEOS_DB_PASSWORD')) define('VIDEOS_DB_PASSWORD', kasher_secret('media_db_password', 'VIDEOS_DB_PASSWORD', 'levi12345'));
    if (!defined('VIDEOS_DB_NAME'))     define('VIDEOS_DB_NAME', 'nexoracore_db');
    if (!defined('VIDEOS_DB_CHARSET'))  define('VIDEOS_DB_CHARSET', 'utf8mb4');
}

/* ---------------------------------------------------------------------------
 * ٤) URL ـی سایت — لۆکاڵ خۆکارانە، سێرڤەر domain ـی ڕاستەقینە
 * ------------------------------------------------------------------------- */
if (!defined('SITE_URL')) {
    if (KASHER_IS_LOCAL) {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // دۆزینەوەی base path خۆکارانە بەپێی شوێنی ئەپەکە لەناو docroot
        $base = '';
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docroot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
            $approot = str_replace('\\', '/', dirname(__DIR__)); // ڕەگی ئەپەکە (config ـی باوان)
            if ($docroot !== '' && stripos($approot, $docroot) === 0) {
                $base = substr($approot, strlen($docroot));
            }
        }
        if ($base === '' || $base === false) {
            $base = '/systam/Kasher'; // fallback بۆ CLI / دۆخی نەزانراو
        }

        define('SITE_URL', $scheme . '://' . $host . '/' . trim($base, '/') . '/');
    } else {
        define('SITE_URL', 'https://kasheryai.com/');
    }
}
