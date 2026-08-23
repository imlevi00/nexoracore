<?php
/**
 * ئاماری قازانج/فرۆش بەپێی کاڵا (ئاستی هێڵی فرۆشتن و گەڕاندنەوە)
 */

require_once __DIR__ . '/profit_schema.php';

if (!function_exists('getItemProfitReportCostExpressions')) {

    /**
     * @return array{sale_unit_cost: string, return_unit_cost: string, sale_line_cogs: string, return_line_cogs: string, has_external: bool}
     */
    function getItemProfitReportCostExpressions(mysqli $conn)
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        ensureProfitSnapshotColumns($conn);

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

        $saleLineCogs = "si.quantity * COALESCE(si.unit_cost_at_sale, $saleItemCurrentUnitCostExpr)";
        $returnLineCogs = "ri.quantity * COALESCE(ri.unit_cost_at_return, $returnItemCurrentUnitCostExpr)";

        $tableCheck = $conn->query("SHOW TABLES LIKE 'sale_item_external_costs'");
        $hasExternal = $tableCheck && $tableCheck->num_rows > 0;

        if ($hasExternal) {
            $saleLineCogs = "($saleLineCogs) + COALESCE((
                SELECT SUM(e.buy_price * si.quantity)
                FROM sale_item_external_costs e
                WHERE e.sale_item_id = si.id
            ), 0)";
        }

        $cache = [
            'sale_unit_cost' => $saleItemCurrentUnitCostExpr,
            'return_unit_cost' => $returnItemCurrentUnitCostExpr,
            'sale_line_cogs' => $saleLineCogs,
            'return_line_cogs' => $returnLineCogs,
            'has_external' => $hasExternal,
        ];

        return $cache;
    }
}

if (!function_exists('getItemProfitReportProductIdsForFieldOption')) {

    /**
     * @param int[] $optionIds
     * @return int[]
     */
    function getItemProfitReportProductIdsForFieldOption(mysqli $conn, $userId, $fieldId, array $optionIds)
    {
        if (!function_exists('productCustomFieldsFeatureAvailable') || !productCustomFieldsFeatureAvailable($conn)) {
            return [];
        }

        $optionIds = array_values(array_filter(array_map('intval', $optionIds), function ($id) {
            return $id > 0;
        }));
        if ($fieldId <= 0 || empty($optionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($optionIds), '?'));
        $types = 'ii' . str_repeat('i', count($optionIds));
        $params = array_merge([(int)$userId, (int)$fieldId], $optionIds);

        $sql = "SELECT DISTINCT v.product_id
                FROM product_custom_field_values v
                WHERE v.user_id = ? AND v.field_id = ?
                AND CAST(v.value_text AS UNSIGNED) IN ($placeholders)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $ids = [];
        foreach ($rows as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }

        return $ids;
    }
}

