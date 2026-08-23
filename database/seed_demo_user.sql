-- Demo user and sample data for local development
-- Password: 123456

USE `nexoracore_db`;

-- Business types
INSERT INTO `business_types` (`id`, `code`, `name_ku`, `sort_order`) VALUES
(1, 'pharmacy', 'دەرمانخانە', 1),
(2, 'other', 'جۆرەکانی تر', 2),
(3, 'pharmacy_and_medical_center', 'سەنتەری پزیشکی + دەرمانخانە', 3),
(4, 'jwmla_w_mandwb', 'جوملە و مەندووب', 4),
(5, 'curtain_shop', 'دوکانی پەردە', 5);

-- Package with full access
INSERT INTO `packages` (`id`, `name`, `description`, `permissions`, `is_active`, `max_sub_users`) VALUES
(1, 'پاکێجی تەواو', 'پاکێجی تاقیکردنەوە بۆ گەشەپێدەران', '{}', 1, 10);

-- Demo merchant user & Master Admin user (approved, password: 123456)
INSERT INTO `users` (`id`, `business_name`, `email`, `password`, `phone`, `address`, `telegram_sent`, `status`, `package_id`, `expiration_date`, `created_at`, `approved_at`, `ai_balance`, `support_balance`) VALUES
(1, 'فرۆشگای نموونە', 'demo@kashery.local', 'Czt0Bsu', '07501234567', 'هەولێر - شەقامی 100 مەتر', 0, 'approved', 1, DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW(), NOW(), 5.00, 10000.000),
(2, 'Nexora Master Admin', 'admin@kashery.local', '$2y$12$aQly0x1ymkJkC1wGDyYLKO22dIjBhM4MyKu8XlbBM7mtmDCzt0Bsu', '07500000000', 'کوردستان', 0, 'approved', 1, '2099-12-31 23:59:59', NOW(), NOW(), 1000.00, 100000.000)
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `status` = 'approved', `expiration_date` = VALUES(`expiration_date`);

-- Business settings
INSERT INTO `settings` (`id`, `user_id`, `business_type_id`, `receipt_header`, `receipt_footer`, `receipt_size`, `currency`, `tax_rate`, `low_stock_alert`, `expiry_alert_days`) VALUES
(1, 1, 3, 'فرۆشگای نموونە', 'سوپاس بۆ کڕینەکەت', 'thermal', 'IQD', 0.00, 1, 30),
(2, 2, 3, 'Nexora Master Admin', 'سوپاس بۆ سەردانەکەت', 'thermal', 'IQD', 0.00, 1, 30)
ON DUPLICATE KEY UPDATE `business_type_id` = 3;

-- Wallets
INSERT INTO `wallets` (`user_id`, `name`, `is_default`, `is_active`, `balance_iqd`, `balance_usd`) VALUES
(1, 'قاسەی سەرەکی', 1, 1, 0.000, 0.000),
(2, 'قاسەی سەرەکی', 1, 1, 0.000, 0.000)
ON DUPLICATE KEY UPDATE `is_active` = 1, `is_default` = 1;

