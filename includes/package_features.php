<?php
/**
 * کاتالۆگی feature ـەکانی پاکێج — سەرچاوەی یەکگرتوو بۆ Admin و User
 */

/**
 * @return array<string, array<string, mixed>>
 */
function getPackageFeatureCatalog()
{
    return [
        'pos_receipt_view' => [
            'label' => 'بینینی وەسڵی POS',
            'section' => 'pos',
            'description' => 'ڕێگەدان بە بەکارهێنەران بۆ بینینی پەڕەی وەسڵی بچووک (user/pos/receipt.php)',
            'icon' => 'bi-receipt',
            'usage_suffix' => 'بینینی وەسڵی POS',
        ],
        'pos_receipt_a4_view' => [
            'label' => 'بینینی وەسڵی A4',
            'section' => 'pos',
            'description' => 'ڕێگەدان بە بەکارهێنەران بۆ بینینی پەڕەی وەسڵی A4 (user/pos/receipt_a4.php)',
            'icon' => 'bi-file-earmark-richtext',
            'usage_suffix' => 'بینینی وەسڵی A4',
        ],
        'pos_barcode_scan' => [
            'label' => 'سکانەری بارکۆد لە POS',
            'section' => 'pos',
            'description' => 'ڕێگەدان بە سکانەری hardware و کامێرا لە user/pos/index.php',
            'icon' => 'bi-upc-scan',
            'usage_suffix' => 'بەکارهێنانی سکانەری بارکۆد لە فرۆشتن',
        ],
        'employees_manage' => [
            'label' => 'بەڕێوەبردنی کارمەندان',
            'section' => 'employees',
            'description' => 'ڕێگەدان بۆ بەڕێوەبردنی کارمەندان و بەکارهێنەرانی لاوەکی (user/employees/manage.php)',
            'icon' => 'bi-people-fill',
            'usage_suffix' => 'بەڕێوەبردنی کارمەندان',
        ],
        'employees_stats' => [
            'label' => 'ئاماری کارمەندان',
            'section' => 'employees',
            'description' => 'ڕێگەدان بۆ بینینی ئاماری فرۆشتنی کارمەندان (user/employees/index.php)',
            'icon' => 'bi-graph-up-arrow',
            'usage_suffix' => 'بینینی ئاماری کارمەندان',
        ],
        'employees_max_count' => [
            'label' => 'ژمارەی کارمەندان',
            'section' => 'employees',
            'description' => 'زۆرترین ژمارەی کارمەندان کە بەکارهێنەر دەتوانێت دروستیان بکات و بەکاریان بهێنێت (٠ = هیچ کارمەندێک ناتوانێت چوونەژوورەوە بکات)',
            'icon' => 'bi-person-badge',
            'type' => 'number',
            'min' => 0,
            'max' => 10,
            'default' => 3,
        ],
        'companies_manage' => [
            'label' => 'بەڕێوەبردنی کۆمپانیاکان',
            'section' => 'companies',
            'description' => 'ڕێگەدان بە بەکارهێنەران بۆ بینینی بەشی کۆمپانیاکان و مامەڵەکردن لەگەڵ کۆمپانیاکان (user/companies/)',
            'icon' => 'bi-building',
            'usage_suffix' => 'بەکارهێنانی بەشی کۆمپانیاکان',
        ],
        'customers_history' => [
            'label' => 'مێژووی کڕیاران',
            'section' => 'customers',
            'description' => 'ڕێگەدان بۆ بینینی مێژووی گۆڕانکارییەکانی کڕیاران (user/customers/history.php)',
            'icon' => 'bi-clock-history',
            'usage_suffix' => 'بینینی مێژووی کڕیاران',
        ],
        'customers_account_statement' => [
            'label' => 'کەشف حسابی',
            'section' => 'customers',
            'description' => 'ڕێگەدان بۆ بینینی کەشف حسابی کڕیار (user/customers/account_statement.php)',
            'icon' => 'bi-file-earmark-text',
            'usage_suffix' => 'بینینی کەشف حسابی',
        ],
        'wallets_manage' => [
            'label' => 'بەڕێوەبردنی قاسەکان',
            'section' => 'wallets',
            'description' => 'ڕێگەدان بە بەکارهێنەران بۆ دەستپێگەیشتن و بەڕێوەبردنی module ی قاسەکان (user/wallets/)',
            'icon' => 'bi-wallet2',
            'usage_suffix' => 'بەکارهێنانی بەشی قاسەکان',
        ],
        'reports_item_section_profit' => [
            'label' => 'ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان',
            'section' => 'reports',
            'description' => 'ڕێگەدان بۆ بینینی ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان (user/reports/item_section_profit_report.php)',
            'icon' => 'bi-box-seam',
            'usage_suffix' => 'بینینی ڕاپۆرتی قازانج بەپێی کاڵا و بەشەکان',
        ],
        'products_shelf_price_tags' => [
            'label' => 'نرخی سەر ڕەفە',
            'section' => 'products',
            'description' => 'ڕێگەدان بۆ دروستکردن و چاپکردنی تێمپلێتی نرخی سەر ڕەفە (user/products/shelf_price_tags/)',
            'icon' => 'bi-tag',
            'usage_suffix' => 'بەکارهێنانی نرخی سەر ڕەفە',
        ],
        'products_services_manage' => [
            'label' => 'خزمەتگوزاری',
            'section' => 'products',
            'description' => 'ڕێگەدان بۆ بەڕێوەبردنی خزمەتگوزارییەکان (user/products/services.php)',
            'icon' => 'bi-briefcase',
            'usage_suffix' => 'بەڕێوەبردنی خزمەتگوزاری',
        ],
        'products_drug_interactions' => [
            'label' => 'دوو دەرمانی نەگونجاو (بۆ دەرمانخانە)',
            'section' => 'products',
            'description' => 'ڕێگەدان بۆ بەڕێوەبردنی دەرمانی نەگونجاو لە دەرمانخانە (user/products/drug_interactions.php)',
            'icon' => 'bi-shield-exclamation',
            'usage_suffix' => 'بەڕێوەبردنی دەرمانی نەگونجاو',
        ],
        'products_history' => [
            'label' => 'مێژووی کاڵاکان',
            'section' => 'products',
            'description' => 'ڕێگەدان بۆ بینینی مێژووی گۆڕانکارییەکانی کاڵاکان (user/products/history.php)',
            'icon' => 'bi-clock-history',
            'usage_suffix' => 'بینینی مێژووی کاڵاکان',
        ],
        'products_barcode_generate' => [
            'label' => 'دروستکردنی بارکۆد',
            'section' => 'products',
            'description' => 'ڕێگەدان بۆ دروستکردن و چاپکردنی لەیبڵی بارکۆد (user/products/barcode/)',
            'icon' => 'bi-upc-scan',
            'usage_suffix' => 'دروستکردنی بارکۆد',
        ],
        'products_inventory_value_cards' => [
            'label' => 'کارتی مەخزەن (نرخی کڕین و فرۆشتن)',
            'section' => 'products',
            'description' => 'ڕێگەدان بۆ بینینی هەردوو کارتی کۆی مەخزەن بە نرخی کڕین و فرۆشتن لە لیستی کاڵاکان (user/products/index.php)',
            'icon' => 'bi-box-seam',
            'usage_suffix' => 'بینینی کارتی مەخزەن بە نرخی کڕین و فرۆشتن',
        ],
        'products_units_manage' => [
            'label' => 'بەڕێوەبردنی یەکەکانی جیاواز',
            'section' => 'products',
            'description' => 'ڕێگەدان بۆ هەڵبژاردن و بەڕێوەبردنی چەند یەکەی جیاواز لە زیادکردن و دەستکاری کاڵا (user/products/add.php, edit.php)',
            'icon' => 'bi-rulers',
            'usage_suffix' => 'بەکارهێنانی یەکەکانی جیاواز بۆ کاڵا',
        ],
        'products_smart_scale' => [
            'label' => 'قەپانی زیرەک',
            'section' => 'products',
            'description' => 'ڕێگەدان بۆ ڕێکخستن و کاڵاکانی قەپانی زیرەک و ناسینەوەی بارکۆد لە فرۆشتن',
            'icon' => 'bi-speedometer2',
            'usage_suffix' => 'بەکارهێنانی قەپانی زیرەک',
            'default_enabled' => false,
        ],
        'expenses_manage' => [
            'label' => 'خەرجیەکان',
            'section' => 'expenses',
            'description' => 'ڕێگەدان بۆ بەڕێوەبردنی خەرجیەکان و قەرزەکانی خەرجی (user/expenses/)',
            'icon' => 'bi-cash-stack',
            'usage_suffix' => 'بەڕێوەبردنی خەرجیەکان',
        ],
        'medical_staff_manage' => [
            'label' => 'بەڕێوەبردنی پزیشکی',
            'section' => 'medical',
            'description' => 'ڕێگەدان بۆ بەڕێوەبردنی پزیشک، سکرتێر و ڕەچەتەکان (user/medical_staff/)',
            'icon' => 'bi-hospital',
            'usage_suffix' => 'بەکارهێنانی بەشی بەڕێوەبردنی پزیشکی',
        ],
        'cosmetic_staff_manage' => [
            'label' => 'بەڕێوەبردنی سەنتەری جوانکاری',
            'section' => 'medical',
            'description' => 'ڕێگەدان بۆ بەڕێوەبردنی ئەکاونتی سەنتەر و دکتۆری جوانکاری (user/cosmetic_staff/)',
            'icon' => 'bi-flower1',
            'usage_suffix' => 'بەکارهێنانی بەشی بەڕێوەبردنی سەنتەری جوانکاری',
        ],
        'notifications_sale_receipt_delete' => [
            'label' => 'سڕینەوەی وەسڵی فرۆشتن',
            'section' => 'notifications',
            'description' => 'خۆکار — کاتێک وەسڵێکی فرۆشتن لە POS دەسڕدرێتەوە، ئاگادارکەرەوە بۆ تیلیگرام دەنێردرێت',
            'icon' => 'bi-receipt-cutoff',
            'usage_suffix' => 'ئاگادارکەرەوەی سڕینەوەی وەسڵی فرۆشتن',
        ],
        'notifications_purchase_receipt_delete' => [
            'label' => 'سڕینەوەی وەسڵی کڕین',
            'section' => 'notifications',
            'description' => 'خۆکار — کاتێک وەسڵێکی کڕین لە بەشی کڕینەکان دەسڕدرێتەوە، ئاگادارکەرەوە بۆ تیلیگرام دەنێردرێت',
            'icon' => 'bi-bag-x',
            'usage_suffix' => 'ئاگادارکەرەوەی سڕینەوەی وەسڵی کڕین',
        ],
        'notifications_debt_payment_delete' => [
            'label' => 'سڕینەوەی وەسڵی پارەدانەوەی قەرز',
            'section' => 'notifications',
            'description' => 'خۆکار — کاتێک پارەدانەوەی قەرزی کڕیار دەسڕدرێتەوە، ئاگادارکەرەوە بۆ تیلیگرام دەنێردرێت',
            'icon' => 'bi-cash-coin',
            'usage_suffix' => 'ئاگادارکەرەوەی سڕینەوەی پارەدانەوەی قەرز',
        ],
        'multi_business_max_count' => [
            'label' => 'ژمارەی بزنسەکان',
            'section' => 'organization',
            'description' => 'زۆرترین ژمارەی بزنس کە ئەم خاوەنە دەتوانێت هەیبێت و لەنێوانیان بگۆڕێت (١ = تاک-بزنس/ڕەفتاری ئاسایی). تەنها ئەدمین بزنسی نوێ دروست دەکات و دەیبەستێتەوە.',
            'icon' => 'bi-shop-window',
            'type' => 'number',
            'min' => 1,
            'max' => 20,
            'default' => 1,
        ],
    ];
}

