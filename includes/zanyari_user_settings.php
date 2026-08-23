<?php
/**
 * ڕێکخستنەکانی kasher_z.user_account_settings
 */
require_once dirname(__DIR__) . '/config/kasher_zanyari/database.php';

if (!function_exists('getPurchasesUseWeightedAvgPrices')) {

    /**
     * ئایا لە وەسڵی کڕیندا نرخەکانی کۆگا بە تێکڕا نوێ بکرێنەوە (true) یان وەک نرخی ڕیز جێگیر بن (false).
     */
    function getPurchasesUseWeightedAvgPrices($userId) {
        global $conn_zanyari;

        $userId = (int)$userId;
        if ($userId <= 0 || !($conn_zanyari instanceof mysqli)) {
            return true;
        }

        $stmt = $conn_zanyari->prepare(
            'SELECT purchases_use_weighted_avg_prices FROM user_account_settings WHERE user_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return true;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !array_key_exists('purchases_use_weighted_avg_prices', $row)) {
            return true;
        }

        return (int)$row['purchases_use_weighted_avg_prices'] === 1;
    }
}

if (!function_exists('getRecognizeCustomerDebtRevenueAtSale')) {

    /**
     * ئایا داهاتی فرۆشتنی قەرز لە ڕۆژی فرۆشتن لە قازانجدا دەرکەوێت (true) یان دوای پارەدان (false).
     * ئەگەر ستوونەکە نەبێت یان داتابەیس بەردەست نەبێت، true دەگەڕێتەوە (ڕەفتاری پێشوو).
     */
    function getRecognizeCustomerDebtRevenueAtSale($userId) {
        global $conn_zanyari;

        $userId = (int)$userId;
        if ($userId <= 0 || !($conn_zanyari instanceof mysqli)) {
            return true;
        }

        $stmt = $conn_zanyari->prepare(
            'SELECT recognize_customer_debt_revenue_at_sale FROM user_account_settings WHERE user_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return true;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !array_key_exists('recognize_customer_debt_revenue_at_sale', $row)) {
            return true;
        }

        return (int)$row['recognize_customer_debt_revenue_at_sale'] === 1;
    }
}

if (!function_exists('getReceiptA4ItemsFontSize')) {

    /**
     * قەبارەی فۆنتی خشتەی کاڵاکان لە وەسڵی A4 (پیکسڵ). گەر داتابەیس نەبێت یان ستوون نەبێت، ١٦.
     */
    function getReceiptA4ItemsFontSize($userId) {
        global $conn_zanyari;

        $userId = (int)$userId;
        if ($userId <= 0 || !($conn_zanyari instanceof mysqli)) {
            return 16;
        }

        $stmt = $conn_zanyari->prepare(
            'SELECT receipt_a4_items_font_size FROM user_account_settings WHERE user_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return 16;
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return 16;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !array_key_exists('receipt_a4_items_font_size', $row) || $row['receipt_a4_items_font_size'] === null) {
            return 16;
        }

        $n = (int)$row['receipt_a4_items_font_size'];
        if ($n < 1) {
            return 16;
        }

        return max(10, min(22, $n));
    }
}
