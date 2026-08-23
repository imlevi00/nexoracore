<?php
/**
 * Orders Management - user/website/orders.php
 * Manage online shop orders
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/profit_schema.php';
require_once '../../includes/web_order_duplicate.php';

// Check user authentication
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

try {
    ensureProfitSnapshotColumns($conn);
} catch (Exception $e) {
    writeLog('Profit schema migration check failed in orders.php: ' . $e->getMessage());
}

// Check if user is main user (not sub-user)
if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    header('Location: ' . url('user/dashboard/index.php'));
    exit;
}

$success = '';
$error = '';

// Get website settings
$stmt = $conn->prepare("SELECT * FROM website_settings WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$websiteSettings = $result->fetch_assoc();
$stmt->close();

if (!$websiteSettings) {
    header('Location: ' . url('user/website/index.php'));
    exit;
}

$checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'order_complete_customer_required'");
if ($checkColumnStmt && $checkColumnStmt->num_rows === 0) {
    $conn->query("ALTER TABLE website_settings ADD COLUMN order_complete_customer_required TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=کڕیار مەرجە، 0=ئارەزوومەندانە'");
}
if ($checkColumnStmt) {
    $checkColumnStmt->close();
}

$checkColumnStmt = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'order_complete_default_payment_credit'");
if ($checkColumnStmt && $checkColumnStmt->num_rows === 0) {
    $conn->query("ALTER TABLE website_settings ADD COLUMN order_complete_default_payment_credit TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=بنەڕەت قەرز، 0=بنەڕەت نەخت'");
}
if ($checkColumnStmt) {
    $checkColumnStmt->close();
}

$orderCustomerRequired = (int)($websiteSettings['order_complete_customer_required'] ?? 1);
$orderDefaultPaymentCredit = (int)($websiteSettings['order_complete_default_payment_credit'] ?? 0);

// Handle order status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } elseif ($_POST['action'] === 'mark_completed') {
        $orderId = intval($_POST['order_id']);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get order details first
            $orderStmt = $conn->prepare("SELECT items, status FROM web_orders WHERE id = ? AND user_id = ?");
            $orderStmt->bind_param("ii", $orderId, $userId);
            $orderStmt->execute();
            $orderResult = $orderStmt->get_result();
            $order = $orderResult->fetch_assoc();
            $orderStmt->close();
            
            if (!$order) {
                throw new Exception('داواکارییەکە نەدۆزرایەوە');
            }
            
            // Only reduce stock if order is not already completed
            if ($order['status'] !== 'completed') {
                // Parse items JSON
                $items = json_decode($order['items'], true);
                
                if ($items && is_array($items)) {
                    // Reduce stock for each item
                    foreach ($items as $item) {
                        $quantity = floatval($item['quantity'] ?? 0);
                        $productId = isset($item['id']) ? intval($item['id']) : null;
                        $unitId = isset($item['unitId']) ? intval($item['unitId']) : null;
                        
                        if ($quantity <= 0) {
                            continue;
                        }
                        
                        // If product has unitId, it means it has units
                        if ($unitId) {
                            // Get product_id and unit_id from product_units table
                            $unitInfoStmt = $conn->prepare("
                                SELECT product_id, unit_id, conversion_ratio 
                                FROM product_units 
                                WHERE id = ? AND product_id IN (SELECT id FROM products WHERE user_id = ?)
                            ");
                            $unitInfoStmt->bind_param("ii", $unitId, $userId);
                            $unitInfoStmt->execute();
                            $unitInfoResult = $unitInfoStmt->get_result();
                            $unitInfo = $unitInfoResult->fetch_assoc();
                            $unitInfoStmt->close();
                            
                            if (!$unitInfo) {
                                throw new Exception('یەکەی کاڵا نەدۆزرایەوە');
                            }
                            
                            $actualProductId = $unitInfo['product_id'];
                            $actualUnitId = $unitInfo['unit_id'];
                            $soldUnitRatio = floatval($unitInfo['conversion_ratio'] ?? 1.0);
                            
                            // First, update the sold unit's stock
                            $updateStockStmt = $conn->prepare("
                                UPDATE product_units 
                                SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                                WHERE id = ?
                            ");
                            $updateStockStmt->bind_param("di", $quantity, $unitId);
                            
                            if (!$updateStockStmt->execute()) {
                                throw new Exception('نەتوانرا بڕی کاڵا نوێ بکرێتەوە');
                            }
                            $updateStockStmt->close();
                            
                            // Now update other units of the same product using conversion ratio
                            $otherUnitsStmt = $conn->prepare("
                                SELECT id, unit_id, conversion_ratio
                                FROM product_units
                                WHERE product_id = ? AND id != ?
                            ");
                            $otherUnitsStmt->bind_param("ii", $actualProductId, $unitId);
                            $otherUnitsStmt->execute();
                            $otherUnitsResult = $otherUnitsStmt->get_result();
                            
                            // Update each other unit based on conversion ratio
                            while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                                $otherUnitId = $otherUnit['id'];
                                $otherUnitRatio = floatval($otherUnit['conversion_ratio'] ?? 1.0);
                                if ($otherUnitRatio <= 0) {
                                    writeLog("Warning: skipped stock sync for unit_id {$otherUnitId}: invalid conversion_ratio");
                                    continue;
                                }

                                // Calculate how much to deduct from this unit
                                // Formula: sold_quantity * (sold_unit_ratio / other_unit_ratio)
                                $deductionAmount = $quantity * ($soldUnitRatio / $otherUnitRatio);
                                
                                // Update the other unit's stock
                                $updateOtherUnitStmt = $conn->prepare("
                                    UPDATE product_units 
                                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                                    WHERE id = ?
                                ");
                                $updateOtherUnitStmt->bind_param("di", $deductionAmount, $otherUnitId);
                                
                                if (!$updateOtherUnitStmt->execute()) {
                                    // Log error but don't fail the transaction
                                    writeLog("Warning: Failed to update other unit stock for unit_id: {$otherUnitId}");
                                }
                                $updateOtherUnitStmt->close();
                            }
                            $otherUnitsStmt->close();
                        } elseif ($productId) {
                            // Product without units - update products table directly
                            $updateStockStmt = $conn->prepare("
                                UPDATE product_units
                                SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                                WHERE id = (
                                    SELECT pux.id FROM (
                                        SELECT pu.id FROM product_units pu
                                        INNER JOIN products p ON p.id = pu.product_id
                                        WHERE pu.product_id = ? AND p.user_id = ?
                                        ORDER BY pu.is_primary DESC, pu.id ASC
                                        LIMIT 1
                                    ) AS pux
                                )
                            ");
                            $updateStockStmt->bind_param("dii", $quantity, $productId, $userId);
                            
                            if (!$updateStockStmt->execute()) {
                                throw new Exception('نەتوانرا بڕی کاڵا نوێ بکرێتەوە');
                            }
                            $updateStockStmt->close();
                        }
                    }
                }
            }
            
            // Update order status
            $updateStmt = $conn->prepare("UPDATE web_orders SET status = 'completed', completed_at = NOW() WHERE id = ? AND user_id = ?");
            $updateStmt->bind_param("ii", $orderId, $userId);
            
            if (!$updateStmt->execute()) {
                throw new Exception('نەتوانرا دۆخی داواکاری نوێ بکرێتەوە');
            }
            $updateStmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $success = 'داواکارییەکە نیشانکرا وەک تەواو بوو و بڕی کاڵاکان نوێکرانەوە';
            writeLog("Order #{$orderId} marked as completed and stock reduced by user {$currentUser['email']}");
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = $e->getMessage();
            writeLog("Error completing order #{$orderId}: " . $e->getMessage());
        }
    } elseif ($_POST['action'] === 'complete_order_with_customer') {
        $orderId = intval($_POST['order_id']);
        $customerId = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $paymentMethod = cleanInput($_POST['payment_method'] ?? 'cash');
        $notes = cleanInput($_POST['notes'] ?? '');
        
        $customerRequiredSetting = (int)($websiteSettings['order_complete_customer_required'] ?? 1);

        if (!in_array($paymentMethod, ['cash', 'credit'], true)) {
            $error = 'شێوەی پارەدان نادروستە';
        } elseif ($customerRequiredSetting && !$customerId) {
            $error = 'تکایە کڕیارێک هەڵبژێرە';
        } elseif ($paymentMethod === 'credit' && !$customerId) {
            $error = 'بۆ پارەدانی قەرز، پێویستە کڕیارێک هەڵبژێریت';
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get order details
                $orderStmt = $conn->prepare("SELECT * FROM web_orders WHERE id = ? AND user_id = ?");
                $orderStmt->bind_param("ii", $orderId, $userId);
                $orderStmt->execute();
                $orderResult = $orderStmt->get_result();
                $order = $orderResult->fetch_assoc();
                $orderStmt->close();
                
                if (!$order) {
                    throw new Exception('داواکارییەکە نەدۆزرایەوە');
                }
                
                if ($order['status'] === 'completed') {
                    throw new Exception('ئەم وەسڵە پێشتر تەواو کراوە');
                }
                
                $customerName = '';
                $customerPhone = '';

                if ($customerId) {
                    $customerStmt = $conn->prepare("SELECT name, phone FROM customers WHERE id = ? AND user_id = ?");
                    $customerStmt->bind_param("ii", $customerId, $userId);
                    $customerStmt->execute();
                    $customerResult = $customerStmt->get_result();
                    $customer = $customerResult->fetch_assoc();
                    $customerStmt->close();

                    if (!$customer) {
                        throw new Exception('کڕیارەکە نەدۆزرایەوە');
                    }

                    $customerName = $customer['name'];
                    $customerPhone = $customer['phone'] ?? '';
                } else {
                    $customerName = cleanInput($order['customer_name'] ?? '');
                    $customerPhone = cleanInput($order['customer_phone'] ?? '');
                    if ($customerName === '') {
                        $customerName = 'کڕیار';
                    }
                }
                
                // Parse order items
                $items = json_decode($order['items'], true);
                if (!$items || !is_array($items)) {
                    throw new Exception('زانیاری کاڵاکان نادروستە');
                }
                
                // Determine currency (default to IQD)
                $saleCurrency = 'IQD';
                if (!empty($items[0]['currency']) && in_array($items[0]['currency'], ['USD', 'IQD'])) {
                    $saleCurrency = $items[0]['currency'];
                }
                
                // Calculate totals
                $totalAmount = floatval($order['total_amount']);
                $discount = 0; // Orders don't have discount field, but we'll use 0
                $finalAmount = $totalAmount;
                
                // Generate invoice number with retry logic to handle race conditions
                $invoiceNumber = generateInvoiceNumber();
                $attempts = 0;
                $invoiceGenerated = false;
                
                while (!$invoiceGenerated && $attempts < 20) {
                    // Check if invoice number exists (within transaction to prevent race conditions)
                    $checkStmt = $conn->prepare("SELECT id FROM sales WHERE invoice_number = ? AND user_id = ? FOR UPDATE");
                    $checkStmt->bind_param("si", $invoiceNumber, $userId);
                    $checkStmt->execute();
                    $exists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                    
                    if (!$exists) {
                        $invoiceGenerated = true;
                    } else {
                        // Generate new invoice number if duplicate found
                        $invoiceNumber = generateInvoiceNumber();
                        $attempts++;
                    }
                }
                
                if (!$invoiceGenerated) {
                    throw new Exception('نەتوانرا ژمارەی پسوولەی یەکتا دروست بکرێت');
                }
                
                // Determine payment status and amounts
                $paymentStatus = 'paid';
                $paidAmount = $finalAmount;
                $remainingAmount = 0;
                
                if ($paymentMethod === 'credit') {
                    $paymentStatus = 'pending';
                    $paidAmount = 0;
                    $remainingAmount = $finalAmount;
                }
                
                // Determine user type
                $userType = 'main';
                $subUserId = null;
                if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
                    $userType = 'sub';
                    $subUserId = isset($currentUser['sub_user_id']) ? (int)$currentUser['sub_user_id'] : null;
                }
                
                // Create sale record
                $saleStmt = $conn->prepare("
                    INSERT INTO sales (
                        user_id, user_type, sub_user_id, customer_id, invoice_number, customer_name, 
                        total_amount, discount, final_amount, currency, payment_method, 
                        payment_status, paid_amount, remaining_amount, sale_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $saleStmt->bind_param(
                    "ississdddsssdd",
                    $userId, $userType, $subUserId, $customerId, $invoiceNumber, $customerName,
                    $totalAmount, $discount, $finalAmount, $saleCurrency, $paymentMethod,
                    $paymentStatus, $paidAmount, $remainingAmount
                );
                
                if (!$saleStmt->execute()) {
                    throw new Exception('نەتوانرا فرۆشتنەکە تۆمار بکرێت: ' . $saleStmt->error);
                }
                
                $saleId = $conn->insert_id;
                $saleStmt->close();
                
                // Create sale items and reduce stock
                foreach ($items as $item) {
                    $quantity = floatval($item['quantity'] ?? 0);
                    $productId = isset($item['id']) ? intval($item['id']) : null;
                    $unitId = isset($item['unitId']) ? intval($item['unitId']) : null;
                    $productName = cleanInput($item['name'] ?? '');
                    $unitPrice = floatval($item['price'] ?? 0);
                    $totalPrice = $unitPrice * $quantity;
                    $priceType = cleanInput($item['price_type'] ?? 'retail');
                    $itemCurrency = !empty($item['currency']) && in_array($item['currency'], ['USD', 'IQD']) 
                        ? $item['currency'] 
                        : $saleCurrency;
                    
                    // Validate product exists if productId is provided
                    // If product doesn't exist, set productId to NULL (FK constraint allows NULL)
                    if ($productId && $productId > 0) {
                        $checkProductStmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
                        $checkProductStmt->bind_param("ii", $productId, $userId);
                        $checkProductStmt->execute();
                        $productExists = $checkProductStmt->get_result()->num_rows > 0;
                        $checkProductStmt->close();
                        
                        if (!$productExists) {
                            // Product doesn't exist (may have been deleted), set to NULL
                            writeLog("Warning: Product ID {$productId} not found for user {$userId}, setting product_id to NULL in sale_items");
                            $productId = null;
                        }
                    } else {
                        // Invalid product ID, set to NULL
                        $productId = null;
                    }
                    
                    // Get unit info if unitId exists
                    $unitName = null;
                    $unitSymbol = null;
                    $saleUnitId = null;
                    $unitCostAtSale = null;
                    $unitCostCurrency = 'IQD';
                    if ($unitId) {
                        $unitInfoStmt = $conn->prepare("
                            SELECT pu.unit_id, pu.buy_price, COALESCE(pu.currency, 'IQD') AS currency, u.name as unit_name, u.symbol as unit_symbol
                            FROM product_units pu
                            JOIN units u ON pu.unit_id = u.id
                            WHERE pu.id = ? AND pu.product_id IN (SELECT id FROM products WHERE user_id = ?)
                        ");
                        $unitInfoStmt->bind_param("ii", $unitId, $userId);
                        $unitInfoStmt->execute();
                        $unitInfoResult = $unitInfoStmt->get_result();
                        if ($unitInfo = $unitInfoResult->fetch_assoc()) {
                            $saleUnitId = (int)$unitInfo['unit_id'];
                            $unitName = $unitInfo['unit_name'];
                            $unitSymbol = $unitInfo['unit_symbol'];
                            if ($unitInfo['buy_price'] !== null) {
                                $unitCostAtSale = (float)$unitInfo['buy_price'];
                                $unitCostCurrency = strtoupper((string)$unitInfo['currency']);
                            }
                        }
                        $unitInfoStmt->close();
                    }

                    if ($unitCostAtSale === null && $productId !== null) {
                        $fallbackCostStmt = $conn->prepare("
                            SELECT pu.buy_price, COALESCE(pu.currency, 'IQD') AS currency
                            FROM product_units pu
                            JOIN products p ON p.id = pu.product_id
                            WHERE pu.product_id = ? AND p.user_id = ?
                            ORDER BY pu.is_primary DESC, pu.id ASC
                            LIMIT 1
                        ");
                        if ($fallbackCostStmt) {
                            $fallbackCostStmt->bind_param("ii", $productId, $userId);
                            $fallbackCostStmt->execute();
                            $fallbackCostRow = $fallbackCostStmt->get_result()->fetch_assoc();
                            if ($fallbackCostRow && $fallbackCostRow['buy_price'] !== null) {
                                $unitCostAtSale = (float)$fallbackCostRow['buy_price'];
                                $unitCostCurrency = strtoupper((string)$fallbackCostRow['currency']);
                            }
                            $fallbackCostStmt->close();
                        }
                    }

                    // گۆڕینی تێچووی کاڵا بۆ دراوی فرۆشتن تاکو لە ڕاپۆرتی قازانجدا
                    // تێچوو و داهات بە هەمان دراو (دینار یان دۆلار) بەراورد بکرێن.
                    if ($unitCostAtSale !== null && $unitCostAtSale > 0) {
                        $unitCostCurrency = ($unitCostCurrency === 'USD') ? 'USD' : 'IQD';
                        if ($unitCostCurrency !== $saleCurrency && function_exists('getExchangeRate')) {
                            $costRate = getExchangeRate($userId, 'USD', 'IQD');
                            if ($costRate !== false && $costRate > 0) {
                                if ($unitCostCurrency === 'USD' && $saleCurrency === 'IQD') {
                                    $unitCostAtSale = round($unitCostAtSale * $costRate);
                                } elseif ($unitCostCurrency === 'IQD' && $saleCurrency === 'USD') {
                                    $unitCostAtSale = round($unitCostAtSale / $costRate, 2);
                                }
                            }
                        }
                    }

                    // Insert sale item
                    // Note: product_id can be NULL if product doesn't exist (FK constraint allows NULL with ON DELETE SET NULL)
                    $itemStmt = $conn->prepare("
                        INSERT INTO sale_items (
                            sale_id, product_id, product_name, quantity, 
                            unit_price, total_price, unit_cost_at_sale, price_type, currency, unit_id, unit_name, unit_symbol
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    // Convert product_id to string or NULL for bind_param (mysqli handles NULL correctly with 's' type)
                    $productIdParam = $productId === null ? null : (string)$productId;
                    $unitCostAtSaleParam = $unitCostAtSale === null ? null : (string)$unitCostAtSale;
                    
                    $itemStmt->bind_param(
                        "issdddsssiss",
                        $saleId, $productIdParam, $productName, $quantity,
                        $unitPrice, $totalPrice, $unitCostAtSaleParam, $priceType, $itemCurrency, $saleUnitId, $unitName, $unitSymbol
                    );
                    
                    if (!$itemStmt->execute()) {
                        throw new Exception('نەتوانرا ئایتمی فرۆشتن تۆمار بکرێت: ' . $itemStmt->error);
                    }
                    $itemStmt->close();
                    
                    // Reduce stock
                    if ($quantity > 0) {
                        if ($unitId) {
                            // Get unit info for stock update
                            $unitInfoStmt = $conn->prepare("
                                SELECT product_id, unit_id, conversion_ratio 
                                FROM product_units 
                                WHERE id = ? AND product_id IN (SELECT id FROM products WHERE user_id = ?)
                            ");
                            $unitInfoStmt->bind_param("ii", $unitId, $userId);
                            $unitInfoStmt->execute();
                            $unitInfoResult = $unitInfoStmt->get_result();
                            $unitInfo = $unitInfoResult->fetch_assoc();
                            $unitInfoStmt->close();
                            
                            if ($unitInfo) {
                                $actualProductId = $unitInfo['product_id'];
                                $soldUnitRatio = floatval($unitInfo['conversion_ratio'] ?? 1.0);
                                
                                // Update the sold unit's stock
                                $updateStockStmt = $conn->prepare("
                                    UPDATE product_units 
                                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                                    WHERE id = ?
                                ");
                                $updateStockStmt->bind_param("di", $quantity, $unitId);
                                
                                if (!$updateStockStmt->execute()) {
                                    throw new Exception('نەتوانرا بڕی کاڵا نوێ بکرێتەوە');
                                }
                                $updateStockStmt->close();
                                
                                // Update other units of the same product
                                $otherUnitsStmt = $conn->prepare("
                                    SELECT id, unit_id, conversion_ratio
                                    FROM product_units
                                    WHERE product_id = ? AND id != ?
                                ");
                                $otherUnitsStmt->bind_param("ii", $actualProductId, $unitId);
                                $otherUnitsStmt->execute();
                                $otherUnitsResult = $otherUnitsStmt->get_result();
                                
                                while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                                    $otherUnitId = $otherUnit['id'];
                                    $otherUnitRatio = floatval($otherUnit['conversion_ratio'] ?? 1.0);
                                    if ($otherUnitRatio <= 0) {
                                        writeLog("Warning: skipped stock sync for unit_id {$otherUnitId}: invalid conversion_ratio");
                                        continue;
                                    }
                                    $deductionAmount = $quantity * ($soldUnitRatio / $otherUnitRatio);
                                    
                                    $updateOtherUnitStmt = $conn->prepare("
                                        UPDATE product_units 
                                        SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                                        WHERE id = ?
                                    ");
                                    $updateOtherUnitStmt->bind_param("di", $deductionAmount, $otherUnitId);
                                    
                                    if (!$updateOtherUnitStmt->execute()) {
                                        writeLog("Warning: Failed to update other unit stock for unit_id: {$otherUnitId}");
                                    }
                                    $updateOtherUnitStmt->close();
                                }
                                $otherUnitsStmt->close();
                            }
                        } elseif ($productId) {
                            // Product without units
                            $updateStockStmt = $conn->prepare("
                                UPDATE product_units
                                SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                                WHERE id = (
                                    SELECT pux.id FROM (
                                        SELECT pu.id FROM product_units pu
                                        INNER JOIN products p ON p.id = pu.product_id
                                        WHERE pu.product_id = ? AND p.user_id = ?
                                        ORDER BY pu.is_primary DESC, pu.id ASC
                                        LIMIT 1
                                    ) AS pux
                                )
                            ");
                            $updateStockStmt->bind_param("dii", $quantity, $productId, $userId);
                            
                            if (!$updateStockStmt->execute()) {
                                throw new Exception('نەتوانرا بڕی کاڵا نوێ بکرێتەوە');
                            }
                            $updateStockStmt->close();
                        }
                    }
                }
                
                // Handle payment method specific actions
                if ($paymentMethod === 'cash' && $customerId) {
                    $cashPurchaseStmt = $conn->prepare("
                        INSERT INTO customer_cash_purchases (
                            user_id, customer_id, sale_id, invoice_number, 
                            total_amount, discount, final_amount, purchase_date
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");

                    $cashPurchaseStmt->bind_param(
                        "iiisddd",
                        $userId, $customerId, $saleId, $invoiceNumber,
                        $totalAmount, $discount, $finalAmount
                    );

                    if (!$cashPurchaseStmt->execute()) {
                        throw new Exception('نەتوانرا کڕینی کاش تۆمار بکرێت: ' . $cashPurchaseStmt->error);
                    }
                    $cashPurchaseStmt->close();
                } elseif ($paymentMethod === 'credit') {
                    $debtStmt = $conn->prepare("
                        INSERT INTO debts (
                            user_id, customer_id, sale_id, customer_name, customer_phone, total_debt, 
                            paid_amount, remaining_amount, debt_type, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'credit', 'active', NOW())
                    ");

                    $initialPaidAmount = $finalAmount - $remainingAmount;
                    $debtStmt->bind_param(
                        "iiissddd",
                        $userId, $customerId, $saleId, $customerName, $customerPhone,
                        $finalAmount, $initialPaidAmount, $remainingAmount
                    );

                    if (!$debtStmt->execute()) {
                        throw new Exception('نەتوانرا قەرز تۆمار بکرێت: ' . $debtStmt->error);
                    }
                    $debtStmt->close();

                    if ($customerId) {
                        $updateCustomerDebt = $conn->prepare("
                            UPDATE customers 
                            SET total_debt = (
                                SELECT COALESCE(SUM(remaining_amount), 0) 
                                FROM debts 
                                WHERE customer_id = ? AND status = 'active'
                            )
                            WHERE id = ?
                        ");
                        $updateCustomerDebt->bind_param("ii", $customerId, $customerId);
                        $updateCustomerDebt->execute();
                        $updateCustomerDebt->close();
                    }
                }
                
                // Update order status
                $updateStmt = $conn->prepare("UPDATE web_orders SET status = 'completed', completed_at = NOW() WHERE id = ? AND user_id = ?");
                $updateStmt->bind_param("ii", $orderId, $userId);
                
                if (!$updateStmt->execute()) {
                    throw new Exception('نەتوانرا دۆخی داواکاری نوێ بکرێتەوە');
                }
                $updateStmt->close();
                
                // Commit transaction
                $conn->commit();
                
                writeLog("Order #{$orderId} completed with customer #{$customerId} and payment method {$paymentMethod} by user {$currentUser['email']}");
                
                // Redirect based on payment method
                if ($paymentMethod === 'cash') {
                    header('Location: ' . url('user/customers/cash_purchases.php') . '?success=1');
                    exit;
                } else {
                    header('Location: ' . url('user/customers/credit_sales.php') . '?success=1');
                    exit;
                }
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error = $e->getMessage();
                writeLog("Error completing order #{$orderId}: " . $e->getMessage());
            }
        }
    } elseif ($_POST['action'] === 'mark_pending') {
        $orderId = intval($_POST['order_id']);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get order details first
            $orderStmt = $conn->prepare("SELECT items, status FROM web_orders WHERE id = ? AND user_id = ?");
            $orderStmt->bind_param("ii", $orderId, $userId);
            $orderStmt->execute();
            $orderResult = $orderStmt->get_result();
            $order = $orderResult->fetch_assoc();
            $orderStmt->close();
            
            if (!$order) {
                throw new Exception('داواکارییەکە نەدۆزرایەوە');
            }
            
            // Only restore stock if order was completed
            if ($order['status'] === 'completed') {
                // Parse items JSON
                $items = json_decode($order['items'], true);
                
                if ($items && is_array($items)) {
                    // Restore stock for each item
                    foreach ($items as $item) {
                        $quantity = floatval($item['quantity'] ?? 0);
                        $productId = isset($item['id']) ? intval($item['id']) : null;
                        $unitId = isset($item['unitId']) ? intval($item['unitId']) : null;
                        
                        if ($quantity <= 0) {
                            continue;
                        }
                        
                        // If product has unitId, it means it has units
                        if ($unitId) {
                            // Get product_id and unit_id from product_units table
                            $unitInfoStmt = $conn->prepare("
                                SELECT product_id, unit_id, conversion_ratio 
                                FROM product_units 
                                WHERE id = ? AND product_id IN (SELECT id FROM products WHERE user_id = ?)
                            ");
                            $unitInfoStmt->bind_param("ii", $unitId, $userId);
                            $unitInfoStmt->execute();
                            $unitInfoResult = $unitInfoStmt->get_result();
                            $unitInfo = $unitInfoResult->fetch_assoc();
                            $unitInfoStmt->close();
                            
                            if (!$unitInfo) {
                                throw new Exception('یەکەی کاڵا نەدۆزرایەوە');
                            }
                            
                            $actualProductId = $unitInfo['product_id'];
                            $soldUnitRatio = floatval($unitInfo['conversion_ratio'] ?? 1.0);
                            
                            // First, restore the sold unit's stock
                            $restoreStockStmt = $conn->prepare("
                                UPDATE product_units 
                                SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                                WHERE id = ?
                            ");
                            $restoreStockStmt->bind_param("di", $quantity, $unitId);
                            
                            if (!$restoreStockStmt->execute()) {
                                throw new Exception('نەتوانرا بڕی کاڵا گەڕێندرێتەوە');
                            }
                            $restoreStockStmt->close();
                            
                            // Now restore other units of the same product using conversion ratio
                            $otherUnitsStmt = $conn->prepare("
                                SELECT id, unit_id, conversion_ratio
                                FROM product_units
                                WHERE product_id = ? AND id != ?
                            ");
                            $otherUnitsStmt->bind_param("ii", $actualProductId, $unitId);
                            $otherUnitsStmt->execute();
                            $otherUnitsResult = $otherUnitsStmt->get_result();
                            
                            // Restore each other unit based on conversion ratio
                            while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                                $otherUnitId = $otherUnit['id'];
                                $otherUnitRatio = floatval($otherUnit['conversion_ratio'] ?? 1.0);
                                if ($otherUnitRatio <= 0) {
                                    writeLog("Warning: skipped stock restore for unit_id {$otherUnitId}: invalid conversion_ratio");
                                    continue;
                                }

                                // Calculate how much to restore to this unit
                                // Formula: sold_quantity * (sold_unit_ratio / other_unit_ratio)
                                $restoreAmount = $quantity * ($soldUnitRatio / $otherUnitRatio);
                                
                                // Restore the other unit's stock
                                $restoreOtherUnitStmt = $conn->prepare("
                                    UPDATE product_units 
                                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                                    WHERE id = ?
                                ");
                                $restoreOtherUnitStmt->bind_param("di", $restoreAmount, $otherUnitId);
                                
                                if (!$restoreOtherUnitStmt->execute()) {
                                    // Log error but don't fail the transaction
                                    writeLog("Warning: Failed to restore other unit stock for unit_id: {$otherUnitId}");
                                }
                                $restoreOtherUnitStmt->close();
                            }
                            $otherUnitsStmt->close();
                        } elseif ($productId) {
                            // Product without units - restore products table directly
                            $restoreStockStmt = $conn->prepare("
                                UPDATE product_units
                                SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                                WHERE id = (
                                    SELECT pux.id FROM (
                                        SELECT pu.id FROM product_units pu
                                        INNER JOIN products p ON p.id = pu.product_id
                                        WHERE pu.product_id = ? AND p.user_id = ?
                                        ORDER BY pu.is_primary DESC, pu.id ASC
                                        LIMIT 1
                                    ) AS pux
                                )
                            ");
                            $restoreStockStmt->bind_param("dii", $quantity, $productId, $userId);
                            
                            if (!$restoreStockStmt->execute()) {
                                throw new Exception('نەتوانرا بڕی کاڵا گەڕێندرێتەوە');
                            }
                            $restoreStockStmt->close();
                        }
                    }
                }
            }
            
            // Update order status
            $updateStmt = $conn->prepare("UPDATE web_orders SET status = 'pending', completed_at = NULL WHERE id = ? AND user_id = ?");
            $updateStmt->bind_param("ii", $orderId, $userId);
            
            if (!$updateStmt->execute()) {
                throw new Exception('نەتوانرا دۆخی داواکاری نوێ بکرێتەوە');
            }
            $updateStmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $success = 'داواکارییەکە گەڕایەوە بۆ ناردراوەکان و بڕی کاڵاکان گەڕێندرایەوە';
            writeLog("Order #{$orderId} marked as pending and stock restored by user {$currentUser['email']}");
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = $e->getMessage();
            writeLog("Error reverting order #{$orderId} to pending: " . $e->getMessage());
        }
    } elseif ($_POST['action'] === 'cancel_order') {
        $orderId = intval($_POST['order_id']);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get order details first
            $orderStmt = $conn->prepare("SELECT items, status FROM web_orders WHERE id = ? AND user_id = ?");
            $orderStmt->bind_param("ii", $orderId, $userId);
            $orderStmt->execute();
            $orderResult = $orderStmt->get_result();
            $order = $orderResult->fetch_assoc();
            $orderStmt->close();
            
            if (!$order) {
                throw new Exception('داواکارییەکە نەدۆزرایەوە');
            }
            
            // If order was completed, restore stock
            if ($order['status'] === 'completed') {
                // Parse items JSON
                $items = json_decode($order['items'], true);
                
                if ($items && is_array($items)) {
                    // Restore stock for each item
                    foreach ($items as $item) {
                        $quantity = floatval($item['quantity'] ?? 0);
                        $productId = isset($item['id']) ? intval($item['id']) : null;
                        $unitId = isset($item['unitId']) ? intval($item['unitId']) : null;
                        
                        if ($quantity <= 0) {
                            continue;
                        }
                        
                        // If product has unitId, it means it has units
                        if ($unitId) {
                            // Get product_id and unit_id from product_units table
                            $unitInfoStmt = $conn->prepare("
                                SELECT product_id, unit_id, conversion_ratio 
                                FROM product_units 
                                WHERE id = ? AND product_id IN (SELECT id FROM products WHERE user_id = ?)
                            ");
                            $unitInfoStmt->bind_param("ii", $unitId, $userId);
                            $unitInfoStmt->execute();
                            $unitInfoResult = $unitInfoStmt->get_result();
                            $unitInfo = $unitInfoResult->fetch_assoc();
                            $unitInfoStmt->close();
                            
                            if (!$unitInfo) {
                                throw new Exception('یەکەی کاڵا نەدۆزرایەوە');
                            }
                            
                            $actualProductId = $unitInfo['product_id'];
                            $soldUnitRatio = floatval($unitInfo['conversion_ratio'] ?? 1.0);
                            
                            // First, restore the sold unit's stock
                            $restoreStockStmt = $conn->prepare("
                                UPDATE product_units 
                                SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                                WHERE id = ?
                            ");
                            $restoreStockStmt->bind_param("di", $quantity, $unitId);
                            
                            if (!$restoreStockStmt->execute()) {
                                throw new Exception('نەتوانرا بڕی کاڵا گەڕێندرێتەوە');
                            }
                            $restoreStockStmt->close();
                            
                            // Now restore other units of the same product using conversion ratio
                            $otherUnitsStmt = $conn->prepare("
                                SELECT id, unit_id, conversion_ratio
                                FROM product_units
                                WHERE product_id = ? AND id != ?
                            ");
                            $otherUnitsStmt->bind_param("ii", $actualProductId, $unitId);
                            $otherUnitsStmt->execute();
                            $otherUnitsResult = $otherUnitsStmt->get_result();
                            
                            // Restore each other unit based on conversion ratio
                            while ($otherUnit = $otherUnitsResult->fetch_assoc()) {
                                $otherUnitId = $otherUnit['id'];
                                $otherUnitRatio = floatval($otherUnit['conversion_ratio'] ?? 1.0);
                                if ($otherUnitRatio <= 0) {
                                    writeLog("Warning: skipped stock restore for unit_id {$otherUnitId}: invalid conversion_ratio");
                                    continue;
                                }

                                // Calculate how much to restore to this unit
                                // Formula: sold_quantity * (sold_unit_ratio / other_unit_ratio)
                                $restoreAmount = $quantity * ($soldUnitRatio / $otherUnitRatio);
                                
                                // Restore the other unit's stock
                                $restoreOtherUnitStmt = $conn->prepare("
                                    UPDATE product_units 
                                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                                    WHERE id = ?
                                ");
                                $restoreOtherUnitStmt->bind_param("di", $restoreAmount, $otherUnitId);
                                
                                if (!$restoreOtherUnitStmt->execute()) {
                                    // Log error but don't fail the transaction
                                    writeLog("Warning: Failed to restore other unit stock for unit_id: {$otherUnitId}");
                                }
                                $restoreOtherUnitStmt->close();
                            }
                            $otherUnitsStmt->close();
                        } elseif ($productId) {
                            // Product without units - restore products table directly
                            $restoreStockStmt = $conn->prepare("
                                UPDATE product_units
                                SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                                WHERE id = (
                                    SELECT pux.id FROM (
                                        SELECT pu.id FROM product_units pu
                                        INNER JOIN products p ON p.id = pu.product_id
                                        WHERE pu.product_id = ? AND p.user_id = ?
                                        ORDER BY pu.is_primary DESC, pu.id ASC
                                        LIMIT 1
                                    ) AS pux
                                )
                            ");
                            $restoreStockStmt->bind_param("dii", $quantity, $productId, $userId);
                            
                            if (!$restoreStockStmt->execute()) {
                                throw new Exception('نەتوانرا بڕی کاڵا گەڕێندرێتەوە');
                            }
                            $restoreStockStmt->close();
                        }
                    }
                }
            }
            
            // Update order status to cancelled
            $updateStmt = $conn->prepare("UPDATE web_orders SET status = 'cancelled' WHERE id = ? AND user_id = ?");
            $updateStmt->bind_param("ii", $orderId, $userId);
            
            if (!$updateStmt->execute()) {
                throw new Exception('نەتوانرا داواکاری بسڕێتەوە');
            }
            $updateStmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $success = 'داواکارییەکە سڕایەوە و بڕی کاڵاکان گەڕێندرایەوە';
            writeLog("Order #{$orderId} cancelled and stock restored by user {$currentUser['email']}");
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = $e->getMessage();
            writeLog("Error cancelling order #{$orderId}: " . $e->getMessage());
        }
    } elseif ($_POST['action'] === 'delete_order') {
        $orderId = intval($_POST['order_id']);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get order details first - verify ownership and status
            $orderStmt = $conn->prepare("SELECT status, order_number FROM web_orders WHERE id = ? AND user_id = ?");
            $orderStmt->bind_param("ii", $orderId, $userId);
            $orderStmt->execute();
            $orderResult = $orderStmt->get_result();
            $order = $orderResult->fetch_assoc();
            $orderStmt->close();
            
            if (!$order) {
                throw new Exception('داواکارییەکە نەدۆزرایەوە');
            }
            
            // Only allow deletion of cancelled orders
            if ($order['status'] !== 'cancelled') {
                throw new Exception('تەنها دەتوانیت داواکاریە هەڵوەشاوەکان بسڕیتەوە');
            }
            
            // Delete the order
            $deleteStmt = $conn->prepare("DELETE FROM web_orders WHERE id = ? AND user_id = ? AND status = 'cancelled'");
            $deleteStmt->bind_param("ii", $orderId, $userId);
            
            if (!$deleteStmt->execute()) {
                throw new Exception('نەتوانرا داواکاری بسڕێتەوە');
            }
            $deleteStmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $success = 'داواکارییەکە بە سەرکەوتوویی سڕایەوە';
            writeLog("Order #{$orderId} ({$order['order_number']}) permanently deleted by user {$currentUser['email']}");
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = $e->getMessage();
            writeLog("Error deleting order #{$orderId}: " . $e->getMessage());
        }
    }
}

// Get filter
$statusFilter = $_GET['status'] ?? 'pending';
$statusFilter = in_array($statusFilter, ['pending', 'completed', 'cancelled']) ? $statusFilter : 'pending';

// Get orders
$ordersStmt = $conn->prepare("
    SELECT * FROM web_orders 
    WHERE user_id = ? AND status = ?
    ORDER BY created_at DESC
");
$ordersStmt->bind_param("is", $userId, $statusFilter);
$ordersStmt->execute();
$result = $ordersStmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);
$ordersStmt->close();

$duplicateOfByOrderId = [];
if ($statusFilter === 'pending' && !empty($orders)) {
    $completedStmt = $conn->prepare("
        SELECT id, order_number, customer_phone, items, created_at
        FROM web_orders
        WHERE user_id = ? AND status = 'completed'
        ORDER BY created_at DESC
    ");
    $completedStmt->bind_param('i', $userId);
    $completedStmt->execute();
    $completedRows = $completedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $completedStmt->close();

    $completedByPhone = web_order_build_completed_by_phone_index($completedRows);

    foreach ($orders as $pendingOrder) {
        $pendingId = (int) ($pendingOrder['id'] ?? 0);
        $match = web_order_find_duplicate_of($pendingOrder, $completedByPhone);
        if ($match !== null) {
            $duplicateOfByOrderId[$pendingId] = $match;
        }
    }
}

// Get counts for tabs
$pendingCount = 0;
$completedCount = 0;
$cancelledCount = 0;

$countStmt = $conn->prepare("
    SELECT status, COUNT(*) as count 
    FROM web_orders 
    WHERE user_id = ? 
    GROUP BY status
");
$countStmt->bind_param("i", $userId);
$countStmt->execute();
$countResult = $countStmt->get_result();
while ($row = $countResult->fetch_assoc()) {
    if ($row['status'] === 'pending') {
        $pendingCount = $row['count'];
    } elseif ($row['status'] === 'completed') {
        $completedCount = $row['count'];
    } elseif ($row['status'] === 'cancelled') {
        $cancelledCount = $row['count'];
    }
}
$countStmt->close();

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>داواکارییەکان - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.2);
            --border-radius: 16px;
            --border-radius-sm: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --page-bg: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            --panel-bg: #ffffff;
            --panel-muted-bg: #f9fafb;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-soft: #e5e7eb;
            --item-bg: #ffffff;
            --item-hover-bg: #f8f9fa;
            --notes-bg: #f3f4f6;
        }

        [data-bs-theme="dark"],
        body.dark-mode {
            --page-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --panel-bg: #1e293b;
            --panel-muted-bg: #334155;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --border-soft: #475569;
            --item-bg: #1f2937;
            --item-hover-bg: #334155;
            --notes-bg: #334155;
            --shadow-sm: 0 2px 6px rgba(0, 0, 0, 0.35);
            --shadow-md: 0 10px 20px rgba(0, 0, 0, 0.35);
        }

        body {
            background: var(--page-bg);
            min-height: 100vh;
            color: var(--text-primary);
        }

        /* Header Section */
        .page-header {
            background: var(--panel-bg);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }

        /* Tabs Design */
        .nav-tabs {
            border: none;
            background: var(--panel-bg);
            border-radius: var(--border-radius-sm);
            padding: 0.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
        }

        .nav-tabs .nav-link {
            border: none;
            color: var(--text-secondary);
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            margin: 0 0.25rem;
            font-weight: 500;
            position: relative;
        }

        .nav-tabs .nav-link:hover {
            color: var(--text-primary);
            background: var(--panel-muted-bg);
        }

        .nav-tabs .nav-link.active {
            color: white;
            background: var(--primary-color);
            box-shadow: var(--shadow-sm);
        }

        .nav-tabs .nav-link i {
            margin-left: 0.5rem;
        }

        .badge-count {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 0.5rem;
        }

        .nav-tabs .nav-link:not(.active) .badge-count {
            background: #9ca3af;
            color: white;
        }

        /* Order Cards */
        .order-card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            margin-bottom: 1.5rem;
            overflow: hidden;
            background: var(--panel-bg);
        }
        
        .order-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .order-card.is-duplicate {
            box-shadow: 0 0 0 2px #dc2626, var(--shadow-md);
        }

        .duplicate-order-alert {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding: 0.5rem 0.75rem;
            background: #dc2626;
            color: #ffffff;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            line-height: 1.4;
            position: relative;
            z-index: 1;
        }

        .duplicate-order-alert i {
            flex-shrink: 0;
            font-size: 1rem;
        }
        
        .order-header {
            background: var(--primary-color);
            color: white;
            padding: 1.25rem;
        }
        
        .order-number {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .order-date {
            font-size: 0.875rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }
        
        .order-body {
            padding: 1.5rem;
        }
        
        .customer-info {
            background: var(--panel-muted-bg);
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 1.25rem;
            border: 1px solid var(--border-soft);
        }

        .customer-info-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }

        .customer-info-item:last-child {
            margin-bottom: 0;
        }

        .customer-info-item i {
            width: 24px;
            margin-left: 0.75rem;
            color: #667eea;
        }

        .customer-info-item a {
            color: #059669;
            text-decoration: none;
            transition: var(--transition);
        }

        .customer-info-item a:hover {
            color: #047857;
            text-decoration: underline;
        }
        
        .items-section {
            margin-bottom: 1.25rem;
        }

        .items-section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
        }

        .items-section-title i {
            margin-left: 0.5rem;
            color: var(--text-secondary);
        }
        
        .items-list {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--border-soft);
            border-radius: var(--border-radius-sm);
            padding: 0.5rem;
            background: var(--panel-muted-bg);
        }

        .items-list::-webkit-scrollbar {
            width: 6px;
        }

        .items-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .items-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .items-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        .item-row {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-soft);
            background: var(--item-bg);
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: var(--transition);
        }

        .item-row:hover {
            background: var(--item-hover-bg);
            transform: translateX(-4px);
        }
        
        .item-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .item-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .item-details {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .item-price {
            font-weight: 700;
            color: #059669;
            font-size: 1.05rem;
        }
        
        .order-total {
            background: var(--success-gradient);
            color: white;
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            font-size: 1.2rem;
            font-weight: 700;
            text-align: center;
            box-shadow: var(--shadow-md);
            margin-bottom: 1.25rem;
        }

        .order-notes {
            background: var(--notes-bg);
            border: 1px solid var(--border-soft);
            border-radius: var(--border-radius-sm);
            padding: 0.875rem;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .order-notes i {
            margin-left: 0.5rem;
            color: var(--text-secondary);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .btn-action {
            border-radius: var(--border-radius-sm);
            padding: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-action i {
            font-size: 1.1rem;
        }

        .btn-view-pdf {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .btn-view-pdf:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
        }

        .btn-complete {
            background: var(--success-gradient);
            color: white;
        }

        .btn-complete:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
        }

        .btn-pending {
            background: var(--warning-gradient);
            color: white;
        }

        .btn-pending:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            color: white;
        }

        .btn-cancel {
            background: var(--danger-gradient);
            color: white;
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-align: center;
        }

        .status-cancelled {
            background: var(--panel-muted-bg);
            color: var(--text-secondary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--panel-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
        }

        .empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }

        .empty-state h4 {
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: #94a3b8;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem;
            }

            .nav-tabs .nav-link {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }

            .order-body {
                padding: 1rem;
            }

            .order-header {
                padding: 1rem;
            }
        }

        /* Customer Search Styles */
        .customer-search-wrapper {
            position: relative;
        }

        .customer-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--panel-bg);
            border: 1px solid var(--border-soft);
            border-radius: var(--border-radius-sm);
            box-shadow: var(--shadow-lg);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            margin-top: 0.25rem;
        }

        .customer-search-results.show {
            display: block;
        }

        .customer-search-result-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-soft);
            transition: var(--transition);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .customer-search-result-item:hover,
        .customer-search-result-item.selected {
            background: var(--panel-muted-bg);
            border-right: 3px solid var(--primary-color);
        }

        .customer-search-result-item:last-child {
            border-bottom: none;
        }

        .customer-search-result-item .customer-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .customer-search-result-item .customer-details {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .customer-search-result-item .customer-phone {
            color: #059669;
        }

        [data-bs-theme="dark"] .modal-content,
        body.dark-mode .modal-content {
            background: var(--panel-bg);
            color: var(--text-primary);
            border-color: var(--border-soft);
        }

        [data-bs-theme="dark"] .form-control,
        [data-bs-theme="dark"] .form-select,
        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background: #0f172a;
            color: var(--text-primary);
            border-color: var(--border-soft);
        }

        [data-bs-theme="dark"] .form-control::placeholder,
        body.dark-mode .form-control::placeholder {
            color: #94a3b8;
        }

        [data-bs-theme="dark"] .alert-info,
        body.dark-mode .alert-info {
            background: #1e3a5f;
            color: #dbeafe;
            border-color: #2563eb;
        }

        [data-bs-theme="dark"] .text-muted,
        body.dark-mode .text-muted {
            color: var(--text-secondary) !important;
        }

        .selected-customer-display {
            animation: fadeIn 0.3s ease-in;
        }

        .selected-customer-display .alert {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .customer-search-results::-webkit-scrollbar {
            width: 6px;
        }

        .customer-search-results::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .customer-search-results::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .customer-search-results::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="website-module-page website-orders-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>

<div class="container-fluid py-4 hub-page-content">
        
        <!-- Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="mb-2 fw-bold" style="color: var(--text-primary);">
                        <i class="bi bi-receipt-cutoff text-primary"></i>
                        داواکارییەکانی فرۆشگای ئۆنلاین
                    </h2>
                    <p class="text-muted mb-0">
                        <i class="bi bi-info-circle"></i>
                        بەڕێوەبردنی داواکارییەکانی کڕیاران
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button"
                            class="btn btn-outline-secondary btn-lg"
                            data-bs-toggle="modal"
                            data-bs-target="#orderCompletionSettingsModal">
                        <i class="bi bi-gear"></i> ڕێکخستنی تەواوکردنی وەسڵ
                    </button>
                    <?php if ($websiteSettings['is_active']): ?>
                        <a href="<?php echo SITE_URL; ?>web/shop.php?slug=<?php echo $websiteSettings['website_slug']; ?>" 
                           target="_blank" class="btn btn-primary btn-lg">
                            <i class="bi bi-eye"></i> بینینی فرۆشگا
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" 
                   href="?status=pending">
                    <i class="bi bi-hourglass-split"></i>
                    داواکاریە ناردراوەکان
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge-count"><?php echo $pendingCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>" 
                   href="?status=completed">
                    <i class="bi bi-check-circle"></i>
                    داواکاریە تەواو بووەکان
                    <?php if ($completedCount > 0): ?>
                        <span class="badge-count"><?php echo $completedCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $statusFilter === 'cancelled' ? 'active' : ''; ?>" 
                   href="?status=cancelled">
                    <i class="bi bi-x-circle"></i>
                    داواکاریە هەڵوەشاوەکان
                    <?php if ($cancelledCount > 0): ?>
                        <span class="badge-count"><?php echo $cancelledCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <!-- Orders List -->
        <div class="row">
            <?php if (empty($orders)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <h4>هیچ داواکارییەک نییە</h4>
                        <p>
                            <?php if ($statusFilter === 'pending'): ?>
                                هیچ داواکارییەکی نوێ نییە
                            <?php elseif ($statusFilter === 'completed'): ?>
                                هیچ داواکارییەکی تەواو بوو نییە
                            <?php else: ?>
                                هیچ داواکارییەکی هەڵوەشاوە نییە
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $order): 
                    $items = json_decode($order['items'], true);
                    $orderDate = date('Y/m/d H:i', strtotime($order['created_at']));
                    $orderId = (int) ($order['id'] ?? 0);
                    $duplicateOf = $duplicateOfByOrderId[$orderId] ?? null;
                    $isDuplicateOrder = $duplicateOf !== null;
                ?>
                <div class="col-lg-6 col-xl-4">
                    <div class="order-card<?php echo $isDuplicateOrder ? ' is-duplicate' : ''; ?>">
                        <div class="order-header">
                            <div class="order-number">
                                <i class="bi bi-receipt"></i>
                                <?php echo htmlspecialchars($order['order_number']); ?>
                            </div>
                            <div class="order-date">
                                <i class="bi bi-calendar3"></i>
                                <?php echo $orderDate; ?>
                            </div>
                            <?php if ($isDuplicateOrder): ?>
                            <div class="duplicate-order-alert" role="status">
                                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                <span>ئەم وەسلە دووبارەیە — <?php echo htmlspecialchars($duplicateOf['order_number']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="order-body">
                            <!-- Customer Info -->
                            <div class="customer-info">
                                <div class="customer-info-item">
                                    <i class="bi bi-person-fill"></i>
                                    <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong>
                                </div>
                                <div class="customer-info-item">
                                    <i class="bi bi-telephone-fill"></i>
                                    <a href="tel:<?php echo htmlspecialchars($order['customer_phone']); ?>">
                                        <?php echo htmlspecialchars($order['customer_phone']); ?>
                                    </a>
                                </div>
                                <?php if (!empty($order['customer_address'])): ?>
                                <div class="customer-info-item">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <span><?php echo htmlspecialchars($order['customer_address']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Items -->
                            <div class="items-section">
                                <div class="items-section-title">
                                    <i class="bi bi-box-seam"></i>
                                    کاڵاکان
                                </div>
                                <div class="items-list">
                                    <?php foreach ($items as $item): 
                                        $itemSubtotal = $item['price'] * $item['quantity'];
                                    ?>
                                    <div class="item-row">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                <div class="item-details">
                                                    <?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?> × 
                                                    <?php echo number_format($item['price'], 0); ?> دینار
                                                </div>
                                            </div>
                                            <div class="item-price">
                                                <?php echo number_format($itemSubtotal, 0); ?> دینار
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($order['notes'])): ?>
                            <div class="order-notes">
                                <i class="bi bi-chat-left-text"></i>
                                <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Total -->
                            <div class="order-total">
                                <i class="bi bi-currency-exchange"></i>
                                کۆی گشتی: <?php echo number_format($order['total_amount'], 0); ?> دینار
                            </div>
                            
                            <!-- Actions -->
                            <div class="action-buttons">
                                <div class="d-flex gap-2">
                                    <a href="<?php echo url('user/website/order_receipt.php'); ?>?id=<?php echo $order['id']; ?>&print=1" 
                                       target="_blank" class="btn btn-action btn-view-pdf flex-fill">
                                        <i class="bi bi-receipt"></i>
                                        <span>بینینی وەسڵی کاشێر</span>
                                    </a>

                                    <a href="<?php echo SITE_URL; ?>web/api/generate_order_pdf.php?order_id=<?php echo $order['id']; ?>" 
                                       target="_blank" class="btn btn-action btn-view-pdf flex-fill">
                                        <i class="bi bi-file-pdf"></i>
                                        <span>وەسڵی A4</span>
                                    </a>
                                </div>
                                
                                <?php if ($order['status'] === 'pending'): ?>
                                <button type="button" class="btn btn-action btn-complete w-100 complete-order-btn" 
                                        data-order-id="<?php echo $order['id']; ?>"
                                        data-order-number="<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES); ?>"
                                        data-order-total="<?php echo $order['total_amount']; ?>"
                                        data-customer-name="<?php echo htmlspecialchars($order['customer_name'], ENT_QUOTES); ?>"
                                        data-customer-phone="<?php echo htmlspecialchars($order['customer_phone'] ?? '', ENT_QUOTES); ?>">
                                    <i class="bi bi-check-circle"></i>
                                    <span>تەواوکردنی وەسڵ</span>
                                </button>
                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('دڵنیایت لە هەڵوەشاندنەوەی ئەم وەسڵە؟');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <button type="submit" class="btn btn-action btn-cancel w-100">
                                        <i class="bi bi-x-circle"></i>
                                        <span>هەڵوەشاندنەوەی وەسڵ</span>
                                    </button>
                                </form>
                                <?php elseif ($order['status'] === 'completed'): ?>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="mark_pending">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <button type="submit" class="btn btn-action btn-pending w-100">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                        <span>گەڕاندنەوە بۆ ناردراوەکان</span>
                                    </button>
                                </form>
                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('دڵنیایت لە هەڵوەشاندنەوەی ئەم وەسڵە؟ بڕی کاڵاکان دەگەڕێتەوە.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <button type="submit" class="btn btn-action btn-cancel w-100">
                                        <i class="bi bi-x-circle"></i>
                                        <span>هەڵوەشاندنەوەی وەسڵ</span>
                                    </button>
                                </form>
                                <?php else: ?>
                                <div class="status-badge status-cancelled mb-3">
                                    <i class="bi bi-x-circle"></i>
                                    ئەم وەسڵە هەڵوەشێندراوەتەوە
                                </div>
                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوەی بە تەواوی ئەم داواکارییە؟ ئەم کارە ناگەڕێتەوە.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="delete_order">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <button type="submit" class="btn btn-action btn-cancel w-100">
                                        <i class="bi bi-trash"></i>
                                        <span>سڕینەوەی داواکاری</span>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Order Completion Settings Modal -->
    <div class="modal fade" id="orderCompletionSettingsModal" tabindex="-1" aria-labelledby="orderCompletionSettingsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--panel-bg); color: var(--text-primary);">
                <div class="modal-header">
                    <h5 class="modal-title" id="orderCompletionSettingsModalLabel">
                        <i class="bi bi-gear"></i> ڕێکخستنی تەواوکردنی وەسڵ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">هەڵبژاردنی کڕیار لە کاتی تەواوکردن</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="setting_customer_required" id="setting_customer_required_yes" value="1" <?php echo $orderCustomerRequired ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="setting_customer_required_yes">مەرجە — پێویستە کڕیار لە سیستەم هەڵبژێردرێت</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="setting_customer_required" id="setting_customer_required_no" value="0" <?php echo !$orderCustomerRequired ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="setting_customer_required_no">ئارەزوومەندانە — دەتوانرێت بەبێ هەڵبژاردنی کڕیار تەواو بکرێت</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">شێوەی پارەدانی بنەڕەتی</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="setting_default_payment" id="setting_default_payment_cash" value="0" <?php echo !$orderDefaultPaymentCredit ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="setting_default_payment_cash">
                                    <i class="bi bi-cash-coin text-success"></i> نەخت
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="setting_default_payment" id="setting_default_payment_credit" value="1" <?php echo $orderDefaultPaymentCredit ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="setting_default_payment_credit">
                                    <i class="bi bi-credit-card text-warning"></i> قەرز
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-warning mb-0 py-2">
                        <small><i class="bi bi-info-circle"></i> قەرز هەمیشە پێویستی بە هەڵبژاردنی کڕیار لە سیستەم هەیە، تەنانەت کاتێک هەڵبژاردنی کڕیار ئارەزوومەندانە بێت.</small>
                    </div>
                    <div id="orderCompletionSettingsAlert" class="alert d-none mt-3 mb-0" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                    <button type="button" class="btn btn-primary" id="saveOrderCompletionSettingsBtn">
                        <i class="bi bi-save"></i> پاشەکەوتکردن
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Complete Order Modal -->
    <div class="modal fade" id="completeOrderModal" tabindex="-1" aria-labelledby="completeOrderModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="completeOrderModalLabel">
                        <i class="bi bi-check-circle"></i> تەواوکردنی وەسڵ
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="داخستن"></button>
                </div>
                <form id="completeOrderForm" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="complete_order_with_customer">
                        <input type="hidden" name="order_id" id="modal_order_id">
                        
                        <!-- Order Summary -->
                        <div class="alert alert-info">
                            <h6 class="mb-2"><i class="bi bi-info-circle"></i> زانیاری وەسڵ</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>ژمارەی وەسڵ:</strong> <span id="modal_order_number"></span><br>
                                    <strong>کۆی گشتی:</strong> <span id="modal_order_total"></span>
                                </div>
                                <div class="col-md-6">
                                    <strong>کڕیار:</strong> <span id="modal_customer_name"></span><br>
                                    <strong>تەلەفۆن:</strong> <span id="modal_customer_phone"></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Customer Selection -->
                        <div class="mb-3">
                            <label for="customer_search" class="form-label">
                                کڕیار <span class="text-danger" id="customer_required_marker">*</span>
                                <span class="text-muted small" id="customer_optional_hint" style="display: none;">(ئارەزوومەندانە)</span>
                            </label>
                            <div class="customer-search-wrapper position-relative">
                                <input type="text" 
                                       class="form-control" 
                                       id="customer_search" 
                                       placeholder="گەڕان بە ناو یان تەلەفۆن..."
                                       autocomplete="off">
                                <input type="hidden" id="customer_id" name="customer_id"<?php echo $orderCustomerRequired ? ' required' : ''; ?>>
                                <div id="customer_search_results" class="customer-search-results"></div>
                                <div id="selected_customer_display" class="selected-customer-display mt-2" style="display: none;">
                                    <div class="alert alert-info mb-0 py-2">
                                        <i class="bi bi-person-check"></i>
                                        <strong id="selected_customer_name"></strong>
                                        <span id="selected_customer_phone" class="text-muted"></span>
                                        <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="clear_customer_btn">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted">گەڕان بە ناو یان تەلەفۆنی کڕیار</small>
                        </div>
                        
                        <!-- Payment Method -->
                        <div class="mb-3">
                            <label class="form-label">
                                شێوەی پارەدان <span class="text-danger">*</span>
                            </label>
                            <div class="d-flex gap-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method" id="payment_cash" value="cash" <?php echo !$orderDefaultPaymentCredit ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="payment_cash">
                                        <i class="bi bi-cash-coin text-success"></i> نەقد
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method" id="payment_credit" value="credit" <?php echo $orderDefaultPaymentCredit ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="payment_credit">
                                        <i class="bi bi-credit-card text-warning"></i> قەرز
                                    </label>
                                </div>
                            </div>
                            <small class="text-muted">
                                <span id="payment_method_hint">نەقد: وەسڵ دەچێتە بەشی کڕینەکانی کاش</span>
                            </small>
                        </div>
                        
                        <!-- Notes -->
                        <div class="mb-3">
                            <label for="notes" class="form-label">تێبینی</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="تێبینی دەربارەی وەسڵەکە..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> داخستن
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> تەواوکردنی وەسڵ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    const ORDER_COMPLETION_SETTINGS = {
        customerRequired: <?php echo $orderCustomerRequired ? 'true' : 'false'; ?>,
        defaultPaymentCredit: <?php echo $orderDefaultPaymentCredit ? 'true' : 'false'; ?>
    };
    const ORDER_COMPLETION_CSRF = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
    const ORDER_COMPLETION_SAVE_URL = <?php echo json_encode(url('user/website/ajax/save_order_completion_settings.php'), JSON_UNESCAPED_UNICODE); ?>;

    let customerSearchTimeout = null;
    let currentSearchResults = [];
    let selectedSearchIndex = -1;
    let pendingOrderCustomerPhone = '';
    
    function searchCustomers(query) {
        if (query.length < 2) {
            hideCustomerResults();
            return;
        }
        
        clearTimeout(customerSearchTimeout);
        
        customerSearchTimeout = setTimeout(() => {
            fetch('<?php echo url("user/website/ajax/search_customers.php"); ?>?q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.customers) {
                        displayCustomerResults(data.customers);
                    } else {
                        hideCustomerResults();
                    }
                })
                .catch(error => {
                    console.error('Error searching customers:', error);
                    hideCustomerResults();
                });
        }, 300);
    }
    
    function displayCustomerResults(customers) {
        const resultsContainer = document.getElementById('customer_search_results');
        currentSearchResults = customers;
        selectedSearchIndex = -1;
        
        if (customers.length === 0) {
            resultsContainer.innerHTML = '<div class="customer-search-result-item text-muted text-center p-3">هیچ کڕیارێک نەدۆزرایەوە</div>';
            resultsContainer.classList.add('show');
            return;
        }
        
        let html = '';
        customers.forEach((customer, index) => {
            html += `
                <div class="customer-search-result-item" data-customer-id="${customer.id}" data-index="${index}">
                    <div>
                        <div class="customer-name">${customer.name}</div>
                        <div class="customer-details">
                            ${customer.phone ? '<span class="customer-phone"><i class="bi bi-telephone"></i> ' + customer.phone + '</span>' : ''}
                            ${customer.address ? (customer.phone ? ' | ' : '') + '<span><i class="bi bi-geo-alt"></i> ' + customer.address + '</span>' : ''}
                        </div>
                    </div>
                    <i class="bi bi-arrow-left text-muted"></i>
                </div>
            `;
        });
        
        resultsContainer.innerHTML = html;
        resultsContainer.classList.add('show');
        
        // Add click handlers
        resultsContainer.querySelectorAll('.customer-search-result-item').forEach(item => {
            item.addEventListener('click', function() {
                const customerId = parseInt(this.getAttribute('data-customer-id'));
                const customer = customers.find(c => c.id === customerId);
                if (customer) {
                    selectCustomer(customer);
                }
            });
        });
    }
    
    function hideCustomerResults() {
        const resultsContainer = document.getElementById('customer_search_results');
        resultsContainer.classList.remove('show');
        currentSearchResults = [];
        selectedSearchIndex = -1;
    }
    
    function selectCustomer(customer) {
        document.getElementById('customer_id').value = customer.id;
        document.getElementById('customer_search').value = '';
        document.getElementById('selected_customer_name').textContent = customer.name;
        document.getElementById('selected_customer_phone').textContent = customer.phone ? ' - ' + customer.phone : '';
        document.getElementById('selected_customer_display').style.display = 'block';
        hideCustomerResults();
    }
    
    function clearSelectedCustomer() {
        document.getElementById('customer_id').value = '';
        document.getElementById('customer_search').value = '';
        document.getElementById('selected_customer_display').style.display = 'none';
        hideCustomerResults();
    }
    
    function handleCustomerSearchKeydown(event) {
        const resultsContainer = document.getElementById('customer_search_results');
        if (!resultsContainer.classList.contains('show') || currentSearchResults.length === 0) {
            return;
        }
        
        const items = resultsContainer.querySelectorAll('.customer-search-result-item[data-index]');
        
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                selectedSearchIndex = Math.min(selectedSearchIndex + 1, items.length - 1);
                updateSelectedSearchItem(items);
                break;
                
            case 'ArrowUp':
                event.preventDefault();
                selectedSearchIndex = Math.max(selectedSearchIndex - 1, -1);
                updateSelectedSearchItem(items);
                break;
                
            case 'Enter':
                event.preventDefault();
                if (selectedSearchIndex >= 0 && selectedSearchIndex < currentSearchResults.length) {
                    selectCustomer(currentSearchResults[selectedSearchIndex]);
                }
                break;
                
            case 'Escape':
                hideCustomerResults();
                break;
        }
    }
    
    function updateSelectedSearchItem(items) {
        items.forEach((item, index) => {
            if (index === selectedSearchIndex) {
                item.classList.add('selected');
                item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                item.classList.remove('selected');
            }
        });
    }
    
    function applyOrderCompletionSettings() {
        const customerIdInput = document.getElementById('customer_id');
        const requiredMarker = document.getElementById('customer_required_marker');
        const optionalHint = document.getElementById('customer_optional_hint');
        const cashRadio = document.getElementById('payment_cash');
        const creditRadio = document.getElementById('payment_credit');

        if (customerIdInput) {
            if (ORDER_COMPLETION_SETTINGS.customerRequired) {
                customerIdInput.setAttribute('required', 'required');
            } else {
                customerIdInput.removeAttribute('required');
            }
        }

        if (requiredMarker) {
            requiredMarker.style.display = ORDER_COMPLETION_SETTINGS.customerRequired ? 'inline' : 'none';
        }
        if (optionalHint) {
            optionalHint.style.display = ORDER_COMPLETION_SETTINGS.customerRequired ? 'none' : 'inline';
        }

        if (cashRadio && creditRadio) {
            if (ORDER_COMPLETION_SETTINGS.defaultPaymentCredit) {
                creditRadio.checked = true;
            } else {
                cashRadio.checked = true;
            }
        }

        updatePaymentMethodHint();
    }

    function validateCompleteOrderForm() {
        const customerId = document.getElementById('customer_id')?.value?.trim() || '';
        const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value || 'cash';

        if (ORDER_COMPLETION_SETTINGS.customerRequired && !customerId) {
            alert('تکایە کڕیارێک هەڵبژێرە');
            return false;
        }

        if (paymentMethod === 'credit' && !customerId) {
            alert('بۆ پارەدانی قەرز، پێویستە کڕیارێک هەڵبژێریت');
            return false;
        }

        return true;
    }

    function showOrderCompletionSettingsAlert(message, type) {
        const alertBox = document.getElementById('orderCompletionSettingsAlert');
        if (!alertBox) {
            return;
        }

        alertBox.className = 'alert mt-3 mb-0 alert-' + (type === 'success' ? 'success' : 'danger');
        alertBox.textContent = message;
        alertBox.classList.remove('d-none');
    }

    async function saveOrderCompletionSettings() {
        const saveBtn = document.getElementById('saveOrderCompletionSettingsBtn');
        const customerRequiredInput = document.querySelector('input[name="setting_customer_required"]:checked');
        const defaultPaymentInput = document.querySelector('input[name="setting_default_payment"]:checked');

        if (!customerRequiredInput || !defaultPaymentInput) {
            showOrderCompletionSettingsAlert('تکایە هەموو ڕێکخستنەکان هەڵبژێرە', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('csrf_token', ORDER_COMPLETION_CSRF);
        formData.append('order_complete_customer_required', customerRequiredInput.value);
        formData.append('order_complete_default_payment_credit', defaultPaymentInput.value);

        if (saveBtn) {
            saveBtn.disabled = true;
        }

        try {
            const response = await fetch(ORDER_COMPLETION_SAVE_URL, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'نەتوانرا ڕێکخستنەکان پاشەکەوت بکرێن');
            }

            ORDER_COMPLETION_SETTINGS.customerRequired = !!data.settings?.customerRequired;
            ORDER_COMPLETION_SETTINGS.defaultPaymentCredit = !!data.settings?.defaultPaymentCredit;

            showOrderCompletionSettingsAlert(data.message || 'ڕێکخستنەکان پاشەکەوت کران', 'success');
            applyOrderCompletionSettings();
        } catch (error) {
            showOrderCompletionSettingsAlert(error.message || 'هەڵەیەک ڕوویدا', 'error');
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
            }
        }
    }

    function showCompleteOrderModal(button) {
        const orderId = button.getAttribute('data-order-id');
        const orderNumber = button.getAttribute('data-order-number') || 'N/A';
        const orderTotal = parseFloat(button.getAttribute('data-order-total')) || 0;
        const customerName = button.getAttribute('data-customer-name') || 'N/A';
        const customerPhone = button.getAttribute('data-customer-phone') || 'N/A';
        pendingOrderCustomerPhone = customerPhone && customerPhone !== 'N/A' ? customerPhone.trim() : '';

        document.getElementById('modal_order_id').value = orderId;
        document.getElementById('modal_order_number').textContent = orderNumber;
        document.getElementById('modal_order_total').textContent = formatMoney(orderTotal);
        document.getElementById('modal_customer_name').textContent = customerName;
        document.getElementById('modal_customer_phone').textContent = customerPhone;

        document.getElementById('completeOrderForm').reset();
        document.getElementById('modal_order_id').value = orderId;

        clearSelectedCustomer();
        hideCustomerResults();
        applyOrderCompletionSettings();

        if (pendingOrderCustomerPhone.length >= 2) {
            searchCustomers(pendingOrderCustomerPhone);
        }

        const modal = new bootstrap.Modal(document.getElementById('completeOrderModal'));
        modal.show();
    }
    
    function updatePaymentMethodHint() {
        const cashRadio = document.getElementById('payment_cash');
        const creditRadio = document.getElementById('payment_credit');
        const hint = document.getElementById('payment_method_hint');
        
        if (cashRadio.checked) {
            hint.textContent = 'نەقد: وەسڵ دەچێتە بەشی کڕینەکانی کاش';
        } else if (creditRadio.checked) {
            hint.textContent = 'قەرز: وەسڵ وەک قەرز لەسەر کڕیار تۆمار دەکرێت';
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        applyOrderCompletionSettings();

        const completeOrderModal = document.getElementById('completeOrderModal');
        if (completeOrderModal) {
            completeOrderModal.addEventListener('change', function(e) {
                if (e.target.id === 'payment_cash' || e.target.id === 'payment_credit') {
                    updatePaymentMethodHint();
                }
            });
        }

        const completeOrderForm = document.getElementById('completeOrderForm');
        if (completeOrderForm) {
            completeOrderForm.addEventListener('submit', function(e) {
                if (!validateCompleteOrderForm()) {
                    e.preventDefault();
                }
            });
        }

        const saveSettingsBtn = document.getElementById('saveOrderCompletionSettingsBtn');
        if (saveSettingsBtn) {
            saveSettingsBtn.addEventListener('click', saveOrderCompletionSettings);
        }

        const completeOrderButtons = document.querySelectorAll('.complete-order-btn');
        completeOrderButtons.forEach(button => {
            button.addEventListener('click', function() {
                showCompleteOrderModal(this);
            });
        });
        
        // Customer search input handler
        const customerSearchInput = document.getElementById('customer_search');
        if (customerSearchInput) {
            customerSearchInput.addEventListener('input', function(e) {
                const query = e.target.value.trim();
                searchCustomers(query);
            });
            
            customerSearchInput.addEventListener('keydown', handleCustomerSearchKeydown);
            
            // Hide results when clicking outside
            document.addEventListener('click', function(e) {
                const wrapper = document.querySelector('.customer-search-wrapper');
                if (wrapper && !wrapper.contains(e.target)) {
                    hideCustomerResults();
                }
            });
        }
        
        // Clear customer button handler
        const clearCustomerBtn = document.getElementById('clear_customer_btn');
        if (clearCustomerBtn) {
            clearCustomerBtn.addEventListener('click', function(e) {
                e.preventDefault();
                clearSelectedCustomer();
                document.getElementById('customer_search').focus();
            });
        }
    });
    
    function formatMoney(amount) {
        return new Intl.NumberFormat('ku-IQ').format(amount) + ' دینار';
    }
    </script>

</body>
</html>

