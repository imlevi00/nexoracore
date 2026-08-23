<?php
/**
 * config/secrets.php
 * ---------------------------------------------------------------------------
 * لۆدەری ناوەندی بۆ نهێنییەکان (پاسۆردی داتابەیس، توکنی بۆت، کلیلی OAuth ...).
 *
 * فایلی نهێنی (array) لەم شوێنانەوە دەخوێنرێتەوە — یەکەم شوێنی بەردەست بەکاردێت:
 *   ١) ڕێڕەوی فایل لە environment variable ـی KASHER_SECRETS_FILE
 *      (پێشنیارکراو بۆ سێرڤەر: فایلێک لە **دەرەوەی** فۆڵدەری پڕۆژە و docroot،
 *       بۆ نموونە /home/USER/private/secrets.local.php)
 *   ٢) config/secrets.local.php  (تەنیا بۆ ئاسانی لۆکاڵ — لە git تۆمار ناکرێت)
 *
 * نموونە/تێمپلەیت: config/secrets.example.php
 *
 * تەرتیبی وەرگرتنی نرخی هەر کلیلێک:
 *   ١) environment variable (ئەگەر ناوی env دراوە و نرخی هەیە)
 *   ٢) array ـی فایلی نهێنی
 *   ٣) نرخی default (بە بنەڕەت بەتاڵ)
 *
 * ڕێکخستنی سێرڤەر (نموونە، لە httpd.conf یان .htaccess ـی دەرەوەی docroot):
 *   SetEnv KASHER_SECRETS_FILE "/home/USER/private/secrets.local.php"
 *
 * بەکارهێنان لە کۆد:
 *   require_once __DIR__ . '/secrets.php';
 *   $pw = kasher_secret('db_password', 'DB_PASSWORD');
 * ---------------------------------------------------------------------------
 */

if (!function_exists('kasher_env')) {
    /**
     * خوێندنەوەی environment variable هەم لە getenv() و هەم لە $_SERVER
     * (بۆ گونجاندن لەگەڵ mod_php و PHP-FPM/FastCGI کە SetEnv تێیدا دەچێتە $_SERVER).
     */
    function kasher_env($name) {
        $v = getenv($name);
        if ($v !== false && $v !== '') {
            return $v;
        }
        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }
        return false;
    }
}

if (!function_exists('kasher_secret')) {
    /**
     * @param string $key     کلیلی ناو array ـی فایلی نهێنی
     * @param string $envName ناوی environment variable (ئیختیاری)
     * @param string $default نرخی fallback ئەگەر هیچیان نەبوون
     */
    function kasher_secret($key, $envName = '', $default = '') {
        static $secrets = null;

        if ($secrets === null) {
            $secrets = [];

            // ١) ڕێڕەوی فایل لە KASHER_SECRETS_FILE (دەرەوەی repo/docroot) — پێشینەی هەیە
            $file = '';
            $envFile = kasher_env('KASHER_SECRETS_FILE');
            if ($envFile !== false && is_readable($envFile)) {
                $file = $envFile;
            } elseif (is_readable(__DIR__ . '/secrets.local.php')) {
                // ٢) fallback ـی لۆکاڵ لەناو فۆڵدەری config
                $file = __DIR__ . '/secrets.local.php';
            }

            if ($file !== '') {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $secrets = $loaded;
                }
            }
        }

        if ($envName !== '') {
            $envValue = kasher_env($envName);
            if ($envValue !== false) {
                return (string) $envValue;
            }
        }

        if (isset($secrets[$key]) && $secrets[$key] !== '') {
            return (string) $secrets[$key];
        }

        return $default;
    }
}
