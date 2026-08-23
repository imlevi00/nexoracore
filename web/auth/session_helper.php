<?php
/**
 * Session Helper for Web Customers
 * Handles customer authentication and session management
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

class CustomerSession {
    
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public static function login($customerId, $customerData) {
        self::start();
        $_SESSION['customer_id'] = $customerId;
        $_SESSION['customer_name'] = $customerData['name'];
        $_SESSION['customer_email'] = $customerData['email'];
        $_SESSION['customer_phone'] = $customerData['phone'];
        $_SESSION['customer_address'] = $customerData['address'] ?? '';
        
        // Update last login
        global $conn;
        $stmt = $conn->prepare("UPDATE web_customers SET last_login = NOW() WHERE id = ?");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $stmt->close();
    }
    
    public static function logout() {
        self::start();
        session_destroy();
    }

    /**
     * Clear shop customer session keys without destroying the session (preserves Google OAuth state).
     */
    public static function logoutCustomerOnly(): void
    {
        self::start();
        unset(
            $_SESSION['customer_id'],
            $_SESSION['customer_name'],
            $_SESSION['customer_email'],
            $_SESSION['customer_phone'],
            $_SESSION['customer_address']
        );
    }
    
    public static function isLoggedIn() {
        self::start();
        return isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);
    }
    
    public static function getCustomerId() {
        self::start();
        return $_SESSION['customer_id'] ?? null;
    }
    
    public static function getCustomerData() {
        self::start();
        if (!self::isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['customer_id'],
            'name' => $_SESSION['customer_name'],
            'email' => $_SESSION['customer_email'],
            'phone' => $_SESSION['customer_phone'],
            'address' => $_SESSION['customer_address']
        ];
    }
    
    public static function requireLogin($redirectUrl = null) {
        if (!self::isLoggedIn()) {
            if ($redirectUrl) {
                header("Location: " . SITE_URL . "web/auth/login.php?redirect=" . urlencode($redirectUrl));
            } else {
                header("Location: " . SITE_URL . "web/auth/login.php");
            }
            exit;
        }
    }
    
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    // ==========================================
    // Guest Session Management Functions
    // ==========================================
    
    /**
     * Check if current user is a guest (not logged in)
     */
    public static function isGuest() {
        return !self::isLoggedIn();
    }
    
    /**
     * Get or create a guest session ID
     * This ID is stored in the session and used to track guest orders
     */
    public static function getOrCreateGuestSessionId() {
        self::start();
        
        if (!isset($_SESSION['guest_session_id'])) {
            // Generate a unique session ID for the guest
            $_SESSION['guest_session_id'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['guest_session_id'];
    }
    
    /**
     * Get the guest session ID if it exists
     */
    public static function getGuestSessionId() {
        self::start();
        return $_SESSION['guest_session_id'] ?? null;
    }
    
    /**
     * Get the client's IP address
     */
    public static function getClientIp() {
        // Check for proxy headers first
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Get the first IP if multiple are present
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        return trim($ip);
    }
    
    /**
     * Get guest orders by session ID and IP address
     * Returns orders that match both session ID and IP for security
     */
    public static function getGuestOrders($sessionId = null, $ipAddress = null) {
        global $conn;
        
        if ($sessionId === null) {
            $sessionId = self::getGuestSessionId();
        }
        
        if ($ipAddress === null) {
            $ipAddress = self::getClientIp();
        }
        
        if (empty($sessionId)) {
            return [];
        }
        
        // Get orders that match session ID and IP address
        $stmt = $conn->prepare("
            SELECT wo.*, u.business_name, u.phone as shop_phone, u.address as shop_address
            FROM web_orders wo
            INNER JOIN users u ON wo.user_id = u.id
            WHERE wo.guest_session_id = ? AND wo.guest_ip_address = ?
            ORDER BY wo.created_at DESC
        ");
        $stmt->bind_param("ss", $sessionId, $ipAddress);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $orders;
    }
    
    /**
     * Get guest data for checkout
     * Returns basic guest information
     */
    public static function getGuestData() {
        self::start();
        
        return [
            'session_id' => self::getOrCreateGuestSessionId(),
            'ip_address' => self::getClientIp(),
            'is_guest' => true
        ];
    }
}

// Note: sanitizeInput(), isValidEmail() and isValidPhone() are available from included files
?>
