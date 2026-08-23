-- Wallets module migration (idempotent)
-- Adds wallets ledger tables and wallet_id references.

SET @db_name := DATABASE();

SET @wallets_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'wallets'
);

SET @wallets_sql := IF(
    @wallets_table_exists = 0,
    'CREATE TABLE `wallets` (
        `id` int NOT NULL AUTO_INCREMENT,
        `user_id` int NOT NULL,
        `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        `is_active` tinyint(1) NOT NULL DEFAULT ''1'',
        `is_default` tinyint(1) NOT NULL DEFAULT ''0'',
        `balance_iqd` decimal(14,3) NOT NULL DEFAULT ''0.000'',
        `balance_usd` decimal(14,3) NOT NULL DEFAULT ''0.000'',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_wallets_user` (`user_id`),
        KEY `idx_wallets_default` (`user_id`,`is_default`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''wallets table already exists'' AS info'
);

PREPARE wallets_stmt FROM @wallets_sql;
EXECUTE wallets_stmt;
DEALLOCATE PREPARE wallets_stmt;

SET @wallet_tx_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'wallet_transactions'
);

SET @wallet_tx_sql := IF(
    @wallet_tx_table_exists = 0,
    'CREATE TABLE `wallet_transactions` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int NOT NULL,
        `wallet_id` int NOT NULL,
        `tx_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        `direction` enum(''in'',''out'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        `currency` enum(''IQD'',''USD'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''IQD'',
        `amount` decimal(14,3) NOT NULL,
        `reference_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `reference_id` bigint DEFAULT NULL,
        `related_wallet_id` int DEFAULT NULL,
        `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        `created_by` int DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_wallet_tx_user_wallet` (`user_id`,`wallet_id`),
        KEY `idx_wallet_tx_reference` (`reference_type`,`reference_id`),
        KEY `idx_wallet_tx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''wallet_transactions table already exists'' AS info'
);

PREPARE wallet_tx_stmt FROM @wallet_tx_sql;
EXECUTE wallet_tx_stmt;
DEALLOCATE PREPARE wallet_tx_stmt;

SET @sales_wallet_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'sales'
      AND COLUMN_NAME = 'wallet_id'
);
SET @sales_wallet_sql := IF(
    @sales_wallet_col_exists = 0,
    'ALTER TABLE `sales` ADD COLUMN `wallet_id` int DEFAULT NULL AFTER `payment_method`',
    'SELECT ''sales.wallet_id already exists'' AS info'
);
PREPARE sales_wallet_stmt FROM @sales_wallet_sql;
EXECUTE sales_wallet_stmt;
DEALLOCATE PREPARE sales_wallet_stmt;

SET @expenses_wallet_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'expenses'
      AND COLUMN_NAME = 'wallet_id'
);
SET @expenses_wallet_sql := IF(
    @expenses_wallet_col_exists = 0,
    'ALTER TABLE `expenses` ADD COLUMN `wallet_id` int DEFAULT NULL AFTER `payment_method`',
    'SELECT ''expenses.wallet_id already exists'' AS info'
);
PREPARE expenses_wallet_stmt FROM @expenses_wallet_sql;
EXECUTE expenses_wallet_stmt;
DEALLOCATE PREPARE expenses_wallet_stmt;

SET @returns_wallet_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'returns'
      AND COLUMN_NAME = 'wallet_id'
);
SET @returns_wallet_sql := IF(
    @returns_wallet_col_exists = 0,
    'ALTER TABLE `returns` ADD COLUMN `wallet_id` int DEFAULT NULL AFTER `payment_method`',
    'SELECT ''returns.wallet_id already exists'' AS info'
);
PREPARE returns_wallet_stmt FROM @returns_wallet_sql;
EXECUTE returns_wallet_stmt;
DEALLOCATE PREPARE returns_wallet_stmt;

SET @purchase_wallet_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'purchase_receipts'
      AND COLUMN_NAME = 'wallet_id'
);
SET @purchase_wallet_sql := IF(
    @purchase_wallet_col_exists = 0,
    'ALTER TABLE `purchase_receipts` ADD COLUMN `wallet_id` int DEFAULT NULL AFTER `payment_type`',
    'SELECT ''purchase_receipts.wallet_id already exists'' AS info'
);
PREPARE purchase_wallet_stmt FROM @purchase_wallet_sql;
EXECUTE purchase_wallet_stmt;
DEALLOCATE PREPARE purchase_wallet_stmt;

SET @expense_credit_payment_wallet_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'expense_credit_payments'
      AND COLUMN_NAME = 'wallet_id'
);
SET @expense_credit_payment_wallet_sql := IF(
    @expense_credit_payment_wallet_col_exists = 0,
    'ALTER TABLE `expense_credit_payments` ADD COLUMN `wallet_id` int DEFAULT NULL AFTER `payment_method`',
    'SELECT ''expense_credit_payments.wallet_id already exists'' AS info'
);
PREPARE expense_credit_payment_wallet_stmt FROM @expense_credit_payment_wallet_sql;
EXECUTE expense_credit_payment_wallet_stmt;
DEALLOCATE PREPARE expense_credit_payment_wallet_stmt;
