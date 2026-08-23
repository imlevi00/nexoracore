<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_catalog_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];
$csrfToken = Security::generateCSRFToken();

$testId = (int)($_GET['id'] ?? 0);
$test = labFetchTest($conn_kasher_platform, $userId, $labId, $testId);
if (!$test) {
    setMessage('فەحس نەدۆزرایەوە', 'danger');
    redirect(url('professions/medical-center/lab/tests/index.php'));
}

$columns = labFetchColumns($conn_kasher_platform, $testId);
$rows = labFetchRows($conn_kasher_platform, $testId);
$cells = labFetchCells($conn_kasher_platform, $testId);
$columnTypes = labColumnTypes();

$pageTitle = 'دیزاینی فەحس - ' . $test['name'];
$activeNav = 'tests';
$bodyClass = 'lab-dashboard-page';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <a href="<?php echo url('professions/medical-center/lab/tests/index.php'); ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ فەحسەکان
    </a>
    <span class="badge lab-autosave-badge" id="autosave-indicator">
        <i class="bi bi-cloud-check"></i> خۆکار پاشەکەوت دەکرێت
    </span>
</div>

<!-- Test meta -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small mb-1">ناوی فەحس</label>
                <input type="text" id="test-name" class="form-control" value="<?php echo htmlspecialchars((string)$test['name'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1">گرووپ</label>
                <input type="text" id="test-group" class="form-control" value="<?php echo htmlspecialchars((string)($test['group_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="ئارەزوومەندانە">
            </div>
            <div class="col-md-2">
                <button id="save-meta" class="btn btn-outline-primary w-100"><i class="bi bi-save"></i> پاشەکەوت</button>
            </div>
        </div>
    </div>
</div>

<!-- Grid -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <button class="btn btn-sm btn-primary" id="btn-add-column"><i class="bi bi-plus-square"></i> ستوون</button>
            <button class="btn btn-sm btn-success" id="btn-add-row"><i class="bi bi-plus-circle"></i> ڕیز (Name)</button>
            <div class="lab-legend ms-auto">
                <span><?php echo labFlagMarkup('high'); ?> بەرز</span>
                <span><?php echo labFlagMarkup('low'); ?> نزم</span>
                <span><?php echo labFlagMarkup('normal'); ?> ئاسایی</span>
                <span><?php echo labFlagMarkup('abnormal'); ?> نائاسایی</span>
            </div>
        </div>

        <div id="designer-grid-container">
            <?php include __DIR__ . '/_grid_partial.php'; ?>
        </div>

        <p class="text-body-secondary small mt-3 mb-0">
            <i class="bi bi-info-circle"></i>
            Name و خانە دەقییەکان کاتێک دەرچوویت لێیان خۆکار پاشەکەوت دەکرێن. ڕیزەکان دەتوانیت بە
            <i class="bi bi-grip-vertical"></i> ڕابکێشیت بۆ ڕیزکردن.
        </p>
    </div>
</div>