if (!function_exists('fetchItemProfitSalesAggregatesByProduct')) {

    /**
     * @param int[]|null $productIds null = no filter
     * @return array<int, array{product_id: int, product_name: string, qty: float, revenue: float, cogs: float}>
     */
    function fetchItemProfitSalesAggregatesByProduct(
        mysqli $conn,
        $userId,
        $fromDate,
        $toDate,
        $subUserId = null,
        $searchQ = '',
        $productIds = null
    ) {
        $userId = (int)$userId;
        $subUserId = $subUserId !== null ? (int)$subUserId : null;
        $costExpr = getItemProfitReportCostExpressions($conn);
        $saleLineCogs = $costExpr['sale_line_cogs'];

        $sql = "
            SELECT
                si.product_id,
                COALESCE(s.currency, 'IQD') AS currency,
                MAX(si.product_name) AS product_name,
                COALESCE(SUM(si.quantity), 0) AS qty,
                COALESCE(SUM(si.total_price), 0) AS revenue,
                COALESCE(SUM($saleLineCogs), 0) AS cogs
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.id
            LEFT JOIN product_units pu ON (si.product_id = pu.product_id AND si.unit_id = pu.unit_id)
            WHERE s.user_id = ?
            AND DATE(s.sale_date) BETWEEN ? AND ?
            AND (s.invoice_number IS NULL OR s.invoice_number NOT LIKE 'INIT-%')
            AND si.product_id IS NOT NULL
        ";

        $params = [$userId, $fromDate, $toDate];
        $types = 'iss';

        if ($subUserId !== null) {
            $sql .= ' AND s.sub_user_id = ?';
            $types .= 'i';
            $params[] = $subUserId;
        }

        if ($searchQ !== '') {
            $sql .= ' AND si.product_name LIKE ?';
            $types .= 's';
            $params[] = '%' . $searchQ . '%';
        }

        if (is_array($productIds)) {
            if (empty($productIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $sql .= " AND si.product_id IN ($placeholders)";
            $types .= str_repeat('i', count($productIds));
            foreach ($productIds as $pid) {
                $params[] = (int)$pid;
            }
        }

        // کۆکردنەوە بەپێی کاڵا و دراو — بۆ ئەوەی دینار و دۆلار هەرگیز تێکەڵ نەبن
        $sql .= " GROUP BY si.product_id, COALESCE(s.currency, 'IQD')";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $map = [];
        foreach ($rows as $row) {
            $pid = (int)$row['product_id'];
            $cur = ($row['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';
            $key = $pid . '|' . $cur;
            $map[$key] = [
                'product_id' => $pid,
                'currency' => $cur,
                'product_name' => (string)$row['product_name'],
                'qty' => (float)$row['qty'],
                'revenue' => (float)$row['revenue'],
                'cogs' => (float)$row['cogs'],
            ];
        }

        return $map;
    }
}

if (!function_exists('fetchItemProfitReturnsAggregatesByProduct')) {

    /**
     * @param int[]|null $productIds
     * @return array<int, array{product_id: int, product_name: string, qty: float, revenue: float, cogs: float}>
     */
    function fetchItemProfitReturnsAggregatesByProduct(
        mysqli $conn,
        $userId,
        $fromDate,
        $toDate,
        $subUserId = null,
        $searchQ = '',
        $productIds = null
    ) {
        $userId = (int)$userId;
        $subUserId = $subUserId !== null ? (int)$subUserId : null;
        $costExpr = getItemProfitReportCostExpressions($conn);
        $returnLineCogs = $costExpr['return_line_cogs'];

        // دراوی گەڕانەوە لە sale_id → sales.currency وەردەگیرێت. ئەگەر ستوونی
        // sale_id نەبوو، هەموو گەڕانەوەکان بە دینار دادەنرێن.
        static $returnsHasSaleId = null;
        if ($returnsHasSaleId === null) {
            $colCheck = $conn->query("SHOW COLUMNS FROM returns LIKE 'sale_id'");
            $returnsHasSaleId = ($colCheck && $colCheck->num_rows > 0);
        }
        $currencyExpr = $returnsHasSaleId
            ? "COALESCE((SELECT s2.currency FROM sales s2 WHERE s2.id = r.sale_id LIMIT 1), 'IQD')"
            : "'IQD'";

        $sql = "
            SELECT
                ri.product_id,
                $currencyExpr AS currency,
                MAX(ri.product_name) AS product_name,
                COALESCE(SUM(ri.quantity), 0) AS qty,
                COALESCE(SUM(ri.total_price), 0) AS revenue,
                COALESCE(SUM($returnLineCogs), 0) AS cogs
            FROM return_items ri
            INNER JOIN returns r ON ri.return_id = r.id
            LEFT JOIN product_units pu ON (ri.product_id = pu.product_id AND ri.unit_id = pu.unit_id)
            WHERE r.user_id = ?
            AND DATE(r.return_date) BETWEEN ? AND ?
            AND ri.product_id IS NOT NULL
        ";

        $params = [$userId, $fromDate, $toDate];
        $types = 'iss';

        if ($subUserId !== null) {
            $sql .= ' AND r.sub_user_id = ?';
            $types .= 'i';
            $params[] = $subUserId;
        }

        if ($searchQ !== '') {
            $sql .= ' AND ri.product_name LIKE ?';
            $types .= 's';
            $params[] = '%' . $searchQ . '%';
        }

        if (is_array($productIds)) {
            if (empty($productIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $sql .= " AND ri.product_id IN ($placeholders)";
            $types .= str_repeat('i', count($productIds));
            foreach ($productIds as $pid) {
                $params[] = (int)$pid;
            }
        }

        $sql .= " GROUP BY ri.product_id, $currencyExpr";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $map = [];
        foreach ($rows as $row) {
            $pid = (int)$row['product_id'];
            $cur = ($row['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';
            $key = $pid . '|' . $cur;
            $map[$key] = [
                'product_id' => $pid,
                'currency' => $cur,
                'product_name' => (string)$row['product_name'],
                'qty' => (float)$row['qty'],
                'revenue' => (float)$row['revenue'],
                'cogs' => (float)$row['cogs'],
            ];
        }

        return $map;
    }
}

if (!function_exists('mergeItemProfitProductAggregates')) {

    /**
     * @param array<int, array> $salesMap
     * @param array<int, array> $returnsMap
     * @return array<int, array{product_id: int, product_name: string, qty_sold: float, revenue: float, cogs: float, profit: float}>
     */
    function mergeItemProfitProductAggregates(array $salesMap, array $returnsMap)
    {
        // کلیلەکان بە شێوەی «product_id|currency»ـن، بۆیە دینار و دۆلاری هەمان
        // کاڵا وەک دوو ڕیزی جیاواز دەمێننەوە.
        $allKeys = array_unique(array_merge(array_keys($salesMap), array_keys($returnsMap)));
        $merged = [];

        foreach ($allKeys as $key) {
            $sale = $salesMap[$key] ?? null;
            $ret = $returnsMap[$key] ?? null;

            $pid = (int)($sale['product_id'] ?? ($ret['product_id'] ?? 0));
            $currency = $sale['currency'] ?? ($ret['currency'] ?? 'IQD');
            $currency = $currency === 'USD' ? 'USD' : 'IQD';
            $name = $sale['product_name'] ?? ($ret['product_name'] ?? '');
            $qty = ($sale['qty'] ?? 0) - ($ret['qty'] ?? 0);
            $revenue = ($sale['revenue'] ?? 0) - ($ret['revenue'] ?? 0);
            $cogs = ($sale['cogs'] ?? 0) - ($ret['cogs'] ?? 0);
            $profit = $revenue - $cogs;

            if ($qty == 0 && abs($revenue) < 0.0001 && abs($profit) < 0.0001) {
                continue;
            }

            $merged[$key] = [
                'product_id' => $pid,
                'currency' => $currency,
                'product_name' => $name,
                'qty_sold' => $qty,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'profit' => $profit,
            ];
        }

        return $merged;
    }
}

if (!function_exists('applyItemProfitSoldRatePct')) {

    /**
     * @param array<int, array> $rows
     * @return array<int, array>
     */
    function applyItemProfitSoldRatePct(array $rows)
    {
        $totalQty = 0.0;
        foreach ($rows as $row) {
            $totalQty += max(0, (float)($row['qty_sold'] ?? 0));
        }

        foreach ($rows as $pid => $row) {
            $qty = max(0, (float)($row['qty_sold'] ?? 0));
            $rows[$pid]['sold_rate_pct'] = $totalQty > 0 ? ($qty / $totalQty) * 100 : 0.0;
        }

        return $rows;
    }
}

if (!function_exists('sortItemProfitRows')) {

    /**
     * @param array<int, array> $rows
     * @return array<int, array>
     */
    function sortItemProfitRows(array $rows, $sortBy = 'qty', $sortDir = 'desc')
    {
        $allowed = ['qty', 'revenue', 'profit', 'sold_rate', 'name'];
        if (!in_array($sortBy, $allowed, true)) {
            $sortBy = 'qty';
        }
        $sortDir = strtolower((string)$sortDir) === 'asc' ? 'asc' : 'desc';

        $list = array_values($rows);
        usort($list, function ($a, $b) use ($sortBy, $sortDir) {
            if ($sortBy === 'name') {
                $cmp = strcmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
            } elseif ($sortBy === 'sold_rate') {
                $cmp = ($a['sold_rate_pct'] ?? 0) <=> ($b['sold_rate_pct'] ?? 0);
            } elseif ($sortBy === 'revenue') {
                $cmp = ($a['revenue'] ?? 0) <=> ($b['revenue'] ?? 0);
            } elseif ($sortBy === 'profit') {
                $cmp = ($a['profit'] ?? 0) <=> ($b['profit'] ?? 0);
            } else {
                $cmp = ($a['qty_sold'] ?? 0) <=> ($b['qty_sold'] ?? 0);
            }
            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        return $list;
    }
}

if (!function_exists('fetchItemProfitByProduct')) {

    /**
     * @return array{rows: array, total_count: int, totals: array}
     */
    function fetchItemProfitByProduct(
        mysqli $conn,
        $userId,
        $fromDate,
        $toDate,
        $subUserId = null,
        $searchQ = '',
        $productIdsFilter = null,
        $sortBy = 'qty',
        $sortDir = 'desc',
        $page = 1,
        $perPage = 50
    ) {
        $salesMap = fetchItemProfitSalesAggregatesByProduct($conn, $userId, $fromDate, $toDate, $subUserId, $searchQ, $productIdsFilter);
        $returnsMap = fetchItemProfitReturnsAggregatesByProduct($conn, $userId, $fromDate, $toDate, $subUserId, $searchQ, $productIdsFilter);
        $merged = mergeItemProfitProductAggregates($salesMap, $returnsMap);
        $merged = applyItemProfitSoldRatePct($merged);

        $totals = fetchItemProfitReportTotalsFromRows($merged);

        $sorted = sortItemProfitRows($merged, $sortBy, $sortDir);
        $totalCount = count($sorted);
        $page = max(1, (int)$page);
        $perPage = max(1, min(200, (int)$perPage));
        $offset = ($page - 1) * $perPage;
        $rows = array_slice($sorted, $offset, $perPage);

        return [
            'rows' => $rows,
            'total_count' => $totalCount,
            'totals' => $totals,
        ];
    }
}

if (!function_exists('fetchItemProfitReportTotalsFromRows')) {

    function fetchItemProfitReportTotalsFromRows(array $rows)
    {
        // بڕ و ژمارەی کاڵا سەربەخۆن لە دراو؛ بەڵام داهات/تێچوو/قازانج بەپێی دراو
        // جیا دەکرێنەوە تاکو دینار و دۆلار تێکەڵ نەبن.
        $uniqueProducts = [];
        $totals = [
            'qty_sold' => 0.0,
            'product_count' => 0,
            'IQD' => ['revenue' => 0.0, 'cogs' => 0.0, 'profit' => 0.0],
            'USD' => ['revenue' => 0.0, 'cogs' => 0.0, 'profit' => 0.0],
            // ڕەگەزە کۆنەکان بۆ گونجان (تەنها دینار)
            'revenue' => 0.0,
            'cogs' => 0.0,
            'profit' => 0.0,
        ];

        foreach ($rows as $row) {
            $cur = ($row['currency'] ?? 'IQD') === 'USD' ? 'USD' : 'IQD';
            $totals['qty_sold'] += (float)($row['qty_sold'] ?? 0);
            $totals[$cur]['revenue'] += (float)($row['revenue'] ?? 0);
            $totals[$cur]['cogs'] += (float)($row['cogs'] ?? 0);
            $totals[$cur]['profit'] += (float)($row['profit'] ?? 0);
            $uniqueProducts[(int)($row['product_id'] ?? 0)] = true;
        }

        $totals['product_count'] = count($uniqueProducts);
        $totals['revenue'] = $totals['IQD']['revenue'];
        $totals['cogs'] = $totals['IQD']['cogs'];
        $totals['profit'] = $totals['IQD']['profit'];

        return $totals;
    }
}

if (!function_exists('fetchItemProfitReportTotals')) {

    function fetchItemProfitReportTotals(
        mysqli $conn,
        $userId,
        $fromDate,
        $toDate,
        $subUserId = null,
        $searchQ = '',
        $productIdsFilter = null
    ) {
        $salesMap = fetchItemProfitSalesAggregatesByProduct($conn, $userId, $fromDate, $toDate, $subUserId, $searchQ, $productIdsFilter);
        $returnsMap = fetchItemProfitReturnsAggregatesByProduct($conn, $userId, $fromDate, $toDate, $subUserId, $searchQ, $productIdsFilter);
        $merged = mergeItemProfitProductAggregates($salesMap, $returnsMap);

        return fetchItemProfitReportTotalsFromRows($merged);
    }
}

if (!function_exists('fetchItemProfitByCustomFieldOptions')) {

    /**
     * کۆکردنەوە بەپێی هەڵبژاردنی leaf بۆ هەر خانەی select
     *
     * @return array<int, array{field_id: int, field_name: string, option_id: int, option_label: string, qty_sold: float, revenue: float, profit: float, sold_rate_pct: float}>
     */
    function fetchItemProfitByCustomFieldOptions(
        mysqli $conn,
        $userId,
        $fromDate,
        $toDate,
        $subUserId = null,
        $searchQ = '',
        $fieldIdFilter = 0
    ) {
        if (!function_exists('productCustomFieldsFeatureAvailable') || !function_exists('getProductCustomFields')) {
            return [];
        }
        if (!productCustomFieldsFeatureAvailable($conn) || !productCustomFieldOptionsAvailable($conn)) {
            return [];
        }

        $fields = getProductCustomFields($conn, $userId, true);
        $selectFields = array_filter($fields, function ($f) {
            return ($f['field_type'] ?? '') === 'select';
        });

        if ($fieldIdFilter > 0) {
            $selectFields = array_filter($selectFields, function ($f) use ($fieldIdFilter) {
                return (int)$f['id'] === $fieldIdFilter;
            });
        }

        $resultRows = [];

        foreach ($selectFields as $field) {
            $fieldId = (int)$field['id'];
            $flat = getProductCustomFieldOptionsFlat($conn, $userId, $fieldId, true);
            $leafOptions = [];
            foreach ($flat as $opt) {
                $optId = (int)$opt['id'];
                if (!productCustomFieldOptionHasActiveChildren($conn, $userId, $fieldId, $optId)) {
                    $leafOptions[] = $opt;
                }
            }

            foreach ($leafOptions as $opt) {
                $optionId = (int)$opt['id'];
                $productIds = getItemProfitReportProductIdsForFieldOption($conn, $userId, $fieldId, [$optionId]);
                if (empty($productIds)) {
                    continue;
                }

                $salesMap = fetchItemProfitSalesAggregatesByProduct($conn, $userId, $fromDate, $toDate, $subUserId, $searchQ, $productIds);
                $returnsMap = fetchItemProfitReturnsAggregatesByProduct($conn, $userId, $fromDate, $toDate, $subUserId, $searchQ, $productIds);
                $merged = mergeItemProfitProductAggregates($salesMap, $returnsMap);
                $totals = fetchItemProfitReportTotalsFromRows($merged);

                if ($totals['qty_sold'] <= 0
                    && abs($totals['IQD']['revenue']) < 0.0001
                    && abs($totals['USD']['revenue']) < 0.0001) {
                    continue;
                }

                $label = resolveCustomFieldOptionPath($conn, $userId, $optionId);
                if ($label === '') {
                    $label = (string)($opt['option_label'] ?? '');
                }

                $resultRows[] = [
                    'field_id' => $fieldId,
                    'field_name' => (string)($field['field_name'] ?? ''),
                    'section_name' => (string)($field['section_name'] ?? ''),
                    'option_id' => $optionId,
                    'option_label' => $label,
                    'qty_sold' => $totals['qty_sold'],
                    // دینار
                    'revenue' => $totals['IQD']['revenue'],
                    'cogs' => $totals['IQD']['cogs'],
                    'profit' => $totals['IQD']['profit'],
                    // دۆلار
                    'revenue_usd' => $totals['USD']['revenue'],
                    'cogs_usd' => $totals['USD']['cogs'],
                    'profit_usd' => $totals['USD']['profit'],
                ];
            }
        }

        $totalQty = 0.0;
        foreach ($resultRows as $row) {
            $totalQty += max(0, (float)$row['qty_sold']);
        }
        foreach ($resultRows as $i => $row) {
            $qty = max(0, (float)$row['qty_sold']);
            $resultRows[$i]['sold_rate_pct'] = $totalQty > 0 ? ($qty / $totalQty) * 100 : 0.0;
        }

        usort($resultRows, function ($a, $b) {
            return ($b['qty_sold'] ?? 0) <=> ($a['qty_sold'] ?? 0);
        });

        return $resultRows;
    }
}

if (!function_exists('loadItemProfitReportCached')) {

    /**
     * @return array|null
     */
    function loadItemProfitReportCached($cacheKey, $ttlSeconds = 90)
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kasher_report_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';
        if (!is_file($cacheFile) || (time() - (int)@filemtime($cacheFile)) > $ttlSeconds) {
            return null;
        }
        $json = @file_get_contents($cacheFile);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('saveItemProfitReportCached')) {

    function saveItemProfitReportCached($cacheKey, array $data)
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kasher_report_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
