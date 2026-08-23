-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Apr 28, 2026 at 04:20 AM
-- Server version: 8.0.24
-- PHP Version: 8.3.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `test_test`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'جۆری کردار (login, product_add, sale, purchase, etc)',
  `entity_id` int DEFAULT NULL COMMENT 'ID ی کاڵا، فرۆشتن، کڕین، هتد',
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'جۆری entity (product, sale, purchase, etc)',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `agents`
--

CREATE TABLE `agents` (
  `id` int NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_code` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `agent_registrations`
--

CREATE TABLE `agent_registrations` (
  `id` int NOT NULL,
  `agent_id` int NOT NULL,
  `registered_user_id` int NOT NULL,
  `agent_code_snapshot` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_balance_history`
--

CREATE TABLE `ai_balance_history` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('credit','debit','admin_add') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `balance_before` decimal(10,2) NOT NULL,
  `balance_after` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `input_tokens` int DEFAULT '0' COMMENT 'ژمارەی token ی input',
  `output_tokens` int DEFAULT '0' COMMENT 'ژمارەی token ی output'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_settings`
--

CREATE TABLE `ai_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `barcode_templates`
--

CREATE TABLE `barcode_templates` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ناوی تێمپلێت',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ڕێکخستنەکان بە JSON',
  `is_default` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'تێمپلێتی دیفۆڵت',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تێمپلێتەکانی بارکۆد بۆ هەر بەکارهێنەرێک';

-- --------------------------------------------------------

--
-- Table structure for table `business_images`
--

CREATE TABLE `business_images` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `business_types`
--

CREATE TABLE `business_types` (
  `id` int NOT NULL,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'کۆدی جۆر وەک pharmacy, other',
  `name_ku` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ناوی کوردی',
  `sort_order` int DEFAULT NULL COMMENT 'ڕیزکردن لە UI'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جۆرەکانی ئیش و کار';

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_visible_on_website` tinyint(1) DEFAULT '1' COMMENT 'نیشاندانی کەتەلۆگ لە وێب سایت (1=نیشان بدرێت، 0=بشاردرێتەوە)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int NOT NULL,
  `session_id` int NOT NULL,
  `role` enum('user','assistant') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_sessions`
--

CREATE TABLE `chat_sessions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `section` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'کاڵاکان، فرۆشتن، قەرز',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_usage`
--

CREATE TABLE `chat_usage` (
  `id` int NOT NULL,
  `session_id` int NOT NULL,
  `message_id` int NOT NULL,
  `input_tokens` int NOT NULL DEFAULT '0',
  `output_tokens` int NOT NULL DEFAULT '0',
  `total_tokens` int NOT NULL DEFAULT '0',
  `cost_usd` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `debt_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_debts`
--

CREATE TABLE `company_debts` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `company_id` int NOT NULL,
  `purchase_receipt_id` int DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `type` enum('debt','payment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'debt',
  `date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `currency_exchange_rates`
--

CREATE TABLE `currency_exchange_rates` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `from_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `to_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
  `exchange_rate` decimal(10,3) NOT NULL,
  `manual_adjustment` decimal(10,3) NOT NULL DEFAULT '0.000' COMMENT 'زیادکراوەی دەستی بەکارهێنەر (وەک +5 دینار)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `total_debt` decimal(10,3) DEFAULT '0.000',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_cash_purchases`
--

CREATE TABLE `customer_cash_purchases` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `sale_id` int NOT NULL,
  `invoice_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` decimal(10,3) NOT NULL,
  `discount` decimal(10,3) DEFAULT '0.000',
  `final_amount` decimal(10,3) NOT NULL,
  `purchase_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks cash purchases made by customers for reporting and analysis';

-- --------------------------------------------------------

--
-- Stand-in structure for view `customer_debt_summary`
-- (See below for the actual view)
--
CREATE TABLE `customer_debt_summary` (
`customer_id` int
,`user_id` int
,`customer_name` varchar(100)
,`phone` varchar(20)
,`address` text
,`total_debts` bigint
,`total_debt_amount` decimal(32,3)
,`total_paid_amount` decimal(32,3)
,`last_debt_date` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `customer_gmail_links`
--

CREATE TABLE `customer_gmail_links` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `gmail` varchar(320) COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_expires_at` datetime DEFAULT NULL COMMENT 'بەسەرچوونی دەستڕسی فرۆشگا؛ NULL = بێ سنوور',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_money_debts`
--

CREATE TABLE `customer_money_debts` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `amount` decimal(10,3) NOT NULL,
  `currency` enum('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `type` enum('debt','payment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'debt',
  `date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `debts`
--

CREATE TABLE `debts` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `sale_id` int NOT NULL,
  `customer_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_debt` decimal(10,3) NOT NULL,
  `paid_amount` decimal(10,3) DEFAULT '0.000',
  `remaining_amount` decimal(10,3) NOT NULL,
  `debt_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'debt',
  `installment_months` int DEFAULT NULL,
  `monthly_amount` decimal(10,3) DEFAULT NULL,
  `next_payment_date` date DEFAULT NULL,
  `status` enum('active','completed','defaulted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `debt_payments`
--

CREATE TABLE `debt_payments` (
  `id` int NOT NULL,
  `debt_id` int NOT NULL,
  `payment_amount` decimal(10,3) NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `debt_receipts`
--

CREATE TABLE `debt_receipts` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `receipt_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int NOT NULL,
  `debt_payment_id` int DEFAULT NULL,
  `payment_amount` decimal(10,3) NOT NULL,
  `currency` enum('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
  `payment_method` enum('cash','credit','bank_transfer','check') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `receipt_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `printed` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dollar_prices`
--

CREATE TABLE `dollar_prices` (
  `id` int NOT NULL,
  `city_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ناوی شار (هەولێر، سلێمانی، هتد)',
  `offer_price` decimal(10,3) NOT NULL COMMENT 'نرخی عــــرض',
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '@nrxy_do' COMMENT 'سەرچاوەی نرخ',
  `message_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'دەقی تەواوی نامەکە',
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'کاتی دوایین نوێکردنەوە',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'کاتی دروستکردن'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نرخەکانی دۆلار لە شارەکان';

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `expense_type_id` int DEFAULT NULL,
  `expense_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,3) NOT NULL,
  `payment_method` enum('cash','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `is_recurring` tinyint(1) DEFAULT '0',
  `has_credit` tinyint(1) DEFAULT '0',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `receipt_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expense_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_credits`
--

CREATE TABLE `expense_credits` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `expense_id` int NOT NULL,
  `creditor_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `creditor_phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_amount` decimal(10,3) NOT NULL,
  `paid_amount` decimal(10,3) DEFAULT '0.000',
  `remaining_amount` decimal(10,3) NOT NULL,
  `due_date` date DEFAULT NULL,
  `payment_terms` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','completed','overdue') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_credit_payments`
--

CREATE TABLE `expense_credit_payments` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `expense_credit_id` int NOT NULL,
  `payment_amount` decimal(10,3) NOT NULL,
  `payment_method` enum('cash','bank_transfer','check','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `payment_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `receipt_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_types`
--

CREATE TABLE `expense_types` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_recurring` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_adjustments`
--

CREATE TABLE `inventory_adjustments` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `unit_id` int NOT NULL,
  `adjustment_type` enum('add','subtract','set') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int NOT NULL,
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notebook_attachments`
--

CREATE TABLE `notebook_attachments` (
  `id` int NOT NULL,
  `entry_id` int NOT NULL,
  `user_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint NOT NULL DEFAULT '0',
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notebook_entries`
--

CREATE TABLE `notebook_entries` (
  `id` int NOT NULL,
  `topic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entry_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tags` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_favorite` tinyint(1) DEFAULT '0',
  `is_archived` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notebook_fields`
--

CREATE TABLE `notebook_fields` (
  `id` int NOT NULL,
  `topic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `field_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_type` enum('text_short','text_long','number','date','time','datetime') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text_short',
  `is_required` tinyint(1) DEFAULT '0',
  `field_order` int DEFAULT '0',
  `placeholder` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notebook_topics`
--

CREATE TABLE `notebook_topics` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'bi-book',
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#007bff',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `packages`
--

CREATE TABLE `organizations` (
  `id` int NOT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ناوی ڕێکخراو/خاوەنکار',
  `owner_user_id` int NOT NULL COMMENT 'anchor — users.id ی ئەکاونتی خاوەن',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ڕێکخراوی چەندین بزنس بۆ یەک خاوەن';

-- --------------------------------------------------------

--
-- Table structure for table `packages`
--

CREATE TABLE `packages` (
  `id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ناوی پاکێج',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'وەسفی پاکێج',
  `permissions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'دەسەڵاتەکان بە شێوەی JSON',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'چالاکە یان نا',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `max_sub_users` int DEFAULT NULL COMMENT 'ژمارەی کارمەندی ڕێپێدراو بۆ هەر بەکارهێنەری سەرەکی'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `package_feature_permissions`
--

CREATE TABLE `package_feature_permissions` (
  `id` int NOT NULL,
  `package_id` int NOT NULL,
  `feature_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_enabled` int NOT NULL DEFAULT '1',
  `lock_html` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `category_id` int DEFAULT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `currency` enum('IQD','USD') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_barcodes`
--

CREATE TABLE `product_barcodes` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `barcode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_primary` tinyint(1) DEFAULT '0' COMMENT 'Whether this is the primary barcode (for reference)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_custom_field_sections`
--

CREATE TABLE `product_custom_field_sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `section_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pcf_sections_user_active_order` (`user_id`,`is_active`,`section_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_custom_fields`
--

CREATE TABLE `product_custom_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `field_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_key` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_type` enum('text','number','select') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `section_id` int DEFAULT NULL,
  `field_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_custom_fields_user_key` (`user_id`,`field_key`),
  KEY `idx_product_custom_fields_user_active_order` (`user_id`,`is_active`,`field_order`),
  KEY `idx_product_custom_fields_section` (`section_id`),
  CONSTRAINT `fk_product_custom_fields_section` FOREIGN KEY (`section_id`) REFERENCES `product_custom_field_sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_custom_field_options`
--

CREATE TABLE `product_custom_field_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `field_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `option_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `option_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pcf_options_field_parent_order` (`field_id`,`parent_id`,`is_active`,`option_order`),
  KEY `idx_pcf_options_user_field` (`user_id`,`field_id`),
  CONSTRAINT `fk_pcf_options_field` FOREIGN KEY (`field_id`) REFERENCES `product_custom_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pcf_options_parent` FOREIGN KEY (`parent_id`) REFERENCES `product_custom_field_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_custom_field_values`
--

CREATE TABLE `product_custom_field_values` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `field_id` int NOT NULL,
  `user_id` int NOT NULL,
  `value_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `value_number` decimal(14,3) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_custom_values_product_field` (`product_id`,`field_id`),
  KEY `idx_product_custom_values_user_product` (`user_id`,`product_id`),
  CONSTRAINT `fk_product_custom_values_field` FOREIGN KEY (`field_id`) REFERENCES `product_custom_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_product_custom_values_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_details`
--

CREATE TABLE `product_details` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'وەسفی کاڵا',
  `discount_price` decimal(10,3) DEFAULT NULL COMMENT 'نرخی داشکاندنی کاڵا',
  `main_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وێنەی سەرەکی',
  `sub_images` json DEFAULT NULL COMMENT 'وێنە لاوەکیەکان (تا 5 وێنە)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `product_inventory_view`
-- (See below for the actual view)
--
CREATE TABLE `product_inventory_view` (
`id` int
,`user_id` int
,`name` varchar(200)
,`barcode` varchar(50)
,`primary_unit_id` bigint
,`primary_unit_name` varchar(50)
,`primary_unit_symbol` varchar(10)
,`secondary_unit_id` int
,`secondary_unit_name` varchar(50)
,`secondary_unit_symbol` varchar(10)
,`conversion_rate` decimal(10,3)
,`primary_stock` decimal(10,3)
,`secondary_stock` decimal(10,3)
,`primary_min_stock` bigint
,`secondary_min_stock` bigint
,`buy_price` decimal(10,3)
,`sell_price` decimal(10,3)
,`wholesale_price` decimal(10,3)
,`special_price` decimal(10,3)
,`calculated_secondary_stock` bigint
,`remaining_primary_units` decimal(10,3)
,`created_at` timestamp
,`updated_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `product_returns`
--

CREATE TABLE `product_returns` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `return_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int DEFAULT NULL,
  `customer_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_id` int NOT NULL,
  `product_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `unit_price` decimal(10,3) NOT NULL,
  `total_amount` decimal(10,3) NOT NULL,
  `return_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `return_type` enum('refund','exchange','store_credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'refund',
  `status` enum('pending','approved','completed','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `original_sale_id` int DEFAULT NULL,
  `refund_amount` decimal(10,3) DEFAULT '0.000',
  `return_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_by` int DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_units`
--

CREATE TABLE `product_units` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `unit_id` int NOT NULL,
  `buy_price` decimal(10,3) DEFAULT '0.000',
  `sell_price` decimal(10,3) DEFAULT '0.000',
  `wholesale_price` decimal(10,3) DEFAULT '0.000',
  `special_price` decimal(10,3) DEFAULT '0.000',
  `currency` enum('IQD','USD') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
  `stock_quantity` decimal(10,3) DEFAULT '0.000',
  `min_stock` int DEFAULT '0',
  `conversion_ratio` decimal(10,4) DEFAULT '1.0000' COMMENT 'ڕێژەی گۆڕینی یەکە بۆ یەکەکەی تر (گەورە/بچووک)',
  `conversion_rate` decimal(10,3) DEFAULT '1.000' COMMENT 'Conversion rate from this unit to primary unit (e.g., 10 for 10 pieces per package)',
  `is_primary` tinyint(1) DEFAULT '0' COMMENT 'Whether this is the primary unit for inventory calculations',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_receipts`
--

CREATE TABLE `purchase_receipts` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `company_id` int NOT NULL,
  `receipt_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_type` enum('cash','debt') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `receipt_date` date NOT NULL,
  `total_amount` decimal(14,3) NOT NULL DEFAULT '0.000',
  `discount_percent` decimal(10,3) NOT NULL DEFAULT '0.000' COMMENT 'ڕێژەی داشکاندنی گشتی بۆ وەسڵ',
  `discount_amount` decimal(10,3) DEFAULT '0.000',
  `additional_charges` decimal(10,3) DEFAULT '0.000',
  `final_amount` decimal(14,3) NOT NULL DEFAULT '0.000',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `inventory_price_strategy` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=تێکڕا، 0=جێگیر',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_receipt_items`
--

CREATE TABLE `purchase_receipt_items` (
  `id` int NOT NULL,
  `purchase_receipt_id` int NOT NULL,
  `product_id` int NOT NULL,
  `product_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `buy_price` decimal(14,3) NOT NULL,
  `sell_price` decimal(14,3) NOT NULL,
  `wholesale_price` decimal(14,3) DEFAULT '0.000',
  `special_price` decimal(14,3) DEFAULT '0.000',
  `expiry_date` date DEFAULT NULL,
  `total_cost` decimal(14,3) NOT NULL,
  `unit_id` int DEFAULT '0' COMMENT 'یەکەی هەڵبژێردراو بۆ کاڵاکە (0 = یەکەی بنەڕەتی)',
  `packet_bonus` decimal(10,3) DEFAULT NULL COMMENT 'بۆنسی پاکەت',
  `sheets_per_packet` int DEFAULT NULL COMMENT 'ژمارەی شیت لە هەر پاکەت',
  `discount_amount` decimal(15,3) NOT NULL DEFAULT '0.000' COMMENT 'بڕی داشکاندن بۆ ئەم ڕیزە',
  `revert_sheet_sell_price` decimal(14,3) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revert_buy_price` decimal(14,3) DEFAULT NULL COMMENT 'نرخی کۆگا پێش جێبەجێکردنی وەسڵ (جێگیر)',
  `revert_sell_price` decimal(14,3) DEFAULT NULL,
  `revert_wholesale_price` decimal(14,3) DEFAULT NULL,
  `revert_special_price` decimal(14,3) DEFAULT NULL,
  `revert_sheet_buy_price` decimal(14,3) DEFAULT NULL COMMENT 'مۆدی دەرمانخانە: شیت'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `user_type` enum('user','admin','sub') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'user',
  `token` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `selector` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `user_type` enum('main','sub') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'main',
  `sub_user_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `return_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_amount` decimal(10,3) NOT NULL,
  `discount` decimal(10,3) DEFAULT '0.000',
  `final_amount` decimal(10,3) NOT NULL,
  `payment_method` enum('cash','credit','debt','installment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `return_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `return_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `return_items`
--

CREATE TABLE `return_items` (
  `id` int NOT NULL,
  `return_id` int NOT NULL,
  `product_id` int NOT NULL,
  `product_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `unit_price` decimal(10,3) NOT NULL,
  `total_price` decimal(10,3) NOT NULL,
  `price_type` enum('retail','wholesale','special') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'retail',
  `unit_id` int DEFAULT NULL COMMENT 'ID یەکەی کاڵا لە units تەیبڵەکەدا',
  `unit_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ناوی یەکەی کاڵا',
  `unit_symbol` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'هێمای یەکەی کاڵا',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `user_type` enum('main','sub') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'main',
  `sub_user_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `invoice_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_amount` decimal(10,3) NOT NULL,
  `discount` decimal(10,3) DEFAULT '0.000',
  `final_amount` decimal(10,3) NOT NULL,
  `currency` enum('IQD','USD') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
  `payment_method` enum('cash','credit','debt','installment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `payment_status` enum('paid','pending','partial') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'paid',
  `paid_amount` decimal(10,3) DEFAULT '0.000',
  `remaining_amount` decimal(10,3) DEFAULT '0.000',
  `sale_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Updated to support credit payment method for customer debt tracking';

-- --------------------------------------------------------

--
-- Stand-in structure for view `sales_with_customers`
-- (See below for the actual view)
--
CREATE TABLE `sales_with_customers` (
`id` int
,`user_id` int
,`user_type` enum('main','sub')
,`sub_user_id` int
,`customer_id` int
,`invoice_number` varchar(50)
,`customer_name` varchar(100)
,`total_amount` decimal(10,3)
,`discount` decimal(10,3)
,`final_amount` decimal(10,3)
,`payment_method` enum('cash','credit','debt','installment')
,`payment_status` enum('paid','pending','partial')
,`paid_amount` decimal(10,3)
,`remaining_amount` decimal(10,3)
,`sale_date` timestamp
,`customer_full_name` varchar(100)
,`customer_phone` varchar(20)
,`customer_address` text
,`payment_method_text` varchar(4)
);

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int NOT NULL,
  `sale_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `unit_price` decimal(10,3) NOT NULL,
  `total_price` decimal(10,3) NOT NULL,
  `currency` enum('IQD','USD') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
  `price_type` enum('retail','wholesale','special') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'retail',
  `unit_id` int DEFAULT NULL COMMENT 'ID یەکەی کاڵا لە units تەیبڵەکەدا',
  `unit_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ناوی یەکەی کاڵا',
  `unit_symbol` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'هێمای یەکەی کاڵا',
  `conversion_rate` decimal(10,3) DEFAULT '1.000' COMMENT 'ڕێژەی گۆڕین بۆ یەکەی سەرەکی'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_item_external_costs`
--

CREATE TABLE `sale_item_external_costs` (
  `id` int NOT NULL,
  `sale_item_id` int NOT NULL,
  `buy_price` decimal(10,3) NOT NULL COMMENT 'نرخی کڕین بۆ یەک یەکە'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نرخی کڕینی ئایتمەکانی کاڵای دەرەکی';

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `business_type_id` int DEFAULT NULL COMMENT 'جۆری ئیش و کار',
  `business_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_header` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `receipt_footer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `receipt_size` enum('A4','thermal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'thermal',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'IQD',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `low_stock_alert` tinyint(1) DEFAULT '1',
  `expiry_alert_days` int DEFAULT '30',
  `a4_receipt_banner` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وێنەی بانەری سەرەوەی وەسڵی A4',
  `a4_receipt_notes` text COLLATE utf8mb4_unicode_ci COMMENT 'تێبینەکانی خوارەوەی وەسڵی A4',
  `receipt_banner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وێنەی بانەری وەسڵی کاشێر',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shelf_price_tags`
--

CREATE TABLE `shelf_price_tags` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ناوی تێمپلێت',
  `template_type` enum('simple','detailed','professional') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'simple' COMMENT 'جۆری تێمپلێت',
  `is_default` tinyint(1) DEFAULT '0' COMMENT 'ئایا تێمپلێتی بنەڕەتییە',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تێمپلێتەکانی نرخی سەر ڕەفە بۆ هەر بەکارهێنەرێک';

-- --------------------------------------------------------

--
-- Table structure for table `shelf_price_tag_items`
--

CREATE TABLE `shelf_price_tag_items` (
  `id` int NOT NULL,
  `template_id` int NOT NULL,
  `text_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ناوەڕۆکی دەق',
  `text_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'text' COMMENT 'جۆری دەق (text, product_name, price, barcode, date, business_name)',
  `font_size` int DEFAULT '12' COMMENT 'قەبارەی فۆنت',
  `font_weight` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'normal' COMMENT 'قەڵەویی فۆنت',
  `text_align` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'center' COMMENT 'ڕیزکردنی دەق (left, center, right)',
  `display_order` int NOT NULL DEFAULT '0' COMMENT 'ڕیزبەندی پیشاندان',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='دەقەکانی تێمپلێتی نرخی سەر ڕەفە';

-- --------------------------------------------------------

--
-- Table structure for table `shop_customer_access`
--

CREATE TABLE `shop_customer_access` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `access_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shop_visitors`
--

CREATE TABLE `shop_visitors` (
  `id` int NOT NULL,
  `shop_id` int NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `visit_count` int DEFAULT '1',
  `first_visit` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_visit` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sub_users`
--

CREATE TABLE `sub_users` (
  `id` int NOT NULL,
  `main_user_id` int NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `permissions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON string of permissions',
  `is_active` tinyint(1) DEFAULT '1',
  `expiration_date` timestamp NULL DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sub_user_activity_logs`
--

CREATE TABLE `sub_user_activity_logs` (
  `id` int NOT NULL,
  `sub_user_id` int NOT NULL,
  `main_user_id` int NOT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_balance_history`
--

CREATE TABLE `support_balance_history` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT 'ئایدی بەکارهێنەر',
  `admin_id` int DEFAULT NULL COMMENT 'ئایدی ئەدمین کە پاڵپشتیەکەی زیادکرد',
  `amount` decimal(10,3) NOT NULL COMMENT 'بڕی پارە',
  `payment_type` enum('cash','fastpay','fib','qi_card') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'جۆری پارەدان',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'تێبینی',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'بەروار و کات'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مێژووی زیادکردنی پاڵپشتی بەکارهێنەران';

-- --------------------------------------------------------

--
-- Stand-in structure for view `support_balance_summary`
-- (See below for the actual view)
--
CREATE TABLE `support_balance_summary` (
`user_id` int
,`business_name` varchar(200)
,`email` varchar(100)
,`phone` varchar(20)
,`current_balance` decimal(10,3)
,`total_added` decimal(32,3)
,`total_used` decimal(32,3)
,`total_additions` bigint
,`total_usages` bigint
,`last_addition_date` timestamp
,`last_usage_date` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `support_balance_usage`
--

CREATE TABLE `support_balance_usage` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT 'ئایدی بەکارهێنەر',
  `admin_id` int DEFAULT NULL COMMENT 'ئایدی ئەدمین کە پارەکەی کەمکردەوە',
  `amount` decimal(10,3) NOT NULL COMMENT 'بڕی پارەی بەکارهاتوو',
  `service_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'باسی خزمەتگوزاری',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'تێبینی',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'بەروار و کات'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مێژووی بەکارهێنانی پاڵپشتی بەکارهێنەران';

-- --------------------------------------------------------

--
-- Table structure for table `sync_conflicts`
--

CREATE TABLE `sync_conflicts` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `client_txn_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_id` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conflict_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conflict_message` text COLLATE utf8mb4_unicode_ci,
  `payload_json` json DEFAULT NULL,
  `status` enum('needs_review','resolved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'needs_review',
  `resolution_note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sync_transactions`
--

CREATE TABLE `sync_transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `client_txn_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_id` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('synced','duplicate','conflict','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'synced',
  `sale_id` int DEFAULT NULL,
  `response_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `telegram_logs`
--

CREATE TABLE `telegram_logs` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `message_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'جۆری پەیام (debt_report, etc)',
  `telegram_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('success','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'success',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `symbol` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `business_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telegram_sent` tinyint(1) DEFAULT '0',
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `package_id` int DEFAULT NULL,
  `organization_id` int DEFAULT NULL COMMENT 'گرێدان بۆ organizations.id — NULL = بزنسی تاک',
  `expiration_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` timestamp NULL DEFAULT NULL,
  `telegram_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ئایدی تیلیگرامی بەکارهێنەر',
  `telegram_last_sent` timestamp NULL DEFAULT NULL COMMENT 'دوایین جاری ناردنی زانیاری بە تیلیگرام',
  `ai_balance` decimal(10,2) DEFAULT '0.00' COMMENT 'باڵانسی AI بە دۆلار',
  `support_balance` decimal(10,3) DEFAULT '0.000' COMMENT 'بڕی پاڵپشتی بەکارهێنەر بە دینار'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_a4_field_settings`
--

CREATE TABLE `user_a4_field_settings` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `fields` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_abac_policies`
--

CREATE TABLE `user_abac_policies` (
  `id` int UNSIGNED NOT NULL,
  `subject_type` enum('user','sub_user') COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_id` int NOT NULL,
  `main_user_id` int NOT NULL,
  `resource` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conditions_json` json DEFAULT NULL,
  `effect` enum('allow','deny') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allow',
  `priority` int NOT NULL DEFAULT '100',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `website_product_visibility`
--

CREATE TABLE `website_product_visibility` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `is_visible` tinyint(1) DEFAULT '1' COMMENT 'نیشاندان لە وێب سایت',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `website_settings`
--

CREATE TABLE `website_settings` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `website_slug` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'یوزەری یەکتا بۆ URL',
  `is_active` tinyint(1) DEFAULT '0' COMMENT 'چالاککردنی وێب سایت',
  `show_on_index` tinyint(1) DEFAULT '0' COMMENT 'نیشاندان لە لیستی سەرەکی (web/index.php)',
  `show_product_images` tinyint(1) DEFAULT '1' COMMENT 'پیشاندانی وێنەی کاڵاکان',
  `show_prices` tinyint(1) DEFAULT '1' COMMENT 'پیشاندانی نرخەکان',
  `show_retail_price` tinyint(1) DEFAULT '1' COMMENT 'نرخی تاک',
  `show_wholesale_price` tinyint(1) DEFAULT '1' COMMENT 'نرخی جوملە',
  `show_special_price` tinyint(1) DEFAULT '0' COMMENT 'نرخی تایبەت',
  `show_by_category` tinyint(1) DEFAULT '1' COMMENT 'پیشاندان بە کەتەلۆگ',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `shop_google_restrict` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = فرۆشگا تەنها بە Gmail ڕێگەپێدراو و بەرواری دروست',
  `show_only_with_images` tinyint(1) DEFAULT '0',
  `show_stock_quantity` tinyint(1) DEFAULT '1' COMMENT 'پیشاندانی بڕی بەردەست',
  `product_display_order` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'random',
  `shop_banner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وێنەی بانەری لاپەڕەی فرۆشگا',
  `show_shop_exit_button` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=show دەرچوون in shop nav'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `web_customers`
--

CREATE TABLE `web_customers` (
  `id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Online shop customers';

-- --------------------------------------------------------

--
-- Table structure for table `web_orders`
--

CREATE TABLE `web_orders` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `guest_session_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Session ID for guest customers',
  `guest_ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP address of guest customer',
  `website_slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_address` text COLLATE utf8mb4_unicode_ci,
  `items` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON data of ordered items',
  `total_amount` decimal(10,2) NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','completed','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Online shop orders';

-- --------------------------------------------------------

--
-- Table structure for table `web_visitors`
--

CREATE TABLE `web_visitors` (
  `id` int NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `visit_count` int DEFAULT '1',
  `first_visit` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_visit` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `idx_action_type` (`action_type`),
  ADD KEY `idx_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_user_action_type` (`user_id`,`action_type`),
  ADD KEY `idx_created_at_action` (`created_at`,`action_type`),
  ADD KEY `idx_user_created_at` (`user_id`,`created_at`),
  ADD KEY `idx_action_created_at` (`action`,`created_at`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `agents`
--
ALTER TABLE `agents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_agent_code` (`agent_code`),
  ADD UNIQUE KEY `uniq_agent_email` (`email`),
  ADD KEY `idx_agents_active` (`is_active`);

--
-- Indexes for table `agent_registrations`
--
ALTER TABLE `agent_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_registered_user` (`registered_user_id`),
  ADD KEY `idx_agent_id` (`agent_id`),
  ADD KEY `idx_agent_created_at` (`agent_id`,`created_at`);

--
-- Indexes for table `ai_balance_history`
--
ALTER TABLE `ai_balance_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_balance` (`user_id`,`created_at`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_tokens` (`input_tokens`,`output_tokens`);

--
-- Indexes for table `ai_settings`
--
ALTER TABLE `ai_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `barcode_templates`
--
ALTER TABLE `barcode_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_template_name` (`user_id`,`name`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_user_default` (`user_id`,`is_default`);

--
-- Indexes for table `business_images`
--
ALTER TABLE `business_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `business_types`
--
ALTER TABLE `business_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_code` (`code`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_categories_website_visibility` (`user_id`,`is_visible_on_website`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session_messages` (`session_id`,`created_at`);

--
-- Indexes for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_sessions` (`user_id`,`created_at`),
  ADD KEY `idx_section` (`section`);

--
-- Indexes for table `chat_usage`
--
ALTER TABLE `chat_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `message_id` (`message_id`),
  ADD KEY `idx_session_usage` (`session_id`),
  ADD KEY `idx_cost_tracking` (`created_at`,`cost_usd`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `name` (`name`);

--
-- Indexes for table `company_debts`
--
ALTER TABLE `company_debts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `date` (`date`),
  ADD KEY `type` (`type`),
  ADD KEY `idx_company_debts_purchase_receipt_id` (`purchase_receipt_id`);

--
-- Indexes for table `currency_exchange_rates`
--
ALTER TABLE `currency_exchange_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_currency` (`user_id`,`from_currency`,`to_currency`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_customers_status` (`user_id`,`status`),
  ADD KEY `idx_name_phone` (`name`,`phone`);
ALTER TABLE `customers` ADD FULLTEXT KEY `name` (`name`,`notes`);

--
-- Indexes for table `customer_cash_purchases`
--
ALTER TABLE `customer_cash_purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_customer` (`user_id`,`customer_id`),
  ADD KEY `idx_user_date` (`user_id`,`purchase_date`),
  ADD KEY `idx_customer_date` (`customer_id`,`purchase_date`),
  ADD KEY `idx_sale_id` (`sale_id`),
  ADD KEY `idx_invoice_number` (`invoice_number`);

--
-- Indexes for table `customer_gmail_links`
--
ALTER TABLE `customer_gmail_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_customer` (`user_id`,`customer_id`),
  ADD KEY `idx_user_gmail` (`user_id`,`gmail`),
  ADD KEY `fk_customer_gmail_links_customer` (`customer_id`);

--
-- Indexes for table `customer_money_debts`
--
ALTER TABLE `customer_money_debts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_user_customer` (`user_id`,`customer_id`),
  ADD KEY `idx_type_date` (`type`,`date`);

--
-- Indexes for table `debts`
--
ALTER TABLE `debts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_debts_status` (`user_id`,`status`),
  ADD KEY `idx_debt_remaining` (`remaining_amount`),
  ADD KEY `idx_customer_status` (`customer_id`,`status`);

--
-- Indexes for table `debt_payments`
--
ALTER TABLE `debt_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `debt_id` (`debt_id`);

--
-- Indexes for table `debt_receipts`
--
ALTER TABLE `debt_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `debt_payment_id` (`debt_payment_id`),
  ADD KEY `idx_user_date` (`user_id`,`receipt_date`);

--
-- Indexes for table `dollar_prices`
--
ALTER TABLE `dollar_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_city` (`city_name`),
  ADD KEY `idx_last_updated` (`last_updated`),
  ADD KEY `idx_city_name` (`city_name`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expense_type_id` (`expense_type_id`),
  ADD KEY `idx_user_date` (`user_id`,`expense_date`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_recurring` (`is_recurring`);

--
-- Indexes for table `expense_credits`
--
ALTER TABLE `expense_credits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expense_id` (`expense_id`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_due_date` (`due_date`);

--
-- Indexes for table `expense_credit_payments`
--
ALTER TABLE `expense_credit_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expense_credit_id` (`expense_credit_id`),
  ADD KEY `idx_payment_date` (`payment_date`);

--
-- Indexes for table `expense_types`
--
ALTER TABLE `expense_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_user_recurring` (`user_id`,`is_recurring`);

--
-- Indexes for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `idx_inventory_adjustments_user_date` (`user_id`,`created_at`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `version` (`version`);

--
-- Indexes for table `notebook_attachments`
--
ALTER TABLE `notebook_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_entry_id` (`entry_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `notebook_entries`
--
ALTER TABLE `notebook_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_topic_id` (`topic_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_is_favorite` (`is_favorite`),
  ADD KEY `idx_is_archived` (`is_archived`);

--
-- Indexes for table `notebook_fields`
--
ALTER TABLE `notebook_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_topic_id` (`topic_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_field_order` (`field_order`);

--
-- Indexes for table `notebook_topics`
--
ALTER TABLE `notebook_topics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_sort_order` (`sort_order`);

--
-- Indexes for table `packages`
--
ALTER TABLE `packages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `package_feature_permissions`
--
ALTER TABLE `package_feature_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_package_feature` (`package_id`,`feature_key`),
  ADD KEY `idx_feature_key` (`feature_key`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_user_category` (`user_id`,`category_id`),
  ADD KEY `idx_barcode` (`barcode`),
  ADD KEY `idx_expiry` (`expiry_date`),
  ADD KEY `idx_products_user_category` (`user_id`,`category_id`);

--
-- Indexes for table `product_barcodes`
--
ALTER TABLE `product_barcodes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_product_barcode` (`product_id`,`barcode`),
  ADD KEY `idx_barcode_lookup` (`barcode`),
  ADD KEY `idx_product` (`product_id`),
  ADD KEY `idx_barcode_user_lookup` (`barcode`,`product_id`);

--
-- Indexes for table `product_details`
--
ALTER TABLE `product_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_product` (`product_id`),
  ADD KEY `idx_product_details_product_id` (`product_id`);

--
-- Indexes for table `product_returns`
--
ALTER TABLE `product_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `original_sale_id` (`original_sale_id`),
  ADD KEY `idx_user_date` (`user_id`,`return_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_return_reason` (`return_reason`),
  ADD KEY `idx_return_type` (`return_type`);

--
-- Indexes for table `product_units`
--
ALTER TABLE `product_units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `idx_product_primary` (`product_id`,`is_primary`),
  ADD KEY `idx_conversion_rate` (`conversion_rate`),
  ADD KEY `idx_product_units_product_primary` (`product_id`,`is_primary`);

--
-- Indexes for table `purchase_receipts`
--
ALTER TABLE `purchase_receipts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `receipt_date` (`receipt_date`),
  ADD KEY `payment_type` (`payment_type`);

--
-- Indexes for table `purchase_receipt_items`
--
ALTER TABLE `purchase_receipt_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_receipt_id` (`purchase_receipt_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `selector` (`selector`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `return_date` (`return_date`),
  ADD KEY `idx_returns_user_date` (`user_id`,`return_date`),
  ADD KEY `idx_returns_customer` (`customer_id`,`return_date`);

--
-- Indexes for table `return_items`
--
ALTER TABLE `return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `return_id` (`return_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_return_items_product` (`product_id`,`return_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `idx_user_date` (`user_id`,`sale_date`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `sub_user_id` (`sub_user_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_sale_items_unit_id` (`unit_id`);

--
-- Indexes for table `sale_item_external_costs`
--
ALTER TABLE `sale_item_external_costs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_sale_item` (`sale_item_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_settings_business_type` (`business_type_id`);

--
-- Indexes for table `shelf_price_tags`
--
ALTER TABLE `shelf_price_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_template_name` (`user_id`,`name`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`);

--
-- Indexes for table `shelf_price_tag_items`
--
ALTER TABLE `shelf_price_tag_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_template_id` (`template_id`),
  ADD KEY `idx_display_order` (`template_id`,`display_order`);

--
-- Indexes for table `shop_customer_access`
--
ALTER TABLE `shop_customer_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_customer` (`user_id`,`customer_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_customer_id` (`customer_id`);

--
-- Indexes for table `shop_visitors`
--
ALTER TABLE `shop_visitors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_shop_visitor` (`shop_id`,`ip_address`,`user_agent`),
  ADD KEY `idx_shop_id` (`shop_id`);

--
-- Indexes for table `sub_users`
--
ALTER TABLE `sub_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `main_user_id` (`main_user_id`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `idx_expiration_date` (`expiration_date`);

--
-- Indexes for table `sub_user_activity_logs`
--
ALTER TABLE `sub_user_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sub_user_id` (`sub_user_id`),
  ADD KEY `main_user_id` (`main_user_id`),
  ADD KEY `action` (`action`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `support_balance_history`
--
ALTER TABLE `support_balance_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `support_balance_usage`
--
ALTER TABLE `support_balance_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `sync_conflicts`
--
ALTER TABLE `sync_conflicts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conflict_user_status` (`user_id`,`status`),
  ADD KEY `idx_conflict_txn` (`user_id`,`client_txn_id`);

--
-- Indexes for table `sync_transactions`
--
ALTER TABLE `sync_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_sync_user_txn` (`user_id`,`client_txn_id`),
  ADD KEY `idx_sync_status` (`user_id`,`status`),
  ADD KEY `idx_sync_created` (`created_at`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `telegram_logs`
--
ALTER TABLE `telegram_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_sent_at` (`sent_at`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_default` (`is_default`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_expiration_date` (`expiration_date`),
  ADD KEY `package_id` (`package_id`),
  ADD KEY `idx_telegram_id` (`telegram_id`),
  ADD KEY `idx_users_organization` (`organization_id`);

--
-- Indexes for table `organizations`
--
ALTER TABLE `organizations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_org_owner` (`owner_user_id`);

--
-- Indexes for table `user_a4_field_settings`
--
ALTER TABLE `user_a4_field_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `user_abac_policies`
--
ALTER TABLE `user_abac_policies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_abac_subject` (`subject_type`,`subject_id`,`is_active`),
  ADD KEY `idx_abac_main_user` (`main_user_id`),
  ADD KEY `idx_abac_resource_action` (`resource`,`action`),
  ADD KEY `idx_abac_priority` (`priority`);

--
-- Indexes for table `website_product_visibility`
--
ALTER TABLE `website_product_visibility`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_product` (`user_id`,`product_id`),
  ADD KEY `website_product_visibility_ibfk_2` (`product_id`);

--
-- Indexes for table `website_settings`
--
ALTER TABLE `website_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_slug` (`user_id`),
  ADD UNIQUE KEY `unique_website_slug` (`website_slug`);

--
-- Indexes for table `web_customers`
--
ALTER TABLE `web_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_phone` (`phone`);

--
-- Indexes for table `web_orders`
--
ALTER TABLE `web_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `website_slug` (`website_slug`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_guest_session` (`guest_session_id`,`guest_ip_address`),
  ADD KEY `idx_web_orders_dedupe` (`user_id`,`website_slug`,`customer_phone`,`request_token`);

--
-- Indexes for table `web_visitors`
--
ALTER TABLE `web_visitors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_visitor` (`ip_address`,`user_agent`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agents`
--
ALTER TABLE `agents`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agent_registrations`
--
ALTER TABLE `agent_registrations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_balance_history`
--
ALTER TABLE `ai_balance_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_settings`
--
ALTER TABLE `ai_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `barcode_templates`
--
ALTER TABLE `barcode_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `business_images`
--
ALTER TABLE `business_images`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `business_types`
--
ALTER TABLE `business_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_usage`
--
ALTER TABLE `chat_usage`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_debts`
--
ALTER TABLE `company_debts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `currency_exchange_rates`
--
ALTER TABLE `currency_exchange_rates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_cash_purchases`
--
ALTER TABLE `customer_cash_purchases`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_gmail_links`
--
ALTER TABLE `customer_gmail_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_money_debts`
--
ALTER TABLE `customer_money_debts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `debts`
--
ALTER TABLE `debts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `debt_payments`
--
ALTER TABLE `debt_payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `debt_receipts`
--
ALTER TABLE `debt_receipts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dollar_prices`
--
ALTER TABLE `dollar_prices`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_credits`
--
ALTER TABLE `expense_credits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_credit_payments`
--
ALTER TABLE `expense_credit_payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_types`
--
ALTER TABLE `expense_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notebook_attachments`
--
ALTER TABLE `notebook_attachments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notebook_entries`
--
ALTER TABLE `notebook_entries`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notebook_fields`
--
ALTER TABLE `notebook_fields`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notebook_topics`
--
ALTER TABLE `notebook_topics`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `packages`
--
ALTER TABLE `packages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `package_feature_permissions`
--
ALTER TABLE `package_feature_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_barcodes`
--
ALTER TABLE `product_barcodes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_details`
--
ALTER TABLE `product_details`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_returns`
--
ALTER TABLE `product_returns`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_units`
--
ALTER TABLE `product_units`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_receipts`
--
ALTER TABLE `purchase_receipts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_receipt_items`
--
ALTER TABLE `purchase_receipt_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `return_items`
--
ALTER TABLE `return_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_item_external_costs`
--
ALTER TABLE `sale_item_external_costs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shelf_price_tags`
--
ALTER TABLE `shelf_price_tags`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shelf_price_tag_items`
--
ALTER TABLE `shelf_price_tag_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shop_customer_access`
--
ALTER TABLE `shop_customer_access`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shop_visitors`
--
ALTER TABLE `shop_visitors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sub_users`
--
ALTER TABLE `sub_users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sub_user_activity_logs`
--
ALTER TABLE `sub_user_activity_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_balance_history`
--
ALTER TABLE `support_balance_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_balance_usage`
--
ALTER TABLE `support_balance_usage`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sync_conflicts`
--
ALTER TABLE `sync_conflicts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sync_transactions`
--
ALTER TABLE `sync_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `telegram_logs`
--
ALTER TABLE `telegram_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_a4_field_settings`
--
ALTER TABLE `user_a4_field_settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_abac_policies`
--
ALTER TABLE `user_abac_policies`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `website_product_visibility`
--
ALTER TABLE `website_product_visibility`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `website_settings`
--
ALTER TABLE `website_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `web_customers`
--
ALTER TABLE `web_customers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `web_orders`
--
ALTER TABLE `web_orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `web_visitors`
--
ALTER TABLE `web_visitors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `customer_debt_summary`
--
DROP TABLE IF EXISTS `customer_debt_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`test_test`@`localhost` SQL SECURITY DEFINER VIEW `customer_debt_summary`  AS SELECT `c`.`id` AS `customer_id`, `c`.`user_id` AS `user_id`, `c`.`name` AS `customer_name`, `c`.`phone` AS `phone`, `c`.`address` AS `address`, count(`d`.`id`) AS `total_debts`, coalesce(sum((case when (`d`.`status` = 'active') then `d`.`remaining_amount` else 0 end)),0) AS `total_debt_amount`, coalesce(sum((case when (`d`.`status` = 'active') then `d`.`paid_amount` else 0 end)),0) AS `total_paid_amount`, max(`d`.`created_at`) AS `last_debt_date` FROM (`customers` `c` left join `debts` `d` on((`c`.`id` = `d`.`customer_id`))) WHERE (`c`.`status` = 'active') GROUP BY `c`.`id`, `c`.`user_id`, `c`.`name`, `c`.`phone`, `c`.`address` ;

-- --------------------------------------------------------

--
-- Structure for view `product_inventory_view`
--
DROP TABLE IF EXISTS `product_inventory_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`test_test`@`localhost` SQL SECURITY INVOKER VIEW `product_inventory_view`  AS SELECT `p`.`id` AS `id`, `p`.`user_id` AS `user_id`, `p`.`name` AS `name`, `p`.`barcode` AS `barcode`, coalesce(`pu_primary`.`unit_id`,`pu_fallback`.`unit_id`) AS `primary_unit_id`, `pu_meta_primary`.`name` AS `primary_unit_name`, `pu_meta_primary`.`symbol` AS `primary_unit_symbol`, `pu_secondary`.`unit_id` AS `secondary_unit_id`, `pu_meta_secondary`.`name` AS `secondary_unit_name`, `pu_meta_secondary`.`symbol` AS `secondary_unit_symbol`, coalesce(nullif(`pu_secondary`.`conversion_rate`,0),1.000) AS `conversion_rate`, coalesce(`pu_primary`.`stock_quantity`,`pu_fallback`.`stock_quantity`,0.000) AS `primary_stock`, coalesce(`pu_secondary`.`stock_quantity`,0.000) AS `secondary_stock`, coalesce(`pu_primary`.`min_stock`,`pu_fallback`.`min_stock`,0) AS `primary_min_stock`, coalesce(`pu_secondary`.`min_stock`,0) AS `secondary_min_stock`, coalesce(`pu_primary`.`buy_price`,`pu_fallback`.`buy_price`,0.000) AS `buy_price`, coalesce(`pu_primary`.`sell_price`,`pu_fallback`.`sell_price`,0.000) AS `sell_price`, coalesce(`pu_primary`.`wholesale_price`,`pu_fallback`.`wholesale_price`,0.000) AS `wholesale_price`, coalesce(`pu_primary`.`special_price`,`pu_fallback`.`special_price`,0.000) AS `special_price`, floor((coalesce(`pu_primary`.`stock_quantity`,`pu_fallback`.`stock_quantity`,0.000) / coalesce(nullif(`pu_secondary`.`conversion_rate`,0),1.000))) AS `calculated_secondary_stock`, (coalesce(`pu_primary`.`stock_quantity`,`pu_fallback`.`stock_quantity`,0.000) % coalesce(nullif(`pu_secondary`.`conversion_rate`,0),1.000)) AS `remaining_primary_units`, `p`.`created_at` AS `created_at`, `p`.`updated_at` AS `updated_at` FROM (((((`products` `p` left join `product_units` `pu_primary` on(((`pu_primary`.`product_id` = `p`.`id`) and (`pu_primary`.`is_primary` = 1)))) left join `product_units` `pu_fallback` on((`pu_fallback`.`id` = (select `pu2`.`id` from `product_units` `pu2` where (`pu2`.`product_id` = `p`.`id`) order by `pu2`.`is_primary` desc,`pu2`.`id` limit 1)))) left join `product_units` `pu_secondary` on((`pu_secondary`.`id` = (select `pu3`.`id` from `product_units` `pu3` where ((`pu3`.`product_id` = `p`.`id`) and (`pu3`.`is_primary` = 0)) order by `pu3`.`id` limit 1)))) left join `units` `pu_meta_primary` on((`pu_meta_primary`.`id` = coalesce(`pu_primary`.`unit_id`,`pu_fallback`.`unit_id`)))) left join `units` `pu_meta_secondary` on((`pu_meta_secondary`.`id` = `pu_secondary`.`unit_id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `sales_with_customers`
--
DROP TABLE IF EXISTS `sales_with_customers`;

CREATE ALGORITHM=UNDEFINED DEFINER=`test_test`@`localhost` SQL SECURITY DEFINER VIEW `sales_with_customers`  AS SELECT `s`.`id` AS `id`, `s`.`user_id` AS `user_id`, `s`.`user_type` AS `user_type`, `s`.`sub_user_id` AS `sub_user_id`, `s`.`customer_id` AS `customer_id`, `s`.`invoice_number` AS `invoice_number`, `s`.`customer_name` AS `customer_name`, `s`.`total_amount` AS `total_amount`, `s`.`discount` AS `discount`, `s`.`final_amount` AS `final_amount`, `s`.`payment_method` AS `payment_method`, `s`.`payment_status` AS `payment_status`, `s`.`paid_amount` AS `paid_amount`, `s`.`remaining_amount` AS `remaining_amount`, `s`.`sale_date` AS `sale_date`, `c`.`name` AS `customer_full_name`, `c`.`phone` AS `customer_phone`, `c`.`address` AS `customer_address`, (case when (`s`.`payment_method` = 'cash') then 'نەقد' when (`s`.`payment_method` = 'debt') then 'قەرز' when (`s`.`payment_method` = 'installment') then 'قیست' else 'نامۆ' end) AS `payment_method_text` FROM (`sales` `s` left join `customers` `c` on((`s`.`customer_id` = `c`.`id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `support_balance_summary`
--
DROP TABLE IF EXISTS `support_balance_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`test_test`@`localhost` SQL SECURITY DEFINER VIEW `support_balance_summary`  AS SELECT `u`.`id` AS `user_id`, `u`.`business_name` AS `business_name`, `u`.`email` AS `email`, `u`.`phone` AS `phone`, `u`.`support_balance` AS `current_balance`, coalesce(sum(`sbh`.`amount`),0) AS `total_added`, coalesce(sum(`sbu`.`amount`),0) AS `total_used`, count(distinct `sbh`.`id`) AS `total_additions`, count(distinct `sbu`.`id`) AS `total_usages`, max(`sbh`.`created_at`) AS `last_addition_date`, max(`sbu`.`created_at`) AS `last_usage_date` FROM ((`users` `u` left join `support_balance_history` `sbh` on((`u`.`id` = `sbh`.`user_id`))) left join `support_balance_usage` `sbu` on((`u`.`id` = `sbu`.`user_id`))) GROUP BY `u`.`id`, `u`.`business_name`, `u`.`email`, `u`.`phone`, `u`.`support_balance` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `activity_logs_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `agent_registrations`
--
ALTER TABLE `agent_registrations`
  ADD CONSTRAINT `fk_agent_registrations_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_agent_registrations_user` FOREIGN KEY (`registered_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ai_balance_history`
--
ALTER TABLE `ai_balance_history`
  ADD CONSTRAINT `ai_balance_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `barcode_templates`
--
ALTER TABLE `barcode_templates`
  ADD CONSTRAINT `barcode_templates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `business_images`
--
ALTER TABLE `business_images`
  ADD CONSTRAINT `business_images_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  ADD CONSTRAINT `chat_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_usage`
--
ALTER TABLE `chat_usage`
  ADD CONSTRAINT `chat_usage_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_usage_ibfk_2` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `companies`
--
ALTER TABLE `companies`
  ADD CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `company_debts`
--
ALTER TABLE `company_debts`
  ADD CONSTRAINT `company_debts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `company_debts_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_company_debts_purchase_receipt` FOREIGN KEY (`purchase_receipt_id`) REFERENCES `purchase_receipts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_cash_purchases`
--
ALTER TABLE `customer_cash_purchases`
  ADD CONSTRAINT `customer_cash_purchases_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_cash_purchases_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_cash_purchases_ibfk_3` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_gmail_links`
--
ALTER TABLE `customer_gmail_links`
  ADD CONSTRAINT `fk_customer_gmail_links_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_customer_gmail_links_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_money_debts`
--
ALTER TABLE `customer_money_debts`
  ADD CONSTRAINT `customer_money_debts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_money_debts_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `debts`
--
ALTER TABLE `debts`
  ADD CONSTRAINT `debts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `debts_ibfk_2` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `debts_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `debt_payments`
--
ALTER TABLE `debt_payments`
  ADD CONSTRAINT `debt_payments_ibfk_1` FOREIGN KEY (`debt_id`) REFERENCES `debts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `debt_receipts`
--
ALTER TABLE `debt_receipts`
  ADD CONSTRAINT `debt_receipts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `debt_receipts_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `debt_receipts_ibfk_3` FOREIGN KEY (`debt_payment_id`) REFERENCES `debt_payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`expense_type_id`) REFERENCES `expense_types` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expense_credits`
--
ALTER TABLE `expense_credits`
  ADD CONSTRAINT `expense_credits_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expense_credits_ibfk_2` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expense_credit_payments`
--
ALTER TABLE `expense_credit_payments`
  ADD CONSTRAINT `expense_credit_payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expense_credit_payments_ibfk_2` FOREIGN KEY (`expense_credit_id`) REFERENCES `expense_credits` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expense_types`
--
ALTER TABLE `expense_types`
  ADD CONSTRAINT `expense_types_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_adjustments`
--
ALTER TABLE `inventory_adjustments`
  ADD CONSTRAINT `inventory_adjustments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_adjustments_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_adjustments_ibfk_3` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notebook_attachments`
--
ALTER TABLE `notebook_attachments`
  ADD CONSTRAINT `notebook_attachments_ibfk_1` FOREIGN KEY (`entry_id`) REFERENCES `notebook_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notebook_attachments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notebook_entries`
--
ALTER TABLE `notebook_entries`
  ADD CONSTRAINT `notebook_entries_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `notebook_topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notebook_entries_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notebook_fields`
--
ALTER TABLE `notebook_fields`
  ADD CONSTRAINT `notebook_fields_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `notebook_topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notebook_fields_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notebook_topics`
--
ALTER TABLE `notebook_topics`
  ADD CONSTRAINT `notebook_topics_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `package_feature_permissions`
--
ALTER TABLE `package_feature_permissions`
  ADD CONSTRAINT `fk_package_feature_permissions_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `products_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_barcodes`
--
ALTER TABLE `product_barcodes`
  ADD CONSTRAINT `product_barcodes_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_details`
--
ALTER TABLE `product_details`
  ADD CONSTRAINT `fk_product_details_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_returns`
--
ALTER TABLE `product_returns`
  ADD CONSTRAINT `product_returns_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_returns_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_returns_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_returns_ibfk_4` FOREIGN KEY (`original_sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_units`
--
ALTER TABLE `product_units`
  ADD CONSTRAINT `product_units_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_units_ibfk_2` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_receipts`
--
ALTER TABLE `purchase_receipts`
  ADD CONSTRAINT `purchase_receipts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_receipts_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_receipt_items`
--
ALTER TABLE `purchase_receipt_items`
  ADD CONSTRAINT `purchase_receipt_items_ibfk_1` FOREIGN KEY (`purchase_receipt_id`) REFERENCES `purchase_receipts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_receipt_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `return_items`
--
ALTER TABLE `return_items`
  ADD CONSTRAINT `return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `return_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`sub_user_id`) REFERENCES `sub_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_ibfk_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_item_external_costs`
--
ALTER TABLE `sale_item_external_costs`
  ADD CONSTRAINT `fk_external_costs_sale_item` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `settings`
--
ALTER TABLE `settings`
  ADD CONSTRAINT `fk_settings_business_type` FOREIGN KEY (`business_type_id`) REFERENCES `business_types` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shelf_price_tags`
--
ALTER TABLE `shelf_price_tags`
  ADD CONSTRAINT `shelf_price_tags_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shelf_price_tag_items`
--
ALTER TABLE `shelf_price_tag_items`
  ADD CONSTRAINT `shelf_price_tag_items_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `shelf_price_tags` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sub_users`
--
ALTER TABLE `sub_users`
  ADD CONSTRAINT `sub_users_ibfk_1` FOREIGN KEY (`main_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sub_user_activity_logs`
--
ALTER TABLE `sub_user_activity_logs`
  ADD CONSTRAINT `sub_user_activity_logs_ibfk_1` FOREIGN KEY (`sub_user_id`) REFERENCES `sub_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sub_user_activity_logs_ibfk_2` FOREIGN KEY (`main_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `support_balance_history`
--
ALTER TABLE `support_balance_history`
  ADD CONSTRAINT `fk_support_history_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_support_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `support_balance_usage`
--
ALTER TABLE `support_balance_usage`
  ADD CONSTRAINT `fk_support_usage_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_support_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `telegram_logs`
--
ALTER TABLE `telegram_logs`
  ADD CONSTRAINT `telegram_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `website_product_visibility`
--
ALTER TABLE `website_product_visibility`
  ADD CONSTRAINT `website_product_visibility_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `website_product_visibility_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `website_settings`
--
ALTER TABLE `website_settings`
  ADD CONSTRAINT `website_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `web_orders`
--
ALTER TABLE `web_orders`
  ADD CONSTRAINT `fk_web_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `web_customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `web_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
