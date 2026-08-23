-- زیادکردنی پشتگیری دراوی دۆلار (USD) بۆ کۆمپانیاکان، قەرزەکان و وەسڵەکانی کڕین
-- مۆدێل: هەر کۆمپانیایەک دوو باڵانسی سەربەخۆی هەیە (دینار + دۆلار)؛ هەر وەسڵێک یەک دراو.
-- ئەنجامدان: php database/migrations/run_2026_08_14_companies_purchases_currency.php

-- باڵانسی قەرزی دۆلار بۆ هەر کۆمپانیایەک (debt_amount ی ئێستا دەمێنێتەوە بۆ دینار)
ALTER TABLE `companies`
  ADD COLUMN `debt_amount_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `debt_amount`;

-- دراوی هەر تۆمارێکی قەرز/پارەدان
ALTER TABLE `company_debts`
  ADD COLUMN `currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `amount`;

-- دراوی وەسڵی کڕین (هەموو کاڵاکانی وەسڵ بەم دراوەن)
ALTER TABLE `purchase_receipts`
  ADD COLUMN `currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `final_amount`;
