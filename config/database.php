<?php
// config/database.php - ڕێکخستنی بنکەی زانیاریەکان

// دیاریکردنی ژینگە (لۆکاڵ/سێرڤەر) و کرێدێنشاڵەکان بە شێوەی ناوەندی
require_once __DIR__ . '/env.php';

// Fallback ـەکان — ئەگەر env.php بار نەبووبێت، نرخی سێرڤەر بەکاردەهێنرێت
if (!defined('DB_HOST'))     define('DB_HOST', 'localhost');
if (!defined('DB_USERNAME')) define('DB_USERNAME', 'itsmelevi');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', 'levi12345');
if (!defined('DB_NAME'))     define('DB_NAME', 'nexoracore_db');
if (!defined('DB_CHARSET'))  define('DB_CHARSET', 'utf8mb4');



class Database {
    private $host = DB_HOST;
    private $username = DB_USERNAME;
    private $password = DB_PASSWORD;
    private $database = DB_NAME;
    private $charset = DB_CHARSET;
    private $connection;
    
    /**
     * پەیوەندی بونەوەی داتابەیس
     */
    public function connect() {
        if ($this->connection !== null && !$this->connection->ping()) {
            $this->connection->close();
            $this->connection = null;
        }

        if ($this->connection === null) {
            try {
                $this->connection = new mysqli(
                    $this->host, 
                    $this->username, 
                    $this->password, 
                    $this->database
                );
                
                // چیکردنی هەڵە
                if ($this->connection->connect_error) {
                    throw new Exception("پەیوەندی نەکرا بە داتابەیس: " . $this->connection->connect_error);
                }
                
                // دانانی charset
                $this->connection->set_charset($this->charset);
                
                // دانانی timezone بۆ MySQL
                $this->connection->query("SET time_zone = '+03:00'");
                
            } catch (Throwable $e) {
                error_log("Database connection failed: " . $e->getMessage());
                die("هەڵەی پەیوەندی بە داتابەیس: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
        }
        
        return $this->connection;
    }
    
    /**
     * وەرگرتنی پەیوەندی
     */
    public function getConnection() {
        return $this->connect();
    }
    
    /**
     * داخستنی پەیوەندی
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
    }
    
    /**
     * پاککردنەوەی داتا بۆ ئاسایشی
     */
    public function escape($data) {
        return $this->connection->real_escape_string($data);
    }
    
    /**
     * ئەجراکردنی query
     */
    public function query($sql) {
        $result = $this->connection->query($sql);
        if (!$result) {
            error_log("SQL Error: " . $this->connection->error . " - Query: " . $sql);
            return false;
        }
        return $result;
    }
    
    /**
     * ئەجراکردنی prepared statement
     */
    public function prepare($sql) {
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            error_log("Prepare Error: " . $this->connection->error . " - Query: " . $sql);
            return false;
        }
        return $stmt;
    }
    
    /**
     * وەرگرتنی ID ی دوایین insert
     */
    public function lastInsertId() {
        return $this->connection->insert_id;
    }
    
    /**
     * وەرگرتنی ژمارەی affected rows
     */
    public function affectedRows() {
        return $this->connection->affected_rows;
    }
}

// دروستکردنی instance ی گشتی
$db = new Database();
$conn = $db->connect();

/**
 * فانکشنە یارمەتیدەرەکان بۆ داتابەیس
 */

/**
 * پاککردنەوەی input
 */
function cleanInput($data) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($data));
}

/**
 * ئەجراکردنی SELECT query
 */
function selectQuery($sql, $params = []) {
    global $conn;
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        
        $types = '';
        $values = [];
        
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $param;
        }
        
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        
        return $stmt->get_result();
    } else {
        return $conn->query($sql);
    }
}

/**
 * ئەجراکردنی INSERT, UPDATE, DELETE query
 */
function executeQuery($sql, $params = []) {
    global $conn;
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        
        $types = '';
        $values = [];
        
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $param;
        }
        
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        
        if ($result) {
            return $stmt->affected_rows;
        }
        
        return false;
    } else {
        return $conn->query($sql);
    }
}

/**
 * تاقیکردنەوەی پەیوەندی
 */
function testConnection() {
    global $conn;
    return $conn && $conn->ping();
}

?>