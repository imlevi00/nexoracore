-- Product custom fields: select type, sections, nested options (idempotent)

SET @db_name := DATABASE();

-- Sections table
SET @sections_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'product_custom_field_sections'
);

SET @sections_sql := IF(
    @sections_table_exists = 0,
    'CREATE TABLE `product_custom_field_sections` (
        `id` int NOT NULL AUTO_INCREMENT,
        `user_id` int NOT NULL,
        `section_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        `section_order` int NOT NULL DEFAULT ''0'',
        `is_active` tinyint(1) NOT NULL DEFAULT ''1'',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_pcf_sections_user_active_order` (`user_id`,`is_active`,`section_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''product_custom_field_sections table already exists'' AS info'
);

PREPARE sections_stmt FROM @sections_sql;
EXECUTE sections_stmt;
DEALLOCATE PREPARE sections_stmt;

-- section_id on product_custom_fields
SET @pcf_section_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'product_custom_fields'
      AND COLUMN_NAME = 'section_id'
);

SET @pcf_section_sql := IF(
    @pcf_section_col_exists = 0,
    'ALTER TABLE `product_custom_fields`
        ADD COLUMN `section_id` int DEFAULT NULL AFTER `field_type`,
        ADD KEY `idx_product_custom_fields_section` (`section_id`)',
    'SELECT ''product_custom_fields.section_id already exists'' AS info'
);

PREPARE pcf_section_stmt FROM @pcf_section_sql;
EXECUTE pcf_section_stmt;
DEALLOCATE PREPARE pcf_section_stmt;

-- FK for section_id (if not exists)
SET @pcf_section_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'product_custom_fields'
      AND CONSTRAINT_NAME = 'fk_product_custom_fields_section'
);

SET @pcf_section_fk_sql := IF(
    @pcf_section_fk_exists = 0 AND @pcf_section_col_exists = 0,
    'ALTER TABLE `product_custom_fields`
        ADD CONSTRAINT `fk_product_custom_fields_section`
        FOREIGN KEY (`section_id`) REFERENCES `product_custom_field_sections` (`id`) ON DELETE SET NULL',
    'SELECT ''fk_product_custom_fields_section already exists or column missing'' AS info'
);

PREPARE pcf_section_fk_stmt FROM @pcf_section_fk_sql;
EXECUTE pcf_section_fk_stmt;
DEALLOCATE PREPARE pcf_section_fk_stmt;

-- Options tree table
SET @options_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'product_custom_field_options'
);

SET @options_sql := IF(
    @options_table_exists = 0,
    'CREATE TABLE `product_custom_field_options` (
        `id` int NOT NULL AUTO_INCREMENT,
        `user_id` int NOT NULL,
        `field_id` int NOT NULL,
        `parent_id` int DEFAULT NULL,
        `option_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        `option_order` int NOT NULL DEFAULT ''0'',
        `is_active` tinyint(1) NOT NULL DEFAULT ''1'',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_pcf_options_field_parent_order` (`field_id`,`parent_id`,`is_active`,`option_order`),
        KEY `idx_pcf_options_user_field` (`user_id`,`field_id`),
        CONSTRAINT `fk_pcf_options_field` FOREIGN KEY (`field_id`) REFERENCES `product_custom_fields` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_pcf_options_parent` FOREIGN KEY (`parent_id`) REFERENCES `product_custom_field_options` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''product_custom_field_options table already exists'' AS info'
);

PREPARE options_stmt FROM @options_sql;
EXECUTE options_stmt;
DEALLOCATE PREPARE options_stmt;

-- Extend field_type enum with select
SET @pcf_type_has_select := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'product_custom_fields'
      AND COLUMN_NAME = 'field_type'
      AND COLUMN_TYPE LIKE '%select%'
);

SET @pcf_type_sql := IF(
    @pcf_type_has_select = 0,
    'ALTER TABLE `product_custom_fields`
        MODIFY COLUMN `field_type` enum(''text'',''number'',''select'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''text''',
    'SELECT ''product_custom_fields.field_type already includes select'' AS info'
);

PREPARE pcf_type_stmt FROM @pcf_type_sql;
EXECUTE pcf_type_stmt;
DEALLOCATE PREPARE pcf_type_stmt;
