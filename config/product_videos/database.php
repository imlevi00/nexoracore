<?php
/**
 * پەیوەندی بە داتابەیسە نوێیەکە بۆ ڤیدیۆی کاڵاکان
 * config/product_videos/database.php
 */

require_once __DIR__ . '/config.php';

if (!defined('VIDEOS_DB_NAME') || VIDEOS_DB_NAME === '') {
    $conn_videos = null;
    $GLOBALS['conn_videos'] = null;
    return;
}

if (!class_exists('VideosDatabase')) {
    class VideosDatabase {
        private $host;
        private $username;
        private $password;
        private $database;
        private $charset;
        private $connection;

        public function __construct() {
            $this->host     = VIDEOS_DB_HOST;
            $this->username = VIDEOS_DB_USERNAME;
            $this->password = VIDEOS_DB_PASSWORD;
            $this->database = VIDEOS_DB_NAME;
            $this->charset  = VIDEOS_DB_CHARSET;
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
}

$videosDb   = new VideosDatabase();
$conn_videos = $videosDb->connect();
$GLOBALS['conn_videos'] = $conn_videos;
