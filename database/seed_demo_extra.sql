-- Additional demo data for user_id = 1
USE `nexoracore_db`;

-- Link business to pharmacy type
UPDATE `settings` SET `business_type_id` = 1 WHERE `user_id` = 1;

-- More categories
INSERT INTO `categories` (`id`, `user_id`, `name`, `description`, `is_visible_on_website`) VALUES
(4, 1, 'دەرمان', 'دەرمان و ڤیتامین', 1),
(5, 1, 'پێداویستی', 'پێداویستی ڕۆژانە', 1);

-- More products
INSERT INTO `products` (`id`, `user_id`, `category_id`, `name`, `barcode`, `currency`, `expiry_date`) VALUES
(6, 1, 4, 'پاراسیتامۆل 500mg', '6281000000006', 'IQD', DATE_ADD(CURDATE(), INTERVAL 8 MONTH)),
(7, 1, 4, 'ڤیتامین C', '6281000000007', 'IQD', DATE_ADD(CURDATE(), INTERVAL 1 YEAR)),
(8, 1, 5, 'ماسک', '6281000000008', 'IQD', NULL),
(9, 1, 1, 'شیر 1 لتر', '6281000000009', 'IQD', DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(10, 1, 2, 'هێلکە (30)', '6281000000010', 'IQD', DATE_ADD(CURDATE(), INTERVAL 20 DAY));

INSERT INTO `product_units` (`id`, `product_id`, `unit_id`, `buy_price`, `sell_price`, `wholesale_price`, `special_price`, `currency`, `stock_quantity`, `min_stock`, `conversion_ratio`, `conversion_rate`, `is_primary`) VALUES
(6, 6, 1, 800.000, 1500.000, 1300.000, 1200.000, 'IQD', 120.000, 15, 1.0000, 1.000, 1),
(7, 7, 1, 2000.000, 3500.000, 3200.000, 3000.000, 'IQD', 60.000, 10, 1.0000, 1.000, 1),
(8, 8, 1, 500.000, 1000.000, 900.000, 850.000, 'IQD', 5.000, 20, 1.0000, 1.000, 1),
(9, 9, 1, 1000.000, 1750.000, 1600.000, 1500.000, 'IQD', 45.000, 10, 1.0000, 1.000, 1),
(10, 10, 1, 4500.000, 7000.000, 6500.000, 6200.000, 'IQD', 18.000, 5, 1.0000, 1.000, 1);

INSERT INTO `product_barcodes` (`id`, `product_id`, `barcode`, `is_primary`) VALUES
(6, 6, '6281000000006', 1),
(7, 7, '6281000000007', 1),
(8, 8, '6281000000008', 1),
(9, 9, '6281000000009', 1),
(10, 10, '6281000000010', 1);

-- More customers
INSERT INTO `customers` (`id`, `user_id`, `name`, `phone`, `address`, `total_debt`, `status`, `notes`) VALUES
(4, 1, 'کاروان ڕەشید', '07504444444', 'هەولێر - 60 مەتر', 25000.000, 'active', 'کڕیاری جوملە'),
(5, 1, 'لەیلا حەسەن', '07505555555', 'کەرکوک', 0.000, 'active', NULL),
(6, 1, 'ڕێباز سالم', '07506666666', 'سلێمانی', 8000.000, 'active', 'قەرزدار');

-- Supplier companies
INSERT INTO `companies` (`id`, `user_id`, `name`, `address`, `phone`, `debt_amount`, `notes`, `status`) VALUES
(1, 1, 'کۆمپانیای ئەسیل', 'هەولێر - بازاڕی گشتی', '07507777777', 50000.00, 'دابینکەری سەرەکی', 'active'),
(2, 1, 'کۆمپانیای ڕۆژهەڵات', 'سلێمانی', '07508888888', 0.00, 'دابینکەری دەرمان', 'active');

-- Purchase receipt (stock in)
INSERT INTO `purchase_receipts` (`id`, `user_id`, `company_id`, `receipt_number`, `payment_type`, `receipt_date`, `total_amount`, `discount_amount`, `final_amount`, `notes`, `status`) VALUES
(1, 1, 1, 'PUR-00001', 'debt', CURDATE(), 96000.000, 0.000, 96000.000, 'وەسڵی کڕینی مانگ', 'completed');

INSERT INTO `purchase_receipt_items` (`id`, `purchase_receipt_id`, `product_id`, `product_name`, `quantity`, `buy_price`, `sell_price`, `wholesale_price`, `special_price`, `total_cost`, `unit_id`) VALUES
(1, 1, 6, 'پاراسیتامۆل 500mg', 50.000, 800.000, 1500.000, 1300.000, 1200.000, 40000.000, 1),
(2, 1, 7, 'ڤیتامین C', 20.000, 2000.000, 3500.000, 3200.000, 3000.000, 40000.000, 1),
(3, 1, 9, 'شیر 1 لتر', 10.000, 1000.000, 1750.000, 1600.000, 1500.000, 10000.000, 1);

UPDATE `companies` SET `debt_amount` = 96000.00 WHERE `id` = 1 AND `user_id` = 1;

-- More sales
INSERT INTO `sales` (`id`, `user_id`, `user_type`, `customer_id`, `invoice_number`, `customer_name`, `total_amount`, `discount`, `final_amount`, `payment_method`, `payment_status`, `paid_amount`, `remaining_amount`, `sale_date`) VALUES
(2, 1, 'main', 2, 'INV-00002', 'سارا عەلی', 15000.000, 0.000, 15000.000, 'debt', 'pending', 0.000, 15000.000, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, 1, 'main', 4, 'INV-00003', 'کاروان ڕەشید', 22000.000, 1000.000, 21000.000, 'cash', 'paid', 21000.000, 0.000, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4, 1, 'main', 5, 'INV-00004', 'لەیلا حەسەن', 8750.000, 250.000, 8500.000, 'cash', 'paid', 8500.000, 0.000, NOW()),
(5, 1, 'main', 6, 'INV-00005', 'ڕێباز سالم', 8000.000, 0.000, 8000.000, 'debt', 'pending', 0.000, 8000.000, DATE_SUB(NOW(), INTERVAL 2 DAY));

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `product_name`, `unit_id`, `quantity`, `unit_price`, `total_price`, `currency`, `price_type`, `unit_name`, `unit_symbol`) VALUES
(3, 2, 3, 'برنج 5kg', 1, 1.000, 12000.000, 12000.000, 'IQD', 'retail', 'دانە', 'pc'),
(4, 2, 4, 'زەیت', 1, 1.000, 3000.000, 3000.000, 'IQD', 'retail', 'دانە', 'pc'),
(5, 3, 6, 'پاراسیتامۆل 500mg', 1, 10.000, 1300.000, 13000.000, 'IQD', 'wholesale', 'دانە', 'pc'),
(6, 3, 7, 'ڤیتامین C', 1, 3.000, 3000.000, 9000.000, 'IQD', 'wholesale', 'دانە', 'pc'),
(7, 4, 9, 'شیر 1 لتر', 1, 2.000, 1750.000, 3500.000, 'IQD', 'retail', 'دانە', 'pc'),
(8, 4, 10, 'هێلکە (30)', 1, 1.000, 7000.000, 7000.000, 'IQD', 'retail', 'دانە', 'pc'),
(9, 4, 1, 'ئاو 0.5 لتر', 1, 3.000, 500.000, 1500.000, 'IQD', 'retail', 'دانە', 'pc'),
(10, 5, 6, 'پاراسیتامۆل 500mg', 1, 5.000, 1500.000, 8000.000, 'IQD', 'retail', 'دانە', 'pc');

