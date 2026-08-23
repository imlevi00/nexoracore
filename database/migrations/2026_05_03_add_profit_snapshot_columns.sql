-- Profit snapshot columns migration (idempotent)
-- Adds:
--   sale_items.unit_cost_at_sale
--   return_items.unit_cost_at_return

SET @db_name := DATABASE();

SET @sale_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'sale_items'
      AND COLUMN_NAME = 'unit_cost_at_sale'
);

SET @sale_sql := IF(
    @sale_col_exists = 0,
    'ALTER TABLE `sale_items` ADD COLUMN `unit_cost_at_sale` DECIMAL(10,3) NULL AFTER `total_price`',
    'SELECT ''sale_items.unit_cost_at_sale already exists'' AS info'
);

PREPARE sale_stmt FROM @sale_sql;
EXECUTE sale_stmt;
DEALLOCATE PREPARE sale_stmt;

SET @return_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'return_items'
      AND COLUMN_NAME = 'unit_cost_at_return'
);

SET @return_sql := IF(
    @return_col_exists = 0,
    'ALTER TABLE `return_items` ADD COLUMN `unit_cost_at_return` DECIMAL(10,3) NULL AFTER `total_price`',
    'SELECT ''return_items.unit_cost_at_return already exists'' AS info'
);

PREPARE return_stmt FROM @return_sql;
EXECUTE return_stmt;
DEALLOCATE PREPARE return_stmt;
