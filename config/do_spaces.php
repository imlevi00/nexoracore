<?php
/**
 * گۆشەی پاشکۆ بۆ کۆدی کۆن — نرخەکان لە config/spaces.php دێن.
 */
require_once __DIR__ . '/spaces.php';
require_once __DIR__ . '/local_storage.php';

$accessKey = SPACES_KEY;
$secretKey = SPACES_SECRET;
$spaceName = SPACES_BUCKET;
$region = SPACES_REGION;
