<?php
/**
 * خەزنکردنی خێرای خانەکانی لیستی کاڵاکان
 */
require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/unit_functions.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$csrfToken = $input['csrf_token'] ?? '';

if (!Security::validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە']);
    exit;
}

$productId = (int)($input['product_id'] ?? 0);
$field = (string)($input['field'] ?? '');
$productUnitId = (int)($input['product_unit_id'] ?? 0);
$value = $input['value'] ?? null;

const ALLOWED_QUICK_UPDATE_FIELDS = ['name', 'barcode', 'buy_price', 'sell_price', 'stock', 'expiry_date'];

const PRODUCT_UNIT_UPDATE_SQL = [
    'buy_price' => 'UPDATE product_units SET buy_price = ?, updated_at = NOW() WHERE id = ?',
    'sell_price' => 'UPDATE product_units SET sell_price = ?, updated_at = NOW() WHERE id = ?',
    'stock' => 'UPDATE product_units SET stock_quantity = ?, updated_at = NOW() WHERE id = ?',
];

/**
 * @throws Exception
 */
function executePreparedStatement(mysqli_stmt $stmt, string $errorMessage): void
{
    try {
        if (!$stmt->execute()) {
            throw new Exception($errorMessage);
        }
    } finally {
        $stmt->close();
    }
}

/**
 * @throws Exception
 */
function fetchPreparedRow(mysqli $conn, string $sql, string $types, array $params): ?array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('هەڵە لە ئامادەکردنی داواکاری');
    }

    try {
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception('هەڵە لە جێبەجێکردنی داواکاری');
        }
        $result = $stmt->get_result();
        return $result ? $result->fetch_assoc() : null;
    } finally {
        $stmt->close();
    }
}

/**
 * @throws Exception
 */
function assertBarcodeIsUnique(mysqli $conn, string $barcode, int $userId, int $productId): void
{
    $duplicateInProducts = fetchPreparedRow(
        $conn,
        'SELECT id FROM products WHERE barcode = ? AND user_id = ? AND id != ? LIMIT 1',
        'sii',
        [$barcode, $userId, $productId]
    );
    if ($duplicateInProducts) {
        throw new Exception('ئەم بارکۆدە پێشتر بەکارهاتووە');
    }

    $duplicateInProductBarcodes = fetchPreparedRow(
        $conn,
        'SELECT pb.id
         FROM product_barcodes pb
         INNER JOIN products p ON pb.product_id = p.id
         WHERE pb.barcode = ? AND p.user_id = ? AND pb.product_id != ?
         LIMIT 1',
        'sii',
        [$barcode, $userId, $productId]
    );
    if ($duplicateInProductBarcodes) {
        throw new Exception('ئەم بارکۆدە پێشتر بەکارهاتووە');
    }
}

/**
 * نرخ و بەردەست تەنها لە product_units خەزن دەکرێن.
 *
 * @throws Exception
 */
function resolveOwnedProductUnitId(mysqli $conn, int $productId, int $userId, int $requestedProductUnitId = 0): int
{
    if ($requestedProductUnitId > 0) {
        $ownedUnit = fetchPreparedRow(
            $conn,
            'SELECT pu.id
             FROM product_units pu
             JOIN products p ON p.id = pu.product_id
             WHERE pu.id = ? AND pu.product_id = ? AND p.user_id = ?
             LIMIT 1',
            'iii',
            [$requestedProductUnitId, $productId, $userId]
        );
        if (!$ownedUnit) {
            throw new Exception('یەکەی کاڵا نەدۆزرایەوە');
        }
        return $requestedProductUnitId;
    }

    $existingUnit = fetchPreparedRow(
        $conn,
        'SELECT id FROM product_units WHERE product_id = ? ORDER BY id ASC LIMIT 1',
        'i',
        [$productId]
    );
    if ($existingUnit) {
        return (int)$existingUnit['id'];
    }

    $product = fetchPreparedRow(
        $conn,
        'SELECT currency FROM products WHERE id = ? AND user_id = ? LIMIT 1',
        'ii',
        [$productId, $userId]
    );
    if (!$product) {
        throw new Exception('کاڵا نەدۆزرایەوە');
    }

    $defaultUnitId = (int)ensureDefaultUnit($userId);
    if ($defaultUnitId <= 0) {
        throw new Exception('یەکەی بنەڕەت نەدۆزرایەوە');
    }

    $currency = $product['currency'] ?? 'IQD';
    if (!in_array($currency, ['IQD', 'USD'], true)) {
        $currency = 'IQD';
    }

    $stmt = $conn->prepare('
        INSERT INTO product_units (
            product_id, unit_id, buy_price, sell_price, wholesale_price, special_price,
            currency, stock_quantity, min_stock, conversion_ratio, conversion_rate, is_primary
        ) VALUES (?, ?, 0, 0, 0, 0, ?, 0, 0, 1, 1, 1)
    ');
    if (!$stmt) {
        throw new Exception('هەڵە لە ئامادەکردنی داواکاری');
    }
    $stmt->bind_param('iis', $productId, $defaultUnitId, $currency);
    executePreparedStatement($stmt, 'هەڵە لە دروستکردنی یەکەی کاڵا');

    return (int)$conn->insert_id;
}

if (!$productId || !in_array($field, ALLOWED_QUICK_UPDATE_FIELDS, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'داواکاری نادروست']);
    exit;
}

