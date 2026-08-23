<?php
/**
 * گەڕاندنەوەی کۆگا و نرخ بۆ یەک ڕیزی وەسڵی کڕین — تەنها لە product_units (ستوونی products نەماوە).
 *
 * @param array $oldItem ڕیزی purchase_receipt_items (product_id, quantity, unit_id, buy_price, …، revert_* ئەگەر هەبێت)
 */
function revertPurchaseReceiptLineInventory(mysqli $conn, int $userId, array $oldItem, int $oldStrategy): void
{
    $oldProductId = (int)$oldItem['product_id'];
    $oldQuantity = (float)$oldItem['quantity'];
    $oldUnitId = (int)$oldItem['unit_id'];
    $quantityToSubtract = $oldQuantity;
    $primaryUnitRowId = 0;

    $getOldProductStmt = $conn->prepare("
        SELECT 
            COALESCE(pu.buy_price, 0) AS buy_price,
            COALESCE(pu.sell_price, 0) AS sell_price,
            COALESCE(pu.wholesale_price, 0) AS wholesale_price,
            COALESCE(pu.special_price, 0) AS special_price,
            COALESCE(pu.stock_quantity, 0) AS stock_quantity,
            COALESCE(pu.id, 0) AS primary_unit_row_id,
            COALESCE(pu.conversion_ratio, 1) AS primary_ratio,
            p.expiry_date
        FROM products p
        LEFT JOIN product_units pu ON pu.id = (
            SELECT pu2.id
            FROM product_units pu2
            WHERE pu2.product_id = p.id
            ORDER BY pu2.is_primary DESC, pu2.id ASC
            LIMIT 1
        )
        WHERE p.id = ? AND p.user_id = ?
    ");
    $getOldProductStmt->bind_param("ii", $oldProductId, $userId);
    $getOldProductStmt->execute();
    $oldProductData = $getOldProductStmt->get_result()->fetch_assoc();

    if ($oldProductData) {
        $currentStock = (float)$oldProductData['stock_quantity'];
        $currentBuyPrice = (float)$oldProductData['buy_price'];
        $currentSellPrice = (float)$oldProductData['sell_price'];
        $currentWholesalePrice = (float)$oldProductData['wholesale_price'];
        $currentSpecialPrice = (float)$oldProductData['special_price'];
        $primaryUnitRowId = (int)($oldProductData['primary_unit_row_id'] ?? 0);

        // کەمکردنەوەی یەکەی سەرەکی بەپێی conversion: ئەگەر یەکەی کڕدراو ناسەرەکی بێت،
        // پێویستە بڕی هاوتای یەکەی سەرەکی کەم بکرێتەوە (نەک بڕی خاوی یەکەی کڕدراو).
        // بۆ کەیسی باو (کڕین بە یەکەی سەرەکی) scale = 1 و هیچ ناگۆڕێت.
        $primaryRatio = (float)($oldProductData['primary_ratio'] ?? 1);
        $purchasedRatio = $primaryRatio;
        if ($oldUnitId > 0) {
            $puRatioStmt = $conn->prepare("SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?");
            $puRatioStmt->bind_param("ii", $oldProductId, $oldUnitId);
            $puRatioStmt->execute();
            $puRatioRow = $puRatioStmt->get_result()->fetch_assoc();
            $fetchedRatio = $puRatioRow['conversion_ratio'] ?? null;
            if ($fetchedRatio !== null && (float)$fetchedRatio > 0) {
                $purchasedRatio = (float)$fetchedRatio;
            }
        }
        $primaryScale = ($primaryRatio > 0) ? ($purchasedRatio / $primaryRatio) : 1.0;
        $primaryStockDeduction = $quantityToSubtract * $primaryScale;

        $stockAfterRevert = $currentStock - $quantityToSubtract;

        $revertStockStmt = $conn->prepare("
            UPDATE product_units
            SET stock_quantity = GREATEST(0, stock_quantity - ?), updated_at = NOW()
            WHERE id = (
                SELECT pux.id
                FROM (
                    SELECT pu.id
                    FROM product_units pu
                    JOIN products p ON p.id = pu.product_id
                    WHERE pu.product_id = ? AND p.user_id = ?
                    ORDER BY pu.is_primary DESC, pu.id ASC
                    LIMIT 1
                ) AS pux
            )
        ");
        $revertStockStmt->bind_param("dii", $primaryStockDeduction, $oldProductId, $userId);
        $revertStockStmt->execute();

        if ($quantityToSubtract > 0) {
            $havePriSnap = $oldStrategy === 0
                && isset($oldItem['revert_buy_price'], $oldItem['revert_sell_price'], $oldItem['revert_wholesale_price'], $oldItem['revert_special_price'])
                && $oldItem['revert_buy_price'] !== null && $oldItem['revert_sell_price'] !== null
                && $oldItem['revert_wholesale_price'] !== null && $oldItem['revert_special_price'] !== null;

            if ($havePriSnap) {
                $revertedBuyPrice = max(0, round((float)$oldItem['revert_buy_price'], 4));
                $revertedSellPrice = max(0, round((float)$oldItem['revert_sell_price'], 4));
                $revertedWholesalePrice = max(0, round((float)$oldItem['revert_wholesale_price'], 4));
                $revertedSpecialPrice = max(0, round((float)$oldItem['revert_special_price'], 4));
                $revertPriceStmt = $conn->prepare("
                    UPDATE product_units
                    SET buy_price = ?, sell_price = ?, wholesale_price = ?, special_price = ?, updated_at = NOW()
                    WHERE id = (
                        SELECT pux.id
                        FROM (
                            SELECT pu.id
                            FROM product_units pu
                            JOIN products p ON p.id = pu.product_id
                            WHERE pu.product_id = ? AND p.user_id = ?
                            ORDER BY pu.is_primary DESC, pu.id ASC
                            LIMIT 1
                        ) AS pux
                    )
                ");
                $revertPriceStmt->bind_param("ddddii",
                    $revertedBuyPrice,
                    $revertedSellPrice,
                    $revertedWholesalePrice,
                    $revertedSpecialPrice,
                    $oldProductId,
                    $userId
                );
                $revertPriceStmt->execute();
            } else {
                $oldBuyPrice = (float)$oldItem['buy_price'];
                $oldSellPrice = (float)$oldItem['sell_price'];
                $oldWholesalePrice = (float)$oldItem['wholesale_price'];
                $oldSpecialPrice = (float)$oldItem['special_price'];

                $actualStockAfterRevert = max(0, $stockAfterRevert);

                if ($actualStockAfterRevert > 0) {
                    $actualPurchaseInStock = min($quantityToSubtract, $currentStock);

                    if ($actualPurchaseInStock > 0 && $currentStock > 0) {
                        $revertedBuyPrice = (($currentStock * $currentBuyPrice) - ($actualPurchaseInStock * $oldBuyPrice)) / $actualStockAfterRevert;
                        $revertedSellPrice = (($currentStock * $currentSellPrice) - ($actualPurchaseInStock * $oldSellPrice)) / $actualStockAfterRevert;
                        $revertedWholesalePrice = (($currentStock * $currentWholesalePrice) - ($actualPurchaseInStock * $oldWholesalePrice)) / $actualStockAfterRevert;
                        $revertedSpecialPrice = (($currentStock * $currentSpecialPrice) - ($actualPurchaseInStock * $oldSpecialPrice)) / $actualStockAfterRevert;
                    } else {
                        $revertedBuyPrice = $currentBuyPrice;
                        $revertedSellPrice = $currentSellPrice;
                        $revertedWholesalePrice = $currentWholesalePrice;
                        $revertedSpecialPrice = $currentSpecialPrice;
                    }

                    $revertedBuyPrice = max(0, round($revertedBuyPrice, 4));
                    $revertedSellPrice = max(0, round($revertedSellPrice, 4));
                    $revertedWholesalePrice = max(0, round($revertedWholesalePrice, 4));
                    $revertedSpecialPrice = max(0, round($revertedSpecialPrice, 4));

                    $revertPriceStmt = $conn->prepare("
                        UPDATE product_units
                        SET buy_price = ?, sell_price = ?, wholesale_price = ?, special_price = ?, updated_at = NOW()
                        WHERE id = (
                            SELECT pux.id
                            FROM (
                                SELECT pu.id
                                FROM product_units pu
                                JOIN products p ON p.id = pu.product_id
                                WHERE pu.product_id = ? AND p.user_id = ?
                                ORDER BY pu.is_primary DESC, pu.id ASC
                                LIMIT 1
                            ) AS pux
                        )
                    ");
                    $revertPriceStmt->bind_param("ddddii",
                        $revertedBuyPrice,
                        $revertedSellPrice,
                        $revertedWholesalePrice,
                        $revertedSpecialPrice,
                        $oldProductId,
                        $userId
                    );
                    $revertPriceStmt->execute();
                } else {
                    $zeroPrice = 0;
                    $revertPriceStmt = $conn->prepare("
                        UPDATE product_units
                        SET buy_price = ?, sell_price = ?, wholesale_price = ?, special_price = ?, updated_at = NOW()
                        WHERE id = (
                            SELECT pux.id
                            FROM (
                                SELECT pu.id
                                FROM product_units pu
                                JOIN products p ON p.id = pu.product_id
                                WHERE pu.product_id = ? AND p.user_id = ?
                                ORDER BY pu.is_primary DESC, pu.id ASC
                                LIMIT 1
                            ) AS pux
                        )
                    ");
                    $revertPriceStmt->bind_param("ddddii",
                        $zeroPrice, $zeroPrice, $zeroPrice, $zeroPrice,
                        $oldProductId,
                        $userId
                    );
                    $revertPriceStmt->execute();
                }
            }
        }

        if (!empty($oldItem['expiry_date']) && $oldItem['expiry_date'] !== null) {
            $revertExpiryStmt = $conn->prepare("
                UPDATE products SET expiry_date = NULL
                WHERE id = ? AND user_id = ? AND expiry_date = ?
            ");
            $revertExpiryStmt->bind_param("iis", $oldProductId, $userId, $oldItem['expiry_date']);
            $revertExpiryStmt->execute();
        }
    }

    if ($oldUnitId > 0) {
        $getOldUnitStmt = $conn->prepare("
            SELECT id AS unit_row_id, buy_price, sell_price, wholesale_price, special_price, stock_quantity
            FROM product_units 
            WHERE product_id = ? AND unit_id = ?
        ");
        $getOldUnitStmt->bind_param("ii", $oldProductId, $oldUnitId);
        $getOldUnitStmt->execute();
        $oldUnitData = $getOldUnitStmt->get_result()->fetch_assoc();

        $oldUnitRowId = (int)($oldUnitData['unit_row_id'] ?? 0);
        if ($oldUnitData && $oldUnitRowId > 0 && $oldUnitRowId !== $primaryUnitRowId) {
            $revertUnitStockStmt = $conn->prepare("
                UPDATE product_units SET 
                    stock_quantity = GREATEST(0, stock_quantity - ?)
                WHERE product_id = ? AND unit_id = ?
            ");
            $revertUnitStockStmt->bind_param("dii", $oldQuantity, $oldProductId, $oldUnitId);
            $revertUnitStockStmt->execute();

            if ($oldQuantity > 0) {
                $currentUnitStock = (float)$oldUnitData['stock_quantity'];
                $unitStockAfterRevert = $currentUnitStock - $oldQuantity;
                $actualUnitStockAfterRevert = max(0, $unitStockAfterRevert);

                if ($actualUnitStockAfterRevert > 0) {
                    $oldUnitBuyPrice = (float)$oldItem['buy_price'];
                    $oldUnitSellPrice = (float)$oldItem['sell_price'];
                    $oldUnitWholesalePrice = (float)$oldItem['wholesale_price'];
                    $oldUnitSpecialPrice = (float)$oldItem['special_price'];

                    $currentUnitBuyPrice = (float)$oldUnitData['buy_price'];
                    $currentUnitSellPrice = (float)$oldUnitData['sell_price'];
                    $currentUnitWholesalePrice = (float)$oldUnitData['wholesale_price'];
                    $currentUnitSpecialPrice = (float)$oldUnitData['special_price'];

                    $actualUnitPurchaseInStock = min($oldQuantity, $currentUnitStock);

                    if ($actualUnitPurchaseInStock > 0 && $currentUnitStock > 0) {
                        $revertedUnitBuyPrice = max(0, round((($currentUnitStock * $currentUnitBuyPrice) - ($actualUnitPurchaseInStock * $oldUnitBuyPrice)) / $actualUnitStockAfterRevert, 4));
                        $revertedUnitSellPrice = max(0, round((($currentUnitStock * $currentUnitSellPrice) - ($actualUnitPurchaseInStock * $oldUnitSellPrice)) / $actualUnitStockAfterRevert, 4));
                        $revertedUnitWholesalePrice = max(0, round((($currentUnitStock * $currentUnitWholesalePrice) - ($actualUnitPurchaseInStock * $oldUnitWholesalePrice)) / $actualUnitStockAfterRevert, 4));
                        $revertedUnitSpecialPrice = max(0, round((($currentUnitStock * $currentUnitSpecialPrice) - ($actualUnitPurchaseInStock * $oldUnitSpecialPrice)) / $actualUnitStockAfterRevert, 4));
                    } else {
                        $revertedUnitBuyPrice = $currentUnitBuyPrice;
                        $revertedUnitSellPrice = $currentUnitSellPrice;
                        $revertedUnitWholesalePrice = $currentUnitWholesalePrice;
                        $revertedUnitSpecialPrice = $currentUnitSpecialPrice;
                    }

                    $revertUnitPriceStmt = $conn->prepare("
                        UPDATE product_units SET 
                            buy_price = ?, 
                            sell_price = ?, 
                            wholesale_price = ?, 
                            special_price = ?
                        WHERE product_id = ? AND unit_id = ?
                    ");
                    $revertUnitPriceStmt->bind_param("ddddii",
                        $revertedUnitBuyPrice,
                        $revertedUnitSellPrice,
                        $revertedUnitWholesalePrice,
                        $revertedUnitSpecialPrice,
                        $oldProductId,
                        $oldUnitId
                    );
                    $revertUnitPriceStmt->execute();
                } else {
                    $zeroPrice = 0;
                    $revertUnitPriceStmt = $conn->prepare("
                        UPDATE product_units SET 
                            buy_price = ?, 
                            sell_price = ?, 
                            wholesale_price = ?, 
                            special_price = ?
                        WHERE product_id = ? AND unit_id = ?
                    ");
                    $revertUnitPriceStmt->bind_param("ddddii",
                        $zeroPrice, $zeroPrice, $zeroPrice, $zeroPrice,
                        $oldProductId,
                        $oldUnitId
                    );
                    $revertUnitPriceStmt->execute();
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // هاوکاتکردنی (sync) یەکەکانی تری ماوە بەپێی conversion_ratio — پێچەوانەی add.
    // یەکەی سەرەکی (block ١) و یەکەی کڕدراو (block ٢) پێشتر گەڕێندراونەتەوە؛
    // ئەم بەشە تەنها یەکەکانی تر ڕاست دەکاتەوە تاکو drift دروست نەبێت.
    // مۆدی دەرمانخانە (پاکەت/شیت) دەست لێنادرێت — بەپێی داواکاری.
    // ---------------------------------------------------------------------
    $isPharmacyItem = !empty($oldItem['sheets_per_packet']) && (int)$oldItem['sheets_per_packet'] > 0;
    if (!$isPharmacyItem && $oldQuantity > 0) {
        // یەکەی سەرەکی (بۆ دەرکردن لە sync چونکە block ١ کارەکەی کردووە)
        $priUnitStmt = $conn->prepare("
            SELECT unit_id, conversion_ratio
            FROM product_units
            WHERE product_id = ?
            ORDER BY is_primary DESC, id ASC
            LIMIT 1
        ");
        $priUnitStmt->bind_param("i", $oldProductId);
        $priUnitStmt->execute();
        $priUnitRow = $priUnitStmt->get_result()->fetch_assoc();
        $primaryUnitId = (int)($priUnitRow['unit_id'] ?? 0);

        // یەکەی ئەنکەر (کڕدراو) و ڕێژەکەی؛ ئەگەر unit_id تۆمار نەکرابوو، سەرەکی وەک ئەنکەر
        $refUnitId = $oldUnitId;
        $refRatio = null;
        if ($refUnitId <= 0) {
            $refUnitId = $primaryUnitId;
            $refRatio = $priUnitRow['conversion_ratio'] ?? null;
        } else {
            $refRatioStmt = $conn->prepare("SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?");
            $refRatioStmt->bind_param("ii", $oldProductId, $refUnitId);
            $refRatioStmt->execute();
            $refRatioRow = $refRatioStmt->get_result()->fetch_assoc();
            $refRatio = $refRatioRow['conversion_ratio'] ?? null;
        }

        if ($refRatio === null || (float)$refRatio <= 0) {
            error_log("Purchase revert sync skipped: invalid ref conversion_ratio for product_id=$oldProductId, unit_id=$refUnitId");
        } else {
            $otherStmt = $conn->prepare("
                SELECT unit_id, conversion_ratio
                FROM product_units
                WHERE product_id = ? AND unit_id != ? AND unit_id != ?
            ");
            $otherStmt->bind_param("iii", $oldProductId, $refUnitId, $primaryUnitId);
            $otherStmt->execute();
            $otherRes = $otherStmt->get_result();
            while ($ou = $otherRes->fetch_assoc()) {
                $ouId = (int)$ou['unit_id'];
                $ouRatio = $ou['conversion_ratio'];

                // پارێزەری دابەشکردن بەسەر سفر
                if ($ouRatio === null || (float)$ouRatio <= 0) {
                    error_log("Purchase revert sync skipped for unit_id=$ouId (product_id=$oldProductId): invalid conversion_ratio");
                    continue;
                }

                // فۆرمولا: بڕی هاوکات = بڕ × (ratioی یەکەی کڕدراو ÷ ratioی یەکەی تر)
                $subAmount = $oldQuantity * ((float)$refRatio / (float)$ouRatio);
                $updSync = $conn->prepare("
                    UPDATE product_units SET stock_quantity = GREATEST(0, stock_quantity - ?), updated_at = NOW()
                    WHERE product_id = ? AND unit_id = ?
                ");
                $updSync->bind_param("dii", $subAmount, $oldProductId, $ouId);
                $updSync->execute();
            }
        }
    }
}
