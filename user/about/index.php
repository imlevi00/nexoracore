<?php
/**
 * دەربارەی ئێمە - user/about/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'dashboard.view', [
    'route' => '/user/about/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title>دەربارەی ئێمە - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .hero-section {
            background: var(--primary-gradient);
            color: white;
            padding: 80px 0;
            position: relative;
            overflow: hidden;
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.1)" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            opacity: 0.3;
        }
        
        .hero-content {
            position: relative;
            z-index: 1;
        }
        
        .service-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }
        
        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }
        
        .service-card .card-header {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 20px;
            font-size: 1.3rem;
            font-weight: bold;
        }
        
        .service-card .card-body {
            padding: 30px;
            background: white;
        }
        
        .service-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .feature-item {
            padding: 15px 0;
            border-bottom: 1px solid #eee;
            transition: all 0.2s ease;
        }
        
        .feature-item:last-child {
            border-bottom: none;
        }
        
        .feature-item:hover {
            background: #f8f9fa;
            padding-left: 10px;
            border-radius: 8px;
        }
        
        .feature-item i {
            color: #667eea;
            font-size: 1.2rem;
            margin-left: 10px;
        }
        
        .contact-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        .contact-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        
        .contact-item:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(-5px);
        }
        
        .contact-item i {
            font-size: 1.5rem;
            margin-left: 15px;
        }
        
        .contact-item a {
            color: white;
            text-decoration: none;
            font-weight: 500;
        }
        
        .contact-item a:hover {
            text-decoration: underline;
        }
        
        .stats-box {
            background: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .stats-number {
            font-size: 3rem;
            font-weight: bold;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .back-to-dashboard {
            position: fixed;
            bottom: 30px;
            left: 30px;
            background: var(--primary-gradient);
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.5);
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .back-to-dashboard:hover {
            transform: scale(1.1);
            text-decoration: none;
            color: white;
        }
        
        .section-title {
            position: relative;
            padding-bottom: 20px;
            margin-bottom: 40px;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 100px;
            height: 4px;
            background: var(--primary-gradient);
            border-radius: 2px;
        }
        
        @media (max-width: 768px) {
            .hero-section {
                padding: 40px 0;
            }
            
            .stats-number {
                font-size: 2rem;
            }
            
            .back-to-dashboard {
                bottom: 20px;
                left: 20px;
                width: 50px;
                height: 50px;
            }
        }
    </style>
</head>
<body class="about-module-page bg-body-secondary">
    <?php include_once '../../includes/navigation.php'; ?>

<!-- Hero Section -->
    <section class="hero-section">
        <div class="container hero-content">
            <div class="row align-items-center">
                <div class="col-lg-8 mx-auto text-center">
                    <h1 class="display-3 mb-4 fw-bold">Amir Technology</h1>
                    <p class="lead mb-4" style="font-size: 1.5rem;">چارەسەری تەواوی دیجیتاڵی بۆ بزنسی مۆدێرن</p>
                    <div class="stats-box d-inline-block">
                        <div class="stats-number">7+</div>
                        <p class="mb-0 text-muted">ساڵ ئەزموون لە بواری IT</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center section-title fw-bold mb-5">خزمەتگوزاریە دیجیتاڵییەکانمان</h2>
            
            <div class="row g-4">
                <!-- Web Design & Development -->
                <div class="col-lg-6 col-md-6">
                    <div class="service-card">
                        <div class="card-header">
                            <i class="bi bi-globe2"></i> وێب دیزاین و گەشەپێدان
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled">
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    دروستکردنی وێبسایتی پیشەیی
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    دروستکردنی سیستەمی کاشێری دیجیتاڵ
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    دیزاینکردن و بەڕێوەبردنی داتابەیس
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Design & Creative -->
                <div class="col-lg-6 col-md-6">
                    <div class="service-card">
                        <div class="card-header">
                            <i class="bi bi-palette"></i> دیزاین و کرێیتیڤ
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled">
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    دیزاینی گرافیکی و وێنە
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    مۆنتاژکردنی ڤیدیۆ
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    مۆشن گرافیک و ئەنیمەیشن
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Social Media Management -->
                <div class="col-lg-6 col-md-6">
                    <div class="service-card">
                        <div class="card-header">
                            <i class="bi bi-share"></i> بەڕێوەبردنی سۆشیاڵ میدیا
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled">
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    بەڕێوەبردنی پەیجەکان
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    سپۆنسەرکردن و ڕیکلامسازی
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    دانانی وەڵامدانەوەی ئۆتۆماتیک
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- E-Commerce -->
                <div class="col-lg-6 col-md-6">
                    <div class="service-card">
                        <div class="card-header">
                            <i class="bi bi-cart3"></i> بازرگانی ئۆنڵاین
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled">
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    فرۆشتنی کاڵا لە ئینتەرنێت
                                </li>
                                <li class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    چارەسەری بازرگانی دیجیتاڵ
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Training -->
                <div class="col-lg-12">
                    <div class="service-card">
                        <div class="card-header">
                            <i class="bi bi-book"></i> خولی فێرکاری
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li class="feature-item">
                                            <i class="bi bi-check-circle-fill"></i>
                                            کۆرسی دیزاین
                                        </li>
                                        <li class="feature-item">
                                            <i class="bi bi-check-circle-fill"></i>
                                            کۆرسی مۆنتاژ
                                        </li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li class="feature-item">
                                            <i class="bi bi-check-circle-fill"></i>
                                            کۆرسی پڕۆگرامسازی
                                        </li>
                                        <li class="feature-item">
                                            <i class="bi bi-check-circle-fill"></i>
                                            ئەمانەو زیاتر...
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section class="py-5 bg-body">
        <div class="container">
            <h2 class="text-center section-title fw-bold mb-5">پەیوەندی</h2>
            
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="contact-box">
                        <h3 class="mb-4 text-center fw-bold">
                            <i class="bi bi-envelope-heart"></i>
                            پەیوەندیمان پێوە بکە
                        </h3>
                        
                        <!-- Address -->
                        <div class="contact-item">
                            <i class="bi bi-geo-alt-fill"></i>
                            <strong>ناونیشان:</strong> سیدصادق / قەیسەری بەرکێو
                        </div>
                        
                        <!-- Phone Numbers -->
                        <div class="contact-item">
                            <i class="bi bi-telephone-fill"></i>
                            <strong>ژمارەی پەیوەندی:</strong>
                            <div class="mt-2">
                                <a href="tel:07705406115">0770 540 6115</a>
                                <span class="mx-2">|</span>
                                <a href="tel:07501837582">0750 183 7582</a>
                            </div>
                        </div>
                        
                        <!-- Website -->
                        <div class="contact-item">
                            <i class="bi bi-globe"></i>
                            <strong>وێبسایت:</strong>
                            <a href="https://AmirTechOne.com" target="_blank">AmirTechOne.com</a>
                        </div>
                        
                        <!-- Social Media -->
                        <div class="contact-item">
                            <i class="bi bi-facebook"></i>
                            <strong>فەیسبووک:</strong>
                            <a href="https://www.facebook.com/AmirTechOne" target="_blank">@AmirTechOne</a>
                        </div>
                        
                        <div class="contact-item">
                            <i class="bi bi-telegram"></i>
                            <strong>کەناڵی تیلیگرام:</strong>
                            <a href="https://t.me/AmirTechOne" target="_blank">@AmirTechOne</a>
                        </div>
                        
                        <div class="contact-item">
                            <i class="bi bi-person-circle"></i>
                            <strong>ئەکاوەنتی پەیوەندی:</strong>
                            <a href="https://t.me/itz_levi0" target="_blank">@Amir_Kurdish_1</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Back to Dashboard Button -->
    <a href="<?php echo url('user/dashboard/index.php'); ?>" class="back-to-dashboard" title="گەڕانەوە بۆ داشبۆرد">
        <i class="bi bi-house-fill" style="font-size: 1.5rem;"></i>
    </a>

    <!-- Footer -->
    <footer class="py-4 text-center" style="background: #2c3e50; color: white;">
        <div class="container">
            <p class="mb-0">
                © <?php echo date('Y'); ?> Amir Technology - هەموو مافێک پارێزراوە
            </p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