$product = fetchPreparedRow(
    $conn,
    'SELECT * FROM products WHERE id = ? AND user_id = ? LIMIT 1',
    'ii',
    [$productId, $userId]
);

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'کاڵا نەدۆزرایەوە']);
    exit;
}

try {
    switch ($field) {
        case 'name':
            $name = cleanInput((string)$value);
            if ($name === '') {
                throw new Exception('ناوی کاڵا پێویستە');
            }

            $stmt = $conn->prepare('UPDATE products SET name = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
            if (!$stmt) {
                throw new Exception('هەڵە لە ئامادەکردنی داواکاری');
            }
            $stmt->bind_param('sii', $name, $productId, $userId);
            executePreparedStatement($stmt, 'هەڵە لە خەزنکردنی ناو');

            echo json_encode([
                'success' => true,
                'message' => 'ناوی کاڵا نوێکرایەوە',
                'data' => ['value' => $name]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'barcode':
            $barcode = cleanInput((string)$value);
            if ($barcode !== '' && $barcode !== ($product['barcode'] ?? '')) {
                assertBarcodeIsUnique($conn, $barcode, $userId, $productId);
            }

            $stmt = $conn->prepare('UPDATE products SET barcode = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
            if (!$stmt) {
                throw new Exception('هەڵە لە ئامادەکردنی داواکاری');
            }
            $stmt->bind_param('sii', $barcode, $productId, $userId);
            executePreparedStatement($stmt, 'هەڵە لە خەزنکردنی بارکۆد');

            echo json_encode([
                'success' => true,
                'message' => 'بارکۆد نوێکرایەوە',
                'data' => ['value' => $barcode]
            ]);
            break;

        case 'expiry_date':
            $dateValue = trim((string)$value);

            if ($dateValue === '') {
                $stmt = $conn->prepare('UPDATE products SET expiry_date = NULL, updated_at = NOW() WHERE id = ? AND user_id = ?');
                if (!$stmt) {
                    throw new Exception('هەڵە لە ئامادەکردنی داواکاری');
                }
                $stmt->bind_param('ii', $productId, $userId);
                executePreparedStatement($stmt, 'هەڵە لە خەزنکردنی بەروار');

                echo json_encode([
                    'success' => true,
                    'message' => 'بەروار سڕایەوە',
                    'data' => ['value' => '']
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $parsedDate = DateTime::createFromFormat('Y-m-d', $dateValue);
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateValue) {
                throw new Exception('بەرواری نادروست');
            }

            $stmt = $conn->prepare('UPDATE products SET expiry_date = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
            if (!$stmt) {
                throw new Exception('هەڵە لە ئامادەکردنی داواکاری');
            }
            $stmt->bind_param('sii', $dateValue, $productId, $userId);
            executePreparedStatement($stmt, 'هەڵە لە خەزنکردنی بەروار');

            echo json_encode([
                'success' => true,
                'message' => 'بەروار نوێکرایەوە',
                'data' => ['value' => $dateValue]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'buy_price':
        case 'sell_price':
        case 'stock':
            $numericValue = is_numeric($value) ? (float)$value : -1;
            if ($numericValue < 0) {
                throw new Exception('بەهای نادروست');
            }

            if (!isset(PRODUCT_UNIT_UPDATE_SQL[$field])) {
                throw new Exception('داواکاری نادروست');
            }

            $productUnitId = resolveOwnedProductUnitId($conn, $productId, $userId, $productUnitId);

            $updateStmt = $conn->prepare(PRODUCT_UNIT_UPDATE_SQL[$field]);
            if (!$updateStmt) {
                throw new Exception('هەڵە لە ئامادەکردنی داواکاری');
            }
            $updateStmt->bind_param('di', $numericValue, $productUnitId);
            executePreparedStatement($updateStmt, 'هەڵە لە خەزنکردن');

            echo json_encode([
                'success' => true,
                'message' => 'بە سەرکەوتوویی خەزن کرا',
                'data' => [
                    'value' => $numericValue,
                    'product_unit_id' => $productUnitId
                ]
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
