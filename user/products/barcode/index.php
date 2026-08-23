<?php
/**
 * دروستکردنی لەیبڵی بارکۆد - user/products/barcode/index.php
 */

require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/permissions.php';

SessionManager::requireAuth('user');
requireProductsBarcodeAccess();

$toolFile = __DIR__ . '/barcode_tool.html';
if (!is_file($toolFile)) {
    http_response_code(500);
    echo 'فایلی ئامرازی بارکۆد نەدۆزرایەوە.';
    exit;
}

readfile($toolFile);
