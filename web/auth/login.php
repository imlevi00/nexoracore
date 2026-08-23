<?php
/**
 * Customer Login - web/auth/login.php
 * Login form for online shop customers
 */

require_once 'session_helper.php';

$error = '';
$redirectUrl = $_GET['redirect'] ?? '';

// Redirect if already logged in
if (CustomerSession::isLoggedIn()) {
    if ($redirectUrl) {
        header('Location: ../' . $redirectUrl);
    } else {
        header('Location: ../shop.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = sanitizeInput($_POST['login'] ?? ''); // email or phone
    $password = $_POST['password'] ?? '';
    
    if (empty($login)) {
        $error = 'ئیمەیڵ یان ژمارەی تەلەفۆن پێویستە';
    } elseif (empty($password)) {
        $error = 'پاسۆرد پێویستە';
    } else {
        // Find customer by email or phone
        $stmt = $conn->prepare("SELECT * FROM web_customers WHERE (email = ? OR phone = ?) AND is_active = 1");
        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();
        $customer = $result->fetch_assoc();
        $stmt->close();
        
        if ($customer && CustomerSession::verifyPassword($password, $customer['password_hash'])) {
            $customerData = [
                'name' => $customer['name'],
                'email' => $customer['email'],
                'phone' => $customer['phone'],
                'address' => $customer['address']
            ];
            
            CustomerSession::login($customer['id'], $customerData);
            
            if ($redirectUrl) {
                header('Location: ../' . $redirectUrl);
            } else {
                header('Location: ../shop.php');
            }
            exit;
        } else {
            $error = 'ئیمەیڵ/ژمارەی تەلەفۆن یان پاسۆرد هەڵەیە';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چوونەژوورەوە - فرۆشگای ئۆنلاین</title>
    
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
            max-width: 450px;
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
                    <i class="bi bi-box-arrow-in-right"></i>
                    چوونەژوورەوە
                </h2>
                <p class="mb-0 mt-2">چوونەژوورەوە بۆ فرۆشگای ئۆنلاین</p>
            </div>
            
            <div class="auth-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="login" class="form-label">ئیمەیڵ یان ژمارەی تەلەفۆن</label>
                        <input type="text" class="form-control" id="login" name="login" 
                               value="<?php echo htmlspecialchars($login ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-4">
                        <label for="password" class="form-label">پاسۆرد</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 mb-3">
                        <i class="bi bi-box-arrow-in-right"></i>
                        چوونەژوورەوە
                    </button>
                    
                    <div class="text-center">
                        <p class="mb-0">هێشتا ئەکاونتت نییە؟</p>
                        <a href="register.php" class="btn btn-outline-primary">
                            <i class="bi bi-person-plus"></i>
                            تۆمارکردن
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
