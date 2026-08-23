<?php

// ===============================================
// user/notebooks/api/get_topic_stats.php  
// ===============================================
require_once '../../../config/config.php';
require_once '../../../config/security.php';

SessionManager::requireAuth('user');
header('Content-Type: application/json');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$topicId = (int)($_GET['topic_id'] ?? 0);

try {
    if ($topicId > 0) {
        // آمار بابەتێکی تایبەت
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_entries,
                COUNT(CASE WHEN is_favorite = 1 THEN 1 END) as favorite_entries,
                COUNT(CASE WHEN created_at >= CURDATE() THEN 1 END) as today_entries,
                COUNT(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_entries
            FROM notebook_entries 
            WHERE topic_id = ? AND user_id = ? AND is_archived = 0
        ");
        $stmt->bind_param('ii', $topicId, $userId);
    } else {
        // آماری گشتی
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_entries,
                COUNT(CASE WHEN is_favorite = 1 THEN 1 END) as favorite_entries,
                COUNT(CASE WHEN created_at >= CURDATE() THEN 1 END) as today_entries,
                COUNT(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_entries
            FROM notebook_entries 
            WHERE user_id = ? AND is_archived = 0
        ");
        $stmt->bind_param('i', $userId);
    }
    
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    // آماری بابەتەکان
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total_topics
        FROM notebook_topics 
        WHERE user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $topicStats = $stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_entries' => (int)$stats['total_entries'],
            'favorite_entries' => (int)$stats['favorite_entries'],
            'today_entries' => (int)$stats['today_entries'],
            'week_entries' => (int)$stats['week_entries'],
            'total_topics' => (int)$topicStats['total_topics']
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>