<?php
/**
 * Auto-save endpoint for an in-progress consultation (a prescription "draft").
 *
 * The patient History page (patients/history.php) calls this on every edit so a
 * doctor never loses work: History / Examination / Diagnoses and any medications
 * chosen so far are persisted immediately. A draft is scoped to one patient and
 * one doctor — there is at most one open draft per patient at a time, so calling
 * this repeatedly updates the same row rather than piling up new ones.
 *
 * A draft has status = 'draft', which every downstream view (pharmacy, staff,
 * secretary, prescription lists, visit history) filters out. The draft only
 * reaches the pharmacy when the doctor submits the History form with at least
 * one medication ("Save & send to Pharmacy"), which flips it to 'pending'; that
 * finalization happens in prescriptions/create.php, not here.
 *
 * Responds with JSON: { success, draft_id, saved_at } or { success:false, message }.
 */
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/referral_service.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(['success' => false, 'message' => 'Invalid request method'], 405);
}

$conn = $GLOBALS['conn'] ?? null;
$conn_kasher_platform = $GLOBALS['conn_kasher_platform'] ?? null;
if (!($conn instanceof mysqli) || !($conn_kasher_platform instanceof mysqli)) {
    $respond(['success' => false, 'message' => 'Database connection is unavailable'], 500);
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $respond(['success' => false, 'message' => 'Security validation failed'], 419);
}

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];

$patientId = (int)($_POST['patient_id'] ?? 0);
if ($patientId <= 0) {
    $respond(['success' => false, 'message' => 'Missing patient'], 400);
}

// Patient must be one this doctor owns OR one referred to them. Referred patients
// let a specialist auto-save a consultation under their own doctor_id.
if (!medicalDoctorCanAccessPatient($conn_kasher_platform, $userId, $doctorId, $patientId)) {
    $respond(['success' => false, 'message' => 'Invalid patient'], 404);
}

$history = trim((string)($_POST['history'] ?? ''));
$examination = trim((string)($_POST['examination'] ?? ''));
$diagnosis = trim((string)($_POST['diagnosis'] ?? ''));

// --- Parse submitted medications (mirrors prescriptions/create.php) ---------
$rawProductIds = array_values((array)($_POST['product_ids'] ?? []));
$rawQuantities = array_values((array)($_POST['quantities'] ?? []));
$rawUnitIds    = array_values((array)($_POST['product_unit_ids'] ?? []));

$submittedItems = [];
foreach ($rawProductIds as $idx => $rawId) {
    $productId = (int)$rawId;
    if ($productId <= 0) {
        continue;
    }
    $qty = isset($rawQuantities[$idx]) ? (float)$rawQuantities[$idx] : 1.0;
    if ($qty <= 0) {
        $qty = 1.0;
    }
    $unitId = isset($rawUnitIds[$idx]) ? (int)$rawUnitIds[$idx] : 0;
    $submittedItems[$productId] = [
        'quantity' => $qty,
        'product_unit_id' => $unitId > 0 ? $unitId : null,
    ];
}
$selectedProductIds = array_keys($submittedItems);

