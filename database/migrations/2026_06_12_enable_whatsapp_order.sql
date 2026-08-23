-- WhatsApp order from cart sidebar (idempotent)
-- Adds website_settings column for cart WhatsApp order button

SET @db_name := DATABASE();

SET @col_enable_whatsapp_order_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'website_settings'
      AND COLUMN_NAME = 'enable_whatsapp_order'
);

SET @sql_enable_whatsapp_order := IF(
    @col_enable_whatsapp_order_exists = 0,
    'ALTER TABLE `website_settings` ADD COLUMN `enable_whatsapp_order` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1=دوگمەی واتسئاپ لە سەبەتەی کڕین'' AFTER `show_shop_exit_button`',
    'SELECT ''enable_whatsapp_order already exists'' AS info'
);
PREPARE stmt_enable_whatsapp_order FROM @sql_enable_whatsapp_order;
EXECUTE stmt_enable_whatsapp_order;
DEALLOCATE PREPARE stmt_enable_whatsapp_order;
