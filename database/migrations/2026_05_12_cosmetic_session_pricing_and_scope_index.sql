-- پارەی هەر جەلسە + ئیندێکسی پاڵاوتەی دروستکەر (kasher_platform)
-- دوای ئەم فایلە: cosmetic_client_sessions ستونی price/discount هەیە؛ وەسڵ لەوێ وەردەگیرێت.

ALTER TABLE `cosmetic_client_sessions`
  ADD COLUMN `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `created_by_account_id`,
  ADD COLUMN `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `price`;

-- یەکەم جەلسەی هەر کەیسێک نرخی کۆنی کەیس دەگرێتەوە؛ جەلسەکانی تر ٠ دەمێننەوە تاوەکو دەستی دەستکاری بکرێت.
UPDATE `cosmetic_client_sessions` `s`
INNER JOIN `cosmetic_client_cases` `c` ON `c`.`id` = `s`.`case_id`
SET `s`.`price` = `c`.`price`, `s`.`discount` = `c`.`discount`
WHERE `s`.`session_number` = 1;

ALTER TABLE `cosmetic_client_cases`
  ADD INDEX `idx_cosmetic_cases_user_creator` (`user_id`, `created_by_role`, `created_by_account_id`);
