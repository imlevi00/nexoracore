<?php
/**
 * زیادکردنی تێبینی نوێ
 * user/notebooks/add_entry.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$topicId = (int)($_GET['topic_id'] ?? 0);

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

if (empty($fields)) {
    setMessage('پێش زیادکردنی تێبینی، دەبێت خانەکان دروست بکەیت', 'error');
    redirect('manage_fields.php?topic_id=' . $topicId);
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = Security::sanitizeInput($_POST['title'] ?? '');
    $tags = Security::sanitizeInput($_POST['tags'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrf_token)) {
        $errors[] = 'تۆکێنی ئاسایشی نادروستە';
    }
    
    // validation خانەکان
    $entryData = [];
    foreach ($fields as $field) {
        $fieldValue = $_POST['field_' . $field['id']] ?? '';
        
        if ($field['is_required'] && empty($fieldValue)) {
            $errors[] = 'خانەی "' . $field['field_name'] . '" پێویستە';
            continue;
        }
        
        // validation بەپێی جۆری خانە
        switch ($field['field_type']) {
            case 'number':
                if (!empty($fieldValue) && !is_numeric($fieldValue)) {
                    $errors[] = 'خانەی "' . $field['field_name'] . '" دەبێت ژمارە بێت';
                }
                break;
            case 'date':
                if (!empty($fieldValue) && !strtotime($fieldValue)) {
                    $errors[] = 'فۆرماتی بەرواری خانەی "' . $field['field_name'] . '" هەڵەیە';
                }
                break;
        }
        
        $entryData[$field['id']] = Security::sanitizeInput($fieldValue);
    }
    
    // خەزنکردن
    if (empty($errors)) {
        try {
            $entryDataJson = json_encode($entryData);
            
            $stmt = $conn->prepare("
                INSERT INTO notebook_entries (topic_id, user_id, title, entry_data, tags)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisss", $topicId, $userId, $title, $entryDataJson, $tags);
            
            if ($stmt->execute()) {
                setMessage('تێبینیەکە بەسەرکەوتوویی زیادکرا', 'success');
                redirect('view_topic.php?id=' . $topicId);
            } else {
                $errors[] = 'هەڵەیەک ڕوویدا لە خەزنکردن';
            }
        } catch (Exception $e) {
            $errors[] = 'هەڵەیەکی داتابەیس ڕوویدا';
        }
    }
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>تێبینی نوێ - <?php echo htmlspecialchars($topic['name']); ?> - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    
    <style>
        .topic-header {
            background: linear-gradient(135deg, <?php echo htmlspecialchars($topic['color']); ?>, <?php echo htmlspecialchars($topic['color']); ?>dd);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .form-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 2rem;
        }
        
        .field-group {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid <?php echo htmlspecialchars($topic['color']); ?>;
        }
        
        .field-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #495057;
        }
        
        .required-field::after {
            content: ' *';
            color: #dc3545;
        }
        
        .field-help {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        .save-buttons {
            position: sticky;
            bottom: 20px;
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 -4px 6px rgba(0, 0, 0, 0.1);
            margin-top: 2rem;
        }
    </style>
</head>
<body class="notebooks-module-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>
    
    <div class="container mt-4 hub-page-content">
        <!-- Header -->
        <div class="topic-header">
            <div class="d-flex align-items-center">
                <a href="view_topic.php?id=<?php echo $topicId; ?>" class="btn btn-light btn-sm me-3">
                    <i class="bi bi-arrow-right"></i>
                </a>
                <div>
                    <h2 class="mb-1">
                        <i class="bi bi-plus-lg me-2"></i>
                        تێبینی نوێ
                    </h2>
                    <p class="mb-0 opacity-75">
                        <i class="<?php echo htmlspecialchars($topic['icon']); ?> me-1"></i>
                        <?php echo htmlspecialchars($topic['name']); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h6><i class="bi bi-exclamation-triangle"></i> هەڵەکان:</h6>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="form-container">
                    <form method="POST" id="entryForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <!-- ناونیشان -->
                        <div class="mb-4">
                            <label for="title" class="field-label">ناونیشانی تێبینی</label>
                            <input type="text" class="form-control form-control-lg" id="title" name="title" 
                                   value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                                   placeholder="ناونیشانێک بۆ ئەم تێبینیەیە...">
                            <div class="field-help">
                                <i class="bi bi-info-circle"></i>
                                ئەگەر ناونیشان دانەنێیت، ژمارەی تێبینیەکە دەردەکەوێت
                            </div>
                        </div>
                        
                        <!-- خانەکان -->
                        <?php foreach ($fields as $index => $field): ?>
                            <div class="field-group">
                                <label for="field_<?php echo $field['id']; ?>" 
                                       class="field-label <?php echo $field['is_required'] ? 'required-field' : ''; ?>">
                                    <?php echo htmlspecialchars($field['field_name']); ?>
                                </label>
                                
                                <?php
                                $fieldValue = $_POST['field_' . $field['id']] ?? $field['default_value'] ?? '';
                                $placeholder = $field['placeholder'] ?? '';
                                $fieldId = 'field_' . $field['id'];
                                $required = $field['is_required'] ? 'required' : '';
                                ?>
                                
                                <?php switch ($field['field_type']):
                                    case 'text_short': ?>
                                        <input type="text" class="form-control" id="<?php echo $fieldId; ?>" 
                                               name="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" 
                                               placeholder="<?php echo htmlspecialchars($placeholder); ?>" <?php echo $required; ?>>
                                        <?php break;
                                    
                                    case 'text_long': ?>
                                        <textarea class="form-control" id="<?php echo $fieldId; ?>" 
                                                  name="<?php echo $fieldId; ?>" rows="4" 
                                                  placeholder="<?php echo htmlspecialchars($placeholder); ?>" <?php echo $required; ?>><?php echo htmlspecialchars($fieldValue); ?></textarea>
                                        <?php break;
                                    
                                    case 'number': ?>
                                        <input type="number" class="form-control" id="<?php echo $fieldId; ?>" 
                                               name="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" 
                                               placeholder="<?php echo htmlspecialchars($placeholder); ?>" step="any" <?php echo $required; ?>>
                                        <?php break;
                                    
                                    case 'date': ?>
                                        <input type="date" class="form-control" id="<?php echo $fieldId; ?>" 
                                               name="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php echo $required; ?>>
                                        <?php break;
                                    
                                    case 'time': ?>
                                        <input type="time" class="form-control" id="<?php echo $fieldId; ?>" 
                                               name="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php echo $required; ?>>
                                        <?php break;
                                    
                                    case 'datetime': ?>
                                        <input type="datetime-local" class="form-control" id="<?php echo $fieldId; ?>" 
                                               name="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php echo $required; ?>>
                                        <?php break;
                                endswitch; ?>
                                
                                <?php if (!empty($placeholder) && $field['field_type'] !== 'text_short' && $field['field_type'] !== 'text_long'): ?>
                                    <div class="field-help">
                                        <i class="bi bi-lightbulb"></i>
                                        <?php echo htmlspecialchars($placeholder); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- تاگەکان -->
                        <div class="mb-4">
                            <label for="tags" class="field-label">تاگەکان</label>
                            <input type="text" class="form-control" id="tags" name="tags" 
                                   value="<?php echo htmlspecialchars($_POST['tags'] ?? ''); ?>" 
                                   placeholder="تاگەکان بە کۆما جیا بکەرەوە، بۆ نموونە: گرنگ، کار، خێرا">
                            <div class="field-help">
                                <i class="bi bi-tags"></i>
                                تاگەکان یارمەتیدەرن بۆ گەڕان و ڕێکخستنی تێبینیەکان
                            </div>
                        </div>
                        
                        <!-- دوگمەکان -->
                        <div class="save-buttons">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-primary btn-lg w-100">
                                        <i class="bi bi-check-lg"></i>
                                        خەزنکردنی تێبینی
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <a href="view_topic.php?id=<?php echo $topicId; ?>" class="btn btn-outline-secondary btn-lg w-100">
                                        <i class="bi bi-x-lg"></i>
                                        هەڵوەشاندنەوە
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-save draft functionality
        let autoSaveTimer;
        const formInputs = document.querySelectorAll('#entryForm input, #entryForm textarea');
        
        function autoSave() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                const formData = new FormData(document.getElementById('entryForm'));
                const data = Object.fromEntries(formData);
                
                // Save to localStorage
                localStorage.setItem('draft_entry_<?php echo $topicId; ?>', JSON.stringify(data));
                
                // Show save indicator
                showSaveIndicator();
            }, 2000);
        }
        
        function showSaveIndicator() {
            // Create temporary indicator
            const indicator = document.createElement('div');
            indicator.className = 'position-fixed bottom-0 end-0 m-3 alert alert-success alert-sm';
            indicator.innerHTML = '<i class="bi bi-cloud-check"></i> پاشەکەوتکرا';
            indicator.style.zIndex = '1060';
            document.body.appendChild(indicator);
            
            setTimeout(() => {
                indicator.remove();
            }, 2000);
        }
        
        // Load draft on page load
        window.addEventListener('load', () => {
            const draft = localStorage.getItem('draft_entry_<?php echo $topicId; ?>');
            if (draft && !document.querySelector('.alert-danger')) {
                try {
                    const data = JSON.parse(draft);
                    Object.keys(data).forEach(key => {
                        const input = document.querySelector(`[name="${key}"]`);
                        if (input && !input.value) {
                            input.value = data[key];
                        }
                    });
                } catch (e) {
                    // console.log('Error loading draft:', e);
                }
            }
        });
        
        // Auto-save listeners
        formInputs.forEach(input => {
            input.addEventListener('input', autoSave);
            input.addEventListener('change', autoSave);
        });
        
        // Clear draft on successful submit
        document.getElementById('entryForm').addEventListener('submit', () => {
            localStorage.removeItem('draft_entry_<?php echo $topicId; ?>');
        });
        
        // Form validation
        document.getElementById('entryForm').addEventListener('submit', function(e) {
            const requiredFields = document.querySelectorAll('[required]');
            let hasErrors = false;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    hasErrors = true;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (hasErrors) {
                e.preventDefault();
                document.querySelector('.is-invalid').focus();
                
                // Show error message
                const errorDiv = document.createElement('div');
                errorDiv.className = 'alert alert-danger mt-3';
                errorDiv.innerHTML = '<i class="bi bi-exclamation-triangle"></i> تکایە هەموو خانە پێویستەکان پڕ بکەرەوە';
                
                const form = document.getElementById('entryForm');
                form.insertBefore(errorDiv, form.firstChild);
                
                setTimeout(() => errorDiv.remove(), 5000);
            }
        });
        
        // Real-time validation
        formInputs.forEach(input => {
            input.addEventListener('blur', function() {
                if (this.hasAttribute('required') && !this.value.trim()) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                }
            });
        });
    </script>
</body>
</html>