<?php
/**
 * زیادکردنی کۆمپانیای نوێ - user/companies/add.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

SessionManager::requireAuth('user');
requireCompaniesModuleAccess();

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token پشکنین
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        // وەرگرتنی زانیارەکان
        $name = cleanInput($_POST['name'] ?? '');
        $address = cleanInput($_POST['address'] ?? '');
        $phone = cleanInput($_POST['phone'] ?? '');
        $debt_amount = (float)($_POST['debt_amount'] ?? 0);
        $notes = cleanInput($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // پشتڕاستکردنەوە
        if (empty($name)) {
            $errors[] = 'ناوی کۆمپانیا پێویستە';
        } elseif (strlen($name) < 2) {
            $errors[] = 'ناوی کۆمپانیا دەبێت لانیکەم ٢ پیت بێت';
        }
        
        if (!empty($phone) && !preg_match('/^[0-9+\-\s()]{10,20}$/', $phone)) {
            $errors[] = 'ژمارەی تەلەفۆن درووست نییە';
        }
        
        if ($debt_amount < 0) {
            $errors[] = 'بڕی قەرز نابێت کەمتر لە سفر بێت';
        }
        
        if (!in_array($status, ['active', 'inactive'])) {
            $status = 'active';
        }
        
        // پشکنین کە ناو دووبارە نەبێت
        if (empty($errors)) {
            $checkStmt = $conn->prepare("SELECT id FROM companies WHERE name = ? AND user_id = ?");
            $checkStmt->bind_param("si", $name, $userId);
            $checkStmt->execute();
            
            if ($checkStmt->get_result()->num_rows > 0) {
                $errors[] = 'کۆمپانیایەک بەم ناوەوە پێشتر تۆمار کراوە';
            }
        }
        
        // تۆمارکردن
        if (empty($errors)) {
            $conn->begin_transaction();
            
            try {
                // تۆمارکردنی کۆمپانیا
                $stmt = $conn->prepare("INSERT INTO companies (user_id, name, address, phone, debt_amount, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssdss", $userId, $name, $address, $phone, $debt_amount, $notes, $status);
                
                if ($stmt->execute()) {
                    $companyId = $conn->insert_id;
                    
                    // ئەگەر قەرز هەبوو، وەک مامەڵەی یەکەم تۆماری بکە
                    if ($debt_amount > 0) {
                        $debtStmt = $conn->prepare("INSERT INTO company_debts (user_id, company_id, amount, description, type, date) VALUES (?, ?, ?, ?, 'debt', CURDATE())");
                        $description = "قەرزی سەرەتایی";
                        $debtStmt->bind_param("iids", $userId, $companyId, $debt_amount, $description);
                        $debtStmt->execute();
                    }
                    
                    $conn->commit();
                    setMessage('کۆمپانیای نوێ بەسەرکەوتوویی زیاد کرا', 'success');
                    redirect(url('user/companies/index.php'));
                } else {
                    throw new Exception('هەڵە لە تۆمارکردنی کۆمپانیا');
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'هەڵە لە تۆمارکردن: ' . $e->getMessage();
            }
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
    <title>کۆمپانیای نوێ - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

</head>
<body class="companies-module-page bg-light">
    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

<!-- Main Content -->
    <div class="container py-4 hub-page-content">
        <div class="row justify-content-center">
            <div class="col-xl-8 col-lg-10">
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-plus-circle text-primary"></i>
                            کۆمپانیای نوێ
                        </h2>
                        <p class="text-muted mb-0">زیادکردنی کۆمپانیایەکی نوێ بۆ بەڕێوەبردنی قەرزەکان</p>
                    </div>
                </div>

                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>هەڵەکان:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Add Company Form -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-building"></i>
                            زانیاری کۆمپانیا
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="row g-3">
                                <!-- Company Name -->
                                <div class="col-md-6">
                                    <label for="name" class="form-label required">ناوی کۆمپانیا</label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                           required maxlength="255">
                                    <div class="invalid-feedback">تکایە ناوی کۆمپانیا بنووسە</div>
                                </div>
                                
                                <!-- Phone -->
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">ژمارەی موبایل</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
                                           placeholder="07xxxxxxxxx">
                                    <div class="form-text">نموونە: 07501234567</div>
                                </div>
                                
                                <!-- Address -->
                                <div class="col-12">
                                    <label for="address" class="form-label">ناونیشان</label>
                                    <textarea class="form-control" id="address" name="address" rows="2" 
                                              placeholder="ناونیشانی کۆمپانیا..."><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                </div>
                                
                                <!-- Debt Amount -->
                                <div class="col-md-6">
                                    <label for="debt_amount" class="form-label">بڕی قەرز</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="debt_amount" name="debt_amount" 
                                               value="<?php echo htmlspecialchars($_POST['debt_amount'] ?? '0'); ?>" 
                                               min="0" step="0.01">
                                        <span class="input-group-text">دینار</span>
                                    </div>
                                    <div class="form-text">بڕی قەرزی سەرەتایی (ئەگەر هەبوو)</div>
                                </div>
                                
                                <!-- Status -->
                                <div class="col-md-6">
                                    <label for="status" class="form-label">دۆخ</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" <?php echo ($_POST['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>
                                            چالاک
                                        </option>
                                        <option value="inactive" <?php echo ($_POST['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>
                                            ناچالاک
                                        </option>
                                    </select>
                                </div>
                                
                                <!-- Notes -->
                                <div class="col-12">
                                    <label for="notes" class="form-label">تێبینی</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" 
                                              placeholder="تێبینی و وردەکاری تایبەت..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between">
                                        <a href="<?php echo url('user/companies/index.php'); ?>" class="btn btn-secondary">
                                            <i class="bi bi-x-circle"></i> پاشگەز
                                        </a>
                                        <button type="submit" class="btn btn-primary px-4">
                                            <i class="bi bi-check-circle"></i> تۆمارکردن
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Help Card -->
                <div class="card mt-4 border-info">
                    <div class="card-body">
                        <h6 class="card-title text-info">
                            <i class="bi bi-info-circle"></i> تێبینی گرنگ
                        </h6>
                        <ul class="mb-0 small">
                            <li>دوای زیادکردنی کۆمپانیا، دەتوانیت قەرز و دانەوەی پارە تۆمار بکەیت</li>
                            <li>ئەگەر بڕی قەرزی سەرەتایی دیاری بکەیت، وەک یەکەم مامەڵە تۆمار دەبێت</li>
                            <li>دەتوانیت ئامارەکانی قەرز و دانەوەکان لە بەشی قەرزەکان ببینیت</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Bootstrap validation
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();
    </script>
    
    <style>
        .required::after {
            content: ' *';
            color: red;
        }
    </style>
</body>
</html>