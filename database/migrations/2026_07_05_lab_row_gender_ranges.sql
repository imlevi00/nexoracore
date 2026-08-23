-- Gender-specific normal ranges for lab test rows (numeric result type)
ALTER TABLE `lab_test_rows`
  ADD COLUMN `normal_min_male` DECIMAL(12,4) NULL DEFAULT NULL AFTER `normal_max`,
  ADD COLUMN `normal_max_male` DECIMAL(12,4) NULL DEFAULT NULL AFTER `normal_min_male`,
  ADD COLUMN `normal_min_female` DECIMAL(12,4) NULL DEFAULT NULL AFTER `normal_max_male`,
  ADD COLUMN `normal_max_female` DECIMAL(12,4) NULL DEFAULT NULL AFTER `normal_min_female`;

UPDATE `lab_test_rows`
SET `normal_min_male` = `normal_min`,
    `normal_max_male` = `normal_max`,
    `normal_min_female` = `normal_min`,
    `normal_max_female` = `normal_max`
WHERE `normal_min` IS NOT NULL OR `normal_max` IS NOT NULL;
