<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/theme_bootstrap.php';
require_once __DIR__ . '/includes/zanyari_user_settings.php';
require_once __DIR__ . '/includes/profit_schema.php';
require_once __DIR__ . '/includes/item_profit_report_stats.php';
require_once __DIR__ . '/includes/reports_cache.php';
require_once __DIR__ . '/user/products/includes/custom_fields_helpers.php';

$database = new Database();
$conn = $database->connect();

$userId = 1;
$isSubUser = false;
$currentUser = ['id' => 1, 'user_type' => 'main'];

try {
    $today = date('Y-m-d');
    $fromDate = $today;
    $toDate = $today;
    $searchQ = '';
    $filterFieldId = 0;
    $filterOptionId = 0;
    $sortBy = 'qty';
    $sortDir = 'desc';
    $page = 1;
    $perPage = 50;
    $activeTab = 'products';
    $effectiveSubUserId = null;
    $productIdsFilter = null;

    $recognizeDebtRevenueAtSale = getRecognizeCustomerDebtRevenueAtSale($userId);

    $dataSignature = getReportsDataSignature($conn, (int)$userId, $effectiveSubUserId);
    $cacheKey = sha1(implode('|', [
        'item_profit_report_v3_multicurrency',
        (string)$userId,
        $fromDate,
        $toDate,
        (string)($effectiveSubUserId ?? 0),
        $searchQ,
        (string)$filterFieldId,
        (string)$filterOptionId,
        $sortBy,
        $sortDir,
        (string)$page,
        $activeTab,
        $dataSignature,
    ]));

    $cached = loadItemProfitReportCached($cacheKey, 3600);
    if (is_array($cached) && isset($cached['productReport'], $cached['optionRows'])) {
        $productReport = $cached['productReport'];
        $optionRows = $cached['optionRows'];
    } else {
        $productReport = fetchItemProfitByProduct(
            $conn,
            $userId,
            $fromDate,
            $toDate,
            $effectiveSubUserId,
            $searchQ,
            $productIdsFilter,
            $sortBy,
            $sortDir,
            $page,
            $perPage
        );
        $optionRows = fetchItemProfitByCustomFieldOptions(
            $conn,
            $userId,
            $fromDate,
            $toDate,
            $effectiveSubUserId,
            $searchQ,
            $filterFieldId > 0 ? $filterFieldId : 0
        );
        saveItemProfitReportCached($cacheKey, [
            'productReport' => $productReport,
            'optionRows' => $optionRows,
        ]);
        cleanupStaleReportsCache(getReportsCacheDir() . DIRECTORY_SEPARATOR . $cacheKey . '.json');
    }

    echo "SUCCESS! No fatal errors.";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}

