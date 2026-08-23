-- Age-specific (and optionally gender-specific) normal ranges for lab test rows.
-- Each row (analyte) may have zero or more brackets. When a row has brackets,
-- they take priority over the flat / gender ranges on lab_test_rows for both the
-- reference display and result flagging (matched by the patient's age).
-- Database: kasher_platform

CREATE TABLE IF NOT EXISTS `lab_test_row_ranges` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `row_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `label` VARCHAR(120) NULL DEFAULT NULL,
  `gender` ENUM('any', 'male', 'female') NOT NULL DEFAULT 'any',
  `age_min_value` DECIMAL(7,2) NULL DEFAULT NULL,
  `age_min_unit` ENUM('day', 'month', 'year') NULL DEFAULT NULL,
  `age_max_value` DECIMAL(7,2) NULL DEFAULT NULL,
  `age_max_unit` ENUM('day', 'month', 'year') NULL DEFAULT NULL,
  `normal_min` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_max` DECIMAL(12,4) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lab_row_ranges_row` (`row_id`, `sort_order`),
  KEY `idx_lab_row_ranges_test` (`test_id`),
  CONSTRAINT `fk_lab_row_ranges_test`
    FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lab_row_ranges_row`
    FOREIGN KEY (`row_id`) REFERENCES `lab_test_rows` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
