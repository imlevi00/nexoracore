        // ===== TAB MANAGEMENT =====
        function createNewSaleTab() {
            // Save current tab data before creating new one
            if (POS.activeTabId) {
                saveCurrentTabData();
            }
            
            // Check if current active tab is empty, if so reuse it instead of creating new one
            const activeTab = POS.tabs.find(t => t.id === POS.activeTabId);
            if (activeTab && activeTab.cart.length === 0 && !activeTab.selectedCustomer) {
                // Current tab is empty, just switch to it (it's already active)
                resetCurrentPriceTypeToDefault();
                showNotification('تابی بەتاڵ بەردەستە', 'info');
                return;
            }
            
            // Remove all empty tabs before creating new one
            removeEmptyTabs();
            
            POS.tabCounter++;
            let tabId = `tab_${POS.tabCounter}`;
            let tabName = `فرۆشتن ${POS.tabCounter}`;
            
            // Check for duplicate tab names and adjust counter if needed
            let duplicateFound = POS.tabs.some(tab => tab.name === tabName);
            while (duplicateFound) {
                POS.tabCounter++;
                const newTabName = `فرۆشتن ${POS.tabCounter}`;
                duplicateFound = POS.tabs.some(tab => tab.name === newTabName);
                if (!duplicateFound) {
                    tabName = newTabName;
                    tabId = `tab_${POS.tabCounter}`;
                }
            }
            
            const newTab = {
                id: tabId,
                name: tabName,
                cart: [],
                selectedCustomer: null,
                paymentMethod: POS.defaultPaymentMethod,
                walletId: POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '',
                discountAmount: '',
                saleNotes: ''
            };
            
            POS.tabs.push(newTab);
            POS.activeTabId = tabId;
            
            // Clear current cart for new tab
            POS.cart = [];
            POS.selectedCustomer = null;
            resetCurrentPriceTypeToDefault();
            document.getElementById('paymentMethod').value = POS.defaultPaymentMethod;
            document.getElementById('walletId').value = POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '';
            document.getElementById('discountAmount').value = '';
            document.getElementById('saleNotes').value = '';
            
            updateTabsDisplay();
            updateCartDisplay();
            syncPaymentMethodUI();
            saveCartToStorage();
            showNotification('فرۆشتنی نوێ دروستکرا', 'success');
        }
        
        // Function to remove empty tabs
        function removeEmptyTabs() {
            // Keep at least one tab, remove only empty ones
            const emptyTabs = POS.tabs.filter(tab => 
                tab.cart.length === 0 && !tab.selectedCustomer && tab.id !== POS.activeTabId
            );
            
            emptyTabs.forEach(emptyTab => {
                const index = POS.tabs.findIndex(t => t.id === emptyTab.id);
                if (index !== -1 && POS.tabs.length > 1) {
                    POS.tabs.splice(index, 1);
                }
            });
        }

        function switchTab(tabId) {
            if (POS.activeTabId === tabId) return;
            
            // Save current tab data
            if (POS.activeTabId) {
                saveCurrentTabData();
            }
            
            // Switch to new tab
            POS.activeTabId = tabId;
            const tab = POS.tabs.find(t => t.id === tabId);
            
            if (tab) {
                POS.cart = [...tab.cart];
                POS.selectedCustomer = tab.selectedCustomer;
                document.getElementById('paymentMethod').value = tab.paymentMethod;
                document.getElementById('walletId').value = tab.walletId || (POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '');
                document.getElementById('discountAmount').value = tab.discountAmount;
                document.getElementById('saleNotes').value = tab.saleNotes || '';
                
                updateTabsDisplay();
                updateCartDisplay();
                updateCustomerDisplay();
                syncPaymentMethodUI();
            }
        }

        function closeTab(tabId) {
            if (POS.tabs.length <= 1) {
                showNotification('ناتوانرێت کۆتا تاب ببەسترێت', 'warning');
                return;
            }
            
            const tabIndex = POS.tabs.findIndex(t => t.id === tabId);
            if (tabIndex === -1) return;
            
            const tabToClose = POS.tabs[tabIndex];
            
            // If closing active tab, switch to another tab
            if (POS.activeTabId === tabId) {
                // Find next available tab (prefer non-empty tabs)
                let nextTab = null;
                for (let i = 0; i < POS.tabs.length; i++) {
                    if (i !== tabIndex) {
                        const candidateTab = POS.tabs[i];
                        if (candidateTab.cart.length > 0 || candidateTab.selectedCustomer) {
                            nextTab = candidateTab;
                            break;
                        }
                    }
                }
                // If no non-empty tab found, use any other tab
                if (!nextTab) {
                    nextTab = POS.tabs[tabIndex === 0 ? 1 : tabIndex - 1];
                }
                if (nextTab) {
                    switchTab(nextTab.id);
                }
            }
            
            POS.tabs.splice(tabIndex, 1);
            updateTabsDisplay();
            saveCartToStorage();
        }

        function saveCurrentTabData() {
            if (!POS.activeTabId) return;
            
            const tab = POS.tabs.find(t => t.id === POS.activeTabId);
            if (tab) {
                tab.cart = [...POS.cart];
                tab.selectedCustomer = POS.selectedCustomer;
                tab.paymentMethod = document.getElementById('paymentMethod').value;
                tab.walletId = document.getElementById('walletId').value;
                tab.discountAmount = document.getElementById('discountAmount').value;
                tab.saleNotes = document.getElementById('saleNotes').value;
            }
        }

        function updateTabsDisplay() {
            const tabsList = document.getElementById('tabsList');
            tabsList.innerHTML = '';
            
            POS.tabs.forEach(tab => {
                const tabElement = document.createElement('div');
                tabElement.className = `tab-item ${tab.id === POS.activeTabId ? 'active' : ''}`;
                tabElement.onclick = () => switchTab(tab.id);
                
                const tabName = document.createElement('span');
                const itemCount = tab.cart.reduce((sum, item) => sum + item.quantity, 0);
                tabName.textContent = `${tab.name}${itemCount > 0 ? ` (${itemCount})` : ''}`;
                
                const closeBtn = document.createElement('button');
                closeBtn.className = 'tab-close';
                closeBtn.innerHTML = '×';
                closeBtn.type = 'button'; // Prevent form submission if inside a form
                closeBtn.onclick = (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    closeTab(tab.id);
                };
                closeBtn.setAttribute('title', 'داخستنی تاب');
                
                tabElement.appendChild(tabName);
                tabElement.appendChild(closeBtn);
                tabsList.appendChild(tabElement);
            });
            
            // Scroll active tab into view
            const activeTab = tabsList.querySelector('.tab-item.active');
            if (activeTab) {
                activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
        }

        // Initialize with default tab
        function initializeTabs() {
            if (isPosDataVersionStale()) {
                clearPosLocalStorageCache();
            }

            // Try to load saved tabs
            const savedTabs = localStorage.getItem('pos_tabs');
            const savedActiveTab = localStorage.getItem('pos_active_tab');
            
            if (savedTabs && savedActiveTab) {
                try {
                    POS.tabs = JSON.parse(savedTabs);
                    POS.tabs.forEach(tab => {
                        if (!tab.paymentMethod) {
                            tab.paymentMethod = POS.defaultPaymentMethod;
                        }
                        if (typeof tab.walletId === 'undefined' || tab.walletId === null) {
                            tab.walletId = POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '';
                        }
                        if (typeof tab.saleNotes === 'undefined' || tab.saleNotes === null) {
                            tab.saleNotes = '';
                        }
                    });
                    POS.activeTabId = savedActiveTab;
                    
                    // Validate that active tab still exists
                    const activeTab = POS.tabs.find(t => t.id === POS.activeTabId);
                    if (!activeTab) {
                        throw new Error('Active tab not found');
                    }
                    
                    // Load active tab data
                    POS.cart = [...activeTab.cart];
                    POS.selectedCustomer = activeTab.selectedCustomer;
                    document.getElementById('paymentMethod').value = activeTab.paymentMethod;
                    document.getElementById('walletId').value = activeTab.walletId || (POS.defaultWalletId > 0 ? String(POS.defaultWalletId) : '');
                    document.getElementById('discountAmount').value = activeTab.discountAmount;
                    document.getElementById('saleNotes').value = activeTab.saleNotes || '';
                    
                    updateTabsDisplay();
                    updateCartDisplay();
                    updateCustomerDisplay();
                    refreshStalePosData({ silent: true });
                    return;
                } catch (e) {
                    // Clear invalid data
                    localStorage.removeItem('pos_tabs');
                    localStorage.removeItem('pos_active_tab');
                }
            }
            
            // Create default tab if no saved data
            createNewSaleTab();
        }

        

        function printReceipt() {
            // پشتڕاستکردنەوەی هەبوونی sale ID
            if (!lastSaleId) {
                showNotification('تکایە یەکەم فرۆشتنێک تەواو بکە', 'warning');
                return;
            }
            
            // کردنەوەی تابێکی نوێ بەرەو وەسڵی چاپ
            const receiptUrl = POS.urls.receipt + '?id=' + lastSaleId + '&print=1';
            window.open(receiptUrl, '_blank');
        }

        function printA4Receipt() {
            // پشتڕاستکردنەوەی هەبوونی sale ID
            if (!lastSaleId) {
                showNotification('تکایە یەکەم فرۆشتنێک تەواو بکە', 'warning');
                return;
            }
            
            // کردنەوەی تابێکی نوێ بەرەو وەسڵی A4
            const a4ReceiptUrl = POS.urls.receiptA4 + '?id=' + lastSaleId;
            window.open(a4ReceiptUrl, '_blank');
        }

        async function sendReceiptToWhatsApp() {
            // پشتڕاستکردنەوەی هەبوونی زانیاری فرۆشتن
            if (!POS.lastSaleData) {
                showNotification('تکایە یەکەم فرۆشتنێک تەواو بکە', 'warning');
                return;
            }
            
            const saleData = POS.lastSaleData;
            
            // پشتڕاستکردنەوەی هەبوونی کڕیار
            if (!saleData.customer_id) {
                showNotification('تکایە کڕیارێک هەڵبژێرە بۆ ناردنی وەسڵ بۆ واتسئاپ', 'warning');
                return;
            }
            
            // پشتڕاستکردنەوەی هەبوونی ژمارە تەلەفۆن
            if (!saleData.customer_phone || saleData.customer_phone.trim() === '') {
                showNotification('ئەم کڕیارە ژمارە تەلەفۆنی نییە. تکایە ژمارە تەلەفۆن زیاد بکە لە بەشی کڕیاران', 'warning');
                return;
            }
            
            // ئامادەکردنی نامەی وەسڵ
            let message = `*وەسڵی فرۆشتن*\n\n`;
            message += `*${POS.receipt.businessName}*\n`;
            message += `━━━━━━━━━━━━━\n\n`;
            message += `ژمارەی وەسڵ: #${saleData.invoice_number}\n`;
            message += `بەروار: ${new Date(saleData.sale_date).toLocaleDateString('ku-IQ')}\n`;
            message += `کات: ${new Date(saleData.sale_date).toLocaleTimeString('ku-IQ', {hour: '2-digit', minute: '2-digit'})}\n`;
            
            if (saleData.customer_name) {
                message += `کڕیار: ${saleData.customer_name}\n`;
            }
            
            message += `\n*کاڵاکان:*\n`;
            message += `━━━━━━━━━━━━━\n`;
            
            saleData.items.forEach((item, index) => {
                message += `${index + 1}. ${item.product_name}\n`;
                message += `   ${formatMoney(item.unit_price)} × ${formatCompactQty(item.quantity)} = ${formatMoney(item.total_price)}\n`;
            });
            
            message += `\n━━━━━━━━━━━━━\n`;
            
            if (saleData.discount > 0) {
                message += `کۆی گشتی: ${formatMoney(saleData.total_amount)}\n`;
                message += `داشکاندن: -${formatMoney(saleData.discount)}\n`;
            }
            
            message += `*کۆی کۆتایی: ${formatMoney(saleData.final_amount)}*\n`;
            
            // زیادکردنی زانیاری پارەدان
            if (saleData.payment_method === 'credit') {
                const remainingDebt = saleData.final_amount - (saleData.paid_amount || 0);
                if (remainingDebt > 0) {
                    message += `\nپارەی واسڵکراو: ${formatMoney(saleData.paid_amount || 0)}\n`;
                    message += `*قەرزی وەسڵ: ${formatMoney(remainingDebt)}*\n`;
                    
                    // حیسابکردنی کۆی گشتی قەرزی ئێستا
                    // وەرگرتنی قەرزی کڕیار لە API
                    try {
                        const customerResponse = await posFetch(`ajax/search_customers.php?q=&id=${saleData.customer_id}`);
                        const customerData = await customerResponse.json();
                        
                        if (customerData.customers && customerData.customers.length > 0) {
                            const customer = customerData.customers[0];
                            const debtIqd = customer.current_debt_iqd || 0;
                            const debtUsd = customer.current_debt_usd || 0;

                            if (debtIqd > 0) {
                                message += `*کۆی گشتی قەرزی ئێستا (دینار): ${formatMoneyByCurrency(debtIqd, 'IQD')}*\n`;
                            }
                            if (debtUsd > 0) {
                                message += `*کۆی گشتی قەرزی ئێستا (دۆلار): ${formatMoneyByCurrency(debtUsd, 'USD')}*\n`;
                            }
                        }
                    } catch (error) {
                        console.error('Error fetching customer debt:', error);
                        // ئەگەر نەتوانرا قەرز وەربگرێت، تەنها قەرزی وەسڵ نیشان بدە
                    }
                }
            }
            
            message += `\n━━━━━━━━━━━━━\n`;
            message += `سوپاس بۆ هاتنتان\n`;
            message += `سیستەمی NexoraCore\nNexoraCore.com`;
            
            // پاککردنەوەی ژمارە تەلەفۆن (لابردنی هەموو کاراکتەرێک جگە لە ژمارە)
            let phoneNumber = saleData.customer_phone.replace(/\D/g, '');
            
            // ئەگەر ژمارەکە بە 0 دەست پێ دەکات، بیگۆڕە بۆ کۆدی وڵات (964 بۆ عێراق)
            if (phoneNumber.startsWith('0')) {
                phoneNumber = '964' + phoneNumber.substring(1);
            } else if (!phoneNumber.startsWith('964')) {
                phoneNumber = '964' + phoneNumber;
            }
            
            // دروستکردنی لینکی واتسئاپ
            const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
            
            // کردنەوەی واتسئاپ لە تابێکی نوێ
            window.open(whatsappUrl, '_blank');
            
            showNotification('واتسئاپ کرایەوە بۆ ناردنی وەسڵ', 'success');
        }

