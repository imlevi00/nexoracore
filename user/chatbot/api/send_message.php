<?php
/**
 * ناردنی پەیام بۆ AI
 * user/chatbot/api/send_message.php
 */
 
require_once '../../../config/config.php';
require_once '../../../config/security.php';

// تاقیکردنی دەسەڵات
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// وەرگرتنی داتا
$input = json_decode(file_get_contents('php://input'), true);

$sessionId = $input['session_id'] ?? null;
$section = $input['section'] ?? null;
$message = trim($input['message'] ?? '');
$csrfToken = $input['csrf_token'] ?? '';

// تاقیکردنی CSRF
if (!Security::validateCSRFToken($csrfToken)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// تاقیکردنی داتا
if (empty($section) || empty($message)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'بەشی پێویست و پەیام پێویستە']);
    exit;
}

// دانانی headers بۆ streaming
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // بۆ nginx

try {
    // تاقیکردنی باڵانس
    $stmt = $conn->prepare("SELECT ai_balance FROM users WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userData = $stmt->get_result()->fetch_assoc();
    $balance = $userData['ai_balance'] ?? 0;
    
    if ($balance <= 0.01) {
        sendSSE('error', ['message' => 'باڵانست تەواوبووە! پەیوەندی بە بەڕێوەبەرەوە بکە.']);
        exit;
    }
    
    // دروستکردن یان وەرگرتنی session
    if (!$sessionId) {
        $stmt = $conn->prepare("INSERT INTO chat_sessions (user_id, section, title) VALUES (?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $title = mb_substr($message, 0, 50);
        $stmt->bind_param("iss", $userId, $section, $title);
        if (!$stmt->execute()) {
            throw new Exception("Failed to create session: " . $stmt->error);
        }
        $sessionId = $conn->insert_id;
    } else {
        // تاقیکردنی خاوەندارێتی session
        $stmt = $conn->prepare("SELECT id FROM chat_sessions WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $sessionId, $userId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            sendSSE('error', ['message' => 'Session نادروستە']);
            exit;
        }
    }
    
    // ناردنی session_id بۆ فرۆنت‌ئێند
    sendSSE('session', ['session_id' => $sessionId]);
    
    // هەڵگرتنی پەیامی بەکارهێنەر
    $stmt = $conn->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'user', ?)");
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    $stmt->bind_param("is", $sessionId, $message);
    if (!$stmt->execute()) {
        throw new Exception("Failed to save message: " . $stmt->error);
    }
    $userMessageId = $conn->insert_id;
    
    // وەرگرتنی داتای بەش
    $contextData = getSectionData($userId, $section, $conn);
    
    // ناردن بۆ Claude API لەگەڵ streaming
    $aiResponse = sendToClaudeAPIStreaming($message, $section, $contextData);
    
    if ($aiResponse['success']) {
        // هەڵگرتنی وەڵامی AI
        $stmt = $conn->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)");
        $responseContent = $aiResponse['response'];
        $stmt->bind_param("is", $sessionId, $responseContent);
        $stmt->execute();
        $assistantMessageId = $conn->insert_id;
        
        // حسابکردن و هەڵگرتنی token usage
        $inputTokens = $aiResponse['input_tokens'];
        $outputTokens = $aiResponse['output_tokens'];
        $totalTokens = $inputTokens + $outputTokens;
        
        // وەرگرتنی نرخەکان
        $stmt = $conn->query("SELECT setting_value FROM ai_settings WHERE setting_key = 'input_token_cost'");
        $inputCost = floatval($stmt->fetch_assoc()['setting_value']);
        
        $stmt = $conn->query("SELECT setting_value FROM ai_settings WHERE setting_key = 'output_token_cost'");
        $outputCost = floatval($stmt->fetch_assoc()['setting_value']);
        
        $totalCost = ($inputTokens * $inputCost) + ($outputTokens * $outputCost);
        
        // هەڵگرتنی usage
        $stmt = $conn->prepare("INSERT INTO chat_usage (session_id, message_id, input_tokens, output_tokens, total_tokens, cost_usd) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiiid", $sessionId, $assistantMessageId, $inputTokens, $outputTokens, $totalTokens, $totalCost);
        $stmt->execute();
        
        // کەمکردنەوەی باڵانس
        $newBalance = $balance - $totalCost;
        $stmt = $conn->prepare("UPDATE users SET ai_balance = ? WHERE id = ?");
        $stmt->bind_param("di", $newBalance, $userId);
        $stmt->execute();
        
        // تۆمارکردنی مێژوو
        $stmt = $conn->prepare("INSERT INTO ai_balance_history (user_id, amount, type, description, balance_before, balance_after, input_tokens, output_tokens) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?)");
        $description = "Chat - {$section}";
        $stmt->bind_param("idsddii", $userId, $totalCost, $description, $balance, $newBalance, $inputTokens, $outputTokens);
        $stmt->execute();
        
        // نوێکردنەوەی session
        $stmt = $conn->prepare("UPDATE chat_sessions SET updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
         
        // ناردنی داتای کۆتایی
        sendSSE('done', [
            'session_id' => $sessionId,
            'new_balance' => $newBalance,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $totalCost
        ]);
    } else {
        sendSSE('error', ['message' => $aiResponse['error'] ?? 'هەڵەیەک ڕوویدا لە AI']);
    }
    
} catch (Exception $e) {
    error_log("Chatbot error: " . $e->getMessage());
    sendSSE('error', ['message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()]);
}

/**
 * ناردنی Server-Sent Event
 */
function sendSSE($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * وەرگرتنی داتای بەشی دیاریکراو
 */
function getSectionData($userId, $section, $conn) {
    $data = [];
    
    switch ($section) {
        case 'products':
            // کاڵاکان - هەموو زانیارییەکانی سەرەکی
            $stmt = $conn->prepare("
                SELECT 
                    p.id, p.name, p.barcode,
                    COALESCE(pu_primary.stock_quantity, pu_any.stock_quantity, 0) as stock_quantity,
                    COALESCE(pu_primary.min_stock, pu_any.min_stock, 0) as min_stock,
                    COALESCE(pu_primary.buy_price, pu_any.buy_price, 0) as buy_price,
                    COALESCE(pu_primary.sell_price, pu_any.sell_price, 0) as sell_price,
                    COALESCE(pu_primary.wholesale_price, pu_any.wholesale_price, 0) as wholesale_price,
                    COALESCE(pu_primary.special_price, pu_any.special_price, 0) as special_price,
                    p.category_id, p.expiry_date, p.image_path,
                    c.name as category_name
                FROM products p
                LEFT JOIN product_units pu_primary ON pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                LEFT JOIN product_units pu_any ON pu_any.id = (
                    SELECT pu2.id
                    FROM product_units pu2
                    WHERE pu2.product_id = p.id
                    ORDER BY pu2.is_primary DESC, pu2.id ASC
                    LIMIT 1
                )
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.user_id = ? 
                LIMIT 100
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['products'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($data['products'] as &$prow) {
                $prow['image_url'] = product_image_url($prow['image_path'] ?? null);
            }
            unset($prow);
            
            // یەکەکانی هەر کاڵایەک (product_units) - پەیوەندی نێوان کاڵا و یەکەکان
            $stmt = $conn->prepare("
                SELECT 
                    pu.id, pu.product_id, pu.unit_id,
                    pu.buy_price, pu.sell_price, pu.wholesale_price, pu.special_price,
                    pu.stock_quantity, pu.min_stock, pu.conversion_ratio,
                    u.name as unit_name, u.name_en as unit_name_en, u.symbol as unit_symbol
                FROM product_units pu
                JOIN units u ON pu.unit_id = u.id
                JOIN products p ON pu.product_id = p.id
                WHERE p.user_id = ?
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['product_units'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // هەموو یەکەکانی بەردەست (units)
            $stmt = $conn->prepare("
                SELECT 
                    id, name, name_en, symbol, is_default, is_active
                FROM units 
                WHERE user_id = ? OR user_id = 0
                ORDER BY is_default DESC, name ASC
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['units'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // کەتەگۆریەکان
            $stmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['categories'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // ئاماری کاڵاکان
            $stmt = $conn->prepare("
                SELECT
                    COUNT(*) as total_products,
                    SUM(
                        CASE WHEN
                            COALESCE(
                                (SELECT pu_primary.stock_quantity FROM product_units pu_primary
                                 WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                                 ORDER BY pu_primary.id ASC LIMIT 1),
                                (SELECT pu_any.stock_quantity FROM product_units pu_any
                                 WHERE pu_any.product_id = p.id
                                 ORDER BY pu_any.id ASC LIMIT 1),
                                0
                            ) <= COALESCE(
                                (SELECT pu_primary.min_stock FROM product_units pu_primary
                                 WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                                 ORDER BY pu_primary.id ASC LIMIT 1),
                                (SELECT pu_any.min_stock FROM product_units pu_any
                                 WHERE pu_any.product_id = p.id
                                 ORDER BY pu_any.id ASC LIMIT 1),
                                0
                            )
                        THEN 1 ELSE 0 END
                    ) as low_stock_count,
                    SUM(
                        CASE WHEN
                            COALESCE(
                                (SELECT pu_primary.stock_quantity FROM product_units pu_primary
                                 WHERE pu_primary.product_id = p.id AND pu_primary.is_primary = 1
                                 ORDER BY pu_primary.id ASC LIMIT 1),
                                (SELECT pu_any.stock_quantity FROM product_units pu_any
                                 WHERE pu_any.product_id = p.id
                                 ORDER BY pu_any.id ASC LIMIT 1),
                                0
                            ) = 0
                        THEN 1 ELSE 0 END
                    ) as out_of_stock_count,
                    SUM(CASE WHEN p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon_count
                FROM products p
                WHERE p.user_id = ?
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['product_stats'] = $stmt->get_result()->fetch_assoc();
            break;
            
        case 'sales':
            // فرۆشتنەکان (دوایین 100)
            $stmt = $conn->prepare("SELECT id, invoice_number, customer_name, total_amount, discount, final_amount, payment_method, sale_date FROM sales WHERE user_id = ? ORDER BY sale_date DESC LIMIT 100");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['sales'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // ئامار
            $stmt = $conn->prepare("SELECT 
                COUNT(*) as total_sales,
                SUM(final_amount) as total_revenue,
                AVG(final_amount) as avg_sale
                FROM sales WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['stats'] = $stmt->get_result()->fetch_assoc();
            break;
            
        case 'debts':
            // قەرزەکان
            $stmt = $conn->prepare("SELECT d.*, c.name as customer_name, c.phone
                FROM debts d
                LEFT JOIN customers c ON d.customer_id = c.id
                WHERE d.user_id =?
                ORDER BY d.created_at DESC
                LIMIT 100");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['debts'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // کورتەی قەرزی کڕیاران
            $stmt = $conn->prepare("SELECT * FROM customer_debt_summary WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['customer_debt_summary'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // ئامار
            $stmt = $conn->prepare("SELECT
                COUNT(*) as total_debts,
                SUM(CASE WHEN status = 'active' THEN remaining_amount ELSE 0 END) as total_remaining,
                SUM(paid_amount) as total_paid
                FROM debts WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $data['stats'] = $stmt->get_result()->fetch_assoc();
            break;
    }
    
    return $data;
}

/**
 * ناردن بۆ Claude API لەگەڵ streaming
 */
function sendToClaudeAPIStreaming($message, $section, $contextData) {
    // وەرگرتنی ڕێکخستنەکان
    global $conn;
    
    $stmt = $conn->query("SELECT setting_value FROM ai_settings WHERE setting_key = 'claude_api_key'");
    $apiKey = $stmt->fetch_assoc()['setting_value'] ?? '';
    
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'API Key دانەنراوە'];
    }
    
    $stmt = $conn->query("SELECT setting_value FROM ai_settings WHERE setting_key = 'claude_model'");
    $model = $stmt->fetch_assoc()['setting_value'] ?? 'claude-3-5-sonnet-20241022';
    
    $stmt = $conn->query("SELECT setting_value FROM ai_settings WHERE setting_key = 'max_tokens_per_request'");
    $maxTokens = intval($stmt->fetch_assoc()['setting_value'] ?? 4096);
    
    // دروستکردنی system prompt
    $systemPrompt = "تۆ زیرەکی دەستکردی NexoraCore ، NexoraCore یەکەمین سیستەمی کاشێری کوردیە کە زیرەکە و بەشی گفتوگۆی زیرەکی دەستکردی تێدایە. ";
    $systemPrompt .= "زیاتر لە 20 بەشی ترت تێدایە وەکو فرۆشتن و کاڵاکان و گەڕاوەکان و بەسەرچووەکان و کەم بووەکان و ڕۆژانە هەڵگرتنی نوسخەی داتا ئۆتۆماتیکی و وەرگرتنی نرخی دۆلار بەنرخی ڕۆژ ئۆتۆماتیکی و گفتگۆکردن لەگەڵ NexoraCore و زۆر زیاتر. ";
    $systemPrompt .= "لە لایەن ستافی Amir Technology وە دروست کراوە AmirTechOne.com. ";
    $systemPrompt .= "بە زمانی کوردی (سۆرانی) وەڵام بدەرەوە. ";
    $systemPrompt .= "لە وەڵامەکەتدا هیچ زانیاریەکی ئینگلیزی مەنوسە وەکو ناوی تەیبڵ و ناوی کۆڵۆم. ";
    $systemPrompt .= "تەنها وەڵامی پرسیاری سەبارەت بە NexoraCore و ئەم سیستەمە بدەرەوە. ";
    
    // ڕوونکردنەوەی پێکهاتەی داتا بۆ بەشی کاڵاکان
    if ($section === 'products') {
        $systemPrompt .= "\n\n## پێکهاتەی داتای کاڵاکان:\n";
        $systemPrompt .= "- **products**: لیستی کاڵاکان لەگەڵ زانیاری سەرەکی (ناو، بارکۆد، کۆ، نرخ، بەروار بەسەرچوون، کەتەگۆری)\n";
        $systemPrompt .= "- **product_units**: یەکەکانی هەر کاڵایەک (نرخی کڕین، نرخی فرۆشتن، نرخی کۆگا، نرخی تایبەت، کۆی ماوە، ڕێژەی گۆڕین)\n";
        $systemPrompt .= "- **units**: تەواوی یەکەکانی بەردەست لە سیستەم (دانە، کارتۆن، قوتی، کیلۆ، لیتر، هتد)\n";
        $systemPrompt .= "- **categories**: کەتەگۆریەکانی کاڵاکان\n";
        $systemPrompt .= "- **product_stats**: ئاماری گشتی (کۆی گشتی، کاڵای کەم، کاڵای تەواوبوو، کاڵای نزیکەی بەسەرچوون)\n";
        $systemPrompt .= "\nکاتێک بەکارهێنەر دەیەوێت لیستی کاڵاکان ببینێت، لەیادت بێت کە هەموو زانیاری پەیوەست لە خوارەوە هەیە لەگەڵ یەکەکانیان.\n";
    }
    $systemPrompt .= "\n\n## ڕێنمایی بۆ پیشاندانی زانیاری بە HTML:\n";
    $systemPrompt .= "کاتێک بەکارهێنەر داوای پیشاندانی زانیاری بە دیزاینەوە یان بە جوانی یان بەخشتە یان بە چارت یان بە کارت یان بە تابڵۆ یان بە وردی دەکات، وەڵامەکەت بە HTML مۆدێرن بنووسە.\n";
    $systemPrompt .= "بۆ وەڵامدانەوە بە HTML، دەبێت کۆدەکەت لە نێوان ```html و ``` بنووسیت.\n";
    $systemPrompt .= "دەتوانیت CSS مۆدێرن بەکاربهێنیت بۆ جوانکاری، ڕەنگ، دیزاین، سێبەر (shadow)، گرادیێنت (gradient)، ستایلکردن و ئەنیمەیشن.\n";
    $systemPrompt .= "هەمیشە direction: rtl بەکاربهێنە بۆ کوردی.\n\n";

    $systemPrompt .= "### جۆرەکانی پیشاندان:\n";
    $systemPrompt .= "1. **چارت و گراف**: بۆ ئامار و ژمارەکان، دەتوانیت bar chart، pie chart، یان line graph بە CSS درووست بکەیت\n";
    $systemPrompt .= "2. **کارتەکان**: بۆ پیشاندانی زانیاری بە شێوەی کارت بە سێبەر و دیزاینی جوان\n";
    $systemPrompt .= "3. **خشتەکان**: بۆ داتای ژمارەیی و لیستی زانیاری\n";
    $systemPrompt .= "4. **تابڵۆی داشبۆرد**: بۆ پیشاندانی چەند زانیاری جیاواز بە یەکەوە\n";
    $systemPrompt .= "5. **تایم‌لاین**: بۆ ڕووداوەکان بە شێوەی کات\n\n";

    $systemPrompt .= "ئەم داتایەی خوارەوە لە سیستەمی بەکارهێنەرەوە هاتووە:\n\n";
    $systemPrompt .= json_encode($contextData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // ناردنی داواکاری لەگەڵ streaming
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    
    $data = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $systemPrompt,
        'messages' => [
            [
                'role' => 'user',
                'content' => $message
            ]
        ],
        'stream' => true  // چالاککردنی streaming
    ];
    
    $fullResponse = '';
    $inputTokens = 0;
    $outputTokens = 0;
    
    // فانکشنێک بۆ وەرگرتنی هەر لاینێک
    $writeFunction = function($ch, $data) use (&$fullResponse, &$inputTokens, &$outputTokens) {
        $lines = explode("\n", $data);
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            // لاینەکانی SSE بە "data: " دەست پێدەکەن
            if (strpos($line, 'data: ') === 0) {
                $json = substr($line, 6);
                
                // پشکنینی [DONE]
                if (trim($json) === '[DONE]') {
                    continue;
                }
                
                $chunk = json_decode($json, true);
                if (!$chunk) continue;
                
                // وەرگرتنی جۆری event
                $type = $chunk['type'] ?? '';
                
                if ($type === 'message_start') {
                    // وەرگرتنی input tokens
                    $inputTokens = $chunk['message']['usage']['input_tokens'] ?? 0;
                    
                } elseif ($type === 'content_block_delta') {
                    // وەرگرتنی تێکستی نوێ
                    $text = $chunk['delta']['text'] ?? '';
                    if ($text) {
                        $fullResponse .= $text;
                        // ناردنی بۆ فرۆنت‌ئێند
                        sendSSE('content', ['text' => $text]);
                    }
                    
                } elseif ($type === 'message_delta') {
                    // وەرگرتنی output tokens
                    $outputTokens = $chunk['usage']['output_tokens'] ?? 0;
                }
            }
        }
        
        return strlen($data);
    };
    
    curl_setopt_array($ch, [
        CURLOPT_WRITEFUNCTION => $writeFunction,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ]
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($result === false) {
        error_log("Claude API Error: " . $error);
        return ['success' => false, 'error' => 'Connection error: ' . $error];
    }
    
    if ($httpCode !== 200) {
        error_log("Claude API Error: HTTP " . $httpCode);
        return ['success' => false, 'error' => 'API Error: ' . $httpCode];
    }
    
    if (empty($fullResponse)) {
        return ['success' => false, 'error' => 'No response from AI'];
    }
    
    return [
        'success' => true,
        'response' => $fullResponse,
        'input_tokens' => $inputTokens,
        'output_tokens' => $outputTokens
    ];
}
