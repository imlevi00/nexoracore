<?php
/**
 * Universal Database Deployment Script for VPS & Web.
 * Can be run via Browser or CLI:
 *   CLI:     php database/deploy_database.php
 *   Browser: http://<ip>/Ka.sheryAi/database/deploy_database.php
 */

declare(strict_types=1);

// Disable time limit for large database imports
@set_time_limit(300);
@ini_set('memory_limit', '512M');

$root = dirname(__DIR__);
require_once $root . '/config/env.php';

$isCli = (PHP_SAPI === 'cli');

$dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
$dbName = defined('DB_NAME') ? DB_NAME : 'nexoracore_db';
$dbUser = defined('DB_USERNAME') ? DB_USERNAME : 'itsmelevi';
$dbPass = defined('DB_PASSWORD') ? DB_PASSWORD : 'levi12345';

$logs = [];
function out(string $msg, string $type = 'info'): void {
    global $isCli, $logs;
    $timestamp = date('H:i:s');
    $line = "[{$timestamp}] {$msg}";
    $logs[] = ['msg' => $msg, 'time' => $timestamp, 'type' => $type];
    if ($isCli) {
        echo $line . PHP_EOL;
    }
}

out("Starting NexoraCore Database Deployment...", "info");
out("Host: {$dbHost} | Database: {$dbName} | User: {$dbUser}", "info");

// 1. Ensure required directories exist and are writable
$dirs = [
    $root . '/logs',
    $root . '/cache',
    $root . '/assets/uploads',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
    out("Ensured directory: " . basename($dir), "info");
}

// 2. Connect to MySQL Server
$conn = null;
try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+03:00'");
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    out("Connected successfully to MySQL database `{$dbName}`.", "success");
} catch (Throwable $e) {
    out("FATAL: " . $e->getMessage(), "error");
    if (!$isCli) {
        renderHtml("Connection Error", $logs, false);
    }
    exit(1);
}

function sanitizeSql(string $sql): string {
    $sql = preg_replace(
        '/,\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY\s+\([^)]+\)\s+REFERENCES\s+`[^`]+`\s+\([^)]+\)(?:\s+ON DELETE\s+(?:SET NULL|CASCADE|RESTRICT|NO ACTION|\w+))?(?:\s+ON UPDATE\s+(?:SET NULL|CASCADE|RESTRICT|NO ACTION|\w+))?/i',
        '',
        $sql
    );
    $sql = preg_replace('/,\s*\)/', ')', $sql);
    $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/', '', $sql);
    return "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n" . $sql;
}

function runSqlFile(mysqli $conn, string $filePath): bool {
    if (!is_file($filePath)) {
        out("File not found: " . basename($filePath), "warning");
        return false;
    }

    $fileName = basename($filePath);
    $raw = file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return true;
    }

    $sanitized = sanitizeSql($raw);
    
    // Execute using multi_query
    if ($conn->multi_query($sanitized)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }

    if ($conn->errno) {
        $err = $conn->error;
        if (!str_contains($err, 'already exists') && !str_contains($err, 'Duplicate column')) {
            out("Import note [{$fileName}]: {$err}", "warning");
        }
    } else {
        out("Imported: {$fileName}", "success");
    }

    return true;
}

// 3. Import Base Schemas
out("\n--- 1. Importing Schemas ---", "info");
$schemaFiles = [
    $root . '/database/test_test.sql',
    $root . '/database/kasher_platform/kasher_platform.sql',
    $root . '/database/kasher_zanyari/kasher_z.sql',
    $root . '/database/kasher_media/kasher_media.sql',
    $root . '/database/kasher_logs/kasher_logs.sql',
    $root . '/database/test_test_services.sql',
];

foreach ($schemaFiles as $file) {
    runSqlFile($conn, $file);
}

// 4. Run Migrations
out("\n--- 2. Running Migrations ---", "info");
$migrationsDir = $root . '/database/migrations';
$sqlMigrations = glob($migrationsDir . '/*.sql') ?: [];
$phpMigrations = glob($migrationsDir . '/*.php') ?: [];
$migrationFiles = array_merge($sqlMigrations, $phpMigrations);
sort($migrationFiles);

