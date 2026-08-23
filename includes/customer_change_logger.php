<?php
/**
 * Central helper for customer/debt change history logging.
 */

require_once __DIR__ . '/../config/kasher_logs/database.php';

if (!function_exists('resolveCustomerChangeActors')) {
    function resolveCustomerChangeActors($currentUser, $fallbackUserId = null)
    {
        $userId = $fallbackUserId !== null ? (int)$fallbackUserId : 0;
        $subUserId = null;

        if (is_array($currentUser) && !empty($currentUser)) {
            $currentId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
            $userType = isset($currentUser['user_type']) ? (string)$currentUser['user_type'] : '';
            $parentId = isset($currentUser['parent_user_id']) ? (int)$currentUser['parent_user_id'] : 0;
            $sessionSubUserId = isset($currentUser['sub_user_id']) ? (int)$currentUser['sub_user_id'] : 0;

            if ($userType === 'sub') {
                if ($sessionSubUserId > 0) {
                    $subUserId = $sessionSubUserId;
                } elseif ($currentId > 0 && $parentId > 0 && $currentId !== $parentId) {
                    $subUserId = $currentId;
                }

                if ($parentId > 0) {
                    $userId = $parentId;
                } elseif ($currentId > 0) {
                    $userId = $currentId;
                } elseif ($fallbackUserId !== null) {
                    $userId = (int)$fallbackUserId;
                }
            } else {
                $userId = $currentId > 0 ? $currentId : (int)$userId;
            }
        }

        return [
            'user_id' => $userId,
            'sub_user_id' => $subUserId
        ];
    }
}

if (!function_exists('safeJsonEncodeCustomerChange')) {
    function safeJsonEncodeCustomerChange($data)
    {
        if ($data === null) {
            return null;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}

if (!function_exists('getCustomerDebtSummaryForLogs')) {
    function getCustomerDebtSummaryForLogs($conn, $userId, $customerId)
    {
        $summary = [
            'active_debt_iqd' => 0.0,
            'active_debt_usd' => 0.0,
            'active_debt_total' => 0.0,
            'active_debt_count' => 0
        ];

        if (!$conn || $userId <= 0 || $customerId <= 0) {
            return $summary;
        }

        $stmt = $conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) AS debt_iqd,
                COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) AS debt_usd,
                COUNT(*) AS debt_count
            FROM debts d
            LEFT JOIN sales s ON s.id = d.sale_id
            WHERE d.user_id = ? AND d.customer_id = ? AND d.status = 'active'
        ");
        if (!$stmt) {
            return $summary;
        }
        $stmt->bind_param("ii", $userId, $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $summary['active_debt_iqd'] = (float)($row['debt_iqd'] ?? 0);
        $summary['active_debt_usd'] = (float)($row['debt_usd'] ?? 0);
        $summary['active_debt_total'] = $summary['active_debt_iqd'] + $summary['active_debt_usd'];
        $summary['active_debt_count'] = (int)($row['debt_count'] ?? 0);

        return $summary;
    }
}

if (!function_exists('getCustomerMoneyDebtSummaryForLogs')) {
    function getCustomerMoneyDebtSummaryForLogs($conn, $userId, $customerId)
    {
        $summary = [
            'money_debt_iqd' => 0.0,
            'money_debt_usd' => 0.0
        ];

        if (!$conn || $userId <= 0 || $customerId <= 0) {
            return $summary;
        }

        $stmt = $conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN currency = 'IQD' AND type = 'debt' THEN amount WHEN currency = 'IQD' AND type = 'payment' THEN -amount ELSE 0 END), 0) AS balance_iqd,
                COALESCE(SUM(CASE WHEN currency = 'USD' AND type = 'debt' THEN amount WHEN currency = 'USD' AND type = 'payment' THEN -amount ELSE 0 END), 0) AS balance_usd
            FROM customer_money_debts
            WHERE user_id = ? AND customer_id = ?
        ");
        if (!$stmt) {
            return $summary;
        }
        $stmt->bind_param("ii", $userId, $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $summary['money_debt_iqd'] = (float)($row['balance_iqd'] ?? 0);
        $summary['money_debt_usd'] = (float)($row['balance_usd'] ?? 0);

        return $summary;
    }
}

