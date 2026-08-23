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

$orderId = (int)($_GET['id'] ?? 0);
$order = labFetchOrder($conn_kasher_platform, $userId, $orderId);
if (!$order || (int)$order['lab_id'] !== $labId) {
    setMessage('داواکاری نەدۆزرایەوە', 'danger');
    redirect(url('professions/medical-center/lab/orders/index.php'));
}

$orderTests = labFetchOrderTests($conn_kasher_platform, $orderId);
$orderStatus = (string)$order['status'];
$isCompleted = $orderStatus === 'completed';
$isDirectOrder = labOrderIsDirect($order);
$orderNotes = trim((string)($order['notes'] ?? ''));
$doctorNotes = !$isDirectOrder ? $orderNotes : '';
$labNotes = $isDirectOrder ? $orderNotes : '';
$patientGender = $order['gender'] ?? null;

// --- Edit order tests (add / remove) — works for both direct and doctor-referral orders ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $redirectUrl = url('professions/medical-center/lab/orders/fill.php?id=' . $orderId);

    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        setMessage('نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە', 'danger');
        redirect($redirectUrl);
    }
    if ($isCompleted) {
        setMessage('ئەم داواکارییە تەواوبووە — دەستکاری فەحسەکان ناکرێت', 'danger');
        redirect($redirectUrl);
    }

    if ($action === 'add_tests') {
        $addTestIds = array_map('intval', (array)($_POST['test_ids'] ?? []));
        $addTestIds = array_values(array_filter(array_unique($addTestIds), static fn($id) => $id > 0));

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

        if ($addTestIds === []) {
            setMessage('تکایە لانیکەم یەک فەحس هەڵبژێرە', 'danger');
        } else {
            $added = labAddTestsToOrder($conn_kasher_platform, $userId, $labId, $orderId, $addTestIds, $selectedRowIdsByTest);
            if ($added > 0) {
                setMessage("{$added} فەحس زیادکرا", 'success');
            } else {
                setMessage('هیچ فەحسێکی نوێ زیاد نەکرا', 'warning');
            }
        }
        redirect($redirectUrl);
    }

    if ($action === 'remove_test') {
        $removeOrderTestId = (int)($_POST['order_test_id'] ?? 0);
        if ($removeOrderTestId > 0 && labRemoveOrderTest($conn_kasher_platform, $orderId, $removeOrderTestId)) {
            setMessage('فەحس سڕایەوە', 'success');
        } else {
            setMessage('سڕینەوەی فەحس سەرکەوتوو نەبوو', 'danger');
        }
        redirect($redirectUrl);
    }

    redirect($redirectUrl);
}

// Tests available to add (active catalog tests not already in this order).
$availableGroupedTests = [];
$availableTestRows = [];
if (!$isCompleted) {
    $existingLookup = array_fill_keys(labOrderExistingTestIds($conn_kasher_platform, $orderId), true);
    foreach (labFetchTests($conn_kasher_platform, $userId, $labId, true) as $catalogTest) {
        $catalogTestId = (int)$catalogTest['id'];
        if (isset($existingLookup[$catalogTestId])) {
            continue;
        }
        $key = trim((string)($catalogTest['group_name'] ?? ''));
        $availableGroupedTests[$key][] = $catalogTest;
        $availableTestRows[$catalogTestId] = labFetchRows($conn_kasher_platform, $catalogTestId);
    }
}

$pageTitle = 'پڕکردنەوەی ئەنجام';
$activeNav = 'orders';
$bodyClass = 'lab-dashboard-page';
$columnTypes = labColumnTypes();
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="lab-page-header d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h4 class="mb-0">پڕکردنەوەی ئەنجام</h4>
        <p class="lab-page-subtitle">داواکاری #<?php echo (int)$orderId; ?> — ئەنجامەکان خۆکار پاشەکەوت دەکرێن</p>
    </div>
    <a href="<?php echo url('professions/medical-center/lab/orders/index.php'); ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-right"></i> گەڕانەوە
    </a>
</div>

<?php if ($doctorNotes !== ''): ?>
    <div class="alert alert-info border-0 shadow-sm mb-3">
        <div class="fw-semibold mb-1"><i class="bi bi-chat-left-text"></i> تێبینی دکتۆر</div>
        <div class="mb-0"><?php echo nl2br(htmlspecialchars($doctorNotes, ENT_QUOTES, 'UTF-8')); ?></div>
    </div>
<?php endif; ?>

