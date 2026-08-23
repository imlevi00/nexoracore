<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
require_once dirname(__DIR__) . '/includes/lab_catalog_service.php';
require_once dirname(__DIR__) . '/includes/lab_order_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];
$csrfToken = Security::generateCSRFToken();
$errors = [];

// Which kind of patient this order is for:
//   'clinic'   — a patient the secretary already registered (medical_center_patients).
//                Used when someone who saw a doctor before now wants a test first.
//   'external' — a walk-in the lab records itself (lab_patients).
$patientSource = (string)($_POST['patient_source'] ?? 'clinic');
if (!in_array($patientSource, ['clinic', 'external'], true)) {
    $patientSource = 'clinic';
}
$clinicPatientId = (int)($_POST['clinic_patient_id'] ?? 0);

$name = trim((string)($_POST['name'] ?? ''));
$mobile = trim((string)($_POST['mobile'] ?? ''));
$age = (int)($_POST['age'] ?? -1);
$ageMonthsInput = (string)($_POST['age_months'] ?? '');
$ageMonths = medicalCenterNormalizePatientAgeMonths($ageMonthsInput);
$gender = medicalCenterNormalizePatientGender((string)($_POST['gender'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$selectedTestIds = array_map('intval', (array)($_POST['test_ids'] ?? []));
$selectedTestIds = array_values(array_filter(array_unique($selectedTestIds), static fn($id) => $id > 0));

$rawRowSelections = (array)($_POST['row_ids'] ?? []);
$selectedRowIdsByTest = [];
foreach ($rawRowSelections as $testKey => $rowList) {
    $tid = (int)$testKey;
    if ($tid <= 0) {
        continue;
    }
    $ids = array_values(array_filter(array_unique(array_map('intval', (array)$rowList)), static fn($id) => $id > 0));
    if ($ids !== []) {
        $selectedRowIdsByTest[$tid] = $ids;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    }
    if ($labId <= 0) {
        $errors[] = 'هیچ تاقیگەیەک ڕێکنەخراوە';
    }

    $clinicPatient = null;
    if ($patientSource === 'clinic') {
        $clinicPatient = $clinicPatientId > 0
            ? labFindClinicPatient($conn_kasher_platform, $userId, $clinicPatientId)
            : null;
        if ($clinicPatient === null) {
            $errors[] = 'تکایە نەخۆشێکی کلینیک هەڵبژێرە';
        }
    } else {
        if ($name === '' || $mobile === '' || $age < 0 || $age > 130 || $gender === null) {
            $errors[] = 'تکایە زانیاریی نەخۆش بە دروستی بنووسە';
        }
        if (trim($ageMonthsInput) !== '' && $ageMonths === null) {
            $errors[] = 'ژمارەی مانگ دەبێت لە نێوان 0 تا 11 بێت';
        }
        if ($mobile !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $mobile)) {
            $errors[] = 'ژمارەی مۆبایل نادروستە';
        }
    }

    if (empty($selectedTestIds)) {
        $errors[] = 'تکایە لانیکەم یەک فەحس هەڵبژێرە';
    }

    $testMeta = [];
    if (empty($errors) && !empty($selectedTestIds)) {
        $testMeta = labValidateOrderTestIds($conn_kasher_platform, $userId, $labId, $selectedTestIds);
        if (count($testMeta) !== count($selectedTestIds)) {
            $errors[] = 'یەکێک لە فەحسە هەڵبژێردراوەکان نەگونجاوە';
        }
    }

    if (empty($errors)) {
        $notesValue = $notes !== '' ? $notes : null;

        if ($patientSource === 'clinic') {
            $orderId = labCreateDirectClinicOrder(
                $conn_kasher_platform,
                $userId,
                $labId,
                (int)$clinicPatient['id'],
                $selectedTestIds,
                $notesValue,
                $selectedRowIdsByTest
            );
            if ($orderId <= 0) {
                $errors[] = 'هەڵەیەک ڕوویدا لە دروستکردنی داواکاری';
            } else {
                setMessage("داواکاری تاقیگە تۆمارکرا بۆ {$clinicPatient['name']}", 'success');
                redirect(url('professions/medical-center/lab/orders/fill.php?id=' . $orderId));
            }
        } else {
            $labPatientId = labUpsertWalkInPatient(
                $conn_kasher_platform,
                $userId,
                $labId,
                $name,
                $mobile,
                $age,
                $ageMonths,
                $gender
            );
            if ($labPatientId <= 0) {
                $errors[] = 'هەڵەیەک ڕوویدا لە تۆمارکردنی نەخۆش';
            } else {
                $orderId = labCreateDirectOrder(
                    $conn_kasher_platform,
                    $userId,
                    $labId,
                    $labPatientId,
                    $selectedTestIds,
                    $notesValue,
                    $selectedRowIdsByTest
                );
                if ($orderId <= 0) {
                    $errors[] = 'هەڵەیەک ڕوویدا لە دروستکردنی داواکاری';
                } else {
                    setMessage("داواکاری تاقیگە تۆمارکرا بۆ {$name}", 'success');
                    redirect(url('professions/medical-center/lab/orders/fill.php?id=' . $orderId));
                }
            }
        }
    }
}

// For rendering: restore the selected clinic patient after a failed POST (or when
// arriving with a preselected id), so the picker keeps its selection.
$selectedClinicPatient = null;
if ($clinicPatientId > 0) {
    $selectedClinicPatient = labFindClinicPatient($conn_kasher_platform, $userId, $clinicPatientId);
    if ($selectedClinicPatient === null) {
        $clinicPatientId = 0;
    }
}

$tests = $labId > 0 ? labFetchTests($conn_kasher_platform, $userId, $labId, true) : [];
$groupedTests = [];
foreach ($tests as $test) {
    $key = trim((string)($test['group_name'] ?? ''));
    $groupedTests[$key][] = $test;
}

$testRows = [];
foreach ($tests as $test) {
    $testRows[(int)$test['id']] = labFetchRows($conn_kasher_platform, (int)$test['id']);
}

$pageTitle = 'داواکاری نوێ — فەحسی ڕاستەوخۆ';
$activeNav = 'orders_create';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h4 class="mb-0">داواکاری نوێ</h4>
        <p class="text-body-secondary mb-0 small">فەحس بۆ نەخۆشی کلینیک یان دەرەکی — بەبێ داواکاری دکتۆر</p>
    </div>
    <a href="<?php echo url('professions/medical-center/lab/orders/index.php'); ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-right"></i> گەڕانەوە
    </a>
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

<form method="post" id="direct-order-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="clinic_patient_id" id="clinic-patient-id"
           value="<?php echo $clinicPatientId > 0 ? (int)$clinicPatientId : ''; ?>">

    <div class="btn-group w-100 mb-4" role="group" aria-label="جۆری نەخۆش" id="patient-source-toggle">
        <input type="radio" class="btn-check" name="patient_source" id="src-clinic" value="clinic"
               autocomplete="off" <?php echo $patientSource === 'clinic' ? 'checked' : ''; ?>>
        <label class="btn btn-outline-primary" for="src-clinic">
            <i class="bi bi-hospital"></i> نەخۆشی کلینیک
        </label>
        <input type="radio" class="btn-check" name="patient_source" id="src-external" value="external"
               autocomplete="off" <?php echo $patientSource === 'external' ? 'checked' : ''; ?>>
        <label class="btn btn-outline-primary" for="src-external">
            <i class="bi bi-person-plus"></i> نەخۆشی دەرەکی
        </label>
    </div>

    <div class="card border-0 shadow-sm mb-4<?php echo $patientSource === 'clinic' ? '' : ' d-none'; ?>"
         id="clinic-patient-panel" data-source-panel="clinic">
        <div class="card-body">
            <h5 class="mb-1"><i class="bi bi-hospital text-primary"></i> نەخۆشی کلینیک</h5>
            <p class="text-body-secondary small mb-3">
                گەڕان بۆ نەخۆشێک کە پێشتر لای سکرتێر تۆمارکراوە بۆ نووسینی فەحس بەبێ داواکاری دکتۆر
            </p>

            <div id="clinic-picker" class="<?php echo $selectedClinicPatient !== null ? 'd-none' : ''; ?>">
                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="clinic-search" class="form-control" autocomplete="off"
                           placeholder="گەڕان بە ناو یان مۆبایل...">
                </div>
                <div id="clinic-results" class="lab-clinic-results"></div>
                <div id="clinic-results-empty" class="queue-empty py-4 d-none">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">هیچ نەخۆشێک نەدۆزرایەوە</p>
                </div>
                <div id="clinic-results-hint" class="form-text">
                    <i class="bi bi-info-circle"></i> بۆ گەڕان ناو یان ژمارەی مۆبایل بنووسە
                </div>
            </div>

            <div id="clinic-selected" class="lab-clinic-selected<?php echo $selectedClinicPatient !== null ? '' : ' d-none'; ?>">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-check-fill text-success fs-4"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold" id="clinic-selected-name">
                            <?php echo $selectedClinicPatient !== null ? htmlspecialchars((string)$selectedClinicPatient['name'], ENT_QUOTES, 'UTF-8') : ''; ?>
                        </div>
                        <div class="text-body-secondary small" id="clinic-selected-meta">
                            <?php
                            if ($selectedClinicPatient !== null) {
                                $ageLbl = medicalCenterPatientAgeLabel(
                                    (int)$selectedClinicPatient['age'],
                                    isset($selectedClinicPatient['age_months']) ? (int)$selectedClinicPatient['age_months'] : null,
                                    'ku'
                                );
                                $genderLbl = medicalCenterPatientGenderLabel($selectedClinicPatient['gender'] ?? null, 'ku');
                                echo htmlspecialchars(
                                    (string)$selectedClinicPatient['mobile'] . ' · ' . $ageLbl . ' · ' . $genderLbl,
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            }
                            ?>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="clinic-change">
                        <i class="bi bi-arrow-repeat"></i> گۆڕین
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4<?php echo $patientSource === 'external' ? '' : ' d-none'; ?>"
         id="external-patient-panel" data-source-panel="external">
        <div class="card-body">
            <h5 class="mb-3"><i class="bi bi-person-plus text-primary"></i> نەخۆشی دەرەکی</h5>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">ناوی نەخۆش <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="patient-name" class="form-control" required maxlength="150"
                           value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">مۆبایل <span class="text-danger">*</span></label>
                    <input type="tel" name="mobile" id="patient-mobile" class="form-control" required maxlength="30"
                           value="<?php echo htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="07xx xxx xxxx">
                    <div class="form-text" id="mobile-lookup-hint"></div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">تەمەن (ساڵ) <span class="text-danger">*</span></label>
                    <input type="number" name="age" id="patient-age" class="form-control" min="0" max="130" required
                           value="<?php echo $age >= 0 ? (int)$age : ''; ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">مانگ <small class="text-body-secondary">(ئارەزوومەندانە)</small></label>
                    <input type="number" name="age_months" id="patient-age-months" class="form-control" min="0" max="11"
                           value="<?php echo htmlspecialchars($ageMonthsInput, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">ڕەگەز <span class="text-danger">*</span></label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="gender" id="gender-male" value="male"
                                   <?php echo $gender === 'male' ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="gender-male">نێر</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="gender" id="gender-female" value="female"
                                   <?php echo $gender === 'female' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="gender-female">مێ</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-clipboard2-pulse text-success"></i> هەڵبژاردنی فەحسەکان</h5>
                <span class="badge text-bg-success" id="selected-tests-count">٠ هەڵبژێردراو</span>
            </div>

            <?php if (empty($tests)): ?>
                <div class="alert alert-info mb-0">هیچ فەحسێکی چالاک نییە. سەرەتا فەحسەکان دروست بکە.</div>
            <?php else: ?>
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="test-search" class="form-control" autocomplete="off"
                               placeholder="گەڕان بۆ فەحس بە ناو...">
                    </div>
                    <div class="form-text">
                        <i class="bi bi-info-circle"></i>
                        فەحس هەڵبژێرە. بۆ دیاریکردنی چەند خانەیەکی دیاریکراو لە فەحسێکدا، دوگمەی «خانەکان» بکەرەوە.
                    </div>
                </div>

                <div id="tests-no-results" class="queue-empty py-4 d-none">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">هیچ فەحسێک نەدۆزرایەوە</p>
                </div>

                <?php foreach ($groupedTests as $groupName => $groupTests): ?>
                    <div class="mb-3 lab-test-group">
                        <?php if ($groupName !== ''): ?>
                            <div class="lab-group-title mb-2"><i class="bi bi-folder2"></i> <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php foreach ($groupTests as $test): ?>
                            <?php
                            $tid = (int)$test['id'];
                            $rowsForTest = $testRows[$tid] ?? [];
                            $testChecked = in_array($tid, $selectedTestIds, true);
                            $chosenRows = $selectedRowIdsByTest[$tid] ?? [];
                            $panelOpen = $chosenRows !== [];
                            ?>
                            <div class="lab-test-item border rounded mb-2"
                                 data-test-item
                                 data-test-name="<?php echo htmlspecialchars(mb_strtolower((string)$test['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="d-flex align-items-center gap-2 p-2">
                                    <div class="form-check flex-grow-1 m-0">
                                        <input class="form-check-input lab-test-check" type="checkbox" name="test_ids[]"
                                               value="<?php echo $tid; ?>"
                                               id="test_<?php echo $tid; ?>"
                                               data-test-id="<?php echo $tid; ?>"
                                               <?php echo $testChecked ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="test_<?php echo $tid; ?>">
                                            <?php echo htmlspecialchars((string)$test['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </label>
                                    </div>
                                    <?php if (!empty($rowsForTest)): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary lab-rows-toggle<?php echo $panelOpen ? ' active' : ''; ?>"
                                                data-target="rows_<?php echo $tid; ?>"
                                                aria-expanded="<?php echo $panelOpen ? 'true' : 'false'; ?>">
                                            <i class="bi bi-sliders"></i>
                                            <span>خانەکان</span>
                                            <span class="badge text-bg-secondary lab-rows-badge<?php echo $panelOpen ? '' : ' d-none'; ?>"></span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($rowsForTest)): ?>
                                    <div class="lab-rows-panel border-top p-2<?php echo $panelOpen ? '' : ' d-none'; ?>" id="rows_<?php echo $tid; ?>">
                                        <div class="text-body-secondary small mb-2">
                                            <i class="bi bi-hand-index"></i>
                                            لەسەر خانەکان دابگرە بۆ هەڵبژاردن — بەتاڵ بمێنێتەوە بۆ هەموو خانەکان
                                        </div>
                                        <div class="lab-row-chips">
                                            <input type="checkbox" class="btn-check lab-rows-all"
                                                   id="rowsall_<?php echo $tid; ?>"
                                                   data-target-panel="rows_<?php echo $tid; ?>"
                                                   autocomplete="off">
                                            <label class="btn btn-outline-primary rounded-pill lab-chip lab-chip-all" for="rowsall_<?php echo $tid; ?>">
                                                <i class="bi bi-check-all"></i> هەموو
                                            </label>
                                            <?php foreach ($rowsForTest as $rIndex => $rowItem): ?>
                                                <?php
                                                $rowId = (int)$rowItem['id'];
                                                $rowName = trim((string)$rowItem['name']);
                                                if ($rowName === '') {
                                                    $rowName = 'خانەی ' . ($rIndex + 1);
                                                }
                                                ?>
                                                <input type="checkbox" class="btn-check lab-row-check"
                                                       name="row_ids[<?php echo $tid; ?>][]"
                                                       value="<?php echo $rowId; ?>"
                                                       id="row_<?php echo $tid; ?>_<?php echo $rowId; ?>"
                                                       data-panel="rows_<?php echo $tid; ?>"
                                                       data-test-id="<?php echo $tid; ?>"
                                                       autocomplete="off"
                                                       <?php echo in_array($rowId, $chosenRows, true) ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-success rounded-pill lab-chip" for="row_<?php echo $tid; ?>_<?php echo $rowId; ?>">
                                                    <?php echo htmlspecialchars($rowName, ENT_QUOTES, 'UTF-8'); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="mb-0 mt-3">
                <label class="form-label">تێبینی <small class="text-body-secondary">(ئارەزوومەندانە)</small></label>
                <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-success btn-lg" <?php echo (empty($tests) || $labId <= 0) ? 'disabled' : ''; ?>>
        <i class="bi bi-check2-circle"></i> تۆمارکردنی داواکاری
    </button>
</form>

<style>
    .lab-test-item { transition: border-color .15s ease, background-color .15s ease; }
    .lab-test-item.is-selected { border-color: var(--bs-success) !important; background: rgba(25, 135, 84, .05); }
    .lab-rows-toggle .lab-rows-badge { margin-inline-start: .25rem; }
    .lab-rows-panel { background: rgba(0, 0, 0, .02); border-radius: 0 0 .375rem .375rem; }
    .lab-row-chips { display: flex; flex-wrap: wrap; gap: .45rem; }
    .lab-row-chips .lab-chip { --bs-btn-padding-y: .4rem; --bs-btn-padding-x: .9rem; font-weight: 500; }
    .lab-row-chips .lab-chip-all { border-style: dashed; }
    .lab-clinic-results { display: flex; flex-direction: column; gap: .5rem; }
    .lab-clinic-result {
        display: flex; align-items: center; gap: .75rem; width: 100%;
        text-align: start; padding: .6rem .8rem; border: 1px solid var(--bs-border-color);
        border-radius: .5rem; background: var(--bs-body-bg); cursor: pointer;
        transition: border-color .15s ease, background-color .15s ease;
    }
    .lab-clinic-result:hover { border-color: var(--bs-primary); background: rgba(13, 110, 253, .04); }
    .lab-clinic-result .lab-clinic-result-name { font-weight: 600; }
    .lab-clinic-result .lab-clinic-result-meta { font-size: .82rem; color: var(--bs-secondary-color); }
    .lab-clinic-selected {
        border: 1px solid var(--bs-success); border-radius: .5rem;
        padding: .75rem .9rem; background: rgba(25, 135, 84, .06);
    }
</style>

<script>
(function () {
    const lookupEndpoint = '<?php echo url('professions/medical-center/lab/api/walk_in_patient.php'); ?>';
    const csrf = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    const mobileInput = document.getElementById('patient-mobile');
    const hintEl = document.getElementById('mobile-lookup-hint');
    let lookupTimer = null;

    async function lookupPatient() {
        const mobile = (mobileInput.value || '').trim();
        if (mobile.length < 7) {
            hintEl.textContent = '';
            hintEl.className = 'form-text';
            return;
        }
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('action', 'lookup_by_mobile');
        body.append('mobile', mobile);
        try {
            const res = await fetch(lookupEndpoint, { method: 'POST', body, credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) {
                return;
            }
            if (data.found && data.patient) {
                const p = data.patient;
                document.getElementById('patient-name').value = p.name || '';
                document.getElementById('patient-age').value = p.age ?? '';
                document.getElementById('patient-age-months').value = p.age_months ?? '';
                if (p.gender === 'male') {
                    document.getElementById('gender-male').checked = true;
                } else if (p.gender === 'female') {
                    document.getElementById('gender-female').checked = true;
                }
                hintEl.textContent = 'نەخۆشی پێشتر تۆمارکراو — زانیاری بارکرا';
                hintEl.className = 'form-text text-success';
            } else {
                hintEl.textContent = 'نەخۆشی نوێ';
                hintEl.className = 'form-text text-body-secondary';
            }
        } catch (e) {
            /* ignore lookup errors */
        }
    }

    if (mobileInput) {
        mobileInput.addEventListener('blur', lookupPatient);
        mobileInput.addEventListener('input', function () {
            clearTimeout(lookupTimer);
            lookupTimer = setTimeout(lookupPatient, 600);
        });
    }
})();

(function () {
    // Patient-source toggle (clinic vs external) + clinic-patient live search.
    const form = document.getElementById('direct-order-form');
    if (!form) { return; }
    const clinicPanel = document.getElementById('clinic-patient-panel');
    const externalPanel = document.getElementById('external-patient-panel');
    const radios = form.querySelectorAll('input[name="patient_source"]');

    // Disabled fields are neither submitted nor validated, so the hidden panel's
    // required inputs can't block the form. Toggle disabled with visibility.
    function setPanelActive(panel, active) {
        if (!panel) { return; }
        panel.classList.toggle('d-none', !active);
        panel.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = !active;
        });
    }

    function currentSource() {
        const checked = form.querySelector('input[name="patient_source"]:checked');
        return checked ? checked.value : 'clinic';
    }

    function applySource() {
        const source = currentSource();
        setPanelActive(clinicPanel, source === 'clinic');
        setPanelActive(externalPanel, source === 'external');
    }

    radios.forEach(function (r) { r.addEventListener('change', applySource); });

    // --- Clinic patient search ---
    const searchEndpoint = '<?php echo url('professions/medical-center/lab/api/clinic_patient.php'); ?>';
    const csrf = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    const searchInput = document.getElementById('clinic-search');
    const resultsEl = document.getElementById('clinic-results');
    const emptyEl = document.getElementById('clinic-results-empty');
    const hintEl = document.getElementById('clinic-results-hint');
    const pickerEl = document.getElementById('clinic-picker');
    const selectedEl = document.getElementById('clinic-selected');
    const selectedName = document.getElementById('clinic-selected-name');
    const selectedMeta = document.getElementById('clinic-selected-meta');
    const hiddenId = document.getElementById('clinic-patient-id');
    const changeBtn = document.getElementById('clinic-change');
    let searchTimer = null;

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function showPicker() {
        if (pickerEl) { pickerEl.classList.remove('d-none'); }
        if (selectedEl) { selectedEl.classList.add('d-none'); }
        if (hiddenId) { hiddenId.value = ''; }
        if (searchInput) { searchInput.focus(); }
    }

    function selectPatient(p) {
        if (hiddenId) { hiddenId.value = p.id; }
        if (selectedName) { selectedName.textContent = p.name || ''; }
        if (selectedMeta) {
            const parts = [p.mobile, p.age_label, p.gender_label].filter(Boolean);
            selectedMeta.textContent = parts.join(' · ');
        }
        if (pickerEl) { pickerEl.classList.add('d-none'); }
        if (selectedEl) { selectedEl.classList.remove('d-none'); }
    }

    function renderResults(list) {
        if (!resultsEl) { return; }
        resultsEl.innerHTML = '';
        if (!list.length) {
            if (emptyEl) { emptyEl.classList.remove('d-none'); }
            return;
        }
        if (emptyEl) { emptyEl.classList.add('d-none'); }
        list.forEach(function (p) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'lab-clinic-result';
            const meta = [p.mobile, p.age_label, p.gender_label].filter(Boolean).join(' · ');
            const doc = p.doctor_name ? '<div class="lab-clinic-result-meta"><i class="bi bi-person-badge"></i> د. ' + esc(p.doctor_name) + '</div>' : '';
            btn.innerHTML =
                '<i class="bi bi-person text-primary"></i>' +
                '<span class="flex-grow-1">' +
                    '<span class="lab-clinic-result-name d-block">' + esc(p.name) + '</span>' +
                    '<span class="lab-clinic-result-meta">' + esc(meta) + '</span>' +
                    doc +
                '</span>';
            btn.addEventListener('click', function () { selectPatient(p); });
            resultsEl.appendChild(btn);
        });
    }

    async function runSearch() {
        const q = (searchInput.value || '').trim();
        if (hintEl) { hintEl.classList.toggle('d-none', q !== ''); }
        if (q === '') {
            renderResults([]);
            if (emptyEl) { emptyEl.classList.add('d-none'); }
            return;
        }
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('action', 'search');
        body.append('q', q);
        try {
            const res = await fetch(searchEndpoint, { method: 'POST', body, credentials: 'same-origin' });
            const data = await res.json();
            if (data.success) { renderResults(data.patients || []); }
        } catch (e) { /* ignore */ }
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(runSearch, 350);
        });
    }
    if (changeBtn) { changeBtn.addEventListener('click', showPicker); }

    applySource();
})();

