-- مۆدیولی سەنتەری جوانکاری: تەیبڵەکان لە kasher_platform
-- ئەنووع: دەستی لەسەر DB جێبەجێ بکە یان لە ڕێگەی import

CREATE TABLE IF NOT EXISTS `cosmetic_center_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `mobile` VARCHAR(30) NOT NULL,
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

CREATE TABLE IF NOT EXISTS `cosmetic_center_client_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `center_account_id` BIGINT UNSIGNED NOT NULL,
  `client_name` VARCHAR(150) NOT NULL,
  `age` SMALLINT UNSIGNED NOT NULL,
  `session_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `work_type` VARCHAR(255) NOT NULL,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `mobile` VARCHAR(30) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cosmetic_center_records_user` (`user_id`),
  KEY `idx_cosmetic_center_records_account` (`user_id`, `center_account_id`),
  CONSTRAINT `fk_cosmetic_center_records_account`
    FOREIGN KEY (`center_account_id`) REFERENCES `cosmetic_center_accounts` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cosmetic_doctor_client_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `doctor_account_id` BIGINT UNSIGNED NOT NULL,
  `client_name` VARCHAR(150) NOT NULL,
  `age` SMALLINT UNSIGNED NOT NULL,
  `session_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `work_type` VARCHAR(255) NOT NULL,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `mobile` VARCHAR(30) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cosmetic_doctor_records_user` (`user_id`),
  KEY `idx_cosmetic_doctor_records_account` (`user_id`, `doctor_account_id`),
  CONSTRAINT `fk_cosmetic_doctor_records_account`
    FOREIGN KEY (`doctor_account_id`) REFERENCES `cosmetic_doctor_accounts` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
