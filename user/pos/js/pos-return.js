        // ===== RETURN PROCESS =====
        async function processReturn(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            if (POS.cart.length === 0) {
                showNotification('سەبەتە بەتاڵە', 'warning');
                return;
            }
            if (POS.cart.some(item => item.isExternal)) {
                showNotification('گەڕاندنەوە بۆ خزمەتگوزاری/کاڵای دەرەکی چالاک نییە', 'warning');
                return;
            }
            
            if (!confirm('ئایا دڵنیایت لە گەڕاندنەوەی ئەم کاڵایانە؟')) {
                return;
            }
            
            const customerName = document.getElementById('customerName').value.trim();
            const paymentMethod = document.getElementById('paymentMethod').value;
            const walletIdValue = document.getElementById('walletId').value;
            const walletId = walletIdValue ? parseInt(walletIdValue, 10) : null;
            const discountAmount = parseFloat(document.getElementById('discountAmount').value) || 0;
            const selectedCustomerId = document.getElementById('selectedCustomerId').value;

            if ((paymentMethod === 'credit' || paymentMethod === 'debt') && !selectedCustomerId) {
                showNotification('بۆ پارەدانی قەرز، پێویستە کڕیارێک هەڵبژێریت', 'warning');
                return;
            }
            if (paymentMethod === 'cash' && (!walletId || Number.isNaN(walletId))) {
                showNotification('تکایە قاسەی پارەدان هەڵبژێرە', 'warning');
                return;
            }
            
            const totalAmount = POS.cart.reduce((sum, item) => sum + item.total, 0);
            const finalAmount = roundPriceByCurrency(Math.max(0, totalAmount - discountAmount), POS.currentCurrency);
            
            // پاککردنی داتا بۆ JSON
            const cleanData = (str) => {
                if (typeof str === 'string') {
                    return str.replace(/[;'"]/g, '').trim();
                }
                return str;
            };
            
            // Determine decimal places based on currency
            const decimals = POS.currentCurrency === 'USD' ? 2 : 0;
            const roundedTotalAmount = roundPriceByCurrency(totalAmount, POS.currentCurrency);
            const roundedDiscountAmount = roundPriceByCurrency(discountAmount, POS.currentCurrency);
            
            const returnData = {
                customer_id: selectedCustomerId ? parseInt(selectedCustomerId) : null,
                customer_name: cleanData(customerName),
                wallet_id: walletId,
                payment_method: cleanData(paymentMethod),
                total_amount: Number(roundedTotalAmount.toFixed(decimals)),
                discount: Number(roundedDiscountAmount.toFixed(decimals)),
                final_amount: Number(finalAmount.toFixed(decimals)),
                currency: POS.currentCurrency || 'IQD',
                items: POS.cart.map(item => ({
                    product_id: parseInt(item.id),
                    product_name: cleanData(item.name),
                    quantity: parseFloat(item.quantity),
                    unit_price: Number(item.price.toFixed(decimals)),
                    total_price: Number(item.total.toFixed(decimals)),
                    price_type: cleanData(item.price_type || 'retail'),
                    unit_id: item.unit_id != null && item.unit_id !== '' ? parseInt(item.unit_id, 10) : null,
                    unit_name: cleanData(item.unit_name || 'دانە'),
                    unit_symbol: cleanData(item.unit_symbol || '')
                })),
                csrf_token: POS.csrfToken
            };
            
            try {
                showLoading();
                
                // console.log('Sending return data:', returnData);
                
                let response = await fetch('../../api/returns.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(returnData)
                });
                
                // If we get a 403 error, refresh CSRF token and retry once
                if (!response.ok && response.status === 403) {
                    console.log('Session expired (403), refreshing token and retrying...');
                    
                    // Refresh the CSRF token
                    const tokenRefreshed = await refreshCSRFToken();
                    
                    if (tokenRefreshed) {
                        // Update the return data with new token
                        returnData.csrf_token = POS.csrfToken;
                        
                        // Retry the request with new token
                        response = await fetch('../../api/returns.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify(returnData)
                        });
                    }
                }
                
                const responseText = await response.text();
                // console.log('Raw response:', responseText);
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    throw new Error('وەڵامی سێرڤەر JSON نیە');
                }

                if (!response.ok) {
                    throw new Error(data?.message || `HTTP Error: ${response.status} - ${response.statusText}`);
                }
                
                if (data.success) {
                    showReturnReceipt(data.data.return);
                    clearCartSilent();
                    // Close only the current tab after successful return
                    closeCurrentTabAfterSale();
                    showNotification('گەڕاندنەوە بە سەرکەوتووییی تەواو بوو', 'success');
                } else {
                    throw new Error(data.message || 'هەڵەیەک ڕووی دا');
                }
                
            } catch (error) {
                console.error('Return error:', error);
                showNotification('هەڵەیەک ڕووی دا لە گەڕاندنەوە: ' + error.message, 'danger');
            } finally {
                hideLoading();
            }
        }

        function showReceipt(saleData) {
            // پاراستنی ID ـی فرۆشتن بۆ بەکارهێنان لە دووگمەی وەسڵی A4
            lastSaleId = saleData.id;
            
            // پاراستنی زانیاری کڕیار بۆ دوگمەی واتسئاپ
            POS.lastSaleData = saleData;
            
            console.log('Sale Data:', saleData); // بۆ تاقیکردنەوە
            console.log('Customer ID:', saleData.customer_id);
            console.log('Customer Phone:', saleData.customer_phone);
            
            const receiptContent = generateReceiptHTML(saleData);
            document.getElementById('receiptContent').innerHTML = receiptContent;
            
            // نیشاندانی دوگمەی واتسئاپ ئەگەر کڕیارێک هەڵبژێردرابێت
            const whatsappBtn = document.getElementById('sendWhatsAppBtn');
            if (saleData.customer_id) {
                whatsappBtn.style.display = 'inline-block';
            } else {
                whatsappBtn.style.display = 'none';
            }
            
            new bootstrap.Modal(document.getElementById('receiptModal')).show();
        }

        function generateReceiptHTML(saleData) {
            const receipt = POS.receipt;
            const logoBlock = receipt.logoUrl
                ? `<img src="${receipt.logoUrl}" alt="Logo" style="max-width: 80px; max-height: 80px;"><br>`
                : '';
            const headerBlock = receipt.headerHtml
                ? `<div style="font-size: 10px; margin: 5px 0;">${receipt.headerHtml}</div>`
                : '';
            return `
                <div class="text-center" style="max-width: 400px; margin: 0 auto; font-family: monospace;">
                    <!-- Receipt Header -->
                    <div class="receipt-header">
                        ${logoBlock}
                        <strong>${receipt.businessName}</strong>
                        <br>
                        ${headerBlock}
                        
                        <div style="font-size: 10px;">
                            بەرواری فرۆشتن: ${new Date(saleData.sale_date).toLocaleDateString('ku-IQ')}
                            <br>
                            کاتژمێر: ${new Date(saleData.sale_date).toLocaleTimeString('ku-IQ', {hour: '2-digit', minute: '2-digit'})}
                            <br>
                            ژمارەی وەسڵ: #${saleData.invoice_number}
                        </div>
                    </div>
                    ${saleData.customer_name ? `<p>کڕیار: ${saleData.customer_name}</p>` : ''}
                    <hr>
                    
                    ${saleData.items.map(item => `
                        <div class="d-flex justify-content-between mb-1">
                            <div class="text-start">
                                <div>${item.product_name}</div>
                                <small>${formatMoney(item.unit_price)} × ${formatCompactQty(item.quantity)}</small>
                            </div>
                            <div>${formatMoney(item.total_price)}</div>
                        </div>
                    `).join('')}
                    
                    <hr>
                    ${saleData.discount > 0 ? `
                        <div class="d-flex justify-content-between">
                            <span>کۆی گشتی:</span>
                            <span>${formatMoney(saleData.total_amount)}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>داشکاندن:</span>
                            <span>-${formatMoney(saleData.discount)}</span>
                        </div>
                    ` : ''}
                    <div class="d-flex justify-content-between fw-bold">
                        <span>کۆی کۆتایی:</span>
                        <span>${formatMoney(saleData.final_amount)}</span>
                    </div>
                    <hr>
                    <p style="white-space: pre-line;">${receipt.footerHtml}</p>
                </div>
            `;
        }

        function showReturnReceipt(returnData) {
            const receiptContent = generateReturnReceiptHTML(returnData);
            document.getElementById('receiptContent').innerHTML = receiptContent;
            new bootstrap.Modal(document.getElementById('receiptModal')).show();
        }

        function generateReturnReceiptHTML(returnData) {
            const receipt = POS.receipt;
            return `
                <div class="text-center" style="max-width: 400px; margin: 0 auto; font-family: monospace;">
                    <h4>${receipt.headerText}</h4>
                    <hr>
                    <p><strong>گەڕاندنەوەی کاڵا</strong></p>
                    <p>ژمارەی گەڕاندنەوە: <strong>${returnData.return_number}</strong></p>
                    <p>بەروار: ${new Date(returnData.return_date).toLocaleDateString('ku-IQ')}</p>
                    <p>کات: ${new Date(returnData.return_date).toLocaleTimeString('ku-IQ')}</p>
                    ${returnData.customer_name ? `<p>کڕیار: ${returnData.customer_name}</p>` : ''}
                    <hr>
                    
                    ${returnData.items.map(item => `
                        <div class="d-flex justify-content-between mb-1">
                            <div class="text-start">
                                <div>${item.product_name}</div>
                                <small>${formatMoney(item.unit_price)} × ${formatCompactQty(item.quantity)}</small>
                            </div>
                            <div>${formatMoney(item.total_price)}</div>
                        </div>
                    `).join('')}
                    
                    <hr>
                    ${returnData.discount > 0 ? `
                        <div class="d-flex justify-content-between">
                            <span>کۆی گشتی:</span>
                            <span>${formatMoney(returnData.total_amount)}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>داشکاندن:</span>
                            <span>-${formatMoney(returnData.discount)}</span>
                        </div>
                    ` : ''}
                    <div class="d-flex justify-content-between fw-bold">
                        <span>کۆی کۆتایی:</span>
                        <span>${formatMoney(returnData.final_amount)}</span>
                    </div>
                    <hr>
                    <p><strong>سپاس بۆ گەڕاندنەوە</strong></p>
                    <p style="white-space: pre-line;">${receipt.footerHtml}</p>
                </div>
            `;
        }

