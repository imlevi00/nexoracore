<?php
/**
 * Simple Google OAuth2 login example (no external libraries).
 *
 * Steps to use:
 * 1. Go to Google Cloud Console → Credentials → Create OAuth client ID (Web application).
 * 2. Set "Authorized redirect URI" to this exact URL, e.g.:
 *      https://YOUR-DOMAIN.com/videos/google-login.php
 * 3. Copy Client ID and Client Secret and paste them below.
 * 4. Open this file in your browser; it will redirect to Google, then back here.
 */

// سێشن بۆ هەفتەیەک (بە کوکی) دروستبکە
if (session_status() === PHP_SESSION_NONE) {
    $oneWeek = 7 * 24 * 60 * 60;
    ini_set('session.gc_maxlifetime', (string)$oneWeek);
    session_set_cookie_params([
        'lifetime' => $oneWeek,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// پەیوەندی نوێ بۆ داتابەیسی زانیاری login
require_once __DIR__ . '/../config/kasher_zanyari/database.php';
require_once __DIR__ . '/../config/database.php';

// بۆ دیاریکردنی ئەو لاپەڕەیەی دواتر بگەڕێتەوە بۆی (وەک my_account/index.php)
$requestedReturnPath = null;
if (isset($_GET['return_to']) && is_string($_GET['return_to'])) {
    $returnTo = trim($_GET['return_to']);
    // تەنها ڕێگای ناوخۆی سایت قبووڵ بکە بۆ ئاسایش
    if ($returnTo !== '' && strpos($returnTo, 'http') !== 0) {
        $requestedReturnPath = ltrim($returnTo, '/');
        $_SESSION['google_login_return_to'] = $requestedReturnPath;
    }
}

$effectiveReturnPath = $requestedReturnPath
    ?? ($_SESSION['google_login_return_to'] ?? 'videos/index.php');

$googleFlow = null;
if (isset($_GET['flow']) && is_string($_GET['flow'])) {
    $googleFlow = trim($_GET['flow']);
    $_SESSION['google_login_flow'] = $googleFlow;
} elseif (isset($_SESSION['google_login_flow']) && is_string($_SESSION['google_login_flow'])) {
    $googleFlow = $_SESSION['google_login_flow'];
}

$isForgotPasswordFlow = ($googleFlow === 'forgot_password');

// local path بۆ redirect بێ پێویستی بە url() helper
function buildLocalRedirectPath(string $path): string
{
    return '/' . ltrim($path, '/');
}

// ئەگەر پێشتر هاتووە ژوورەوە و سێشنی Google هێشتا هەیە، دووبارە نێردن بۆ Google مەکە
// و تەنها نامەیەکی کورت نیشان بدە.
if (!empty($_SESSION['google_user']) && empty($_GET['code']) && !$isForgotPasswordFlow) {
    header('Location: ' . buildLocalRedirectPath($effectiveReturnPath));
    exit;
    ?>
    <!DOCTYPE html>
    <html lang="ku" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>پێشتر داخڵ بوویت</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body {
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: #0f172a;
                margin: 0;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                color: #e5e7eb;
            }
            .card {
                background: #020617;
                padding: 24px 28px;
                border-radius: 14px;
                border: 1px solid rgba(148,163,184,0.4);
                box-shadow: 0 18px 45px rgba(15,23,42,0.95);
                max-width: 420px;
                width: 100%;
                text-align: center;
            }
            .card h1 {
                margin: 0 0 10px;
                font-size: 20px;
            }
            .card p {
                margin: 0 0 18px;
                font-size: 14px;
                color: #cbd5f5;
            }
            .card a {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 8px 16px;
                border-radius: 999px;
                background: #22c55e;
                color: #022c22;
                font-weight: 600;
                font-size: 14px;
                text-decoration: none;
                box-shadow: 0 12px 30px rgba(34,197,94,0.55);
            }
            .card a:hover {
                background: #16a34a;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>تۆ پێشتر داخڵ بوویت</h1>
            <p>سێشنی هاتووە ژوورەوەت هێشتا بەسەر نەچووە. دەتوانیت ڕاستەوخۆ بڕۆیت بۆ بەشی ڤیدیۆکان.</p>
            <a href="index.php">بڕۆ بۆ ڤیدیۆکان</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ====== CONFIGURATION ======
// client_id/secret لە config/secrets.local.php یان environment variable دێن
require_once __DIR__ . '/../config/secrets.php';
$clientId     = kasher_secret('google_client_id', 'GOOGLE_CLIENT_ID');
$clientSecret = kasher_secret('google_client_secret', 'GOOGLE_CLIENT_SECRET');
$redirectUri  = 'https://nexoracore.com/videos/google-login.php'; // must match Google Console

// Space‑separated scopes for basic profile + email
$scopes = [
    'openid',
    'email',
    'profile',
];

// ====== HELPER: Build current full URL (HTTP/HTTPS + host + path) ======
function getCurrentUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/videos/google-login.php';
    return $scheme . '://' . $host . $uri;
}

// If you prefer not to hard‑code $redirectUri, you can uncomment this:
// $redirectUri = getCurrentUrl();

// ====== STEP 1: If this is the first visit (no "code"), redirect to Google ======
if (!isset($_GET['code'])) {
    // CSRF protection: generate and store random "state"
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth2_state'] = $state;

    $params = [
        'response_type' => 'code',
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'scope'         => implode(' ', $scopes),
        'state'         => $state,
        'access_type'   => 'online',
        'include_granted_scopes' => 'true',
        'prompt'        => 'select_account', // or 'consent'
    ];

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    header('Location: ' . $authUrl);
    exit;
}

// ====== STEP 2: We came back from Google with ?code=... ======
// Validate state
if (empty($_SESSION['oauth2_state']) || empty($_GET['state']) || !hash_equals($_SESSION['oauth2_state'], $_GET['state'])) {
    http_response_code(400);
    echo '<h2>Invalid OAuth state.</h2>';
    exit;
}

// Exchange authorization code for access token
$tokenEndpoint = 'https://oauth2.googleapis.com/token';

$postFields = [
    'code'          => $_GET['code'],
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
];

$ch = curl_init($tokenEndpoint);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);

$tokenResponse = curl_exec($ch);
$curlError     = curl_error($ch);
$httpCode      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($tokenResponse === false || $httpCode !== 200) {
    http_response_code(500);
    echo '<h2>Failed to get access token from Google.</h2>';
    if ($curlError) {
        echo '<pre>cURL error: ' . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<pre>HTTP code: ' . (int)$httpCode . '</pre>';
        echo '<pre>Response: ' . htmlspecialchars((string)$tokenResponse, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    exit;
}

$tokenData = json_decode($tokenResponse, true);

if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    http_response_code(500);
    echo '<h2>Invalid token response from Google.</h2>';
    echo '<pre>' . htmlspecialchars((string)$tokenResponse, ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$accessToken = $tokenData['access_token'];

// ====== STEP 3: Use access token to get user info ======
$userinfoEndpoint = 'https://www.googleapis.com/oauth2/v3/userinfo';

$ch = curl_init($userinfoEndpoint);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$userInfoResponse = curl_exec($ch);
$curlError        = curl_error($ch);
$httpCode         = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($userInfoResponse === false || $httpCode !== 200) {
    http_response_code(500);
    echo '<h2>Failed to fetch user info from Google.</h2>';
    if ($curlError) {
        echo '<pre>cURL error: ' . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<pre>HTTP code: ' . (int)$httpCode . '</pre>';
        echo '<pre>Response: ' . htmlspecialchars((string)$userInfoResponse, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    exit;
}

$userInfo = json_decode($userInfoResponse, true);

if (!is_array($userInfo)) {
    http_response_code(500);
    echo '<h2>Invalid user info response from Google.</h2>';
    echo '<pre>' . htmlspecialchars((string)$userInfoResponse, ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

if ($isForgotPasswordFlow) {
    $_SESSION['google_forgot_auth'] = $userInfo;
    header('Location: ' . buildLocalRedirectPath('user/auth/google-forgot-password.php'));
    exit;
}

// ====== STEP 4: Save / update user in kasher_zanyari DB and create session ======

// تێدانی بەڵگەنامەکان
$googleSub = $userInfo['sub'] ?? null;
$email = $userInfo['email'] ?? null;
$emailVerifiedBool = !empty($userInfo['email_verified']);

if (!empty($googleSub) && $conn_zanyari instanceof mysqli) {
    $emailVerified = isset($userInfo['email_verified']) ? (int)(bool)$userInfo['email_verified'] : null;
    $name          = $userInfo['name']          ?? null;
    $givenName     = $userInfo['given_name']    ?? null;
    $familyName    = $userInfo['family_name']   ?? null;
    $picture       = $userInfo['picture']       ?? null;
    $locale        = $userInfo['locale']        ?? null;
    $hd            = $userInfo['hd']            ?? null;
    $ip            = $_SERVER['REMOTE_ADDR']    ?? null;
    $ua            = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // بەکارهێنەر هەیە؟ نوێبکەرەوە، ئەگەر نەخێر دروستبکە
    $conn_zanyari->begin_transaction();
    try {
        $stmt = $conn_zanyari->prepare("
            INSERT INTO google_users (
                google_sub, email, email_verified, name, given_name, family_name,
                picture, locale, hd, last_login_ip, last_login_user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                email_verified = VALUES(email_verified),
                name = VALUES(name),
                given_name = VALUES(given_name),
                family_name = VALUES(family_name),
                picture = VALUES(picture),
                locale = VALUES(locale),
                hd = VALUES(hd),
                updated_at = CURRENT_TIMESTAMP,
                last_login_ip = VALUES(last_login_ip),
                last_login_user_agent = VALUES(last_login_user_agent)
        ");

        if ($stmt) {
            $stmt->bind_param(
                'ssissssssss',
                $googleSub,
                $email,
                $emailVerified,
                $name,
                $givenName,
                $familyName,
                $picture,
                $locale,
                $hd,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        }

        // دووبارە بخوێنەوە بۆ هێنانی id ـی ناوخۆیی
        $stmt = $conn_zanyari->prepare("SELECT id FROM google_users WHERE google_sub = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $googleSub);
            $stmt->execute();
            $result = $stmt->get_result();
            $row    = $result ? $result->fetch_assoc() : null;
            $stmt->close();
        } else {
            $row = null;
        }

        $conn_zanyari->commit();
    } catch (Throwable $e) {
        $conn_zanyari->rollback();
        $row = null;
    }

    $localUserId = $row['id'] ?? null;
} else {
    $localUserId = null;
}

// زانیاری سێشن بۆ بەکارهێنەر
$_SESSION['google_user'] = [
    'id'     => $localUserId,
    'sub'    => $userInfo['sub']   ?? null,
    'email'  => $userInfo['email'] ?? null,
    'name'   => $userInfo['name']  ?? null,
    'picture'=> $userInfo['picture'] ?? null,
];

// دوای سەرکەوتنی چوونەژوورەوە، یەکسەر ڕەوانە بکە بۆ لاپەڕەی ڤیدیۆکان
$returnPath = $effectiveReturnPath;
unset($_SESSION['google_login_return_to']);
unset($_SESSION['google_login_flow']);
unset($_SESSION['google_forgot_auth']);

// دووبارە چوونەژوورەوە بۆ فرۆشگا دوای دەرچوون (shop suppress)
if (preg_match('/shop\.php\?[^#]*slug=([^&]+)/', $returnPath, $m)) {
    $sl = urldecode($m[1]);
    if ($sl !== '') {
        if (!isset($_SESSION['shop_suppress_google_auto']) || !is_array($_SESSION['shop_suppress_google_auto'])) {
            $_SESSION['shop_suppress_google_auto'] = [];
        }
        unset($_SESSION['shop_suppress_google_auto'][$sl]);
    }
}

header('Location: ' . buildLocalRedirectPath($returnPath));
exit;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Google Login Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .card {
            background: #fff;
            padding: 24px 28px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            max-width: 420px;
            width: 100%;
        }
        .card h1 {
            margin-top: 0;
            margin-bottom: 12px;
            font-size: 22px;
        }
        .user {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
        }
        .user img {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
        }
        pre {
            background: #111827;
            color: #e5e7eb;
            padding: 12px;
            border-radius: 8px;
            overflow: auto;
            font-size: 13px;
        }
        .hint {
            margin-top: 16px;
            font-size: 13px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>Google Login – Test Result</h1>
    <p>Log in via your Google account was successful. This is demo data you can use to connect with your own user system.</p>

    <?php if (!empty($_SESSION['google_user'])): ?>
        <div class="user">
            <?php if (!empty($_SESSION['google_user']['picture'])): ?>
                <img src="<?php echo htmlspecialchars($_SESSION['google_user']['picture'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar">
            <?php endif; ?>
            <div>
                <div><strong><?php echo htmlspecialchars($_SESSION['google_user']['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><?php echo htmlspecialchars($_SESSION['google_user']['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <h3>Raw user info (JSON)</h3>
    <pre><?php echo htmlspecialchars(json_encode($userInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>

    <div class="hint">
        - لە Google Console، ئەم URL ـە وەک <strong>Authorized redirect URI</strong> لەجێبەجێ بکەرەوە.<br>
        - لەسەرەوە <strong>Client ID</strong> و <strong>Client Secret</strong> لە گوگڵ بنووسە.<br>
        - دوای سەرکەوتنی چوونەژوورەوە، دەتوانی لێرە session ـەکە بە سیستەمی login ـی خۆت ببەستیت.
    </div>
</div>
</body>
</html>

