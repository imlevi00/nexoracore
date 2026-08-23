<?php
/**
 * زیادکردنی بابەتی نوێ بۆ دەفتەری تێبینی
 * user/notebooks/add_topic.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = Security::sanitizeInput($_POST['name'] ?? '');
    $description = Security::sanitizeInput($_POST['description'] ?? '');
    $icon = Security::sanitizeInput($_POST['icon'] ?? 'bi-book');
    $color = Security::sanitizeInput($_POST['color'] ?? '#007bff');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // تاقیکردنی CSRF token
    if (!Security::validateCSRFToken($csrf_token)) {
        $errors[] = 'تۆکێنی ئاسایشی نادروستە';
    }
    
    // validation
    if (empty($name)) {
        $errors[] = 'ناوی بابەت پێویستە';
    }
    
    if (strlen($name) > 200) {
        $errors[] = 'ناوی بابەت زۆر درێژە';
    }
    
    // تاقیکردنی دووبارەبوون
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notebook_topics WHERE user_id = ? AND name = ?");
        $stmt->bind_param("is", $userId, $name);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $errors[] = 'بابەتێک بەم ناوە پێشتر بوونی هەیە';
        }
    }
    
    // خەزنکردن
    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO notebook_topics (user_id, name, description, icon, color, sort_order)
                VALUES (?, ?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM notebook_topics nt WHERE nt.user_id = ?))
            ");
            $stmt->bind_param("issssi", $userId, $name, $description, $icon, $color, $userId);
            
            if ($stmt->execute()) {
                $topicId = $conn->insert_id;
                setMessage('بابەتەکە بەسەرکەوتوویی زیادکرا', 'success');
                redirect('manage_fields.php?topic_id=' . $topicId);
            } else {
                $errors[] = 'هەڵەیەک ڕوویدا لە خەزنکردن';
            }
        } catch (Exception $e) {
            $errors[] = 'هەڵەیەکی داتابەیس ڕوویدا';
        }
    }
}

$csrf_token = Security::generateCSRFToken();

// ئایکۆن و ڕەنگەکان
$icons = [
    'bi-book' => 'کتێب',
    'bi-journal-text' => 'جۆرناڵ',
    'bi-note-text' => 'تێبینی',
    'bi-clipboard' => 'کلیپبۆرد',
    'bi-bookmark' => 'نیشانەکراو',
    'bi-file-text' => 'فایل',
    'bi-collection' => 'کۆکراوە',
    'bi-archive' => 'ئەرشیف',
    'bi-folder' => 'فۆڵدەر',
    'bi-list-check' => 'لیستی چێک',
    'bi-graph-up' => 'چارت',
    'bi-people' => 'کەسان',
    'bi-briefcase' => 'کار',
    'bi-house' => 'ماڵ',
    'bi-heart' => 'دڵ',
    'bi-star' => 'ئەستێرە'
];

$colors = [
    '#007bff' => 'شین',
    '#28a745' => 'سەوز',
    '#dc3545' => 'سوور',
    '#ffc107' => 'زەرد',
    '#17a2b8' => 'فیروزی',
    '#6f42c1' => 'مۆر',
    '#fd7e14' => 'پرتەقاڵی',
    '#e83e8c' => 'پەمبە',
    '#6c757d' => 'خاکستەری',
    '#343a40' => 'ڕەش'
];
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>بابەتی نوێ - دەفتەری تێبینی - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    
    <style>
        .form-container {
            background: var(--bs-body-bg);
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 2rem;
        }
        
        .icon-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(60px, 1fr));
            gap: 10px;
        }
        
        .icon-option {
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .icon-option:hover,
        .icon-option.active {
            border-color: var(--bs-primary);
            background-color: rgba(13, 110, 253, 0.1);
        }
        
        .color-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(60px, 1fr));
            gap: 10px;
        }
        
        .color-option {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: 3px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .color-option:hover,
        .color-option.active {
            border-color: #333;
            transform: scale(1.1);
        }
        
        .color-option.active::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.7);
        }
        
        .preview-card {
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }
        
        .preview-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--preview-color, #007bff);
        }
        
        .preview-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: var(--preview-color, #007bff);
        }

        body.dark-mode .form-container,
        [data-bs-theme="dark"] .form-container,
        [data-theme="dark"] .form-container {
            background: #1f2937;
            color: #e5e7eb;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }

        body.dark-mode .icon-option,
        [data-bs-theme="dark"] .icon-option,
        [data-theme="dark"] .icon-option {
            background: #111827;
            border-color: #374151;
            color: #e5e7eb;
        }

        body.dark-mode .icon-option:hover,
        body.dark-mode .icon-option.active,
        [data-bs-theme="dark"] .icon-option:hover,
        [data-bs-theme="dark"] .icon-option.active,
        [data-theme="dark"] .icon-option:hover,
        [data-theme="dark"] .icon-option.active {
            border-color: #60a5fa;
            background-color: rgba(96, 165, 250, 0.18);
        }

        body.dark-mode .color-option,
        [data-bs-theme="dark"] .color-option,
        [data-theme="dark"] .color-option {
            border-color: #4b5563;
        }

        body.dark-mode .color-option:hover,
        body.dark-mode .color-option.active,
        [data-bs-theme="dark"] .color-option:hover,
        [data-bs-theme="dark"] .color-option.active,
        [data-theme="dark"] .color-option:hover,
        [data-theme="dark"] .color-option.active {
            border-color: #f3f4f6;
        }

        body.dark-mode .preview-card,
        [data-bs-theme="dark"] .preview-card,
        [data-theme="dark"] .preview-card {
            background: #111827;
            border: 1px solid #374151;
        }
    </style>
</head>
<body class="notebooks-module-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>
    
    <div class="container mt-4 hub-page-content">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <!-- Header -->
                <div class="d-flex align-items-center mb-4">
                    <a href="index.php" class="btn btn-outline-secondary me-3">
                        <i class="bi bi-arrow-right"></i>
                    </a>
                    <h2 class="mb-0">
                        <i class="bi bi-plus-lg"></i>
                        بابەتی نوێ زیاد بکە
                    </h2>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <div class="row g-4">
                    <!-- فۆرمی زیادکردن -->
                    <div class="col-md-7">
                        <div class="form-container">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <!-- ناوی بابەت -->
                                <div class="mb-4">
                                    <label for="name" class="form-label">ناوی بابەت <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-lg" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                           placeholder="بۆ نموونە: زانیاری کڕیارەکان" required>
                                </div>
                                
                                <!-- وەسف -->
                                <div class="mb-4">
                                    <label for="description" class="form-label">وەسف</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" 
                                              placeholder="وەسفێکی کورت بۆ ئەم بابەتە..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                </div>
                                
                                <!-- ئایکۆن -->
                                <div class="mb-4">
                                    <label class="form-label">ئایکۆن</label>
                                    <div class="icon-selector">
                                        <?php foreach ($icons as $iconClass => $iconName): ?>
                                            <div class="icon-option <?php echo (($_POST['icon'] ?? 'bi-book') === $iconClass) ? 'active' : ''; ?>" 
                                                 onclick="selectIcon('<?php echo $iconClass; ?>')">
                                                <i class="<?php echo $iconClass; ?> fs-4"></i>
                                                <div class="small mt-1"><?php echo $iconName; ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="icon" id="selected_icon" value="<?php echo htmlspecialchars($_POST['icon'] ?? 'bi-book'); ?>">
                                </div>
                                
                                <!-- ڕەنگ -->
                                <div class="mb-4">
                                    <label class="form-label">ڕەنگ</label>
                                    <div class="color-selector">
                                        <?php foreach ($colors as $colorCode => $colorName): ?>
                                            <div class="color-option <?php echo (($_POST['color'] ?? '#007bff') === $colorCode) ? 'active' : ''; ?>" 
                                                 style="background-color: <?php echo $colorCode; ?>"
                                                 onclick="selectColor('<?php echo $colorCode; ?>')"
                                                 title="<?php echo $colorName; ?>">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="color" id="selected_color" value="<?php echo htmlspecialchars($_POST['color'] ?? '#007bff'); ?>">
                                </div>
                                
                                <!-- دوگمەکان -->
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-check-lg"></i>
                                        دروستکردنی بابەت
                                    </button>
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-lg"></i>
                                        هەڵوەشاندنەوە
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- پێشبینی -->
                    <div class="col-md-5">
                        <div class="position-sticky" style="top: 20px;">
                            <h5 class="mb-3">پێشبینی</h5>
                            <div class="preview-card card" 
                                 style="--preview-color: <?php echo htmlspecialchars($_POST['color'] ?? '#007bff'); ?>;">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between mb-3">
                                        <div class="preview-icon" id="preview_icon">
                                            <i class="<?php echo htmlspecialchars($_POST['icon'] ?? 'bi-book'); ?>"></i>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-link text-muted p-1" disabled>
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <h5 class="card-title mb-2" id="preview_name">
                                        <?php echo htmlspecialchars($_POST['name'] ?? 'ناوی بابەت'); ?>
                                    </h5>
                                    
                                    <p class="text-muted small mb-3" id="preview_description">
                                        <?php echo htmlspecialchars($_POST['description'] ?? 'وەسفی بابەت لێرە دەردەکەوێت...'); ?>
                                    </p>
                                    
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="small text-muted">تێبینی</div>
                                            <div class="fw-bold">0</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="small text-muted">خانە</div>
                                            <div class="fw-bold">0</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="small text-muted">نوێ</div>
                                            <div class="fw-bold">✓</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectIcon(iconClass) {
            // وەختی گۆڕینی ئایکۆن
            document.querySelectorAll('.icon-option').forEach(el => el.classList.remove('active'));
            event.target.closest('.icon-option').classList.add('active');
            document.getElementById('selected_icon').value = iconClass;
            document.querySelector('#preview_icon i').className = iconClass;
        }
        
        function selectColor(colorCode) {
            // وەختی گۆڕینی ڕەنگ
            document.querySelectorAll('.color-option').forEach(el => el.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById('selected_color').value = colorCode;
            document.documentElement.style.setProperty('--preview-color', colorCode);
            document.querySelector('.preview-card').style.setProperty('--preview-color', colorCode);
            document.querySelector('.preview-icon').style.backgroundColor = colorCode;
        }
        
        // پێشبینی لەگەڵ نووسین
        document.getElementById('name').addEventListener('input', function() {
            const preview = document.getElementById('preview_name');
            preview.textContent = this.value || 'ناوی بابەت';
        });
        
        document.getElementById('description').addEventListener('input', function() {
            const preview = document.getElementById('preview_description');
            preview.textContent = this.value || 'وەسفی بابەت لێرە دەردەکەوێت...';
        });
    </script>
</body>
</html>