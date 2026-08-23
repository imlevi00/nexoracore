-- زیادکردنی سیستەمی ناوچە بۆ کڕیاران
-- customer_regions: lookup table بۆ ناوچەکان (پێنجوێن، سەیدسادق، ...)
-- customers.region_id: پەیوەستکردنی کڕیار بە ناوچە
-- ئەنجامدان: php database/migrations/run_2026_08_06_customer_regions.php

CREATE TABLE IF NOT EXISTS customer_regions (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_region_name (user_id, name),
  KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customers
  ADD COLUMN region_id INT NULL DEFAULT NULL AFTER address,
  ADD KEY idx_region_id (region_id),
  ADD CONSTRAINT fk_customers_region
    FOREIGN KEY (region_id) REFERENCES customer_regions(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;