/**
 * @return array<string, string>
 */
function getPackageFeatureSectionLabels()
{
    return [
        'pos' => 'سیستەمی فرۆشتن (POS)',
        'employees' => 'کارمەندان',
        'companies' => 'کۆمپانیاکان',
        'customers' => 'کڕیاران',
        'wallets' => 'قاسەکان',
        'products' => 'کاڵاکان',
        'reports' => 'ڕاپۆرتەکان',
        'expenses' => 'خەرجیەکان',
        'medical' => 'پزیشکی و جوانکاری',
        'notifications' => 'ئاگادارکەرەوەکان',
        'organization' => 'چەندین بزنس',
        'other' => 'هیتر',
    ];
}

/**
 * @return string[]
 */
function getPackageFeatureSectionOrder()
{
    return ['pos', 'employees', 'companies', 'customers', 'wallets', 'products', 'reports', 'expenses', 'medical', 'notifications', 'organization', 'other'];
}

/**
 * پەیوەندی message_type ـی تیلیگرام بە feature_key ـی پاکێج
 *
 * @return array<string, string>
 */
function getNotificationFeatureKeyMap()
{
    return [
        'sale_delete' => 'notifications_sale_receipt_delete',
        'purchase_receipt_delete' => 'notifications_purchase_receipt_delete',
        'debt_payment_delete' => 'notifications_debt_payment_delete',
    ];
}

/**
 * usage_suffix بۆ پەیامی قوفڵ — لە کاتالۆگ یان fallback
 */
function getPackageFeatureUsageSuffix($featureKey, $fallback = null)
{
    $catalog = getPackageFeatureCatalog();
    if (isset($catalog[$featureKey]['usage_suffix'])) {
        return (string)$catalog[$featureKey]['usage_suffix'];
    }

    return $fallback;
}

/**
 * بنەڕەتی چالاکی feature کاتێک ڕیز لە package_feature_permissions نییە
 */
function isPackageFeatureEnabledByDefault($featureKey)
{
    $catalog = getPackageFeatureCatalog();
    if (!isset($catalog[$featureKey])) {
        return true;
    }

    $feature = $catalog[$featureKey];
    if (isset($feature['type']) && $feature['type'] === 'number') {
        return (int)($feature['default'] ?? 0) > 0;
    }

    return (bool)($feature['default_enabled'] ?? true);
}
