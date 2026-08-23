-- Lab module — doctor-selected rows (parameters) per ordered test.
-- Database: kasher_platform
--
-- When a doctor orders a test they may restrict it to specific rows
-- (parameters/"خانە"). A row here means one lab_test_rows entry.
-- Semantics: if an order_test has NO entries in this table, the whole test
-- applies (every row) — backward compatible with existing orders. If it has
-- entries, only those rows are shown/filled in the lab, doctor view & receipt.
-- No FK to lab_test_rows so catalog edits do not block historical orders.

CREATE TABLE IF NOT EXISTS `lab_order_test_rows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_test_id` BIGINT UNSIGNED NOT NULL,
  `row_id` BIGINT UNSIGNED NOT NULL,
  `row_name_snapshot` VARCHAR(190) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lab_order_test_row` (`order_test_id`, `row_id`),
  KEY `idx_lab_order_test_rows_order_test` (`order_test_id`),
  CONSTRAINT `fk_lab_order_test_rows_order_test`
    FOREIGN KEY (`order_test_id`) REFERENCES `lab_order_tests` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
