<?php
/**
 * لایەری «بزنسی چالاک» بۆ چەندین بزنس (Multi-Business Context)
 * includes/business_context.php
 *
 * بیرۆکەی سەرەکی:
 *   هەموو سیستەمەکە داتای خۆی بە $_SESSION['user_data']['id'] scope دەکات.
 *   ئەم لایەرە ڕێگە دەدات یەک خاوەن (owner) چەندین ئەکاونتی `users` (= بزنس)
 *   هەبێت کە بە organizations ـەوە بەیەکەوە بەسترابن، و بە ئاسانی لەنێوانیاندا
 *   بگۆڕێت. لە کاتی گۆڕیندا تەنها user_data['id'] دەگۆڕدرێت، بۆیە هەموو
 *   queryـیەکانی ئێستا خۆکارانە بۆ بزنسی چالاک کاردەکەن — بەبێ گۆڕینی هیچ query.
 *
 * دەستەبەری بێ-کێشەیی:
 *   - تەنها بۆ main-user ـی چوونەژوورەوە کار دەکات. sub-user (کارمەند) قوفڵکراوە
 *     بۆ یەک بزنس و هیچ ناگۆڕێت.
 *   - بەکارهێنەری بێ ڕێکخراو (organization نییە) = ڕەفتاری کۆنی تاک-بزنس.
 *   - idempotent ـە: لەسەر هەموو داواکارییەک بەبێ زیان دووبارە کاردەکات.
 */

/**
 * ئایدی ڕاستەقینەی ئەکاونتی چوونەژوورەوە (anchor/owner) — نەگۆڕ لە کاتی گۆڕینی بزنس.
 */
function getAuthUserId()
{
    if (isset($_SESSION['owner_user_id'])) {
        return (int)$_SESSION['owner_user_id'];
    }
    $user = getCurrentUser();
    return $user ? (int)($user['id'] ?? 0) : 0;
}

/**
 * ئایدی بزنسی چالاک ئێستا (ئەوەی هەموو داتا بۆی scope دەکرێت).
 */
function getActiveBusinessId()
{
    if (isset($_SESSION['active_business_id'])) {
        return (int)$_SESSION['active_business_id'];
    }
    $user = getCurrentUser();
    return $user ? (int)($user['id'] ?? 0) : 0;
}

/**
 * ڕیزی organization ئەگەر ئەم بەکارهێنەرە خاوەنی ڕێکخراوێک بێت (owner_user_id == userId).
 * تەنها خاوەنی دیاریکراوی ڕێکخراو switcher و ڕاپۆرتی گشتی دەبینێت.
 */
