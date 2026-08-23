-- Smart scale (قەپانی زیرەک) module schema
-- Run on main application database before using scale features.

CREATE TABLE IF NOT EXISTS `scale_barcode_settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `prefix` VARCHAR(10) NOT NULL DEFAULT '21',
  `total_digits` TINYINT UNSIGNED NOT NULL DEFAULT 13,
  `product_code_digits` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `price_digits` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `validate_check_digit` TINYINT(1) NOT NULL DEFAULT 0,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scale_barcode_settings_user` (`user_id`),
  CONSTRAINT `fk_scale_barcode_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scale_products` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `product_code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `buy_price` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `sell_price` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `wholesale_price` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `special_price` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `stock_quantity` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  `product_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scale_products_user_code` (`user_id`, `product_code`),
  KEY `idx_scale_products_product_id` (`product_id`),
  CONSTRAINT `fk_scale_products_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scale_products_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
