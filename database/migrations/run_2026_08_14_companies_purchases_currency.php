<?php
/**
 * Runner for 2026_08_14_companies_purchases_currency.sql (database: test_test / app DB).
 * Idempotent: only adds columns that don't already exist.
 * Usage:  php database/migrations/run_2026_08_14_companies_purchases_currency.php
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

addColumnIfMissing($conn, $dbName, 'companies', 'debt_amount_usd',
    "`debt_amount_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `debt_amount`");

addColumnIfMissing($conn, $dbName, 'company_debts', 'currency',
    "`currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `amount`");

addColumnIfMissing($conn, $dbName, 'purchase_receipts', 'currency',
    "`currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `final_amount`");

echo "migration_ok ({$dbName})\n";
