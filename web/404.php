<?php
/**
 * 404 Error Page - web/404.php
 * Handle missing shop pages
 */

// Include config if not already included
if (!defined('SITE_NAME')) {
    require_once '../config/config.php';
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرۆشگا نەدۆزرایەوە - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="template/assets/css/shop.css" rel="stylesheet">
    <style>
        .error-page-content {
            background: white;
            padding: 3rem 2rem;
            border-radius: 20px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }
        .error-icon-wrapper {
            display: inline-block;
            animation: float 3s ease-in-out infinite;
        }
        .error-code {
            font-size: 6rem;
            font-weight: 900;
            background: linear-gradient(135deg, #ffc107 0%, #ff6b6b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
            margin: 1rem 0;
        }
        .suggestions-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1.5rem;
            border-radius: 15px;
            border-right: 4px solid #0d6efd;
        }
        .suggestions-box h6 {
            color: #0d6efd;
            font-weight: 600;
        }
        .suggestions-box ul li {
            padding: 0.5rem 0;
            color: #495057;
        }
        .suggestions-box ul li i {
            margin-left: 0.5rem;
        }
        .error-actions .btn {
            min-width: 180px;
        }
        @media (max-width: 576px) {
            .error-code {
                font-size: 4rem;
            }
            .error-page-content {
                padding: 2rem 1rem;
            }
        }
    </style>
</head>
<body class="bg-light">

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>web/">
                <i class="bi bi-arrow-right"></i>
                گەڕانەوە بۆ فرۆشگاکان
            </a>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-7 text-center">
                <div class="error-page-content">
                    <div class="error-animation mb-4">
                        <div class="error-icon-wrapper">
                            <i class="bi bi-exclamation-triangle display-1 text-warning"></i>
                        </div>
                        <h1 class="error-code">404</h1>
                    </div>
                    
                    <div class="error-details">
                        <h2 class="mb-3 fw-bold">فرۆشگا نەدۆزرایەوە</h2>
                        <p class="lead text-muted mb-4">
                            ئەم فرۆشگایە نەدۆزرایەوە یان چالاک نییە.
                        </p>
                        
                        <div class="suggestions-box mb-4">
                            <h6 class="mb-3">
                                <i class="bi bi-lightbulb"></i>
                                پێشنیاری بونیات:
                            </h6>
                            <ul class="list-unstyled text-start">
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success"></i>
                                    دڵنیای لە دروستی بەستەری URL بکەرەوە
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success"></i>
                                    بگەڕێرەوە بۆ لیستی فرۆشگاکانی چالاک
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success"></i>
                                    پەیوەندی بکە بە خاوەنی فرۆشگاکە
                                </li>
                            </ul>
                        </div>
                        
                        <div class="error-actions d-flex gap-3 justify-content-center flex-wrap">
                            <a href="<?php echo SITE_URL; ?>web/" class="btn btn-primary btn-lg">
                                <i class="bi bi-house"></i>
                                پەڕەی سەرەکی
                            </a>
                            <a href="<?php echo SITE_URL; ?>web/" class="btn btn-outline-primary btn-lg">
                                <i class="bi bi-shop"></i>
                                لیستی فرۆشگاکان
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-light py-4 mt-5">
        <div class="container text-center">
            <p class="mb-0">
                <i class="bi bi-shield-check"></i>
                پاڵپشتی لەلایەن <?php echo SITE_NAME; ?>
            </p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const errorContent = document.querySelector('.error-page-content');
            if (errorContent) {
                errorContent.style.animation = 'fadeIn 0.6s ease-out';
            }
        });
    </script>

</body>
</html>
