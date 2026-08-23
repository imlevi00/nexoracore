-- Prescription clinical-note image attachments
-- Database: kasher_platform
--
-- Doctors can attach images to the History / Examination / Diagnoses sections of
-- a consultation (medical_prescriptions row — draft or finalized). Each image is
-- stored in the unified object storage (local assets/uploads on dev, Spaces on
-- production) under img/medical/prescriptions/<prescription_id>/…; only the
-- object key is kept here so the public URL can be resolved per-environment.

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
