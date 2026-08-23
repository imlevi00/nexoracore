/**
 * بەڕێوەبردنی ڕەچەتەکانی سەنتەری پزیشکی لە ناو بەشی فرۆشتن (POS).
 * - نیشاندانی ژمارەی ڕەچەتە چاوەڕوانەکان لەسەر دوگمەی مەلوان.
 * - پیشاندانی لیستەکە بە زانیاری نەخۆش، دکتۆر، جۆری نەخۆشی و دەرمانەکان.
 * - زیادکردنی دەرمانەکان بۆ سەبەتەی کڕین.
 * - نیشانکردنی ڕەچەتە وەک "تەواوکراو" (دەستی، یان خۆکار دوای فرۆشتنی سەرکەوتوو).
 *
 * پشت دەبەستێت بە function ـە گشتییەکانی POS: fetchProductAndAddToCart, showNotification.
 */
(function () {
    'use strict';

    const ENDPOINT = 'ajax/prescriptions.php';
    const POLL_INTERVAL_MS = 60000;

    let pollTimer = null;
    let loading = false;

    /**
     * پەیوەندی ڕەچەتە بە سەبەتەکەوە دەبەسترێت — نەک لە بیرگەی کاتیدا.
     * ناسنامەی ڕەچەتە پەیوەستکراوەکان لەسەر تابی چالاک (POS.tabs) هەڵدەگیرێن،
     * کە بۆ localStorage پاشەکەوت دەکرێن. بەمە:
     *  - دوای نوێبوونەوەی پەڕە (reload) پەیوەندییەکە نافەوتێت.
     *  - هەر تابێک تەنها ڕەچەتەی خۆی تەواو دەکات (نەک تابەکانی تر).
     */
    function getActiveTab() {
        // گرنگ: POS بە `const` ناساێنراوە لە index.php، بۆیە نابێتە موڵکی window
        // (const/let ی ئاستی سەرەوە ناچێتە سەر global object). واتە `window.POS`
        // هەمیشە undefined ـە، بەڵام `POS` بە ناوی ساده لە هەموو سکریپتەکاندا بەردەستە.
        // پێشتر پشکنینی `window.POS` وای دەکرد ئەم فەنکشنە هەمیشە null بگەڕێنێتەوە.
        if (typeof POS === 'undefined' || !POS || !Array.isArray(POS.tabs)) return null;
        return POS.tabs.find(function (t) { return t.id === POS.activeTabId; }) || null;
    }

    function persistTabs() {
        if (typeof saveCartToStorage === 'function') {
            saveCartToStorage();
        } else if (typeof POS !== 'undefined' && POS && Array.isArray(POS.tabs)) {
            // پاشگرتنی سەلامەت ئەگەر فەنکشنی گشتی بەردەست نەبوو
            try {
                localStorage.setItem('pos_tabs', JSON.stringify(POS.tabs));
                if (POS.activeTabId) localStorage.setItem('pos_active_tab', POS.activeTabId);
            } catch (e) { /* بێدەنگ */ }
        }
    }

    function addLinkedPrescriptionId(prescriptionId) {
        const tab = getActiveTab();
        if (!tab) return;
        if (!Array.isArray(tab.linkedPrescriptionIds)) {
            tab.linkedPrescriptionIds = [];
        }
        const id = Number(prescriptionId);
        if (!tab.linkedPrescriptionIds.includes(id)) {
            tab.linkedPrescriptionIds.push(id);
        }
        persistTabs();
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatQty(value) {
        const n = Number(value);
        if (!Number.isFinite(n) || n <= 0) {
            return '1';
        }
        // سڕینەوەی سفرەکانی دوای خاڵی دەیی (1.00 → 1، 1.50 → 1.5)
        return String(parseFloat(n.toFixed(2)));
    }

    function notify(message, type) {
        if (typeof showNotification === 'function') {
            showNotification(message, type || 'info');
        }
    }

    function getCsrfToken() {
        // یەکەم لە POS.csrfToken — ئەمە لەلایەن refreshCSRFToken()ـەوە نوێدەکرێتەوە،
        // بۆیە دوای هەڵەی 403 و نوێکردنەوە، هەوڵی دووبارە token ـی نوێ بەکاردەهێنێت.
        if (typeof POS !== 'undefined' && POS && POS.csrfToken) {
            return POS.csrfToken;
        }
        // پاشگرت: token ـی server-rendered (embed کراوە لە partial)
        const root = document.getElementById('posPrescriptionsRoot');
        return root ? (root.getAttribute('data-csrf') || '') : '';
    }

    function updateBadge(count) {
        const badge = document.getElementById('prescriptionsFabBadge');
        const fab = document.getElementById('prescriptionsFabBtn');
        const modalCount = document.getElementById('prescriptionsModalCount');
        const n = Number(count) || 0;
        if (badge) {
            badge.textContent = n;
            badge.style.display = n > 0 ? 'inline-flex' : 'none';
        }
        if (fab) {
            fab.classList.toggle('has-pending', n > 0);
        }
        if (modalCount) {
            modalCount.textContent = n;
        }
    }

    function genderLabel(gender) {
        if (gender === 'male') return 'نێر';
        if (gender === 'female') return 'مێ';
        return '';
    }

    function ageLabel(age, months) {
        const parts = [];
        if (age !== null && age !== undefined && age !== '') {
            parts.push(`${age} ساڵ`);
        }
        if (months !== null && months !== undefined && months !== '' && Number(months) > 0) {
            parts.push(`${months} مانگ`);
        }
        return parts.join(' و ');
    }

    function renderList(prescriptions) {
        const container = document.getElementById('prescriptionsListContainer');
        if (!container) return;

        if (!prescriptions || prescriptions.length === 0) {
            container.innerHTML = `
                <div class="text-center text-body-secondary py-5">
                    <i class="bi bi-clipboard-check" style="font-size: 2.5rem;"></i>
                    <p class="mb-0 mt-2">هیچ ڕەچەتەیەکی چاوەڕوان نییە</p>
                </div>`;
            return;
        }

        container.innerHTML = prescriptions.map(function (pr) {
            const meta = [];
            const age = ageLabel(pr.patient_age, pr.patient_age_months);
            const gender = genderLabel(pr.patient_gender);
            if (pr.patient_mobile) meta.push(`<i class="bi bi-telephone"></i> ${escapeHtml(pr.patient_mobile)}`);
            if (age) meta.push(`<i class="bi bi-calendar3"></i> ${escapeHtml(age)}`);
            if (gender) meta.push(`<i class="bi bi-person"></i> ${escapeHtml(gender)}`);

            const meds = (pr.items || []).length
                ? pr.items.map(function (it) {
                    const qtyLabel = formatQty(it.quantity);
                    const unitLabel = it.unit_name ? ` ${escapeHtml(it.unit_name)}` : '';
                    const qtyBadge = `<span class="rx-med-qty">${qtyLabel}${unitLabel}</span>`;
                    return `<span class="rx-med"><i class="bi bi-capsule"></i> ${escapeHtml(it.name)} ${qtyBadge}</span>`;
                }).join('')
                : '<span class="text-body-secondary">هیچ دەرمانێک تۆمار نەکراوە</span>';

            const hasProducts = (pr.items || []).some(function (it) { return Number(it.product_id) > 0; });

            return `
                <div class="pos-rx-card" data-prescription-id="${pr.id}">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="rx-patient"><i class="bi bi-person-vcard text-primary"></i> ${escapeHtml(pr.patient_name)}</div>
                            <div class="pos-rx-meta text-body-secondary mt-1">${meta.join(' &nbsp;|&nbsp; ')}</div>
                        </div>
                        <span class="badge text-bg-warning align-self-start">#${pr.id} چاوەڕوان</span>
                    </div>
                    <div class="pos-rx-meta mt-2">
                        <div><i class="bi bi-person-badge text-success"></i> <strong>دکتۆر:</strong> ${escapeHtml(pr.doctor_name)}</div>
                        <div class="mt-1"><i class="bi bi-clipboard-pulse text-danger"></i> <strong>جۆری نەخۆشی:</strong> ${escapeHtml(pr.diagnosis)}</div>
                    </div>
                    <div class="mt-2"><strong class="pos-rx-meta"><i class="bi bi-prescription2"></i> دەرمانەکان:</strong></div>
                    <div class="pos-rx-med-list">${meds}</div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-primary rx-add-cart-btn" data-id="${pr.id}" ${hasProducts ? '' : 'disabled'}>
                            <i class="bi bi-cart-plus"></i> زیادکردن بۆ سەبەتە
                        </button>
                    </div>
                </div>`;
        }).join('');

        // بەستنەوەی کردارەکان
        container.querySelectorAll('.rx-add-cart-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                addPrescriptionToCart(Number(btn.getAttribute('data-id')), prescriptions);
            });
        });
    }

    async function fetchList() {
        if (loading) return;
        loading = true;
        try {
            const res = await fetch(`${ENDPOINT}?action=list&_t=${Date.now()}`, { credentials: 'same-origin' });
            const data = await res.json();
            if (data && data.success) {
                updateBadge(data.count);
                renderList(data.prescriptions || []);
            } else {
                const container = document.getElementById('prescriptionsListContainer');
                if (container) {
                    container.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml((data && data.message) || 'هەڵەیەک ڕوویدا')}</div>`;
                }
            }
        } catch (e) {
            const container = document.getElementById('prescriptionsListContainer');
            if (container) {
                container.innerHTML = '<div class="alert alert-danger mb-0">نەتوانرا ڕەچەتەکان باربکرێن</div>';
            }
        } finally {
            loading = false;
        }
    }

    async function fetchCount() {
        try {
            const res = await fetch(`${ENDPOINT}?action=count&_t=${Date.now()}`, { credentials: 'same-origin' });
            const data = await res.json();
            if (data && data.success) {
                updateBadge(data.count);
            }
        } catch (e) {
            // بێدەنگ
        }
    }

    function addPrescriptionToCart(prescriptionId, prescriptions) {
        const pr = (prescriptions || []).find(function (p) { return Number(p.id) === Number(prescriptionId); });
        if (!pr) return;

        const products = (pr.items || []).filter(function (it) { return Number(it.product_id) > 0; });
        if (products.length === 0) {
            notify('هیچ دەرمانێکی پەیوەندیدار بە کاڵا نییە', 'warning');
            return;
        }

        if (typeof fetchProductAndAddToCart !== 'function') {
            notify('نەتوانرا دەرمانەکان زیاد بکرێن', 'danger');
            return;
        }

        products.forEach(function (it) {
            const qty = Number(it.quantity) > 0 ? Number(it.quantity) : 1;
            const productUnitId = (it.product_unit_id !== null && it.product_unit_id !== undefined)
                ? Number(it.product_unit_id)
                : null;
            fetchProductAndAddToCart(Number(it.product_id), null, qty, productUnitId);
        });

        // پەیوەندی ڕەچەتەکە بە تابی چالاکەوە دەبەسترێت (پاشەکەوتکراو) بۆ تەواوکردنی خۆکار دوای فرۆشتن
        addLinkedPrescriptionId(Number(prescriptionId));
        notify(`دەرمانەکانی ڕەچەتەی #${prescriptionId} بۆ نەخۆش «${pr.patient_name}» زیادکران`, 'success');

        // داخستنی مۆداڵ بۆ بەردەوامبوون لە فرۆشتن
        const modalEl = document.getElementById('posPrescriptionsModal');
        if (modalEl && window.bootstrap) {
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        }
    }

    function postComplete(prescriptionId) {
        return fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                action: 'complete',
                prescription_id: prescriptionId,
                csrf_token: getCsrfToken()
            })
        });
    }

    async function completePrescription(prescriptionId, showToast) {
        try {
            let res = await postComplete(prescriptionId);
            // ئەگەر token کۆن بوو (403)، نوێی بکەرەوە و دووبارە هەوڵ بدە
            if (res.status === 403 && typeof refreshCSRFToken === 'function') {
                const refreshed = await refreshCSRFToken();
                if (refreshed) {
                    res = await postComplete(prescriptionId);
                }
            }
            const data = await res.json();
            if (data && data.success) {
                updateBadge(data.count);
                if (showToast) {
                    notify(`ڕەچەتەی #${prescriptionId} بوو بە تەواوکراو`, 'success');
                }
                // نوێکردنەوەی لیست ئەگەر مۆداڵ کراوەیە
                const modalEl = document.getElementById('posPrescriptionsModal');
                if (modalEl && modalEl.classList.contains('show')) {
                    fetchList();
                }
                return true;
            }
            if (showToast) {
                notify((data && data.message) || 'هەڵە لە نوێکردنەوەی دۆخ', 'danger');
            }
        } catch (e) {
            if (showToast) {
                notify('هەڵە لە پەیوەندی بە سێرڤەر', 'danger');
            }
        }
        return false;
    }

    /**
     * دوای فرۆشتنی سەرکەوتوو بانگ دەکرێت — ڕەچەتە پەیوەستکراوەکانی ئەم فرۆشتنە دەکات بە تەواوکراو.
     * گرنگ: پێویستە پێش پاککردنەوە/داخستنی تاب بانگ بکرێت (لە pos-checkout.js)، چونکە
     * ناسنامەکان لەسەر تابی چالاک هەڵگیراون. ناسنامەکان یەکسەر (sync) وەردەگیرێن و
     * تابەکە پاک دەکرێتەوە پێش هەر await ـێک، بۆ ئەوەی دووبارە تەواو نەکرێتەوە.
     */
    async function onSaleCompleted() {
        const tab = getActiveTab();
        const ids = (tab && Array.isArray(tab.linkedPrescriptionIds))
            ? tab.linkedPrescriptionIds.slice()
            : [];
        // پاککردنەوەی پەیوەندییەکان لەسەر تابەکە یەکسەر، بۆ ڕێگرتن لە تەواوکردنەوەی دووبارە
        if (tab) {
            tab.linkedPrescriptionIds = [];
            persistTabs();
        }
        if (ids.length === 0) {
            return;
        }
        const failed = [];
        for (const id of ids) {
            const ok = await completePrescription(id, false);
            if (!ok) {
                failed.push(id);
            }
        }
        fetchCount();
        // ئەگەر تەواوکردنی خۆکار سەرکەوتوو نەبوو، ئاگادارکردنەوە بۆ ئەوەی پزیشکیار دەستی بیکات
        if (failed.length > 0) {
            const list = failed.map(function (n) { return '#' + n; }).join('، ');
            notify(`ئاگاداری: ڕەچەتەی ${list} نەکرا بە تەواوکراو. تکایە لە لیستی ڕەچەتەکان دەستی نوێی بکەرەوە.`, 'warning');
            // نوێکردنەوەی لیست ئەگەر مۆداڵ کراوەیە
            const modalEl = document.getElementById('posPrescriptionsModal');
            if (modalEl && modalEl.classList.contains('show')) {
                fetchList();
            }
        }
    }

    function init() {
        // بارکردنی سەرەتایی ژمارە
        fetchCount();

        const refreshBtn = document.getElementById('refreshPrescriptionsBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', fetchList);
        }

        // کاتێک مۆداڵ دەکرێتەوە، لیستی نوێ باربکە
        const modalEl = document.getElementById('posPrescriptionsModal');
        if (modalEl) {
            modalEl.addEventListener('show.bs.modal', fetchList);
        }

        // نوێکردنەوەی ژمارە بە شێوەی خۆکار
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(fetchCount, POLL_INTERVAL_MS);
    }

    window.POSPrescriptions = {
        init: init,
        refresh: fetchList,
        refreshCount: fetchCount,
        onSaleCompleted: onSaleCompleted
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
