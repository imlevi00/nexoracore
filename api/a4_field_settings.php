<?php
/**
 * API ڕێکخستنەکانی خانەکانی چاپی A4 - api/a4_field_settings.php
 * هەڵگرتن/خوێندنەوەی لیستی خانە هەڵبژێردراوەکان بۆ وەسڵی A4 لە مێژووی فرۆشتن
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/security.php';
require_once '../includes/permissions.php';

/**
 * وەڵامی JSON بنێرە و دەرچوون
 */
function sendA4Response($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success'   => $success,
        'data'      => $data,
        'message'   => $message,
        'timestamp' => date('c')
    ]);
    exit();
}

// تاقیکردنی داخڵبوون
if (!isUser()) {
    sendA4Response(false, null, 'غیر مجاز - داخڵبوون پێویستە', 401);
}

$currentUser = getCurrentUser();
$userId      = $currentUser['id'] ?? null;

if (!$userId) {
    sendA4Response(false, null, 'ناسنامەی بەکارهێنەر نەدۆزرایەوە', 401);
}

enforceAuthorizationOrDeny($currentUser, 'settings.view', [
    'route' => '/api/a4_field_settings.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'json');

/**
 * دڵنیابوون لەوەی خشتەی ڕێکخستنەکان هەیە، ئەگەر نییە دروستی بکە
 */
function ensureA4FieldSettingsTable($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS `user_a4_field_settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL UNIQUE,
            `fields` VARCHAR(255) NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        // تەنیا لۆگ بکە، بەکارهێنەر هێشتا دەتوانێت بە دیفۆلت کاربکات
        error_log('Failed to ensure user_a4_field_settings table: ' . $conn->error);
    }
}

// دڵنیابوون لە خشتەکە
try {
    ensureA4FieldSettingsTable($conn);
} catch (Exception $e) {
    error_log('ensureA4FieldSettingsTable error: ' . $e->getMessage());
}

// ناوەکانی خانە ڕێگەپێدراو
$allowedFields = [
    'product_name',
    'product_image',
    'barcode',
    'quantity',
    'unit',
    'unit_price',
    'total',
    'receipt_number',
    'date',
    'time',
    'customer_info',
    'totals',
    'unit_totals_summary',
    'payment_method',
    'discount',
    'tax',
    'customer_note',
    'sale_note',
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // خوێندنەوەی ڕێکخستنەکان
        $stmt = $conn->prepare("
            SELECT fields 
            FROM user_a4_field_settings 
            WHERE user_id = ? 
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $fields = array_filter(array_map('trim', explode(',', $row['fields'] ?? '')));
            // تەنیا خانە ڕێگەپێدراوەکان بێنەرەوە
            $fields = array_values(array_intersect($fields, $allowedFields));

            if (empty($fields)) {
                // ئەگەر داتای پاشەکەوتکراو بەتاڵ بوو، وەک دیفۆلت مامەڵە بکە (هەموو خانە)
                sendA4Response(true, ['fields' => null]);
            }

            $orderedGet = [];
            foreach ($allowedFields as $allowed) {
                if (in_array($allowed, $fields, true)) {
                    $orderedGet[] = $allowed;
                }
            }

            sendA4Response(true, ['fields' => $orderedGet]);
        }

        // هیچ ڕیزێک نییە، دیفۆلت هەموو خانە نیشان بدرێت
        sendA4Response(true, ['fields' => null]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawBody = file_get_contents('php://input');
        $input   = json_decode($rawBody, true);

        if (!is_array($input)) {
            sendA4Response(false, null, 'داتای نادروست', 400);
        }

        $fields = isset($input['fields']) && is_array($input['fields'])
            ? $input['fields']
            : [];

        // پاککردنەوەی ناوەکان و پشکنینیان
        $normalized = [];
        foreach ($fields as $field) {
            $name = trim((string)$field);
            if (in_array($name, $allowedFields, true)) {
                $normalized[] = $name;
            }
        }

        // ئەگەر هێچ خانەیەک نەما، ڕێکخستن بسڕەوە بۆ ئەو بەکارهێنەرە
        if (empty($normalized)) {
            $deleteStmt = $conn->prepare("
                DELETE FROM user_a4_field_settings 
                WHERE user_id = ?
            ");
            $deleteStmt->bind_param('i', $userId);
            $deleteStmt->execute();

            // دیفۆلت = هەموو خانە، بۆ ئاسانکاری fields => null دەگەڕێنین
            sendA4Response(true, ['fields' => null], 'ڕێکخستنەکان سڕاوەتەوە (دیفۆلت: هەموو خانە)');
        }

        // دروستکردن ناوەکان بە شێوەی سترینگێکی کۆماجیاکراو، بە ڕیزی allowedFields داواکرایەتی
        $ordered = [];
        foreach ($allowedFields as $allowed) {
            if (in_array($allowed, $normalized, true)) {
                $ordered[] = $allowed;
            }
        }

        $fieldsString = implode(',', $ordered);

        $stmt = $conn->prepare("
            INSERT INTO user_a4_field_settings (user_id, fields, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                fields = VALUES(fields),
                updated_at = VALUES(updated_at)
        ");
        $stmt->bind_param('is', $userId, $fieldsString);

        if (!$stmt->execute()) {
            sendA4Response(false, null, 'نەتوانرا ڕێکخستن پاشەکەوت بکرێت', 500);
        }

        sendA4Response(true, ['fields' => $ordered], 'ڕێکخستنەکان پاشەکەوت کران');
    } else {
        sendA4Response(false, null, 'ڕێگەی داواکاری نادروست', 405);
    }
} catch (Exception $e) {
    error_log('A4 field settings API error: ' . $e->getMessage());
    sendA4Response(false, null, 'هەڵەیەک ڕوویدا لە سێرڤەر', 500);
}