function getOrganizationForOwner($userId)
{
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        return null;
    }
    try {
        $stmt = $conn->prepare("SELECT id, name, owner_user_id FROM organizations WHERE owner_user_id = ? LIMIT 1");
        if (!$stmt) {
            return null; // خشتەکە نییە (migration نەکراوە) → بێ ڕێکخراو
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'owner_user_id' => (int)$row['owner_user_id'],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * لیستی بزنسەکانی (users) ناو ڕێکخراوێک — بۆ switcher و ڕاپۆرتی گشتی.
 */
function getBusinessesInOrganization($organizationId)
{
    global $conn;
    $organizationId = (int)$organizationId;
    if ($organizationId <= 0 || !$conn) {
        return [];
    }
    try {
        $stmt = $conn->prepare("SELECT id, business_name, status, expiration_date FROM users WHERE organization_id = ? ORDER BY id ASC");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $out[] = [
                    'id' => (int)$row['id'],
                    'business_name' => (string)$row['business_name'],
                    'status' => (string)$row['status'],
                    'expiration_date' => $row['expiration_date'] !== null ? (string)$row['expiration_date'] : null,
                ];
            }
        }
        $stmt->close();
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * ئایا سێشنی ئێستا خاوەنێکە کە switcher ی هەیە (main-user + خاوەنی ڕێکخراو + ≥١ بزنس).
 */
function activeUserOwnsMultipleBusinesses()
{
    $ctx = getBusinessSwitchContext();
    return $ctx !== null && count($ctx['businesses']) >= 2;
}

/**
 * کۆکراوەی زانیاری switcher بۆ سێشنی ئێستا، یان null ئەگەر بەکار نایەت.
 */
function getBusinessSwitchContext()
{
    if (!function_exists('isUser') || !isUser()) {
        return null;
    }
    $user = getCurrentUser();
    if (!$user) {
        return null;
    }
    if (($user['user_type'] ?? 'main') === 'sub') {
        return null; // کارمەند — قوفڵکراو
    }

    $ownerId = getAuthUserId();
    $org = getOrganizationForOwner($ownerId);
    if (!$org) {
        return null;
    }
    $businesses = getBusinessesInOrganization($org['id']);
    if (count($businesses) < 1) {
        return null;
    }
    return [
        'organization' => $org,
        'businesses' => $businesses,
        'active_id' => getActiveBusinessId(),
        'owner_id' => $ownerId,
    ];
}

/**
 * دەستنیشانکردنی بزنسی چالاک لەسەر هەموو داواکارییەک (لە config.php بانگ دەکرێت
 * دوای دەستپێکردنی session). idempotent و بێ-زیان بۆ بەکارهێنەری تاک.
 */
function resolveBusinessContext()
{
    if (!function_exists('isUser') || !isUser()) {
        return;
    }
    $user = getCurrentUser();
    if (!$user || empty($user['id'])) {
        return;
    }

    // کارمەند (sub-user): قوفڵکراو بۆ یەک بزنس — هیچ ناکەین.
    if (($user['user_type'] ?? 'main') === 'sub') {
        return;
    }

    // ئایدی anchor یەکجار دەگیرێت (کاتی یەکەم داواکاری دوای چوونەژوورەوە).
    if (!isset($_SESSION['owner_user_id'])) {
        $_SESSION['owner_user_id'] = (int)$user['id'];
    }
    $ownerId = (int)$_SESSION['owner_user_id'];

    $org = getOrganizationForOwner($ownerId);

    // بەکارهێنەری ئاسایی (بێ ڕێکخراو) یان ئەکاونتێک کە خاوەنی ڕێکخراو نییە:
    // بزنسی چالاک = خۆی. ڕەفتاری کۆن بەبێ گۆڕان.
    if (!$org) {
        $_SESSION['active_business_id'] = $ownerId;
        if ((int)($_SESSION['user_data']['id'] ?? 0) !== $ownerId) {
            $_SESSION['user_data']['id'] = $ownerId;
        }
        return;
    }

    // خاوەنی ڕێکخراو: پشتڕاستکردنەوەی بزنسی چالاک.
    $businesses = getBusinessesInOrganization((int)$org['id']);
    $validIds = array_map('intval', array_column($businesses, 'id'));

    $active = (int)($_SESSION['active_business_id'] ?? $ownerId);
    if (!in_array($active, $validIds, true)) {
        $active = $ownerId; // گەڕانەوە بۆ anchor ئەگەر بزنسی چالاک نادروست بوو
    }

    $_SESSION['active_business_id'] = $active;
    // کلیلی سەرەکی: re-scope ی هەموو سیستەمەکە بۆ بزنسی چالاک.
    if ((int)($_SESSION['user_data']['id'] ?? 0) !== $active) {
        $_SESSION['user_data']['id'] = $active;
    }

    // هاوکاتکردنی ناوی بزنسی چالاک لە session بۆ پیشاندانی دروست لە هەموو شوێنێک.
    foreach ($businesses as $b) {
        if ((int)$b['id'] === $active) {
            $_SESSION['user_data']['business_name'] = $b['business_name'];
            break;
        }
    }
}

/**
 * پاککردنەوەی کلیلەکانی business context (لە کاتی دەرچوون).
 */
function clearBusinessContext()
{
    unset($_SESSION['owner_user_id'], $_SESSION['active_business_id']);
}

/* --------------------------------------------------------------------------
 * گەیتی پاکێج بۆ چەندین بزنس (وەک getMaxEmployeesForPackage/ForUser)
 * ------------------------------------------------------------------------ */

/**
 * زۆرترین ژمارەی بزنس ڕێگەپێدراو بۆ پاکێجێک (بنەڕەت 1 = تاک-بزنس).
 */
function getMaxBusinessesForPackage($packageId = null)
{
    global $conn;

    if ($packageId === null && function_exists('getCurrentUserPackageId')) {
        $packageId = getCurrentUserPackageId();
    }
    if (!$packageId || !$conn) {
        return 1;
    }

    try {
        $stmt = $conn->prepare("SELECT is_enabled FROM package_feature_permissions WHERE package_id = ? AND feature_key = 'multi_business_max_count' LIMIT 1");
        if (!$stmt) {
            return 1;
        }
        $packageId = (int)$packageId;
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return 1; // ڕیز نییە → بنەڕەت (تاک-بزنس، backward compatible)
        }
        $val = (int)$row['is_enabled'];
        return $val < 1 ? 1 : $val;
    } catch (Throwable $e) {
        return 1;
    }
}

/**
 * زۆرترین ژمارەی بزنس بۆ بەکارهێنەرێکی سەرەکی (بەپێی id).
 */
function getMaxBusinessesForUser($userId)
{
    global $conn;
    $userId = (int)$userId;
    if ($userId <= 0 || !$conn) {
        return 1;
    }
    try {
        $stmt = $conn->prepare("SELECT package_id FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return 1;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row || empty($row['package_id'])) {
            return 1;
        }
        return getMaxBusinessesForPackage((int)$row['package_id']);
    } catch (Throwable $e) {
        return 1;
    }
}

/* --------------------------------------------------------------------------
 * دروستکردن/گرێدانی بزنس — تەنها لە پانێڵی ئەدمین بانگ دەکرێن
 * ------------------------------------------------------------------------ */

/**
 * دڵنیابوونەوە لەوەی بەکارهێنەرێک خاوەنی ڕێکخراوێکە (owner). ئەگەر نەبوو، دروستی
 * دەکات و organization_id ی خۆیشی ڕێک دەخات.
 */
function ensureOrganizationForOwner($ownerUserId, $name = null)
{
    global $conn;
    $ownerUserId = (int)$ownerUserId;
    if ($ownerUserId <= 0 || !$conn) {
        return ['ok' => false, 'error' => 'invalid_owner'];
    }

    try {
        // ئایا پێشتر خاوەنی ڕێکخراوێکە؟
        $existing = getOrganizationForOwner($ownerUserId);
        if ($existing) {
            return ['ok' => true, 'org_id' => (int)$existing['id']];
        }

        // ئایا سەر بە ڕێکخراوێکی ترە (وەک ئەندام، نەک خاوەن)؟ ئەوا ناکرێت.
        $stmt = $conn->prepare("SELECT organization_id, business_name FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        $stmt->bind_param('i', $ownerUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return ['ok' => false, 'error' => 'owner_not_found'];
        }
        if (!empty($row['organization_id'])) {
            // سەر بە ڕێکخراوێکی ترە بەڵام خاوەن نییە
            return ['ok' => false, 'error' => 'already_member_not_owner'];
        }

        $orgName = ($name !== null && trim((string)$name) !== '') ? trim((string)$name) : (string)$row['business_name'];

        $conn->begin_transaction();
        $insOrg = $conn->prepare("INSERT INTO organizations (name, owner_user_id) VALUES (?, ?)");
        $insOrg->bind_param('si', $orgName, $ownerUserId);
        $insOrg->execute();
        $orgId = (int)$conn->insert_id;
        $insOrg->close();

        $upd = $conn->prepare("UPDATE users SET organization_id = ? WHERE id = ?");
        $upd->bind_param('ii', $orgId, $ownerUserId);
        $upd->execute();
        $upd->close();

        $conn->commit();
        return ['ok' => true, 'org_id' => $orgId];
    } catch (Throwable $e) {
        if ($conn) {
            @$conn->rollback();
        }
        return ['ok' => false, 'error' => 'create_failed'];
    }
}

/**
 * دروستکردنی بزنسێکی نوێ (ڕیزێکی users) و بەستنیەوە بە ڕێکخراوی خاوەنێک.
 * تەنها لە پانێڵی ئەدمین بانگ دەکرێت.
 */
function createLinkedBusiness($ownerUserId, $data)
{
    global $conn;
    $ownerUserId = (int)$ownerUserId;
    if ($ownerUserId <= 0 || !$conn || !is_array($data)) {
        return ['ok' => false, 'error' => 'invalid_owner'];
    }

    $businessName = trim((string)($data['business_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $phone = trim((string)($data['phone'] ?? ''));
    $address = trim((string)($data['address'] ?? ''));
    $packageId = isset($data['package_id']) && $data['package_id'] !== '' && $data['package_id'] !== null
        ? (int)$data['package_id'] : null;
    $expiration = isset($data['expiration_date']) && $data['expiration_date'] !== ''
        ? (string)$data['expiration_date'] : null;

    if ($businessName === '' || $email === '' || $password === '') {
        return ['ok' => false, 'error' => 'missing_fields'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_email'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'error' => 'weak_password'];
    }

    // دڵنیابوونەوە لە ڕێکخراو (ناوی ڕێکخراو = ناوی بزنسی خاوەن، نەک بزنسی نوێ)
    $orgResult = ensureOrganizationForOwner($ownerUserId, null);
    if (!$orgResult['ok']) {
        return ['ok' => false, 'error' => $orgResult['error'] ?? 'org_failed'];
    }
    $orgId = (int)$orgResult['org_id'];

    // سنووری پاکێج (بەپێی پاکێجی خاوەن)
    $maxBusinesses = getMaxBusinessesForUser($ownerUserId);
    $current = count(getBusinessesInOrganization($orgId));
    if ($current >= $maxBusinesses) {
        return ['ok' => false, 'error' => 'limit_reached'];
    }

    // یەکتایی ئیمەیڵ
    $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $check->bind_param('s', $email);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if ($exists) {
        return ['ok' => false, 'error' => 'email_taken'];
    }

    $hashed = Security::hashPassword($password);

    $stmt = $conn->prepare("
        INSERT INTO users (business_name, email, password, phone, address, telegram_sent, status, package_id, organization_id, expiration_date, created_at, approved_at)
        VALUES (?, ?, ?, ?, ?, 0, 'approved', ?, ?, ?, NOW(), NOW())
    ");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'db_error'];
    }
    // types: business_name,email,password,phone,address (s×5), package_id,org_id (i×2), expiration (s)
    // package_id/expiration ئەگەر null بن، mysqli وەک NULL دەیاننێرێت.
    $stmt->bind_param('sssssiis', $businessName, $email, $hashed, $phone, $address, $packageId, $orgId, $expiration);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'insert_failed'];
    }
    $newId = (int)$conn->insert_id;
    $stmt->close();

    return ['ok' => true, 'new_user_id' => $newId];
}

/**
 * پەیامی کوردی بۆ کۆدەکانی هەڵەی createLinkedBusiness/ensureOrganizationForOwner.
 */
function businessProvisionErrorMessage($code)
{
    $map = [
        'invalid_owner' => 'خاوەنی نادروست',
        'owner_not_found' => 'خاوەن نەدۆزرایەوە',
        'already_member_not_owner' => 'ئەم بەکارهێنەرە خۆی سەر بە ڕێکخراوێکی ترە و ناتوانێت ببێتە خاوەن',
        'missing_fields' => 'تکایە ناوی بزنس، ئیمەیڵ و پاسۆرد پڕ بکەرەوە',
        'invalid_email' => 'ئیمەیڵ نادروستە',
        'weak_password' => 'پاسۆرد دەبێت لانیکەم ٦ پیت بێت',
        'email_taken' => 'ئەم ئیمەیڵە پێشتر بەکارهاتووە',
        'limit_reached' => 'گەیشتیتە سنووری ژمارەی بزنسەکانی پاکێج',
        'org_failed' => 'دروستکردنی ڕێکخراو سەرکەوتوو نەبوو',
        'create_failed' => 'دروستکردنی ڕێکخراو سەرکەوتوو نەبوو',
        'insert_failed' => 'زیادکردنی بزنس سەرکەوتوو نەبوو',
        'db_error' => 'هەڵەی داتابەیس',
    ];
    return $map[$code] ?? 'هەڵەیەکی نەزانراو ڕوویدا';
}