-- Website settings
INSERT INTO `website_settings` (`id`, `user_id`, `website_slug`, `is_active`, `show_on_index`, `show_product_images`, `show_prices`, `show_retail_price`, `show_wholesale_price`, `show_by_category`, `show_stock_quantity`) VALUES
(1, 1, 'demo-shop', 1, 1, 1, 1, 1, 1, 1, 1),
(2, 2, 'admin-shop', 1, 1, 1, 1, 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE `is_active` = 1;

-- Units
INSERT INTO `units` (`id`, `user_id`, `name`, `name_en`, `symbol`, `is_default`, `is_active`) VALUES
(1, 1, 'دانە', 'Piece', 'pc', 1, 1),
(2, 1, 'کارتۆن', 'Carton', 'ctn', 0, 1);

-- Categories
INSERT INTO `categories` (`id`, `user_id`, `name`, `description`, `is_visible_on_website`) VALUES
(1, 1, 'خواردن', 'کاڵاکانی خواردن', 1),
(2, 1, 'خۆراک', 'کاڵاکانی خۆراک', 1),
(3, 1, 'پاکێج', 'کاڵاکانی پاکێج', 1);

-- Products
INSERT INTO `products` (`id`, `user_id`, `category_id`, `name`, `barcode`, `currency`) VALUES
(1, 1, 1, 'ئاو 0.5 لتر', '6281000000001', 'IQD'),
(2, 1, 1, 'چای زەرد', '6281000000002', 'IQD'),
(3, 1, 2, 'برنج 5kg', '6281000000003', 'IQD'),
(4, 1, 2, 'زەیت', '6281000000004', 'IQD'),
(5, 1, 3, 'شامپۆ', '6281000000005', 'IQD');

-- Product units (pricing & stock)
INSERT INTO `product_units` (`id`, `product_id`, `unit_id`, `buy_price`, `sell_price`, `wholesale_price`, `special_price`, `currency`, `stock_quantity`, `min_stock`, `conversion_ratio`, `conversion_rate`, `is_primary`) VALUES
(1, 1, 1, 250.000, 500.000, 450.000, 400.000, 'IQD', 200.000, 20, 1.0000, 1.000, 1),
(2, 2, 1, 1500.000, 2500.000, 2200.000, 2000.000, 'IQD', 50.000, 10, 1.0000, 1.000, 1),
(3, 3, 1, 8000.000, 12000.000, 11000.000, 10500.000, 'IQD', 30.000, 5, 1.0000, 1.000, 1),
(4, 4, 1, 5000.000, 7500.000, 7000.000, 6500.000, 'IQD', 25.000, 5, 1.0000, 1.000, 1),
(5, 5, 1, 3000.000, 5500.000, 5000.000, 4800.000, 'IQD', 40.000, 8, 1.0000, 1.000, 1);

-- Product barcodes
INSERT INTO `product_barcodes` (`id`, `product_id`, `barcode`, `is_primary`) VALUES
(1, 1, '6281000000001', 1),
(2, 2, '6281000000002', 1),
(3, 3, '6281000000003', 1),
(4, 4, '6281000000004', 1),
(5, 5, '6281000000005', 1);

-- Customers
INSERT INTO `customers` (`id`, `user_id`, `name`, `phone`, `address`, `total_debt`, `status`) VALUES
(1, 1, 'ئەحمەد محەمەد', '07501111111', 'هەولێر', 0.000, 'active'),
(2, 1, 'سارا عەلی', '07502222222', 'سلێمانی', 15000.000, 'active'),
(3, 1, 'کریارێکی گشتی', '07503333333', 'دهۆک', 0.000, 'active');

-- Sample sale
INSERT INTO `sales` (`id`, `user_id`, `user_type`, `customer_id`, `invoice_number`, `customer_name`, `total_amount`, `discount`, `final_amount`, `payment_method`, `payment_status`, `paid_amount`, `remaining_amount`, `sale_date`) VALUES
(1, 1, 'main', 1, 'INV-00001', 'ئەحمەد محەمەد', 3000.000, 0.000, 3000.000, 'cash', 'paid', 3000.000, 0.000, NOW());

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `product_name`, `unit_id`, `quantity`, `unit_price`, `total_price`, `currency`) VALUES
(1, 1, 1, 'ئاو 0.5 لتر', 1, 2.000, 500.000, 1000.000, 'IQD'),
(2, 1, 2, 'چای زەرد', 1, 1.000, 2500.000, 2500.000, 'IQD');

-- Package feature permissions (enable all catalog features)
INSERT INTO `package_feature_permissions` (`package_id`, `feature_key`, `is_enabled`, `lock_html`) VALUES
(1, 'pos_receipt_view', 1, ''),
(1, 'pos_receipt_a4_view', 1, ''),
(1, 'pos_barcode_scan', 1, ''),
(1, 'employees_manage', 1, ''),
(1, 'employees_stats', 1, ''),
(1, 'employees_max_count', 1, ''),
(1, 'companies_manage', 1, ''),
(1, 'customers_history', 1, ''),
(1, 'customers_account_statement', 1, ''),
(1, 'wallets_manage', 1, ''),
(1, 'reports_item_section_profit', 1, ''),
(1, 'products_smart_scale', 1, '');

-- user account settings (same unified database)
USE `nexoracore_db`;

INSERT INTO `user_account_settings` (`user_id`, `pos_show_zero_stock_products`, `purchases_use_weighted_avg_prices`, `recognize_customer_debt_revenue_at_sale`, `receipt_a4_items_font_size`, `pos_default_sale_currency`, `pos_default_price_type`, `pos_default_payment_is_credit`, `theme_mode`) VALUES
(1, 1, 1, 1, 16, 'IQD', 'retail', 0, 'system');
