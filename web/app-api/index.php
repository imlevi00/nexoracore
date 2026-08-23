<?php
/**
 * ====================================================================
 * App API - Index / Documentation  (web/app-api/index.php)
 * ====================================================================
 * لیستی endpoint ـەکان بە فۆرماتی JSON — بۆ تاقیکردنەوە و ڕێنمایی.
 * ====================================================================
 */

require_once __DIR__ . '/_bootstrap.php';

$base = rtrim(SITE_URL, '/') . '/web/app-api';

app_api_success([
    'name'    => 'Kashery App API',
    'version' => '1.0.0',
    'base_url'=> $base,
    'endpoints' => [
        [
            'method' => 'GET',
            'path'   => '/shops.php',
            'desc'   => 'لیستی هەموو فرۆشگا چالاکەکان (search, page, per_page)',
        ],
        [
            'method' => 'GET',
            'path'   => '/shops.php?slug=SHOP',
            'desc'   => 'زانیاری تەواوی یەک فرۆشگا',
        ],
        [
            'method' => 'GET',
            'path'   => '/categories.php?slug=SHOP',
            'desc'   => 'کەتەگۆرییەکانی فرۆشگا',
        ],
        [
            'method' => 'GET',
            'path'   => '/products.php?slug=SHOP',
            'desc'   => 'کاڵاکانی فرۆشگا (category, search, sort, page, per_page)',
        ],
        [
            'method' => 'GET',
            'path'   => '/products.php?slug=SHOP&id=ID',
            'desc'   => 'زانیاری تەواوی یەک کاڵا (وێنەکان، یەکەکان، وردەکاری)',
        ],
        [
            'method' => 'POST',
            'path'   => '/submit_order.php',
            'desc'   => 'ناردنی داواکاری بۆ خاوەن فرۆشگا (JSON body)',
        ],
        [
            'method' => 'GET',
            'path'   => '/order_status.php?order_number=..&phone=..',
            'desc'   => 'پشکنینی دۆخی داواکاری',
        ],
        [
            'method' => 'GET',
            'path'   => '/videos.php',
            'desc'   => 'فیدی ڤیدیۆکان (slug, page, per_page)',
        ],
        [
            'method' => 'GET',
            'path'   => '/videos.php?video_type=free&video_id=ID',
            'desc'   => 'یەک ڤیدیۆی دیاریکراو',
        ],
        [
            'method' => 'POST',
            'path'   => '/video_view.php',
            'desc'   => 'تۆمارکردنی بینینی ڤیدیۆ (video_id, video_type)',
        ],
    ],
]);
