<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
require_once dirname(dirname(__DIR__)) . '/lab/includes/lab_order_service.php';
require_once dirname(__DIR__) . '/includes/visit_history.php';
require_once dirname(__DIR__) . '/includes/referral_service.php';
require_once dirname(__DIR__) . '/includes/markdown_field.php';
require_once dirname(__DIR__) . '/includes/prescription_attachments.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];
$csrfToken = Security::generateCSRFToken();
$errors = [];

// When included from patients/history.php this is defined true, so the clinical
// fields (History / Examination / Diagnoses) are shown and validated. On the
// standalone create.php page only the Treatment panel is shown.
$showClinicalFields = defined('SC_SHOW_CLINICAL_FIELDS') && SC_SHOW_CLINICAL_FIELDS;

$selectedPatientId = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $selectedPatientId <= 0) {
    setMessage('Select a patient from the dashboard to write a prescription.', 'warning');
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}
$history = trim((string)($_POST['history'] ?? ''));
$examination = trim((string)($_POST['examination'] ?? ''));
$diagnosis = trim((string)($_POST['diagnosis'] ?? ''));

$rawProductIds  = array_values((array)($_POST['product_ids'] ?? []));
$rawQuantities  = array_values((array)($_POST['quantities'] ?? []));
$rawUnitIds     = array_values((array)($_POST['product_unit_ids'] ?? []));

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

