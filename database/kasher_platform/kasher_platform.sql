

CREATE TABLE IF NOT EXISTS `drug_interactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `product_id_1` INT NOT NULL,
  `product_id_2` INT NOT NULL,
  `risk_level` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `note` VARCHAR(1000) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_pair` (`user_id`, `product_id_1`, `product_id_2`),
  KEY `idx_user_product1` (`user_id`, `product_id_1`),
  KEY `idx_user_product2` (`user_id`, `product_id_2`),
  KEY `idx_user_risk` (`user_id`, `risk_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doctors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `mobile` VARCHAR(30) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doctors_user_email` (`user_id`, `email`),
  KEY `idx_doctors_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doctor_secretaries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `doctor_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `mobile` VARCHAR(30) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_secretaries_user_email` (`user_id`, `email`),
  KEY `idx_secretaries_user` (`user_id`),
  KEY `idx_secretaries_doctor` (`doctor_id`),
  CONSTRAINT `fk_secretaries_doctor`
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doctor_secretary_doctors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `secretary_id` BIGINT UNSIGNED NOT NULL,
  `doctor_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_secretary_doctor` (`secretary_id`, `doctor_id`),
  KEY `idx_secretary_doctors_user` (`user_id`),
  KEY `idx_secretary_doctors_doctor` (`doctor_id`),
  CONSTRAINT `fk_secretary_doctors_secretary`
    FOREIGN KEY (`secretary_id`) REFERENCES `doctor_secretaries` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_secretary_doctors_doctor`
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medical_center_patients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `doctor_id` BIGINT UNSIGNED NOT NULL,
  `created_by_secretary_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `mobile` VARCHAR(30) NOT NULL,
  `age` SMALLINT UNSIGNED NOT NULL,
  `age_months` TINYINT UNSIGNED NULL DEFAULT NULL,
  `gender` ENUM('male', 'female') NULL DEFAULT NULL,
  `profession` VARCHAR(100) NULL DEFAULT NULL,
  `blood_type` VARCHAR(10) NULL DEFAULT NULL,
  `address` VARCHAR(255) NULL DEFAULT NULL,
  `visit_status` ENUM('waiting', 'with_doctor', 'completed') NOT NULL DEFAULT 'waiting',
  `visit_status_updated_at` DATETIME NULL DEFAULT NULL,
  `appointment_date` DATE NULL DEFAULT NULL,
  `appointment_time` TIME NULL DEFAULT NULL,
  `appointment_end_time` TIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patients_user_secretary` (`user_id`, `created_by_secretary_id`),
  KEY `idx_patients_user_doctor` (`user_id`, `doctor_id`),
  KEY `idx_patients_doctor_appointment` (`user_id`, `doctor_id`, `appointment_date`, `appointment_time`),
  CONSTRAINT `fk_medical_patients_doctor`
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_medical_patients_secretary`
    FOREIGN KEY (`created_by_secretary_id`) REFERENCES `doctor_secretaries` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medical_prescriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `doctor_id` BIGINT UNSIGNED NOT NULL,
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `history` TEXT NULL DEFAULT NULL,
  `examination` TEXT NULL DEFAULT NULL,
  `diagnosis` VARCHAR(1000) NOT NULL,
  `status` ENUM('draft', 'pending', 'completed', 'consultation') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prescriptions_user_doctor` (`user_id`, `doctor_id`),
  KEY `idx_prescriptions_user_patient` (`user_id`, `patient_id`),
  KEY `idx_prescriptions_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_prescriptions_doctor`
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_prescriptions_patient`
    FOREIGN KEY (`patient_id`) REFERENCES `medical_center_patients` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medical_prescription_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `prescription_id` BIGINT UNSIGNED NOT NULL,
  `product_id` INT NOT NULL,
  `product_name_snapshot` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1,
  `product_unit_id` INT NULL DEFAULT NULL,
  `unit_name_snapshot` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prescription_items_prescription` (`prescription_id`),
  KEY `idx_prescription_items_product` (`product_id`),
  CONSTRAINT `fk_prescription_items_header`
    FOREIGN KEY (`prescription_id`) REFERENCES `medical_prescriptions` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medical_prescription_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `prescription_id` BIGINT UNSIGNED NOT NULL,
  `section` ENUM('history', 'examination', 'diagnosis') NOT NULL,
  `object_key` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NULL DEFAULT NULL,
  `mime` VARCHAR(100) NULL DEFAULT NULL,
  `file_size` INT UNSIGNED NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rx_attachments_rx_section` (`prescription_id`, `section`, `sort_order`),
  CONSTRAINT `fk_rx_attachments_prescription`
    FOREIGN KEY (`prescription_id`) REFERENCES `medical_prescriptions` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medical_referrals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `from_doctor_id` BIGINT UNSIGNED NOT NULL,
  `to_doctor_id` BIGINT UNSIGNED NOT NULL,
  `note` VARCHAR(1000) NULL DEFAULT NULL,
  `status` ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_referrals_to` (`user_id`, `to_doctor_id`, `status`),
  KEY `idx_referrals_from` (`user_id`, `from_doctor_id`),
  KEY `idx_referrals_patient` (`patient_id`),
  CONSTRAINT `fk_referrals_patient`
    FOREIGN KEY (`patient_id`) REFERENCES `medical_center_patients` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_referrals_from_doctor`
    FOREIGN KEY (`from_doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_referrals_to_doctor`
    FOREIGN KEY (`to_doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medical_center_labs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `mobile` VARCHAR(30) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_labs_user` (`user_id`),
  UNIQUE KEY `uniq_labs_user_email` (`user_id`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lab module — Stage 1: test catalog (spreadsheet-like) + receipt template settings
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

CREATE TABLE IF NOT EXISTS `lab_test_columns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(150) NOT NULL,
  `col_type` ENUM('result', 'reference', 'unit', 'text', 'status', 'choice') NOT NULL DEFAULT 'text',
  `sort_order` INT NOT NULL DEFAULT 0,
  `width` VARCHAR(20) NULL DEFAULT NULL,
  `options` JSON NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lab_columns_test` (`test_id`, `sort_order`),
  CONSTRAINT `fk_lab_columns_test`
    FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lab_test_rows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `result_type` ENUM('numeric', 'text') NOT NULL DEFAULT 'numeric',
  `normal_min` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_max` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_min_male` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_max_male` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_min_female` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_max_female` DECIMAL(12,4) NULL DEFAULT NULL,
  `normal_text` VARCHAR(190) NULL DEFAULT NULL,
  `unit` VARCHAR(60) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lab_rows_test` (`test_id`, `sort_order`),
  CONSTRAINT `fk_lab_rows_test`
    FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lab_test_cells` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `test_id` BIGINT UNSIGNED NOT NULL,
  `row_id` BIGINT UNSIGNED NOT NULL,
  `column_id` BIGINT UNSIGNED NOT NULL,
  `content` VARCHAR(500) NOT NULL DEFAULT '',
  `options` JSON NULL DEFAULT NULL,
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

-- Lab module — Direct walk-in patients (external visits)
CREATE TABLE IF NOT EXISTS `lab_patients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `lab_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `mobile` VARCHAR(30) NOT NULL,
  `age` SMALLINT UNSIGNED NOT NULL,
  `age_months` TINYINT UNSIGNED NULL DEFAULT NULL,
  `gender` ENUM('male', 'female') NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lab_patients_user_lab` (`user_id`, `lab_id`),
  KEY `idx_lab_patients_mobile` (`user_id`, `lab_id`, `mobile`),
  CONSTRAINT `fk_lab_patients_lab`
    FOREIGN KEY (`lab_id`) REFERENCES `medical_center_labs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lab module — Stage 2: doctor orders + filled results
CREATE TABLE IF NOT EXISTS `lab_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `lab_id` BIGINT UNSIGNED NOT NULL,
  `order_source` ENUM('doctor_referral', 'direct') NOT NULL DEFAULT 'doctor_referral',
  `doctor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `patient_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `lab_patient_id` BIGINT UNSIGNED NULL DEFAULT NULL,
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
  KEY `idx_lab_orders_source` (`user_id`, `order_source`),
  KEY `idx_lab_orders_lab_patient` (`lab_patient_id`),
  CONSTRAINT `fk_lab_orders_lab`
    FOREIGN KEY (`lab_id`) REFERENCES `medical_center_labs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lab_orders_doctor`
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_lab_orders_patient`
    FOREIGN KEY (`patient_id`) REFERENCES `medical_center_patients` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_lab_orders_lab_patient`
    FOREIGN KEY (`lab_patient_id`) REFERENCES `lab_patients` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `cosmetic_center_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `mobile` VARCHAR(30) NOT NULL,
  `receipt_header` TEXT NULL,
  `receipt_logo_url` VARCHAR(512) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cosmetic_center_user_email` (`user_id`, `email`),
  KEY `idx_cosmetic_center_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cosmetic_doctor_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `mobile` VARCHAR(30) NOT NULL,
  `receipt_header` TEXT NULL,
  `receipt_logo_url` VARCHAR(512) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cosmetic_doctor_user_email` (`user_id`, `email`),
  KEY `idx_cosmetic_doctor_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cosmetic_client_cases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `client_name` VARCHAR(150) NOT NULL,
  `age` SMALLINT UNSIGNED NOT NULL,
  `sessions_planned` INT UNSIGNED NOT NULL DEFAULT 1,
  `work_type` VARCHAR(255) NOT NULL,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `mobile` VARCHAR(30) NOT NULL,
  `created_by_role` ENUM('center','doctor') NOT NULL,
  `created_by_account_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_role` ENUM('center','doctor') NOT NULL,
  `updated_by_account_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cosmetic_cases_user` (`user_id`),
  KEY `idx_cosmetic_cases_user_creator` (`user_id`, `created_by_role`, `created_by_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cosmetic_client_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `session_number` INT UNSIGNED NOT NULL,
  `session_date` DATE NOT NULL,
  `notes` TEXT NULL,
  `created_by_role` ENUM('center','doctor') NOT NULL,
  `created_by_account_id` BIGINT UNSIGNED NOT NULL,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cosmetic_case_session` (`case_id`, `session_number`),
  KEY `idx_cosmetic_sessions_case` (`case_id`),
  CONSTRAINT `fk_cosmetic_sessions_case`
    FOREIGN KEY (`case_id`) REFERENCES `cosmetic_client_cases` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
