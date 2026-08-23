-- Lab module — Stage 2: doctor orders + filled results
-- Database: kasher_platform

-- A lab order placed by a doctor for a patient
CREATE TABLE IF NOT EXISTS `lab_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `lab_id` BIGINT UNSIGNED NOT NULL,
  `doctor_id` BIGINT UNSIGNED NOT NULL,
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('pending', 'sample_collected', 'completed') NOT NULL DEFAULT 'pending',
  `notes` VARCHAR(1000) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lab_orders_user_lab` (`user_id`, `lab_id`),
  KEY `idx_lab_orders_user_status` (`user_id`, `status`),
  KEY `idx_lab_orders_doctor` (`doctor_id`),
  KEY `idx_lab_orders_patient` (`patient_id`),
  CONSTRAINT `fk_lab_orders_lab`
    FOREIGN KEY (`lab_id`) REFERENCES `medical_center_labs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lab_orders_doctor`
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_lab_orders_patient`
    FOREIGN KEY (`patient_id`) REFERENCES `medical_center_patients` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The tests selected within an order (name snapshotted at order time)
CREATE TABLE IF NOT EXISTS `lab_order_tests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `test_name_snapshot` VARCHAR(190) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lab_order_tests_order` (`order_id`),
  KEY `idx_lab_order_tests_test` (`test_id`),
  CONSTRAINT `fk_lab_order_tests_order`
    FOREIGN KEY (`order_id`) REFERENCES `lab_orders` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The entered result value per (row, result-column), with computed flag.
-- No FK to rows/columns so catalog edits do not block historical orders.
CREATE TABLE IF NOT EXISTS `lab_order_results` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_test_id` BIGINT UNSIGNED NOT NULL,
  `row_id` BIGINT UNSIGNED NOT NULL,
  `column_id` BIGINT UNSIGNED NOT NULL,
  `value` VARCHAR(255) NOT NULL DEFAULT '',
  `flag` ENUM('normal', 'high', 'low', 'abnormal') NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lab_result_cell` (`order_test_id`, `row_id`, `column_id`),
  CONSTRAINT `fk_lab_order_results_order_test`
    FOREIGN KEY (`order_test_id`) REFERENCES `lab_order_tests` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