if (!function_exists('getCustomerSnapshotForLogs')) {
    function getCustomerSnapshotForLogs($conn, $userId, $customerId)
    {
        $userId = (int)$userId;
        $customerId = (int)$customerId;
        if (!$conn || $userId <= 0 || $customerId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT id, user_id, name, phone, address, region_id, total_debt, notes, status, created_at, updated_at
            FROM customers
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("ii", $customerId, $userId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$customer) {
            return null;
        }

        return [
            'customer' => $customer,
            'debt_summary' => getCustomerDebtSummaryForLogs($conn, $userId, $customerId),
            'money_debt_summary' => getCustomerMoneyDebtSummaryForLogs($conn, $userId, $customerId)
        ];
    }
}

if (!function_exists('getDebtSnapshotForCustomerLogs')) {
    function getDebtSnapshotForCustomerLogs($conn, $userId, $debtId)
    {
        $debtId = (int)$debtId;
        $userId = (int)$userId;
        if (!$conn || $debtId <= 0 || $userId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT
                d.id, d.user_id, d.customer_id, d.sale_id, d.customer_name, d.customer_phone,
                d.total_debt, d.paid_amount, d.remaining_amount, d.debt_type, d.status, d.created_at, d.updated_at,
                COALESCE(s.currency, 'IQD') AS currency
            FROM debts d
            LEFT JOIN sales s ON s.id = d.sale_id
            WHERE d.id = ? AND d.user_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("ii", $debtId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('getSaleSnapshotForCustomerLogs')) {
    function getSaleSnapshotForCustomerLogs($conn, $userId, $saleId)
    {
        $saleId = (int)$saleId;
        $userId = (int)$userId;
        if (!$conn || $saleId <= 0 || $userId <= 0) {
            return null;
        }

        $saleStmt = $conn->prepare("
            SELECT
                id, user_id, customer_id, invoice_number, customer_name,
                total_amount, discount, final_amount, currency,
                payment_method, payment_status, paid_amount, remaining_amount, sale_date
            FROM sales
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$saleStmt) {
            return null;
        }
        $saleStmt->bind_param("ii", $saleId, $userId);
        $saleStmt->execute();
        $sale = $saleStmt->get_result()->fetch_assoc();
        $saleStmt->close();
        if (!$sale) {
            return null;
        }

        $items = [];
        $itemsStmt = $conn->prepare("
            SELECT
                id, sale_id, product_id, product_name, quantity, unit_price, total_price, price_type, currency,
                unit_id, unit_name, unit_symbol
            FROM sale_items
            WHERE sale_id = ?
            ORDER BY id ASC
        ");
        if ($itemsStmt) {
            $itemsStmt->bind_param("i", $saleId);
            $itemsStmt->execute();
            $itemsRes = $itemsStmt->get_result();
            while ($row = $itemsRes->fetch_assoc()) {
                $items[] = $row;
            }
            $itemsStmt->close();
        }

        return [
            'sale' => $sale,
            'items' => $items
        ];
    }
}

if (!function_exists('buildCustomerChangeDelta')) {
    function buildCustomerChangeDelta($beforeState, $afterState)
    {
        return [
            'before_exists' => $beforeState !== null,
            'after_exists' => $afterState !== null
        ];
    }
}

if (!function_exists('logCustomerChangeEvent')) {
    function logCustomerChangeEvent($eventType, $entityType, $entityId, $beforeState, $afterState, $meta = [])
    {
        $connLogs = $GLOBALS['conn_kasher_logs'] ?? null;
        if (!$connLogs) {
            return false;
        }

        $eventType = (string)$eventType;
        $entityType = (string)$entityType;
        $entityId = $entityId !== null ? (int)$entityId : null;

        $currentUser = $meta['current_user'] ?? null;
        $actors = resolveCustomerChangeActors($currentUser, $meta['user_id'] ?? null);
        $userId = (int)$actors['user_id'];
        $subUserId = $actors['sub_user_id'];
        if ($userId <= 0) {
            return false;
        }

        $customerId = isset($meta['customer_id']) && $meta['customer_id'] !== null ? (int)$meta['customer_id'] : null;
        $saleId = isset($meta['sale_id']) && $meta['sale_id'] !== null ? (int)$meta['sale_id'] : null;
        $debtId = isset($meta['debt_id']) && $meta['debt_id'] !== null ? (int)$meta['debt_id'] : null;
        $currency = isset($meta['currency']) ? (string)$meta['currency'] : null;
        $sourceModule = isset($meta['source_module']) ? (string)$meta['source_module'] : null;
        $sourceReference = isset($meta['source_reference']) ? (string)$meta['source_reference'] : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $beforeJson = safeJsonEncodeCustomerChange($beforeState);
        $afterJson = safeJsonEncodeCustomerChange($afterState);
        $deltaJson = safeJsonEncodeCustomerChange(buildCustomerChangeDelta($beforeState, $afterState));

        $stmt = $connLogs->prepare("
            INSERT INTO customer_change_logs (
                user_id, sub_user_id, event_type, entity_type, entity_id,
                customer_id, sale_id, debt_id, currency,
                before_state_json, after_state_json, delta_json,
                source_module, source_reference, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "iissiiiissssssss",
            $userId,
            $subUserId,
            $eventType,
            $entityType,
            $entityId,
            $customerId,
            $saleId,
            $debtId,
            $currency,
            $beforeJson,
            $afterJson,
            $deltaJson,
            $sourceModule,
            $sourceReference,
            $ipAddress,
            $userAgent
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
