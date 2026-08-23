<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection is unavailable']);
    exit;
}
/** @var mysqli $conn */
$conn = $GLOBALS['conn'];

$session = medicalDoctorSession();
$userId = (int)$session['user_id'];
$action = trim((string)($_GET['action'] ?? ''));

if ($action !== 'search_products') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

$like = '%' . $query . '%';
$namePrefix = $query . '%';
$barcodeMatch = preg_replace('/\s+/', '', $query);
$stmt = $conn->prepare("
    SELECT p.id, p.name, COALESCE(p.barcode, '') AS barcode,
           COALESCE(c.name, '') AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.user_id = ?
      AND (
        p.name LIKE ?
        OR p.barcode LIKE ?
        OR REPLACE(COALESCE(p.barcode, ''), ' ', '') = ?
        OR c.name LIKE ?
      )
    ORDER BY
      CASE
        WHEN REPLACE(COALESCE(p.barcode, ''), ' ', '') = ? THEN 0
        WHEN p.barcode = ? THEN 1
        WHEN p.name LIKE ? THEN 2
        ELSE 3
      END,
      p.name ASC
    LIMIT 20
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    exit;
}

$stmt->bind_param(
    'isssssss',
    $userId,
    $like,
    $like,
    $barcodeMatch,
    $like,
    $barcodeMatch,
    $barcodeMatch,
    $namePrefix
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$unitsByProduct = [];
if (!empty($rows)) {
    $productIds = array_map(static fn($r) => (int)$r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $unitTypes = str_repeat('i', count($productIds));
    $unitStmt = $conn->prepare("
        SELECT pu.product_id, pu.id AS product_unit_id, pu.is_primary,
               u.name AS unit_name, COALESCE(u.symbol, '') AS unit_symbol
        FROM product_units pu
        INNER JOIN units u ON u.id = pu.unit_id
        WHERE pu.product_id IN ($placeholders)
        ORDER BY pu.is_primary DESC, pu.id ASC
    ");
    if ($unitStmt) {
        $unitStmt->bind_param($unitTypes, ...$productIds);
        $unitStmt->execute();
        $unitRows = $unitStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $unitStmt->close();
        foreach ($unitRows as $ur) {
            $pid = (int)$ur['product_id'];
            $unitsByProduct[$pid][] = [
                'product_unit_id' => (int)$ur['product_unit_id'],
                'unit_name'       => (string)$ur['unit_name'],
                'unit_symbol'     => (string)$ur['unit_symbol'],
                'is_primary'      => (int)$ur['is_primary'],
            ];
        }
    }
}

foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['category_name'] = (string)($row['category_name'] ?? '');
    $row['units'] = $unitsByProduct[(int)$row['id']] ?? [];
}
unset($row);

echo json_encode(['success' => true, 'data' => $rows]);
