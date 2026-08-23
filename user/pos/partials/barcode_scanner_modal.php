    <div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="barcodeScannerModalLabel">
                        <i class="bi bi-camera"></i> سکانکردنی بارکۆد
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        کامێراکەت بەرەو بارکۆدەکە بگرە. بارکۆدەکە خۆکار دەسکان دەکرێت.
                    </div>
                    <div id="scanner-container" style="position: relative; width: 100%; min-height: 300px;">
                        <div id="interactive" class="viewport" style="width: 100%; height: 300px; background: #000; border-radius: 8px;"></div>
                        <div id="scanner-status" class="text-center mt-2">
                            <span class="badge bg-warning">چاوەڕوانی دەستپێکردن...</span>
                        </div>
                    </div>
                    <div id="scanned-result" class="mt-3" style="display: none;">
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i>
                            <strong>بارکۆد دۆزرایەوە:</strong> <span id="scanned-barcode-value"></span>
                            <br><small class="text-muted">بارکۆدەکە خۆکار زیاد دەکرێت و مۆدالەکە داخست دەکرێت...</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="stopBarcodeScanner()">
                        <i class="bi bi-x-circle"></i> داخستن
                    </button>
                </div>
            </div>
        </div>
    </div>
