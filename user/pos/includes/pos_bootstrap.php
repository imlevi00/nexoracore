<?php
/**
 * POS page bootstrap — auth, settings, exchange rate, CSRF.
 * user/pos/includes/pos_bootstrap.php
 */

require_once '../../config/config.php';
require_once '../../config/kasher_zanyari/database.php';
require_once '../../config/kasher_platform/database.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/wallet_service.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'pos.view', [
    'route' => '/user/pos/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];
$posWallets = walletGetUserWallets($conn, (int)$userId, true);
$posDefaultWalletId = 0;
foreach ($posWallets as $posWalletRow) {
    if ((int)($posWalletRow['is_default'] ?? 0) === 1) {
        $posDefaultWalletId = (int)$posWalletRow['id'];
        break;
    }
}
if ($posDefaultWalletId <= 0 && !empty($posWallets)) {
    $posDefaultWalletId = (int)$posWallets[0]['id'];
}

// ڕێکخستنەکانی kasher_z.user_account_settings (نیشاندانی ڕێژە سفر + دیفۆڵتی POS) — خوێندنەوە دوای نرخی دۆلار بۆ fallbackی USD
$posShowZeroStockProducts = 1;
$posDefaultSaleCurrency = 'IQD';
$posDefaultPriceType = 'retail';
$posDefaultPaymentIsCredit = 0;
$dbZanyariPos = isset($conn_zanyari) && $conn_zanyari instanceof mysqli ? $conn_zanyari : null;

// وەرگرتنی کەتەلۆگەکان
$categories = [];
$categoriesResult = $conn->query("SELECT id, name FROM categories WHERE user_id = $userId ORDER BY name");
if ($categoriesResult) {
    $categories = $categoriesResult->fetch_all(MYSQLI_ASSOC);
}

// ڕێکخستنەکانی بەکارهێنەر
$settings = [
    'business_logo' => null,
    'receipt_header' => $currentUser['business_name'],
    'receipt_footer' => "سوپاس بۆ هاتنتان\n\nسیستەمی NexoraCore NexoraCore.com",
    'receipt_size' => 'thermal',
    'currency' => 'دینار'
];

$settingsResult = $conn->query("SELECT * FROM settings WHERE user_id = $userId LIMIT 1");
if ($settingsResult && $settingsResult->num_rows > 0) {
    $userSettings = $settingsResult->fetch_assoc();
    $settings = array_merge($settings, $userSettings);
}
$posIsPharmacyMode = in_array((int)($settings['business_type_id'] ?? 0), [1, 3], true);
$posDrugInteractionWarningEnabled = $posIsPharmacyMode && ($conn_kasher_platform instanceof mysqli);

// دۆخی سەنتەری پزیشکی — بۆ پیشاندانی ڕەچەتەکانی دکتۆر لە بەشی فرۆشتن
$posIsMedicalCenterMode = false;
$posMedBusinessTypeId = (int)($settings['business_type_id'] ?? 0);
if ($posMedBusinessTypeId === 3) {
    $posIsMedicalCenterMode = true;
} elseif ($posMedBusinessTypeId > 0) {
    $posBtStmt = $conn->prepare("SELECT code FROM business_types WHERE id = ? LIMIT 1");
    if ($posBtStmt) {
        $posBtStmt->bind_param('i', $posMedBusinessTypeId);
        $posBtStmt->execute();
        $posBtRow = $posBtStmt->get_result()->fetch_assoc();
        $posBtStmt->close();
        if ($posBtRow && trim((string)$posBtRow['code']) === 'pharmacy_and_medical_center') {
            $posIsMedicalCenterMode = true;
        }
    }
}
// تەنها کاتێک چالاکە کە داتابەیسی kasher_platform بەردەستە
$posIsMedicalCenterMode = $posIsMedicalCenterMode && ($conn_kasher_platform instanceof mysqli);

