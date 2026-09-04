<?php
/**
 * چارەسەرکردنی کێشەکانی بەشی خەرجیەکان و قەرزەکانی
 * 
 * کێشەکان:
 *   1. دروستکردن و پشکنینی خشتەکانی expense_types, expenses, expense_credits, expense_credit_payments
 *   2. زیادکردنی ستوونی currency بۆ expenses, expense_credits, expense_credit_payments
 *   3. زیادکردنی wallet_id بۆ expense_credit_payments
 *   4. نوێکردنەوەی status enum لە expense_credits بۆ زیادکردنی 'pending'
 *   5. پاککردنەوە و ڕێکخستنی دراو بە IQD بۆ تۆمارە کۆنەکان
 *
 * بەکارهێنان لە براوزەر:
 *   http://<IP>/Ka.sheryAi/fix_expenses_migration.php
 */

declare(strict_types=1);

@set_time_limit(120);
@ini_set('memory_limit', '256M');

$root = __DIR__;
require_once $root . '/config/database.php';

$isCli = (PHP_SAPI === 'cli');
$results = [];

function log_result(string $msg, string $type = 'info'): void {
    global $results;
    $results[] = ['msg' => $msg, 'type' => $type, 'time' => date('H:i:s')];
    if (PHP_SAPI === 'cli') {
        echo "[{$type}] {$msg}" . PHP_EOL;
    }
}

log_result("دەستپێکردنی پشکنین و چاکسازی بەشی خەرجیەکان و قەرزەکانی...", 'info');

$db = new Database();
$conn = $db->connect();

if (!($conn instanceof mysqli)) {
    log_result("هەڵە: پەیوەندی داتابەیس دروست نەبوو", 'error');
    goto render;
}

$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? 'test_test';
log_result("داتابەیس: {$dbName}", 'info');

function addColumnIfMissing(mysqli $conn, string $dbName, string $table, string $col, string $definition): string {
    $r = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='$table' AND COLUMN_NAME='$col'");
    $exists = $r && ((int)$r->fetch_row()[0]) > 0;
    if ($exists) {
        return "skip:{$table}.{$col}";
    }
    $ok = $conn->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    if (!$ok) {
        return "error:{$table}.{$col}: " . $conn->error;
    }
    return "added:{$table}.{$col}";
}

