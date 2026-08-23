/**
 * Smart Clinics Doctor UI — shared behaviors for create pages.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    function initPatientPicker() {
        const patientPickerList = document.getElementById('patient-picker-list');
        const patientIdInput = document.getElementById('patient-id-input');
        if (!patientPickerList || !patientIdInput) {
            return;
        }
        patientPickerList.querySelectorAll('.patient-picker-card').forEach(function (card) {
            card.addEventListener('click', function () {
                patientIdInput.value = card.dataset.patientId || '';
                patientPickerList.querySelectorAll('.patient-picker-card').forEach(function (item) {
                    item.classList.remove('selected');
                    const check = item.querySelector('.patient-picker-check');
                    if (check) {
                        check.classList.add('d-none');
                    }
                });
                card.classList.add('selected');
                const activeCheck = card.querySelector('.patient-picker-check');
                if (activeCheck) {
                    activeCheck.classList.remove('d-none');
                }
            });
        });
    }

    function initPrescriptionCreate() {
        const cfg = window.__scPrescriptionCreate;
        const searchInput = document.getElementById('product-search');
        const resultsBox = document.getElementById('product-search-results');
        const selectedProducts = document.getElementById('selected-products');
        const selectedProductsEmpty = document.getElementById('selected-products-empty');

        if (!cfg || !searchInput || !resultsBox || !selectedProducts) {
            return;
        }

        let timer = null;
        let fetchSeq = 0;
        let lastResults = [];
        let suppressBlurHide = false;

        function showResults() {
            resultsBox.classList.add('is-open');
            resultsBox.style.display = 'block';
        }

        function hideResults() {
            resultsBox.classList.remove('is-open');
            resultsBox.style.display = 'none';
        }

        function hasProduct(productId) {
            return selectedProducts.querySelector('.rx-item-row[data-id="' + productId + '"]') !== null;
        }

        function refreshEmptyState() {
            if (!selectedProductsEmpty) {
                return;
            }
            const hasRows = selectedProducts.querySelector('.rx-item-row') !== null;
            selectedProductsEmpty.classList.toggle('d-none', hasRows);
        }

        function bindRemoveButtons() {
            selectedProducts.querySelectorAll('.remove-product').forEach(function (btn) {
                btn.onclick = function () {
                    const row = btn.closest('.rx-item-row');
                    if (row) {
                        row.remove();
                    }
                    refreshEmptyState();
                };
            });
        }

        function buildUnitOptions(units) {
            const list = units || [];
            let html = list.length ? '' : '<option value="">' + escapeHtml(cfg.unitPlaceholder) + '</option>';
            list.forEach(function (unit, idx) {
                const selected = idx === 0 ? ' selected' : '';
                html += '<option value="' + Number(unit.product_unit_id) + '"' + selected + '>' + escapeHtml(unit.unit_name) + '</option>';
            });
            return html;
        }

        function appendProduct(item) {
            if (hasProduct(String(item.id))) {
                return;
            }
            const row = document.createElement('div');
            row.className = 'rx-item-row card border-0 mb-2';
            row.dataset.id = String(item.id);
            row.innerHTML =
                '<div class="card-body py-2 px-3 d-flex flex-wrap gap-2 align-items-center">' +
                    '<div class="flex-grow-1 fw-semibold rx-item-name">' + escapeHtml(item.name) + '</div>' +
                    '<div class="rx-item-field">' +
                        '<label class="rx-item-mini-label">' + escapeHtml(cfg.labelQty) + '</label>' +
                        '<input type="number" class="form-control form-control-sm" name="quantities[]" min="0.01" step="any" style="width: 90px;" value="1">' +
                    '</div>' +
                    '<div class="rx-item-field">' +
                        '<label class="rx-item-mini-label">' + escapeHtml(cfg.labelUnit) + '</label>' +
                        '<select class="form-select form-select-sm" name="product_unit_ids[]" style="min-width: 130px;">' +
                            buildUnitOptions(item.units) +
                        '</select>' +
                    '</div>' +
                    '<input type="hidden" name="product_ids[]" value="' + Number(item.id) + '">' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-product" aria-label="Remove"><i class="bi bi-trash"></i></button>' +
                '</div>';
            selectedProducts.appendChild(row);
            bindRemoveButtons();
            refreshEmptyState();
        }

        function renderResultMeta(item) {
            const parts = [];
            if (item.barcode) {
                parts.push(escapeHtml(item.barcode));
            }
            if (item.category_name) {
                parts.push(escapeHtml(item.category_name));
            }
            if (parts.length === 0) {
                return '';
            }
            return '<div class="prescription-search-result-meta">' + parts.join(' · ') + '</div>';
        }

        function bindResultItems(payload) {
            resultsBox.querySelectorAll('.prescription-search-result-item').forEach(function (btn) {
                btn.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    suppressBlurHide = true;
                    const item = payload.data[Number(btn.dataset.idx)];
                    if (item) {
                        appendProduct({ id: item.id, name: item.name, units: item.units || [] });
                    }
                    hideResults();
                    searchInput.value = '';
                    lastResults = [];
                    searchInput.focus();
                    setTimeout(function () {
                        suppressBlurHide = false;
                    }, 0);
                });
            });
        }

        function renderResults(payload) {
            if (!payload.success || !Array.isArray(payload.data) || payload.data.length === 0) {
                resultsBox.innerHTML = '<div class="list-group-item text-muted">' + escapeHtml(cfg.noResults) + '</div>';
                showResults();
                return;
            }
            lastResults = payload.data;
            resultsBox.innerHTML = payload.data.map(function (item, idx) {
                return '<button type="button" class="list-group-item list-group-item-action prescription-search-result-item' + (idx === 0 ? ' active' : '') + '" data-idx="' + idx + '">' +
                    '<div class="fw-semibold">' + escapeHtml(item.name) + '</div>' +
                    renderResultMeta(item) +
                '</button>';
            }).join('');
            showResults();
            bindResultItems(payload);
        }

        async function runSearch(query) {
            const seq = ++fetchSeq;
            resultsBox.innerHTML = '<div class="prescription-search-loading">' + escapeHtml(cfg.searching || 'Searching…') + '</div>';
            showResults();
            try {
                const response = await fetch(cfg.searchEndpoint + '&q=' + encodeURIComponent(query), { credentials: 'same-origin' });
                const payload = await response.json();
                if (seq !== fetchSeq) {
                    return;
                }
                renderResults(payload);
            } catch (error) {
                if (seq !== fetchSeq) {
                    return;
                }
                resultsBox.innerHTML = '<div class="list-group-item text-danger">' + escapeHtml(cfg.searchError) + '</div>';
                showResults();
            }
        }

        function scheduleSearch() {
            const query = searchInput.value.trim();
            if (query.length < 1) {
                hideResults();
                resultsBox.innerHTML = '';
                lastResults = [];
                return;
            }
            clearTimeout(timer);
            timer = setTimeout(function () {
                runSearch(query);
            }, 220);
        }

        function selectFirstResult() {
            if (lastResults.length === 0) {
                return;
            }
            appendProduct({
                id: lastResults[0].id,
                name: lastResults[0].name,
                units: lastResults[0].units || [],
            });
            hideResults();
            searchInput.value = '';
            lastResults = [];
        }

        searchInput.addEventListener('input', scheduleSearch);

        searchInput.addEventListener('focus', function () {
            const query = searchInput.value.trim();
            if (query.length >= 1 && lastResults.length === 0) {
                scheduleSearch();
            } else if (query.length >= 1 && resultsBox.innerHTML !== '') {
                showResults();
            }
        });

        searchInput.addEventListener('blur', function () {
            setTimeout(function () {
                if (suppressBlurHide) {
                    return;
                }
                hideResults();
            }, 180);
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                const items = resultsBox.querySelectorAll('.prescription-search-result-item');
                if (items.length === 0) {
                    return;
                }
                let activeIdx = 0;
                items.forEach(function (item, idx) {
                    if (item.classList.contains('active')) {
                        activeIdx = idx;
                    }
                    item.classList.remove('active');
                });
                const nextIdx = Math.min(activeIdx + 1, items.length - 1);
                items[nextIdx].classList.add('active');
                items[nextIdx].scrollIntoView({ block: 'nearest' });
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                const active = resultsBox.querySelector('.prescription-search-result-item.active');
                if (active) {
                    suppressBlurHide = true;
                    const item = lastResults[Number(active.dataset.idx)];
                    if (item) {
                        appendProduct({ id: item.id, name: item.name, units: item.units || [] });
                    }
                    hideResults();
                    searchInput.value = '';
                    lastResults = [];
                    setTimeout(function () {
                        suppressBlurHide = false;
                    }, 0);
                } else {
                    selectFirstResult();
                }
            }
            if (event.key === 'Escape') {
                hideResults();
            }
        });

        resultsBox.addEventListener('mousedown', function () {
            suppressBlurHide = true;
        });

        bindRemoveButtons();
    }

    function initLabCreate() {
        const cfg = window.__scLabCreate;
        const form = document.getElementById('lab-order-form');
        if (!form) {
            return;
        }

        const countBadge = document.getElementById('selected-tests-count');
        const searchInput = document.getElementById('test-search');
        const noResults = document.getElementById('tests-no-results');
        const selectedLabel = (cfg && cfg.selectedLabel) || 'selected';

        function formatSelectedCount(n) {
            return n + ' ' + selectedLabel;
        }

        function updateCount() {
            const n = form.querySelectorAll('.lab-test-check:checked').length;
            if (countBadge) {
                countBadge.textContent = formatSelectedCount(n);
            }
        }

        function markSelected(item) {
            if (!item) {
                return;
            }
            const check = item.querySelector('.lab-test-check');
            item.classList.toggle('is-selected', !!(check && check.checked));
        }

        function refreshRowsBadge(panelId) {
            const panel = document.getElementById(panelId);
            const btn = form.querySelector('.lab-rows-toggle[data-target="' + panelId + '"]');
            if (!panel || !btn) {
                return;
            }
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
            if (!panel) {
                return;
            }
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
                check.dataset.autoChecked = '1';
                markSelected(item);
                updateCount();
            }
        }

        function collapseTestPanel(item) {
            const panel = item && item.querySelector('.lab-rows-panel');
            if (!panel) {
                return;
            }
            panel.querySelectorAll('.lab-row-check').forEach(function (b) {
                b.checked = false;
            });
            panel.classList.add('d-none');
            const btn = item.querySelector('.lab-rows-toggle');
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
            }
            const allCb = panel.querySelector('.lab-rows-all');
            if (allCb) {
                allCb.checked = false;
                allCb.indeterminate = false;
            }
            refreshRowsBadge(panel.id);
        }

        // If the test was checked automatically by selecting parameters, and the
        // doctor later removes every parameter, drop the test check too so it does
        // not linger and appear as a full test request.
        function maybeUncheckAutoTest(item) {
            const check = item && item.querySelector('.lab-test-check');
            if (!check || !check.checked || check.dataset.autoChecked !== '1') {
                return;
            }
            const panel = item.querySelector('.lab-rows-panel');
            const anyRow = panel && panel.querySelector('.lab-row-check:checked');
            if (!anyRow) {
                check.checked = false;
                delete check.dataset.autoChecked;
                markSelected(item);
                updateCount();
            }
        }

        form.querySelectorAll('.lab-rows-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const panel = document.getElementById(btn.dataset.target);
                if (!panel) {
                    return;
                }
                const willOpen = panel.classList.contains('d-none');
                panel.classList.toggle('d-none', !willOpen);
                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });

        form.querySelectorAll('.lab-rows-all').forEach(function (all) {
            all.addEventListener('change', function () {
                const panelId = all.dataset.targetPanel;
                const panel = document.getElementById(panelId);
                if (!panel) {
                    return;
                }
                const item = all.closest('.lab-test-item');
                panel.querySelectorAll('.lab-row-check').forEach(function (box) {
                    box.checked = all.checked;
                });
                if (all.checked) {
                    ensureTestChecked(item);
                } else {
                    maybeUncheckAutoTest(item);
                }
                refreshRowsBadge(panelId);
                syncAllCheckbox(panelId);
            });
        });

        form.querySelectorAll('.lab-row-check').forEach(function (box) {
            box.addEventListener('change', function () {
                const item = box.closest('.lab-test-item');
                if (box.checked) {
                    ensureTestChecked(item);
                } else {
                    maybeUncheckAutoTest(item);
                }
                refreshRowsBadge(box.dataset.panel);
                syncAllCheckbox(box.dataset.panel);
            });
        });

        form.querySelectorAll('.lab-test-check').forEach(function (check) {
            check.addEventListener('change', function () {
                const item = check.closest('.lab-test-item');
                // A manual toggle takes ownership; it is no longer auto-managed.
                delete check.dataset.autoChecked;
                markSelected(item);
                if (!check.checked && item) {
                    collapseTestPanel(item);
                }
                updateCount();
            });
        });

        const searchClear = document.getElementById('test-search-clear');

        // Reveal (or re-hide) a test's parameter panel while searching, and mark
        // the parameter chips that matched. Panels opened purely by the search are
        // tagged so they can be auto-collapsed again — unless the doctor already
        // selected parameters inside them.
        function applyParamSearch(item, q, revealForParam) {
            const panel = item.querySelector('.lab-rows-panel');
            const hint = item.querySelector('[data-param-hint]');
            if (!panel) {
                if (hint) {
                    hint.classList.add('d-none');
                }
                return;
            }
            const matchedNames = [];
            panel.querySelectorAll('.lab-chip').forEach(function (label) {
                if (label.classList.contains('lab-chip-all')) {
                    return;
                }
                const text = (label.textContent || '').trim().toLowerCase();
                const isMatch = q !== '' && text.indexOf(q) !== -1;
                label.classList.toggle('lab-chip--match', isMatch);
                if (isMatch) {
                    matchedNames.push((label.textContent || '').trim());
                }
            });

            if (hint) {
                if (revealForParam && matchedNames.length > 0) {
                    const hintText = hint.querySelector('.lab-param-hint-text');
                    if (hintText) {
                        hintText.textContent = matchedNames.slice(0, 3).join(', ')
                            + (matchedNames.length > 3 ? '…' : '');
                    }
                    hint.classList.remove('d-none');
                } else {
                    hint.classList.add('d-none');
                }
            }

            if (revealForParam && matchedNames.length > 0) {
                if (panel.classList.contains('d-none')) {
                    panel.classList.remove('d-none');
                    panel.dataset.searchExpanded = '1';
                    const btn = item.querySelector('.lab-rows-toggle');
                    if (btn) {
                        btn.setAttribute('aria-expanded', 'true');
                    }
                }
            } else if (panel.dataset.searchExpanded === '1') {
                // Only collapse panels the search itself opened, and only when the
                // doctor has not selected any parameter inside them.
                if (!panel.querySelector('.lab-row-check:checked')) {
                    panel.classList.add('d-none');
                    const btn = item.querySelector('.lab-rows-toggle');
                    if (btn) {
                        btn.setAttribute('aria-expanded', 'false');
                    }
                }
                delete panel.dataset.searchExpanded;
            }
        }

        function runTestSearch(q) {
            let anyVisible = false;
            form.querySelectorAll('.lab-test-group').forEach(function (group) {
                let groupVisible = false;
                group.querySelectorAll('[data-test-item]').forEach(function (item) {
                    const name = item.getAttribute('data-test-name') || '';
                    const params = item.getAttribute('data-test-params') || '';
                    const nameMatch = q !== '' && name.indexOf(q) !== -1;
                    const paramMatch = q !== '' && params.indexOf(q) !== -1;
                    const match = q === '' || nameMatch || paramMatch;
                    item.classList.toggle('d-none', !match);
                    item.classList.toggle('lab-test-item--param-match', match && paramMatch && !nameMatch);
                    applyParamSearch(item, q, paramMatch && !nameMatch);
                    if (match) {
                        groupVisible = true;
                        anyVisible = true;
                    }
                });
                group.classList.toggle('d-none', !groupVisible);
            });
            if (noResults) {
                noResults.classList.toggle('d-none', anyVisible);
            }
            if (searchClear) {
                searchClear.classList.toggle('d-none', q === '');
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                runTestSearch(searchInput.value.trim().toLowerCase());
            });
        }

        if (searchClear) {
            searchClear.addEventListener('click', function () {
                searchInput.value = '';
                runTestSearch('');
                searchInput.focus();
            });
        }

        form.querySelectorAll('.lab-test-item').forEach(markSelected);
        form.querySelectorAll('.lab-rows-panel').forEach(function (panel) {
            refreshRowsBadge(panel.id);
            syncAllCheckbox(panel.id);
        });
        updateCount();
    }

    /* ---- Markdown editors (clinical note fields) ------------------------ */

    function renderInlineMarkdown(text) {
        // Escape first so user content can never inject HTML.
        let out = escapeHtml(text);

        const codeStore = [];
        out = out.replace(/`([^`]+)`/g, function (_, code) {
            codeStore.push('<code>' + code + '</code>');
            return '\uE000' + (codeStore.length - 1) + '\uE000';
        });

        out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (whole, label, url) {
            if (!/^(https?:\/\/|mailto:|\/)/i.test(url)) {
                return whole;
            }
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
        });

        out = out
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/__([^_]+)__/g, '<strong>$1</strong>')
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            .replace(/(^|[^a-zA-Z0-9])_([^_]+)_(?![a-zA-Z0-9])/g, '$1<em>$2</em>');

        out = out.replace(/\uE000(\d+)\uE000/g, function (_, idx) {
            return codeStore[Number(idx)];
        });

        return out;
    }

    function renderMarkdown(source) {
        const text = String(source || '').replace(/\r\n?/g, '\n').trim();
        if (text === '') {
            return '';
        }
        const lines = text.split('\n');
        let html = '';
        let listType = null;
        let inQuote = false;

        function closeList() {
            if (listType) {
                html += '</' + listType + '>';
                listType = null;
            }
        }
        function closeQuote() {
            if (inQuote) {
                html += '</blockquote>';
                inQuote = false;
            }
        }

        lines.forEach(function (rawLine) {
            const line = rawLine.trim();
            let m;

            if (line === '') {
                closeList();
                closeQuote();
                return;
            }
            if (/^(-{3,}|\*{3,}|_{3,})$/.test(line)) {
                closeList();
                closeQuote();
                html += '<hr>';
                return;
            }
            if ((m = line.match(/^(#{1,6})\s+(.*)$/))) {
                closeList();
                closeQuote();
                const level = m[1].length;
                html += '<h' + level + '>' + renderInlineMarkdown(m[2]) + '</h' + level + '>';
                return;
            }
            if ((m = line.match(/^>\s?(.*)$/))) {
                closeList();
                if (!inQuote) {
                    html += '<blockquote>';
                    inQuote = true;
                }
                html += renderInlineMarkdown(m[1]) + '<br>';
                return;
            }
            closeQuote();
            if ((m = line.match(/^[-*+]\s+(.*)$/))) {
                if (listType !== 'ul') {
                    closeList();
                    html += '<ul>';
                    listType = 'ul';
                }
                html += '<li>' + renderInlineMarkdown(m[1]) + '</li>';
                return;
            }
            if ((m = line.match(/^\d+[.)]\s+(.*)$/))) {
                if (listType !== 'ol') {
                    closeList();
                    html += '<ol>';
                    listType = 'ol';
                }
                html += '<li>' + renderInlineMarkdown(m[1]) + '</li>';
                return;
            }
            closeList();
            html += '<p>' + renderInlineMarkdown(line) + '</p>';
        });

        closeList();
        closeQuote();
        return html;
    }

    function initMarkdownEditors() {
        document.querySelectorAll('[data-md-editor]').forEach(function (editor) {
            const textarea = editor.querySelector('.sc-md-input');
            const preview = editor.querySelector('[data-md-preview]');
            if (!textarea || !preview) {
                return;
            }

            function surround(before, after, placeholder) {
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const value = textarea.value;
                const selected = value.slice(start, end) || placeholder;
                const next = value.slice(0, start) + before + selected + after + value.slice(end);
                textarea.value = next;
                textarea.focus();
                const selStart = start + before.length;
                textarea.setSelectionRange(selStart, selStart + selected.length);
            }

            function prefixLines(prefix, ordered) {
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const value = textarea.value;
                const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                let lineEnd = value.indexOf('\n', end);
                if (lineEnd === -1) {
                    lineEnd = value.length;
                }
                const block = value.slice(lineStart, lineEnd) || '';
                const newBlock = block
                    .split('\n')
                    .map(function (l, i) {
                        return (ordered ? (i + 1) + '. ' : prefix) + l;
                    })
                    .join('\n');
                textarea.value = value.slice(0, lineStart) + newBlock + value.slice(lineEnd);
                textarea.focus();
                textarea.setSelectionRange(lineStart, lineStart + newBlock.length);
            }

            editor.querySelectorAll('[data-md-cmd]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    switch (btn.dataset.mdCmd) {
                        case 'bold': surround('**', '**', 'bold text'); break;
                        case 'italic': surround('*', '*', 'italic text'); break;
                        case 'code': surround('`', '`', 'code'); break;
                        case 'heading': prefixLines('## ', false); break;
                        case 'ul': prefixLines('- ', false); break;
                        case 'ol': prefixLines('', true); break;
                        case 'quote': prefixLines('> ', false); break;
                        case 'link': surround('[', '](https://)', 'link text'); break;
                        default: break;
                    }
                });
            });

            editor.querySelectorAll('[data-md-tab]').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    const mode = tab.dataset.mdTab;
                    editor.querySelectorAll('[data-md-tab]').forEach(function (t) {
                        t.classList.toggle('is-active', t === tab);
                    });
                    if (mode === 'preview') {
                        const rendered = renderMarkdown(textarea.value);
                        preview.innerHTML = rendered || '<p class="sc-md-empty">Nothing to preview yet.</p>';
                        preview.hidden = false;
                        textarea.hidden = true;
                        editor.querySelectorAll('[data-md-cmd]').forEach(function (b) {
                            b.disabled = true;
                        });
                    } else {
                        preview.hidden = true;
                        textarea.hidden = false;
                        editor.querySelectorAll('[data-md-cmd]').forEach(function (b) {
                            b.disabled = false;
                        });
                        textarea.focus();
                    }
                });
            });
        });

        // A required note field left in Preview mode is `hidden`, which browsers
        // cannot focus for constraint validation — the submit would fail
        // silently. Revert every editor to Write mode before native validation
        // runs (submit-button click fires ahead of it).
        const forms = new Set();
        document.querySelectorAll('[data-md-editor]').forEach(function (editor) {
            const form = editor.closest('form');
            if (form) {
                forms.add(form);
            }
        });
        forms.forEach(function (form) {
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    form.querySelectorAll('[data-md-editor]').forEach(function (editor) {
                        const writeTab = editor.querySelector('[data-md-tab="write"]');
                        const input = editor.querySelector('.sc-md-input');
                        if (writeTab && input && input.hidden) {
                            writeTab.click();
                        }
                    });
                });
            });
        });
    }

    /* ---- Draft auto-save (patient History consultation) ----------------- */

    function initDraftAutosave() {
        const cfg = window.__scDraftAutosave;
        const form = document.getElementById('prescription-form');
        if (!cfg || !form) {
            return;
        }
        const patientInput = document.getElementById('patient-id-input');
        const draftInput = document.getElementById('draft-id-input');
        const statusBox = document.getElementById('draft-autosave-status');
        const statusText = statusBox ? statusBox.querySelector('.sc-autosave-text') : null;
        const statusIcon = statusBox ? statusBox.querySelector('i') : null;
        if (!patientInput || !(Number(patientInput.value) > 0)) {
            return;
        }

        const idleDelay = Number(cfg.idleDelay) > 0 ? Number(cfg.idleDelay) : 900;
        let timer = null;
        let inFlight = false;
        let pending = false;

        function setStatus(state, text) {
            if (!statusBox) {
                return;
            }
            statusBox.hidden = false;
            statusBox.classList.remove('is-saving', 'is-saved', 'is-error');
            if (state) {
                statusBox.classList.add('is-' + state);
            }
            if (statusIcon) {
                statusIcon.className = state === 'saving'
                    ? 'bi bi-arrow-repeat sc-spin'
                    : (state === 'error' ? 'bi bi-cloud-slash' : 'bi bi-cloud-check');
            }
            if (statusText) {
                statusText.textContent = text || '';
            }
        }

        async function save() {
            if (inFlight) {
                pending = true;
                return;
            }
            inFlight = true;
            pending = false;
            setStatus('saving', cfg.savingText || 'Saving…');
            try {
                const response = await fetch(cfg.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                });
                const payload = await response.json();
                inFlight = false;
                if (payload && payload.success) {
                    if (draftInput && payload.draft_id) {
                        draftInput.value = String(payload.draft_id);
                    }
                    setStatus('saved', cfg.savedText || 'Draft saved');
                } else {
                    setStatus('error', cfg.errorText || 'Not saved — will retry');
                }
            } catch (error) {
                inFlight = false;
                setStatus('error', cfg.errorText || 'Not saved — will retry');
            }
            if (pending) {
                save();
            }
        }

        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(save, idleDelay);
        }

        // Text fields (History / Examination / Diagnoses) + quantity inputs.
        form.addEventListener('input', schedule);
        // Unit dropdowns.
        form.addEventListener('change', schedule);

        // Medications are added / removed by rebuilding rows in #selected-products;
        // those DOM mutations don't fire input/change, so watch them directly.
        const selectedProducts = document.getElementById('selected-products');
        if (selectedProducts && 'MutationObserver' in window) {
            new MutationObserver(schedule).observe(selectedProducts, { childList: true });
        }

        // Best-effort flush when leaving the page (e.g. clicking "Lab") so the last
        // keystrokes are not lost to the debounce. A normal form submit is left
        // alone — that path finalizes the prescription server-side.
        function flushBeacon() {
            if (!navigator.sendBeacon) {
                return;
            }
            clearTimeout(timer);
            try {
                navigator.sendBeacon(cfg.endpoint, new FormData(form));
            } catch (error) {
                /* ignore */
            }
        }
        let submitting = false;
        form.addEventListener('submit', function () {
            submitting = true;
        });
        window.addEventListener('pagehide', function () {
            if (!submitting) {
                flushBeacon();
            }
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden' && !submitting) {
                flushBeacon();
            }
        });
    }

    /* ---- Clinical-note image attachments -------------------------------- */

    function initPrescriptionImages() {
        const cfg = window.__scPrescriptionImages;
        const zones = document.querySelectorAll('[data-md-attach]');
        if (!cfg || zones.length === 0) {
            return;
        }

        const MAX_BYTES = 8 * 1024 * 1024;

        function resolvePrescriptionId() {
            const draftInput = document.getElementById('draft-id-input');
            if (draftInput && Number(draftInput.value) > 0) {
                return String(draftInput.value);
            }
            if (cfg.prescriptionId && Number(cfg.prescriptionId) > 0) {
                return String(cfg.prescriptionId);
            }
            return '';
        }

        function syncDraftId(draftId) {
            if (!draftId) {
                return;
            }
            const draftInput = document.getElementById('draft-id-input');
            if (draftInput && !(Number(draftInput.value) > 0)) {
                draftInput.value = String(draftId);
            }
        }

        function buildThumb(attachment) {
            const wrap = document.createElement('div');
            wrap.className = 'sc-attach-thumb';
            wrap.dataset.attachId = String(attachment.id || '');
            const url = attachment.url || '';
            const name = attachment.original_name || '';
            wrap.innerHTML =
                '<a class="sc-attach-thumb-link" href="' + escapeHtml(url) + '" data-sc-lightbox data-caption="' + escapeHtml(name) + '">' +
                    '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(name || 'Attachment') + '" loading="lazy">' +
                '</a>' +
                '<button type="button" class="sc-attach-del" data-attach-del title="Remove image" aria-label="Remove image"><i class="bi bi-x-lg"></i></button>';
            return wrap;
        }

        zones.forEach(function (zone) {
            const section = zone.dataset.section || '';
            const grid = zone.querySelector('[data-attach-grid]');
            const input = zone.querySelector('[data-attach-input]');
            const addLabel = zone.querySelector('[data-attach-add]');
            const status = zone.querySelector('[data-attach-status]');
            if (!grid || !input) {
                return;
            }

            function setStatus(text, isError) {
                if (!status) {
                    return;
                }
                if (!text) {
                    status.hidden = true;
                    status.textContent = '';
                    status.classList.remove('is-error');
                    return;
                }
                status.hidden = false;
                status.textContent = text;
                status.classList.toggle('is-error', !!isError);
            }

            async function uploadOne(file) {
                if (!file || file.type.indexOf('image/') !== 0) {
                    setStatus(cfg.notImage || 'Only image files are allowed', true);
                    return;
                }
                if (file.size > MAX_BYTES) {
                    setStatus(cfg.tooLarge || 'Image is too large', true);
                    return;
                }
                setStatus(cfg.uploadingText || 'Uploading…', false);
                const fd = new FormData();
                fd.append('action', 'upload');
                fd.append('csrf_token', cfg.csrfToken || '');
                fd.append('patient_id', String(cfg.patientId || ''));
                fd.append('section', section);
                fd.append('prescription_id', resolvePrescriptionId());
                fd.append('image', file);
                try {
                    const response = await fetch(cfg.endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd,
                    });
                    const payload = await response.json();
                    if (payload && payload.success && payload.attachment) {
                        grid.appendChild(buildThumb(payload.attachment));
                        syncDraftId(payload.draft_id);
                        setStatus('', false);
                    } else {
                        setStatus((payload && payload.message) || cfg.uploadError || 'Upload failed', true);
                    }
                } catch (error) {
                    setStatus(cfg.uploadError || 'Upload failed', true);
                }
            }

            async function uploadFiles(files) {
                const list = Array.prototype.slice.call(files || []);
                for (let i = 0; i < list.length; i += 1) {
                    // Sequential so sort_order stays stable and status is readable.
                    // eslint-disable-next-line no-await-in-loop
                    await uploadOne(list[i]);
                }
            }

            input.addEventListener('change', function () {
                if (input.files && input.files.length) {
                    uploadFiles(input.files);
                    input.value = '';
                }
            });

            // Drag & drop onto the "Add image" control.
            if (addLabel) {
                ['dragenter', 'dragover'].forEach(function (evt) {
                    addLabel.addEventListener(evt, function (e) {
                        e.preventDefault();
                        addLabel.classList.add('is-dragover');
                    });
                });
                ['dragleave', 'dragend', 'drop'].forEach(function (evt) {
                    addLabel.addEventListener(evt, function () {
                        addLabel.classList.remove('is-dragover');
                    });
                });
                addLabel.addEventListener('drop', function (e) {
                    e.preventDefault();
                    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                        uploadFiles(e.dataTransfer.files);
                    }
                });
            }

            // Paste an image while typing in this section's textarea.
            const panelBody = zone.closest('.sc-md-body');
            const textarea = panelBody ? panelBody.querySelector('.sc-md-input') : null;
            if (textarea) {
                textarea.addEventListener('paste', function (e) {
                    const items = (e.clipboardData && e.clipboardData.items) || [];
                    const images = [];
                    for (let i = 0; i < items.length; i += 1) {
                        if (items[i].kind === 'file' && items[i].type.indexOf('image/') === 0) {
                            const f = items[i].getAsFile();
                            if (f) {
                                images.push(f);
                            }
                        }
                    }
                    if (images.length) {
                        e.preventDefault();
                        uploadFiles(images);
                    }
                });
            }

            // Delete (event-delegated so it covers thumbnails added at runtime).
            grid.addEventListener('click', async function (e) {
                const btn = e.target.closest('[data-attach-del]');
                if (!btn) {
                    return;
                }
                const thumb = btn.closest('.sc-attach-thumb');
                const attachId = thumb ? thumb.dataset.attachId : '';
                if (!attachId) {
                    return;
                }
                if (cfg.deleteConfirm && !window.confirm(cfg.deleteConfirm)) {
                    return;
                }
                btn.disabled = true;
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('csrf_token', cfg.csrfToken || '');
                fd.append('attachment_id', attachId);
                try {
                    const response = await fetch(cfg.endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd,
                    });
                    const payload = await response.json();
                    if (payload && payload.success) {
                        if (thumb) {
                            thumb.remove();
                        }
                    } else {
                        btn.disabled = false;
                        setStatus((payload && payload.message) || cfg.uploadError || 'Failed', true);
                    }
                } catch (error) {
                    btn.disabled = false;
                    setStatus(cfg.uploadError || 'Failed', true);
                }
            });
        });
    }

    /* ---- Image lightbox (thumbnails everywhere) ------------------------- */

    function initImageLightbox() {
        let overlay = null;

        function ensureOverlay() {
            if (overlay) {
                return overlay;
            }
            overlay = document.createElement('div');
            overlay.className = 'sc-lightbox';
            overlay.hidden = true;
            overlay.innerHTML =
                '<button type="button" class="sc-lightbox-close" aria-label="Close">&times;</button>' +
                '<figure class="sc-lightbox-figure">' +
                    '<img class="sc-lightbox-img" src="" alt="">' +
                    '<figcaption class="sc-lightbox-caption"></figcaption>' +
                '</figure>';
            document.body.appendChild(overlay);

            function close() {
                overlay.hidden = true;
                overlay.classList.remove('is-open');
                const img = overlay.querySelector('.sc-lightbox-img');
                if (img) {
                    img.src = '';
                }
                document.body.classList.remove('sc-lightbox-open');
            }

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay || e.target.closest('.sc-lightbox-close')) {
                    close();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) {
                    close();
                }
            });
            overlay._close = close;
            return overlay;
        }

        document.addEventListener('click', function (e) {
            const link = e.target.closest('[data-sc-lightbox]');
            if (!link) {
                return;
            }
            e.preventDefault();
            const url = link.getAttribute('href') || (link.querySelector('img') && link.querySelector('img').src);
            if (!url) {
                return;
            }
            const box = ensureOverlay();
            const img = box.querySelector('.sc-lightbox-img');
            const caption = box.querySelector('.sc-lightbox-caption');
            if (img) {
                img.src = url;
            }
            if (caption) {
                const text = link.getAttribute('data-caption') || '';
                caption.textContent = text;
                caption.hidden = text === '';
            }
            box.hidden = false;
            box.classList.add('is-open');
            document.body.classList.add('sc-lightbox-open');
        });
    }

    /* ---- Visit tree master/detail (patient history page) ---------------- */

    function initVisitTree() {
        document.querySelectorAll('[data-sc-visit-tree]').forEach(function (widget) {
            // The tree (nav) lives in the right rail; the detail host sits in the
            // middle column above the form. Both are inside this widget scope.
            const detail = widget.querySelector('[data-vt-detail]');
            // Any element carrying data-vt-target is clickable: the Pharmacy/Lab
            // leaf buttons (.sc-vt-node) AND the visit summary itself, which opens
            // the visit's clinical panel (History / Examination / Diagnoses).
            const nodes = widget.querySelectorAll('[data-vt-target]');
            if (!detail || nodes.length === 0) {
                return;
            }
            const placeholder = detail.querySelector('[data-vt-placeholder]');
            const panels = detail.querySelectorAll('[data-vt-panel]');
            const closeBtn = detail.querySelector('[data-vt-close]');

            function activate(node) {
                const targetId = node.getAttribute('data-vt-target');
                if (!targetId) {
                    return;
                }
                const target = detail.querySelector('#' + (window.CSS && CSS.escape ? CSS.escape(targetId) : targetId));
                if (!target) {
                    return;
                }
                nodes.forEach(function (n) {
                    n.classList.toggle('is-active', n === node);
                });
                detail.hidden = false;
                if (placeholder) {
                    placeholder.hidden = true;
                }
                panels.forEach(function (p) {
                    p.hidden = p !== target;
                });
                // Bring the revealed record into view (it renders above the form).
                detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            nodes.forEach(function (node) {
                node.addEventListener('click', function () {
                    activate(node);
                });
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    detail.hidden = true;
                    nodes.forEach(function (n) {
                        n.classList.remove('is-active');
                    });
                });
            }
        });
    }

    function initTableFilter() {
        const table = document.getElementById('sc-filterable-table');
        const nameInput = document.getElementById('sc-filter-name');
        const mobileInput = document.getElementById('sc-filter-mobile');
        if (!table) {
            return;
        }

        function applyFilter() {
            const nameQ = (nameInput && nameInput.value.trim().toLowerCase()) || '';
            const mobileQ = (mobileInput && mobileInput.value.trim().toLowerCase()) || '';
            table.querySelectorAll('tbody tr[data-sc-row]').forEach(function (row) {
                const name = (row.dataset.scName || '').toLowerCase();
                const mobile = (row.dataset.scMobile || '').toLowerCase();
                const matchName = nameQ === '' || name.indexOf(nameQ) !== -1;
                const matchMobile = mobileQ === '' || mobile.indexOf(mobileQ) !== -1;
                row.classList.toggle('d-none', !(matchName && matchMobile));
            });
        }

        if (nameInput) {
            nameInput.addEventListener('input', applyFilter);
        }
        if (mobileInput) {
            mobileInput.addEventListener('input', applyFilter);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPatientPicker();
        initTableFilter();
        initMarkdownEditors();
        initPrescriptionImages();
        initImageLightbox();
        initVisitTree();

        const page = document.body.dataset.scPage
            || (document.querySelector('[data-sc-page="prescription-create"]') ? 'prescription-create' : '')
            || (document.querySelector('[data-sc-page="lab-create"]') ? 'lab-create' : '');

        if (page === 'prescription-create' || document.getElementById('product-search')) {
            initPrescriptionCreate();
            initDraftAutosave();
        } else if (page === 'lab-create') {
            initLabCreate();
        }
    });
})();
