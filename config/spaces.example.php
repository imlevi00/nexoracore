<?php
/**
 * کۆپی بکە بۆ config/spaces.local.php و نرخەکان پڕ بکەرەوە.
 * spaces.local.php لە git تۆمار ناکرێت.
 */
return [
    'key' => '',
    'secret' => '',
    'bucket' => 'your-bucket',
    'region' => 'fra1',
    'endpoint' => 'https://fra1.digitaloceanspaces.com',
    /** URL ی گشتی بۆ خوێندنەوە (CDN یان bucket public URL) — بێ / لە کۆتایی */
    'public_base_url' => 'https://your-bucket.fra1.cdn.digitaloceanspaces.com',
];