// ════════════════════════════════════════════════════════
// 1. خشتەی expense_types
// ════════════════════════════════════════════════════════
$t1 = $conn->query("
    CREATE TABLE IF NOT EXISTS `expense_types` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `user_id` INT NOT NULL,
      `name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `is_recurring` TINYINT(1) DEFAULT '1',
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
if ($t1) {
    log_result("✓ خشتەی expense_types ئامادەیە", 'success');
} else {
    log_result("هەڵە لە خشتەی expense_types: " . $conn->error, 'error');
}

// ════════════════════════════════════════════════════════
// 2. خشتەی expenses
// ════════════════════════════════════════════════════════
$t2 = $conn->query("
    CREATE TABLE IF NOT EXISTS `expenses` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `user_id` INT NOT NULL,
      `expense_type_id` INT DEFAULT NULL,
      `expense_name` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `amount` DECIMAL(10,3) NOT NULL,
      `currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
      `payment_method` ENUM('cash','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
      `is_recurring` TINYINT(1) DEFAULT '0',
      `has_credit` TINYINT(1) DEFAULT '0',
      `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `receipt_number` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `expense_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_user_id` (`user_id`),
      KEY `idx_expense_type_id` (`expense_type_id`),
      KEY `idx_expense_date` (`expense_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
if ($t2) {
    log_result("✓ خشتەی expenses ئامادەیە", 'success');
} else {
    log_result("هەڵە لە خشتەی expenses: " . $conn->error, 'error');
}

$c1 = addColumnIfMissing($conn, $dbName, 'expenses', 'currency', "`currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `amount`");
if (str_starts_with($c1, 'error')) {
    log_result($c1, 'error');
} elseif (str_starts_with($c1, 'added')) {
    log_result("✓ ستوونی currency زیادکرا بۆ expenses", 'success');
} else {
    log_result("ستوونی expenses.currency پێشتر هەیە — Skip", 'info');
}

// ════════════════════════════════════════════════════════
// 3. خشتەی expense_credits
// ════════════════════════════════════════════════════════
$t3 = $conn->query("
    CREATE TABLE IF NOT EXISTS `expense_credits` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `user_id` INT NOT NULL,
      `expense_id` INT NOT NULL,
      `creditor_name` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `creditor_phone` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `total_amount` DECIMAL(10,3) NOT NULL,
      `paid_amount` DECIMAL(10,3) DEFAULT '0.000',
      `remaining_amount` DECIMAL(10,3) NOT NULL,
      `currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
      `due_date` DATE DEFAULT NULL,
      `payment_terms` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `status` ENUM('active','completed','overdue','pending') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
      `notes` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_user_id` (`user_id`),
      KEY `idx_expense_id` (`expense_id`),
      KEY `idx_user_status` (`user_id`,`status`),
      KEY `idx_due_date` (`due_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
if ($t3) {
    log_result("✓ خشتەی expense_credits ئامادەیە", 'success');
} else {
    log_result("هەڵە لە خشتەی expense_credits: " . $conn->error, 'error');
}

$c2 = addColumnIfMissing($conn, $dbName, 'expense_credits', 'currency', "`currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `remaining_amount`");
if (str_starts_with($c2, 'error')) {
    log_result($c2, 'error');
} elseif (str_starts_with($c2, 'added')) {
    log_result("✓ ستوونی currency زیادکرا بۆ expense_credits", 'success');
} else {
    log_result("ستوونی expense_credits.currency پێشتر هەیە — Skip", 'info');
}

// نوێکردنەوەی status enum
$chkStatus = $conn->query("SHOW COLUMNS FROM `expense_credits` LIKE 'status'");
if ($chkStatus && ($row = $chkStatus->fetch_assoc())) {
    $typeStr = (string)($row['Type'] ?? '');
    if (strpos($typeStr, 'pending') === false) {
        $mStatus = $conn->query("ALTER TABLE `expense_credits` MODIFY COLUMN `status` ENUM('active','completed','overdue','pending') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'");
        if ($mStatus) {
            log_result("✓ جۆری status لە expense_credits نوێکرایەوە (pending زیادکرا)", 'success');
        } else {
            log_result("هەڵە لە نوێکردنەوەی status: " . $conn->error, 'error');
        }
    } else {
        log_result("جۆری status پێشتر pending لەخۆ دەگرێت — Skip", 'info');
    }
}
if ($chkStatus instanceof mysqli_result) {
    $chkStatus->close();
}

// ════════════════════════════════════════════════════════
// 4. خشتەی expense_credit_payments
// ════════════════════════════════════════════════════════
$t4 = $conn->query("
    CREATE TABLE IF NOT EXISTS `expense_credit_payments` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `user_id` INT NOT NULL,
      `expense_credit_id` INT NOT NULL,
      `payment_amount` DECIMAL(10,3) NOT NULL,
      `currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD',
      `payment_method` ENUM('cash','bank_transfer','check','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
      `wallet_id` INT DEFAULT NULL,
      `payment_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `receipt_number` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `notes` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_user_id` (`user_id`),
      KEY `idx_expense_credit_id` (`expense_credit_id`),
      KEY `idx_payment_date` (`payment_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
if ($t4) {
    log_result("✓ خشتەی expense_credit_payments ئامادەیە", 'success');
} else {
    log_result("هەڵە لە خشتەی expense_credit_payments: " . $conn->error, 'error');
}

$c3 = addColumnIfMissing($conn, $dbName, 'expense_credit_payments', 'currency', "`currency` ENUM('IQD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IQD' AFTER `payment_amount`");
if (str_starts_with($c3, 'error')) {
    log_result($c3, 'error');
} elseif (str_starts_with($c3, 'added')) {
    log_result("✓ ستوونی currency زیادکرا بۆ expense_credit_payments", 'success');
} else {
    log_result("ستوونی expense_credit_payments.currency پێشتر هەیە — Skip", 'info');
}

$c4 = addColumnIfMissing($conn, $dbName, 'expense_credit_payments', 'wallet_id', "`wallet_id` INT DEFAULT NULL AFTER `payment_method`");
if (str_starts_with($c4, 'error')) {
    log_result($c4, 'error');
} elseif (str_starts_with($c4, 'added')) {
    log_result("✓ ستوونی wallet_id زیادکرا بۆ expense_credit_payments", 'success');
} else {
    log_result("ستوونی expense_credit_payments.wallet_id پێشتر هەیە — Skip", 'info');
}

// ════════════════════════════════════════════════════════
// 5. نوێکردنەوەی دراو بۆ تۆمارە کۆنەکان
// ════════════════════════════════════════════════════════
$conn->query("UPDATE `expenses` SET `currency` = 'IQD' WHERE `currency` IS NULL OR `currency` = ''");
$conn->query("UPDATE `expense_credits` SET `currency` = 'IQD' WHERE `currency` IS NULL OR `currency` = ''");
$conn->query("UPDATE `expense_credit_payments` SET `currency` = 'IQD' WHERE `currency` IS NULL OR `currency` = ''");
log_result("✓ دراوی تۆمارەکانی پێشوو بە IQD ڕێکخرانەوە", 'success');

render:
if ($isCli) {
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چارەسەرکردنی کێشەکانی خەرجیەکان</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            direction: rtl;
            padding: 30px 16px;
            min-height: 100vh;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        h1 {
            font-size: 1.6rem;
            color: #38bdf8;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .summary-bar {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .summary-icon { font-size: 2rem; }
        .summary-text { font-size: 1.1rem; font-weight: 600; color: #4ade80; }
        .summary-sub { font-size: 0.85rem; color: #94a3b8; margin-top: 2px; }
        .log-box {
            background: #020617;
            border: 1px solid #1e293b;
            border-radius: 10px;
            padding: 16px;
            font-family: 'Consolas', monospace;
            font-size: 0.85rem;
            margin-bottom: 24px;
            max-height: 460px;
            overflow-y: auto;
        }
        .log-line {
            padding: 6px 8px;
            border-radius: 6px;
            margin-bottom: 4px;
            display: flex;
            align-items: baseline;
            gap: 10px;
        }
        .log-time { color: #475569; font-size: 0.75rem; min-width: 60px; }
        .log-info { color: #94a3b8; }
        .log-success { color: #4ade80; }
        .log-error { color: #f87171; }
        .badge {
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
            margin-right: auto;
        }
        .badge-success { background: #166534; color: #86efac; }
        .badge-error { background: #991b1b; color: #fca5a5; }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: opacity .15s;
        }
        .btn:hover { opacity: 0.85; }
        .btn-success { background: #22c55e; color: #000; }
        .btn-primary { background: #38bdf8; color: #000; }
        .btn-warning { background: #f59e0b; color: #000; }
    </style>
</head>
<body>

<div class="container">
    <h1>
        🔧 چارەسەرکردنی کێشەکانی خەرجیەکان و قەرزەکانی
    </h1>

    <?php
    $hasErrors = false;
    $errorCount = 0;
    $successCount = 0;
    foreach ($results as $r) {
        if ($r['type'] === 'error') { $hasErrors = true; $errorCount++; }
        if ($r['type'] === 'success') { $successCount++; }
    }
    ?>

    <div class="summary-bar" style="<?php echo $hasErrors ? 'background:rgba(248,113,113,0.1);border-color:rgba(248,113,113,0.3)' : ''; ?>">
        <span class="summary-icon"><?php echo $hasErrors ? '❌' : '✅'; ?></span>
        <div>
            <div class="summary-text" style="<?php echo $hasErrors ? 'color:#f87171' : ''; ?>">
                <?php echo $hasErrors ? "کێشەکان تەواو چارەسەر نەبوون ($errorCount هەڵە)" : "هەموو خشتەکان و ستوونەکان بە سەرکەوتوویی ئامادەکران ($successCount کار)"; ?>
            </div>
            <div class="summary-sub">
                <?php echo $hasErrors ? "تکایە هەڵەکانی خوارەوە بپشکنە" : "ئێستا دەتوانیت بە تەواوی بەشی قەرزەکان و خەرجیەکان بەکاربهێنیت"; ?>
            </div>
        </div>
    </div>

    <div class="log-box">
        <?php foreach ($results as $r): ?>
        <div class="log-line">
            <span class="log-time"><?php echo $r['time']; ?></span>
            <span class="log-<?php echo $r['type']; ?>"><?php echo htmlspecialchars($r['msg']); ?></span>
            <?php if ($r['type'] !== 'info'): ?>
            <span class="badge badge-<?php echo $r['type'] === 'success' ? 'success' : 'error'; ?>">
                <?php echo strtoupper($r['type']); ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="actions">
        <a href="user/expenses/credits/index.php" class="btn btn-success">
            💳 قەرزەکانی خەرجی
        </a>
        <a href="user/expenses/index.php" class="btn btn-primary">
            💰 هەموو خەرجیەکان
        </a>
        <a href="user/expenses/main.php" class="btn btn-warning">
            🏠 بەشی سەرەکی خەرجیەکان
        </a>
    </div>
</div>

</body>
</html>

