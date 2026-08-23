<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "=== DIAGNOSTIC START ===\n";

try {
    echo "1. Loading config.php...\n";
    require_once __DIR__ . '/config/config.php';
    echo "2. config.php loaded successfully.\n";

    echo "3. Testing DB connection...\n";
    global $conn;
    if ($conn) {
        echo "DB connected. Server info: " . $conn->server_info . "\n";
    } else {
        echo "DB connection is NULL!\n";
    }

    echo "4. Loading security.php...\n";
    require_once __DIR__ . '/config/security.php';
    echo "5. security.php loaded.\n";

    echo "6. Testing isUser()...\n";
    $u = isUser();
    echo "isUser result: " . ($u ? 'true' : 'false') . "\n";

    echo "7. Testing login_view.inc.php...\n";
    $error = '';
    $loginAttempts = 0;
    $csrf_token = 'test';
    // capture output
    ob_start();
    include __DIR__ . '/user/auth/login_view.inc.php';
    $viewOut = ob_get_clean();
    echo "login_view.inc.php rendered, length: " . strlen($viewOut) . "\n";

    echo "=== DIAGNOSTIC FINISHED WITH SUCCESS ===\n";
} catch (Throwable $t) {
    echo "FATAL ERROR CAUGHT:\n";
    echo "Message: " . $t->getMessage() . "\n";
    echo "File: " . $t->getFile() . "\n";
    echo "Line: " . $t->getLine() . "\n";
    echo "Trace:\n" . $t->getTraceAsString() . "\n";
}