$phpBin = PHP_BINARY;
foreach ($migrationFiles as $file) {
    $base = basename($file);
    if (str_ends_with($base, '.php')) {
        out("Running PHP migration: {$base}", "info");
        try {
            // Include PHP migration directly
            ob_start();
            include $file;
            ob_end_clean();
            out("Completed PHP migration: {$base}", "success");
        } catch (Throwable $pe) {
            out("PHP migration warning [{$base}]: " . $pe->getMessage(), "warning");
        }
    } else {
        runSqlFile($conn, $file);
    }
}

// 5. Seed Data
out("\n--- 3. Seeding Demo Users & Initial Data ---", "info");
$seedFiles = [
    $root . '/database/seed_demo_user.sql',
    $root . '/database/seed_demo_extra.sql',
];
foreach ($seedFiles as $file) {
    runSqlFile($conn, $file);
}

// Ensure Admin & Demo users exist with password "123456"
$hashedPass = password_hash('123456', PASSWORD_BCRYPT, ['cost' => 12]);
$conn->query("
    INSERT INTO `users` (`id`, `business_name`, `email`, `password`, `phone`, `address`, `telegram_sent`, `status`, `package_id`, `expiration_date`, `created_at`, `approved_at`, `ai_balance`, `support_balance`)
    VALUES 
    (1, 'فرۆشگای نموونە', 'demo@kashery.local', '{$hashedPass}', '07501234567', 'هەولێر', 0, 'approved', 1, '2037-12-31 23:59:59', NOW(), NOW(), 100.00, 10000.000),
    (2, 'Nexora Master Admin', 'admin@kashery.local', '{$hashedPass}', '07500000000', 'کوردستان', 0, 'approved', 1, '2037-12-31 23:59:59', NOW(), NOW(), 1000.00, 100000.000)
    ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `status` = 'approved', `expiration_date` = '2037-12-31 23:59:59'
");

$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// Verify created tables
$res = $conn->query("SHOW TABLES");
$tblCount = $res ? $res->num_rows : 0;
out("\n==================================================", "info");
out("SUCCESS! {$tblCount} database tables are ready.", "success");
out("Admin Login: admin@kashery.local | Pass: 123456", "success");
out("Demo Login:  demo@kashery.local  | Pass: 123456", "success");
out("==================================================", "info");

if (!$isCli) {
    renderHtml("Deployment Complete", $logs, true);
}

function renderHtml(string $title, array $logs, bool $success): void {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($title); ?> - NexoraCore</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; }
        .container { max-width: 800px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; font-size: 1.5rem; display: flex; align-items: center; justify-content: space-between; }
        .badge { padding: 4px 12px; border-radius: 9999px; font-size: 0.85rem; font-weight: bold; }
        .badge.pass { background: #15803d; color: #dcfce7; }
        .badge.fail { background: #b91c1c; color: #fee2e2; }
        .log-box { background: #0b0f19; border-radius: 8px; padding: 1rem; margin-top: 1.5rem; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 0.85rem; }
        .log-line { margin-bottom: 4px; }
        .log-info { color: #94a3b8; }
        .log-success { color: #4ade80; }
        .log-warning { color: #facc15; }
        .log-error { color: #f87171; }
        .btn-group { margin-top: 2rem; display: flex; gap: 1rem; }
        .btn { display: inline-block; padding: 10px 20px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; }
        .btn-success { background: #10b981; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
<div class="container">
    <h1>
        NexoraCore Database Deployment
        <span class="badge <?php echo $success ? 'pass' : 'fail'; ?>">
            <?php echo $success ? 'COMPLETED' : 'FAILED'; ?>
        </span>
    </h1>
    <div class="log-box">
        <?php foreach ($logs as $l): ?>
            <div class="log-line log-<?php echo $l['type']; ?>">
                [<?php echo $l['time']; ?>] <?php echo htmlspecialchars($l['msg']); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="btn-group">
        <a href="../health_check.php" class="btn btn-success">Check Diagnostics →</a>
        <a href="../user/auth/login.php" class="btn">Go to Login Page →</a>
    </div>
</div>
</body>
</html>
<?php
}

