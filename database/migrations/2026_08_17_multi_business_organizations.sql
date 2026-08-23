-- ---------------------------------------------------------------------------
-- بەڕێوەبردنی چەندین بزنس بۆ یەک خاوەن (Multi-Business / Organizations)
-- ---------------------------------------------------------------------------
-- مۆدێل: هەر بزنس = ڕیزێکی `users` (وەک ئێستا، بە products/sales/settings/
--        sub_users/business_type ی جیای خۆیەوە). ئەم migrationـە تەنها:
--          1) خشتەی `organizations` زیاد دەکات (چەتری خاوەنکار).
--          2) کۆڵۆمی `organization_id` بۆ `users` زیاد دەکات (NULL = بزنسی تاک).
--          3) دوو feature key بۆ کۆنترۆڵی پاکێج زیاد دەکات (لە کۆدەوە، نەک لێرە).
--
-- دەستەبەری بێ-کێشەیی:
--   * `organization_id` بنەڕەت NULL — هەموو بەکارهێنەرانی ئێستا هەروەک خۆیان
--     دەمێننەوە (بزنسی تاک، هیچ switcher، هیچ گۆڕانکاری لە queryـیەکاندا).
--   * هیچ خشتەیەکی تر دەستکاری ناکرێت. هیچ FK توندی زیاد ناکرێت (بۆ ئەوەی
--     migrationـەکە لەسەر داتای ئێستا شکست نەهێنێت). یەکپارچەیی لە کۆدەوە
--     جێبەجێ دەکرێت.
--
-- گەڕانەوە (rollback):
--   ALTER TABLE `users` DROP COLUMN `organization_id`;
--   DROP TABLE IF EXISTS `organizations`;
-- ---------------------------------------------------------------------------

-- 1) خشتەی organizations
CREATE TABLE IF NOT EXISTS `organizations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ناوی ڕێکخراو/خاوەنکار',
  `owner_user_id` int NOT NULL COMMENT 'anchor — users.id ی ئەکاونتی خاوەن (ئەو تاکە ئەکاونتەی switcher و ڕاپۆرتی گشتی دەبینێت)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ڕێکخراوی چەندین بزنس بۆ یەک خاوەن';

-- 2) کۆڵۆمی organization_id بۆ users
--    تێبینی: ئەگەر پێشتر زیادکراوە، ئەم بەشە دەبێت دەستی تێپەڕێنرێت (بەبێ خەوش).
ALTER TABLE `users`
  ADD COLUMN `organization_id` int DEFAULT NULL COMMENT 'گرێدان بۆ organizations.id — NULL = بزنسی تاک (ڕەفتاری کۆن)' AFTER `package_id`,
  ADD KEY `idx_users_organization` (`organization_id`);

-- 3) feature keyـەکانی پاکێج (multi_business_max_count / multi_business_stats)
--    لە کۆدەوە لە getPackageFeatureCatalog() پێناسە کراون. ئێرە هیچ INSERT
--    ناکرێت — بەهای بنەڕەت (1 بزنس) لە کۆدەوە بەڕێوە دەبرێت کاتێک ڕیز نییە،
--    بۆیە پاکێجە ئێستاکان خۆکارانە «تاک-بزنس» دەمێننەوە.
