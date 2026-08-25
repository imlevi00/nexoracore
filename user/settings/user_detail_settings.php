<?php
/**
 * ڕێکخستنی وردەکارییەکان بەپێی هەژمار - user/settings/user_detail_settings.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../config/kasher_zanyari/database.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);

$success = '';
$error = '';

$dbZanyari = $conn_zanyari instanceof mysqli ? $conn_zanyari : null;
$zanyariOk = $dbZanyari !== null;

/** @var int $posShowZeroStock 1 = نیشان بدرێت، 0 = نیشان نەدرێت */
$posShowZeroStock = 1;
/** @var int $purchasesWeightedAvg 1 = نرخی تێکڕا بۆ کۆگا، 0 = نرخی جێگیر */
$purchasesWeightedAvg = 1;
/** @var int $recognizeDebtRevenueAtSale 1 = داهاتی قەرز لە ڕۆژی فرۆشتن، 0 = دوای پارەدانەوە */
$recognizeDebtRevenueAtSale = 1;
/** @var int $receiptA4ItemsFontSize قەبارەی فۆنتی خشتەی کاڵا لە وەسڵی A4 (پیکسڵ، ١٠–٢٢) */
$receiptA4ItemsFontSize = 16;
/** @var string $posDefaultSaleCurrency IQD یان USD */ 
$posDefaultSaleCurrency = 'IQD';
/** @var string $posDefaultPriceType retail|wholesale|special */
$posDefaultPriceType = 'retail';
/** @var int $posDefaultPaymentIsCredit 1 = دیفۆڵت قەرز، 0 = کاش */
$posDefaultPaymentIsCredit = 0;
/** @var string $themeMode light|dark|system */
$themeMode = 'system';

