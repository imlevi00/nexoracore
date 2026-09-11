<?php
/**
 * پەیوەندیمان پێوە بکە - user/aboutsystem/contact.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>پەیوەندیمان پێوە بکە - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#4f46e5">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">

    <style>
        :root {
            --contact-brand: #4f46e5;
            --contact-surface: #ffffff;
            --contact-surface-soft: #f8fafc;
            --contact-border: rgba(148, 163, 184, 0.25);
            --contact-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.06), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            --contact-shadow-hover: 0 20px 30px -10px rgba(79, 70, 229, 0.15);
        }

        .contact-main-wrapper {
            background: var(--contact-surface);
            border-radius: 20px;
            border: 1px solid var(--contact-border);
            box-shadow: var(--contact-shadow);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .contact-hero-banner {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4338ca 80%, #6366f1 100%);
            color: #ffffff;
            padding: 2.5rem 2rem;
            position: relative;
            overflow: hidden;
        }

        .contact-channel-card {
            background: var(--contact-surface-soft);
            border: 1px solid var(--contact-border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .contact-channel-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--contact-shadow-hover);
            border-color: rgba(99, 102, 241, 0.4);
            background: #ffffff;
        }

        .phone-item-box {
            background: #ffffff;
            border: 1px solid var(--contact-border);
            border-radius: 14px;
            padding: 1.15rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            transition: all 0.2s ease;
        }

        .phone-item-box:hover {
            border-color: #4f46e5;
            background: #fdfdfe;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.08);
        }

        .phone-number-text {
            font-family: 'Segoe UI', system-ui, -apple-system, monospace;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: 0.5px;
            direction: ltr;
            text-align: left;
        }

        .social-link-card {
            border-radius: 14px;
            padding: 1.15rem;
            text-decoration: none !important;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #ffffff !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
            font-weight: 600;
        }

        .social-link-card:hover {
            transform: translateY(-3px);
            opacity: 0.95;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.15);
        }

        .social-tg {
            background: linear-gradient(135deg, #2AABEE 0%, #229ED9 100%);
        }

        .social-ig {
            background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
        }

        .social-fb {
            background: linear-gradient(135deg, #1877F2 0%, #0d65d9 100%);
        }

        .social-icon-wrapper {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.45rem;
            flex-shrink: 0;
            backdrop-filter: blur(4px);
        }

        .action-icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            text-decoration: none;
            flex-shrink: 0;
            font-size: 1.1rem;
        }

        .btn-call {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .btn-call:hover {
            background: #1d4ed8;
            color: #ffffff;
        }

        .btn-wa {
            background: #dcfce7;
            color: #15803d;
        }

        .btn-wa:hover {
            background: #15803d;
            color: #ffffff;
        }

        .btn-copy {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            cursor: pointer;
        }

        .btn-copy:hover {
            background: #475569;
            color: #ffffff;
        }

        .copy-toast {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: #1e293b;
            color: #ffffff;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-size: 0.95rem;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            pointer-events: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .copy-toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body class="aboutsystem-module-page bg-body-secondary">

    <!-- Navigation -->
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Main Content -->
    <div class="container py-4">
        
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center hub-page-header flex-wrap gap-2">
                    <div>
                        <nav class="small text-muted mb-2" aria-label="breadcrumb">
                            <a href="<?php echo url('user/dashboard/index.php'); ?>" class="text-decoration-none text-muted">
                                <i class="bi bi-speedometer2"></i> داشبۆرد
                            </a>
                            <span class="mx-2">/</span>
                            <a href="<?php echo url('user/aboutsystem/main.php'); ?>" class="text-decoration-none text-muted">
                                دەربارەی ئێمە و سیستەم
                            </a>
                            <span class="mx-2">/</span>
                            <span class="text-primary fw-medium">پەیوەندیمان پێوە بکە</span>
                        </nav>
                        <h2 class="mb-1">
                            <i class="bi bi-headset text-primary"></i>
                            پەیوەندیمان پێوە بکە
                        </h2>
                        <p class="text-muted mb-0">تیمی پاڵپشتی، خزمەتگوزاری کڕیاران و تۆڕە کۆمەڵایەتییەکان</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?php echo url('user/aboutsystem/main.php'); ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-right"></i> دەربارەی سیستەم
                        </a>
                        <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-outline-primary">
                            <i class="bi bi-speedometer2"></i> داشبۆرد
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- بەشی سەرەکی پەیوەندی -->
        <div class="row">
            <div class="col-12">
                <div class="contact-main-wrapper mb-4">
                    
                    <!-- Banner & Description -->
                    <div class="contact-hero-banner">
                        <div class="row align-items-center g-3">
                            <div class="col-12 col-lg-8">
                                <span class="badge bg-white text-primary fw-bold px-3 py-2 rounded-pill mb-3">
                                    <i class="bi bi-headset me-1"></i> تیمی پاڵپشتی و گەشەپێدان
                                </span>
                                <h3 class="fw-bold mb-2">پەیوەندیمان پێوە بکە</h3>
                                <p class="mb-0 text-white-50" style="font-size: 1.05rem; line-height: 1.8;">
                                    ئێمە لە تیمی <strong>کاشێری نێکسۆرا کۆر (NexoraCore)</strong> پابەندین بە پێشکەشکردنی زیرەکترین و باشترین چارەسەرەکانی بەڕێوەبردنی خاڵی فرۆشتن (POS)، ژمێریاری، کۆگا و چاودێری بازرگانی. بۆ هەر پرسیارێک، ڕاهێنانی ستاف، داواکاری نوێکردنەوە یان پاڵپشتی تەکنیکی، دەتوانن لە ڕێگەی تەلەفۆن، واتسئەپ، یان تۆڕە کۆمەڵایەتییەکانمانەوە پەیوەندیمان پێوە بکەن. هەمیشە ئامادەی خزمەتکردنتانین.
                                </p>
                            </div>
                            <div class="col-12 col-lg-4 text-lg-start">
                                <div class="p-3 rounded-4" style="background: rgba(255,255,255,0.1); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.2);">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="fs-1 text-warning">
                                            <i class="bi bi-shield-check"></i>
                                        </div>
                                        <div>
                                            <h6 class="text-white mb-1 fw-bold">پاڵپشتی بەردەوام</h6>
                                            <p class="small text-white-50 mb-0">وەڵامدانەوەی خێرا بۆ گشت کێشە و ڕێنماییەکان</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Details Content -->
                    <div class="p-4 p-lg-5">
                        <div class="row g-4">

                            <!-- ژمارە تەلەفۆنەکان -->
                            <div class="col-12 col-lg-6">
                                <div class="contact-channel-card">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-3">
                                            <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2 fs-6">
                                                <i class="bi bi-telephone-inbound-fill me-1"></i> پەیوەندی تەلەفۆنی و واتسئەپ
                                            </span>
                                        </div>
                                        <p class="text-muted small mb-4">
                                            دەتوانن لە ڕێگەی پەیوەندی تەلەفۆنی ڕاستەوخۆ یان نامەی واتسئەپ لەگەڵ کارمەندانی پاڵپشتی لە پەیوەندیدا بن:
                                        </p>

                                        <div class="d-flex flex-column gap-3">
                                            
                                            <!-- ژمارەی یەکەم -->
                                            <div class="phone-item-box">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="social-icon-wrapper bg-primary text-white">
                                                        <i class="bi bi-telephone-fill"></i>
                                                    </div>
                                                    <div>
                                                        <div class="small text-muted mb-1">هێڵی پەیوەندی و واتسئەپ ١</div>
                                                        <div class="phone-number-text">0773 193 9973</div>
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <a href="tel:07731939973" class="action-icon-btn btn-call" title="پەیوەندیکردن">
                                                        <i class="bi bi-telephone-outbound-fill"></i>
                                                    </a>
                                                    <a href="https://wa.me/9647731939973" target="_blank" rel="noopener noreferrer" class="action-icon-btn btn-wa" title="نامەی واتسئەپ">
                                                        <i class="bi bi-whatsapp"></i>
                                                    </a>
                                                    <button type="button" class="action-icon-btn btn-copy" onclick="copyToClipboard('07731939973', this)" title="کۆپیکردنی ژمارە">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- ژمارەی دووەم -->
                                            <div class="phone-item-box">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="social-icon-wrapper bg-success text-white">
                                                        <i class="bi bi-telephone-fill"></i>
                                                    </div>
                                                    <div>
                                                        <div class="small text-muted mb-1">هێڵی پەیوەندی و واتسئەپ ٢</div>
                                                        <div class="phone-number-text">0772 774 3043</div>
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <a href="tel:07727743043" class="action-icon-btn btn-call" title="پەیوەندیکردن">
                                                        <i class="bi bi-telephone-outbound-fill"></i>
                                                    </a>
                                                    <a href="https://wa.me/9647727743043" target="_blank" rel="noopener noreferrer" class="action-icon-btn btn-wa" title="نامەی واتسئەپ">
                                                        <i class="bi bi-whatsapp"></i>
                                                    </a>
                                                    <button type="button" class="action-icon-btn btn-copy" onclick="copyToClipboard('07727743043', this)" title="کۆپیکردنی ژمارە">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                </div>
                                            </div>

                                        </div>
                                    </div>

                                    <div class="mt-4 pt-3 border-top text-muted small d-flex align-items-center gap-2">
                                        <i class="bi bi-clock-history text-primary"></i>
                                        <span>کاتەکانی وەڵامدانەوە: هەموو ڕۆژانی هەفتە</span>
                                    </div>
                                </div>
                            </div>

                            <!-- تۆڕە کۆمەڵایەتییەکان -->
                            <div class="col-12 col-lg-6">
                                <div class="contact-channel-card">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-3">
                                            <span class="badge bg-info-subtle text-info-emphasis rounded-pill px-3 py-2 fs-6">
                                                <i class="bi bi-share-fill me-1"></i> تۆڕە کۆمەڵایەتییە فەرمییەکان
                                            </span>
                                        </div>
                                        <p class="text-muted small mb-4">
                                            فۆڵۆمان بکەن بۆ بینینی فێرکارییەکان، نوێکارییەکانی سیستەم و نامەناردنی ڕاستەوخۆ:
                                        </p>

                                        <div class="d-flex flex-column gap-3">
                                            
                                            <!-- تێلیگرام -->
                                            <a href="https://t.me/Itz_levi0" target="_blank" rel="noopener noreferrer" class="social-link-card social-tg">
                                                <div class="social-icon-wrapper">
                                                    <i class="bi bi-telegram"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>تێلیگرام (Telegram)</span>
                                                        <span class="badge bg-white text-dark small px-2 py-1">چاتی ڕاستەوخۆ</span>
                                                    </div>
                                                    <small class="text-white-50 font-monospace" dir="ltr">@Itz_levi0</small>
                                                </div>
                                                <div>
                                                    <i class="bi bi-arrow-left-circle fs-4"></i>
                                                </div>
                                            </a>

                                            <!-- ئینستاگرام -->
                                            <a href="https://www.instagram.com/kasheryai1" target="_blank" rel="noopener noreferrer" class="social-link-card social-ig">
                                                <div class="social-icon-wrapper">
                                                    <i class="bi bi-instagram"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>ئینستاگرام (Instagram)</span>
                                                        <span class="badge bg-white text-dark small px-2 py-1">فۆڵۆمان بکە</span>
                                                    </div>
                                                    <small class="text-white-50 font-monospace" dir="ltr">@zher.zhmerr</small>
                                                </div>
                                                <div>
                                                    <i class="bi bi-arrow-left-circle fs-4"></i>
                                                </div>
                                            </a>

                                            <!-- فەیسبووک -->
                                            <a href="https://www.facebook.com/share/1DZdQYKeU8/?mibextid=wwXIfr" target="_blank" rel="noopener noreferrer" class="social-link-card social-fb">
                                                <div class="social-icon-wrapper">
                                                    <i class="bi bi-facebook"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>فەیسبووک (Facebook)</span>
                                                        <span class="badge bg-white text-dark small px-2 py-1">پەڕەی فەرمی</span>
                                                    </div>
                                                    <small class="text-white-50">NexoraCore</small>
                                                </div>
                                                <div>
                                                    <i class="bi bi-arrow-left-circle fs-4"></i>
                                                </div>
                                            </a>

                                        </div>
                                    </div>

                                    <div class="mt-4 pt-3 border-top text-muted small d-flex align-items-center gap-2">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                        <span>ئەکاونتە فەرمییەکانی کاشێری نێکسۆرا کۆر (NexoraCore)</span>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <!-- Copy Toast Notification -->
    <div id="copyToast" class="copy-toast" role="alert" aria-live="polite">
        <i class="bi bi-check2-circle text-success fs-5"></i>
        <span>ژمارە تەلەفۆنەکە بە سەرکەوتوویی کۆپی کرا!</span>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyToClipboard(text, btnElement) {
            navigator.clipboard.writeText(text).then(function() {
                const toast = document.getElementById('copyToast');
                if (toast) {
                    toast.classList.add('show');
                    setTimeout(() => {
                        toast.classList.remove('show');
                    }, 2500);
                }

                if (btnElement) {
                    const originalHtml = btnElement.innerHTML;
                    btnElement.innerHTML = '<i class="bi bi-check-lg text-success"></i>';
                    btnElement.classList.add('btn-success', 'text-white');
                    setTimeout(() => {
                        btnElement.innerHTML = originalHtml;
                        btnElement.classList.remove('btn-success', 'text-white');
                    }, 2000);
                }
            }).catch(function(err) {
                // Fallback
                const tempInput = document.createElement('input');
                tempInput.value = text;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                const toast = document.getElementById('copyToast');
                if (toast) {
                    toast.classList.add('show');
                    setTimeout(() => {
                        toast.classList.remove('show');
                    }, 2500);
                }
            });
        }
    </script>

</body>
</html>

