-- Lab module — Stage 1: test catalog (spreadsheet-like) + receipt template settings
-- Database: kasher_platform

-- A lab test / panel (فەحس)
CREATE TABLE IF NOT EXISTS `lab_tests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `lab_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `group_name` VARCHAR(150) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `show_on_receipt` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lab_tests_user_lab` (`user_id`, `lab_id`),
  KEY `idx_lab_tests_active` (`user_id`, `is_active`),
  CONSTRAINT `fk_lab_tests_lab`
    FOREIGN KEY (`lab_id`) REFERENCES `medical_center_labs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horizontal columns of a test's result table
CREATE TABLE IF NOT EXISTS `lab_test_columns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(150) NOT NULL,
  `col_type` ENUM('result', 'reference', 'unit', 'text', 'status') NOT NULL DEFAULT 'text',
  `sort_order` INT NOT NULL DEFAULT 0,
  `width` VARCHAR(20) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lab_columns_test` (`test_id`, `sort_order`),
  CONSTRAINT `fk_lab_columns_test`
    FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vertical rows (analytes / parameters) of a test, holding the normal range
CREATE TABLE IF NOT EXISTS `lab_test_rows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `result_type` ENUM('numeric', 'text') NOT NULL DEFAULT 'numeric',
  `normal_min` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_max` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_text` VARCHAR(190) NULL DEFAULT NULL,
  `unit` VARCHAR(60) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lab_rows_test` (`test_id`, `sort_order`),
  CONSTRAINT `fk_lab_rows_test`
    FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Static default content for non-result cells (reference / unit / text columns)
CREATE TABLE IF NOT EXISTS `lab_test_cells` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `row_id` BIGINT UNSIGNED NOT NULL,
  `column_id` BIGINT UNSIGNED NOT NULL,
  `content` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lab_cell` (`row_id`, `column_id`),
  KEY `idx_lab_cells_test` (`test_id`),
  CONSTRAINT `fk_lab_cells_test`
    FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lab_cells_row`
    FOREIGN KEY (`row_id`) REFERENCES `lab_test_rows` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lab_cells_column`
    FOREIGN KEY (`column_id`) REFERENCES `lab_test_columns` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receipt template settings (one row per lab)
CREATE TABLE IF NOT EXISTS `lab_receipt_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `lab_id` BIGINT UNSIGNED NOT NULL,
  `banner_url` VARCHAR(500) NULL DEFAULT NULL,
  `stamp_url` VARCHAR(500) NULL DEFAULT NULL,
  `header_text` VARCHAR(1000) NULL DEFAULT NULL,
  `footer_text` VARCHAR(1000) NULL DEFAULT NULL,
  `info_fields` JSON NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lab_receipt_settings_lab` (`lab_id`),
  KEY `idx_lab_receipt_settings_user` (`user_id`),
  CONSTRAINT `fk_lab_receipt_settings_lab`
    FOREIGN KEY (`lab_id`) REFERENCES `medical_center_labs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
