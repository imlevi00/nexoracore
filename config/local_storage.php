<?php
/**
 * config/local_storage.php
 * ---------------------------------------------------------------------------
 * خەزنی ناوخۆیی (Local storage) بۆ ژینگەی لۆکاڵ (XAMPP).
 *
 * ئامانج: هەمان کۆد بەبێ گۆڕانکاری کار بکات:
 *   - لەسەر سێرڤەر  → وێنە/ڤیدیۆ دەچن بۆ DigitalOcean Spaces (وەک ئێستا).
 *   - لە لۆکاڵ       → لە فۆڵدەری assets/uploads/ خەزن دەبن و URL ـەکە بۆ
 *                      SITE_URL/assets/uploads/ دەڕوات.
 *
 * ڕێبازی object key: هەمان object key ـی Spaces (بۆ نموونە img/products/x.jpg
 * یان videos/product_videos/y.mp4) وەک ڕێڕەوی ناوخۆیی لەژێر UPLOADS_PATH
 * بەکاردەهێنرێتەوە — بۆیە بارکردن، پیشاندان و سڕینەوە هەمووی یەکگرتوون.
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/env.php';

if (!function_exists('kasher_storage_is_local')) {

    /**
     * ئایا لە ژینگەی لۆکاڵداین (خەزنی ناوخۆیی بەکاردێت لەبری Spaces)
     */
    function kasher_storage_is_local(): bool {
        return defined('KASHER_IS_LOCAL') && KASHER_IS_LOCAL;
    }

    /**
     * ڕەگی فۆڵدەری خەزنی ناوخۆیی — assets/uploads لەناو ڕەگی ئەپەکە
     */
    function kasher_local_uploads_root(): string {
        if (defined('UPLOADS_PATH')) {
            return rtrim(str_replace('\\', '/', UPLOADS_PATH), '/');
        }
        return str_replace('\\', '/', dirname(__DIR__)) . '/assets/uploads';
    }

    /**
     * بنەڕەتی URL ـی گشتی بۆ فایلە ناوخۆییەکان (بێ / لە کۆتایی)
     */
    function kasher_local_uploads_base_url(): string {
        if (defined('UPLOADS_URL')) {
            return rtrim(UPLOADS_URL, '/');
        }
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        return $site . '/assets/uploads';
    }

    /**
     * نۆرماڵکردنی object key — / یەکلاکردنەوە و لابردنی / ی سەرەتا
     */
    function kasher_normalize_object_key(string $objectKey): string {
        return ltrim(str_replace('\\', '/', $objectKey), '/');
    }

    /**
     * ڕێڕەوی فایلی ناوخۆیی بۆ object key ـێک
     */
    function kasher_local_path_for_object_key(string $objectKey): string {
        return kasher_local_uploads_root() . '/' . kasher_normalize_object_key($objectKey);
    }

    /**
     * URL ـی گشتی ناوخۆیی بۆ object key ـێک (هەر بەشێک بە rawurlencode)
     */
    function kasher_local_public_url_for_object_key(string $objectKey): ?string {
        $key = kasher_normalize_object_key($objectKey);
        if ($key === '') {
            return null;
        }
        $parts = explode('/', $key);
        $enc = array_map('rawurlencode', $parts);
        return kasher_local_uploads_base_url() . '/' . implode('/', $enc);
    }

    /**
     * دڵنیابوون لە بوونی فۆڵدەری باوان بۆ ڕێڕەوێک
     *
     * @throws RuntimeException
     */
    function kasher_local_ensure_dir(string $filePath): void {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('نەتوانرا فۆڵدەری خەزنکردن دروست بکرێت: ' . $dir);
        }
    }

    /**
     * خەزنکردنی ناوەڕۆکێک (body) وەک فایل بۆ object key
     *
     * @throws RuntimeException
     */
    function kasher_local_put_object(string $objectKey, string $body): void {
        $dest = kasher_local_path_for_object_key($objectKey);
        kasher_local_ensure_dir($dest);
        if (@file_put_contents($dest, $body) === false) {
            throw new RuntimeException('نەتوانرا فایل خەزن بکرێت: ' . $dest);
        }
    }

    /**
     * کۆپیکردنی فایلێکی سەر دیسک بۆ object key (بۆ ڤیدیۆی گەورە — بێ خستنە RAM)
     *
     * @throws RuntimeException
     */
    function kasher_local_put_object_from_file(string $objectKey, string $sourcePath): void {
        if (!is_readable($sourcePath)) {
            throw new RuntimeException('فایلی سەرچاوە نەدۆزرایەوە: ' . $sourcePath);
        }
        $dest = kasher_local_path_for_object_key($objectKey);
        kasher_local_ensure_dir($dest);
        if (!@copy($sourcePath, $dest)) {
            throw new RuntimeException('نەتوانرا فایل کۆپی بکرێت بۆ: ' . $dest);
        }
    }

    /**
     * سڕینەوەی فایلی ناوخۆیی بۆ object key (بێ هەڵە ئەگەر نەبوو)
     */
    function kasher_local_delete_object(string $objectKey): void {
        $dest = kasher_local_path_for_object_key($objectKey);
        if (is_file($dest)) {
            @unlink($dest);
        }
    }

    /**
     * دەرهێنانی object key لە URL ـێکی ناوخۆیی (پاش /assets/uploads/)
     */
    function kasher_object_key_from_local_url(string $url): ?string {
        $parsed = parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : $url;
        $marker = '/assets/uploads/';
        $pos = strpos($path, $marker);
        if ($pos === false) {
            return null;
        }
        $key = substr($path, $pos + strlen($marker));
        $key = rawurldecode(ltrim($key, '/'));
        return $key !== '' ? $key : null;
    }
}
