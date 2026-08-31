<?php
/**
 * Create Dedicated Curtain Shop User
 * بەکارهێنەری تایبەت بە دوکانی پەردە (Sebar Home)
 * 
 * Browser: http://<domain-or-ip>/Ka.sheryAi/create_curtain_user.php
 * CLI:     php create_curtain_user.php
 */

declare(strict_types=1);

$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/config/security.php';

$isCli = (PHP_SAPI === 'cli');

$targetEmail = 'sebar.home@kasher.com';
$targetPassword = '87654321';
$targetBusinessName = 'دوکانی پەردەی سێبار هۆم (Sebar Home)';
$targetPhone = '07500000000';
$targetAddress = 'هەولێر - کوردستان';

function provisionCurtainShopUser(mysqli $conn, string $email, string $password, string $businessName, string $phone, string $address): array {
    global $isCli;

    // 1. دڵنیابوون لە هەبوونی جۆری ئیشی 'curtain_shop' لە خشتەی business_types
    $curtainTypeId = 5;
    $btCheck = $conn->query("SELECT id FROM business_types WHERE code = 'curtain_shop' LIMIT 1");
    if ($btCheck && $row = $btCheck->fetch_assoc()) {
        $curtainTypeId = (int)$row['id'];
    } else {
        $conn->query("
            INSERT INTO `business_types` (`id`, `code`, `name_ku`, `sort_order`)
            VALUES (5, 'curtain_shop', 'دوکانی پەردە', 5)
            ON DUPLICATE KEY UPDATE `name_ku` = 'دوکانی پەردە', `code` = 'curtain_shop'
        ");
        $curtainTypeId = (int)$conn->insert_id ?: 5;
    }

    // 2. دڵنیابوون لە هەبوونی پاکێجی تەواو (Super Admin VIP / Full Access Package)
    $conn->query("
        INSERT INTO `packages` (`id`, `name`, `description`, `permissions`, `is_active`, `max_sub_users`)
        VALUES (999, 'پاکێجی تایبەتی دوکانی پەردە (VIP)', 'دەسەڵاتی تەواوی دوکانی پەردە و سیستەم', '{}', 1, 999)
        ON DUPLICATE KEY UPDATE `is_active` = 1, `max_sub_users` = 999
    ");

    $features = [
        'pos_receipt_view', 'pos_receipt_a4_view', 'pos_barcode_scan',
        'employees_manage', 'employees_stats', 'employees_max_count',
        'companies_manage', 'customers_history', 'customers_account_statement',
        'wallets_manage', 'reports_item_section_profit', 'products_smart_scale',
        'multi_business_max_count'
    ];
    foreach ($features as $f) {
        $conn->query("
            INSERT INTO `package_feature_permissions` (`package_id`, `feature_key`, `is_enabled`, `lock_html`)
            VALUES (999, '{$f}', 1, '')
            ON DUPLICATE KEY UPDATE `is_enabled` = 1
        ");
    }

    // 3. دروستکردن یان نوێکردنەوەی بەکارهێنەر لە users
    $hashedPassword = Security::hashPassword($password);

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
                business_name = ?, 
                password = ?, 
                phone = ?, 
                address = ?, 
                status = 'approved', 
                package_id = 999, 
                expiration_date = '2099-12-31 23:59:59',
                approved_at = NOW(),
                ai_balance = 999.00,
                support_balance = 99999.00
            WHERE id = ?
        ");
        $update->bind_param("ssssi", $businessName, $hashedPassword, $phone, $address, $userId);
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
        return ['success' => false, 'message' => 'هەڵە ڕوویدا لە دروستکردنی بەکارهێنەر: ' . $conn->error];
    }

    // 4. دانانی ڕێکخستنەکان (Settings) تایبەت بە دوکانی پەردە
    $conn->query("
        INSERT INTO `settings` (`user_id`, `business_type_id`, `receipt_header`, `receipt_footer`, `receipt_size`, `currency`, `tax_rate`, `low_stock_alert`, `expiry_alert_days`)
        VALUES ({$userId}, {$curtainTypeId}, '{$businessName}', 'سوپاس بۆ کڕینەکەت لە دوکانی پەردەی سێبار هۆم', 'thermal', 'IQD', 0.00, 1, 30)
        ON DUPLICATE KEY UPDATE `business_type_id` = {$curtainTypeId}, `receipt_header` = '{$businessName}'
    ");

    // 5. دروستکردنی قاسەی سەرەکی
    $conn->query("
        INSERT INTO `wallets` (`user_id`, `name`, `is_default`, `is_active`, `balance_iqd`, `balance_usd`)
        VALUES ({$userId}, 'قاسەی سەرەکی', 1, 1, 0.000, 0.000)
        ON DUPLICATE KEY UPDATE `is_active` = 1, `is_default` = 1
    ");

    // 6. ڕێکخستنی هەژماری بەکارهێنەر (user_account_settings)
    $conn->query("
        INSERT INTO `user_account_settings` (`user_id`, `pos_show_zero_stock_products`, `purchases_use_weighted_avg_prices`, `recognize_customer_debt_revenue_at_sale`, `receipt_a4_items_font_size`, `pos_default_sale_currency`, `pos_default_price_type`, `pos_default_payment_is_credit`, `theme_mode`)
        VALUES ({$userId}, 1, 1, 1, 16, 'IQD', 'retail', 0, 'system')
        ON DUPLICATE KEY UPDATE `pos_show_zero_stock_products` = 1
    ");

    // 7. زیادکردنی یەکەکانی پێوانەی پەردە (Units)
    $units = [
        ['name' => 'مەتر', 'name_en' => 'Meter', 'symbol' => 'm', 'is_default' => 1],
        ['name' => 'دانە / پارچە', 'name_en' => 'Piece', 'symbol' => 'pc', 'is_default' => 0],
        ['name' => 'سانتیمەتر', 'name_en' => 'Centimeter', 'symbol' => 'cm', 'is_default' => 0]
    ];
    foreach ($units as $u) {
        $uStmt = $conn->prepare("SELECT id FROM units WHERE user_id = ? AND name = ? LIMIT 1");
        $uStmt->bind_param("is", $userId, $u['name']);
        $uStmt->execute();
        if ($uStmt->get_result()->num_rows === 0) {
            $uIns = $conn->prepare("INSERT INTO units (user_id, name, name_en, symbol, is_default, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $uIns->bind_param("isssi", $userId, $u['name'], $u['name_en'], $u['symbol'], $u['is_default']);
            $uIns->execute();
            $uIns->close();
        }
        $uStmt->close();
    }

    // 8. زیادکردنی کەتەلۆگەکانی پەردە (Categories)
    $categories = [
        'پەردەی قوماش',
        'پەردەی زێبرا و ڕۆڵەر',
        'تولی و تەنک',
        'ئێکسسوارات و میل و زنجیر'
    ];
    foreach ($categories as $cat) {
        $cStmt = $conn->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? LIMIT 1");
        $cStmt->bind_param("is", $userId, $cat);
        $cStmt->execute();
        if ($cStmt->get_result()->num_rows === 0) {
            $cIns = $conn->prepare("INSERT INTO categories (user_id, name, description, is_visible_on_website) VALUES (?, ?, 'بەشی تایبەت بە پەردە', 1)");
            $cIns->bind_param("is", $userId, $cat);
            $cIns->execute();
            $cIns->close();
        }
        $cStmt->close();
    }

    return [
        'success' => true,
        'user_id' => $userId,
        'email' => $email,
        'password' => $password,
        'business_name' => $businessName,
        'business_type_id' => $curtainTypeId,
        'message' => 'هەژماری تایبەت بە دوکانی پەردە بە سەرکەوتوویی دروستکرا و چالاک کرا!'
    ];
}

// کاتێک پەڕەکە بەڕێوەدەبرێت
$actionResult = provisionCurtainShopUser($conn, $targetEmail, $targetPassword, $targetBusinessName, $targetPhone, $targetAddress);

if ($isCli) {
    echo "====================================================\n";
    echo $actionResult['message'] . "\n";
    echo "User ID:       " . ($actionResult['user_id'] ?? '') . "\n";
    echo "Email:         " . $targetEmail . "\n";
    echo "Password:      " . $targetPassword . "\n";
    echo "Business Name: " . $targetBusinessName . "\n";
    echo "Business Type: Curtain Shop (ID: " . ($actionResult['business_type_id'] ?? 5) . ")\n";
    echo "Status:        Approved / Lifetime Access (2099)\n";
    echo "====================================================\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دروستکردنی بەکارهێنەری دوکانی پەردە - Sebar Home</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; font-family: 'Vazirmatn', -apple-system, sans-serif; }
        body {
            background: linear-gradient(135deg, #090e17 0%, #111827 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #f1f5f9;
            margin: 0;
        }
        .card {
            background: rgba(30, 41, 59, 0.9);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            max-width: 580px;
            width: 100%;
            padding: 35px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
        }
        .header-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 20px;
            color: white;
            box-shadow: 0 10px 20px -5px rgba(59, 130, 246, 0.5);
        }
        h1 {
            font-size: 22px;
            font-weight: 800;
            text-align: center;
            margin-bottom: 8px;
            color: #ffffff;
        }
        .subtitle {
            text-align: center;
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 25px;
        }
        .alert-box {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.4);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #34d399;
            font-weight: 600;
            font-size: 15px;
        }
        .info-table {
            width: 100%;
            background: rgba(15, 23, 42, 0.6);
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.07);
            overflow: hidden;
            margin-bottom: 25px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .info-row:last-child { border-bottom: none; }
        .info-label {
            color: #94a3b8;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-val {
            font-weight: 700;
            font-size: 14px;
            color: #38bdf8;
            direction: ltr;
            text-align: left;
        }
        .badge-type {
            background: rgba(139, 92, 246, 0.2);
            color: #c084fc;
            border: 1px solid rgba(139, 92, 246, 0.4);
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            direction: rtl;
        }
        .btn-login {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 16px;
            box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.5);
            transition: all 0.2s ease;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #1d4ed8, #2563eb);
            transform: translateY(-2px);
        }
        .features-list {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .features-title {
            font-size: 13px;
            font-weight: 700;
            color: #cbd5e1;
            margin-bottom: 10px;
        }
        .features-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            font-size: 13px;
            color: #94a3b8;
        }
        .features-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .features-item i {
            color: #34d399;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="header-icon">
        <i class="bi bi-window"></i>
    </div>
    <h1>هەژماری تایبەت بە دوکانی پەردە</h1>
    <p class="subtitle">سیستەمی بەڕێوەبردنی فرۆشتن و داتابەیس - Sebar Home</p>

    <div class="alert-box">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <span><?php echo htmlspecialchars($actionResult['message']); ?></span>
    </div>

    <div class="info-table">
        <div class="info-row">
            <span class="info-label"><i class="bi bi-envelope"></i> ئیمەیڵ / یوزەر:</span>
            <span class="info-val"><?php echo htmlspecialchars($targetEmail); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="bi bi-key"></i> تێپەڕەوشە (Password):</span>
            <span class="info-val" style="color: #f59e0b;"><?php echo htmlspecialchars($targetPassword); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="bi bi-shop"></i> ناوی فرۆشگا:</span>
            <span class="info-val" style="direction: rtl;"><?php echo htmlspecialchars($targetBusinessName); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="bi bi-tag"></i> جۆری چالاکی:</span>
            <span class="badge-type"><i class="bi bi-check2"></i> دوکانی پەردە (Curtain Shop)</span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="bi bi-shield-check"></i> دۆخی هەژمار:</span>
            <span style="color: #34d399; font-weight: 700;">چالاککراو (هەمیشەیی - 2099)</span>
        </div>
    </div>

    <div class="features-list">
        <div class="features-title">تایبەتمەندییە چالاککراوەکانی دوکانی پەردە:</div>
        <div class="features-grid">
            <div class="features-item"><i class="bi bi-check-lg"></i> پێوانەی پانتایی و بەرزی پەردە</div>
            <div class="features-item"><i class="bi bi-check-lg"></i> یەکەی مەتر و سانتیمەتر</div>
            <div class="features-item"><i class="bi bi-check-lg"></i> شاشەی تایبەتی فرۆشتنی پەردە</div>
            <div class="features-item"><i class="bi bi-check-lg"></i> کەتەلۆگ و قوماشی تایبەت</div>
            <div class="features-item"><i class="bi bi-check-lg"></i> قاسە و قەرز و کڕیاران</div>
            <div class="features-item"><i class="bi bi-check-lg"></i> وەسڵ و پسوولەی گەرمی و A4</div>
        </div>
    </div>

    <a href="user/auth/login.php" class="btn-login">
        <span>چوونەژوورەوە بۆ سیستەم</span>
        <i class="bi bi-arrow-left"></i>
    </a>
</div>

</body>
</html>

