-- Add optional sale notes column (idempotent)

SET @db_name := DATABASE();

SET @sales_notes_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'sales'
      AND COLUMN_NAME = 'notes'
);
SET @sales_notes_sql := IF(
    @sales_notes_col_exists = 0,
    'ALTER TABLE `sales` ADD COLUMN `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `wallet_id`',
    'SELECT ''sales.notes already exists'' AS info'
);
PREPARE sales_notes_stmt FROM @sales_notes_sql;
EXECUTE sales_notes_stmt;
DEALLOCATE PREPARE sales_notes_stmt;
