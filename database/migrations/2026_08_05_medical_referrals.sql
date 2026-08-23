-- Doctor-to-doctor patient referrals.
--
-- A referring doctor sends one of their patients to another doctor (a
-- "specialist") within the same tenant (user_id). The target doctor then sees
-- the patient in their Referrals list and can write History / prescribe / send
-- to the lab for that patient just like one of their own. Any doctor can be a
-- referral target — no separate "specialist" flag is needed.
--
-- The patient row itself keeps its original owner (medical_center_patients.doctor_id);
-- access for the target doctor is granted purely by the existence of a referral
-- row here. Clinical records the target doctor creates (medical_prescriptions,
-- lab_orders) carry that doctor's own doctor_id.

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
