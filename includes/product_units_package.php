<?php
/**
 * یارمەتیدەری پاکێج بۆ یەکەکانی کاڵا — تەنها «دانە» کاتێک feature ناچالاکە
 */

require_once __DIR__ . '/permissions.php';

/**
 * یەکەی بنەڕەت (دانە) بۆ بەکارهێنەر
 *
 * @return array<string, mixed>|null
 */
function getDefaultPieceUnitForUser($conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, name, symbol, is_default
        FROM units
        WHERE user_id = ? AND is_active = 1 AND is_default = 1
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
    }

    $stmt = $conn->prepare("
        SELECT id, name, symbol, is_default
        FROM units
        WHERE user_id = ? AND is_active = 1 AND name = 'دانە'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
    }

    $stmt = $conn->prepare("
        SELECT id, name, symbol, is_default
        FROM units
        WHERE user_id = ? AND is_active = 1
        ORDER BY id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * سنووردارکردنی POST ـی units بۆ تەنها یەکەی دانە
 *
 * @param array<int, array<string, mixed>> $productUnits
 * @return array<int, array<string, mixed>>
 */
function normalizeProductUnitsForPackageAccess(array $productUnits, $conn, int $userId): array
{
    if (hasProductsUnitsManageAccess()) {
        return $productUnits;
    }

    $defaultUnit = getDefaultPieceUnitForUser($conn, $userId);
    if (!$defaultUnit) {
        return [];
    }

    $defaultUnitId = (int)$defaultUnit['id'];
    $selected = null;

    foreach ($productUnits as $unit) {
        if ((int)($unit['unit_id'] ?? 0) === $defaultUnitId) {
            $selected = $unit;
            break;
        }
    }

    if ($selected === null && !empty($productUnits)) {
        $selected = $productUnits[0];
        foreach ($productUnits as $unit) {
            if (!empty($unit['is_primary'])) {
                $selected = $unit;
                break;
            }
        }
    }

    if ($selected === null) {
        $selected = [
            'unit_id' => $defaultUnitId,
            'buy_price' => 0,
            'sell_price' => 0,
            'wholesale_price' => 0,
            'special_price' => 0,
            'stock_quantity' => 0,
            'min_stock' => 0,
            'currency' => 'IQD',
        ];
    }

    $selected['unit_id'] = $defaultUnitId;
    $selected['is_primary'] = true;
    $selected['conversion_factor'] = 1.0;
    $selected['conversion_ratio'] = 1.0;
    $selected['conversion_rate'] = 1.0;

    return [$selected];
}

/**
 * فیلتەرکردنی یەکەکانی پیشاندان لە edit.php
 *
 * @param array<int, array<string, mixed>> $productUnits
 * @return array<int, array<string, mixed>>
 */
function filterProductUnitsForPackageDisplay(array $productUnits, $conn, int $userId): array
{
    if (hasProductsUnitsManageAccess() || empty($productUnits)) {
        return $productUnits;
    }

    $defaultUnit = getDefaultPieceUnitForUser($conn, $userId);
    if (!$defaultUnit) {
        return $productUnits;
    }

    $defaultUnitId = (int)$defaultUnit['id'];

    foreach ($productUnits as $unit) {
        if ((int)($unit['unit_id'] ?? 0) === $defaultUnitId) {
            $unit['is_primary'] = 1;
            $unit['conversion_factor'] = 1;
            return [$unit];
        }
    }

    $fallback = $productUnits[0];
    foreach ($productUnits as $unit) {
        if (!empty($unit['is_primary'])) {
            $fallback = $unit;
            break;
        }
    }

    $fallback['unit_id'] = $defaultUnitId;
    $fallback['unit_name'] = $defaultUnit['name'];
    $fallback['unit_symbol'] = $defaultUnit['symbol'] ?? '';
    $fallback['is_primary'] = 1;
    $fallback['conversion_factor'] = 1;

    return [$fallback];
}
