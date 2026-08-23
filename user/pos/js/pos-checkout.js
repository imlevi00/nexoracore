        // ===== CHECKOUT PROCESS =====
       async function submitSaleOnline(saleData) {
    let response = await fetch('../../api/sales.php?_t=' + new Date().getTime(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify(saleData)
    });
    
    // If we get a 403 error, refresh CSRF token and retry once
    if (!response.ok && response.status === 403) {
        console.log('Session expired (403), refreshing token and retrying...');
        const tokenRefreshed = await refreshCSRFToken();
        if (tokenRefreshed) {
            saleData.csrf_token = POS.csrfToken;
            response = await fetch('../../api/sales.php?_t=' + new Date().getTime(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(saleData)
            });
        }
    }
    
    if (!response.ok) {
        throw new Error(`HTTP Error: ${response.status} - ${response.statusText}`);
    }
    
    const responseText = await response.text();
    let data;
    try {
        data = JSON.parse(responseText);
    } catch (parseError) {
        throw new Error('وەڵامی سێرڤەر JSON نیە');
    }
    return data;
}

       async function processCheckout() {
    if (POS.isSubmittingSale) {
        return;
    }
    if (POS.cart.length === 0) {
        showNotification('سەبەتە بەتاڵە', 'warning');
        return;
    }
    
    const customerName = document.getElementById('customerName').value.trim();
    const paymentMethod = document.getElementById('paymentMethod').value;
    const walletIdValue = document.getElementById('walletId').value;
    const walletId = walletIdValue ? parseInt(walletIdValue, 10) : null;
    const discountAmount = parseFloat(document.getElementById('discountAmount').value) || 0;
    const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;
    
    const totalAmount = POS.cart.reduce((sum, item) => sum + item.total, 0);
    const finalAmount = roundPriceByCurrency(Math.max(0, totalAmount - discountAmount), POS.currentCurrency);
    
    if (finalAmount <= 0) {
        showNotification('بەی کۆتاییی ناتوانێت سفر یان کەمتر بێت', 'danger');
        return;
    }
    
    // پشکنینی کۆگا تەنها لە سێرڤەر (api/sales.php) — پشکنینی لێرە هەڵە دەردەخست بە هۆی کۆگای کۆن لەسەر ئایتم یان کاڵای لە لیستدا نەبوون.
    
    // Check if credit payment requires customer selection
    if (paymentMethod === 'credit') {
        const selectedCustomerId = document.getElementById('selectedCustomerId').value;
        if (!selectedCustomerId) {
            showNotification('بۆ پارەدانی قەرز، پێویستە کڕیارێک هەڵبژێریت', 'warning');
            return;
        }
    }
    if (paymentMethod === 'cash' && (!walletId || Number.isNaN(walletId))) {
        showNotification('تکایە قاسەی پارەدان هەڵبژێرە', 'warning');
        return;
    }
    
    // پاککردنی داتا بۆ JSON
    const cleanData = (str) => {
        if (typeof str === 'string') {
            return str.replace(/[;'"]/g, '').trim();
        }
        return str;
    };
    
    const selectedCustomerId = document.getElementById('selectedCustomerId').value;
    
    // Build sale date/time for checkout (auto-now unless manually edited)
    const saleDateTime = getCheckoutSaleDateTime();
    
    // Determine decimal places based on currency
    const decimals = POS.currentCurrency === 'USD' ? 2 : 0;
    const roundedTotalAmount = roundPriceByCurrency(totalAmount, POS.currentCurrency);
    const roundedDiscountAmount = roundPriceByCurrency(discountAmount, POS.currentCurrency);
    const clientTxnId = (window.crypto && typeof window.crypto.randomUUID === 'function')
        ? window.crypto.randomUUID()
        : ('txn_' + Date.now() + '_' + Math.random().toString(16).slice(2));
    
    const saleData = {
        customer_id: selectedCustomerId ? parseInt(selectedCustomerId) : null,
        paid_amount: paidAmount,
        wallet_id: walletId,
        customer_name: cleanData(customerName),
        payment_method: cleanData(paymentMethod),
        total_amount: Number(roundedTotalAmount.toFixed(decimals)),
        discount: Number(roundedDiscountAmount.toFixed(decimals)),
        final_amount: Number(finalAmount.toFixed(decimals)),
        currency: POS.currentCurrency || 'IQD', // Add currency to sale data
        sale_date: saleDateTime, // Add sale date and time
        items: POS.cart.map(item => ({
            product_id: item.isExternal ? null : parseInt(item.id),
            product_name: cleanData(item.name),
            quantity: parseFloat(item.quantity),
            unit_price: Number(item.price.toFixed(decimals)),
            total_price: Number(item.total.toFixed(decimals)),
            price_type: cleanData(item.price_type || 'retail'),
            unit_id: item.unit_id != null && item.unit_id !== '' ? parseInt(item.unit_id, 10) : null,
            unit_name: cleanData(item.unit_name || 'دانە'),
            unit_symbol: cleanData(item.unit_symbol || ''),
            isExternal: item.isExternal || false,
            currency: item.currency || POS.currentCurrency || 'IQD', // Add currency to each item
            cost_price: item.isExternal && item.costPrice != null && item.costPrice !== '' && !isNaN(parseFloat(item.costPrice))
                ? Number(parseFloat(item.costPrice).toFixed(decimals))
                : null
        })),
        client_txn_id: clientTxnId,
        device_id: '',
        notes: document.getElementById('saleNotes').value.trim(),
        csrf_token: POS.csrfToken
    };

    try {
        const conflictsForSale = await fetchCartInteractionConflicts();
        POS.currentInteractionConflicts = conflictsForSale;
        refreshRiskAlertIndicator();
        if (conflictsForSale.length > 0) {
            const allowSale = await showRiskConfirmCard(conflictsForSale);
            if (!allowSale) {
                showNotification('فرۆشتن هەڵوەشێندرایەوە بە هۆی مەترسیی کاڵاکان', 'info');
                return;
            }
        }
    } catch (error) {
        // لە کاتی هەڵەی scan، flow ی فرۆشتن بەردەوام دەبێت
    }
    
    try {
        POS.isSubmittingSale = true;
        showLoading();
        
        // تاقیکردنی داتا پێش ناردن
        // console.log('=== CHECKOUT DEBUG INFO ===');
        // console.log('Payment method:', paymentMethod);
        // console.log('Customer ID:', selectedCustomerId);
        // console.log('Customer name:', customerName);
        // console.log('Total amount:', totalAmount);
        // console.log('Final amount:', finalAmount);
        // console.log('Sending sale data:', saleData);
        // console.log('Cart items:', POS.cart);
        // console.log('========================');
        
        // Check each cart item
        POS.cart.forEach((item, index) => {
            // console.log(`Cart item ${index}:`, {
            //     id: item.id,
            //     name: item.name,
            //     quantity: item.quantity,
            //     price: item.price,
            //     unit_id: item.unit_id,
            //     unit_name: item.unit_name
            // });
        });
        
        const data = await submitSaleOnline(saleData);
        
        if (data.success) {
            // نیشانکردنی ڕەچەتە پەیوەستکراوەکان وەک تەواوکراو (دۆخی سەنتەری پزیشکی).
            // پێویستە پێش clearCartSilent/closeCurrentTabAfterSale بانگ بکرێت، چونکە
            // ناسنامەی ڕەچەتەکان لەسەر تابی چالاک هەڵگیراون و ئەو فەنکشنانە تابەکە پاک/دادەخەن.
            if (window.POSPrescriptions && typeof POSPrescriptions.onSaleCompleted === 'function') {
                POSPrescriptions.onSaleCompleted();
            }
            showReceipt(data.data.sale);
            clearCartSilent();
            // Close only the current tab after successful sale
            closeCurrentTabAfterSale();
            showNotification('فرۆشتن بە سەرکەوتووییی تەواو بوو', 'success');
        } else {
        
            
            let errorMessage = 'هەڵەیەک ڕووی دا';
            if (data.message) {
                errorMessage = data.message;
            }
            
            // نیشاندانی هەڵەکە بە شێوەیەکی وردتر
            showNotification(`هەڵە: ${errorMessage}`, 'danger');
            
      
        }
        
    } catch (error) {
 
        
        // نیشاندانی هەڵەکە بە وردی
        let errorMsg = 'هەڵەیەک ڕووی دا لە فرۆشتن';
        if (error.message) {
            errorMsg = `هەڵە: ${error.message}`;
        }
        
        showNotification(errorMsg, 'danger');
        
        // نیشاندانی زانیاری زیاتر لە console
    } finally {
        POS.isSubmittingSale = false;
        hideLoading();
    }
}

