<?php
/**
 * دفتەری تێبینی - بەشی سەرەکی
 * user/notebooks/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';

// تاقیکردنەوەی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'notebooks.view', [
    'route' => '/user/notebooks/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

// وەرگرتنی بابەتەکان
$stmt = $conn->prepare("
    SELECT t.*, 
           COUNT(e.id) as entries_count,
           COUNT(f.id) as fields_count
    FROM notebook_topics t
    LEFT JOIN notebook_entries e ON t.id = e.topic_id AND e.is_archived = 0
    LEFT JOIN notebook_fields f ON t.id = f.topic_id
    WHERE t.user_id = ?
    GROUP BY t.id
    ORDER BY t.sort_order, t.created_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$topics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// وەرگرتنی کۆی گشتی تێبینیەکان
$stmt = $conn->prepare("
    SELECT COUNT(*) as total_entries
    FROM notebook_entries 
    WHERE user_id = ? AND is_archived = 0
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$totalStats = $stmt->get_result()->fetch_assoc();

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>دەفتەری تێبینی - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#6f42c1">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    
    <style>
        :root {
            --notebook-primary: #6f42c1;
            --notebook-secondary: #563d7c;
            --notebook-accent: #e7f3ff;
        }
        
        .notebook-header {
            background: linear-gradient(135deg, var(--notebook-primary), var(--notebook-secondary));
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .topic-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        
        .topic-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
        }
        
        .topic-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--topic-color, var(--notebook-primary));
        }
        
        .topic-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin-bottom: 15px;
            background: var(--topic-color, var(--notebook-primary));
        }
        
        .stats-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body class="notebooks-module-page notebooks-hub-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>
    
    <div class="container-fluid mt-4 hub-page-content">
        <!-- Header -->
        <div class="notebook-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="bi bi-book"></i>
                        دەفتەری تێبینی
                    </h1>
                    <p class="mb-0 opacity-75">بەڕێوەبردنی زانیاری و تێبینیەکانت بە شێوەیەکی ڕێکخراو</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="add_topic.php" class="btn btn-light btn-lg">
                        <i class="bi bi-plus-lg"></i>
                        بابەتی نوێ
                    </a>
                </div>
            </div>
        </div>
        
        <div class="row g-4">
            <!-- آمارها -->
            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo count($topics); ?></h3>
                            <p class="mb-0">بابەت</p>
                        </div>
                        <i class="bi bi-bookmark-star fs-1 opacity-75"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo $totalStats['total_entries']; ?></h3>
                            <p class="mb-0">تێبینی</p>
                        </div>
                        <i class="bi bi-journal-text fs-1 opacity-75"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo array_sum(array_column($topics, 'fields_count')); ?></h3>
                            <p class="mb-0">خانە</p>
                        </div>
                        <i class="bi bi-grid fs-1 opacity-75"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3 class="mb-1"><?php echo date('m/d'); ?></h3>
                            <p class="mb-0">ئەمڕۆ</p>
                        </div>
                        <i class="bi bi-calendar-date fs-1 opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- بابەتەکان -->
        <div class="row g-4 mt-2">
            <?php if (empty($topics)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="bi bi-book"></i>
                        <h4>هێشتا هیچ بابەتێکت دروستنەکردووە</h4>
                        <p class="mb-4">دەستپێک بکە بە دروستکردنی یەکەم بابەتەکەت بۆ ڕێکخستنی تێبینیەکانت</p>
                        <a href="add_topic.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-plus-lg"></i>
                            یەکەم بابەت دروست بکە
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($topics as $topic): ?>
                    <div class="col-lg-4 col-md-6">
                        <div class="topic-card card h-100" style="--topic-color: <?php echo htmlspecialchars($topic['color']); ?>;">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div class="topic-icon" style="background: <?php echo htmlspecialchars($topic['color']); ?>;">
                                        <i class="<?php echo htmlspecialchars($topic['icon']); ?>"></i>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-link text-muted p-1" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="view_topic.php?id=<?php echo $topic['id']; ?>">
                                                <i class="bi bi-eye me-2"></i>بینین</a></li>
                                            <li><a class="dropdown-item" href="manage_fields.php?topic_id=<?php echo $topic['id']; ?>">
                                                <i class="bi bi-gear me-2"></i>بەڕێوەبردنی خانەکان</a></li>
                                            <li><a class="dropdown-item" href="edit_topic.php?id=<?php echo $topic['id']; ?>">
                                                <i class="bi bi-pencil me-2"></i>دەستکاری</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteTopic(<?php echo $topic['id']; ?>)">
                                                <i class="bi bi-trash me-2"></i>سڕینەوە</a></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <h5 class="card-title mb-2"><?php echo htmlspecialchars($topic['name']); ?></h5>
                                
                                <?php if (!empty($topic['description'])): ?>
                                    <p class="text-muted small mb-3"><?php echo htmlspecialchars(substr($topic['description'], 0, 100)); ?><?php echo strlen($topic['description']) > 100 ? '...' : ''; ?></p>
                                <?php endif; ?>
                                
                                <div class="row text-center mt-auto">
                                    <div class="col-4">
                                        <div class="small text-muted">تێبینی</div>
                                        <div class="fw-bold"><?php echo $topic['entries_count']; ?></div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">خانە</div>
                                        <div class="fw-bold"><?php echo $topic['fields_count']; ?></div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">دروستکراو</div>
                                        <div class="fw-bold"><?php echo date('m/d', strtotime($topic['created_at'])); ?></div>
                                    </div>
                                </div>
                                
                                <div class="mt-3 d-grid gap-2">
                                    <a href="view_topic.php?id=<?php echo $topic['id']; ?>" class="btn btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                        بینینی تێبینیەکان
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteTopic(topicId) {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم بابەتە؟\nهەموو تێبینیەکانی ئەم بابەتە دەسڕدرێنەوە!')) {
                fetch('api/delete_topic.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        topic_id: topicId,
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
                })
                .catch(error => {
                });
            }
        }
    </script>
</body>
</html>