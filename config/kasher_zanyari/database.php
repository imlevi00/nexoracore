<?php
/**
 * پەیوەندی بە داتابەیسە نوێیەکە بۆ زانیاری login ـی بەکارهێنەر
 * config/kasher_zanyari/database.php
 */

require_once __DIR__ . '/config.php';

if (!defined('ZANYARI_DB_NAME') || ZANYARI_DB_NAME === '') {
    $conn_zanyari = null;
    return;
}

class ZanyariDatabase {
    private $host;
    private $username;
    private $password;
    private $database;
    private $charset;
    private $connection;

    public function __construct() {
        $this->host     = ZANYARI_DB_HOST;
        $this->username = ZANYARI_DB_USERNAME;
        $this->password = ZANYARI_DB_PASSWORD;
        $this->database = ZANYARI_DB_NAME;
        $this->charset  = ZANYARI_DB_CHARSET;
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

    public function getConnection() {
        return $this->connect();
    }
}

$zanyariDb   = new ZanyariDatabase();
$conn_zanyari = $zanyariDb->connect();

