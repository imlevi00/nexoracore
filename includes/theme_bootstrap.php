<?php
/**
 * Global theme bootstrap helpers.
 */

if (!function_exists('kasher_normalize_theme_mode')) {
    function kasher_normalize_theme_mode($mode) {
        $value = strtolower(trim((string)$mode));
        if ($value === 'light' || $value === 'dark' || $value === 'system') {
            return $value;
        }
        return 'system';
    }
}

if (!function_exists('kasher_is_admin_path')) {
    function kasher_is_admin_path() {
        $uri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
        $scriptName = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        return strpos($uri, '/admin/') !== false
            || strpos($uri, '/adminkx9mzpqa7wvrt4ny6lb3/') !== false
            || strpos($scriptName, '/admin/') !== false
            || strpos($scriptName, '/adminkx9mzpqa7wvrt4ny6lb3/') !== false;
    }
}

if (!function_exists('kasher_is_html_request')) {
    function kasher_is_html_request() {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = strtolower((string)parse_url($uri, PHP_URL_PATH));

        if ($path === '') {
            return true;
        }

        if (preg_match('/\.(css|js|map|json|xml|txt|png|jpe?g|gif|webp|svg|ico|pdf|zip|csv)$/i', $path)) {
            return false;
        }

        if (strpos($path, '/api/') !== false) {
            return false;
        }

        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && strpos($accept, 'text/html') === false && strpos($accept, '*/*') === false) {
            return false;
        }

        return true;
    }
}

if (!function_exists('kasher_should_apply_theme_bootstrap')) {
    function kasher_should_apply_theme_bootstrap() {
        return !kasher_is_admin_path() && kasher_is_html_request();
    }
}

if (!function_exists('kasher_resolve_theme_mode')) {
    function kasher_resolve_theme_mode() {
        if (kasher_is_admin_path()) {
            return 'system';
        }

        if (!function_exists('isUser') || !isUser()) {
            return 'system';
        }

        if (!empty($_SESSION['user_theme_mode'])) {
            return kasher_normalize_theme_mode($_SESSION['user_theme_mode']);
        }

        if (!empty($_SESSION['user_data']['theme_mode'])) {
            $themeMode = kasher_normalize_theme_mode($_SESSION['user_data']['theme_mode']);
            $_SESSION['user_theme_mode'] = $themeMode;
            return $themeMode;
        }

        if (!defined('ROOT_PATH')) {
            return 'system';
        }

        $dbFile = ROOT_PATH . '/config/kasher_zanyari/database.php';
        if (file_exists($dbFile) && !isset($GLOBALS['conn_zanyari'])) {
            require_once $dbFile;
        }

        $connZanyari = $GLOBALS['conn_zanyari'] ?? null;
        $userId = (int)($_SESSION['user_data']['id'] ?? 0);
        if (!($connZanyari instanceof mysqli) || $userId <= 0) {
            return 'system';
        }

        $themeMode = 'system';
        try {
            $stmt = $connZanyari->prepare('SELECT theme_mode FROM user_account_settings WHERE user_id = ? LIMIT 1');
            if (!$stmt) {
                return 'system';
            }

            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($row && isset($row['theme_mode'])) {
                    $themeMode = kasher_normalize_theme_mode($row['theme_mode']);
                }
            }
            $stmt->close();
        } catch (Throwable $e) {
            $themeMode = 'system';
        }

        $_SESSION['user_theme_mode'] = $themeMode;
        $_SESSION['user_data']['theme_mode'] = $themeMode;
        return $themeMode;
    }
}

if (!function_exists('kasher_get_theme_bootstrap_markup')) {
    function kasher_get_theme_bootstrap_markup() {
        if (!kasher_should_apply_theme_bootstrap()) {
            return '';
        }

        $themeMode = kasher_resolve_theme_mode();
        $safeMode = json_encode($themeMode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<script id="kasher-theme-bootstrap">(function(){'
            . 'var mode=' . $safeMode . ';'
            . 'var root=document.documentElement;'
            . 'if(!root){return;}'
            . 'function systemIsDark(){'
            . 'return !!(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches);'
            . '}'
            . 'function applyTheme(){'
            . 'var isDark=(mode==="dark")||(mode==="system"&&systemIsDark());'
            . 'root.setAttribute("data-kasher-theme-mode",mode);'
            . 'root.setAttribute("data-bs-theme",isDark?"dark":"light");'
            . '}'
            . 'applyTheme();'
            . 'if(mode==="system"&&window.matchMedia){'
            . 'var media=window.matchMedia("(prefers-color-scheme: dark)");'
            . 'if(media&&typeof media.addEventListener==="function"){media.addEventListener("change",applyTheme);}'
            . 'else if(media&&typeof media.addListener==="function"){media.addListener(applyTheme);}'
            . '}'
            . 'window.__kasherTheme={'
            . 'getMode:function(){return mode;},'
            . 'setMode:function(nextMode){'
            . 'var normalized=(nextMode==="dark"||nextMode==="light"||nextMode==="system")?nextMode:"system";'
            . 'mode=normalized;'
            . 'applyTheme();'
            . '}'
            . '};'
            . '})();</script>';
    }
}
