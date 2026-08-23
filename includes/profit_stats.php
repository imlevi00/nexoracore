<?php
/**
 * حیسابکردنی ئاماری قازانج (داهات، تێچووی کاڵا، قازانج)
 *
 * پشتگیری فرە-دراو (multi-currency): دەکرێت بە فلتەری دراو (IQD/USD) بانگ بکرێت
 * بۆ ئەوەی هەر دراوێک جیا حیساب بکرێت. دینار و دۆلار هەرگیز تێکەڵ ناکرێن.
 */

require_once __DIR__ . '/profit_schema.php';

if (!function_exists('calculateProfitStats')) {

    /**
     * ڕاکردنی پرسیارێکی کۆکراوە (scalar aggregate) بە پارامەتری داینامیک و
     * گەڕاندنەوەی نرخێکی float.
     */
    function profitStatsRunScalar($conn, $sql, $types, array $params, $col) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            if ($conn->error) {
                error_log('calculateProfitStats prepare failed: ' . $conn->error . ' | SQL: ' . $sql);
            }
            return 0.0;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return isset($row[$col]) ? (float)$row[$col] : 0.0;
    }

    /**
     * دروستکردنی رستەی فلتەری دراو بۆ خشتەی returns.
     * returns ستوونی currency ی ڕاستەوخۆی نییە؛ دراوەکە لە sale_id → sales.currency
     * وەردەگیرێت. ئەگەر sale_id نەبوو، هەموو گەڕانەوەکان بە IQD دادەنرێن.
     *
     * @return array{sql:string,types:string,params:array}
     */
    function profitStatsReturnsCurrencyClause($alias, $currency, $hasSaleId) {
        if ($currency === null) {
            return ['sql' => '', 'types' => '', 'params' => []];
        }
        if (!$hasSaleId) {
            // بەبێ بەستنەوە بە فرۆشتن: هەموو گەڕانەوەکان بە دینار دادەنرێن
            return $currency === 'USD'
                ? ['sql' => ' AND 1=0', 'types' => '', 'params' => []]
                : ['sql' => '', 'types' => '', 'params' => []];
        }
        return [
            'sql' => " AND COALESCE((SELECT s2.currency FROM sales s2 WHERE s2.id = {$alias}.sale_id LIMIT 1), 'IQD') = ?",
            'types' => 's',
            'params' => [$currency],
        ];
    }

    /**
     * @param mysqli $conn
     * @param int $userId
     * @param string $fromDate Y-m-d
     * @param string $toDate Y-m-d
     * @param int|null $subUserId
     * @param bool $isSubUser
     * @param bool $recognizeDebtRevenueAtSale true = داهاتی قەرز لە ڕۆژی فرۆشتن (وەک پێشتر)، false = بەپێی debt_payments
     * @param string|null $currency فلتەری دراو: 'IQD' یان 'USD'. ئەگەر null بێت، هەموو دراوەکان تێکەڵ (ڕەفتاری کۆن).
     */
    function calculateProfitStats($conn, $userId, $fromDate, $toDate, $subUserId = null, $isSubUser = false, $recognizeDebtRevenueAtSale = true, $currency = null) {
        $stats = [
            'revenue' => 0,
            'cost_of_goods' => 0,
            'expenses' => 0,
            'returns' => 0,
            'return_cost_of_goods' => 0,
            'net_revenue' => 0,
            'net_cost_of_goods' => 0,
            'profit' => 0,
            'profit_margin' => 0,
            'currency' => $currency,
        ];

        $subUserId = $subUserId !== null ? (int)$subUserId : null;
        $userId = (int)$userId;
        $currency = ($currency === 'USD' || $currency === 'IQD') ? $currency : null;

        static $profitSchemaEnsured = false;
        if (!$profitSchemaEnsured) {
            ensureProfitSnapshotColumns($conn);
            $profitSchemaEnsured = true;
        }

        static $returnsHasSaleId = null;
        if ($returnsHasSaleId === null) {
            $colCheck = $conn->query("SHOW COLUMNS FROM returns LIKE 'sale_id'");
            $returnsHasSaleId = ($colCheck && $colCheck->num_rows > 0);
        }

        // تێچووی ئێستای product_units بۆ fallback
        $saleItemCurrentUnitCostExpr = "
                CASE
                    WHEN si.unit_id IS NOT NULL AND si.unit_id != 0 THEN
                        COALESCE(
                            pu.buy_price,
                            (SELECT pu2.buy_price FROM product_units pu2 WHERE pu2.product_id = si.product_id ORDER BY pu2.is_primary DESC, pu2.id ASC LIMIT 1),
                            0
                        )
                    ELSE COALESCE(
                        (SELECT pu2.buy_price FROM product_units pu2 WHERE pu2.product_id = si.product_id ORDER BY pu2.is_primary DESC, pu2.id ASC LIMIT 1),
                        0
                    )
                END
        ";

        $returnItemCurrentUnitCostExpr = "
                CASE
                    WHEN ri.unit_id IS NOT NULL AND ri.unit_id != 0 THEN
                        COALESCE(
                            pu.buy_price,
                            (SELECT pu2.buy_price FROM product_units pu2 WHERE pu2.product_id = ri.product_id ORDER BY pu2.is_primary DESC, pu2.id ASC LIMIT 1),
                            0
                        )
                    ELSE COALESCE(
                        (SELECT pu2.buy_price FROM product_units pu2 WHERE pu2.product_id = ri.product_id ORDER BY pu2.is_primary DESC, pu2.id ASC LIMIT 1),
                        0
                    )
                END
        ";

        $saleItemUnitCostExpr = "
                si.quantity * COALESCE(si.unit_cost_at_sale, $saleItemCurrentUnitCostExpr)
        ";

        $returnItemUnitCostExpr = "
                ri.quantity * COALESCE(ri.unit_cost_at_return, $returnItemCurrentUnitCostExpr)
        ";

        // ---------- داهات (Revenue) ----------
        if ($recognizeDebtRevenueAtSale) {
            $sql = "SELECT COALESCE(SUM(final_amount), 0) as revenue
                    FROM sales
                    WHERE user_id = ? AND DATE(sale_date) BETWEEN ? AND ?
                    AND (invoice_number IS NULL OR invoice_number NOT LIKE 'INIT-%')";
            $types = 'iss';
            $params = [$userId, $fromDate, $toDate];
            if ($subUserId) {
                $sql .= " AND sub_user_id = ?";
                $types .= 'i';
                $params[] = $subUserId;
            }
            if ($currency !== null) {
                $sql .= " AND COALESCE(currency, 'IQD') = ?";
                $types .= 's';
                $params[] = $currency;
            }
            $stats['revenue'] = profitStatsRunScalar($conn, $sql, $types, $params, 'revenue');
        } else {
            $noDebtExists = "NOT EXISTS (SELECT 1 FROM debts d WHERE d.sale_id = s.id AND d.user_id = s.user_id)";

            // فرۆشتنی بێ قەرز
            $sql = "SELECT COALESCE(SUM(s.final_amount), 0) AS revenue
                    FROM sales s
                    WHERE s.user_id = ? AND DATE(s.sale_date) BETWEEN ? AND ?
                    AND (s.invoice_number IS NULL OR s.invoice_number NOT LIKE 'INIT-%')
                    AND $noDebtExists";
            $types = 'iss';
            $params = [$userId, $fromDate, $toDate];
            if ($subUserId) {
                $sql .= " AND s.sub_user_id = ?";
                $types .= 'i';
                $params[] = $subUserId;
            }
            if ($currency !== null) {
                $sql .= " AND COALESCE(s.currency, 'IQD') = ?";
                $types .= 's';
                $params[] = $currency;
            }
            $revNoDebt = profitStatsRunScalar($conn, $sql, $types, $params, 'revenue');

            // پارەدانی قەرز (داهات بە ڕۆژی وەرگرتن)
            $sql = "SELECT COALESCE(SUM(dp.payment_amount), 0) AS revenue
                    FROM debt_payments dp
                    INNER JOIN debts d ON d.id = dp.debt_id
                    INNER JOIN sales s ON s.id = d.sale_id AND s.user_id = d.user_id
                    WHERE d.user_id = ? AND DATE(dp.payment_date) BETWEEN ? AND ?
                    AND (s.invoice_number IS NULL OR s.invoice_number NOT LIKE 'INIT-%')";
            $types = 'iss';
            $params = [$userId, $fromDate, $toDate];
            if ($subUserId) {
                $sql .= " AND s.sub_user_id = ?";
                $types .= 'i';
                $params[] = $subUserId;
            }
            if ($currency !== null) {
                $sql .= " AND COALESCE(s.currency, 'IQD') = ?";
                $types .= 's';
                $params[] = $currency;
            }
            $revPayments = profitStatsRunScalar($conn, $sql, $types, $params, 'revenue');

            $stats['revenue'] = $revNoDebt + $revPayments;
        }

        // ---------- تێچووی کاڵا لە product_units ----------
        $sql = "SELECT COALESCE(SUM($saleItemUnitCostExpr), 0) as cost_from_products
                FROM sale_items si
                INNER JOIN sales s ON si.sale_id = s.id
                LEFT JOIN product_units pu ON (si.product_id = pu.product_id AND si.unit_id = pu.unit_id)
                WHERE s.user_id = ? AND DATE(s.sale_date) BETWEEN ? AND ?";
        $types = 'iss';
        $params = [$userId, $fromDate, $toDate];
        if ($subUserId) {
            $sql .= " AND s.sub_user_id = ?";
            $types .= 'i';
            $params[] = $subUserId;
        }
        if (!$recognizeDebtRevenueAtSale) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM debts d WHERE d.sale_id = s.id AND d.user_id = s.user_id)";
        }
        if ($currency !== null) {
            $sql .= " AND COALESCE(s.currency, 'IQD') = ?";
            $types .= 's';
            $params[] = $currency;
        }
        $cost_from_products = profitStatsRunScalar($conn, $sql, $types, $params, 'cost_from_products');

        // ---------- تێچووی کاڵای دەرەکی ----------
        $cost_from_external = 0;
        $tableCheck = $conn->query("SHOW TABLES LIKE 'sale_item_external_costs'");
        $hasExternalCosts = ($tableCheck && $tableCheck->num_rows > 0);
        if ($hasExternalCosts) {
            $sql = "SELECT COALESCE(SUM(si.quantity * e.buy_price), 0) as cost_from_external
                    FROM sale_items si
                    INNER JOIN sale_item_external_costs e ON si.id = e.sale_item_id
                    INNER JOIN sales s ON si.sale_id = s.id
                    WHERE s.user_id = ? AND DATE(s.sale_date) BETWEEN ? AND ?";
            $types = 'iss';
            $params = [$userId, $fromDate, $toDate];
            if ($subUserId) {
                $sql .= " AND s.sub_user_id = ?";
                $types .= 'i';
                $params[] = $subUserId;
            }
            if (!$recognizeDebtRevenueAtSale) {
                $sql .= " AND NOT EXISTS (SELECT 1 FROM debts d WHERE d.sale_id = s.id AND d.user_id = s.user_id)";
            }
            if ($currency !== null) {
                $sql .= " AND COALESCE(s.currency, 'IQD') = ?";
                $types .= 's';
                $params[] = $currency;
            }
            $cost_from_external = profitStatsRunScalar($conn, $sql, $types, $params, 'cost_from_external');
        }

        $stats['cost_of_goods'] = $cost_from_products + $cost_from_external;

        // ---------- تێچووی دواخراو بۆ پارەدانی قەرز (تەنها کاتێک !recognize) ----------
        if (!$recognizeDebtRevenueAtSale) {
            $perSaleProductCogsSub = "
                SELECT si.sale_id,
                    COALESCE(SUM($saleItemUnitCostExpr), 0) AS sale_product_cogs
                FROM sale_items si
                INNER JOIN sales s_agg ON si.sale_id = s_agg.id
                LEFT JOIN product_units pu ON (si.product_id = pu.product_id AND si.unit_id = pu.unit_id)
                GROUP BY si.sale_id
            ";

            if ($hasExternalCosts) {
                $deferredCogsSql = "
                    SELECT COALESCE(SUM(
                        (dp.payment_amount / NULLIF(d.total_debt, 0)) *
                        (COALESCE(pc.sale_product_cogs, 0) + COALESCE(ec.sale_external_cogs, 0))
                    ), 0) AS deferred_cogs
                    FROM debt_payments dp
                    INNER JOIN debts d ON d.id = dp.debt_id
                    INNER JOIN sales s ON s.id = d.sale_id AND s.user_id = d.user_id
                    LEFT JOIN ($perSaleProductCogsSub) pc ON pc.sale_id = s.id
                    LEFT JOIN (
                        SELECT si2.sale_id,
                            COALESCE(SUM(si2.quantity * e.buy_price), 0) AS sale_external_cogs
                        FROM sale_items si2
                        INNER JOIN sale_item_external_costs e ON si2.id = e.sale_item_id
                        GROUP BY si2.sale_id
                    ) ec ON ec.sale_id = s.id
                    WHERE d.user_id = ? AND DATE(dp.payment_date) BETWEEN ? AND ?
                    AND (s.invoice_number IS NULL OR s.invoice_number NOT LIKE 'INIT-%')
                ";
            } else {
                $deferredCogsSql = "
                    SELECT COALESCE(SUM(
                        (dp.payment_amount / NULLIF(d.total_debt, 0)) * COALESCE(pc.sale_product_cogs, 0)
                    ), 0) AS deferred_cogs
                    FROM debt_payments dp
                    INNER JOIN debts d ON d.id = dp.debt_id
                    INNER JOIN sales s ON s.id = d.sale_id AND s.user_id = d.user_id
                    LEFT JOIN ($perSaleProductCogsSub) pc ON pc.sale_id = s.id
                    WHERE d.user_id = ? AND DATE(dp.payment_date) BETWEEN ? AND ?
                    AND (s.invoice_number IS NULL OR s.invoice_number NOT LIKE 'INIT-%')
                ";
            }

            $types = 'iss';
            $params = [$userId, $fromDate, $toDate];
            if ($subUserId) {
                $deferredCogsSql .= " AND s.sub_user_id = ?";
                $types .= 'i';
                $params[] = $subUserId;
            }
            if ($currency !== null) {
                $deferredCogsSql .= " AND COALESCE(s.currency, 'IQD') = ?";
                $types .= 's';
                $params[] = $currency;
            }
            $stats['cost_of_goods'] += profitStatsRunScalar($conn, $deferredCogsSql, $types, $params, 'deferred_cogs');
        }

        // ---------- خەرجیەکان ----------
        if ($subUserId || $isSubUser) {
            $stats['expenses'] = 0;
        } else {
            $sql = "SELECT COALESCE(SUM(amount), 0) as expenses
                    FROM expenses
                    WHERE user_id = ? AND DATE(expense_date) BETWEEN ? AND ?";
            $types = 'iss';
            $params = [$userId, $fromDate, $toDate];
            if ($currency !== null) {
                $sql .= " AND COALESCE(currency, 'IQD') = ?";
                $types .= 's';
                $params[] = $currency;
            }
            $stats['expenses'] = profitStatsRunScalar($conn, $sql, $types, $params, 'expenses');
        }

        // ---------- گەڕانەوەکان ----------
        $retClause = profitStatsReturnsCurrencyClause('returns', $currency, $returnsHasSaleId);
        $sql = "SELECT COALESCE(SUM(final_amount), 0) as returns
                FROM returns
                WHERE user_id = ? AND DATE(return_date) BETWEEN ? AND ?";
        $types = 'iss';
        $params = [$userId, $fromDate, $toDate];
        if ($subUserId) {
            $sql .= " AND sub_user_id = ?";
            $types .= 'i';
            $params[] = $subUserId;
        }
        $sql .= $retClause['sql'];
        $types .= $retClause['types'];
        foreach ($retClause['params'] as $p) {
            $params[] = $p;
        }
        $stats['returns'] = profitStatsRunScalar($conn, $sql, $types, $params, 'returns');

        // ---------- تێچووی گەڕانەوەکان ----------
        $retCogsClause = profitStatsReturnsCurrencyClause('r', $currency, $returnsHasSaleId);
        $sql = "SELECT COALESCE(SUM($returnItemUnitCostExpr), 0) as return_cost_of_goods
                FROM return_items ri
                INNER JOIN returns r ON ri.return_id = r.id
                LEFT JOIN product_units pu ON (ri.product_id = pu.product_id AND ri.unit_id = pu.unit_id)
                WHERE r.user_id = ? AND DATE(r.return_date) BETWEEN ? AND ?";
        $types = 'iss';
        $params = [$userId, $fromDate, $toDate];
        if ($subUserId) {
            $sql .= " AND r.sub_user_id = ?";
            $types .= 'i';
            $params[] = $subUserId;
        }
        $sql .= $retCogsClause['sql'];
        $types .= $retCogsClause['types'];
        foreach ($retCogsClause['params'] as $p) {
            $params[] = $p;
        }
        $stats['return_cost_of_goods'] = profitStatsRunScalar($conn, $sql, $types, $params, 'return_cost_of_goods');

        // ---------- کۆتایی ----------
        $stats['net_revenue'] = $stats['revenue'] - $stats['returns'];
        $stats['net_cost_of_goods'] = max(0, $stats['cost_of_goods'] - $stats['return_cost_of_goods']);
        $stats['profit'] = $stats['net_revenue'] - $stats['net_cost_of_goods'] - $stats['expenses'];

        if ($stats['net_revenue'] > 0) {
            $stats['profit_margin'] = ($stats['profit'] / $stats['net_revenue']) * 100;
        }

        return $stats;
    }

    /**
     * حیسابکردنی ئاماری قازانج بۆ هەردوو دراو (دینار و دۆلار) بە جیایی.
     * دەگەڕێنێتەوە: ['IQD' => [...stats], 'USD' => [...stats]]
     */
    function calculateProfitStatsByCurrency($conn, $userId, $fromDate, $toDate, $subUserId = null, $isSubUser = false, $recognizeDebtRevenueAtSale = true) {
        return [
            'IQD' => calculateProfitStats($conn, $userId, $fromDate, $toDate, $subUserId, $isSubUser, $recognizeDebtRevenueAtSale, 'IQD'),
            'USD' => calculateProfitStats($conn, $userId, $fromDate, $toDate, $subUserId, $isSubUser, $recognizeDebtRevenueAtSale, 'USD'),
        ];
    }
}
