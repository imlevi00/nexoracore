<?php
/**
 * ڕێکخستنەکانی ئامنیەت
 * config/security.php
 */

/**
 * کلاسی ئامنیەت
 */
class Security {
    
    /**
     * hash کردنی پاسۆرد
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    /**
     * تاقیکردنەوەی پاسۆرد
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * دروستکردنی CSRF token
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * تاقیکردنەوەی CSRF token
     */
    public static function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * پاککردنەوەی input
     */
    public static function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeInput'], $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * تاقیکردنەوەی ئیمەیڵ
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * تاقیکردنەوەی ژمارەی مۆبایل (عێراقی)
     */
    public static function validatePhone($phone) {
        // فۆرماتەکانی مۆبایلی عێراق
        return preg_match('/^(07[3-9][0-9]{8}|7[3-9][0-9]{8})$/', $phone);
    }
    
    /**
     * بەدواداچوونی هەوڵەکانی داخڵبوون
     */
    public static function trackLoginAttempt($identifier, $success = false) {
        $key = 'login_attempts_' . md5($identifier);
        
        if ($success) {
            unset($_SESSION[$key]);
            return true;
        }
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 1,
                'first_attempt' => time(),
                'last_attempt' => time(),
                'blocked_until' => 0
            ];
        } else {
            $_SESSION[$key]['count']++;
            $_SESSION[$key]['last_attempt'] = time();
            
            if ($_SESSION[$key]['count'] >= MAX_LOGIN_ATTEMPTS) {
                $_SESSION[$key]['blocked_until'] = time() + LOGIN_LOCKOUT_TIME;
            }
        }
        
        return false;
    }
    
    /**
     * تاقیکردنی ئەگەر بەکارهێنەر blocked بێت
     */
    public static function isBlocked($identifier) {
        return false;
    }
    
    /**
     * وەرگرتنی کاتی ماوەی block
     */
    public static function getBlockedTime($identifier) {
        $key = 'login_attempts_' . md5($identifier);
        
        if (isset($_SESSION[$key]) && $_SESSION[$key]['blocked_until'] > time()) {
            return $_SESSION[$key]['blocked_until'] - time();
        }
        
        return 0;
    }
    
    /**
     * وەرگرتنی ژمارەی هەوڵەکانی داخڵبوون
     */
    public static function getLoginAttempts($identifier) {
        $key = 'login_attempts_' . md5($identifier);

        if (isset($_SESSION[$key])) {
            return $_SESSION[$key]['count'];
        }

        return 0;
    }

    /**
     * دروستکردنی Remember Me token
     *
     * @param int    $userId   بۆ user_type=user → users.id | بۆ user_type=sub → sub_users.id | admin → admins.id
     * @param string $userType 'user' | 'admin' | 'sub' (sub = تەنها کارمەند، جیا لە main user)
     */
    public static function createRememberToken($userId, $userType = 'user') {
        global $conn;

        // دروستکردنی selector و token ی ڕەندۆم
        $selector = bin2hex(random_bytes(16));
        $token = random_bytes(32);
        $hashedToken = hash('sha256', $token);

        // کاتی بەسەرچوون - 30 ڕۆژ
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));

        // تەنها token ە بەسەرچووەکانی ئەم بەکارهێنەرە بسڕەوە — نەک هەموو token ەکانی.
        // بەمە هەمان ئەکاونت دەتوانێت لە چەند تەرمیناڵ/ئامێردا هاوکات لە بیر بمێنێتەوە (Remember Me)؛
        // پێشتر چوونەژوورەوە لە ئامێرێکی نوێ token ی ئامێرەکانی تر ناچالاک دەکرد و دەبووە هۆی
        // داواکردنی دووبارەی چوونەژوورەوە لەسەر تەرمیناڵەکانی تر.
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND user_type = ? AND expires_at < NOW()");
        $stmt->bind_param("is", $userId, $userType);
        $stmt->execute();
        $stmt->close();

        // هەڵگرتنی token ی نوێ
        $stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, user_type, selector, token, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $userId, $userType, $selector, $hashedToken, $expiresAt);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            // گەڕاندنەوەی selector و token بۆ دانان لە cookie
            return [
                'selector' => $selector,
                'token' => bin2hex($token)
            ];
        }

        return false;
    }

    /**
     * تاقیکردنەوەی Remember Me token
     */
    public static function validateRememberToken($selector, $token) {
        global $conn;

        if (!$selector || !$token || !is_string($token) || !ctype_xdigit($token) || strlen($token) % 2 !== 0) {
            return false;
        }

        // وەرگرتنی token لە database
        $stmt = $conn->prepare("SELECT user_id, user_type, token, expires_at FROM remember_tokens WHERE selector = ? AND expires_at > NOW()");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $stmt->close();

            // تاقیکردنەوەی token
            $hashedToken = hash('sha256', hex2bin($token));

            if (hash_equals($row['token'], $hashedToken)) {
                return [
                    'user_id' => $row['user_id'],
                    'user_type' => $row['user_type']
                ];
            }
        }

        $stmt->close();
        return false;
    }

    /**
     * سڕینەوەی Remember Me token
     */
    public static function deleteRememberToken($selector = null, $userId = null, $userType = null) {
        global $conn;

        if ($selector) {
            $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE selector = ?");
            $stmt->bind_param("s", $selector);
        } elseif ($userId && $userType) {
            $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND user_type = ?");
            $stmt->bind_param("is", $userId, $userType);
        } else {
            return false;
        }

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * پاککردنەوەی token ە بەسەرچووەکان
     */
    public static function cleanExpiredTokens() {
        global $conn;

        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE expires_at < NOW()");
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}

/**
 * کلاسی Session Management
 */
class SessionManager {

    private static function isHttps() {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /**
     * ئایا داواکاریی ئێستا AJAX/XHR یان API/JSON ـە؟
     * بۆ ئەوەی نوێکردنەوەی ناسنامەی سێشن لەسەر داواکارییە هاوکاتەکان ئەنجام نەدرێت.
     */
    private static function isAjaxRequest() {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/api/') !== false || strpos($uri, '/ajax/') !== false) {
            return true;
        }
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            return true;
        }
        return false;
    }

    /**
     * ڕێکخستنی کوکی سێشن (پێش هەر session_start)
     */
    private static function applySessionCookieSettings() {
        $isSecure = self::isHttps();
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $isSecure ? '1' : '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @return array [secure] بۆ setcookie */
    private static function authCookieOpts($expires) {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * دوای چوونەژوورەوە بە پاسۆرد — Remember Me تەنها لە هەمان ڕۆژدا کار دەکات
     */
    public static function setPasswordAuthDayCookie() {
        $name = AUTH_DAY_COOKIE_NAME;
        setcookie($name, date('Y-m-d'), self::authCookieOpts(time() + SESSION_LIFETIME));
    }

    public static function clearPasswordAuthDayCookie() {
        $name = AUTH_DAY_COOKIE_NAME;
        setcookie($name, '', self::authCookieOpts(time() - 3600));
    }

    /**
     * سڕینەوەی remember_me لە داواکاریی ئێستا
     */
    public static function clearRememberMeFromRequest() {
        if (!isset($_COOKIE['remember_me'])) {
            return;
        }
        $cookieParts = explode(':', $_COOKIE['remember_me'], 2);
        if (count($cookieParts) === 2) {
            Security::deleteRememberToken($cookieParts[0]);
        }
        setcookie('remember_me', '', self::authCookieOpts(time() - 3600));
    }

    /**
     * لابردنی چوونەژوورەوە بەبێ تەواوکردنی سێشن (بۆ گۆڕینی ڕۆژ)
     */
    private static function stripAllAuthFromSession() {
        unset(
            $_SESSION['user_logged_in'],
            $_SESSION['user_data'],
            $_SESSION['admin_logged_in'],
            $_SESSION['admin_data'],
            $_SESSION['auth_calendar_day'],
            $_SESSION['login_time']
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * ئەگەر سێشن بۆ ڕۆژێکی تر بێت، دەرچوون و پاککردنەوەی remember
     */
    private static function enforceDailyReauth() {
        // Disabled: Allow users to stay logged in across multiple days
        return;
    }
    
    /**
     * دەستپێکردنی secure session
     */
    public static function startSecureSession() {
        if (session_status() === PHP_SESSION_NONE) {
            self::applySessionCookieSettings();
            session_start();
        }

        self::enforceDailyReauth();

        // تاقیکردنی session timeout
        self::checkSessionTimeout();

        // نوێکردنەوەی ناسنامەی سێشن بۆ ئامنیەت (دژە session fixation)
        // گرنگ: سێشنی کۆن ناسڕدرێتەوە (delete_old_session=false) و لەسەر داواکاری AJAX
        // نوێ ناکرێتەوە. بەشی فرۆشتن چەندین داواکاری هاوکاتی AJAX دەنێرێت (هەندێکیان
        // لۆکی سێشن زوو ئازاد دەکەن)؛ ئەگەر ناسنامەی سێشنی بەکارهاتوو یەکسەر بسڕدرێتەوە،
        // ئەو داواکارییانەی هێشتا ناسنامەی کۆنیان پێیە (بە use_strict_mode) سێشنێکی بەتاڵ
        // وەردەگرن → 401 → ڕیدایرێکت بۆ لاپەڕەی چوونەژوورەوە. ئەمە هۆکاری سەرەکی
        // دەرکردنە چەند جارەکانی ڕۆژانە بوو.
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif ((time() - $_SESSION['last_regeneration'] > 1800) && !self::isAjaxRequest()) {
            // کاتژمێرەکە پێش نوێکردنەوە نوێ بکەرەوە بۆ ئەوەی داواکارییە هاوکاتەکان دووبارە نوێی نەکەنەوە
            $_SESSION['last_regeneration'] = time();
            // سێشنی کۆن بەردەست دەمێنێتەوە بۆ داواکارییە لە ئارادا؛ GC دواتر پاکی دەکاتەوە
            session_regenerate_id(false);
        }

        // تاقیکردنەوەی Remember Me token بۆ auto login
        self::checkRememberMe();
    }
    
    /**
     * تاقیکردنی session timeout
     */
    public static function checkSessionTimeout() {
        // تەنها کاتێک timeout هەڵسەنگێنە کە بەڕاستی logged-in state هەبێت
        $hasAuth = isLoggedIn('user') || isLoggedIn('admin');
        if (!$hasAuth) {
            $_SESSION['last_activity'] = time();
            return true;
        }

        $lastActivity = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : 0;
        if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_TIMEOUT) {
            self::destroySession();
            return false;
        }
        
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    /**
     * login کردنی بەکارهێنەر
     */
    public static function loginUser($userData, $type = 'user', $passwordLogin = false) {
        $_SESSION[$type . '_logged_in'] = true;
        $_SESSION[$type . '_data'] = $userData;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['auth_calendar_day'] = date('Y-m-d');
        if ($passwordLogin) {
            self::setPasswordAuthDayCookie();
        }

        // regenerate session ID بۆ ئامنیەت
        session_regenerate_id(true);

        return true;
    }
    
    /**
     * logout کردن
     */
    public static function logout($type = 'user') {
        unset($_SESSION[$type . '_logged_in']);
        unset($_SESSION[$type . '_data']);

        // پاککردنەوەی کلیلەکانی «بزنسی چالاک» (چەندین بزنس) لە کاتی دەرچوونی بەکارهێنەر
        if ($type === 'user') {
            unset($_SESSION['owner_user_id'], $_SESSION['active_business_id']);
        }

        // دووبارە دروستکردنی session id بۆ کەمکردنەوەی fixation دوای دەرچوون
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // ئەگەر هیچ user یان admin ی تری logged in نەبێت، session destroy بکە
        if (!isLoggedIn('user') && !isLoggedIn('admin')) {
            self::destroySession();
        }
    }
    
    /**
     * تەواو کردنی session
     */
    public static function destroySession() {
        self::clearPasswordAuthDayCookie();
        session_unset();
        session_destroy();
        
        // سڕینەوەی session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
    }
    
    /**
     * تاقیکردنی دەسەڵات
     */
    public static function requireAuth($type = 'user', $redirect = null) {
        if (!isLoggedIn($type)) {
            // Check if it's an API or AJAX request
            $isApiRequest = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) || 
                            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
                            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
            
            if ($isApiRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Your session has expired. Please log in again.',
                    'redirect' => url(($type === 'admin') ? '/adminKx9mZpQa7WvRt4Ny6Lb3/login.php' : '/user/auth/login.php')
                ]);
                exit();
            }

            if ($redirect === null) {
                $redirect = ($type === 'admin') ? '/adminKx9mZpQa7WvRt4Ny6Lb3/login.php' : '/user/auth/login.php';
            }
            redirect(url($redirect));
        }

        return true;
    }

    /**
     * Release session file lock after auth so parallel read-only API calls (e.g. POS) do not queue.
     * Call only after isUser/getCurrentUser/enforceAuthorizationOrDeny on GET/read endpoints.
     */
    public static function releaseSessionLockForParallelReads() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * تاقیکردنەوەی Remember Me token و auto login
     */
    public static function checkRememberMe() {
        global $conn;

        // ئەگەر بەکارهێنەر پێشتر logged in بێت، پێویستی بە remember me نییە
        if (isLoggedIn('user') || isLoggedIn('admin')) {
            return false;
        }

        // تاقیکردنی بوونی remember_me cookie
        if (!isset($_COOKIE['remember_me'])) {
            return false;
        }

        // Remember Me verification (Day check removed so it works across multiple days)
        // جیاکردنەوەی selector و token
        $cookieParts = explode(':', $_COOKIE['remember_me'], 2);
        if (count($cookieParts) !== 2 || empty($cookieParts[0]) || empty($cookieParts[1])) {
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
            return false;
        }

        list($selector, $token) = $cookieParts;

        $userData = Security::validateRememberToken($selector, $token);

        if (!$userData) {
            Security::deleteRememberToken($selector);
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
            return false;
        }

        $userId = (int) $userData['user_id'];
        $userType = $userData['user_type'];

        // --- user_type = sub: تەنها sub_users (ناسنامەی ڕوون) ---
        if ($userType === 'sub') {
            $stmt = $conn->prepare("
                SELECT su.id, su.username, su.email, su.full_name, su.permissions, su.is_active,
                       su.expiration_date, u.business_name, u.id as main_user_id,
                       u.expiration_date as main_expiration_date, u.status
                FROM sub_users su
                JOIN users u ON su.main_user_id = u.id
                WHERE su.id = ? AND su.is_active = 1
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $subUser = $result->fetch_assoc();
            $stmt->close();

            if (!$subUser) {
                writeLog("AuthRestore FAIL: sub token user_id=$userId not found selector=" . substr($selector, 0, 12) . " IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''), 'WARNING');
                Security::deleteRememberToken($selector);
                setcookie('remember_me', '', time() - 3600, '/', '', true, true);
                return false;
            }

            $isExpired = false;
            if ($subUser['expiration_date'] && strtotime($subUser['expiration_date']) < time()) {
                $isExpired = true;
            } elseif ($subUser['main_expiration_date'] && strtotime($subUser['main_expiration_date']) < time()) {
                $isExpired = true;
            } elseif ($subUser['status'] !== 'approved') {
                $isExpired = true;
            }

            if ($isExpired) {
                Security::deleteRememberToken($selector);
                setcookie('remember_me', '', time() - 3600, '/', '', true, true);
                return false;
            }

            SessionManager::loginUser([
                'id' => $subUser['main_user_id'],
                'sub_user_id' => $subUser['id'],
                'business_name' => $subUser['business_name'],
                'email' => $subUser['email'],
                'username' => $subUser['username'],
                'full_name' => $subUser['full_name'],
                'permissions' => json_decode($subUser['permissions'], true),
                'user_type' => 'sub'
            ], 'user');

            writeLog("AuthRestore OK: sub-user email={$subUser['email']} main_user_id={$subUser['main_user_id']} selector=" . substr($selector, 0, 12) . " IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
            writeLog("Auto login via Remember Me: {$subUser['email']} (sub-user) from IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
            return true;
        }

        // --- user_type = user: main user یان legacy token (تەنها یەکێک لە users/sub_users بۆ هەمان id) ---
        if ($userType === 'user') {
            $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $hasMain = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("SELECT id FROM sub_users WHERE id = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $hasSub = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // پێشتر: ئەگەر هەمان id هەم لە users و هەم لە sub_users هەبووایە، token بە "ئامبیگیۆس"
            // هەژمار دەکرا و دەسڕایەوە — بەڵام users.id و sub_users.id دوو ژمێرەری سەربەخۆن، بۆیە
            // هاوتاییان زۆر ئاساییە (نموونە: users.id=1 و sub_users.id=1). ئەمە دەبووە هۆی سڕینەوەی
            // token ی سەرەکی و دەرکردنی ناچاری. لە کۆدی ئێستادا چوونەژوورەوەی سەرەکی هەمیشە
            // user_type='user' و کارمەند user_type='sub' تۆمار دەکات، بۆیە 'user' بەمانای main user ـە:
            // پێشینەیی دەدەین بە main user و token ناسڕینەوە.
            if ($hasMain && $hasSub) {
                writeLog("AuthRestore: remember token user_id=$userId matches both users and sub_users — resolving as MAIN user. IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
            }

            if ($hasMain) {
                $stmt = $conn->prepare("SELECT id, business_name, email, approved_at, status, expiration_date FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();

                if (!$user || $user['status'] !== 'approved') {
                    Security::deleteRememberToken($selector);
                    setcookie('remember_me', '', time() - 3600, '/', '', true, true);
                    return false;
                }

                if ($user['expiration_date'] && strtotime($user['expiration_date']) < time()) {
                    Security::deleteRememberToken($selector);
                    setcookie('remember_me', '', time() - 3600, '/', '', true, true);
                    return false;
                }

                SessionManager::loginUser([
                    'id' => $user['id'],
                    'business_name' => $user['business_name'],
                    'email' => $user['email'],
                    'approved_at' => $user['approved_at']
                ], 'user');

                writeLog("AuthRestore OK: main user email={$user['email']} user_id={$user['id']} selector=" . substr($selector, 0, 12) . " IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
                writeLog("Auto login via Remember Me: {$user['email']} from IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
                return true;
            }

            if ($hasSub) {
                $stmt = $conn->prepare("
                    SELECT su.id, su.username, su.email, su.full_name, su.permissions, su.is_active,
                           su.expiration_date, u.business_name, u.id as main_user_id,
                           u.expiration_date as main_expiration_date, u.status
                    FROM sub_users su
                    JOIN users u ON su.main_user_id = u.id
                    WHERE su.id = ? AND su.is_active = 1
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $subUser = $result->fetch_assoc();
                $stmt->close();

                if (!$subUser) {
                    Security::deleteRememberToken($selector);
                    setcookie('remember_me', '', time() - 3600, '/', '', true, true);
                    return false;
                }

                $isExpired = false;
                if ($subUser['expiration_date'] && strtotime($subUser['expiration_date']) < time()) {
                    $isExpired = true;
                } elseif ($subUser['main_expiration_date'] && strtotime($subUser['main_expiration_date']) < time()) {
                    $isExpired = true;
                } elseif ($subUser['status'] !== 'approved') {
                    $isExpired = true;
                }

                if ($isExpired) {
                    Security::deleteRememberToken($selector);
                    setcookie('remember_me', '', time() - 3600, '/', '', true, true);
                    return false;
                }

                SessionManager::loginUser([
                    'id' => $subUser['main_user_id'],
                    'sub_user_id' => $subUser['id'],
                    'business_name' => $subUser['business_name'],
                    'email' => $subUser['email'],
                    'username' => $subUser['username'],
                    'full_name' => $subUser['full_name'],
                    'permissions' => json_decode($subUser['permissions'], true),
                    'user_type' => 'sub'
                ], 'user');

                writeLog("AuthRestore OK: legacy sub (user_type=user) email={$subUser['email']} selector=" . substr($selector, 0, 12) . " IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
                writeLog("Auto login via Remember Me: {$subUser['email']} (sub-user, legacy token) from IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''));
                return true;
            }

            writeLog("AuthRestore FAIL: user_type=user but no users/sub_users row for id=$userId IP: " . ($_SERVER['REMOTE_ADDR'] ?? ''), 'WARNING');
            Security::deleteRememberToken($selector);
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
            return false;
        }

        // admin یان جۆری نەناسراو
        Security::deleteRememberToken($selector);
        setcookie('remember_me', '', time() - 3600, '/', '', true, true);
        return false;
    }
}

/**
 * فایل ئەپلۆد بە ئامنیەت
 */
class SecureFileUpload {
    
    /**
     * تاقیکردنی جۆری فایل
     */
    public static function validateFileType($filename, $allowedTypes = ALLOWED_IMAGE_TYPES) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $allowedTypes);
    }
    
    /**
     * تاقیکردنی قەبارەی فایل
     */
    public static function validateFileSize($fileSize, $maxSize = MAX_FILE_SIZE) {
        return $fileSize <= $maxSize;
    }
    
    /**
     * دروستکردنی ناوی ئامن بۆ فایل
     */
    public static function generateSafeFilename($originalName) {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $filename = uniqid() . '_' . time() . '.' . $extension;
        
        return $filename;
    }
    public static function validateNumber($number, $min = 0, $max = null) {
        if (!is_numeric($number)) return false;
        if ($number < $min) return false;
        if ($max !== null && $number > $max) return false;
        return true;
    }

    /**
     * کەمکردنەوەی وێنە بە server-side
     */
    public static function compressImage($sourcePath, $destinationPath, $quality = 80, $maxWidth = 1920, $maxHeight = 1080) {
        // تاقیکردنی بوونی فایل
        if (!is_readable($sourcePath) || !file_exists($sourcePath)) {
            return false;
        }
        if (@filesize($sourcePath) < 1) {
            return false;
        }

        // ئەگەر GD چالاک نەبێت (بۆ نموونە XAMPP ی لۆکاڵ) — پەردەختکردن تێدەپەڕێنرێت،
        // بانگکەرەکان false وەردەگرن و فایلە ئۆریجیناڵەکە بەکاردەهێنن.
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        // وەرگرتنی زانیاری فایل (@ بۆ ئەوەی Notice نەدات کاتێک فایل نادروستە یان GD ناتوانێت بخوێنێتەوە)
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false || empty($imageInfo['mime'])) {
            return false;
        }
        
        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'];
        
        // دروستکردنی image resource بەپێی جۆری فایل
        switch ($mimeType) {
            case 'image/jpeg':
                if (!function_exists('imagecreatefromjpeg')) return false;
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                if (!function_exists('imagecreatefrompng')) return false;
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                if (!function_exists('imagecreatefromgif')) return false;
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                if (!function_exists('imagecreatefromwebp')) return false;
                $sourceImage = imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }
        
        if (!$sourceImage) {
            return false;
        }
        
        // حیسابکردنی قەبارەی نوێ
        $newDimensions = self::calculateNewDimensions($originalWidth, $originalHeight, $maxWidth, $maxHeight);
        
        // دروستکردنی وێنەی نوێ
        $newImage = imagecreatetruecolor($newDimensions['width'], $newDimensions['height']);
        
        // پاراستنی شەفافیەتی بۆ PNG
        if ($mimeType === 'image/png') {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newDimensions['width'], $newDimensions['height'], $transparent);
        }
        
        // resize کردنی وێنە
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, 
            $newDimensions['width'], $newDimensions['height'], 
            $originalWidth, $originalHeight);
        
        // هەڵگرتنی وێنەی کەمکراوە
        $result = false;
        switch ($mimeType) {
            case 'image/jpeg':
                $result = imagejpeg($newImage, $destinationPath, $quality);
                break;
            case 'image/png':
                $result = imagepng($newImage, $destinationPath, 9);
                break;
            case 'image/gif':
                $result = imagegif($newImage, $destinationPath);
                break;
            case 'image/webp':
                $result = imagewebp($newImage, $destinationPath, $quality);
                break;
        }
        
        // پاککردنەوەی memory
        imagedestroy($sourceImage);
        imagedestroy($newImage);
        
        return $result;
    }
    
    /**
     * حیسابکردنی قەبارەی نوێ
     */
    private static function calculateNewDimensions($originalWidth, $originalHeight, $maxWidth, $maxHeight) {
        $originalWidth = (float)$originalWidth;
        $originalHeight = (float)$originalHeight;
        $maxWidth = (float)$maxWidth;
        $maxHeight = (float)$maxHeight;

        if ($originalWidth <= 0 || $originalHeight <= 0) {
            return [
                'width' => max(1, (int)$maxWidth),
                'height' => max(1, (int)$maxHeight)
            ];
        }

        $width = $originalWidth;
        $height = $originalHeight;
        
        // حیسابکردنی aspect ratio
        $aspectRatio = $originalWidth / $originalHeight;
        
        // resize ئەگەر زۆر گەورە بێت
        if ($maxWidth > 0 && $width > $maxWidth) {
            $width = $maxWidth;
            $height = $aspectRatio > 0 ? ($width / $aspectRatio) : $height;
        }
        
        if ($maxHeight > 0 && $height > $maxHeight) {
            $height = $maxHeight;
            $width = $height * $aspectRatio;
        }
        
        return [
            'width' => max(1, (int)round($width)),
            'height' => max(1, (int)round($height))
        ];
    }

    /**
     * ئەپلۆدی ئامنی فایل
     */
    /**
     * @param bool $compressAfterMove ئەگەر false بێت، پەردەختکردنی وێنە (GD) ئەنجام نادرێت — بۆ بارکردنی دەرەکی وەک Spaces
     */
    public static function uploadFile($file, $destination, $allowedTypes = ALLOWED_IMAGE_TYPES, $compressAfterMove = true) {
        $errors = [];
        
        // تاقیکردنی بوونی فایل
        if (!isset($file) || !is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "هەڵەیەک ڕوویدا لە ئەپلۆدی فایل";
            return ['success' => false, 'errors' => $errors];
        }
        
        // تاقیکردنی جۆری فایل
        if (!isset($file['name']) || !self::validateFileType($file['name'], $allowedTypes)) {
            $errors[] = "جۆری فایل ڕێپێدراو نییە";
        }
        
        // تاقیکردنی قەبارەی فایل
        if (!isset($file['size']) || !self::validateFileSize($file['size'])) {
            $errors[] = "قەبارەی فایل زۆر گەورەیە";
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        // دروستکردنی ناوی ئامن
        $safeFilename = self::generateSafeFilename($file['name']);
        $targetPath = $destination . '/' . $safeFilename;
        
        // دروستکردنی directory ئەگەر نەبێت
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // گواستنەوەی فایل
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            if ($compressAfterMove) {
                $compressedPath = $targetPath . '.compressed';
                if (self::compressImage($targetPath, $compressedPath)) {
                    if (file_exists($compressedPath)) {
                        unlink($targetPath);
                        rename($compressedPath, $targetPath);
                    }
                }
            }

            return [
                'success' => true,
                'filename' => $safeFilename,
                'path' => $targetPath
            ];
        } else {
            return ['success' => false, 'errors' => ['نەتوانرا فایل ئەپلۆد بکرێت']];
        }
    }
}

if (!function_exists('validateNumber')) {
    /**
     * تاقیکردنی ژمارە
     */
    function validateNumber($number, $min = 0, $max = null) {
        if (!is_numeric($number)) return false;
        if ($number < $min) return false;
        if ($max !== null && $number > $max) return false;
        return true;
    }
}

// دەستپێکردنی secure session
SessionManager::startSecureSession();

// helpers ـی Spaces / وێنە (پێویست بە SecureFileUpload لەم فایلە) — دوای session
require_once __DIR__ . '/product_images.php';

?>