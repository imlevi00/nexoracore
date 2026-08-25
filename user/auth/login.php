<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * داخڵبوونی بەکارهێنەر - user/auth/login.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/security.php';
require_once '../../config/config.php';
require_once '../../config/security.php';

// ئەگەر user logged in بێت، redirect بکە
if (isUser()) {
    redirect(url('user/dashboard/index.php'));
}

if (isset($_GET['unblock']) || isset($_GET['reset'])) {
    foreach ($_SESSION as $k => $v) {
        if (strpos((string)$k, 'login_attempts_') === 0) {
            unset($_SESSION[$k]);
        }
    }
}

 
$error = '';
$showPendingContact = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // CSRF token validation
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    }
    
    // Check if blocked
    elseif (Security::isBlocked($email)) {
        $remainingTime = Security::getBlockedTime($email);
        $minutes = ceil($remainingTime / 60);
        $error = "ئەکاونت بلۆک کراوە بۆ $minutes خولەک بەهۆی هەوڵی زۆرەوە";
    }
    
    elseif (empty($email) || empty($password)) {
        $error = 'تکایە هەموو خانەکان پڕبکەرەوە';
    }
    
    else {
        // Check main user credentials first
        $stmt = $conn->prepare("SELECT id, business_name, email, password, status, approved_at, expiration_date FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if ($user['status'] !== 'approved') {
                switch ($user['status']) {
                    case 'pending':
                        $error = 'ئەکاونتەکەت لە چاوەڕوانی پەسەندکردنی بەڕێوەبەردایە';
                        $showPendingContact = true;
                        break;
                    case 'rejected':
                        $error = 'ئەکاونتەکەت ڕەت کرایەوە. پەیوەندی بە بەڕێوەبەر بگرە';
                        break;
                    default:
                        $error = 'ئەکاونتەکەت لە دۆخێکی نەناسراودایە';
                }
            } elseif ($user['expiration_date'] && strtotime($user['expiration_date']) < time()) {
                // Account has expired
                $expiredDate = date('Y/m/d', strtotime($user['expiration_date']));
                $error = "ئەکاونتەکەت پێوستی بە نوێکردنەوەیە<br>پەیوەندیمان پێوەبکە تا وەکوو بەزووترین کات ئەکاوەنتەکەتان بۆ نوێ بکەینەوە<br>لە تیلیگرام نامە بنێرە: <a href='https://t.me/itz_levi0' target='_blank' class='text-primary'>@itz_levi0</a>";
            } elseif (Security::verifyPassword($password, $user['password'])) {
                // Successful main user login
                Security::trackLoginAttempt($email, true);

                // Set session data
                SessionManager::loginUser([
                    'id' => $user['id'],
                    'business_name' => $user['business_name'],
                    'email' => $user['email'],
                    'approved_at' => $user['approved_at']
                ], 'user', true);

                // Set theme mode (default to light)
                $userThemeMode = 'light';
                $dbZFile = __DIR__ . '/../../config/kasher_zanyari/database.php';
                if (file_exists($dbZFile)) {
                    require_once $dbZFile;
                    if (isset($conn_zanyari) && $conn_zanyari instanceof mysqli) {
                        $tStmt = $conn_zanyari->prepare('SELECT theme_mode FROM user_account_settings WHERE user_id = ? LIMIT 1');
                        if ($tStmt) {
                            $tStmt->bind_param('i', $user['id']);
                            if ($tStmt->execute()) {
                                $tRes = $tStmt->get_result();
                                $tRow = $tRes ? $tRes->fetch_assoc() : null;
                                if ($tRow && !empty($tRow['theme_mode'])) {
                                    $userThemeMode = strtolower((string)$tRow['theme_mode']);
                                }
                            }
                            $tStmt->close();
                        }
                    }
                }
                $_SESSION['user_theme_mode'] = $userThemeMode;
                $_SESSION['user_data']['theme_mode'] = $userThemeMode;

                // Handle Remember Me
                if (isset($_POST['remember']) && $_POST['remember'] == 'on') {
                    $tokenData = Security::createRememberToken($user['id'], 'user');
                    if ($tokenData) {
                        $cookieValue = $tokenData['selector'] . ':' . $tokenData['token'];
                        // دانانی cookie بۆ 30 ڕۆژ
                        setcookie('remember_me', $cookieValue, time() + (30 * 24 * 60 * 60), '/', '', true, true);
                    }
                }

                // Log activity
                writeLog("Main user login successful: {$user['email']} from IP: " . $_SERVER['REMOTE_ADDR']);

                setMessage('بەخێرهاتیت ' . $user['business_name'], 'success');
                redirect(url('user/dashboard/index.php'));
            } else {
                // Wrong password for main user, check sub-users
                $stmt->close();
                
                // Check sub-user credentials
                $stmt = $conn->prepare("SELECT su.id, su.username, su.email, su.password, su.full_name, su.permissions, su.is_active, su.expiration_date, u.business_name, u.id as main_user_id, u.expiration_date as main_expiration_date FROM sub_users su JOIN users u ON su.main_user_id = u.id WHERE su.email = ? AND su.is_active = 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($subUser = $result->fetch_assoc()) {
                    // Check if sub-user or main user account has expired
                    $isExpired = false;
                    $expiredDate = '';
                    
                    if ($subUser['expiration_date'] && strtotime($subUser['expiration_date']) < time()) {
                        $isExpired = true;
                        $expiredDate = date('Y/m/d', strtotime($subUser['expiration_date']));
                    } elseif ($subUser['main_expiration_date'] && strtotime($subUser['main_expiration_date']) < time()) {
                        $isExpired = true;
                        $expiredDate = date('Y/m/d', strtotime($subUser['main_expiration_date']));
                    }
                    
                    if ($isExpired) {
                        $error = "ئەکاونتەکەت پێوستی بە نوێکردنەوەیە<br>پەیوەندیمان پێوەبکە تا وەکوو بەزووترین کات ئەکاوەنتەکەتان بۆ نوێ بکەینەوە<br>لە تیلیگرام نامە بنێرە: <a href='https://t.me/itz_levi0' target='_blank' class='text-primary'>@itz_levi0</a>";
                    } elseif (Security::verifyPassword($password, $subUser['password'])) {
                        // تاقیکردنی سنووری کارمەندان بەپێی پاکێجی بەکارهێنەری سەرەکی
                        $maxEmployees = getMaxEmployeesForUser($subUser['main_user_id']);
                        $currentEmployeeCount = getEmployeeCountForUser($subUser['main_user_id']);
                        
                        if ($maxEmployees <= 0 || $currentEmployeeCount > $maxEmployees) {
                            $error = "ئەم خزمەتگوزارییە بۆ تۆ بەردەست نییە<br>ژمارەی کارمەندەکان ($currentEmployeeCount) زیاترە لەوەی لە پاکێجەکەدا دانراوە ($maxEmployees)<br>پێویستە پاکێجەکەتان بەرزبکەنەوە<br>لە تیلیگرام پەیوەندی بە بەڕێوەبەرەوە بکەن: <a href='https://t.me/itz_levi0' target='_blank' class='text-primary'>@itz_levi0</a>";
                            writeLog("Sub-user login blocked (count=$currentEmployeeCount, max=$maxEmployees): {$subUser['email']} from IP: " . $_SERVER['REMOTE_ADDR']);
                        } else {
                            // Successful sub-user login
                            Security::trackLoginAttempt($email, true);

                            // Update last login
                            $updateStmt = $conn->prepare("UPDATE sub_users SET last_login = NOW() WHERE id = ?");
                            $updateStmt->bind_param("i", $subUser['id']);
                            $updateStmt->execute();
                            $updateStmt->close();

                            // Set session data for sub-user
                            SessionManager::loginUser([
                                'id' => $subUser['main_user_id'],
                                'sub_user_id' => $subUser['id'],
                                'business_name' => $subUser['business_name'],
                                'email' => $subUser['email'],
                                'username' => $subUser['username'],
                                'full_name' => $subUser['full_name'],
                                'permissions' => json_decode($subUser['permissions'], true),
                                'user_type' => 'sub'
                            ], 'user', true);

                            $_SESSION['user_theme_mode'] = 'light';
                            $_SESSION['user_data']['theme_mode'] = 'light';

                            // Handle Remember Me for sub-user
                            if (isset($_POST['remember']) && $_POST['remember'] == 'on') {
                                $tokenData = Security::createRememberToken($subUser['id'], 'sub');
                                if ($tokenData) {
                                    $cookieValue = $tokenData['selector'] . ':' . $tokenData['token'];
                                    setcookie('remember_me', $cookieValue, time() + (30 * 24 * 60 * 60), '/', '', true, true);
                                }
                            }

                            // Log activity
                            writeLog("Sub-user login successful: {$subUser['email']} (Main: {$subUser['business_name']}) from IP: " . $_SERVER['REMOTE_ADDR']);

                            setMessage('بەخێرهاتیت ' . $subUser['full_name'], 'success');
                            redirect(url('user/dashboard/index.php'));
                        }
                    } else {
                        // Wrong password for sub-user
                        Security::trackLoginAttempt($email, false);
                        $error = 'ئیمەیڵ یان پاسۆرد هەڵەیە';
                        writeLog("Sub-user login failed: wrong password for {$email} from IP: " . $_SERVER['REMOTE_ADDR']);
                    }
                } else {
                    // Sub-user not found
                    Security::trackLoginAttempt($email, false);
                    $error = 'ئیمەیڵ یان پاسۆرد هەڵەیە';
                    writeLog("Login failed: user not found {$email} from IP: " . $_SERVER['REMOTE_ADDR']);
                }
            }
        } else {
            // Main user not found, check sub-users
            $stmt->close();
            
            // Check sub-user credentials
            $stmt = $conn->prepare("SELECT su.id, su.username, su.email, su.password, su.full_name, su.permissions, su.is_active, su.expiration_date, u.business_name, u.id as main_user_id, u.expiration_date as main_expiration_date FROM sub_users su JOIN users u ON su.main_user_id = u.id WHERE su.email = ? AND su.is_active = 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($subUser = $result->fetch_assoc()) {
                // Check if sub-user or main user account has expired
                $isExpired = false;
                $expiredDate = '';
                
                if ($subUser['expiration_date'] && strtotime($subUser['expiration_date']) < time()) {
                    $isExpired = true;
                    $expiredDate = date('Y/m/d', strtotime($subUser['expiration_date']));
                } elseif ($subUser['main_expiration_date'] && strtotime($subUser['main_expiration_date']) < time()) {
                    $isExpired = true;
                    $expiredDate = date('Y/m/d', strtotime($subUser['main_expiration_date']));
                }
                
                if ($isExpired) {
                    $error = "ئەکاونتەکەت پێوستی بە نوێکردنەوەیە<br>پەیوەندیمان پێوەبکە تا وەکوو بەزووترین کات ئەکاوەنتەکەتان بۆ نوێ بکەینەوە<br>لە تیلیگرام نامە بنێرە: <a href='https://t.me/itz_levi0' target='_blank' class='text-primary'>@itz_levi0</a>";
                } elseif (Security::verifyPassword($password, $subUser['password'])) {
                    // تاقیکردنی سنووری کارمەندان بەپێی پاکێجی بەکارهێنەری سەرەکی
                    $maxEmployees = getMaxEmployeesForUser($subUser['main_user_id']);
                    $currentEmployeeCount = getEmployeeCountForUser($subUser['main_user_id']);
                    
                    if ($maxEmployees <= 0 || $currentEmployeeCount > $maxEmployees) {
                        $error = "ئەم خزمەتگوزارییە بۆ تۆ بەردەست نییە<br>ژمارەی کارمەندەکان ($currentEmployeeCount) زیاترە لەوەی لە پاکێجەکەدا دانراوە ($maxEmployees)<br>پێویستە پاکێجەکەتان بەرزبکەنەوە<br>لە تیلیگرام پەیوەندی بە بەڕێوەبەرەوە بکەن: <a href='https://t.me/itz_levi0' target='_blank' class='text-primary'>@itz_levi0</a>";
                        writeLog("Sub-user login blocked (count=$currentEmployeeCount, max=$maxEmployees): {$subUser['email']} from IP: " . $_SERVER['REMOTE_ADDR']);
                    } else {
                        // Successful sub-user login
                        Security::trackLoginAttempt($email, true);

                        // Update last login
                        $updateStmt = $conn->prepare("UPDATE sub_users SET last_login = NOW() WHERE id = ?");
                        $updateStmt->bind_param("i", $subUser['id']);
                        $updateStmt->execute();
                        $updateStmt->close();

                        // Set session data for sub-user
                        SessionManager::loginUser([
                            'id' => $subUser['main_user_id'],
                            'sub_user_id' => $subUser['id'],
                            'business_name' => $subUser['business_name'],
                            'email' => $subUser['email'],
                            'username' => $subUser['username'],
                            'full_name' => $subUser['full_name'],
                            'permissions' => json_decode($subUser['permissions'], true),
                            'user_type' => 'sub'
                        ], 'user', true);

                        // Handle Remember Me for sub-user
                        if (isset($_POST['remember']) && $_POST['remember'] == 'on') {
                            $tokenData = Security::createRememberToken($subUser['id'], 'sub');
                            if ($tokenData) {
                                $cookieValue = $tokenData['selector'] . ':' . $tokenData['token'];
                                setcookie('remember_me', $cookieValue, time() + (30 * 24 * 60 * 60), '/', '', true, true);
                            }
                        }

                        // Log activity
                        writeLog("Sub-user login successful: {$subUser['email']} (Main: {$subUser['business_name']}) from IP: " . $_SERVER['REMOTE_ADDR']);

                        setMessage('بەخێرهاتیت ' . $subUser['full_name'], 'success');
                        redirect(url('user/dashboard/index.php'));
                    }
                } else {
                    // Wrong password for sub-user
                    Security::trackLoginAttempt($email, false);
                    $error = 'ئیمەیڵ یان پاسۆرد هەڵەیە';
                    writeLog("Sub-user login failed: wrong password for {$email} from IP: " . $_SERVER['REMOTE_ADDR']);
                }
            } else {
                // User not found
                Security::trackLoginAttempt($email, false);
                $error = 'ئیمەیڵ یان پاسۆرد هەڵەیە';
                writeLog("Login failed: user not found {$email} from IP: " . $_SERVER['REMOTE_ADDR']);
            }
        }
        
        $stmt->close();
    }
}

// Get login attempts count for display
$loginAttempts = 0;
if (!empty($_POST['email'])) {
    $loginAttempts = Security::getLoginAttempts($_POST['email']);
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();
?>

<?php require __DIR__ . '/login_view.inc.php';
