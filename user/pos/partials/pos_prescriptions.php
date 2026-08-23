<?php
/**
 * ئاگادارکردنەوە و لیستی ڕەچەتەکانی سەنتەری پزیشکی بۆ بەشی فرۆشتن.
 * تەنها کاتێک دەردەکەوێت کە دۆخی سەنتەری پزیشکی چالاک بێت ($posIsMedicalCenterMode).
 * user/pos/partials/pos_prescriptions.php
 */
?>
<style>
    .pos-rx-fab {
        position: fixed;
        bottom: 24px;
        inset-inline-start: 24px;
        z-index: 1045;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 18px;
        border: none;
        border-radius: 999px;
        background: linear-gradient(135deg, #0d6efd, #0a58ca);
        color: #fff;
        font-size: 0.95rem;
        font-weight: 600;
        box-shadow: 0 6px 20px rgba(13, 110, 253, 0.35);
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .pos-rx-fab:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 26px rgba(13, 110, 253, 0.45);
    }
    .pos-rx-fab i { font-size: 1.25rem; }
    .pos-rx-fab-badge {
        position: absolute;
        top: -6px;
        inset-inline-end: -6px;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border-radius: 999px;
        background: #dc3545;
        color: #fff;
        font-size: 0.75rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 0 0 2px #fff;
    }
    .pos-rx-fab.has-pending { animation: posRxPulse 2s infinite; }
    @keyframes posRxPulse {
        0% { box-shadow: 0 6px 20px rgba(13, 110, 253, 0.35), 0 0 0 0 rgba(220, 53, 69, 0.5); }
        70% { box-shadow: 0 6px 20px rgba(13, 110, 253, 0.35), 0 0 0 12px rgba(220, 53, 69, 0); }
        100% { box-shadow: 0 6px 20px rgba(13, 110, 253, 0.35), 0 0 0 0 rgba(220, 53, 69, 0); }
    }
    .pos-rx-card {
        border: 1px solid var(--bs-border-color, #dee2e6);
        border-radius: 12px;
        padding: 14px 16px;
        margin-bottom: 12px;
        background: var(--bs-body-bg, #fff);
    }
    .pos-rx-card .rx-patient { font-size: 1.05rem; font-weight: 700; }
    .pos-rx-meta { font-size: 0.85rem; }
    .pos-rx-med-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .pos-rx-med-list .rx-med {
        background: var(--bs-secondary-bg, #f1f3f5);
        border-radius: 8px;
        padding: 4px 10px;
        font-size: 0.85rem;
    }
    .pos-rx-med-list .rx-med-qty {
        display: inline-block;
        margin-inline-start: 4px;
        padding: 1px 8px;
        border-radius: 999px;
        background: var(--bs-primary, #0d6efd);
        color: #fff;
        font-size: 0.78rem;
        font-weight: 600;
    }
</style>

<?php
// Token ـی CSRF ـی server-rendered — دڵنیایی هەمان session و هەرگیز بەتاڵ نابێت.
$rxCsrfToken = (isset($csrf_token) && $csrf_token !== '')
    ? $csrf_token
    : (class_exists('Security') ? Security::generateCSRFToken() : '');
?>
<div id="posPrescriptionsRoot" data-csrf="<?php echo htmlspecialchars($rxCsrfToken, ENT_QUOTES, 'UTF-8'); ?>" style="display: none;"></div>

<!-- Floating notification button -->
<button type="button" id="prescriptionsFabBtn" class="pos-rx-fab" title="ڕەچەتەکانی دکتۆر"
        data-bs-toggle="modal" data-bs-target="#posPrescriptionsModal">
    <i class="bi bi-file-medical"></i>
    <span>ڕەچەتەکان</span>
    <span class="pos-rx-fab-badge" id="prescriptionsFabBadge" style="display: none;">0</span>
</button>

<!-- Prescriptions modal -->
<div class="modal fade" id="posPrescriptionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-medical text-primary"></i> ڕەچەتەکانی چاوەڕوان
                    <span class="badge text-bg-primary ms-1" id="prescriptionsModalCount">0</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
            </div>
            <div class="modal-body">
                <div id="prescriptionsListContainer">
                    <div class="text-center text-body-secondary py-5">
                        <div class="spinner-border text-primary mb-2"></div>
                        <p class="mb-0">لە بارکردندا...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary" id="refreshPrescriptionsBtn">
                    <i class="bi bi-arrow-clockwise"></i> نوێکردنەوە
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
            </div>
        </div>
    </div>
</div>
