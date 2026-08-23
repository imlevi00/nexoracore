        let scannerActive = false;
        let scannedBarcode = null;
        
        // Initialize scanner when modal is shown
        const scannerModal = document.getElementById('barcodeScannerModal');
        if (scannerModal) {
            scannerModal.addEventListener('shown.bs.modal', function() {
                startBarcodeScanner();
            });
            
            scannerModal.addEventListener('hidden.bs.modal', function() {
                stopBarcodeScanner();
            });
        }
        
        function startBarcodeScanner() {
            if (!POS.barcodeScanEnabled) {
                showPackageFeatureLockNotification();
                return;
            }
            if (scannerActive) return;

            scannerActive = true;
            const statusElement = document.getElementById('scanner-status');
            const resultElement = document.getElementById('scanned-result');
            
            if (statusElement) {
                statusElement.innerHTML = '<span class="badge bg-info">سکانکردن دەستپێکرا...</span>';
            }
            if (resultElement) {
                resultElement.style.display = 'none';
            }
            scannedBarcode = null;
            
            Quagga.init({
                inputStream: {
                    name: "Live",
                    type: "LiveStream",
                    target: document.querySelector('#interactive'),
                    constraints: {
                        width: { min: 640 },
                        height: { min: 480 },
                        aspectRatio: { min: 1, max: 100 },
                        facingMode: "environment" // Use back camera
                    }
                },
                locator: {
                    patchSize: "medium",
                    halfSample: true
                },
                numOfWorkers: 2,
                decoder: {
                    readers: [
                        "code_128_reader",
                        "ean_reader",
                        "ean_8_reader",
                        "code_39_reader",
                        "code_39_vin_reader",
                        "codabar_reader",
                        "upc_reader",
                        "upc_e_reader",
                        "i2of5_reader"
                    ]
                },
                locate: true
            }, function(err) {
                if (err) {
                    console.error('Scanner initialization error:', err);
                    if (statusElement) {
                        statusElement.innerHTML = '<span class="badge bg-danger">هەڵە: ' + err.message + '</span>';
                    }
                    scannerActive = false;
                    return;
                }
                
                Quagga.start();
                if (statusElement) {
                    statusElement.innerHTML = '<span class="badge bg-success">سکانکردن چالاکە - بارکۆدەکە بگرە</span>';
                }
            });
            
            Quagga.onDetected(function(result) {
                if (!scannerActive) return;
                
                const code = result.codeResult.code;
                
                // Prevent duplicate detections
                if (scannedBarcode === code) {
                    return;
                }
                
                scannedBarcode = code;
                
                // Stop scanning
                Quagga.stop();
                scannerActive = false;
                
                // Show result briefly
                const scannedValueElement = document.getElementById('scanned-barcode-value');
                const scannedResultElement = document.getElementById('scanned-result');
                
                if (scannedValueElement) {
                    scannedValueElement.textContent = code;
                }
                if (scannedResultElement) {
                    scannedResultElement.style.display = 'block';
                }
                if (statusElement) {
                    statusElement.innerHTML = '<span class="badge bg-success">بارکۆد دۆزرایەوە!</span>';
                }
                
                console.log('بارکۆد دۆزرایەوە:', code);
                
                // Automatically add barcode to input field and trigger search
                const barcodeInput = document.getElementById('barcodeInput');
                if (barcodeInput) {
                    barcodeInput.value = code;
                    
                    // Trigger input event to ensure all listeners are notified (this will trigger handleSearchInput)
                    barcodeInput.dispatchEvent(new Event('input', { bubbles: true }));
                    barcodeInput.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    // Also trigger Enter key to add product if found
                    setTimeout(() => {
                        const enterEvent = new KeyboardEvent('keydown', {
                            key: 'Enter',
                            code: 'Enter',
                            keyCode: 13,
                            which: 13,
                            bubbles: true
                        });
                        barcodeInput.dispatchEvent(enterEvent);
                    }, 100);
                }
                
                // Close modal after a short delay
                setTimeout(function() {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('barcodeScannerModal'));
                    if (modal) {
                        modal.hide();
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
            const resultElement = document.getElementById('scanned-result');
            if (resultElement) {
                resultElement.style.display = 'none';
            }
        }