<!-- Column modal -->
<div class="modal fade" id="columnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="columnModalTitle">ستوون</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="column-id">
                <div class="mb-3">
                    <label class="form-label">ناوی ستوون</label>
                    <input type="text" id="column-label" class="form-control" placeholder="e.g. Result">
                </div>
                <div class="mb-3">
                    <label class="form-label">جۆری ستوون</label>
                    <select id="column-type" class="form-select">
                        <?php foreach ($columnTypes as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        «Result» = خانەی پڕکردنەوە کە بەراورد دەکرێت بە Reference Range. «Reference Range» و «Unit» خۆکار لە ڕێکخستنی ڕیزەوە دەردەکەون. «Status» = هێمای ↑/↓/↔ خۆکار لە وەسڵدا. «Text» = خانەی ئازاد. «Choose» = هەڵبژاردن بۆ هەر ڕیز جیاوازە — لە خانەکانی خشتەدا دابنێ.
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label">پانی ستوون <small class="text-body-secondary">(ئارەزوومەندانە)</small></label>
                    <input type="text" id="column-width" class="form-control" placeholder="نموونە: 120px یان 20%">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">داخستن</button>
                <button type="button" class="btn btn-primary" id="column-save"><i class="bi bi-check2"></i> پاشەکەوت</button>
            </div>
        </div>
    </div>
</div>

<!-- Row settings modal -->
<div class="modal fade" id="rowModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">نۆرمەڵ ڕێنج و یەکە</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="row-id">
                <div class="mb-3">
                    <label class="form-label">جۆری ئەنجام</label>
                    <select id="row-result-type" class="form-select">
                        <option value="numeric">ژمارەیی (min/max)</option>
                        <option value="text">نووسینی / هەڵبژاردن (نموونە: Positive/Negative)</option>
                    </select>
                </div>
                <div id="numeric-range">
                    <div class="mb-3">
                        <div class="fw-semibold small mb-2">نێر</div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">کەمترین (min)</label>
                                <input type="text" id="row-min-male" class="form-control" inputmode="decimal">
                            </div>
                            <div class="col-6">
                                <label class="form-label">زۆرترین (max)</label>
                                <input type="text" id="row-max-male" class="form-control" inputmode="decimal">
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <div class="fw-semibold small mb-2">مێ</div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">کەمترین (min)</label>
                                <input type="text" id="row-min-female" class="form-control" inputmode="decimal">
                            </div>
                            <div class="col-6">
                                <label class="form-label">زۆرترین (max)</label>
                                <input type="text" id="row-max-female" class="form-control" inputmode="decimal">
                            </div>
                        </div>
                    </div>
                    <div class="form-text">ئەگەر تەنها یەکێکیان پڕ بکەیتەوە، تەنها ئەو لایەنە بەراورد دەکرێت.</div>

                    <hr class="my-3">
                    <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between mb-2">
                        <div>
                            <div class="fw-semibold small mb-0">نۆرمەڵ ڕێنج بەپێی تەمەن <span class="text-body-secondary">(ئارەزوومەندانە)</span></div>
                            <div class="form-text mt-0">ئەگەر تەمەن دابنێیت، لەبری ڕێنجی سەرەوە بۆ ئەو تەمەنە بەکاردێت. لە وەسڵدا هەموو تەمەنەکان دەردەکەون و هێمای دۆخ بەپێی تەمەنی نەخۆش دیاری دەکرێت.</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary flex-shrink-0" id="age-range-add">
                            <i class="bi bi-plus-lg"></i> تەمەن زیاد بکە
                        </button>
                    </div>
                    <div id="age-range-list"></div>
                    <div id="age-range-empty" class="text-body-secondary small fst-italic">هیچ تەمەنێک دانەنراوە.</div>
                </div>
                <div id="text-range" class="d-none">
                    <label class="form-label">نرخی ئاسایی (normal)</label>
                    <input type="text" id="row-text" class="form-control" placeholder="نموونە: Negative">
                </div>
                <div class="mt-3">
                    <label class="form-label">یەکە (unit)</label>
                    <input type="text" id="row-unit" class="form-control" placeholder="نموونە: mg/dL">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">داخستن</button>
                <button type="button" class="btn btn-primary" id="row-save"><i class="bi bi-check2"></i> پاشەکەوت</button>
            </div>
        </div>
    </div>
</div>

<div class="lab-toast" id="lab-toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const endpoint = '<?php echo url('professions/medical-center/lab/api/catalog.php'); ?>';
    const csrf = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    const testId = <?php echo (int)$testId; ?>;

    const columnModal = new bootstrap.Modal(document.getElementById('columnModal'));
    const rowModal = new bootstrap.Modal(document.getElementById('rowModal'));
    const gridContainer = document.getElementById('designer-grid-container');
    const toastEl = document.getElementById('lab-toast');
    let toastTimer = null;

    function resetColumnModal(type) {
        document.getElementById('column-type').value = type || 'result';
        document.getElementById('column-width').value = '';
    }

    async function api(action, data) {
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('action', action);
        body.append('test_id', String(testId));
        Object.keys(data || {}).forEach(k => body.append(k, data[k]));
        try {
            const res = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
            return await res.json();
        } catch (e) {
            return { success: false, message: 'هەڵە لە پەیوەندی' };
        }
    }

    function showToast(message, ok) {
        toastEl.textContent = message;
        toastEl.className = 'lab-toast show ' + (ok ? 'ok' : 'err');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastEl.className = 'lab-toast'; }, 1800);
    }

    async function refreshGrid() {
        const r = await api('grid_html', {});
        if (r.success && typeof r.html === 'string') {
            gridContainer.innerHTML = r.html;
            bindGrid();
        }
        return r;
    }

    // Run a structural action then re-render the grid.
    async function structural(action, data, okMsg) {
        const r = await api(action, data);
        if (r.success) {
            await refreshGrid();
            if (okMsg) { showToast(okMsg, true); }
        } else {
            showToast(r.message || 'هەڵە', false);
        }
        return r;
    }

    // ---- Test meta (bound once) ----
    document.getElementById('save-meta').addEventListener('click', async function () {
        const r = await api('update_test', {
            name: document.getElementById('test-name').value,
            group_name: document.getElementById('test-group').value
        });
        showToast(r.success ? 'ناوی فەحس پاشەکەوتکرا' : (r.message || 'هەڵە'), r.success);
    });

    // ---- Add column / add row (bound once) ----
    document.getElementById('btn-add-column').addEventListener('click', function () {
        document.getElementById('columnModalTitle').textContent = 'ستوونی نوێ';
        document.getElementById('column-id').value = '';
        document.getElementById('column-label').value = '';
        resetColumnModal('result');
        columnModal.show();
    });
    document.getElementById('btn-add-row').addEventListener('click', function () {
        structural('add_row', {}, 'ڕیز زیادکرا');
    });

    // ---- Column modal save (bound once) ----
    document.getElementById('column-save').addEventListener('click', async function () {
        const id = document.getElementById('column-id').value;
        const payload = {
            label: document.getElementById('column-label').value,
            col_type: document.getElementById('column-type').value,
            width: document.getElementById('column-width').value
        };
        if (id) { payload.column_id = id; }
        columnModal.hide();
        const r = await structural(id ? 'update_column' : 'add_column', payload, 'ستوون پاشەکەوتکرا');
        if (!r.success && r.message) {
            columnModal.show();
        }
    });

    // ---- Row settings modal (bound once) ----
    const rowTypeSel = document.getElementById('row-result-type');
    function syncRangeVisibility() {
        const isNum = rowTypeSel.value === 'numeric';
        document.getElementById('numeric-range').classList.toggle('d-none', !isNum);
        document.getElementById('text-range').classList.toggle('d-none', isNum);
    }
    rowTypeSel.addEventListener('change', syncRangeVisibility);

    // ---- Age-based reference ranges (optional, numeric rows) ----
    const AGE_UNITS = [['day', 'ڕۆژ'], ['month', 'مانگ'], ['year', 'ساڵ']];
    const AGE_GENDERS = [['any', 'هەردوو'], ['male', 'نێر'], ['female', 'مێ']];
    const ageList = document.getElementById('age-range-list');
    const ageEmpty = document.getElementById('age-range-empty');

    function optionsHtml(pairs, selected) {
        return pairs.map(p => '<option value="' + p[0] + '"' + (p[0] === selected ? ' selected' : '') + '>' + p[1] + '</option>').join('');
    }

    function toggleAgeEmpty() {
        ageEmpty.classList.toggle('d-none', ageList.children.length > 0);
    }

    function makeAgeRow(data) {
        data = data || {};
        const wrap = document.createElement('div');
        wrap.className = 'lab-age-range border rounded p-2 mb-2';
        wrap.innerHTML =
            '<div class="row g-2 align-items-center mb-2">' +
                '<div class="col"><input type="text" class="form-control form-control-sm ar-label" placeholder="ناونیشان: منداڵ / گەورە (ئارەزوو)"></div>' +
                '<div class="col-auto"><select class="form-select form-select-sm ar-gender">' + optionsHtml(AGE_GENDERS, data.gender || 'any') + '</select></div>' +
                '<div class="col-auto"><button type="button" class="btn btn-sm btn-outline-danger ar-del" title="سڕینەوە"><i class="bi bi-trash"></i></button></div>' +
            '</div>' +
            '<div class="row g-2 align-items-center mb-2">' +
                '<div class="col-auto small text-body-secondary">تەمەن لە</div>' +
                '<div class="col"><input type="text" inputmode="decimal" class="form-control form-control-sm ar-min-age" placeholder="لە"></div>' +
                '<div class="col-auto"><select class="form-select form-select-sm ar-min-unit">' + optionsHtml(AGE_UNITS, data.age_min_unit || 'year') + '</select></div>' +
                '<div class="col-auto small text-body-secondary">بۆ</div>' +
                '<div class="col"><input type="text" inputmode="decimal" class="form-control form-control-sm ar-max-age" placeholder="بۆ"></div>' +
                '<div class="col-auto"><select class="form-select form-select-sm ar-max-unit">' + optionsHtml(AGE_UNITS, data.age_max_unit || 'year') + '</select></div>' +
            '</div>' +
            '<div class="row g-2 align-items-center">' +
                '<div class="col-auto small text-body-secondary">ڕێنج</div>' +
                '<div class="col"><input type="text" inputmode="decimal" class="form-control form-control-sm ar-min" placeholder="کەمترین (min)"></div>' +
                '<div class="col-auto">—</div>' +
                '<div class="col"><input type="text" inputmode="decimal" class="form-control form-control-sm ar-max" placeholder="زۆرترین (max)"></div>' +
            '</div>';
        wrap.querySelector('.ar-label').value = data.label || '';
        wrap.querySelector('.ar-min-age').value = data.age_min_value || '';
        wrap.querySelector('.ar-max-age').value = data.age_max_value || '';
        wrap.querySelector('.ar-min').value = data.normal_min || '';
        wrap.querySelector('.ar-max').value = data.normal_max || '';
        wrap.querySelector('.ar-del').addEventListener('click', function () {
            wrap.remove();
            toggleAgeEmpty();
        });
        return wrap;
    }

    function loadAgeRanges(json) {
        ageList.innerHTML = '';
        let arr = [];
        try { arr = JSON.parse(json || '[]'); } catch (e) { arr = []; }
        if (Array.isArray(arr)) {
            arr.forEach(d => ageList.appendChild(makeAgeRow(d)));
        }
        toggleAgeEmpty();
    }

    function collectAgeRanges() {
        const out = [];
        ageList.querySelectorAll('.lab-age-range').forEach(w => {
            out.push({
                label: w.querySelector('.ar-label').value.trim(),
                gender: w.querySelector('.ar-gender').value,
                age_min_value: w.querySelector('.ar-min-age').value.trim(),
                age_min_unit: w.querySelector('.ar-min-unit').value,
                age_max_value: w.querySelector('.ar-max-age').value.trim(),
                age_max_unit: w.querySelector('.ar-max-unit').value,
                normal_min: w.querySelector('.ar-min').value.trim(),
                normal_max: w.querySelector('.ar-max').value.trim()
            });
        });
        return JSON.stringify(out);
    }

    document.getElementById('age-range-add').addEventListener('click', function () {
        ageList.appendChild(makeAgeRow({}));
        toggleAgeEmpty();
    });

    document.getElementById('row-save').addEventListener('click', async function () {
        const id = document.getElementById('row-id').value;
        const tr = gridContainer.querySelector('tr[data-row-id="' + id + '"]');
        if (!tr) { rowModal.hide(); return; }
        const payload = {
            row_id: id,
            name: tr.querySelector('.row-name').value,
            result_type: rowTypeSel.value,
            normal_min_male: document.getElementById('row-min-male').value,
            normal_max_male: document.getElementById('row-max-male').value,
            normal_min_female: document.getElementById('row-min-female').value,
            normal_max_female: document.getElementById('row-max-female').value,
            normal_text: document.getElementById('row-text').value,
            unit: document.getElementById('row-unit').value,
            ranges: collectAgeRanges()
        };
        rowModal.hide();
        await structural('update_row', payload, 'ڕێکخستنی ڕیز پاشەکەوتکرا');
    });

    // ---- Drag & drop helpers for rows ----
    let draggedRow = null;
    function getRowAfter(tbody, y) {
        const rows = [...tbody.querySelectorAll('tr:not(.lab-dragging)')];
        return rows.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            }
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
    }

    // ---- (Re)bind everything inside the grid ----
    function bindGrid() {
        // Column actions
        gridContainer.querySelectorAll('.btn-edit-column').forEach(btn => btn.addEventListener('click', function () {
            document.getElementById('columnModalTitle').textContent = 'دەستکاری ستوون';
            document.getElementById('column-id').value = this.dataset.id;
            document.getElementById('column-label').value = this.dataset.label;
            document.getElementById('column-width').value = this.dataset.width || '';
            resetColumnModal(this.dataset.type);
            columnModal.show();
        }));
        gridContainer.querySelectorAll('.btn-delete-column').forEach(btn => btn.addEventListener('click', function () {
            if (!confirm('ئەم ستوونە بسڕێتەوە؟')) { return; }
            structural('delete_column', { column_id: this.dataset.id }, 'ستوون سڕایەوە');
        }));
        gridContainer.querySelectorAll('.btn-move-column').forEach(btn => btn.addEventListener('click', function () {
            if (this.disabled) { return; }
            structural('reorder_column', { column_id: this.dataset.id, direction: this.dataset.dir });
        }));

        // Row actions
        gridContainer.querySelectorAll('.btn-delete-row').forEach(btn => btn.addEventListener('click', function () {
            if (!confirm('ئەم ڕیزە بسڕێتەوە؟')) { return; }
            structural('delete_row', { row_id: this.dataset.id }, 'ڕیز سڕایەوە');
        }));
        gridContainer.querySelectorAll('.btn-duplicate-row').forEach(btn => btn.addEventListener('click', function () {
            structural('duplicate_row', { row_id: this.dataset.id }, 'ڕیز لەبەرگیرایەوە');
        }));
        gridContainer.querySelectorAll('.btn-move-row').forEach(btn => btn.addEventListener('click', function () {
            if (this.disabled) { return; }
            structural('reorder_row', { row_id: this.dataset.id, direction: this.dataset.dir });
        }));
        gridContainer.querySelectorAll('.btn-row-settings').forEach(btn => btn.addEventListener('click', function () {
            const tr = gridContainer.querySelector('tr[data-row-id="' + this.dataset.id + '"]');
            document.getElementById('row-id').value = this.dataset.id;
            rowTypeSel.value = tr.dataset.resultType || 'numeric';
            document.getElementById('row-min-male').value = tr.dataset.normalMinMale || '';
            document.getElementById('row-max-male').value = tr.dataset.normalMaxMale || '';
            document.getElementById('row-min-female').value = tr.dataset.normalMinFemale || '';
            document.getElementById('row-max-female').value = tr.dataset.normalMaxFemale || '';
            document.getElementById('row-text').value = tr.dataset.normalText || '';
            document.getElementById('row-unit').value = tr.dataset.unit || '';
            loadAgeRanges(tr.dataset.ranges);
            syncRangeVisibility();
            rowModal.show();
        }));

        // Inline autosave: row name
        gridContainer.querySelectorAll('.row-name').forEach(input => input.addEventListener('change', async function () {
            const tr = this.closest('tr');
            const r = await api('update_row', {
                row_id: tr.dataset.rowId,
                name: this.value,
                result_type: tr.dataset.resultType || 'numeric',
                normal_min_male: tr.dataset.normalMinMale || '',
                normal_max_male: tr.dataset.normalMaxMale || '',
                normal_min_female: tr.dataset.normalMinFemale || '',
                normal_max_female: tr.dataset.normalMaxFemale || '',
                normal_text: tr.dataset.normalText || '',
                unit: tr.dataset.unit || ''
            });
            showToast(r.success ? 'پاشەکەوتکرا' : (r.message || 'هەڵە'), r.success);
        }));

        // Inline autosave: free text cells
        gridContainer.querySelectorAll('.text-cell').forEach(input => input.addEventListener('change', async function () {
            const r = await api('update_cell', {
                row_id: this.dataset.rowId,
                column_id: this.dataset.columnId,
                content: this.value
            });
            showToast(r.success ? 'پاشەکەوتکرا' : (r.message || 'هەڵە'), r.success);
        }));

        // Inline autosave: per-row choice options
        gridContainer.querySelectorAll('.choice-options-cell').forEach(input => input.addEventListener('change', async function () {
            const r = await api('update_cell', {
                row_id: this.dataset.rowId,
                column_id: this.dataset.columnId,
                options: this.value
            });
            showToast(r.success ? 'پاشەکەوتکرا' : (r.message || 'هەڵە'), r.success);
        }));

        // Row drag & drop
        const tbody = gridContainer.querySelector('#designer-rows');
        if (tbody) {
            gridContainer.querySelectorAll('.lab-row-drag').forEach(handle => {
                handle.addEventListener('dragstart', function () {
                    draggedRow = this.closest('tr');
                    draggedRow.classList.add('lab-dragging');
                });
                handle.addEventListener('dragend', async function () {
                    if (!draggedRow) { return; }
                    draggedRow.classList.remove('lab-dragging');
                    draggedRow = null;
                    const order = [...tbody.querySelectorAll('tr')].map(tr => tr.dataset.rowId).join(',');
                    const r = await api('set_row_order', { order: order });
                    if (r.success) { await refreshGrid(); showToast('ڕیزبەندی نوێکرایەوە', true); }
                    else { showToast(r.message || 'هەڵە', false); await refreshGrid(); }
                });
            });
            tbody.addEventListener('dragover', function (e) {
                if (!draggedRow) { return; }
                e.preventDefault();
                const after = getRowAfter(tbody, e.clientY);
                if (after == null) { tbody.appendChild(draggedRow); }
                else { tbody.insertBefore(draggedRow, after); }
            });
        }
    }

    bindGrid();
});
</script>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
