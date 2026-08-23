<?php
/**
 * Gmail allowlist + expiry for restricted online shop (shop_google_restrict).
 */

require_once __DIR__ . '/session_helper.php';

/**
 * Ensure DB columns exist (idempotent).
 */
function shop_google_ensure_db_schema(mysqli $conn): void
{
    $col = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'shop_google_restrict'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE `website_settings` ADD COLUMN `shop_google_restrict` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=Gmail allowlist for shop' AFTER `updated_at`");
    }
    if ($col) {
        $col->close();
    }

    $colExp = $conn->query("SHOW COLUMNS FROM customer_gmail_links LIKE 'access_expires_at'");
    if ($colExp && $colExp->num_rows === 0) {
        $conn->query("ALTER TABLE `customer_gmail_links` ADD COLUMN `access_expires_at` datetime DEFAULT NULL COMMENT 'Shop access expiry; NULL=unlimited' AFTER `gmail`");
    }
    if ($colExp) {
        $colExp->close();
    }
}

/**
 * @return array{ok:bool,reason?:string,customer?:array}
 */
function shop_google_validate_access(mysqli $conn, int $merchantUserId, string $emailLower): array
{
    $emailLower = strtolower(trim($emailLower));
    if ($emailLower === '') {
        return ['ok' => false, 'reason' => 'not_allowed'];
    }

    $sql = "
        SELECT c.id AS customer_id, c.name, c.phone, c.address, c.status,
               cgl.gmail,
               cgl.access_expires_at
        FROM customer_gmail_links cgl
        INNER JOIN customers c ON c.id = cgl.customer_id AND c.user_id = cgl.user_id
        WHERE cgl.user_id = ? AND LOWER(TRIM(cgl.gmail)) = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'reason' => 'not_allowed'];
    }
    $stmt->bind_param('is', $merchantUserId, $emailLower);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'reason' => 'not_allowed'];
    }
    if (($row['status'] ?? '') !== 'active') {
        return ['ok' => false, 'reason' => 'inactive_customer'];
    }
    $exp = $row['access_expires_at'] ?? null;
    if ($exp !== null && $exp !== '' && strtotime($exp) < time()) {
        return ['ok' => false, 'reason' => 'expired'];
    }

    return ['ok' => true, 'customer' => $row];
}

/**
 * Find or create web_customers row for checkout / web_orders FK.
 */
