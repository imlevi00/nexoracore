<?php
/**
 * پەیوەندی بە داتابەیسی kasher_platform
 */

require_once __DIR__ . '/config.php';

if (!defined('KASHER_PLATFORM_DB_NAME') || KASHER_PLATFORM_DB_NAME === '') {
    $conn_kasher_platform = null;
    $GLOBALS['conn_kasher_platform'] = null;
    return;
}

if (!class_exists('KasherPlatformDatabase')) {
    class KasherPlatformDatabase {
        private $host;
        private $username;
        private $password;
        private $database;
        private $charset;
        private $connection;

        public function __construct() {
            $this->host = KASHER_PLATFORM_DB_HOST;
            $this->username = KASHER_PLATFORM_DB_USERNAME;
            $this->password = KASHER_PLATFORM_DB_PASSWORD;
            $this->database = KASHER_PLATFORM_DB_NAME;
            $this->charset = KASHER_PLATFORM_DB_CHARSET;
        }

        public function connect() {
            if ($this->connection === null) {
                $this->connection = new mysqli(
                    $this->host,
                    $this->username,
                    $this->password,
                    $this->database
                );

                if ($this->connection->connect_error) {
                    return null;
                }

                $this->connection->set_charset($this->charset);
            }

            return $this->connection;
        }
    }
}

$kasherPlatformDb = new KasherPlatformDatabase();
$conn_kasher_platform = $kasherPlatformDb->connect();
$GLOBALS['conn_kasher_platform'] = $conn_kasher_platform;
