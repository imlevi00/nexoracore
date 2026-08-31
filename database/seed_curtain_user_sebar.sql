-- =============================================================================
-- دروستکردنی بەکارهێنەری تایبەت بە دوکانی پەردە (Sebar Home)
-- User: sebar.home@kasher.com
-- Pass: 87654321
-- =============================================================================

USE `nexoracore_db`;

-- 1. دڵنیابوون لە هەبوونی جۆری ئیشی دوکانی پەردە
INSERT INTO `business_types` (`id`, `code`, `name_ku`, `sort_order`)
VALUES (5, 'curtain_shop', 'دوکانی پەردە', 5)
ON DUPLICATE KEY UPDATE `name_ku` = 'دوکانی پەردە', `code` = 'curtain_shop';

-- 2. دڵنیابوون لە هەبوونی پاکێجی تەواو (VIP)
INSERT INTO `packages` (`id`, `name`, `description`, `permissions`, `is_active`, `max_sub_users`)
VALUES (999, 'پاکێجی تەواوی دوکانی پەردە VIP', 'دەسەڵاتی تەواوی بەڕێوەبردنی دوکانی پەردە', '{}', 1, 999)
ON DUPLICATE KEY UPDATE `is_active` = 1, `max_sub_users` = 999;

-- دەسەڵاتەکانی پاکێج
INSERT INTO `package_feature_permissions` (`package_id`, `feature_key`, `is_enabled`, `lock_html`) VALUES
(999, 'pos_receipt_view', 1, ''),
(999, 'pos_receipt_a4_view', 1, ''),
(999, 'pos_barcode_scan', 1, ''),
(999, 'employees_manage', 1, ''),
(999, 'employees_stats', 1, ''),
(999, 'employees_max_count', 1, ''),
(999, 'companies_manage', 1, ''),
(999, 'customers_history', 1, ''),
(999, 'customers_account_statement', 1, ''),
(999, 'wallets_manage', 1, ''),
(999, 'reports_item_section_profit', 1, ''),
(999, 'products_smart_scale', 1, ''),
(999, 'multi_business_max_count', 1, '')
ON DUPLICATE KEY UPDATE `is_enabled` = 1;

-- 3. دروستکردن یان نوێکردنەوەی بەکارهێنەری sebar.home@kasher.com
-- پاسۆرد: 87654321 (BCrypt Hash: $2y$12$Kk9K5pU7P5fC.O1P3Xh07.rJ4W9S4Y6xX4XvW2Qe/8O5n6xY9qW7e)
INSERT INTO `users` (
    `business_name`, `email`, `password`, `phone`, `address`, `telegram_sent`,
    `status`, `package_id`, `expiration_date`, `created_at`, `approved_at`,
    `ai_balance`, `support_balance`
) VALUES (
    'دوکانی پەردەی سێبار هۆم (Sebar Home)',
    'sebar.home@kasher.com',
    '$2y$12$Kk9K5pU7P5fC.O1P3Xh07.rJ4W9S4Y6xX4XvW2Qe/8O5n6xY9qW7e',
    '07500000000',
    'هەولێر - کوردستان',
    0,
    'approved',
    999,
    '2099-12-31 23:59:59',
    NOW(),
    NOW(),
    999.00,
    99999.00
)
ON DUPLICATE KEY UPDATE
    `business_name` = 'دوکانی پەردەی سێبار هۆم (Sebar Home)',
    `password` = '$2y$12$Kk9K5pU7P5fC.O1P3Xh07.rJ4W9S4Y6xX4XvW2Qe/8O5n6xY9qW7e',
    `status` = 'approved',
    `package_id` = 999,
    `expiration_date` = '2099-12-31 23:59:59',
    `approved_at` = NOW();

-- 4. ڕێکخستنی جۆری ئیش بە دوکانی پەردە بۆ ئەم بەکارهێنەرە
INSERT INTO `settings` (`user_id`, `business_type_id`, `receipt_header`, `receipt_footer`, `receipt_size`, `currency`, `tax_rate`, `low_stock_alert`, `expiry_alert_days`)
SELECT 
    `id`, 5, 'دوکانی پەردەی سێبار هۆم', 'سوپاس بۆ کڕینەکەت لە دوکانی پەردەی سێبار هۆم', 'thermal', 'IQD', 0.00, 1, 30
FROM `users` WHERE `email` = 'sebar.home@kasher.com' LIMIT 1
ON DUPLICATE KEY UPDATE `business_type_id` = 5, `receipt_header` = 'دوکانی پەردەی سێبار هۆم';

-- 5. قاسەی سەرەکی
INSERT INTO `wallets` (`user_id`, `name`, `is_default`, `is_active`, `balance_iqd`, `balance_usd`)
SELECT `id`, 'قاسەی سەرەکی', 1, 1, 0.000, 0.000
FROM `users` WHERE `email` = 'sebar.home@kasher.com' LIMIT 1
ON DUPLICATE KEY UPDATE `is_active` = 1, `is_default` = 1;

-- 6. ڕێکخستنەکانی هەژمار
INSERT INTO `user_account_settings` (`user_id`, `pos_show_zero_stock_products`, `purchases_use_weighted_avg_prices`, `recognize_customer_debt_revenue_at_sale`, `receipt_a4_items_font_size`, `pos_default_sale_currency`, `pos_default_price_type`, `pos_default_payment_is_credit`, `theme_mode`)
SELECT `id`, 1, 1, 1, 16, 'IQD', 'retail', 0, 'system'
FROM `users` WHERE `email` = 'sebar.home@kasher.com' LIMIT 1
ON DUPLICATE KEY UPDATE `pos_show_zero_stock_products` = 1;

-- 7. یەکەکانی پێوانەی پەردە (مەتر، دانە، سانتیمەتر)
INSERT INTO `units` (`user_id`, `name`, `name_en`, `symbol`, `is_default`, `is_active`)
SELECT `id`, 'مەتر', 'Meter', 'm', 1, 1 FROM `users` WHERE `email` = 'sebar.home@kasher.com'
UNION ALL
SELECT `id`, 'دانە / پارچە', 'Piece', 'pc', 0, 1 FROM `users` WHERE `email` = 'sebar.home@kasher.com'
UNION ALL
SELECT `id`, 'سانتیمەتر', 'Centimeter', 'cm', 0, 1 FROM `users` WHERE `email` = 'sebar.home@kasher.com';

-- 8. کەتەلۆگەکانی پەردە
INSERT INTO `categories` (`user_id`, `name`, `description`, `is_visible_on_website`)
SELECT `id`, 'پەردەی قوماش', 'بەشی پەردەی قوماشی تایبەت', 1 FROM `users` WHERE `email` = 'sebar.home@kasher.com'
UNION ALL
SELECT `id`, 'پەردەی زێبرا و ڕۆڵەر', 'بەشی پەردەی زێبرا', 1 FROM `users` WHERE `email` = 'sebar.home@kasher.com'
UNION ALL
SELECT `id`, 'تولی و تەنک', 'بەشی تولی و تەنک', 1 FROM `users` WHERE `email` = 'sebar.home@kasher.com'
UNION ALL
SELECT `id`, 'ئێکسسوارات و میل و زنجیر', 'بەشی ئێکسسوارات', 1 FROM `users` WHERE `email` = 'sebar.home@kasher.com';

