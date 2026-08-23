<?php
/**
 * API Session Keep-Alive - api/session_keepalive.php
 * چالاکخستنەوەی سێشن و نوێکردنەوەی CSRF token
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../includes/permissions.php';

// Function to send JSON response
function sendResponse($success, $data = null, $message = '', $code = 200, $error = null) {
    http_response_code($code);
    $payload = [
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('c')
    ];

    if ($error !== null) {
        $payload['error'] = $error;
    }

    echo json_encode($payload);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, null, 'تەنها داواکاری POST ڕێپێدراوە', 405, 'method_not_allowed');
}

// Check if user is authenticated
if (!isUser()) {
    sendResponse(false, [
        'session_active' => false,
        'redirect' => url('user/auth/login.php')
    ], 'غیر مجاز - داخڵبوون پێویستە', 401, 'unauthorized');
}

try {
    // Get current user to verify session is valid
    $currentUser = getCurrentUser();
    
    if (!$currentUser) {
        sendResponse(false, [
            'session_active' => false,
            'redirect' => url('user/auth/login.php')
        ], 'سێشن نەدۆزرایەوە', 401, 'session_not_found');
    }

    enforceAuthorizationOrDeny($currentUser, 'dashboard.view', [
        'route' => '/api/session_keepalive.php',
        'request_method' => 'POST'
    ], 'json');
    
    // Regenerate session to extend expiration
    // This keeps the session alive
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Touch session to update last activity time
        $_SESSION['last_activity'] = time();
    }
    
    // Generate new CSRF token
    $newCsrfToken = Security::generateCSRFToken();
    
    // Send success response with new CSRF token
    sendResponse(true, [
        'user_id' => $currentUser['id'],
        'csrf_token' => $newCsrfToken,
        'session_active' => true
    ], 'سێشن بە سەرکەوتوویی نوێکرایەوە', 200, null);
    
} catch (Exception $e) {
    // Log error but don't expose details to client
    error_log('Session keep-alive error: ' . $e->getMessage());
    sendResponse(false, [
        'session_active' => false
    ], 'هەڵەیەک ڕوویدا لە نوێکردنەوەی سێشن', 500, 'server_error');
}