function shop_google_ensure_web_customer(mysqli $conn, array $crmCustomer, string $email, string $name): int
{
    $email = strtolower(trim($email));
    $stmt = $conn->prepare('SELECT id FROM web_customers WHERE LOWER(TRIM(email)) = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing) {
        return (int) $existing['id'];
    }

    $phone = preg_replace('/\D/', '', (string) ($crmCustomer['phone'] ?? ''));
    if (strlen($phone) < 11) {
        $phone = '00000000000';
    } else {
        $phone = substr($phone, 0, 11);
    }
    $address = (string) ($crmCustomer['address'] ?? '');
    $displayName = $name !== '' ? $name : $email;

    $stmt = $conn->prepare('SELECT id FROM web_customers WHERE phone = ? LIMIT 1');
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $byPhone = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($byPhone) {
        $wid = (int) $byPhone['id'];
        $stmt = $conn->prepare('SELECT id FROM web_customers WHERE LOWER(TRIM(email)) = ? AND id != ? LIMIT 1');
        $stmt->bind_param('si', $email, $wid);
        $stmt->execute();
        $emailOther = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($emailOther) {
            return (int) $emailOther['id'];
        }
        $upd = $conn->prepare('UPDATE web_customers SET name = ?, email = ?, address = ? WHERE id = ?');
        $upd->bind_param('sssi', $displayName, $email, $address, $wid);
        $upd->execute();
        $upd->close();
        return $wid;
    }

    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $ins = $conn->prepare('INSERT INTO web_customers (name, phone, email, address, password_hash, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    $ins->bind_param('sssss', $displayName, $phone, $email, $address, $hash);
    $ins->execute();
    $newId = (int) $conn->insert_id;
    $ins->close();

    return $newId;
}

/**
 * Block storefront when restrict mode requires Google and session is invalid.
 */
function shop_google_access_render_blocked(string $slug, string $reason): void
{
    $loginUrl = SITE_URL . 'videos/google-login.php?return_to=' . rawurlencode('web/shop.php?slug=' . $slug);
    $messages = [
        'login_required' => 'بۆ بینینی ئەم فرۆشگایە پێویستە بە هەژماری Google بچیتە ژوورەوە.',
        'not_allowed' => 'ئەم ناونیشانی ئیمەیڵە ڕێگەپێدراو نییە بۆ ئەم فرۆشگایە. تکایە بەڕێوەبەری فرۆشگا پەیوەندی بکە.',
        'inactive_customer' => 'ئەکاونتی کڕیارەکەت لە سیستەمەکەدا ناچالاکە. تکایە بەڕێوەبەر پەیوەندی بکە.',
        'expired' => 'جیمەیڵەکەت بۆ ئەم وێبسایتە بەسەرچووە، پێویستە پەیوەندی بە بەڕێوەبەرەوە بکەیت بۆ ئەوەی بۆت نوێ بکاتەوە تا بتوانیت وێبسایتەکە (فرۆشگا ئۆنلاینەکە) ببینیت.',
    ];
    $msg = $messages[$reason] ?? $messages['not_allowed'];
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title>دەستڕاگەیشتنی</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-body-secondary d-flex align-items-center min-vh-100">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow border-0">
                    <div class="card-body p-4 text-center">
                        <i class="bi bi-shield-lock text-danger display-4 d-block mb-3"></i>
                        <h1 class="h4 mb-3">دەستڕاگەیشتنی قەدەغەیە</h1>
                        <p class="text-muted mb-4"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if ($reason === 'login_required' || $reason === 'not_allowed' || $reason === 'inactive_customer'): ?>
                            <a href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-danger btn-lg">
                                <i class="bi bi-google"></i> چوونەژوورەوە بە Google
                            </a>
                        <?php endif; ?>
                        <div class="mt-3">
                            <a href="<?php echo htmlspecialchars(SITE_URL . 'web/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">گەڕانەوە</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
}

/**
 * Enforce Gmail allowlist for this shop. Exits with HTML if blocked.
 */
function shop_google_access_guard(mysqli $conn, string $slug, array $websiteSettings): void
{
    shop_google_ensure_db_schema($conn);

    $restrict = isset($websiteSettings['shop_google_restrict']) ? (int) $websiteSettings['shop_google_restrict'] : 0;
    if ($restrict !== 1) {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    CustomerSession::start();

    $suppress = $_SESSION['shop_suppress_google_auto'] ?? [];
    if (!empty($suppress[$slug])) {
        shop_google_access_render_blocked($slug, 'login_required');
        exit;
    }

    $merchantUserId = (int) ($websiteSettings['user_id'] ?? 0);
    $googleUser = $_SESSION['google_user'] ?? null;
    $email = isset($googleUser['email']) ? strtolower(trim((string) $googleUser['email'])) : '';

    if ($email === '') {
        shop_google_access_render_blocked($slug, 'login_required');
        exit;
    }

    $validation = shop_google_validate_access($conn, $merchantUserId, $email);
    if (!$validation['ok']) {
        $reason = $validation['reason'] ?? 'not_allowed';
        shop_google_access_render_blocked($slug, $reason);
        exit;
    }

    $cust = $validation['customer'];
    $webId = shop_google_ensure_web_customer($conn, $cust, $email, (string) ($cust['name'] ?? ''));
    $customerData = [
        'name' => $cust['name'] ?? $email,
        'email' => $email,
        'phone' => $cust['phone'] ?? '',
        'address' => $cust['address'] ?? '',
    ];
    CustomerSession::login($webId, $customerData);
}

/**
 * API: return null if allowed, else ['http' => int, 'message' => string]
 *
 * @return array<string,mixed>|null
 */
function shop_google_access_api_error(mysqli $conn, int $merchantUserId): ?array
{
    shop_google_ensure_db_schema($conn);

    $stmt = $conn->prepare('SELECT shop_google_restrict FROM website_settings WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $merchantUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $restrict = isset($row['shop_google_restrict']) ? (int) $row['shop_google_restrict'] : 0;
    if ($restrict !== 1) {
        return null;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    CustomerSession::start();

    $googleUser = $_SESSION['google_user'] ?? null;
    $email = isset($googleUser['email']) ? strtolower(trim((string) $googleUser['email'])) : '';
    if ($email === '') {
        return ['http' => 403, 'message' => 'پێویستە بە Google بچیتە ژوورەوە بۆ ئەم فرۆشگایە.'];
    }

    $validation = shop_google_validate_access($conn, $merchantUserId, $email);
    if (!$validation['ok']) {
        $reason = $validation['reason'] ?? 'not_allowed';
        if ($reason === 'expired') {
            return ['http' => 403, 'message' => 'جیمەیڵەکەت بۆ ئەم وێبسایتە بەسەرچووە، پێویستە پەیوەندی بە بەڕێوەبەرەوە بکەیت بۆ ئەوەی بۆت نوێ بکاتەوە تا بتوانیت وێبسایتەکە (فرۆشگا ئۆنلاینەکە) ببینیت.'];
        }
        return ['http' => 403, 'message' => 'دەستڕاگەیشتنی ڕێگەپێدراو نییە بۆ ئەم فرۆشگایە.'];
    }

    return null;
}
