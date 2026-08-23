<?php
/**
 * API بۆ وەرگرتنی نرخەکانی دۆلار
 * user/Tele_Bot/api_prices.php
 * 
 * بەکارهێنان:
 * GET /api_prices.php - وەرگرتنی هەموو نرخەکان
 * GET /api_prices.php?city=هەولێر - وەرگرتنی نرخی شارێکی دیاریکراو
 * GET /api_prices.php?latest=1 - وەرگرتنی دوایین نرخی نوێکراوە
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../../config/database.php';

// وەرگرتنی پارامەترەکان
$city = isset($_GET['city']) ? trim($_GET['city']) : null;
$latest = isset($_GET['latest']) ? true : false;

$response = [
    'success' => false,
    'data' => [],
    'message' => '',
    'timestamp' => date('Y-m-d H:i:s')
];

try {
    if ($city) {
        // وەرگرتنی نرخی شارێکی دیاریکراو
        $stmt = $conn->prepare("SELECT 
            city_name,
            offer_price,
            source,
            message_text,
            DATE_FORMAT(last_updated, '%Y-%m-%d %H:%i:%s') as last_updated,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at,
            TIMESTAMPDIFF(MINUTE, last_updated, NOW()) as minutes_ago
        FROM dollar_prices 
        WHERE city_name = ?");
        
        $stmt->bind_param("s", $city);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $response['success'] = true;
            $response['data'] = [
                'city_name' => $row['city_name'],
                'offer_price' => floatval($row['offer_price']),
                'source' => $row['source'],
                'last_updated' => $row['last_updated'],
                'created_at' => $row['created_at'],
                'minutes_ago' => intval($row['minutes_ago'])
            ];
            $response['message'] = 'نرخی ' . $city . ' بە سەرکەوتوویی دۆزرایەوە';
        } else {
            $response['message'] = 'نرخ بۆ ئەم شارە تۆمار نەکراوە: ' . $city;
        }
        
    } elseif ($latest) {
        // وەرگرتنی دوایین نرخی نوێکراوە
        $result = $conn->query("SELECT 
            city_name,
            offer_price,
            source,
            DATE_FORMAT(last_updated, '%Y-%m-%d %H:%i:%s') as last_updated,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at,
            TIMESTAMPDIFF(MINUTE, last_updated, NOW()) as minutes_ago
        FROM dollar_prices 
        ORDER BY last_updated DESC 
        LIMIT 1");
        
        if ($row = $result->fetch_assoc()) {
            $response['success'] = true;
            $response['data'] = [
                'city_name' => $row['city_name'],
                'offer_price' => floatval($row['offer_price']),
                'source' => $row['source'],
                'last_updated' => $row['last_updated'],
                'created_at' => $row['created_at'],
                'minutes_ago' => intval($row['minutes_ago'])
            ];
            $response['message'] = 'دوایین نرخی نوێکراوە بە سەرکەوتوویی دۆزرایەوە';
        } else {
            $response['message'] = 'هیچ نرخێک تۆمار نەکراوە';
        }
        
    } else {
        // وەرگرتنی هەموو نرخەکان
        $result = $conn->query("SELECT 
            city_name,
            offer_price,
            source,
            DATE_FORMAT(last_updated, '%Y-%m-%d %H:%i:%s') as last_updated,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at,
            TIMESTAMPDIFF(MINUTE, last_updated, NOW()) as minutes_ago
        FROM dollar_prices 
        ORDER BY last_updated DESC");
        
        $prices = [];
        while ($row = $result->fetch_assoc()) {
            $prices[] = [
                'city_name' => $row['city_name'],
                'offer_price' => floatval($row['offer_price']),
                'source' => $row['source'],
                'last_updated' => $row['last_updated'],
                'created_at' => $row['created_at'],
                'minutes_ago' => intval($row['minutes_ago'])
            ];
        }
        
        if (!empty($prices)) {
            $response['success'] = true;
            $response['data'] = $prices;
            $response['message'] = count($prices) . ' نرخ دۆزرایەوە';
            
            // زیادکردنی ئاماری گشتی
            $statsResult = $conn->query("SELECT 
                MAX(offer_price) as highest,
                MIN(offer_price) as lowest,
                AVG(offer_price) as average,
                COUNT(*) as total
            FROM dollar_prices");
            
            if ($stats = $statsResult->fetch_assoc()) {
                $response['statistics'] = [
                    'highest_price' => floatval($stats['highest']),
                    'lowest_price' => floatval($stats['lowest']),
                    'average_price' => round(floatval($stats['average']), 3),
                    'total_cities' => intval($stats['total'])
                ];
            }
        } else {
            $response['message'] = 'هیچ نرخێک تۆمار نەکراوە';
        }
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'هەڵەیەک ڕوویدا: ' . $e->getMessage();
}

// ڕاگەیاندنی وەڵام
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

?>

