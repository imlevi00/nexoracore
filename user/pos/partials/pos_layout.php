<body class="pos-page">

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="text-center">
            <div class="spinner-border text-primary pos-loading-spinner"></div>
            <p class="mt-2 mb-0">لە چاوەڕوانیدا...</p>
        </div>
    </div>

    <div class="pos-container">
        <!-- Main Layout -->
        <div class="main-layout">
            
            <!-- Left Section - Controls -->
            <div class="left-section pos-left-section" id="leftSection">
                <!-- Top Buttons Section -->
                <div class="control-section">
                    <div class="d-flex gap-2 align-items-center">
                       
                        <!-- Dashboard Back Button - Right (Smaller) -->
                        <a href="../dashboard/" class="back-to-dashboard-small">
                            <i class="bi bi-arrow-right"></i>
                            گەڕانەوە بۆ داشبۆرد
                        </a>
                         <!-- Sales Button - Left -->
                        <button class="btn sales-btn-custom flex-fill" id="salesBtn" onclick="window.open('sales.php', '_blank')">
                            <i class="bi bi-receipt"></i> وەسڵەکان
                        </button>
                    </div>
                </div>

                <!-- New Sale Button -->
                <!-- Dollar Price Display / Editor (تایبەت بەم ئەکاونتە و کارمەندەکانی) -->
                <div class="control-section pos-control-section">
                    <div class="dollar-price-display" id="dollarPriceCard">
                        <!-- دۆخی نیشاندان — کرتەکردنی بۆ گۆڕینی نرخ -->
                        <div class="dollar-price-view" id="dollarPriceView" role="button" tabindex="0"
                             title="کرتە بکە بۆ گۆڕینی نرخی دۆلار">
                            <div class="price-label">
                                نرخی دۆلار
                                <i class="bi bi-pencil-square dollar-edit-icon"></i>
                            </div>
                            <div class="price-value">
                                <i class="bi bi-currency-dollar"></i>
                                <span id="dollarPriceValue"><?php echo $dollarPrice ? number_format($dollarPrice, 0) : '—'; ?></span>
                            </div>
                            <div class="currency">دینار</div>
                            <div class="last-updated" id="dollarLastUpdated">
                                <?php echo !empty($dollarLastUpdatedDisplay) ? $dollarLastUpdatedDisplay : ''; ?>
                            </div>
                        </div>
                        <!-- دۆخی دەستکاری -->
                        <div class="dollar-price-edit pos-hidden" id="dollarPriceEdit">
                            <div class="price-label">نرخی نوێی دۆلار (دینار)</div>
                            <div class="dollar-edit-row">
                                <input type="number" inputmode="numeric" min="101" max="9999" step="1"
                                       class="form-control dollar-edit-input" id="dollarRateInput"
                                       placeholder="بۆ نمونە: 1490">
                            </div>
                            <div class="dollar-edit-actions">
                                <button type="button" class="btn btn-success btn-sm" id="dollarRateSaveBtn">
                                    <i class="bi bi-check-lg"></i> پاشەکەوت
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="dollarRateCancelBtn">
                                    <i class="bi bi-x-lg"></i> پاشگەزبوونەوە
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Price Type and Currency Selection -->
                <div class="control-section">
                    <div class="d-flex gap-2">
                        <!-- Price Type Selection -->
                        <div class="dropdown custom-dropdown-select flex-fill">
                            <button class="btn custom-dropdown-btn w-100" type="button" id="priceTypeDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi <?php echo htmlspecialchars($posDefaultPriceTypeMeta['icon'], ENT_QUOTES, 'UTF-8'); ?>" id="priceTypeIcon"></i>
                                <span id="priceTypeText"><?php echo htmlspecialchars($posDefaultPriceTypeMeta['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <i class="bi bi-chevron-down ms-auto"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end w-100" aria-labelledby="priceTypeDropdown">
                                <li><a class="dropdown-item price-type-dropdown-item<?php echo $posDefaultPriceType === 'retail' ? ' active' : ''; ?>" href="#" data-price-type="retail" data-icon="bi-1-circle" data-text="تاک">
                                    <i class="bi bi-1-circle"></i> تاک
                                </a></li>
                                <li><a class="dropdown-item price-type-dropdown-item<?php echo $posDefaultPriceType === 'wholesale' ? ' active' : ''; ?>" href="#" data-price-type="wholesale" data-icon="bi-2-circle" data-text="جوملە">
                                    <i class="bi bi-2-circle"></i> جوملە
                                </a></li>
                                <li><a class="dropdown-item price-type-dropdown-item<?php echo $posDefaultPriceType === 'special' ? ' active' : ''; ?>" href="#" data-price-type="special" data-icon="bi-star" data-text="تایبەت">
                                    <i class="bi bi-star"></i> تایبەت
                                </a></li>
                            </ul>
                        </div>
                        <!-- Currency Selection -->
                        <div class="dropdown custom-dropdown-select flex-fill">
                            <?php
                            $posCurIqdActive = ($posEffectiveSaleCurrency !== 'USD');
                            $posCurBtnIcon = $posCurIqdActive ? 'bi-cash-coin' : 'bi-currency-dollar';
                            $posCurBtnText = $posCurIqdActive ? 'دینار' : 'دۆلار';
                            ?>
                            <button class="btn custom-dropdown-btn w-100" type="button" id="currencyDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi <?php echo htmlspecialchars($posCurBtnIcon, ENT_QUOTES, 'UTF-8'); ?>" id="currencyIcon"></i>
                                <span id="currencyText"><?php echo htmlspecialchars($posCurBtnText, ENT_QUOTES, 'UTF-8'); ?></span>
                                <i class="bi bi-chevron-down ms-auto"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end w-100" aria-labelledby="currencyDropdown">
                                <li><a class="dropdown-item currency-dropdown-item<?php echo $posCurIqdActive ? ' active' : ''; ?>" href="#" data-currency="IQD" data-icon="bi-cash-coin">
                                    <i class="bi bi-cash-coin"></i> دینار
                                </a></li>
                                <li><a class="dropdown-item currency-dropdown-item<?php echo $posCurIqdActive ? '' : ' active'; ?>" href="#" data-currency="USD" data-icon="bi-currency-dollar">
                                    <i class="bi bi-currency-dollar"></i> دۆلار
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>

             

                <!-- Date and Products Buttons -->
                <div class="control-section">
                    <div class="pos-flex-row">
                        <!-- Products Toggle Button -->
                        <button class="btn btn-primary products-toggle-btn btn-sm pos-flex-auto" id="productsToggleBtn">
                            <i class="bi bi-box-seam"></i> کاڵاکان
                        </button>
                        <!-- Date Selection -->
                        <div class="pos-flex-auto pos-flex-col-tight">
                            <div class="pos-flex-col-tight">
                                <input type="date" class="form-control date-picker pos-date-input" id="saleDate" 
                                       value="<?php echo date('Y-m-d'); ?>">
                                <input type="time" class="form-control time-picker pos-date-input" id="saleTime" 
                                       value="<?php echo date('H:i'); ?>">
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm pos-btn-compact" id="dateToggleBtn">
                                <i class="bi bi-calendar"></i>
                            </button>
                        </div>
                    </div>
                </div>


                   <!-- Barcode Scanner -->
                   <div class="control-section">
                    <h6><i class="bi bi-upc-scan"></i> سکان کردنی بارکۆد</h6>
                    <div class="input-group pos-search-group">
                        <input type="text" class="form-control barcode-input numpad-target" id="barcodeInput" 
                               placeholder="بارکۆد یان گەڕان..." 
                               autocomplete="off">
                        <?php
                        $posBarcodeScanBtnEnabled = !empty($posBarcodeScanEnabled);
                        $scanBtnClass = $posBarcodeScanBtnEnabled ? 'btn-outline-primary' : 'btn-outline-secondary';
                        $scanBtnTitle = $posBarcodeScanBtnEnabled
                            ? 'سکانکردنی بارکۆد بە کامێرا'
                            : htmlspecialchars($posBarcodeScanLockTexts['title'] ?? 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نیە', ENT_QUOTES, 'UTF-8');
                        ?>
                        <button type="button"
                                class="btn <?php echo $scanBtnClass; ?>"
                                id="scanBarcodeBtn"
                                <?php if ($posBarcodeScanBtnEnabled): ?>
                                data-bs-toggle="modal" data-bs-target="#barcodeScannerModal"
                                <?php else: ?>
                                data-barcode-scan-locked="1"
                                <?php endif; ?>
                                title="<?php echo $scanBtnTitle; ?>">
                            <i class="bi bi-camera"></i>
                        </button>
                        <div id="searchResults" class="search-results"></div>
                    </div>
                    
                    <!-- NumPad Fixed at Bottom -->
                    <div id="numpadOverlay" class="pos-numpad-overlay">
                        <div class="numpad-grid">
                            <button class="numpad-btn btn-number" data-value="1">1</button>
                            <button class="numpad-btn btn-number" data-value="2">2</button>
                            <button class="numpad-btn btn-number" data-value="3">3</button>
                            <button class="numpad-btn btn-number" data-value="4">4</button>
                            <button class="numpad-btn btn-number" data-value="5">5</button>
                            <button class="numpad-btn btn-number" data-value="6">6</button>
                            <button class="numpad-btn btn-number" data-value="7">7</button>
                            <button class="numpad-btn btn-number" data-value="8">8</button>
                            <button class="numpad-btn btn-number" data-value="9">9</button>
                            <button class="numpad-btn btn-number fw-bold fs-4" data-value="." title="خاڵ / پۆینت">.</button>
                            <button class="numpad-btn btn-number" data-value="0">0</button>
                            <button class="numpad-btn btn-action" data-action="backspace"><i class="bi bi-backspace"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resize Handle -->
            <div class="resize-handle" id="resizeHandle"></div>

            <!-- Center Section - Products -->
            <div class="center-section pos-hidden" id="centerSection">
                <!-- Products Header -->
                <div class="products-header pos-products-header" id="productsHeader">
                    <h6><i class="bi bi-box-seam"></i> کاڵاکان</h6>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary" id="productCount">0</span>
                        <button class="btn btn-sm btn-outline-secondary" id="resizeProductsBtn">
                            <i class="bi bi-arrows-expand"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" id="closeProductsBtn">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>

                <!-- Products and Categories Container -->
                <div class="products-categories-container">
                    <!-- Products Side (Left) -->
                    <div class="products-side">
                        <div class="products-side-header">
                            <h6 id="currentCategoryName"><i class="bi bi-list-ul"></i> کلیک لە کەتەلۆگێک بکە</h6>
                            <div class="d-flex align-items-center gap-2">
                                <button class="btn btn-sm btn-outline-secondary" id="viewToggleBtn" title="گۆڕینی شێوازی نیشاندان">
                                    <i class="bi bi-grid-3x3-gap"></i>
                                </button>
                                <span class="badge bg-success" id="productsCountBadge">0</span>
                            </div>
                        </div>
                        <div class="products-list-container" id="productsList">
                            <div class="products-empty-state">
                                <i class="bi bi-hand-index"></i>
                                <p>کلیک لە کەتەلۆگێک بکە بۆ بینینی کاڵاکان</p>
                            </div>
                        </div>
                    </div>

                    <!-- Categories Side (Right) -->
                    <div class="categories-side">
                        <div class="categories-side-header">
                            <i class="bi bi-folder"></i> کەتەلۆگەکان
                        </div>
                        <div class="categories-list" id="categoriesList">
                            <button class="category-list-item" data-category="">
                                <i class="bi bi-grid"></i> هەموو
                            </button>
                            <?php
                            $posServicesBtnEnabled = !empty($posHasServicesAccess);
                            $servicesBtnTitle = $posServicesBtnEnabled
                                ? 'خزمەتگوزاری'
                                : htmlspecialchars($posServicesLockTexts['title'] ?? 'ئەم خزمەتگوزاریە بۆ ئەم پاکێجە بەردەست نیە', ENT_QUOTES, 'UTF-8');
                            ?>
                            <button type="button"
                                    class="category-list-item category-services-item<?php echo $posServicesBtnEnabled ? '' : ' category-services-item--locked'; ?>"
                                    id="servicesListBtn"
                                    data-category="__services__"
                                    <?php if (!$posServicesBtnEnabled): ?>
                                    data-services-locked="1"
                                    <?php endif; ?>
                                    title="<?php echo $servicesBtnTitle; ?>">
                                <i class="bi bi-briefcase"></i> خزمەتگوزاری
                                <?php if (!$posServicesBtnEnabled): ?>
                                <i class="bi bi-lock-fill ms-1 category-services-lock-icon"></i>
                                <?php endif; ?>
                            </button>
                            <?php foreach ($categories as $category): ?>
                                <button class="category-list-item" data-category="<?php echo $category['id']; ?>">
                                    <i class="bi bi-folder2"></i> <?php echo htmlspecialchars($category['name']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Resize Handle -->
            <div class="resize-handle" id="rightResizeHandle"></div>

            <!-- Right Section - Cart -->
            <div class="right-section" id="rightSection">
                <!-- Top Controls Section - Payment and Customer -->
                <div class="top-controls-section">
                    <!-- New Customer Button -->
                    <div class="customer-new-btn-wrapper" style="display: flex; justify-content: flex-start;">
                        <button class="btn btn-sm btn-outline-primary customer-new-btn" id="newCustomerBtn" title="کڕیاری نوێ">
                            <i class="bi bi-person-plus"></i>
                        </button>
                    </div>

                    <!-- Customer Container -->
                    <div class="customer-container" id="customerContainer">
                        <!-- Customer Search State (shown when no customer selected) -->
                        <div class="customer-search-state" id="customerSearchState">
                            <div class="customer-search-input-wrapper">
                                <i class="bi bi-search customer-search-icon"></i>
                                <input type="text" class="form-control customer-search-input" id="customerSearch" 
                                       placeholder="گەڕان بە ناوی کڕیار...">
                            </div>
                            <div id="customerSearchResults" class="search-results" style="display: none;"></div>
                        </div>

                        <!-- Selected Customer State (shown when customer selected) -->
                        <div class="customer-selected-state" id="customerSelectedState" style="display: none;">
                            <div class="customer-badge">
                                <div class="customer-badge-content">
                                    <div class="customer-badge-main">
                                        <i class="bi bi-person-fill customer-badge-icon"></i>
                                        <div class="customer-badge-info">
                                            <span class="customer-badge-name" id="customerNameDisplay"></span>
                                            <span class="customer-badge-phone" id="customerPhoneDisplay"></span>
                                        </div>
                                    </div>
                                    <div class="customer-badge-debt" id="customerDebtDisplay"></div>
                                </div>
                                <button class="btn btn-sm btn-link customer-clear-btn" id="clearCustomerBtn" title="پاککردنەوە">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>

                        <input type="hidden" id="selectedCustomerId">
                        <input type="hidden" id="customerName">
                        <input type="hidden" id="customerPhone">
                    </div>

                    <!-- Payment Method Dropdown -->
                    <div class="payment-dropdown-wrapper">
                        <select class="form-select" id="paymentMethod">
                            <option value="cash">کاش</option>
                            <option value="credit">قەرز</option>
                        </select>
                    </div>

                    <div class="payment-dropdown-wrapper" id="walletSelectWrapper">
                        <select class="form-select" id="walletId">
                            <option value="">قاسە هەڵبژێرە</option>
                            <?php foreach ($posWallets as $posWallet): ?>
                                <option value="<?php echo (int)$posWallet['id']; ?>" <?php echo ((int)$posDefaultWalletId === (int)$posWallet['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$posWallet['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sale-notes-wrapper" id="saleNotesWrapper">
                        <input type="text" class="form-control" id="saleNotes"
                               placeholder="تێبینی (ئارەزوومەندانە)" maxlength="500"
                               autocomplete="off">
                    </div>

                    <!-- Paid Amount Input (shown when credit) -->
                    <div class="paid-amount-wrapper" id="paidAmountWrapper" style="display: none;">
                        <input type="number" class="form-control" id="paidAmount"
                               placeholder="بڕی پارەی واسڵکراو" min="0" step="0.01" value=""
                               title="ئەم بڕە لە قەرزی کڕیار کەم دەکرێتەوە">
                    </div>

                    <!-- Amir Technology Brand -->
                    <div class="pos-brand" aria-label="<?php echo htmlspecialchars(SITE_NAME); ?>">
                        <img class="pos-brand-logo" src="<?php echo asset('images/logo.png'); ?>" alt="<?php echo htmlspecialchars(SITE_NAME); ?>">
                        <span class="pos-brand-text">
                            <span class="pos-brand-name">Nexora</span>
                            <span class="pos-brand-tech">Core</span>
                        </span>
                    </div>
                </div>

                <!-- Tabs Container -->
                <div class="tabs-container" id="tabsContainer">
                    <div class="tabs-header">
                        <div class="tabs-list" id="tabsList">
                            <!-- Tabs will be dynamically added here -->
                        </div>
                        <button class="btn btn-sm btn-outline-primary" id="addTabBtn" onclick="createNewSaleTab()" title="فرۆشتنی نوێ">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                </div>

                <!-- Cart Header -->
                <div class="cart-header">
                    <h6><i class="bi bi-cart"></i> سەبەتەی کڕین</h6>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="risk-alert-btn" id="riskAlertBtn" onclick="showRiskDetailsModal()">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <span id="riskAlertCount">0</span>
                        </button>
                        <span class="badge bg-success" id="cartCount">0</span>
                    </div>
                </div>

                <!-- Cart Table -->
                <div class="cart-table-container">
                    <table class="cart-table" id="cartTable">
                        <thead>
                            <tr>
                                <th style="width: 4%; text-align: center;">ژ</th>
                                <th style="width: 20%; text-align: right;">ناوی کاڵا</th>
                                <th style="width: 10%; text-align: center;">بارکۆد</th>
                                <th style="width: 8%; text-align: center;">یەکە</th>
                                <th style="width: 13%; text-align: center;">قیاس (مەتر)</th>
                                <th style="width: 11%; text-align: center;">نرخ</th>
                                <th style="width: 13%; text-align: center;">بڕ</th>
                                <th style="width: 16%; text-align: center;">کۆی نرخ</th>
                                <th style="width: 5%; text-align: center;">سڕینەوە</th>
                            </tr>
                        </thead>
                        <tbody id="cartTableBody">
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="bi bi-cart-x display-4 mb-3"></i>
                                    <p>سەبەتە بەتاڵە</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cart Summary -->
                <div class="cart-summary">
                    <!-- Total Display -->
                    <div class="total-display<?php if (!empty($posCanViewProfits)): ?> pos-total-clickable<?php endif; ?>" id="totalAmount"<?php if (!empty($posCanViewProfits)): ?> onclick="showReceiptProfitCard()" title="کلیک بکە بۆ بینینی قازانجی وەسڵ"<?php endif; ?>>0 دینار</div>

                    <!-- Discount -->
                    <div class="mb-3">
                        <div class="d-flex align-items-center mb-2 gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary pos-action-btn" id="addExternalProductBtn">
                                <i class="bi bi-plus-square"></i> کاڵای دەرەکی
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary pos-action-btn" id="delegateSalesBtn" title="فرۆشی مەندوب">
                                <i class="bi bi-shop-window"></i> فرۆشی مەندوب
                            </button>
                              <button type="button" class="btn btn-sm btn-outline-primary pos-action-btn" id="showChangeCardBtn">
                                <i class="bi bi-cash-coin"></i> باقی دانەوە
                            </button>
                            
                            <button type="button" class="btn btn-sm btn-outline-secondary pos-action-btn" id="toggleDiscountBtn">
                                <i class="bi bi-percent"></i> داشکاندن
                                <i class="bi bi-chevron-down ms-1" id="discountToggleIcon"></i>
                            </button>
                          
                        </div>
                        <div class="discount-container" id="discountContainer" style="display: none;">
                            <div class="input-group">
                                <input type="number" class="form-control discount-input" id="discountAmount" 
                                       placeholder="0" min="0" step="1">
                                <button class="btn btn-outline-secondary" type="button" id="clearDiscountBtn">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <div class="discount-options mt-2">
                                <button class="btn btn-sm btn-outline-primary discount-btn pos-discount-chip" data-amount="5">5%</button>
                                <button class="btn btn-sm btn-outline-primary discount-btn pos-discount-chip" data-amount="10">10%</button>
                                <button class="btn btn-sm btn-outline-primary discount-btn pos-discount-chip" data-amount="15">15%</button>
                                <button class="btn btn-sm btn-outline-primary discount-btn pos-discount-chip" data-amount="20">20%</button>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-grid gap-1">
                        <div class="row g-1">
                            <div class="col-6">
                                <button type="button" class="btn btn-success-custom btn-custom w-100" id="checkoutBtn" disabled>
                                    <i class="bi bi-check-circle"></i> فرۆشتن
                                </button>
                            </div>
                            <div class="col-3">
                                <button type="button" class="btn btn-warning-custom btn-custom w-100" id="returnBtn">
                                    <i class="bi bi-arrow-return-left"></i> گەڕاوە
                                </button>
                            </div>
                            <div class="col-3">
                                <button type="button" class="btn btn-danger-custom btn-custom w-100" id="clearCartBtn">
                                    <i class="bi bi-trash"></i> پاککردنەوە
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
