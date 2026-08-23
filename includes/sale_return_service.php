<?php
/**
 * Sale-linked return helpers.
 */

if (!function_exists('ensureSaleReturnLinkColumns')) {
    function ensureSaleReturnLinkColumns($conn) {
        if (!($conn instanceof mysqli)) {
            return;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'returns'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $columnCheck = $conn->query("SHOW COLUMNS FROM returns LIKE 'sale_id'");
            if (!$columnCheck || $columnCheck->num_rows === 0) {
                $conn->query("ALTER TABLE `returns` ADD COLUMN `sale_id` INT NULL DEFAULT NULL AFTER `customer_id`");
                $conn->query("ALTER TABLE `returns` ADD KEY `idx_returns_sale_id` (`sale_id`)");
            }
            if ($columnCheck) {
                $columnCheck->free();
            }
        }
        if ($tableCheck) {
            $tableCheck->free();
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'return_items'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $columnCheck = $conn->query("SHOW COLUMNS FROM return_items LIKE 'sale_item_id'");
            if (!$columnCheck || $columnCheck->num_rows === 0) {
                $conn->query("ALTER TABLE `return_items` ADD COLUMN `sale_item_id` INT NULL DEFAULT NULL AFTER `return_id`");
                $conn->query("ALTER TABLE `return_items` ADD KEY `idx_return_items_sale_item_id` (`sale_item_id`)");
            }
            if ($columnCheck) {
                $columnCheck->free();
            }
        }
        if ($tableCheck) {
            $tableCheck->free();
        }
    }
}

if (!function_exists('saleReturnHasSaleIdColumn')) {
    function saleReturnHasSaleIdColumn($conn) {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }
        $result = $conn->query("SHOW COLUMNS FROM returns LIKE 'sale_id'");
        $hasColumn = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $hasColumn;
    }
}

if (!function_exists('saleReturnHasSaleItemIdColumn')) {
    function saleReturnHasSaleItemIdColumn($conn) {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }
        $result = $conn->query("SHOW COLUMNS FROM return_items LIKE 'sale_item_id'");
        $hasColumn = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $hasColumn;
    }
}

require_once __DIR__ . '/debt_payment_breakdown.php';

