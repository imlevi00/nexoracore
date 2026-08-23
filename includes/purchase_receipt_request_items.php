<?php
/**
 * Parsing ی کاڵاکانی فۆرمی وەسڵی کڕین — JSON payload یان fallback بۆ POST arrays.
 */

if (!function_exists('purchaseReceiptItemFieldKeys')) {
    function purchaseReceiptItemFieldKeys(): array
    {
        return [
            'product_id', 'product_name', 'barcode', 'quantity', 'unit_id', 'conversion_rate',
            'buy_price', 'sell_price', 'wholesale_price', 'special_price', 'expiry_date',
            'category_id', 'packet_bonus', 'sheets_per_packet', 'sheet_buy_price', 'sheet_sell_price',
            'item_discount_percent', 'image_key',
        ];
    }
}

if (!function_exists('purchaseReceiptRowHasAnyValue')) {
    function purchaseReceiptRowHasAnyValue(array $row): bool
    {
        return trim((string)($row['product_name'] ?? '')) !== ''
            || trim((string)($row['barcode'] ?? '')) !== ''
            || trim((string)($row['quantity'] ?? '')) !== ''
            || trim((string)($row['buy_price'] ?? '')) !== ''
            || trim((string)($row['sell_price'] ?? '')) !== ''
            || trim((string)($row['wholesale_price'] ?? '')) !== ''
            || trim((string)($row['special_price'] ?? '')) !== ''
            || trim((string)($row['expiry_date'] ?? '')) !== ''
            || ((int)($row['product_id'] ?? 0)) > 0;
    }
}

if (!function_exists('purchaseReceiptNormalizeRow')) {
    function purchaseReceiptNormalizeRow(array $source): array
    {
        $row = [];
        foreach (purchaseReceiptItemFieldKeys() as $field) {
            $row[$field] = isset($source[$field]) ? (string)$source[$field] : '';
        }

        return $row;
    }
}

