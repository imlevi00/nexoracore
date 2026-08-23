<?php
/**
 * Setup script for local XAMPP development.
 * Creates the unified database, app user, imports schemas, runs migrations, and seeds demo data.
 *
 * Usage: php database/setup_local.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$mysqlBin = 'C:\\xampp\\mysql\\bin\\mysql.exe';
$phpBin = PHP_BINARY;

$dbName = 'nexoracore_db';
$dbUser = 'itsmelevi';
$dbPassword = 'levi12345';

if (!is_file($mysqlBin)) {
    fwrite(STDERR, "MySQL not found at {$mysqlBin}. Update \$mysqlBin if needed.\n");
    exit(1);
}

$schemaFiles = [
    $root . '/database/test_test.sql',
    $root . '/database/kasher_platform/kasher_platform.sql',
    $root . '/database/kasher_zanyari/kasher_z.sql',
    $root . '/database/kasher_media/kasher_media.sql',
    $root . '/database/kasher_logs/kasher_logs.sql',
];

$migrationsDir = $root . '/database/migrations';
$extraSql = [
    $root . '/database/test_test_services.sql',
];

function run(string $cmd): int
{
    passthru($cmd, $code);
    return $code;
}

function mysqlCli(string $mysqlBin, string $sql, string $database = ''): int
{
    $target = $database !== '' ? ' ' . $database : '';
    return run('"' . $mysqlBin . '" -h 127.0.0.1 --protocol=TCP -u root' . $target . ' -e "' . $sql . '"');
}

function sanitizeSchemaSql(string $sql): string
{
    $sql = preg_replace(
        '/,\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY\s+\([^)]+\)\s+REFERENCES\s+`[^`]+`\s+\([^)]+\)(?:\s+ON DELETE\s+(?:SET NULL|CASCADE|RESTRICT|NO ACTION|\w+))?(?:\s+ON UPDATE\s+(?:SET NULL|CASCADE|RESTRICT|NO ACTION|\w+))?/i',
        '',
        $sql
    );
    $sql = preg_replace('/,\s*\)/', ')', $sql);
    $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/', '', $sql);

    return "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n" . $sql;
}

function importSqlFile(string $mysqlBin, string $db, string $file): int
{
    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexoracore_import_' . md5($file) . '.sql';
    file_put_contents($tmpFile, sanitizeSchemaSql(file_get_contents($file)));
    $sourcePath = str_replace('\\', '/', $tmpFile);
    $code = run('"' . $mysqlBin . '" -h 127.0.0.1 --protocol=TCP -u root ' . $db . ' -e "SOURCE ' . $sourcePath . ';"');
    @unlink($tmpFile);
    return $code;
}

function println(string $msg): void
{
    echo $msg . PHP_EOL;
}

println('=== NexoraCore — Local Database Setup ===');

println("Recreating database: {$dbName}");
$code = mysqlCli($mysqlBin, "DROP DATABASE IF EXISTS `{$dbName}`; CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
if ($code !== 0) {
    exit($code);
}

println("Ensuring user: {$dbUser}");
$escapedPass = str_replace("'", "''", $dbPassword);
$code = mysqlCli($mysqlBin, implode(' ', [
    "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$escapedPass}';",
    "CREATE USER IF NOT EXISTS '{$dbUser}'@'127.0.0.1' IDENTIFIED BY '{$escapedPass}';",
    "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost';",
    "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'127.0.0.1';",
    'FLUSH PRIVILEGES;',
]));
if ($code !== 0) {
    exit($code);
}

foreach ($schemaFiles as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing schema file: {$file}\n");
        exit(1);
    }
    println('Importing schema: ' . basename($file));
    $code = importSqlFile($mysqlBin, $dbName, $file);
    if ($code !== 0) {
        exit($code);
    }
}

foreach ($extraSql as $file) {
    if (!is_file($file)) {
        continue;
    }
    println('Running extra SQL: ' . basename($file));
    $code = importSqlFile($mysqlBin, $dbName, $file);
    if ($code !== 0) {
        exit($code);
    }
}

$sqlMigrations = glob($migrationsDir . '/*.sql') ?: [];
$phpMigrations = glob($migrationsDir . '/*.php') ?: [];
$migrationFiles = array_merge($sqlMigrations, $phpMigrations);
sort($migrationFiles);

foreach ($migrationFiles as $file) {
    $base = basename($file);
    println("Running migration: {$base}");
    if (str_ends_with($base, '.php')) {
        $code = run('"' . $phpBin . '" "' . $file . '"');
    } else {
        $code = importSqlFile($mysqlBin, $dbName, $file);
    }
    if ($code !== 0) {
        println("Warning: migration {$base} returned code {$code} (may be already applied)");
    }
}

$seedFiles = [
    $root . '/database/seed_demo_user.sql',
    $root . '/database/seed_demo_extra.sql',
];
foreach ($seedFiles as $seedFile) {
    if (!is_file($seedFile)) {
        continue;
    }
    println('Seeding: ' . basename($seedFile));
    $sourcePath = str_replace('\\', '/', $seedFile);
    $code = run('"' . $mysqlBin . '" -h 127.0.0.1 --protocol=TCP -u root -e "SOURCE ' . $sourcePath . ';"');
    if ($code !== 0) {
        exit($code);
    }
}

println('');
println('=== Setup complete ===');
println("Database: {$dbName}");
println("User: {$dbUser}");
println('Login: demo@kashery.local');
println('Password: 123456');
println('Dashboard: http://localhost/systam/Ka.sheryAi/user/auth/login.php');
