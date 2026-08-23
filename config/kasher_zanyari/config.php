<?php
/**
 * ڕێکخستنی داتابەیسە نوێیەکە بۆ زانیاری login ـی بەکارهێنەر
 * config/kasher_zanyari/config.php
 * ناوی داتابەیس و پاسۆرد دواتر دایدەنێین.
 */

require_once __DIR__ . '/../env.php';

if (!defined('ZANYARI_DB_HOST')) {
    define('ZANYARI_DB_HOST', 'localhost');
}
if (!defined('ZANYARI_DB_USERNAME')) {
    define('ZANYARI_DB_USERNAME', 'itsmelevi');
}
if (!defined('ZANYARI_DB_PASSWORD')) {
    define('ZANYARI_DB_PASSWORD', 'levi12345');
}
if (!defined('ZANYARI_DB_NAME')) {
    define('ZANYARI_DB_NAME', 'nexoracore_db');
}
if (!defined('ZANYARI_DB_CHARSET')) {
    define('ZANYARI_DB_CHARSET', 'utf8mb4');
}

