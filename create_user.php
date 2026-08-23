<?php
/**
 * Create Master User with Full Access
 * Can be run via Browser or CLI:
 *   Browser: http://<ip>/Ka.sheryAi/create_user.php
 *   CLI:     php create_user.php <email> <password> <business_name>
 */

declare(strict_types=1);

$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/security.php';

$isCli = (PHP_SAPI === 'cli');

$message = '';
$messageType = '';
$createdUser = null;

if ($isCli) {
    $email = $argv[1] ?? 'admin@kashery.local';
    $password = $argv[2] ?? 'admin123456';
    $businessName = $argv[3] ?? 'Master Admin';
    createMasterUser($conn, $email, $password, $businessName);
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $businessName = trim($_POST['business_name'] ?? 'Master Admin');

    if (empty($email) || empty($password)) {
        $message = "Please fill in both Email and Password.";
        $messageType = "error";
    } else {
        $res = createMasterUser($conn, $email, $password, $businessName);
        if ($res['success']) {
            $message = $res['message'];
            $messageType = "success";
            $createdUser = ['email' => $email, 'password' => $password, 'business' => $businessName];
        } else {
            $message = $res['message'];
            $messageType = "error";
        }
    }
}

function createMasterUser(mysqli $conn, string $email, string $password, string $businessName): array {
    global $isCli;
    
    // Hash password with BCrypt
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $phone = '07501234567';
    $address = 'Master Office';
    
    // 1. Ensure master package exists with all permissions
    $conn->query("
        INSERT INTO `packages` (`id`, `name`, `description`, `permissions`, `is_active`, `max_sub_users`)
        VALUES (999, 'Super Admin VIP', 'Full Lifetime Access Package', '{}', 1, 999)
        ON DUPLICATE KEY UPDATE `is_active` = 1, `max_sub_users` = 999
    ");

    // All feature permissions for the package
    $features = [
        'pos_receipt_view', 'pos_receipt_a4_view', 'pos_barcode_scan',
        'employees_manage', 'employees_stats', 'employees_max_count',
        'companies_manage', 'customers_history', 'customers_account_statement',
        'wallets_manage', 'reports_item_section_profit', 'products_smart_scale'
    ];
    foreach ($features as $f) {
        $conn->query("
            INSERT INTO `package_feature_permissions` (`package_id`, `feature_key`, `is_enabled`, `lock_html`)
            VALUES (999, '{$f}', 1, '')
            ON DUPLICATE KEY UPDATE `is_enabled` = 1
        ");
    }

    // 2. Check if user already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $userId = 0;
    if ($existing) {
        $userId = (int)$existing['id'];
        $update = $conn->prepare("
            UPDATE users SET 
                password = ?, 
                business_name = ?, 
                status = 'approved', 
                package_id = 999, 
                expiration_date = '2099-12-31 23:59:59',
                approved_at = NOW(),
                ai_balance = 999.00,
                support_balance = 99999.00
            WHERE id = ?
        ");
        $update->bind_param("ssi", $hashedPassword, $businessName, $userId);
        $update->execute();
        $update->close();
    } else {
        $insert = $conn->prepare("
            INSERT INTO users (
                business_name, email, password, phone, address, telegram_sent,
                status, package_id, expiration_date, created_at, approved_at,
                ai_balance, support_balance
            ) VALUES (
                ?, ?, ?, ?, ?, 0,
                'approved', 999, '2099-12-31 23:59:59', NOW(), NOW(),
                999.00, 99999.00
            )
        ");
        $insert->bind_param("sssss", $businessName, $email, $hashedPassword, $phone, $address);
        $insert->execute();
        $userId = (int)$conn->insert_id;
        $insert->close();
    }

    if ($userId <= 0) {
        $err = "Failed to create/update user in database: " . $conn->error;
        if ($isCli) echo $err . PHP_EOL;
        return ['success' => false, 'message' => $err];
    }

    // 3. Ensure Settings (Full business modules: pharmacy + medical center + market)
    $conn->query("
        INSERT INTO `settings` (`user_id`, `business_type_id`, `receipt_header`, `receipt_footer`, `receipt_size`, `currency`, `tax_rate`, `low_stock_alert`, `expiry_alert_days`)
        VALUES ({$userId}, 3, '{$businessName}', 'Thank you for your business', 'thermal', 'IQD', 0.00, 1, 30)
        ON DUPLICATE KEY UPDATE `business_type_id` = 3
    ");

    // 4. Ensure Wallet
    $conn->query("
        INSERT INTO `wallets` (`user_id`, `name`, `is_default`, `is_active`, `balance_iqd`, `balance_usd`)
        VALUES ({$userId}, 'قاسەی سەرەکی', 1, 1, 0.000, 0.000)
        ON DUPLICATE KEY UPDATE `is_active` = 1, `is_default` = 1
    ");

    // 5. Ensure user account settings
    $conn->query("
        INSERT INTO `user_account_settings` (`user_id`, `pos_show_zero_stock_products`, `purchases_use_weighted_avg_prices`, `recognize_customer_debt_revenue_at_sale`, `receipt_a4_items_font_size`, `pos_default_sale_currency`, `pos_default_price_type`, `pos_default_payment_is_credit`, `theme_mode`)
        VALUES ({$userId}, 1, 1, 1, 16, 'IQD', 'retail', 0, 'system')
        ON DUPLICATE KEY UPDATE `pos_show_zero_stock_products` = 1
    ");

    $msg = "Master User successfully created with Full Lifetime Access!";
    if ($isCli) {
        echo "==================================================" . PHP_EOL;
        echo $msg . PHP_EOL;
        echo "Email:       {$email}" . PHP_EOL;
        echo "Password:    {$password}" . PHP_EOL;
        echo "Business:    {$businessName}" . PHP_EOL;
        echo "Expiration:  Lifetime (2099)" . PHP_EOL;
        echo "==================================================" . PHP_EOL;
    }
    return ['success' => true, 'message' => $msg];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Full Access User - NexoraCore</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; }
        .container { max-width: 550px; margin: 0 auto; background: #1e293b; padding: 2.5rem; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; font-size: 1.6rem; color: #38bdf8; display: flex; align-items: center; gap: 8px; }
        p { color: #94a3b8; font-size: 0.95rem; line-height: 1.5; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.9rem; color: #e2e8f0; }
        input { width: 100%; box-sizing: border-box; padding: 12px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: white; font-size: 1rem; }
        input:focus { outline: none; border-color: #38bdf8; ring: 2px solid #38bdf8; }
        .btn { display: block; width: 100%; padding: 12px; background: #0284c7; color: white; border: none; border-radius: 8px; font-weight: bold; font-size: 1rem; cursor: pointer; transition: 0.2s; text-align: center; text-decoration: none; box-sizing: border-box; }
        .btn:hover { background: #0369a1; }
        .alert { padding: 14px 16px; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .alert-success { background: #064e3b; color: #6ee7b7; border: 1px solid #059669; }
        .alert-error { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }
        .badge-card { background: #0b0f19; border-radius: 8px; padding: 1rem; margin-top: 1.5rem; border: 1px solid #334155; }
        .badge-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #1e293b; }
        .badge-row:last-child { border-bottom: none; }
        .badge-label { color: #94a3b8; }
        .badge-val { font-weight: bold; color: #38bdf8; }
    </style>
</head>
<body>
<div class="container">
    <h1>👑 Create Full-Access Master Account</h1>
    <p>Generate an administrator / merchant user with <strong>100% full permissions</strong>, lifetime expiration, and all modules unlocked.</p>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($createdUser): ?>
        <div class="badge-card">
            <div class="badge-row">
                <span class="badge-label">Email:</span>
                <span class="badge-val"><?php echo htmlspecialchars($createdUser['email']); ?></span>
            </div>
            <div class="badge-row">
                <span class="badge-label">Password:</span>
                <span class="badge-val"><?php echo htmlspecialchars($createdUser['password']); ?></span>
            </div>
            <div class="badge-row">
                <span class="badge-label">Business:</span>
                <span class="badge-val"><?php echo htmlspecialchars($createdUser['business']); ?></span>
            </div>
            <div class="badge-row">
                <span class="badge-label">Access Level:</span>
                <span class="badge-val" style="color: #4ade80;">Full Lifetime (2099)</span>
            </div>
        </div>
        <div style="margin-top: 1.5rem;">
            <a href="user/auth/login.php" class="btn" style="background: #10b981;">Log In Now →</a>
        </div>
    <?php else: ?>
        <form method="POST">
            <div class="form-group">
                <label>Business Name</label>
                <input type="text" name="business_name" value="Nexora Master" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="admin@kashery.local" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="text" name="password" value="admin123456" required>
            </div>
            <button type="submit" class="btn">⚡ Create Full Access Account</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>