// وەرگرتنی دوایین نرخی دۆلار لە currency_exchange_rates
$dollarPrice = null;
$dollarLastUpdatedDisplay = null;
// گۆڕاوەکان بۆ زیادکراوی دەستی
$manualAdjustment = 0.0;          // بۆ نرخی دۆلار / API
$manualChangeAdjustment = 0.0;    // بۆ بەشی باقی دانەوە "بڕی پارە:"
try {
    // سەرەتا لە currency_exchange_rates وەربگرە
    $exchangeResult = $conn->query("SELECT exchange_rate FROM currency_exchange_rates WHERE user_id = $userId AND from_currency = 'USD' AND to_currency = 'IQD' LIMIT 1");
    if ($exchangeResult && $exchangeRow = $exchangeResult->fetch_assoc()) {
        $exchangeRate = floatval($exchangeRow['exchange_rate']);
        // پشکنینی دوبارە بۆ currency_exchange_rates
        if ($exchangeRate > 100 && $exchangeRate < 10000) {
            $dollarPrice = $exchangeRate;
        }
    }
    
    // ئەگەر لە currency_exchange_rates نەدۆزرایەوە، لە dollar_prices وەربگرە
    if (!$dollarPrice) {
        $dollarResult = $conn->query("SELECT offer_price FROM dollar_prices ORDER BY last_updated DESC LIMIT 1");
        if ($dollarResult && $row = $dollarResult->fetch_assoc()) {
            $rawPrice = floatval($row['offer_price']);

            // ئەگەر offer_price بە شێوازی 156100 هاتبێت، دوو ژمارەی کۆتایی بسڕەوە → 1561
            if ($rawPrice >= 10000) {
                $rawPrice = floor($rawPrice / 100);
            }

            // پشکنینی هەڵە: ئەگەر نرخەکە زۆر گەورە بێت یان زۆر بچووک بێت
            if ($rawPrice > 100 && $rawPrice < 10000) {
                $dollarPrice = $rawPrice;
            }
        }
    }
    
    // ئەگەر هیچی نەدۆزرایەوە، بەکارهێنانی هەمان ڕێژەی ئاڵوگۆڕکردن
    // کە لە ajax/get_exchange_rate.php بەکاردێت
    if (!$dollarPrice) {
        try {
            $fallbackExchangeRate = null;

            // هەوڵبدە دوایین offer_price وەربگری لە dollar_prices
            $fallbackResult = $conn->query("SELECT offer_price FROM dollar_prices ORDER BY last_updated DESC LIMIT 1");
            if ($fallbackResult && $row = $fallbackResult->fetch_assoc()) {
                $offerPrice = floatval($row['offer_price']);

                // ئەگەر offer_price بە شێوازی 156100 هاتبێت، دوو ژمارەی کۆتایی بسڕەوە → 1561
                if ($offerPrice >= 10000) {
                    $offerPrice = floor($offerPrice / 100);
                }

                // زیادکراوی دەستی بەکارهێنەر، ئەرێنی function ـەکە بکرێت
                $manualAdjustment = function_exists('getManualAdjustment') ? getManualAdjustment($userId) : 0.0;

                // هەمان حیسابکردن وەکو ajax/get_exchange_rate.php، بە شێوازی نوێ
                $fallbackExchangeRate = $offerPrice + $manualAdjustment;
            } else {
                // ئەگەر offer_price نەبوو، وەرگرتن لە currency_exchange_rates
                if (function_exists('getExchangeRate')) {
                    $fallbackExchangeRate = getExchangeRate($userId, 'USD', 'IQD');
                }

                // ئەگەر هێشتا نرخ نییە یان نادروستە، نرخی default
                if ($fallbackExchangeRate === false || $fallbackExchangeRate <= 0) {
                    $fallbackExchangeRate = 1400.0;
                }

                // لەم حاڵەتەداش، زیادکراوی دەستی لە function ـەکەوە وەرگرە
                if (function_exists('getManualAdjustment')) {
                    $manualAdjustment = getManualAdjustment($userId);
                }
            }

            // نرخی دۆلار تەنیا کاتێک دابنێ کە لە مەودای معقول دا بێت
            if ($fallbackExchangeRate > 100 && $fallbackExchangeRate < 10000) {
                $dollarPrice = $fallbackExchangeRate;
            } else {
                $dollarPrice = 1400.0;
            }
        } catch (Exception $innerE) {
            // لە کاتی هەر هەڵەیەک، ھەمان نرخی default
            $dollarPrice = 1400.0;
        }
    }
} catch (Exception $e) {
    // هەڵە بێدەنگ بکە
}

