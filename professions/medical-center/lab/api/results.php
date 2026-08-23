<?php
/**
 * Lab result entry API (AJAX, JSON).
 */
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_api_helpers.php';
require_once dirname(__DIR__) . '/includes/lab_catalog_service.php';
require_once dirname(__DIR__) . '/includes/lab_order_service.php';

header('Content-Type: application/json; charset=utf-8');

if (!($conn_kasher_platform instanceof mysqli)) {
    labJsonRespond(false, [], 'پەیوەندی داتابەیس بەردەست نییە', 500);
}
/** @var mysqli $conn_kasher_platform */
$conn = $conn_kasher_platform;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    labJsonRespond(false, [], 'ڕێگەی داواکاری نادروست', 405);
}
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    labJsonRespond(false, [], 'نادروستی ئامنیەتی', 403);
}

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];
$action = trim((string)($_POST['action'] ?? ''));
$orderId = (int)($_POST['order_id'] ?? 0);

$order = labFetchOrder($conn, $userId, $orderId);
if (!$order || (int)$order['lab_id'] !== $labId) {
    labJsonRespond(false, [], 'داواکاری نەدۆزرایەوە', 404);
}

$orderStatus = (string)$order['status'];

if ($action === 'set_status') {
    $status = (string)($_POST['status'] ?? '');
    if (!array_key_exists($status, labOrderStatuses())) {
        labJsonRespond(false, [], 'دۆخی نادروست');
    }
    if ($status === 'completed' && !labOrderResultsComplete($conn, $orderId)) {
        labJsonRespond(false, [], 'بۆ هەر فەحسێک لانیکەم یەک خانەی ئەنجام پڕبکە پێش تەواوکردن');
    }
    if ($status === 'completed') {
        $stmt = $conn->prepare("UPDATE lab_orders SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE id = ? AND user_id = ? AND lab_id = ?");
        $stmt->bind_param('iii', $orderId, $userId, $labId);
    } else {
        $stmt = $conn->prepare("UPDATE lab_orders SET status = ?, completed_at = NULL, updated_at = NOW() WHERE id = ? AND user_id = ? AND lab_id = ?");
        $stmt->bind_param('siii', $status, $orderId, $userId, $labId);
    }
    $stmt->execute();
    $stmt->close();
    labJsonRespond(true, [
        'status' => $status,
        'status_label' => labOrderStatusLabel($status),
        'status_badge' => labOrderStatusBadge($status),
    ], 'پاشەکەوتکرا');
}

if ($action === 'save_cell') {
    if ($orderStatus === 'completed') {
        labJsonRespond(false, [], 'داواکاری تەواوبووە — دەستکاری ئەنجام ناکرێت', 403);
    }

    $orderTestId = (int)($_POST['order_test_id'] ?? 0);
    $rowId = (int)($_POST['row_id'] ?? 0);
    $columnId = (int)($_POST['column_id'] ?? 0);
    $value = mb_substr((string)($_POST['value'] ?? ''), 0, 255, 'UTF-8');

    $stmt = $conn->prepare("SELECT test_id FROM lab_order_tests WHERE id = ? AND order_id = ? LIMIT 1");
    $stmt->bind_param('ii', $orderTestId, $orderId);
    $stmt->execute();
    $otRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$otRow) {
        labJsonRespond(false, [], 'فەحس نەدۆزرایەوە');
    }
    $testId = (int)$otRow['test_id'];

    $stmt = $conn->prepare("
        SELECT result_type,
               normal_min, normal_max,
               normal_min_male, normal_max_male, normal_min_female, normal_max_female,
               normal_text
        FROM lab_test_rows WHERE id = ? AND test_id = ? LIMIT 1
    ");
    $stmt->bind_param('ii', $rowId, $testId);
    $stmt->execute();
    $rowData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rowData) {
        labJsonRespond(false, [], 'ڕیز نەدۆزرایەوە');
    }
    // Attach optional age brackets so flagging can match by patient age.
    $rowData['ranges'] = labFetchRowRanges($conn, $testId)[$rowId] ?? [];

    $stmt = $conn->prepare("SELECT col_type FROM lab_test_columns WHERE id = ? AND test_id = ? LIMIT 1");
    $stmt->bind_param('ii', $columnId, $testId);
    $stmt->execute();
    $colData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$colData) {
        labJsonRespond(false, [], 'ستوون نەدۆزرایەوە');
    }

    $colType = (string)$colData['col_type'];
    $flag = null;
    if (labColumnComputesFlag($colType)) {
        $ageDays = labPatientAgeInDays(
            isset($order['age']) ? (int)$order['age'] : null,
            isset($order['age_months']) ? (int)$order['age_months'] : null
        );
        $flag = labComputeResultFlag($rowData, $value, $order['gender'] ?? null, $ageDays);
    }

    $stmt = $conn->prepare("
        INSERT INTO lab_order_results (order_test_id, row_id, column_id, value, flag, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE value = VALUES(value), flag = VALUES(flag), updated_at = NOW()
    ");
    $stmt->bind_param('iiiss', $orderTestId, $rowId, $columnId, $value, $flag);
    $stmt->execute();
    $stmt->close();

    $statusColumnId = null;
    if (labColumnComputesFlag($colType)) {
        $columns = labFetchColumns($conn, $testId);
        foreach ($columns as $col) {
            if (($col['col_type'] ?? '') === 'status') {
                $statusColumnId = (int)$col['id'];
                break;
            }
        }
    }

    labJsonRespond(true, [
        'flag' => $flag,
        'flag_markup' => labFlagMarkup($flag),
        'status_column_id' => $statusColumnId,
    ]);
}

labJsonRespond(false, [], 'کرداری نەناسراو', 400);
