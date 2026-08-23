<?php
/**
 * دەستکاری تێبینی
 * user/notebooks/edit_entry.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$entryId = (int)($_GET['id'] ?? 0);

if (!$entryId) {
    setMessage('تێبینی دۆزرایەوە', 'error');
    redirect('index.php');
}

// وەرگرتنی تێبینی
$stmt = $conn->prepare("
    SELECT e.*, t.name as topic_name, t.icon as topic_icon, t.color as topic_color
    FROM notebook_entries e
    INNER JOIN notebook_topics t ON e.topic_id = t.id
    WHERE e.id = ? AND e.user_id = ?
");
$stmt->bind_param("ii", $entryId, $userId);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();

if (!$entry) {
    setMessage('تێبینی دۆزرایەوە یان دەسەڵاتت نییە', 'error');
    redirect('index.php');
}

$topicId = $entry['topic_id'];
$entryData = json_decode($entry['entry_data'], true) ?? [];

// وەرگرتنی خانەکان
$stmt = $conn->prepare("
    SELECT * FROM notebook_fields 
    WHERE topic_id = ? AND user_id = ? 
    ORDER BY field_order, created_at
");
$stmt->bind_param("ii", $topicId, $userId);
$stmt->execute();
$fields = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
    $newEntryData = [];
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
        
        $newEntryData[$field['id']] = Security::sanitizeInput($fieldValue);
    }
    
    // نوێکردنەوە
    if (empty($errors)) {
        try {
            $entryDataJson = json_encode($newEntryData);
            
            $stmt = $conn->prepare("
                UPDATE notebook_entries 
                SET title = ?, entry_data = ?, tags = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?
            ");
            $stmt->bind_param("sssii", $title, $entryDataJson, $tags, $entryId, $userId);
            
            if ($stmt->execute()) {
                setMessage('تێبینیەکە بەسەرکەوتوویی نوێکرایەوە', 'success');
                redirect('view_topic.php?id=' . $topicId);
            } else {
                $errors[] = 'هەڵەیەک ڕوویدا لە نوێکردنەوە';
            }
        } catch (Exception $e) {
            $errors[] = 'هەڵەیەکی داتابەیس ڕوویدا';
        }
    }
} else {
    // پڕکردنەوەی فۆرم بە زانیاری بوونیش
    $_POST['title'] = $entry['title'];
    $_POST['tags'] = $entry['tags'];
    foreach ($entryData as $fieldId => $value) {
        $_POST['field_' . $fieldId] = $value;
    }
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>دەستکاری تێبینی - <?php echo htmlspecialchars($entry['topic_name']); ?> - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    
    <style>
        .topic-header {
            background: linear-gradient(135deg, <?php echo htmlspecialchars($entry['topic_color']); ?>, <?php echo htmlspecialchars($entry['topic_color']); ?>dd);
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
            border-left: 4px solid <?php echo htmlspecialchars($entry['topic_color']); ?>;
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
        
        .entry-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
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
                        <i class="bi bi-pencil me-2"></i>
                        دەستکاری تێبینی
                    </h2>
                    <p class="mb-0 opacity-75">
                        <i class="<?php echo htmlspecialchars($entry['topic_icon']); ?> me-1"></i>
                        <?php echo htmlspecialchars($entry['topic_name']); ?>
                    </p>
                </div>
            </div>
            
            <!-- زانیاری تێبینی -->
            <div class="entry-info">
                <div class="row">
                    <div class="col-6">
                        <small>دروستکراو:</small>
                        <div><?php echo date('Y/m/d H:i', strtotime($entry['created_at'])); ?></div>
                    </div>
                    <div class="col-6">
                        <small>دوایین نوێکردنەوە:</small>
                        <div><?php echo date('Y/m/d H:i', strtotime($entry['updated_at'])); ?></div>
                    </div>
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
                    <form method="POST" id="editEntryForm">
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
                                $fieldValue = $_POST['field_' . $field['id']] ?? '';
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
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-success btn-lg w-100">
                                        <i class="bi bi-check-lg"></i>
                                        نوێکردنەوە
                                    </button>
                                </div>
                                <div class="col-md-4">
                                    <a href="view_topic.php?id=<?php echo $topicId; ?>" class="btn btn-outline-secondary btn-lg w-100">
                                        <i class="bi bi-x-lg"></i>
                                        هەڵوەشاندنەوە
                                    </a>
                                </div>
                                <div class="col-md-4">
                                    <button type="button" class="btn btn-outline-danger btn-lg w-100" onclick="deleteEntry()">
                                        <i class="bi bi-trash"></i>
                                        سڕینەوە
                                    </button>
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
        const formInputs = document.querySelectorAll('#editEntryForm input, #editEntryForm textarea');
        
        function autoSave() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                const formData = new FormData(document.getElementById('editEntryForm'));
                const data = Object.fromEntries(formData);
                
                // Save to localStorage
                localStorage.setItem('draft_edit_<?php echo $entryId; ?>', JSON.stringify(data));
                
                // Show save indicator
                showSaveIndicator();
            }, 2000);
        }
        
        function showSaveIndicator() {
            const indicator = document.createElement('div');
            indicator.className = 'position-fixed bottom-0 end-0 m-3 alert alert-info alert-sm';
            indicator.innerHTML = '<i class="bi bi-cloud-check"></i> پاشەکەوتکراوە (نوسخەی کاتی)';
            indicator.style.zIndex = '1060';
            document.body.appendChild(indicator);
            
            setTimeout(() => {
                indicator.remove();
            }, 2000);
        }
        
        // Auto-save listeners
        formInputs.forEach(input => {
            input.addEventListener('input', autoSave);
            input.addEventListener('change', autoSave);
        });
        
        // Clear draft on successful submit
        document.getElementById('editEntryForm').addEventListener('submit', () => {
            localStorage.removeItem('draft_edit_<?php echo $entryId; ?>');
        });
        
        // سڕینەوەی تێبینی
        function deleteEntry() {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم تێبینیەیە؟\nئەم کردارە ناگەڕێتەوە!')) {
                fetch('api/delete_entry.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        entry_id: <?php echo $entryId; ?>,
                        csrf_token: '<?php echo $csrf_token; ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        localStorage.removeItem('draft_edit_<?php echo $entryId; ?>');
                        window.location.href = 'view_topic.php?id=<?php echo $topicId; ?>';
                    } else {
                        alert(data.message || 'هەڵەیەک ڕوویدا');
                    }
                })
                .catch(error => {
              
                });
            }
        }
        
        // Form validation
        document.getElementById('editEntryForm').addEventListener('submit', function(e) {
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
        
        // تاقیکردنەوەی گۆڕانکارییەکان
        let originalData = {};
        window.addEventListener('load', () => {
            const formData = new FormData(document.getElementById('editEntryForm'));
            originalData = Object.fromEntries(formData);
        });
        
        window.addEventListener('beforeunload', (e) => {
            const formData = new FormData(document.getElementById('editEntryForm'));
            const currentData = Object.fromEntries(formData);
            
            // تاقیکردنەوەی گۆڕانکاری
            let hasChanges = false;
            Object.keys(currentData).forEach(key => {
                if (currentData[key] !== originalData[key]) {
                    hasChanges = true;
                }
            });
            
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = 'گۆڕانکارییەکانت خەزننەکراون. دڵنیایت لە چوونەدەرەوە؟';
            }
        });
    </script>
</body>
</html>