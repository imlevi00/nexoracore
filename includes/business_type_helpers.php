<?php
/**
 * یارمەتیدەر بۆ business_type ـی بەکارهێنەر
 */

/**
 * @return array{id: int, code: string, name_ku: string}
 */
function getUserBusinessType($conn, int $userId): array
{
    $default = ['id' => 0, 'code' => '', 'name_ku' => ''];

    $stmt = $conn->prepare("
        SELECT s.business_type_id, bt.code AS business_type_code, bt.name_ku AS business_type_name
        FROM settings s
        LEFT JOIN business_types bt ON bt.id = s.business_type_id
        WHERE s.user_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['business_type_id'] === null) {
        return $default;
    }

    return [
        'id' => (int)$row['business_type_id'],
        'code' => trim((string)($row['business_type_code'] ?? '')),
        'name_ku' => trim((string)($row['business_type_name'] ?? '')),
    ];
}

function isWholesaleDistributionMode($conn, int $userId): bool
{
    $businessType = getUserBusinessType($conn, $userId);

    if ($businessType['id'] === 4) {
        return true;
    }

    $code = strtolower($businessType['code']);
    return in_array($code, ['wholesale', 'wholesale_distribution', 'distribution', 'jwmla_w_mandwb'], true);
}

function isCurtainShopMode($conn, int $userId): bool
{
    $businessType = getUserBusinessType($conn, $userId);

    $code = strtolower($businessType['code']);
    return $code === 'curtain_shop';
}

/**
 * @param mixed $value
 */
function formatCurtainFabricSizeNumber($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return (string)$value;
    }

    return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
}

/**
 * @param mixed $raw
 * @return float|null|false  null = بەتاڵ، false = نادروست، float = نرخ
 */
function parseCurtainFabricSizeNumber($raw)
{
    $raw = trim(str_replace(',', '.', (string)$raw));
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw)) {
        return false;
    }
    $value = round((float)$raw, 3);
    if ($value <= 0) {
        return false;
    }

    return $value;
}

/**
 * @param array<string, mixed> $post
 * @return array{width: ?float, height: ?float, unit: string, errors: string[]}
 */
function parseCurtainFabricSizeInput(array $post): array
{
    $unit = strtolower(trim((string)($post['fabric_measure_unit'] ?? 'cm')));
    if (!in_array($unit, ['cm', 'm'], true)) {
        $unit = 'cm';
    }

    $errors = [];
    $width = parseCurtainFabricSizeNumber($post['fabric_width'] ?? '');
    $height = parseCurtainFabricSizeNumber($post['fabric_height'] ?? '');

    if ($width === false) {
        $errors[] = 'پانی دەبێت ژمارەیەکی گەورەتر لە سفر بێت';
        $width = null;
    }
    if ($height === false) {
        $errors[] = 'بەرزی دەبێت ژمارەیەکی گەورەتر لە سفر بێت';
        $height = null;
    }

    return [
        'width' => $width,
        'height' => $height,
        'unit' => $unit,
        'errors' => $errors,
    ];
}

/**
 * @param mixed $width
 * @param mixed $height
 */
function formatCurtainFabricSize($width, $height, $unit): string
{
    $unitLabel = (strtolower((string)$unit) === 'm') ? 'م' : 'سم';
    $parts = [];

    $widthText = formatCurtainFabricSizeNumber($width);
    $heightText = formatCurtainFabricSizeNumber($height);
    if ($widthText !== '') {
        $parts[] = 'پانی ' . $widthText . ' ' . $unitLabel;
    }
    if ($heightText !== '') {
        $parts[] = 'بەرزی ' . $heightText . ' ' . $unitLabel;
    }

    return implode(' · ', $parts);
}

/**
 * ئایا product_units بۆ UI ـی wholesale گونجاوە
 *
 * @param array<int, array<string, mixed>> $productUnits
 */
function canUseWholesaleUnitsUiForProduct(array $productUnits): bool
{
    if (empty($productUnits)) {
        return true;
    }

    if (count($productUnits) > 2) {
        return false;
    }

    if (count($productUnits) === 1) {
        return true;
    }

    $primaryCount = 0;
    $secondaryCount = 0;
    foreach ($productUnits as $pu) {
        if (!empty($pu['is_primary'])) {
            $primaryCount++;
        } else {
            $secondaryCount++;
        }
    }

    return $primaryCount === 1 && $secondaryCount === 1;
}
