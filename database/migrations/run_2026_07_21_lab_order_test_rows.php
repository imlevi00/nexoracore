<?php
/**
 * Runner for 2026_07_21_lab_order_test_rows.sql (database: kasher_platform).
 * Usage:  php database/migrations/run_2026_07_21_lab_order_test_rows.php
 */
require dirname(__DIR__, 2) . '/config/kasher_platform/database.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    fwrite(STDERR, "migration_fail: kasher_platform connection unavailable\n");
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/2026_07_21_lab_order_test_rows.sql');

if ($conn_kasher_platform->multi_query($sql)) {
    do {
        if ($result = $conn_kasher_platform->store_result()) {
            $result->free();
        }
    } while ($conn_kasher_platform->more_results() && $conn_kasher_platform->next_result());
    echo "migration_ok\n";
} else {
    fwrite(STDERR, 'migration_fail: ' . $conn_kasher_platform->error . "\n");
    exit(1);
}
