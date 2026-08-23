<?php
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

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';

function generateLocalCsrfToken(): string
{
    if (empty($_SESSION['google_reset_csrf']) || !is_string($_SESSION['google_reset_csrf'])) {
        $_SESSION['google_reset_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['google_reset_csrf'];
}

function validateLocalCsrfToken(string $token): bool
{
    return isset($_SESSION['google_reset_csrf'])
        && is_string($_SESSION['google_reset_csrf'])
        && hash_equals($_SESSION['google_reset_csrf'], $token);
}

function validateLocalPasswordStrength(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'پاسۆرد دەبێت لانی کەم 8 پیت بێت';
    }
    return $errors;
}

$usersConn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;
$googleAuth = $_SESSION['google_forgot_auth'] ?? null;
$email = is_array($googleAuth) ? (string)($googleAuth['email'] ?? '') : '';
$emailVerifiedBool = !empty($googleAuth['email_verified']);

$resetError = '';
$resetSuccess = '';
$mainUser = null;

if (!is_array($googleAuth)) {
    $resetError = 'تکایە سەرەتا بە Google هاتنەژوورەوە تەواو بکە بۆ گۆڕینی پاسۆرد.';
} elseif ($email === '' || !$emailVerifiedBool) {
    $resetError = 'ببورە، Gmail ـەکەت پشتڕاست نەکراوەتەوە لەلایەن Google. ناتوانرێت پاسۆرد بگۆڕدرێت.';
} elseif ($usersConn instanceof mysqli) {
    try {
        $stmt = $usersConn->prepare("SELECT id, business_name, email FROM users WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $mainUser = $result ? $result->fetch_assoc() : null;
            $stmt->close();
        }
    } catch (Throwable $e) {
        $resetError = 'هەڵەی سیستەم ڕوویدا لە کاتی دۆزینەوەی ئەکاونت.';
    }
} else {
    $resetError = 'پەیوەندی بە داتابەیس بەردەست نییە. تکایە دووبارە هەوڵبدە.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!validateLocalCsrfToken($csrfToken)) {
        $resetError = 'هەڵەی پاراستن (CSRF). تکایە دووبارە هەوڵبدە.';
    } elseif (empty($mainUser)) {
        $resetError = 'ئەم Gmail ـە بەکارهێنەری سەرەکی نییە. تەنها یوزەری سەرەکی دەتوانێت پاسۆرد بگۆڕێت.';
    } elseif ($newPassword === '' || $confirmPassword === '') {
        $resetError = 'تکایە هەردوو خانەی پاسۆرد پڕبکەرەوە.';
    } elseif ($newPassword !== $confirmPassword) {
        $resetError = 'پاسۆردی نوێ و دووبارەکردنەوەکە یەک ناگرنەوە.';
    } else {
        $passwordErrors = validateLocalPasswordStrength($newPassword);
        if (!empty($passwordErrors)) {
            $resetError = implode('<br>', $passwordErrors);
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            try {
                $stmt = ($usersConn instanceof mysqli)
                    ? $usersConn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1")
                    : false;
                if ($stmt) {
                    $stmt->bind_param('si', $hashedPassword, $mainUser['id']);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();

                    if ($affected >= 0) {
                        $resetSuccess = 'پاسۆردەکەت بە سەرکەوتوویی گۆڕدرا. دەتوانیت ئێستا بە پاسۆردی نوێ داخڵ ببیت.';
                        unset($_SESSION['google_login_flow']);
                        unset($_SESSION['google_login_return_to']);
                        unset($_SESSION['google_forgot_auth']);
                        unset($_SESSION['google_reset_csrf']);
                    } else {
                        $resetError = 'گۆڕینی پاسۆرد سەرکەوتوو نەبوو. تکایە دووبارە هەوڵبدە.';
                    }
                } else {
                    $resetError = 'هەڵەی سیستەم ڕوویدا لە کاتی گۆڕینی پاسۆرد.';
                }
            } catch (Throwable $e) {
                $resetError = 'هەڵەی سیستەم ڕوویدا لە کاتی گۆڕینی پاسۆرد.';
            }
        }
    }
}

$csrfToken = generateLocalCsrfToken();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گۆڕینی پاسۆرد بە Gmail</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/auth-slim.css'); ?>" rel="stylesheet">
</head>
<body class="auth-slim-page d-flex align-items-center justify-content-center">
<div class="auth-slim-card card border-0">
    <div class="card-header">
        <h1 class="h5 mb-0">گۆڕینی پاسۆرد بە Gmail</h1>
    </div>
    <div class="card-body">
    <p class="text-muted small mb-3">Gmail: <strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong></p>

    <?php if ($resetError !== ''): ?>
        <div class="alert alert-danger py-2"><?php echo $resetError; ?></div>
    <?php endif; ?>

    <?php if ($resetSuccess !== ''): ?>
        <div class="alert alert-success py-2"><?php echo $resetSuccess; ?></div>
        <a class="btn btn-link px-0" href="/user/auth/login.php">چوونەوە بۆ لاپەڕەی داخڵبوون</a>
    <?php elseif (!empty($mainUser)): ?>
        <p class="text-muted small mb-3">ئەم Gmail ـە بە یوزەری سەرەکی پەیوەستە: <strong><?php echo htmlspecialchars((string)$mainUser['business_name'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="form-label" for="new_password">پاسۆردی نوێ</label>
            <input class="form-control mb-2" id="new_password" name="new_password" type="password" minlength="8" required>

            <label class="form-label" for="confirm_password">دووبارەکردنەوەی پاسۆرد</label>
            <input class="form-control mb-3" id="confirm_password" name="confirm_password" type="password" minlength="8" required>

            <button class="btn btn-primary w-100" type="submit">پاسۆرد بگۆڕە</button>
        </form>
    <?php else: ?>
        <div class="alert alert-warning py-2">ئەم Gmail ـە لە بەکارهێنەر  لە کارمەند ـە. تەنها یوزەری سەرەکی دەتوانێت لەم ڕێگایە پاسۆرد بگۆڕێت.</div>
        <a class="btn btn-link px-0" href="/user/auth/login.php">چوونەوە بۆ لاپەڕەی داخڵبوون</a>
    <?php endif; ?>
    </div>
</div>
</body>
</html>
