<?php
/**
 * پەیوەندی بە داتابەیسی kasher_logs
 */

require_once __DIR__ . '/config.php';

if (!defined('KASHER_LOGS_DB_NAME') || KASHER_LOGS_DB_NAME === '') {
    $conn_kasher_logs = null;
    $GLOBALS['conn_kasher_logs'] = null;
    return;
}

if (!class_exists('KasherLogsDatabase')) {
    class KasherLogsDatabase {
        private $host;
        private $username;
        private $password;
        private $database;
        private $charset;
        private $connection;

        public function __construct() {
            $this->host = KASHER_LOGS_DB_HOST;
            $this->username = KASHER_LOGS_DB_USERNAME;
            $this->password = KASHER_LOGS_DB_PASSWORD;
            $this->database = KASHER_LOGS_DB_NAME;
            $this->charset = KASHER_LOGS_DB_CHARSET;
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

$kasherLogsDb = new KasherLogsDatabase();
$conn_kasher_logs = $kasherLogsDb->connect();
$GLOBALS['conn_kasher_logs'] = $conn_kasher_logs;
