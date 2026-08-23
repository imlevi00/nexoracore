<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

if (isMedicalSecretaryLoggedIn()) {
    redirect(url('professions/medical-center/secretary/dashboard/index.php'));
}

$error = '';
$csrfToken = Security::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } elseif (Security::isBlocked($email)) {
        $minutes = (int)ceil(Security::getBlockedTime($email) / 60);
        $error = "چوونەژوورەوە بلۆک کراوە بۆ {$minutes} خولەک";
    } elseif ($email === '' || $password === '') {
        $error = 'تکایە ئیمەیڵ و پاسۆرد پڕبکەرەوە';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'ئیمەیڵ نادروستە';
    } else {
        $stmt = $conn_kasher_platform->prepare("
            SELECT id, user_id, doctor_id, name, email, password_hash
            FROM doctor_secretaries
            WHERE email = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && password_verify($password, (string)$row['password_hash'])) {
                Security::trackLoginAttempt($email, true);
                setMedicalSecretarySession([
                    'secretary_id' => (int)$row['id'],
                    'user_id' => (int)$row['user_id'],
                    'doctor_id' => (int)$row['doctor_id'],
                    'name' => (string)$row['name'],
                    'email' => (string)$row['email']
                ]);
                redirect(url('professions/medical-center/secretary/dashboard/index.php'));
            }
        }

        Security::trackLoginAttempt($email, false);
        $error = 'ئیمەیڵ یان پاسۆرد هەڵەیە';
    }
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>چوونەژوورەوەی سکرتێر - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_center/secretary.css'); ?>" rel="stylesheet">
</head>
<body class="secretary-auth-bg">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-7 col-lg-5">
            <div class="card shadow-lg border-0 secretary-card">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="secretary-icon mb-3"><i class="bi bi-person-workspace"></i></div>
                        <h4 class="mb-1">چوونەژوورەوەی سکرتێر</h4>
                        <p class="text-body-secondary mb-0">بەشی مێدیکەل سێنتەر</p>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mb-3">
                            <label class="form-label">ئیمەیڵ</label>
                            <input type="email" name="email" class="form-control form-control-lg" required
                                   value="<?php echo htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">پاسۆرد</label>
                            <input type="password" name="password" class="form-control form-control-lg" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            <i class="bi bi-box-arrow-in-right"></i> چوونەژوورەوە
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
