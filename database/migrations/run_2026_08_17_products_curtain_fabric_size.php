<?php
/**
 * Runner for 2026_08_17_products_curtain_fabric_size.sql (database: test_test / app DB).
 * Idempotent: only adds columns that don't already exist.
 * Usage:  php database/migrations/run_2026_08_17_products_curtain_fabric_size.php
 */
require dirname(__DIR__, 2) . '/config/database.php';

$db = new Database();
$conn = $db->connect();
if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "migration_fail: app DB connection unavailable\n");
    exit(1);
}

$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];

/** Add a column only if it is missing. */
function addColumnIfMissing(mysqli $conn, string $dbName, string $table, string $column, string $definition): void {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('sss', $dbName, $table, $column);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_row()[0]) > 0;
    $stmt->close();

    if ($exists) {
        echo "skip: {$table}.{$column} already exists\n";
        return;
    }
    if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}")) {
        fwrite(STDERR, "migration_fail ({$table}.{$column}): " . $conn->error . "\n");
        exit(1);
    }
    echo "added: {$table}.{$column}\n";
}

addColumnIfMissing($conn, $dbName, 'products', 'fabric_width',
    "`fabric_width` DECIMAL(10,3) NULL DEFAULT NULL COMMENT 'پانی قوماش' AFTER `image_path`");

addColumnIfMissing($conn, $dbName, 'products', 'fabric_height',
    "`fabric_height` DECIMAL(10,3) NULL DEFAULT NULL COMMENT 'بەرزی قوماش' AFTER `fabric_width`");

addColumnIfMissing($conn, $dbName, 'products', 'fabric_measure_unit',
    "`fabric_measure_unit` ENUM('cm','m') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cm' COMMENT 'یەکەی پێوانە (سم یان مەتر)' AFTER `fabric_height`");

echo "migration_ok ({$dbName})\n";
