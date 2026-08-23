<?php
/**
 * بەڕێوەبردنی خانەکانی بابەت
 * user/notebooks/manage_fields.php
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

// تاقیکردنی دەسەڵات
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

$errors = [];
$success = false;

// زیادکردنی خانەی نوێ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_field') {
    $fieldName = Security::sanitizeInput($_POST['field_name'] ?? '');
    $fieldType = Security::sanitizeInput($_POST['field_type'] ?? 'text_short');
    $isRequired = isset($_POST['is_required']) ? 1 : 0;
    $placeholder = Security::sanitizeInput($_POST['placeholder'] ?? '');
    $defaultValue = Security::sanitizeInput($_POST['default_value'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrf_token)) {
        $errors[] = 'تۆکێنی ئاسایشی نادروستە';
    }
    
    if (empty($fieldName)) {
        $errors[] = 'ناوی خانە پێویستە';
    }
    
    if (count($fields) >= 10) {
        $errors[] = 'ناتوانیت زیاتر لە ١٠ خانە زیاد بکەیت';
    }
    
    // تاقیکردنی دووبارەبوون
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notebook_fields WHERE topic_id = ? AND field_name = ?");
        $stmt->bind_param("is", $topicId, $fieldName);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $errors[] = 'خانەیەک بەم ناوە پێشتر بوونی هەیە';
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO notebook_fields (topic_id, user_id, field_name, field_type, is_required, placeholder, default_value, field_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(field_order), 0) + 1 FROM notebook_fields nf WHERE nf.topic_id = ?))
            ");
            $stmt->bind_param("iisssssi", $topicId, $userId, $fieldName, $fieldType, $isRequired, $placeholder, $defaultValue, $topicId);
            
            if ($stmt->execute()) {
                setMessage('خانەکە بەسەرکەوتوویی زیادکرا', 'success');
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

$fieldTypes = [
    'text_short' => 'نوسینی کورت',
    'text_long' => 'نوسینی درێژ',
    'number' => 'ژمارە',
    'date' => 'بەروار',
    'time' => 'کات',
    'datetime' => 'بەروار و کات'
];
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>بەڕێوەبردنی خانەکان - <?php echo htmlspecialchars($topic['name']); ?> - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.css">
    
    <style>
        .topic-header {
            background: linear-gradient(135deg, <?php echo htmlspecialchars($topic['color']); ?>, <?php echo htmlspecialchars($topic['color']); ?>dd);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .field-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            cursor: move;
        }
        
        .field-card:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .field-type-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .drag-handle {
            cursor: move;
            color: #6c757d;
        }
        
        .add-field-form {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            border: 2px dashed #dee2e6;
        }
        
        .field-preview {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid #e9ecef;
        }
        
        .sortable-ghost {
            opacity: 0.4;
        }
        
        .sortable-chosen {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
    </style>
</head>
<body class="notebooks-module-page bg-light">
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
                            <h2 class="mb-1">
                                <i class="<?php echo htmlspecialchars($topic['icon']); ?> me-2"></i>
                                <?php echo htmlspecialchars($topic['name']); ?>
                            </h2>
                            <p class="mb-0 opacity-75">بەڕێوەبردنی خانەکان</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="view_topic.php?id=<?php echo $topicId; ?>" class="btn btn-light">
                        <i class="bi bi-eye"></i>
                        بینینی تێبینیەکان
                    </a>
                </div>
            </div>
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
        
        <?php
        $message = getMessage();
        if ($message):
        ?>
            <div class="alert alert-<?php echo $message['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($message['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row g-4">
            <!-- خانە بوونەکان -->
            <div class="col-md-7">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>خانەکان (<?php echo count($fields); ?>/10)</h4>
                    <?php if (count($fields) > 1): ?>
                        <small class="text-muted">
                            <i class="bi bi-arrows-move"></i>
                            ڕاکێشە بۆ ڕیزکردن
                        </small>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($fields)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-grid text-muted" style="font-size: 3rem;"></i>
                        <h5 class="text-muted mt-3">هێشتا هیچ خانەیەک زیادنەکراوە</h5>
                        <p class="text-muted">یەکەم خانەت زیاد بکە لەلای ڕاستەوە</p>
                    </div>
                <?php else: ?>
                    <div id="fields-list">
                        <?php foreach ($fields as $field): ?>
                            <div class="field-card card" data-field-id="<?php echo $field['id']; ?>">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-1">
                                            <i class="bi bi-grip-vertical drag-handle"></i>
                                        </div>
                                        <div class="col-6">
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($field['field_name']); ?>
                                                <?php if ($field['is_required']): ?>
                                                    <span class="text-danger">*</span>
                                                <?php endif; ?>
                                            </h6>
                                            <span class="badge bg-secondary field-type-badge">
                                                <?php echo $fieldTypes[$field['field_type']] ?? $field['field_type']; ?>
                                            </span>
                                        </div>
                                        <div class="col-3">
                                            <?php if (!empty($field['placeholder'])): ?>
                                                <small class="text-muted">
                                                    <i class="bi bi-quote"></i>
                                                    <?php echo htmlspecialchars(substr($field['placeholder'], 0, 20)); ?>
                                                    <?php echo strlen($field['placeholder']) > 20 ? '...' : ''; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-2 text-end">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                                    <i class="bi bi-three-dots"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#" onclick="editField(<?php echo $field['id']; ?>)">
                                                        <i class="bi bi-pencil me-2"></i>دەستکاری</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item text-danger" href="#" onclick="deleteField(<?php echo $field['id']; ?>)">
                                                        <i class="bi bi-trash me-2"></i>سڕینەوە</a></li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- زیادکردنی خانەی نوێ -->
            <div class="col-md-5">
                <div class="position-sticky" style="top: 20px;">
                    <h4 class="mb-3">خانەی نوێ زیاد بکە</h4>
                    
                    <?php if (count($fields) >= 10): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            گەیشتوویتە سنووری ١٠ خانە
                        </div>
                    <?php else: ?>
                        <div class="add-field-form">
                            <form method="POST" id="addFieldForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="add_field">
                                
                                <div class="mb-3">
                                    <label for="field_name" class="form-label">ناوی خانە <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="field_name" name="field_name" 
                                           placeholder="بۆ نموونە: ناوی کڕیار" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="field_type" class="form-label">جۆری خانە</label>
                                    <select class="form-select" id="field_type" name="field_type">
                                        <?php foreach ($fieldTypes as $type => $label): ?>
                                            <option value="<?php echo $type; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="placeholder" class="form-label">پڵەیس هۆڵدەر</label>
                                    <input type="text" class="form-control" id="placeholder" name="placeholder" 
                                           placeholder="نموونە: ناوی کڕیار بنووسە">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="default_value" class="form-label">بەهای بنەڕەتی</label>
                                    <input type="text" class="form-control" id="default_value" name="default_value" 
                                           placeholder="بەهایەک کە لە سەرەتادا دابنرێت">
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_required" name="is_required">
                                        <label class="form-check-label" for="is_required">
                                            خانەیەکی پێویستە
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-plus-lg"></i>
                                        زیادکردنی خانە
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- پێشبینی -->
                        <div class="mt-4">
                            <h6>پێشبینی:</h6>
                            <div class="field-preview" id="field-preview">
                                <label class="form-label">ناوی خانە</label>
                                <input type="text" class="form-control" placeholder="پڵەیس هۆڵدەر" disabled>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
        // Sortable بۆ ڕیزکردنی خانەکان
        <?php if (count($fields) > 1): ?>
        const sortable = Sortable.create(document.getElementById('fields-list'), {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onEnd: function(evt) {
                updateFieldOrder();
            }
        });
        
        function updateFieldOrder() {
            const fieldIds = Array.from(document.querySelectorAll('.field-card')).map(card => 
                card.getAttribute('data-field-id')
            );
            
            fetch('api/update_field_order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    topic_id: <?php echo $topicId; ?>,
                    field_ids: fieldIds,
                    csrf_token: '<?php echo $csrf_token; ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                }
            });
        }
        <?php endif; ?>
        
        // پێشبینی خانە
        function updatePreview() {
            const fieldName = document.getElementById('field_name').value || 'ناوی خانە';
            const fieldType = document.getElementById('field_type').value;
            const placeholder = document.getElementById('placeholder').value || 'پڵەیس هۆڵدەر';
            const isRequired = document.getElementById('is_required').checked;
            const defaultValue = document.getElementById('default_value').value;
            
            let inputHtml = '';
            const requiredStar = isRequired ? '<span class="text-danger">*</span>' : '';
            
            switch(fieldType) {
                case 'text_short':
                    inputHtml = `<input type="text" class="form-control" placeholder="${placeholder}" value="${defaultValue}" disabled>`;
                    break;
                case 'text_long':
                    inputHtml = `<textarea class="form-control" rows="3" placeholder="${placeholder}" disabled>${defaultValue}</textarea>`;
                    break;
                case 'number':
                    inputHtml = `<input type="number" class="form-control" placeholder="${placeholder}" value="${defaultValue}" disabled>`;
                    break;
                case 'date':
                    inputHtml = `<input type="date" class="form-control" value="${defaultValue}" disabled>`;
                    break;
                case 'time':
                    inputHtml = `<input type="time" class="form-control" value="${defaultValue}" disabled>`;
                    break;
                case 'datetime':
                    inputHtml = `<input type="datetime-local" class="form-control" value="${defaultValue}" disabled>`;
                    break;
            }
            
            document.getElementById('field-preview').innerHTML = `
                <label class="form-label">${fieldName} ${requiredStar}</label>
                ${inputHtml}
            `;
        }
        
        // Event listeners بۆ پێشبینی
        ['field_name', 'field_type', 'placeholder', 'is_required', 'default_value'].forEach(id => {
            document.getElementById(id).addEventListener('input', updatePreview);
            document.getElementById(id).addEventListener('change', updatePreview);
        });
        
        // سڕینەوەی خانە
        function deleteField(fieldId) {
            if (confirm('ئایا دڵنیایت لە سڕینەوەی ئەم خانەیە؟')) {
                fetch('api/delete_field.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        field_id: fieldId,
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
        
        // دەستکاری خانە
        function editField(fieldId) {
            // TODO: پێکردنی مۆدال بۆ دەستکاری
            alert('ئەم فیچەرە بەزووی زیاد دەکرێت');
        }
        
        // سەرەتایی پێشبینی
        updatePreview();
    </script>
</body>
</html>