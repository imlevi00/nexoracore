<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/theme_bootstrap.php';
require_once __DIR__ . '/includes/zanyari_user_settings.php';
require_once __DIR__ . '/includes/profit_schema.php';
require_once __DIR__ . '/includes/item_profit_report_stats.php';
require_once __DIR__ . '/includes/reports_cache.php';
require_once __DIR__ . '/user/products/includes/custom_fields_helpers.php';

$database = new Database();
$conn = $database->connect();

$userId = 1;
$res = $conn->query("SELECT * FROM system_settings");
if ($res) {
    echo "<pre>";
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
    echo "</pre>";
} else {
    echo "Query failed: " . $conn->error;
}

