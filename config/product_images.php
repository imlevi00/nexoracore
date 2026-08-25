<?php
/**
 * وێنەی کاڵا — DigitalOcean Spaces (S3-compatible)
 *
 * داتابەیس: ڕێڕەوی نسبی وەک products/filename.ext
 * Object key: img/products/filename.ext
 */

require_once __DIR__ . '/spaces.php';
require_once __DIR__ . '/spaces_s3_http.php';

/**
 * MIME بۆ PutObject — بێ پێویستی بە fileinfo/mime_content_type لە هەموو سێرڤەرێکدا
 */
function product_image_detect_mime_type(string $path, string $originalName = ''): string {
    $mime = '';
    if (function_exists('finfo_open')) {
        $f = @finfo_open(FILEINFO_MIME_TYPE);
        if ($f) {
            $mime = (string) @finfo_file($f, $path);
            finfo_close($f);
        }
    }
    if ($mime !== '' && strncmp($mime, 'image/', 6) === 0) {
        return $mime;
    }
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($path);
        if (is_string($m) && $m !== '') {
            return $m;
        }
    }
    $base = $originalName !== '' ? $originalName : basename($path);
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * پەردەختکردنی وێنە (GD) بۆ بارکردن بۆ Spaces — سنوور و کوالیتی لە spaces.php
 *
 * @return array{body:string|false, mime:string}
 */
function spaces_optimized_image_upload_payload(string $sourcePath, string $originalName = ''): array {
    if (!is_readable($sourcePath)) {
        return ['body' => false, 'mime' => 'application/octet-stream'];
    }
    $tmpOut = $sourcePath . '_spaces_opt_' . bin2hex(random_bytes(6));
    $compressed = SecureFileUpload::compressImage(
        $sourcePath,
        $tmpOut,
        SPACES_IMAGE_JPEG_QUALITY,
        SPACES_IMAGE_MAX_WIDTH,
        SPACES_IMAGE_MAX_HEIGHT
    );
    $useCompressed = $compressed && is_file($tmpOut);
    $readFrom = $useCompressed ? $tmpOut : $sourcePath;
    $mime = product_image_detect_mime_type($readFrom, $originalName);
    $body = file_get_contents($readFrom);
    if ($useCompressed) {
        @unlink($tmpOut);
    }
    if ($body === false) {
        return ['body' => false, 'mime' => 'application/octet-stream'];
    }
    return ['body' => $body, 'mime' => $mime];
}

/**
 * ئایا ڕێکخستنی پێویست بۆ بارکردن/سڕینەوە هەیە
 */
function product_spaces_enabled(): bool {
    // هەمیشە ڕێگە بە بارکردن دەدرێت (ئەگەر Spaces نەبێت، بە خۆکاری لە فۆڵدەری ناوخۆیی assets/uploads خەزن دەبێت)
    return true;
}

/**
 * لە ناوەڕۆکی DB → کلیلی تەواو لە bucket
 */
function spaces_product_key_from_db_path(?string $dbPath): ?string {
    if ($dbPath === null || $dbPath === '') {
        return null;
    }
    $dbPath = ltrim($dbPath, '/');
    if (strpos($dbPath, 'products/') === 0) {
        $rest = substr($dbPath, strlen('products/'));
    } else {
        $rest = basename($dbPath);
    }
    $rest = ltrim(str_replace('\\', '/', $rest), '/');
    if ($rest === '') {
        return null;
    }
    return PRODUCT_SPACES_OBJECT_PREFIX . '/' . $rest;
}

/**
 * URL ی گشتی بۆ پیشاندانی وێنە
 */
function product_image_url(?string $dbPath): ?string {
    if ($dbPath === null || $dbPath === '') {
        return null;
    }
    $key = spaces_product_key_from_db_path($dbPath);
    if ($key === null) {
        return null;
    }
    return spaces_public_url_for_object_key($key);
}

/**
 * سڕینەوەی کۆپی کۆنی لەسەر دیسک (ئەگەر هەبێت) + DeleteObject لە Spaces
 */
