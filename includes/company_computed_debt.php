<?php
/**
 * ماوەی قەرزی کۆمپانیا — هەمان منطقی user/companies/debts.php
 * (مامەڵەی company_debts بە purchase_receipt_id IS NULL + ماوەی وەسڵ بە purchase_receipt_id)
 *
 * پشتگیری دوو دراو (IQD / USD): هەر کۆمپانیایەک دوو باڵانسی سەربەخۆی هەیە.
 * بەرگری پاشەکەوت: پارامەتری $currency بە بنەڕەت 'IQD'ـە، بۆیە هەموو ئەو
 * پیشاندانە کۆنانەی «دینار» هەر بە دینار و ڕاست دەمێننەوە.
 */

/**
 * پاککردنەوەی ناوی دراو بۆ IQD/USD (بۆ inline-کردنی سەلامەت لە SQL).
 */
function company_debt_normalize_currency(string $currency): string
{
    return ($currency === 'USD') ? 'USD' : 'IQD';
}

/**
 * مەرج بۆ دەرکردنی تۆماری قەرزی «وەسڵی کڕین #» کە لەگەڵ وەسڵی چالاک دووبارە دەبێتەوە.
 */
function company_debt_mirror_exclude_sql(): string
{
    return "cd.type = 'debt'
    AND cd.description LIKE 'وەسڵی کڕین #%'
    AND EXISTS (
        SELECT 1 FROM purchase_receipts pr
        WHERE pr.company_id = cd.company_id
          AND pr.user_id = cd.user_id
          AND pr.payment_type = 'debt'
          AND pr.status = 'active'
          AND (
              cd.description = CONCAT('وەسڵی کڕین #', pr.id)
              OR (pr.receipt_number IS NOT NULL AND TRIM(pr.receipt_number) <> ''
                  AND cd.description = CONCAT('وەسڵی کڕین #', pr.receipt_number))
          )
    )";
}

/**
 * دەربڕینی SQL بۆ یەک ڕیز companies (scalar) — alias بنەڕەتی c.
 */
function company_computed_remaining_debt_expr_sql(string $companyAlias = 'c', string $currency = 'IQD'): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $companyAlias)) {
        $companyAlias = 'c';
    }
    $cur = company_debt_normalize_currency($currency);
    $m = company_debt_mirror_exclude_sql();
    $a = $companyAlias;

    return '(
        COALESCE((
            SELECT GREATEST(0,
                COALESCE(SUM(CASE WHEN cd.type = \'debt\' THEN cd.amount ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN cd.type = \'payment\' THEN cd.amount ELSE 0 END), 0)
            )
            FROM company_debts cd
            WHERE cd.company_id = ' . $a . '.id AND cd.user_id = ' . $a . '.user_id AND cd.purchase_receipt_id IS NULL
            AND cd.currency = \'' . $cur . '\'
            AND NOT (' . $m . ')
        ), 0)
        + COALESCE((
            SELECT SUM(GREATEST(0, pr.final_amount - COALESCE((
                SELECT SUM(cd2.amount)
                FROM company_debts cd2
                WHERE cd2.company_id = pr.company_id
                  AND cd2.user_id = pr.user_id
                  AND cd2.type = \'payment\'
                  AND cd2.currency = \'' . $cur . '\'
                  AND cd2.purchase_receipt_id = pr.id
            ), 0)))
            FROM purchase_receipts pr
            WHERE pr.company_id = ' . $a . '.id AND pr.user_id = ' . $a . '.user_id
              AND pr.payment_type = \'debt\' AND pr.status = \'active\'
              AND pr.currency = \'' . $cur . '\'
        ), 0)
    )';
}

/**
 * ژێر-query: id کۆمپانیا → remaining (بۆ JOIN). پێویستی بە ? بۆ c2.user_id هەیە.
 */
function company_remaining_by_id_subquery_sql(string $currency = 'IQD'): string
{
    $cur = company_debt_normalize_currency($currency);
    $m = company_debt_mirror_exclude_sql();

    return '(SELECT c2.id,
            GREATEST(0, IFNULL(tx.net, 0) + IFNULL(ir.srem, 0)) AS remaining
        FROM companies c2
        LEFT JOIN (
            SELECT cd.company_id, cd.user_id,
                GREATEST(0,
                    COALESCE(SUM(CASE WHEN cd.type = \'debt\' THEN cd.amount ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN cd.type = \'payment\' THEN cd.amount ELSE 0 END), 0)
                ) AS net
            FROM company_debts cd
            WHERE cd.purchase_receipt_id IS NULL
            AND cd.currency = \'' . $cur . '\'
            AND NOT (' . $m . ')
            GROUP BY cd.company_id, cd.user_id
        ) tx ON tx.company_id = c2.id AND tx.user_id = c2.user_id
        LEFT JOIN (
            SELECT pr.company_id, pr.user_id,
                SUM(GREATEST(0, pr.final_amount - COALESCE(po.sum_p, 0))) AS srem
            FROM purchase_receipts pr
            LEFT JOIN (
                SELECT purchase_receipt_id, user_id, company_id, SUM(amount) AS sum_p
                FROM company_debts
                WHERE type = \'payment\' AND currency = \'' . $cur . '\'
                GROUP BY purchase_receipt_id, user_id, company_id
            ) po ON po.purchase_receipt_id = pr.id
                AND po.user_id = pr.user_id
                AND po.company_id = pr.company_id
            WHERE pr.payment_type = \'debt\' AND pr.status = \'active\'
              AND pr.currency = \'' . $cur . '\'
            GROUP BY pr.company_id, pr.user_id
        ) ir ON ir.company_id = c2.id AND ir.user_id = c2.user_id
        WHERE c2.user_id = ?)';
}

/**
 * ماوەی قەرزی ژمێردراو بۆ یەک کۆمپانیا (بۆ دراوێکی دیاریکراو).
 */
function fetch_company_computed_remaining_debt(mysqli $conn, int $companyId, int $userId, string $currency = 'IQD'): float
{
    $expr = company_computed_remaining_debt_expr_sql('c', $currency);
    $sql = 'SELECT ' . $expr . ' AS r FROM companies c WHERE c.id = ? AND c.user_id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return 0.0;
    }
    $stmt->bind_param('ii', $companyId, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return 0.0;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['r'] ?? 0);
}