// The open draft this consultation belongs to (History page only). Carried
// through the form so a submit finalizes the same row the auto-save built.
$draftId = (int)($_POST['draft_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security validation failed. Please try again.';
    }
    if ($selectedPatientId <= 0) {
        $errors[] = 'Please select a patient.';
    }
    // Medication is optional. A prescription with medication is finalized and
    // sent to the pharmacy ('pending'). One finalized without any medication is
    // saved as a 'consultation' instead: it stays out of the pharmacy queue
    // (which only dispenses 'pending') but still shows in the clinic visit
    // history (which lists everything except the in-progress 'draft').
    // History / Examination / Diagnoses are all optional too.
    $targetStatus = empty($selectedProductIds) ? 'consultation' : 'pending';

    $patientName = '';
    if ($selectedPatientId > 0) {
        // Patient may be one this doctor owns OR one referred to them; the helper
        // enforces tenant scope and either ownership or a pending referral.
        $accessiblePatient = medicalDoctorFetchAccessiblePatient(
            $conn_kasher_platform,
            $userId,
            $doctorId,
            $selectedPatientId
        );
        if (!$accessiblePatient) {
            $errors[] = 'Selected patient is invalid.';
        } else {
            $patientName = (string)$accessiblePatient['name'];
        }
    }

    $productMeta = [];
    $unitMeta = [];
    if (!empty($selectedProductIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
        $types = 'i' . str_repeat('i', count($selectedProductIds));
        $params = array_merge([$userId], $selectedProductIds);
        $sql = "SELECT id, name FROM products WHERE user_id = ? AND id IN ($placeholders)";
        $productsStmt = $conn->prepare($sql);
        if ($productsStmt) {
            $productsStmt->bind_param($types, ...$params);
            $productsStmt->execute();
            $rows = $productsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $productsStmt->close();
            foreach ($rows as $row) {
                $productMeta[(int)$row['id']] = (string)$row['name'];
            }
            if (count($productMeta) !== count($selectedProductIds)) {
                $errors[] = 'One or more selected medications are invalid.';
            }

            $unitPlaceholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
            $unitTypes = str_repeat('i', count($selectedProductIds));
            $unitSql = "
                SELECT pu.id AS product_unit_id, pu.product_id, u.name AS unit_name
                FROM product_units pu
                INNER JOIN units u ON u.id = pu.unit_id
                WHERE pu.product_id IN ($unitPlaceholders)
            ";
            $unitLookupStmt = $conn->prepare($unitSql);
            if ($unitLookupStmt) {
                $unitLookupStmt->bind_param($unitTypes, ...$selectedProductIds);
                $unitLookupStmt->execute();
                $unitRows = $unitLookupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $unitLookupStmt->close();
                foreach ($unitRows as $ur) {
                    $unitMeta[(int)$ur['product_id']][(int)$ur['product_unit_id']] = (string)$ur['unit_name'];
                }
            }
        } else {
            $errors[] = 'Something went wrong while validating medications.';
        }
    }

    // When finalizing from the History page, promote the patient's existing draft
    // to a real (pending) prescription instead of creating a second row. Only the
    // History page touches drafts; the standalone create.php always inserts new.
    $finalizeDraftId = 0;
    if ($showClinicalFields && empty($errors)) {
        if ($draftId > 0) {
            $draftCheckStmt = $conn_kasher_platform->prepare("
                SELECT id FROM medical_prescriptions
                WHERE id = ? AND user_id = ? AND doctor_id = ? AND patient_id = ? AND status = 'draft'
                LIMIT 1
            ");
            $draftCheckStmt->bind_param('iiii', $draftId, $userId, $doctorId, $selectedPatientId);
            $draftCheckStmt->execute();
            if ($draftCheckStmt->get_result()->fetch_assoc()) {
                $finalizeDraftId = $draftId;
            }
            $draftCheckStmt->close();
        }
        if ($finalizeDraftId <= 0) {
            $draftLookupStmt = $conn_kasher_platform->prepare("
                SELECT id FROM medical_prescriptions
                WHERE user_id = ? AND doctor_id = ? AND patient_id = ? AND status = 'draft'
                ORDER BY id DESC LIMIT 1
            ");
            $draftLookupStmt->bind_param('iii', $userId, $doctorId, $selectedPatientId);
            $draftLookupStmt->execute();
            if ($draftLookupRow = $draftLookupStmt->get_result()->fetch_assoc()) {
                $finalizeDraftId = (int)$draftLookupRow['id'];
            }
            $draftLookupStmt->close();
        }
    }

    if (empty($errors)) {
        $conn_kasher_platform->begin_transaction();
        try {
            $historyValue = $history !== '' ? $history : null;
            $examinationValue = $examination !== '' ? $examination : null;

            if ($finalizeDraftId > 0) {
                $promoteStmt = $conn_kasher_platform->prepare("
                    UPDATE medical_prescriptions
                    SET history = ?, examination = ?, diagnosis = ?, status = ?, updated_at = NOW()
                    WHERE id = ? AND user_id = ? AND doctor_id = ? AND status = 'draft'
                ");
                if (!$promoteStmt) {
                    throw new RuntimeException('Promote draft prepare failed');
                }
                $promoteStmt->bind_param(
                    'ssssiii',
                    $historyValue,
                    $examinationValue,
                    $diagnosis,
                    $targetStatus,
                    $finalizeDraftId,
                    $userId,
                    $doctorId
                );
                if (!$promoteStmt->execute()) {
                    throw new RuntimeException('Promote draft execute failed');
                }
                $promoteStmt->close();
                $prescriptionId = $finalizeDraftId;

                $clearItemsStmt = $conn_kasher_platform->prepare("
                    DELETE FROM medical_prescription_items WHERE prescription_id = ?
                ");
                $clearItemsStmt->bind_param('i', $prescriptionId);
                if (!$clearItemsStmt->execute()) {
                    throw new RuntimeException('Clear draft items failed');
                }
                $clearItemsStmt->close();
            } else {
                $insertPrescriptionStmt = $conn_kasher_platform->prepare("
                    INSERT INTO medical_prescriptions
                    (user_id, doctor_id, patient_id, history, examination, diagnosis, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                if (!$insertPrescriptionStmt) {
                    throw new RuntimeException('Insert prescription prepare failed');
                }
                $insertPrescriptionStmt->bind_param(
                    'iiissss',
                    $userId,
                    $doctorId,
                    $selectedPatientId,
                    $historyValue,
                    $examinationValue,
                    $diagnosis,
                    $targetStatus
                );
                if (!$insertPrescriptionStmt->execute()) {
                    throw new RuntimeException('Insert prescription execute failed');
                }
                $prescriptionId = (int)$insertPrescriptionStmt->insert_id;
                $insertPrescriptionStmt->close();
            }

            $insertItemStmt = $conn_kasher_platform->prepare("
                INSERT INTO medical_prescription_items
                (prescription_id, product_id, product_name_snapshot, quantity, product_unit_id, unit_name_snapshot, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            if (!$insertItemStmt) {
                throw new RuntimeException('Insert item prepare failed');
            }
            foreach ($selectedProductIds as $productId) {
                $productNameSnapshot = $productMeta[$productId];
                $quantity = (float)($submittedItems[$productId]['quantity'] ?? 1);
                $productUnitId = $submittedItems[$productId]['product_unit_id'] ?? null;
                if ($productUnitId !== null && !isset($unitMeta[$productId][$productUnitId])) {
                    $productUnitId = null;
                }
                $unitNameSnapshot = $productUnitId !== null
                    ? ($unitMeta[$productId][$productUnitId] ?? null)
                    : null;
                $insertItemStmt->bind_param(
                    'iisdis',
                    $prescriptionId,
                    $productId,
                    $productNameSnapshot,
                    $quantity,
                    $productUnitId,
                    $unitNameSnapshot
                );
                if (!$insertItemStmt->execute()) {
                    throw new RuntimeException('Insert item execute failed');
                }
            }
            $insertItemStmt->close();
            $conn_kasher_platform->commit();

            $successMessage = $targetStatus === 'pending'
                ? "Prescription sent to the pharmacy for {$patientName}."
                : "Consultation saved for {$patientName}.";
            setMessage($successMessage, 'success');
            redirect(url('professions/medical-center/doctor/prescriptions/view.php?id=' . $prescriptionId));
        } catch (Throwable $e) {
            $conn_kasher_platform->rollback();
            $errors[] = 'Something went wrong while saving the prescription.';
        }
    }
}

// Resume the patient's open draft on the History page (GET). This is what makes
// the work survive navigating away — e.g. writing History, sending the patient
// to the lab, closing the page, then coming back after the results to finish and
// send to the pharmacy. The draft's fields + medications are loaded into the same
// structures the render below already understands.
if ($showClinicalFields && $_SERVER['REQUEST_METHOD'] !== 'POST' && $selectedPatientId > 0) {
    $draftStmt = $conn_kasher_platform->prepare("
        SELECT id, history, examination, diagnosis
        FROM medical_prescriptions
        WHERE user_id = ? AND doctor_id = ? AND patient_id = ? AND status = 'draft'
        ORDER BY id DESC LIMIT 1
    ");
    if ($draftStmt) {
        $draftStmt->bind_param('iii', $userId, $doctorId, $selectedPatientId);
        $draftStmt->execute();
        $draftRow = $draftStmt->get_result()->fetch_assoc();
        $draftStmt->close();
        if ($draftRow) {
            $draftId = (int)$draftRow['id'];
            $history = (string)($draftRow['history'] ?? '');
            $examination = (string)($draftRow['examination'] ?? '');
            $diagnosis = (string)($draftRow['diagnosis'] ?? '');

            $draftItemsStmt = $conn_kasher_platform->prepare("
                SELECT product_id, quantity, product_unit_id
                FROM medical_prescription_items
                WHERE prescription_id = ?
                ORDER BY id ASC
            ");
            if ($draftItemsStmt) {
                $draftItemsStmt->bind_param('i', $draftId);
                $draftItemsStmt->execute();
                $draftItems = $draftItemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $draftItemsStmt->close();
                foreach ($draftItems as $di) {
                    $pid = (int)$di['product_id'];
                    if ($pid <= 0) {
                        continue;
                    }
                    $qty = (float)$di['quantity'];
                    if ($qty <= 0) {
                        $qty = 1.0;
                    }
                    $submittedItems[$pid] = [
                        'quantity' => $qty,
                        'product_unit_id' => isset($di['product_unit_id']) ? (int)$di['product_unit_id'] : null,
                    ];
                }
                $selectedProductIds = array_keys($submittedItems);

                if (!empty($selectedProductIds)) {
                    $draftPlaceholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
                    $draftProdStmt = $conn->prepare("SELECT id, name FROM products WHERE user_id = ? AND id IN ($draftPlaceholders)");
                    if ($draftProdStmt) {
                        $draftProdStmt->bind_param(
                            'i' . str_repeat('i', count($selectedProductIds)),
                            ...array_merge([$userId], $selectedProductIds)
                        );
                        $draftProdStmt->execute();
                        foreach ($draftProdStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $pr) {
                            $productMeta[(int)$pr['id']] = (string)$pr['name'];
                        }
                        $draftProdStmt->close();
                    }
                    $draftUnitStmt = $conn->prepare("
                        SELECT pu.id AS product_unit_id, pu.product_id, u.name AS unit_name
                        FROM product_units pu
                        INNER JOIN units u ON u.id = pu.unit_id
                        WHERE pu.product_id IN ($draftPlaceholders)
                    ");
                    if ($draftUnitStmt) {
                        $draftUnitStmt->bind_param(str_repeat('i', count($selectedProductIds)), ...$selectedProductIds);
                        $draftUnitStmt->execute();
                        foreach ($draftUnitStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $ur) {
                            $unitMeta[(int)$ur['product_id']][(int)$ur['product_unit_id']] = (string)$ur['unit_name'];
                        }
                        $draftUnitStmt->close();
                    }
                    // Drop any medication whose product no longer exists.
                    foreach (array_keys($submittedItems) as $pid) {
                        if (!isset($productMeta[$pid])) {
                            unset($submittedItems[$pid]);
                        }
                    }
                    $selectedProductIds = array_keys($submittedItems);
                }
            }
        }
    }
}

// Image attachments for the clinical-note sections, grouped by section. On the
// History page these hang off the open draft ($draftId); before a draft exists
// there are none, and the first upload creates one server-side.
$sectionAttachments = ['history' => [], 'examination' => [], 'diagnosis' => []];
if ($showClinicalFields && $draftId > 0) {
    $sectionAttachments = array_merge(
        $sectionAttachments,
        medicalRxAttachmentsForPrescription($conn_kasher_platform, $draftId)
    );
}

$selectedItemsForRender = [];
foreach ($selectedProductIds as $productId) {
    if (!isset($productMeta[$productId])) {
        continue;
    }
    $unitOptions = [];
    foreach (($unitMeta[$productId] ?? []) as $puId => $unitName) {
        $unitOptions[] = ['product_unit_id' => (int)$puId, 'unit_name' => (string)$unitName];
    }
    $selectedItemsForRender[] = [
        'product_id'      => (int)$productId,
        'name'            => (string)$productMeta[$productId],
        'quantity'        => (float)($submittedItems[$productId]['quantity'] ?? 1),
        'selected_unit_id'=> $submittedItems[$productId]['product_unit_id'] ?? null,
        'units'           => $unitOptions,
    ];
}

$selectedPatient = null;
if ($selectedPatientId > 0) {
    // Owned or referred-in patient (helper enforces tenant + access).
    $selectedPatient = medicalDoctorFetchAccessiblePatient(
        $conn_kasher_platform,
        $userId,
        $doctorId,
        $selectedPatientId
    );
    if (!$selectedPatient) {
        $selectedPatientId = 0;
    }
}

// Other doctors this patient can be referred to (for the "Referral" control).
$referralTargets = $selectedPatient !== null
    ? medicalDoctorListReferralTargets($conn_kasher_platform, $userId, $doctorId)
    : [];
$referralActionUrl = url('professions/medical-center/doctor/referrals/create.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $selectedPatient === null) {
    setMessage('Selected patient is invalid or not found.', 'danger');
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}

$visitHistory = null;
if ($selectedPatient !== null) {
    $visitHistory = medicalDoctorFetchPatientVisitHistory($conn_kasher_platform, $userId, $doctorId, (int)$selectedPatient['id']);
}

// History page only: the Clinic master–detail. The 'nav' (clickable tree) goes in
// the right rail; the 'detail' pane sits in the middle column above the form and is
// revealed by doctor-ui.js when a node is clicked.
$visitTreeParts = ($showClinicalFields && $selectedPatient !== null)
    ? medicalDoctorRenderVisitTree($visitHistory, 'en')
    : null;

$doctorUiLocale = 'en';
$pageTitle = 'Write Prescription';
$activeNav = 'create';
$bodyClass = 'doctor-smart-clinic-page' . ($showClinicalFields ? ' doctor-history-page' : '');
$prescriptionSearchEndpoint = url('professions/medical-center/doctor/api/products.php?action=search_products');
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">'
    . '<script>window.__scPrescriptionCreate=' . json_encode([
        'searchEndpoint' => $prescriptionSearchEndpoint,
        'labelQty' => 'Qty',
        'labelUnit' => 'Unit',
        'unitPlaceholder' => '— Unit —',
        'noResults' => 'No results found',
        'searchError' => 'Search error',
        'searching' => 'Searching…',
    ], JSON_UNESCAPED_UNICODE) . ';</script>';
if ($showClinicalFields) {
    $extraHead .= '<script>window.__scDraftAutosave=' . json_encode([
        'endpoint' => url('professions/medical-center/doctor/api/prescription_draft.php'),
        'idleDelay' => 900,
        'savingText' => 'Saving…',
        'savedText' => 'Draft saved',
        'errorText' => 'Not saved — will retry',
    ], JSON_UNESCAPED_UNICODE) . ';</script>';
    $extraHead .= '<script>window.__scPrescriptionImages=' . json_encode([
        'endpoint' => url('professions/medical-center/doctor/api/prescription_image.php'),
        'csrfToken' => $csrfToken,
        'patientId' => $selectedPatientId,
        'uploadingText' => 'Uploading…',
        'uploadError' => 'Upload failed',
        'deleteConfirm' => 'Remove this image?',
        'tooLarge' => 'Image is too large (max 8MB)',
        'notImage' => 'Only image files are allowed',
    ], JSON_UNESCAPED_UNICODE) . ';</script>';
}
$extraHead .= '<script src="' . asset('js/medical_center/doctor-ui.js') . '" defer></script>';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="sc-page" data-sc-page="prescription-create">
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title">Write Prescription</h1>
            <p class="sc-page-subtitle">Create a new prescription for a patient</p>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="sc-workspace<?php echo $showClinicalFields ? ' sc-workspace-hist' : ''; ?>"<?php echo $visitTreeParts !== null ? ' data-sc-visit-tree data-vt-first="' . htmlspecialchars($visitTreeParts['first'], ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
        <?php if ($selectedPatient !== null): ?>
            <aside class="sc-sidebar">
                <div class="sc-patient-card">
                    <div class="sc-patient-name"><?php echo htmlspecialchars((string)$selectedPatient['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="sc-patient-detail">
                        <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$selectedPatient['mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>
                            <i class="bi bi-person"></i>
                            <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$selectedPatient['age'], isset($selectedPatient['age_months']) ? (int)$selectedPatient['age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                            · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($selectedPatient['gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php if (!empty($selectedPatient['profession'])): ?>
                        <span><i class="bi bi-briefcase"></i> <?php echo htmlspecialchars((string)$selectedPatient['profession'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($selectedPatient['blood_type'])): ?>
                        <span><i class="bi bi-droplet"></i> <?php echo htmlspecialchars((string)$selectedPatient['blood_type'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($selectedPatient['address'])): ?>
                        <span><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string)$selectedPatient['address'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($selectedPatient['is_referred'])): ?>
                        <span class="sc-referred-badge">
                            <i class="bi bi-arrow-left-right"></i>
                            Referred by <?php echo htmlspecialchars((string)$selectedPatient['referring_doctor_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sc-send-to">
                    <div class="sc-send-to-title">Send To</div>
                    <div class="sc-send-to-grid">
                        <a class="sc-send-to-btn sc-send-lab"
                           href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php?patient_id=' . (int)$selectedPatient['id']); ?>">
                            <i class="bi bi-clipboard2-pulse"></i> Lab
                        </a>
                        <a class="sc-send-to-btn sc-send-rx"
                           href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php?patient_id=' . (int)$selectedPatient['id']); ?>">
                            <i class="bi bi-file-earmark-medical"></i> Pharmacy
                        </a>
                        <a class="sc-send-to-btn sc-send-history"
                           href="<?php echo url('professions/medical-center/doctor/patients/history.php?patient_id=' . (int)$selectedPatient['id']); ?>">
                            <i class="bi bi-diagram-3"></i> History
                        </a>
                        <?php if ($referralTargets !== []): ?>
                        <button type="button" class="sc-send-to-btn sc-send-referral"
                                data-bs-toggle="modal" data-bs-target="#referralModal">
                            <i class="bi bi-arrow-left-right"></i> Referral
                        </button>
                        <?php endif; ?>
                        <a class="sc-send-to-btn sc-send-change"
                           href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php'); ?>">
                            <i class="bi bi-arrow-repeat"></i> Change
                        </a>
                    </div>
                </div>

                <?php if (!$showClinicalFields): ?>
                <details class="sc-panel sc-vh-panel sc-clinic-panel">
                    <summary class="sc-panel-header sc-vh-panel-header">
                        <i class="bi bi-hospital"></i> Clinic
                        <span class="badge bg-light text-dark ms-auto">
                            <?php echo (int)($visitHistory['total_prescriptions'] ?? 0); ?> Pharmacy · <?php echo (int)($visitHistory['total_lab_orders'] ?? 0); ?> Lab
                        </span>
                        <span class="sc-vh-caret sc-vh-panel-caret"><i class="bi bi-chevron-right"></i></span>
                    </summary>
                    <div class="sc-panel-body">
                        <?php echo medicalDoctorRenderVisitHistory($visitHistory, 'en', $doctorId); ?>
                    </div>
                </details>
                <?php if ((int)($visitHistory['total_direct_lab_orders'] ?? 0) > 0): ?>
                <details class="sc-panel sc-vh-panel sc-clinic-panel">
                    <summary class="sc-panel-header sc-vh-panel-header">
                        <i class="bi bi-clipboard2-pulse"></i> Direct Lab
                        <span class="badge bg-light text-dark ms-auto">
                            <?php echo (int)($visitHistory['total_direct_lab_orders'] ?? 0); ?> Walk-in
                        </span>
                        <span class="sc-vh-caret sc-vh-panel-caret"><i class="bi bi-chevron-right"></i></span>
                    </summary>
                    <div class="sc-panel-body">
                        <?php echo medicalDoctorRenderDirectLabOrders($visitHistory['direct_lab_orders'] ?? [], 'en'); ?>
                    </div>
                </details>
                <?php endif; ?>
                <?php endif; ?>
            </aside>
        <?php endif; ?>

        <?php if ($showClinicalFields && $selectedPatient !== null): ?>
            <aside class="sc-clinic-column">
                <details class="sc-panel sc-vh-panel sc-clinic-panel" open>
                    <summary class="sc-panel-header sc-vh-panel-header">
                        <i class="bi bi-hospital"></i> PATIENT VISIT HISTORY
                        <span class="badge bg-light text-dark ms-auto">
                            <?php echo (int)($visitHistory['total_prescriptions'] ?? 0); ?> Pharmacy · <?php echo (int)($visitHistory['total_lab_orders'] ?? 0); ?> Lab
                        </span>
                        <span class="sc-vh-caret sc-vh-panel-caret"><i class="bi bi-chevron-right"></i></span>
                    </summary>
                    <div class="sc-panel-body">
                        <?php echo $visitTreeParts['nav']; ?>
                    </div>
                </details>
                <?php if ((int)($visitHistory['total_direct_lab_orders'] ?? 0) > 0): ?>
                <details class="sc-panel sc-vh-panel sc-clinic-panel" open>
                    <summary class="sc-panel-header sc-vh-panel-header">
                        <i class="bi bi-clipboard2-pulse"></i> Direct Lab
                        <span class="badge bg-light text-dark ms-auto">
                            <?php echo (int)($visitHistory['total_direct_lab_orders'] ?? 0); ?> Walk-in
                        </span>
                        <span class="sc-vh-caret sc-vh-panel-caret"><i class="bi bi-chevron-right"></i></span>
                    </summary>
                    <div class="sc-panel-body">
                        <?php echo medicalDoctorRenderDirectLabOrders($visitHistory['direct_lab_orders'] ?? [], 'en'); ?>
                    </div>
                </details>
                <?php endif; ?>
            </aside>
        <?php endif; ?>

        <div class="sc-main-panels">
            <?php if ($visitTreeParts !== null): ?>
            <div class="sc-vt-detail" data-vt-detail hidden>
                <div class="sc-vt-detail-bar">
                    <span class="sc-vt-detail-bar-title"><i class="bi bi-clock-history"></i> Selected visit</span>
                    <button type="button" class="sc-vt-detail-close" data-vt-close aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <?php echo $visitTreeParts['detail']; ?>
            </div>
            <?php endif; ?>
            <form method="post" id="prescription-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="patient_id" id="patient-id-input"
                       value="<?php echo $selectedPatientId > 0 ? (int)$selectedPatientId : ''; ?>">
                <?php if ($showClinicalFields): ?>
                <input type="hidden" name="draft_id" id="draft-id-input" value="<?php echo $draftId > 0 ? (int)$draftId : ''; ?>">
                <div class="sc-autosave" id="draft-autosave-status" role="status" aria-live="polite" hidden>
                    <i class="bi bi-cloud-check"></i> <span class="sc-autosave-text"></span>
                </div>
                <?php endif; ?>

                <?php if ($showClinicalFields): ?>
                <?php
                    echo medicalDoctorRenderMarkdownField(
                        'history',
                        'History',
                        'bi-journal-text',
                        $history,
                        'Chief complaint & patient history…',
                        false,
                        true,
                        'history',
                        $sectionAttachments['history'] ?? []
                    );
                    echo medicalDoctorRenderMarkdownField(
                        'examination',
                        'Examination',
                        'bi-clipboard2-pulse',
                        $examination,
                        'Physical examination findings…',
                        false,
                        true,
                        'examination',
                        $sectionAttachments['examination'] ?? []
                    );
                    echo medicalDoctorRenderMarkdownField(
                        'diagnosis',
                        'Diagnoses',
                        'bi-journal-medical',
                        $diagnosis,
                        'Diagnosis / assessment…',
                        false,
                        true,
                        'diagnosis',
                        $sectionAttachments['diagnosis'] ?? []
                    );
                ?>
                <?php endif; ?>

                <div class="sc-panel">
                    <div class="sc-panel-header">
                        <i class="bi bi-capsule"></i> Treatment
                    </div>
                    <div class="sc-panel-body">
                        <div class="mb-3 position-relative sc-product-search-wrap">
                            <label class="form-label" for="product-search">Search medications</label>
                            <input type="text" class="form-control" id="product-search" autocomplete="off"
                                   placeholder="Search by name or barcode">
                            <div class="prescription-search-results list-group" id="product-search-results" role="listbox"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Selected medications</label>
                            <div id="selected-products">
                        <?php foreach ($selectedItemsForRender as $item): ?>
                            <div class="rx-item-row card border-0 mb-2" data-id="<?php echo (int)$item['product_id']; ?>">
                                <div class="card-body py-2 px-3 d-flex flex-wrap gap-2 align-items-center">
                                    <div class="flex-grow-1 fw-semibold rx-item-name">
                                        <?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="rx-item-field">
                                        <label class="rx-item-mini-label">Qty</label>
                                        <input type="number" class="form-control form-control-sm" name="quantities[]"
                                               min="0.01" step="any" style="width: 90px;"
                                               value="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.') ?: '1', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="rx-item-field">
                                        <label class="rx-item-mini-label">Unit</label>
                                        <select class="form-select form-select-sm" name="product_unit_ids[]" style="min-width: 130px;">
                                            <option value="">— Unit —</option>
                                            <?php foreach ($item['units'] as $unit): ?>
                                                <option value="<?php echo (int)$unit['product_unit_id']; ?>"
                                                    <?php echo ((int)$item['selected_unit_id'] === (int)$unit['product_unit_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string)$unit['unit_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <input type="hidden" name="product_ids[]" value="<?php echo (int)$item['product_id']; ?>">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-product" aria-label="Remove">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                            </div>
                            <div id="selected-products-empty" class="text-body-secondary small<?php echo empty($selectedItemsForRender) ? '' : ' d-none'; ?>">
                                No medications selected yet
                            </div>
                        </div>

                        <button type="submit" class="btn sc-btn-primary">
                            <i class="bi bi-send-check"></i> Save &amp; send to Pharmacy
                        </button>
                        <?php if ($showClinicalFields): ?>
                            <p class="text-body-secondary small mt-2 mb-0">
                                <i class="bi bi-info-circle"></i>
                                History, Examination &amp; Diagnoses save automatically. Nothing is sent to the pharmacy until you add a medication and press this button.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($selectedPatient !== null && $referralTargets !== []): ?>
<div class="modal fade" id="referralModal" tabindex="-1" aria-labelledby="referralModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?php echo htmlspecialchars($referralActionUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="referralModalLabel">
                        <i class="bi bi-arrow-left-right"></i> Refer patient to a doctor
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="patient_id" value="<?php echo (int)$selectedPatient['id']; ?>">
                    <p class="text-body-secondary small mb-3">
                        <i class="bi bi-person"></i>
                        <?php echo htmlspecialchars((string)$selectedPatient['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="referral-to-doctor">Specialist doctor</label>
                        <select class="form-select" id="referral-to-doctor" name="to_doctor_id" required>
                            <option value="">— Select a doctor —</option>
                            <?php foreach ($referralTargets as $target): ?>
                                <option value="<?php echo (int)$target['id']; ?>">
                                    <?php echo htmlspecialchars((string)$target['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="referral-note">Note <span class="text-body-secondary">(optional)</span></label>
                        <textarea class="form-control" id="referral-note" name="note" rows="3"
                                  maxlength="1000" placeholder="Reason for referral, findings so far…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn sc-btn-primary">
                        <i class="bi bi-send"></i> Send referral
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
