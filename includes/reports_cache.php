<?php
/**
 * کاشی ڕاپۆرتەکان بەپێی "واژووی داتا" (data-signature caching)
 * -----------------------------------------------------------
 * لەبری بەکارهێنانی TTL (کە داتای کۆن پیشان دەدا)، کاشەکە بەپێی
 * واژووێکی سووکی داتا کلیل دەکرێت. هەر کاتێک فرۆشتن/گەڕانەوە/خەرجی/
 * پارەدانی قەرز زیاد/دەستکاری/بسڕدرێتەوە، واژووەکە دەگۆڕێت و کاشەکە
 * خۆکارانە نوێ دەبێتەوە — بێ ئەوەی پێویست بێت لە هەموو شوێنێک بە
 * دەستی کاشەکە بسڕینەوە.
 */

if (!function_exists('getReportsCacheDir')) {
    /**
     * فۆڵدەری هەڵگرتنی کاش (لە فۆڵدەری کاتیی سیستەم).
     */
    function getReportsCacheDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kasher_report_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
}

if (!function_exists('getReportsDataSignature')) {
    /**
     * واژووێکی سووک (hash) لە دۆخی ئێستای داتاکان دەگەڕێنێتەوە.
     * تەنها کوێری زۆر خێرای aggregate بەکاردەهێنێت (COUNT/SUM/MAX) بەبێ
     * هیچ JOIN ـێکی قورس، بۆیە کاریگەری لەسەر خێرایی نییە.
     *
     * @param mysqli   $conn
     * @param int      $userId
     * @param int|null $subUserId
     */
    function getReportsDataSignature($conn, int $userId, ?int $subUserId = null): string
    {
        $userId = (int)$userId;
        $scope = '';
        if ($subUserId !== null) {
            $scope = ' AND sub_user_id = ' . (int)$subUserId;
        }

        $sql = "SELECT
            (SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(final_amount), 0), '|', COALESCE(MAX(id), 0))
               FROM sales   WHERE user_id = {$userId}{$scope}) AS sig_sales,
            (SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(quantity), 0), '|', COALESCE(MAX(si.id), 0))
               FROM sale_items si INNER JOIN sales s ON si.sale_id = s.id
               WHERE s.user_id = {$userId}" . ($subUserId !== null ? ' AND s.sub_user_id = ' . (int)$subUserId : '') . ") AS sig_items,
            (SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(final_amount), 0), '|', COALESCE(MAX(id), 0))
               FROM returns WHERE user_id = {$userId}{$scope}) AS sig_returns,
            (SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(amount), 0), '|', COALESCE(MAX(id), 0))
               FROM expenses WHERE user_id = {$userId}) AS sig_expenses,
            (SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(dp.payment_amount), 0), '|', COALESCE(MAX(dp.id), 0))
               FROM debt_payments dp INNER JOIN debts d ON d.id = dp.debt_id
               WHERE d.user_id = {$userId}) AS sig_debt_payments
        ";

        $parts = [];
        $result = @$conn->query($sql);
        if ($result && ($row = $result->fetch_assoc())) {
            $parts = [
                $row['sig_sales'] ?? '',
                $row['sig_items'] ?? '',
                $row['sig_returns'] ?? '',
                $row['sig_expenses'] ?? '',
                $row['sig_debt_payments'] ?? '',
            ];
        } else {
            // ئەگەر کوێریەکە شکستی هێنا، واژووێکی کاتی بگەڕێنەوە تا کاش نەکرێت
            $parts = [(string)microtime(true)];
        }

        return sha1(implode('#', $parts));
    }
}

if (!function_exists('cleanupStaleReportsCache')) {
    /**
     * سڕینەوەی فایلە کاشە کۆنەکان (زیاتر لە کاتژمێرێک) بۆ ئەوەی فۆڵدەری
     * کاش پڕ نەبێت لە واژووە بەسەرچووەکان. فایلی ئێستا نەسڕدرێتەوە.
     *
     * @param string $keepFile فایلی کاشی ئێستا کە نابێت بسڕدرێتەوە
     */
    function cleanupStaleReportsCache(string $keepFile): void
    {
        $dir = getReportsCacheDir();
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files)) {
            return;
        }

        $now = time();
        foreach ($files as $file) {
            if ($file === $keepFile) {
                continue;
            }
            if (($now - (int)@filemtime($file)) > 3600) {
                @unlink($file);
            }
        }
    }
}
