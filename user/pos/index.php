<?php
require_once __DIR__ . '/includes/pos_bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title>فرۆشتن - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/pos-design.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/delegate-sales-picker.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/pos-page-overrides.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/pos-fancy.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/pos/css/pos-responsive.css'); ?>" rel="stylesheet">

    <?php if (!empty($posBarcodeScanEnabled)): ?>
    <!-- QuaggaJS for Barcode Scanning -->
    <script src="https://cdn.jsdelivr.net/npm/quagga@0.12.1/dist/quagga.min.js"></script>
    <?php endif; ?>
</head>
<?php require __DIR__ . '/partials/pos_layout.php'; ?>
<?php require __DIR__ . '/partials/pos_modals.php'; ?>
<?php require __DIR__ . '/partials/pos_popups.php'; ?>
<?php if (!empty($posIsMedicalCenterMode)): ?>
<?php require __DIR__ . '/partials/pos_prescriptions.php'; ?>
<?php endif; ?>

    <?php require __DIR__ . '/partials/delegate_sales_picker.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ===== GLOBAL VARIABLES =====
        const POS = {
            cart: [],
            products: [],
            services: [],
            currentCategory: '',
            currentSource: 'products',
            searchTimeout: null,
            userId: <?php echo $userId; ?>,
            /** true = کاڵای ڕێژە سفر لە dropdown ـی «بارکۆد یان گەڕان» دەردەکەوێت */
            posShowZeroStockProducts: <?php echo !empty($posShowZeroStockProducts) ? 'true' : 'false'; ?>,
            csrfToken: '<?php echo $csrf_token; ?>',
            currentPriceType: <?php echo json_encode($posDefaultPriceType, JSON_UNESCAPED_UNICODE); ?>,
            defaultPriceType: <?php echo json_encode($posDefaultPriceType, JSON_UNESCAPED_UNICODE); ?>,
            currentCurrency: <?php echo json_encode($posEffectiveSaleCurrency, JSON_UNESCAPED_UNICODE); ?>,
            defaultSaleCurrency: <?php echo json_encode($posDefaultSaleCurrency, JSON_UNESCAPED_UNICODE); ?>,
            defaultPaymentMethod: <?php echo json_encode($posDefaultPaymentMethod, JSON_UNESCAPED_UNICODE); ?>,
            defaultWalletId: <?php echo (int)$posDefaultWalletId; ?>,
            posSaleCurrencyFallbackFromUsd: <?php echo !empty($posSaleCurrencyFallbackFromUsd) ? 'true' : 'false'; ?>,
            dollarPrice: <?php echo $dollarPrice ? $dollarPrice : 'null'; ?>,
            manualChangeAdjustment: <?php echo isset($manualChangeAdjustment) ? (float)$manualChangeAdjustment : 0.0; ?>,
            selectedCustomer: null,
            resizeHandle: null,
            isResizing: false,
            tabs: [],
            activeTabId: null,
            tabCounter: 0,
            viewMode: 'list',
            productsPagination: { offset: 0, limit: 75, total: 0, hasMore: false, loading: false },
            servicesPagination: { offset: 0, limit: 150, total: 0, hasMore: false, loading: false },
            lastSaleData: null,
            isSubmittingSale: false,
            drugInteractionWarningEnabled: <?php echo $posDrugInteractionWarningEnabled ? 'true' : 'false'; ?>,
            shownInteractionWarnings: new Set(),
            knownConflictKeys: new Set(),
            currentInteractionConflicts: [],
            riskScanTimer: null,
            saleDateTimeManuallyEdited: false,
            barcodeScanEnabled: <?php echo !empty($posBarcodeScanEnabled) ? 'true' : 'false'; ?>,
            barcodeInputTiming: { firstKey: 0, lastKey: 0, charCount: 0 },
            packageLock: {
                title: <?php echo json_encode($posBarcodeScanLockTexts['title'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
                description: <?php echo json_encode($posBarcodeScanLockTexts['description'] ?? '', JSON_UNESCAPED_UNICODE); ?>
            },
            hasServicesAccess: <?php echo !empty($posHasServicesAccess) ? 'true' : 'false'; ?>,
            canViewProfits: <?php echo !empty($posCanViewProfits) ? 'true' : 'false'; ?>,
            canEditPrice: <?php echo !empty($posCanEditPrice) ? 'true' : 'false'; ?>,
            canEditTotal: <?php echo !empty($posCanEditTotal) ? 'true' : 'false'; ?>,
            servicesLock: {
                title: <?php echo json_encode($posServicesLockTexts['title'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
                description: <?php echo json_encode($posServicesLockTexts['description'] ?? '', JSON_UNESCAPED_UNICODE); ?>
            },
            scaleSettings: <?php echo json_encode($posScaleSettings, JSON_UNESCAPED_UNICODE); ?>,
            receipt: {
                businessName: <?php echo json_encode($currentUser['business_name'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
                headerHtml: <?php echo json_encode(!empty($settings['receipt_header']) ? nl2br(htmlspecialchars($settings['receipt_header'], ENT_QUOTES, 'UTF-8')) : '', JSON_UNESCAPED_UNICODE); ?>,
                footerHtml: <?php echo json_encode(htmlspecialchars($settings['receipt_footer'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                headerText: <?php echo json_encode($settings['receipt_header'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
                logoUrl: <?php echo json_encode(!empty($settings['business_logo']) ? url('uploads/' . $settings['business_logo']) : null, JSON_UNESCAPED_UNICODE); ?>
            },
            urls: {
                customerAdd: <?php echo json_encode(url('user/customers/index.php?action=add'), JSON_UNESCAPED_UNICODE); ?>,
                receipt: <?php echo json_encode(url('user/pos/receipt.php'), JSON_UNESCAPED_UNICODE); ?>,
                receiptA4: <?php echo json_encode(url('user/pos/receipt_a4.php'), JSON_UNESCAPED_UNICODE); ?>
            }
        };
        let lastSaleId = null;
    </script>
    <script src="<?php echo asset_url('user/pos/js/pos-utils.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-search.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-cart.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-change.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-checkout.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-return.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-handlers.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-tabs.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-features.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-core.js'); ?>"></script>
    <script src="<?php echo asset_url('user/pos/js/pos-exchange-rate.js'); ?>"></script>
    <?php if (!empty($posIsMedicalCenterMode)): ?>
    <script src="<?php echo asset_url('user/pos/js/pos-prescriptions.js'); ?>"></script>
    <?php endif; ?>
    <?php if (!empty($posBarcodeScanEnabled)): ?>
    <?php require __DIR__ . '/partials/barcode_scanner_modal.php'; ?>
    <script src="<?php echo asset_url('user/pos/js/pos-barcode-scanner.js'); ?>"></script>
    <?php endif; ?>
    <script src="<?php echo asset_url('user/pos/js/delegate-sales-picker.js'); ?>"></script>
    <script>
        DelegateSalesPicker.init({
            categories: <?php echo json_encode($categories, JSON_UNESCAPED_UNICODE); ?>,
            showZeroStock: <?php echo !empty($posShowZeroStockProducts) ? 'true' : 'false'; ?>,
            hasServicesAccess: <?php echo !empty($posHasServicesAccess) ? 'true' : 'false'; ?>,
            servicesLock: {
                title: <?php echo json_encode($posServicesLockTexts['title'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
                description: <?php echo json_encode($posServicesLockTexts['description'] ?? '', JSON_UNESCAPED_UNICODE); ?>
            },
            getPosState: function () {
                return {
                    userId: POS.userId,
                    priceType: POS.currentPriceType,
                    currency: POS.currentCurrency
                };
            },
            formatMoney: formatMoney,
            getConvertedPrice: getConvertedPrice,
            roundPriceByCurrency: roundPriceByCurrency,
            addProductToCart: addProductToCart,
            addServiceToCart: addServiceToCart,
            showNotification: showNotification,
            isProductExpired: isProductExpired
        });
    </script>
</body>
</html>
