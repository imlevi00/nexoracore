<?php
/**
 * Standalone Authentication & Account Fixer Tool
 * http://<ip>/Ka.sheryAi/debug_auth.php
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Clear any lockout in session
foreach ($_SESSION as $k => $v) {
    if (strpos((string)$k, 'login_attempts_') === 0) {
        unset($_SESSION[$k]);
    }
}

// Database Connection
$dbHost = '127.0.0.1';
$dbUser = 'itsmelevi';
$dbPass = 'levi12345';
$dbName = 'nexoracore_db';

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_error) {
    die("Database Connection Error: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
$conn->query("SET FOREIGN_KEY_CHECKS = 0");

$notices = [];
$hashed = password_hash('123456', PASSWORD_BCRYPT, ['cost' => 12]);

// 1. Ensure packages table & insert full access package
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS `packages` (
            `id` int NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` text,
            `permissions` text,
            `is_active` tinyint(1) NOT NULL DEFAULT '1',
            `max_sub_users` int NOT NULL DEFAULT '10',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $conn->query("
        INSERT INTO `packages` (`id`, `name`, `description`, `permissions`, `is_active`, `max_sub_users`)
        VALUES (1, 'پاکێجی تەواو', 'Full Access', '{}', 1, 999)
        ON DUPLICATE KEY UPDATE `is_active` = 1, `max_sub_users` = 999;
    ");
} catch (Throwable $e) {
    $notices[] = "Packages: " . $e->getMessage();
}

// 2. Ensure users table exists & insert admin and demo users
try {
    @$conn->query("ALTER TABLE `users` MODIFY COLUMN `expiration_date` DATETIME DEFAULT NULL");

    $conn->query("
        INSERT INTO `users` (`id`, `business_name`, `email`, `password`, `phone`, `address`, `status`, `package_id`, `expiration_date`, `created_at`, `approved_at`, `ai_balance`, `support_balance`)
        VALUES 
        (1, 'فرۆشگای نموونە', 'demo@kashery.local', '{$hashed}', '07501234567', 'هەولێر', 'approved', 1, '2037-12-31 23:59:59', NOW(), NOW(), 100.00, 10000.000),
        (2, 'Nexora Master Admin', 'admin@kashery.local', '{$hashed}', '07500000000', 'کوردستان', 'approved', 1, '2037-12-31 23:59:59', NOW(), NOW(), 1000.00, 100000.000)
        ON DUPLICATE KEY UPDATE `password` = '{$hashed}', `status` = 'approved', `expiration_date` = '2037-12-31 23:59:59';
    ");
    $notices[] = "✔ Passwords for both admin@kashery.local and demo@kashery.local are set to: 123456";
} catch (Throwable $e) {
    $notices[] = "Users: " . $e->getMessage();
}

// 3. Ensure settings
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS `settings` (
            `id` int NOT NULL AUTO_INCREMENT,
            `user_id` int NOT NULL,
            `business_type_id` int DEFAULT '3',
            `receipt_header` text,
            `receipt_footer` text,
            `receipt_size` varchar(20) DEFAULT 'thermal',
            `currency` varchar(10) DEFAULT 'IQD',
            `tax_rate` decimal(5,2) DEFAULT '0.00',
            `low_stock_alert` int DEFAULT '1',
            `expiry_alert_days` int DEFAULT '30',
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $conn->query("
        INSERT INTO `settings` (`id`, `user_id`, `business_type_id`, `receipt_header`, `receipt_footer`, `receipt_size`, `currency`, `tax_rate`, `low_stock_alert`, `expiry_alert_days`)
        VALUES 
        (1, 1, 3, 'فرۆشگای نموونە', 'سوپاس بۆ کڕینەکەت', 'thermal', 'IQD', 0.00, 1, 30),
        (2, 2, 3, 'Nexora Master Admin', 'سوپاس بۆ سەردانەکەت', 'thermal', 'IQD', 0.00, 1, 30)
        ON DUPLICATE KEY UPDATE `business_type_id` = 3;
    ");
} catch (Throwable $e) {
    $notices[] = "Settings: " . $e->getMessage();
}

// 4. Ensure wallets
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS `wallets` (
            `id` int NOT NULL AUTO_INCREMENT,
            `user_id` int NOT NULL,
            `name` varchar(100) NOT NULL,
            `notes` text,
            `is_active` tinyint(1) NOT NULL DEFAULT '1',
            `is_default` tinyint(1) NOT NULL DEFAULT '0',
            `balance_iqd` decimal(15,3) NOT NULL DEFAULT '0.000',
            `balance_usd` decimal(15,3) NOT NULL DEFAULT '0.000',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $conn->query("
        INSERT INTO `wallets` (`user_id`, `name`, `is_default`, `is_active`, `balance_iqd`, `balance_usd`)
        VALUES 
        (1, 'قاسەی سەرەکی', 1, 1, 0.000, 0.000),
        (2, 'قاسەی سەرەکی', 1, 1, 0.000, 0.000)
        ON DUPLICATE KEY UPDATE `is_active` = 1, `is_default` = 1;
    ");
} catch (Throwable $e) {
    $notices[] = "Wallets: " . $e->getMessage();
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// Action: Direct 1-Click Login
$action = $_GET['action'] ?? '';
if ($action === 'direct_login') {
    $targetEmail = $_GET['email'] ?? 'admin@kashery.local';
    $stmt = $conn->prepare("SELECT id, business_name, email, approved_at FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $targetEmail);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($u) {
        $_SESSION['user_logged_in'] = true;
        $_SESSION['user_data'] = [
            'id' => $u['id'],
            'business_name' => $u['business_name'],
            'email' => $u['email'],
            'approved_at' => $u['approved_at']
        ];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['auth_calendar_day'] = date('Y-m-d');
        
        header("Location: user/dashboard/index.php");
        exit();
    }
}

// Fetch users
$users = [];
$res = $conn->query("SELECT id, business_name, email, status, expiration_date FROM users");
if ($res) {
    $users = $res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexoraCore Auth Helper</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; }
        .container { max-width: 800px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; font-size: 1.5rem; color: #38bdf8; }
        .alert { background: #064e3b; color: #6ee7b7; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 12px 14px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-size: 0.85rem; text-transform: uppercase; }
        .btn { display: inline-block; padding: 8px 16px; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 0.9rem; }
        .btn-green { background: #10b981; }
        .btn:hover { opacity: 0.9; }
        .card { background: #0b0f19; border: 1px solid #334155; border-radius: 8px; padding: 1.25rem; margin-top: 1.5rem; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔑 NexoraCore User Accounts & 1-Click Login</h1>
    
    <?php foreach ($notices as $n): ?>
        <div class="alert"><?php echo htmlspecialchars($n); ?></div>
    <?php endforeach; ?>

    <h3>Database Users (<?php echo count($users); ?>)</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Business Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Expiration</th>
                <th>Direct Login</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo $u['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($u['business_name']); ?></strong></td>
                    <td><code style="color:#38bdf8;"><?php echo htmlspecialchars($u['email']); ?></code></td>
                    <td><span style="color:#4ade80; font-weight:bold;"><?php echo htmlspecialchars($u['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($u['expiration_date'] ?? 'Lifetime'); ?></td>
                    <td>
                        <a href="debug_auth.php?action=direct_login&email=<?php echo urlencode($u['email']); ?>" class="btn btn-green">
                            🚀 1-Click Login
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="card">
        <h4>Credentials for Standard Login:</h4>
        <p><strong>Admin Email:</strong> <code>admin@kashery.local</code></p>
        <p><strong>Demo Email:</strong> <code>demo@kashery.local</code></p>
        <p><strong>Password:</strong> <code>123456</code></p>
        <div style="margin-top: 1rem; display: flex; gap: 1rem;">
            <a href="user/auth/login.php?unblock=1" class="btn">Go to Login Page →</a>
            <a href="health_check.php" class="btn" style="background: #64748b;">Diagnostics →</a>
        </div>
    </div>
</div>
</body>
</html>
