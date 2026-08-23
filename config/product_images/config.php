<?php
/**
 * ڕێکخستنی داتابەیسە نوێیەکە بۆ وێنەکانی کاڵاکان (لۆگۆی بەکارهێنەران)
 * config/product_images/config.php
 * ناوی داتابەیس و پاسۆرد دواتر دایدەنێین.
 */

require_once __DIR__ . '/../env.php';

if (!defined('IMAGES_DB_HOST')) {
    define('IMAGES_DB_HOST', 'localhost');
}
if (!defined('IMAGES_DB_USERNAME')) {
    define('IMAGES_DB_USERNAME', 'itsmelevi');
}
if (!defined('IMAGES_DB_PASSWORD')) {
    define('IMAGES_DB_PASSWORD', 'levi12345');
}
if (!defined('IMAGES_DB_NAME')) {
    define('IMAGES_DB_NAME', 'nexoracore_db');
}
if (!defined('IMAGES_DB_CHARSET')) {
    define('IMAGES_DB_CHARSET', 'utf8mb4');
}
