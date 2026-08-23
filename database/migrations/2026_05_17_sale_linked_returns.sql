-- Sale-linked returns migration (idempotent)
-- Adds:
--   returns.sale_id
--   return_items.sale_item_id

SET @db_name := DATABASE();

SET @returns_sale_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'returns'
      AND COLUMN_NAME = 'sale_id'
);

SET @returns_sale_sql := IF(
    @returns_sale_col_exists = 0,
    'ALTER TABLE `returns` ADD COLUMN `sale_id` INT NULL DEFAULT NULL AFTER `customer_id`, ADD KEY `idx_returns_sale_id` (`sale_id`)',
    'SELECT ''returns.sale_id already exists'' AS info'
);

PREPARE returns_sale_stmt FROM @returns_sale_sql;
EXECUTE returns_sale_stmt;
DEALLOCATE PREPARE returns_sale_stmt;

SET @return_item_sale_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'return_items'
      AND COLUMN_NAME = 'sale_item_id'
);

SET @return_item_sale_sql := IF(
    @return_item_sale_col_exists = 0,
    'ALTER TABLE `return_items` ADD COLUMN `sale_item_id` INT NULL DEFAULT NULL AFTER `return_id`, ADD KEY `idx_return_items_sale_item_id` (`sale_item_id`)',
    'SELECT ''return_items.sale_item_id already exists'' AS info'
);

PREPARE return_item_sale_stmt FROM @return_item_sale_sql;
EXECUTE return_item_sale_stmt;
DEALLOCATE PREPARE return_item_sale_stmt;