if ($dbZanyari !== null && $userId > 0) {
    $sel = $dbZanyari->prepare(
        'SELECT pos_show_zero_stock_products, purchases_use_weighted_avg_prices, recognize_customer_debt_revenue_at_sale, receipt_a4_items_font_size, pos_default_sale_currency, pos_default_price_type, pos_default_payment_is_credit, theme_mode FROM user_account_settings WHERE user_id = ? LIMIT 1'
    );
    if ($sel) {
        $sel->bind_param('i', $userId);
        if ($sel->execute()) {
            $row = $sel->get_result()->fetch_assoc();
        } else {
            $row = null;
        }
        $sel->close();
        if ($row) {
            if (array_key_exists('pos_show_zero_stock_products', $row)) {
                $posShowZeroStock = (int)$row['pos_show_zero_stock_products'] ? 1 : 0;
            }
            if (array_key_exists('purchases_use_weighted_avg_prices', $row)) {
                $purchasesWeightedAvg = (int)$row['purchases_use_weighted_avg_prices'] ? 1 : 0;
            }
            if (array_key_exists('recognize_customer_debt_revenue_at_sale', $row)) {
                $recognizeDebtRevenueAtSale = (int)$row['recognize_customer_debt_revenue_at_sale'] ? 1 : 0;
            }
            if (array_key_exists('receipt_a4_items_font_size', $row) && $row['receipt_a4_items_font_size'] !== null) {
                $receiptA4ItemsFontSize = max(10, min(22, (int)$row['receipt_a4_items_font_size']));
            }
            if (array_key_exists('pos_default_sale_currency', $row) && $row['pos_default_sale_currency'] !== null) {
                $cur = strtoupper((string)$row['pos_default_sale_currency']);
                $posDefaultSaleCurrency = ($cur === 'USD') ? 'USD' : 'IQD';
            }
            if (array_key_exists('pos_default_price_type', $row) && $row['pos_default_price_type'] !== null) {
                $priceType = strtolower((string)$row['pos_default_price_type']);
                $posDefaultPriceType = in_array($priceType, ['retail', 'wholesale', 'special'], true) ? $priceType : 'retail';
            }
            if (array_key_exists('pos_default_payment_is_credit', $row)) {
                $posDefaultPaymentIsCredit = (int)$row['pos_default_payment_is_credit'] ? 1 : 0;
            }
            if (array_key_exists('theme_mode', $row) && $row['theme_mode'] !== null) {
                $postedThemeMode = strtolower((string)$row['theme_mode']);
                $themeMode = in_array($postedThemeMode, ['light', 'dark', 'system'], true) ? $postedThemeMode : 'system';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } elseif ($userId <= 0) {
        $error = 'ناتوانرێت ناسنامەی بەکارهێنەر دۆزرێتەوە';
    } elseif ($dbZanyari === null) {
        $error = 'پەیوەندی بە داتابەیس نەبڕاوە. دواتر هەوڵ بدەرەوە';
    } else {
        $posShowZeroStock = isset($_POST['pos_show_zero_stock_products']) ? 1 : 0;
        $purchasesWeightedAvg = isset($_POST['purchases_use_weighted_avg_prices']) ? 1 : 0;
        $recognizeDebtRevenueAtSale = isset($_POST['recognize_customer_debt_revenue_at_sale']) ? 1 : 0;
        $receiptA4ItemsFontSize = max(10, min(22, (int)($_POST['receipt_a4_items_font_size'] ?? 16)));

        $postedCur = strtoupper((string)($_POST['pos_default_sale_currency'] ?? 'IQD'));
        $posDefaultSaleCurrency = ($postedCur === 'USD') ? 'USD' : 'IQD';
        $postedPriceType = strtolower((string)($_POST['pos_default_price_type'] ?? 'retail'));
        $posDefaultPriceType = in_array($postedPriceType, ['retail', 'wholesale', 'special'], true) ? $postedPriceType : 'retail';
        $posDefaultPaymentIsCredit = isset($_POST['pos_default_payment_is_credit']) ? 1 : 0;
        $postedThemeMode = strtolower((string)($_POST['theme_mode'] ?? $themeMode));
        $themeMode = in_array($postedThemeMode, ['light', 'dark', 'system'], true) ? $postedThemeMode : 'system';

        $ins = $dbZanyari->prepare(
            'INSERT INTO user_account_settings (user_id, pos_show_zero_stock_products, purchases_use_weighted_avg_prices, recognize_customer_debt_revenue_at_sale, receipt_a4_items_font_size, pos_default_sale_currency, pos_default_price_type, pos_default_payment_is_credit, theme_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               pos_show_zero_stock_products = VALUES(pos_show_zero_stock_products),
               purchases_use_weighted_avg_prices = VALUES(purchases_use_weighted_avg_prices),
               recognize_customer_debt_revenue_at_sale = VALUES(recognize_customer_debt_revenue_at_sale),
               receipt_a4_items_font_size = VALUES(receipt_a4_items_font_size),
               pos_default_sale_currency = VALUES(pos_default_sale_currency),
               pos_default_price_type = VALUES(pos_default_price_type),
               pos_default_payment_is_credit = VALUES(pos_default_payment_is_credit),
               theme_mode = VALUES(theme_mode)'
        );
        if ($ins) {
            $ins->bind_param('iiiiissis', $userId, $posShowZeroStock, $purchasesWeightedAvg, $recognizeDebtRevenueAtSale, $receiptA4ItemsFontSize, $posDefaultSaleCurrency, $posDefaultPriceType, $posDefaultPaymentIsCredit, $themeMode);
            if ($ins->execute()) {
                $success = 'ڕێکخستنەکان بە سەرکەوتوویی پاشەکەوتکران';
                $_SESSION['user_theme_mode'] = $themeMode;
                $_SESSION['user_data']['theme_mode'] = $themeMode;
                $email = $currentUser['email'] ?? '';
                writeLog("user_account_settings: user_id={$userId} pos={$posShowZeroStock} purchase_avg={$purchasesWeightedAvg} debt_rev_at_sale={$recognizeDebtRevenueAtSale} receipt_a4_fs={$receiptA4ItemsFontSize} pos_cur={$posDefaultSaleCurrency} pos_price_type={$posDefaultPriceType} pos_pay_credit={$posDefaultPaymentIsCredit} theme_mode={$themeMode} by {$email}");
            } else {
                $error = 'هەڵەیەک ڕوویدا لە پاشەکەوتکردن';
            }
            $ins->close();
        } else {
            $error = 'هەڵەیەک ڕوویدا لە پاشەکەوتکردن';
        }
    }
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>وردەکارییەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('user/settings/settings.css'); ?>" rel="stylesheet">
</head>
<body class="settings-module-page settings-page">

    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container py-4">
        
        <!-- Header -->
        <div class="settings-header-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <nav class="small text-muted mb-2" aria-label="breadcrumb">
                        <a href="<?php echo url('user/dashboard/index.php'); ?>" class="text-decoration-none text-muted">
                            <i class="bi bi-speedometer2"></i> داشبۆرد
                        </a>
                        <span class="mx-2">/</span>
                        <a href="<?php echo url('user/settings/main.php'); ?>" class="text-decoration-none text-muted">
                            ڕێکخستنەکان
                        </a>
                        <span class="mx-2">/</span>
                        <span class="text-primary fw-medium">وردەکارییەکانی هەژمار</span>
                    </nav>
                    <h2 class="mb-1 d-flex align-items-center gap-2">
                        <i class="bi bi-sliders2-vertical text-primary"></i>
                        وردەکارییەکانی هەژمار
                    </h2>
                    <p class="text-muted mb-0">ڕێکخستنەکانی تایبەت بە فرۆشتن (POS)، وەسڵی A4، کۆگا و قازانج</p>
                </div>
                <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right"></i> گەڕانەوە
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-1"></i> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="داخستن"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="داخستن"></button>
            </div>
        <?php endif; ?>

        <?php if (!$zanyariOk): ?>
            <div class="alert alert-warning">
                <i class="bi bi-database-x me-1"></i> داتابەیسی زانیارییەکان بەردەست نییە؛ ڕێکخستنەکان پاشەکەوت ناکرێن تا پەیوەندی چاک بکرێتەوە.
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="row g-4">
                
                <!-- فرۆشتنی کاڵاکان (POS) -->
                <div class="col-12 col-lg-6">
                    <div class="settings-card card p-4 h-100">
                        <div class="settings-card-header-accent">
                            <div class="settings-icon section-business">
                                <i class="bi bi-shop"></i>
                            </div>
                            <div>
                                <h4 class="mb-1">فرۆشتنی کاڵاکان (POS)</h4>
                                <p class="text-muted small mb-0">ڕێکخستنی شێوازی کارکردنی سیستەمی فرۆشتن</p>
                            </div>
                        </div>

                        <!-- Show zero stock products switch -->
                        <div class="selectable-card-option mb-3">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="pos_show_zero_stock_products"
                                   name="pos_show_zero_stock_products"
                                   value="1"
                                   <?php echo $posShowZeroStock ? 'checked' : ''; ?>
                                   <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                            <label class="form-check-label mb-0 flex-grow-1 cursor-pointer" for="pos_show_zero_stock_products">
                                <strong class="d-block text-primary">نیشاندانی کاڵای تەواوبوو</strong>
                                <span class="d-block small text-muted">
                                    کاڵاکانی ڕێژە سفر لە لیستی فرۆشتندا دەربکەون
                                </span>
                            </label>
                        </div>

                        <!-- Default sale currency -->
                        <div class="p-3 border rounded-3 mb-3" style="background: var(--surface-2);">
                            <label class="form-label fw-semibold mb-2 d-block">
                                <i class="bi bi-currency-exchange"></i> دیفۆڵتی دراو لە فرۆشتن
                            </label>
                            <p class="small text-muted mb-2">کاتێک POS دەکەیتەوە، سەرەتا دینار یان دۆلار هەڵبژێرێت.</p>
                            <div class="d-flex gap-2">
                                <label class="selectable-card-option flex-fill mb-0">
                                    <input type="radio" name="pos_default_sale_currency" id="pos_cur_iqd" value="IQD"
                                           <?php echo $posDefaultSaleCurrency === 'IQD' ? 'checked' : ''; ?>
                                           <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                                    <span class="fw-medium"><i class="bi bi-cash-coin text-success"></i> دینار (IQD)</span>
                                </label>
                                <label class="selectable-card-option flex-fill mb-0">
                                    <input type="radio" name="pos_default_sale_currency" id="pos_cur_usd" value="USD"
                                           <?php echo $posDefaultSaleCurrency === 'USD' ? 'checked' : ''; ?>
                                           <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                                    <span class="fw-medium"><i class="bi bi-currency-dollar text-primary"></i> دۆلار (USD)</span>
                                </label>
                            </div>
                        </div>

                        <!-- Default price type -->
                        <div class="p-3 border rounded-3 mb-3" style="background: var(--surface-2);">
                            <label class="form-label fw-semibold mb-2 d-block">
                                <i class="bi bi-tags"></i> دیفۆڵتی بنەڕەتی نرخ لە فرۆشتن
                            </label>
                            <p class="small text-muted mb-2">کاتێک POS دەکەیتەوە، ئەم جۆرە نرخە سەرەتا هەڵبژێردرێت.</p>
                            <div class="d-flex flex-wrap gap-2">
                                <label class="selectable-card-option flex-fill mb-0">
                                    <input type="radio" name="pos_default_price_type" id="pos_price_type_retail" value="retail"
                                           <?php echo $posDefaultPriceType === 'retail' ? 'checked' : ''; ?>
                                           <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                                    <span class="fw-medium"><i class="bi bi-1-circle text-primary"></i> تاک</span>
                                </label>
                                <label class="selectable-card-option flex-fill mb-0">
                                    <input type="radio" name="pos_default_price_type" id="pos_price_type_wholesale" value="wholesale"
                                           <?php echo $posDefaultPriceType === 'wholesale' ? 'checked' : ''; ?>
                                           <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                                    <span class="fw-medium"><i class="bi bi-2-circle text-success"></i> جوملە</span>
                                </label>
                                <label class="selectable-card-option flex-fill mb-0">
                                    <input type="radio" name="pos_default_price_type" id="pos_price_type_special" value="special"
                                           <?php echo $posDefaultPriceType === 'special' ? 'checked' : ''; ?>
                                           <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                                    <span class="fw-medium"><i class="bi bi-star text-warning"></i> تایبەت</span>
                                </label>
                            </div>
                        </div>

                        <!-- Default payment is credit -->
                        <div class="selectable-card-option">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="pos_default_payment_is_credit"
                                   name="pos_default_payment_is_credit"
                                   value="1"
                                   <?php echo $posDefaultPaymentIsCredit ? 'checked' : ''; ?>
                                   <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                            <label class="form-check-label mb-0 flex-grow-1 cursor-pointer" for="pos_default_payment_is_credit">
                                <strong class="d-block text-warning">دیفۆڵتی پارەدان: قەرز</strong>
                                <span class="d-block small text-muted">
                                    تاب و فرۆشتنی نوێ بە قەرز دەست پێدەکات (پێویستە کڕیار هەڵبژێریت)
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- بەشەکانی تر (وەسڵی A4، کڕین، قازانج) -->
                <div class="col-12 col-lg-6">
                    <div class="d-flex flex-column gap-4">
                        
                        <!-- وەسڵی A4 (قەبارەی فۆنتی خشتەی کاڵا) -->
                        <div class="settings-card card p-4">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-info">
                                    <i class="bi bi-file-earmark-text"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1">وەسڵی A4</h4>
                                    <p class="text-muted small mb-0">قەبارەی فۆنتی ناو خشتەی کاڵاکان لە چاپی A4</p>
                                </div>
                            </div>

                            <p class="text-muted small mb-3">
                                فۆنتی بچووکتر = کاڵای زیاتر لە هەر لاپەڕەیەکدا دەگونجێت.
                            </p>

                            <div class="p-3 border rounded-3" style="background: var(--surface-2);">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
                                    <label class="mb-0 fw-semibold" for="receipt_a4_items_font_size">قەبارەی فۆنت (پیکسڵ):</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-outline-secondary btn-sm receipt-a4-fs-dec" aria-label="کەمکردنەوە" <?php echo !$zanyariOk ? 'disabled' : ''; ?>>−</button>
                                        <span class="fs-5 fw-bold text-primary px-2" id="receipt_a4_fs_display" style="min-width:2.5rem;text-align:center;"><?php echo (int) $receiptA4ItemsFontSize; ?></span>
                                        <button type="button" class="btn btn-outline-secondary btn-sm receipt-a4-fs-inc" aria-label="زیادکردن" <?php echo !$zanyariOk ? 'disabled' : ''; ?>>+</button>
                                    </div>
                                </div>
                                <input type="range" class="form-range mb-0" name="receipt_a4_items_font_size" id="receipt_a4_items_font_size"
                                       min="10" max="22" step="1" value="<?php echo (int) $receiptA4ItemsFontSize; ?>"
                                       <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                            </div>
                        </div>

                        <!-- وەسڵی کۆمپانیاکان (کڕین) -->
                        <div class="settings-card card p-4">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-dollar-price">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1">وەسڵی کۆمپانیاکان (کڕین)</h4>
                                    <p class="text-muted small mb-0">شێوازی نوێکردنەوەی نرخەکانی کۆگا لە کاتی کڕین</p>
                                </div>
                            </div>

                            <div class="selectable-card-option">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="purchases_use_weighted_avg_prices"
                                       name="purchases_use_weighted_avg_prices"
                                       value="1"
                                       <?php echo $purchasesWeightedAvg ? 'checked' : ''; ?>
                                       <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                                <label class="form-check-label mb-0 flex-grow-1 cursor-pointer" for="purchases_use_weighted_avg_prices">
                                    <strong class="d-block text-primary">نرخی تێکڕا بۆ کۆگا (Weighted Average)</strong>
                                    <span class="d-block small text-muted">
                                        چالاک: نرخەکان لەگەڵ کۆگای پێشوو تێکڕا دەبن. ناچالاک: نرخی وەسڵ دادەنرێت.
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- قازانج و داهاتی قەرزی کڕیاران -->
                        <div class="settings-card card p-4">
                            <div class="settings-card-header-accent">
                                <div class="settings-icon section-business">
                                    <i class="bi bi-cash-stack"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1">قازانج و داهاتی قەرز</h4>
                                    <p class="text-muted small mb-0">شێوازی هەژمارکردنی داهاتی فرۆشتنی قەرز</p>
                                </div>
                            </div>

                            <div class="selectable-card-option">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="recognize_customer_debt_revenue_at_sale"
                                       name="recognize_customer_debt_revenue_at_sale"
                                       value="1"
                                       <?php echo $recognizeDebtRevenueAtSale ? 'checked' : ''; ?>
                                       <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                                <label class="form-check-label mb-0 flex-grow-1 cursor-pointer" for="recognize_customer_debt_revenue_at_sale">
                                    <strong class="d-block text-success">داهاتی قەرز لە ڕۆژی فرۆشتن</strong>
                                    <span class="d-block small text-muted">
                                        چالاک: لە هەمان ڕۆژی فرۆشتن دەرکەوێت. ناچالاک: تەنها کاتێک پارە وەرگیرا لە قازانجدا هەژمار بکرێت.
                                    </span>
                                </label>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Submit Bar -->
                <div class="col-12">
                    <div class="settings-card card p-3 d-flex flex-row justify-content-between align-items-center flex-wrap gap-2">
                        <a href="<?php echo url('user/settings/main.php'); ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right"></i> گەڕانەوە
                        </a>
                        <button type="submit" class="btn btn-save" <?php echo !$zanyariOk ? 'disabled' : ''; ?>>
                            <i class="bi bi-check-lg"></i> پاشەکەوتکردنی ڕێکخستنەکان
                        </button>
                    </div>
                </div>

            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        var input = document.getElementById('receipt_a4_items_font_size');
        var display = document.getElementById('receipt_a4_fs_display');
        if (!input || !display) return;

        function clampFs(v) {
            var n = parseInt(v, 10);
            if (isNaN(n)) n = 16;
            return Math.max(10, Math.min(22, n));
        }

        function sync() {
            var px = clampFs(input.value);
            input.value = String(px);
            display.textContent = String(px);
        }

        input.addEventListener('input', sync);
        input.addEventListener('change', sync);

        document.querySelectorAll('.receipt-a4-fs-dec').forEach(function (btn) {
            btn.addEventListener('click', function () {
                input.value = String(clampFs((parseInt(input.value, 10) || 16) - 1));
                sync();
            });
        });
        document.querySelectorAll('.receipt-a4-fs-inc').forEach(function (btn) {
            btn.addEventListener('click', function () {
                input.value = String(clampFs((parseInt(input.value, 10) || 16) + 1));
                sync();
            });
        });

        sync();
    </script>
</body>
</html>
