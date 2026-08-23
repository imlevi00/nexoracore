-- یەکگرتنی تۆماری کڕیار/کەیس و جەلسەکان (kasher_platform)
-- پێش نیوەدوای مایگڕەیشنی 2026_05_05؛ دامەزراندنی نوێ database/kasher_platform/kasher_platform.sql بەکاربهێنە.
-- تەنها یەک جار لەسەر ئەو داتابەیسە ئەنجام بدە کە هێشتا دوو خشتەی کۆنی کڕیار تێدایە.
-- ئەگەر ستوونەکانی receipt بۆ cosmetic_center_accounts هەبوون، هەنگاوی ALTER لابدە.

ALTER TABLE `cosmetic_center_accounts`
  ADD COLUMN `receipt_header` TEXT NULL AFTER `mobile`,
  ADD COLUMN `receipt_logo_url` VARCHAR(512) NULL AFTER `receipt_header`;

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
  `legacy_ref` VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cosmetic_cases_user` (`user_id`),
  KEY `idx_cosmetic_cases_legacy` (`legacy_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cosmetic_client_cases` (
  `user_id`, `client_name`, `age`, `sessions_planned`, `work_type`, `price`, `discount`, `mobile`,
  `created_by_role`, `created_by_account_id`, `updated_by_role`, `updated_by_account_id`,
  `created_at`, `updated_at`, `legacy_ref`
)
SELECT
  `user_id`, `client_name`, `age`, `session_count`, `work_type`, `price`, `discount`, `mobile`,
  'center', `center_account_id`, 'center', `center_account_id`,
  `created_at`, `updated_at`, CONCAT('c:', `id`)
FROM `cosmetic_center_client_records`;

INSERT INTO `cosmetic_client_cases` (
  `user_id`, `client_name`, `age`, `sessions_planned`, `work_type`, `price`, `discount`, `mobile`,
  `created_by_role`, `created_by_account_id`, `updated_by_role`, `updated_by_account_id`,
  `created_at`, `updated_at`, `legacy_ref`
)
SELECT
  `user_id`, `client_name`, `age`, `session_count`, `work_type`, `price`, `discount`, `mobile`,
  'doctor', `doctor_account_id`, 'doctor', `doctor_account_id`,
  `created_at`, `updated_at`, CONCAT('d:', `id`)
FROM `cosmetic_doctor_client_records`;

CREATE TABLE IF NOT EXISTS `cosmetic_client_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `session_number` INT UNSIGNED NOT NULL,
  `session_date` DATE NOT NULL,
  `notes` TEXT NULL,
  `created_by_role` ENUM('center','doctor') NOT NULL,
  `created_by_account_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cosmetic_case_session` (`case_id`, `session_number`),
  KEY `idx_cosmetic_sessions_case` (`case_id`),
  CONSTRAINT `fk_cosmetic_sessions_case`
    FOREIGN KEY (`case_id`) REFERENCES `cosmetic_client_cases` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cosmetic_client_sessions` (
  `case_id`, `session_number`, `session_date`, `notes`, `created_by_role`, `created_by_account_id`, `created_at`
)
SELECT
  `c`.`id`,
  1,
  DATE(`cc`.`created_at`),
  NULL,
  'center',
  `cc`.`center_account_id`,
  `cc`.`created_at`
FROM `cosmetic_center_client_records` `cc`
INNER JOIN `cosmetic_client_cases` `c` ON `c`.`legacy_ref` = CONCAT('c:', `cc`.`id`);

INSERT INTO `cosmetic_client_sessions` (
  `case_id`, `session_number`, `session_date`, `notes`, `created_by_role`, `created_by_account_id`, `created_at`
)
SELECT
  `c`.`id`,
  1,
  DATE(`dd`.`created_at`),
  NULL,
  'doctor',
  `dd`.`doctor_account_id`,
  `dd`.`created_at`
FROM `cosmetic_doctor_client_records` `dd`
INNER JOIN `cosmetic_client_cases` `c` ON `c`.`legacy_ref` = CONCAT('d:', `dd`.`id`);

ALTER TABLE `cosmetic_client_cases` DROP COLUMN `legacy_ref`;

DROP TABLE IF EXISTS `cosmetic_center_client_records`;
DROP TABLE IF EXISTS `cosmetic_doctor_client_records`;