<?php if ($labNotes !== ''): ?>
    <div class="alert alert-info border-0 shadow-sm mb-3">
        <div class="fw-semibold mb-1"><i class="bi bi-chat-left-text"></i> تێبینی</div>
        <div class="mb-0"><?php echo nl2br(htmlspecialchars($labNotes, ENT_QUOTES, 'UTF-8')); ?></div>
    </div>
<?php endif; ?>

<?php if ($isCompleted): ?>
    <div class="alert alert-success border-0 shadow-sm mb-3">
        <i class="bi bi-check-circle"></i> ئەم داواکارییە تەواوبووە — دەستکاری ئەنجام ناکرێت.
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
            <div>
                <h5 class="mb-1"><i class="bi bi-person"></i> <?php echo htmlspecialchars((string)$order['patient_name'], ENT_QUOTES, 'UTF-8'); ?></h5>
                <div class="text-body-secondary small">
                    <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$order['patient_mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                    ·
                    <span><?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$order['age'], isset($order['age_months']) ? (int)$order['age_months'] : null), ENT_QUOTES, 'UTF-8'); ?>
                    · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($order['gender'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                    ·
                    <span><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars(labOrderDoctorDisplay($order), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($isDirectOrder): ?>
                        <span class="badge text-bg-secondary ms-1">دەرەکی</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-end">
                <div class="mb-2">
                    <span class="text-body-secondary small">دۆخ:</span>
                    <span class="badge text-bg-<?php echo labOrderStatusBadge($orderStatus); ?>" id="status-badge"><?php echo htmlspecialchars(labOrderStatusLabel($orderStatus), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="btn-group btn-group-sm" role="group" id="status-btn-group">
                    <?php foreach (labOrderStatuses() as $key => $meta): ?>
                        <button type="button"
                                class="btn btn-outline-secondary btn-set-status<?php echo $orderStatus === $key ? ' active' : ''; ?>"
                                data-status="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php if ($isCompleted): ?>
                    <div class="mt-2">
                        <a href="<?php echo url('professions/medical-center/lab/orders/receipt.php?id=' . (int)$orderId); ?>" target="_blank" class="btn btn-sm btn-primary">
                            <i class="bi bi-printer"></i> چاپی وەسڵ
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (empty($orderTests)): ?>
    <div class="alert alert-info">هیچ فەحسێک لەم داواکارییەدا نییە.</div>
<?php endif; ?>

<?php foreach ($orderTests as $orderTest): ?>
    <?php
    $orderTestId = (int)$orderTest['id'];
    $testId = (int)$orderTest['test_id'];
    $columns = labFetchColumns($conn_kasher_platform, $testId);
    $rows = labFetchRows($conn_kasher_platform, $testId);
    $rows = labFilterRowsBySelection($rows, labFetchOrderTestRowIds($conn_kasher_platform, $orderTestId));
    $cells = labFetchCells($conn_kasher_platform, $testId);
    $results = labFetchOrderResults($conn_kasher_platform, $orderTestId);
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                <h6 class="mb-0"><i class="bi bi-clipboard-data text-primary"></i> <?php echo htmlspecialchars((string)$orderTest['test_name_snapshot'], ENT_QUOTES, 'UTF-8'); ?></h6>
                <?php if (!$isCompleted): ?>
                    <form method="post" action="<?php echo url('professions/medical-center/lab/orders/fill.php?id=' . (int)$orderId); ?>"
                          class="lab-remove-test-form m-0"
                          onsubmit="return confirm('سڕینەوەی ئەم فەحسە لە داواکارییەکە؟ ئەنجامە تۆمارکراوەکانیشی دەسڕێنەوە.');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="remove_test">
                        <input type="hidden" name="order_test_id" value="<?php echo $orderTestId; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="سڕینەوەی فەحس">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (empty($columns) || empty($rows)): ?>
                <p class="text-body-secondary mb-0">خشتەی ئەم فەحسە بەتاڵە.</p>
            <?php else: ?>
                <?php
                $colTypeClass = [
                    'result' => 'lab-col-result',
                    'reference' => 'lab-col-reference',
                    'unit' => 'lab-col-unit',
                    'status' => 'lab-col-status',
                    'text' => 'lab-col-text',
                    'choice' => 'lab-col-choice',
                ];
                ?>
                <div class="lab-designer-wrap">
                    <table class="lab-designer-table" data-order-test-id="<?php echo $orderTestId; ?>">
                        <thead>
                            <tr>
                                <th class="lab-sticky-col lab-corner-cell lab-col-parameter" style="min-width: 160px;">
                                    <i class="bi bi-list-ul"></i> ناو
                                </th>
                                <?php foreach ($columns as $col): ?>
                                    <?php
                                    $colType = (string)$col['col_type'];
                                    $widthStyle = labColumnWidthStyle($col['width'] ?? null);
                                    ?>
                                    <th class="lab-col-head <?php echo htmlspecialchars($colTypeClass[$colType] ?? 'lab-col-' . $colType, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php if ($widthStyle !== ''): ?>style="<?php echo htmlspecialchars($widthStyle, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                                        <div class="lab-col-head-inner">
                                            <div class="lab-col-head-text">
                                                <span class="col-label"><?php echo htmlspecialchars((string)$col['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="lab-col-type-badge"><?php echo htmlspecialchars($columnTypes[$colType] ?? $colType, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $rowId = (int)$row['id'];
                                $rowFlag = labRowResultFlag($columns, $results, $rowId);
                                ?>
                                <tr data-row-id="<?php echo $rowId; ?>">
                                    <td class="lab-sticky-col lab-fill-param-cell"><?php echo htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php foreach ($columns as $col): ?>
                                        <?php
                                        $colId = (int)$col['id'];
                                        $type = (string)$col['col_type'];
                                        $cell = $results[$rowId][$colId] ?? ['value' => '', 'flag' => null];
                                        $widthStyle = labColumnWidthStyle($col['width'] ?? null);
                                        $tdClass = $colTypeClass[$type] ?? 'lab-col-' . $type;
                                        $flagClass = labResultFlagClass($cell['flag'] ?? null);
                                        ?>
                                        <td class="<?php echo htmlspecialchars($tdClass, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-col-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-column-id="<?php echo $colId; ?>"
                                            <?php if ($widthStyle !== ''): ?>style="<?php echo htmlspecialchars($widthStyle, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                                            <?php if ($type === 'result'): ?>
                                                <input type="text" class="lab-cell-input lab-result-input<?php echo $flagClass !== '' ? ' ' . htmlspecialchars($flagClass, ENT_QUOTES, 'UTF-8') : ''; ?>"
                                                       data-order-test-id="<?php echo $orderTestId; ?>"
                                                       data-row-id="<?php echo $rowId; ?>"
                                                       data-column-id="<?php echo $colId; ?>"
                                                       value="<?php echo htmlspecialchars((string)$cell['value'], ENT_QUOTES, 'UTF-8'); ?>"
                                                       <?php echo $isCompleted ? 'readonly disabled' : ''; ?>
                                                       maxlength="255">
                                            <?php elseif ($type === 'choice'): ?>
                                                <?php
                                                $choiceOptions = labResolveChoiceOptions(
                                                    labCellOptionsJson($cells, $rowId, $colId),
                                                    $col['options'] ?? null
                                                );
                                                ?>
                                                <select class="lab-choice-input<?php echo $flagClass !== '' ? ' ' . htmlspecialchars($flagClass, ENT_QUOTES, 'UTF-8') : ''; ?>"
                                                        data-order-test-id="<?php echo $orderTestId; ?>"
                                                        data-row-id="<?php echo $rowId; ?>"
                                                        data-column-id="<?php echo $colId; ?>"
                                                        <?php echo ($isCompleted || $choiceOptions === []) ? 'disabled' : ''; ?>>
                                                    <option value=""><?php echo $choiceOptions === [] ? '— هەڵبژاردن نییە —' : '— هەڵبژێرە —'; ?></option>
                                                    <?php foreach ($choiceOptions as $opt): ?>
                                                        <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"
                                                            <?php echo (string)$cell['value'] === $opt ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php elseif ($type === 'status'): ?>
                                                <div class="lab-derived-cell status-cell text-center">
                                                    <span class="lab-flag-slot d-inline-flex justify-content-center w-100"><?php echo labFlagMarkup($rowFlag); ?></span>
                                                </div>
                                            <?php elseif ($type === 'reference'): ?>
                                                <div class="lab-derived-cell reference-cell"><?php echo labReferenceDisplayHtml($row, $patientGender); ?></div>
                                            <?php elseif ($type === 'unit'): ?>
                                                <div class="lab-derived-cell unit-cell"><?php echo htmlspecialchars((string)($row['unit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php elseif ($type === 'text'): ?>
                                                <?php $textValue = isset($results[$rowId][$colId]) ? (string)$results[$rowId][$colId]['value'] : labCellContent($cells, $rowId, $colId); ?>
                                                <input type="text" class="lab-cell-input lab-text-input"
                                                       data-order-test-id="<?php echo $orderTestId; ?>"
                                                       data-row-id="<?php echo $rowId; ?>"
                                                       data-column-id="<?php echo $colId; ?>"
                                                       value="<?php echo htmlspecialchars($textValue, ENT_QUOTES, 'UTF-8'); ?>"
                                                       <?php echo $isCompleted ? 'readonly disabled' : ''; ?>
                                                       maxlength="255">
                                            <?php else: ?>
                                                <div class="lab-derived-cell"><?php echo htmlspecialchars(labCellContent($cells, $rowId, $colId), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php if (!$isCompleted): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-plus-circle text-success"></i> زیادکردنی فەحس بۆ داواکارییەکە</h6>
                <button type="button" class="btn btn-sm btn-outline-success" id="add-tests-toggle" aria-expanded="false">
                    <i class="bi bi-plus-lg"></i> زیادکردنی فەحس
                </button>
            </div>

            <div id="add-tests-panel" class="d-none mt-3">
                <?php if (empty($availableGroupedTests)): ?>
                    <div class="alert alert-info mb-0">هیچ فەحسێکی نوێ بۆ زیادکردن نییە.</div>
                <?php else: ?>
                    <form method="post" action="<?php echo url('professions/medical-center/lab/orders/fill.php?id=' . (int)$orderId); ?>" id="add-tests-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="add_tests">

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="add-test-search" class="form-control" autocomplete="off"
                                       placeholder="گەڕان بۆ فەحس بە ناو...">
                            </div>
                            <div class="form-text">
                                <i class="bi bi-info-circle"></i>
                                فەحس هەڵبژێرە. بۆ دیاریکردنی چەند خانەیەکی دیاریکراو لە فەحسێکدا، دوگمەی «خانەکان» بکەرەوە.
                            </div>
                        </div>

                        <div id="add-tests-no-results" class="queue-empty py-4 d-none">
                            <i class="bi bi-inbox"></i>
                            <p class="mb-0">هیچ فەحسێک نەدۆزرایەوە</p>
                        </div>

                        <?php foreach ($availableGroupedTests as $groupName => $groupTests): ?>
                            <div class="mb-3 lab-test-group">
                                <?php if ($groupName !== ''): ?>
                                    <div class="lab-group-title mb-2"><i class="bi bi-folder2"></i> <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php foreach ($groupTests as $test): ?>
                                    <?php
                                    $tid = (int)$test['id'];
                                    $rowsForTest = $availableTestRows[$tid] ?? [];
                                    ?>
                                    <div class="lab-test-item border rounded mb-2"
                                         data-test-item
                                         data-test-name="<?php echo htmlspecialchars(mb_strtolower((string)$test['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="d-flex align-items-center gap-2 p-2">
                                            <div class="form-check flex-grow-1 m-0">
                                                <input class="form-check-input lab-test-check" type="checkbox" name="test_ids[]"
                                                       value="<?php echo $tid; ?>"
                                                       id="add_test_<?php echo $tid; ?>"
                                                       data-test-id="<?php echo $tid; ?>">
                                                <label class="form-check-label" for="add_test_<?php echo $tid; ?>">
                                                    <?php echo htmlspecialchars((string)$test['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </label>
                                            </div>
                                            <?php if (!empty($rowsForTest)): ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary lab-rows-toggle"
                                                        data-target="addrows_<?php echo $tid; ?>"
                                                        aria-expanded="false">
                                                    <i class="bi bi-sliders"></i>
                                                    <span>خانەکان</span>
                                                    <span class="badge text-bg-secondary lab-rows-badge d-none"></span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($rowsForTest)): ?>
                                            <div class="lab-rows-panel border-top p-2 d-none" id="addrows_<?php echo $tid; ?>">
                                                <div class="text-body-secondary small mb-2">
                                                    <i class="bi bi-hand-index"></i>
                                                    لەسەر خانەکان دابگرە بۆ هەڵبژاردن — بەتاڵ بمێنێتەوە بۆ هەموو خانەکان
                                                </div>
                                                <div class="lab-row-chips">
                                                    <input type="checkbox" class="btn-check lab-rows-all"
                                                           id="addrowsall_<?php echo $tid; ?>"
                                                           data-target-panel="addrows_<?php echo $tid; ?>"
                                                           autocomplete="off">
                                                    <label class="btn btn-outline-primary rounded-pill lab-chip lab-chip-all" for="addrowsall_<?php echo $tid; ?>">
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
                                                               id="addrow_<?php echo $tid; ?>_<?php echo $rowId; ?>"
                                                               data-panel="addrows_<?php echo $tid; ?>"
                                                               data-test-id="<?php echo $tid; ?>"
                                                               autocomplete="off">
                                                        <label class="btn btn-outline-success rounded-pill lab-chip" for="addrow_<?php echo $tid; ?>_<?php echo $rowId; ?>">
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

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check2-circle"></i> زیادکردن بۆ داواکارییەکە
                            </button>
                            <span class="badge text-bg-success align-self-center" id="add-selected-count">٠ هەڵبژێردراو</span>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
        .lab-test-item { transition: border-color .15s ease, background-color .15s ease; }
        .lab-test-item.is-selected { border-color: var(--bs-success) !important; background: rgba(25, 135, 84, .05); }
        .lab-rows-toggle .lab-rows-badge { margin-inline-start: .25rem; }
        .lab-rows-panel { background: rgba(0, 0, 0, .02); border-radius: 0 0 .375rem .375rem; }
        .lab-row-chips { display: flex; flex-wrap: wrap; gap: .45rem; }
        .lab-row-chips .lab-chip { --bs-btn-padding-y: .4rem; --bs-btn-padding-x: .9rem; font-weight: 500; }
        .lab-row-chips .lab-chip-all { border-style: dashed; }
    </style>

    <script>
    (function () {
        const toggle = document.getElementById('add-tests-toggle');
        const panel = document.getElementById('add-tests-panel');
        if (toggle && panel) {
            toggle.addEventListener('click', function () {
                const willOpen = panel.classList.contains('d-none');
                panel.classList.toggle('d-none', !willOpen);
                toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        }

        const form = document.getElementById('add-tests-form');
        if (!form) { return; }
        const countBadge = document.getElementById('add-selected-count');
        const searchInput = document.getElementById('add-test-search');
        const noResults = document.getElementById('add-tests-no-results');

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
            const p = document.getElementById(panelId);
            const btn = form.querySelector('.lab-rows-toggle[data-target="' + panelId + '"]');
            if (!p || !btn) { return; }
            const badge = btn.querySelector('.lab-rows-badge');
            const checked = p.querySelectorAll('.lab-row-check:checked').length;
            if (badge) {
                if (checked > 0) { badge.textContent = String(checked); badge.classList.remove('d-none'); }
                else { badge.textContent = ''; badge.classList.add('d-none'); }
            }
            btn.classList.toggle('active', checked > 0);
        }

        function syncAllCheckbox(panelId) {
            const p = document.getElementById(panelId);
            if (!p) { return; }
            const boxes = p.querySelectorAll('.lab-row-check');
            const checked = p.querySelectorAll('.lab-row-check:checked');
            const all = p.querySelector('.lab-rows-all');
            if (all) {
                all.checked = boxes.length > 0 && checked.length === boxes.length;
                all.indeterminate = checked.length > 0 && checked.length < boxes.length;
            }
        }

        function ensureTestChecked(item) {
            const check = item && item.querySelector('.lab-test-check');
            if (check && !check.checked) { check.checked = true; markSelected(item); updateCount(); }
        }

        form.querySelectorAll('.lab-rows-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const p = document.getElementById(btn.dataset.target);
                if (!p) { return; }
                const willOpen = p.classList.contains('d-none');
                p.classList.toggle('d-none', !willOpen);
                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (willOpen) { ensureTestChecked(btn.closest('.lab-test-item')); }
            });
        });

        form.querySelectorAll('.lab-rows-all').forEach(function (all) {
            all.addEventListener('change', function () {
                const panelId = all.dataset.targetPanel;
                const p = document.getElementById(panelId);
                if (!p) { return; }
                p.querySelectorAll('.lab-row-check').forEach(function (box) { box.checked = all.checked; });
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
                    const p = item.querySelector('.lab-rows-panel');
                    if (p) {
                        p.querySelectorAll('.lab-row-check').forEach(function (b) { b.checked = false; });
                        p.classList.add('d-none');
                        const btn = item.querySelector('.lab-rows-toggle');
                        if (btn) { btn.setAttribute('aria-expanded', 'false'); }
                        const all = p.querySelector('.lab-rows-all');
                        if (all) { all.checked = false; all.indeterminate = false; }
                        refreshRowsBadge(p.id);
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

        form.addEventListener('submit', function (e) {
            if (form.querySelectorAll('.lab-test-check:checked').length === 0) {
                e.preventDefault();
                alert('تکایە لانیکەم یەک فەحس هەڵبژێرە');
            }
        });

        updateCount();
    })();
    </script>
<?php endif; ?>

<div class="lab-toast" id="lab-toast"></div>

<script>
(function () {
    const endpoint = '<?php echo url('professions/medical-center/lab/api/results.php'); ?>';
    const csrf = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    const orderId = <?php echo (int)$orderId; ?>;
    const toastEl = document.getElementById('lab-toast');
    let toastTimer = null;
    let currentStatus = '<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>';

    function showToast(message, ok) {
        toastEl.textContent = message;
        toastEl.className = 'lab-toast show ' + (ok ? 'ok' : 'err');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = 'lab-toast'; }, 2200);
    }

    async function api(action, data) {
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('action', action);
        body.append('order_id', String(orderId));
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        try {
            const res = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
            return await res.json();
        } catch (e) {
            return { success: false, message: 'هەڵە لە پەیوەندی' };
        }
    }

    function updateStatusUi(status, label, badge) {
        currentStatus = status;
        const statusBadge = document.getElementById('status-badge');
        statusBadge.textContent = label;
        statusBadge.className = 'badge text-bg-' + badge;
        document.querySelectorAll('.btn-set-status').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.status === status);
        });
        if (status === 'completed') {
            document.querySelectorAll('.lab-result-input, .lab-text-input, .lab-choice-input').forEach(function (input) {
                input.readOnly = true;
                input.disabled = true;
            });
            window.location.reload();
        }
    }

    function applyResultFlagClass(input, flag) {
        input.classList.remove('lab-result-high', 'lab-result-low', 'lab-result-abnormal');
        if (flag === 'high' || flag === 'low') {
            input.classList.add('lab-result-' + flag);
        } else if (flag === 'abnormal') {
            input.classList.add('lab-result-abnormal');
        }
    }

    function updateRowFlags(input, markup, flag) {
        const tr = input.closest('tr');
        if (!tr) {
            return;
        }
        const slot = tr.querySelector('td[data-col-type="status"] .lab-flag-slot');
        if (slot) {
            slot.innerHTML = markup || '';
        }
        if (input.classList.contains('lab-result-input') || input.classList.contains('lab-choice-input')) {
            applyResultFlagClass(input, flag || null);
        }
    }

    document.querySelectorAll('.lab-result-input').forEach(function (input) {
        input.addEventListener('change', async function () {
            if (currentStatus === 'completed') {
                return;
            }
            const r = await api('save_cell', {
                order_test_id: this.dataset.orderTestId,
                row_id: this.dataset.rowId,
                column_id: this.dataset.columnId,
                value: this.value
            });
            if (r.success) {
                updateRowFlags(this, r.flag_markup || '', r.flag || null);
            } else if (r.message) {
                showToast(r.message, false);
            }
        });
    });

    document.querySelectorAll('.lab-text-input').forEach(function (input) {
        input.addEventListener('change', async function () {
            if (currentStatus === 'completed') {
                return;
            }
            const r = await api('save_cell', {
                order_test_id: this.dataset.orderTestId,
                row_id: this.dataset.rowId,
                column_id: this.dataset.columnId,
                value: this.value
            });
            if (!r.success && r.message) {
                showToast(r.message, false);
            }
        });
    });

    document.querySelectorAll('.lab-choice-input').forEach(function (input) {
        input.addEventListener('change', async function () {
            if (currentStatus === 'completed') {
                return;
            }
            const r = await api('save_cell', {
                order_test_id: this.dataset.orderTestId,
                row_id: this.dataset.rowId,
                column_id: this.dataset.columnId,
                value: this.value
            });
            if (r.success) {
                updateRowFlags(this, r.flag_markup || '', r.flag || null);
            } else if (r.message) {
                showToast(r.message, false);
            }
        });
    });

    document.querySelectorAll('.btn-set-status').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const r = await api('set_status', { status: this.dataset.status });
            if (r.success) {
                updateStatusUi(r.status, r.status_label, r.status_badge);
                showToast(r.message || 'پاشەکەوتکرا', true);
            } else if (r.message) {
                showToast(r.message, false);
            }
        });
    });
})();
</script>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
