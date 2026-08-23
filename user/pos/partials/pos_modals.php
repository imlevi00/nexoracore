    <!-- Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-receipt"></i> وەسڵی فرۆشتن
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="receiptContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="printReceiptBtn">
                        <i class="bi bi-printer"></i> چاپکردن
                    </button>
                    <button type="button" class="btn btn-info" id="printA4ReceiptBtn">
                        <i class="bi bi-file-earmark-text"></i> وەسڵی A4
                    </button>
                    <button type="button" class="btn btn-success" id="sendWhatsAppBtn" style="display: none;">
                        <i class="bi bi-whatsapp"></i> ناردن بۆ واتسئاپ
                    </button>
                    <button type="button" class="btn btn-success" id="newSaleFromReceiptBtn">
                        <i class="bi bi-plus-circle"></i> فرۆشتنی نوێ
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        داخستن
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- External Product Modal -->
    <div class="modal fade" id="externalProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-square"></i> کاڵای دەرەکی
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="externalProductForm">
                        <div class="mb-3">
                            <label class="form-label">ناوی کاڵا</label>
                            <input type="text" class="form-control" id="externalProductName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">بڕ</label>
                            <input type="number" class="form-control" id="externalProductQuantity" min="0.01" step="0.01" value="1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نرخی کڕین</label>
                            <input type="number" class="form-control" id="externalProductCostPrice" min="0" step="0.001" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نرخی فرۆشتن</label>
                            <input type="number" class="form-control" id="externalProductSellPrice" step="0.001" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" id="addExternalProductConfirmBtn">
                        <i class="bi bi-plus-circle"></i> زیادکردن
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        هەڵوەشاندنەوە
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="riskDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-diamond-fill text-danger"></i> زانیاری مەترسیی کاڵاکان</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="riskDetailsModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                </div>
            </div>
        </div>
    </div>
