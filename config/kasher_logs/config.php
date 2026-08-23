<?php
/**
 * ڕێکخستنی داتابەیسی kasher_logs
 */

require_once __DIR__ . '/../env.php';

if (!defined('KASHER_LOGS_DB_HOST')) {
    define('KASHER_LOGS_DB_HOST', 'localhost');
}
if (!defined('KASHER_LOGS_DB_USERNAME')) {
    define('KASHER_LOGS_DB_USERNAME', 'itsmelevi');
}
if (!defined('KASHER_LOGS_DB_PASSWORD')) {
    define('KASHER_LOGS_DB_PASSWORD', 'levi12345');
}
if (!defined('KASHER_LOGS_DB_NAME')) {
    define('KASHER_LOGS_DB_NAME', 'nexoracore_db');
}
if (!defined('KASHER_LOGS_DB_CHARSET')) {
    define('KASHER_LOGS_DB_CHARSET', 'utf8mb4');
}
