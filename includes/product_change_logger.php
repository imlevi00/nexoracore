<?php
/**
 * Central helper for product change history logging.
 */

require_once __DIR__ . '/../config/kasher_logs/database.php';

if (!function_exists('resolveProductChangeActors')) {
    function resolveProductChangeActors($currentUser, $fallbackUserId = null) {
        $userId = $fallbackUserId !== null ? (int)$fallbackUserId : 0;
        $subUserId = null;

        if (is_array($currentUser) && !empty($currentUser)) {
            $currentId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
            $userType = isset($currentUser['user_type']) ? (string)$currentUser['user_type'] : '';
            $parentId = isset($currentUser['parent_user_id']) ? (int)$currentUser['parent_user_id'] : 0;
            $sessionSubUserId = isset($currentUser['sub_user_id']) ? (int)$currentUser['sub_user_id'] : 0;

            if ($userType === 'sub') {
                // لە سێشنی sub، زۆرجار id = main_user_id ـە؛ بۆیە sub_user_id دەبێت لە field ـی تایبەت وەربگیرێت.
                if ($sessionSubUserId > 0) {
                    $subUserId = $sessionSubUserId;
                } elseif ($currentId > 0 && $parentId > 0 && $currentId !== $parentId) {
                    // Legacy fallback بۆ دۆخی پێوەندی جیاواز.
                    $subUserId = $currentId;
                }

                if ($parentId > 0) {
                    $userId = $parentId;
                } elseif ($currentId > 0) {
                    // بۆ session ـی ئێستا id = main_user_id
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

if (!function_exists('safeJsonEncodeProductChange')) {
    function safeJsonEncodeProductChange($data) {
        if ($data === null) {
            return null;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}

if (!function_exists('getProductSnapshotForLogs')) {
    function getProductSnapshotForLogs($conn, $userId, $productId) {
        $productId = (int)$productId;
        $userId = (int)$userId;
        if (!$conn || $productId <= 0 || $userId <= 0) {
            return null;
        }

        $productStmt = $conn->prepare("
            SELECT id, user_id, category_id, name, barcode, expiry_date, currency, image_path, created_at, updated_at
            FROM products
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$productStmt) {
            return null;
        }
        $productStmt->bind_param("ii", $productId, $userId);
        $productStmt->execute();
        $product = $productStmt->get_result()->fetch_assoc();
        $productStmt->close();

        if (!$product) {
            return null;
        }

        $units = [];
        $unitsStmt = $conn->prepare("
            SELECT pu.id, pu.unit_id, u.name AS unit_name, u.symbol AS unit_symbol,
                   pu.buy_price, pu.sell_price, pu.wholesale_price, pu.special_price,
                   pu.stock_quantity, pu.min_stock, pu.currency, pu.conversion_ratio, pu.conversion_rate, pu.is_primary
            FROM product_units pu
            LEFT JOIN units u ON u.id = pu.unit_id
            WHERE pu.product_id = ?
            ORDER BY pu.is_primary DESC, pu.id ASC
        ");
        if ($unitsStmt) {
            $unitsStmt->bind_param("i", $productId);
            $unitsStmt->execute();
            $unitsResult = $unitsStmt->get_result();
            while ($row = $unitsResult->fetch_assoc()) {
                $units[] = $row;
            }
            $unitsStmt->close();
        }

        return [
            'product' => $product,
            'units' => $units
        ];
    }
}

if (!function_exists('logProductChangeEvent')) {
    function logProductChangeEvent($eventType, $entityType, $entityId, $beforeState, $afterState, $meta = []) {
        $connLogs = $GLOBALS['conn_kasher_logs'] ?? null;
        if (!$connLogs) {
            return false;
        }

        $eventType = (string)$eventType;
        $entityType = (string)$entityType;
        $entityId = $entityId !== null ? (int)$entityId : null;

        $currentUser = $meta['current_user'] ?? null;
        $actors = resolveProductChangeActors($currentUser, $meta['user_id'] ?? null);
        $userId = (int)$actors['user_id'];
        $subUserId = $actors['sub_user_id'];
        if ($userId <= 0) {
            return false;
        }

        $productId = isset($meta['product_id']) && $meta['product_id'] !== null ? (int)$meta['product_id'] : null;
        $sourceModule = isset($meta['source_module']) ? (string)$meta['source_module'] : null;
        $sourceReference = isset($meta['source_reference']) ? (string)$meta['source_reference'] : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $beforeJson = safeJsonEncodeProductChange($beforeState);
        $afterJson = safeJsonEncodeProductChange($afterState);
        $delta = [
            'before_exists' => $beforeState !== null,
            'after_exists' => $afterState !== null
        ];
        $deltaJson = safeJsonEncodeProductChange($delta);

        $stmt = $connLogs->prepare("
            INSERT INTO product_change_logs (
                user_id, sub_user_id, event_type, entity_type, entity_id, product_id,
                before_state_json, after_state_json, delta_json,
                source_module, source_reference, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "iissiisssssss",
            $userId,
            $subUserId,
            $eventType,
            $entityType,
            $entityId,
            $productId,
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
