<?php
/**
 * بینینی تێبینیەکانی بابەت
 * user/notebooks/view_topic.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$topicId = (int)($_GET['id'] ?? 0);

if (!$topicId) {
    setMessage('بابەت دۆزرایەوە', 'error');
    redirect('index.php');
}

// وەرگرتنی بابەت
$stmt = $conn->prepare("SELECT * FROM notebook_topics WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $topicId, $userId);
$stmt->execute();
$topic = $stmt->get_result()->fetch_assoc();

if (!$topic) {
    setMessage('بابەت دۆزرایەوە یان دەسەڵاتت نییە', 'error');
    redirect('index.php');
}

// وەرگرتنی خانەکان
$stmt = $conn->prepare("
    SELECT * FROM notebook_fields 
    WHERE topic_id = ? AND user_id = ? 
    ORDER BY field_order, created_at
");
$stmt->bind_param("ii", $topicId, $userId);
$stmt->execute();
$fields = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی تێبینیەکان
$page = (int)($_GET['page'] ?? 1);
$limit = 10;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM notebook_entries 
    WHERE topic_id = ? AND user_id = ? AND is_archived = 0
");
$stmt->bind_param("ii", $topicId, $userId);
$stmt->execute();
$totalEntries = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalEntries / $limit);

$stmt = $conn->prepare("
    SELECT * FROM notebook_entries 
    WHERE topic_id = ? AND user_id = ? AND is_archived = 0
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iiii", $topicId, $userId, $limit, $offset);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($topic['name']); ?> - دەفتەری تێبینی - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    
    <style>
        .topic-header {
            background: linear-gradient(135deg, <?php echo htmlspecialchars($topic['color']); ?>, <?php echo htmlspecialchars($topic['color']); ?>dd);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .entry-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .entry-card:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }
        
        .entry-field {
            border-bottom: 1px solid #f8f9fa;
            padding: 0.75rem 0;
        }
        
        .entry-field:last-child {
            border-bottom: none;
        }
        
        .field-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }
        
        .field-value {
            color: #212529;
            white-space: pre-wrap;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .stats-bar {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .floating-add-btn {
            position: fixed;
            bottom: 2rem;
            left: 2rem;
            z-index: 1000;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
    </style>
</head>
<body class="notebooks-module-page notebooks-topic-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>
    
    <div class="container mt-4 hub-page-content">
        <!-- Header -->
        <div class="topic-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center">
                        <a href="index.php" class="btn btn-light btn-sm me-3">
                            <i class="bi bi-arrow-right"></i>
                        </a>
                        <div>
                            <h1 class="mb-1">
                                <i class="<?php echo htmlspecialchars($topic['icon']); ?> me-2"></i>
                                <?php echo htmlspecialchars($topic['name']); ?>
                            </h1>
                            <?php if (!empty($topic['description'])): ?>
                                <p class="mb-0 opacity-75"><?php echo htmlspecialchars($topic['description']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="manage_fields.php?topic_id=<?php echo $topicId; ?>" class="btn btn-light me-2">
                        <i class="bi bi-gear"></i>
                        خانەکان
                    </a>
                    <?php if (!empty($fields)): ?>
                        <a href="add_entry.php?topic_id=<?php echo $topicId; ?>" class="btn btn-success">
                            <i class="bi bi-plus-lg"></i>
                            تێبینی نوێ
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- آمار -->
            <div class="stats-bar">
                <div class="row text-center">
                    <div class="col-3">
                        <div class="h5 mb-1"><?php echo count($entries); ?></div>
                        <small>لەم پەڕەدا</small>
                    </div>
                    <div class="col-3">
                        <div class="h5 mb-1"><?php echo $totalEntries; ?></div>
                        <small>کۆی گشتی</small>
                    </div>
                    <div class="col-3">
                        <div class="h5 mb-1"><?php echo count($fields); ?></div>
                        <small>خانە</small>
                    </div>
                    <div class="col-3">
                        <div class="h5 mb-1"><?php echo $totalPages; ?></div>
                        <small>پەڕە</small>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        $message = getMessage();
        if ($message):
        ?>
            <div class="alert alert-<?php echo $message['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($message['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- ناوەڕۆک -->
        <?php if (empty($fields)): ?>
            <div class="empty-state">
                <i class="bi bi-grid"></i>
                <h4>هیچ خانەیەک دروستنەکراوە</h4>
                <p class="mb-4">پێش زیادکردنی تێبینی، دەبێت خانەکان دروست بکەیت</p>
                <a href="manage_fields.php?topic_id=<?php echo $topicId; ?>" class="btn btn-primary btn-lg">
                    <i class="bi bi-gear"></i>
                    خانەکان بەڕێوەبەرە
                </a>
            </div>
        <?php elseif (empty($entries)): ?>
            <div class="empty-state">
                <i class="bi bi-journal-plus"></i>
                <h4>هیچ تێبینیەک نووسراوە</h4>
                <p class="mb-4">یەکەم تێبینیت بنووسە بۆ ئەم بابەتە</p>
                <a href="add_entry.php?topic_id=<?php echo $topicId; ?>" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-lg"></i>
                    یەکەم تێبینی
                </a>
            </div>
        <?php else: ?>
            <!-- تێبینیەکان -->
            <div class="row">
                <?php foreach ($entries as $entry): ?>
                    <?php 
                    $entryData = json_decode($entry['entry_data'], true) ?? [];
                    ?>
                    <div class="col-lg-6 col-xl-4">
                        <div class="entry-card card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <h6 class="card-title mb-0">
                                        <?php if (!empty($entry['title'])): ?>
                                            <?php echo htmlspecialchars($entry['title']); ?>
                                        <?php else: ?>
                                            تێبینی #<?php echo $entry['id']; ?>
                                        <?php endif; ?>
                                        <?php if ($entry['is_favorite']): ?>
                                            <i class="bi bi-star-fill text-warning ms-1"></i>
                                        <?php endif; ?>
                                    </h6>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-link text-muted p-1" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="edit_entry.php?id=<?php echo $entry['id']; ?>">
                                                <i class="bi bi-pencil me-2"></i>دەستکاری</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="toggleFavorite(<?php echo $entry['id']; ?>)">
                                                <i class="bi bi-star me-2"></i><?php echo $entry['is_favorite'] ? 'لابردن لە نیشانەکراو' : 'نیشانەکردن'; ?></a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteEntry(<?php echo $entry['id']; ?>)">
                                                <i class="bi bi-trash me-2"></i>سڕینەوە</a></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <!-- خانەکان -->
                                <?php foreach ($fields as $field): ?>
                                    <?php 
                                    $fieldValue = $entryData[$field['id']] ?? ($field['default_value'] ?? '');
                                    if (!empty($fieldValue) || $field['field_type'] === 'number'):
                                    ?>
                                        <div class="entry-field">
                                            <div class="field-label"><?php echo htmlspecialchars($field['field_name']); ?></div>
                                            <div class="field-value">
                                                <?php
                                                switch ($field['field_type']) {
                                                    case 'number':
                                                        echo number_format((float)$fieldValue);
                                                        break;
                                                    case 'date':
                                                        if (!empty($fieldValue)) {
                                                            echo date('Y/m/d', strtotime($fieldValue));
                                                        }
                                                        break;
                                                    case 'time':
                                                        if (!empty($fieldValue)) {
                                                            echo date('H:i', strtotime($fieldValue));
                                                        }
                                                        break;
                                                    case 'datetime':
                                                        if (!empty($fieldValue)) {
                                                            echo date('Y/m/d H:i', strtotime($fieldValue));
                                                        }
                                                        break;
                                                    default:
                                                        echo htmlspecialchars($fieldValue);
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- بەروار -->
                                <div class="text-muted small mt-3">
                                    <i class="bi bi-clock"></i>
                                    <?php echo date('Y/m/d H:i', strtotime($entry['created_at'])); ?>
                                    <?php if ($entry['updated_at'] !== $entry['created_at']): ?>
                                        <span class="ms-2">
                                            <i class="bi bi-pencil"></i>
                                            <?php echo date('Y/m/d H:i', strtotime($entry['updated_at'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- صفحەبەندی -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="pagination" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $topicId; ?>&page=<?php echo $page - 1; ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?id=<?php echo $topicId; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?id=<?php echo $topicId; ?>&page=<?php echo $page + 1; ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- دوگمەی زیادکردنی شەنم -->
    <?php if (!empty($fields)): ?>
        <a href="add_entry.php?topic_id=<?php echo $topicId; ?>" class="btn btn-primary floating-add-btn">
            <i class="bi bi-plus-lg"></i>
        </a>
    <?php endif; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteEntry(entryId) {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم تێبینیەیە؟')) {
                fetch('api/delete_entry.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        entry_id: entryId,
                        csrf_token: '<?php echo $csrf_token; ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'هەڵەیەک ڕوویدا');
                    }
                });
            }
        }
        
        function toggleFavorite(entryId) {
            fetch('api/toggle_favorite.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    entry_id: entryId,
                    csrf_token: '<?php echo $csrf_token; ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'هەڵەیەک ڕوویدا');
                }
            });
        }
    </script>
</body>
</html>