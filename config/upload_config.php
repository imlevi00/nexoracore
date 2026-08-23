<?php
/**
 * ڕێکخستنەکانی ئەپلۆدی فایل
 * config/upload_config.php
 */

// دانانی سنوورەکانی ئەپلۆد
ini_set('upload_max_filesize', '8M');
ini_set('post_max_size', '25M');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);
ini_set('memory_limit', '256M');

// دڵنیابوونەوە لە ئەپلۆدی فایل
ini_set('file_uploads', '1');

// سنوورەکانی ئەپلۆد
define('MAX_UPLOAD_SIZE', 8 * 1024 * 1024); // 8MB
define('MAX_POST_SIZE', 25 * 1024 * 1024); // 25MB

// جۆرەکانی فایلی ڕێگەپێدراو
define('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_IMAGE_MIME_TYPES', [
    'image/jpeg',
    'image/jpg', 
    'image/png',
    'image/gif',
    'image/webp'
]);

/**
 * تاقیکردنی قەبارەی فایل
 */
function validateFileSize($fileSize, $maxSize = MAX_UPLOAD_SIZE) {
    return $fileSize <= $maxSize;
}

/**
 * تاقیکردنی جۆری فایل
 */
function validateFileType($filename, $allowedTypes = ALLOWED_IMAGE_EXTENSIONS) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowedTypes);
}

/**
 * تاقیکردنی MIME type
 */
function validateMimeType($mimeType, $allowedTypes = ALLOWED_IMAGE_MIME_TYPES) {
    return in_array($mimeType, $allowedTypes);
}

/**
 * گەڕاندنەوەی پەیامی هەڵە بە کوردی
 */
function getUploadErrorMessage($errorCode) {
    $messages = [
        UPLOAD_ERR_OK => 'ئەپلۆد بە سەرکەوتوویی تەواو بوو',
        UPLOAD_ERR_INI_SIZE => 'فایلەکە گەورەترە لە سنووری upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'فایلەکە گەورەترە لە سنووری MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'فایلەکە تەنها بەشێکی ئەپلۆد کرا',
        UPLOAD_ERR_NO_FILE => 'هیچ فایلێک ئەپلۆد نەکراوە',
        UPLOAD_ERR_NO_TMP_DIR => 'فۆڵدەری کاتی بوونی نییە',
        UPLOAD_ERR_CANT_WRITE => 'نەتوانرا فایل بنووسرێتەوە',
        UPLOAD_ERR_EXTENSION => 'ئەپلۆدی فایل بەهۆی extension ەوە وەستا'
    ];
    
    return $messages[$errorCode] ?? 'هەڵەی نەزانراو لە ئەپلۆدی فایل';
}

/**
 * تاقیکردنی تەواوی فایل
 */
function validateUploadedFile($file) {
    $errors = [];
    
    // تاقیکردنی بوونی فایل
    if (!isset($file) || !is_array($file)) {
        $errors[] = 'فایلەکە نەدۆزرایەوە';
        return ['valid' => false, 'errors' => $errors];
    }
    
    // تاقیکردنی error code
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = getUploadErrorMessage($file['error']);
        return ['valid' => false, 'errors' => $errors];
    }
    
    // تاقیکردنی قەبارە
    if (!validateFileSize($file['size'])) {
        $errors[] = 'قەبارەی فایل زۆر گەورەیە. حەدئکثر ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB';
    }
    
    // تاقیکردنی جۆری فایل
    if (!validateFileType($file['name'])) {
        $errors[] = 'جۆری فایل ڕێگەپێدراو نییە. تەنها ' . implode(', ', ALLOWED_IMAGE_EXTENSIONS) . ' ڕێگەپێدراوە';
    }
    
    // تاقیکردنی MIME type
    if (!validateMimeType($file['type'])) {
        $errors[] = 'جۆری MIME ی فایل ڕێگەپێدراو نییە';
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * دروستکردنی ناوی ئامن بۆ فایل
 */
function generateSecureFilename($originalName, $prefix = '') {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $timestamp = time();
    $random = bin2hex(random_bytes(8));
    $prefix = $prefix ? $prefix . '_' : '';
    
    return $prefix . $timestamp . '_' . $random . '.' . $extension;
}

/**
 * ئەپلۆدی ئامنی فایل
 */
function secureFileUpload($file, $destination, $prefix = '') {
    $validation = validateUploadedFile($file);
    
    if (!$validation['valid']) {
        return [
            'success' => false,
            'errors' => $validation['errors']
        ];
    }
    
    // دروستکردنی ناوی ئامن
    $filename = generateSecureFilename($file['name'], $prefix);
    $targetPath = rtrim($destination, '/') . '/' . $filename;
    
    // دروستکردنی directory ئەگەر نەبێت
    $dir = dirname($targetPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            return [
                'success' => false,
                'errors' => ['نەتوانرا فۆڵدەر دروست بکرێت']
            ];
        }
    }
    
    // گواستنەوەی فایل
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $targetPath,
            'size' => $file['size']
        ];
    } else {
        return [
            'success' => false,
            'errors' => ['نەتوانرا فایل گواستنەوە بکرێت']
        ];
    }
}

