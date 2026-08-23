    <!-- Barcode Scanner Modal -->
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

    <!-- مۆداڵی ڕێژەی یەکەکان لە خودی پەڕەکەدا (add.php / edit.php) پێناسە کراوە بۆ ڕێگریکردن لە دووبارەبوونەوەی id -->

    <script>
        let scannerActive = false;
        let scannedBarcode = null;
        let scanResults = []; // Store multiple scan results for validation
        const REQUIRED_MATCHES = 4; // Number of matching scans required (increased for better accuracy)
        
        // ==========================================
        // Format Validation Functions
        // ==========================================
        
        // Validate EAN-13 checksum
        function validateEAN13(code) {
            if (!/^\d{13}$/.test(code)) return false;
            
            let sum = 0;
            for (let i = 0; i < 12; i++) {
                sum += parseInt(code[i]) * (i % 2 === 0 ? 1 : 3);
            }
            const checkDigit = (10 - (sum % 10)) % 10;
            return checkDigit === parseInt(code[12]);
        }
        
        // Validate EAN-8 checksum
        function validateEAN8(code) {
            if (!/^\d{8}$/.test(code)) return false;
            
            let sum = 0;
            for (let i = 0; i < 7; i++) {
                sum += parseInt(code[i]) * (i % 2 === 0 ? 3 : 1);
            }
            const checkDigit = (10 - (sum % 10)) % 10;
            return checkDigit === parseInt(code[7]);
        }
        
        // Validate UPC-A checksum
        function validateUPCA(code) {
            if (!/^\d{12}$/.test(code)) return false;
            
            let sum = 0;
            for (let i = 0; i < 11; i++) {
                sum += parseInt(code[i]) * (i % 2 === 0 ? 3 : 1);
            }
            const checkDigit = (10 - (sum % 10)) % 10;
            return checkDigit === parseInt(code[11]);
        }
        
        // Validate UPC-E checksum
        function validateUPCE(code) {
            if (!/^\d{8}$/.test(code)) return false;
            // UPC-E is a compressed version of UPC-A, complex validation
            return true; // Basic length check passed
        }
        
        // Validate Code 39 character set
        function validateCode39(code) {
            // Code 39 valid characters: 0-9, A-Z, -, ., $, /, +, %, SPACE
            const validChars = /^[0-9A-Z\-\.\$\/\+\%\s\*]+$/;
            return validChars.test(code);
        }
        
        // Validate Code 93 character set  
        function validateCode93(code) {
            // Code 93 valid characters: 0-9, A-Z, -, ., $, /, +, %, SPACE and special shift chars
            const validChars = /^[0-9A-Z\-\.\$\/\+\%\s]+$/;
            return validChars.test(code);
        }
        
        // Validate Code 128 - can encode all ASCII
        function validateCode128(code) {
            // Code 128 can encode all ASCII characters
            return code.length > 0 && code.length <= 48;
        }
        
        // Validate Codabar
        function validateCodabar(code) {
            // Codabar: digits, -, $, :, /, ., + and start/stop chars A, B, C, D
            const validChars = /^[ABCD][0-9\-\$\:\/\.\+]+[ABCD]$/i;
            return validChars.test(code) || /^[0-9\-\$\:\/\.\+]+$/.test(code);
        }
         
        // Validate I2of5 (Interleaved 2 of 5)
        function validateI2of5(code) {
            // I2of5: numeric only, even number of digits
            return /^\d+$/.test(code) && code.length % 2 === 0;
        }
        
        // Check if code content matches the declared format
        function isFormatValid(code, format) {
            switch(format) {
                case 'ean_13':
                    return validateEAN13(code);
                case 'ean_8':
                    return validateEAN8(code);
                case 'upc_a':
                    return validateUPCA(code);
                case 'upc_e':
                    return validateUPCE(code);
                case 'code_39':
                    return validateCode39(code);
                case 'code_93':
                    return validateCode93(code);
                case 'code_128':
                    return validateCode128(code);
                case 'codabar':
                    return validateCodabar(code);
                case 'i2of5':
                    return validateI2of5(code);
                default:
                    return true;
            }
        }
        
        // Detect the most likely format for a code
        function detectLikelyFormat(code) {
            // Check numeric-only formats first
            if (/^\d+$/.test(code)) {
                if (code.length === 13 && validateEAN13(code)) return 'ean_13';
                if (code.length === 8 && validateEAN8(code)) return 'ean_8';
                if (code.length === 12 && validateUPCA(code)) return 'upc_a';
                if (code.length === 8 && validateUPCE(code)) return 'upc_e';
                if (code.length % 2 === 0) return 'i2of5';
            }
            
            // Check alphanumeric formats
            if (validateCode39(code)) return 'code_39';
            if (validateCode93(code)) return 'code_93';
            
            // Default to Code 128 as it can encode anything
            return 'code_128';
        }
        
        // ==========================================
        // Scanner Functions
        // ==========================================
        
        // Initialize scanner when modal is shown
        const scannerModal = document.getElementById('barcodeScannerModal');
        scannerModal.addEventListener('shown.bs.modal', function() {
            startBarcodeScanner();
        });
        
        scannerModal.addEventListener('hidden.bs.modal', function() {
            stopBarcodeScanner();
        });
        
        function startBarcodeScanner() {
            if (scannerActive) return;
            
            scannerActive = true;
            scanResults = []; // Reset scan results
            document.getElementById('scanner-status').innerHTML = '<span class="badge bg-info">سکانکردن دەستپێکرا...</span>';
            document.getElementById('scanned-result').style.display = 'none';
            scannedBarcode = null;
            
            Quagga.init({
                inputStream: {
                    name: "Live",
                    type: "LiveStream",
                    target: document.querySelector('#interactive'),
                    constraints: {
                        width: { min: 640, ideal: 1280 },
                        height: { min: 480, ideal: 720 },
                        aspectRatio: { min: 1, max: 100 },
                        facingMode: "environment" // Use back camera
                    }
                },
                locator: {
                    patchSize: "medium",
                    halfSample: false // Better accuracy
                },
                numOfWorkers: 4,
                frequency: 10, // Scan frequency
                decoder: {
                    readers: [
                        "code_128_reader",
                        "ean_reader",
                        "ean_8_reader",
                        "code_39_reader",
                        "code_93_reader",
                        "codabar_reader",
                        "upc_reader",
                        "upc_e_reader",
                        "i2of5_reader"
                    ],
                    multiple: false
                },
                locate: true
            }, function(err) {
                if (err) {
                    console.error('Scanner initialization error:', err);
                    document.getElementById('scanner-status').innerHTML = 
                        '<span class="badge bg-danger">هەڵە: ' + err.message + '</span>';
                    scannerActive = false;
                    return;
                }
                
                Quagga.start();
                document.getElementById('scanner-status').innerHTML = 
                    '<span class="badge bg-success">سکانکردن چالاکە - بارکۆدەکە بگرە</span>';
            });
            
            // Process each frame for validation
            Quagga.onProcessed(function(result) {
                const drawingCtx = Quagga.canvas.ctx.overlay;
                const drawingCanvas = Quagga.canvas.dom.overlay;
                
                if (result) {
                    // Draw detection boxes for visual feedback
                    if (result.boxes) {
                        drawingCtx.clearRect(0, 0, drawingCanvas.width, drawingCanvas.height);
                        result.boxes.filter(function(box) {
                            return box !== result.box;
                        }).forEach(function(box) {
                            Quagga.ImageDebug.drawPath(box, { x: 0, y: 1 }, drawingCtx, { color: "green", lineWidth: 2 });
                        });
                    }
                    
                    if (result.box) {
                        Quagga.ImageDebug.drawPath(result.box, { x: 0, y: 1 }, drawingCtx, { color: "#00F", lineWidth: 2 });
                    }
                    
                    if (result.codeResult && result.codeResult.code) {
                        Quagga.ImageDebug.drawPath(result.line, { x: 'x', y: 'y' }, drawingCtx, { color: 'red', lineWidth: 3 });
                    }
                }
            });
            
            Quagga.onDetected(function(result) {
                if (!scannerActive) return;
                
                const code = result.codeResult.code;
                const format = result.codeResult.format;
                
                // Calculate error rate from decodedCodes
                let avgError = 0;
                const errors = result.codeResult.decodedCodes
                    .filter(function(x) { return x.error !== undefined; })
                    .map(function(x) { return x.error; });
                
                if (errors.length > 0) {
                    avgError = errors.reduce(function(a, b) { return a + b; }, 0) / errors.length;
                }
                
                // Validate format matches content
                const formatValid = isFormatValid(code, format);
                const likelyFormat = detectLikelyFormat(code);
                
                console.log('Detected:', code, 'Format:', format, 'Error:', avgError.toFixed(4), 
                           'FormatValid:', formatValid, 'LikelyFormat:', likelyFormat);
                
                // Reject if error rate is too high
                if (avgError > 0.12) {
                    console.log('Rejected due to high error rate:', avgError);
                    return;
                }
                
                // Reject if format doesn't match content (e.g., Code 39 detected as EAN-13)
                if (!formatValid) {
                    console.log('Rejected: Format mismatch -', format, 'detected but content suggests', likelyFormat);
                    return;
                }
                
                // Use likely format instead of detected format if they differ
                const finalFormat = formatValid ? format : likelyFormat;
                
                // Add to results for validation
                scanResults.push({
                    code: code,
                    format: finalFormat,
                    error: avgError,
                    timestamp: Date.now()
                });
                
                // Keep only recent results (last 2 seconds)
                const now = Date.now();
                scanResults = scanResults.filter(r => (now - r.timestamp) < 2000);
                
                // Count occurrences of each code+format combination
                const codeFormatCounts = {};
                scanResults.forEach(r => {
                    const key = r.code + '|' + r.format;
                    codeFormatCounts[key] = (codeFormatCounts[key] || 0) + 1;
                });
                
                // Find the most common code+format combination
                let mostCommonKey = null;
                let maxCount = 0;
                for (const [key, count] of Object.entries(codeFormatCounts)) {
                    if (count > maxCount) {
                        maxCount = count;
                        mostCommonKey = key;
                    }
                }
                
                // Update status with progress
                document.getElementById('scanner-status').innerHTML = 
                    '<span class="badge bg-info">سکانکردن... (' + maxCount + '/' + REQUIRED_MATCHES + ')</span>';
                
                // Only accept if we have enough matching results
                if (maxCount < REQUIRED_MATCHES) {
                    return;
                }
                
                // Extract code and format from the key
                const [mostCommonCode, mostCommonFormat] = mostCommonKey.split('|');
                
                // Prevent duplicate final detections
                if (scannedBarcode === mostCommonCode) {
                    return;
                }
                
                scannedBarcode = mostCommonCode;
                
                // Stop scanning
                Quagga.stop();
                scannerActive = false;
                
                // Show result briefly
                const scannedValueElement = document.getElementById('scanned-barcode-value');
                const scannedResultElement = document.getElementById('scanned-result');
                
                if (scannedValueElement) {
                    scannedValueElement.textContent = mostCommonCode;
                }
                if (scannedResultElement) {
                    scannedResultElement.style.display = 'block';
                }
                document.getElementById('scanner-status').innerHTML = 
                    '<span class="badge bg-success">بارکۆد دۆزرایەوە! (' + mostCommonFormat + ')</span>';
                
                console.log('بارکۆد دۆزرایەوە:', mostCommonCode, 'جۆر:', mostCommonFormat);
                
                // Automatically add barcode to input field
                const barcodeInput = document.getElementById('barcode');
                if (barcodeInput) {
                    barcodeInput.value = mostCommonCode;
                    
                    // Trigger input event to ensure all listeners are notified
                    barcodeInput.dispatchEvent(new Event('input', { bubbles: true }));
                    barcodeInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                
                // Close modal after a short delay
                setTimeout(function() {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('barcodeScannerModal'));
                    if (modal) {
                        modal.hide();
                    }
                    
                    // Show success message
                    if (typeof showMessage === 'function') {
                        showMessage('بارکۆد بە سەرکەوتوویی زیادکرا: ' + mostCommonCode, 'success');
                    }
                    
                    // Focus on the barcode input field
                    if (barcodeInput) {
                        barcodeInput.focus();
                    }
                }, 1000); // Wait 1 second to show the result before closing
            });
        }
        
        function stopBarcodeScanner() {
            if (scannerActive) {
                try {
                    Quagga.stop();
                } catch(e) {
                    console.error('Error stopping scanner:', e);
                }
                scannerActive = false;
            }
            scannedBarcode = null;
            scanResults = [];
            document.getElementById('scanned-result').style.display = 'none';
        }
    </script>
