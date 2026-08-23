<?php
/**
 * Customer Logout - web/auth/logout.php
 * Handles customer logout
 */

require_once 'session_helper.php';

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['slug']) : '';

CustomerSession::logoutCustomerOnly();

if ($slug !== '') {
    if (!isset($_SESSION['shop_suppress_google_auto']) || !is_array($_SESSION['shop_suppress_google_auto'])) {
        $_SESSION['shop_suppress_google_auto'] = [];
    }
    $_SESSION['shop_suppress_google_auto'][$slug] = true;
}

// Redirect to shop
$loc = '../shop.php';
if ($slug !== '') {
    $loc .= '?slug=' . rawurlencode($slug);
}
header('Location: ' . $loc);
exit;
?>
