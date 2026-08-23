<?php
/**
 * Drug Interactions API - user/products/api/drug_interactions.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';
require_once '../../../config/kasher_platform/database.php';

SessionManager::requireAuth('user');

header('Content-Type: application/json; charset=utf-8');

$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$action = trim((string)($_GET['action'] ?? ''));

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!hasDrugInteractionsAccess()) {
    if ($action === 'find_conflicts') {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نیە'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!($conn_kasher_platform instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Platform database is unavailable']);
    exit;
}

try {
    switch ($action) {
        case 'search_products':
            searchProducts($conn, $userId);
            break;
        case 'find_conflicts':
            findConflicts($conn_kasher_platform, $userId);
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function searchProducts(mysqli $conn, int $userId): void {
    $query = trim((string)($_GET['q'] ?? ''));
    if ($query === '') {
        echo json_encode(['success' => true, 'data' => []]);
        return;
    }

    $like = '%' . $query . '%';
    $barcodeMatch = preg_replace('/\s+/', '', $query);

    $stmt = $conn->prepare("
        SELECT p.id, p.name, COALESCE(p.barcode, '') AS barcode
        FROM products p
        WHERE p.user_id = ?
          AND (
            p.name LIKE ?
            OR p.barcode LIKE ?
            OR REPLACE(COALESCE(p.barcode, ''), ' ', '') = ?
          )
        ORDER BY
          CASE WHEN p.barcode = ? THEN 0 ELSE 1 END,
          p.name ASC
        LIMIT 20
    ");
    $stmt->bind_param('issss', $userId, $like, $like, $barcodeMatch, $barcodeMatch);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'success' => true,
        'data' => $rows
    ]);
}

function findConflicts(mysqli $connPlatform, int $userId): void {
    $raw = $_GET['product_ids'] ?? '';
    $idParts = array_filter(array_map('trim', explode(',', (string)$raw)));
    $ids = [];
    foreach ($idParts as $part) {
        $value = (int)$part;
        if ($value > 0) {
            $ids[$value] = $value;
        }
    }

    if (count($ids) < 2) {
        echo json_encode(['success' => true, 'data' => []]);
        return;
    }

    $ids = array_values($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = 'i' . str_repeat('i', count($ids));
    $params = array_merge([$userId], $ids);

    $sql = "
        SELECT di.id,
               di.product_id_1,
               di.product_id_2,
               di.risk_level,
               COALESCE(di.note, '') AS note
        FROM drug_interactions di
        WHERE di.user_id = ?
          AND di.product_id_1 IN ($placeholders)
          AND di.product_id_2 IN ($placeholders)
    ";

    $stmt = $connPlatform->prepare($sql);
    $fullTypes = $types . str_repeat('i', count($ids));
    $fullParams = array_merge($params, $ids);
    $stmt->bind_param($fullTypes, ...$fullParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'success' => true,
        'data' => $rows
    ]);
}
