-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Apr 28, 2026 at 04:25 AM
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
-- Database: `kasher_z`
--

-- --------------------------------------------------------

--
-- Table structure for table `google_users`
--

CREATE TABLE `google_users` (
  `id` int NOT NULL,
  `google_sub` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Google user unique ID (sub)',
  `email` varchar(320) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `given_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `family_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `picture` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locale` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hd` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Hosted domain (if any)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'First time we saw this Google user',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last time this user logged in',
  `last_login_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_login_user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_account_settings`
--

CREATE TABLE `user_account_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL COMMENT 'ID ی بەکارهێنەری سەرەکی لە خشتەی users (داتابەیسی ئەپ)',
  `pos_show_zero_stock_products` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=نیشاندانی کاڵای ڕێژە سفر لە POS، 0=شاردراوە',
  `purchases_use_weighted_avg_prices` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=نرخی تێکڕا بۆ کۆگا لە وەسڵ، 0=نرخی جێگیر وەک ڕیزی وەسڵ',
  `recognize_customer_debt_revenue_at_sale` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=داهاتی قەرز لە ڕۆژی فرۆشتن، 0=داهات تەنها لە کاتی پارەدانەوە',
  `receipt_a4_items_font_size` tinyint UNSIGNED NOT NULL DEFAULT '16' COMMENT 'قەبارەی فۆنتی خشتەی کاڵاکان لە وەسڵی A4 (پیکسڵ، نموونە 10-22)',
  `pos_default_sale_currency` enum('IQD','USD') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' COMMENT 'دیفۆڵتی دراو لە بەشی فرۆشتن',
  `pos_default_price_type` enum('retail','wholesale','special') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'retail' COMMENT 'دیفۆڵتی بنەڕەتی نرخ لە POS',
  `pos_default_payment_is_credit` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=دیفۆڵت قەرز، 0=دیفۆڵت کاش',
  `theme_mode` enum('light','dark','system') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system' COMMENT 'دۆخی ڕووکار بۆ بەکارهێنەر',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ڕێکخستنەکانی هەژمار؛ فراوانکردنی داهاتوو بە ستوونی نوێ';

-- --------------------------------------------------------

--
-- Table structure for table `video_comments`
--

CREATE TABLE `video_comments` (
  `id` bigint UNSIGNED NOT NULL,
  `video_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `video_id` bigint UNSIGNED NOT NULL,
  `google_user_id` int NOT NULL,
  `parent_id` bigint UNSIGNED DEFAULT NULL,
  `comment_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `video_likes`
--

CREATE TABLE `video_likes` (
  `id` int NOT NULL,
  `video_type` enum('free','product') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'جۆری ڤیدیۆ (free یان product)',
  `video_id` int NOT NULL COMMENT 'ID ی ڤیدیۆ لە free_videos یان product_videos',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `google_user_id` int DEFAULT NULL COMMENT 'پەیوەندیدار بگە بە google_users.id',
  `liked_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `video_views`
--

CREATE TABLE `video_views` (
  `id` int NOT NULL,
  `video_type` enum('free','product') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'جۆری ڤیدیۆ (free یان product)',
  `video_id` int NOT NULL COMMENT 'ID ی ڤیدیۆ لە free_videos یان product_videos',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `google_user_id` int DEFAULT NULL COMMENT 'پەیوەندیدار بگە بە google_users.id',
  `viewed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `watch_seconds` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'کۆی کاتی بینینی ئەم ڤیدیۆیە بەپێی بینەر بە چرکە'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `google_users`
--
ALTER TABLE `google_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_google_sub` (`google_sub`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `user_account_settings`
--
ALTER TABLE `user_account_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_id` (`user_id`);

--
-- Indexes for table `video_comments`
--
ALTER TABLE `video_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_video` (`video_type`,`video_id`),
  ADD KEY `idx_parent` (`parent_id`),
  ADD KEY `idx_google_user` (`google_user_id`);

--
-- Indexes for table `video_likes`
--
ALTER TABLE `video_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_video_like_guest` (`video_type`,`video_id`,`ip_address`,`user_agent`),
  ADD UNIQUE KEY `unique_video_like_google` (`video_type`,`video_id`,`google_user_id`),
  ADD KEY `idx_video` (`video_type`,`video_id`),
  ADD KEY `idx_google_user` (`google_user_id`);

--
-- Indexes for table `video_views`
--
ALTER TABLE `video_views`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_video_view_guest` (`video_type`,`video_id`,`ip_address`,`user_agent`),
  ADD UNIQUE KEY `unique_video_view_google` (`video_type`,`video_id`,`google_user_id`),
  ADD KEY `idx_video` (`video_type`,`video_id`),
  ADD KEY `idx_google_user` (`google_user_id`),
  ADD KEY `idx_video_type_id_viewed_at` (`video_type`,`video_id`,`viewed_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `google_users`
--
ALTER TABLE `google_users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_account_settings`
--
ALTER TABLE `user_account_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_comments`
--
ALTER TABLE `video_comments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_likes`
--
ALTER TABLE `video_likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_views`
--
ALTER TABLE `video_views`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `video_comments`
--
ALTER TABLE `video_comments`
  ADD CONSTRAINT `fk_video_comments_google_user` FOREIGN KEY (`google_user_id`) REFERENCES `google_users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