// Resolve product + unit name snapshots for whatever is valid. Auto-save is
// lenient: an unknown product id is simply skipped, never an error.
$productMeta = [];
$unitMeta = [];
if (!empty($selectedProductIds)) {
    $placeholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
    $types = 'i' . str_repeat('i', count($selectedProductIds));
    $params = array_merge([$userId], $selectedProductIds);
    $productsStmt = $conn->prepare("SELECT id, name FROM products WHERE user_id = ? AND id IN ($placeholders)");
    if ($productsStmt) {
        $productsStmt->bind_param($types, ...$params);
        $productsStmt->execute();
        foreach ($productsStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $productMeta[(int)$row['id']] = (string)$row['name'];
        }
        $productsStmt->close();
    }

    $validProductIds = array_keys($productMeta);
    if (!empty($validProductIds)) {
        $unitPlaceholders = implode(',', array_fill(0, count($validProductIds), '?'));
        $unitStmt = $conn->prepare("
            SELECT pu.id AS product_unit_id, pu.product_id, u.name AS unit_name
            FROM product_units pu
            INNER JOIN units u ON u.id = pu.unit_id
            WHERE pu.product_id IN ($unitPlaceholders)
        ");
        if ($unitStmt) {
            $unitStmt->bind_param(str_repeat('i', count($validProductIds)), ...$validProductIds);
            $unitStmt->execute();
            foreach ($unitStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $ur) {
                $unitMeta[(int)$ur['product_id']][(int)$ur['product_unit_id']] = (string)$ur['unit_name'];
            }
            $unitStmt->close();
        }
    }
}

$hasContent = $history !== '' || $examination !== '' || $diagnosis !== '' || !empty($productMeta);

// --- Locate the existing draft for this patient (if any) --------------------
$draftId = (int)($_POST['draft_id'] ?? 0);
if ($draftId > 0) {
    // Trust only a draft that really is this doctor's, this patient's, and still a draft.
    $checkStmt = $conn_kasher_platform->prepare("
        SELECT id FROM medical_prescriptions
        WHERE id = ? AND user_id = ? AND doctor_id = ? AND patient_id = ? AND status = 'draft'
        LIMIT 1
    ");
    $checkStmt->bind_param('iiii', $draftId, $userId, $doctorId, $patientId);
    $checkStmt->execute();
    if (!$checkStmt->get_result()->fetch_assoc()) {
        $draftId = 0;
    }
    $checkStmt->close();
}
if ($draftId <= 0) {
    $findStmt = $conn_kasher_platform->prepare("
        SELECT id FROM medical_prescriptions
        WHERE user_id = ? AND doctor_id = ? AND patient_id = ? AND status = 'draft'
        ORDER BY id DESC
        LIMIT 1
    ");
    $findStmt->bind_param('iii', $userId, $doctorId, $patientId);
    $findStmt->execute();
    $existing = $findStmt->get_result()->fetch_assoc();
    $findStmt->close();
    if ($existing) {
        $draftId = (int)$existing['id'];
    }
}

// Nothing to save and no draft to update: succeed quietly without creating an
// empty row.
if ($draftId <= 0 && !$hasContent) {
    $respond(['success' => true, 'draft_id' => null, 'saved_at' => date('c')]);
}

$historyValue = $history !== '' ? $history : null;
$examinationValue = $examination !== '' ? $examination : null;

$conn_kasher_platform->begin_transaction();
try {
    if ($draftId > 0) {
        $updateStmt = $conn_kasher_platform->prepare("
            UPDATE medical_prescriptions
            SET history = ?, examination = ?, diagnosis = ?, status = 'draft', updated_at = NOW()
            WHERE id = ? AND user_id = ? AND doctor_id = ?
        ");
        if (!$updateStmt) {
            throw new RuntimeException('draft update prepare failed');
        }
        $updateStmt->bind_param('sssiii', $historyValue, $examinationValue, $diagnosis, $draftId, $userId, $doctorId);
        if (!$updateStmt->execute()) {
            throw new RuntimeException('draft update execute failed');
        }
        $updateStmt->close();

        $deleteStmt = $conn_kasher_platform->prepare("DELETE FROM medical_prescription_items WHERE prescription_id = ?");
        $deleteStmt->bind_param('i', $draftId);
        if (!$deleteStmt->execute()) {
            throw new RuntimeException('draft item cleanup failed');
        }
        $deleteStmt->close();
    } else {
        $insertStmt = $conn_kasher_platform->prepare("
            INSERT INTO medical_prescriptions
            (user_id, doctor_id, patient_id, history, examination, diagnosis, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())
        ");
        if (!$insertStmt) {
            throw new RuntimeException('draft insert prepare failed');
        }
        $insertStmt->bind_param('iiisss', $userId, $doctorId, $patientId, $historyValue, $examinationValue, $diagnosis);
        if (!$insertStmt->execute()) {
            throw new RuntimeException('draft insert execute failed');
        }
        $draftId = (int)$insertStmt->insert_id;
        $insertStmt->close();
    }

    if (!empty($productMeta)) {
        $insertItemStmt = $conn_kasher_platform->prepare("
            INSERT INTO medical_prescription_items
            (prescription_id, product_id, product_name_snapshot, quantity, product_unit_id, unit_name_snapshot, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$insertItemStmt) {
            throw new RuntimeException('draft item insert prepare failed');
        }
        foreach ($selectedProductIds as $productId) {
            if (!isset($productMeta[$productId])) {
                continue;
            }
            $productNameSnapshot = $productMeta[$productId];
            $quantity = (float)($submittedItems[$productId]['quantity'] ?? 1);
            $productUnitId = $submittedItems[$productId]['product_unit_id'] ?? null;
            if ($productUnitId !== null && !isset($unitMeta[$productId][$productUnitId])) {
                $productUnitId = null;
            }
            $unitNameSnapshot = $productUnitId !== null ? ($unitMeta[$productId][$productUnitId] ?? null) : null;
            $insertItemStmt->bind_param(
                'iisdis',
                $draftId,
                $productId,
                $productNameSnapshot,
                $quantity,
                $productUnitId,
                $unitNameSnapshot
            );
            if (!$insertItemStmt->execute()) {
                throw new RuntimeException('draft item insert execute failed');
            }
        }
        $insertItemStmt->close();
    }

    $conn_kasher_platform->commit();
} catch (Throwable $e) {
    $conn_kasher_platform->rollback();
    $respond(['success' => false, 'message' => 'Could not save draft'], 500);
}

$respond(['success' => true, 'draft_id' => $draftId, 'saved_at' => date('c')]);
