<?php
/**
 * Smart scale barcode parsing helpers.
 */

function getDefaultScaleBarcodeSettings(): array
{
    return [
        'prefix' => '21',
        'total_digits' => 13,
        'product_code_digits' => 5,
        'price_digits' => 5,
        'validate_check_digit' => 0,
        'is_enabled' => 1,
    ];
}

function normalizeScaleBarcodeSettings(array $settings): array
{
    $defaults = getDefaultScaleBarcodeSettings();

    return [
        'prefix' => preg_replace('/\D/', '', (string)($settings['prefix'] ?? $defaults['prefix'])),
        'total_digits' => max(1, (int)($settings['total_digits'] ?? $defaults['total_digits'])),
        'product_code_digits' => max(1, (int)($settings['product_code_digits'] ?? $defaults['product_code_digits'])),
        'price_digits' => max(1, (int)($settings['price_digits'] ?? $defaults['price_digits'])),
        'validate_check_digit' => !empty($settings['validate_check_digit']) ? 1 : 0,
        'is_enabled' => !isset($settings['is_enabled']) || !empty($settings['is_enabled']) ? 1 : 0,
    ];
}

function getScaleCheckDigitLength(array $settings): int
{
    $normalized = normalizeScaleBarcodeSettings($settings);
    $prefixLen = strlen($normalized['prefix']);
    $used = $prefixLen + $normalized['product_code_digits'] + $normalized['price_digits'];

    return max(0, $normalized['total_digits'] - $used);
}

function validateScaleBarcodeSettings(array $settings): array
{
    $errors = [];
    $normalized = normalizeScaleBarcodeSettings($settings);

    if ($normalized['prefix'] === '') {
        $errors[] = 'کۆدی دەستپێکی بارکۆدی قەپان پێویستە.';
    }

    if (!ctype_digit($normalized['prefix'])) {
        $errors[] = 'کۆدی دەستپێکی قەپان تەنها دەبێت ژمارە بێت.';
    }

    $checkDigits = getScaleCheckDigitLength($normalized);
    if ($checkDigits < 0) {
        $errors[] = 'کۆی درێژی خانەکان لەگەڵ کۆدی قەپان و کۆدی کاڵا و نرخ ناگونجێت.';
    }

    if ($normalized['total_digits'] < 4) {
        $errors[] = 'ژمارەی گشتی بارکۆد زۆر کەمە.';
    }

    return $errors;
}

function padScaleProductCode(string $productCode, int $digits): string
{
    $digitsOnly = preg_replace('/\D/', '', $productCode);
    if ($digitsOnly === '') {
        return '';
    }

    return str_pad($digitsOnly, max(1, $digits), '0', STR_PAD_LEFT);
}

function isLikelyScaleBarcode(string $barcode, array $settings): bool
{
    $normalized = normalizeScaleBarcodeSettings($settings);
    if (empty($normalized['is_enabled'])) {
        return false;
    }

    $barcode = trim($barcode);
    if ($barcode === '' || !ctype_digit($barcode)) {
        return false;
    }

    if (strlen($barcode) !== (int)$normalized['total_digits']) {
        return false;
    }

    return strncmp($barcode, $normalized['prefix'], strlen($normalized['prefix'])) === 0;
}

function validateEan13CheckDigit(string $barcode): bool
{
    if (!ctype_digit($barcode) || strlen($barcode) < 2) {
        return false;
    }

    $digits = str_split($barcode);
    $checkDigit = (int)array_pop($digits);
    $sum = 0;
    $reverse = array_reverse($digits);

    foreach ($reverse as $index => $digit) {
        $weight = ($index % 2 === 0) ? 3 : 1;
        $sum += (int)$digit * $weight;
    }

    $calculated = (10 - ($sum % 10)) % 10;

    return $calculated === $checkDigit;
}

function parseScaleBarcode(string $barcode, array $settings): ?array
{
    $normalized = normalizeScaleBarcodeSettings($settings);
    $barcode = trim($barcode);

    if (!isLikelyScaleBarcode($barcode, $normalized)) {
        return null;
    }

    $offset = strlen($normalized['prefix']);
    $productCode = substr($barcode, $offset, $normalized['product_code_digits']);
    $offset += $normalized['product_code_digits'];
    $priceRaw = substr($barcode, $offset, $normalized['price_digits']);
    $offset += $normalized['price_digits'];
    $checkDigits = substr($barcode, $offset);

    if ($productCode === false || $priceRaw === false) {
        return null;
    }

    if (!empty($normalized['validate_check_digit'])) {
        $expectedCheckLen = getScaleCheckDigitLength($normalized);
        if ($expectedCheckLen > 0 && strlen($checkDigits) !== $expectedCheckLen) {
            return null;
        }
        if (!validateEan13CheckDigit($barcode)) {
            return null;
        }
    }

    $totalPrice = (float)(int)$priceRaw;

    return [
        'product_code' => padScaleProductCode($productCode, $normalized['product_code_digits']),
        'total_price' => $totalPrice,
        'price_raw' => $priceRaw,
        'check_digits' => $checkDigits,
    ];
}

function calculateScaleWeightKg(float $totalPrice, float $sellPricePerKg): float
{
    if ($sellPricePerKg <= 0) {
        return 0.0;
    }

    if ($totalPrice <= 0) {
        return 0.0;
    }

    return round($totalPrice / $sellPricePerKg, 3);
}

function buildScaleBarcodePreview(array $settings, string $productCode = '00002', int $totalPrice = 112): string
{
    $normalized = normalizeScaleBarcodeSettings($settings);
    $prefix = $normalized['prefix'];
    $paddedCode = padScaleProductCode($productCode, $normalized['product_code_digits']);
    $pricePart = str_pad((string)max(0, $totalPrice), $normalized['price_digits'], '0', STR_PAD_LEFT);
    $partial = $prefix . $paddedCode . $pricePart;
    $checkLen = getScaleCheckDigitLength($normalized);

    if ($checkLen <= 0) {
        return substr($partial, 0, $normalized['total_digits']);
    }

    $withoutCheck = substr($partial, 0, $normalized['total_digits'] - $checkLen);
    if ($checkLen === 1) {
        $digits = str_split($withoutCheck);
        $sum = 0;
        $reverse = array_reverse($digits);
        foreach ($reverse as $index => $digit) {
            $weight = ($index % 2 === 0) ? 3 : 1;
            $sum += (int)$digit * $weight;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $withoutCheck . $check;
    }

    return str_pad($withoutCheck, $normalized['total_digits'], '0', STR_PAD_RIGHT);
}
