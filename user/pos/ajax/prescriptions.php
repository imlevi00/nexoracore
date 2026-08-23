<?php
/**
 * ڕەچەتەکانی سەنتەری پزیشکی بۆ بەشی فرۆشتن (POS) — ajax/prescriptions.php
 *
 * GET  ?action=list  → لیستی ڕەچەتە چاوەڕوانەکان لەگەڵ زانیاری نەخۆش و دەرمانەکان
 * GET  ?action=count → تەنها ژمارەی ڕەچەتە چاوەڕوانەکان
 * POST action=complete → دۆخی ڕەچەتەیەک دەکات بە "تەواوکراو"
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../config/kasher_platform/database.php';
require_once '../../../includes/functions.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

function pxJson($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    $resp = ['success' => $success, 'message' => $message];
    if (is_array($data)) {
        $resp = array_merge($resp, $data);
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];

if (!($conn_kasher_platform instanceof mysqli)) {
    pxJson(false, null, 'داتابەیسی kasher_platform بەردەست نییە', 500);
}

/**
 * ژمارەی ڕەچەتە چاوەڕوانەکان
 */
function pxPendingCount(mysqli $conn, int $userId): int {
    // Only count prescriptions that actually carry medication. A prescription
    // with no items must never reach the pharmacy, so it is not counted here.
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM medical_prescriptions mp
        WHERE mp.user_id = ? AND mp.status = 'pending'
          AND EXISTS (
              SELECT 1 FROM medical_prescription_items mpi
              WHERE mpi.prescription_id = mp.id
          )
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = trim((string)($_GET['action'] ?? 'list'));

    if ($action === 'count') {
        pxJson(true, ['count' => pxPendingCount($conn_kasher_platform, $userId)]);
    }

    // action = list
    $sql = "
        SELECT mp.id, mp.diagnosis, mp.created_at,
               p.name AS patient_name, p.mobile AS patient_mobile,
               p.age AS patient_age, p.age_months AS patient_age_months, p.gender AS patient_gender,
               d.name AS doctor_name
        FROM medical_prescriptions mp
        INNER JOIN medical_center_patients p ON p.id = mp.patient_id
        INNER JOIN doctors d ON d.id = mp.doctor_id
        WHERE mp.user_id = ? AND mp.status = 'pending'
          AND EXISTS (
              SELECT 1 FROM medical_prescription_items mpi
              WHERE mpi.prescription_id = mp.id
          )
        ORDER BY mp.id DESC
    ";
    $stmt = $conn_kasher_platform->prepare($sql);
    if (!$stmt) {
        pxJson(false, null, 'هەڵە لە خوێندنەوەی ڕەچەتەکان', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // دەرمانەکانی هەر ڕەچەتەیەک
    $itemsByPrescription = [];
    if (!empty($prescriptions)) {
        $ids = array_map(static fn($r) => (int)$r['id'], $prescriptions);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $itemStmt = $conn_kasher_platform->prepare("
            SELECT prescription_id, product_id, product_name_snapshot, quantity, product_unit_id, unit_name_snapshot
            FROM medical_prescription_items
            WHERE prescription_id IN ($placeholders)
            ORDER BY id ASC
        ");
        if ($itemStmt) {
            $itemStmt->bind_param($types, ...$ids);
            $itemStmt->execute();
            $itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemStmt->close();
            foreach ($itemRows as $ir) {
                $pid = (int)$ir['prescription_id'];
                $itemsByPrescription[$pid][] = [
                    'product_id' => (int)$ir['product_id'],
                    'name' => (string)$ir['product_name_snapshot'],
                    'quantity' => (float)$ir['quantity'],
                    'product_unit_id' => $ir['product_unit_id'] !== null ? (int)$ir['product_unit_id'] : null,
                    'unit_name' => (string)($ir['unit_name_snapshot'] ?? ''),
                ];
            }
        }
    }

    $out = [];
    foreach ($prescriptions as $pr) {
        $pid = (int)$pr['id'];
        $out[] = [
            'id' => $pid,
            'patient_name' => (string)$pr['patient_name'],
            'patient_mobile' => (string)$pr['patient_mobile'],
            'patient_age' => $pr['patient_age'] !== null ? (int)$pr['patient_age'] : null,
            'patient_age_months' => $pr['patient_age_months'] !== null ? (int)$pr['patient_age_months'] : null,
            'patient_gender' => $pr['patient_gender'] !== null ? (string)$pr['patient_gender'] : null,
            'doctor_name' => (string)$pr['doctor_name'],
            'diagnosis' => (string)$pr['diagnosis'],
            'created_at' => (string)$pr['created_at'],
            'items' => $itemsByPrescription[$pid] ?? [],
        ];
    }

    pxJson(true, ['count' => count($out), 'prescriptions' => $out]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    if (!Security::validateCSRFToken($input['csrf_token'] ?? '')) {
        pxJson(false, null, 'CSRF token نادروستە', 403);
    }

    $action = trim((string)($input['action'] ?? ''));
    if ($action === 'complete') {
        $prescriptionId = (int)($input['prescription_id'] ?? 0);
        if ($prescriptionId <= 0) {
            pxJson(false, null, 'ناسنامەی ڕەچەتە نادروستە', 400);
        }
        $stmt = $conn_kasher_platform->prepare("
            UPDATE medical_prescriptions
            SET status = 'completed', updated_at = NOW()
            WHERE id = ? AND user_id = ? AND status = 'pending'
        ");
        if (!$stmt) {
            pxJson(false, null, 'هەڵە لە نوێکردنەوەی دۆخ', 500);
        }
        $stmt->bind_param('ii', $prescriptionId, $userId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            pxJson(false, null, 'هەڵە لە نوێکردنەوەی دۆخ', 500);
        }
        pxJson(true, [
            'updated' => (int)$affected,
            'count' => pxPendingCount($conn_kasher_platform, $userId),
        ], 'دۆخی ڕەچەتە نوێکرایەوە');
    }

    pxJson(false, null, 'کرداری نادروست', 400);
}

pxJson(false, null, 'شێوازی داواکاری نادروستە', 405);
