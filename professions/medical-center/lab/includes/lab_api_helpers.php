<?php
/**
 * Shared JSON response helper for lab API endpoints.
 */

if (!function_exists('labJsonRespond')) {
    function labJsonRespond(bool $success, array $extra = [], string $message = '', int $httpCode = 200): void
    {
        if ($httpCode !== 200) {
            http_response_code($httpCode);
        }
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
