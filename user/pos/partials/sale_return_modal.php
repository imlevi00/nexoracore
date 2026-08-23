<?php
/**
 * Modal for sale-linked product returns.
 */
?>
<div class="modal fade" id="saleReturnModal" tabindex="-1" aria-labelledby="saleReturnModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="saleReturnModalLabel">
                    <i class="bi bi-arrow-return-left me-2"></i>گەڕاندنەوەی کاڵا لە وەسڵ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="saleReturnLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted mt-2 mb-0">بارکردنی کاڵاکان...</p>
                </div>

                <div id="saleReturnContent" class="d-none">
                    <div class="alert alert-secondary border mb-3" id="saleReturnSaleInfo"></div>

                    <h6 class="text-muted mb-2">
                        <i class="bi bi-receipt me-1"></i>کاڵاکانی ئەم وەسڵە
                    </h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>کاڵا</th>
                                    <th class="text-center">فرۆشراو</th>
                                    <th class="text-center">پێشتر گەڕاوە</th>
                                    <th class="text-center">ماوە</th>
                                    <th style="width: 120px;" class="text-center">بڕی گەڕاندنەوە</th>
                                    <th class="text-end">کۆ</th>
                                </tr>
                            </thead>
                            <tbody id="saleReturnItemsBody"></tbody>
                        </table>
                    </div>

                    <div class="card border mb-3">
                        <div class="card-header py-2 bg-body-secondary">
                            <i class="bi bi-plus-circle me-1"></i>زیادکردنی کاڵای تر بۆ گەڕاندنەوە
                        </div>
                        <div class="card-body py-2">
                            <div class="position-relative">
                                <input type="text"
                                       class="form-control"
                                       id="saleReturnProductSearch"
                                       placeholder="گەڕان بە ناو، بارکۆد، یان پۆل..."
                                       autocomplete="off">
                                <div id="saleReturnSearchResults"
                                     class="list-group position-absolute w-100 shadow-sm d-none"
                                     style="z-index: 1050; max-height: 220px; overflow-y: auto;"></div>
                            </div>
                            <small class="text-muted d-block mt-1">هەر کاڵایەک لە کۆگادا دەتوانرێت زیاد بکرێت و بەگەڕاوە هەژمار بکرێت لەسەر وەسڵ</small>
                        </div>
                    </div>

                    <div id="saleReturnExtraWrap" class="d-none mb-3">
                        <h6 class="text-muted mb-2">
                            <i class="bi bi-box-seam me-1"></i>کاڵا زیادکراوەکان
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>کاڵا</th>
                                        <th class="text-center">یەکە</th>
                                        <th style="width: 90px;" class="text-center">بڕ</th>
                                        <th style="width: 110px;" class="text-center">نرخ/یەک</th>
                                        <th class="text-end">کۆ</th>
                                        <th style="width: 40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="saleReturnExtraBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card border mb-3">
                        <div class="card-body py-2">
                            <div class="row g-2 small">
                                <div class="col-sm-4 d-flex justify-content-between">
                                    <span class="text-muted">کۆی گەڕاندنەوە:</span>
                                    <strong id="saleReturnSummaryTotal">0</strong>
                                </div>
                                <div class="col-sm-4 d-flex justify-content-between" id="saleReturnSummaryDebtWrap">
                                    <span class="text-muted">کەمکردنەوەی قەرز:</span>
                                    <strong class="text-warning" id="saleReturnSummaryDebt">0</strong>
                                </div>
                                <div class="col-sm-4 d-flex justify-content-between" id="saleReturnSummaryCashWrap">
                                    <span class="text-muted">گەڕاندنەوەی نەقد:</span>
                                    <strong class="text-success" id="saleReturnSummaryCash">0</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">شێوەی گەڕاندنەوە</label>
                            <input type="text" class="form-control" id="saleReturnPaymentMethod" readonly>
                        </div>
                        <div class="col-md-4" id="saleReturnWalletWrap">
                            <label class="form-label">قاسە <span class="text-muted small" id="saleReturnWalletHint"></span></label>
                            <select class="form-select" id="saleReturnWalletId"></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">کۆی گەڕاندنەوە</label>
                            <input type="text" class="form-control fw-bold" id="saleReturnTotal" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">هۆکار (ئارەزوومەندانە)</label>
                        <input type="text" class="form-control" id="saleReturnReason" placeholder="تێبینی گەڕاندنەوە">
                    </div>

                    <div id="saleReturnPriorWrap" class="d-none">
                        <h6 class="text-muted mb-2">مێژووی گەڕاندنەوە بۆ ئەم وەسڵە</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>ژمارە</th>
                                        <th>بەروار</th>
                                        <th>کۆ</th>
                                    </tr>
                                </thead>
                                <tbody id="saleReturnPriorBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="saleReturnError" class="alert alert-danger d-none mb-0"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                <button type="button" class="btn btn-success" id="saleReturnSubmitBtn" disabled>
                    <i class="bi bi-check-circle me-1"></i>تۆمارکردنی گەڕاندنەوە
                </button>
            </div>
        </div>
    </div>
</div>
