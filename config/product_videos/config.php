<?php
/**
 * ڕێکخستنی داتابەیسە نوێیەکە بۆ ڤیدیۆی کاڵاکان
 * config/product_videos/config.php
 * ناوی داتابەیس و پاسۆرد دواتر دایدەنێین.
 */

require_once __DIR__ . '/../env.php';

if (!defined('VIDEOS_DB_HOST')) {
    define('VIDEOS_DB_HOST', 'localhost');
}
if (!defined('VIDEOS_DB_USERNAME')) {
    define('VIDEOS_DB_USERNAME', 'itsmelevi');
}
if (!defined('VIDEOS_DB_PASSWORD')) {
    define('VIDEOS_DB_PASSWORD', 'levi12345');
}
if (!defined('VIDEOS_DB_NAME')) {
    define('VIDEOS_DB_NAME', 'nexoracore_db');
}
if (!defined('VIDEOS_DB_CHARSET')) {
    define('VIDEOS_DB_CHARSET', 'utf8mb4');
}