/**
 * کەمکردنەوەی قەبارەی وێنە
 * ئەم فەنکشێنە وێنەکە دەخوێنێتەوە و قەبارەکەی کەمدەکاتەوە
 */
function resizeImage($sourcePath, $destinationPath, $maxWidth = 1920, $maxHeight = 1080, $quality = 80) {
    // تاقیکردنی بوونی فایل
    if (!file_exists($sourcePath)) {
        return [
            'success' => false,
            'error' => 'فایلی سەرچاوە نەدۆزرایەوە'
        ];
    }
    
    // وەرگرتنی زانیاری وێنە
    $imageInfo = @getimagesize($sourcePath);
    if ($imageInfo === false) {
        return [
            'success' => false,
            'error' => 'نەتوانرا زانیاری وێنە بخوێنرێتەوە'
        ];
    }
    
    list($originalWidth, $originalHeight, $imageType) = $imageInfo;
    
    // دروستکردنی وێنەی سەرچاوە
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $sourceImage = @imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = @imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = @imagecreatefromgif($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            $sourceImage = @imagecreatefromwebp($sourcePath);
            break;
        default:
            return [
                'success' => false,
                'error' => 'جۆری وێنە پاڵپشتی نەکراوە'
            ];
    }
    
    if ($sourceImage === false) {
        return [
            'success' => false,
            'error' => 'نەتوانرا وێنە بخوێنرێتەوە'
        ];
    }
    
    // حیسابکردنی قەبارەی نوێ
    $aspectRatio = $originalWidth / $originalHeight;
    
    $newWidth = $originalWidth;
    $newHeight = $originalHeight;
    
    // ئەگەر وێنە گەورەتر بێت لە سنوور، قەبارەکەی کەمبکەرەوە
    if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
        if ($originalWidth > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = $newWidth / $aspectRatio;
        }
        
        if ($newHeight > $maxHeight) {
            $newHeight = $maxHeight;
            $newWidth = $newHeight * $aspectRatio;
        }
        
        $newWidth = round($newWidth);
        $newHeight = round($newHeight);
    }
    
    // دروستکردنی وێنەی نوێ
    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // پاراستنی شەفافیەت بۆ PNG و GIF
    if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);
        $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
        imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // کۆپیکردن و گەورەکردنەوەی وێنە
    imagecopyresampled(
        $resizedImage,
        $sourceImage,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $originalWidth, $originalHeight
    );
    
    // پاشەکەوتکردنی وێنەی نوێ
    $success = false;
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $success = imagejpeg($resizedImage, $destinationPath, $quality);
            break;
        case IMAGETYPE_PNG:
            // PNG quality بە ئاستی 0-9 (9 بەرزترین کۆمپرێشن)
            $pngQuality = round((100 - $quality) / 10);
            $success = imagepng($resizedImage, $destinationPath, $pngQuality);
            break;
        case IMAGETYPE_GIF:
            $success = imagegif($resizedImage, $destinationPath);
            break;
        case IMAGETYPE_WEBP:
            $success = imagewebp($resizedImage, $destinationPath, $quality);
            break;
    }
    
    // پاککردنەوەی یادگە
    imagedestroy($sourceImage);
    imagedestroy($resizedImage);
    
    if ($success) {
        return [
            'success' => true,
            'original_width' => $originalWidth,
            'original_height' => $originalHeight,
            'new_width' => $newWidth,
            'new_height' => $newHeight,
            'original_size' => filesize($sourcePath),
            'new_size' => filesize($destinationPath)
        ];
    } else {
        return [
            'success' => false,
            'error' => 'نەتوانرا وێنە پاشەکەوت بکرێت'
        ];
    }
}

/**
 * گەڕاندنەوەی زانیاری ڕێکخستنەکانی ئەپلۆد
 */
function getUploadConfig() {
    return [
        'max_file_size' => MAX_UPLOAD_SIZE,
        'max_post_size' => MAX_POST_SIZE,
        'allowed_extensions' => ALLOWED_IMAGE_EXTENSIONS,
        'allowed_mime_types' => ALLOWED_IMAGE_MIME_TYPES,
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit')
    ];
}
?>