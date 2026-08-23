<?php
/**
 * Duplicate detection helpers for web_orders (online shop).
 */

if (!function_exists('web_order_normalize_phone')) {
    /**
     * Normalize phone to comparable digits (Iraq-friendly).
     */
    function web_order_normalize_phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || $digits === '') {
            return '';
        }

        if (str_starts_with($digits, '964') && strlen($digits) > 10) {
            $digits = substr($digits, 3);
        }

        return ltrim($digits, '0');
    }
}

if (!function_exists('web_order_items_signature')) {
    /**
     * Build a stable fingerprint for order line items (product + qty only).
     */
    function web_order_items_signature(array $items): string
    {
        $lines = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qty = round((float) ($item['quantity'] ?? 0), 4);
            if ($qty <= 0) {
                continue;
            }

            $productId = isset($item['id']) ? (int) $item['id'] : 0;
            $unitId = isset($item['unitId']) ? (int) $item['unitId'] : 0;

            if ($productId > 0) {
                $key = 'p' . $productId . 'u' . $unitId;
            } else {
                $name = mb_strtolower(trim((string) ($item['name'] ?? '')), 'UTF-8');
                $unit = trim((string) ($item['unit'] ?? ''));
                $key = 'n' . $name . '|' . $unit;
            }

            $lines[] = $key . ':' . $qty;
        }

        sort($lines, SORT_STRING);

        return implode(';', $lines);
    }
}

if (!function_exists('web_order_find_duplicate_of')) {
    /**
     * Find the most recent completed order matching phone + items signature.
     *
     * @param array $order Pending order row (id, customer_phone, items, created_at)
     * @param array<string, list<array>> $completedByPhone Indexed by normalized phone
     * @return array{id:int,order_number:string}|null
     */
    function web_order_find_duplicate_of(array $order, array $completedByPhone): ?array
    {
        $orderId = (int) ($order['id'] ?? 0);
        $phoneKey = web_order_normalize_phone((string) ($order['customer_phone'] ?? ''));
        if ($phoneKey === '' || !isset($completedByPhone[$phoneKey])) {
            return null;
        }

        $items = json_decode((string) ($order['items'] ?? '[]'), true);
        if (!is_array($items)) {
            $items = [];
        }

        $signature = web_order_items_signature($items);
        if ($signature === '') {
            return null;
        }

        $orderCreatedAt = strtotime((string) ($order['created_at'] ?? '')) ?: 0;

        foreach ($completedByPhone[$phoneKey] as $completed) {
            $completedId = (int) ($completed['id'] ?? 0);
            if ($completedId === $orderId) {
                continue;
            }

            if (($completed['signature'] ?? '') !== $signature) {
                continue;
            }

            $completedCreatedAt = strtotime((string) ($completed['created_at'] ?? '')) ?: 0;
            if ($completedCreatedAt >= $orderCreatedAt) {
                continue;
            }

            return [
                'id' => $completedId,
                'order_number' => (string) ($completed['order_number'] ?? ''),
            ];
        }

        return null;
    }
}

if (!function_exists('web_order_build_completed_by_phone_index')) {
    /**
     * Index completed orders by normalized phone with precomputed signatures.
     *
     * @param list<array> $completedRows
     * @return array<string, list<array>>
     */
    function web_order_build_completed_by_phone_index(array $completedRows): array
    {
        $index = [];

        foreach ($completedRows as $row) {
            $phoneKey = web_order_normalize_phone((string) ($row['customer_phone'] ?? ''));
            if ($phoneKey === '') {
                continue;
            }

            $items = json_decode((string) ($row['items'] ?? '[]'), true);
            if (!is_array($items)) {
                $items = [];
            }

            $entry = [
                'id' => (int) ($row['id'] ?? 0),
                'order_number' => (string) ($row['order_number'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'signature' => web_order_items_signature($items),
            ];

            if (!isset($index[$phoneKey])) {
                $index[$phoneKey] = [];
            }
            $index[$phoneKey][] = $entry;
        }

        return $index;
    }
}
