<?php
/**
 * چارەسەرکردنی کێشەی نەتوانینی زیادکردنی کاڵا و وێنەی کاڵا
 * 
 * کێشەکان:
 *   1. ستوونەکانی fabric_width / fabric_height / fabric_measure_unit نەبوونیان لە خشتەی products (لەسەر سێرڤەر)
 *   2. فۆڵدەری بارکردنی وێنە دروست نەبوو / مۆڵەتی نیشتنی نییە
 *
 * بۆ بەکارهێنان:
 *   Browser: http://<IP>/Ka.sheryAi/fix_products_migration.php
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

// ════════════════════════════════════════════════════════
// 1. پشکنینی کۆڕێکخستنی ستوونەکانی دوکانی پەردە
// ════════════════════════════════════════════════════════
log_result("کێشەی ستوونەکانی fabric_width/height پشکنین دەکرێت...", 'info');

$db = new Database();
$conn = $db->connect();

if (!($conn instanceof mysqli)) {
    log_result("هەڵە: پەیوەندی داتابەیس نەدەکرێت", 'error');
    goto render;
}

$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];
log_result("داتابەیس: {$dbName}", 'info');

/**
 * Add a column if it doesn't exist
 */
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

// fabric_width
$r1 = addColumnIfMissing($conn, $dbName, 'products', 'fabric_width',
    "`fabric_width` DECIMAL(10,3) NULL DEFAULT NULL COMMENT 'پانی قوماش' AFTER `image_path`");
if (str_starts_with($r1, 'error')) {
    log_result($r1, 'error');
} elseif (str_starts_with($r1, 'added')) {
    log_result("✓ ستوونی fabric_width زیادکرا بۆ خشتەی products", 'success');
} else {
    log_result("ستوونی fabric_width پێشتر هەیە — Skip", 'info');
}

// fabric_height
$r2 = addColumnIfMissing($conn, $dbName, 'products', 'fabric_height',
    "`fabric_height` DECIMAL(10,3) NULL DEFAULT NULL COMMENT 'بەرزی قوماش' AFTER `fabric_width`");
if (str_starts_with($r2, 'error')) {
    log_result($r2, 'error');
} elseif (str_starts_with($r2, 'added')) {
    log_result("✓ ستوونی fabric_height زیادکرا بۆ خشتەی products", 'success');
} else {
    log_result("ستوونی fabric_height پێشتر هەیە — Skip", 'info');
}

// fabric_measure_unit
$r3 = addColumnIfMissing($conn, $dbName, 'products', 'fabric_measure_unit',
    "`fabric_measure_unit` ENUM('cm','m') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cm' COMMENT 'یەکەی پێوانە (سم یان مەتر)' AFTER `fabric_height`");
if (str_starts_with($r3, 'error')) {
    log_result($r3, 'error');
} elseif (str_starts_with($r3, 'added')) {
    log_result("✓ ستوونی fabric_measure_unit زیادکرا بۆ خشتەی products", 'success');
} else {
    log_result("ستوونی fabric_measure_unit پێشتر هەیە — Skip", 'info');
}

// ════════════════════════════════════════════════════════
// 2. دڵنیابوون لە هەبوونی جۆری ئیشی curtain_shop
// ════════════════════════════════════════════════════════
log_result("پشکنینی business_types بۆ curtain_shop...", 'info');
$r = $conn->query("SELECT id FROM business_types WHERE code='curtain_shop' LIMIT 1");
if (!$r || $r->num_rows === 0) {
    $conn->query("INSERT INTO `business_types` (`id`,`code`,`name_ku`,`sort_order`) VALUES (5,'curtain_shop','دوکانی پەردە',5) ON DUPLICATE KEY UPDATE `name_ku`='دوکانی پەردە'");
    log_result("✓ جۆری ئیشی دوکانی پەردە زیادکرا", 'success');
} else {
    log_result("جۆری ئیشی curtain_shop پێشتر هەیە — Skip", 'info');
}

