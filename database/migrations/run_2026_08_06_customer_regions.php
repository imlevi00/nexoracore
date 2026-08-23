<?php
/**
 * Runner for 2026_08_06_customer_regions.sql (database: test_test / app DB).
 * Idempotent: creates table and column only if missing.
 * Usage:  php database/migrations/run_2026_08_06_customer_regions.php
 */
require dirname(__DIR__, 2) . '/config/database.php';

$db = new Database();
$conn = $db->connect();
if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "migration_fail: app DB connection unavailable\n");
    exit(1);
}

$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];

function tableExists(mysqli $conn, string $dbName, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $stmt->bind_param('ss', $dbName, $table);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_row()[0]) > 0;
    $stmt->close();
    return $exists;
}

function addColumnIfMissing(mysqli $conn, string $dbName, string $table, string $column, string $definition): void
{
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

function addIndexIfMissing(mysqli $conn, string $dbName, string $table, string $index, string $sql): void
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->bind_param('sss', $dbName, $table, $index);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_row()[0]) > 0;
    $stmt->close();

    if ($exists) {
        echo "skip: {$table}.{$index} already exists\n";
        return;
    }
    if (!$conn->query($sql)) {
        fwrite(STDERR, "migration_fail ({$table}.{$index}): " . $conn->error . "\n");
        exit(1);
    }
    echo "added: {$table}.{$index}\n";
}

function addForeignKeyIfMissing(mysqli $conn, string $dbName, string $table, string $constraint, string $sql): void
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->bind_param('sss', $dbName, $table, $constraint);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_row()[0]) > 0;
    $stmt->close();

    if ($exists) {
        echo "skip: {$table}.{$constraint} already exists\n";
        return;
    }
    if (!$conn->query($sql)) {
        fwrite(STDERR, "migration_fail ({$table}.{$constraint}): " . $conn->error . "\n");
        exit(1);
    }
    echo "added: {$table}.{$constraint}\n";
}

if (!tableExists($conn, $dbName, 'customer_regions')) {
    $ok = $conn->query("
        CREATE TABLE `customer_regions` (
          `id` INT NOT NULL AUTO_INCREMENT,
          `user_id` INT NOT NULL,
          `name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_user_region_name` (`user_id`, `name`),
          KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!$ok) {
        fwrite(STDERR, "migration_fail (customer_regions): " . $conn->error . "\n");
        exit(1);
    }
    echo "added: customer_regions\n";
} else {
    echo "skip: customer_regions already exists\n";
}

addColumnIfMissing(
    $conn,
    $dbName,
    'customers',
    'region_id',
    "`region_id` INT NULL DEFAULT NULL COMMENT 'ناوچەی کڕیار' AFTER `address`"
);
addIndexIfMissing(
    $conn,
    $dbName,
    'customers',
    'idx_region_id',
    "ALTER TABLE `customers` ADD KEY `idx_region_id` (`region_id`)"
);
addForeignKeyIfMissing(
    $conn,
    $dbName,
    'customers',
    'fk_customers_region',
    "ALTER TABLE `customers`
     ADD CONSTRAINT `fk_customers_region`
     FOREIGN KEY (`region_id`) REFERENCES `customer_regions`(`id`)
     ON DELETE RESTRICT ON UPDATE CASCADE"
);

echo "migration_ok ({$dbName})\n";
