<?php
/**
 * سەرپەڕەی گشتی - includes/header.php
 */

// Ensure config is loaded
if (!defined('SITE_NAME')) {
    require_once dirname(__DIR__) . '/config/config.php';
}

$pageTitle = $pageTitle ?? 'پەڕە';
$bodyClass = $bodyClass ?? '';
$additionalCSS = $additionalCSS ?? [];
$metaDescription = $metaDescription ?? 'سیستەمی بەڕێوەبردنی فرۆشگا';
$metaKeywords = $metaKeywords ?? 'POS, فرۆشگا, بەڕێوەبردن, کاڵا, فرۆشتن';
require_once dirname(__DIR__) . '/includes/theme_bootstrap.php';

?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    
    <!-- SEO Meta Tags -->
    <title><?php echo clean($pageTitle); ?> - <?php echo SITE_NAME; ?></title>
    <meta name="description" content="<?php echo clean($metaDescription); ?>">
    <meta name="keywords" content="<?php echo clean($metaKeywords); ?>">
    <meta name="author" content="<?php echo SITE_NAME; ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?php echo clean($pageTitle); ?> - <?php echo SITE_NAME; ?>">
    <meta property="og:description" content="<?php echo clean($metaDescription); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo url(); ?>">
    <meta property="og:site_name" content="<?php echo SITE_NAME; ?>">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php echo asset('images/logo.png'); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset('images/logo.png'); ?>">
    
    <!-- CSS -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    
    <!-- Additional CSS -->
    <?php if (!empty($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link href="<?php echo is_url($css) ? $css : asset("css/$css"); ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Shell utilities (see theme-modern.css) -->
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; background: white !important; }
        }
    </style>
</head>
<body class="<?php echo $bodyClass; ?>">

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="text-center">
            <div class="spinner-border text-primary mb-2" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mb-0">لە چاوەڕوانیدا...</p>
        </div>
    </div>

    <!-- Navigation (if not disabled) -->
    <?php if (!isset($hideNavigation) || !$hideNavigation): ?>
        <?php include_once 'navigation.php'; ?>
    <?php endif; ?>

    <!-- Flash Messages Container -->
    <div id="flashMessages">
        <?php
        $message = getMessage();
        if ($message):
        ?>
            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show notification" role="alert">
                <i class="bi bi-<?php 
                    switch($message['type']) {
                        case 'success': echo 'check-circle-fill'; break;
                        case 'error':
                        case 'danger': echo 'exclamation-triangle-fill'; break;
                        case 'warning': echo 'exclamation-triangle-fill'; break;
                        case 'info':
                        default: echo 'info-circle-fill'; break;
                    }
                ?>"></i>
                <?php echo $message['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Page Content Starts Here -->