// ════════════════════════════════════════════════════════
// 3. پشکنینی ڕێکخستنی بەکارهێنەری دوکانی پەردە
// ════════════════════════════════════════════════════════
log_result("پشکنینی بەکارهێنەری sebar.home@kasher.com...", 'info');
$r = $conn->query("SELECT id FROM users WHERE email='sebar.home@kasher.com' LIMIT 1");
if ($r && $row = $r->fetch_assoc()) {
    $uid = (int)$row['id'];
    log_result("✓ بەکارهێنەر دۆزرایەوە (ID: {$uid})", 'success');

    // پشکنینی ڕێکخستنی business_type
    $s = $conn->query("SELECT business_type_id FROM settings WHERE user_id={$uid} LIMIT 1");
    if ($s && $sr = $s->fetch_assoc()) {
        $btId = (int)$sr['business_type_id'];
        if ($btId !== 5) {
            $conn->query("UPDATE settings SET business_type_id=5 WHERE user_id={$uid}");
            log_result("✓ ڕێکخستنی بزنسی بەکارهێنەر گۆڕدرا بۆ دوکانی پەردە (curtain_shop)", 'success');
        } else {
            log_result("بزنس-تایپی بەکارهێنەر باشە (curtain_shop)", 'info');
        }
    } else {
        $conn->query("INSERT INTO settings (user_id, business_type_id, receipt_header, receipt_footer, receipt_size, currency, tax_rate, low_stock_alert, expiry_alert_days) VALUES ({$uid}, 5, 'دوکانی پەردەی سێبار هۆم', 'سوپاس', 'thermal', 'IQD', 0.00, 1, 30)");
        log_result("✓ ڕێکخستنی بزنسی تازە دروستکرا بۆ بەکارهێنەر", 'success');
    }

    // پشکنین و دروستکردنی یەکەکانی مەتر و تۆپ
    $curtainUnits = [
        ['name' => 'مەتر', 'name_en' => 'Meter', 'symbol' => 'm', 'is_default' => 1],
        ['name' => 'تۆپ', 'name_en' => 'Roll', 'symbol' => 'top', 'is_default' => 0],
        ['name' => 'دانە / پارچە', 'name_en' => 'Piece', 'symbol' => 'pc', 'is_default' => 0],
        ['name' => 'سانتیمەتر', 'name_en' => 'Centimeter', 'symbol' => 'cm', 'is_default' => 0]
    ];
    foreach ($curtainUnits as $u) {
        $uCheck = $conn->query("SELECT id FROM units WHERE user_id={$uid} AND name='{$u['name']}' LIMIT 1");
        if (!$uCheck || $uCheck->num_rows === 0) {
            $conn->query("INSERT INTO units (user_id, name, name_en, symbol, is_default, is_active) VALUES ({$uid}, '{$u['name']}', '{$u['name_en']}', '{$u['symbol']}', {$u['is_default']}, 1)");
            log_result("✓ یەکەی '{$u['name']}' زیادکرا", 'success');
        }
    }
} else {
    log_result("بەکارهێنەری sebar.home@kasher.com نەدۆزرایەوە — تکایە create_curtain_user.php بەڕێوەبکە", 'warning');
}

// ════════════════════════════════════════════════════════
// 4. پشکنین و دروستکردنی فۆڵدەری بارکردنی وێنە (assets/uploads)
// ════════════════════════════════════════════════════════
log_result("پشکنینی فۆڵدەری بارکردنی وێنە...", 'info');
$uploadDirs = [
    $root . '/assets/uploads',
    $root . '/assets/uploads/img',
    $root . '/assets/uploads/img/products',
];

foreach ($uploadDirs as $dir) {
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0775, true)) {
            log_result("✓ فۆڵدەر دروستکرا: " . str_replace($root, '', $dir), 'success');
        } else {
            log_result("✗ نەتوانرا فۆڵدەر دروست بکرێت: " . str_replace($root, '', $dir), 'error');
        }
    } else {
        log_result("فۆڵدەر بەردەستە: " . str_replace($root, '', $dir), 'info');
    }

    // پشکنینی مۆڵەت نووسین
    if (is_dir($dir)) {
        if (is_writable($dir)) {
            log_result("  ✓ مۆڵەتی نووسین هەیە", 'success');
        } else {
            @chmod($dir, 0775);
            if (is_writable($dir)) {
                log_result("  ✓ مۆڵەتی نووسین چاک کرایەوە", 'success');
            } else {
                log_result("  ✗ مۆڵەتی نووسین نییە — تکایە chmod 775 بکە: " . $dir, 'error');
            }
        }
    }
}

// ════════════════════════════════════════════════════════
// 5. پشکنینی ستوونی image_path لە خشتەی products
// ════════════════════════════════════════════════════════
log_result("پشکنینی ستوونی image_path لە products...", 'info');
$r5 = addColumnIfMissing($conn, $dbName, 'products', 'image_path',
    "`image_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'ڕێڕەوی وێنە' AFTER `currency`");
if (str_starts_with($r5, 'error')) {
    log_result($r5, 'error');
} elseif (str_starts_with($r5, 'added')) {
    log_result("✓ ستوونی image_path زیادکرا", 'success');
} else {
    log_result("ستوونی image_path پێشتر هەیە — Skip", 'info');
}

