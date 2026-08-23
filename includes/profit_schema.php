<?php
/**
 * Profit schema guards for snapshot cost columns.
 */

if (!function_exists('ensureProfitSnapshotColumns')) {
    /**
     * Additive, idempotent migration for sale/return snapshot unit costs.
     *
     * @param mysqli $conn
     * @return void
     */
    function ensureProfitSnapshotColumns($conn) {
        if (!($conn instanceof mysqli)) {
            return;
        }

        try {
            $tableCheck = $conn->query("SHOW TABLES LIKE 'sale_items'");
            if ($tableCheck && $tableCheck->num_rows > 0) {
                $columnCheck = $conn->query("SHOW COLUMNS FROM sale_items LIKE 'unit_cost_at_sale'");
                if (!$columnCheck || $columnCheck->num_rows === 0) {
                    $conn->query("ALTER TABLE `sale_items` ADD COLUMN `unit_cost_at_sale` DECIMAL(10,3) NULL AFTER `total_price`");
                }
                if ($columnCheck) {
                    $columnCheck->free();
                }
            }
            if ($tableCheck) {
                $tableCheck->free();
            }

            $tableCheck = $conn->query("SHOW TABLES LIKE 'return_items'");
            if ($tableCheck && $tableCheck->num_rows > 0) {
                $columnCheck = $conn->query("SHOW COLUMNS FROM return_items LIKE 'unit_cost_at_return'");
                if (!$columnCheck || $columnCheck->num_rows === 0) {
                    $conn->query("ALTER TABLE `return_items` ADD COLUMN `unit_cost_at_return` DECIMAL(10,3) NULL AFTER `total_price`");
                }
                if ($columnCheck) {
                    $columnCheck->free();
                }
            }
            if ($tableCheck) {
                $tableCheck->free();
            }
        } catch (Throwable $e) {
            // Schema migration failed silently — table may not exist yet
        }
    }
}