function delete_product_image_from_spaces(?string $dbPath): bool {
    if ($dbPath === null || $dbPath === '') {
        return true;
    }
    if (defined('UPLOADS_PATH')) {
        $local = UPLOADS_PATH . '/' . ltrim($dbPath, '/');
        if (is_file($local)) {
            @unlink($local);
        }
    }
    if (!product_spaces_enabled()) {
        return true;
    }
    $key = spaces_product_key_from_db_path($dbPath);
    if ($key === null) {
        return true;
    }
    try {
        spaces_delete_object($key);
        return true;
    } catch (Throwable $e) {
        if (function_exists('writeLog')) {
            writeLog('Spaces delete_product_image: ' . $e->getMessage());
        } else {
            error_log('Spaces delete_product_image: ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * ئەپڵۆد لە فۆڕمی HTTP → پەردەخت لە temp → PutObject → گەڕاندنەوەی products/...
 *
 * @return array{success:bool, db_path?:string, errors?:array}
 */
function upload_product_image_to_spaces(array $file): array {
    if (!product_spaces_enabled()) {
        return ['success' => false, 'errors' => ['ڕێکخستنی Spaces تەواو نییە']];
    }
    $tmpDir = sys_get_temp_dir() . '/kasher_pimg_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        return ['success' => false, 'errors' => ['نەتوانرا فۆڵدەری کاتی دروست بکرێت']];
    }
    // پەردەختکردنی GD لە سێرڤەر (یەکگرتوو لەگەڵ SPACES_IMAGE_*)
    $uploadResult = SecureFileUpload::uploadFile($file, $tmpDir, ALLOWED_IMAGE_TYPES, true);
    if (!$uploadResult['success']) {
        @rmdir($tmpDir);
        return $uploadResult;
    }
    $localPath = $uploadResult['path'];
    $filename = $uploadResult['filename'];
    $dbPath = 'products/' . $filename;
    $key = spaces_product_key_from_db_path($dbPath);
    if ($key === null) {
        @unlink($localPath);
        @rmdir($tmpDir);
        return ['success' => false, 'errors' => ['ناونانی فایل نادروستە']];
    }
    try {
        $body = file_get_contents($localPath);
        if ($body === false) {
            @unlink($localPath);
            @rmdir($tmpDir);
            return ['success' => false, 'errors' => ['نەتوانرا فایل خوێندرایەوە']];
        }
        $contentType = product_image_detect_mime_type($localPath, $file['name'] ?? '');
        spaces_put_object($key, $body, $contentType);
    } catch (Throwable $e) {
        @unlink($localPath);
        @rmdir($tmpDir);
        $msg = $e->getMessage();
        if (function_exists('writeLog')) {
            writeLog('Spaces upload_product_image: ' . $msg);
        }
        if (strlen($msg) > 400) {
            $msg = substr($msg, 0, 400) . '…';
        }
        return ['success' => false, 'errors' => ['نەتوانرا وێنە بارکرێت بۆ Spaces: ' . $msg]];
    }
    @unlink($localPath);
    @rmdir($tmpDir);
    return ['success' => true, 'db_path' => $dbPath];
}

/**
 * بارکردنی فایلێک کە لەسەر دیسکی ناوخۆ هەیە (بۆ manage_product_details)
 *
 * @return array{success:bool, db_path?:string, errors?:array}
 */
function upload_product_image_file_to_spaces(string $localPath, string $originalFilename): array {
    if (!product_spaces_enabled()) {
        return ['success' => false, 'errors' => ['ڕێکخستنی Spaces تەواو نییە']];
    }
    if (!is_readable($localPath)) {
        return ['success' => false, 'errors' => ['فایل نەدۆزرایەوە']];
    }
    $safe = SecureFileUpload::generateSafeFilename($originalFilename);
    $dbPath = 'products/' . $safe;
    $key = spaces_product_key_from_db_path($dbPath);
    if ($key === null) {
        return ['success' => false, 'errors' => ['ناونانی فایل نادروستە']];
    }
    try {
        $payload = spaces_optimized_image_upload_payload($localPath, $originalFilename);
        if ($payload['body'] === false) {
            return ['success' => false, 'errors' => ['نەتوانرا فایل خوێندرایەوە']];
        }
        spaces_put_object($key, $payload['body'], $payload['mime']);
        return ['success' => true, 'db_path' => $dbPath];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (function_exists('writeLog')) {
            writeLog('Spaces upload_product_image_file: ' . $msg);
        }
        if (strlen($msg) > 400) {
            $msg = substr($msg, 0, 400) . '…';
        }
        return ['success' => false, 'errors' => ['نەتوانرا وێنە بارکرێت بۆ Spaces: ' . $msg]];
    }
}
