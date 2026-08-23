<?php
/**
 * دەستکاری کردنی کۆمپانیا - user/companies/edit.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/company_computed_debt.php';
SessionManager::requireAuth('user');
requireCompaniesModuleAccess();

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$companyId = (int)($_GET['id'] ?? 0);
$errors = [];
$success = '';

// وەرگرتنی زانیاری کۆمپانیا
$stmt = $conn->prepare("SELECT * FROM companies WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $companyId, $userId);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();

if (!$company) {
    setMessage('کۆمپانیا نەدۆزرایەوە', 'error');
    redirect(url('user/companies/index.php'));
}

$computedRemainingDebt = fetch_company_computed_remaining_debt($conn, $companyId, $userId);
$computedRemainingDebtUsd = fetch_company_computed_remaining_debt($conn, $companyId, $userId, 'USD');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token پشکنین
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        // وەرگرتنی زانیارەکان
        $name = cleanInput($_POST['name'] ?? '');
        $address = cleanInput($_POST['address'] ?? '');
        $phone = cleanInput($_POST['phone'] ?? '');
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
        
        if (!in_array($status, ['active', 'inactive'])) {
            $status = 'active';
        }
        
        // پشکنین کە ناو دووبارە نەبێت (بە جگە لەم کۆمپانیایە)
        if (empty($errors)) {
            $checkStmt = $conn->prepare("SELECT id FROM companies WHERE name = ? AND user_id = ? AND id != ?");
            $checkStmt->bind_param("sii", $name, $userId, $companyId);
            $checkStmt->execute();
            
            if ($checkStmt->get_result()->num_rows > 0) {
                $errors[] = 'کۆمپانیایەک بەم ناوەوە پێشتر تۆمار کراوە';
            }
        }
        
        // نوێکردنەوە
        if (empty($errors)) {
            $updateStmt = $conn->prepare("UPDATE companies SET name = ?, address = ?, phone = ?, notes = ?, status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $updateStmt->bind_param("sssssii", $name, $address, $phone, $notes, $status, $companyId, $userId);
            
            if ($updateStmt->execute()) {
                setMessage('کۆمپانیا بەسەرکەوتوویی نوێ کرایەوە', 'success');
                redirect(url('user/companies/index.php'));
            } else {
                $errors[] = 'هەڵە لە نوێکردنەوەی کۆمپانیا';
            }
        }
    }
    
    // ئەگەر هەڵە هەبوو، زانیارە نوێکان بۆ فۆڕمەکە بهێڵەرەوە
    if (!empty($errors)) {
        $company['name'] = $_POST['name'] ?? $company['name'];
        $company['address'] = $_POST['address'] ?? $company['address'];
        $company['phone'] = $_POST['phone'] ?? $company['phone'];
        $company['notes'] = $_POST['notes'] ?? $company['notes'];
        $company['status'] = $_POST['status'] ?? $company['status'];
    }
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>دەستکاری <?php echo htmlspecialchars($company['name']); ?> - <?php echo SITE_NAME; ?></title>
    
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
                            <i class="bi bi-pencil text-primary"></i>
                            دەستکاری <?php echo htmlspecialchars($company['name']); ?>
                        </h2>
                        <p class="text-muted mb-0">گۆڕینی زانیاری کۆمپانیا</p>
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

                <!-- Edit Company Form -->
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
                                           value="<?php echo htmlspecialchars($company['name']); ?>" 
                                           required maxlength="255">
                                    <div class="invalid-feedback">تکایە ناوی کۆمپانیا بنووسە</div>
                                </div>
                                
                                <!-- Phone -->
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">ژمارەی موبایل</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($company['phone']); ?>" 
                                           placeholder="07xxxxxxxxx">
                                    <div class="form-text">نموونە: 07501234567</div>
                                </div>
                                
                                <!-- Address -->
                                <div class="col-12">
                                    <label for="address" class="form-label">ناونیشان</label>
                                    <textarea class="form-control" id="address" name="address" rows="2" 
                                              placeholder="ناونیشانی کۆمپانیا..."><?php echo htmlspecialchars($company['address']); ?></textarea>
                                </div>
                                
                                <!-- Status -->
                                <div class="col-md-6">
                                    <label for="status" class="form-label">دۆخ</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" <?php echo $company['status'] === 'active' ? 'selected' : ''; ?>>
                                            چالاک
                                        </option>
                                        <option value="inactive" <?php echo $company['status'] === 'inactive' ? 'selected' : ''; ?>>
                                            ناچالاک
                                        </option>
                                    </select>
                                </div>
                                
                                <!-- Current Debt (Read-only) -->
                                <div class="col-md-6">
                                    <label class="form-label">بڕی قەرزی ئێستا (دینار)</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control"
                                               value="<?php echo number_format($computedRemainingDebt); ?>" readonly>
                                        <span class="input-group-text">دینار</span>
                                    </div>
                                    <div class="form-text">بۆ گۆڕینی قەرز، لە بەشی قەرزەکاندا مامەڵە زیاد بکە</div>
                                </div>

                                <!-- Current Debt USD (Read-only) -->
                                <div class="col-md-6">
                                    <label class="form-label">بڕی قەرزی ئێستا (دۆلار)</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control"
                                               value="<?php echo number_format($computedRemainingDebtUsd, 2); ?>" readonly>
                                        <span class="input-group-text">دۆلار</span>
                                    </div>
                                    <div class="form-text">قەرزی دۆلاری کۆمپانیا (سەربەخۆ لە دینار)</div>
                                </div>
                                
                                <!-- Notes -->
                                <div class="col-12">
                                    <label for="notes" class="form-label">تێبینی</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" 
                                              placeholder="تێبینی و وردەکاری تایبەت..."><?php echo htmlspecialchars($company['notes']); ?></textarea>
                                </div>
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between">
                                        <a href="<?php echo url('user/companies/index.php'); ?>" class="btn btn-secondary">
                                            <i class="bi bi-x-circle"></i> پاشگەز
                                        </a>
                                        <div class="d-flex gap-2">
                                            <a href="<?php echo url('user/companies/debts.php?company_id=' . $company['id']); ?>" class="btn btn-info">
                                                <i class="bi bi-list-check"></i> قەرزەکان
                                            </a>
                                            <button type="submit" class="btn btn-primary px-4">
                                                <i class="bi bi-check-circle"></i> نوێکردنەوە
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Company Info Card -->
                <div class="card mt-4 border-info">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-info-circle text-info"></i>
                            زانیاری زیاتر
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>بەروارەی تۆمارکردن:</strong></p>
                                <p class="text-muted small"><?php echo date('Y/m/d H:i', strtotime($company['created_at'])); ?></p>
                            </div>
                            <?php if ($company['updated_at']): ?>
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>دوایین نوێکردنەوە:</strong></p>
                                    <p class="text-muted small"><?php echo date('Y/m/d H:i', strtotime($company['updated_at'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <hr>
                        
                        <h6 class="mb-2">کردەوە خێراکان:</h6>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="<?php echo url('user/companies/debts.php?company_id=' . $company['id']); ?>" 
                               class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-list-check"></i> بینینی قەرزەکان
                            </a>
                            <a href="<?php echo url('user/companies/debts.php?company_id=' . $company['id']); ?>" 
                               class="btn btn-outline-success btn-sm">
                                <i class="bi bi-cash"></i> دانەوەی پارە
                            </a>
                        </div>
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