if (!function_exists('purchaseReceiptExtractRowsFromPost')) {
    /**
     * @return array<int, array<string, string>>
     */
    function purchaseReceiptExtractRowsFromPost(array $post, array &$errors = []): array
    {
        $jsonRaw = trim((string)($post['purchase_items_json'] ?? ''));
        if ($jsonRaw !== '') {
            $decoded = json_decode($jsonRaw, true);
            if (!is_array($decoded)) {
                $errors[] = 'داتای کاڵاکان نادروستە. تکایە دووبارە هەوڵ بدەرەوە.';
                return [];
            }

            $rows = [];
            foreach ($decoded as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $rows[] = purchaseReceiptNormalizeRow($entry);
            }

            return $rows;
        }

        $fieldKeys = purchaseReceiptItemFieldKeys();
        $maxRows = 0;
        foreach ($fieldKeys as $field) {
            $values = $post[$field] ?? [];
            if (!is_array($values)) {
                $values = [];
            }
            $maxRows = max($maxRows, count($values));
        }

        $rows = [];
        for ($i = 0; $i < $maxRows; $i++) {
            $row = [];
            foreach ($fieldKeys as $field) {
                $values = $post[$field] ?? [];
                if (!is_array($values)) {
                    $values = [];
                }
                $row[$field] = (string)($values[$i] ?? '');
            }
            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('purchaseReceiptParseRawRowsFromPost')) {
    /**
     * @return array<int, array<string, string>>
     */
    function purchaseReceiptParseRawRowsFromPost(array $post, array &$errors = []): array
    {
        $rows = purchaseReceiptExtractRowsFromPost($post, $errors);
        $filtered = [];

        foreach ($rows as $row) {
            if (purchaseReceiptRowHasAnyValue($row)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }
}

if (!function_exists('purchaseReceiptApplyItemProductImage')) {
    /**
     * بارکردنی وێنەی کاڵا لە فایلی ئایتەم (product_image[KEY]) و تۆمارکردنی لە products.image_path.
     * تەنها ئەگەر کاڵاکە پێشتر هیچ وێنەیەکی نەبووبێت — وێنەی هەبوو نەدەگۆڕدرێت.
     */
    function purchaseReceiptApplyItemProductImage(mysqli $conn, int $userId, int $productId, string $imageKey): void
    {
        if ($productId <= 0 || $imageKey === '') {
            return;
        }
        if (!function_exists('upload_product_image_to_spaces')) {
            return;
        }
        if (!isset($_FILES['product_image']) || !is_array($_FILES['product_image']['tmp_name'] ?? null)) {
            return;
        }
        if (!array_key_exists($imageKey, $_FILES['product_image']['tmp_name'])) {
            return;
        }
        $error = (int)($_FILES['product_image']['error'][$imageKey] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return;
        }

        // وەرگرتنی وێنەی ئێستای کاڵا (بۆ گۆڕین/سڕینەوەی کۆن دوای بارکردنی نوێ)
        $check = $conn->prepare("SELECT image_path FROM products WHERE id = ? AND user_id = ? LIMIT 1");
        $check->bind_param("ii", $productId, $userId);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing === null) {
            return;
        }
        $currentPath = trim((string)($existing['image_path'] ?? ''));

        $file = [
            'name' => (string)($_FILES['product_image']['name'][$imageKey] ?? ''),
            'type' => (string)($_FILES['product_image']['type'][$imageKey] ?? ''),
            'tmp_name' => (string)($_FILES['product_image']['tmp_name'][$imageKey] ?? ''),
            'error' => $error,
            'size' => (int)($_FILES['product_image']['size'][$imageKey] ?? 0),
        ];

        $result = upload_product_image_to_spaces($file);
        if (!empty($result['success']) && !empty($result['db_path'])) {
            $newPath = (string)$result['db_path'];
            $upd = $conn->prepare("UPDATE products SET image_path = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $upd->bind_param("sii", $newPath, $productId, $userId);
            $upd->execute();
            $upd->close();

            // سڕینەوەی وێنەی کۆن ئەگەر هەبوو و جیاواز بوو
            if ($currentPath !== '' && $currentPath !== $newPath && function_exists('delete_product_image_from_spaces')) {
                delete_product_image_from_spaces($currentPath);
            }
        }
    }
}

if (!function_exists('purchaseReceiptResolveGlobalItemDiscountPercent')) {
    function purchaseReceiptResolveGlobalItemDiscountPercent(array $rows, bool $isPharmacyMode): float
    {
        if (!$isPharmacyMode) {
            return 0.0;
        }

        foreach ($rows as $row) {
            $val = $row['item_discount_percent'] ?? '';
            if ($val === '' || $val === null) {
                continue;
            }

            $percent = (float)$val;
            if ($percent != 0.0 || $val === '0' || $val === '0.0') {
                if ($percent < 0.0) {
                    $percent = 0.0;
                } elseif ($percent > 100.0) {
                    $percent = 100.0;
                }
                return $percent;
            }
        }

        return 0.0;
    }
}

if (!function_exists('purchaseReceiptBuildItemsFromPost')) {
    /**
     * @param string $context 'add' | 'edit'
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   global_item_discount_percent: float,
     *   total_amount: float,
     *   gross_total_amount: float
     * }
     */
    function purchaseReceiptBuildItemsFromPost(array $post, bool $isPharmacyMode, array &$errors, string $context = 'add'): array
    {
        $rows = purchaseReceiptExtractRowsFromPost($post, $errors);
        $globalItemDiscountPercent = purchaseReceiptResolveGlobalItemDiscountPercent($rows, $isPharmacyMode);

        $items = [];
        $totalAmount = 0.0;
        $grossTotalAmount = 0.0;

        foreach ($rows as $row) {
            $productName = trim((string)($row['product_name'] ?? ''));
            $quantityRaw = trim((string)($row['quantity'] ?? ''));
            $buyPriceRaw = $row['buy_price'] ?? '';

            $includeRow = false;
            if ($context === 'add') {
                $includeRow = $productName !== '' && $quantityRaw !== '' && array_key_exists('buy_price', $row) && $buyPriceRaw !== '';
            } else {
                $includeRow = $productName !== '' && $quantityRaw !== '' && trim((string)$buyPriceRaw) !== '';
            }

            if (!$includeRow) {
                continue;
            }

            $quantity = (float)$quantityRaw;
            $buyPrice = (float)$buyPriceRaw;
            $productNameClean = cleanInput($productName);

            $item = [
                'product_id' => (int)($row['product_id'] ?? 0),
                'product_name' => $productNameClean,
                'barcode' => cleanInput($row['barcode'] ?? ''),
                'quantity' => $quantity,
                'buy_price' => $buyPrice,
                'sell_price' => (float)($row['sell_price'] ?? 0),
                'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
                'special_price' => (float)($row['special_price'] ?? 0),
                'expiry_date' => !empty($row['expiry_date']) && $row['expiry_date'] !== ''
                    ? (strtotime($row['expiry_date']) !== false ? $row['expiry_date'] : null)
                    : null,
                'total_cost' => $quantity * $buyPrice,
                'unit_id' => (int)($row['unit_id'] ?? 0),
                'conversion_rate' => (float)($row['conversion_rate'] ?? 1.0),
                'image_key' => trim((string)($row['image_key'] ?? '')),
            ];

            if ($isPharmacyMode) {
                $item['category_id'] = (int)($row['category_id'] ?? 0);
                $item['packet_bonus'] = (float)($row['packet_bonus'] ?? 0);
                $item['sheets_per_packet'] = (int)($row['sheets_per_packet'] ?? 0);
                $sheetBuyRaw = $row['sheet_buy_price'] ?? '';
                $sheetSellRaw = $row['sheet_sell_price'] ?? '';

                if ($context === 'add') {
                    $item['sheet_buy_price'] = $sheetBuyRaw !== '' ? (float)$sheetBuyRaw : null;
                    $item['sheet_sell_price'] = $sheetSellRaw !== '' ? (float)$sheetSellRaw : null;
                    $item['discount_percent'] = $globalItemDiscountPercent;
                } else {
                    $item['sheet_buy_price'] = (float)($sheetBuyRaw !== '' ? $sheetBuyRaw : 0);
                    $item['sheet_sell_price'] = (float)($sheetSellRaw !== '' ? $sheetSellRaw : 0);
                    $item['item_discount_percent'] = $globalItemDiscountPercent;
                }

                $item['discount_amount'] = 0.0;
            } elseif ($context === 'edit') {
                $item['category_id'] = 0;
                $item['packet_bonus'] = 0.0;
                $item['sheets_per_packet'] = 0;
                $item['sheet_buy_price'] = 0.0;
                $item['sheet_sell_price'] = 0.0;
                $item['item_discount_percent'] = 0.0;
                $item['discount_amount'] = 0.0;
            }

            if ($item['quantity'] <= 0) {
                $errors[] = "بڕی کاڵای '{$productNameClean}' نابێت سفر یان منفی بێت";
            }

            if ($item['buy_price'] < 0) {
                $errors[] = "نرخی کڕینی کاڵای '{$productNameClean}' نابێت منفی بێت";
            }

            $items[] = $item;
            $totalAmount += $item['total_cost'];
            $grossTotalAmount += $item['total_cost'];
        }

        if (empty($items)) {
            $errors[] = 'لانی کەم یەک کاڵا دەبێت زیاد بکرێت';
        }

        return [
            'items' => $items,
            'global_item_discount_percent' => $globalItemDiscountPercent,
            'total_amount' => $totalAmount,
            'gross_total_amount' => $grossTotalAmount,
        ];
    }
}
