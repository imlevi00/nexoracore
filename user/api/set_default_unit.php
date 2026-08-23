<?php
/**
 * Set the user's default unit (used when adding new products, etc.)
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

try {
    $unitId = (int)($_POST['unit_id'] ?? 0);

    if ($unitId <= 0) {
        echo json_encode(['success' => false, 'message' => 'یەکە دیارینەکراوە']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, name FROM units WHERE id = ? AND user_id = ? AND is_active = 1");
    $stmt->bind_param("ii", $unitId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'یەکەکە نەدۆزرایەوە']);
        exit;
    }

    $unit = $result->fetch_assoc();
    $stmt->close();

    $conn->begin_transaction();

    $stmt = $conn->prepare("UPDATE units SET is_default = 0 WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE units SET is_default = 1, updated_at = NOW() WHERE id = ? AND user_id = ? AND is_active = 1");
    $stmt->bind_param("ii", $unitId, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'نەتوانرا یەکەی بنەڕەتی نوێ بکرێت']);
        exit;
    }

    $conn->commit();

    writeLog("Default unit set: {$unit['name']} (ID: $unitId) by user: {$currentUser['email']}");

    echo json_encode([
        'success' => true,
        'message' => 'یەکەی بنەڕەتی بە سەرکەوتوویی پاشەکەوتکرا',
        'unit_id' => $unitId,
        'unit' => [
            'id' => (int)$unit['id'],
            'name' => $unit['name'],
            'is_default' => 1,
        ],
    ]);
} catch (Exception $e) {
    try {
        $conn->rollback();
    } catch (Throwable $t) {
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا: ' . $e->getMessage()]);
}
