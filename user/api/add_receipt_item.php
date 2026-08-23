<?php
/**
 * API بۆ زیادکردنی کاڵا بۆ وەسڵی کڕدراو - api/add_receipt_item.php
 */

require_once '../includes/functions.php';
require_once '../config/config.php';
require_once '../config/security.php';
require_once '../../includes/product_change_logger.php';

SessionManager::requireAuth('user');

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$receiptId = (int)($input['receipt_id'] ?? 0);
$productId = (int)($input['product_id'] ?? 0);
$quantity = (float)($input['quantity'] ?? 0);
$unitIdInput = (int)($input['unit_id'] ?? 0);
$buyPrice = (float)($input['buy_price'] ?? 0);
$sellPrice = (float)($input['sell_price'] ?? 0);
$wholesalePrice = (float)($input['wholesale_price'] ?? 0);
$specialPrice = (float)($input['special_price'] ?? 0);
$expiryDate = !empty($input['expiry_date']) ? $input['expiry_date'] : null;

// تاقیکردنەوەی داتاکان
if (!$receiptId || !$productId || $quantity <= 0 || $buyPrice <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit;
}

try {
    $beforeSnapshot = getProductSnapshotForLogs($conn, $userId, $productId);
    $conn->begin_transaction();
    
    // تاقیکردنەوە کە وەسڵەکە هی بەکارهێنەرەکەیە
    $stmt = $conn->prepare("SELECT id FROM purchase_receipts WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $receiptId, $userId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Receipt not found']);
        exit;
    }
    
    // وەرگرتنی زانیاری کاڵاکە
    $stmt = $conn->prepare("SELECT name FROM products WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $productId, $userId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    
    $totalCost = $quantity * $buyPrice;

    $effectiveUnitId = $unitIdInput;
    if ($effectiveUnitId <= 0) {
        $uStmt = $conn->prepare("SELECT unit_id FROM product_units WHERE product_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1");
        $uStmt->bind_param('i', $productId);
        $uStmt->execute();
        $uRow = $uStmt->get_result()->fetch_assoc();
        $effectiveUnitId = (int)($uRow['unit_id'] ?? 0);
    }
    if ($effectiveUnitId <= 0) {
        throw new Exception('یەکەی کاڵا دیاری نەکراوە — تکایە unit_id بنێرە یان یەکەی بنەڕەتی زیاد بکە');
    }

    require_once __DIR__ . '/../../includes/zanyari_user_settings.php';
    $useWeighted = getPurchasesUseWeightedAvgPrices($userId);
    
    // زیادکردنی کاڵا بۆ وەسڵەکە
    $stmt = $conn->prepare("
        INSERT INTO purchase_receipt_items 
        (purchase_receipt_id, product_id, product_name, quantity, buy_price, sell_price, 
         wholesale_price, special_price, expiry_date, total_cost, unit_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param('iisdddddsdi', 
        $receiptId, $productId, $product['name'], $quantity, $buyPrice, $sellPrice,
        $wholesalePrice, $specialPrice, $expiryDate, $totalCost, $effectiveUnitId
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to add item to receipt');
    }

    $purchaseItemId = (int)$conn->insert_id;
    
    // نوێکردنەوەی بەرواری بەسەرچوون
    if ($expiryDate) {
        $stmt = $conn->prepare("UPDATE products SET expiry_date = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param('sii', $expiryDate, $productId, $userId);
        $stmt->execute();
    }

    // کۆگا و نرخ لە product_units تەنها (وەک purchases/add.php)
    $getUnitPriceStmt = $conn->prepare("
        SELECT buy_price, sell_price, wholesale_price, special_price, stock_quantity 
        FROM product_units 
        WHERE product_id = ? AND unit_id = ?
    ");
    $getUnitPriceStmt->bind_param("ii", $productId, $effectiveUnitId);
    $getUnitPriceStmt->execute();
    $currentUnit = $getUnitPriceStmt->get_result()->fetch_assoc();

    if (!$useWeighted && $purchaseItemId > 0 && $currentUnit) {
        $revB = (float)$currentUnit['buy_price'];
        $revS = (float)$currentUnit['sell_price'];
        $revW = (float)$currentUnit['wholesale_price'];
        $revSp = (float)$currentUnit['special_price'];
        $updRev = $conn->prepare("
            UPDATE purchase_receipt_items SET
                revert_buy_price = ?, revert_sell_price = ?, revert_wholesale_price = ?, revert_special_price = ?
            WHERE id = ?
        ");
        $updRev->bind_param("ddddi", $revB, $revS, $revW, $revSp, $purchaseItemId);
        $updRev->execute();
        $updRev->close();
    }

    $updateUnitStockStmt = $conn->prepare("
        UPDATE product_units SET 
            stock_quantity = stock_quantity + ?
        WHERE product_id = ? AND unit_id = ?
    ");
    $updateUnitStockStmt->bind_param("dii", $quantity, $productId, $effectiveUnitId);
    $updateUnitStockStmt->execute();

    if ($currentUnit) {
        $Q_old_unit = (float)$currentUnit['stock_quantity'];
        $Q_new_unit = $quantity;
        if (($Q_old_unit + $Q_new_unit) > 0) {
            if ($useWeighted) {
                $P_old_unit_buy = (float)$currentUnit['buy_price'];
                $P_new_unit_buy = $buyPrice;
                $newWeightedAverageUnitBuy = (($Q_old_unit * $P_old_unit_buy) + ($Q_new_unit * $P_new_unit_buy)) / ($Q_old_unit + $Q_new_unit);

                $P_old_unit_sell = (float)$currentUnit['sell_price'];
                $P_new_unit_sell = $sellPrice;
                $newWeightedAverageUnitSell = (($Q_old_unit * $P_old_unit_sell) + ($Q_new_unit * $P_new_unit_sell)) / ($Q_old_unit + $Q_new_unit);

                $P_old_unit_wholesale = (float)$currentUnit['wholesale_price'];
                $P_new_unit_wholesale = $wholesalePrice;
                $newWeightedAverageUnitWholesale = (($Q_old_unit * $P_old_unit_wholesale) + ($Q_new_unit * $P_new_unit_wholesale)) / ($Q_old_unit + $Q_new_unit);

                $P_old_unit_special = (float)$currentUnit['special_price'];
                $P_new_unit_special = $specialPrice;
                $newWeightedAverageUnitSpecial = (($Q_old_unit * $P_old_unit_special) + ($Q_new_unit * $P_new_unit_special)) / ($Q_old_unit + $Q_new_unit);
            } else {
                $newWeightedAverageUnitBuy = $buyPrice;
                $newWeightedAverageUnitSell = $sellPrice;
                $newWeightedAverageUnitWholesale = $wholesalePrice;
                $newWeightedAverageUnitSpecial = $specialPrice;
            }

            $updateUnitPriceStmt = $conn->prepare("
                UPDATE product_units SET 
                    buy_price = ?, 
                    sell_price = ?, 
                    wholesale_price = ?, 
                    special_price = ?
                WHERE product_id = ? AND unit_id = ?
            ");
            $updateUnitPriceStmt->bind_param("ddddii", 
                $newWeightedAverageUnitBuy, 
                $newWeightedAverageUnitSell, 
                $newWeightedAverageUnitWholesale, 
                $newWeightedAverageUnitSpecial,
                $productId, 
                $effectiveUnitId
            );
            $updateUnitPriceStmt->execute();
        }
    }

    // هاوکاتکردنی (sync) یەکەکانی تری ئەم کاڵایە بەپێی conversion_ratio — هاوشێوەی api/sales.php
    // بۆ ئەوەی کۆگای هەموو یەکەکان هاوتا بمێنێتەوە (drift دروست نەبێت).
    if ($effectiveUnitId > 0) {
        $txnRatioStmt = $conn->prepare("
            SELECT conversion_ratio FROM product_units WHERE product_id = ? AND unit_id = ?
        ");
        $txnRatioStmt->bind_param("ii", $productId, $effectiveUnitId);
        $txnRatioStmt->execute();
        $txnRatioRow = $txnRatioStmt->get_result()->fetch_assoc();
        $txn_unit_ratio = $txnRatioRow['conversion_ratio'] ?? null;

        if ($txn_unit_ratio === null || (float)$txn_unit_ratio <= 0) {
            // ڕێژەی یەکەی کڕدراو نەزانراو/سفرە — sync ناکرێت (بەبێ ڕێژە فۆرمولا مانای نییە)
            error_log("Purchase stock sync skipped (add_receipt_item): invalid txn conversion_ratio for product_id=$productId, unit_id=$effectiveUnitId");
        } else {
            $otherUnitsStmt = $conn->prepare("
                SELECT unit_id, conversion_ratio
                FROM product_units
                WHERE product_id = ? AND unit_id != ?
            ");
            $otherUnitsStmt->bind_param("ii", $productId, $effectiveUnitId);
            $otherUnitsStmt->execute();
            $otherUnitsResult = $otherUnitsStmt->get_result();

            while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                $other_unit_id = (int)$otherUnit['unit_id'];
                $other_unit_ratio = $otherUnit['conversion_ratio'];

                // پارێزەری دابەشکردن بەسەر سفر — ئەگەر ڕێژە نەزانراو/سفر بێت ئەم یەکەیە تێپەڕ بکە
                if ($other_unit_ratio === null || (float)$other_unit_ratio <= 0) {
                    error_log("Purchase stock sync skipped for unit_id=$other_unit_id (product_id=$productId): invalid conversion_ratio");
                    continue;
                }

                // فۆرمولا: بڕی هاوکات = بڕ × (ratioی یەکەی کڕدراو ÷ ratioی یەکەی تر)
                $sync_amount = $quantity * ((float)$txn_unit_ratio / (float)$other_unit_ratio);

                $updSyncStmt = $conn->prepare("
                    UPDATE product_units SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                    WHERE product_id = ? AND unit_id = ?
                ");
                $updSyncStmt->bind_param("dii", $sync_amount, $productId, $other_unit_id);
                $updSyncStmt->execute();
            }
        }
    }

    // نوێکردنەوەی کۆی گشتی وەسڵەکە
    updateReceiptTotals($conn, $receiptId);
    
    $conn->commit();
    $afterSnapshot = getProductSnapshotForLogs($conn, $userId, $productId);
    logProductChangeEvent(
        'purchase_receipt_item.create',
        'purchase_receipt',
        $receiptId,
        $beforeSnapshot,
        $afterSnapshot,
        [
            'user_id' => $userId,
            'current_user' => $currentUser,
            'product_id' => $productId,
            'source_module' => 'user/api/add_receipt_item.php',
            'source_reference' => (string)$purchaseItemId
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Item added successfully'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

function updateReceiptTotals($conn, $receiptId) {
    // حیسابکردنی کۆی بنەڕەتی
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_cost), 0) as subtotal 
        FROM purchase_receipt_items 
        WHERE purchase_receipt_id = ?
    ");
    $stmt->bind_param('i', $receiptId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $subtotal = (float)$result['subtotal'];
    
    // وەرگرتنی داشکاندن و کرێی زیادە
    $stmt = $conn->prepare("
        SELECT discount_amount, additional_charges 
        FROM purchase_receipts 
        WHERE id = ?
    ");
    $stmt->bind_param('i', $receiptId);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();
    
    $discountAmount = (float)($receipt['discount_amount'] ?? 0);
    $additionalCharges = (float)($receipt['additional_charges'] ?? 0);
    
    $finalAmount = $subtotal - $discountAmount + $additionalCharges;
    
    // نوێکردنەوەی وەسڵەکە
    $stmt = $conn->prepare("
        UPDATE purchase_receipts 
        SET total_amount = ?, final_amount = ? 
        WHERE id = ?
    ");
    $stmt->bind_param('ddi', $subtotal, $finalAmount, $receiptId);
    $stmt->execute();
}
?>