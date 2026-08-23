<?php
/**
 * Returns API - api/returns.php
 * Handles product return operations
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/security.php';
require_once '../includes/functions.php';
require_once '../includes/permissions.php';
require_once '../includes/profit_schema.php';
require_once '../includes/wallet_service.php';
require_once '../includes/sale_return_service.php';

class ApiException extends Exception {
    public $statusCode;

    public function __construct(string $message, int $statusCode = 400) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }
}

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$userType = $currentUser['user_type'] ?? 'main';
$subUserId = $currentUser['sub_user_id'] ?? null;

// کارمەندی سنووردار: تەنها بۆ فرۆشتنی خۆی گەڕانەوە دروست بکات
$ownOnlySubUserId = getSalesOwnOnlySubUserId($currentUser);

enforceAuthorizationOrDeny($currentUser, 'returns.create', [
    'route' => '/api/returns.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST'
], 'json');

try {
    ensureProfitSnapshotColumns($conn);
    ensureSaleReturnLinkColumns($conn);
} catch (Exception $e) {
    error_log('Profit schema migration check failed in returns.php: ' . $e->getMessage());
}

function resolveReturnUnitCostAtReturn($conn, $userId, $productId, $unitId = null, $saleItemId = null) {
    if ($saleItemId !== null) {
        $saleItemId = (int)$saleItemId;
        if ($saleItemId > 0) {
            $saleItemStmt = $conn->prepare("
                SELECT si.unit_cost_at_sale
                FROM sale_items si
                INNER JOIN sales s ON s.id = si.sale_id
                WHERE si.id = ? AND s.user_id = ?
                LIMIT 1
            ");
            if ($saleItemStmt) {
                $saleItemStmt->bind_param("ii", $saleItemId, $userId);
                if ($saleItemStmt->execute()) {
                    $saleItemRow = $saleItemStmt->get_result()->fetch_assoc();
                    $saleItemStmt->close();
                    if ($saleItemRow && $saleItemRow['unit_cost_at_sale'] !== null) {
                        return (float)$saleItemRow['unit_cost_at_sale'];
                    }
                } else {
                    $saleItemStmt->close();
                }
            }
        }
    }

    $productId = (int)$productId;
    if ($productId <= 0) {
        return null;
    }

    if ($unitId !== null) {
        $unitId = (int)$unitId;
        $stmt = $conn->prepare("
            SELECT pu.buy_price
            FROM product_units pu
            JOIN products p ON p.id = pu.product_id
            WHERE pu.product_id = ? AND pu.unit_id = ? AND p.user_id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("iii", $productId, $unitId, $userId);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && $row['buy_price'] !== null) {
                    return (float)$row['buy_price'];
                }
            } else {
                $stmt->close();
            }
        }
    }

    $fallbackStmt = $conn->prepare("
        SELECT pu.buy_price
        FROM product_units pu
        JOIN products p ON p.id = pu.product_id
        WHERE pu.product_id = ? AND p.user_id = ?
        ORDER BY pu.is_primary DESC, pu.id ASC
        LIMIT 1
    ");
    if (!$fallbackStmt) {
        return null;
    }
    $fallbackStmt->bind_param("ii", $productId, $userId);
    if (!$fallbackStmt->execute()) {
        $fallbackStmt->close();
        return null;
    }

    $row = $fallbackStmt->get_result()->fetch_assoc();
    $fallbackStmt->close();
    if ($row && $row['buy_price'] !== null) {
        return (float)$row['buy_price'];
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'تەنها POST ڕێگەپێدراوە']);
    exit();
}

try {
    // وەرگرتنی داتا
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new ApiException('داتای نادروست', 400);
    }
    
    // تاقیکردنی CSRF token
    if (!isset($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
        throw new ApiException('CSRF token نادروست', 403);
    }
    
    // تاقیکردنی داتای پێویست
    if (empty($data['items']) || !is_array($data['items'])) {
        throw new ApiException('هیچ کاڵایەک دیاری نەکراوە', 400);
    }

    $linkedSaleId = !empty($data['sale_id']) ? (int)$data['sale_id'] : 0;
    $saleReturnContext = null;
    if ($linkedSaleId > 0) {
        $saleReturnContext = getSaleReturnContext($conn, $userId, $linkedSaleId, $ownOnlySubUserId);
        if (!$saleReturnContext) {
            throw new ApiException('فرۆشتن نەدۆزرایەوە', 404);
        }
        try {
            $normalized = validateAndNormalizeSaleReturnItems($conn, $userId, $saleReturnContext, $data['items']);
        } catch (InvalidArgumentException $e) {
            throw new ApiException($e->getMessage(), 422);
        }
        $data['items'] = $normalized['items'];
        $data['total_amount'] = $normalized['total_amount'];
        $data['final_amount'] = $normalized['final_amount'];
        $data['discount'] = $normalized['discount'];
    }
    
    $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
    $customerName = !empty($data['customer_name']) ? trim($data['customer_name']) : null;
    $totalAmount = (float)$data['total_amount'];
    $discount = (float)($data['discount'] ?? 0);
    $finalAmount = (float)$data['final_amount'];
    $paymentMethod = $data['payment_method'] ?? 'cash';
    $walletId = isset($data['wallet_id']) ? (int)$data['wallet_id'] : 0;
    $returnReason = $data['return_reason'] ?? null;

    if ($saleReturnContext) {
        $saleRow = $saleReturnContext['sale'];
        $customerId = !empty($saleRow['customer_id']) ? (int)$saleRow['customer_id'] : $customerId;
        $customerName = !empty($saleRow['customer_name']) ? trim($saleRow['customer_name']) : $customerName;
        $paymentMethod = $saleReturnContext['suggested_return_payment_method'];
        // قاسە هەمیشە دەستنیشان دەکەین چونکە لەوانەیە بەشی نەقدی گەڕاندنەوە هەبێت (cap-then-cash).
        if ($walletId <= 0) {
            $walletId = (int)($saleReturnContext['suggested_wallet_id'] ?? 0);
        }
        if ($walletId <= 0) {
            $walletId = (int)(walletGetDefaultWalletId($conn, (int)$userId) ?? 0);
        }
        $data['currency'] = $saleRow['currency'] ?? 'IQD';
    }

    if (!$saleReturnContext && $paymentMethod === 'cash' && $walletId <= 0) {
        throw new ApiException('بۆ گەڕاندنەوەی نەقد، قاسە پێویستە', 422);
    }

    $returnCurrency = (!empty($data['currency']) && in_array($data['currency'], ['IQD', 'USD'], true)) ? $data['currency'] : 'IQD';
    
    // دروستکردنی ژمارەی گەڕاندنەوە
    $returnNumber = 'RET-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // دەستپێکردنی transaction
    $conn->begin_transaction();
    
    try {
        // For credit/debt returns: reduce outstanding debt first, then refund the remainder as cash (cap-then-cash).
        $isCreditReturn = in_array($paymentMethod, ['credit', 'debt', 'installment'], true);
        $debtPortion = 0.0;
        $cashPortion = $finalAmount;
        if ($isCreditReturn) {
            if (!$customerId) {
                throw new ApiException('بۆ گەڕاوەی قەرز، دیاریکردنی کڕیار پێویستە', 422);
            }

            $totalOutstanding = saleReturnGetCustomerOutstanding($conn, (int)$userId, (int)$customerId, $returnCurrency);
            $debtPortion = min($finalAmount, $totalOutstanding);
            if ($debtPortion < 0) {
                $debtPortion = 0.0;
            }
            $cashPortion = max(0.0, $finalAmount - $debtPortion);
        }

        if ($cashPortion > 0.00001 && $walletId <= 0) {
            throw new ApiException('بۆ بەشی نەقدی گەڕاندنەوە، قاسە پێویستە', 422);
        }

        // زیادکردنی گەڕاندنەوە بۆ داتابەیس
        if ($linkedSaleId > 0 && saleReturnHasSaleIdColumn($conn)) {
            $stmt = $conn->prepare("
                INSERT INTO returns (
                    user_id, user_type, sub_user_id, customer_id, sale_id, return_number,
                    customer_name, total_amount, discount, final_amount,
                    payment_method, wallet_id, return_reason
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'isiiissdddsis',
                $userId, $userType, $subUserId, $customerId, $linkedSaleId, $returnNumber,
                $customerName, $totalAmount, $discount, $finalAmount,
                $paymentMethod, $walletId, $returnReason
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO returns (
                    user_id, user_type, sub_user_id, customer_id, return_number,
                    customer_name, total_amount, discount, final_amount,
                    payment_method, wallet_id, return_reason
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'isiissdddsis',
                $userId, $userType, $subUserId, $customerId, $returnNumber,
                $customerName, $totalAmount, $discount, $finalAmount,
                $paymentMethod, $walletId, $returnReason
            );
        }
        
        if (!$stmt->execute()) {
            throw new Exception('هەڵەیەک ڕووی دا لە زیادکردنی گەڕاندنەوە');
        }
        
        $returnId = $conn->insert_id;
        
        // زیادکردنی کاڵاکانی گەڕاندنەوە
        foreach ($data['items'] as $item) {
            $productId = (int)$item['product_id'];
            $productName = trim($item['product_name']);
            $quantity = (float)$item['quantity'];
            $unitPrice = (float)$item['unit_price'];
            $totalPrice = (float)$item['total_price'];
            $priceType = $item['price_type'] ?? 'retail';
            $unitId = !empty($item['unit_id']) ? (int)$item['unit_id'] : null;
            $unitName = $item['unit_name'] ?? 'دانە';
            $unitSymbol = $item['unit_symbol'] ?? '';
            $saleItemId = !empty($item['sale_item_id']) ? (int)$item['sale_item_id'] : null;
            $unitCostAtReturn = resolveReturnUnitCostAtReturn($conn, $userId, $productId, $unitId, $saleItemId);
            $unitCostAtReturnParam = $unitCostAtReturn === null ? null : (string)$unitCostAtReturn;
            
            // زیادکردنی کاڵای گەڕاندنەوە
            if ($saleItemId && saleReturnHasSaleItemIdColumn($conn)) {
                $stmt = $conn->prepare("
                    INSERT INTO return_items (
                        return_id, sale_item_id, product_id, product_name, quantity, unit_price,
                        total_price, unit_cost_at_return, price_type, unit_id, unit_name, unit_symbol
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    'iiisdddssiss',
                    $returnId, $saleItemId, $productId, $productName, $quantity, $unitPrice,
                    $totalPrice, $unitCostAtReturnParam, $priceType, $unitId, $unitName, $unitSymbol
                );
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO return_items (
                        return_id, product_id, product_name, quantity, unit_price,
                        total_price, unit_cost_at_return, price_type, unit_id, unit_name, unit_symbol
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    'iisdddssiss',
                    $returnId, $productId, $productName, $quantity, $unitPrice,
                    $totalPrice, $unitCostAtReturnParam, $priceType, $unitId, $unitName, $unitSymbol
                );
            }
            
            if (!$stmt->execute()) {
                throw new Exception('هەڵەیەک ڕووی دا لە زیادکردنی کاڵای گەڕاندنەوە');
            }
            
            // زیادکردنی بڕی کاڵا بۆ کۆگا
            if ($unitId) {
                // Update product_units table for products with units
                // First, add to the returned unit
                $updateStmt = $conn->prepare("
                    UPDATE product_units 
                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                    WHERE product_id = ? AND unit_id = ?
                ");
                
                $updateStmt->bind_param('dii', $quantity, $productId, $unitId);
                
                if (!$updateStmt->execute()) {
                    throw new Exception('هەڵەیەک ڕووی دا لە نوێکردنەوەی کۆگا');
                }
                
                // Now add to other units of the same product using conversion ratio
                // وەرگرتنی هەموو یەکەکانی تری ئەم کاڵایە
                $otherUnitsStmt = $conn->prepare("
                    SELECT unit_id, conversion_ratio, stock_quantity
                    FROM product_units
                    WHERE product_id = ? AND unit_id != ?
                ");
                $otherUnitsStmt->bind_param("ii", $productId, $unitId);
                $otherUnitsStmt->execute();
                $otherUnitsResult = $otherUnitsStmt->get_result();
                
                // Get the conversion ratio of the returned unit
                $returnedUnitStmt = $conn->prepare("
                    SELECT conversion_ratio 
                    FROM product_units 
                    WHERE product_id = ? AND unit_id = ?
                ");
                $returnedUnitStmt->bind_param("ii", $productId, $unitId);
                $returnedUnitStmt->execute();
                $returnedUnitData = $returnedUnitStmt->get_result()->fetch_assoc();
                $returned_unit_ratio = $returnedUnitData['conversion_ratio'] ?? 1.0;
                
                // Update each other unit based on conversion ratio
                while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                    $other_unit_id = $otherUnit['unit_id'];
                    $other_unit_ratio = $otherUnit['conversion_ratio'];

                    // پارێزەری دابەشکردن بەسەر سفر — ئەگەر ڕێژە نەزانراو/سفر بێت ئەم یەکەیە تێپەڕ بکە
                    if ($other_unit_ratio === null || (float)$other_unit_ratio <= 0) {
                        error_log("Return stock sync skipped for unit_id=$other_unit_id (product_id=$productId): invalid conversion_ratio");
                        continue;
                    }

                    // Calculate how much to add to this unit
                    // Formula: returned_quantity * (returned_unit_ratio / other_unit_ratio)
                    $addition_amount = $quantity * ($returned_unit_ratio / $other_unit_ratio);
                    
                    // Update the other unit's stock
                    $updateOtherUnitStmt = $conn->prepare("
                        UPDATE product_units 
                        SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                        WHERE product_id = ? AND unit_id = ?
                    ");
                    $updateOtherUnitStmt->bind_param("dii", $addition_amount, $productId, $other_unit_id);
                    $updateOtherUnitStmt->execute();
                }
            } else {
                // Update primary/fallback unit for products without explicit unit_id
                $updateStmt = $conn->prepare("
                    UPDATE product_units
                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
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
                
                $updateStmt->bind_param('dii', $quantity, $productId, $userId);
                
                if (!$updateStmt->execute()) {
                    throw new Exception('هەڵەیەک ڕووی دا لە نوێکردنەوەی کۆگا');
                }
                
                // تاقیکردنی ئەوەی کاڵا بە سەرکەوتوویی نوێکرایەوە
                if ($updateStmt->affected_rows === 0) {
                    throw new Exception('کاڵا بەم ID یە نەدۆزرایەوە: ' . $productId);
                }
            }
        }

        // Apply the debt-reducing portion first (sale debt first, then other debts via LIFO).
        if ($isCreditReturn && $debtPortion > 0.00001) {
            if ($linkedSaleId > 0) {
                $remainingToApply = applySaleLinkedDebtReduction(
                    $conn,
                    $userId,
                    $customerId,
                    $linkedSaleId,
                    $debtPortion,
                    $returnCurrency,
                    $returnNumber
                );
            } else {
                $remainingToApply = $debtPortion;

                $activeDebtsStmt = $conn->prepare("
                    SELECT d.id, d.remaining_amount
                    FROM debts d
                    LEFT JOIN sales s ON d.sale_id = s.id
                    WHERE d.user_id = ?
                      AND d.customer_id = ?
                      AND d.status = 'active'
                      AND d.remaining_amount > 0
                      AND COALESCE(s.currency, 'IQD') = ?
                    ORDER BY d.created_at DESC, d.id DESC
                ");
                $activeDebtsStmt->bind_param('iis', $userId, $customerId, $returnCurrency);
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

                saleReturnSyncCustomerDebt($conn, $userId, $customerId);
            }

            // ئەگەر بەشێک نەگەڕایەوە سەر قەرز (بۆ نموونە قەرز کەمتر بوو لە چاوەڕوانکراو)، بیخەرە سەر بەشی نەقد.
            if ($remainingToApply > 0.00001) {
                $cashPortion += $remainingToApply;
            }
        }

        // Refund the remaining (cash) portion from the wallet.
        if ($cashPortion > 0.00001) {
            if ($walletId <= 0) {
                throw new ApiException('بۆ بەشی نەقدی گەڕاندنەوە، قاسە پێویستە', 422);
            }
            if (!walletPostEntry(
                $conn,
                $userId,
                $walletId,
                'return_out',
                'out',
                $returnCurrency,
                $cashPortion,
                'return',
                $returnId,
                'Return refund outflow',
                (int)$userId
            )) {
                throw new Exception('نەتوانرا جوڵەی قاسەی گەڕاندنەوە تۆمار بکرێت');
            }
        }
        
        // تەواوکردنی transaction
        $conn->commit();
        
        // وەرگرتنی داتای گەڕاندنەوە بۆ گەڕاندنەوە
        $returnData = [
            'id' => $returnId,
            'return_number' => $returnNumber,
            'customer_name' => $customerName,
            'total_amount' => $totalAmount,
            'discount' => $discount,
            'final_amount' => $finalAmount,
            'payment_method' => $paymentMethod,
            'return_reason' => $returnReason,
            'return_date' => date('Y-m-d H:i:s'),
            'items' => $data['items']
        ];
        
        // تۆمارکردنی چالاکی
        logActivity($userId, 'return_created', "گەڕاندنەوەیەک دروستکرا - ژمارە: $returnNumber");
        
        echo json_encode([
            'success' => true,
            'message' => 'گەڕاندنەوە بە سەرکەوتووییی تەواو بوو',
            'data' => [
                'return' => $returnData
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    $status = 400;
    if ($e instanceof ApiException) {
        $status = $e->statusCode;
    }
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
