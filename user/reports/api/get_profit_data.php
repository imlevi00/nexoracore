<?php
/**
 * API بۆ وەرگرتنی داتای قازانج - user/reports/api/get_profit_data.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';
require_once '../../../includes/zanyari_user_settings.php';
require_once '../../../includes/profit_stats.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$userPermissions = getUserPermissions($userId);

// تاقیکردنی دەسەڵاتی قازانج
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';
if ($isSubUser && (!isset($userPermissions['profits']) || !$userPermissions['profits'])) {
    http_response_code(403);
    echo json_encode(['error' => 'دەسەڵات نەماوە']);
    exit;
}

// وەرگرتنی پارامیتەرەکان
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

if (empty($fromDate) || empty($toDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'ڕێکەوتەکان پێویستن']);
    exit;
}

$recognizeDebtRevenueAtSale = getRecognizeCustomerDebtRevenueAtSale($userId);

// حیسابکردنی داتای چارت (بۆ هەردوو دراو بە جیایی)
function getProfitChartData($conn, $userId, $fromDate, $toDate, $recognizeDebtRevenueAtSale) {
    $chartData = [];
    $startDate = new DateTime($fromDate);
    $endDate = new DateTime($toDate);

    while ($startDate <= $endDate) {
        $date = $startDate->format('Y-m-d');
        $dayIqd = calculateProfitStats($conn, $userId, $date, $date, null, false, $recognizeDebtRevenueAtSale, 'IQD');
        $dayUsd = calculateProfitStats($conn, $userId, $date, $date, null, false, $recognizeDebtRevenueAtSale, 'USD');

        $chartData[] = [
            'date' => $date,
            'profit' => $dayIqd['profit'],
            'revenue' => $dayIqd['revenue'],
            'cost' => $dayIqd['cost_of_goods'],
            'profit_usd' => $dayUsd['profit'],
            'revenue_usd' => $dayUsd['revenue'],
            'cost_usd' => $dayUsd['cost_of_goods']
        ];

        $startDate->add(new DateInterval('P1D'));
    }

    return $chartData;
}

try {
    // حیسابکردنی ئامارەکانی سەرەکی بۆ هەردوو دراو بە جیایی
    $statsByCurrency = calculateProfitStatsByCurrency($conn, $userId, $fromDate, $toDate, null, false, $recognizeDebtRevenueAtSale);

    // حیسابکردنی داتای چارت
    $chartData = getProfitChartData($conn, $userId, $fromDate, $toDate, $recognizeDebtRevenueAtSale);

    // گەڕاندنی نەتەجەکان
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'stats' => $statsByCurrency['IQD'],
        'stats_by_currency' => $statsByCurrency,
        'chart_data' => $chartData
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()
    ]);
}
?>
