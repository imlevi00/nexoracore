<?php
/**
 * System Health & Diagnostics Check
 * http://<server-ip>/Ka.sheryAi/health_check.php
 */

header('Content-Type: text/html; charset=utf-8');

$checks = [];

// 1. PHP Version
$phpVersion = PHP_VERSION;
$checks[] = [
    'name' => 'PHP Version (>= 7.4)',
    'status' => version_compare($phpVersion, '7.4.0', '>='),
    'details' => $phpVersion
];

// 2. PHP Extensions
$requiredExts = ['mysqli', 'pdo_mysql', 'mbstring', 'gd', 'curl', 'zip', 'xml', 'json'];
foreach ($requiredExts as $ext) {
    $loaded = extension_loaded($ext);
    $checks[] = [
        'name' => "PHP Extension: {$ext}",
        'status' => $loaded,
        'details' => $loaded ? 'Installed' : 'MISSING - Run apt-get install php-' . $ext
    ];
}

// 3. Config files existence
$root = __DIR__;
$configFiles = [
    'config/config.php' => $root . '/config/config.php',
    'config/env.php' => $root . '/config/env.php',
    'config/database.php' => $root . '/config/database.php',
    'config/security.php' => $root . '/config/security.php',
];
foreach ($configFiles as $label => $path) {
    $exists = is_file($path);
    $checks[] = [
        'name' => "File: {$label}",
        'status' => $exists,
        'details' => $exists ? 'OK' : 'MISSING'
    ];
}

// 4. Load Config & DB
$dbOk = false;
$dbDetails = '';
try {
    require_once $root . '/config/config.php';
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $dbOk = true;
        $dbDetails = "Connected successfully to " . DB_NAME . " (" . $conn->server_info . ")";
    } else {
        $dbDetails = "Failed connecting to database: " . ($conn ? $conn->connect_error : 'conn is null');
    }
} catch (Throwable $e) {
    $dbDetails = "Exception: " . $e->getMessage();
}
$checks[] = [
    'name' => 'Database Connection (MySQL)',
    'status' => $dbOk,
    'details' => $dbDetails
];

// 5. Database Tables
if ($dbOk) {
    $essentialTables = ['users', 'sub_users', 'products', 'settings', 'wallets', 'categories'];
    foreach ($essentialTables as $tbl) {
        $res = $conn->query("SHOW TABLES LIKE '{$tbl}'");
        $hasTbl = ($res && $res->num_rows > 0);
        $count = 0;
        if ($hasTbl) {
            $cntRes = $conn->query("SELECT COUNT(*) as c FROM `{$tbl}`");
            $count = $cntRes ? (int)$cntRes->fetch_assoc()['c'] : 0;
        }
        $checks[] = [
            'name' => "Database Table: {$tbl}",
            'status' => $hasTbl,
            'details' => $hasTbl ? "{$count} rows found" : "MISSING TABLE - Run deploy_database.php"
        ];
    }
}

// 6. Session Write Test
$sessionOk = false;
$sessionDetails = '';
try {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['__health_check'] = time();
        $sessionOk = true;
        $sessionDetails = "Session active (ID: " . session_id() . ")";
    } else {
        $sessionDetails = "Session not active";
    }
} catch (Throwable $e) {
    $sessionDetails = "Session Error: " . $e->getMessage();
}
$checks[] = [
    'name' => 'PHP Session Storage',
    'status' => $sessionOk,
    'details' => $sessionDetails
];

// 7. Directory Writable Test
$writableDirs = [
    'logs' => $root . '/logs',
    'cache' => $root . '/cache',
    'assets/uploads' => $root . '/assets/uploads',
];
foreach ($writableDirs as $name => $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
    $isWritable = is_writable($dir);
    $checks[] = [
        'name' => "Directory Writable: {$name}",
        'status' => $isWritable,
        'details' => $isWritable ? 'Writable (OK)' : 'Run on VPS: sudo chown -R www-data:www-data /var/www/html'
    ];
}

$allPassed = true;
foreach ($checks as $c) {
    if (!$c['status']) {
        $allPassed = false;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexoraCore - Server Diagnostics</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; }
        .container { max-width: 800px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; font-size: 1.5rem; display: flex; align-items: center; justify-content: space-between; }
        .badge { padding: 4px 12px; border-radius: 9999px; font-size: 0.85rem; font-weight: bold; }
        .badge.pass { background: #15803d; color: #dcfce7; }
        .badge.fail { background: #b91c1c; color: #fee2e2; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        th, td { text-align: left; padding: 12px 14px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-size: 0.85rem; text-transform: uppercase; }
        .status-ok { color: #4ade80; font-weight: bold; }
        .status-fail { color: #f87171; font-weight: bold; }
        .btn { display: inline-block; margin-top: 1.5rem; padding: 10px 20px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
<div class="container">
    <h1>
        NexoraCore Diagnostics Check
        <span class="badge <?php echo $allPassed ? 'pass' : 'fail'; ?>">
            <?php echo $allPassed ? 'ALL CHECKS PASSED' : 'ISSUES DETECTED'; ?>
        </span>
    </h1>
    <table>
        <thead>
            <tr>
                <th>Check Item</th>
                <th>Status</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($checks as $check): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($check['name']); ?></strong></td>
                    <td class="<?php echo $check['status'] ? 'status-ok' : 'status-fail'; ?>">
                        <?php echo $check['status'] ? '✔ PASS' : '✖ FAIL'; ?>
                    </td>
                    <td><?php echo htmlspecialchars($check['details']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div style="margin-top: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;">
        <?php if (!$allPassed): ?>
            <a href="database/deploy_database.php" class="btn" style="background: #10b981;">⚡ Run Database Setup & Fix All Tables</a>
        <?php endif; ?>
        <a href="create_user.php" class="btn" style="background: #8b5cf6;">👑 Create Full-Access Account</a>
        <a href="user/auth/login.php" class="btn">Go to Login Page →</a>
    </div>
</div>
</body>
</html>

