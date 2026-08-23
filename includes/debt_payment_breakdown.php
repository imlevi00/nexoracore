<?php
/**
 * جیاکردنەوەی پارەی وەرگیراو لە پارەی گەڕاوە لە debt_payments.
 */

if (!function_exists('debtPaymentIsReturnAdjustmentNote')) {
    function debtPaymentIsReturnAdjustmentNote($notes) {
        $notes = (string)$notes;
        if ($notes === '') {
            return false;
        }
        if (strpos($notes, 'کەمکردنەوەی قەرز بەهۆی گەڕاوە') !== false) {
            return true;
        }
        return (bool)preg_match('/RET-\d{8}-\d+/', $notes);
    }
}

if (!function_exists('getDebtPaymentReturnNoteSqlCondition')) {
    function getDebtPaymentReturnNoteSqlCondition($alias = 'dp') {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'dp';
        return "({$alias}.notes LIKE '%کەمکردنەوەی قەرز بەهۆی گەڕاوە%' OR {$alias}.notes REGEXP 'RET-[0-9]{8}-[0-9]+')";
    }
}

if (!function_exists('enrichDebtRowsWithPaymentBreakdown')) {
    /**
     * @param array<int, array<string, mixed>> $debts
     * @return array<int, array<string, mixed>>
     */
    function enrichDebtRowsWithPaymentBreakdown($conn, array $debts) {
        if (empty($debts) || !($conn instanceof mysqli)) {
            return $debts;
        }

        $debtIds = [];
        foreach ($debts as $debt) {
            $id = (int)($debt['id'] ?? 0);
            if ($id > 0) {
                $debtIds[] = $id;
            }
        }
        if (empty($debtIds)) {
            return $debts;
        }

        $received = [];
        $returned = [];
        $placeholders = implode(',', array_fill(0, count($debtIds), '?'));
        $types = str_repeat('i', count($debtIds));
        $stmt = $conn->prepare("
            SELECT debt_id, payment_amount, notes
            FROM debt_payments
            WHERE debt_id IN ($placeholders)
        ");
        if (!$stmt) {
            return $debts;
        }
        $stmt->bind_param($types, ...$debtIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $debtId = (int)$row['debt_id'];
            $amount = (float)$row['payment_amount'];
            if (debtPaymentIsReturnAdjustmentNote($row['notes'] ?? '')) {
                $returned[$debtId] = ($returned[$debtId] ?? 0) + $amount;
            } else {
                $received[$debtId] = ($received[$debtId] ?? 0) + $amount;
            }
        }

        foreach ($debts as &$debt) {
            $debtId = (int)($debt['id'] ?? 0);
            $debt['received_payments'] = (float)($received[$debtId] ?? 0);
            $debt['return_payments'] = (float)($returned[$debtId] ?? 0);
        }
        unset($debt);

        return $debts;
    }
}

if (!function_exists('fetchDebtPaymentBreakdownTotals')) {
    /**
     * @return array{received_iqd: float, received_usd: float, return_iqd: float, return_usd: float}
     */
    function fetchDebtPaymentBreakdownTotals($conn, $userId, $whereClause, array $params, $types) {
        $returnSql = getDebtPaymentReturnNoteSqlCondition('dp');
        $query = "
            SELECT
                CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN 'USD' ELSE 'IQD' END AS currency,
                COALESCE(SUM(CASE WHEN {$returnSql} THEN dp.payment_amount ELSE 0 END), 0) AS return_total,
                COALESCE(SUM(CASE WHEN NOT {$returnSql} THEN dp.payment_amount ELSE 0 END), 0) AS received_total
            FROM debt_payments dp
            INNER JOIN debts d ON d.id = dp.debt_id
            LEFT JOIN sales s ON d.sale_id = s.id
            {$whereClause}
            GROUP BY CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN 'USD' ELSE 'IQD' END
        ";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return [
                'received_iqd' => 0.0,
                'received_usd' => 0.0,
                'return_iqd' => 0.0,
                'return_usd' => 0.0,
            ];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totals = [
            'received_iqd' => 0.0,
            'received_usd' => 0.0,
            'return_iqd' => 0.0,
            'return_usd' => 0.0,
        ];

        foreach ($rows as $row) {
            $currency = ($row['currency'] ?? 'IQD') === 'USD' ? 'usd' : 'iqd';
            $totals['received_' . $currency] = (float)$row['received_total'];
            $totals['return_' . $currency] = (float)$row['return_total'];
        }

        return $totals;
    }
}