-- Customer debts
INSERT INTO `debts` (`id`, `user_id`, `customer_id`, `sale_id`, `customer_name`, `customer_phone`, `total_debt`, `paid_amount`, `remaining_amount`, `debt_type`, `status`, `created_at`) VALUES
(1, 1, 2, 2, 'سارا عەلی', '07502222222', 15000.000, 0.000, 15000.000, 'debt', 'active', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 1, 6, 5, 'ڕێباز سالم', '07506666666', 8000.000, 0.000, 8000.000, 'debt', 'active', DATE_SUB(NOW(), INTERVAL 2 DAY));

-- Expense types & expenses
INSERT INTO `expense_types` (`id`, `user_id`, `name`, `description`, `is_recurring`) VALUES
(1, 1, 'کرێ', 'کرێی شوێن', 1),
(2, 1, 'کارەبا', 'بیلەکانی کارەبا', 1),
(3, 1, 'مووچە', 'مووچەی کارمەند', 1);

INSERT INTO `expenses` (`id`, `user_id`, `expense_type_id`, `expense_name`, `amount`, `payment_method`, `description`, `expense_date`) VALUES
(1, 1, 1, 'کرێی مانگی ئاب', 500000.000, 'cash', 'کرێی فرۆشگا', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(2, 1, 2, 'کارەبا - تەموز', 85000.000, 'cash', 'بیلی کارەبا', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(3, 1, 3, 'مووچەی کارمەند', 350000.000, 'cash', 'مووچەی ئەمیر', DATE_SUB(NOW(), INTERVAL 2 DAY));

-- Services
INSERT INTO `services` (`id`, `user_id`, `name`, `cost_price`, `sell_price`) VALUES
(1, 1, 'پێوانەی فشار', 0.00, 5000.00),
(2, 1, 'ڕاوێژکاری دەرمان', 0.00, 10000.00),
(3, 1, 'گەیاندن', 2000.00, 5000.00);

-- Wallet transactions (from cash sales)
INSERT INTO `wallets` (`id`, `user_id`, `name`, `notes`, `is_active`, `is_default`, `balance_iqd`, `balance_usd`) VALUES
(2, 1, 'قاسەی دووەم', 'قاسەی پاشەکەوت', 1, 0, 500000.000, 0.000);

UPDATE `wallets` SET `balance_iqd` = 125000.000 WHERE `id` = 1 AND `user_id` = 1;

INSERT INTO `wallet_transactions` (`id`, `user_id`, `wallet_id`, `tx_type`, `direction`, `currency`, `amount`, `reference_type`, `reference_id`, `notes`, `created_at`) VALUES
(1, 1, 1, 'sale_income', 'in', 'IQD', 3000.000, 'sale', 1, 'فرۆشتنی INV-00001', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(2, 1, 1, 'sale_income', 'in', 'IQD', 21000.000, 'sale', 3, 'فرۆشتنی INV-00003', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 1, 1, 'sale_income', 'in', 'IQD', 8500.000, 'sale', 4, 'فرۆشتنی INV-00004', NOW()),
(4, 1, 1, 'expense', 'out', 'IQD', 85000.000, 'expense', 2, 'کارەبا - تەموز', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 1, 2, 'manual_deposit', 'in', 'IQD', 500000.000, NULL, NULL, 'پاشەکەوتی سەرەتایی', DATE_SUB(NOW(), INTERVAL 30 DAY));

-- Sub-user (employee) — password: 123456
INSERT INTO `sub_users` (`id`, `main_user_id`, `username`, `email`, `password`, `full_name`, `permissions`, `is_active`, `expiration_date`) VALUES
(1, 1, 'amir_staff', 'staff@kashery.local', '$2y$12$aQly0x1ymkJkC1wGDyYLKO22dIjBhM4MyKu8XlbBM7mtmDCzt0Bsu', 'ئەمیر کارمەند', '{"pos":true,"products":true,"customers":true,"reports":false}', 1, DATE_ADD(NOW(), INTERVAL 1 YEAR));

-- Low stock alert sample (product 8)
INSERT INTO `inventory_adjustments` (`id`, `user_id`, `product_id`, `unit_id`, `adjustment_type`, `quantity`, `reason`, `created_at`) VALUES
(1, 1, 8, 1, 'subtract', 15.000, 'کەمبوونەوەی کۆگا لە پشکنین', DATE_SUB(NOW(), INTERVAL 1 DAY));