// کاتی دوایین نوێکردنەوەی نرخی دۆلار (بۆ نیشاندان لە POS)
try {
    $dollarUpdatedResult = $conn->query("SELECT last_updated FROM dollar_prices ORDER BY last_updated DESC LIMIT 1");
    if ($dollarUpdatedResult && $dollarUpdatedRow = $dollarUpdatedResult->fetch_assoc()) {
        $rawLastUpdated = isset($dollarUpdatedRow['last_updated']) ? trim((string)$dollarUpdatedRow['last_updated']) : '';
        if ($rawLastUpdated !== '') {
            $updatedTimestamp = strtotime($rawLastUpdated);
            if ($updatedTimestamp !== false) {
                $weekdayNames = [
                    'Sunday' => 'یەکشەممە',
                    'Monday' => 'دووشەممە',
                    'Tuesday' => 'سێشەممە',
                    'Wednesday' => 'چوارشەممە',
                    'Thursday' => 'پێنجشەممە',
                    'Friday' => 'هەینی',
                    'Saturday' => 'شەممە',
                ];

                $todayStart = strtotime(date('Y-m-d'));
                $updatedDayStart = strtotime(date('Y-m-d', $updatedTimestamp));
                $daysDiff = (int)(($todayStart - $updatedDayStart) / 86400);

                if ($daysDiff === 0) {
                    $dayLabel = 'ئەمڕۆ';
                } elseif ($daysDiff === 1) {
                    $dayLabel = 'دوێنێ';
                } else {
                    $englishWeekday = date('l', $updatedTimestamp);
                    $dayLabel = $weekdayNames[$englishWeekday] ?? $englishWeekday;
                }

                $timeValue = date('h:i', $updatedTimestamp);
                $meridiem = date('a', $updatedTimestamp);
                $dollarLastUpdatedDisplay = $dayLabel . ' ' . $timeValue . '<span class="ampm">' . $meridiem . '</span>';
            }
        }
    }
} catch (Exception $e) {
    // هەڵە بێدەنگ بکە
}

// هەمیشە manual_change بۆ باقی دانەوە لە بنکەدراوەوە وەر بگرە
if (function_exists('getManualAdjustment')) {
    $manualChangeAdjustment = getManualAdjustment($userId);
    // ئەگەر بە شێوازی 5 / -5 دانرابێت، بۆ بەکارهێنانی لە "بڕی پارە:" *99 بکە → 495 / -495
    if (abs($manualChangeAdjustment) > 0 && abs($manualChangeAdjustment) < 99) {
        $manualChangeAdjustment = $manualChangeAdjustment * 99;
    }
    // نۆرمالکردن بۆ نزیکترین 99ـەوە، بۆ دوورخستن لە دیناری زیاتر (مثال: 500 → 495 یان 594 → 594)
    if ($manualChangeAdjustment !== 0.0) {
        $manualChangeAdjustment = round($manualChangeAdjustment / 99) * 99;
    }
}

