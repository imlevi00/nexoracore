<?php
/**
 * Customer Registration - web/auth/register.php
 * Registration form for online shop customers
 */

require_once 'session_helper.php';

$error = '';
$success = '';

// Redirect if already logged in
if (CustomerSession::isLoggedIn()) {
    header('Location: ../shop.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $address = sanitizeInput($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($name)) {
        $error = 'ناو پێویستە';
    } elseif (empty($phone)) {
        $error = 'ژمارەی تەلەفۆن پێویستە';
    } elseif (!isValidPhone($phone)) {
        $error = 'ژمارەی تەلەفۆن نادروستە';
    } elseif (empty($email)) {
        $error = 'ئیمەیڵ پێویستە';
    } elseif (!isValidEmail($email)) {
        $error = 'ئیمەیڵ نادروستە';
    } elseif (empty($password)) {
        $error = 'پاسۆرد پێویستە';
    } elseif (strlen($password) < 6) {
        $error = 'پاسۆرد دەبێت لانیکەم ٦ پیت بێت';
    } elseif ($password !== $confirmPassword) {
        $error = 'پاسۆردەکان یەکناگرنەوە';
    } else {
        // Check if email or phone already exists
        $checkStmt = $conn->prepare("SELECT id FROM web_customers WHERE email = ? OR phone = ?");
        $checkStmt->bind_param("ss", $email, $phone);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'ئیمەیڵ یان ژمارەی تەلەفۆن پێشتر بەکارهاتووە';
        } else {
            // Create new customer
            $passwordHash = CustomerSession::hashPassword($password);
            $insertStmt = $conn->prepare("
                INSERT INTO web_customers (name, phone, email, address, password_hash) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->bind_param("sssss", $name, $phone, $email, $address, $passwordHash);
            
            if ($insertStmt->execute()) {
                $customerId = $conn->insert_id;
                $customerData = [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address
                ];
                
                CustomerSession::login($customerId, $customerData);
                $success = 'ئەکاونتەکەت بە سەرکەوتوویی دروستکرا';
                
                // Redirect to shop
                header('Location: ../shop.php');
                exit;
            } else {
                $error = 'هەڵەیەک ڕوویدا لە دروستکردنی ئەکاونت';
            }
            $insertStmt->close();
        }
        $checkStmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تۆمارکردن - فرۆشگای ئۆنلاین</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../template/assets/css/shop.css" rel="stylesheet">
    
    <style>
        .auth-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .auth-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
        }
        
        .auth-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .auth-body {
            padding: 2rem;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px;
            font-weight: bold;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h2 class="mb-0">
                    <i class="bi bi-person-plus"></i>
                    تۆمارکردن
                </h2>
                <p class="mb-0 mt-2">دروستکردنی ئەکاونت بۆ فرۆشگای ئۆنلاین</p>
            </div>
            
            <div class="auth-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i>
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="name" class="form-label">ناو</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo htmlspecialchars($name ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="phone" class="form-label">ژمارەی تەلەفۆن</label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($phone ?? ''); ?>" 
                               placeholder="07xxxxxxxxx" required>
                        <div class="form-text">نموونە: 07501234567</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">ئیمەیڵ</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">ناونیشان (ئیختیاری)</label>
                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($address ?? ''); ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">پاسۆرد</label>
                        <input type="password" class="form-control" id="password" name="password" 
                               minlength="6" required>
                        <div class="form-text">لانیکەم ٦ پیت</div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">پشتڕاستکردنەوەی پاسۆرد</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               minlength="6" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 mb-3">
                        <i class="bi bi-person-plus"></i>
                        دروستکردنی ئەکاونت
                    </button>
                    
                    <div class="text-center">
                        <p class="mb-0">پێشتر ئەکاونتت هەیە؟</p>
                        <a href="login.php" class="btn btn-outline-primary">
                            <i class="bi bi-box-arrow-in-right"></i>
                            چوونەژوورەوە
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('پاسۆردەکان یەکناگرنەوە');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
