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
        return 'light';
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
            return 'light';
        }

        if (!function_exists('isUser') || !isUser()) {
            return 'light';
        }

        if (!empty($_SESSION['user_theme_mode'])) {
            return kasher_normalize_theme_mode($_SESSION['user_theme_mode']);
        }

        if (!empty($_SESSION['user_data']['theme_mode'])) {
            $themeMode = kasher_normalize_theme_mode($_SESSION['user_data']['theme_mode']);
            $_SESSION['user_theme_mode'] = $themeMode;
            return $themeMode;
        }

        $userId = (int)($_SESSION['user_data']['id'] ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
        if ($userId <= 0 && function_exists('getCurrentUser')) {
            $cu = getCurrentUser();
            $userId = (int)($cu['id'] ?? 0);
        }

        $themeMode = 'light';

        if ($userId > 0) {
            $dbFile = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__)) . '/config/kasher_zanyari/database.php';
            if (file_exists($dbFile) && !isset($GLOBALS['conn_zanyari'])) {
                require_once $dbFile;
            }

            $connZanyari = $GLOBALS['conn_zanyari'] ?? null;
            if ($connZanyari instanceof mysqli) {
                try {
                    $stmt = $connZanyari->prepare('SELECT theme_mode FROM user_account_settings WHERE user_id = ? LIMIT 1');
                    if ($stmt) {
                        $stmt->bind_param('i', $userId);
                        if ($stmt->execute()) {
                            $res = $stmt->get_result();
                            $row = $res ? $res->fetch_assoc() : null;
                            if ($row && !empty($row['theme_mode'])) {
                                $themeMode = kasher_normalize_theme_mode($row['theme_mode']);
                            }
                        }
                        $stmt->close();
                    }
                } catch (\Exception $e) {
                    $themeMode = 'light';
                } catch (\Throwable $e) {
                    $themeMode = 'light';
                }
            }
        }

        $_SESSION['user_theme_mode'] = $themeMode;
        if (isset($_SESSION['user_data']) && is_array($_SESSION['user_data'])) {
            $_SESSION['user_data']['theme_mode'] = $themeMode;
        }
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
            . 'var normalized=(nextMode==="dark"||nextMode==="light"||nextMode==="system")?nextMode:"light";'
            . 'mode=normalized;'
            . 'applyTheme();'
            . '}'
            . '};'
            . '})();</script>';
    }
}
