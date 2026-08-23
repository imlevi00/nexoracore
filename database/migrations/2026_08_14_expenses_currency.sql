-- زیادکردنی پشتگیری دراوی دۆلار (USD) بۆ خەرجیەکان و قەرزەکانیان
-- مۆدێل: هەر خەرجییەک یەک دراوی هەیە (دینار یان دۆلار)؛ قەرز و پارەدانەوەکانیشی هەمان دراو.
-- ئەنجامدان: php database/migrations/run_2026_08_14_expenses_currency.php

-- دراوی هەر خەرجییەک
ALTER TABLE `expenses`
  ADD COLUMN `currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `amount`;

-- دراوی هەر قەرزێکی خەرجی (هەمان دراوی خەرجییەکەی)
ALTER TABLE `expense_credits`
  ADD COLUMN `currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `remaining_amount`;

-- دراوی هەر پارەدانەوەیەکی قەرز (هەمان دراوی قەرزەکەی)
ALTER TABLE `expense_credit_payments`
  ADD COLUMN `currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `payment_amount`;
