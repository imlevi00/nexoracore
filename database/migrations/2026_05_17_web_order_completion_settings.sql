-- Web order completion settings (idempotent)
-- Adds website_settings columns for complete-order modal defaults

SET @db_name := DATABASE();

SET @col_customer_required_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'website_settings'
      AND COLUMN_NAME = 'order_complete_customer_required'
);

SET @sql_customer_required := IF(
    @col_customer_required_exists = 0,
    'ALTER TABLE `website_settings` ADD COLUMN `order_complete_customer_required` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1=کڕیار مەرجە، 0=ئارەزوومەندانە''',
    'SELECT ''order_complete_customer_required already exists'' AS info'
);
PREPARE stmt_customer_required FROM @sql_customer_required;
EXECUTE stmt_customer_required;
DEALLOCATE PREPARE stmt_customer_required;

SET @col_default_payment_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'website_settings'
      AND COLUMN_NAME = 'order_complete_default_payment_credit'
);

SET @sql_default_payment := IF(
    @col_default_payment_exists = 0,
    'ALTER TABLE `website_settings` ADD COLUMN `order_complete_default_payment_credit` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1=بنەڕەت قەرز، 0=بنەڕەت نەخت''',
    'SELECT ''order_complete_default_payment_credit already exists'' AS info'
);
PREPARE stmt_default_payment FROM @sql_default_payment;
EXECUTE stmt_default_payment;
DEALLOCATE PREPARE stmt_default_payment;
