<?php
/**
 * Fullscreen delegate sales product picker (markup only).
 */
?>
<div class="modal fade delegate-sales-modal" id="delegateSalesPickerModal" tabindex="-1"
     aria-labelledby="delegateSalesPickerModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content delegate-sales-modal__content">
            <header class="delegate-sales-header">
                <div class="delegate-sales-header__top">
                    <h5 class="delegate-sales-header__title" id="delegateSalesPickerModalLabel">
                        <i class="bi bi-shop-window" aria-hidden="true"></i>
                        فرۆشی مەندوب
                    </h5>
                    <div class="delegate-sales-header__actions">
                        <div class="delegate-sales-view-toggle btn-group btn-group-sm" role="group" aria-label="شێوازی نیشاندان">
                            <button type="button" class="btn btn-outline-secondary active" data-view="grid" title="تۆڕ">
                                <i class="bi bi-grid-3x3-gap"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" data-view="list" title="لیست">
                                <i class="bi bi-list-ul"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" data-view="horizontal" title="ئاسۆیی">
                                <i class="bi bi-arrows"></i>
                            </button>
                        </div>
                        <button type="button" class="btn-close delegate-sales-close" data-bs-dismiss="modal" aria-label="داخستن"></button>
                    </div>
                </div>
                <div class="delegate-sales-catalog-wrap">
                    <div class="delegate-sales-catalog" id="delegateCatalogStrip" role="tablist" aria-label="کەتەلۆگەکان"></div>
                </div>
                <div class="delegate-sales-search-wrap">
                    <div class="input-group delegate-sales-search">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input type="search" class="form-control" id="delegateSalesSearch"
                               placeholder="گەڕان بە ناو یان بارکۆد..." autocomplete="off" enterkeyhint="search">
                        <button type="button" class="btn btn-outline-secondary" id="delegateSalesSearchClear" title="پاککردنەوە" hidden>
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            </header>

            <div class="modal-body delegate-sales-body p-0">
                <div class="delegate-sales-products-scroll" id="delegateSalesProductsScroll">
                    <div id="delegateSalesLoading" class="delegate-sales-loading d-none">
                        <div class="delegate-sales-skeleton-grid" aria-hidden="true">
                            <?php for ($i = 0; $i < 8; $i++): ?>
                            <div class="delegate-sales-skeleton-card"></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div id="delegateSalesEmpty" class="delegate-sales-empty d-none">
                        <i class="bi bi-inbox display-4"></i>
                        <p>هیچ کاڵایەک نەدۆزرایەوە</p>
                    </div>
                    <div class="delegate-sales-products delegate-sales-view-grid" id="delegateSalesProducts" role="list"></div>
                    <div id="delegateSalesSentinel" class="delegate-sales-sentinel" aria-hidden="true"></div>
                    <div id="delegateSalesLoadMore" class="delegate-sales-load-more d-none">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="ms-2">بارکردنی زیاتر...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="delegate-sales-lightbox" id="delegateSalesImageLightbox" hidden role="dialog" aria-modal="true" aria-label="وێنەی گەورە">
    <button type="button" class="delegate-sales-lightbox__close" id="delegateSalesLightboxClose" aria-label="داخستن">
        <i class="bi bi-x-lg"></i>
    </button>
    <div class="delegate-sales-lightbox__backdrop" id="delegateSalesLightboxBackdrop"></div>
    <div class="delegate-sales-lightbox__inner">
        <img src="" alt="" id="delegateSalesLightboxImg" class="delegate-sales-lightbox__img">
    </div>
</div>
