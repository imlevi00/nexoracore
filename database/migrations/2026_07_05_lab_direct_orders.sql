-- Lab module — Direct (walk-in) orders without doctor referral
-- Database: kasher_platform

-- Walk-in patients managed by the lab (external / direct visits)
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

-- Extend lab_orders for direct (lab-created) orders
ALTER TABLE `lab_orders`
  ADD COLUMN `order_source` ENUM('doctor_referral', 'direct') NOT NULL DEFAULT 'doctor_referral' AFTER `lab_id`,
  ADD COLUMN `lab_patient_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `patient_id`;

-- Allow NULL doctor_id and patient_id for direct orders
ALTER TABLE `lab_orders`
  MODIFY COLUMN `doctor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  MODIFY COLUMN `patient_id` BIGINT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `lab_orders`
  ADD KEY `idx_lab_orders_source` (`user_id`, `order_source`),
  ADD KEY `idx_lab_orders_lab_patient` (`lab_patient_id`),
  ADD CONSTRAINT `fk_lab_orders_lab_patient`
    FOREIGN KEY (`lab_patient_id`) REFERENCES `lab_patients` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE;
