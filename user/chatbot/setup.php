<?php
/**
 * دامەزراندنی سیستەمی AI Chatbot
 * user/chatbot/setup.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەڕێوەبەر
SessionManager::requireAuth('admin');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // خوێندنەوەی فایلی migration
        $sqlFile = '../../database/migrations/add_ai_chatbot.sql';
        
        if (!file_exists($sqlFile)) {
            throw new Exception('فایلی migration نەدۆزرایەوە');
        }
        
        $sql = file_get_contents($sqlFile);
        
        // جیاکردنەوەی queries
        $queries = array_filter(
            array_map('trim', explode(';', $sql)),
            function($query) {
                return !empty($query) && !str_starts_with($query, '--');
            }
        );
        
        // ئەجراکردنی queries
        $conn->begin_transaction();
        
        foreach ($queries as $query) {
            if (!empty($query)) {
                if (!$conn->query($query)) {
                    throw new Exception('هەڵە لە ئەجراکردنی query: ' . $conn->error);
                }
            }
        }
        
        $conn->commit();
        $message = 'سیستەمی AI Chatbot بە سەرکەوتوویی دامەزرا! ';
        
        // تاقیکردنەوەی خشتەکان
        $tables = ['ai_chat_sessions', 'ai_chat_messages', 'ai_usage_logs'];
        $existingTables = [];
        
        foreach ($tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result->num_rows > 0) {
                $existingTables[] = $table;
            }
        }
        
        $message .= 'خشتەکانی دروستکراو: ' . implode(', ', $existingTables);
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = 'هەڵە: ' . $e->getMessage();
    }
}

// تاقیکردنەوەی دۆخی دامەزراندن
$isInstalled = false;
$result = $conn->query("SHOW TABLES LIKE 'ai_chat_sessions'");
if ($result && $result->num_rows > 0) {
    $isInstalled = true;
}

?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title>دامەزراندنی AI Chatbot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="chatbot-module-page bg-body-secondary">
    <div class="container mt-5 hub-page-content">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">دامەزراندنی سیستەمی AI Chatbot</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-success"><?php echo $message; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($isInstalled): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-check-circle"></i>
                                سیستەمی AI Chatbot پێشتر دامەزراوە.
                            </div>
                            
                            <h5>زانیاری سیستەم:</h5>
                            <ul>
                                <li>خشتەی چاتەکان: ai_chat_sessions ✓</li>
                                <li>خشتەی پەیامەکان: ai_chat_messages ✓</li>
                                <li>خشتەی لۆگەکان: ai_usage_logs ✓</li>
                            </ul>
                            
                            <div class="mt-4">
                                <a href="index.php" class="btn btn-success">چوونە ناو AI Chatbot</a>
                                <a href="../../user/dashboard/index.php" class="btn btn-secondary">گەڕانەوە بۆ داشبۆرد</a>
                            </div>
                        <?php else: ?>
                            <p>ئەم فۆرمە بەکاربهێنە بۆ دامەزراندنی سیستەمی AI Chatbot</p>
                            
                            <h5>ئەم تایبەتمەندیانە زیاد دەکرێت:</h5>
                            <ul>
                                <li>خشتەی ai_chat_sessions - بۆ خەزنکردنی چاتەکان</li>
                                <li>خشتەی ai_chat_messages - بۆ خەزنکردنی پەیامەکان</li>
                                <li>خشتەی ai_usage_logs - بۆ تۆمارکردنی بەکارهێنان</li>
                                <li>ستوونی ai_balance لە خشتەی users</li>
                            </ul>
                            
                            <form method="POST" class="mt-4">
                                <button type="submit" class="btn btn-primary">دامەزراندنی سیستەم</button>
                                <a href="../../user/dashboard/index.php" class="btn btn-secondary">پاشگەزبوونەوە</a>
                            </form>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <h5>ڕێنمایی:</h5>
                        <div class="alert alert-warning">
                            <strong>تێبینی:</strong> بۆ کارکردنی تەواوی AI Chatbot، پێویستە OpenAI API key دابنێیت.
                            دەتوانیت لە فایلی .env یان وەک environment variable دایبنێیت:
                            <code>OPENAI_API_KEY=your-api-key-here</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

