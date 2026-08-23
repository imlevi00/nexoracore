<?php
/**
 * پەیوەندی بە داتابەیسی kasher_media بۆ وێنەکانی کاڵاکان (لۆگۆی بەکارهێنەران)
 * config/product_images/database.php
 */

require_once __DIR__ . '/config.php';

if (!defined('IMAGES_DB_NAME') || IMAGES_DB_NAME === '') {
    $conn_images = null;
    return;
}

class ImagesDatabase {
    private $host;
    private $username;
    private $password;
    private $database;
    private $charset;
    private $connection;

    public function __construct() {
        $this->host     = IMAGES_DB_HOST;
        $this->username = IMAGES_DB_USERNAME;
        $this->password = IMAGES_DB_PASSWORD;
        $this->database = IMAGES_DB_NAME;
        $this->charset  = IMAGES_DB_CHARSET;
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

$imagesDb   = new ImagesDatabase();
$conn_images = $imagesDb->connect();
