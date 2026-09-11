<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
$database = new Database();
$conn = $database->connect();
$res = $conn->query("DESCRIBE sub_users");
while($row = $res->fetch_assoc()) {
    print_r($row);
}

