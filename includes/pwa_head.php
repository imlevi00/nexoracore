<?php
/**
 * PWA meta tags and config — includes/pwa_head.php
 */
if (!defined('SITE_NAME')) {
    require_once dirname(__DIR__) . '/config/config.php';
}

$pwaScopePath = rtrim(parse_url(SITE_URL, PHP_URL_PATH) ?: '', '/');
if ($pwaScopePath === '') {
    $pwaScopePath = '/';
} else {
    $pwaScopePath .= '/';
}
?>
<link rel="manifest" href="<?php echo url('manifest.webmanifest'); ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo asset('images/pwa/favicon-32.png'); ?>">
<link rel="apple-touch-icon" href="<?php echo asset('images/pwa/apple-touch-icon.png'); ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?php echo clean(SITE_NAME); ?>">
<meta name="mobile-web-app-capable" content="yes">
<script id="kasher-pwa-config" type="application/json"><?php
echo json_encode([
    'swUrl' => url('sw.js'),
    'scope' => $pwaScopePath,
    'logoUrl' => asset('images/pwa/icon-192.png'),
    'dismissKey' => 'pwa_install_dismissed',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?></script>
