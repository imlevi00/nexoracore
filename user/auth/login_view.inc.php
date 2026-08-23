<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داخڵبوون - <?php echo SITE_NAME; ?></title>
    <link rel="icon" type="image/png" href="<?php echo asset('images/logo.png'); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset('images/logo.png'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/auth-login.css'); ?>" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-shell">
        <aside class="login-showcase">
            <div class="login-showcase-grid" aria-hidden="true"></div>
            <div class="login-showcase-inner">
                <div class="showcase-brand">
                    <div class="showcase-logo"><img src="<?php echo asset('images/logo.png'); ?>" alt="<?php echo SITE_NAME; ?>"></div>
                    <div class="showcase-brand-text">
                        <strong><?php echo SITE_NAME; ?></strong>
                        <span>سیستەمی کاشیر و بەڕێوەبردن</span>
                    </div>
                </div>
                <h1>بازرگانییەکەت بە زیرەکی بەڕێوەببە</h1>
                <p>فرۆشتن، کۆگا، کڕیار و ڕاپۆرت — هەمووی لە یەک پلاتفۆرم. خێرا، ئاسان، ئامادە بۆ گەشەکردن.</p>
                <ul class="login-showcase-list">
                    <li><i class="bi bi-lightning-charge-fill"></i><span>POS خێرا بە پشتگیری ئۆفلاین</span></li>
                    <li><i class="bi bi-box-seam"></i><span>کۆگا و ئاگادارکردنەوەی کاتی ڕاست</span></li>
                    <li><i class="bi bi-graph-up-arrow"></i><span>ڕاپۆرت و شیکاری بە بڕیاردان</span></li>
                </ul>
                <div class="showcase-stat-row">
                    <div class="showcase-stat"><strong>+۱۲K</strong><span>بازرگانی</span></div>
                    <div class="showcase-stat"><strong>٪۹۹.۹</strong><span>کاتی کارکردن</span></div>
                    <div class="showcase-stat"><strong>۲۴/۷</strong><span>پشتگیری</span></div>
                </div>
            </div>
        </aside>

        <main class="login-main">
            <div class="login-panel">
                <div class="login-card">
                    <div class="login-card-head">
                        <div class="mini-logo"><img src="<?php echo asset('images/logo.png'); ?>" alt="<?php echo SITE_NAME; ?>"></div>
                        <h2>داخڵبوون</h2>
                        <p>زانیارییەکانی ئەکاونتەکەت بنووسە</p>
                    </div>

                    <div class="login-card-body">
                        <?php if (!empty($error)): ?>
                            <div class="login-alert login-alert-danger" role="alert">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>
                                    <?php echo $error; ?>
                                    <?php if ($loginAttempts > 0): ?>
                                        <br><small>هەوڵ: <?php echo $loginAttempts; ?> / <?php echo MAX_LOGIN_ATTEMPTS; ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php
                        $message = getMessage();
                        if ($message):
                        ?>
                            <div class="login-alert login-alert-<?php echo htmlspecialchars($message['type']); ?>" role="alert">
                                <i class="bi bi-info-circle-fill"></i>
                                <div><?php echo $message['message']; ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($showPendingContact)): ?>
                            <div class="login-pending-box">
                                <h5><i class="bi bi-lightning-charge-fill"></i> بۆ وەرگرتنی ئەکاونت پەیوەندی بکە</h5>
                                <p class="login-pending-note">پێویستە لە ڕێگەی ئەم شێوازانەوە پەیوەندیمان پێوە بکەیت:</p>
                                <a href="https://t.me/itz_levi0" target="_blank" class="login-submit login-telegram-btn">
                                    <i class="bi bi-telegram"></i> پەیام لە تێلێگرام
                                </a>
                                <div class="login-phone-links">
                                    <a href="tel:07731939973" dir="ltr">0773 193 9973</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="login-demo-hint">
                            <i class="bi bi-info-circle"></i>
                            <div>
                                بۆ تاقیکردنەوە: User <code>demo@kashery.local</code> · Pass <code>123456</code>
                            </div>
                        </div>

                        <form method="POST" class="needs-validation" novalidate id="loginForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="remember" value="on">

                            <div class="login-field">
                                <label for="email">User</label>
                                <div class="login-input-wrap">
                                    <i class="bi bi-person field-icon"></i>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                           placeholder="user@email.com"
                                           autocomplete="username"
                                           required autofocus>
                                </div>
                                <div class="invalid-feedback">تکایە User / ئیمەیڵ بنووسە</div>
                            </div>

                            <div class="login-field">
                                <label for="password">Pass</label>
                                <div class="login-input-wrap">
                                    <i class="bi bi-lock field-icon"></i>
                                    <input type="password" class="form-control" id="password" name="password"
                                           placeholder="pass"
                                           autocomplete="current-password"
                                           required>
                                    <button type="button" class="login-toggle-pass" id="togglePass" aria-label="پیشاندانی پاسورد">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback">تکایە Pass / پاسورد بنووسە</div>
                            </div>

                            <button type="submit" class="login-submit">
                                <i class="bi bi-box-arrow-in-right"></i>
                                داخڵبوون
                            </button>

                            <div class="login-links">
                                <a href="<?php echo url('videos/google-login.php?flow=forgot_password&return_to=user/auth/login.php'); ?>" class="muted-link">
                                    <i class="bi bi-question-circle"></i> پاسوردت لەبیرکردووە؟
                                </a>
                                <a href="https://nexoracore.com/terms_and_conditions.html" target="_blank">
                                    <i class="bi bi-file-text"></i> مەرجەکان
                                </a>
                            </div>
                        </form>
                    </div>

                    <div class="login-card-foot">
                        ئەکاونتت نییە؟
                        <a href="<?php echo url('user/auth/register.php'); ?>">ئێستا تۆمار بکە</a>
                    </div>
                </div>

                <div class="login-bottom-links">
                    <a href="<?php echo url(); ?>"><i class="bi bi-house"></i> گەڕانەوە بۆ سەرەکی</a>
                    <span class="mx-2">·</span>
                    <span><i class="bi bi-shield-check"></i> پەیوەندی پارێزراو</span>
                </div>
            </div>
        </main>
    </div>

    <script>
        (function () {
            'use strict';
            var form = document.getElementById('loginForm');
            if (form) {
                form.addEventListener('submit', function (e) {
                    if (!form.checkValidity()) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    form.classList.add('was-validated');
                });
            }

            var toggle = document.getElementById('togglePass');
            var pass = document.getElementById('password');
            if (toggle && pass) {
                toggle.addEventListener('click', function () {
                    var isText = pass.type === 'text';
                    pass.type = isText ? 'password' : 'text';
                    toggle.innerHTML = isText ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                var email = document.getElementById('email');
                if (email && email.value === '') {
                    email.focus();
                } else if (pass) {
                    pass.focus();
                }
            });
        })();
    </script>
</body>
</html>
