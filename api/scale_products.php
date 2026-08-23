<?php
/**
 * API قەپانی زیرەک - api/scale_products.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/security.php';
require_once '../includes/permissions.php';
require_once '../includes/scale_barcode_parser.php';
require_once '../includes/scale_product_sync.php';

function scaleApiResponse($success, $data = null, $message = '', $code = 200)
{
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('c'),
    ]);
    exit();
}

if (!isUser()) {
    scaleApiResponse(false, null, 'غیر مجاز - داخڵبوون پێویستە', 401);
}

if (!hasSmartScaleAccess()) {
    scaleApiResponse(false, null, 'قەپانی زیرەک لە پاکێجەکەتدا چالاک نییە', 403);
}

$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list'));

$authContext = [
    'route' => '/api/scale_products.php?action=' . $action,
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
];

$readActions = ['list', 'settings_get', 'barcode'];
if (in_array($action, $readActions, true)) {
    if (!authorizeProductsReadInSalesContext($currentUser, $authContext)) {
        enforceAuthorizationOrDeny($currentUser, 'products.view', $authContext, 'json');
    }
} else {
    enforceAuthorizationOrDeny($currentUser, 'products', $authContext, 'json');
}

SessionManager::releaseSessionLockForParallelReads();

if (!scaleTablesReady($conn) && in_array($action, ['list', 'settings_get', 'settings_save', 'barcode', 'add', 'update', 'delete'], true)) {
    scaleApiResponse(false, null, 'خشتەکانی قەپانی زیرەک لە داتابەیسدا نییە. migration جێبەجێ بکە.', 503);
}

switch ($action) {
    case 'settings_get':
        scaleApiResponse(true, ['settings' => getScaleBarcodeSettingsForUser($conn, $userId)]);
        break;

    case 'settings_save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            scaleApiResponse(false, null, 'تەنها POST ڕێگەپێدراوە', 405);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!Security::validateCSRFToken($input['csrf_token'] ?? '')) {
            scaleApiResponse(false, null, 'نادروستی ئامنیەتی', 403);
        }
        $settingsInput = [
            'prefix' => $input['prefix'] ?? '',
            'total_digits' => (int)($input['total_digits'] ?? 13),
            'product_code_digits' => (int)($input['product_code_digits'] ?? 5),
            'price_digits' => (int)($input['price_digits'] ?? 5),
            'validate_check_digit' => !empty($input['validate_check_digit']) ? 1 : 0,
            'is_enabled' => !empty($input['is_enabled']) ? 1 : 0,
        ];
        $errors = validateScaleBarcodeSettings($settingsInput);
        if (!empty($errors)) {
            scaleApiResponse(false, null, implode(' ', $errors), 400);
        }
        if (!saveScaleBarcodeSettingsForUser($conn, $userId, $settingsInput)) {
            scaleApiResponse(false, null, 'هەڵە لە پاشەکەوتکردن', 500);
        }
        scaleApiResponse(true, ['settings' => getScaleBarcodeSettingsForUser($conn, $userId)], 'ڕێکخستنەکان پاشەکەوت کران');
        break;

    case 'list':
        $items = [];
        $stmt = $conn->prepare('
            SELECT id, product_code, name, buy_price, sell_price, wholesale_price, special_price,
                   stock_quantity, product_id, created_at, updated_at
            FROM scale_products
            WHERE user_id = ?
            ORDER BY product_code ASC, id ASC
        ');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        scaleApiResponse(true, ['products' => $items, 'total' => count($items)]);
        break;

    case 'barcode':
        $barcode = trim((string)($_GET['code'] ?? ''));
        if ($barcode === '') {
            scaleApiResponse(false, null, 'بارکۆد پێویستە', 400);
        }

        $settings = getScaleBarcodeSettingsForUser($conn, $userId);
        if (empty($settings['is_enabled'])) {
            scaleApiResponse(false, null, 'قەپانی زیرەک چالاک نییە');
        }

        $parsed = parseScaleBarcode($barcode, $settings);
        if ($parsed === null) {
            scaleApiResponse(false, null, 'بارکۆدی قەپان نادروستە');
        }

        $scaleProduct = loadScaleProductByCode($conn, $userId, $parsed['product_code']);
        if (!$scaleProduct) {
            scaleApiResponse(false, null, 'کاڵای قەپان بە کۆدی ' . $parsed['product_code'] . ' نەدۆزرایەوە');
        }

        $sellPrice = (float)$scaleProduct['sell_price'];
        $weightKg = calculateScaleWeightKg((float)$parsed['total_price'], $sellPrice);
        if ($weightKg <= 0) {
            scaleApiResponse(false, null, 'ناتوانرێت بڕی کیلۆ دەرهێنرێت. نرخی فرۆشتن پشکنین بکە.');
        }

        $productId = (int)($scaleProduct['product_id'] ?? 0);
        if ($productId <= 0) {
            scaleApiResponse(false, null, 'کاڵای پەیوەست نەدۆزرایەوە');
        }

        $product = loadProductForScaleApi($conn, $userId, $productId);
        if (!$product) {
            scaleApiResponse(false, null, 'کاڵا نەدۆزرایەوە');
        }

        scaleApiResponse(true, [
            'product' => $product,
            'scale' => [
                'product_code' => $parsed['product_code'],
                'total_price' => (float)$parsed['total_price'],
                'weight_kg' => $weightKg,
                'unit_name' => 'کیلۆ',
                'unit_symbol' => 'کگ',
            ],
        ]);
        break;

    case 'add':
    case 'update':
    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            scaleApiResponse(false, null, 'تەنها POST ڕێگەپێدراوە', 405);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!Security::validateCSRFToken($input['csrf_token'] ?? '')) {
            scaleApiResponse(false, null, 'نادروستی ئامنیەتی', 403);
        }

        $settings = getScaleBarcodeSettingsForUser($conn, $userId);

        if ($action === 'delete') {
            $scaleId = (int)($input['scale_product_id'] ?? $input['id'] ?? 0);
            $row = loadScaleProductRow($conn, $userId, $scaleId);
            if (!$row) {
                scaleApiResponse(false, null, 'کاڵا نەدۆزرایەوە', 404);
            }
            try {
                $conn->begin_transaction();
                $productId = (int)($row['product_id'] ?? 0);
                $delStmt = $conn->prepare('DELETE FROM scale_products WHERE id = ? AND user_id = ?');
                $delStmt->bind_param('ii', $scaleId, $userId);
                $delStmt->execute();
                $delStmt->close();
                deleteLinkedProductForScale($conn, $userId, $productId);
                $conn->commit();
                scaleApiResponse(true, null, 'کاڵا سڕایەوە');
            } catch (Throwable $e) {
                $conn->rollback();
                scaleApiResponse(false, null, $e->getMessage(), 400);
            }
        }

        $name = cleanInput($input['name'] ?? '');
        $rawCode = cleanInput($input['product_code'] ?? '');
        $buyPrice = (float)($input['buy_price'] ?? 0);
        $sellPrice = (float)($input['sell_price'] ?? 0);
        $wholesalePrice = (float)($input['wholesale_price'] ?? 0);
        $specialPrice = (float)($input['special_price'] ?? 0);
        $stockQty = (float)($input['stock_quantity'] ?? 0);
        $productCode = padScaleProductCode($rawCode, (int)$settings['product_code_digits']);

        $validationErrors = [];
        if ($name === '') {
            $validationErrors[] = 'ناوی کاڵا پێویستە.';
        }
        if ($productCode === '') {
            $validationErrors[] = 'کۆدی کاڵا پێویستە.';
        }
        if ($sellPrice <= 0) {
            $validationErrors[] = 'نرخی فرۆشتن دەبێت لە سفر زیاتر بێت.';
        }
        if ($buyPrice < 0 || $wholesalePrice < 0 || $specialPrice < 0 || $stockQty < 0) {
            $validationErrors[] = 'نرخ و بڕ نابێت لە ژێر سفر بن.';
        }
        if (!empty($validationErrors)) {
            scaleApiResponse(false, null, implode(' ', $validationErrors), 400);
        }

        $payload = [
            'product_code' => $productCode,
            'name' => $name,
            'buy_price' => $buyPrice,
            'sell_price' => $sellPrice,
            'wholesale_price' => $wholesalePrice,
            'special_price' => $specialPrice,
            'stock_quantity' => $stockQty,
        ];

        try {
            $conn->begin_transaction();

            if ($action === 'add') {
                $dupStmt = $conn->prepare('SELECT id FROM scale_products WHERE user_id = ? AND product_code = ? LIMIT 1');
                $dupStmt->bind_param('is', $userId, $productCode);
                $dupStmt->execute();
                if ($dupStmt->get_result()->num_rows > 0) {
                    $dupStmt->close();
                    throw new RuntimeException('ئەم کۆدی کاڵایە پێشتر هەیە.');
                }
                $dupStmt->close();

                $linkedBarcode = buildScaleProductBarcode($settings, $productCode);
                $barcodeStmt = $conn->prepare('SELECT id FROM products WHERE user_id = ? AND barcode = ? LIMIT 1');
                $barcodeStmt->bind_param('is', $userId, $linkedBarcode);
                $barcodeStmt->execute();
                if ($barcodeStmt->get_result()->num_rows > 0) {
                    $barcodeStmt->close();
                    throw new RuntimeException('بارکۆدی پەیوەست پێشتر بەکارهاتووە.');
                }
                $barcodeStmt->close();

                $productId = createLinkedProductForScale($conn, $userId, $payload, $settings);

                $insertStmt = $conn->prepare('
                    INSERT INTO scale_products (
                        user_id, product_code, name, buy_price, sell_price, wholesale_price, special_price,
                        stock_quantity, product_id, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ');
                $insertStmt->bind_param(
                    'issdddddi',
                    $userId,
                    $productCode,
                    $name,
                    $buyPrice,
                    $sellPrice,
                    $wholesalePrice,
                    $specialPrice,
                    $stockQty,
                    $productId
                );
                $insertStmt->execute();
                $scaleId = (int)$conn->insert_id;
                $insertStmt->close();
            } else {
                $scaleId = (int)($input['scale_product_id'] ?? $input['id'] ?? 0);
                $existing = loadScaleProductRow($conn, $userId, $scaleId);
                if (!$existing) {
                    throw new RuntimeException('کاڵا نەدۆزرایەوە.');
                }

                if ($productCode !== $existing['product_code']) {
                    $dupStmt = $conn->prepare('SELECT id FROM scale_products WHERE user_id = ? AND product_code = ? AND id != ? LIMIT 1');
                    $dupStmt->bind_param('isi', $userId, $productCode, $scaleId);
                    $dupStmt->execute();
                    if ($dupStmt->get_result()->num_rows > 0) {
                        $dupStmt->close();
                        throw new RuntimeException('ئەم کۆدی کاڵایە پێشتر هەیە.');
                    }
                    $dupStmt->close();
                }

                $productId = (int)($existing['product_id'] ?? 0);
                if ($productId <= 0) {
                    $productId = createLinkedProductForScale($conn, $userId, $payload, $settings);
                } else {
                    syncLinkedProductForScale($conn, $userId, $productId, $payload, $settings);
                }

                $updateStmt = $conn->prepare('
                    UPDATE scale_products
                    SET product_code = ?, name = ?, buy_price = ?, sell_price = ?, wholesale_price = ?,
                        special_price = ?, stock_quantity = ?, product_id = ?, updated_at = NOW()
                    WHERE id = ? AND user_id = ?
                ');
                $updateStmt->bind_param(
                    'ssddddddii',
                    $productCode,
                    $name,
                    $buyPrice,
                    $sellPrice,
                    $wholesalePrice,
                    $specialPrice,
                    $stockQty,
                    $productId,
                    $scaleId,
                    $userId
                );
                $updateStmt->execute();
                $updateStmt->close();
            }

            $conn->commit();
            $saved = loadScaleProductRow($conn, $userId, $scaleId);
            scaleApiResponse(true, ['product' => $saved], $action === 'add' ? 'کاڵا زیادکرا' : 'کاڵا نوێکرایەوە');
        } catch (Throwable $e) {
            $conn->rollback();
            scaleApiResponse(false, null, $e->getMessage(), 400);
        }
        break;

    default:
        scaleApiResponse(false, null, 'کردارێکی نادروست', 400);
}
