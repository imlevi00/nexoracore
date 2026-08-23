<?php
/**
 * ڕێکخستنی داتابەیسی kasher_platform
 */

require_once __DIR__ . '/../env.php';

if (!defined('KASHER_PLATFORM_DB_HOST')) {
    define('KASHER_PLATFORM_DB_HOST', 'localhost');
}
if (!defined('KASHER_PLATFORM_DB_USERNAME')) {
    define('KASHER_PLATFORM_DB_USERNAME', 'itsmelevi');
}
if (!defined('KASHER_PLATFORM_DB_PASSWORD')) {
    define('KASHER_PLATFORM_DB_PASSWORD', 'levi12345');
}
if (!defined('KASHER_PLATFORM_DB_NAME')) {
    define('KASHER_PLATFORM_DB_NAME', 'nexoracore_db');
}
if (!defined('KASHER_PLATFORM_DB_CHARSET')) {
    define('KASHER_PLATFORM_DB_CHARSET', 'utf8mb4');
}
