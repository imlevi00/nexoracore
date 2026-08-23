-- سکرتێر بۆ چەند دکتۆر: تەیبڵی پەیوەندی لە kasher_platform
-- ئەنووع: دەستی لەسەر DB جێبەجێ بکە یان لە ڕێگەی import

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

INSERT INTO `doctor_secretary_doctors` (`user_id`, `secretary_id`, `doctor_id`, `created_at`)
SELECT `user_id`, `id`, `doctor_id`, `created_at`
FROM `doctor_secretaries`
WHERE NOT EXISTS (
    SELECT 1
    FROM `doctor_secretary_doctors` dsd
    WHERE dsd.secretary_id = `doctor_secretaries`.`id`
      AND dsd.doctor_id = `doctor_secretaries`.`doctor_id`
);