// ════════════════════════════════════════════════════════
// 6. پشکنینی ستوونی products بۆ ئاماری گشتی
// ════════════════════════════════════════════════════════
log_result("ستوونەکانی خشتەی products بپشکنین...", 'info');
$r6 = $conn->query("SHOW COLUMNS FROM products LIKE 'fabric%'");
$fabricCols = [];
if ($r6) {
    while ($row = $r6->fetch_assoc()) {
        $fabricCols[] = $row['Field'];
    }
}
if (count($fabricCols) >= 3) {
    log_result("✓ هەموو ستوونەکانی قیاسی پەردە بەردەستن: " . implode(', ', $fabricCols), 'success');
} else {
    log_result("⚠ تەنها ئەمانە دۆزرانەوە: " . implode(', ', $fabricCols), 'warning');
}

// ════════════════════════════════════════════════════════
// 7. پشکنینی ستوونی product_barcodes
// ════════════════════════════════════════════════════════
log_result("پشکنینی خشتەی product_barcodes...", 'info');
$r7 = $conn->query("SHOW TABLES LIKE 'product_barcodes'");
if (!$r7 || $r7->num_rows === 0) {
    log_result("✗ خشتەی product_barcodes نییە! تکایە deploy_database.php بەڕێوەبکە", 'error');
} else {
    log_result("✓ خشتەی product_barcodes بەردەستە", 'success');
}

log_result("═══════════ چارەسەرکردن تەواو بوو ═══════════", 'info');

render:
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چارەسەرکردنی کێشەی زیادکردنی کاڵا</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Vazirmatn', -apple-system, sans-serif; background: #0f172a; color: #f1f5f9; padding: 24px; margin: 0; }
        .container { max-width: 750px; margin: 0 auto; background: #1e293b; border-radius: 16px; padding: 28px; border: 1px solid #334155; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
        h1 { font-size: 20px; font-weight: 800; color: #e2e8f0; margin: 0 0 20px; display: flex; align-items: center; gap: 10px; }
        .log-box { background: #0b0f19; border-radius: 10px; padding: 16px; max-height: 480px; overflow-y: auto; font-size: 14px; border: 1px solid #1e293b; }
        .log-line { padding: 5px 0; border-bottom: 1px solid #1e293b; display: flex; align-items: flex-start; gap: 10px; }
        .log-line:last-child { border-bottom: none; }
        .log-time { color: #475569; font-size: 12px; white-space: nowrap; margin-top: 2px; }
        .log-info { color: #94a3b8; }
        .log-success { color: #4ade80; }
        .log-warning { color: #fbbf24; }
        .log-error { color: #f87171; }
        .badge { padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; }
        .badge-success { background: rgba(74, 222, 128, 0.15); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.3); }
        .badge-error { background: rgba(248, 113, 113, 0.15); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.3); }
        .badge-warning { background: rgba(251, 191, 36, 0.15); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.3); }
        .actions { margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 10px; font-weight: 700; font-size: 14px; text-decoration: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; }
        .btn-warning { background: #d97706; color: white; }
        .btn-warning:hover { background: #b45309; }
        .summary-bar { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .summary-icon { font-size: 24px; }
        .summary-text { color: #34d399; font-weight: 700; font-size: 15px; }
        .summary-sub { color: #94a3b8; font-size: 13px; margin-top: 2px; }
    </style>
</head>
<body>

<div class="container">
    <h1>
        🔧 چارەسەرکردنی کێشەی زیادکردنی کاڵا و وێنە
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
                <?php echo $hasErrors ? "کێشەکان تەواو چارەسەر نەبوون ($errorCount هەڵە)" : "هەموو چارەسەرییەکان بە سەرکەوتوویی کابراون ($successCount کار)"; ?>
            </div>
            <div class="summary-sub">
                <?php echo $hasErrors ? "تکایە هەڵەکانی خوارەوە بپشکنە و بۆ چارەسەرکردن بخوێنەوە" : "ئێستا دەتوانیت کاڵا زیاد بکەیت بۆ سیستەمەکە"; ?>
            </div>
        </div>
    </div>

    <div class="log-box">
        <?php foreach ($results as $r): ?>
        <div class="log-line">
            <span class="log-time"><?php echo $r['time']; ?></span>
            <span class="log-<?php echo $r['type']; ?>"><?php echo htmlspecialchars($r['msg']); ?></span>
            <?php if ($r['type'] !== 'info'): ?>
            <span class="badge badge-<?php echo $r['type'] === 'success' ? 'success' : ($r['type'] === 'error' ? 'error' : 'warning'); ?>">
                <?php echo strtoupper($r['type']); ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="actions">
        <a href="user/products/add.php" class="btn btn-success">
            ➕ زیادکردنی کاڵای نوێ
        </a>
        <a href="user/auth/login.php" class="btn btn-primary">
            🔐 چوونەژوورەوە
        </a>
        <?php if ($hasErrors): ?>
        <a href="fix_products_migration.php" class="btn btn-warning">
            🔄 هەوڵدانەوە
        </a>
        <?php endif; ?>
    </div>
</div>

</body>
</html>