$dollarPriceOk = ($dollarPrice !== null && (float)$dollarPrice > 0);
$uidPos = (int)$userId;
if ($dbZanyariPos !== null && $uidPos > 0) {
    $stmtPos = $dbZanyariPos->prepare(
        'SELECT pos_show_zero_stock_products, pos_default_sale_currency, pos_default_price_type, pos_default_payment_is_credit FROM user_account_settings WHERE user_id = ? LIMIT 1'
    );
    if ($stmtPos) {
        $stmtPos->bind_param('i', $uidPos);
        $stmtPos->execute();
        $rowPos = $stmtPos->get_result()->fetch_assoc();
        $stmtPos->close();
        if ($rowPos) {
            if (array_key_exists('pos_show_zero_stock_products', $rowPos)) {
                $posShowZeroStockProducts = (int)$rowPos['pos_show_zero_stock_products'] ? 1 : 0;
            }
            if (array_key_exists('pos_default_sale_currency', $rowPos) && $rowPos['pos_default_sale_currency'] !== null) {
                $c = strtoupper((string)$rowPos['pos_default_sale_currency']);
                $posDefaultSaleCurrency = ($c === 'USD') ? 'USD' : 'IQD';
            }
            if (array_key_exists('pos_default_price_type', $rowPos) && $rowPos['pos_default_price_type'] !== null) {
                $rawPriceType = strtolower((string)$rowPos['pos_default_price_type']);
                $posDefaultPriceType = in_array($rawPriceType, ['retail', 'wholesale', 'special'], true) ? $rawPriceType : 'retail';
            }
            if (array_key_exists('pos_default_payment_is_credit', $rowPos)) {
                $posDefaultPaymentIsCredit = (int)$rowPos['pos_default_payment_is_credit'] ? 1 : 0;
            }
        }
    }
}
$posEffectiveSaleCurrency = ($posDefaultSaleCurrency === 'USD' && !$dollarPriceOk) ? 'IQD' : $posDefaultSaleCurrency;
$posSaleCurrencyFallbackFromUsd = ($posDefaultSaleCurrency === 'USD' && $posEffectiveSaleCurrency === 'IQD');
$posDefaultPaymentMethod = $posDefaultPaymentIsCredit ? 'credit' : 'cash';
$posPriceTypeMeta = [
    'retail' => ['icon' => 'bi-1-circle', 'text' => 'تاک'],
    'wholesale' => ['icon' => 'bi-2-circle', 'text' => 'جوملە'],
    'special' => ['icon' => 'bi-star', 'text' => 'تایبەت'],
];
$posDefaultPriceTypeMeta = $posPriceTypeMeta[$posDefaultPriceType] ?? $posPriceTypeMeta['retail'];

$csrf_token = Security::generateCSRFToken();

$posBarcodeScanEnabled = hasFeaturePermission('pos_barcode_scan');
$posBarcodeScanLockTexts = getPremiumLockPackageTexts(
    getPackageFeatureUsageSuffix('pos_barcode_scan', 'بەکارهێنانی سکانەری بارکۆد لە فرۆشتن')
);

$posHasServicesAccess = hasFeaturePermission('products_services_manage');
$posServicesLockTexts = getPremiumLockPackageTexts(
    getPackageFeatureUsageSuffix('products_services_manage', 'بەڕێوەبردنی خزمەتگوزاری')
);

// دەسەڵاتی بینینی قازانج — کاتێک ناچالاک بێت، کارتەکانی قازانج لە فرۆشتن دەرناکەون
$posCanViewProfits = !empty(authorize($currentUser, 'profits.view')['allowed']);

// دەسەڵاتی گۆڕینی نرخ و کۆی گشتی لە سەبەتەی فرۆشتن (مۆدێلی deny — بنەڕەت ڕێگەپێدراوە)
$posCanEditPrice = posSubUserCanEditField($currentUser, 'pos.price_edit');
$posCanEditTotal = posSubUserCanEditField($currentUser, 'pos.total_edit');

require_once '../../includes/scale_product_sync.php';
$posScaleSettings = getScaleBarcodeSettingsForUser($conn, (int)$userId);
if (!hasSmartScaleAccess()) {
    $posScaleSettings['is_enabled'] = false;
}

