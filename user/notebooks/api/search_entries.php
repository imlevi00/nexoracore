<?php
// ===============================================
// user/notebooks/api/search_entries.php
// ===============================================
require_once '../../../config/config.php';
require_once '../../../config/security.php';

SessionManager::requireAuth('user');
header('Content-Type: application/json');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');
$topicId = (int)($input['topic_id'] ?? 0);

if (empty($query)) {
    echo json_encode(['success' => false, 'message' => 'Query is required']);
    exit;
}

try {
    $searchQuery = "%$query%";
    
    $sql = "
        SELECT e.*, t.name as topic_name, t.icon as topic_icon, t.color as topic_color
        FROM notebook_entries e
        INNER JOIN notebook_topics t ON e.topic_id = t.id
        WHERE e.user_id = ? 
        AND e.is_archived = 0
        AND (e.title LIKE ? OR e.entry_data LIKE ? OR e.tags LIKE ?)
    ";
    
    $params = [$userId, $searchQuery, $searchQuery, $searchQuery];
    $types = 'isss';
    
    if ($topicId > 0) {
        $sql .= " AND e.topic_id = ?";
        $params[] = $topicId;
        $types .= 'i';
    }
    
    $sql .= " ORDER BY e.created_at DESC LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $entries = [];
    
    while ($row = $result->fetch_assoc()) {
        $entries[] = [
            'id' => (int)$row['id'],
            'topic_id' => (int)$row['topic_id'],
            'title' => $row['title'],
            'topic_name' => $row['topic_name'],
            'topic_icon' => $row['topic_icon'],
            'topic_color' => $row['topic_color'],
            'is_favorite' => (bool)$row['is_favorite'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'total' => count($entries)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

