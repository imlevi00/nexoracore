<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
require_once dirname(__DIR__) . '/includes/markdown_field.php';
require_once dirname(__DIR__) . '/includes/prescription_attachments.php';
require_once dirname(__DIR__) . '/includes/referral_service.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */
/** @var mysqli $conn */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];
$csrfToken = Security::generateCSRFToken();
$prescriptionId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

if ($prescriptionId <= 0) {
    setMessage('Invalid prescription ID.', 'danger');
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}

// --- Confirm the prescription exists within this tenant --------------------
// Scoped to the tenant (user_id) only. Ownership is resolved afterwards: the
// doctor who wrote it gets full edit access, while a prescription written by
// another doctor is opened read-only when this doctor can access the patient
// (owns them or was referred). That lets a referral specialist review the
// referring doctor's prior work from the patient's history.
$headerStmt = $conn_kasher_platform->prepare("
    SELECT mp.id, mp.doctor_id, mp.patient_id, mp.history, mp.examination, mp.diagnosis, mp.status, mp.created_at,
           p.name AS patient_name, p.mobile AS patient_mobile,
           p.age, p.age_months, p.gender,
           d.name AS doctor_name
    FROM medical_prescriptions mp
    INNER JOIN medical_center_patients p ON p.id = mp.patient_id
    INNER JOIN doctors d ON d.id = mp.doctor_id
    WHERE mp.id = ? AND mp.user_id = ?
    LIMIT 1
");
$headerStmt->bind_param('ii', $prescriptionId, $userId);
$headerStmt->execute();
$prescription = $headerStmt->get_result()->fetch_assoc();
$headerStmt->close();

if (!$prescription) {
    setMessage('Prescription not found.', 'warning');
    redirect(url('professions/medical-center/doctor/dashboard/index.php'));
}

$patientId = (int)$prescription['patient_id'];

// Only the authoring doctor can edit. A non-owner may still view read-only, but
// only if they can access the patient; otherwise it stays out of their reach.
$canEdit = ((int)$prescription['doctor_id'] === $doctorId);
if (!$canEdit) {
    $accessiblePatient = medicalDoctorFetchAccessiblePatient($conn_kasher_platform, $userId, $doctorId, $patientId);
    if ($accessiblePatient === null) {
        setMessage('Prescription not found.', 'warning');
        redirect(url('professions/medical-center/doctor/dashboard/index.php'));
    }
}

// --- Handle an edit submission (update in place) ---------------------------
// Editing is available only to the authoring doctor ($canEdit). For any other
// viewer the page is strictly read-only, so ?edit and POSTs are ignored.
$isEdit = $canEdit && isset($_GET['edit']) && $_GET['edit'] !== '0';

if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isEdit = true;

    $history = trim((string)($_POST['history'] ?? ''));
    $examination = trim((string)($_POST['examination'] ?? ''));
    $diagnosis = trim((string)($_POST['diagnosis'] ?? ''));

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

    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security validation failed. Please try again.';
    }
    if ($diagnosis === '') {
        $errors[] = 'Diagnosis is required.';
    }
    if (empty($selectedProductIds)) {
        $errors[] = 'Please select at least one medication.';
    }

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
            $unitLookupStmt = $conn->prepare("
                SELECT pu.id AS product_unit_id, pu.product_id, u.name AS unit_name
                FROM product_units pu
                INNER JOIN units u ON u.id = pu.unit_id
                WHERE pu.product_id IN ($unitPlaceholders)
            ");
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

    if (empty($errors)) {
        $conn_kasher_platform->begin_transaction();
        try {
            $historyValue = $history !== '' ? $history : null;
            $examinationValue = $examination !== '' ? $examination : null;

            $updateStmt = $conn_kasher_platform->prepare("
                UPDATE medical_prescriptions
                SET history = ?, examination = ?, diagnosis = ?, updated_at = NOW()
                WHERE id = ? AND user_id = ? AND doctor_id = ?
            ");
            if (!$updateStmt) {
                throw new RuntimeException('Update prescription prepare failed');
            }
            $updateStmt->bind_param(
                'sssiii',
                $historyValue,
                $examinationValue,
                $diagnosis,
                $prescriptionId,
                $userId,
                $doctorId
            );
            if (!$updateStmt->execute()) {
                throw new RuntimeException('Update prescription execute failed');
            }
            $updateStmt->close();

            $deleteStmt = $conn_kasher_platform->prepare("
                DELETE FROM medical_prescription_items WHERE prescription_id = ?
            ");
            if (!$deleteStmt) {
                throw new RuntimeException('Delete items prepare failed');
            }
            $deleteStmt->bind_param('i', $prescriptionId);
            if (!$deleteStmt->execute()) {
                throw new RuntimeException('Delete items execute failed');
            }
            $deleteStmt->close();

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

            setMessage('Prescription #' . $prescriptionId . ' updated successfully.', 'success');
            redirect(url('professions/medical-center/doctor/prescriptions/view.php?id=' . $prescriptionId));
        } catch (Throwable $e) {
            $conn_kasher_platform->rollback();
            $errors[] = 'Something went wrong while saving the changes.';
        }
    }

    // Reflect the submitted values back into the form on error.
    $prescription['history'] = $history;
    $prescription['examination'] = $examination;
    $prescription['diagnosis'] = $diagnosis;
}

// --- Load the items for the current prescription ---------------------------
$itemsStmt = $conn_kasher_platform->prepare("
    SELECT product_id, product_name_snapshot, quantity, product_unit_id, unit_name_snapshot
    FROM medical_prescription_items
    WHERE prescription_id = ?
    ORDER BY id ASC
");
$itemsStmt->bind_param('i', $prescriptionId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

$status = (string)$prescription['status'];
$itemCount = count($items);

// Image attachments for the clinical-note sections, grouped by section.
$sectionAttachments = medicalRxAttachmentsForPrescription($conn_kasher_platform, $prescriptionId);

// In edit mode, pre-build medication rows (with the product's available units).
$selectedItemsForRender = [];
if ($isEdit) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Rebuild rows from what was just submitted so nothing is lost on error.
        foreach ($selectedProductIds as $productId) {
            $unitOptions = [];
            foreach (($unitMeta[$productId] ?? []) as $puId => $unitName) {
                $unitOptions[] = ['product_unit_id' => (int)$puId, 'unit_name' => (string)$unitName];
            }
            $selectedItemsForRender[] = [
                'product_id'       => (int)$productId,
                'name'             => (string)($productMeta[$productId] ?? ('#' . $productId)),
                'quantity'         => (float)($submittedItems[$productId]['quantity'] ?? 1),
                'selected_unit_id' => $submittedItems[$productId]['product_unit_id'] ?? null,
                'units'            => $unitOptions,
            ];
        }
    } elseif ($items !== []) {
        $itemProductIds = array_map(static fn($it) => (int)$it['product_id'], $items);
        $unitMetaEdit = [];
        $uph = implode(',', array_fill(0, count($itemProductIds), '?'));
        $unitLookupStmt = $conn->prepare("
            SELECT pu.id AS product_unit_id, pu.product_id, u.name AS unit_name
            FROM product_units pu
            INNER JOIN units u ON u.id = pu.unit_id
            WHERE pu.product_id IN ($uph)
        ");
        if ($unitLookupStmt) {
            $unitLookupStmt->bind_param(str_repeat('i', count($itemProductIds)), ...$itemProductIds);
            $unitLookupStmt->execute();
            $unitRows = $unitLookupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $unitLookupStmt->close();
            foreach ($unitRows as $ur) {
                $unitMetaEdit[(int)$ur['product_id']][(int)$ur['product_unit_id']] = (string)$ur['unit_name'];
            }
        }
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $unitOptions = [];
            foreach (($unitMetaEdit[$pid] ?? []) as $puId => $unitName) {
                $unitOptions[] = ['product_unit_id' => (int)$puId, 'unit_name' => (string)$unitName];
            }
            $selectedItemsForRender[] = [
                'product_id'       => $pid,
                'name'             => (string)$item['product_name_snapshot'],
                'quantity'         => (float)$item['quantity'],
                'selected_unit_id' => isset($item['product_unit_id']) ? (int)$item['product_unit_id'] : null,
                'units'            => $unitOptions,
            ];
        }
    }
}

$doctorUiLocale = 'en';
$pageTitle = $isEdit ? 'Edit Prescription' : 'View Prescription';
$activeNav = 'history';
$bodyClass = 'doctor-smart-clinic-page';
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">';
if ($isEdit) {
    $prescriptionSearchEndpoint = url('professions/medical-center/doctor/api/products.php?action=search_products');
    $extraHead .= '<script>window.__scPrescriptionCreate=' . json_encode([
        'searchEndpoint' => $prescriptionSearchEndpoint,
        'labelQty' => 'Qty',
        'labelUnit' => 'Unit',
        'unitPlaceholder' => '— Unit —',
        'noResults' => 'No results found',
        'searchError' => 'Search error',
        'searching' => 'Searching…',
    ], JSON_UNESCAPED_UNICODE) . ';</script>'
        . '<script>window.__scPrescriptionImages=' . json_encode([
            'endpoint' => url('professions/medical-center/doctor/api/prescription_image.php'),
            'csrfToken' => $csrfToken,
            'patientId' => $patientId,
            'prescriptionId' => $prescriptionId,
            'uploadingText' => 'Uploading…',
            'uploadError' => 'Upload failed',
            'deleteConfirm' => 'Remove this image?',
            'tooLarge' => 'Image is too large (max 8MB)',
            'notImage' => 'Only image files are allowed',
        ], JSON_UNESCAPED_UNICODE) . ';</script>';
}
// doctor-ui.js is loaded in both modes: it powers the edit-mode uploader and the
// read-mode image lightbox.
$extraHead .= '<script src="' . asset('js/medical_center/doctor-ui.js') . '" defer></script>';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="sc-page"<?php echo $isEdit ? ' data-sc-page="prescription-create"' : ''; ?>>
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title"><?php echo $isEdit ? 'Edit' : ''; ?> Prescription #<?php echo (int)$prescription['id']; ?></h1>
            <p class="sc-page-subtitle">
                <i class="bi bi-calendar3"></i>
                Created <?php echo htmlspecialchars((string)$prescription['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                · Dr. <?php echo htmlspecialchars((string)$prescription['doctor_name'], ENT_QUOTES, 'UTF-8'); ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge <?php echo medicalCenterPrescriptionStatusBadgeClass($status); ?> fs-6">
                <?php echo htmlspecialchars(medicalCenterPrescriptionStatusLabel($status, 'en'), ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <?php if ($isEdit): ?>
                <a class="btn btn-outline-secondary btn-sm"
                   href="<?php echo url('professions/medical-center/doctor/prescriptions/view.php?id=' . $prescriptionId); ?>">
                    <i class="bi bi-x-lg"></i> Cancel
                </a>
            <?php else: ?>
                <?php if ($canEdit): ?>
                <a class="btn sc-btn-primary btn-sm"
                   href="<?php echo url('professions/medical-center/doctor/prescriptions/view.php?id=' . $prescriptionId . '&edit=1'); ?>">
                    <i class="bi bi-pencil-square"></i> Edit
                </a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary btn-sm"
                   href="<?php echo url('professions/medical-center/doctor/prescriptions/index.php'); ?>">
                    <i class="bi bi-arrow-left"></i> Back to history
                </a>
            <?php endif; ?>
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

    <div class="sc-workspace">
        <aside class="sc-sidebar">
            <div class="sc-patient-card">
                <div class="sc-patient-name"><?php echo htmlspecialchars((string)$prescription['patient_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="sc-patient-detail">
                    <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$prescription['patient_mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span>
                        <i class="bi bi-person"></i>
                        <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$prescription['age'], isset($prescription['age_months']) ? (int)$prescription['age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                        · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($prescription['gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>
            <div class="sc-send-to">
                <div class="sc-send-to-title">Send To</div>
                <div class="sc-send-to-grid">
                    <a class="sc-send-to-btn sc-send-lab"
                       href="<?php echo url('professions/medical-center/doctor/lab-orders/create.php?patient_id=' . $patientId); ?>">
                        <i class="bi bi-clipboard2-pulse"></i> Lab
                    </a>
                    <a class="sc-send-to-btn sc-send-rx"
                       href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php?patient_id=' . $patientId); ?>">
                        <i class="bi bi-file-earmark-medical"></i> Pharmacy
                    </a>
                    <a class="sc-send-to-btn sc-send-history"
                       href="<?php echo url('professions/medical-center/doctor/patients/history.php?patient_id=' . $patientId); ?>">
                        <i class="bi bi-diagram-3"></i> History
                    </a>
                    <a class="sc-send-to-btn sc-send-change"
                       href="<?php echo url('professions/medical-center/doctor/prescriptions/create.php'); ?>">
                        <i class="bi bi-arrow-repeat"></i> Change
                    </a>
                </div>
            </div>
        </aside>

        <div class="sc-main-panels">
            <?php if ($isEdit): ?>
                <form method="post" id="prescription-form"
                      action="<?php echo url('professions/medical-center/doctor/prescriptions/view.php?id=' . $prescriptionId . '&edit=1'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?php echo $prescriptionId; ?>">

                    <?php
                        echo medicalDoctorRenderMarkdownField(
                            'history',
                            'History',
                            'bi-journal-text',
                            (string)($prescription['history'] ?? ''),
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
                            (string)($prescription['examination'] ?? ''),
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
                            (string)$prescription['diagnosis'],
                            'Diagnosis / assessment…',
                            true,
                            true,
                            'diagnosis',
                            $sectionAttachments['diagnosis'] ?? []
                        );
                    ?>

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

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn sc-btn-primary">
                                    <i class="bi bi-check2-circle"></i> Save changes
                                </button>
                                <a class="btn btn-outline-secondary"
                                   href="<?php echo url('professions/medical-center/doctor/prescriptions/view.php?id=' . $prescriptionId); ?>">
                                    Cancel
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <?php
                    $noteSections = [
                        ['History', 'bi-journal-text', (string)($prescription['history'] ?? ''), 'history'],
                        ['Examination', 'bi-clipboard2-pulse', (string)($prescription['examination'] ?? ''), 'examination'],
                        ['Diagnoses', 'bi-journal-medical', (string)$prescription['diagnosis'], 'diagnosis'],
                    ];
                    foreach ($noteSections as [$noteLabel, $noteIcon, $noteValue, $noteSection]):
                        $noteHtml = medicalCenterRenderMarkdown($noteValue);
                        $noteImages = $sectionAttachments[$noteSection] ?? [];
                        $noteGallery = medicalDoctorRenderAttachmentStrip($noteImages);
                ?>
                    <div class="sc-panel mb-3">
                        <div class="sc-panel-header">
                            <i class="bi <?php echo $noteIcon; ?>"></i> <?php echo htmlspecialchars($noteLabel, ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($noteImages !== []): ?>
                                <span class="badge bg-light text-dark ms-auto"><i class="bi bi-images"></i> <?php echo count($noteImages); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="sc-panel-body">
                            <?php if ($noteHtml !== ''): ?>
                                <div class="sc-md-render"><?php echo $noteHtml; ?></div>
                            <?php elseif ($noteGallery === ''): ?>
                                <p class="text-body-secondary mb-0">—</p>
                            <?php endif; ?>
                            <?php echo $noteGallery; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="sc-panel">
                    <div class="sc-panel-header">
                        <i class="bi bi-capsule"></i> Treatment (<?php echo $itemCount; ?>)
                    </div>
                    <div class="sc-panel-body">
                        <?php if ($items === []): ?>
                            <p class="text-body-secondary mb-0">No medications recorded</p>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                    $qtyLabel = rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.');
                                    if ($qtyLabel === '') {
                                        $qtyLabel = '1';
                                    }
                                    $unitLabel = trim((string)($item['unit_name_snapshot'] ?? ''));
                                ?>
                                <div class="rx-item-row card border-0 mb-2">
                                    <div class="card-body py-2 px-3 d-flex flex-wrap gap-2 align-items-center">
                                        <div class="flex-grow-1 fw-semibold rx-item-name">
                                            <?php echo htmlspecialchars((string)$item['product_name_snapshot'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <span class="badge text-bg-light border text-body">
                                            <i class="bi bi-box-seam"></i>
                                            <?php echo htmlspecialchars($qtyLabel, ENT_QUOTES, 'UTF-8'); ?><?php echo $unitLabel !== '' ? ' ' . htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8') : ''; ?>
                                        </span>
                                        <small class="text-body-secondary">#<?php echo (int)$item['product_id']; ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