if (!function_exists('getSaleItemReturnedQuantities')) {
    /**
     * @return array<int, float> sale_item_id => returned_qty
     */
    function getSaleItemReturnedQuantities($conn, $userId, $saleId) {
        ensureSaleReturnLinkColumns($conn);

        $map = [];
        $saleId = (int)$saleId;
        $userId = (int)$userId;
        if ($saleId <= 0 || $userId <= 0 || !saleReturnHasSaleIdColumn($conn) || !saleReturnHasSaleItemIdColumn($conn)) {
            return $map;
        }

        $stmt = $conn->prepare("
            SELECT ri.sale_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_qty
            FROM return_items ri
            INNER JOIN returns r ON r.id = ri.return_id
            WHERE r.sale_id = ? AND r.user_id = ? AND ri.sale_item_id IS NOT NULL
            GROUP BY ri.sale_item_id
        ");
        if (!$stmt) {
            return $map;
        }
        $stmt->bind_param('ii', $saleId, $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $map[(int)$row['sale_item_id']] = (float)$row['returned_qty'];
        }

        return $map;
    }
}

if (!function_exists('validateSaleEditWithReturns')) {
    /**
     * پشکنینی دەستکاریکردنی وەسڵ لەگەڵ گەڕاوەکانی پێشوو.
     *
     * @param array<int, float> $returnedBySaleItem
     * @throws InvalidArgumentException
     */
    function validateSaleEditWithReturns($conn, $userId, $saleId, array $normalizedNewItems, array $returnedBySaleItem) {
        $saleId = (int)$saleId;
        $userId = (int)$userId;

        $itemsStmt = $conn->prepare("
            SELECT id, quantity, product_name
            FROM sale_items
            WHERE sale_id = ?
        ");
        if (!$itemsStmt) {
            return;
        }
        $itemsStmt->bind_param('i', $saleId);
        $itemsStmt->execute();
        $oldItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        $newQtyBySaleItem = [];
        foreach ($normalizedNewItems as $item) {
            $saleItemId = (int)($item['sale_item_id'] ?? 0);
            if ($saleItemId <= 0) {
                continue;
            }
            $newQtyBySaleItem[$saleItemId] = ($newQtyBySaleItem[$saleItemId] ?? 0) + (float)$item['quantity'];
        }

        foreach ($oldItems as $oldItem) {
            $saleItemId = (int)$oldItem['id'];
            $soldQty = (float)$oldItem['quantity'];
            $returnedQty = (float)($returnedBySaleItem[$saleItemId] ?? 0);
            if ($returnedQty <= 0) {
                continue;
            }

            $newQty = (float)($newQtyBySaleItem[$saleItemId] ?? 0);
            $name = $oldItem['product_name'] ?? 'کاڵا';

            if ($newQty + 0.00001 < $returnedQty) {
                throw new InvalidArgumentException(
                    'بڕی "' . $name . '" ناتوانێت کەمتر بێت لە ' . $returnedQty . ' (پێشتر گەڕاوەتەوە)'
                );
            }

            if ($newQty <= 0 && $returnedQty + 0.00001 < $soldQty) {
                throw new InvalidArgumentException(
                    'ناتوانیت "' . $name . '" لە وەسڵەکە بسڕیتەوە چونکە بەشێکی پێشتر گەڕاوەتەوە'
                );
            }
        }
    }
}

if (!function_exists('saleReturnResolveRefundPaymentMethod')) {
    /**
     * دەستنیشانکردنی شێوەی گەڕاندنەوە: قەرز ئەگەر قەرزی ماوە هەبێت، وەڵا نەقد لە قاسە.
     */
    function saleReturnResolveRefundPaymentMethod($conn, $userId, array $sale, array $debtSummary, bool $isDebtSale) {
        if (!$isDebtSale) {
            return 'cash';
        }

        $customerId = !empty($sale['customer_id']) ? (int)$sale['customer_id'] : 0;
        if ($customerId <= 0) {
            return 'cash';
        }

        $saleRemaining = (!empty($debtSummary['has_debt']) && ($debtSummary['status'] ?? '') === 'active')
            ? (float)($debtSummary['remaining_amount'] ?? 0)
            : 0.0;

        if ($saleRemaining > 0.00001) {
            return 'debt';
        }

        $currency = $sale['currency'] ?? 'IQD';
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(d.remaining_amount), 0) AS total_outstanding
            FROM debts d
            LEFT JOIN sales s ON d.sale_id = s.id
            WHERE d.user_id = ?
              AND d.customer_id = ?
              AND d.status = 'active'
              AND COALESCE(s.currency, 'IQD') = ?
        ");
        if (!$stmt) {
            return 'cash';
        }
        $stmt->bind_param('iis', $userId, $customerId, $currency);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalOutstanding = (float)($row['total_outstanding'] ?? 0);

        return $totalOutstanding > 0.00001 ? 'debt' : 'cash';
    }
}

if (!function_exists('getSaleReturnContext')) {
    function getSaleReturnContext($conn, $userId, $saleId, $restrictSubUserId = null) {
        ensureSaleReturnLinkColumns($conn);

        $saleId = (int)$saleId;
        $userId = (int)$userId;
        if ($saleId <= 0 || $userId <= 0) {
            return null;
        }

        // کارمەندی سنووردار: تەنها بۆ فرۆشتنی خۆی
        $ownFilter = ($restrictSubUserId !== null) ? " AND s.sub_user_id = ?" : "";
        $stmt = $conn->prepare("
            SELECT s.*, c.phone AS customer_phone
            FROM sales s
            LEFT JOIN customers c ON c.id = s.customer_id
            WHERE s.id = ? AND s.user_id = ?" . $ownFilter . "
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        if ($restrictSubUserId !== null) {
            $restrictSubUserId = (int)$restrictSubUserId;
            $stmt->bind_param('iii', $saleId, $userId, $restrictSubUserId);
        } else {
            $stmt->bind_param('ii', $saleId, $userId);
        }
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sale) {
            return null;
        }

        $itemsStmt = $conn->prepare("
            SELECT si.*,
                   COALESCE(p.name, si.product_name) AS display_name,
                   p.barcode,
                   CASE WHEN e.sale_item_id IS NOT NULL THEN 1 ELSE 0 END AS is_external
            FROM sale_items si
            LEFT JOIN products p ON p.id = si.product_id
            LEFT JOIN sale_item_external_costs e ON e.sale_item_id = si.id
            WHERE si.sale_id = ?
            ORDER BY si.id
        ");
        $itemsStmt->bind_param('i', $saleId);
        $itemsStmt->execute();
        $rawItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        $returnedBySaleItem = [];
        if (saleReturnHasSaleIdColumn($conn) && saleReturnHasSaleItemIdColumn($conn)) {
            $returnedStmt = $conn->prepare("
                SELECT ri.sale_item_id, COALESCE(SUM(ri.quantity), 0) AS returned_qty
                FROM return_items ri
                INNER JOIN returns r ON r.id = ri.return_id
                WHERE r.sale_id = ? AND r.user_id = ? AND ri.sale_item_id IS NOT NULL
                GROUP BY ri.sale_item_id
            ");
            if ($returnedStmt) {
                $returnedStmt->bind_param('ii', $saleId, $userId);
                $returnedStmt->execute();
                $returnedRows = $returnedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $returnedStmt->close();
                foreach ($returnedRows as $row) {
                    $returnedBySaleItem[(int)$row['sale_item_id']] = (float)$row['returned_qty'];
                }
            }
        }

        $items = [];
        foreach ($rawItems as $row) {
            $saleItemId = (int)$row['id'];
            $soldQty = (float)$row['quantity'];
            $returnedQty = (float)($returnedBySaleItem[$saleItemId] ?? 0);
            $returnableQty = max(0, $soldQty - $returnedQty);
            $lineTotalSold = (float)$row['total_price'];
            $listedUnitPrice = (float)$row['unit_price'];
            // نرخی گەڕاندنەوە = نرخی ڕاستەقینەی هەمان وەسڵ (نەک نرخی ئێستای کاڵا لە products)
            $unitReturnPrice = $soldQty > 0
                ? ($lineTotalSold / $soldQty)
                : $listedUnitPrice;

            $items[] = [
                'sale_item_id' => $saleItemId,
                'product_id' => (int)($row['product_id'] ?? 0),
                'product_name' => $row['display_name'] ?? $row['product_name'],
                'quantity_sold' => $soldQty,
                'returned_qty' => $returnedQty,
                'returnable_qty' => $returnableQty,
                'unit_price' => $unitReturnPrice,
                'unit_price_listed' => $listedUnitPrice,
                'line_total_sold' => $lineTotalSold,
                'unit_id' => $row['unit_id'] !== null ? (int)$row['unit_id'] : null,
                'unit_name' => $row['unit_name'] ?? 'دانە',
                'unit_symbol' => $row['unit_symbol'] ?? '',
                'price_type' => $row['price_type'] ?? 'retail',
                'currency' => $row['currency'] ?? ($sale['currency'] ?? 'IQD'),
                'is_external' => !empty($row['is_external']),
            ];
        }

        $priorReturns = [];
        if (saleReturnHasSaleIdColumn($conn)) {
            $priorStmt = $conn->prepare("
                SELECT id, return_number, final_amount, return_date, payment_method
                FROM returns
                WHERE sale_id = ? AND user_id = ?
                ORDER BY return_date DESC, id DESC
            ");
            if ($priorStmt) {
                $priorStmt->bind_param('ii', $saleId, $userId);
                $priorStmt->execute();
                $priorReturns = $priorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $priorStmt->close();
            }
        }

        $debtSummary = [
            'has_debt' => false,
            'debt_id' => null,
            'total_debt' => 0.0,
            'paid_amount' => 0.0,
            'remaining_amount' => 0.0,
            'status' => null,
        ];
        $debtStmt = $conn->prepare("
            SELECT id, total_debt, paid_amount, remaining_amount, status
            FROM debts
            WHERE sale_id = ? AND user_id = ?
            LIMIT 1
        ");
        if ($debtStmt) {
            $debtStmt->bind_param('ii', $saleId, $userId);
            $debtStmt->execute();
            $debtRow = $debtStmt->get_result()->fetch_assoc();
            $debtStmt->close();
            if ($debtRow) {
                $debtSummary = [
                    'has_debt' => true,
                    'debt_id' => (int)$debtRow['id'],
                    'total_debt' => (float)$debtRow['total_debt'],
                    'paid_amount' => (float)$debtRow['paid_amount'],
                    'remaining_amount' => (float)$debtRow['remaining_amount'],
                    'status' => $debtRow['status'],
                ];
            }
        }

        $paymentMethod = $sale['payment_method'] ?? 'cash';
        $isDebtSale = in_array($paymentMethod, ['debt', 'credit', 'installment'], true);
        $suggestedRefundMethod = saleReturnResolveRefundPaymentMethod($conn, $userId, $sale, $debtSummary, $isDebtSale);

        return [
            'sale' => $sale,
            'items' => $items,
            'prior_returns' => $priorReturns,
            'debt_summary' => $debtSummary,
            'is_debt_sale' => $isDebtSale,
            'suggested_return_payment_method' => $suggestedRefundMethod,
            'suggested_wallet_id' => !empty($sale['wallet_id']) ? (int)$sale['wallet_id'] : null,
        ];
    }
}

if (!function_exists('saleReturnGetCustomerOutstanding')) {
    /**
     * کۆی قەرزی ماوەی کڕیار بەپێی currency.
     */
    function saleReturnGetCustomerOutstanding($conn, $userId, $customerId, $currency = 'IQD') {
        $customerId = (int)$customerId;
        $userId = (int)$userId;
        if ($customerId <= 0 || $userId <= 0) {
            return 0.0;
        }

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(d.remaining_amount), 0) AS total_outstanding
            FROM debts d
            LEFT JOIN sales s ON d.sale_id = s.id
            WHERE d.user_id = ?
              AND d.customer_id = ?
              AND d.status = 'active'
              AND COALESCE(s.currency, 'IQD') = ?
        ");
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('iis', $userId, $customerId, $currency);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (float)($row['total_outstanding'] ?? 0);
    }
}

if (!function_exists('saleReturnResolveExtraProduct')) {
    /**
     * پشتڕاستکردنەوەی کاڵای زیادکراو لە کۆگا (نە لەسەر وەسڵ).
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    function saleReturnResolveExtraProduct($conn, $userId, array $reqItem, $saleCurrency) {
        $productId = (int)($reqItem['product_id'] ?? 0);
        $quantity = (float)($reqItem['quantity'] ?? 0);
        $unitId = !empty($reqItem['unit_id']) ? (int)$reqItem['unit_id'] : null;
        $unitPrice = (float)($reqItem['unit_price'] ?? -1);

        if ($productId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException('کاڵای زیادکراو نادروستە');
        }
        if ($unitPrice < 0) {
            throw new InvalidArgumentException('نرخی کاڵای زیادکراو نادروستە');
        }

        $productStmt = $conn->prepare("
            SELECT p.id, p.name
            FROM products p
            WHERE p.id = ? AND p.user_id = ?
            LIMIT 1
        ");
        if (!$productStmt) {
            throw new InvalidArgumentException('نەتوانرا کاڵا پشتڕاست بکرێتەوە');
        }
        $productStmt->bind_param('ii', $productId, $userId);
        $productStmt->execute();
        $productRow = $productStmt->get_result()->fetch_assoc();
        $productStmt->close();

        if (!$productRow) {
            throw new InvalidArgumentException('کاڵا لە کۆگادا نەدۆزرایەوە');
        }

        if ($unitId) {
            $unitStmt = $conn->prepare("
                SELECT pu.unit_id, pu.sell_price, pu.currency,
                       u.name AS unit_name, u.symbol AS unit_symbol
                FROM product_units pu
                LEFT JOIN units u ON u.id = pu.unit_id
                WHERE pu.product_id = ? AND pu.unit_id = ?
                LIMIT 1
            ");
            if (!$unitStmt) {
                throw new InvalidArgumentException('نەتوانرا یەکەی کاڵا پشتڕاست بکرێتەوە');
            }
            $unitStmt->bind_param('ii', $productId, $unitId);
            $unitStmt->execute();
            $unitRow = $unitStmt->get_result()->fetch_assoc();
            $unitStmt->close();
        } else {
            $unitStmt = $conn->prepare("
                SELECT pu.unit_id, pu.sell_price, pu.currency,
                       u.name AS unit_name, u.symbol AS unit_symbol
                FROM product_units pu
                LEFT JOIN units u ON u.id = pu.unit_id
                WHERE pu.product_id = ?
                ORDER BY pu.is_primary DESC, pu.id ASC
                LIMIT 1
            ");
            if (!$unitStmt) {
                throw new InvalidArgumentException('نەتوانرا یەکەی کاڵا پشتڕاست بکرێتەوە');
            }
            $unitStmt->bind_param('i', $productId);
            $unitStmt->execute();
            $unitRow = $unitStmt->get_result()->fetch_assoc();
            $unitStmt->close();
            $unitId = $unitRow ? (int)$unitRow['unit_id'] : null;
        }

        if (!$unitRow) {
            throw new InvalidArgumentException('یەکەی کاڵا نەدۆزرایەوە');
        }

        $unitCurrency = $unitRow['currency'] ?? 'IQD';
        if ($unitCurrency !== $saleCurrency) {
            throw new InvalidArgumentException(
                'کاڵای "' . ($productRow['name'] ?? 'کاڵا') . '" بە ' . $unitCurrency . ' ـە، بەڵام وەسڵەکە ' . $saleCurrency . ' ـە'
            );
        }

        return [
            'sale_item_id' => null,
            'product_id' => $productId,
            'product_name' => trim((string)($reqItem['product_name'] ?? $productRow['name'] ?? 'کاڵا')),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'price_type' => $reqItem['price_type'] ?? 'retail',
            'unit_id' => $unitId,
            'unit_name' => $unitRow['unit_name'] ?? ($reqItem['unit_name'] ?? 'دانە'),
            'unit_symbol' => $unitRow['unit_symbol'] ?? ($reqItem['unit_symbol'] ?? ''),
        ];
    }
}

if (!function_exists('validateAndNormalizeSaleReturnItems')) {
    /**
     * @return array{items: array, total_amount: float, final_amount: float, discount: float}
     * @throws InvalidArgumentException
     */
    function validateAndNormalizeSaleReturnItems($conn, $userId, array $context, array $requestItems) {
        $itemMap = [];
        foreach ($context['items'] as $ctxItem) {
            $itemMap[(int)$ctxItem['sale_item_id']] = $ctxItem;
        }

        $normalized = [];
        $totalAmount = 0.0;
        $currency = $context['sale']['currency'] ?? 'IQD';
        $decimals = $currency === 'USD' ? 2 : 0;
        $userId = (int)$userId;

        foreach ($requestItems as $reqItem) {
            $saleItemId = (int)($reqItem['sale_item_id'] ?? 0);
            $quantity = (float)($reqItem['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            if ($saleItemId > 0) {
                if (!isset($itemMap[$saleItemId])) {
                    throw new InvalidArgumentException('هەندێک کاڵا بەم وەسڵەوە ناگونجێت');
                }

                $ctxItem = $itemMap[$saleItemId];
                if (!empty($ctxItem['is_external'])) {
                    throw new InvalidArgumentException('گەڕاندنەوە بۆ کاڵای دەرەکی ڕێگەپێنەدراوە');
                }

                if ($quantity > $ctxItem['returnable_qty'] + 0.00001) {
                    $name = $ctxItem['product_name'] ?? 'کاڵا';
                    throw new InvalidArgumentException('بڕی گەڕاندنەوە بۆ "' . $name . '" لە ماوە زیاترە');
                }

                // هەرگیز unit_price لە request وەرناگرین — تەنها لە sale_items (نرخی کاتی فرۆشتن)
                $unitPrice = (float)$ctxItem['unit_price'];
                $lineTotal = round($quantity * $unitPrice, $decimals);
                $totalAmount += $lineTotal;

                $normalized[] = [
                    'sale_item_id' => $saleItemId,
                    'product_id' => (int)$ctxItem['product_id'],
                    'product_name' => $ctxItem['product_name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                    'price_type' => $ctxItem['price_type'] ?? 'retail',
                    'unit_id' => $ctxItem['unit_id'],
                    'unit_name' => $ctxItem['unit_name'] ?? 'دانە',
                    'unit_symbol' => $ctxItem['unit_symbol'] ?? '',
                ];
                continue;
            }

            $resolved = saleReturnResolveExtraProduct($conn, $userId, $reqItem, $currency);
            $unitPrice = (float)$resolved['unit_price'];
            $lineTotal = round($quantity * $unitPrice, $decimals);
            $totalAmount += $lineTotal;

            $normalized[] = array_merge($resolved, [
                'quantity' => $quantity,
                'total_price' => $lineTotal,
            ]);
        }

        if (empty($normalized)) {
            throw new InvalidArgumentException('تکایە لانیکەم یەک کاڵا بۆ گەڕاندنەوە دیاری بکە');
        }

        $totalAmount = round($totalAmount, $decimals);

        return [
            'items' => $normalized,
            'total_amount' => $totalAmount,
            'final_amount' => $totalAmount,
            'discount' => 0.0,
        ];
    }
}

if (!function_exists('applySaleLinkedDebtReduction')) {
    /**
     * Apply return amount to sale debt first, then remaining via LIFO.
     *
     * @return float Amount still not applied (should be ~0)
     */
    function applySaleLinkedDebtReduction($conn, $userId, $customerId, $saleId, $finalAmount, $returnCurrency, $returnNumber) {
        $remainingToApply = (float)$finalAmount;
        if ($remainingToApply <= 0 || !$customerId) {
            return 0.0;
        }

        $saleId = (int)$saleId;
        $userId = (int)$userId;
        $customerId = (int)$customerId;

        $saleDebtStmt = $conn->prepare("
            SELECT d.id, d.remaining_amount
            FROM debts d
            WHERE d.sale_id = ? AND d.user_id = ? AND d.customer_id = ?
              AND d.status = 'active' AND d.remaining_amount > 0
            LIMIT 1
        ");
        if ($saleDebtStmt) {
            $saleDebtStmt->bind_param('iii', $saleId, $userId, $customerId);
            $saleDebtStmt->execute();
            $saleDebt = $saleDebtStmt->get_result()->fetch_assoc();
            $saleDebtStmt->close();

            if ($saleDebt) {
                $remainingToApply = saleReturnApplyDebtSlice(
                    $conn,
                    $userId,
                    (int)$saleDebt['id'],
                    $remainingToApply,
                    $returnNumber
                );
            }
        }

        if ($remainingToApply > 0.00001) {
            $activeDebtsStmt = $conn->prepare("
                SELECT d.id, d.remaining_amount
                FROM debts d
                LEFT JOIN sales s ON d.sale_id = s.id
                WHERE d.user_id = ?
                  AND d.customer_id = ?
                  AND d.status = 'active'
                  AND d.remaining_amount > 0
                  AND d.sale_id != ?
                  AND COALESCE(s.currency, 'IQD') = ?
                ORDER BY d.created_at DESC, d.id DESC
            ");
            $activeDebtsStmt->bind_param('iiis', $userId, $customerId, $saleId, $returnCurrency);
            $activeDebtsStmt->execute();
            $activeDebts = $activeDebtsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $activeDebtsStmt->close();

            foreach ($activeDebts as $debt) {
                if ($remainingToApply <= 0) {
                    break;
                }
                $remainingToApply = saleReturnApplyDebtSlice(
                    $conn,
                    $userId,
                    (int)$debt['id'],
                    $remainingToApply,
                    $returnNumber
                );
            }
        }

        saleReturnSyncCustomerDebt($conn, $userId, $customerId);
        saleReturnSyncSaleRemaining($conn, $userId, $saleId);

        return $remainingToApply;
    }
}

if (!function_exists('saleReturnApplyDebtSlice')) {
    function saleReturnApplyDebtSlice($conn, $userId, $debtId, $remainingToApply, $returnNumber) {
        $debtStmt = $conn->prepare("SELECT remaining_amount FROM debts WHERE id = ? AND user_id = ? LIMIT 1");
        $debtStmt->bind_param('ii', $debtId, $userId);
        $debtStmt->execute();
        $debtRow = $debtStmt->get_result()->fetch_assoc();
        $debtStmt->close();

        if (!$debtRow) {
            return $remainingToApply;
        }

        $debtRemaining = (float)$debtRow['remaining_amount'];
        if ($debtRemaining <= 0) {
            return $remainingToApply;
        }

        $appliedAmount = min($remainingToApply, $debtRemaining);
        $newRemaining = $debtRemaining - $appliedAmount;
        $newStatus = $newRemaining <= 0.00001 ? 'completed' : 'active';

        $updateDebtStmt = $conn->prepare("
            UPDATE debts
            SET paid_amount = paid_amount + ?,
                remaining_amount = GREATEST(remaining_amount - ?, 0),
                status = ?
            WHERE id = ? AND user_id = ?
        ");
        $updateDebtStmt->bind_param('ddsii', $appliedAmount, $appliedAmount, $newStatus, $debtId, $userId);
        if (!$updateDebtStmt->execute()) {
            throw new Exception('هەڵەی نوێکردنەوەی قەرز');
        }
        $updateDebtStmt->close();

        $paymentDate = date('Y-m-d H:i:s');
        $paymentNote = "کەمکردنەوەی قەرز بەهۆی گەڕاوە - {$returnNumber}";
        $insertPaymentStmt = $conn->prepare("
            INSERT INTO debt_payments (debt_id, payment_amount, payment_date, notes)
            VALUES (?, ?, ?, ?)
        ");
        $insertPaymentStmt->bind_param('idss', $debtId, $appliedAmount, $paymentDate, $paymentNote);
        if (!$insertPaymentStmt->execute()) {
            throw new Exception('هەڵەی تۆمارکردنی کەمکردنەوەی قەرز');
        }
        $insertPaymentStmt->close();

        return $remainingToApply - $appliedAmount;
    }
}

if (!function_exists('saleReturnSyncCustomerDebt')) {
    function saleReturnSyncCustomerDebt($conn, $userId, $customerId) {
        $updateCustomerDebtStmt = $conn->prepare("
            UPDATE customers
            SET total_debt = (
                SELECT COALESCE(SUM(remaining_amount), 0)
                FROM debts
                WHERE customer_id = ? AND status = 'active'
            )
            WHERE id = ? AND user_id = ?
        ");
        $updateCustomerDebtStmt->bind_param('iii', $customerId, $customerId, $userId);
        if (!$updateCustomerDebtStmt->execute()) {
            throw new Exception('هەڵەی نوێکردنەوەی کۆی قەرزی کڕیار');
        }
        $updateCustomerDebtStmt->close();
    }
}

if (!function_exists('saleReturnSyncSaleRemaining')) {
    function saleReturnSyncSaleRemaining($conn, $userId, $saleId) {
        $debtStmt = $conn->prepare("
            SELECT remaining_amount FROM debts WHERE sale_id = ? AND user_id = ? LIMIT 1
        ");
        $debtStmt->bind_param('ii', $saleId, $userId);
        $debtStmt->execute();
        $debtRow = $debtStmt->get_result()->fetch_assoc();
        $debtStmt->close();

        if (!$debtRow) {
            return;
        }

        $remaining = max(0, (float)$debtRow['remaining_amount']);
        $updateSaleStmt = $conn->prepare("
            UPDATE sales
            SET remaining_amount = ?
            WHERE id = ? AND user_id = ?
        ");
        $updateSaleStmt->bind_param('dii', $remaining, $saleId, $userId);
        $updateSaleStmt->execute();
        $updateSaleStmt->close();
    }
}

if (!function_exists('getSaleReceiptReturnsData')) {
    function getSaleReceiptReturnsData($conn, $userId, $saleId) {
        ensureSaleReturnLinkColumns($conn);

        if (!saleReturnHasSaleIdColumn($conn)) {
            return ['rows' => [], 'total_returned' => 0.0];
        }

        $saleId = (int)$saleId;
        $userId = (int)$userId;

        $stmt = $conn->prepare("
            SELECT r.return_number,
                   r.return_date,
                   ri.product_name,
                   ri.quantity,
                   ri.unit_price,
                   ri.total_price,
                   ri.unit_symbol,
                   ri.unit_name,
                   p.image_path AS product_image_path,
                   COALESCE(s.currency, 'IQD') AS currency
            FROM returns r
            INNER JOIN return_items ri ON ri.return_id = r.id
            LEFT JOIN sales s ON s.id = r.sale_id
            LEFT JOIN products p ON p.id = ri.product_id AND p.user_id = r.user_id
            WHERE r.sale_id = ? AND r.user_id = ?
            ORDER BY r.return_date ASC, ri.id ASC
        ");
        if (!$stmt) {
            return ['rows' => [], 'total_returned' => 0.0];
        }

        $stmt->bind_param('ii', $saleId, $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totalReturned = 0.0;
        foreach ($rows as $row) {
            $totalReturned += (float)$row['total_price'];
        }

        return [
            'rows' => $rows,
            'total_returned' => $totalReturned,
        ];
    }
}

if (!function_exists('getSaleReceiptNetFinalAmount')) {
    function getSaleReceiptNetFinalAmount(array $sale, array $returnsData): float {
        $final = (float)($sale['final_amount'] ?? 0);
        $returned = (float)($returnsData['total_returned'] ?? 0);
        return max(0.0, $final - $returned);
    }
}

if (!function_exists('getSaleReceiptPaidReceivedAmount')) {
    /**
     * Actual cash received for a sale (excludes return debt adjustments).
     * For debt/credit: sums debt_payments excluding return notes.
     * For cash/card: uses sales.paid_amount.
     */
    function getSaleReceiptPaidReceivedAmount($conn, $userId, $saleId, array $sale = []): float {
        $paymentMethod = (string)($sale['payment_method'] ?? '');
        if (!in_array($paymentMethod, ['debt', 'credit', 'installment'], true)) {
            return (float)($sale['paid_amount'] ?? 0);
        }

        if (!function_exists('debtPaymentIsReturnAdjustmentNote')) {
            require_once __DIR__ . '/debt_payment_breakdown.php';
        }

        $saleId = (int)$saleId;
        $userId = (int)$userId;

        $debtStmt = $conn->prepare("
            SELECT d.id
            FROM debts d
            WHERE d.sale_id = ? AND d.user_id = ?
            LIMIT 1
        ");
        if (!$debtStmt) {
            return (float)($sale['paid_amount'] ?? 0);
        }

        $debtStmt->bind_param('ii', $saleId, $userId);
        $debtStmt->execute();
        $debtRow = $debtStmt->get_result()->fetch_assoc();
        $debtStmt->close();

        if (!$debtRow) {
            return (float)($sale['paid_amount'] ?? 0);
        }

        $debtId = (int)$debtRow['id'];
        $payStmt = $conn->prepare("
            SELECT payment_amount, notes
            FROM debt_payments
            WHERE debt_id = ?
        ");
        if (!$payStmt) {
            return (float)($sale['paid_amount'] ?? 0);
        }

        $payStmt->bind_param('i', $debtId);
        $payStmt->execute();
        $payments = $payStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $payStmt->close();

        $received = 0.0;
        foreach ($payments as $payment) {
            if (debtPaymentIsReturnAdjustmentNote($payment['notes'] ?? '')) {
                continue;
            }
            $received += (float)($payment['payment_amount'] ?? 0);
        }

        return $received;
    }
}
