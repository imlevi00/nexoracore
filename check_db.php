<?php
require 'config/config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$res = $conn->query("DESCRIBE sale_items");
$cols = [];
while($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'];
}
echo implode(", ", $cols);