(function () {
    const form = document.getElementById('direct-order-form');
    if (!form) { return; }
    const countBadge = document.getElementById('selected-tests-count');
    const searchInput = document.getElementById('test-search');
    const noResults = document.getElementById('tests-no-results');

    function updateCount() {
        const n = form.querySelectorAll('.lab-test-check:checked').length;
        if (countBadge) { countBadge.textContent = n + ' هەڵبژێردراو'; }
    }

    function markSelected(item) {
        if (!item) { return; }
        const check = item.querySelector('.lab-test-check');
        item.classList.toggle('is-selected', !!(check && check.checked));
    }

    function refreshRowsBadge(panelId) {
        const panel = document.getElementById(panelId);
        const btn = form.querySelector('.lab-rows-toggle[data-target="' + panelId + '"]');
        if (!panel || !btn) { return; }
        const badge = btn.querySelector('.lab-rows-badge');
        const checked = panel.querySelectorAll('.lab-row-check:checked').length;
        if (badge) {
            if (checked > 0) {
                badge.textContent = String(checked);
                badge.classList.remove('d-none');
            } else {
                badge.textContent = '';
                badge.classList.add('d-none');
            }
        }
        btn.classList.toggle('active', checked > 0);
    }

    function syncAllCheckbox(panelId) {
        const panel = document.getElementById(panelId);
        if (!panel) { return; }
        const boxes = panel.querySelectorAll('.lab-row-check');
        const checked = panel.querySelectorAll('.lab-row-check:checked');
        const all = panel.querySelector('.lab-rows-all');
        if (all) {
            all.checked = boxes.length > 0 && checked.length === boxes.length;
            all.indeterminate = checked.length > 0 && checked.length < boxes.length;
        }
    }

    function ensureTestChecked(item) {
        const check = item && item.querySelector('.lab-test-check');
        if (check && !check.checked) {
            check.checked = true;
            markSelected(item);
            updateCount();
        }
    }

    form.querySelectorAll('.lab-rows-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const panel = document.getElementById(btn.dataset.target);
            if (!panel) { return; }
            const willOpen = panel.classList.contains('d-none');
            panel.classList.toggle('d-none', !willOpen);
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) { ensureTestChecked(btn.closest('.lab-test-item')); }
        });
    });

    form.querySelectorAll('.lab-rows-all').forEach(function (all) {
        all.addEventListener('change', function () {
            const panelId = all.dataset.targetPanel;
            const panel = document.getElementById(panelId);
            if (!panel) { return; }
            panel.querySelectorAll('.lab-row-check').forEach(function (box) { box.checked = all.checked; });
            if (all.checked) { ensureTestChecked(all.closest('.lab-test-item')); }
            refreshRowsBadge(panelId);
            syncAllCheckbox(panelId);
        });
    });

    form.querySelectorAll('.lab-row-check').forEach(function (box) {
        box.addEventListener('change', function () {
            if (box.checked) { ensureTestChecked(box.closest('.lab-test-item')); }
            refreshRowsBadge(box.dataset.panel);
            syncAllCheckbox(box.dataset.panel);
        });
    });

    form.querySelectorAll('.lab-test-check').forEach(function (check) {
        check.addEventListener('change', function () {
            const item = check.closest('.lab-test-item');
            markSelected(item);
            if (!check.checked && item) {
                const panel = item.querySelector('.lab-rows-panel');
                if (panel) {
                    panel.querySelectorAll('.lab-row-check').forEach(function (b) { b.checked = false; });
                    panel.classList.add('d-none');
                    const btn = item.querySelector('.lab-rows-toggle');
                    if (btn) { btn.setAttribute('aria-expanded', 'false'); }
                    const all = panel.querySelector('.lab-rows-all');
                    if (all) { all.checked = false; all.indeterminate = false; }
                    refreshRowsBadge(panel.id);
                }
            }
            updateCount();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q = searchInput.value.trim().toLowerCase();
            let anyVisible = false;
            form.querySelectorAll('.lab-test-group').forEach(function (group) {
                let groupVisible = false;
                group.querySelectorAll('[data-test-item]').forEach(function (item) {
                    const name = item.getAttribute('data-test-name') || '';
                    const match = q === '' || name.indexOf(q) !== -1;
                    item.classList.toggle('d-none', !match);
                    if (match) { groupVisible = true; anyVisible = true; }
                });
                group.classList.toggle('d-none', !groupVisible);
            });
            if (noResults) { noResults.classList.toggle('d-none', anyVisible); }
        });
    }

    form.querySelectorAll('.lab-test-item').forEach(markSelected);
    form.querySelectorAll('.lab-rows-panel').forEach(function (panel) {
        refreshRowsBadge(panel.id);
        syncAllCheckbox(panel.id);
    });
    updateCount();
})();
</script>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
