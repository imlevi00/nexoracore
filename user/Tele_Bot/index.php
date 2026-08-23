<?php
/**
 * بۆتی تەلیگرام بۆ مۆنیتەرکردنی نرخی دۆلار لە کەناڵی @NrxiDraw24
 * user/Tele_Bot/index.php
 */

// پێویستیەکان
require_once '../../config/database.php';
require_once __DIR__ . '/../../config/secrets.php';

// تۆکێنی بۆتی تیلیگرام — لە config/secrets.local.php یان environment variable
$botToken = kasher_secret('nrx_bot_token', 'NRX_BOT_TOKEN');
$apiUrl = "https://api.telegram.org/bot" . $botToken;

// یوزەرنەیمی کەناڵی چاودێریکراو
$targetChannel = "@NrxiDraw24";

// وەرگرتنی نوێکردنەوەکان
$update = json_decode(file_get_contents('php://input'), true);

// لۆگکردنی نوێکردنەوەکان بۆ دیباگکردن (دەتوانرێت لاببرێت دواتر)
file_put_contents(__DIR__ . '/logs.txt', date('Y-m-d H:i:s') . " - " . json_encode($update, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

// پرۆسێسکردنی نامە لە کەناڵ
if (isset($update['channel_post'])) {
    $channelPost = $update['channel_post'];
    $text = $channelPost['text'] ?? '';
    $chatUsername = $channelPost['chat']['username'] ?? '';
    
    // پشکنین ئایا نامەکە لە کەناڵی چاودێریکراوە
    if ('@' . $chatUsername === $targetChannel) {
        processChannelMessage($text);
    }
}

// پرۆسێسکردنی نامەی ئاسایی (بۆ کۆماندەکان)
if (isset($update['message'])) {
    $chatId = $update['message']['chat']['id'];
    $text = $update['message']['text'] ?? '';

    // وەڵامدانەوە بۆ کۆماندی /start
    if ($text == '/start') {
        $response = "بەخێربێت بۆ سیستەمی  NexoraCore\n\n";
        $response .= " لەڕێگەی ئەم بۆتەوە بەردەوام ڕۆژانە کە سیستەمەکەتان بەکارهێنا داتای قەرزەکانتان بۆ دەنێرێتەوە";
        $response .= "ئایدی تیلیگرامت: " . $chatId;
        sendMessage($chatId, $response);
    }
    
    // کۆماندی /prices بۆ پیشاندانی دوایین نرخەکان
    elseif ($text == '/prices' || $text == '/نرخ') {
        $prices = getLatestPrices();
        if (!empty($prices)) {
            $response = "📊 دوایین نرخەکانی دۆلار:\n\n";
            foreach ($prices as $price) {
                $response .= "🏙 {$price['city_name']}: {$price['offer_price']} دینار\n";
                $response .= "⏰ نوێکراوەتەوە: {$price['last_updated']}\n\n";
            }
        } else {
            $response = "هیچ نرخێک تۆمار نەکراوە تاوەکو ئێستا.";
        }
        sendMessage($chatId, $response);
    }
}

/**
 * پرۆسێسکردنی نامەکانی کەناڵ
 */
function processChannelMessage($text) {
    global $conn;
    
    // پشکنین ئایا نامەکە وشەی "عــــرض"ی تێدایە
    if (strpos($text, 'عــــرض') === false) {
        return; // نامەکە پەیوەندی بە نرخەوە نییە
    }
    
    // دەرهێنانی زانیاریەکان
    $extracted = extractPriceInfo($text);
    
    if ($extracted) {
        // خەزنکردن یان نوێکردنەوەی نرخ لە داتابەیس
        savePriceToDatabase($extracted['city'], $extracted['price'], $text);
    }
}

/**
 * دەرهێنانی زانیاریەکانی نرخ لە دەقی نامە
 */
function extractPriceInfo($text) {
    $result = null;
    
    // پاتێرن بۆ دۆزینەوەی ناوی شار و نرخی عــــرض
    // پاتێرن دەگەڕێت بۆ: ناوی شار + ژمارە + عــــرض
    
    // لیستی شارەکان
    $cities = [
        'هەولێر', 'سلێمانی', 'دهۆک', 'کەرکووک', 
        'هەڵەبجە', 'کۆیە', 'زاخۆ', 'ئاکرێ',
        'سۆران', 'رانیە', 'قەڵادزێ', 'خانەقین'
    ];
    
    foreach ($cities as $city) {
        if (stripos($text, $city) !== false) {
            // دۆزینەوەی ژمارەکەی پێش عــــرض
            // پاتێرن: ژمارە (لەوانەیە کۆما یان خاڵی تێدابێت) + عــــرض
            if (preg_match('/[\d\.,]+\s*عــــرض/', $text, $matches)) {
                // دەرهێنانی ژمارە
                preg_match('/([\d\.,]+)/', $matches[0], $priceMatch);
                if (!empty($priceMatch[1])) {
                    $price = normalizeOfferPrice($priceMatch[1]);
                    if ($price === null) {
                        continue;
                    }
                    $result = [
                        'city' => $city,
                        'price' => $price
                    ];
                    break;
                }
            }
        }
    }
    
    return $result;
}

/**
 * normalizeکردنی نرخی عــــرض
 * - 153.250 => 153250 (thousand grouping)
 * - 1420.50 => 1420.50 (decimal)
 * - 1,234.56 / 1.234,56 => 1234.56
 */
function normalizeOfferPrice($rawPrice) {
    $value = trim((string)$rawPrice);
    $value = preg_replace('/[^\d\.,]/', '', $value);

    if ($value === '') {
        return null;
    }

    $hasDot = strpos($value, '.') !== false;
    $hasComma = strpos($value, ',') !== false;

    // کاتێک هەردوو separator هەن، دوا separator بە decimal دەژمێررێت.
    if ($hasDot && $hasComma) {
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot > $lastComma) {
            $normalized = str_replace(',', '', $value);
        } else {
            $normalized = str_replace('.', '', $value);
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? floatval($normalized) : null;
    }

    // تەنها یەک separator type
    if ($hasDot || $hasComma) {
        $separator = $hasDot ? '.' : ',';
        $parts = explode($separator, $value);

        // چەند separator ـێک: زۆرجار thousand grouping ـە
        if (count($parts) > 2) {
            $normalized = str_replace($separator, '', $value);
            return is_numeric($normalized) ? floatval($normalized) : null;
        }

        if (count($parts) === 2) {
            $fractionLength = strlen($parts[1]);

            // 3 ژمارەی دوای separator -> thousand grouping
            if ($fractionLength === 3) {
                $normalized = str_replace($separator, '', $value);
                return is_numeric($normalized) ? floatval($normalized) : null;
            }

            // 1-2 ژمارەی دوای separator -> decimal
            if ($fractionLength <= 2) {
                $normalized = $hasComma ? str_replace(',', '.', $value) : $value;
                return is_numeric($normalized) ? floatval($normalized) : null;
            }

            // دۆخی ناڕوون: separator بە thousand وەربگرە
            $normalized = str_replace($separator, '', $value);
            return is_numeric($normalized) ? floatval($normalized) : null;
        }
    }

    return is_numeric($value) ? floatval($value) : null;
}

/**
 * خەزنکردنی نرخ لە داتابەیس
 */
function savePriceToDatabase($city, $price, $messageText) {
    global $conn;
    
    try {
        // پشکنینی بوونی ریکۆرد بۆ ئەم شارە
        $stmt = $conn->prepare("SELECT id, offer_price FROM dollar_prices WHERE city_name = ?");
        $stmt->bind_param("s", $city);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // نوێکردنەوەی نرخی هەبوو
            $stmt = $conn->prepare("UPDATE dollar_prices SET offer_price = ?, message_text = ?, last_updated = NOW() WHERE city_name = ?");
            $stmt->bind_param("dss", $price, $messageText, $city);
            $stmt->execute();
        } else {
            // زیادکردنی نرخی نوێ
            $stmt = $conn->prepare("INSERT INTO dollar_prices (city_name, offer_price, message_text, source) VALUES (?, ?, ?, '@NrxiDraw24')");
            $stmt->bind_param("sds", $city, $price, $messageText);
            $stmt->execute();
        }
        
        $stmt->close();
        
        // لۆگکردن بۆ سەرکەوتن
        file_put_contents(__DIR__ . '/price_updates.log', 
            date('Y-m-d H:i:s') . " - نرخ نوێکرایەوە: $city = $price دینار\n", 
            FILE_APPEND
        );
        
        // نوێکردنەوەی ئۆتۆماتیکی exchange_rate لە currency_exchange_rates
        updateExchangeRatesFromDollarPrices();
        
        return true;
    } catch (Exception $e) {
        // لۆگکردنی هەڵە
        file_put_contents(__DIR__ . '/errors.log', 
            date('Y-m-d H:i:s') . " - هەڵە: " . $e->getMessage() . "\n", 
            FILE_APPEND
        );
        return false;
    }
}

/**
 * نوێکردنەوەی ئۆتۆماتیکی exchange_rate لە currency_exchange_rates
 * وەرگرتنی دوایین offer_price لە dollar_prices و نوێکردنەوەی exchange_rate بۆ هەموو بەکارهێنەران
 */
function updateExchangeRatesFromDollarPrices() {
    global $conn;
    
    try {
        // وەرگرتنی دوایین offer_price لە dollar_prices (کۆتا last_updated)
        $result = $conn->query("SELECT offer_price FROM dollar_prices ORDER BY last_updated DESC LIMIT 1");
        
        if (!$result || $result->num_rows === 0) {
            return false; // هیچ نرخێک نییە
        }
        
        $row = $result->fetch_assoc();
        $offerPrice = floatval($row['offer_price']);
        
        // وەرگرتنی تەنها 4 ژمارەی سەرەتای (142000 → 1420)
        $baseRate = extractFirstFourDigits($offerPrice);
        
        // وەرگرتنی هەموو بەکارهێنەران لە currency_exchange_rates
        $usersResult = $conn->query("
            SELECT DISTINCT user_id, manual_adjustment 
            FROM currency_exchange_rates 
            WHERE from_currency = 'USD' AND to_currency = 'IQD'
        ");
        
        if ($usersResult && $usersResult->num_rows > 0) {
            while ($userRow = $usersResult->fetch_assoc()) {
                $userId = $userRow['user_id'];
                $manualAdjustment = floatval($userRow['manual_adjustment'] ?? 0);
                
                // حسابکردنی exchange_rate = baseRate + manual_adjustment
                $newExchangeRate = $baseRate + $manualAdjustment;
                
                // نوێکردنەوەی exchange_rate
                $updateStmt = $conn->prepare("
                    UPDATE currency_exchange_rates 
                    SET exchange_rate = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE user_id = ? AND from_currency = 'USD' AND to_currency = 'IQD'
                ");
                $updateStmt->bind_param("di", $newExchangeRate, $userId);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }
        
        // لۆگکردن
        file_put_contents(__DIR__ . '/exchange_rate_updates.log', 
            date('Y-m-d H:i:s') . " - Exchange rates updated: base_rate=$baseRate\n", 
            FILE_APPEND
        );
        
        return true;
    } catch (Exception $e) {
        // لۆگکردنی هەڵە
        file_put_contents(__DIR__ . '/errors.log', 
            date('Y-m-d H:i:s') . " - Exchange rate update error: " . $e->getMessage() . "\n", 
            FILE_APPEND
        );
        return false;
    }
}

/**
 * وەرگرتنی تەنها 4 ژمارەی سەرەتای ژمارەکە
 * بۆ نمونە: 142000 → 1420
 */
function extractFirstFourDigits($number) {
    $numberStr = (string)intval($number); // گۆڕینی بۆ integer و پاشان string
    $firstFour = substr($numberStr, 0, 4); // وەرگرتنی 4 ژمارەی سەرەتای
    return floatval($firstFour); // گەڕاندنەوەی بۆ float
}

/**
 * وەرگرتنی دوایین نرخەکان
 */
function getLatestPrices() {
    global $conn;
    
    $prices = [];
    $result = $conn->query("SELECT city_name, offer_price, DATE_FORMAT(last_updated, '%Y-%m-%d %H:%i:%s') as last_updated FROM dollar_prices ORDER BY last_updated DESC");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $prices[] = $row;
        }
    }
    
    return $prices;
}

/**
 * ناردنی نامە بۆ چات
 */
function sendMessage($chatId, $text) {
    global $apiUrl;

    $url = $apiUrl . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data)
        ]
    ];

    $context = stream_context_create($options);
    file_get_contents($url, false, $context);
}

?>