-- قیاسی قوماش (پانی و بەرزی) بۆ دوکانی پەردە

ALTER TABLE `products`
  ADD COLUMN `fabric_width` DECIMAL(10,3) NULL DEFAULT NULL COMMENT 'پانی قوماش' AFTER `image_path`,
  ADD COLUMN `fabric_height` DECIMAL(10,3) NULL DEFAULT NULL COMMENT 'بەرزی قوماش' AFTER `fabric_width`,
  ADD COLUMN `fabric_measure_unit` ENUM('cm','m') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cm' COMMENT 'یەکەی پێوانە (سم یان مەتر)' AFTER `fabric_height`;
